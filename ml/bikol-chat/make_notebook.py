"""Write colab/train_bikol_chat.ipynb (Google Colab notebook, version 2) for the Rinconada chat model.

    python make_notebook.py

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
# LMLinga Rinconada chat model — training (version 2)

This notebook teaches an AI model (**Gemma 3 4B**) to **talk naturally in Rinconada / Bikol-Iriga**
(greetings, feelings, daily life, school, family, holidays…).

⚠️ **Small talk only.** Health questions still go to the chatbot's health-answer path (RAG, the checked
health documents). Serious messages (no hope / hurt myself) always get the team's fixed checked reply.

**Before you start:** **Runtime → Change runtime type → T4 GPU → Save**.

**Then:** **Runtime → Run all**. When Step 2 asks, upload these 2 files
(from `Desktop\\datasets\\colab_upload_chat`):

- `chat_pairs.csv` — 293 checked chats (one message + one reply)
- `dialogs.csv` — 45 checked conversations (back and forth)

The Rinconada sentences for Stage 1 are downloaded automatically.

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

md("## Step 2 — Upload the 2 data files")
code("""
import os, glob, shutil
ON_KAGGLE = "KAGGLE_KERNEL_RUN_TYPE" in os.environ   # (Colab also has a /kaggle folder now)
files = None
if ON_KAGGLE:  # Kaggle: the 2 files were added with "Add Input" (they are under /kaggle/input)
    os.makedirs("/tmp/bikol", exist_ok=True)   # big work files go here (more disk space)
    for name in ["chat_pairs.csv", "dialogs.csv"]:
        found = glob.glob(f"/kaggle/input/**/{name}", recursive=True)
        if found:
            shutil.copy(found[0], f"/tmp/bikol/{name}")
    os.chdir("/tmp/bikol")
    DATA_DIR = "."
else:
    try:
        from google.colab import files  # running on Colab
        print("Choose 2 files: chat_pairs.csv and dialogs.csv")
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

for name in ["chat_pairs.csv", "dialogs.csv"]:
    print(name, "OK" if os.path.exists(os.path.join(DATA_DIR, name)) else "MISSING - upload it")
""", tag="data")

md("""
## Step 3 — Settings

You normally do **not** need to change these.
""")
code("""
BASE_MODEL = "unsloth/gemma-3-4b-it"   # Gemma 3 4B, already knows how to chat in many languages
MAX_LEN = 1024
SEED = 3407

# Stage 1: Rinconada sentences (Hugging Face dataset, Apache-2.0, 1,089 sentences)
RINCONADA_REPO = "tiffp/rinconada_bikol_english_cleaned"
RINCONADA_FILE = "rinconada_bikol_english_cleaned.csv"
PRETRAIN_EPOCHS = 2      # 0 = skip Stage 1
PRETRAIN_LR = 5e-5       # small, so the model does not forget how to chat

# Stage 2: your checked chats + conversations
CHAT_EPOCHS = 3
CHAT_LR = 2e-4

MODEL_NAME = "lmlinga-bikol-chat"
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

def read_csv(name):
    with open(os.path.join(DATA_DIR, name), encoding="utf-8") as f:
        return [r for r in csv.DictReader(f) if r.get("status", "reviewed") == "reviewed"]

# --- single chats: hide the last way of saying it (conversations with 3+ ways) ---
pairs = read_csv("chat_pairs.csv")
ways = {}
for p in pairs:
    ways.setdefault(p["conv_id"], [])
    if p["user"] not in ways[p["conv_id"]]:
        ways[p["conv_id"]].append(p["user"])
hidden = {(c, us[-1]) for c, us in ways.items() if len(us) >= 3}
train_chats = [[("user", p["user"]), ("assistant", p["bot"])]
               for p in pairs if (p["conv_id"], p["user"]) not in hidden]
test_pairs = [p for p in pairs if (p["conv_id"], p["user"]) in hidden]

# --- conversations (back and forth) ---
turns = {}
for r in read_csv("dialogs.csv"):
    role = "user" if r["speaker"] == "Person" else "assistant"
    turns.setdefault(int(r["dialog_id"]), []).append((int(r["turn"]), role, r["text"]))
train_dialogs = [[(role, text) for _, role, text in sorted(t)] for _, t in sorted(turns.items())]

train_convs = train_chats + train_dialogs
print(f"Learn from: {len(train_chats)} chats + {len(train_dialogs)} conversations = {len(train_convs)}")
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

The model reads real Rinconada text so it gets used to the words and grammar.
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

def ask(message, max_new_tokens=128):
    msgs = [{"role": "user", "content": message}]
    text = tokenizer.apply_chat_template(msgs, tokenize=False, add_generation_prompt=True)
    inputs = tokenizer(text, return_tensors="pt", add_special_tokens=False).to(model.device)
    out = model.generate(**inputs, max_new_tokens=max_new_tokens, do_sample=False)
    return tokenizer.decode(out[0][inputs["input_ids"].shape[1]:], skip_special_tokens=True).strip()

def bad_words(text):
    return ", ".join(sorted({w for w in re.findall(r"[a-zñ']+", text.lower()) if w in NOT_TEAM_WORDS}))

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

for r in rows:
    flag = f"   ⚠ {r['not_team_words']}" if r["not_team_words"] else ""
    print(f"[{r['test']}] {r['message']}\\n   -> {r['model_reply']}{flag}")
n = len(expected)
print(f"\\nHidden messages: {n}   same reply: {same}/{n}   close (>=80% alike): {close}/{n}")
print("Replies with Central Bikol / u-spelling words:", sum(1 for r in rows if r["not_team_words"]), "of", len(rows))
print("(Version 1 had: same 6/63, close 14/63)")

with open("bikol_chat_test_results.csv", "w", newline="", encoding="utf-8-sig") as f:
    w = csv.DictWriter(f, fieldnames=list(rows[0]))
    w.writeheader()
    w.writerows(rows)
give("bikol_chat_test_results.csv")
""")
code("""
# Try it yourself: change these messages and run this cell again.
for message in ["Kumusta ika?", "Napagal ako ngowan.", "Ono a ngaran mo?", "Paalam!"]:
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

UPLOAD.mkdir(parents=True, exist_ok=True)
old = UPLOAD / "bcl_sentences.txt"   # version 1 file, not used any more
if old.exists():
    old.unlink()
shutil.copy(out, UPLOAD / out.name)
for name in ["chat_pairs.csv", "dialogs.csv"]:
    shutil.copy(HERE / "dataset" / name, UPLOAD / name)
print("upload folder ->", UPLOAD, sorted(p.name for p in UPLOAD.iterdir()))
