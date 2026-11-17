"""Write colab/train_bikol_intent.ipynb (the Google Colab training notebook).

    python make_notebook.py
"""
import json
from pathlib import Path

CELLS = []


def md(text):
    CELLS.append({"cell_type": "markdown", "metadata": {}, "source": text.strip("\n")})


def code(text):
    CELLS.append({"cell_type": "code", "metadata": {}, "execution_count": None, "outputs": [],
                  "source": text.strip("\n")})


md("""
# Bikol-Iriga intent model — training

This notebook trains the model that tells the LMLinga chatbot **what kind of question** a resident is asking
(definition, symptoms, causes, management, diagnosis, benefits, effects, schedule, warning, general).

**How to use:** click **Runtime → Run all**. When step 1 asks, upload these 4 files
(from `Desktop\\datasets\\colab_upload`):

- `train.csv`
- `test.csv`
- `real_check.csv`
- `real_check_ono.csv`

At the end the notebook downloads **`bikol_intent_model.json`** — that file is the trained model.
""")

md("## Step 1 — Upload the data files")
code("""
import os
try:
    from google.colab import files  # running on Colab
    print("Choose the 4 CSV files (train.csv, test.csv, real_check.csv, real_check_ono.csv)")
    files.upload()
    DATA_DIR = "."
except ImportError:  # running on a normal computer
    files = None
    DATA_DIR = os.environ.get("BIKOL_DATA_DIR", "../dataset")
print("Files found:", sorted(f for f in os.listdir(DATA_DIR) if f.endswith(".csv")))
""")

md("""
## Step 2 — How the model reads a question

The question is cut into small pieces of letters (for example `ono` → ` o`, `on`, `no`, `o `, ` on` …)
plus the whole words. Small pieces help the model understand spelling variants
(*sinyales / senyales*, *igin / igen*, *ono a / ono an*).

⚠️ The chatbot (PHP) must cut questions **exactly the same way**, so do not change this function
unless the PHP code is changed too.
""")
code("""
import re

def features(text):
    t = text.lower()
    t = re.sub(r"[^a-z0-9'\\- ]+", " ", t)
    out = []
    for w in t.split():
        out.append("w:" + w)
        p = " " + w + " "
        for n in range(2, 6):
            for i in range(len(p) - n + 1):
                out.append(p[i:i + n])
    return out

print(features("Ono a sintomas ka dengue?")[:20], "...")
""")

md("## Step 3 — Load the data")
code("""
import pandas as pd

train = pd.read_csv(f"{DATA_DIR}/train.csv")
test = pd.read_csv(f"{DATA_DIR}/test.csv")
extra = {}
for name in ["real_check.csv", "real_check_ono.csv"]:
    if os.path.exists(f"{DATA_DIR}/{name}"):
        extra[name] = pd.read_csv(f"{DATA_DIR}/{name}")

print(f"train: {len(train)} questions   test: {len(test)} questions")
print(train["intent"].value_counts().to_string())
""")

md("## Step 4 — Train (tries a few settings and keeps the best)")
code("""
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import GridSearchCV, StratifiedKFold
from sklearn.pipeline import Pipeline

pipe = Pipeline([
    ("tfidf", TfidfVectorizer(analyzer=features, sublinear_tf=True)),
    ("lr", LogisticRegression(max_iter=5000, class_weight="balanced")),
])
search = GridSearchCV(
    pipe, {"lr__C": [1, 3, 10, 30, 100]},
    cv=StratifiedKFold(5, shuffle=True, random_state=42), scoring="accuracy",
)
search.fit(train["question"], train["intent"])
model = search.best_estimator_
print("best setting:", search.best_params_, f"  practice score: {search.best_score_:.1%}")
""")

md("## Step 5 — Test on questions the model has never seen")
code("""
from sklearn.metrics import accuracy_score, classification_report

def check(name, df, show_mistakes=True):
    pred = model.predict(df["question"])
    print(f"=== {name}: {accuracy_score(df['intent'], pred):.1%} correct ({len(df)} questions)")
    if show_mistakes:
        wrong = df[pred != df["intent"]].assign(predicted=pred[pred != df["intent"]])
        for _, r in wrong.iterrows():
            print(f"   should be {r['intent']:<11} got {r['predicted']:<11} | {r['question']}")
    return pred

test_pred = check("test.csv", test)
for name, df in extra.items():
    check(name, df)

print()
print(classification_report(test["intent"], test_pred, zero_division=0))
""")

md("### Which types get mixed up? (rows = correct type, columns = model's answer)")
code("""
import matplotlib.pyplot as plt
from sklearn.metrics import ConfusionMatrixDisplay

fig, ax = plt.subplots(figsize=(8, 8))
ConfusionMatrixDisplay.from_predictions(test["intent"], test_pred, ax=ax, xticks_rotation=45, colorbar=False)
plt.tight_layout()
plt.show()
""")

md("""
## Step 6 — How sure is the model?

The model also gives a **confidence** (0–100%). When it is not sure, the chatbot can fall back to its old rules.
This table shows: if we only trust answers above X%, how many questions are covered and how many are right.
""")
code("""
import numpy as np

all_eval = pd.concat([test] + list(extra.values()), ignore_index=True)
proba = model.predict_proba(all_eval["question"])
conf = proba.max(axis=1)
pred = model.classes_[proba.argmax(axis=1)]
right = pred == all_eval["intent"].to_numpy()
print("trust above | questions covered | correct among them")
for t in [0.0, 0.3, 0.4, 0.5, 0.6, 0.7]:
    keep = conf >= t
    print(f"   {t:>4.0%}     |      {keep.mean():6.1%}       |   {right[keep].mean():6.1%}")
""")

md("## Step 7 — Try your own questions (edit the list and run again)")
code("""
my_questions = [
    "Ono a sintomas ka dengue?",
    "Paono malikayan a trangkaso?",
    "Kono a bakuna ka igin ko?",
    "Kinagat sa ayam a igin ko, ono a gibowon ko?",
    "Ono an benepisyo ka pagpapasoso?",
]
for q in my_questions:
    p = model.predict_proba([q])[0]
    best = p.argmax()
    print(f"{model.classes_[best]:<11} {p[best]:5.0%}  | {q}")
""")

md("""
## Step 8 — Save the model for the chatbot

This makes **`bikol_intent_model.json`** and downloads it.
Send that file to Claude (put it in `ml/bikol-intent/model/`) to connect it to the chatbot.
""")
code("""
import json, datetime

vec = model.named_steps["tfidf"]
lr = model.named_steps["lr"]
coef = lr.coef_
feats = {}
for f, j in vec.vocabulary_.items():
    feats[f] = [round(float(vec.idf_[j]), 5)] + [round(float(c), 5) for c in coef[:, j]]

samples = []
for q in list(test["question"].sample(25, random_state=1)) + my_questions:
    p = model.predict_proba([q])[0]
    samples.append({"question": q, "intent": model.classes_[p.argmax()],
                    "confidence": round(float(p.max()), 4)})

export = {
    "name": "bikol-iriga-intent",
    "created": datetime.datetime.now().isoformat(timespec="seconds"),
    "how_to_read": "lowercase; replace chars not in [a-z0-9'- ] with space; per word: 'w:'+word and "
                   "letter pieces of length 2-5 from ' '+word+' '; tf = 1+ln(count); x = tf*idf; "
                   "L2-normalize x; score[c] = intercept[c] + sum(x*coef[c]); softmax",
    "classes": list(model.classes_),
    "intercept": [round(float(v), 5) for v in lr.intercept_],
    "features": feats,  # feature -> [idf, coef for each class in 'classes' order]
    "best_C": search.best_params_["lr__C"],
    "test_accuracy": round(float(accuracy_score(test["intent"], test_pred)), 4),
    "samples": samples,  # used to check that the PHP version gives the same answers
}
with open("bikol_intent_model.json", "w", encoding="utf-8") as f:
    json.dump(export, f, ensure_ascii=False, separators=(",", ":"))
print(f"saved: {len(feats)} features, {os.path.getsize('bikol_intent_model.json') / 1e6:.1f} MB")
if files:
    files.download("bikol_intent_model.json")
""")

nb = {
    "cells": CELLS,
    "metadata": {
        "colab": {"name": "train_bikol_intent.ipynb", "provenance": []},
        "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
        "language_info": {"name": "python"},
    },
    "nbformat": 4,
    "nbformat_minor": 5,
}
for i, c in enumerate(nb["cells"]):
    c["id"] = f"cell-{i}"
    c["source"] = [line + "\n" for line in c["source"].split("\n")]
    c["source"][-1] = c["source"][-1].rstrip("\n")

out = Path(__file__).parent / "colab" / "train_bikol_intent.ipynb"
out.parent.mkdir(exist_ok=True)
out.write_text(json.dumps(nb, indent=1, ensure_ascii=False), encoding="utf-8")
print("wrote", out)
