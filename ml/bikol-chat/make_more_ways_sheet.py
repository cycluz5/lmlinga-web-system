"""Make the 'more ways to say it' sheet: the team adds more user messages to every checked chat.

    python make_more_ways_sheet.py <file.xlsx>

More ways of saying each message = the 'pick the checked reply' model learns better.
The bot replies are NOT changed here (they are already checked).
"""
import sys
from pathlib import Path

import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill

from build_chat_pairs import CONVERSATIONS, as_list

TARGET = 8  # aim: about 8 ways per chat

path = Path(sys.argv[1])
wb = openpyxl.Workbook()

how = wb.active
how.title = "HOW TO"
for line in [
    "MORE WAYS TO SAY IT",
    "",
    "Why: the chatbot learns to pick the right checked reply. It needs MANY ways people may type",
    "the same thing. Today each chat has only 2-3. Aim for about 8 per chat.",
    "",
    "Sheet 'add ways': one row = one chat.",
    "   Column E shows the ways we already have.",
    "   Write NEW ways in column F, one per line (press Alt+Enter for a new line inside the cell).",
    "   The bot reply (column D) is already checked. Do not change it here.",
    "   Rows in yellow have the fewest ways. Start with them.",
    "   Orange rows are serious messages (no hope / hurt myself). Please add many ways for these.",
    "",
    "Write the way REAL people type, not only the perfect way:",
    "   short:            Musta?   Hi po   Salamat   Bye",
    "   with po / tabi:   Salamat po.   Salamat tabi.",
    "   mixed Tagalog/English:   Thank you po.   Okay lang ako.   Sino ka?",
    "   small typos / no punctuation:   kumusta ka   salamat2   hehe",
    "",
    "Example (chat 'thank you'):",
    "   Salamat po!  /  Thank you!  /  Salamat na maray tabi.  /  Ty  /  Salamat sa tabang mo.",
    "",
    "Spelling for Iriga words (same rules as before): ono, a (not an), ika = you, ako = I/me, onga,",
    "manongod, kalusugan, isi, anda, mamoya, naintindiyan, sadi, ulit, modto, ngowan.",
    "",
    "Sheet 'new chats': if you think of small talk that has NO chat yet, write it there",
    "(what the person says + what the bot should reply).",
]:
    how.append([line])
how["A1"].font = Font(bold=True, size=14)
how.column_dimensions["A"].width = 100

ws = wb.create_sheet("add ways")
head = ["#", "type", "meaning (English)", "bot reply (already checked)",
        "ways we already have", "NEW ways (one per line)", "have", "need"]
ws.append(head)
for c in ws[1]:
    c.font = Font(bold=True, color="FFFFFF")
    c.fill = PatternFill("solid", fgColor="2F5597")
    c.alignment = Alignment(wrap_text=True, vertical="center")
yellow = PatternFill("solid", fgColor="FFF2CC")
orange = PatternFill("solid", fgColor="F8CBAD")
green = PatternFill("solid", fgColor="E2EFDA")
total_need = 0
for cid, (cat, eng, users, replies) in enumerate(CONVERSATIONS, 1):
    need = max(TARGET - len(users), 0)
    total_need += need
    ws.append([cid, cat, eng, "\n".join(as_list(replies)), "\n".join(users), "", len(users), need])
    row = ws.max_row
    for c in ws[row]:
        c.alignment = Alignment(wrap_text=True, vertical="top")
    ws.cell(row, 6).fill = green
    if cat == "crisis":
        for col in (1, 2, 3, 4, 5, 7, 8):
            ws.cell(row, col).fill = orange
    elif len(users) <= 2:
        for col in (1, 2, 3, 7, 8):
            ws.cell(row, col).fill = yellow
for col, width in zip("ABCDEFGH", [5, 12, 22, 45, 32, 40, 7, 7]):
    ws.column_dimensions[col].width = width
ws.freeze_panes = "F2"

new = wb.create_sheet("new chats")
new.append(["type", "user says (one per line)", "bot reply"])
for c in new[1]:
    c.font = Font(bold=True)
for col, width in zip("ABC", [14, 40, 60]):
    new.column_dimensions[col].width = width

wb.active = 0
wb.save(path)
print(f"{len(CONVERSATIONS)} chats -> {path}  (about {total_need} new ways needed to reach {TARGET} each)")
