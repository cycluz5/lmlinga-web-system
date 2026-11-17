"""Step 2a: the cloud teacher writes natural Tagalog dialogs (it is strong in Tagalog, weak in Iriga).

    python gen_tagalog_dialogs.py [per_topic=20] [workers=6]

Writes dataset/teacher/dialogs_tl.jsonl (one JSON per line). Resumable: ids already in the file are skipped, and it stops
cleanly if the cloud runs out of credits (402/429).
"""
import json
import sys
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed

from common import TEACHER, OutOfCredits, chat
from topics import requests

OUT = TEACHER / "dialogs_tl.jsonl"

SYSTEM = """Gumagawa ka ng mga halimbawang usapan sa Tagalog para sa isang chatbot na pinangalanang LMLinga.
Si LMLinga ay palakaibigang chatbot ng isang health center sa Iriga City. Sa usapang ito, NORMAL NA KAUSAP lang siya.
Mga patakaran:
- Sumasagot si LMLinga nang natural, maikli (1 o 2 pangungusap), mainit at simple. Gumamit ng simpleng salita at maiikling pangungusap (hanggang 12 salita).
- Hindi niya binabanggit ang kalusugan, doktor, health center o gamot maliban kung ang Person mismo ang nagsabi. Walang payo medikal.
- Kung may tanong na hindi niya alam (presyo, balita, politika, resulta ng laro), magalang siyang aamin na hindi niya alam at magbibigay ng ibang paraan ng pagsagot (iba-iba ang pananalita, huwag ulitin ang parehong linya).
- Chatbot lang si LMLinga: wala siyang katawan, hindi siya kumakain, hindi pumupunta sa mga lugar at walang pamilya. Huwag siyang magkunwaring tao; kung tinanong, malumanay niyang sasabihin na chatbot lang siya, pero masaya siyang makipag-usap.
- Minsan nagtatanong din siya pabalik tungkol sa araw o gusto ng Person.
- Huwag gumamit ng emoji, markdown o pangalan ng totoong tao.
- Ang Person ay karaniwang tao na nagsasalita nang kaswal (maikli, minsan may Taglish).
Ibalik LAMANG ang JSON: {"turns":[{"speaker":"Person","text":"..."},{"speaker":"LMLinga","text":"..."}, ...]}
Palitan ang bawat Person at LMLinga, nagsisimula sa Person at nagtatapos sa LMLinga."""


def make_user(topic, situation, mood, opening, pairs):
    return (f"Paksa: {topic}\nSitwasyon: {situation}\nMood ng Person: {mood}\nSimula: {opening}\n"
            f"Gumawa ng usapan na may {pairs} na tanong/sagot na pares ({pairs * 2} turn lahat).")


def valid(d, pairs):
    t = d.get("turns") if isinstance(d, dict) else None
    if not isinstance(t, list) or len(t) != pairs * 2:
        return False
    for i, x in enumerate(t):
        if not isinstance(x, dict) or x.get("speaker") != ("Person" if i % 2 == 0 else "LMLinga"):
            return False
        if not isinstance(x.get("text"), str) or not x["text"].strip():
            return False
    return True


def main():
    per_topic = int(sys.argv[1]) if len(sys.argv) > 1 else 20
    workers = int(sys.argv[2]) if len(sys.argv) > 2 else 6
    done = set()
    if OUT.exists():
        for line in OUT.read_text(encoding="utf-8").splitlines():
            if line.strip():
                done.add(json.loads(line)["id"])
    todo = [r for r in requests(per_topic) if r[0] not in done]
    print(f"{len(done)} dialogs already written, {len(todo)} to go")

    lock = threading.Lock()
    stop = threading.Event()
    ok = bad = 0

    def work(req):
        rid, topic, situation, mood, opening, pairs = req
        if stop.is_set():
            return None
        d = chat(SYSTEM, make_user(topic, situation, mood, opening, pairs), temperature=0.85, num_predict=600)
        if not valid(d, pairs):
            return ("bad", rid)
        return ("ok", {"id": rid, "topic": topic, "situation": situation, "turns": d["turns"]})

    with ThreadPoolExecutor(workers) as ex, open(OUT, "a", encoding="utf-8") as f:
        futures = [ex.submit(work, r) for r in todo]
        for n, fut in enumerate(as_completed(futures), 1):
            try:
                res = fut.result()
            except OutOfCredits as e:
                stop.set()
                print("STOPPED:", e)
                continue
            except Exception as e:
                bad += 1
                continue
            if res is None:
                continue
            if res[0] == "ok":
                with lock:
                    f.write(json.dumps(res[1], ensure_ascii=False) + "\n")
                    f.flush()
                ok += 1
            else:
                bad += 1
            if n % 50 == 0:
                print(f"  {n}/{len(todo)} done (ok {ok}, bad {bad})")
    print(f"finished: {ok} new dialogs, {bad} failed or malformed -> {OUT}")


if __name__ == "__main__":
    main()
