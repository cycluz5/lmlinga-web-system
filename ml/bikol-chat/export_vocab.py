"""Export the word lists the app needs to judge chat replies (best-of-3 in BikolSmallTalkService).

    python export_vocab.py     -> resources/ml/bikol_chat_vocab.json

allowed   the team's SPOKEN words (reviewed chats, vocabulary list, teammates' sheets), not the medical/document-only words
blocked   Central Bikol / Tagalog / u-spelling words that must never appear in a reply
fallback  the team-reviewed "I did not understand" reply (chat_pairs.csv, english = "I don't understand")
"""
import csv
import json
from pathlib import Path

HERE = Path(__file__).parent
DATA = HERE / "dataset"
OUT = HERE.parent.parent / "resources" / "ml" / "bikol_chat_vocab.json"
SPOKEN = {"chat_pairs", "dialogs", "situational", "vocabulary_guide", "team_translation", "team_translation_copied"}


def main():
    with open(DATA / "allowed_words.csv", encoding="utf-8-sig") as f:
        allowed = sorted(r["word"] for r in csv.DictReader(f) if SPOKEN & set(r["sources"].split(";")))
    with open(DATA / "blocked_words.csv", encoding="utf-8-sig") as f:
        blocked = sorted(r["word"] for r in csv.DictReader(f))
    fallback = ""
    with open(DATA / "chat_pairs.csv", encoding="utf-8-sig") as f:
        for r in csv.DictReader(f):
            if r["english"].strip().lower() in ("i don't understand", "i do not understand"):
                fallback = r["bot"].strip()
                break
    # the team's reviewed "I do not know that, I can help with health" reply (situational.csv, category not_know)
    not_know = ""
    with open(DATA / "situational.csv", encoding="utf-8-sig") as f:
        for r in csv.DictReader(f):
            if r["category"] == "not_know":
                not_know = r["bot"].strip()
                break
    calendar = ["lunes", "martes", "miyerkoles", "huwebes", "biyernes", "sabado", "dominggo", "enero", "pebrero", "marso",
                "abril", "mayo", "hunyo", "hulyo", "agosto", "setyembre", "oktubre", "nobyembre", "disyembre", "lmlinga", "iriga",
                # clock and parts of the day (the words situational.py teaches: "alas otso na sa gab'i")
                "ala", "alas", "ona", "dos", "tres", "kuwatro", "singko", "sais", "siyete", "otso", "nuwebe", "dies", "onse",
                "dose", "ramrag", "modto", "apon", "gab'i"]
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps({"allowed": sorted(set(allowed) | set(calendar)), "blocked": blocked, "fallback": fallback,
                               "not_know": not_know},
                              ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"allowed {len(allowed)} spoken words, blocked {len(blocked)}, fallback: {fallback!r} -> {OUT}")


if __name__ == "__main__":
    main()
