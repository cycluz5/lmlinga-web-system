"""Step 3b: use the team's spot-check corrections (Desktop/datasets/teacher_spotcheck.xlsx).

    python apply_review.py [path to the xlsx]

Rule of the sheet: a blank "ok?" and a blank "note / fix" = the dialog was fine. A corrected dialog written in "note / fix"
(lines "P: ..." and "L: ...") = the team's own Iriga, the best data we have.

Writes
  dataset/dialogs_v6_reviewed.csv        the 40 reviewed dialogs (corrected version where there is one), status "reviewed"
  dataset/approved_words.txt             + every word the team wrote in them (verified Iriga, so the filter accepts it)
  dataset/teacher/learned_fixes.json     substitutions learned from the corrections; filter_teacher.py applies them to the
                                         dialogs nobody has reviewed:
                                           words    a word the team replaced and that is NOT in our spoken vocabulary (kausap->kausip ...)
                                           phrases  consistent two-word fixes ("agko ka" -> "agko ika", "mamoya mo" -> "gusto mo")
"""
import csv
import difflib
import json
import re
import sys
from collections import Counter
from pathlib import Path

from openpyxl import load_workbook

HERE = Path(__file__).resolve().parent
DATA = HERE.parent / "dataset"
TEACHER = DATA / "teacher"
SPOKEN = {"chat_pairs", "dialogs", "situational", "vocabulary_guide", "team_translation", "team_translation_copied"}
WORD = re.compile(r"[^\W\d_]+(?:['’-][^\W\d_]+)*", re.UNICODE)


def parse(text):
    out = []
    for line in str(text or "").splitlines():
        line = line.strip()
        m = re.match(r"^([PL])\s*:\s*(.*)$", line)
        if m:
            out.append([m.group(1), m.group(2).strip()])
        elif line and out:
            out[-1][1] += " " + line
    return out


def clean(w):
    return re.sub(r"[^\w'-]", "", w.lower())


def main():
    xlsx = Path(sys.argv[1]) if len(sys.argv) > 1 else Path.home() / "Desktop" / "datasets" / "teacher_spotcheck.xlsx"
    ids = [r["id"] for r in csv.DictReader(open(TEACHER / "teacher_spotcheck.csv", encoding="utf-8-sig"))]
    ws = load_workbook(xlsx)["dialogs"]

    with open(DATA / "allowed_words.csv", encoding="utf-8-sig") as f:
        spoken = {r["word"] for r in csv.DictReader(f) if SPOKEN & set(r["sources"].split(";"))}

    reviewed, words_seen = [], set()
    word_subs, phrase_counts, phrase_total = Counter(), Counter(), Counter()
    fixed = 0
    for i, row in enumerate(ws.iter_rows(min_row=2)):
        orig = parse(row[2].value)
        fix = parse(row[5].value) if str(row[5].value or "").strip() else None
        use = fix if fix and len(fix) == len(orig) else orig
        fixed += use is fix
        reviewed.append((ids[i], str(row[1].value), use))
        for sp, text in use:
            words_seen |= {clean(w) for w in WORD.findall(text)}
        # bigrams of the ORIGINAL text, to see how consistently a pair was fixed
        for (sp, a), (sp2, b) in zip(orig, use):
            wa = [clean(w) for w in a.split()]
            for x, y in zip(wa, wa[1:]):
                phrase_total[(x, y)] += 1
            if a == b:
                continue
            wb = [clean(w) for w in b.split()]
            sm = difflib.SequenceMatcher(None, wa, wb)
            for op, i1, i2, j1, j2 in sm.get_opcodes():
                if op == "replace" and i2 - i1 == j2 - j1:
                    for x, y in zip(wa[i1:i2], wb[j1:j2]):
                        if x and y and x != y and "/" not in x + y:
                            if x not in spoken:
                                word_subs[(x, y)] += 1
                            if i2 - i1 == 1 and i1 + 1 < len(wa):
                                phrase_counts[((x, wa[i1 + 1]), (y, wa[i1 + 1]))] += 1
                            if i2 - i1 == 1 and i1 > 0:
                                phrase_counts[((wa[i1 - 1], x), (wa[i1 - 1], y))] += 1

    # -- reviewed dialogs
    with open(DATA / "dialogs_v6_reviewed.csv", "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["dialog_id", "status", "topic", "turn", "speaker", "text"])
        for did, topic, turns in reviewed:
            for n, (sp, text) in enumerate(turns, 1):
                w.writerow([f"r-{did}", "reviewed", topic, n, "Person" if sp == "P" else "LMLinga", text])

    # -- approved words (the team wrote them)
    p = DATA / "approved_words.txt"
    have = {x.strip().lower() for x in p.read_text(encoding="utf-8").splitlines()} if p.exists() else set()
    new = sorted(w for w in words_seen if w and w not in have)
    with open(p, "a", encoding="utf-8") as f:
        f.write("\n# from the spot-check corrections (written by the team)\n" + "\n".join(new) + "\n")

    # -- learned fixes: single words (source unknown to us), and consistent two-word fixes
    words = {}
    for (x, y), c in word_subs.items():
        if c >= 1 and x not in words:
            words[x] = y
    phrases = {}
    for ((a1, a2), (b1, b2)), c in phrase_counts.items():
        if c >= 2 and c / max(phrase_total[(a1, a2)], 1) >= 0.7:
            phrases[f"{a1} {a2}"] = f"{b1} {b2}"
    # drop artifacts of the matching: glued alternatives ("friend/barkada/amigo"), a different phrase aligned by accident, sentence borders
    for bad in ("friend", "ganyan", "hirap"):
        words.pop(bad, None)
    words = {a: b for a, b in words.items() if len(b) <= 14}
    phrases = {a: b for a, b in phrases.items() if not ({"adi", "kumusta"} & set(a.split()))}
    (TEACHER / "learned_fixes.json").write_text(json.dumps({"words": words, "phrases": phrases}, ensure_ascii=False, indent=1), encoding="utf-8")

    print(f"{len(reviewed)} reviewed dialogs ({fixed} with the team's corrected version, {len(reviewed) - fixed} fine as written)")
    print(f"{len(new)} new words added to approved_words.txt")
    print(f"learned word fixes ({len(words)}): " + ", ".join(f"{a}->{b}" for a, b in sorted(words.items())))
    print(f"learned phrase fixes ({len(phrases)}): " + ", ".join(f"'{a}'->'{b}'" for a, b in sorted(phrases.items())))


if __name__ == "__main__":
    main()
