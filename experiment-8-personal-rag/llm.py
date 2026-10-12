"""Pluggable answer-generation backends.

The default is the built-in **offline extractive responder** (no network, no API
key). To generate answers with a real LLM, set ``LLM_PROVIDER`` (and the matching
key / server). All of these are free or have a free tier:

    LLM_PROVIDER=ollama     # 100% free, runs locally, NO API key
                            #   install Ollama, then: `ollama pull llama3.2`
                            #   optional: OLLAMA_BASE_URL (default http://localhost:11434)
    LLM_PROVIDER=groq       # free API tier — get a key at console.groq.com
                            #   needs GROQ_API_KEY
    LLM_PROVIDER=gemini     # free API tier — get a key at aistudio.google.com
                            #   needs GOOGLE_API_KEY
    LLM_PROVIDER=anthropic  # Claude — needs ANTHROPIC_API_KEY (console.anthropic.com)

Override the model for any provider with LLM_MODEL. With LLM_PROVIDER unset
(``auto``) the system uses whichever API key is present, else offline mode.

Each provider's integration package is imported lazily and only needs to be
installed if you actually use it (see requirements.txt).
"""
from __future__ import annotations

import os
from typing import Optional, Tuple

MAX_TOKENS = 600

# provider -> (default model, human-readable label)
_DEFAULT_MODEL = {
    "anthropic": "claude-opus-5-5",
    "ollama": "llama3.2",
    "groq": "llama-3.3-70b-versatile",
    "gemini": "gemini-1.5-flash",
}
_LABEL = {
    "anthropic": "Claude (Anthropic)",
    "ollama": "Ollama (local)",
    "groq": "Groq",
    "gemini": "Google Gemini",
    "offline": "offline extractive responder",
}


def active_provider() -> str:
    """Resolve which backend to use from LLM_PROVIDER / available keys."""
    p = os.environ.get("LLM_PROVIDER", "auto").lower().strip()
    if p and p != "auto":
        return p
    if os.environ.get("ANTHROPIC_API_KEY"):
        return "anthropic"
    if os.environ.get("GROQ_API_KEY"):
        return "groq"
    if os.environ.get("GOOGLE_API_KEY"):
        return "gemini"
    return "offline"


def label(provider: Optional[str] = None) -> str:
    provider = provider or active_provider()
    return _LABEL.get(provider, provider)


def _model_for(provider: str) -> str:
    return os.environ.get("LLM_MODEL", _DEFAULT_MODEL.get(provider, ""))


def build_chat_model() -> Tuple[Optional[object], str]:
    """Return (langchain chat model, provider). The model is None for offline."""
    provider = active_provider()
    model_name = _model_for(provider)

    if provider == "anthropic":
        from langchain_anthropic import ChatAnthropic
        return ChatAnthropic(model=model_name, max_tokens=MAX_TOKENS), provider
    if provider == "ollama":
        from langchain_ollama import ChatOllama
        return ChatOllama(
            model=model_name,
            base_url=os.environ.get("OLLAMA_BASE_URL", "http://localhost:11434"),
            num_predict=MAX_TOKENS,
        ), provider
    if provider == "groq":
        from langchain_groq import ChatGroq
        return ChatGroq(model=model_name, max_tokens=MAX_TOKENS), provider
    if provider == "gemini":
        from langchain_google_genai import ChatGoogleGenerativeAI
        return ChatGoogleGenerativeAI(model=model_name, max_output_tokens=MAX_TOKENS), provider

    return None, "offline"
