"""Central configuration for the Personal RAG system (Experiment 8)."""
from __future__ import annotations

import os
from pathlib import Path

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
BASE_DIR = Path(__file__).resolve().parent
KNOWLEDGE_BASE_DIR = BASE_DIR / "Student Knowledge Base"
CHROMA_DIR = BASE_DIR / "chroma_db"
COLLECTION_NAME = "student_knowledge_base"

# File types we ingest from the knowledge base.
SUPPORTED_EXTENSIONS = {".pdf", ".docx", ".txt", ".md"}

# ---------------------------------------------------------------------------
# Chunking
# ---------------------------------------------------------------------------
CHUNK_SIZE = 750          # characters per chunk (keeps a section's heading + content together)
CHUNK_OVERLAP = 120       # overlap keeps sentences from being split mid-thought

# ---------------------------------------------------------------------------
# Retrieval
# ---------------------------------------------------------------------------
TOP_K = 5                 # how many chunks to retrieve per question (per retriever)
# If the best match is further than this cosine distance, we treat the answer
# as "not in the knowledge base" and refuse rather than guess.
# (Chroma returns cosine distance in [0, 2]; smaller = more similar.)
RELEVANCE_DISTANCE_THRESHOLD = 1.15

# ---------------------------------------------------------------------------
# LLM (answer generation)
# ---------------------------------------------------------------------------
# The documented, primary generator is Claude. Set ANTHROPIC_API_KEY to use it.
# Override the model with LLM_MODEL if you want a cheaper / different one.
LLM_MODEL = os.environ.get("LLM_MODEL", "claude-opus-5-5")
LLM_MAX_TOKENS = 600

# When no API key is present, the system falls back to an offline, purely
# extractive responder that answers *only* from retrieved chunks. This keeps
# the pipeline fully runnable (and gradable) without credentials.
def anthropic_api_key_present() -> bool:
    return bool(os.environ.get("ANTHROPIC_API_KEY"))
