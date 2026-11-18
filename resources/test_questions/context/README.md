# Context-based: test questions (Bikol-Iriga)

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
