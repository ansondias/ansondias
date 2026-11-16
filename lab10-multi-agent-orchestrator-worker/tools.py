"""Tools the specialist agents can use.

Four plain Python functions, each wrapped as a `Tool` (name + description +
JSON schema + handler):

    search_knowledge_base   BM25 search over the PDFs in data/knowledge_base/
    lookup_contact          name -> email lookup in data/contacts.json
    send_email              MOCK email service: writes a .eml file to outbox/
    count_words             word counter the Writer uses to check its length limit

No agent gets all of them. Each agent is built with an allowlist (see
agents.py), so the role boundaries are enforced in code, not only in prompts.
"""

from __future__ import annotations

import json
import logging
import math
import os
import re
from dataclasses import dataclass
from datetime import datetime, timezone
from email import policy
from email.message import EmailMessage
from email.utils import format_datetime, make_msgid
from pathlib import Path
from typing import Any, Callable

from pypdf import PdfReader

BASE_DIR = Path(__file__).resolve().parent
KNOWLEDGE_BASE_DIR = BASE_DIR / "data" / "knowledge_base"
CONTACTS_FILE = BASE_DIR / "data" / "contacts.json"
OUTBOX_DIR = Path(os.environ.get("LAB10_OUTBOX", BASE_DIR / "outbox"))
SENDER = "hr-assistant@example.com"

logging.getLogger("pypdf").setLevel(logging.ERROR)  # the sample PDFs trip harmless xref warnings


class ToolError(Exception):
    """Raised by a tool to report a failure back to the calling agent."""


@dataclass(frozen=True)
class Tool:
    """A callable an agent may use: what the LLM sees plus the code that runs."""

    name: str
    description: str
    input_schema: dict[str, Any]
    handler: Callable[..., str]

    def definition(self) -> dict[str, Any]:
        """The tool definition sent to the Claude API."""
        return {
            "name": self.name,
            "description": self.description,
            "input_schema": self.input_schema,
        }


# --------------------------------------------------------------------------
# Knowledge base search
# --------------------------------------------------------------------------

_STOPWORDS = frozenset(
    "a an and are as at be by can do does for from how in is it its may must of on "
    "or our per that the their this to up what when which who will with your".split()
)


def _tokenize(text: str) -> list[str]:
    """Lowercase words, without stopwords, with plural 's' stripped."""
    words = re.findall(r"[a-z0-9]+", text.lower())
    return [w[:-1] if len(w) > 3 and w.endswith("s") else w for w in words if w not in _STOPWORDS]


class KnowledgeBase:
    """The PDFs in a folder, split into sentences and ranked with BM25."""

    def __init__(self, folder: Path = KNOWLEDGE_BASE_DIR):
        self.passages: list[dict[str, str]] = []
        for pdf in sorted(folder.glob("*.pdf")):
            text = " ".join(page.extract_text() or "" for page in PdfReader(pdf).pages)
            text = re.sub(r"\s+", " ", text).strip()
            for sentence in re.split(r"(?<=[.!?])\s+", text):
                if sentence:
                    self.passages.append({"source": pdf.name, "text": sentence})
        if not self.passages:
            raise ToolError(f"No PDF text found in {folder}")

        self._tokens = [_tokenize(p["text"]) for p in self.passages]
        self._avg_len = sum(map(len, self._tokens)) / len(self._tokens)
        doc_freq: dict[str, int] = {}
        for tokens in self._tokens:
            for term in set(tokens):
                doc_freq[term] = doc_freq.get(term, 0) + 1
        n = len(self.passages)
        self._idf = {t: math.log(1 + (n - df + 0.5) / (df + 0.5)) for t, df in doc_freq.items()}

    def search(self, query: str, top_k: int = 5) -> list[dict[str, Any]]:
        k1, b = 1.5, 0.75
        scored = []
        for passage, tokens in zip(self.passages, self._tokens):
            score = 0.0
            for term in set(_tokenize(query)):
                tf = tokens.count(term)
                if tf:
                    norm = tf + k1 * (1 - b + b * len(tokens) / self._avg_len)
                    score += self._idf[term] * tf * (k1 + 1) / norm
            if score > 0:
                scored.append({**passage, "score": round(score, 2)})
        scored.sort(key=lambda p: p["score"], reverse=True)
        return scored[:top_k]


_kb: KnowledgeBase | None = None


def search_knowledge_base(query: str, top_k: int = 5) -> str:
    global _kb
    if _kb is None:
        _kb = KnowledgeBase()
    hits = _kb.search(query, max(1, min(int(top_k), 10)))
    if not hits:
        return f"No passages matched {query!r}. Try different keywords."
    return "\n".join(
        f"{rank}. [{hit['source']}] (score {hit['score']}) {hit['text']}"
        for rank, hit in enumerate(hits, start=1)
    )


# --------------------------------------------------------------------------
# Contact directory
# --------------------------------------------------------------------------


def _load_contacts() -> list[dict[str, str]]:
    return json.loads(CONTACTS_FILE.read_text(encoding="utf-8"))


def lookup_contact(name: str) -> str:
    wanted = name.lower().split()
    matches = [c for c in _load_contacts() if wanted and all(w in c["name"].lower() for w in wanted)]
    if not matches:
        raise ToolError(f"No contact named {name!r} in the directory.")
    return json.dumps(matches, indent=2)


# --------------------------------------------------------------------------
# Mock email service
# --------------------------------------------------------------------------


def send_email(to: str, subject: str, body: str) -> str:
    """Write the email to outbox/ as a .eml file. Nothing leaves this machine."""
    known = {c["email"].lower() for c in _load_contacts()}
    if to.lower() not in known:
        raise ToolError(
            f"{to!r} is not in the contact directory. Use lookup_contact to get the address."
        )
    if not subject.strip() or not body.strip():
        raise ToolError("Both subject and body are required.")

    now = datetime.now(timezone.utc)
    # 8-bit UTF-8 with unwrapped lines, so the saved file reads as plain text.
    message = EmailMessage(policy=policy.default.clone(max_line_length=None, utf8=True))
    message["From"] = SENDER
    message["To"] = to
    message["Subject"] = subject
    message["Date"] = format_datetime(now)
    message["Message-ID"] = make_msgid(domain="example.com")
    message["X-Mock-Delivery"] = "true"
    message.set_content(body, cte="8bit")

    OUTBOX_DIR.mkdir(exist_ok=True)
    path = OUTBOX_DIR / f"{now:%Y%m%dT%H%M%S%fZ}_{to.split('@')[0]}.eml"
    path.write_bytes(bytes(message))
    return json.dumps(
        {
            "status": "sent (mock: saved to outbox, not delivered)",
            "to": to,
            "message_id": message["Message-ID"],
            "saved_to": os.path.relpath(path, BASE_DIR),
        }
    )


# --------------------------------------------------------------------------
# Word counter
# --------------------------------------------------------------------------


def count_words(text: str) -> str:
    return str(len(text.split()))


# --------------------------------------------------------------------------
# Tool registry
# --------------------------------------------------------------------------

DESCRIPTIONS = {
    "search_knowledge_base": (
        "Search the company knowledge base (HR policy documents) and return the most "
        "relevant passages, one per line, each with its source file and a relevance score. "
        "Run several focused queries to cover a topic fully."
    ),
    "lookup_contact": (
        "Look up a person in the company contact directory by full or partial name. "
        "Returns the matching contacts with their email addresses."
    ),
    "send_email": (
        "Send an email through the company mail service. The recipient address must "
        "come from lookup_contact."
    ),
    "count_words": "Count the words in a piece of text. Use it to check a length limit.",
}

ALL_TOOLS: dict[str, Tool] = {
    "search_knowledge_base": Tool(
        name="search_knowledge_base",
        description=DESCRIPTIONS["search_knowledge_base"],
        input_schema={
            "type": "object",
            "properties": {
                "query": {"type": "string", "description": "Keywords to search for."},
                "top_k": {"type": "integer", "description": "Passages to return (default 5, max 10)."},
            },
            "required": ["query"],
        },
        handler=search_knowledge_base,
    ),
    "lookup_contact": Tool(
        name="lookup_contact",
        description=DESCRIPTIONS["lookup_contact"],
        input_schema={
            "type": "object",
            "properties": {"name": {"type": "string", "description": "Full or partial name."}},
            "required": ["name"],
        },
        handler=lookup_contact,
    ),
    "send_email": Tool(
        name="send_email",
        description=DESCRIPTIONS["send_email"],
        input_schema={
            "type": "object",
            "properties": {
                "to": {"type": "string", "description": "Recipient email address."},
                "subject": {"type": "string"},
                "body": {"type": "string", "description": "Plain-text email body."},
            },
            "required": ["to", "subject", "body"],
        },
        handler=send_email,
    ),
    "count_words": Tool(
        name="count_words",
        description=DESCRIPTIONS["count_words"],
        input_schema={
            "type": "object",
            "properties": {"text": {"type": "string"}},
            "required": ["text"],
        },
        handler=count_words,
    ),
}


class LocalToolbox:
    """Hands out tools that run in this process."""

    def get(self, names: list[str]) -> list[Tool]:
        return [ALL_TOOLS[name] for name in names]

    def close(self) -> None:
        pass
