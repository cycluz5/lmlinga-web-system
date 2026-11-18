# Cloud AI (Gemma): test questions (Bikol-Iriga)

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
