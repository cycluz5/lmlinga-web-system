# Shared health question bank (Bikol-Iriga)

The same health questions are used for the three chatbot types (Context-based, Local AI model, Cloud AI).
The type decides HOW they are answered, not WHICH questions are asked.

| Column | Meaning |
|---|---|
| `intent` | the kind of question: definition, symptoms, causes, management, diagnosis, benefits, effects, schedule, warning, general |
| `question` | the wording (a reviewed pattern filled with the topic's Bikol name) |
| `ask_topic_first` | when set, ask this first so the topic is known, then the question (headings such as "Ono ana mga sinyales?") |
| `expected_source` / `also_accept` | the document that must answer (and sibling documents that may also answer) |

A document does not have a section for every question type. A "no information" reply is then correct; a made-up answer or an
answer from another document is not. Rebuild with `python ml/bikol-chat/make_test_questions_three_types.py`.
