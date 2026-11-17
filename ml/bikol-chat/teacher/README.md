# Teacher pipeline (v6): wide everyday conversation without a linguist

A strong cloud model (`gemma4:31b-cloud`, the same one the app uses for English/Tagalog) writes the conversations. It never invents
Iriga: it writes **Tagalog**, then translates with **your team's own translations as examples**, and an automatic filter keeps
only dialogs made of your own words. Needs internet and Ollama Cloud credits only while generating. The final model runs locally.

Run from `ml/bikol-chat/teacher` (`gen`/`translate` need scikit-learn and `OLLAMA_API_KEY` in the project `.env`):

| Step | Command | Output |
|---|---|---|
| 1 | `python build_memory.py` | `dataset/teacher/memory.csv` (4,900 team translations) |
| 2 | `python gen_tagalog_dialogs.py 20 4` | `dataset/teacher/dialogs_tl.jsonl` (78 topics x 20; resumable; 4 parallel calls) |
| 3 | `python translate_to_iriga.py 3` | `dataset/teacher/dialogs_iriga_raw.jsonl` (resumable) |
| 4 | `python filter_teacher.py` | `dataset/dialogs_v6.csv`, `rejected_teacher.csv`, `unknown_words_teacher.csv`, `teacher_spotcheck.csv` |
| 5 | `python ../make_notebook.py` | notebook v6 + upload folder |

`429 too many concurrent requests` only means "wait": the helper retries. `402` means the credits are used up (the scripts stop; nothing is lost).

**Spot-check (5 minutes):** open `dataset/teacher/teacher_spotcheck.csv`, write `yes` or `bad` in the "ok?" column for each of the 40 dialogs.
If more than 30% are bad, the filter is tightened before any training.
**Words:** `unknown_words_teacher.csv` lists words that block otherwise good dialogs. A word that is really Iriga goes into
`dataset/approved_words.txt` (one per line); run step 4 again.
