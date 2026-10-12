# Project Report — High-Throughput LLM Serving with vLLM

**Author:** Anson Dias
**Repository:** vllm-high-throughput-serving

## Objective
Serve a small instruction-tuned LLM (Qwen2.5-0.5B) efficiently and measure how
throughput and latency behave as concurrency increases.

## Method
- Deployed the model with **vLLM** on a single **NVIDIA Tesla T4** GPU.
- Issued batches of concurrent completion requests (1, 10, 25, 50 in flight).
- Recorded tokens/second, wall-clock time and continuous-batching behaviour.
- Inspected PagedAttention KV-cache utilisation.

## Results
- Throughput scaled roughly **24x**, from ~162 tokens/s at 1 request to
  ~3,963 tokens/s at 50 concurrent requests.
- End-to-end wall time stayed between **0.8 and 1.6 seconds** across loads.
- vLLM packed as many as **49 requests into a single continuous batch**.

## Tools
Python, vLLM, PyTorch, Hugging Face model hub, CUDA (Tesla T4).

## Conclusion
Continuous batching and PagedAttention let a tiny model reach very high
aggregate throughput on modest hardware, making it practical for RAG back-ends.
