"""Step 3: keep only the teacher dialogs that are made of YOUR words (this replaces a linguist's review).

    python filter_teacher.py      (standard library + openpyxl only)

Reads  dataset/teacher/dialogs_iriga_raw.jsonl
Writes dataset/dialogs_v6.csv              kept dialogs (dialog_id, status, topic, turn, speaker, text)
       dataset/teacher/rejected_teacher.csv  why each other dialog was dropped
       dataset/teacher/unknown_words_teacher.csv  words that most often block an otherwise good dialog
                                                  -> if one is really Iriga, add it to dataset/approved_words.txt and run again
       dataset/teacher/teacher_spotcheck.csv      40 random kept dialogs for a 5-minute human check (ok / bad)

Rules: spelling is first fixed with the team map (uno->ono, nguwan->ngowan, usad->osad ...); then
  LMLinga turns need >= 93% known spoken words, Person turns >= 80%; no blocked (Central Bikol/Tagalog) word;
  no health talk from LMLinga unless the person brought up health; no repeated reply in a dialog; the same reply at most 3 times overall;
  none of the held-out test messages; sensible lengths.
"""
import csv
import random
import re
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from build_team_translation import SPELLING, normalize   # the team spelling map

# Extra respellings the translator often gets wrong, from your team's own decisions and data (never used for a word with
# another meaning): aram->isi, hapot->onga, dapit->manongod, probaran->tistingon, giraray->ulit, igdi->sadi, masabutan->maintindiyan;
# niyo->ninyo and kasi->ta (both forms are in your teammates' sheets), bang->ba.
SPELLING.update({"aram": "isi", "hapot": "onga", "dapit": "manongod", "probaran": "tistingon", "giraray": "ulit", "igdi": "sadi",
                 "digdi": "sadi", "masabutan": "maintindiyan", "niyo": "ninyo", "kasi": "ta", "bang": "ba"})

HERE = Path(__file__).resolve().parent
DATA = HERE.parent / "dataset"
TEACHER = DATA / "teacher"
ROOT = HERE.parents[2]

WORD = re.compile(r"[^\W\d_]+(?:['’-][^\W\d_]+)*", re.UNICODE)
SPOKEN = {"chat_pairs", "dialogs", "situational", "vocabulary_guide", "team_translation", "team_translation_copied"}
ALWAYS_OK = {"lmlinga", "iriga", "am", "pm", "lunes", "martes", "miyerkoles", "huwebes", "biyernes", "sabado", "dominggo",
             "enero", "pebrero", "marso", "abril", "hunyo", "hulyo", "agosto", "setyembre", "oktubre", "nobyembre", "disyembre",
             "pasko", "bikol", "naga", "maynila", "okay", "ok", "hello", "hi", "chatbot"}
# Tagalog function words: if the translator left one of these unchanged, Tagalog leaked in (they mark Tagalog, not Iriga).
TAG_FUNCTION = set("""ang ng mga ay si ni kay sina nina kina nang para pero kasi kaya dahil habang kung kapag pag ba lang naman din rin
daw nga pala muna po opo oo hindi wala may mayroon ito iyan iyon yan yun dito diyan doon ako ikaw ka siya kami tayo kayo sila
ko mo niya namin natin ninyo niyo nila akin iyo kanila ano sino saan kailan bakit paano ilan magkano talaga siguro baka sana ngayon
bukas kahapon ngunit at o bang mukhang pa na rin eh ha hay naku sige""".split())
INTERJ = {"uy", "wow", "ah", "aha", "hmm", "haha", "hehe", "ay", "oh", "oy", "eh", "naku"}
PUSH = re.compile(r"kalusugan|doktor|health|namamatian|onga\s+manongod|bolong|gamot", re.I)
HEALTH_IN_PERSON = re.compile(r"sakit|kalusugan|doktor|bolong|gamot|health|masakit|kolog|lagnat|kalintora|ospital", re.I)


def bot_clean(text):
    """Words the first v6 model learned from the teacher and then leaked into replies (Wow, congrats, nakakatuwa):
    drop the English exclamation, use the team's words for the others (Ganting mo = congratulations, maogma = glad)."""
    text = re.sub(r"\bwow\b[,!.]*\s*", "", text, flags=re.I)
    text = re.sub(r"\bnakakatuwa\b", "maogma", text, flags=re.I)
    text = re.sub(r"\bcongrats\b|\bcongratulations\b", "Ganting mo", text, flags=re.I)
    text = text.strip()
    return text[:1].upper() + text[1:] if text else text


def squash(s):
    return " ".join(re.sub(r"[^\w' ]", " ", s.lower()).split())


def load_words():
    with open(DATA / "allowed_words.csv", encoding="utf-8-sig") as f:
        allowed = {r["word"] for r in csv.DictReader(f) if SPOKEN & set(r["sources"].split(";"))}
    with open(DATA / "blocked_words.csv", encoding="utf-8-sig") as f:
        blocked = {r["word"] for r in csv.DictReader(f)}
    approved = set()
    p = DATA / "approved_words.txt"
    if p.exists():
        approved = {w.strip().lower() for w in p.read_text(encoding="utf-8").splitlines() if w.strip() and not w.startswith("#")}
    return allowed | approved | ALWAYS_OK, blocked


def held_out():
    msgs = set()
    sys.path.insert(0, str(HERE.parent))
    try:
        from dialogs_v4 import HELD_OUT
        msgs |= {squash(m) for m in HELD_OUT}
    except Exception:
        pass
    for path in (DATA / "free_chat_test.txt",):
        if path.exists():
            msgs |= {squash(l) for l in path.read_text(encoding="utf-8").splitlines() if l.strip()}
    q = ROOT / "resources" / "test_questions" / "local_ai" / "unrelated_by_topic.csv"
    if q.exists():
        with open(q, encoding="utf-8-sig") as f:
            msgs |= {squash(r["message"]) for r in csv.DictReader(f)}
    return msgs


def main():
    import json
    ok_words, blocked = load_words()
    test_msgs = held_out()
    raw = [json.loads(l) for l in (TEACHER / "dialogs_iriga_raw.jsonl").read_text(encoding="utf-8").splitlines() if l.strip()]
    # the 40 spot-checked dialogs are used in their reviewed form (dialogs_v6_reviewed.csv), not the raw machine version
    sp = TEACHER / "teacher_spotcheck.csv"
    if sp.exists():
        reviewed_ids = {r["id"] for r in csv.DictReader(open(sp, encoding="utf-8-sig"))}
        raw = [d for d in raw if d["id"] not in reviewed_ids]
    fx = TEACHER / "learned_fixes.json"
    fixes = json.loads(fx.read_text(encoding="utf-8")) if fx.exists() else {"words": {}, "phrases": {}}

    kept, rejected = [], []
    reasons, near_unknown, reply_count = Counter(), Counter(), Counter()
    for d in raw:
        turns = []
        for t in d["turns"]:
            text = " ".join(t["text"].split())
            for a, b in fixes["phrases"].items():                      # fixes learned from the team's corrections
                text = re.sub(r"\b" + re.escape(a) + r"\b", b, text, flags=re.I)
            text = WORD.sub(lambda m: (fixes["words"].get(m.group(0).lower(), m.group(0)) if m.group(0).lower() not in fixes["words"]
                                       else (fixes["words"][m.group(0).lower()].capitalize() if m.group(0)[:1].isupper() else fixes["words"][m.group(0).lower()])), text)
            text, _ = normalize(text)
            if t["speaker"] == "LMLinga":
                text = bot_clean(text)
            turns.append({"speaker": t["speaker"], "text": text})

        why = None
        unknown_here = Counter()
        person_health = any(HEALTH_IN_PERSON.search(t["text"]) for t in turns if t["speaker"] == "Person")
        bot_replies = []
        for i, t in enumerate(turns):
            toks = WORD.findall(t["text"])
            # a word the translator left unchanged from the Tagalog source counts as Iriga (your decision: copied Tagalog words are
            # accepted), unless it is a Tagalog function word
            src = {w.lower() for w in WORD.findall(d["tagalog"][i]["text"])}
            if not toks:
                why = "empty turn"
                break
            lows = [w.lower() for w in toks]
            if any(w in blocked and raw_w != "Mayo" for w, raw_w in zip(lows, toks)):
                why = "blocked word"
                break
            unknown = [w for w in lows if w not in ok_words and w not in INTERJ and not (w in src and w not in TAG_FUNCTION)]
            # LMLinga is what the model learns to SAY, so its replies may keep at most ONE word copied from the Tagalog
            # (a loanword such as "dessert" or a name); the person's lines may mix Tagalog as real people do.
            copied = [w for w in lows if w not in ok_words and w not in INTERJ and w in src and w not in TAG_FUNCTION]
            if t["speaker"] == "LMLinga" and len(copied) > 1:
                why = why or "too much Tagalog in a reply"
            share = 1 - len(unknown) / len(lows)
            need = 0.93 if t["speaker"] == "LMLinga" else 0.80
            if share < need:
                why = "unknown words"
                unknown_here.update(unknown)
            if t["speaker"] == "LMLinga":
                if not (2 <= len(toks) <= 35):
                    why = why or "length"
                if not person_health and PUSH.search(t["text"]):
                    why = why or "health talk"
                bot_replies.append(squash(t["text"]))
            elif squash(t["text"]) in test_msgs:
                why = why or "held-out test message"
        if why is None and len(bot_replies) != len(set(bot_replies)):
            why = "repeated reply in dialog"
        if why is None and any(reply_count[r] >= 3 for r in bot_replies):
            why = "same reply too often"
        if why is None and not (4 <= len(turns) <= 12):
            why = "length"

        if why is None:
            reply_count.update(bot_replies)
            kept.append({"id": d["id"], "topic": d["topic"], "turns": turns, "tagalog": d["tagalog"]})
        else:
            reasons[why] += 1
            if why == "unknown words":
                near_unknown.update(unknown_here)
            rejected.append((d["id"], d["topic"], why, " | ".join(t["text"] for t in turns)[:200]))

    with open(DATA / "dialogs_v6.csv", "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["dialog_id", "status", "topic", "turn", "speaker", "text"])
        for d in kept:
            for n, t in enumerate(d["turns"], 1):
                w.writerow([d["id"], "auto-checked", d["topic"], n, t["speaker"], t["text"]])
    with open(TEACHER / "rejected_teacher.csv", "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["id", "topic", "reason", "text"])
        w.writerows(rejected)
    with open(TEACHER / "unknown_words_teacher.csv", "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["word", "times_in_rejected_dialogs"])
        w.writerows(near_unknown.most_common(200))
    sample = random.Random(5).sample(kept, min(40, len(kept)))
    with open(TEACHER / "teacher_spotcheck.csv", "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["id", "topic", "dialog (Person / LMLinga in Iriga)", "tagalog source", "ok? (yes/bad)", "note"])
        for d in sample:
            w.writerow([d["id"], d["topic"], "\n".join(f"{'P' if t['speaker'] == 'Person' else 'L'}: {t['text']}" for t in d["turns"]),
                        "\n".join(f"{'P' if t['speaker'] == 'Person' else 'L'}: {t['text']}" for t in d["tagalog"]), "", ""])

    print(f"translated {len(raw)} | kept {len(kept)} ({len(kept) / max(len(raw), 1):.0%}) | rejected {len(rejected)}")
    print("reasons:", dict(reasons))
    print("top unknown words blocking dialogs:", ", ".join(f"{w}({n})" for w, n in near_unknown.most_common(25)))


if __name__ == "__main__":
    main()
