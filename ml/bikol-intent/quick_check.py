"""Quick sanity check of the dataset (no installs needed): a tiny Naive Bayes model.

    python quick_check.py

Trains on dataset/train.csv, then scores dataset/test.csv and dataset/real_check.csv
(real Bikol questions from the chatbot's reference file, not made by build_dataset.py).
The real model is trained later on Colab; this only shows whether the data is learnable.
"""
import csv
import math
import re
from collections import Counter, defaultdict
from pathlib import Path

D = Path(__file__).parent / "dataset"


def load(name):
    with open(D / name, encoding="utf-8") as f:
        return [(r["question"], r["intent"]) for r in csv.DictReader(f)]


def feats(q):
    q = " " + re.sub(r"[^\w\s'-]", "", q.lower()) + " "
    return [q[i:i + n] for n in (3, 4, 5) for i in range(len(q) - n + 1)] + ["w:" + w for w in q.split()]


def main():
    train = load("train.csv")
    cnt, prior = defaultdict(Counter), Counter()
    for q, y in train:
        prior[y] += 1
        cnt[y].update(feats(q))
    vocab = len({f for c in cnt.values() for f in c})
    tot = {y: sum(c.values()) for y, c in cnt.items()}

    def predict(q):
        fs = feats(q)
        return max(prior, key=lambda y: math.log(prior[y])
                   + sum(math.log((cnt[y][f] + 0.1) / (tot[y] + 0.1 * vocab)) for f in fs))

    for name in ("test.csv", "real_check.csv", "real_check_ono.csv"):
        if not (D / name).exists():
            continue
        data = load(name)
        wrong = [(y, predict(q), q) for q, y in data if predict(q) != y]
        print(f"{name}: {len(data) - len(wrong)}/{len(data)} correct = {1 - len(wrong) / len(data):.0%}")
        for y, p, q in wrong:
            print(f"    should be {y:10s} got {p:10s} | {q}")


if __name__ == "__main__":
    main()
