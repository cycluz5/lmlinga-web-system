"""Turn WikiNER-Zero Central Bikol (bcl) TSV files into plain sentences.

Stage 1 of the Bikol chat model only needs Bikol text, so the NER tags are
dropped. Short, list-like and duplicate sentences are removed.

Usage:
    python prepare_wikiner.py <folder with train.tsv/dev.tsv/test.tsv> <output.txt>

Data: WikiNER-Zero (Kaggle, accidentalbrain/wikiner-zero), CC BY-SA 4.0,
derived from Wikipedia and Wikidata.
"""
import re
import sys
from pathlib import Path

NO_SPACE_BEFORE = {".", ",", "!", "?", ":", ";", ")", "]", "%", "'s"}
NO_SPACE_AFTER = {"(", "["}


def read_sentences(path):
    tokens = []
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.rstrip("\n")
            if line.startswith("#"):
                continue
            if not line.strip():
                if tokens:
                    yield tokens
                    tokens = []
                continue
            tokens.append(line.split("\t")[0])
    if tokens:
        yield tokens


def join_tokens(tokens):
    out = ""
    for tok in tokens:
        if not out or tok in NO_SPACE_BEFORE or out[-1] in NO_SPACE_AFTER:
            out += tok
        else:
            out += " " + tok
    return out.strip()


def looks_like_sentence(text, tokens):
    if len(tokens) < 5 or len(tokens) > 80:
        return False
    if text[-1] not in ".!?":
        return False
    words = [t for t in tokens if re.match(r"^[A-Za-zÀ-ÿ'-]+$", t)]
    if len(words) < 0.6 * len(tokens):  # mostly numbers / symbols
        return False
    if text.count(",") > len(tokens) / 3:  # lists of names
        return False
    lower = sum(1 for w in words if w[0].islower())
    return lower >= 0.4 * len(words)  # not just a row of names


def main():
    src, dst = Path(sys.argv[1]), Path(sys.argv[2])
    seen, kept, total = set(), [], 0
    for name in ("train.tsv", "dev.tsv", "test.tsv"):
        for tokens in read_sentences(src / name):
            total += 1
            text = join_tokens(tokens)
            if text in seen or not looks_like_sentence(text, tokens):
                continue
            seen.add(text)
            kept.append(text)
    dst.write_text("\n".join(kept) + "\n", encoding="utf-8")
    words = sum(len(s.split()) for s in kept)
    print(f"read {total} sentences, kept {len(kept)} ({words} words) -> {dst}")


if __name__ == "__main__":
    main()
