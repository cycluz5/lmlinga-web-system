"""Turn the linguist-verified Word/PDF files into the chatbot's .txt files.

Input  : resources/health_docs/newVerifiedDataset/*.docx and *.pdf
         (each topic = English part, Tagalog part, Bikol-Iriga part)
Output : resources/health_docs_v2 (default) -> <out>/en/<category>/<topic>.txt, <out>/tl/..., <out>/bcl/...
         <out>/_conversion_report.csv  (one row per topic, for checking)

The text is copied as-is. Nothing is translated or re-spelled.

Run:
  python ml/health-docs/convert_verified_docs.py <out_dir>
(pdfplumber must be installed for the PDF: pip install pdfplumber)
"""
import csv
import html
import re
import sys
import zipfile
from collections import Counter
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
SRC = REPO / "resources/health_docs/newVerifiedDataset"
LANGS = ("en", "tl", "bcl")

# ---------------------------------------------------------------- topics ---
# slug, category, words that identify the topic in its English title
TOPICS = [
    ("autism", "child_care", ["autism"]),
    ("baby_is_a_champion", "child_care", ["champion"]),
    ("newborn_hearing_screening", "child_care", ["hearing screening", "hear you"]),
    ("breastfeeding_tips", "child_care", ["nursing a pleasant"]),
    ("first_1000_days_nutrition", "child_care", ["1,000 days", "1000 days"]),
    ("puberty_teen_boy", "child_care", ["teen boy"]),
    ("malnutrition", "child_care", ["malnutrition"]),
    ("deworming", "child_care", ["deworming"]),
    ("save_your_baby_mental_retardation", "child_care", ["mental retardation"]),
    ("micronutrient_deficiency", "child_care", ["micronutrient"]),
    ("chickenpox", "child_care", ["chickenpox"]),
    ("pneumonia", "child_care", ["pneumonia"]),
    ("one_child_is_enough", "family_planning", ["one child"]),
    ("family_planning_faq", "family_planning", ["frequently asked questions about modern"]),
    ("family_planning_facts", "family_planning", ["facts about family planning"]),
    ("menopause", "maternal_care", ["menopause"]),
    ("pre_term_birth", "maternal_care", ["pre term birth", "preterm", "pre-term"]),
    ("tetanus", "maternal_care", ["tetanus"]),
    ("healthy_teeth", "other", ["healthy teeth"]),
    ("toothache", "other", ["toothache"]),
    ("high_blood_pressure", "other", ["about high blood pressure"]),
    ("hypertension", "other", ["hypertension"]),
    ("low_blood_pressure", "other", ["low blood", "low  blood"]),
    ("asthma", "other", ["asthma"]),
    ("gout", "other", ["gout"]),
    ("kidney_cancer", "other", ["kidney cancer"]),
    ("liver_cancer", "other", ["liver cancer"]),
    ("lung_cancer", "other", ["lung cancer"]),
    ("breast_cancer", "other", ["breast cancer"]),
    ("chronic_kidney_disease", "other", ["chronic kidney"]),
    ("blood_donation", "other", ["blood donation"]),
    ("cholera", "other", ["cholera"]),
    ("handwashing", "other", ["handwashing", "clean hands"]),
    ("deafness", "other", ["deafness"]),
    ("dementia", "other", ["dementia"]),
    ("diabetes", "other", ["diabetes"]),
    ("gender_identity", "other", ["gender identity"]),
    ("tuberculosis_proper_coughing", "other", ["tuberculosis"]),
    ("heart_attack", "other", ["heart attack"]),
    ("hepatitis", "other", ["hepatitis"]),
    ("hiv_and_aids", "other", ["hiv"]),
    ("dengue", "other", ["dengue"]),
    ("influenza_rainy_season", "other", ["rainy season"]),
    ("influenza", "other", ["influenza"]),
    ("low_back_pain", "other", ["back pain"]),
    ("malaria", "other", ["malaria"]),
    ("pinggang_pinoy", "other", ["plate of pinoy", "pinggang pinoy"]),
    ("rabies", "other", ["rabies"]),
    ("seven_healthy_habits", "other", ["seven healthy habits", "7 healthy habits"]),
    ("ten_kumainments", "other", ["kumainments"]),
    ("epilepsy", "other", ["epilepsy"]),
    ("stroke", "other", ["stroke"]),
    ("smoking", "other", ["smoking"]),
    ("leptospirosis", "other", ["leptospirosis"]),
    ("headache", "other", ["headache"]),
    ("measles", "child_care", ["measles"]),
    ("stress", "other", ["stress"]),
    ("foodborne_illness", "other", ["foodborne"]),
    ("ovarian_cancer", "other", ["ovarian"]),
    ("allergic_rhinitis", "other", ["allergic rhinitis"]),
    ("common_cold", "other", ["cold (sipon)", "common cold"]),
    ("nausea_vomiting", "other", ["nausea", "vomiting"]),
    ("rashes", "other", ["rash"]),
    ("constipation", "other", ["constipation"]),
    ("cough", "other", ["cough"]),
    ("diarrhea", "other", ["diarrhea"]),
    ("fever", "other", ["fever"]),
    ("runny_nose", "other", ["runny nose", "rhinorrhea"]),
    ("sore_throat", "other", ["pharyngitis", "sore throat"]),
]


def topic_for(text):
    t = re.sub(r"\s+", " ", text.lower())
    for slug, cat, keys in TOPICS:
        if any(k in t for k in keys):
            return slug, cat
    return None, None


# -------------------------------------------------------- language guess ---
MARK = {
    "en": set("the and of to is are your you with for can be or that this it if from by when what how which have has should".split()),
    "tl": set("ang ng nang hindi maaaring iyong ito upang kanilang siya sila mula lamang ikaw natin ating tao magkaroon isang dapat ay mas po kapag saan paano bakit ano tungkol kaalaman ngayon alamin tara natin dalhin agad malayaakongmagingmasaya".split()),
    "bcl": set("ana kana ag agko diri boko uno ono adi kadi sadi ika kanimo kanimong sana tawo tawong igin usad sira sirang kanirang nganing uda dakol maray pala ka a kan aldow pirang mag kin manungod pauno ngata kaisihan maisihan ngunyan isihon likayan pirmi mayna".split()),
}
# words used by both Tagalog and Bikol texts: count half for each
TOK = re.compile(r"[a-zñ]+", re.I)


def scores(par):
    ws = [w.lower() for w in TOK.findall(par)]
    return {l: sum(w in MARK[l] for w in ws) for l in LANGS}


def split_three(pars):
    """Best 2 cut points so pars = EN | TL | BCL (in that order)."""
    sc = [scores(p) for p in pars]
    n = len(pars)
    # prefix sums of "cost" of labelling paragraph k with language l
    cost = {l: [0] * (n + 1) for l in LANGS}
    for l in LANGS:
        for k in range(n):
            tot = sum(sc[k].values())
            cost[l][k + 1] = cost[l][k] + (tot - sc[k][l])
    def total(i, j):
        return cost["en"][i] + (cost["tl"][j] - cost["tl"][i]) + (cost["bcl"][n] - cost["bcl"][j])

    best = min(total(i, j) for i in range(n + 1) for j in range(i, n + 1))
    pairs = [(i, j) for i in range(n + 1) for j in range(i, n + 1) if total(i, j) == best]

    def title_like(k):
        s = pars[k] if k < n else ""
        return bool(s) and not s.startswith("- ") and len(s) <= 120 and not s.rstrip().endswith((".", ",", ";"))

    # Lines with no language clues (titles, short list items) can go either
    # way. A new language section starts with its title, so cut at the last
    # first title-like line in the tied range.
    def pick(cands):
        titles = [k for k in cands if title_like(k)]
        return min(titles) if titles else min(cands)

    i = pick(sorted({p[0] for p in pairs}))
    j = pick(sorted({p[1] for p in pairs if p[0] == i}))
    return pars[:i], pars[i:j], pars[j:]


# ------------------------------------------------------------- readers ---
def docx_paragraphs(path):
    xml = zipfile.ZipFile(path).read("word/document.xml").decode("utf8")
    out = []
    for p in re.findall(r"<w:p[ >].*?</w:p>", xml, re.S):
        t = "".join(re.findall(r"<w:t[^>]*>([^<]*)</w:t>", p))
        t = html.unescape(t).replace(" ", " ").strip()
        if not t:
            continue
        if "<w:numPr>" in p and not t.startswith(("•", "-")):
            t = "- " + t
        t = re.sub(r"^[•●▪◦]\s*", "- ", t)
        out.append(t)
    return out


HEADER = re.compile(r"^(eng\w*sh|tagalog|bikol(-iriga)?)$", re.I)  # "Engish" typo in source


def docx_topics(path):
    """Yield (label, [paragraphs]) per topic. Each topic starts with the
    3 header lines English / Tagalog / Bikol-Iriga; the short line just
    before them is the topic label (drop it from the previous topic)."""
    pars = docx_paragraphs(path)
    starts = [k for k in range(len(pars) - 2)
              if HEADER.match(pars[k]) and HEADER.match(pars[k + 1]) and HEADER.match(pars[k + 2])]
    for n, s in enumerate(starts):
        end = starts[n + 1] - 1 if n + 1 < len(starts) else len(pars)
        label = pars[s - 1] if s > 0 else ""
        yield label, pars[s + 3:end]


PDF_HEAD = re.compile(r"^(english|tagalog|bikol+(-iriga)?)$", re.I)
BULLETS = "[" + "".join(map(chr, (0xF09E, 0xF0B7, 0xF0A7, 0x2022))) + "]"  # PDF bullet symbols


def pdf_topics(path):
    """The PDF is made of 3-column tables (English | Tagalog | Bikol).
    A table whose first row is the 'ENGLISH / TAGALOG / BIKOL' header starts a
    new topic; the caps line just above it (e.g. 'LEPTOSPIROSIS') is the label.
    Yields (label, {en: [...], tl: [...], bcl: [...]})."""
    import pdfplumber
    topics = []
    with pdfplumber.open(path) as pdf:
        for page in pdf.pages:
            prev_bottom = 0
            for t in sorted(page.find_tables(), key=lambda t: t.bbox[1]):
                rows = t.extract()
                above = page.crop((0, prev_bottom, page.width, t.bbox[1])).extract_text() or ""
                prev_bottom = t.bbox[3]
                if rows and all(PDF_HEAD.match((c or "").strip()) for c in rows[0][:3]):
                    label = next((x.strip() for x in reversed(above.splitlines())
                                  if x.strip() and not x.strip().isdigit()), "")
                    topics.append((label, {l: [] for l in LANGS}))
                    rows = rows[1:]
                if not topics:
                    continue
                for r in rows:
                    for l, cell in zip(LANGS, r):
                        # PDF bullet symbols -> "- " on a new line
                        cell = re.sub(r"\s*" + BULLETS + r"\s*", "\n- ", cell or "")
                        topics[-1][1][l].extend(cell.splitlines())
    yield from topics


# ------------------------------------------------------------- cleanup ---
def tidy(lines):
    """Join PDF line-wrapped sentences; blank line before headings."""
    text = []
    for s in lines:
        s = re.sub(r"\s+", " ", s).strip()
        if not s:
            continue
        text.append(s)
    out = []
    for s in text:
        is_head = (len(s) <= 80 and not re.search(r"[.!;,:]$", s) and not s.startswith("- ")
                   and (s.isupper() or s.endswith("?") or len(s.split()) <= 7))
        if is_head and out:
            out.append("")
        out.append(s)
    return "\n".join(out).strip() + "\n"


HEAD_SMALL_END = {"kana", "sa", "ana", "ng", "na", "mga", "manga", "o", "ag", "at", "kan", "ka",
                  "nin", "ang", "kin", "kung", "pag", "ay", "the", "of", "and", "to", "for",
                  "a", "an", "in", "with", "or", "is", "your"}
HEAD_START_SMALL = {"kana", "ng", "of", "and", "ag", "at", "na"}
HEAD_QUESTION_START = {"ono", "uno", "ano", "paono", "pauno", "paano", "siisay", "sino", "ngata",
                       "bakit", "sarin", "saan", "pira", "ilan", "what", "how", "who", "why",
                       "when", "where", "can", "does", "do", "is", "are"}


def is_caps(s):
    letters = re.sub(r"[^A-Za-zÑñ]", "", s)
    return len(letters) > 1 and letters.isupper()


def heading_continues(a, b):
    """The PDF wraps long headings: 'ONO ANA ABABANG PRESYON' + 'KANA ROGO?'."""
    if not (is_caps(a) and (is_caps(b) or b.startswith("("))) or re.search(r"[.?!:]$", a):
        return False
    if a.endswith("-") or b.startswith("("):
        return True
    words_a = re.findall(r"[A-Za-zÑñ]+", a.lower())
    words_b = re.findall(r"[A-Za-zÑñ]+", b.lower())
    if not words_a or not words_b or words_b[0] in HEAD_QUESTION_START:
        return False
    # "MAIN SUBJECT: LOW BLOOD" + "PRESSURE" (topic banner wrapped by the PDF)
    if re.match(r"^(main subject|pangunahi?n?g? paksa|pangunang simuno|pangunahong paksa)\s*:", a, re.I) \
            and not b.endswith("?"):
        return True
    return (words_a[-1] in HEAD_SMALL_END or words_b[0] in HEAD_START_SMALL
            or (words_a[0] in HEAD_QUESTION_START and b.endswith("?")))


def join_wrapped(lines):
    """PDF columns wrap sentences over many short lines; glue them back."""
    out = []
    for s in lines:
        s = s.strip()
        if not s:
            continue
        if out and heading_continues(out[-1], s):
            out[-1] = out[-1] + ("" if out[-1].endswith("-") else " ") + s
        elif out and s[:1].islower() and not re.search(r"[.!?:]$", out[-1]):
            # keep the hyphen: Bikol/Tagalog use real hyphens (pag-udo)
            out[-1] = out[-1] + ("" if out[-1].endswith("-") else " ") + s
        else:
            out.append(s)
    return out


# ------------------------------------------------------------ spelling ---
# Team spelling rules for Bikol-Iriga (decided 2026-11-12):
#   uno -> ono, pauno -> paono      (question word "what" / "how")
#   lagnat, kalintura -> kalintora  (fever)
#   a   -> ana                      (article)
#   ka  -> kana                     (of / linker)
#   diri / boko is NOT changed here: diri = "don't", boko = "no/not" depends on
#   meaning, so every diri line goes to _diri_boko_review.csv for the linguist.
WORD_SWAP = {"uno": "ono", "pauno": "paono",
             "lagnat": "kalintora", "kalintura": "kalintora"}  # fever (user, 2026-11-12)
# From the linguist's diri/boko review sheet (2026-11-13): marked on many lines,
# so applied everywhere in the Bikol text.
WORD_SWAP.update({
    "lanay": "kalintora", "kung": "kin", "ang": "ana", "tah": "ta", "ikay": "ika",
    "anom": "unom", "mauban": "maoban", "halangkaw": "alangkaw",
    "unong": "onong", "unuman": "onoman", "unumang": "onomang",
    "nagpapasuso": "nagpapasoso", "nagpapasusu": "nagpapasoso",
    "rumrumon": "tandaan", "rurumon": "tandaan",
    # user, 2026-11-13: "use osad and kaipowan"
    "usad": "osad", "kaipuhan": "kaipowan", "kaipuhang": "kaipowan", "kaipuhanan": "kaipowan",
    "kaipwanan": "kaipowan", "nangangaipuhan": "kaipowan", "kinakaipuhan": "kinakaipowan",
    "kinakaipuhang": "kinakaipowan", "kinakaipuwang": "kinakaipowan",
    # user, 2026-11-13: blood = "rogo" (not rugo); stroke/caress = "apulason"
    "rugo": "rogo", "rugong": "rogong", "pagrugo": "pagrogo", "magrugo": "magrogo",
    "pagrurugo": "pagrorogo", "nagrurugong": "nagrorogong", "hapluson": "apulason",
})

# Tagalog words in the Bikol text -> the Bikol-Iriga word from the team's
# Bible-based vocabulary (ml/bikol-intent/vocabulary_guide.csv). Only words
# whose Iriga word fits the sentence the same way. Not here on purpose (listed
# in _vocab_review.csv for the linguist): words that are ALSO Bikol with another
# meaning (labi = more, aso = smoke, buto = seeds), Iriga words that are a phrase
# or another word type (malusog -> uda ilang, pagsusuri -> magpabayad), topic
# names (gout, dementia, TB), and vocabulary rows marked CHECK.
VOCAB_SWAP = {
    "sintomas": "sinyales", "tubig": "tobig", "puso": "poso", "ubo": "obo",
    "sanggol": "igin", "gulay": "golay", "nanay": "ina", "iwasan": "likayan",
    "dugo": "rogo", "bukol": "bokol", "sanhi": "kwasa", "linggo": "dominggo",  # linguist
    "at": "ag",  # linguist: Tagalog "at" -> Iriga "ag" (kept inside English text)
    "isda": "isura", "lason": "ilo", "lang": "sana", "kinabukasan": "kinaudmaan",
    "buhok": "bok", "pulot": "tanggoli", "midwife": "komadrona",
    "benepisyo": "pakinabang", "paano": "paono", "ano": "ono", "katawan": "awak",
    "kaibigan": "amigo", "daw": "raw", "balisa": "maporisaw", "umaga": "ramrag",
    "ulo": "payo", "oo": "amo", "maghugas": "magramos", "kirot": "kulug",
    "isa": "osad", "hininga": "inangus", "hangin": "angin", "bibig": "nganga",
    "bulate": "olod", "busog": "mabosog", "almusal": "mamawuw", "masakit": "makulug",
}
VOCAB_REVIEW = {
    "madalas": "pirmi (always/often)", "manok": "isiwan (chicks)", "dumi": "udo",
    "baby": "igin", "kalungkutan": "namumundo", "lunas": "bolong",
    "pagbibinata": "pagsoltero", "pamamaga": "pagbaga / namamaga",
    "malusog": "uda ilang / mabosog", "ehersisyo": "pagpapakusug sa awak",
    "senyales": "magigin tanda", "palatandaan": "magigin tanda",
    "pagsusuri": "magpabayad", "uminom": "mag-inom", "emergency": "maispid na tabang",
    "gout": "rayuma", "dementia": "pagkalilipaton", "tb": "tuberkulosis",
    "labi": "oosipun (lips) - but Bikol labi = more", "aso": "ayam (dog) - but Bikol aso = smoke",
    "buto": "tolang (bones) - but buto = seeds here", "kombulsyon": "sobisobi",
    "gabi": "gab'i", "bata": "igin (CHECK)", "baboy": "baktin (CHECK)",
    "balat": "anit (CHECK)", "suso": "ruruggan (CHECK)", "pagsusuka": "isinoka (CHECK)",
    "tiyan": "boros (CHECK)", "check-up": "magpabayad",
}
NOT_ARTICLE_BEFORE = {"vitamin", "bitamina", "bitamin", "hepatitis", "type", "tipo",
                      "influenza", "plan", "letter", "letra", "blood"}
# "ka" followed by these is usually the pronoun "you", not the linker
KA_PRONOUN_NEXT = {"ba", "man", "na", "pa", "sana", "lang", "nang", "daw", "raw", "sa", "ngani"}
WORD = re.compile(r"[A-Za-zÑñ]+(?:-[A-Za-zÑñ]+)*")


def match_case(src, new):
    if src.isupper() and (len(src) > 1 or LINE_IS_CAPS):
        return new.upper()
    if src[:1].isupper():
        return new[:1].upper() + new[1:]
    return new


LINE_IS_CAPS = False
CHANGES = []  # every vocabulary swap, written to _vocab_changes.csv


def normalize_bcl(text, english_only, review, where):
    global LINE_IS_CAPS
    out_lines = []
    for line in text.split("\n"):
        letters = re.sub(r"[^A-Za-z]", "", line)
        LINE_IS_CAPS = bool(letters) and letters.isupper() and len(letters) > 3
        toks = list(WORD.finditer(line))
        new = []
        last = 0
        for k, m in enumerate(toks):
            w = m.group(0)
            lw = w.lower()
            prev = toks[k - 1].group(0).lower() if k else ""
            nxt = toks[k + 1].group(0).lower() if k + 1 < len(toks) else ""
            nxt2 = toks[k + 2].group(0).lower() if k + 2 < len(toks) else ""
            after = line[m.end():m.end() + 2]
            rep = None
            if lw in WORD_SWAP:
                rep = match_case(w, WORD_SWAP[lw])
            elif lw in VOCAB_SWAP:
                # not inside English words ("baby oil", "a midwife or nurse")
                if not (prev in english_only or nxt in english_only):
                    rep = match_case(w, VOCAB_SWAP[lw])
                    CHANGES.append([where, w, rep, line])
            elif lw in VOCAB_REVIEW:
                review.append([where, f"vocabulary: {w} -> {VOCAB_REVIEW[lw]}?", line])
            elif lw in ("a", "ka"):
                # a word or number follows ("ka 24 oras"), or "arog ka:"
                # (or end of line: the PDF wrapped the sentence there)
                word_follows = ((after[:1] == " " and after[1:2].isalnum()) or after[:1] == ":"
                                or after.strip() == "")
                # inside an English sentence ("access to a full range"); English
                # medical words after a Bikol word ("peligro ka lung cancer") still change
                english_sentence = prev in english_only and nxt in english_only
                if not word_follows or prev in NOT_ARTICLE_BEFORE or english_sentence:
                    pass  # list label "A -", "Vitamin A", or an English sentence: keep
                elif lw == "ka" and nxt in KA_PRONOUN_NEXT:
                    review.append([where, "ka (you?) kept", line])
                else:
                    rep = match_case(w, "ana" if lw == "a" else "kana")
            if lw == "diri":
                review.append([where, "diri or boko?", line])
            if rep is not None:
                new.append(line[last:m.start()] + rep)
                last = m.end()
        new.append(line[last:])
        out_lines.append("".join(new))
    return "\n".join(out_lines)


# --------------------------------------------------------- corrections ---
# The linguist's line-by-line fixes (diri -> boko, word fixes) from
# Desktop/datasets/diri_or_boko_REVIEW.xlsx, written as find -> replace on the
# converted Bikol text. A "find" that is no longer there is reported, not ignored.
CHICKENPOX = re.compile(r"\bbulutong ?[- ] ?(?:na )?t[uo]big?\b|\bbuluton-tubig\b", re.I)


def apply_corrections(out_dir):
    bcl = Path(out_dir) / "bcl"
    path = Path(__file__).resolve().parent / "bcl_corrections.csv"
    # Linguist: chickenpox in Iriga is "otos". The first mention keeps the
    # common name in brackets so people who type "bulutong" still find it.
    # (Runs first: the corrections below are written with "otos".)
    for p in bcl.rglob("*.txt"):
        text = p.read_text(encoding="utf-8")
        if not CHICKENPOX.search(text):
            continue
        def otos(m):
            w = m.group(0)
            return "OTOS" if w.isupper() else "Otos" if w[0].isupper() else "otos"
        first = CHICKENPOX.search(text)
        common = "(BULUTONG-TUBIG)" if first.group(0).isupper() else "(bulutong-tubig)"
        text = text[:first.start()] + f"{otos(first)} {common}" + CHICKENPOX.sub(otos, text[first.end():])
        p.write_text(text, encoding="utf-8", newline="\n")
    missing = 0
    with open(path, encoding="utf-8-sig", newline="") as f:
        for r in csv.DictReader(f):
            p = bcl / r["file"]
            text = p.read_text(encoding="utf-8") if p.exists() else ""
            if r["find"] not in text:
                missing += 1
                print(f"correction NOT FOUND (sheet row {r['sheet_row']}): {r['file']}: {r['find']!r}")
                continue
            p.write_text(text.replace(r["find"], r["replace"]), encoding="utf-8", newline="\n")
    print(f"corrections applied, {missing} not found")


# ---------------------------------------------------------------- main ---
def main(out_dir):
    out_dir = Path(out_dir)
    rows = []
    seen = Counter()
    topics = []
    review = []

    def save(slug, cat, parts, source, label):
        topics.append((slug, cat, parts, source, label))

    def write_all():
        en_words = Counter(w.lower() for t in topics for s in t[2]["en"] for w in WORD.findall(s))
        bcl_words = Counter(w.lower() for t in topics for s in t[2]["bcl"] for w in WORD.findall(s))
        tl_words = Counter(w.lower() for t in topics for s in t[2]["tl"] for w in WORD.findall(s))
        english_only = {w for w, n in en_words.items()
                        if n >= 2 and bcl_words[w] < n and tl_words[w] < n and len(w) > 1}
        for slug, cat, parts, source, label in topics:
            seen[slug] += 1
            if seen[slug] > 1:
                slug = f"{slug}_{seen[slug]}"
            words = {}
            for l in LANGS:
                p = out_dir / l / cat / f"{slug}.txt"
                p.parent.mkdir(parents=True, exist_ok=True)
                body = tidy(parts[l])
                if l == "bcl":
                    body = normalize_bcl(body, english_only, review, f"bcl/{cat}/{slug}.txt")
                p.write_text(body, encoding="utf-8", newline="\n")
                words[l] = len(body.split())
            first = {l: (parts[l][0] if parts[l] else "") for l in LANGS}
            rows.append([source, label, slug, cat, words["en"], words["tl"], words["bcl"],
                         first["en"][:60], first["tl"][:60], first["bcl"][:60]])

    for docx in sorted(SRC.glob("*.docx")):
        for label, pars in docx_topics(docx):
            en, tl, bcl = split_three(pars)
            slug, cat = topic_for((en[0] if en else "") + " " + label)
            if not slug:
                slug, cat = re.sub(r"[^a-z0-9]+", "_", label.lower()).strip("_") or "unknown", "other"
            save(slug, cat, {"en": en, "tl": tl, "bcl": bcl}, docx.name, label)

    for pdf in sorted(SRC.glob("*.pdf")):
        for label, parts in pdf_topics(pdf):
            parts = {l: join_wrapped(parts[l]) for l in LANGS}
            slug, cat = topic_for(label)
            if not slug:
                slug, cat = topic_for(" ".join(parts["en"][:2]))
            if not slug:
                slug, cat = re.sub(r"[^a-z0-9]+", "_", label.lower()).strip("_") or "unknown", "other"
            save(slug, cat, parts, pdf.name, label)

    write_all()
    apply_corrections(out_dir)

    # Hand-fixed files replace the converted ones (e.g. en/other/diarrhea_2.txt:
    # the PDF's English column for that topic is in Tagalog).
    overrides = Path(__file__).resolve().parent / "overrides"
    for f in overrides.rglob("*.txt"):
        target = out_dir / f.relative_to(overrides)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(f.read_text(encoding="utf-8"), encoding="utf-8", newline="\n")
        print("override:", f.relative_to(overrides))
    with open(out_dir / "_vocab_changes.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["file", "was", "now", "line (before the change)"])
        w.writerows(CHANGES)
    with open(out_dir / "_diri_boko_review.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["file", "check", "line", "answer (diri / boko / ok)"])
        w.writerows(review)

    with open(out_dir / "_conversion_report.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["source", "label", "file", "category", "en_words", "tl_words", "bcl_words",
                    "en_starts", "tl_starts", "bcl_starts"])
        w.writerows(rows)
    print(f"{len(rows)} topics -> {out_dir}")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else REPO / "resources/health_docs_v2")
