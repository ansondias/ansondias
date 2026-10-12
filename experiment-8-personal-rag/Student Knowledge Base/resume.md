# Anson Dias — Resume

## Contact
- **Full name:** Anson Dias
- **Email:** ansondias18@gmail.com
- **GitHub:** https://github.com/ansondias
- **LinkedIn:** https://www.linkedin.com/in/anson-dias-6411072a1/
- **Location:** India

## Summary
AI/ML engineer focused on large language models, NLP and retrieval. I build
systems from first principles (custom tokenizers and transformers) and then
measure how they actually behave, from fine-tuning a model to load-testing it
in a serving engine.

## Education
- **Example Institute of Technology** — B.E. in Computer Engineering (2021–2025)
- **CGPA:** 8.74 / 10

## Technical skills
- **Programming languages:** Python, TypeScript, JavaScript, SQL, C
- **ML / LLMs:** PyTorch, Hugging Face Transformers, vLLM, Sentence-Transformers, scikit-learn
- **Retrieval & data:** Qdrant, ChromaDB, RAG pipelines, embeddings, semantic search
- **Apps & infra:** FastAPI, Gradio, Docker, React, Git, Linux

## Projects
- **vllm-high-throughput-serving** — Served Qwen2.5-0.5B with vLLM on a Tesla T4
  and load-tested it; throughput scaled ~24x (162 to 3,963 tokens/s) from 1 to 50
  concurrent requests. Studied continuous batching and PagedAttention KV-cache.
- **tiny-transformer-lm** *(final-year project)* — A GPT-style decoder-only
  language model built from scratch in **Python** with PyTorch: custom BPE
  tokenizer, causal multi-head attention, training loop and text generation,
  plus an ablation study on multi-head attention.
- **gpt2-imdb-sentiment** — Fine-tuned GPT-2 (124M) with a classification head on
  IMDb reviews using the Hugging Face Trainer, reaching 86.8% eval accuracy, and
  served it through a Gradio app.
- **semantic-search-qdrant** — Meaning-based search with Sentence-Transformer
  embeddings (all-MiniLM-L6-v2) and Qdrant, with metadata filtering and PDF chunk search.
- **ml-pipeline-architect** — Gemini-powered app (React/TypeScript + FastAPI) that
  turns a plain-English ML problem into a scikit-learn pipeline.
- **metadata-forensics-lab** — Digital-forensics lab on reading, forging and
  recovering metadata in DOCX, PDF and MP3 files with ExifTool.

## Internships
- **ML Engineering Intern — Northwind AI (sample):** Built RAG prototypes with
  LangChain and Qdrant and optimised LLM inference throughput. (May–July 2024)
- **Research Intern — Example Institute NLP Lab (sample):** Worked on transformer
  interpretability and tokenization experiments. (Dec 2023–Feb 2024)

## Final-year project language
The final-year project (tiny-transformer-lm) was implemented in **Python** using PyTorch.
