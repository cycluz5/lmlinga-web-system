"""Safe augmentation of the reviewed chat pairs: more ways to SAY the same message.

    python augment_chat.py

Only changes that cannot create new words or change the meaning:
  - polite word "tabi" (already used by the team) at the start or before the end mark
  - no end punctuation / lower case (how people really type)
  - "ono a" <-> "ono ana" (both spellings are accepted by the app)
The replies (bot column) are never touched. At most MAX_USERS different user messages per chat.

Reads  dataset/chat_pairs.csv       (293 reviewed pairs)
Writes dataset/chat_pairs_v4.csv    (same columns + 'augmented' = no/yes)
"""
import csv
import difflib
import random
import re
from collections import defaultdict
from pathlib import Path

DATA = Path(__file__).parent / "dataset"
MAX_USERS = 8
random.seed(11)

END = re.compile(r"([?!.]+)$")
ENGLISH = {"hello", "hi", "hey", "thanks", "thank", "you", "bye", "ok", "okay", "good", "morning", "night", "evening",
           "afternoon", "what", "how", "are", "is", "the", "a", "i", "am", "my", "your", "name", "haha", "lol",
           "yes", "no", "who", "do", "can", "help", "me", "please", "sorry", "welcome", "goodbye", "see", "later"}


SHOP = re.compile(r"\b(how much|price|buy|kilo|fresh|pay|change|discount|expensive|cheap|market|sell|bargain|"
                  r"peso|cost|store|vendor|receipt|open)\b")
HEALTH = re.compile(r"\b(sick|pain|fever|cough|medicine|hospital|hurts?|dizzy|vomiting|wound\w*|bleeding|"
                    r"need a doctor)\b")


def norm_en(text):
    return " ".join(re.sub(r"[^\w' ]", " ", text.lower()).split())


def mostly_english(text):
    words = re.findall(r"[a-z']+", text.lower())
    return bool(words) and sum(w in ENGLISH for w in words) / len(words) >= 0.5


def variants(text):
    """All safe variants of one user message (not including the original)."""
    out = set()
    base = text.strip()
    m = END.search(base)
    core, mark = (base[: m.start()], m.group(1)) if m else (base, "")
    has_tabi = re.search(r"\btabi\b", base, re.I) is not None
    ascii_ok = not re.search(r"[^\x00-\x7f]", base)

    if not has_tabi and core and not mostly_english(base):
        out.add(f"{core} tabi{mark}")                      # Kumusta ka tabi?
        out.add(f"Tabi, {core[0].lower() + core[1:]}{mark}")  # Tabi, kumusta ka?
    if mark and core:
        out.add(core)                                       # no end mark
    if core:
        out.add(base.lower())
    if re.search(r"\bono a\b", base, re.I):
        out.add(re.sub(r"\b(ono) a\b", r"\1 ana", base, flags=re.I))
    if re.search(r"\bono ana\b", base, re.I):
        out.add(re.sub(r"\b(ono) ana\b", r"\1 a", base, flags=re.I))
    if not ascii_ok:
        out = {v for v in out if v != base}
    return {v.strip() for v in out if v.strip() and v.strip() != base}


def main():
    with open(DATA / "chat_pairs.csv", encoding="utf-8-sig") as f:
        rows = list(csv.DictReader(f))

    by_conv = defaultdict(list)
    for r in rows:
        by_conv[r["conv_id"]].append(r)

    # The teammates' own Iriga phrases (build_team_translation.py): same meaning as a reviewed chat -> a genuine
    # new way to say that message. The reply stays the reviewed one.
    team = []
    if (DATA / "team_phrases.csv").exists():
        with open(DATA / "team_phrases.csv", encoding="utf-8-sig") as f:
            team = [(norm_en(r["english"]), r["iriga"]) for r in csv.DictReader(f)]
    team_matches = []

    out = []
    for conv, group in by_conv.items():
        seen = {r["user"].strip().lower() for r in group}
        for r in group:
            out.append({**r, "augmented": "no"})
        label = norm_en(group[0].get("english", ""))
        for en, iriga in team if label else []:
            if (en == label or difflib.SequenceMatcher(None, en, label).ratio() >= 0.95) and iriga.lower() not in seen:
                seen.add(iriga.lower())
                out.append({**group[0], "user": iriga, "augmented": "team"})
                team_matches.append((conv, group[0]["english"], iriga))
        users = list(group)
        extra = []
        for r in users:
            for v in sorted(variants(r["user"])):
                extra.append((v, r))
        random.shuffle(extra)
        room = MAX_USERS - len(seen)   # the teammate wordings above already count as "seen"
        for v, r in extra:
            if room <= 0:
                break
            if v.lower() in seen:
                continue
            seen.add(v.lower())
            room -= 1
            out.append({**r, "user": v, "augmented": "yes"})

    # Out-of-scope teammate phrases get the replies the team ALREADY reviewed for them (situational.csv):
    #   shopping / price QUESTIONS  -> "not_know" reply (I can only help with health)
    #   symptom statements          -> "health_redirect" reply (ask about a health topic)
    # No new reply text is written here.
    scope = []
    reply = {}
    with open(DATA / "situational.csv", encoding="utf-8-sig") as f:
        for r in csv.DictReader(f):
            reply.setdefault(r["category"], r["bot"])
    if team and {"not_know", "health_redirect"} <= set(reply):
        with open(DATA / "team_phrases.csv", encoding="utf-8-sig") as f:
            phrases = list(csv.DictReader(f))
        used = {r["user"].strip().lower() for r in out}
        base = 2000
        for r in phrases:
            en = r["english"].strip()
            low = en.lower()
            if en.endswith("?") and SHOP.search(low):
                cat = "not_know"
            elif (HEALTH.search(low) and low.startswith(("i ", "my ", "the wound")) and "said" not in low
                  and not en.endswith("?")):   # first-person symptom statements only
                cat = "health_redirect"
            else:
                continue
            if r["iriga"].strip().lower() in used:
                continue
            used.add(r["iriga"].strip().lower())
            base += 1
            scope.append({"conv_id": str(base), "status": "reviewed", "category": cat, "english": en,
                          "user": r["iriga"].strip(), "bot": reply[cat], "augmented": "team"})
        out += [{**{c: "" for c in rows[0].keys()}, **s} for s in scope]

    cols = list(rows[0].keys()) + ["augmented"]
    with open(DATA / "chat_pairs_v4.csv", "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        w.writerows(out)

    new = sum(1 for r in out if r["augmented"] == "yes")
    print(f"{len(rows)} reviewed pairs -> {len(out)} pairs ({new} mechanical + {len(team_matches)} teammate wordings) "
          f"-> dataset/chat_pairs_v4.csv")
    print("teammate wordings matched to reviewed chats (check these):")
    for conv, en, iriga in team_matches:
        print(f"    chat {conv} '{en}'  <=  {iriga}")
    print(f"teammate phrases outside LMLinga's job (reviewed reply, no new text): {len(scope)}")
    for s in scope[:40]:
        print(f"    [{s['category']}] {s['english']}  <=  {s['user']}")
    for r in [x for x in out if x["augmented"] == "yes"][:6]:
        print("   ", r["user"], "=>", r["bot"][:50])


if __name__ == "__main__":
    main()
