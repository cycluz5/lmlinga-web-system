"""Build the allowed (YOUR words) and blocked (Central Bikol / Tagalog) word lists for v4.

    python build_vocab.py

Writes in dataset/:
  allowed_words.csv   word, count, sources      every word found in the team's own, reviewed Iriga files
  blocked_words.csv   word, use_instead         Central Bikol / Naga / Tagalog words that must not appear
  vocab_conflicts.csv word, count, sources      words that are on the block list but the team DID use in reviewed
                                                chats; they are NOT blocked (the team's usage wins). Check once.

Sources (all written or reviewed by the team, no outside corpus):
  ml/bikol-intent/vocabulary_guide.csv  Bible + speaker word list
  dataset/iriga_text.csv                verified health documents (resources/health_docs_v2/bcl)
  dataset/translation_pairs.csv         aligned Iriga sentences from the same documents
  dataset/chat_pairs.csv, dialogs.csv, situational.csv   reviewed chats
  ml/health-docs/bcl_corrections.csv    the team's sentence corrections
  dataset/team_words.csv, team_phrases.csv   the teammates' 1,000 words + 1,000 phrases (v5)

The block list is the spelling map in RagService::fixBikolIrigaSpelling (copied here, RagService is not edited)
plus a few more Central Bikol markers.
"""
import csv
import re
from collections import Counter, defaultdict
from pathlib import Path

HERE = Path(__file__).parent
DATA = HERE / "dataset"
INTENT = HERE.parent / "bikol-intent"
HEALTH = HERE.parent / "health-docs"

# Central Bikol / Naga / Tagalog -> the team's Iriga word (from RagService::fixBikolIrigaSpelling + more)
BLOCKED = {
    "iyo": "amo", "igwa": "agko", "sarong": "osad", "asin": "ag", "kun": "kin", "kung": "kin",
    "saimo": "kanimo", "sakuya": "kanako", "sako": "kanako", "nin": "kana", "ano": "ono", "uno": "ono",
    "paano": "paono", "pauno": "paono", "ang": "ana", "mayo": "uda", "lagnat": "kalintora",
    "kalintura": "kalintora", "tubig": "tobig", "sintomas": "sinyales", "usad": "osad", "rugo": "rogo",
    "dugo": "rogo", "dapit": "manungod", "tungkol": "manungod", "aldaw": "aldow", "marhay": "maray",
    "satuya": "kanato", "satuyang": "kanatong", "kaipuhan": "kaipowan", "kaipuhang": "kaipowan",
    "an": "ana", "kan": "kana", "hali": "galin", "tataramon": "sarita", "gabos": "ngamin",
    # more Central / Naga markers
    "magayon": "maray", "marahay": "maray", "ngonyan": "ngowan", "ngunyan": "ngowan", "ngunian": "ngowan",
    "daw": "raw",
}
# "baga" is NOT blocked: it means lungs in the team's documents (kanser sa baga).

WORD = re.compile(r"[^\W\d_]+(?:['’\-][^\W\d_]+)*", re.UNICODE)


def words(text):
    return [w.lower().replace("’", "'") for w in WORD.findall(text or "")]


def read(path):
    with open(path, encoding="utf-8-sig") as f:
        return list(csv.DictReader(f))


def main():
    counts, sources = Counter(), defaultdict(set)
    team_used = Counter()  # words in reviewed CHAT files only (the team's usage decides conflicts)

    def add(text, source, chat=False):
        for raw in WORD.findall(text or ""):
            w = raw.lower().replace("’", "'")
            counts[w] += 1
            sources[w].add(source)
            # "Mayo" with a capital M is the month May (Central Bikol "mayo" = none is lower case)
            if chat and raw != "Mayo":
                team_used[w] += 1

    # The word list keeps some older spellings (kan, pauno, asin): its words are allowed but do not count as
    # the team's chat usage, so they cannot cancel a block.
    for r in read(INTENT / "vocabulary_guide.csv"):
        for field in ("bikol_iriga", "bikol_variants"):
            add((r.get(field) or "").replace(";", " ").replace("/", " "), "vocabulary_guide")
    for r in read(DATA / "iriga_text.csv"):
        add(r["text"], "health_docs")
    for r in read(DATA / "translation_pairs.csv"):
        if r["language"] in ("en", "tl"):
            add(r["bikol_iriga"], "health_docs")
    for r in read(DATA / "chat_pairs.csv"):
        add(r["user"], "chat_pairs", chat=True)
        add(r["bot"], "chat_pairs", chat=True)
    for r in read(DATA / "dialogs.csv"):
        add(r["text"], "dialogs", chat=True)
    for r in read(DATA / "situational.csv"):
        add(r["user"], "situational", chat=True)
        add(r["bot"], "situational", chat=True)
    for r in read(HEALTH / "bcl_corrections.csv"):
        add(r["replace"], "corrections")
    # The teammates' translation sheets (build_team_translation.py, spelling already normalized). Spoken words, but NOT
    # counted as reviewed-chat usage, so they can never cancel a block. Words copied from Tagalog were accepted as Iriga
    # (user decision 2026-11-16) and are tagged so they stay visible.
    if (DATA / "team_words.csv").exists():
        for r in read(DATA / "team_words.csv"):
            add(r["iriga"], "team_translation_copied" if r["copied_from_tagalog"] == "yes" else "team_translation")
        for r in read(DATA / "team_phrases.csv"):
            add(r["iriga"], "team_translation")

    # blocked unless the team used the word in reviewed chats / vocabulary
    blocked, conflicts = {}, []
    for word, use in BLOCKED.items():
        if team_used[word] > 0:
            conflicts.append((word, team_used[word], ";".join(sorted(sources[word]))))
        else:
            blocked[word] = use

    allowed = [(w, c, ";".join(sorted(sources[w]))) for w, c in counts.most_common() if w not in blocked]

    def write(name, header, rows):
        with open(DATA / name, "w", encoding="utf-8-sig", newline="") as f:
            w = csv.writer(f)
            w.writerow(header)
            w.writerows(rows)

    write("allowed_words.csv", ["word", "count", "sources"], allowed)
    write("blocked_words.csv", ["word", "use_instead"], sorted(blocked.items()))
    write("vocab_conflicts.csv", ["word", "count_in_team_chats", "sources"], sorted(conflicts))

    chat_words = sum(1 for w in counts if team_used[w] > 0)
    print(f"allowed words: {len(allowed)} (of those {chat_words} appear in the team's reviewed chats/vocabulary)")
    print(f"blocked words: {len(blocked)}   conflicts (blocked word the team uses, left allowed): {len(conflicts)}")
    for w, c, s in sorted(conflicts):
        print(f"   conflict: '{w}' used {c}x  ({s})")


if __name__ == "__main__":
    main()
