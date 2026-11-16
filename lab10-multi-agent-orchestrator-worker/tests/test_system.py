"""Offline tests for the orchestration plumbing. No API key needed.

    python -m unittest discover tests -v

The LLM is replaced by `ScriptedClient`, a test double that returns canned tool
calls. That checks everything around the model: the tool loop, the per-agent
tool allowlists, the hand-offs between agents and the mock email service. It
does not test how a real model behaves, and its output is not the lab's
execution output; that comes from running main.py against the real API.
"""

from __future__ import annotations

import json
import os
import re
import sys
import tempfile
import unittest
from email import message_from_bytes, policy
from pathlib import Path
from types import SimpleNamespace

OUTBOX = Path(tempfile.mkdtemp(prefix="lab10-outbox-"))
os.environ["LAB10_OUTBOX"] = str(OUTBOX)  # must be set before tools is imported
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from google.genai import types  # noqa: E402

import agents  # noqa: E402
import main  # noqa: E402
import tools  # noqa: E402

TASK = main.DEFAULT_TASK


# --------------------------------------------------------------------------
# A scripted stand-in for the Gemini client
# --------------------------------------------------------------------------


def reply(*parts, finish_reason=types.FinishReason.STOP):
    """A model response built from the SDK's own types, as the real API returns it."""
    content = types.Content(role="model", parts=list(parts))
    return types.GenerateContentResponse(
        candidates=[types.Candidate(content=content, finish_reason=finish_reason)]
    )


def text(value):
    return reply(types.Part(text=value))


def call(tool_name, /, **arguments):
    function_call = types.FunctionCall(id=f"call_{tool_name}", name=tool_name, args=arguments)
    return reply(types.Part(function_call=function_call))


def task_text(contents):
    """The task the agent was given: the text of the first user turn."""
    return contents[0].parts[0].text


def tool_results(contents):
    """Every function response the agent has sent back to the model so far, in order."""
    results = []
    for content in contents:
        for part in content.parts:
            if part.function_response:
                response = part.function_response.response
                is_error = "error" in response
                results.append(
                    {"content": response["error" if is_error else "result"], "is_error": is_error}
                )
    return results


SEARCH_HIT = re.compile(r"^\d+\. \[(.+?)\] \(score [\d.]+\) (.+)$", re.MULTILINE)


def researcher_script(messages):
    results = tool_results(messages)
    queries = ["leave policy", "sick leave", "carry forward unused leave", "leave request"]
    if len(results) < len(queries):
        return call("search_knowledge_base", query=queries[len(results)], top_k=8)
    facts = []
    for result in results:
        for source, passage in SEARCH_HIT.findall(result["content"]):
            fact = f"- {passage} [{source}]"
            if "leave" in passage.lower() and fact not in facts:
                facts.append(fact)
    return text("\n".join(facts))


def writer_script(messages):
    findings = task_text(messages).split("Research findings from the Researcher:\n")[1]
    summary = "Summary of the company leave policy:\n" + findings
    if not tool_results(messages):
        return call("count_words", text=summary)
    return text(summary)


def emailer_script(messages):
    request = task_text(messages)
    results = tool_results(messages)
    if not results:
        name = request.split("Recipient name: ")[1].splitlines()[0]
        return call("lookup_contact", name=name)
    if len(results) == 1:
        contact = json.loads(results[0]["content"])[0]
        subject = request.split("Subject: ")[1].splitlines()[0]
        summary = request.split("Approved summary:\n")[1]
        body = f"Hi {contact['name'].split()[0]},\n\n{summary}\n\nRegards,\nHR Assistant"
        return call("send_email", to=contact["email"], subject=subject, body=body)
    return text(f"Email sent: {results[1]['content']}")


def supervisor_script(messages, tamper=False):
    results = tool_results(messages)
    if not results:
        return call("delegate_to_researcher", task="Find everything about the leave policy.")
    if len(results) == 1:
        return call(
            "delegate_to_writer",
            task="Summarise the leave policy for an employee.",
            research_findings=results[0]["content"],
        )
    summary = results[1]["content"]
    if len(results) == 2 and tamper:  # try to send text the Writer never produced
        summary += "\nAlso, everyone gets Fridays off."
    if results[-1]["is_error"] or len(results) == 2:
        return call(
            "delegate_to_emailer",
            recipient_name="Kunal",
            subject="Summary of our leave policy",
            approved_summary=summary,
        )
    return text("Done: researched, summarised and emailed to Kunal.")


class ScriptedClient:
    """Mimics `client.models.generate_content` and records every request."""

    def __init__(self, tamper=False):
        self.requests = []
        self.scripts = {
            agents.RESEARCHER_PROMPT: researcher_script,
            agents.WRITER_PROMPT: writer_script,
            agents.EMAILER_PROMPT: emailer_script,
            agents.SUPERVISOR_PROMPT: lambda messages: supervisor_script(messages, tamper),
        }
        self.models = SimpleNamespace(generate_content=self.generate_content)

    def generate_content(self, *, model, contents, config):
        offered = {d.name for tool in config.tools for d in tool.function_declarations}
        self.requests.append(
            {"system": config.system_instruction, "tools": offered, "messages": contents}
        )
        return self.scripts[config.system_instruction](contents)

    def tools_offered(self, system_prompt):
        return set().union(*(r["tools"] for r in self.requests if r["system"] == system_prompt))


def emails_in_outbox():
    return [
        message_from_bytes(path.read_bytes(), policy=policy.default)
        for path in sorted(OUTBOX.glob("*.eml"))
    ]


# --------------------------------------------------------------------------
# Tests
# --------------------------------------------------------------------------


class PipelineTest(unittest.TestCase):
    def setUp(self):
        for path in OUTBOX.glob("*.eml"):
            path.unlink()

    def run_pipeline(self, toolbox, tamper=False):
        client, trace = ScriptedClient(tamper), agents.Trace(echo=False)
        try:
            main.run(TASK, client, toolbox, trace)
        finally:
            toolbox.close()
        return client, trace

    def assert_leave_summary_emailed_to_kunal(self):
        (email,) = emails_in_outbox()
        self.assertEqual(email["To"], "kunal.meher@example.com")
        self.assertEqual(email["Subject"], "Summary of our leave policy")
        body = email.get_content()
        for fact in ("18 days of paid annual leave", "10 days of paid sick leave", "maximum of 5"):
            self.assertIn(fact, body)
        self.assertNotIn("probation", body.lower())  # from the onboarding guide, off-topic
        self.assertNotIn("Fridays off", body)

    def test_supervisor_delegates_research_then_writing_then_email(self):
        client, trace = self.run_pipeline(tools.LocalToolbox())

        self.assertEqual(
            [(d["from"], d["to"]) for d in trace.delegations],
            [("Supervisor", "Researcher"), ("Supervisor", "Writer"), ("Supervisor", "Emailer")],
        )
        self.assert_leave_summary_emailed_to_kunal()

        # The Supervisor passed each output on: findings to the Writer, summary to the Emailer.
        researcher_output = tool_results(client.requests[-1]["messages"])[0]["content"]
        writer_output = tool_results(client.requests[-1]["messages"])[1]["content"]
        writer_input = next(r for r in client.requests if r["system"] == agents.WRITER_PROMPT)
        emailer_input = next(r for r in client.requests if r["system"] == agents.EMAILER_PROMPT)
        self.assertIn(researcher_output, task_text(writer_input["messages"]))
        self.assertIn(writer_output, task_text(emailer_input["messages"]))

    def test_each_agent_is_offered_only_its_own_tools(self):
        client, _ = self.run_pipeline(tools.LocalToolbox())
        self.assertEqual(client.tools_offered(agents.RESEARCHER_PROMPT), {"search_knowledge_base"})
        self.assertEqual(client.tools_offered(agents.WRITER_PROMPT), {"count_words"})
        self.assertEqual(client.tools_offered(agents.EMAILER_PROMPT), {"lookup_contact", "send_email"})
        self.assertEqual(
            client.tools_offered(agents.SUPERVISOR_PROMPT),
            {"delegate_to_researcher", "delegate_to_writer", "delegate_to_emailer"},
        )

    def test_supervisor_cannot_email_text_the_writer_did_not_write(self):
        client, trace = self.run_pipeline(tools.LocalToolbox(), tamper=True)

        results = tool_results(client.requests[-1]["messages"])
        self.assertTrue(results[2]["is_error"])
        self.assertIn("word for word", results[2]["content"])
        # The rejected attempt never reached the Emailer; the verbatim retry did.
        self.assertEqual([d["to"] for d in trace.delegations], ["Researcher", "Writer", "Emailer"])
        self.assert_leave_summary_emailed_to_kunal()

    def test_same_pipeline_with_tools_served_over_mcp(self):
        from mcp_toolbox import MCPToolbox

        client, trace = self.run_pipeline(MCPToolbox())
        self.assertEqual([d["to"] for d in trace.delegations], ["Researcher", "Writer", "Emailer"])
        self.assertEqual(client.tools_offered(agents.EMAILER_PROMPT), {"lookup_contact", "send_email"})
        self.assert_leave_summary_emailed_to_kunal()


class AgentLoopTest(unittest.TestCase):
    def make_agent(self, responses, tool_names, **options):
        seen = []

        def generate_content(*, model, contents, config):
            seen.append(list(contents))
            return responses.pop(0)

        client = SimpleNamespace(models=SimpleNamespace(generate_content=generate_content))
        agent = agents.Agent(
            "Tester",
            "prompt",
            tools.LocalToolbox().get(tool_names),
            client,
            agents.Trace(echo=False),
            **options,
        )
        return agent, seen

    def test_tool_outside_the_allowlist_is_refused(self):
        responses = [
            call("send_email", to="kunal.meher@example.com", subject="s", body="b"),
            text("ok"),
        ]
        agent, seen = self.make_agent(responses, ["search_knowledge_base"])

        self.assertEqual(agent.run("email this"), "ok")
        (result,) = tool_results(seen[1])  # what the model was told about its call
        self.assertTrue(result["is_error"])
        self.assertIn("no tool named 'send_email'", result["content"])
        self.assertEqual(emails_in_outbox(), [])

    def test_runaway_agent_is_stopped(self):
        responses = [call("count_words", text="a b") for _ in range(5)]
        agent, _ = self.make_agent(responses, ["count_words"], max_turns=3)
        with self.assertRaises(agents.AgentError):
            agent.run("loop forever")

    def test_bad_tool_arguments_are_reported_to_the_model(self):
        responses = [call("count_words", words="a b"), text("ok")]
        agent, seen = self.make_agent(responses, ["count_words"])
        self.assertEqual(agent.run("count"), "ok")
        (result,) = tool_results(seen[1])
        self.assertTrue(result["is_error"])
        self.assertIn("bad arguments for count_words", result["content"])

    def test_model_turn_is_sent_back_unchanged_and_thoughts_stay_out_of_the_answer(self):
        thought = types.Part(text="I should count first.", thought=True)
        signed_call = types.Part(
            function_call=types.FunctionCall(name="count_words", args={"text": "a b"}),
            thought_signature=b"signature",
        )
        responses = [reply(thought, signed_call), reply(thought, types.Part(text="Two words."))]
        agent, seen = self.make_agent(responses, ["count_words"])

        self.assertEqual(agent.run("count"), "Two words.")
        model_turn = seen[1][1]
        self.assertEqual(model_turn.parts, [thought, signed_call])
        self.assertEqual(tool_results(seen[1]), [{"content": "2", "is_error": False}])
        self.assertIn("I should count first.", "\n".join(agent.trace.lines))

    def test_blocked_or_truncated_response_raises(self):
        blocked = types.GenerateContentResponse(
            candidates=[types.Candidate(content=None, finish_reason=types.FinishReason.SAFETY)]
        )
        truncated = reply(types.Part(text="Half a sen"), finish_reason=types.FinishReason.MAX_TOKENS)
        for response in (blocked, truncated, types.GenerateContentResponse(candidates=[])):
            agent, _ = self.make_agent([response], ["count_words"])
            with self.assertRaises(agents.AgentError):
                agent.run("anything")


class ToolTest(unittest.TestCase):
    def setUp(self):
        for path in OUTBOX.glob("*.eml"):
            path.unlink()

    def test_search_ranks_the_right_document_first(self):
        source, passage = SEARCH_HIT.findall(tools.search_knowledge_base("sick leave certificate"))[0]
        self.assertEqual(source, "employee_leave_policy.pdf")
        self.assertIn("medical certificate", passage)

        source, _ = SEARCH_HIT.findall(tools.search_knowledge_base("probation period"))[0]
        self.assertEqual(source, "new_employee_onboarding_guide.pdf")

    def test_search_with_no_match_says_so(self):
        self.assertIn("No passages matched", tools.search_knowledge_base("xylophone"))

    def test_contact_lookup(self):
        (contact,) = json.loads(tools.lookup_contact("kunal"))
        self.assertEqual(contact["email"], "kunal.meher@example.com")
        with self.assertRaises(tools.ToolError):
            tools.lookup_contact("nobody")

    def test_email_only_goes_to_directory_addresses(self):
        with self.assertRaises(tools.ToolError):
            tools.send_email("stranger@gmail.com", "Hello", "Body")
        self.assertEqual(emails_in_outbox(), [])

        tools.send_email("kunal.meher@example.com", "Hello", "Body")
        (email,) = emails_in_outbox()
        self.assertEqual(email["X-Mock-Delivery"], "true")


if __name__ == "__main__":
    unittest.main()
