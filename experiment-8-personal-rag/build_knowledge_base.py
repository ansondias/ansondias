"""Generate the sample ``Student Knowledge Base`` documents.

Run once to (re)create the personal documents the RAG system indexes:

    python build_knowledge_base.py

Formats produced: Markdown (.md), plain text (.txt), PDF (.pdf), Word (.docx).

NOTE ON DATA
------------
The name, e-mail, LinkedIn/GitHub handles, open-source projects and technical
skills are real and public. The academic transcript, internship certificate and
certifications use **illustrative placeholder values** so the lab is complete and
answerable end-to-end; each generated "official" document is footed with a note
saying so. Replace them with your real records before using this for anything
other than the Experiment 8 demo.
"""
from __future__ import annotations

from pathlib import Path

from docx import Document as DocxDocument
from docx.shared import Pt
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle
from reportlab.lib import colors

KB = Path(__file__).resolve().parent / "Student Knowledge Base"
KB.mkdir(parents=True, exist_ok=True)

SAMPLE_NOTE = (
    "Sample document generated for Experiment 8 (Personal RAG lab). "
    "Academic/placeholder details are illustrative; replace with official records."
)


# ---------------------------------------------------------------------------
# 1. Resume (Markdown)
# ---------------------------------------------------------------------------
RESUME_MD = """# Anson Dias — Resume

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
"""


# ---------------------------------------------------------------------------
# 2. Certifications (plain text)
# ---------------------------------------------------------------------------
CERTS_TXT = f"""CERTIFICATIONS AND COURSES — ANSON DIAS
========================================

1. Deep Learning Specialization — DeepLearning.AI (Coursera)
2. Natural Language Processing with Transformers — Hugging Face (course)
3. Machine Learning — Stanford Online (Coursera)
4. Docker for Developers — online certificate
5. CS50x: Introduction to Computer Science — HarvardX

Skills validated: deep learning, transformers, NLP, model fine-tuning,
containerization with Docker, and core computer science.

{SAMPLE_NOTE}
"""


# ---------------------------------------------------------------------------
# 3. Achievements and awards (Markdown)
# ---------------------------------------------------------------------------
ACHIEVEMENTS_MD = f"""# Achievements and Awards — Anson Dias

- **Winner**, Inter-College AI Hackathon 2024 — built a retrieval-augmented
  assistant over lecture notes in 24 hours.
- **Finalist**, National Machine Learning Challenge 2023.
- Published the open-source project **tiny-transformer-lm**, which reproduces a
  GPT-style language model from scratch and includes an original ablation study.
- Achieved **86.8% accuracy** fine-tuning GPT-2 for IMDb sentiment classification.
- Demonstrated **~24x throughput scaling** when load-testing an LLM served with vLLM.
- Dean's List for academic performance (CGPA 8.74 / 10).

_{SAMPLE_NOTE}_
"""


# ---------------------------------------------------------------------------
# 4. Project report (Markdown)
# ---------------------------------------------------------------------------
PROJECT_REPORT_MD = """# Project Report — High-Throughput LLM Serving with vLLM

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
"""


def write_text_files() -> None:
    (KB / "resume.md").write_text(RESUME_MD, encoding="utf-8")
    (KB / "certifications.txt").write_text(CERTS_TXT, encoding="utf-8")
    (KB / "achievements_and_awards.md").write_text(ACHIEVEMENTS_MD, encoding="utf-8")
    (KB / "project_report_vllm.md").write_text(PROJECT_REPORT_MD, encoding="utf-8")


# ---------------------------------------------------------------------------
# 5. Academic transcript (PDF via reportlab)
# ---------------------------------------------------------------------------
def write_transcript_pdf() -> None:
    styles = getSampleStyleSheet()
    title = ParagraphStyle("t", parent=styles["Title"], fontSize=16)
    small = ParagraphStyle("s", parent=styles["Normal"], fontSize=8, textColor=colors.grey)

    doc = SimpleDocTemplate(str(KB / "academic_transcript.pdf"), pagesize=A4,
                            topMargin=20 * mm, bottomMargin=20 * mm)
    story = [
        Paragraph("Example Institute of Technology", title),
        Paragraph("Official Academic Transcript", styles["Heading2"]),
        Spacer(1, 6 * mm),
        Paragraph("<b>Student name:</b> Anson Dias", styles["Normal"]),
        Paragraph("<b>Programme:</b> Bachelor of Engineering (B.E.), Computer Engineering", styles["Normal"]),
        Paragraph("<b>Duration:</b> 2021 - 2025", styles["Normal"]),
        Paragraph("<b>Cumulative CGPA:</b> 8.74 / 10 (approx. 83%)", styles["Normal"]),
        Spacer(1, 6 * mm),
    ]

    rows = [
        ["Semester", "Key courses", "SGPA"],
        ["Sem 5", "Machine Learning, Operating Systems, DBMS", "8.6"],
        ["Sem 6", "Deep Learning, Computer Networks, Cloud Computing", "8.8"],
        ["Sem 7", "Natural Language Processing, Big Data", "8.9"],
        ["Sem 8", "Final-Year Project (tiny-transformer-lm), Electives", "9.0"],
    ]
    table = Table(rows, colWidths=[28 * mm, 100 * mm, 22 * mm])
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#2d3748")),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("GRID", (0, 0), (-1, -1), 0.5, colors.grey),
        ("FONTSIZE", (0, 0), (-1, -1), 9),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
    ]))
    story.append(table)
    story.append(Spacer(1, 8 * mm))
    story.append(Paragraph("Final-year project: tiny-transformer-lm, implemented in Python (PyTorch).",
                           styles["Normal"]))
    story.append(Spacer(1, 10 * mm))
    story.append(Paragraph(SAMPLE_NOTE, small))
    doc.build(story)


# ---------------------------------------------------------------------------
# 6. Internship completion certificate (DOCX via python-docx)
# ---------------------------------------------------------------------------
def write_internship_docx() -> None:
    d = DocxDocument()
    style = d.styles["Normal"]
    style.font.name = "Calibri"
    style.font.size = Pt(11)

    h = d.add_heading("Internship Completion Certificate", level=0)
    d.add_heading("Northwind AI (sample employer)", level=2)
    d.add_paragraph(
        "This is to certify that Anson Dias successfully completed an internship "
        "as a Machine Learning Engineering Intern at Northwind AI from "
        "May 2024 to July 2024."
    )
    d.add_paragraph(
        "During the internship, Anson built retrieval-augmented generation (RAG) "
        "prototypes using LangChain and the Qdrant vector database, and worked on "
        "improving large language model inference throughput with vLLM. The work "
        "was carried out primarily in Python."
    )
    d.add_paragraph(
        "Anson demonstrated strong skills in Python, PyTorch, NLP and vector search, "
        "and is commended for the quality of the delivered prototypes."
    )
    d.add_paragraph()
    d.add_paragraph("Authorised signatory: Head of ML, Northwind AI")
    note = d.add_paragraph(SAMPLE_NOTE)
    note.runs[0].italic = True
    d.save(str(KB / "internship_certificate.docx"))


# ---------------------------------------------------------------------------
# 7. Statement of Purpose (DOCX)
# ---------------------------------------------------------------------------
def write_sop_docx() -> None:
    d = DocxDocument()
    d.styles["Normal"].font.size = Pt(11)
    d.add_heading("Statement of Purpose", level=0)
    d.add_heading("Anson Dias", level=2)
    d.add_paragraph(
        "My name is Anson Dias and I am an AI/ML engineer. My research interests "
        "are large language model inference and serving performance, efficient "
        "natural language processing, transformer architectures, and "
        "retrieval-augmented generation (RAG) / semantic search."
    )
    d.add_paragraph(
        "I enjoy building systems from first principles. I implemented a GPT-style "
        "language model and a custom BPE tokenizer from scratch in Python, "
        "fine-tuned GPT-2 for sentiment analysis, and load-tested an LLM served "
        "with vLLM to understand throughput and batching."
    )
    d.add_paragraph(
        "Going forward, I want to work on making language models cheaper and faster "
        "to run, and on grounding them in trustworthy private knowledge through "
        "retrieval. I can be reached at ansondias18@gmail.com or via GitHub "
        "(github.com/ansondias) and LinkedIn."
    )
    d.save(str(KB / "statement_of_purpose.docx"))


def main() -> None:
    write_text_files()
    write_transcript_pdf()
    write_internship_docx()
    write_sop_docx()
    files = sorted(p.name for p in KB.iterdir() if p.is_file())
    print(f"Wrote {len(files)} documents to '{KB.name}/':")
    for name in files:
        size = (KB / name).stat().st_size
        print(f"  - {name:32s} ({size:,} bytes)")


if __name__ == "__main__":
    main()
