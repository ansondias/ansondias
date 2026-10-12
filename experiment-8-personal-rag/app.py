"""Personal RAG Question Answering System — command-line app.

Usage:
    python app.py                      # run the built-in sample question set
    python app.py "your question?"     # ask a single question
    python app.py --interactive        # ask questions in a loop (Ctrl-D to quit)
"""
from __future__ import annotations

import sys
import textwrap

import config
import rag

SAMPLE_QUESTIONS = [
    "What is the student's full name?",
    "What programming languages does the student know?",
    "What projects has the student completed?",
    "Which internships has the student undertaken?",
    "What certifications has the student earned?",
    "What technical skills are listed in the resume?",
    "Which programming language was used in the final-year project?",
    "What is the student's CGPA or percentage?",
    "Which college or university does the student belong to?",
    "What awards or achievements has the student received?",
    "What are the student's research interests?",
    "What is the student's email address or contact information?",
    # Not in any document -> should be refused (guardrail demo):
    "What is the student's blood group?",
]


def _print_result(res: rag.RagResult) -> None:
    engine = "Claude LLM" if res.used_llm else "offline extractive responder"
    print(f"\nQ: {res.question}")
    lines = res.answer.splitlines() or [""]
    wrapped: list[str] = []
    for ln in lines:
        wrapped.extend(textwrap.wrap(ln, width=86) or [""])
    for i, ln in enumerate(wrapped):
        print(f"A: {ln}" if i == 0 else f"   {ln}")
    hits = ", ".join(f"{s}:{d}" for s, d in res.retrieved) or "none"
    print(f"   [engine: {engine} | retrieved: {hits}]")


def _banner() -> None:
    mode = "Claude LLM" if config.anthropic_api_key_present() else "offline extractive responder"
    print("=" * 90)
    print("Personal RAG Question Answering System  —  Experiment 8")
    print(f"Embeddings: all-MiniLM-L6-v2 (ONNX) | Vector DB: Chroma | Generator: {mode}")
    print("=" * 90)


def run_samples() -> None:
    _banner()
    for q in SAMPLE_QUESTIONS:
        _print_result(rag.answer(q))
    print("\nFinished sample question set.")


def run_single(question: str) -> None:
    _banner()
    _print_result(rag.answer(question))


def run_interactive() -> None:
    _banner()
    print("Type a question and press Enter. Ctrl-D or 'quit' to exit.\n")
    while True:
        try:
            q = input("question> ").strip()
        except EOFError:
            print()
            break
        if not q or q.lower() in {"quit", "exit"}:
            break
        _print_result(rag.answer(q))


def main() -> None:
    args = sys.argv[1:]
    if not args:
        run_samples()
    elif args[0] in {"-i", "--interactive"}:
        run_interactive()
    else:
        run_single(" ".join(args))


if __name__ == "__main__":
    main()
