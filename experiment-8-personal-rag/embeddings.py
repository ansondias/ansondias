"""Embedding model adapter.

We use the ``all-MiniLM-L6-v2`` sentence-embedding model (384 dimensions).
chromadb bundles it as a self-contained ONNX model, so it runs on CPU with no
PyTorch and no Hugging Face download. This thin adapter exposes that model
through LangChain's ``Embeddings`` interface so the same vectors are used for
both indexing and querying.
"""
from __future__ import annotations

from typing import List

from langchain_core.embeddings import Embeddings


class LocalONNXEmbeddings(Embeddings):
    """LangChain Embeddings backed by chromadb's bundled all-MiniLM-L6-v2 ONNX model."""

    def __init__(self) -> None:
        # Imported lazily so that importing this module never triggers a download.
        from chromadb.utils import embedding_functions

        self._ef = embedding_functions.ONNXMiniLM_L6_V2()

    def embed_documents(self, texts: List[str]) -> List[List[float]]:
        return [list(map(float, vec)) for vec in self._ef(list(texts))]

    def embed_query(self, text: str) -> List[float]:
        return list(map(float, self._ef([text])[0]))
