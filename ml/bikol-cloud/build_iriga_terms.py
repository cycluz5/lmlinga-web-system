"""Build the Central Bikol -> Bikol-Iriga term list used by Cloud AI (Gemma).

    python build_iriga_terms.py [path to lmlinga-train/ml/bikol-chat]

Writes resources/ml/bikol_iriga_terms.json:
  words      central/Tagalog word -> the team's Iriga word (swapped in Gemma's reply, whole words only)
  glossary   the most common pairs, told to Gemma in its prompt so most replies are already Iriga
  examples   reviewed chat replies (dataset/chat_pairs.csv) shown to Gemma as example conversations

Sources (all from the team's files, in order of authority):
  1. dataset/team_translation_changes.csv   the team's own spelling corrections (authoritative)
  2. dataset/blocked_words.csv              Central Bikol / Naga / Tagalog -> Iriga (build_vocab.py, conflicts removed)
  3. dataset/teacher/learned_fixes.json     word fixes (words only)
  4. token differences inside the sentence corrections of source 1
  5. ml/bikol-cloud/cloud_term_review.csv   pairs the team approved after reviewing Gemma's words (optional;
                                            made by collect_gemma_words.py, the team fills the "iriga" column)

Guard: pairs from sources 3-4 are dropped when the Central word is one the team really uses
(dataset/allowed_words.csv). Sources 1, 2 and 5 are the team's decisions, so they always win.
Words that stay out of the list stay as Gemma wrote them (Central Bikol).
"""
import csv
import difflib
import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).parent
REPO = HERE.parent.parent
DEFAULT_TRAIN = REPO.parent / "lmlinga-train" / "ml" / "bikol-chat"
OUT = REPO / "resources" / "ml" / "bikol_iriga_terms.json"

WORD = re.compile(r"[^\W\d_]+(?:['’\-][^\W\d_]+)*", re.UNICODE)
# Grammar words whose Iriga form depends on the sentence (ka = you / of / in the ...): never swapped by a derived pair.
AMBIGUOUS = {"ka", "an", "sa", "na", "a", "ana", "kana", "mo", "ko", "ta", "ba", "man", "ngani"}
# Older Iriga word -> the team's final word (decided later in review).
TARGET_FIXES = {"manungod": "manongod"}
# Reviewed chats (chat_pairs.csv conv_id) shown to Gemma as example conversations. Left out on purpose:
# time/date chats 105-106 (the real clock line answers those) and crisis chats 37-38 (fixed replies).
# Weather (50) and "who is the president" (51) are left out here: situational.csv has the team's reviewed replies for them.
EXAMPLE_CONVS = [1, 2, 4, 5, 7, 8, 22, 27, 11, 12, 14, 15, 28, 36, 44, 52, 53, 66, 72, 74, 77]
# Reviewed situational chats (situational.csv, they carry the clock line): (category, user message, clock text it must contain).
EXAMPLE_SITUATIONAL = [
    ("greeting_by_time", "Kumusta?", "12:45 AM"),
    ("greeting_by_time", "Hi!", "7:50 AM"),
    ("time", "Onong oras ngowan?", "8:20 PM"),
    ("time", "Onong oras?", "9:30 AM"),
    ("time", "Pirang oras na?", "12:30 PM"),
    ("time", "Pirang oras na?", "3:55 PM"),
    ("time", "Onong oras ngowan?", "12:00 PM"),
    ("time", "Onong oras ngowan?", "1:00 AM"),
    ("day", "Ono ngowan na aldow?", "5:15 PM"),
    ("date", "Ono a petsa ngowan?", "9:50 AM"),
    ("day_period", "Aldow pa ba o gab'i na?", "2:05 PM"),
    ("weather", "Ono a panawon ngowan?", ""),
    ("not_know", "Siisay a presidente?", ""),
    ("thanks_time", "Salamat tabi!", ""),
]
# Short function words kept out of the prompt glossary (the swap still applies them, the team's list says so).
NOT_IN_GLOSSARY = {"kan", "nin", "ang", "daw", "kun", "kung"}


def tokens(text):
    return [w.lower().replace("’", "'") for w in WORD.findall(text or "")]


def read_csv(path):
    with open(path, encoding="utf-8-sig") as f:
        return list(csv.DictReader(f))


def main():
    train = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_TRAIN
    data = train / "dataset"

    allowed = {r["word"] for r in read_csv(data / "allowed_words.csv")}
    words, origin = {}, {}

    def add(central, iriga, source, guarded):
        central, iriga = central.lower().strip(), iriga.lower().strip()
        if not central or not iriga or central == iriga or " " in central:
            return
        if guarded and (central in allowed or central in AMBIGUOUS or len(central) < 3):
            return
        if central not in words:  # first (most authoritative) source wins
            words[central], origin[central] = iriga, source

    changes = read_csv(data / "team_translation_changes.csv")
    # 1a. single-word corrections
    for r in changes:
        if r["kind"] == "word" and len(tokens(r["before"])) == 1 and len(tokens(r["after"])) == 1:
            add(r["before"], r["after"], "team_translation_changes", False)

    # 2. blocked_words (team basis)
    for r in read_csv(data / "blocked_words.csv"):
        add(r["word"], r["use_instead"], "blocked_words", False)

    # 3. learned fixes (words only)
    fixes = json.load(open(data / "teacher" / "learned_fixes.json", encoding="utf-8"))
    for central, iriga in fixes.get("words", {}).items():
        add(central, iriga, "learned_fixes", True)

    # 4. word differences inside the corrected sentences
    for r in changes:
        a, b = tokens(r["before"]), tokens(r["after"])
        for tag, i1, i2, j1, j2 in difflib.SequenceMatcher(None, a, b).get_opcodes():
            if tag == "replace" and i2 - i1 == 1 and j2 - j1 == 1:
                add(a[i1], b[j1], "sentence_corrections", True)

    # 5. pairs approved in the review sheet (optional)
    review = HERE / "cloud_term_review.csv"
    if review.exists():
        for r in read_csv(review):
            if r.get("iriga", "").strip():
                add(r["word"], r["iriga"], "team_review", False)

    # Central Bikol words Gemma really writes come first, then the team's spelling corrections.
    # Later team decisions that override older lists: "about" is manongod (never manungod).
    for central, iriga in list(words.items()):
        if iriga in TARGET_FIXES:
            words[central] = TARGET_FIXES[iriga]
    for central, iriga in TARGET_FIXES.items():
        words.setdefault(central, iriga)
        origin.setdefault(central, "team_decision")

    glossary = [[c, i] for src in ("blocked_words", "team_translation_changes")
                for c, i in words.items() if origin[c] == src and c not in NOT_IN_GLOSSARY][:30]

    # Example conversations: the first wording of each chosen reviewed chat and the team's reply.
    first = {}
    for r in read_csv(data / "chat_pairs.csv"):
        if r.get("status") == "reviewed" and r["conv_id"].isdigit():
            first.setdefault(int(r["conv_id"]), {"user": r["user"].strip(), "bot": r["bot"].strip()})
    examples = [first[c] for c in EXAMPLE_CONVS if c in first]

    # Situational examples: the user message starts with the same clock line the app sends live.
    situational = [r for r in read_csv(data / "situational.csv") if r.get("status") == "reviewed"]
    for category, user, clock in EXAMPLE_SITUATIONAL:
        row = next((r for r in situational if r["category"] == category and r["user"].strip() == user
                    and clock in r["context"]), None)
        if row is None:
            print(f"WARNING: no reviewed situational chat for {category} / {user} / {clock}")
            continue
        examples.append({"user": row["context"].strip() + "\n" + user, "bot": row["bot"].strip()})

    # The team's reviewed reply for a health question the documents do not cover (used as it is, never rewritten).
    redirect = next((r["bot"].strip() for r in situational if r["category"] == "health_redirect"), "")

    OUT.parent.mkdir(parents=True, exist_ok=True)
    with open(OUT, "w", encoding="utf-8", newline="\n") as f:
        json.dump({"words": dict(sorted(words.items())), "glossary": glossary, "examples": examples,
                   "health_redirect": redirect},
                  f, ensure_ascii=False, indent=1)
        f.write("\n")
    by_source = {}
    for s in origin.values():
        by_source[s] = by_source.get(s, 0) + 1
    print(f"{len(words)} terms -> {OUT}\n{by_source}\nglossary: {len(glossary)} pairs, examples: {len(examples)}")


if __name__ == "__main__":
    main()
