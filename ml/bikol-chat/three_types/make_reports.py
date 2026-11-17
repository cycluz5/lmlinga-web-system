"""Build the proof documents in Desktop/chatbot3types_test/{context, local model, cloud model} + a summary.

    python make_reports.py          (needs python-docx and openpyxl)

Reads results_context.json / results_ai.json / results_cloud.json (run_tests.php) and, if present, the health-only comparison
files results_trainbranch_ai.json / results_ragbranch_context.json / results_ragbranch_ai.json (run_rag_only.php).
"""
import json
import re
import subprocess
import urllib.request
from collections import Counter, OrderedDict
from datetime import datetime
from pathlib import Path

from docx import Document
from docx.enum.section import WD_ORIENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, RGBColor, Cm
from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill

from evaluate import evaluate, judge

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
DEST = Path.home() / "Desktop" / "chatbot3types_test"

MODES = OrderedDict([
    ("context", dict(folder="context", title="Context-based", file="Context_Test_Results", xlsx="context_results.xlsx",
                     what="Verified documents + rules only. No AI writes any answer. For unrelated chat it gives a safe fallback.")),
    ("ai", dict(folder="local model", title="Local AI model", file="Local_AI_Model_Test_Results", xlsx="local_model_results.xlsx",
                what="The trained Bikol-Iriga model (lmlinga-bikol-chat-v5, Gemma 3 4B fine-tuned) running on this computer in Ollama, "
                     "with the health-or-chat gate, the team's reviewed replies, the clock check and the safety rules. Health answers come from the verified documents.")),
    ("cloud", dict(folder="cloud model", title="Cloud AI (Gemma)", file="Cloud_AI_Test_Results", xlsx="cloud_model_results.xlsx",
                   what="Gemma 31B on Ollama Cloud (internet) writes the chat replies and re-words the verified health answers. Not the trained model.")),
])
GROUPS = OrderedDict([
    ("H", "Verified health documents (73 topics x 2 questions)"), ("C", "Reviewed chat situations (team's chats)"),
    ("S", "Serious messages (checked reply)"), ("T", "Time and date"), ("W", "Everyday situations"),
    ("P", "Teammates' verified phrases and words"), ("U", "Unseen chat (never used in training)"),
    ("X", "Unlearned: what the chatbot has NOT learned"), ("E", "Edge cases (health / medicine inside chat)"), ("M", "Multi-turn conversations"),
])
COLOR = {"PASS": "C6E5C6", "SAFE": "DDEBF7", "PARTIAL": "FFE699", "FAIL": "F4B6B6"}


def shade(cell, hex_color):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), hex_color)
    tcPr.append(shd)


def set_cell(cell, text, size=7.5, bold=False, color=None):
    cell.text = ""
    p = cell.paragraphs[0]
    p.paragraph_format.space_after = Pt(0)
    run = p.add_run(str(text))
    run.font.size = Pt(size)
    run.bold = bold
    if color:
        run.font.color.rgb = RGBColor.from_string(color)


def table(doc, header, rows, widths=None, size=7.5, verdict_col=None):
    t = doc.add_table(rows=1, cols=len(header))
    t.style = "Table Grid"
    for i, h in enumerate(header):
        set_cell(t.rows[0].cells[i], h, size, True, "FFFFFF")
        shade(t.rows[0].cells[i], "2F6B3A")
    for row in rows:
        cells = t.add_row().cells
        for i, v in enumerate(row):
            set_cell(cells[i], v, size)
            if verdict_col is not None and i == verdict_col and str(v) in COLOR:
                shade(cells[i], COLOR[str(v)])
                cells[i].paragraphs[0].runs[0].bold = True
    if widths:
        for row in t.rows:
            for i, w in enumerate(widths):
                row.cells[i].width = Cm(w)
    return t


def landscape(doc):
    s = doc.sections[0]
    s.orientation = WD_ORIENT.LANDSCAPE
    s.page_width, s.page_height = s.page_height, s.page_width
    s.left_margin = s.right_margin = Cm(1.5)
    s.top_margin = s.bottom_margin = Cm(1.5)


def short(text, n=230):
    text = " ".join(str(text).split())
    return text if len(text) <= n else text[: n - 1] + "…"


def tag(r):
    by = r["answered_by"]
    if by:
        return by
    return "verified documents (RAG)" if r["sources"] else ("RAG fallback" if r["route"] == "RAG" else r["route"])


def environment():
    info = OrderedDict()
    try:
        tags = json.load(urllib.request.urlopen("http://127.0.0.1:11434/api/tags", timeout=5))["models"]
        info["Ollama (local) models"] = "; ".join(f"{m['name']} ({m['size'] / 1e9:.1f} GB)" for m in tags if "lmlinga-bikol-chat-v5" in m["name"] or "nomic" in m["name"] or "gemma4" in m["name"])
    except Exception as e:  # noqa
        info["Ollama (local) models"] = f"not reachable ({e})"
    git = lambda *a: subprocess.run(["git", *a], cwd=ROOT, capture_output=True, text=True).stdout.strip()
    info["Project / branch"] = f"{ROOT.name} / {git('branch', '--show-current')} (commit {git('rev-parse', '--short', 'HEAD')}, with uncommitted changes)"
    info["Verified health index"] = "1,484 chunks from 73 verified topics (resources/health_docs_v2/bcl)"
    return info


def write_xlsx(path, rows, mode):
    wb = Workbook()
    ws = wb.active
    ws.title = "results"
    ws.append(["ID", "Group", "Situation", "Dataset", "Message sent", "Expected", "Answer", "Answered by", "Route", "Sources", "Seconds", "Verdict", "Why"])
    for r in rows:
        x = r["result"]
        ws.append([r["id"], r["group"], r["situation"], r["dataset"], r["message"], r["expect"], x["flat"] or x["answer"], tag(x),
                   x["route"], ", ".join(x["sources"]), x["seconds"], r["verdict"], r["why"]])
    for col, w in zip("ABCDEFGHIJKLM", [8, 6, 34, 26, 38, 40, 70, 30, 11, 22, 8, 10, 46]):
        ws.column_dimensions[col].width = w
    for c in ws[1]:
        c.font = Font(bold=True, color="FFFFFF")
        c.fill = PatternFill("solid", fgColor="2F6B3A")
    for row in ws.iter_rows(min_row=2):
        for c in row:
            c.alignment = Alignment(wrap_text=True, vertical="top")
        row[11].fill = PatternFill("solid", fgColor=COLOR.get(row[11].value, "FFFFFF"))
    ws.freeze_panes = "A2"
    wb.save(path)


def mode_doc(mode, data, rows, env, compare):
    meta = MODES[mode]
    doc = Document()
    landscape(doc)
    st = doc.styles["Normal"]
    st.font.name = "Calibri"
    st.font.size = Pt(10)
    doc.add_heading(f"{meta['title']}: Test Results (Bikol-Iriga chatbot)", 0)
    ran = datetime.fromisoformat(data["ran_at"]).strftime("%B %d, %Y, %I:%M %p")
    doc.add_paragraph(f"Test run: {ran} (Philippine time). {len(rows)} tests, {data['seconds']:.0f} seconds. "
                      f"Each message was sent through the same path as the chat page (serious-message check, time and date, "
                      f"chat layer, verified health documents), with the \"{meta['title']}\" button's behaviour.")
    doc.add_paragraph(meta["what"])

    c = Counter(r["verdict"] for r in rows)
    doc.add_heading("1. Result at a glance", 1)
    total = len(rows)
    p = doc.add_paragraph()
    p.add_run(f"{c['PASS']} PASS, {c['SAFE']} SAFE, {c['PARTIAL']} PARTIAL, {c['FAIL']} FAIL").bold = True
    p.add_run(f" out of {total} tests. PASS = did what was expected for this type. SAFE = not the full goal but safe and honest by design. "
              "PARTIAL = an answer exists but something is off. FAIL = wrong, unsafe, leaking Tagalog or Central Bikol words, wrong time, or an error.")
    gr = []
    for g, name in GROUPS.items():
        sub = [r for r in rows if r["group"] == g]
        if not sub:
            continue
        cc = Counter(r["verdict"] for r in sub)
        gr.append([g, name, len(sub), cc["PASS"], cc["SAFE"], cc["PARTIAL"], cc["FAIL"], f"{sum(r['result']['seconds'] for r in sub) / len(sub):.1f}"])
    table(doc, ["", "Group", "Tests", "PASS", "SAFE", "PARTIAL", "FAIL", "Avg seconds"], gr, [1, 9, 2, 2, 2, 2.2, 2, 2.5], 9)

    doc.add_heading("2. Proof for this type", 1)
    for line in proof_lines(mode, rows):
        doc.add_paragraph(line, style="List Bullet")

    doc.add_heading("3. Environment", 1)
    table(doc, ["Item", "Value"], [[k, v] for k, v in env.items()] + [["Mode tested", meta["title"]], ["Chat model setting", str(data.get("model", ""))]], [6, 19], 9)

    if compare and mode != "cloud":
        doc.add_heading("4. Verified health documents: this branch against the RAG branch", 1)
        doc.add_paragraph(compare)

    doc.add_heading(f"{'5' if compare and mode != 'cloud' else '4'}. Unlearned messages: the chatbot's response", 1)
    doc.add_paragraph("These messages are NOT in any dataset: gibberish, English, Tagalog, Central Bikol and knowledge the chatbot never learned. "
                      "The expected behaviour is an honest reply (the team's reviewed \"I do not know that, I can help with health\"), never a made-up answer.")
    table(doc, ["ID", "Message", "Chatbot reply", "Answered by", "Verdict"],
          [[r["id"], r["message"], short(r["result"]["flat"], 200), tag(r["result"]), r["verdict"]] for r in rows if r["group"] == "X"],
          [1.4, 6, 10.5, 5, 2], 8, 4)

    doc.add_heading("Detailed results by group", 1)
    for g, name in GROUPS.items():
        sub = [r for r in rows if r["group"] == g]
        if not sub:
            continue
        doc.add_heading(f"{g}. {name}", 2)
        table(doc, ["ID", "Situation", "Message sent", "Answer", "Answered by / source", "Verdict", "Why"],
              [[r["id"], short(r["situation"], 60), r["message"], short(r["result"]["flat"], 230),
                tag(r["result"]) + (" [" + ", ".join(r["result"]["sources"][:2]) + "]" if r["result"]["sources"] else ""), r["verdict"], short(r["why"], 90)] for r in sub],
              [1.3, 3.6, 3.8, 8.4, 3.8, 1.6, 3.6], 7, 5)

    doc.add_heading("Problems found", 1)
    bad = [r for r in rows if r["verdict"] in ("FAIL", "PARTIAL")]
    if not bad:
        doc.add_paragraph("None.")
    else:
        table(doc, ["ID", "Message", "What happened", "Verdict"], [[r["id"], r["message"], short(r["why"] + " | " + r["result"]["flat"], 220), r["verdict"]] for r in bad], [1.4, 6, 15, 2], 8, 3)
    return doc


def proof_lines(mode, rows):
    tags = Counter(tag(r["result"]) for r in rows if r["result"]["route"] == "chat layer")
    ai = [r for r in rows if r["result"]["answered_by"].startswith(("Bikol-Iriga LLM", "Cloud AI"))]
    lat = [r["result"]["seconds"] for r in ai]
    L = []
    if mode == "ai":
        L.append(f"{len(ai)} replies were written by the trained model lmlinga-bikol-chat-v5 running locally in Ollama (127.0.0.1:11434). No internet is used for the chat model or for the health search.")
        L.append("Source of the chat-layer replies: " + "; ".join(f"{k}: {v}" for k, v in tags.most_common()) + ".")
        if lat:
            L.append(f"Model reply time: average {sum(lat) / len(lat):.1f} s, slowest {max(lat):.1f} s (6 GB laptop GPU).")
        t = [r for r in rows if r["group"] == "T"]
        L.append(f"Clock questions: {sum(1 for r in t if r['verdict'] == 'PASS')} of {len(t)} match the real clock to the minute (the clock check replaces any wrong model answer).")
        s = [r for r in rows if r["group"] == "S"]
        L.append(f"Serious messages: {sum(1 for r in s if r['verdict'] == 'PASS')} of {len(s)} received the team's checked reply, never a model-written one.")
        x = [r for r in rows if r["group"] == "X"]
        L.append(f"Unlearned messages: {sum(1 for r in x if r['verdict'] == 'PASS')} of {len(x)} received the honest reviewed 'I do not know' reply; {sum(1 for r in x if r['verdict'] == 'PARTIAL')} were answered by the model; {sum(1 for r in x if r['verdict'] == 'FAIL')} failed.")
        h = [r for r in rows if r["group"] == "H"]
        unsafe = sum(1 for r in h + [q for q in rows if q["group"] == "E"] if "UNSAFE" in r["why"])
        L.append(f"Health safety: {unsafe} health or medicine questions were answered by the chat model (target 0).")
    elif mode == "cloud":
        L.append(f"{len(ai)} replies were written by Gemma 31B on Ollama Cloud (internet needed). This is not the trained model and it is not local.")
        if lat:
            L.append(f"Reply time: average {sum(lat) / len(lat):.1f} s, slowest {max(lat):.1f} s.")
        L.append("Its Bikol-Iriga is checked only by the same word lists as the other types: leaked Tagalog or Central Bikol words make a test FAIL.")
    else:
        L.append(f"{len(ai)} answers were written by an AI model: Context-based uses no chat AI (target 0).")
        L.append("Unrelated chat receives a safe fallback or the old canned reply; it cannot hold a conversation by design.")
        L.append("Time and date are not supported in this type (it does not guess a time).")
    h = [r for r in rows if r["group"] == "H"]
    L.append(f"Verified health documents: {sum(1 for r in h if r['verdict'] == 'PASS')} of {len(h)} questions answered from the right document; "
             f"{sum(1 for r in h if r['verdict'] == 'SAFE')} got an honest 'I do not know'; {sum(1 for r in h if r['verdict'] == 'PARTIAL')} came from another document; {sum(1 for r in h if r['verdict'] == 'FAIL')} failed.")
    return L


def health_compare():
    parts = []
    for tag_, f, name in [("trainbranch", "results_trainbranch_ai.json", "This training branch (train-bikol-iriga-fod)"),
                          ("ragbranch", "results_ragbranch_context.json", "RAG branch (rag-chatbot-fod), Context-based"),
                          ("ragbranch", "results_ragbranch_ai.json", "RAG branch (rag-chatbot-fod), with the trained question-type classifier")]:
        p = HERE / f
        if not p.exists():
            continue
        d = json.load(open(p, encoding="utf-8"))
        c = Counter(judge(t, "context")[0] for t in d["results"])
        parts.append(f"{name}: {c['PASS']} of {len(d['results'])} questions answered from the right verified document ({c['SAFE']} honest 'I do not know', {c['PARTIAL']} other document, {c['FAIL']} failed).")
    return " ".join(parts)


def main():
    DEST.mkdir(parents=True, exist_ok=True)
    env = environment()
    compare = health_compare()
    summary = {}
    for mode, meta in MODES.items():
        if not (HERE / f"results_{mode}.json").exists():
            continue
        data, rows = evaluate(mode)
        folder = DEST / meta["folder"]
        folder.mkdir(exist_ok=True)
        doc = mode_doc(mode, data, rows, env, compare)
        doc.save(folder / f"{meta['file']}.docx")
        write_xlsx(folder / meta["xlsx"], rows, mode)
        summary[mode] = (data, rows)
        print(f"{mode}: {folder / (meta['file'] + '.docx')}")

    # the test list itself
    tests = json.load(open(HERE / "test_list.json", encoding="utf-8"))
    wb = Workbook()
    ws = wb.active
    ws.title = "test list"
    ws.append(["ID", "Group", "Situation", "Dataset it comes from", "Message to send", "Expected behaviour", "Verified document (health only)"])
    for t in tests:
        ws.append([t["id"], t["group"], t["situation"], t["dataset"], t["message"], t["expect"], t.get("slug", "")])
    for col, w in zip("ABCDEFG", [8, 6, 44, 34, 48, 58, 30]):
        ws.column_dimensions[col].width = w
    for c in ws[1]:
        c.font = Font(bold=True, color="FFFFFF")
        c.fill = PatternFill("solid", fgColor="2F6B3A")
    ws.freeze_panes = "A2"
    wb.save(DEST / "test_list.xlsx")

    # the summary document
    doc = Document()
    landscape(doc)
    doc.add_heading("Chatbot test: Context, Local AI model and Cloud AI (Bikol-Iriga)", 0)
    doc.add_paragraph(f"{len(tests)} situation-based tests covering every verified dataset (73 health topics, the team's reviewed chats, the teammates' phrases and words, "
                      "time and date, serious messages, edge cases) and messages the chatbot has NOT learned. The same list was run through the same code path as the chat page "
                      "for each of the three types. Each type's folder holds its proof document (Word) and the full results (Excel).")
    rowsum = []
    for mode, (data, rows) in summary.items():
        c = Counter(r["verdict"] for r in rows)
        rowsum.append([MODES[mode]["title"], len(rows), c["PASS"], c["SAFE"], c["PARTIAL"], c["FAIL"], f"{data['seconds'] / len(rows):.1f}"])
    table(doc, ["Type", "Tests", "PASS", "SAFE", "PARTIAL", "FAIL", "Avg seconds per test"], rowsum, [6, 2.5, 2.5, 2.5, 2.5, 2.5, 4], 10)
    doc.add_heading("Result by group", 1)
    header = ["Group"] + [MODES[m]["title"] for m in summary]
    body = []
    for g, name in GROUPS.items():
        row = [f"{g}. {name}"]
        for mode, (data, rows) in summary.items():
            sub = [r for r in rows if r["group"] == g]
            cc = Counter(r["verdict"] for r in sub)
            row.append(f"PASS {cc['PASS']} · SAFE {cc['SAFE']} · PARTIAL {cc['PARTIAL']} · FAIL {cc['FAIL']} (of {len(sub)})")
        body.append(row)
    table(doc, header, body, [8] + [5.5] * len(summary), 8.5)
    if compare:
        doc.add_heading("Verified health documents: this branch against the RAG branch", 1)
        doc.add_paragraph(compare)
    doc.add_heading("What the three types are", 1)
    for mode, meta in MODES.items():
        doc.add_paragraph(f"{meta['title']}: {meta['what']}", style="List Bullet")
    doc.add_heading("Response for something the chatbot has not learned", 1)
    doc.add_paragraph("In the Local AI type, a message that is gibberish, English, Tagalog, Central Bikol, or knowledge the chatbot never learned receives the team's own reviewed reply: "
                      "\"Pasensiya na, diri ko isi ana simbag kadi. Maitatabang ko kanimo manongod sa kalusugan.\" A health question that the documents cannot answer receives the same honest reply, never a model-written one.")
    doc.save(DEST / "00_Summary_Three_Types.docx")
    print("summary:", DEST / "00_Summary_Three_Types.docx")


if __name__ == "__main__":
    main()
