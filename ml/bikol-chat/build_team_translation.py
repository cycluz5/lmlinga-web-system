"""Read the teammates' translation sheets (1,000 words + 1,000 phrases) and make training-ready CSVs.

    python build_team_translation.py [folder with the 2 filled xlsx]      default: ~/Downloads

Input (filled by the team, 500 words + 500 phrases each):
    Crizza_Word-to-be-translated.xlsx        No. | Tagalog word | English meaning | Iriga   ||   No. | Tagalog phrase | English | Iriga
    Paul_Word-Phrases-to-be-translate.xlsx   (same layout, No. 501-1000)

Output in dataset/:
    team_words.csv               tagalog, english, iriga, iriga_raw, author, copied_from_tagalog
    team_phrases.csv             tagalog, english, iriga, iriga_raw, author
    team_translation_changes.csv every spelling change, before -> after, so nothing is changed silently

The spelling is made to follow the team's decisions (whole words only, never inside a word), for example
uno->ono, nguwan->ngowan, usad->osad. Words copied from Tagalog are KEPT (the user accepted them as Iriga) and only
marked in `copied_from_tagalog`. Phrases identical to the Tagalog phrase and empty rows are dropped.
"""
import csv
import re
import sys
from pathlib import Path

import openpyxl

HERE = Path(__file__).parent
DATA = HERE / "dataset"
FILES = ["Crizza_Word-to-be-translated.xlsx", "Paul_Word-Phrases-to-be-translate.xlsx"]

# Team spelling decisions (whole word, case kept). Sources: the user's decisions in this project and the map in
# RagService::fixBikolIrigaSpelling.
SPELLING = {
    "uno": "ono", "pauno": "paono", "nguwan": "ngowan", "kaipuhan": "kaipowan", "kaipuwan": "kaipowan",
    "kaipuhang": "kaipowan", "tubig": "tobig", "kalintura": "kalintora", "rugo": "rogo", "mudto": "modto",
    "usad": "osad", "unga": "onga", "sako": "kanako", "kan": "kana", "ingat": "maan", "ingatan": "maanan",
    "anoman": "onoman", "galing": "ganting", "dakul": "dakol", "trabaho": "trabawo", "gutom": "alop",
    # u -> o spellings the team already decided or used in the reviewed chats (only words where the meaning is certain;
    # other u/o differences, for example buko "coconut" vs boko "no", are NOT touched)
    "unong": "onong", "manungod": "manongod", "mamuya": "mamoya", "matuod": "matood", "makaturog": "makatorog",
    "maturog": "matorog", "bulong": "bolong", "ubo": "obo", "uran": "oran", "puso": "poso", "isurat": "isorat",
    "inumon": "inomon", "maugmang": "maogmang", "miyerkules": "miyerkoles", "sagkod": "sagkud", "umiyan": "omiyan",
    "tulos": "tolos", "makulog": "makolog", "mabros": "mabrus", "dakulo": "dakolo",
}
WORD = re.compile(r"(?<![\w'-])([^\W\d_]+(?:['’-][^\W\d_]+)*)(?![\w'-])", re.UNICODE)


def normalize(text):
    """Return (new text, list of (before, after)). Only whole words, never part of a longer word."""
    changes = []

    def fix(m):
        w = m.group(1)
        new = SPELLING.get(w.lower())
        if new is None:
            return w
        new = new.capitalize() if w[:1].isupper() else new
        if w.isupper() and len(w) > 1:
            new = new.upper()
        changes.append((w, new))
        return new

    return WORD.sub(fix, text), changes


def clean(v):
    return "" if v is None else " ".join(str(v).split())


def tidy(s):
    return re.sub(r"[^\w' ]", " ", s.lower()).split()


def main():
    folder = Path(sys.argv[1]) if len(sys.argv) > 1 else Path.home() / "Downloads"
    words, phrases, log = [], [], []
    stats = {"words_read": 0, "phrases_read": 0, "empty": 0, "identical_phrase": 0, "duplicate": 0}
    seen_w, seen_p = set(), set()

    for fname in FILES:
        path = folder / fname
        if not path.exists():
            raise SystemExit(f"missing: {path}")
        author = fname.split("_")[0]
        ws = openpyxl.load_workbook(path, data_only=True).worksheets[0]
        for row in list(ws.iter_rows(values_only=True))[2:]:
            row = [clean(c) for c in (list(row) + [""] * 9)[:9]]
            no_w, tl_w, en_w, ir_w = row[0], row[1], row[2], row[3]
            no_p, tl_p, en_p, ir_p = row[5], row[6], row[7], row[8]

            if tl_w:
                stats["words_read"] += 1
                if not ir_w:
                    stats["empty"] += 1
                else:
                    new, ch = normalize(ir_w)
                    for b, a in ch:
                        log.append(("word", no_w, author, ir_w, new))
                    key = (tl_w.lower(), new.lower())
                    if key in seen_w:
                        stats["duplicate"] += 1
                    else:
                        seen_w.add(key)
                        words.append({"tagalog": tl_w, "english": en_w, "iriga": new, "iriga_raw": ir_w,
                                      "author": author,
                                      "copied_from_tagalog": "yes" if ir_w.lower() == tl_w.lower() else "no"})
            if tl_p:
                stats["phrases_read"] += 1
                if not ir_p:
                    stats["empty"] += 1
                elif tidy(ir_p) == tidy(tl_p):
                    stats["identical_phrase"] += 1
                else:
                    new, ch = normalize(ir_p)
                    for b, a in ch:
                        log.append(("phrase", no_p, author, ir_p, new))
                    key = (tl_p.lower(), new.lower())
                    if key in seen_p:
                        stats["duplicate"] += 1
                    else:
                        seen_p.add(key)
                        phrases.append({"tagalog": tl_p, "english": en_p, "iriga": new, "iriga_raw": ir_p,
                                        "author": author})

    def write(name, rows, cols):
        with open(DATA / name, "w", encoding="utf-8-sig", newline="") as f:
            w = csv.DictWriter(f, fieldnames=cols)
            w.writeheader()
            w.writerows(rows)

    write("team_words.csv", words, ["tagalog", "english", "iriga", "iriga_raw", "author", "copied_from_tagalog"])
    write("team_phrases.csv", phrases, ["tagalog", "english", "iriga", "iriga_raw", "author"])
    write("team_translation_changes.csv",
          [{"kind": k, "no": n, "author": a, "before": b, "after": c} for k, n, a, b, c in log],
          ["kind", "no", "author", "before", "after"])

    from collections import Counter
    changed = Counter()
    for k, n, a, b, c in log:
        for x, y in zip(b.split(), c.split()):
            if x != y:
                changed[f"{x.strip('.,?!').lower()}->{y.strip('.,?!').lower()}"] += 1
    copied = sum(1 for w in words if w["copied_from_tagalog"] == "yes")
    print(f"read {stats['words_read']} words + {stats['phrases_read']} phrases | dropped: empty {stats['empty']}, "
          f"identical-to-Tagalog phrases {stats['identical_phrase']}, duplicates {stats['duplicate']}")
    print(f"kept {len(words)} words ({copied} copied from Tagalog, kept as you decided) + {len(phrases)} phrases")
    print(f"spelling changes: {len(log)} entries changed. Most common:",
          ", ".join(f"{k} x{v}" for k, v in changed.most_common(14)))


if __name__ == "__main__":
    main()
