"""Build a single submission-ready PDF lab report with the screenshots embedded.

    python make_report.py   ->   Experiment-8-Personal-RAG-Report.pdf
"""
from __future__ import annotations

from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Image,
                                PageBreak, Table, TableStyle, ListFlowable, ListItem)
from reportlab.lib.utils import ImageReader

BASE = Path(__file__).resolve().parent
SHOTS = BASE / "screenshots"
OUT = BASE / "Experiment-8-Personal-RAG-Report.pdf"

PAGE_W, PAGE_H = A4
MARGIN = 18 * mm
USABLE_W = PAGE_W - 2 * MARGIN

styles = getSampleStyleSheet()
H1 = ParagraphStyle("H1", parent=styles["Title"], fontSize=20, spaceAfter=4)
SUB = ParagraphStyle("SUB", parent=styles["Normal"], fontSize=11,
                     textColor=colors.HexColor("#555"), spaceAfter=10)
H2 = ParagraphStyle("H2", parent=styles["Heading2"], fontSize=14,
                    textColor=colors.HexColor("#1a365d"), spaceBefore=12, spaceAfter=4)
BODY = ParagraphStyle("BODY", parent=styles["Normal"], fontSize=10.5, leading=15)
CAP = ParagraphStyle("CAP", parent=styles["Normal"], fontSize=9,
                     textColor=colors.HexColor("#666"), spaceBefore=3, spaceAfter=10)


def img(name: str, max_h_mm: float = 150):
    path = SHOTS / name
    iw, ih = ImageReader(str(path)).getSize()
    scale = min(USABLE_W / iw, (max_h_mm * mm) / ih)
    return Image(str(path), width=iw * scale, height=ih * scale)


def bullets(items):
    return ListFlowable([ListItem(Paragraph(t, BODY), leftIndent=6) for t in items],
                        bulletType="bullet", start="•", leftIndent=12)


def tech_table():
    rows = [
        ["Layer", "Choice"],
        ["Language", "Python 3"],
        ["Framework", "LangChain"],
        ["Document parsing", "pypdf (PDF), python-docx (DOCX), built-in (TXT/MD)"],
        ["Text splitting", "RecursiveCharacterTextSplitter"],
        ["Embeddings", "all-MiniLM-L6-v2 (384-dim ONNX, CPU)"],
        ["Vector database", "Chroma (persistent, cosine)"],
        ["Retrieval", "Hybrid: vector similarity + keyword"],
        ["LLM (generation)", "Pluggable: Ollama (free, local) / Groq / Gemini / Claude"],
        ["Offline fallback", "Built-in extractive responder (no key/network)"],
    ]
    t = Table(rows, colWidths=[42 * mm, USABLE_W - 42 * mm])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1a365d")),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("FONTSIZE", (0, 0), (-1, -1), 9.5),
        ("GRID", (0, 0), (-1, -1), 0.4, colors.HexColor("#bbb")),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#f2f5f9")]),
        ("TOPPADDING", (0, 0), (-1, -1), 4), ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    return t


def build():
    story = []

    # --- Title ---
    story += [
        Paragraph("Experiment 8 — Personal Knowledge Base using RAG", H1),
        Paragraph("Build a Personal RAG-Based Question Answering System &nbsp;|&nbsp; "
                  "Anson Dias", SUB),
    ]

    story += [Paragraph("Objective", H2), Paragraph(
        "Design and implement a Personal Retrieval-Augmented Generation (RAG) "
        "question-answering system that retrieves information from an individual's "
        "personal document collection using embeddings, a vector database, and a "
        "Large Language Model, answering <b>only</b> from the uploaded documents and "
        "clearly indicating when information is unavailable.", BODY)]

    story += [Paragraph("Tech stack", H2), tech_table()]

    story += [Paragraph("How it works", H2), Paragraph(
        "<b>Ingestion:</b> the personal documents (resume, academic record, "
        "internship, certifications, project report, achievements, SOP — in PDF, "
        "DOCX, TXT and Markdown) are loaded, split into overlapping chunks, embedded "
        "with all-MiniLM-L6-v2, and stored in a persistent Chroma collection. "
        "<b>Query time:</b> the question is embedded, the most relevant chunks are "
        "retrieved by a hybrid vector + keyword search, and an LLM (or the offline "
        "extractive responder) writes an answer grounded strictly in those chunks. "
        "If no retrieved passage matches, the system replies that the information is "
        "not in the knowledge base instead of guessing.", BODY)]

    story += [Paragraph("Knowledge base documents", H2), bullets([
        "<b>resume.md</b> — contact, education, skills, projects, experience",
        "<b>academic_transcript.pdf</b> — university, programme, GPA, coursework",
        "<b>internship_certificate.docx</b> — internship experience (CostumePeti)",
        "<b>certifications.txt</b> — certificates and courses",
        "<b>project_report.md</b> — project report (accounting automation)",
        "<b>achievements_and_awards.md</b> — key achievements & highlights",
        "<b>statement_of_purpose.docx</b> — SOP with interests + contact",
    ])]

    # --- Screenshots walkthrough ---
    shots = [
        ("01_generate_documents.png", "Step 1 — Generate the Student Knowledge Base documents."),
        ("02_knowledge_base_files.png", "Step 2 — The personal documents across PDF, DOCX, TXT and Markdown."),
        ("03_ingest_vector_db.png", "Step 3 — Ingestion: load → split → embed → store in Chroma."),
        ("04_qa_part1.png", "Step 4 — Question answering over the knowledge base (1/2)."),
        ("05_qa_part2.png", "Step 4 — Question answering + guardrail refusal (2/2)."),
        ("06_single_question.png", "Step 5 — Asking a single question from the command line."),
        ("07_guardrail_refusal.png", "Step 6 — Guardrail: refusing information not in the documents."),
    ]
    story += [PageBreak(), Paragraph("Walkthrough (screenshots)", H2)]
    for i, (name, cap) in enumerate(shots):
        if not (SHOTS / name).exists():
            continue
        story += [img(name), Paragraph(cap, CAP)]
        if i in (1, 3, 4, 5):  # page breaks so tall captures stay readable
            story.append(PageBreak())

    # --- Results ---
    story += [PageBreak(), Paragraph("Results — all required questions answered", H2),
              Paragraph("Every question required by the assignment is answered from the "
                        "documents; a question whose answer is absent (blood group) is "
                        "correctly refused.", BODY)]
    qa = [
        ["Question", "Answered (from documents)"],
        ["Full name", "Anson Dias"],
        ["Programming languages", "Python, JavaScript, Solidity, SQL, C++"],
        ["Internship", "AI Content & Prompt Engineering Intern, CostumePeti (2024)"],
        ["Certifications", "Google ML Crash Course; DeepLearning.AI ML Specialization"],
        ["Final-year project language", "Python (ML Specialization capstone)"],
        ["CGPA", "6.70 / 10 (through 3rd year)"],
        ["College / university", "Atlas SkillTech University, Mumbai"],
        ["Research interests", "ML pipelines, NLP, blockchain, operational analytics"],
        ["Email / contact", "ansondias18@gmail.com, +91 90829 24744"],
        ["Blood group", "Not in knowledge base → refused"],
    ]
    t = Table(qa, colWidths=[55 * mm, USABLE_W - 55 * mm])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1a365d")),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("FONTSIZE", (0, 0), (-1, -1), 9),
        ("GRID", (0, 0), (-1, -1), 0.4, colors.HexColor("#bbb")),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#f2f5f9")]),
        ("BACKGROUND", (0, 11), (-1, 11), colors.HexColor("#fff3f0")),
    ]))
    story += [t]

    story += [Paragraph("Conclusion", H2), Paragraph(
        "The system builds a searchable vector knowledge base from personal documents "
        "and answers natural-language questions grounded only in retrieved content, "
        "refusing anything not present. The answer generator is pluggable across free "
        "LLMs (Ollama runs locally with no API key); the retrieval pipeline and the "
        "grounding guardrail are identical regardless of generator.", BODY)]

    SimpleDocTemplate(str(OUT), pagesize=A4, topMargin=MARGIN, bottomMargin=MARGIN,
                      leftMargin=MARGIN, rightMargin=MARGIN,
                      title="Experiment 8 - Personal RAG Report",
                      author="Anson Dias").build(story)
    print("Wrote", OUT.name, f"({OUT.stat().st_size:,} bytes)")


if __name__ == "__main__":
    build()
