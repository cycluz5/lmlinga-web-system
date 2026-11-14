"""Stage 1 data for the Bikol-Iriga chat LLM: the verified Iriga text.

Reads resources/health_docs_v2 (the linguist-verified datasets, already corrected to team spelling) and writes

    dataset/iriga_text.csv          column text    - verified Bikol-Iriga text in chunks (teaches Iriga words/grammar)
    dataset/translation_pairs.csv   en/tl -> bcl   - only for topics where the paragraphs line up 1:1

The model reads this in Stage 1 only to get used to Iriga. Health FACTS still come from RAG at answer time.

    python build_language_data.py
"""
import csv
import re
from pathlib import Path

HERE = Path(__file__).parent
DOCS = HERE.parent.parent / "resources" / "health_docs_v2"
OUT = HERE / "dataset"
CHUNK_CHARS = 700


def paragraphs(path):
    text = path.read_text(encoding="utf-8")
    return [re.sub(r"[ \t]+", " ", p).strip() for p in re.split(r"\n\s*\n", text) if p.strip()]


def chunk(paras):
    """Join short paragraphs so one training line is ~CHUNK_CHARS long."""
    out, cur = [], ""
    for p in paras:
        if cur and len(cur) + len(p) > CHUNK_CHARS:
            out.append(cur)
            cur = ""
        cur = f"{cur}\n{p}".strip()
    if cur:
        out.append(cur)
    return out


def main():
    OUT.mkdir(exist_ok=True)
    bcl_files = sorted((DOCS / "bcl").rglob("*.txt"))
    texts, pairs, skipped = [], [], []
    for f in bcl_files:
        rel = f.relative_to(DOCS / "bcl")
        bcl = paragraphs(f)
        texts.extend(chunk(bcl))
        for lang in ("en", "tl"):
            other = DOCS / lang / rel
            if not other.exists():
                continue
            src = paragraphs(other)
            ratio = sum(map(len, bcl)) / max(1, sum(map(len, src)))
            if len(src) == len(bcl) and 0.5 <= ratio <= 2.5:
                pairs += [(lang, rel.stem, s, b) for s, b in zip(src, bcl) if len(s) > 25 and len(b) > 25]
            else:
                skipped.append((lang, str(rel)))

    with open(OUT / "iriga_text.csv", "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["text"])
        w.writerows([[t] for t in texts])
    with open(OUT / "translation_pairs.csv", "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["language", "topic", "source", "bikol_iriga"])
        w.writerows(pairs)

    words = sum(len(t.split()) for t in texts)
    print(f"{len(bcl_files)} Bikol files -> {len(texts)} chunks, {words} words  (dataset/iriga_text.csv)")
    print(f"{len(pairs)} aligned pairs  (dataset/translation_pairs.csv); "
          f"{len(skipped)} file/language combos skipped because paragraphs do not line up")


if __name__ == "__main__":
    main()
