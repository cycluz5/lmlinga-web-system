"""Find the words Cloud Gemma writes that are NOT in the team's Bikol-Iriga word list.

    python collect_gemma_words.py [path to lmlinga-train/ml/bikol-chat] [more_messages.txt]

1. Collects Bikol test messages (the team's unrelated-chat test questions, free_chat_test.txt, your own file).
2. Sends each one through Cloud Gemma (the real app code: BikolCloudChatService, so the term layer is already applied).
3. Lists every reply word that is not in dataset/allowed_words.csv, with a count and an example sentence.

Writes ml/bikol-cloud/cloud_term_review.csv: columns  word, count, example, suggestion, iriga
The team fills the "iriga" column (the Bikol-Iriga word, or leave empty to keep the word as it is).
Rows already filled are kept when this script runs again. Then run build_iriga_terms.py to use them.

Needs OLLAMA_API_KEY in .env and Ollama Cloud credits (about 80 short calls).
"""
import csv
import json
import os
import re
import subprocess
import sys
from collections import Counter
from pathlib import Path

HERE = Path(__file__).parent
REPO = HERE.parent.parent
DEFAULT_TRAIN = REPO.parent / "lmlinga-train" / "ml" / "bikol-chat"
OUT = HERE / "cloud_term_review.csv"
WORD = re.compile(r"[^\W\d_]+(?:['’\-][^\W\d_]+)*", re.UNICODE)

# Words the team already ruled on in earlier reviews (aram -> isi, salud -> kalusugan, ...). Used as the starting answer.
DECIDED = {"hapot": "onga", "aram": "isi", "salud": "kalusugan", "gising": "gisong", "giraray": "ulit", "igdi": "sadi"}

PHP = r"""
$in = json_decode(file_get_contents(getenv('GEMMA_IN')), true);
$svc = app(App\Services\BikolCloudChatService::class);
$out = [];
foreach ($in as $m) { $out[] = ['q' => $m, 'a' => $svc->smallTalk($m)]; }
file_put_contents(getenv('GEMMA_OUT'), json_encode($out, JSON_UNESCAPED_UNICODE));
"""


def read_csv(path):
    with open(path, encoding="utf-8-sig") as f:
        return list(csv.DictReader(f))


def main():
    train = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_TRAIN
    data = train / "dataset"

    messages = []
    unrelated = train.parent.parent / "resources" / "test_questions" / "local_ai" / "unrelated_by_topic.csv"
    if unrelated.exists():
        messages += [r["message"] for r in read_csv(unrelated) if r.get("message")]
    free = data / "free_chat_test.txt"
    if free.exists():
        messages += [l.strip() for l in free.read_text(encoding="utf-8-sig").splitlines() if l.strip()]
    if len(sys.argv) > 2:
        messages += [l.strip() for l in Path(sys.argv[2]).read_text(encoding="utf-8").splitlines() if l.strip()]
    messages += ["Ano na an oras?", "Ano an aldaw ngunyan?", "Salamat", "Kumusta ka?", "Paano ako makakatipid nin kwarta?",
                 "Ano an magayon na lugar sa Bikol?", "Pwede mo daw akong tabangan sa sakong assignment?"]
    messages = list(dict.fromkeys(messages))

    allowed = {r["word"] for r in read_csv(data / "allowed_words.csv")}
    tagalog_to_iriga = {}
    for r in read_csv(data / "team_words.csv"):
        tagalog_to_iriga.setdefault(r["tagalog"].lower(), r["iriga"].lower())

    tmp_in, tmp_out = HERE / "_gemma_in.json", HERE / "_gemma_out.json"
    tmp_in.write_text(json.dumps(messages, ensure_ascii=False), encoding="utf-8")
    env = dict(os.environ, GEMMA_IN=str(tmp_in), GEMMA_OUT=str(tmp_out))
    subprocess.run(["php", "artisan", "tinker", "--execute", PHP], cwd=REPO, env=env, check=True)
    replies = json.loads(tmp_out.read_text(encoding="utf-8"))
    tmp_in.unlink(missing_ok=True)
    tmp_out.unlink(missing_ok=True)

    counts, example = Counter(), {}
    answered = 0
    for item in replies:
        if not item["a"]:
            continue
        answered += 1
        for w in WORD.findall(item["a"]):
            w = w.lower().replace("’", "'")
            if w not in allowed and len(w) > 2:
                counts[w] += 1
                example.setdefault(w, item["a"].replace("\n", " ")[:160])
    print(f"{answered}/{len(messages)} messages answered, {len(counts)} words not in the team list")

    old = {r["word"]: r for r in read_csv(OUT)} if OUT.exists() else {}
    rows = []
    for w, c in counts.most_common():
        keep = old.get(w, {})
        rows.append({"word": w, "count": c, "example": example[w],
                     "suggestion": tagalog_to_iriga.get(w, "") or ("team decision" if w in DECIDED else ""),
                     "iriga": keep.get("iriga") or DECIDED.get(w, "")})
    for w, r in old.items():  # keep rows the team already filled even if Gemma did not use them this time
        if r.get("iriga") and w not in counts:
            rows.append(r)
    with open(OUT, "w", encoding="utf-8-sig", newline="") as f:
        wr = csv.DictWriter(f, fieldnames=["word", "count", "example", "suggestion", "iriga"])
        wr.writeheader()
        wr.writerows(rows)
    print(f"wrote {OUT}")


if __name__ == "__main__":
    main()
