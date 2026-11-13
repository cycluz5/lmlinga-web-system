# Bikol-Iriga intent dataset — team guide

Workspace for building the Bikol-Iriga question dataset. Nothing here is used by
the running app yet.

## Files

| File | What to do |
|---|---|
| `vocabulary_guide.csv` | Fill `bikol_iriga` (the word you normally use) and `bikol_variants` (other spellings / words residents also use). Leave a row blank if there is no Bikol-Iriga word — note it in `notes`. |
| `question_patterns.csv` | Fill `bikol_iriga_pattern` with how a resident would naturally ask it. Add more rows if you know other ways of asking. |

## Steps

1. Fill the vocabulary first — it becomes the shared word list for the team.
2. Fill the question patterns.
3. Use both to write the dataset (`question,intent`), 30–50 questions per intent.
4. A second teammate checks the labels.
5. Save as **CSV UTF-8**.

## Intents

`definition` · `symptoms` · `causes` · `management` (prevention **and** treatment)
· `diagnosis` · `benefits` · `effects` · `schedule` · `warning` · `general`

## Tips

- Translate how residents **speak**, not word-for-word.
- Write down every spelling you see people use (for example igin / igen).
- English and Tagalog words that residents mix in are fine — keep them.

## Training dataset (draft, 2026-11-11)

| File | What it is |
|---|---|
| `build_dataset.py` | Makes the questions from templates + the word list. Run: `python build_dataset.py` |
| `dataset/bikol_intent_all.csv` | All questions (`question,intent,topic,template`) |
| `dataset/train.csv` / `dataset/test.csv` | 80% / 20% split, `question,intent` — what the model trains on |
| `dataset/real_check.csv` | Real Bikol questions from the chatbot's reference file (extra test, not generated) |
| `quick_check.py` | Small test model, no installs: `python quick_check.py` |
| `make_review_sheet.py` | Makes the Excel review sheet from the dataset (all questions) |
| `make_pattern_sheet.py` | Makes the SHORT review sheet: patterns + blank words (faster to check) |
| `pattern_review_2026-11-11.csv` | Record of the speaker review: 108 patterns approved, 5 corrected (kwasa, nakokoko, maiisiyan, mingyari) |

Spelling standard: **ono** (never uno). "ono a" is the main form, "ono an" is also used (about 1 in 8).

Review copy for the speaker: `Desktop/datasets/bikol_intent_dataset_REVIEW_ono.xlsx`
(mark each row yes / fix / delete). After review, the fixes go back into the dataset
before training on Colab.

## Model in the chatbot (2026-11-11)

1. Train in Colab: `colab/train_bikol_intent.ipynb` (upload the 4 CSVs from `dataset/`).
2. Put the downloaded `bikol_intent_model.json` in **both** `ml/bikol-intent/model/` (record) and
   `resources/ml/` (the copy the app reads).
3. `app/Services/BikolIntentClassifier.php` reads it. In `RagService::detectBikolIntent()`:
   warning keyword rules first → model if confidence ≥ 0.5 → old keyword rules.
4. `.env` switches: `BIKOL_INTENT_MODEL_ENABLED=false` turns the model off;
   `BIKOL_INTENT_MIN_CONFIDENCE=0.5` changes how sure it must be.
5. Tests: `php artisan test --filter BikolIntent` (checks PHP gives the same answers as Colab).
