"""Make a SHORT review sheet: the sentence patterns + the words that fill the blanks.

Fixing one pattern fixes every question made from it, so this is much faster than
checking all questions one by one.

    python make_pattern_sheet.py "C:/Users/Kathlyn Cris/Desktop/datasets/bikol_patterns_REVIEW.xlsx"
"""
import sys
from pathlib import Path

import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.worksheet.datavalidation import DataValidation

sys.path.insert(0, str(Path(__file__).parent))
import build_dataset as b  # noqa: E402

# placeholder in code -> what the reviewer sees, list it comes from, example word
BLANKS = {
    "{i}": ("[ILLNESS]", "ILLNESS", "dengue"),
    "{p}": ("[PROGRAM]", "PROGRAM", "bakuna"),
    "{g}": ("[SERVICE]", "GET", "check-up"),
    "{o}": ("[OTHER]", "OTHER", "menopause"),
    "{a}": ("[ANY TOPIC]", "ILLNESS+PROGRAM+OTHER", "hika"),
    "{s}": ("[SCHEDULE ITEM]", "SCHED", "bakuna"),
    "{w}": ("[PERSON]", "WHO", "a igin ko"),
    "{x}": ("[BODY]", "EFFECT_X", "awak"),
    "{d}": ("[DANGER]", "DANGER", "mataas a kalintora ka igin ko"),
    "{D}": ("[DANGER]", "DANGER", "Mataas a kalintora ka igin ko"),
}
LISTS = [
    ("ILLNESS", "illness / condition", b.ILLNESS),
    ("PROGRAM", "program / good habit", b.PROGRAM),
    ("GET", "service you go and get (magpa-...)", b.GET),
    ("OTHER", "other topic", b.OTHER),
    ("SCHED", "schedule item", b.SCHED),
    ("WHO", "person", b.WHO),
    ("DANGER", "danger situation (whole sentence)", b.DANGER),
    ("EFFECT_X", "body / person for effects", b.EFFECT_X),
]
HELP = [
    "HOW TO CHECK (short version)",
    "",
    "All 908 questions are made from ~100 sentence PATTERNS + lists of WORDS that go in the blanks.",
    "Fix a pattern once  ->  every question made from it is fixed.",
    "",
    "SHEET 'patterns'",
    "  Read the 'example'. Would someone from Iriga ask it this way?",
    "  ok? = yes / fix / delete.   If fix: write the better pattern in 'fixed_pattern'.",
    "  KEEP the blank in [BRACKETS] exactly as written, e.g.  Ono a sintomas ka [ILLNESS]?",
    "  If the question type is wrong, choose the right one in 'fixed_intent'.",
    "",
    "SHEET 'words in blanks'",
    "  These words go inside the [BRACKETS]. Check the spelling / the word residents use.",
    "  ok? = yes / fix / delete.   If fix: write it in 'fixed_word'.",
    "",
    "SHEET 'new patterns'",
    "  Write other ways people ask, with a blank, e.g.  Ono a maray na bolong sa [ILLNESS]?",
    "",
    "SPELLING: 'ono' (not uno). 'ono a' and 'ono an' are both OK.",
    "",
    "TYPES: definition (what is) / symptoms (signs) / causes (why) / management (prevent or treat) /",
    "       diagnosis (how to know, test) / benefits (why good) / effects (what happens, side effects) /",
    "       schedule (when, what age) / warning (danger, hospital) / general (who, where, cost)",
]
HEAD = PatternFill("solid", fgColor="DDEBF7")
YELLOW = PatternFill("solid", fgColor="FFF2CC")


def readable(tpl):
    shown, example = tpl, tpl
    for code, (label, _, ex) in BLANKS.items():
        shown = shown.replace(code, label)
        example = example.replace(code, ex)
    return shown, example


def style(ws, widths, yellow_cols, n):
    for c in ws[1]:
        c.font = Font(bold=True)
        c.fill = HEAD
    for col, w in zip("ABCDEFGH", widths):
        ws.column_dimensions[col].width = w
    for row in ws.iter_rows(min_row=2, max_row=n + 1):
        for c in row:
            c.alignment = Alignment(wrap_text=True, vertical="top")
            if c.column_letter in yellow_cols:
                c.fill = YELLOW
    ws.freeze_panes = "A2"


def main(out):
    wb = openpyxl.Workbook()
    info = wb.active
    info.title = "HOW TO CHECK"
    for line in HELP:
        info.append([line])
    info["A1"].font = Font(bold=True, size=14)
    info.column_dimensions["A"].width = 105

    ok = DataValidation(type="list", formula1='"yes,fix,delete"', allow_blank=True)
    intents = DataValidation(type="list", formula1='"' + ",".join(b.T) + '"', allow_blank=True)

    ws = wb.create_sheet("patterns")
    ws.append(["id", "intent", "pattern", "example", "ok?", "fixed_pattern", "fixed_intent"])
    n = 0
    for intent, tpls in b.T.items():
        for k, (tpl, _) in enumerate(tpls, 1):
            shown, ex = readable(tpl)
            ws.append([f"{intent}-{k}", intent, shown, ex, "", "", ""])
            n += 1
    style(ws, (14, 12, 48, 48, 8, 48, 13), "EFG", n)
    ws.add_data_validation(ok)
    ws.add_data_validation(intents)
    ok.add(f"E2:E{n + 1}")
    intents.add(f"G2:G{n + 1}")
    n_patterns = n

    ws = wb.create_sheet("words in blanks")
    ws.append(["list", "blank", "word", "ok?", "fixed_word"])
    ok2 = DataValidation(type="list", formula1='"yes,fix,delete"', allow_blank=True)
    ws.add_data_validation(ok2)
    n = 0
    labels = {lst: lab for lab, lst, _ in BLANKS.values()}
    for key, desc, words in LISTS:
        for w in words:
            ws.append([key, f"{labels.get(key, '')} {desc}".strip(), w, "", ""])
            n += 1
    style(ws, (11, 40, 45, 8, 45), "DE", n)
    ok2.add(f"D2:D{n + 1}")
    n_words = n

    ws = wb.create_sheet("new patterns")
    ws.append(["intent", "new_pattern (use a [BLANK])", "example question"])
    for c in ws[1]:
        c.font = Font(bold=True)
        c.fill = HEAD
    for col, w in zip("ABC", (14, 60, 60)):
        ws.column_dimensions[col].width = w
    dv = DataValidation(type="list", formula1='"' + ",".join(b.T) + '"', allow_blank=True)
    ws.add_data_validation(dv)
    dv.add("A2:A300")

    wb.save(out)
    print(f"{n_patterns} patterns + {n_words} words -> {out}")


if __name__ == "__main__":
    main(sys.argv[1])
