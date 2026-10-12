#!/usr/bin/env bash
# One-command run of the Personal RAG system with a FREE local Ollama LLM.
#
#   ./run_with_ollama.sh                     # run the sample question set
#   ./run_with_ollama.sh "What is the CGPA?" # ask one question
#
# Prerequisite: install Ollama once from https://ollama.com/download
set -euo pipefail
cd "$(dirname "$0")"

MODEL="${LLM_MODEL:-llama3.2}"

if ! command -v ollama >/dev/null 2>&1; then
  echo "Ollama is not installed. Get it (free) from https://ollama.com/download, then re-run."
  exit 1
fi

# Start the Ollama server if it isn't already answering.
if ! curl -sf http://localhost:11434/api/tags >/dev/null 2>&1; then
  echo "Starting Ollama server in the background..."
  ollama serve >/tmp/ollama-serve.log 2>&1 &
  for _ in $(seq 1 20); do
    curl -sf http://localhost:11434/api/tags >/dev/null 2>&1 && break
    sleep 0.5
  done
fi

echo "Ensuring model '$MODEL' is available (first run downloads it)..."
ollama pull "$MODEL"

export LLM_PROVIDER=ollama LLM_MODEL="$MODEL"
echo "Building the vector knowledge base..."
python ingest.py
echo "Answering with Ollama ($MODEL)..."
python app.py "$@"
