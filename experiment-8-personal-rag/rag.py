"""Retrieval-Augmented Generation core.

    question  ->  embed  ->  retrieve top-k chunks (hybrid: vector + keyword)
              ->  relevance gate  ->  generate grounded answer
                                        (Claude LLM, or offline extractive fallback)

The system answers **only** from the retrieved documents. When nothing relevant
is retrieved it says the information is unavailable instead of guessing.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Dict, List, Tuple

from langchain_core.documents import Document
from langchain_chroma import Chroma

import config
from embeddings import LocalONNXEmbeddings

NOT_FOUND = (
    "I could not find that information in the knowledge base. "
    "It does not appear in any of the uploaded documents."
)

SYSTEM_PROMPT = """You are a careful assistant that answers questions about a \
student using ONLY the context extracted from their personal documents.

Rules:
- Use only facts present in the CONTEXT. Do not use outside knowledge.
- If the answer is not contained in the context, reply exactly: "{not_found}"
- Be concise and factual. When useful, mention which document the fact comes from.
""".format(not_found=NOT_FOUND)

# Generic question words carry no answer content -> ignore when matching.
_STOPWORDS = {
    "what", "which", "who", "whom", "where", "when", "the", "a", "an", "is",
    "are", "was", "were", "does", "do", "did", "has", "have", "had", "of",
    "to", "in", "on", "for", "and", "or", "s", "student", "students", "list",
    "any", "if", "available", "his", "her", "their", "please", "tell", "me",
    "about", "completed", "undertaken", "earned", "received", "listed", "know",
    "knows", "belong", "belongs", "undertake", "that", "this", "with", "from",
    "it", "be", "there",
}

# Lightweight query expansion so that a question worded differently from the
# document still matches (classic information-retrieval synonym expansion).
_SYNONYMS: Dict[str, List[str]] = {
    "name": ["name"],
    "college": ["institute", "university", "education", "programme", "b.e", "degree", "school"],
    "university": ["institute", "college", "education", "programme", "b.e", "degree", "school"],
    "email": ["e-mail", "gmail", "mail", "@", "contact"],
    "contact": ["email", "e-mail", "gmail", "phone", "linkedin", "github", "reach"],
    "internship": ["intern", "internships"],
    "internships": ["intern", "internship"],
    "cgpa": ["gpa", "sgpa", "percentage", "grade"],
    "percentage": ["cgpa", "gpa", "percent"],
    "project": ["projects", "repository", "built", "developed"],
    "projects": ["project", "repository", "built", "developed"],
    "achievement": ["achievements", "award", "awards", "winner", "finalist"],
    "achievements": ["achievement", "award", "awards", "winner", "finalist"],
    "award": ["awards", "achievement", "achievements", "winner", "finalist"],
    "awards": ["award", "achievement", "achievements", "winner", "finalist"],
    "certification": ["certifications", "certificate", "certificates", "course", "courses"],
    "certifications": ["certification", "certificate", "certificates", "course", "courses"],
    "research": ["research"],
    "interest": ["interests", "interested"],
    "interests": ["interest", "interested"],
    "language": ["languages", "python", "typescript"],
    "languages": ["language", "python", "typescript"],
    "skill": ["skills", "technical"],
    "skills": ["skill", "technical"],
    "final-year": ["final", "final-year", "project"],
}


@dataclass
class RagResult:
    question: str
    answer: str
    used_llm: bool
    found: bool
    sources: List[str] = field(default_factory=list)
    retrieved: List[Tuple[str, float]] = field(default_factory=list)  # (source, distance)


_store: Chroma | None = None


def get_vectorstore() -> Chroma:
    global _store
    if _store is None:
        if not config.CHROMA_DIR.exists():
            raise SystemExit("Vector store not found. Run ingest.py first.")
        _store = Chroma(
            collection_name=config.COLLECTION_NAME,
            embedding_function=LocalONNXEmbeddings(),
            persist_directory=str(config.CHROMA_DIR),
        )
    return _store


# ---------------------------------------------------------------------------
# Term handling
# ---------------------------------------------------------------------------
def _tokenize(text: str) -> List[str]:
    return [w for w in re.findall(r"[a-zA-Z0-9+.#/@-]+", text.lower()) if w]


def _query_terms(question: str) -> List[str]:
    """Content terms of the question, expanded with synonyms."""
    terms: List[str] = []
    for tok in _tokenize(question):
        if tok in _STOPWORDS or len(tok) < 2:
            continue
        terms.append(tok)
        terms.extend(_SYNONYMS.get(tok, []))
    # de-duplicate, keep order
    return list(dict.fromkeys(terms))


def _term_matches(term: str, text_lc: str) -> bool:
    """A term matches if it appears as a substring (for terms of length >= 3,
    which handles plurals/stems like internship(s)) or as a whole token."""
    if len(term) >= 3:
        return term in text_lc
    return bool(re.search(rf"(?<![a-z0-9]){re.escape(term)}(?![a-z0-9])", text_lc))


# ---------------------------------------------------------------------------
# Hybrid retrieval: dense (vector) + sparse (keyword)
# ---------------------------------------------------------------------------
def _all_chunks() -> List[Tuple[str, dict]]:
    data = get_vectorstore().get(include=["documents", "metadatas"])
    return list(zip(data["documents"], data["metadatas"]))


def retrieve(question: str) -> Tuple[List[Tuple[Document, float]], float]:
    """Return (hits, best_vector_distance).

    hits merges vector-similarity results with keyword matches so that both
    semantically-similar and literally-matching chunks are considered.
    """
    store = get_vectorstore()
    vector_hits = store.similarity_search_with_score(question, k=config.TOP_K)
    best_distance = vector_hits[0][1] if vector_hits else 99.0

    merged: "dict[str, Tuple[Document, float]]" = {}
    for doc, dist in vector_hits:
        merged[doc.page_content] = (doc, float(dist))

    terms = _query_terms(question)
    keyword_scored: List[Tuple[int, Document]] = []
    for text, meta in _all_chunks():
        text_lc = text.lower()
        score = sum(1 for t in terms if _term_matches(t, text_lc))
        if score:
            keyword_scored.append((score, Document(page_content=text, metadata=meta)))
    keyword_scored.sort(key=lambda x: x[0], reverse=True)
    for _, doc in keyword_scored[: config.TOP_K]:
        merged.setdefault(doc.page_content, (doc, 99.0))  # distance unknown for keyword-only

    hits = list(merged.values())
    return hits, best_distance


# ---------------------------------------------------------------------------
# Answer generation
# ---------------------------------------------------------------------------
def _format_context(hits: List[Tuple[Document, float]]) -> str:
    blocks = []
    for doc, _ in hits:
        blocks.append(f"[source: {doc.metadata.get('source', '?')}]\n{doc.page_content}")
    return "\n\n---\n\n".join(blocks)


def _generate_with_claude(question: str, context: str) -> str:
    from langchain_anthropic import ChatAnthropic
    from langchain_core.messages import SystemMessage, HumanMessage

    llm = ChatAnthropic(model=config.LLM_MODEL, max_tokens=config.LLM_MAX_TOKENS)
    user = f"CONTEXT:\n{context}\n\nQUESTION: {question}\n\nAnswer using only the context above."
    resp = llm.invoke([SystemMessage(content=SYSTEM_PROMPT), HumanMessage(content=user)])
    return resp.content.strip() if isinstance(resp.content, str) else str(resp.content).strip()


def _clean(line: str) -> str:
    line = re.sub(r"[#*`_>]+", "", line)          # strip markdown markers
    line = re.sub(r"^\s*[-]\s*", "", line)         # strip list bullets
    return line.strip(" \t-")


def _is_heading(cleaned: str, raw: str) -> bool:
    return raw.lstrip().startswith("#") or (
        len(_tokenize(cleaned)) <= 3 and ":" not in cleaned and len(cleaned) < 40
    )


def _extractive_answer(question: str, hits: List[Tuple[Document, float]]) -> str:
    """Offline, no-LLM responder: return the text from retrieved chunks that best
    overlaps the (expanded) question terms. Purely extractive — it only selects
    text that is already in the documents and never invents facts. When a section
    heading matches, it returns the content lines under that heading (e.g. the
    skills list or the achievements bullets), not the bare heading."""
    terms = _query_terms(question)
    if not terms:
        return NOT_FOUND

    candidates: List[Tuple[float, str, str]] = []
    for doc, _ in hits:
        source = doc.metadata.get("source", "?")
        lines = [(_clean(r), r) for r in re.split(r"\n+", doc.page_content)]
        for i, (line, raw) in enumerate(lines):
            if len(line) < 6:
                continue
            if _is_heading(line, raw):
                matched = sum(1 for t in terms if _term_matches(t, line.lower()))
                if not matched:
                    continue
                follow: List[str] = []
                for cl, cr in lines[i + 1:]:
                    if cr.lstrip().startswith("#"):
                        break
                    if len(cl) >= 6:
                        follow.append(cl)
                    if len(follow) >= 2:
                        break
                if not follow:  # don't answer with a bare heading
                    continue
                candidates.append((matched * 2.0 + 1.0, f"{line}: " + " ".join(follow), source))
            else:
                for sent in re.split(r"(?<=[.!?])\s+", line):
                    sent = sent.strip()
                    if len(sent) < 6:
                        continue
                    matched = sum(1 for t in terms if _term_matches(t, sent.lower()))
                    if not matched:
                        continue
                    has_value = (":" in sent) or len(_tokenize(sent)) >= 4
                    candidates.append((matched * 2.0 + (1.0 if has_value else 0.0), sent, source))

    if not candidates or max(c[0] for c in candidates) <= 0:
        return NOT_FOUND

    candidates.sort(key=lambda x: x[0], reverse=True)
    chosen: List[Tuple[str, str]] = []
    for _, text, source in candidates:
        key = text.lower()
        if any(key in c.lower() or c.lower() in key for c, _ in chosen):
            continue
        chosen.append((text, source))
        if len(chosen) >= 3:
            break

    srcs = ", ".join(dict.fromkeys(s for _, s in chosen))
    body = " ".join(t for t, _ in chosen)
    return f"{body}\n(Source: {srcs})"


def answer(question: str) -> RagResult:
    hits, best_distance = retrieve(question)
    retrieved = [
        (d.metadata.get("source", "?"), (None if dist >= 99.0 else round(dist, 4)))
        for d, dist in hits
    ]
    sources = list(dict.fromkeys(s for s, _ in retrieved))

    # Relevance gate. The final grounding decision is made downstream (Claude is
    # instructed to answer only from context; the offline responder refuses when
    # no question term matches any retrieved passage). Here we only catch the
    # degenerate case where retrieval returns nothing usable.
    if not hits or best_distance > config.RELEVANCE_DISTANCE_THRESHOLD:
        return RagResult(question, NOT_FOUND, used_llm=False, found=False,
                         sources=[], retrieved=retrieved)

    context = _format_context(hits)
    if config.anthropic_api_key_present():
        try:
            text = _generate_with_claude(question, context)
            return RagResult(question, text, used_llm=True,
                             found=NOT_FOUND not in text, sources=sources,
                             retrieved=retrieved)
        except Exception as exc:  # fall back rather than crash the demo
            print(f"  [warn] Claude call failed ({type(exc).__name__}); using offline responder.")

    text = _extractive_answer(question, hits)
    return RagResult(question, text, used_llm=False, found=NOT_FOUND not in text,
                     sources=sources, retrieved=retrieved)
