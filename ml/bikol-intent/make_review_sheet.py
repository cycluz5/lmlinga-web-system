"""Make the Excel review sheet from dataset/bikol_intent_all.csv.

    python make_review_sheet.py "C:/Users/Kathlyn Cris/Desktop/datasets/bikol_intent_dataset_REVIEW.xlsx"
"""
import csv
import sys
from pathlib import Path

import openpyxl
from openpyxl.styles import Font, PatternFill
from openpyxl.worksheet.datavalidation import DataValidation

ORDER = ["definition", "symptoms", "causes", "management", "diagnosis",
         "benefits", "effects", "schedule", "warning", "general"]
HELP = [
    "HOW TO CHECK THE DATASET",
    "",
    "Go to the sheet 'questions'. For each row:",
    "1. Read the question. Would a resident of Iriga ask it this way?",
    "2. In column 'ok?' choose:  yes  /  fix  /  delete",
    "3. If 'fix': write the better question in 'fixed_question' (and/or the right type in 'fixed_intent').",
    "",
    "SPELLING: we use 'ono' (not uno). Both 'ono a' and 'ono an' are OK.",
    "",
    "WHAT EACH TYPE (intent) MEANS",
    "definition = what is it?                      (Ono a dengue?)",
    "symptoms   = signs / what you feel            (Ono a sintomas ka dengue?)",
    "causes     = why / where from                 (Ono a dahilan ka dengue?)",
    "management = prevent OR treat / care          (Paono malikayan a dengue?  Ono a bolong?)",
    "diagnosis  = how to know / test               (Paono maisihan kin agko dengue?)",
    "benefits   = why it is good                   (Ono a benepisyo ka bakuna?)",
    "effects    = what happens / side effects      (Ono a epekto ka pills?)",
    "schedule   = when / what age / how often      (Kono a bakuna ka igin?)",
    "warning    = danger, urgent, go to hospital   (Nagkokombolsyon a igin ko!)",
    "general    = who / where / cost / other       (Libre raw a bakuna?)",
    "",
    "TIP: You can also ADD your own questions at the bottom - real ones you hear are the best data.",
]


def main(out):
    src = Path(__file__).parent / "dataset" / "bikol_intent_all.csv"
    with open(src, encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    rows.sort(key=lambda r: (ORDER.index(r["intent"]), r["question"].lower()))

    wb = openpyxl.Workbook()
    info = wb.active
    info.title = "HOW TO CHECK"
    for line in HELP:
        info.append([line])
    info["A1"].font = Font(bold=True, size=14)
    info.column_dimensions["A"].width = 100

    ws = wb.create_sheet("questions")
    ws.append(["#", "question", "intent", "topic", "ok?", "fixed_question", "fixed_intent"])
    for i, r in enumerate(rows, 1):
        ws.append([i, r["question"], r["intent"], r["topic"], "", "", ""])
    for c in ws[1]:
        c.font = Font(bold=True)
        c.fill = PatternFill("solid", fgColor="DDEBF7")
    for col, w in zip("ABCDEFG", (6, 60, 13, 30, 9, 50, 14)):
        ws.column_dimensions[col].width = w
    yellow = PatternFill("solid", fgColor="FFF2CC")
    for row in ws.iter_rows(min_row=2, min_col=5, max_col=7):
        for c in row:
            c.fill = yellow
    last = len(rows) + 200
    ok = DataValidation(type="list", formula1='"yes,fix,delete"', allow_blank=True)
    intent = DataValidation(type="list", formula1='"' + ",".join(ORDER) + '"', allow_blank=True)
    ws.add_data_validation(ok)
    ws.add_data_validation(intent)
    ok.add(f"E2:E{last}")
    intent.add(f"G2:G{last}")
    ws.freeze_panes = "B2"
    ws.auto_filter.ref = f"A1:G{len(rows) + 1}"
    wb.save(out)
    print(f"{len(rows)} rows -> {out}")


if __name__ == "__main__":
    main(sys.argv[1])
