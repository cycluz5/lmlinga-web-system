"""Check newly drafted dialogs against YOUR vocabulary (this replaces sentence-by-sentence linguist review).

    python filter_generated.py [draft.csv]        default: dataset/dialogs_v4_draft.csv

Draft columns: dialog_id, topic, turn, speaker (Person / LMLinga), text
A dialog is KEPT only if every word of every line is in dataset/allowed_words.csv (or in
dataset/approved_words.txt) and none is on dataset/blocked_words.csv. Otherwise the whole dialog is rejected,
because one bad word in a reply would teach the model that word.

Writes in dataset/:
  dialogs_v4.csv           kept dialogs (status = auto-checked)
  rejected_dialogs_v4.csv  rejected dialogs with the offending words
  unknown_words_v4.csv     every unknown word with how many dialogs it blocks and an example line
                           -> if a word is really yours (correct Iriga), add it to approved_words.txt (one per line)
                              and run again. No sentence review needed, only a word decision.
"""
import csv
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

HERE = Path(__file__).parent
DATA = HERE / "dataset"

WORD = re.compile(r"[^\W\d_]+(?:['’\-][^\W\d_]+)*", re.UNICODE)
ALWAYS_OK = {  # names, calendar words, proper nouns (exact case compared after lowercasing)
    "lmlinga", "iriga", "enero", "pebrero", "marso", "abril", "hunyo", "hulyo", "agosto", "setyembre",
    "oktubre", "nobyembre", "disyembre", "lunes", "martes", "miyerkoles", "huwebes", "biyernes", "sabado",
    "dominggo", "pasko", "am", "pm",
}


def tokens(text):
    out = []
    for raw in WORD.findall(text or ""):
        out.append((raw, raw.lower().replace("’", "'")))
    return out


def load_set(path, col=0, header=True):
    if not path.exists():
        return set()
    with open(path, encoding="utf-8-sig") as f:
        rows = list(csv.reader(f))
    return {r[col].strip().lower() for r in (rows[1:] if header else rows) if r and r[col].strip()}


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    src = Path(args[0]) if args else DATA / "dialogs_v4_draft.csv"
    # Drafts may only use the team's SPOKEN words (reviewed chats + the vocabulary list). Words that appear only in
    # the health documents are medical/formal and are not trusted for small talk. Use --any-source to allow them.
    spoken = {"chat_pairs", "dialogs", "situational", "vocabulary_guide", "team_translation", "team_translation_copied"}
    with open(DATA / "allowed_words.csv", encoding="utf-8-sig") as f:
        rows_allowed = list(csv.DictReader(f))
    allowed = {r["word"] for r in rows_allowed
               if "--any-source" in sys.argv or spoken & set(r["sources"].split(";"))}
    blocked = load_set(DATA / "blocked_words.csv")
    approved = set()
    if (DATA / "approved_words.txt").exists():
        approved = {w.strip().lower() for w in (DATA / "approved_words.txt").read_text(encoding="utf-8").splitlines()
                    if w.strip() and not w.startswith("#")}
    ok_words = allowed | approved | ALWAYS_OK

    with open(src, encoding="utf-8-sig") as f:
        rows = list(csv.DictReader(f))
    dialogs = defaultdict(list)
    for r in rows:
        dialogs[r["dialog_id"]].append(r)

    kept, rejected = [], []
    unknown_count, unknown_example = Counter(), {}
    for did, lines in dialogs.items():
        problems = []
        for r in lines:
            for raw, w in tokens(r["text"]):
                if w in blocked and raw != "Mayo":
                    problems.append(("BLOCKED", w))
                elif w not in ok_words:
                    problems.append(("UNKNOWN", w))
                    unknown_count[w] += 1
                    unknown_example.setdefault(w, r["text"])
        if problems:
            rejected.append((did, lines, problems))
        else:
            kept.append((did, lines))

    cols = ["dialog_id", "status", "topic", "turn", "speaker", "text"]
    with open(DATA / "dialogs_v4.csv", "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        for did, lines in kept:
            for r in lines:
                w.writerow({"dialog_id": did, "status": "auto-checked", "topic": r["topic"], "turn": r["turn"],
                            "speaker": r["speaker"], "text": r["text"]})
    with open(DATA / "rejected_dialogs_v4.csv", "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["dialog_id", "topic", "bad_words", "first_line"])
        for did, lines, problems in rejected:
            bad = sorted({f"{kind}:{word}" for kind, word in problems})
            w.writerow([did, lines[0]["topic"], " ".join(bad), lines[0]["text"]])
    with open(DATA / "unknown_words_v4.csv", "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["word", "times", "example_line"])
        for word, n in unknown_count.most_common():
            w.writerow([word, n, unknown_example[word]])

    print(f"dialogs: {len(dialogs)}  kept {len(kept)}  rejected {len(rejected)}")
    print(f"unknown words: {len(unknown_count)} (top: {', '.join(w for w, _ in unknown_count.most_common(15))})")
    blocked_hits = Counter(word for _, _, ps in rejected for kind, word in ps if kind == "BLOCKED")
    if blocked_hits:
        print("blocked words used:", dict(blocked_hits))


if __name__ == "__main__":
    main()
