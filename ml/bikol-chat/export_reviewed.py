"""Export the reviewed-reply bank for the app: messages your team already answered, with the team's own replies.

    python export_reviewed.py     -> resources/ml/bikol_reviewed_replies.json

tier 1  written or corrected by your team: chat_pairs(.csv / _v4 wordings), dialogs.csv, dialogs_v6_reviewed.csv
tier 2  machine-translated and filter-checked (higher match threshold in the app): dialogs_v4.csv, dialogs_v6.csv
Only the FIRST Person -> LMLinga pair of every dialog is used, because later turns depend on what was said before.
Skipped: time/date questions (the clock check answers those) and the serious chats (the checked reply answers those).
"p" = the reply carries a health tagline ("Maanan mo a kalusugan mo ..."): the app prefers a plain variant when there is one.
"""
import csv
import json
import re
from pathlib import Path

from build_team_translation import SPELLING, normalize   # the team spelling map

SPELLING.update({"unuman": "onoman", "unumang": "onoman"})

HERE = Path(__file__).parent
DATA = HERE / "dataset"
OUT = HERE.parent.parent / "resources" / "ml" / "bikol_reviewed_replies.json"

PUSH = re.compile(r"kalusugan|doktor|health|namamatian|onga\s+manongod", re.I)
TIME_Q = re.compile(r"\b(oras|petsa|time|date|what\s+day|anong\s+araw|ono\s+ngowan\s+na\s+aldow|bulan|taon)\b", re.I)
SERIOUS_CATEGORIES = {"crisis"}


def key(text):
    """Same as BikolSmallTalkService::matchKey(): lower case, no 'tabi', letters/digits/apostrophes only."""
    text = re.sub(r"\btabi\b", " ", text.lower())
    return " ".join(re.sub(r"[^\w' ]", " ", text).split())


def read(path):
    with open(path, encoding="utf-8-sig") as f:
        return list(csv.DictReader(f))


def first_pairs(path, status_ok=None):
    """First Person -> LMLinga pair of each dialog."""
    by = {}
    for r in read(path):
        if status_ok and r.get("status") not in status_ok:
            continue
        by.setdefault(r["dialog_id"], []).append(r)
    for rows in by.values():
        rows.sort(key=lambda r: int(r["turn"]))
        if len(rows) >= 2 and rows[0]["speaker"] == "Person" and rows[1]["speaker"] == "LMLinga":
            yield rows[0]["text"], rows[1]["text"], rows[0].get("topic", "dialog")


def main():
    entries, seen = [], set()

    def add(user, reply, tier, category):
        k, reply = key(user), " ".join(reply.split())
        if tier == 2:                                       # machine-made: apply the team spelling map (uno->ono, nguwan->ngowan ...)
            reply, _ = normalize(reply)
        if len(k) < 2 or not reply or TIME_Q.search(user) or category in SERIOUS_CATEGORIES:
            return
        if (k, reply.lower()) in seen:
            return
        seen.add((k, reply.lower()))
        entries.append({"k": k, "r": reply, "t": tier, "p": 1 if PUSH.search(reply) else 0, "c": category})

    for r in read(DATA / "chat_pairs_v4.csv"):           # reviewed replies + every wording (augmented ones too)
        add(r["user"], r["bot"], 1, r["category"])
    for user, reply, topic in first_pairs(DATA / "dialogs.csv", {"reviewed"}):
        add(user, reply, 1, topic)
    for user, reply, topic in first_pairs(DATA / "dialogs_v6_reviewed.csv", {"reviewed"}):
        add(user, reply, 1, topic)
    for path in ("dialogs_v4.csv", "dialogs_v6.csv"):
        for user, reply, topic in first_pairs(DATA / path, {"auto-checked", "reviewed"}):
            add(user, reply, 2, topic)

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps({"entries": entries}, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    t1 = sum(1 for e in entries if e["t"] == 1)
    print(f"{len(entries)} entries (tier 1 team-reviewed: {t1}, tier 2 filter-checked: {len(entries) - t1}), "
          f"{len({e['k'] for e in entries})} distinct messages, {sum(e['p'] for e in entries)} with a health tagline -> {OUT}")


if __name__ == "__main__":
    main()
