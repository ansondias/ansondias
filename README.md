### Hi, I'm Anson

AI/ML engineer focused on **LLMs, NLP and retrieval**. I like building things from first principles (tokenizers, transformers) and then measuring how they actually behave, from fine-tuning a model to load-testing it in a serving engine.

**Currently exploring:** LLM inference and serving performance · fine-tuning · semantic search / RAG

---

#### Featured projects

| Project | What it shows |
|---|---|
| [**vllm-high-throughput-serving**](https://github.com/ansondias/vllm-high-throughput-serving) | Served Qwen2.5-0.5B with vLLM on a Tesla T4 and load-tested it. Throughput scaled ~24× (162 → 3,963 tok/s) from 1 to 50 concurrent requests while wall time stayed between 0.8 and 1.6 s. Also measured continuous batching (49 requests in one batch) and PagedAttention KV-cache capacity. |
| [**tiny-transformer-lm**](https://github.com/ansondias/tiny-transformer-lm) | GPT-style decoder-only LM built from scratch in plain PyTorch, with no `nn.Transformer` or tokenizer libraries: custom BPE, causal multi-head attention, training loop and generation. Includes an ablation showing multi-head attention gives little benefit at this scale, and that the single-head variant's apparent edge is memorization. |
| [**gpt2-imdb-sentiment**](https://github.com/ansondias/gpt2-imdb-sentiment) | Fine-tuned GPT-2 (124M) with a classification head on IMDb reviews using the Hugging Face `Trainer`, reaching 86.8% eval accuracy, and served it through a Gradio app. |
| [**semantic-search-qdrant**](https://github.com/ansondias/semantic-search-qdrant) | Meaning-based search with Sentence-Transformer embeddings (`all-MiniLM-L6-v2`) and Qdrant, with metadata filtering and PDF chunk search. |
| [**ml-pipeline-architect**](https://github.com/ansondias/ml-pipeline-architect) | Experimental Gemini-powered app (React/TypeScript + FastAPI) that turns a plain-English ML problem into a scikit-learn pipeline. |

**Also:** [metadata-forensics-lab](https://github.com/ansondias/metadata-forensics-lab), a digital-forensics lab on reading, forging and recovering metadata in DOCX, PDF and MP3 files with ExifTool.

---

#### Tools I work with

- **ML / LLMs:** Python · PyTorch · Hugging Face Transformers · vLLM · Sentence-Transformers · scikit-learn
- **Retrieval & apps:** Qdrant · FastAPI · Gradio · Docker · TypeScript / React

---

#### Connect

[LinkedIn](https://www.linkedin.com/in/anson-dias-6411072a1/) · [ansondias18@gmail.com](mailto:ansondias18@gmail.com)
