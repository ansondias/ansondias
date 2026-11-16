"""The agents: one generic tool-calling loop, three specialists, one Supervisor.

Orchestrator-Worker pattern
---------------------------
    Supervisor (orchestrator)
        |-- delegate_to_researcher --> Researcher  [search_knowledge_base]
        |-- delegate_to_writer -----> Writer      [count_words]
        '-- delegate_to_emailer ----> Emailer     [lookup_contact, send_email]

Every box is an `Agent`: its own system prompt, its own tool allowlist, its own
conversation with the LLM and its own tool-execution loop. The Supervisor's
"tools" are the three specialists, so delegating is just a tool call whose
handler runs another agent and returns that agent's final answer.
"""

from __future__ import annotations

import inspect
import json
from contextlib import contextmanager
from typing import Any

from google.genai import types

from tools import Tool, ToolError

DEFAULT_MODEL = "gemini-3.8-flash"


class AgentError(RuntimeError):
    """An agent could not finish its task."""


# --------------------------------------------------------------------------
# Trace: prints the collaboration as it happens and keeps a copy
# --------------------------------------------------------------------------


class Trace:
    def __init__(self, echo: bool = True) -> None:
        self.echo = echo
        self.lines: list[str] = []
        self.delegations: list[dict[str, Any]] = []
        self.model_calls: dict[str, int] = {}
        self._depth = 0

    def log(self, text: str = "") -> None:
        for line in text.splitlines() or [""]:
            line = ("    " * self._depth + line).rstrip()
            if self.echo:
                print(line, flush=True)
            self.lines.append(line)

    def block(self, text: str) -> None:
        """Log multi-line text one level deeper, as a quoted block."""
        with self.indent():
            self.log(text)

    def heading(self, title: str) -> None:
        self.log()
        self.log("=" * 78)
        self.log(title)
        self.log("=" * 78)

    @contextmanager
    def indent(self):
        self._depth += 1
        try:
            yield
        finally:
            self._depth -= 1

    def delegation(self, source: str, target: str, passed: str) -> int:
        """Record one hand-off and return its step number."""
        step = len(self.delegations) + 1
        self.delegations.append({"step": step, "from": source, "to": target, "passed": passed})
        return step


def _preview(text: str, limit: int = 4000) -> str:
    return text if len(text) <= limit else f"{text[:limit]} ... [{len(text) - limit} more characters]"


# --------------------------------------------------------------------------
# The generic agent
# --------------------------------------------------------------------------


class Agent:
    """An LLM with a system prompt, a fixed set of tools and a tool-execution loop."""

    def __init__(
        self,
        name: str,
        system_prompt: str,
        tools: list[Tool],
        client: Any,
        trace: Trace,
        model: str = DEFAULT_MODEL,
        max_turns: int = 12,
        show_tool_io: bool = True,
        show_reasoning: bool = False,
    ) -> None:
        self.name = name
        self.tools = {tool.name: tool for tool in tools}
        self.client = client
        self.trace = trace
        self.model = model
        self.max_turns = max_turns
        self.show_tool_io = show_tool_io
        self.config = types.GenerateContentConfig(
            system_instruction=system_prompt,
            tools=[
                types.Tool(
                    function_declarations=[
                        types.FunctionDeclaration(
                            name=tool.name,
                            description=tool.description,
                            parameters_json_schema=tool.input_schema,
                        )
                        for tool in tools
                    ]
                )
            ],
            # This class runs the tool loop itself, so the SDK must not.
            automatic_function_calling=types.AutomaticFunctionCallingConfig(disable=True),
            thinking_config=types.ThinkingConfig(include_thoughts=show_reasoning),
        )

    def run(self, task: str) -> str:
        """Work on `task` until the LLM stops calling tools; return its final text."""
        tag = f"[{self.name.upper()}]"
        contents = [types.Content(role="user", parts=[types.Part(text=task)])]

        for _ in range(self.max_turns):
            response = self.client.models.generate_content(
                model=self.model, contents=contents, config=self.config
            )
            self.trace.model_calls[self.name] = self.trace.model_calls.get(self.name, 0) + 1
            candidate = response.candidates[0] if response.candidates else None
            if candidate is None or candidate.content is None or not candidate.content.parts:
                reason = candidate.finish_reason if candidate else response.prompt_feedback
                raise AgentError(f"{self.name} got an empty response from the model ({reason})")
            parts = candidate.content.parts

            for part in parts:
                if part.thought and part.text:
                    self.trace.log(f"{tag} reasoning:")
                    self.trace.block(part.text.strip())

            # The model's turn goes back unchanged, thought signatures included.
            contents.append(candidate.content)

            calls = [part.function_call for part in parts if part.function_call]
            if calls:
                results = [self._run_tool(tag, call) for call in calls]
                contents.append(types.Content(role="user", parts=results))
                continue

            if candidate.finish_reason not in (None, types.FinishReason.STOP):
                raise AgentError(f"{self.name} stopped early ({candidate.finish_reason})")
            answer = "\n".join(p.text for p in parts if p.text and not p.thought).strip()
            return answer or "(no output)"

        raise AgentError(f"{self.name} did not finish within {self.max_turns} turns")

    def _run_tool(self, tag: str, call: types.FunctionCall) -> types.Part:
        """Execute one function call and package the outcome as a function response."""
        arguments = dict(call.args or {})
        if self.show_tool_io:
            self.trace.log(f"{tag} calls {call.name}({json.dumps(arguments, ensure_ascii=False)})")
        else:
            self.trace.log(f"{tag} calls {call.name}")
        tool = self.tools.get(call.name)
        try:
            if tool is None:
                raise ToolError(f"{self.name} has no tool named {call.name!r}.")
            try:
                inspect.signature(tool.handler).bind(**arguments)
            except TypeError as error:  # the LLM sent arguments the tool does not take
                raise ToolError(f"bad arguments for {call.name}: {error}") from None
            output, failed = tool.handler(**arguments), False
        except ToolError as error:
            output, failed = f"Error: {error}", True

        if failed or self.show_tool_io:
            self.trace.log(f"{tag} {call.name} returned:")
            self.trace.block(_preview(output))
        return types.Part(
            function_response=types.FunctionResponse(
                id=call.id,
                name=call.name,
                response={"error": output} if failed else {"result": output},
            )
        )


# --------------------------------------------------------------------------
# System prompts: one narrow job each
# --------------------------------------------------------------------------

RESEARCHER_PROMPT = """\
You are the Researcher in a small team of AI agents coordinated by a Supervisor. \
Your one job is to find information in the company knowledge base and report what it says.

Use search_knowledge_base to gather the passages relevant to the research task. The search \
is keyword-based and returns single sentences, so give it several focused queries in one \
call, covering the different aspects and wordings of the topic. One call is normally \
enough; make a second only if the first leaves a clear gap. The knowledge base also holds \
documents on other topics; leave out anything that is not about the topic you were asked \
to research.

Report your findings as a list of facts. Quote or closely paraphrase each one from a \
passage and put its source file in brackets after it. Include every relevant detail \
(numbers, limits, deadlines, conditions): the Writer who works from your findings cannot \
see the knowledge base. If the knowledge base has nothing on part of the task, say so \
rather than filling the gap from general knowledge.

You only do research. Do not write a polished summary and do not draft or send emails; \
other agents handle those."""

WRITER_PROMPT = """\
You are the Writer in a small team of AI agents coordinated by a Supervisor. \
Your one job is to turn research findings into a clear, concise summary.

You will be given a writing task and the Researcher's findings. Work only from those \
findings. You have no access to the knowledge base, so do not add facts, numbers or \
policies that are not in them. If the findings do not contain enough to do the task, say \
what is missing instead of writing a summary.

Write for a busy employee reading an email. Use plain text, not Markdown, because the \
summary is pasted into a plain-text email. Open with one line saying what the summary \
covers, then give short grouped points that keep every number and condition from the \
findings. Stay under 150 words unless the task sets a different limit, and check the \
length with count_words before you finish.

Your final reply must be the summary text and nothing else: no preamble, no greeting or \
sign-off, no commentary. The Supervisor forwards it exactly as you return it. You do not \
search for information and you do not send emails."""

EMAILER_PROMPT = """\
You are the Emailer in a small team of AI agents coordinated by a Supervisor. \
Your one job is to deliver an approved summary to a recipient by email.

You will be given a recipient name, a subject and a summary the Supervisor has approved. \
Look the recipient up with lookup_contact to get their address; never guess or construct \
an address. If the lookup returns no match, or more than one, send nothing and report the \
problem. Otherwise send exactly one email with send_email. Its body is a short greeting \
using the recipient's first name, then the approved summary exactly as given (do not \
reword, shorten or add to it), then a sign-off from "HR Assistant".

Finish by reporting the recipient address, the subject and the message ID that send_email \
returned, or the reason you could not send. You do not do research and you do not write \
or edit summaries."""

SUPERVISOR_PROMPT = """\
You are the Supervisor of a team of three specialist AI agents. You receive a task from a \
user, break it into subtasks, delegate each subtask to the right specialist, and decide \
when the whole task is done. You coordinate; you do not do the specialists' work yourself.

Your team, each available to you as a tool:
- delegate_to_researcher: searches the company knowledge base and returns findings with \
sources. Cannot write summaries or send email.
- delegate_to_writer: turns research findings into a concise summary. Cannot see the \
knowledge base.
- delegate_to_emailer: looks a recipient up in the contact directory and emails them a \
summary. Cannot research or write.

Each specialist starts from a blank slate. It sees only what you put in the tool call, \
not the user's task and not the other specialists' output. So pass along everything it \
needs; in particular, give the Writer the Researcher's findings in full.

Review each result before you move on. If research is thin or off-topic, or a summary is \
inaccurate against the findings, unclear or too long, send it back to the same specialist \
with specific feedback. Never fix a summary yourself: what you pass to the Emailer must \
be the Writer's text exactly as the Writer returned it, and passing it on is how you mark \
it approved. An email cannot be unsent, so delegate to the Emailer only once you are \
satisfied with the summary.

When the task is complete, or cannot be completed, reply to the user with a brief report: \
what each specialist did, who the email went to, and anything the user should know."""


# --------------------------------------------------------------------------
# Wiring the team together
# --------------------------------------------------------------------------


def _normalize(text: str) -> str:
    return " ".join(text.split())


def build_supervisor(client: Any, toolbox: Any, trace: Trace, model: str = DEFAULT_MODEL) -> Agent:
    """Create the three specialists and a Supervisor that can call them as tools."""
    researcher = Agent(
        "Researcher", RESEARCHER_PROMPT, toolbox.get(["search_knowledge_base"]), client, trace, model
    )
    writer = Agent("Writer", WRITER_PROMPT, toolbox.get(["count_words"]), client, trace, model)
    emailer = Agent(
        "Emailer", EMAILER_PROMPT, toolbox.get(["lookup_contact", "send_email"]), client, trace, model
    )
    writer_outputs: list[str] = []

    def delegate(specialist: Agent, message: str, passed: str) -> str:
        step = trace.delegation("Supervisor", specialist.name, passed)
        trace.log()
        trace.log(f"--- STEP {step}: SUPERVISOR -> {specialist.name.upper()} " + "-" * 40)
        trace.log("Message passed to the specialist:")
        trace.block(message)
        with trace.indent():
            output = specialist.run(message)
        trace.log(f"--- STEP {step}: {specialist.name.upper()} -> SUPERVISOR (output) " + "-" * 30)
        trace.block(output)
        return output

    def delegate_to_researcher(task: str) -> str:
        return delegate(researcher, task, "research task")

    def delegate_to_writer(task: str, research_findings: str) -> str:
        message = f"Writing task:\n{task}\n\nResearch findings from the Researcher:\n{research_findings}"
        output = delegate(writer, message, "writing task + Researcher's findings")
        writer_outputs.append(_normalize(output))
        return output

    def delegate_to_emailer(recipient_name: str, subject: str, approved_summary: str) -> str:
        # Role boundary enforced in code: the Supervisor may only forward text
        # the Writer actually produced, so it cannot write the summary itself.
        if _normalize(approved_summary) not in writer_outputs:
            raise ToolError(
                "approved_summary must be a summary returned by delegate_to_writer, passed on "
                "word for word. Call delegate_to_writer first, or ask the Writer for a revision "
                "instead of editing the text yourself."
            )
        message = (
            f"Recipient name: {recipient_name}\n"
            f"Subject: {subject}\n\n"
            f"Approved summary:\n{approved_summary}"
        )
        return delegate(emailer, message, "recipient + subject + approved summary from the Writer")

    string = {"type": "string"}
    specialist_tools = [
        Tool(
            name="delegate_to_researcher",
            description=(
                "Ask the Researcher to search the company knowledge base. Returns findings "
                "with source files. The Researcher sees only the `task` text."
            ),
            input_schema={
                "type": "object",
                "properties": {
                    "task": {**string, "description": "What to research, stated completely."}
                },
                "required": ["task"],
            },
            handler=delegate_to_researcher,
        ),
        Tool(
            name="delegate_to_writer",
            description=(
                "Ask the Writer to turn research findings into a concise plain-text summary. "
                "The Writer sees only `task` and `research_findings`. Call it again with "
                "feedback in `task` to get a revision."
            ),
            input_schema={
                "type": "object",
                "properties": {
                    "task": {
                        **string,
                        "description": "What to write, for whom, and any revision feedback.",
                    },
                    "research_findings": {
                        **string,
                        "description": "The Researcher's findings, in full.",
                    },
                },
                "required": ["task", "research_findings"],
            },
            handler=delegate_to_writer,
        ),
        Tool(
            name="delegate_to_emailer",
            description=(
                "Ask the Emailer to look up the recipient and email them the approved summary. "
                "`approved_summary` must be the Writer's summary, word for word."
            ),
            input_schema={
                "type": "object",
                "properties": {
                    "recipient_name": {
                        **string,
                        "description": "Recipient's name as the user gave it.",
                    },
                    "subject": {**string, "description": "Email subject line."},
                    "approved_summary": {
                        **string,
                        "description": "The Writer's summary, unchanged.",
                    },
                },
                "required": ["recipient_name", "subject", "approved_summary"],
            },
            handler=delegate_to_emailer,
        ),
    ]

    return Agent(
        "Supervisor",
        SUPERVISOR_PROMPT,
        specialist_tools,
        client,
        trace,
        model,
        show_reasoning=True,  # how it splits the task and decides it is done
        show_tool_io=False,  # each hand-off is already printed in full by delegate()
    )
