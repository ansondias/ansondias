"""Central configuration for the Personal RAG system (Experiment 8)."""
from __future__ import annotations

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
# The answer generator is pluggable — see llm.py. Choose a backend with the
# LLM_PROVIDER env var (anthropic / ollama / groq / gemini). With none set, the
# system uses a built-in offline extractive responder so the pipeline runs with
# no network and no API key.
