"""Train the "health or chat?" gate and export it for PHP.

    python train_gate.py            (needs scikit-learn; no GPU)

Reads dataset/gate_all.csv (made by build_gate_dataset.py), trains TF-IDF + logistic regression
with the same feature function as ml/bikol-intent (so app/Services/BikolIntentClassifier.php can run it
unchanged), prints the test results, and writes:
    model/bikol_gate_model.json  and  ../../resources/ml/bikol_gate_model.json

Safety goal: a health question must never be sent to the chat model. So besides accuracy, we report
how many HEALTH test messages the gate would call chat at the app's confidence limit.
"""
import csv
import json
import math
import re
import shutil
import sys
from pathlib import Path

from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression

HERE = Path(__file__).parent
DATA = HERE / "dataset" / "gate_all.csv"
MODEL_DIR = HERE / "model"
APP_MODEL = HERE.parent.parent / "resources" / "ml" / "bikol_gate_model.json"
APP_LIMIT = 0.75  # must match services.bikol_chat.gate_min_confidence

# Hand-written tricky messages (not in the training data). expected label per line.
HARD = [
    ("Masakit a payo ko", "health"),
    ("Kolog ana ulo ko", "health"),
    ("Ono a gigibowon kin may kalintora?", "health"),
    ("Pirang aldow ana otos?", "health"),
    ("Pwede ba magpabakuna ana buntis?", "health"),
    ("Ono an dapat kaunon kin diabetes?", "health"),
    ("Ono a sinyales ka dengue?", "health"),
    ("Paano maiwasan ang dengue?", "health"),
    ("Kumusta ka?", "chat"),
    ("Gutom na ako", "chat"),
    ("Ono a paborito mong pagkaon?", "chat"),
    ("Salamat tabi", "chat"),
    ("Maray a aldow", "chat"),
    ("Siisay ang gumibo saimo?", "chat"),
    ("Pano ka magkaigwa kan sadiri mo?", "chat"),
    ("Tabi, paaram ako kan oras", "chat"),
]


def features(text):
    """Must stay identical to BikolIntentClassifier::features() in PHP."""
    text = re.sub(r"[^a-z0-9'\- ]+", " ", text.lower())
    out = []
    for word in text.split():
        out.append("w:" + word)
        padded = " " + word + " "
        for n in range(2, 6):
            for i in range(len(padded) - n + 1):
                out.append(padded[i:i + n])
    return out


def load(split):
    with open(DATA, encoding="utf-8") as f:
        return [(r["text"], r["label"]) for r in csv.DictReader(f) if r["split"] == split]


def report(name, model, rows):
    wrong_h2c, wrong_c2h, ok = [], [], 0
    for text, label in rows:
        p = dict(zip(model.classes_, model.predict_proba([text])[0]))
        chat_for_app = p["chat"] >= APP_LIMIT  # the app only sends to the chat model above the limit
        pred = "chat" if chat_for_app else "health"
        if pred == label:
            ok += 1
        elif label == "health":
            wrong_h2c.append((text, p["chat"]))
        else:
            wrong_c2h.append((text, p["chat"]))
    print(f"\n{name}: {ok}/{len(rows)} = {ok / len(rows):.1%} (at app limit {APP_LIMIT})")
    print(f"  HEALTH sent to chat (dangerous): {len(wrong_h2c)}")
    for t, c in wrong_h2c:
        print(f"      chat={c:.2f} | {t}")
    print(f"  chat that went to RAG (harmless, just not small talk): {len(wrong_c2h)}")
    for t, c in wrong_c2h[:15]:
        print(f"      chat={c:.2f} | {t}")
    return len(wrong_h2c)


def fit(rows, C):
    vec = TfidfVectorizer(analyzer=features, sublinear_tf=True)
    X = vec.fit_transform([t for t, _ in rows])
    lr = LogisticRegression(max_iter=5000, class_weight="balanced", C=C)
    lr.fit(X, [y for _, y in rows])

    class Model:  # tiny wrapper so predict_proba takes raw text
        classes_ = lr.classes_

        @staticmethod
        def predict_proba(texts):
            return lr.predict_proba(vec.transform(texts))

    return Model, vec, lr


def main():
    train, test = load("train"), load("test")
    print(f"train {len(train)} / test {len(test)}")

    best = None
    for C in (1, 3, 10, 30):
        model, vec, lr = fit(train, C)
        print(f"\n=== C={C} ===")
        bad = report("test", model, test)
        bad_hard = report("hard list", model, HARD)
        score = (bad + bad_hard, C)
        if best is None or score < best[0]:
            best = (score, C)
    C = best[1]
    print(f"\nChosen C={C} (fewest health-to-chat mistakes)")

    # final model: train on train + test so the app learns from every reviewed message
    model, vec, lr = fit(train + test, C)

    classes = list(lr.classes_)
    coef = lr.coef_[0]
    # binary case: sklearn gives one coefficient row (for classes_[1]); PHP uses softmax over both
    # classes, so split it: class0 = -w/2, class1 = +w/2  (softmax of that equals the sigmoid).
    b = float(lr.intercept_[0])
    feats = {}
    for f, j in vec.vocabulary_.items():
        w = float(coef[j])
        feats[f] = [round(float(vec.idf_[j]), 5), round(-w / 2, 5), round(w / 2, 5)]

    samples = []
    for text, _ in HARD:
        p = dict(zip(classes, model.predict_proba([text])[0]))
        samples.append({"question": text, "intent": max(p, key=p.get), "confidence": round(max(p.values()), 4)})

    export = {
        "name": "bikol-iriga-gate",
        "classes": classes,
        "intercept": [round(-b / 2, 5), round(b / 2, 5)],
        "features": feats,
        "best_C": C,
        "app_limit": APP_LIMIT,
        "samples": samples,
    }
    MODEL_DIR.mkdir(exist_ok=True)
    out = MODEL_DIR / "bikol_gate_model.json"
    out.write_text(json.dumps(export, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    APP_MODEL.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(out, APP_MODEL)
    print(f"\nsaved {len(feats)} features -> {out} and {APP_MODEL}")


if __name__ == "__main__":
    sys.exit(main())
