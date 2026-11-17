"""Write colab/train_bikol_chat.ipynb (Google Colab notebook, version 2) for the Rinconada chat model.

    python make_notebook.py

Version 4 (2026-11-15): free-data plan, no linguist. More ways to say each reviewed chat (augment_chat.py),
extra dialogs that passed the vocabulary check (filter_generated.py), and the test reports how many reply words
are outside the team's own vocabulary (allowed_words.csv) or on the blocked list (blocked_words.csv).
Version 3 (2026-11-14): + Stage 1 on the verified Iriga health text (iriga_text.csv, translation_pairs.csv),
+ "situational" chats where the app sends the real time/date in a context line
([Oras: ... | Aldow: ... | Petsa: ...]), + the test checks that time answers match the context.
Version 2 (2026-11-12): Gemma 3 4B (4-bit LoRA), Stage 1 on Rinconada sentences (downloaded in Colab
from Hugging Face, spelling changed to the team's), Stage 2 on checked chats + checked conversations.
Version 1 (Gemma 3 1B + Central Bikol) scored 6/63 same reply -> replaced.

Also copies the notebook + the 2 data files into Desktop/datasets/colab_upload_chat.
"""
import json
import shutil
from pathlib import Path

HERE = Path(__file__).parent
UPLOAD = Path.home() / "Desktop" / "datasets" / "colab_upload_chat"

CELLS = []


def md(text):
    CELLS.append({"cell_type": "markdown", "metadata": {}, "source": text.strip("\n")})


def code(text, tag=None):
    cell = {"cell_type": "code", "metadata": {}, "execution_count": None, "outputs": [],
            "source": text.strip("\n")}
    if tag:
        cell["metadata"]["tags"] = [tag]
    CELLS.append(cell)


md("""
# LMLinga Rinconada chat model — training (version 6: v5 + wide everyday conversation written by a teacher model and checked against your own words)

This notebook teaches an AI model (**Gemma 3 4B**) to **talk naturally in Rinconada / Bikol-Iriga**
(greetings, feelings, daily life, school, family, holidays, and the **time / date / day**).

⚠️ **Small talk only.** Health questions still go to the chatbot's health-answer path (RAG, the checked
health documents). Serious messages (no hope / hurt myself) always get the team's fixed checked reply.

**Before you start:** **Runtime → Change runtime type → T4 GPU → Save**.

**Then:** **Runtime → Run all**. When Step 2 asks, upload these 13 files
(from `Desktop\\datasets\\colab_upload_chat`):

- `chat_pairs_v4.csv` — the 293 checked chats + extra ways to say each message (replies never changed)
- `dialogs.csv` — 45 checked conversations (back and forth)
- `dialogs_v4.csv` — new conversations that passed the vocabulary check (only your words)
- `situational_extra.csv` — 550 more time / day / date chats (every hour, many different minutes) so it reads the clock exactly
- `dialogs_v6_reviewed.csv` — 40 dialogs your team checked and corrected (the best data; used 3 times)
- `dialogs_v6.csv` — wide everyday conversations (written by a teacher model, translated with your team's examples, kept only when made of your own words)
- `team_phrases.csv`, `team_words.csv` — your teammates' 977 phrases + 1,000 words (Stage 1: more real Iriga to read)
- `allowed_words.csv`, `blocked_words.csv` — your vocabulary and the Central Bikol / Tagalog words to avoid (used in the test)
- `situational.csv` — chats about time / date / day (the app sends the real time in a context line)
- `iriga_text.csv` — your verified Bikol-Iriga health text (Stage 1: learn Iriga words and grammar)
- `translation_pairs.csv` — English/Tagalog ↔ Iriga pairs from your verified datasets (Stage 1)

More Rinconada sentences for Stage 1 are downloaded automatically.

Takes about **45–60 minutes**. At the end the notebook downloads:

- **`bikol_chat_test_results.csv`** — test answers with empty **team_ok / team_fix** columns for your team
- **`lmlinga-bikol-chat.zip`** — the trained model for Ollama (~2.5 GB)
- **`bikol_chat_lora.zip`** — a small backup of what was learned
""")

md("## Step 1 — Check the GPU and install the training tools")
code("""
import os, subprocess
os.environ["CUDA_VISIBLE_DEVICES"] = "0"   # use 1 GPU (Kaggle gives 2)
gpu =subprocess.run(["nvidia-smi", "--query-gpu=name,memory.total", "--format=csv,noheader"],
                     capture_output=True, text=True).stdout.strip()
print("GPU:", gpu or "NONE - go to Runtime > Change runtime type > T4 GPU, then run again")
""")
code("""
!pip install -q unsloth
import importlib.util
if importlib.util.find_spec("unsloth") is None:
    raise SystemExit("unsloth did NOT install - read the red lines above and send them")
print("unsloth installed OK")
""")

md("## Step 2 — Upload the 13 data files")
code("""
import os, glob, shutil
DATA_FILES = ["chat_pairs_v4.csv", "dialogs.csv", "dialogs_v4.csv", "dialogs_v6.csv", "dialogs_v6_reviewed.csv", "situational.csv", "situational_extra.csv",
              "iriga_text.csv", "translation_pairs.csv", "team_phrases.csv", "team_words.csv",
              "allowed_words.csv", "blocked_words.csv"]
ON_KAGGLE = "KAGGLE_KERNEL_RUN_TYPE" in os.environ   # (Colab also has a /kaggle folder now)
files = None
if ON_KAGGLE:  # Kaggle: the 2 files were added with "Add Input" (they are under /kaggle/input)
    os.makedirs("/tmp/bikol", exist_ok=True)   # big work files go here (more disk space)
    for name in DATA_FILES:
        found = glob.glob(f"/kaggle/input/**/{name}", recursive=True)
        if found:
            shutil.copy(found[0], f"/tmp/bikol/{name}")
    os.chdir("/tmp/bikol")
    DATA_DIR = "."
else:
    try:
        from google.colab import files  # running on Colab
        print("Choose the 13 files:", ", ".join(DATA_FILES))
        files.upload()
        DATA_DIR = "."
    except ImportError:  # running on a normal computer (only for checking the data steps)
        DATA_DIR = os.environ.get("BIKOL_DATA_DIR", ".")

def give(name):
    \"\"\"Hand a finished file to you: download on Colab, Output panel on Kaggle.\"\"\"
    if files:
        files.download(name)
    elif ON_KAGGLE:
        shutil.copy(name, "/kaggle/working/")
        print("Saved to Output:", name)

for name in DATA_FILES:
    print(name, "OK" if os.path.exists(os.path.join(DATA_DIR, name)) else "MISSING - upload it")
""", tag="data")

md("""
## Step 3 — Settings

You normally do **not** need to change these.
""")
code("""
BASE_MODEL = "unsloth/gemma-3-4b-it"   # Gemma 3 4B: tested, works with the Modelfile below.
# The app's English/Tagalog chat uses Gemma 4 (cloud, 31B = too big to train here). A small Gemma 4 could be
# tried by changing BASE_MODEL, but its chat template / Modelfile were NOT tested: keep Gemma 3 4B first.
MAX_LEN = 1024
SEED = 3407

# Stage 1: Rinconada sentences (Hugging Face dataset, Apache-2.0, 1,089 sentences)
RINCONADA_REPO = "tiffp/rinconada_bikol_english_cleaned"
RINCONADA_FILE = "rinconada_bikol_english_cleaned.csv"
PRETRAIN_EPOCHS = 1      # 0 = skip Stage 1. v5 used 2; 1 saves about 30 minutes of the limited free GPU time
PRETRAIN_LR = 5e-5       # small, so the model does not forget how to chat

# Stage 2: your checked chats + conversations + situational (time/date) chats
CHAT_EPOCHS = 3
# situational.csv stays "draft" until the team checks bikol_situational_REVIEW.xlsx.
# Set True to train on the drafts anyway (to try the time/date feature before the team has checked it).
INCLUDE_DRAFT_SITUATIONAL = False
CHAT_LR = 2e-4

MODEL_NAME = "lmlinga-bikol-chat-v6"
QUANT = "q4_k_m"         # compressed size for Ollama (~2.5 GB)

# The team's spelling (the Rinconada sentences use u-spelling: uno, unga, nguwan)
TEAM_SPELLING = {"uno": "ono", "unga": "onga", "nguwan": "ngowan", "kaipuwan": "kaipowan"}
""", tag="data")

md("""
## Step 4 — Prepare the data

- **Chats:** for chats with 3+ ways of saying the message, **one way is hidden** for the test.
- **Conversations:** all are used for learning (back and forth).
- **Rinconada sentences:** spelling changed to the team's (uno → ono, unga → onga, nguwan → ngowan).
""")
code("""
import csv, random, re
random.seed(SEED)

def read_csv(name, statuses=("reviewed",)):
    with open(os.path.join(DATA_DIR, name), encoding="utf-8-sig") as f:   # -sig: also reads files saved for Excel
        return [r for r in csv.DictReader(f) if r.get("status", "reviewed") in statuses]

# --- the context line the app puts in front of every message (same format as BikolSmallTalkService.php) ---
import datetime as dt
DAYS = ["Lunes", "Martes", "Miyerkoles", "Huwebes", "Biyernes", "Sabado", "Dominggo"]
MONTHS = ["Enero", "Pebrero", "Marso", "Abril", "Mayo", "Hunyo", "Hulyo", "Agosto", "Setyembre",
          "Oktubre", "Nobyembre", "Disyembre"]
def context_line(now):
    h12 = now.hour % 12 or 12
    return (f"[Oras: {h12}:{now.minute:02d} {'AM' if now.hour < 12 else 'PM'} | Aldow: {DAYS[now.weekday()]} | "
            f"Petsa: {MONTHS[now.month - 1]} {now.day}, {now.year}]")
def random_now():
    base = dt.datetime(2026, 1, 1) + dt.timedelta(days=random.randrange(0, 700))
    return base.replace(hour=random.randrange(0, 24), minute=random.choice([0, 0, 0, 5, 15, 30, 45, 55]))
def with_context(conv):
    \"\"\"Put a context line before the first person message (the app always does this).\"\"\"
    (role, text), *rest = conv
    return [(role, context_line(random_now()) + "\\n" + text)] + rest

# --- single chats: hide the last ORIGINAL way of saying it (chats with 3+ original ways) ---
# chat_pairs_v4.csv = the reviewed pairs + augmented ways ('augmented' = yes). The hidden message AND its
# augmented copies (tabi, no punctuation, lower case) are kept out of training, so the test stays honest.
def norm(text):
    text = re.sub(r"\\btabi\\b", " ", text.lower())
    return " ".join(re.sub(r"[^\\w' -]", " ", text).split())

pairs = read_csv("chat_pairs_v4.csv")
originals = [p for p in pairs if p.get("augmented", "no") == "no"]   # not the mechanical ("yes") or teammate ("team") extras
ways = {}
for p in originals:
    ways.setdefault(p["conv_id"], [])
    if p["user"] not in ways[p["conv_id"]]:
        ways[p["conv_id"]].append(p["user"])
hidden = {(c, norm(us[-1])) for c, us in ways.items() if len(us) >= 3}
train_chats = [[("user", p["user"]), ("assistant", p["bot"])]
               for p in pairs if (p["conv_id"], norm(p["user"])) not in hidden]
test_pairs = [p for p in originals if (p["conv_id"], norm(p["user"])) in hidden]

# v6: about 37% of the reviewed replies carry a health tagline ("Maanan mo a kalusugan mo"), which taught the model to push health
# talk into small talk. Keep only 30% of those pairs when the person's message is not about health (your file is not edited).
PUSH_RE = re.compile(r"kalusugan|doktor|health|namamatian|onga manongod", re.I)
HEALTH_USER_RE = re.compile(r"sakit|kalusugan|doktor|bolong|gamot|health|masakit|kolog|lagnat|kalintora|dengue|bakuna|otos|obo", re.I)
_rng = random.Random(SEED)
_before = len(train_chats)
train_chats = [c for c in train_chats
               if not (PUSH_RE.search(c[1][1]) and not HEALTH_USER_RE.search(c[0][1])) or _rng.random() < 0.3]
print(f"Health-tagline chats thinned: {_before} -> {len(train_chats)} single chats")

# --- conversations (back and forth) ---
turns = {}
for r in read_csv("dialogs.csv"):
    role = "user" if r["speaker"] == "Person" else "assistant"
    turns.setdefault(int(r["dialog_id"]), []).append((int(r["turn"]), role, r["text"]))
train_dialogs = [[(role, text) for _, role, text in sorted(t)] for _, t in sorted(turns.items())]
# new conversations that passed the vocabulary check (filter_generated.py), status "auto-checked"
turns4 = {}
for r in read_csv("dialogs_v4.csv", ("auto-checked", "reviewed")):
    role = "user" if r["speaker"] == "Person" else "assistant"
    turns4.setdefault(r["dialog_id"], []).append((int(r["turn"]), role, r["text"]))
train_dialogs += [[(role, text) for _, role, text in sorted(t)] for _, t in sorted(turns4.items())]
# v6: wide everyday conversation. The cloud teacher wrote it in Tagalog, it was translated with YOUR team's examples and kept only
# when made of your own words (ml/bikol-chat/teacher/filter_teacher.py).
turns6 = {}
if os.path.exists(os.path.join(DATA_DIR, "dialogs_v6.csv")):
    for r in read_csv("dialogs_v6.csv", ("auto-checked",)):
        role = "user" if r["speaker"] == "Person" else "assistant"
        turns6.setdefault(r["dialog_id"], []).append((int(r["turn"]), role, r["text"]))
train_dialogs += [[(role, text) for _, role, text in sorted(t)] for _, t in sorted(turns6.items())]
print(f"v6 teacher dialogs: {len(turns6)}")
# v6: the 40 dialogs your team checked and corrected (spot-check): the best data we have, so they are used 3 times
turnsR = {}
if os.path.exists(os.path.join(DATA_DIR, "dialogs_v6_reviewed.csv")):
    for r in read_csv("dialogs_v6_reviewed.csv", ("reviewed",)):
        role = "user" if r["speaker"] == "Person" else "assistant"
        turnsR.setdefault(r["dialog_id"], []).append((int(r["turn"]), role, r["text"]))
reviewed_dialogs = [[(role, text) for _, role, text in sorted(t)] for _, t in sorted(turnsR.items())]
train_dialogs += reviewed_dialogs * 3
print(f"team-corrected dialogs: {len(reviewed_dialogs)} (used 3 times)")

# --- situational chats: the context line is already stored with each one ---
sit_rows = read_csv("situational.csv", ("reviewed", "draft") if INCLUDE_DRAFT_SITUATIONAL else ("reviewed",))
sit_rows += read_csv("situational_extra.csv")   # v4b: many more time chats (same reviewed wording rules)
train_sit = [[("user", r["context"] + "\\n" + r["user"]), ("assistant", r["bot"])] for r in sit_rows]
if not train_sit:
    print("NOTE: no reviewed situational chats yet -> time/date answers are NOT trained "
          "(team checks bikol_situational_REVIEW.xlsx, or set INCLUDE_DRAFT_SITUATIONAL = True)")

train_convs = [with_context(c) for c in train_chats + train_dialogs] + train_sit
print(f"Learn from: {len(train_chats)} chats + {len(train_dialogs)} conversations + {len(train_sit)} situational = {len(train_convs)}")
print(f"Test (hidden ways of saying it): {len(test_pairs)} pairs")

def team_spelling(text):
    def fix(m):
        w = m.group(0)
        new = TEAM_SPELLING[w.lower()]
        return new.capitalize() if w[0].isupper() else new
    return re.sub(r"\\b(" + "|".join(TEAM_SPELLING) + r")\\b", fix, text, flags=re.I)
""", tag="data")
code("""
# --- Rinconada sentences (downloaded, not stored in your project) ---
rinconada = []
if PRETRAIN_EPOCHS > 0:
    try:
        from huggingface_hub import hf_hub_download
        path = hf_hub_download(RINCONADA_REPO, RINCONADA_FILE, repo_type="dataset")
        with open(path, encoding="utf-8") as f:
            rinconada = [team_spelling(r["rinconada_bikol"].strip()) for r in csv.DictReader(f)
                         if r.get("rinconada_bikol", "").strip()]
    except Exception as e:
        print("Could not download the Rinconada sentences, Stage 1 will be skipped:", e)
random.shuffle(rinconada)
paragraphs = [" ".join(rinconada[i:i + 5]) for i in range(0, len(rinconada), 5)]
print(f"Rinconada sentences: {len(rinconada)} -> {len(paragraphs)} short paragraphs")

# --- your verified Iriga health text + translation pairs (only to learn Iriga words and grammar) ---
with open(os.path.join(DATA_DIR, "iriga_text.csv"), encoding="utf-8") as f:
    iriga_text = [r["text"].strip() for r in csv.DictReader(f) if r["text"].strip()]
with open(os.path.join(DATA_DIR, "translation_pairs.csv"), encoding="utf-8") as f:
    pair_texts = [("English" if r["language"] == "en" else "Tagalog", r["source"].strip(), r["bikol_iriga"].strip())
                  for r in csv.DictReader(f)]
paragraphs += iriga_text
paragraphs += [f"{lang}: {src}\\nBikol-Iriga: {bcl}" for lang, src, bcl in pair_texts]

# --- v5: the teammates' translation sheets (977 phrases + 1,000 words written by your team) ---
with open(os.path.join(DATA_DIR, "team_phrases.csv"), encoding="utf-8-sig") as f:
    team_phrases = [r for r in csv.DictReader(f) if r["iriga"].strip()]
with open(os.path.join(DATA_DIR, "team_words.csv"), encoding="utf-8-sig") as f:
    team_words = [r for r in csv.DictReader(f) if r["iriga"].strip()]
paragraphs += [r["iriga"].strip() for r in team_phrases]                                  # plain Iriga sentences
paragraphs += [f"Tagalog: {r['tagalog'].strip()}\\nBikol-Iriga: {r['iriga'].strip()}" for r in team_phrases]
paragraphs += [f"English: {r['english'].strip()}\\nBikol-Iriga: {r['iriga'].strip()}" for r in team_phrases if r["english"].strip()]
for i in range(0, len(team_words), 12):                                                   # word list, 12 per text
    paragraphs.append("Bikol-Iriga words:\\n" + "\\n".join(f"{r['iriga'].strip()} = {r['english'].strip()}"
                                                          for r in team_words[i:i + 12]))
paragraphs += ["\\n".join(text for _, _, text in sorted(d)) for d in list(turns6.values()) + list(turnsR.values()) * 3]    # v6: the new dialogs as plain Iriga text
random.shuffle(paragraphs)
print(f"Verified Iriga text: {len(iriga_text)} chunks + {len(pair_texts)} translation pairs; teammates: "
      f"{len(team_phrases)} phrases + {len(team_words)} words -> Stage 1 has {len(paragraphs)} texts")
if paragraphs:
    print("Example:", paragraphs[0][:200], "...")
""")

md("## Step 5 — Load the base model")
code("""
from unsloth import FastModel
from unsloth.chat_templates import get_chat_template
import torch

model, tokenizer = FastModel.from_pretrained(
    model_name=BASE_MODEL,
    max_seq_length=MAX_LEN,
    load_in_4bit=True,      # 4B fits on a free T4 in 4-bit
    load_in_8bit=False,
    full_finetuning=False,  # LoRA: train small add-on layers, not the whole model
)
model = FastModel.get_peft_model(
    model,
    finetune_vision_layers=False,   # text only
    finetune_language_layers=True,
    finetune_attention_modules=True,
    finetune_mlp_modules=True,
    r=16, lora_alpha=16, lora_dropout=0, bias="none",
    random_state=SEED,
)
tokenizer = get_chat_template(tokenizer, chat_template="gemma-3")
""")

md("""
## Step 6 — Stage 1: read Rinconada sentences

The model reads real Rinconada text (sentences + your verified Iriga health text) so it gets used to the words and grammar.
This is only for **language**: health facts are NOT answered by this model, they come from the RAG documents.
""")
code("""
from datasets import Dataset
from trl import SFTTrainer, SFTConfig

def run_trainer(dataset, epochs, lr, responses_only=False):
    cfg = dict(
        dataset_text_field="text", per_device_train_batch_size=2, gradient_accumulation_steps=4,
        warmup_steps=5, num_train_epochs=epochs, learning_rate=lr, logging_steps=10,
        optim="adamw_8bit", weight_decay=0.01, lr_scheduler_type="linear", seed=SEED,
        output_dir="outputs", report_to="none",
    )
    trainer = SFTTrainer(model=model, tokenizer=tokenizer, train_dataset=dataset,
                         args=SFTConfig(**cfg))
    if responses_only:
        from unsloth.chat_templates import train_on_responses_only
        trainer = train_on_responses_only(trainer, instruction_part="<start_of_turn>user\\n",
                                          response_part="<start_of_turn>model\\n")
    return trainer.train()

if PRETRAIN_EPOCHS > 0 and paragraphs:
    text_data = Dataset.from_dict({"text": [p + tokenizer.eos_token for p in paragraphs]})
    result = run_trainer(text_data, epochs=PRETRAIN_EPOCHS, lr=PRETRAIN_LR)
    print("Stage 1 done. Final loss:", round(result.training_loss, 3))
else:
    print("Stage 1 skipped")
""")

md("""
## Step 7 — Stage 2: learn your chats and conversations

The model only learns to write **LMLinga's replies** (not the person's messages).
""")
code("""
def as_text(conv):
    msgs = [{"role": role, "content": text} for role, text in conv]
    return tokenizer.apply_chat_template(msgs, tokenize=False).removeprefix("<bos>")

random.shuffle(train_convs)
chat_data = Dataset.from_dict({"text": [as_text(c) for c in train_convs]})
print(chat_data[0]["text"])
result = run_trainer(chat_data, epochs=CHAT_EPOCHS, lr=CHAT_LR, responses_only=True)
print("Stage 2 done. Final loss:", round(result.training_loss, 3))
""")

md("""
## Step 7b — SAVE what was learned right now (before anything else)

The free GPU time can run out during the test or the GGUF export. The small adapter file is saved and handed to you **now**,
so the hours of training are never lost: `export_bikol_chat.ipynb` turns it into the Ollama model in about 15 minutes.
**Keep `bikol_chat_lora.zip`.**
""")
code("""
model.save_pretrained("bikol_chat_lora")
tokenizer.save_pretrained("bikol_chat_lora")
shutil.make_archive("bikol_chat_lora", "zip", "bikol_chat_lora")
give("bikol_chat_lora.zip")
print("Saved and handed over: bikol_chat_lora.zip - keep it!")
""")

md("""
## Step 8 — Test

1. **Hidden messages** — ways of saying it that the model never saw. Compared with the checked reply.
2. **New everyday messages** — situations that are NOT in your data. There is no "right" reply,
   so **your team judges** them in the results file (`team_ok` = yes/no, `team_fix` = better reply).
3. **Spelling check** — flags Central Bikol or u-spelling words (ano, kan, nin, dai, uno, unga, nguwan…).
""")
code("""
import difflib

NOT_TEAM_WORDS = {"ano", "kan", "nin", "dai", "saro", "sarong", "igdi", "salud", "dapit", "hapot",
                  "aram", "giraray", "sako", "tataramon", "masabutan", "ingatan", "anoman",
                  "uno", "unga", "nguwan", "kaipuwan"}

NEW_MESSAGES = [
    "Maray na apon! Kumusta a trabawo mo ngowan?",
    "Napagal ako ta naglakaw ako pa-eskwelahan.",
    "Agko kami pista sa barangay udma!",
    "Namumundo ako ta nagadan a ido ko.",
    "Diri ko isi kin ono a gigibowon ko pagkatapos ka eskwelahan.",
    "Mainit ngowan, diri ako makatorog.",
    "Nagkaon ka na?",
    "Ono a gusto mong pagkaon?",
    "Agko ako bagong trabawo!",
    "Inaandal ako ta mag-report ako udma sa klase.",
    "Solo sana ako ngowan sa baluy ta nasa trabawo a nanay ko.",
    "Haha, maray ka man palan mag-istorya.",
    "Maoran na naman. Uda kami kuryente.",
    "Ono a maray na gibowon kin Sabado?",
    "Nagbalik na a tatay ko sa Iriga!",
    "Pasensiya na, ngowan sana ako nakabalik.",
    "Diri ako nakatorog sa maray ngowan na gab'i.",
    "Ganting a Pasko ngowan na taon!",
    "Siisay a gumibo kanimo? Estudyante raw?",
    "Paalam na, matorog na ako. Salamat sa pakikiistorya!",
]

def ask(message, max_new_tokens=128, now=None):
    # the app always sends the real time/date in front of the message
    msgs = [{"role": "user", "content": context_line(now or random_now()) + "\\n" + message}]
    text = tokenizer.apply_chat_template(msgs, tokenize=False, add_generation_prompt=True)
    inputs = tokenizer(text, return_tensors="pt", add_special_tokens=False).to(model.device)
    out = model.generate(**inputs, max_new_tokens=max_new_tokens, do_sample=False)
    return tokenizer.decode(out[0][inputs["input_ids"].shape[1]:], skip_special_tokens=True).strip()

# Your own vocabulary (replaces a linguist's check): words outside it, and words that must never appear.
with open(os.path.join(DATA_DIR, "allowed_words.csv"), encoding="utf-8-sig") as f:
    ALLOWED = {r["word"] for r in csv.DictReader(f)}
with open(os.path.join(DATA_DIR, "blocked_words.csv"), encoding="utf-8-sig") as f:
    BLOCKED = {r["word"] for r in csv.DictReader(f)}
ALLOWED |= {"lmlinga", "iriga", "am", "pm"} | {w.lower() for w in DAYS + MONTHS}
NOT_TEAM_WORDS |= BLOCKED
WORDS = re.compile(r"[^\\W\\d_]+(?:['\\-][^\\W\\d_]+)*")
vocab_total = vocab_outside = 0

def bad_words(text):
    global vocab_total, vocab_outside
    found = set()
    for raw in WORDS.findall(text):
        w = raw.lower()
        vocab_total += 1
        if w in NOT_TEAM_WORDS and raw != "Mayo":
            found.add(w)
        elif w not in ALLOWED:
            vocab_outside += 1
            found.add("?" + w)
    return ", ".join(sorted(found))

rows = []
expected = {}
for p in test_pairs:
    expected.setdefault((p["conv_id"], p["user"]), []).append(p["bot"])
same = close = 0
for (conv, user), answers in expected.items():
    got = ask(user)
    sim = max(difflib.SequenceMatcher(None, got, a).ratio() for a in answers)
    same += got in answers
    close += sim >= 0.8
    rows.append({"test": "hidden", "message": user, "model_reply": got, "checked_reply": answers[0],
                 "similarity": round(sim, 2), "not_team_words": bad_words(got), "team_ok": "", "team_fix": ""})
for msg in NEW_MESSAGES:
    got = ask(msg)
    rows.append({"test": "new", "message": msg, "model_reply": got, "checked_reply": "",
                 "similarity": "", "not_team_words": bad_words(got), "team_ok": "", "team_fix": ""})

# --- time / date check: the answer must contain the time/day/date from the context line ---
HOUR_WORDS = {1: "ona", 2: "dos", 3: "tres", 4: "kuwatro", 5: "singko", 6: "sais", 7: "siyete", 8: "otso",
              9: "nuwebe", 10: "dies", 11: "onse", 12: "dose"}
TIME_QUESTIONS = ["Ono nang oras?", "What time is it?", "Anong oras na?", "Pirang oras na?"]
DAY_QUESTIONS = ["Ono ngowan na aldow?", "What day is it today?"]
DATE_QUESTIONS = ["Ono a petsa ngowan?", "What's the date today?"]
time_ok = day_ok = date_ok = 0
N_TIME = 24   # twice as many clock tests as before (v4 got 8 of 12)
for i in range(N_TIME):
    now = random_now()
    h12 = now.hour % 12 or 12
    for kind, q in (("time", TIME_QUESTIONS[i % 4]), ("day", DAY_QUESTIONS[i % 2]), ("date", DATE_QUESTIONS[i % 2])):
        got = ask(q, now=now)
        if kind == "time":
            ok = (f"{h12}:{now.minute:02d}" in got) if now.minute else (f"{'ala' if h12 == 1 else 'alas'} {HOUR_WORDS[h12]}" in got.lower() or f"{h12}:00" in got)
            time_ok += ok
        elif kind == "day":
            ok = DAYS[now.weekday()].lower() in got.lower()
            day_ok += ok
        else:
            ok = DAYS[now.weekday()].lower() in got.lower() and MONTHS[now.month - 1].lower() in got.lower() and str(now.day) in got
            date_ok += ok
        rows.append({"test": f"{kind}-check", "message": context_line(now) + " " + q, "model_reply": got,
                     "checked_reply": "", "similarity": "OK" if ok else "WRONG",
                     "not_team_words": bad_words(got), "team_ok": "", "team_fix": ""})
print(f"Time/date check (does the answer use the real time in the context line?): "
      f"time {time_ok}/{N_TIME}   day {day_ok}/{N_TIME}   date {date_ok}/{N_TIME}")

for r in rows:
    flag = f"   ⚠ {r['not_team_words']}" if r["not_team_words"] else ""
    print(f"[{r['test']}] {r['message']}\\n   -> {r['model_reply']}{flag}")
n = len(expected)
print(f"\\nHidden messages: {n}   same reply: {same}/{n}   close (>=80% alike): {close}/{n}")
print("Replies with Central Bikol / u-spelling words:", sum(1 for r in rows if r["not_team_words"]), "of", len(rows))
pct = 100 * vocab_outside / max(vocab_total, 1)
leaks = sum(1 for r in rows for w in r["not_team_words"].split(", ") if w and not w.startswith("?"))
print(f"Words outside YOUR vocabulary: {vocab_outside}/{vocab_total} = {pct:.1f}%   (target: under 3%)")
print(f"Central Bikol / Tagalog / u-spelling words used: {leaks}   (target: 0)")
print("(words marked ? are outside your vocabulary; v3 for comparison: same 7/62, close 11/62)")

with open("bikol_chat_test_results.csv", "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, fieldnames=list(rows[0]))
    w.writeheader()
    w.writerows(rows)
give("bikol_chat_test_results.csv")
""")
code("""
# Try it yourself: change these messages and run this cell again.
for message in ["Kumusta ika?", "Napagal ako ngowan.", "Ono a ngaran mo?", "What time is it?", "Paalam!"]:
    print(f"{message}\\n   -> {ask(message)}")
""")

md("""
## Step 9 — Save for Ollama and download

Makes a **GGUF** file (Ollama's model format) and a **Modelfile** (Ollama's settings file), then zips them.

**To use it** (after unzipping `lmlinga-bikol-chat.zip`):

```
cd lmlinga-bikol-chat
ollama create lmlinga-bikol-chat -f Modelfile
ollama run lmlinga-bikol-chat "Kumusta ika?"
```
""")
code("""
import glob, shutil

# Small backup of what was learned (LoRA adapter)
model.save_pretrained("bikol_chat_lora")
tokenizer.save_pretrained("bikol_chat_lora")
shutil.make_archive("bikol_chat_lora", "zip", "bikol_chat_lora")

# Full model as GGUF for Ollama (the Unsloth function arguments changed between versions)
OUT = MODEL_NAME
try:
    model.save_pretrained_gguf(OUT, tokenizer, quantization_method=QUANT)
except TypeError:
    model.save_pretrained_gguf(OUT, quantization_type=QUANT.upper())

ggufs = sorted(glob.glob(f"{OUT}*/**/*.gguf", recursive=True) + glob.glob(f"{OUT}*.gguf") + glob.glob("*.gguf"))
ggufs = [g for g in ggufs if "mmproj" not in g.lower()]   # skip the image part, text only
print("GGUF files found:", ggufs)
assert ggufs, "No .gguf file was made - see the error above"
os.makedirs("ollama_export", exist_ok=True)
gguf_name = f"{MODEL_NAME}.{QUANT.upper()}.gguf"
shutil.copy(ggufs[0], os.path.join("ollama_export", gguf_name))
""")
code('''
Q = chr(34) * 3   # three double quotes, used by the Modelfile
MODELFILE = f"""FROM ./{gguf_name}

TEMPLATE {Q}{{{{- range $i, $_ := .Messages }}}}
{{{{- $last := eq (len (slice $.Messages $i)) 1 }}}}
{{{{- if eq .Role "user" }}}}<start_of_turn>user
{{{{ .Content }}}}<end_of_turn>
{{{{ if $last }}}}<start_of_turn>model
{{{{ end }}}}
{{{{- else if eq .Role "assistant" }}}}<start_of_turn>model
{{{{ .Content }}}}{{{{ if not $last }}}}<end_of_turn>
{{{{ end }}}}
{{{{- end }}}}
{{{{- end }}}}{Q}

PARAMETER stop "<end_of_turn>"
PARAMETER temperature 0.3
PARAMETER num_ctx 2048
"""
with open("ollama_export/Modelfile", "w", encoding="utf-8") as f:
    f.write(MODELFILE)
print(MODELFILE)

shutil.make_archive(MODEL_NAME, "zip", "ollama_export")
print("Made:", f"{MODEL_NAME}.zip", "bikol_chat_lora.zip")
for name in [f"{MODEL_NAME}.zip", "bikol_chat_lora.zip"]:
    give(name)
''')

nb = {
    "cells": CELLS,
    "metadata": {
        "accelerator": "GPU",
        "colab": {"gpuType": "T4", "provenance": []},
        "kernelspec": {"display_name": "Python 3", "name": "python3"},
        "language_info": {"name": "python"},
    },
    "nbformat": 4,
    "nbformat_minor": 0,
}

out = HERE / "colab" / "train_bikol_chat.ipynb"
out.parent.mkdir(exist_ok=True)
out.write_text(json.dumps(nb, indent=1, ensure_ascii=False), encoding="utf-8")
print("notebook ->", out)

# --- the RESCUE notebook: bikol_chat_lora.zip -> Ollama model (for when the free GPU time ran out before the export) ---
step9 = CELLS[-3:]
assert step9[0]["source"].lstrip().startswith("## Step 9"), "the Step 9 cells moved: update the rescue notebook"


def _cell(kind, text):
    cell = {"cell_type": kind, "metadata": {}, "source": text.strip("\n")}
    if kind == "code":
        cell.update({"execution_count": None, "outputs": []})
    return cell


EXPORT_CELLS = [
    _cell("markdown", """
# Turn `bikol_chat_lora.zip` into the Ollama model (about 15 minutes)

Use this when training finished but the free GPU time ran out before the model could be exported.

1. **Runtime → Change runtime type → T4 GPU** (Colab) or **Accelerator → GPU** (Kaggle).
2. **Run all.** When asked, upload `bikol_chat_lora.zip` (on Kaggle: *Add Input* → upload it as a dataset first).
3. Download `lmlinga-bikol-chat-v6.zip`, unzip it, then on your computer:
   `ollama create lmlinga-bikol-chat-v6 -f Modelfile`
"""),
    _cell("code", """
!pip install -q unsloth
import importlib.util
if importlib.util.find_spec("unsloth") is None:
    raise SystemExit("unsloth did NOT install - read the red lines above and send them")
print("unsloth installed OK")
"""),
    _cell("code", """
import os, glob, shutil, zipfile
os.environ["CUDA_VISIBLE_DEVICES"] = "0"
MODEL_NAME = "%s"
QUANT = "q4_k_m"
ON_KAGGLE = "KAGGLE_KERNEL_RUN_TYPE" in os.environ
files = None
if ON_KAGGLE:
    os.makedirs("/tmp/bikol", exist_ok=True)
    found = glob.glob("/kaggle/input/**/bikol_chat_lora*.zip", recursive=True)
    assert found, "Add bikol_chat_lora.zip with Add Input first"
    shutil.copy(found[0], "/tmp/bikol/bikol_chat_lora.zip")
    os.chdir("/tmp/bikol")
else:
    try:
        from google.colab import files
        print("Choose bikol_chat_lora.zip")
        uploaded = files.upload()
        name = next(iter(uploaded))
        if name != "bikol_chat_lora.zip":
            os.replace(name, "bikol_chat_lora.zip")
    except ImportError:
        pass

def give(name):
    if files:
        files.download(name)
    elif ON_KAGGLE:
        shutil.copy(name, "/kaggle/working/")
        print("Saved to Output:", name)

with zipfile.ZipFile("bikol_chat_lora.zip") as z:
    z.extractall("bikol_chat_lora")
print("adapter files:", sorted(os.listdir("bikol_chat_lora"))[:6])
""" % "lmlinga-bikol-chat-v6"),
    _cell("code", """
from unsloth import FastModel
model, tokenizer = FastModel.from_pretrained(
    model_name="bikol_chat_lora",   # the saved adapter: the base model is fetched from its config
    max_seq_length=1024,
    load_in_4bit=True,
    load_in_8bit=False,
    full_finetuning=False,
)
print("adapter loaded")
"""),
] + step9
export_nb = dict(nb, cells=EXPORT_CELLS)
export_out = HERE / "colab" / "export_bikol_chat.ipynb"
export_out.write_text(json.dumps(export_nb, indent=1, ensure_ascii=False), encoding="utf-8")
print("rescue notebook ->", export_out)

UPLOAD.mkdir(parents=True, exist_ok=True)
old = UPLOAD / "bcl_sentences.txt"   # version 1 file, not used any more
if old.exists():
    old.unlink()
shutil.copy(out, UPLOAD / out.name)
shutil.copy(export_out, UPLOAD / export_out.name)
for name in ["chat_pairs_v4.csv", "dialogs.csv", "dialogs_v4.csv", "dialogs_v6.csv", "dialogs_v6_reviewed.csv", "situational.csv", "situational_extra.csv",
             "iriga_text.csv", "translation_pairs.csv", "team_phrases.csv", "team_words.csv",
             "allowed_words.csv", "blocked_words.csv"]:
    src = HERE / "dataset" / name
    if src.exists():
        shutil.copy(src, UPLOAD / name)
    else:
        print("MISSING (make it first):", name)
print("upload folder ->", UPLOAD, sorted(p.name for p in UPLOAD.iterdir()))
