"""Build the Bikol-Iriga intent dataset (question,intent) from question templates.

Words come from vocabulary_guide.csv (Bible word finder + speaker). Topics match
the documents in resources/health_docs/bcl. Output goes to ml/bikol-intent/dataset/.

    python build_dataset.py

Every question is a DRAFT until a Bikol-Iriga speaker marks it ok in the review sheet.
"""
import csv
import random
import re
from pathlib import Path

random.seed(42)
HERE = Path(__file__).parent
OUT = HERE / "dataset"
TARGET_PER_INTENT = 80
TEST_SHARE = 0.2

# Illnesses / conditions (Bikol-Iriga name as residents say it)
ILLNESS = [
    "dengue", "trangkaso", "hika", "altapresyon", "high blood", "rayuma", "gout", "pulmonya",
    "pneumonia", "malnutrisyon", "tetano", "kolera", "tuberkulosis", "TB", "hepatitis", "HIV",
    "malaria", "rabies", "epilepsy", "stroke", "diabetes", "kanser sa soso", "kanser sa baga",
    "kanser sa atay", "atake sa poso", "ilang sa bato", "pagkabongog", "dementia", "autism",
    "kalintora", "obo", "sip'on", "olod", "kolog sa likod", "kakulangan sa micronutrient",
]
# Programs / good habits (things you get or do)
PROGRAM = [
    "bakuna", "pagbabakuna", "pagpupurga", "newborn screening", "newborn hearing screening",
    "pagpapasoso", "family planning", "pills", "bitamina", "pagtao sa rogo", "pag-ogas ka kamut",
    "Pinggang Pinoy", "pagpapakusug sa awak", "check-up ka mabrus", "maray na pagkaun",
]
# Things you go and get ("magpa-...")
GET = ["bakuna", "newborn screening", "hearing screening", "check-up", "purga", "bakuna kontra tetano",
       "test sa HIV", "test sa TB", "family planning", "pills"]
# Life stages / other definition-only topics
OTHER = ["menopause", "puberty", "amay na pangingigin", "preterm birth", "gender identity", "paninigarilyo"]
WHO = ["a igin ko", "a igin", "ako", "a agom ko", "a mabrus", "a gorang", "a talaginun"]

T = {
    "definition": [
        ("Ono a {i}?", "I"), ("Ono tabi a {i}?", "I"), ("Ono raw a {i}?", "I"), ("Ono man a {i}?", "I"),
        ("Ono a buut sabiyun ka {i}?", "I"), ("Ono a kahulugan ka {i}?", "I"), ("Ono a {p}?", "P"),
        ("Ono tabi a {p}?", "P"), ("Ono raw a {p}?", "P"), ("Ono a {o}?", "O"), ("Ono tabi a {o}?", "O"),
        ("Ipaliwanag mo tabi kin ono a {i}", "I"), ("Ono a buut sabiyun ka {p}?", "P"),
        ("Ono man a {o}, tabi?", "O"), ("Ono a {a}?", "A"), ("Ono man a {a}?", "A"), ("Ono tabi a {a}?", "A"),
        ("Ono a {a}, tabi?", "A"), ("Ono a {a} sa igin?", "A"), ("Ono raw a {a}?", "A"),
    ],
    "symptoms": [
        ("Ono a sintomas ka {i}?", "I"), ("Ono a mga sinyales ka {i}?", "I"),
        ("Ono a mga senyales ka {i}?", "I"), ("Ono a tanda kin agko {i}?", "I"),
        ("Ono a namamatian ka agko {i}?", "I"), ("Ono a sintomas kin agko {i} {w}?", "I"),
        ("Ono a sinyales ka {o}?", "O"), ("Ono tabi a sintomas ka {i}?", "I"),
        ("Ono a mga tanda ka {i} sa igin?", "I"), ("Ono raw a namamatian ka nagkaka-{i}?", "I"),
    ],
    "causes": [
        ("Ono a dahilan ka {i}?", "I"), ("Ono a rason ka {i}?", "I"), ("Ono a kwasa ka {i}?", "I"),
        ("Ngata nagkakaagko {i}?", "I"), ("Ngata nagka-{i} {w}?", "I"), ("Paono nakokoko a {i}?", "I"),
        ("Ono raw a dahilan ka {i}?", "I"), ("Ngata tabi nagkaka-{i} a tawo?", "I"),
        ("Ono tabi a dahilan ka {o}?", "O"), ("Sari raw nakokoko a {i}?", "I"),
        ("Ngata nagkakaagko {o}?", "O"), ("Ono tabi a kwasa ka {i}?", "I"),
    ],
    "management": [
        ("Paono malikayan a {i}?", "I"), ("Paono tabi malikayan a {i}?", "I"), ("Ono a bolong sa {i}?", "I"),
        ("Agko bolong sa {i}?", "I"), ("Ono a kaipowan kong gibowon kin agko {i} {w}?", "I"),
        ("Paono ataman a agko {i}?", "I"), ("Paono maparay a {i}?", "I"),
        ("Ono a maray na gibowon tanganing diri magka-{i}?", "I"), ("Ono a paglikay sa {i}?", "I"),
        ("Paono ko asikasowon {w} na agko {i}?", "I"), ("Ono tabi a bolong sa {i} ka igin?", "I"),
    ],
    "diagnosis": [
        ("Paono ko masisigoro kin agko ako {i}?", "I"), ("Paono maisiyan kin agko {i}?", "I"),
        ("Agko test para sa {i}?", "I"), ("Paono sinusuri a {i}?", "I"),
        ("Sari pwedeng magpa-test para sa {i}?", "I"), ("Paono maiisiyan kin agko {i} {w}?", "I"),
        ("Ono a test para maisiyan a {i}?", "I"), ("Kaipowan pa magpa-check-up para maisiyan a {i}?", "I"),
        ("Sari pwedeng magpa-{g}?", "G"),
    ],
    "benefits": [
        ("Ono a benepisyo ka {p}?", "P"), ("Ono a pakinabang ka {p}?", "P"), ("Ngata maray a {p}?", "P"),
        ("Ngata kaipowan a {p}?", "P"), ("Ono a maray na naitatabang ka {p}?", "P"),
        ("Ono tabi a benepisyo ka {p}?", "P"), ("Ono a maray sa igin ka {p}?", "P"),
        ("Ngata importante a {p}?", "P"), ("Ono tabi a pakinabang ka {p} sa awak?", "P"),
    ],
    "effects": [
        ("Ono a epekto ka {i} sa awak?", "I"), ("Ono a mingyari kin diri mabolong a {i}?", "I"),
        ("Ono raw a mingyari kin pabayaan a {i}?", "I"), ("Agko side effect a {p}?", "P"),
        ("Ono a epekto ka {p}?", "P"), ("Ono a komplikasyon ka {i}?", "I"),
        ("Ono a mingyari sa igin kin diri magpa-{g}?", "G"), ("Ono a epekto ka paninigarilyo sa {x}?", "X"),
        ("Ono a mingyari kin magka-{i} a mabrus?", "I"),
    ],
    "schedule": [
        ("Kono dapat a {s}?", "S"), ("Onong edad pwede a {s}?", "S"), ("Pirang beses sa taon a {s}?", "S"),
        ("Kono a iskedyul ka {s}?", "S"), ("Sa pirang bulan na edad a {s}?", "S"), ("Kono a sunod na {s}?", "S"),
        ("Pirang beses kaipowan a {s}?", "S"), ("Kono tabi pwede a {s} ka talaginun?", "S"),
        ("Onong aldow a {s} sa health center?", "S"),
    ],
    "warning": [
        ("Kono kaipowan omiyan sa ospital kin agko {i}?", "I"), ("Ono a delikadong sinyales ka {i}?", "I"),
        ("Delikado na raw kin {d}?", "D"), ("{D}, ono a gibowon ko?", "D"), ("{D}, kaipowan na magpabayad sa doktor?", "D"),
        ("{D}, dapat na omiyan sa ospital?", "D"), ("Kaipowan na tabi magpakonsulta kin {d}?", "D"),
        ("Maispid na tabang tabi, {d}", "D"), ("{D}, ono a dapat kong gibowon?", "D"),
        ("{D}, ono tabi a gibowon ko?", "D"), ("{D}. Delikado na?", "D"),
    ],
    "general": [
        ("Siisay a pwedeng magka-{i}?", "I"), ("Sari makukua a libreng {p} sa barangay?", "P"),
        ("Libre raw a {p}?", "P"), ("Naooyon raw a mabrus na magpa-{g}?", "G"), ("Agko {p} sa health center?", "P"),
        ("Pira a bayad sa {p}?", "P"), ("Siisay a pwedeng magpa-{g}?", "G"), ("Naooyon raw a igin sa {p}?", "P"),
        ("Siisay a dapat kong ipabayad kin agko {i}?", "I"), ("Sari tabi a health center sa barangay?", "-"),
        ("Siisay a pwedeng magka-{a}?", "A"), ("Agko libreng tabang para sa agko {i}?", "I"),
        ("Agko pa ibang impormasyon manungud sa {a}?", "A"),
    ],
}
# Schedule topics and danger situations
SCHED = ["bakuna", "pagbakuna ka igin", "pagpupurga", "newborn screening", "newborn hearing screening",
         "bitamina", "check-up ka mabrus", "bakuna kontra tetano", "bakuna sa trangkaso", "pills"]
DANGER = [
    "mataas a kalintora ka igin ko", "nagkokombolsyon a igin ko", "kinagat sa ayam a igin ko",
    "sakit sa pag-inga a igin ko", "rinorogo a mabrus", "kinagat sa sawa a agom ko",
    "diri nagkaun a igin ko sa tolong aldow", "nangubugkubug a gorang",
    "isinoka a igin ko sagkud maluya", "mahihimatay a agom ko", "makulug saigo a payo ko sagkud agko pagkalibong",
    "maluya a awak ka igin ko sagkud mainit", "kinagat sa ayam ako", "mapolang mata ka igin ko sagkud mainit",
    "makulug a ruruggan ko", "agko rogo sa obo ko", "nakainom sa ilo a igin",
]
EFFECT_X = ["awak", "mabrus", "igin", "baga", "poso"]

# Spelling variants residents use. Standard is "ono" (never uno); "ono a" main, "ono an" also used.
SWAPS = [(" igin", " igen"),
         (" tabi", ""), ("sintomas", "sintomas"), ("sinyales", "senyales")]


def fill(tpl, kind):
    pick = {
        "I": lambda: {"i": random.choice(ILLNESS)},
        "P": lambda: {"p": random.choice(PROGRAM)},
        "G": lambda: {"g": random.choice(GET)},
        "A": lambda: {"a": random.choice(ILLNESS + PROGRAM + OTHER)},
        "O": lambda: {"o": random.choice(OTHER)},
        "S": lambda: {"s": random.choice(SCHED)},
        "D": lambda: (lambda d: {"d": d, "D": d[0].upper() + d[1:]})(random.choice(DANGER)),
        "X": lambda: {"x": random.choice(EFFECT_X)},
        "-": lambda: {},
    }[kind]()
    pick.setdefault("w", random.choice(WHO))
    q = tpl.format(**pick)
    topic = pick.get("a") or pick.get("i") or pick.get("p") or pick.get("o") or pick.get("g") or pick.get("s") or pick.get("d") or pick.get("x") or ""
    return q, topic


def vary(q):
    """Make it look like a real typed message: spelling swaps, lowercase, no '?'."""
    # "ono a" is the main form; "ono an" is also used
    if random.random() < 0.3:
        q = re.sub(r"([Oo]no(?: tabi| raw| man)?) a ", r" an ", q, count=1)
    for a, b in SWAPS:
        if a in q and random.random() < 0.25:
            q = q.replace(a, b, 1)
    r = random.random()
    if r < 0.25:
        q = q.lower()
    if random.random() < 0.2:
        q = q.rstrip("?")
    return q


def main():
    rows, seen = [], set()
    for intent, tpls in T.items():
        made, tries = 0, 0
        while made < TARGET_PER_INTENT and tries < 5000:
            tries += 1
            tpl, kind = random.choice(tpls)
            q, topic = fill(tpl, kind)
            if tries > len(tpls):  # keep the clean version of each template at least once
                q = vary(q)
            key = q.lower().rstrip("?").strip()
            if key in seen:
                continue
            seen.add(key)
            rows.append({"question": q, "intent": intent, "topic": topic, "template": tpl})
            made += 1

    # one plain "what is X?" for EVERY topic, so a topic name never points to just one intent
    for t in ILLNESS + PROGRAM + OTHER + GET:
        for tpl in ("Ono a {}?", "Ono an {}?"):
            q = tpl.format(t)
            if q.lower().rstrip("?") not in seen:
                seen.add(q.lower().rstrip("?"))
                rows.append({"question": q, "intent": "definition", "topic": t, "template": tpl})

    random.shuffle(rows)
    OUT.mkdir(exist_ok=True)
    # stratified split
    train, test = [], []
    for intent in T:
        group = [r for r in rows if r["intent"] == intent]
        n_test = round(len(group) * TEST_SHARE)
        test += group[:n_test]
        train += group[n_test:]

    def write(name, data, cols):
        with open(OUT / name, "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=cols, extrasaction="ignore")
            w.writeheader()
            w.writerows(data)

    write("bikol_intent_all.csv", rows, ["question", "intent", "topic", "template"])
    write("train.csv", train, ["question", "intent"])
    write("test.csv", test, ["question", "intent"])
    print(f"{len(rows)} questions -> train {len(train)}, test {len(test)}")
    for intent in T:
        print(f"  {intent:11s} {sum(r['intent'] == intent for r in rows)}")


if __name__ == "__main__":
    main()
