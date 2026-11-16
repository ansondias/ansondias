"""Experiment 10: multi-agent system using the Orchestrator-Worker pattern.

    python main.py                        # run the default task
    python main.py "your own task"        # run a different task
    python main.py --mcp                  # serve the tools through the MCP server

Prints the full collaboration (every delegation, tool call and agent output)
and saves the same text to execution_output.txt.
"""

from __future__ import annotations

import argparse
import os
import sys
from email import message_from_bytes, policy
from pathlib import Path

import anthropic

from agents import DEFAULT_MODEL, AgentError, Trace, build_supervisor
from tools import BASE_DIR, OUTBOX_DIR, LocalToolbox

DEFAULT_TASK = "Research our company's leave policy, prepare a summary, and email it to Kunal."


def run(task: str, client, toolbox, trace: Trace, model: str = DEFAULT_MODEL) -> str:
    """Run one task through the Supervisor and log the whole collaboration."""
    already_in_outbox = set(OUTBOX_DIR.glob("*.eml"))

    trace.heading("USER TASK")
    trace.log(task)

    trace.heading("COLLABORATION")
    supervisor = build_supervisor(client, toolbox, trace, model)
    report = supervisor.run(task)

    trace.heading("SUPERVISOR'S FINAL REPORT TO THE USER")
    trace.log(report)

    trace.heading("DELEGATION SEQUENCE")
    for d in trace.delegations:
        trace.log(f"{d['step']}. {d['from']} -> {d['to']:<10} passed: {d['passed']}")

    trace.heading("MOCK OUTBOX (emails written by this run; nothing was really sent)")
    sent = sorted(set(OUTBOX_DIR.glob("*.eml")) - already_in_outbox)
    for path in sent:
        trace.log(f"File: {os.path.relpath(path, BASE_DIR)}")
        email = message_from_bytes(path.read_bytes(), policy=policy.default)
        with trace.indent():
            for header in ("From", "To", "Subject", "Date"):
                trace.log(f"{header}: {email[header]}")
            trace.log()
            trace.log(email.get_content())
    if not sent:
        trace.log("(no email was sent)")
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("task", nargs="?", default=DEFAULT_TASK, help="the task for the Supervisor")
    parser.add_argument("--mcp", action="store_true", help="serve the tools through mcp_server.py")
    parser.add_argument(
        "--model",
        default=os.environ.get("LAB10_MODEL", DEFAULT_MODEL),
        help=f"Claude model for all four agents (default: {DEFAULT_MODEL})",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=BASE_DIR / "execution_output.txt",
        help="where to save the transcript",
    )
    args = parser.parse_args()

    trace = Trace()
    trace.log("Experiment 10: Multi-Agent System (Orchestrator-Worker pattern)")
    trace.log(f"Model: {args.model}")
    trace.log(f"Tools: {'MCP server (mcp_server.py over stdio)' if args.mcp else 'local Python functions'}")

    if args.mcp:
        from mcp_toolbox import MCPToolbox

        toolbox = MCPToolbox()
    else:
        toolbox = LocalToolbox()

    error_message = None
    try:
        client = anthropic.Anthropic()
        run(args.task, client, toolbox, trace, args.model)
    except anthropic.AuthenticationError:
        error_message = "the API rejected the credentials. Check ANTHROPIC_API_KEY."
    except anthropic.RateLimitError:
        error_message = "rate limited by the API. Wait a minute and run again."
    except anthropic.APIStatusError as error:
        error_message = f"the API returned {error.status_code}: {error.message}"
    except anthropic.APIConnectionError:
        error_message = "could not reach the API. Check the network connection."
    except TypeError as error:
        if "authentication method" not in str(error):
            raise
        error_message = "no API credentials found. Set ANTHROPIC_API_KEY and run again."
    except AgentError as error:
        error_message = str(error)
    finally:
        toolbox.close()

    if error_message:
        print(f"\nERROR: {error_message}\nTranscript not saved.", file=sys.stderr)
        return 1
    args.output.write_text("\n".join(trace.lines) + "\n", encoding="utf-8")
    print(f"\nTranscript saved to {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
