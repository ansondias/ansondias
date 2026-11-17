# Lab 10: Multi-Agent System Using the Orchestrator-Worker Pattern

A Supervisor agent completes a task by delegating to three specialist agents instead of doing the work itself. The demo task is:

> Research our company's leave policy, prepare a summary, and email it to Kunal.

```
                         user task
                             |
                      +-------------+
                      | SUPERVISOR  |   orchestrator: splits the task, delegates,
                      +-------------+   passes outputs along, decides when it is done
          ______________/    |    \______________
         | 1. research       | 2. write          | 3. email
   +------------+      +------------+      +------------+
   | RESEARCHER |      |   WRITER   |      |  EMAILER   |   workers
   +------------+      +------------+      +------------+
         |                   |                   |
 search_knowledge_base   count_words     lookup_contact, send_email
   (the two PDFs)                        (contacts.json, mock outbox)
```

## Files

| File | What it is |
|---|---|
| `main.py` | Entry point. Runs the task, prints the collaboration and saves it to `execution_output.txt`. |
| `agents.py` | The generic `Agent` (LLM calls + tool-execution loop), the four system prompts, and the wiring that gives the Supervisor its three specialists as callable tools. |
| `tools.py` | The tools: BM25 search over the knowledge-base PDFs, contact lookup, the mock email service and a word counter. |
| `mcp_server.py`, `mcp_toolbox.py` | Optional: the same tools served through an MCP server, and the client the agents use to reach it. |
| `data/knowledge_base/` | The two PDFs supplied with the lab: the leave policy and an onboarding guide. The onboarding guide is off-topic for the demo task, so the Researcher has to leave it out. |
| `data/contacts.json` | Contact directory. All addresses are `@example.com` test addresses. |
| `tests/test_system.py` | Offline tests that need no API key. |
| `execution_output.txt` | Transcript of a real run on `gemini-3.5-flash-lite`: every delegation, tool call and agent output, the delegation sequence and the email that was written to the mock outbox. |

## How it meets the requirements

**Each specialist is an independent mini-agent.** `Researcher`, `Writer` and `Emailer` are separate `Agent` objects. Each has its own system prompt, its own message history, its own LLM calls and its own loop that runs until the model stops asking for tools. A specialist starts every job from a blank context and sees only the message the Supervisor sends it.

**The Supervisor calls the specialists as tools.** Its only tools are `delegate_to_researcher`, `delegate_to_writer` and `delegate_to_emailer`. The handler of each one runs the specialist and returns its final answer as the tool result. The Supervisor has no search or email tool, so it cannot do the work itself. It decides the order, reads each result, passes it to the next specialist, and ends its own loop when the task is done.

**Role boundaries are enforced in code as well as in the prompts.**

| Agent | Tools it is given | What stops it stepping outside its role |
|---|---|---|
| Researcher | `search_knowledge_base` | Has no email or contact tool. |
| Writer | `count_words` | Has no search or email tool, so it can only work from the findings it is handed. |
| Emailer | `lookup_contact`, `send_email` | Has no search tool. `send_email` rejects any address that is not in the contact directory. |
| Supervisor | the three `delegate_to_*` tools | `delegate_to_emailer` rejects a summary that is not word for word what the Writer returned, so the Supervisor cannot write or edit the summary. |

A call to a tool outside an agent's allowlist is refused by the loop and reported back to the model as an error.

**The email service is a mock.** `send_email` writes a `.eml` file to `outbox/` and sends nothing over the network.

## Running it

It needs Python 3.10+ and a Gemini API key, which you can create for free in [Google AI Studio](https://aistudio.google.com/apikey). The agents use Google's `google-genai` SDK. A run makes about a dozen model calls (the saved run made 12). If a model is overloaded or over its per-minute limit, the client retries for about a minute before giving up.

The free tier also has a daily request limit per model; it was 20 requests a day when this lab was run, so expect one or two runs per model per day. If a run stops with `429 RESOURCE_EXHAUSTED` and asks you to retry in some hours, switch to another model with `--model` rather than waiting.

```bash
pip install -r requirements.txt
```

```bash
export GEMINI_API_KEY=your-key
```

```bash
python main.py
```

The transcript is printed and saved to `execution_output.txt`. It shows, in order:

1. the user task;
2. each delegation step, with the exact message the Supervisor passed to the specialist, every tool call the specialist made with its result, and the specialist's output;
3. the Supervisor's final report;
4. the delegation sequence as a numbered list;
5. the email that landed in the mock outbox.

Other ways to run it:

```bash
python main.py --mcp
```

```bash
python main.py "Find out how sick leave works and email a short summary to Asha."
```

```bash
python main.py --model gemini-flash-latest
```

`--mcp` starts `mcp_server.py` as a subprocess and routes every tool call through it over stdio; the agents and their allowlists are unchanged. `--model` (or the `LAB10_MODEL` environment variable) picks the Gemini model for all four agents; the default is `gemini-3.8-flash`. If your key cannot use that model, list the ones it can and pick one:

```bash
python main.py --list-models
```

## Tests

```bash
python -m unittest discover tests -v
```

The tests replace the LLM with a scripted stand-in, so they run offline in about two seconds. They check the parts around the model: the tool loop, the tool allowlists, that the Researcher's output reaches the Writer and the Writer's output reaches the Emailer, that the Supervisor cannot email text the Writer did not write, the mock email service, the knowledge-base search, and the same pipeline with the tools served over MCP. They do not show how a real model behaves; `execution_output.txt` from a real run does.
