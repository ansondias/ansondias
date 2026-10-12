"""Render terminal-style screenshots of the real pipeline steps.

Each screenshot is a genuine capture: this script *runs* the actual commands,
collects their real stdout, and paints it into a terminal window PNG. No output
is faked. Images are written to ./screenshots/.

    python make_screenshots.py
"""
from __future__ import annotations

import html
import os
import re
import subprocess
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = Path(__file__).resolve().parent
SHOTS = BASE / "screenshots"
SHOTS.mkdir(exist_ok=True)

PY = str(BASE.parent / ".ragvenv" / "bin" / "python")
CHROME = "/opt/pw-browsers/chromium-1194/chrome-linux/chrome"
PROMPT = "ansondias@rag:~/experiment-8-personal-rag$"

ENV = {
    **os.environ,
    "ANONYMIZED_TELEMETRY": "False",
    "CHROMA_TELEMETRY_IMPL": "none",
    "PYTHONWARNINGS": "ignore",
}

_NOISE = re.compile(r"^\s*(Download|.*\d+%|\S+\s+\d+(\.\d+)?\s*[kKMG]?i?B)")


def run(cmd: str) -> str:
    """Run a shell command in the project dir and return filtered stdout+stderr.
    Only download/progress-bar noise is stripped; real output is untouched."""
    proc = subprocess.run(cmd, shell=True, cwd=BASE, env=ENV,
                          capture_output=True, text=True)
    raw = (proc.stdout + proc.stderr).splitlines()
    lines = [ln for ln in raw if not _NOISE.search(ln) and "\x1b" not in ln]
    return "\n".join(lines).rstrip()


def _line_class(ln: str) -> str:
    s = ln.strip()
    if s.startswith("Q:"):
        return "q"
    if s.startswith("A:"):
        return "a"
    if s.startswith("[engine") or s.startswith("(Source") or s.startswith("[warn"):
        return "dim"
    if re.match(r"^\[\d/\d\]", s) or s.startswith("=="):
        return "hl"
    if s.startswith(("Done", "Wrote", "loaded", "reingested", "Finished", "->")) or "->" in s:
        return "ok"
    if s.startswith(("-", "drwx", "total")) or re.search(r"\.\w+\s*\(", s):
        return "file"
    return ""


def _render_body(command: str, output: str) -> str:
    rows = [f'<div class="row cmd"><span class="pr">{html.escape(PROMPT)}</span> '
            f'<span class="cm">{html.escape(command)}</span></div>']
    for ln in output.split("\n"):
        cls = _line_class(ln)
        rows.append(f'<div class="row {cls}">{html.escape(ln) or "&nbsp;"}</div>')
    return "\n".join(rows)


HTML = """<!doctype html><html><head><meta charset="utf-8"><style>
  * {{ box-sizing: border-box; }}
  body {{ margin: 0; background: #0b0e14; }}
  .win {{ width: {width}px; background: #11151f; border-radius: 10px;
          overflow: hidden; border: 1px solid #222838;
          font-family: "DejaVu Sans Mono", "Menlo", monospace; }}
  .bar {{ background: #1b2130; height: 38px; display: flex; align-items: center;
          padding: 0 14px; gap: 8px; border-bottom: 1px solid #222838; }}
  .dot {{ width: 12px; height: 12px; border-radius: 50%; }}
  .r {{ background: #ff5f56; }} .y {{ background: #ffbd2e; }} .g {{ background: #27c93f; }}
  .title {{ color: #8b95a7; font-size: 13px; margin-left: 10px; font-weight: 600; }}
  .body {{ padding: 16px 18px; color: #c6cedd; font-size: 13.5px; line-height: 1.5; }}
  .row {{ white-space: pre-wrap; word-break: break-word; }}
  .cmd {{ margin-bottom: 6px; }}
  .pr {{ color: #27c93f; font-weight: 700; }}
  .cm {{ color: #e6edf3; font-weight: 700; }}
  .q {{ color: #ffd479; font-weight: 700; margin-top: 10px; }}
  .a {{ color: #7ee787; }}
  .dim {{ color: #6b7585; }}
  .hl {{ color: #79c0ff; font-weight: 600; }}
  .ok {{ color: #56d364; }}
  .file {{ color: #a5b3c9; }}
</style></head><body>
  <div class="win">
    <div class="bar"><span class="dot r"></span><span class="dot y"></span>
      <span class="dot g"></span><span class="title">{title}</span></div>
    <div class="body">{rows}</div>
  </div>
</body></html>"""


def shot(page, name: str, title: str, command: str, output: str, width: int = 1020) -> None:
    page.set_content(HTML.format(width=width, title=html.escape(title),
                                 rows=_render_body(command, output)))
    page.locator(".win").screenshot(path=str(SHOTS / name))
    print(f"  wrote screenshots/{name}")


def main() -> None:
    print("Capturing real command output ...")
    out_build = run(f"{PY} build_knowledge_base.py")
    out_ls = run('ls -lh "Student Knowledge Base"')
    out_ingest = run(f"{PY} ingest.py")
    out_samples = run(f"{PY} app.py")
    out_single = run(f'{PY} app.py "What are the student\'s research interests?"')
    out_guard = run(f'{PY} app.py "What is the student\'s blood group?"')

    # split the long sample run into two readable frames at Q7
    lines = out_samples.split("\n")
    split_at = next((i for i, l in enumerate(lines)
                     if "final-year project" in l and l.strip().startswith("Q:")), len(lines) // 2)
    samples_a = "\n".join(lines[:split_at])
    samples_b = "\n".join(lines[split_at:])

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=CHROME, args=["--no-sandbox"])
        page = browser.new_page(viewport={"width": 1100, "height": 800}, device_scale_factor=2)

        shot(page, "01_generate_documents.png",
             "Step 1 — Generate the Student Knowledge Base documents",
             "python build_knowledge_base.py", out_build)
        shot(page, "02_knowledge_base_files.png",
             "Step 2 — Personal documents (PDF / DOCX / TXT / MD)",
             'ls -lh "Student Knowledge Base"', out_ls)
        shot(page, "03_ingest_vector_db.png",
             "Step 3 — Ingest: load -> split -> embed -> store in Chroma",
             "python ingest.py", out_ingest)
        shot(page, "04_qa_part1.png",
             "Step 4 — Question answering over the knowledge base (1/2)",
             "python app.py", samples_a)
        shot(page, "05_qa_part2.png",
             "Step 4 — Question answering + guardrail refusal (2/2)",
             "python app.py", samples_b)
        shot(page, "06_single_question.png",
             "Step 5 — Asking a single question from the command line",
             'python app.py "What are the student\'s research interests?"', out_single)
        shot(page, "07_guardrail_refusal.png",
             "Step 6 — Guardrail: refusing info that is not in the documents",
             'python app.py "What is the student\'s blood group?"', out_guard)
        browser.close()
    print("Done.")


if __name__ == "__main__":
    main()
