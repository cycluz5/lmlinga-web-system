"""Full test questions for the three Bikol - Iriga chatbot types, in resources/test_questions/.

    python ml/bikol-chat/make_test_questions_three_types.py

Context-based    verified documents (RAG) only. No AI model.
Local AI model   RAG for health information; the trained Bikol-Iriga model for everything unrelated.
Cloud AI (Gemma) RAG for health information; Gemma for everything unrelated (natural talk that still leads back to
                 health), with the team's Iriga words instead of pure Naga Bikol.

Nothing here is written by the generator itself: every wording is either a team-reviewed pattern from
ml/bikol-intent/build_dataset.py, a heading copied from a verified Bikol document, a name from
config/bikol_topic_aliases.php, or a message from ml/bikol-chat/make_test_questions.py. All of it stays DRAFT until
a Bikol-Iriga speaker checks the wording. Your team's Local AI files (resources/test_questions/local_ai) are not touched.

Output
  shared/health_full_bank.csv          every reviewed pattern x every topic (the same questions for all three types)
  shared/health_headings_and_names.csv headings of the Bikol documents + the names residents use
  context/ cloud/                      README, unrelated and serious/edge files, and the Iriga-term checks for Cloud
"""
import csv
import importlib.util
import json
import re
import subprocess
from pathlib import Path

HERE = Path(__file__).parent
ROOT = HERE.parent.parent
OUT = ROOT / "resources" / "test_questions"
DOCS = ROOT / "resources" / "health_docs_v2" / "bcl"

import make_test_questions as std  # noqa: E402  (same folder: the team's Local AI generator)

_spec = importlib.util.spec_from_file_location("build_dataset", ROOT / "ml" / "bikol-intent" / "build_dataset.py")
bd = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(bd)

# documents that cover the same topic as a sibling document: a right answer may come from either
SIBLINGS = {
    "common_cold": ["runny_nose"], "runny_nose": ["common_cold"],
    "diarrhea": ["diarrhea_2"], "diarrhea_2": ["diarrhea"],
    "high_blood_pressure": ["hypertension"], "hypertension": ["high_blood_pressure"],
    "influenza": ["influenza_rainy_season"], "influenza_rainy_season": ["influenza"],
    "first_1000_days_nutrition": ["first_1000_days_nutrition_2"], "first_1000_days_nutrition_2": ["first_1000_days_nutrition"],
    "family_planning_facts": ["family_planning_faq"], "family_planning_faq": ["family_planning_facts"],
    "cough": ["tuberculosis_proper_coughing"], "tuberculosis_proper_coughing": ["cough"],
}
TOPIC = {slug: (folder, name, kind) for slug, folder, name, kind in std.TOPICS}

BANK_COLS = ["id", "folder", "topic", "topic_name", "intent", "template_kind", "question", "ask_topic_first",
             "expected_source", "also_accept", "result_ok", "notes"]


def source_path(slug):
    return f"resources/health_docs_v2/bcl/{TOPIC[slug][0]}/{slug}.txt"


def also(slug):
    return ";".join(f"{TOPIC[s][0]}/{s}.txt" for s in SIBLINGS.get(slug, []))


def write_csv(path, rows, cols):
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", encoding="utf-8-sig", newline="") as f:  # BOM so Excel shows the letters correctly
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        w.writerows(rows)


# ------------------------------------------------------------------ 1. every reviewed pattern x every topic
def ana_pattern(tpl):
    """ana/kana wording, but the {x} placeholders are left alone."""
    parts = re.split(r"(\{\w+\})", tpl)
    return "".join(x if x.startswith("{") else std.ana_style(x) for x in parts)


def full_bank():
    rows, seen, n = [], set(), 0
    for slug, folder, name, kind in std.TOPICS:
        k = kind[0]
        for intent, templates in bd.T.items():
            for tpl, tk in templates:
                # I = illness, P = program, O = life stage; A = definition wording that fits any topic;
                # S = schedule wording, only for topics that have a schedule
                if not (tk == k or tk == "A" or (tk == "S" and kind.endswith("S"))):
                    continue
                n += 1
                who = bd.WHO[n % len(bd.WHO)]
                # half of the questions use "ana / kana" (the documents' own words); the topic name is never changed
                pattern = ana_pattern(tpl) if n % 2 == 0 else tpl
                q = pattern.format(i=name, p=name, o=name, s=name, a=name, w=who)
                key = (slug, re.sub(r"\W+", " ", q.lower()).strip())
                if key in seen:
                    continue
                seen.add(key)
                rows.append({"id": f"F{len(rows) + 1:04d}", "folder": folder, "topic": slug, "topic_name": name,
                             "intent": intent, "template_kind": tk, "question": q, "ask_topic_first": "",
                             "expected_source": source_path(slug), "also_accept": also(slug), "result_ok": "", "notes": ""})
    return rows


# ------------------------------------------------------------------ 2. headings of the documents + the names residents use
KEEP_UPPER = {"HIV", "AIDS", "TB", "UTI", "CKD", "DOH", "DOTS", "EPI", "MMR", "BCG", "OPV", "IPV", "GERD", "COVID", "NBS", "SC", "GI"}


def sentence_case(t):
    letters = [c for c in t if c.isalpha()]
    if not letters or sum(c.isupper() for c in letters) / len(letters) < 0.7:
        return t
    out = []
    for w in re.split(r"(\s+)", t):
        core = re.sub(r"[^A-Za-z0-9]", "", w)
        out.append(w if core.upper() in KEEP_UPPER else w.lower())
    s = "".join(out)
    return s[:1].upper() + s[1:]


def heading_questions(slug, path):
    text = path.read_text(encoding="utf-8", errors="replace").replace("﻿", "")
    found, seen = [], set()
    for line in text.splitlines():
        t = re.sub(r"^[-•*]\s*", "", line.strip())
        t = re.sub(r"^\d+\s*[.)]\s*", "", t).strip().strip('"“”')
        if not t.endswith("?") or not (12 <= len(t) <= 140):
            continue
        t = sentence_case(t)
        key = re.sub(r"[^a-z0-9]+", " ", t.lower()).strip()
        if key not in seen:
            seen.add(key)
            found.append(t)
    return found


def aliases():
    """Names residents use, from config/bikol_topic_aliases.php."""
    php = "echo json_encode(include 'config/bikol_topic_aliases.php', JSON_UNESCAPED_UNICODE);"
    raw = subprocess.run(["php", "-r", php], capture_output=True, text=True, cwd=ROOT, encoding="utf-8").stdout
    return json.loads(raw)


NAME_PATTERNS = [
    ("definition", "Ono a {x}?"), ("definition", "Ono ana {x}?"),
    ("symptoms", "Ono a mga sinyales ka {x}?"), ("management", "Paono malikayan a {x}?"),
]


def headings_and_names():
    rows = []

    def add(slug, intent, kind, q, ask_first="", accept_slugs=()):
        rows.append({"id": f"N{len(rows) + 1:04d}", "folder": TOPIC[slug][0], "topic": slug, "topic_name": TOPIC[slug][1],
                     "intent": intent, "template_kind": kind, "question": q, "ask_topic_first": ask_first,
                     "expected_source": source_path(slug),
                     "also_accept": ";".join(f"{TOPIC[s][0]}/{s}.txt" for s in list(SIBLINGS.get(slug, [])) + list(accept_slugs)),
                     "result_ok": "", "notes": ""})

    for slug, folder, name, kind in std.TOPICS:
        for q in heading_questions(slug, DOCS / folder / f"{slug}.txt"):
            add(slug, "document heading", "heading", q, ask_first=f"Ono a {name}?")

    by_alias = {}
    for slug, names in aliases().items():
        if slug not in TOPIC:
            continue
        for a in names:
            by_alias.setdefault(a.lower(), []).append(slug)
    for alias, slugs in sorted(by_alias.items()):
        slug = slugs[0]
        if alias == TOPIC[slug][1].lower():
            continue
        for intent, tpl in NAME_PATTERNS:
            add(slug, intent, "resident name", tpl.format(x=alias), accept_slugs=slugs[1:])
    return rows


# ------------------------------------------------------------------ 3. per type: unrelated, serious/edge, terms
UNRELATED_CONTEXT = {
    "time": "No AI in this type: a 'no information' reply is right. The real time cannot be answered here.",
    "day": "No AI in this type: a 'no information' reply is right.",
    "date": "No AI in this type: a 'no information' reply is right.",
}
CONTEXT_PASS = "Reply is the 'no information' message, with NO health document and NO AI tag"


def unrelated_rows(kind):
    rows = []
    for i, (c, m) in enumerate(std.UNRELATED, 1):
        lang = "Bikol-Iriga" if not re.match(r"^(What|hello)", m, re.I) else "English words"
        if kind == "context":
            rows.append({"id": f"U{i:03d}", "category": c, "message": m, "language": lang,
                         "expected_route": "RAG only (no AI model)", "expected_tag": "(none)",
                         "pass_if": UNRELATED_CONTEXT.get(c, CONTEXT_PASS), "result_ok": "", "notes": ""})
        else:
            real = {"time": "Reply gives the REAL time now (Philippine time).", "day": "Reply gives today's real day name.",
                    "date": "Reply gives today's real date."}
            rows.append({"id": f"U{i:03d}", "category": c, "message": m, "language": lang,
                         "expected_route": "Gemma (Cloud AI)", "expected_tag": "Answered by: Cloud AI (Gemma)",
                         "pass_if": real.get(c, "Natural Bikol-Iriga reply from Gemma, Iriga words (no Naga-Bikol word from the term list), "
                                                "no medical advice, may lead back to health information"),
                         "result_ok": "", "notes": ""})
    return rows


def edge_rows(kind):
    rows = []
    for i, (g, m, route, note) in enumerate(std.EDGE, 1):
        if kind == "context" and route.startswith("chat model"):
            route, note = "RAG or 'no information'", "No AI in this type; must not crash or show an error page"
        if kind == "cloud":
            route = route.replace("chat model", "Gemma").replace("trained Gemma", "Gemma")
            if g == "health word in chat":
                note = "Must come from the verified documents (RAG) or Gemma's fixed health redirect; never health advice written by Gemma"
        rows.append({"id": f"E{i:03d}", "group": g, "message": m, "expected_route": route, "pass_if": note,
                     "result_ok": "", "notes": ""})
    return rows


def terms_rows():
    data = json.loads((ROOT / "resources" / "ml" / "bikol_iriga_terms.json").read_text(encoding="utf-8"))
    rows = []
    for central, iriga in sorted((data.get("words") or {}).items()):
        rows.append({"id": f"T{len(rows) + 1:03d}", "kind": "word swap (applied to Gemma's reply)", "central_or_tagalog_word": central,
                     "expected_iriga_word": iriga, "result_ok": "", "notes": ""})
    for pair in data.get("glossary") or []:
        rows.append({"id": f"T{len(rows) + 1:03d}", "kind": "glossary (given to Gemma in its instructions)",
                     "central_or_tagalog_word": pair[0], "expected_iriga_word": pair[1], "result_ok": "", "notes": ""})
    return rows


READ_ME = {
    "context": """# Context-based: test questions (Bikol-Iriga)

**What this type is:** verified health documents (RAG) only. No AI model is ever called.

**What to expect**
- Every health question is answered from the topic's own document; every line of the reply is text of that document.
- There is no "Answered by" AI tag.
- A message that is not about health gets the "no information" reply, never a health document and never an AI reply.
- A serious message always gets the team's fixed, checked reply.

**Files**
| File | Questions |
|---|---|
| `../shared/health_full_bank.csv` | every reviewed pattern x every topic: all possible health questions |
| `../shared/health_headings_and_names.csv` | questions written as headings in the documents (ask the topic first) + the names residents use |
| `../local_ai/health_by_topic.csv` | the team's 719 core questions (a subset of the full bank) |
| `unrelated_no_ai.csv` | everyday messages: no AI reply, no health document |
| `serious_and_edge_cases.csv` | serious messages and tricky mixes |

`result_ok`: `yes` right answer from the right document; `nodoc` the document has no such section (a "no information" reply is correct);
`wrong` another document answered (serious); `bikol` wording problem. All wording is DRAFT until a Bikol-Iriga speaker checks it.
""",
    "cloud": """# Cloud AI (Gemma): test questions (Bikol-Iriga)

**What this type is:** the same RAG for health information, with Gemma (cloud) as the model. Gemma answers the everyday
(unrelated) messages as natural conversation that still leads back to health information, and re-tells the verified health
answer in friendly Bikol. The team's Iriga words replace pure Naga Bikol; every other word stays as Gemma wrote it.

**What to expect**
- Health questions: the answer is built from the verified document (tag "Cloud AI (Gemma) + verified health info"). Gemma may not add
  numbers. If Gemma says the document does not answer the question, the message becomes normal chat.
- Unrelated messages: tag "Cloud AI (Gemma)", natural Bikol-Iriga, no medical advice. A health question Gemma cannot answer from a
  document gets the team's fixed redirect ("Fixed checked reply").
- Iriga words: none of the Naga-Bikol words in `iriga_terms_check.csv` is left in a reply.
- A serious message always gets the team's fixed, checked reply and no model is called.
- If Gemma cannot be reached the resident is told so; there is no other answer.

**Files**
| File | Questions |
|---|---|
| `../shared/health_full_bank.csv` | every reviewed pattern x every topic (Gemma is run on a spread sample of it; Context-based and Local AI run all) |
| `../shared/health_headings_and_names.csv` | headings of the documents + the names residents use |
| `unrelated_by_topic.csv` | everyday messages for Gemma |
| `serious_and_edge_cases.csv` | serious messages and tricky mixes |
| `iriga_terms_check.csv` | every Naga/Tagalog word that must become an Iriga word |

All wording is DRAFT until a Bikol-Iriga speaker checks it.
""",
}

SHARED_README = """# Shared health question bank (Bikol-Iriga)

The same health questions are used for the three chatbot types (Context-based, Local AI model, Cloud AI).
The type decides HOW they are answered, not WHICH questions are asked.

| Column | Meaning |
|---|---|
| `intent` | the kind of question: definition, symptoms, causes, management, diagnosis, benefits, effects, schedule, warning, general |
| `question` | the wording (a reviewed pattern filled with the topic's Bikol name) |
| `ask_topic_first` | when set, ask this first so the topic is known, then the question (headings such as "Ono ana mga sinyales?") |
| `expected_source` / `also_accept` | the document that must answer (and sibling documents that may also answer) |

A document does not have a section for every question type. A "no information" reply is then correct; a made-up answer or an
answer from another document is not. Rebuild with `python ml/bikol-chat/make_test_questions_three_types.py`.
"""


def main():
    bank = full_bank()
    extras = headings_and_names()
    write_csv(OUT / "shared" / "health_full_bank.csv", bank, BANK_COLS)
    write_csv(OUT / "shared" / "health_headings_and_names.csv", extras, BANK_COLS)
    (OUT / "shared" / "README.md").write_text(SHARED_README, encoding="utf-8")

    u_cols = ["id", "category", "message", "language", "expected_route", "expected_tag", "pass_if", "result_ok", "notes"]
    e_cols = ["id", "group", "message", "expected_route", "pass_if", "result_ok", "notes"]
    for kind in ("context", "cloud"):
        d = OUT / kind
        d.mkdir(parents=True, exist_ok=True)
        (d / "README.md").write_text(READ_ME[kind], encoding="utf-8")
        write_csv(d / ("unrelated_no_ai.csv" if kind == "context" else "unrelated_by_topic.csv"), unrelated_rows(kind), u_cols)
        write_csv(d / "serious_and_edge_cases.csv", edge_rows(kind), e_cols)
    terms = terms_rows()
    write_csv(OUT / "cloud" / "iriga_terms_check.csv", terms,
              ["id", "kind", "central_or_tagalog_word", "expected_iriga_word", "result_ok", "notes"])

    by_intent = {}
    for r in bank:
        by_intent[r["intent"]] = by_intent.get(r["intent"], 0) + 1
    print(f"full bank {len(bank)} questions over {len(std.TOPICS)} topics: {by_intent}")
    print(f"headings + names {len(extras)} (headings {sum(1 for r in extras if r['template_kind'] == 'heading')}, "
          f"names {sum(1 for r in extras if r['template_kind'] == 'resident name')})")
    print(f"unrelated {len(unrelated_rows('cloud'))}, serious/edge {len(edge_rows('cloud'))}, iriga terms {len(terms)} -> {OUT}")


if __name__ == "__main__":
    main()
