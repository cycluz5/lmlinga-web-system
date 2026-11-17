"""Step 2b: translate each Tagalog dialog into Iriga, using YOUR team's translations as the examples.

    python translate_to_iriga.py [workers=8] [only_first_N]      (needs scikit-learn)

For every dialog: the most similar memory rows (build_memory.py) + word hints for the Tagalog words in it + the team's
core words and spelling rules go into the prompt, so the cloud model copies your wording. Writes
dataset/teacher/dialogs_iriga_raw.jsonl. Resumable; stops cleanly on 402/429. The result is only a DRAFT: filter_teacher.py
keeps just the dialogs made of your own words.
"""
import csv
import json
import re
import sys
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed

from sklearn.feature_extraction.text import TfidfVectorizer

from common import TEACHER, OutOfCredits, chat

SRC = TEACHER / "dialogs_tl.jsonl"
OUT = TEACHER / "dialogs_iriga_raw.jsonl"
WORD = re.compile(r"[^\W\d_]+(?:['’-][^\W\d_]+)*", re.UNICODE)

CORE = """Mga pangunahing salita at tuntunin ng Bikol-Iriga (Rinconada) ng aming team:
ako = I | ika = ikaw (you) | kamo = kayo | kita = tayo | ana = ang (the) | kana = ng/ni/ng (of) | sa = sa | ag = at (and) | kin = kung (if)
ono = ano (what) | paono = paano (how) | sari = saan (where) | siisay = sino (who) | ngata = bakit (why) | kono = kailan (when) | pira = ilan/magkano
agko = may/mayroon (have/there is) | uda = wala (none) | diri = huwag/hindi (don't, before verbs) | boko = hindi/bukod (no, not, before nouns)
amo = oo/iyon (yes) | ngowan = ngayon (now/today) | udma = bukas (tomorrow) | aldow = araw | ramrag = umaga | modto = tanghali | apon = hapon | gab'i = gabi
maray = mabuti/maganda | salamat | tabi = po/pakiusap | raw = daw/raw | man = din/naman | sana = lang | pa = pa | na = na | osad = isa (one)
mamoya = gusto/masaya | maogma = masaya | napagal = pagod | alop = gutom | torog = tulog | kaon = kain | inom = inom | baluy = bahay | tobig = tubig
Spelling: ono (hindi ano/uno), ana (hindi ang/an), kana (hindi kan/nin), ngowan (hindi ngayon/nguwan), osad (hindi usad/sarong), uda (hindi mayo/wala), agko (hindi igwa)."""

SYSTEM = f"""Isinasalin mo ang usapan mula Tagalog papuntang Bikol-Iriga (Rinconada) para sa chatbot na LMLinga.
{CORE}
Mga patakaran:
- Gamitin ANG MGA HALIMBAWANG ISINALIN NG TEAM at ang mga salitang nasa listahan. Sundan ang kanilang pananalita at baybay.
- Huwag gumamit ng Naga Bikol (marhay, mayo, sarong, igwa, asin, nin, kun, ano, ngunyan) at huwag iwanang Tagalog ang mga salita kung may katumbas sa mga halimbawa.
- Panatilihing maikli at simple ang bawat linya; ganoon din ang kahulugan. Kapag hindi sigurado, pumili ng mas simpleng salita na nasa mga halimbawa.
- Huwag magdagdag ng bagong impormasyon, emoji o markdown.
Ibalik LAMANG ang JSON: {{"turns":[{{"speaker":"Person","text":"..."}}, ...]}} na may parehong bilang at pagkakasunod ng turn."""


def load_memory():
    with open(TEACHER / "memory.csv", encoding="utf-8-sig") as f:
        rows = list(csv.DictReader(f))
    examples = [r for r in rows if r["lang"] == "tl" and r["kind"] in ("phrase", "sentence")]
    hints = {}
    for r in rows:
        if r["lang"] == "tl" and r["kind"] in ("word", "vocab") and len(WORD.findall(r["source"])) == 1:
            if r["iriga"].lower() != r["source"].lower():
                hints.setdefault(r["source"].lower(), r["iriga"])
    return examples, hints


def main():
    workers = int(sys.argv[1]) if len(sys.argv) > 1 else 8
    examples, hints = load_memory()
    vec = TfidfVectorizer(analyzer="char_wb", ngram_range=(2, 4), lowercase=True)
    matrix = vec.fit_transform([e["source"] for e in examples])
    print(f"memory: {len(examples)} example pairs, {len(hints)} word hints")

    done = set()
    if OUT.exists():
        for line in OUT.read_text(encoding="utf-8").splitlines():
            if line.strip():
                done.add(json.loads(line)["id"])
    dialogs = [json.loads(l) for l in SRC.read_text(encoding="utf-8").splitlines() if l.strip()]
    todo = [d for d in dialogs if d["id"] not in done]
    if len(sys.argv) > 2:           # optional: translate only the first N (a quick try)
        todo = todo[:int(sys.argv[2])]
    print(f"{len(done)} translated, {len(todo)} to go")

    lock, stop = threading.Lock(), threading.Event()
    stats = {"ok": 0, "bad": 0}

    def prompt_for(d):
        texts = [t["text"] for t in d["turns"]]
        scores = (vec.transform(texts) @ matrix.T).toarray()
        picked, seen = [], set()
        for row in scores:
            for j in row.argsort()[::-1][:3]:
                if row[j] > 0.15 and j not in seen:
                    seen.add(j)
                    picked.append(examples[j])
        picked = picked[:16]
        toks = {w.lower() for t in texts for w in WORD.findall(t)}
        hint_lines = [f"{w} = {hints[w]}" for w in sorted(toks) if w in hints][:70]
        ex = "\n".join(f"Tagalog: {e['source']}\nBikol-Iriga: {e['iriga']}" for e in picked)
        return (f"MGA HALIMBAWANG ISINALIN NG TEAM:\n{ex}\n\nMGA SALITA:\n" + "\n".join(hint_lines)
                + "\n\nISALIN ANG USAPANG ITO:\n" + json.dumps({"turns": d["turns"]}, ensure_ascii=False))

    def work(d):
        if stop.is_set():
            return None
        out = chat(SYSTEM, prompt_for(d), temperature=0.2, num_predict=900)
        t = out.get("turns") if isinstance(out, dict) else None
        if (not isinstance(t, list) or len(t) != len(d["turns"])
                or any(not isinstance(x, dict) or x.get("speaker") != y["speaker"] or not str(x.get("text", "")).strip()
                       for x, y in zip(t, d["turns"]))):
            return ("bad", None)
        return ("ok", {"id": d["id"], "topic": d["topic"], "situation": d["situation"], "tagalog": d["turns"],
                       "turns": [{"speaker": x["speaker"], "text": str(x["text"]).strip()} for x in t]})

    with ThreadPoolExecutor(workers) as ex, open(OUT, "a", encoding="utf-8") as f:
        futs = [ex.submit(work, d) for d in todo]
        for n, fut in enumerate(as_completed(futs), 1):
            try:
                res = fut.result()
            except OutOfCredits as e:
                stop.set()
                print("STOPPED:", e)
                continue
            except Exception:
                stats["bad"] += 1
                continue
            if res is None:
                continue
            if res[0] == "ok":
                with lock:
                    f.write(json.dumps(res[1], ensure_ascii=False) + "\n")
                    f.flush()
                stats["ok"] += 1
            else:
                stats["bad"] += 1
            if n % 100 == 0:
                print(f"  {n}/{len(todo)} (ok {stats['ok']}, bad {stats['bad']})")
    print(f"finished: {stats['ok']} translated, {stats['bad']} failed/malformed -> {OUT}")


if __name__ == "__main__":
    main()
