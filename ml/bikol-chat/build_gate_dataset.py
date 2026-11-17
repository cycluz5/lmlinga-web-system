"""Build the "health or chat?" gate dataset.

    python build_gate_dataset.py

Writes dataset/gate_all.csv (text,label,source,split):
  label  health = goes to the RAG path (verified health documents)
         chat    = unrelated message, goes to the trained Bikol-Iriga chat model
  split  train / test (test = held out by conversation id or topic, so the model is
         not tested on near-copies of what it trained on)

Sources are the team-reviewed files already in the repo, nothing is invented here.
Spelling variants (ono/uno, a/ana, ka/kana) are added to the HEALTH side only when the
source has only one style, so both styles are recognised.
"""
import csv
import random
import re
from pathlib import Path

HERE = Path(__file__).parent
CHAT = HERE / "dataset"
INTENT = HERE.parent / "bikol-intent" / "dataset"

random.seed(7)


def read(path):
    with open(path, encoding="utf-8") as f:
        return list(csv.DictReader(f))


def spelling_variants(text):
    """Extra spellings of one health question (the app accepts uno|ono and a|ana)."""
    out = {text}
    out.add(re.sub(r"\bono\b", "uno", text, flags=re.I))
    out.add(re.sub(r"\buno\b", "ono", text, flags=re.I))
    out.add(re.sub(r"\bono a\b", "ono ana", text, flags=re.I))
    out.add(re.sub(r"\bono a\b", "ono an", text, flags=re.I))
    return sorted(out)


def main():
    rows = []  # (text, label, source, group)

    # --- HEALTH ---------------------------------------------------------
    for r in read(INTENT / "bikol_intent_all.csv"):
        for t in spelling_variants(r["question"]):
            rows.append((t, "health", "intent_dataset", "topic:" + r["topic"]))
    for name in ("real_check.csv", "real_check_ono.csv"):
        for r in read(INTENT / name):
            rows.append((r["question"], "health", "real_check", "real:" + r["question"][:20]))
    for r in read(CHAT / "situational.csv"):
        if r["category"] == "health_redirect":
            rows.append((r["user"], "health", "situational_health", "sit:" + r["conv_id"]))

    # --- CHAT -----------------------------------------------------------
    for r in read(CHAT / "chat_pairs.csv"):
        rows.append((r["user"], "chat", "chat_pairs", "chat:" + r["conv_id"]))
    for r in read(CHAT / "situational.csv"):
        if r["category"] != "health_redirect":
            rows.append((r["user"], "chat", "situational", "sit:" + r["conv_id"]))
    for r in read(CHAT / "dialogs.csv"):
        if r["speaker"] == "Person":
            rows.append((r["text"], "chat", "dialogs", "dlg:" + r["dialog_id"]))

    # --- clean + dedupe -------------------------------------------------
    seen, clean = set(), []
    for text, label, source, group in rows:
        text = " ".join(text.split())
        key = (text.lower(), label)
        if not text or key in seen:
            continue
        seen.add(key)
        clean.append((text, label, source, group))

    # a text that appears with both labels would teach the model nothing: drop it
    labels_by_text = {}
    for text, label, *_ in clean:
        labels_by_text.setdefault(text.lower(), set()).add(label)
    clean = [r for r in clean if len(labels_by_text[r[0].lower()]) == 1]

    # --- split by group (about 20% of groups go to test) -----------------
    groups = sorted({g for *_, g in clean})
    random.shuffle(groups)
    test_groups = set(groups[: max(1, len(groups) // 5)])

    out = CHAT / "gate_all.csv"
    with open(out, "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["text", "label", "source", "split"])
        for text, label, source, group in clean:
            w.writerow([text, label, source, "test" if group in test_groups else "train"])

    n_h = sum(1 for r in clean if r[1] == "health")
    print(f"wrote {out}: {len(clean)} rows (health {n_h}, chat {len(clean) - n_h})")


if __name__ == "__main__":
    main()
