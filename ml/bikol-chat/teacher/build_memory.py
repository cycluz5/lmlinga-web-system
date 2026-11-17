"""Step 1: the translation memory = everything your team already translated into Iriga (Tagalog/English -> Iriga).

    python build_memory.py     -> dataset/teacher/memory.csv   columns: lang, source, iriga, kind

kind: phrase (teammates' 977 phrases), word (1,000 words), sentence (verified document pairs, short ones only),
      vocab (vocabulary_guide: Bible + speaker words), chat (reviewed chat messages, English label -> Iriga message)
The translator shows the most similar rows as examples, so the cloud model copies YOUR wording instead of inventing Iriga.
"""
import csv
import re
from pathlib import Path

HERE = Path(__file__).resolve().parent
DATA = HERE.parent / "dataset"
INTENT = HERE.parents[1] / "bikol-intent"
OUT = DATA / "teacher" / "memory.csv"


def read(path):
    with open(path, encoding="utf-8-sig") as f:
        return list(csv.DictReader(f))


def words(s):
    return len(re.findall(r"\w+", s))


def main():
    rows, seen = [], set()

    def add(lang, source, iriga, kind):
        source, iriga = " ".join(str(source).split()), " ".join(str(iriga).split())
        if not source or not iriga:
            return
        key = (lang, source.lower(), iriga.lower())
        if key in seen:
            return
        seen.add(key)
        rows.append({"lang": lang, "source": source, "iriga": iriga, "kind": kind})

    for r in read(DATA / "team_phrases.csv"):
        add("tl", r["tagalog"], r["iriga"], "phrase")
        add("en", r["english"], r["iriga"], "phrase")
    for r in read(DATA / "team_words.csv"):
        add("tl", r["tagalog"], r["iriga"], "word")
        add("en", r["english"], r["iriga"], "word")
    for r in read(DATA / "translation_pairs.csv"):
        if r["language"] in ("en", "tl") and words(r["source"]) <= 22 and words(r["bikol_iriga"]) <= 30:
            add(r["language"], r["source"], r["bikol_iriga"], "sentence")
    for r in read(INTENT / "vocabulary_guide.csv"):
        if r.get("bikol_iriga"):
            add("tl", r.get("tagalog", ""), r["bikol_iriga"], "vocab")
            add("en", r.get("english", ""), r["bikol_iriga"], "vocab")
    for r in read(DATA / "chat_pairs.csv"):
        add("en", r["english"], r["user"], "chat")

    OUT.parent.mkdir(parents=True, exist_ok=True)
    with open(OUT, "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=["lang", "source", "iriga", "kind"])
        w.writeheader()
        w.writerows(rows)
    from collections import Counter
    print(f"{len(rows)} memory rows ->", OUT)
    print("  by kind:", dict(Counter(r["kind"] for r in rows)), " by language:", dict(Counter(r["lang"] for r in rows)))


if __name__ == "__main__":
    main()
