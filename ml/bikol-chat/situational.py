"""Situational chats: the app sends the real time/date, the model answers in Bikol-Iriga.

An LLM cannot know the time. So the app puts one context line in front of the user's message:

    [Oras: 8:00 PM | Aldow: Sabado | Petsa: Nobyembre 14, 2026]
    Ono nang oras?

and the model is trained to read it ("Ngowan, alas otso na kana gab'i.").
The SAME line format is built in PHP: app/Services/BikolSmallTalkService.php::contextLine().

Auto-drafted, CHECKED by the team (bikol_situational_REVIEW.xlsx, 2026-11-14). The linguist's fixes are applied to every
chat as rules: hapon->apon, "kana <part of day>"->"sa", panahon->panawon, Domingo->Dominggo, alas una->alas ona.

    python situational.py                 -> dataset/situational.csv
    python situational.py --sheet         -> also the review sheet
"""
import csv
import datetime as dt
import random
import sys
from pathlib import Path

HERE = Path(__file__).parent
SEED = 11

DAYS = ["Lunes", "Martes", "Miyerkoles", "Huwebes", "Biyernes", "Sabado", "Dominggo"]  # Monday = 0
MONTHS = ["Enero", "Pebrero", "Marso", "Abril", "Mayo", "Hunyo", "Hulyo", "Agosto",
          "Setyembre", "Oktubre", "Nobyembre", "Disyembre"]
HOUR_WORDS = {1: "ona", 2: "dos", 3: "tres", 4: "kuwatro", 5: "singko", 6: "sais", 7: "siyete",
              8: "otso", 9: "nuwebe", 10: "dies", 11: "onse", 12: "dose"}


def period(hour):
    """Part of the day in Iriga words (ramrag / modto / hapon / gab'i)."""
    if 5 <= hour < 12:
        return "ramrag"
    if 12 <= hour < 14:
        return "modto"
    if 14 <= hour < 18:
        return "apon"
    return "gab'i"


def context_line(now):
    h12 = now.hour % 12 or 12
    ampm = "AM" if now.hour < 12 else "PM"
    return (f"[Oras: {h12}:{now.minute:02d} {ampm} | Aldow: {DAYS[now.weekday()]} | "
            f"Petsa: {MONTHS[now.month - 1]} {now.day}, {now.year}]")


def time_text(now):
    h12 = now.hour % 12 or 12
    return (f"{'ala' if h12 == 1 else 'alas'} {HOUR_WORDS[h12]}") if now.minute == 0 else f"{h12}:{now.minute:02d}"


# ---- question wordings (Iriga, Tagalog, English: residents mix them) ----
Q_TIME = ["Onong oras?", "Onong oras ngowan?", "Pirang oras na?", "Anong oras na?",
          "Anong oras na ngayon?", "What time is it?", "What time is it now?", "Oras na ba?"]
Q_DAY = ["Ono ngowan na aldow?", "Ono na aldow ngowan?", "Anong araw ngayon?", "What day is it today?",
         "What day is it?"]
Q_DATE = ["Ono a petsa ngowan?", "Ono ana petsa ngowan na aldow?", "Anong petsa ngayon?",
          "What's the date today?", "What is today's date?"]
Q_MONTH = ["Ono a bulan ngowan?", "Anong buwan ngayon?", "What month is it?"]
Q_YEAR = ["Ono nang taon ngowan?", "Anong taon ngayon?", "What year is it?"]
Q_PERIOD = ["Aldow pa ba o gab'i na?", "Gab'i na ba?", "Ramrag pa ba?", "Is it already night?",
            "Is it morning or afternoon?"]
Q_WEEKEND = ["Sabado o Dominggo ba ngowan?", "Weekend ba ngowan?", "Is it the weekend?"]
Q_GREET = ["Kumusta?", "Kumusta tabi!", "Hello!", "Hi!", "Maray na aldow!"]


def reply_time(now):
    tt = time_text(now)
    return random.choice([f"Ngowan, {tt} na sa {period(now.hour)}.", f"{tt[0].upper()}{tt[1:]} na sa {period(now.hour)} ngowan."])


def reply_period(now, q):
    p = period(now.hour)
    return {"ramrag": "Ramrag pa ngowan.", "modto": "Modto na ngowan.",
            "apon": "Apon na ngowan.", "gab'i": "Gab'i na ngowan."}[p]


def reply_weekend(now):
    d = DAYS[now.weekday()]
    return f"Amo, {d} ngowan." if now.weekday() >= 5 else f"Boko pa, {d} ngowan."


def reply_greet(now):
    return f"Maray na {period(now.hour)}! Ako si LMLinga. Ono a maitatabang ko kanimo?"


def random_now(rng):
    base = dt.datetime(2026, 1, 1) + dt.timedelta(days=rng.randrange(0, 700))
    return base.replace(hour=rng.randrange(0, 24), minute=rng.choice([0, 0, 0, 5, 10, 15, 20, 30, 45, 50, 55, 59]))


# ---- hand-written chats (same reply whatever the time) ----
FIXED = [
    ("weather", ["Mainit ba ngowan?", "Maoran ba ngowan?", "Ono a panawon ngowan?", "How's the weather?",
                 "Uuran ba ngowan?"],
     "Diri ko isi ana panawon ngowan. Mas maray kin PAGASA a magsimbag kanimo."),
    ("not_know", ["Pirang bigas ana presyo?", "Siisay a presidente?", "Ono a pinakamaray na cellphone?",
                  "Ono a score ka basketball ngowan?"],
     "Pasensiya na, diri ko isi ana simbag kadi. Maitatabang ko kanimo manongod sa kalusugan."),
    ("health_redirect", ["Ono a bolong sa obo?", "Ono a gigibowon kin agko kalintora?", "Kolog ana ulo ko, ono a bolong?",
                         "Pano maiwasan ana dengue?"],
     "Manongod sa kalusugan, Mag onga ika sa topic (halimbawa: dengue, otos, kalintora). Sa verified na health information ko kukuon ana simbag."),
    ("thanks_time", ["Salamat sa oras!", "Salamat tabi!", "Thank you!"],
     "Uda onoman! Agko pa maitatabang ako kanimo?"),
]


def build():
    rng = random.Random(SEED)
    random.seed(SEED)
    rows = []
    cid = 0

    def add(cat, ctx, user, bot):
        rows.append({"conv_id": cid, "status": "reviewed", "category": cat, "context": ctx, "user": user, "bot": bot})

    def conv(cat, questions, reply_fn, n=14):
        nonlocal cid
        for _ in range(n):
            cid += 1
            now = random_now(rng)
            add(cat, context_line(now), rng.choice(questions), reply_fn(now))

    conv("time", Q_TIME, reply_time, 44)
    # a few exact 1 o'clock times so the model learns "ala ona" (singular "ala", not "alas")
    for hour in (1, 1, 13, 13, 1, 13):
        cid += 1
        now = random_now(rng).replace(hour=hour, minute=0)
        add("time", context_line(now), rng.choice(Q_TIME), reply_time(now))
    conv("day", Q_DAY, lambda n: f"Ngowan, {DAYS[n.weekday()]}.", 14)
    conv("date", Q_DATE, lambda n: f"Ngowan ana {DAYS[n.weekday()]}, {MONTHS[n.month - 1]} {n.day}, {n.year}.", 18)
    conv("month", Q_MONTH, lambda n: f"Ngowan na bulan, {MONTHS[n.month - 1]}.", 8)
    conv("year", Q_YEAR, lambda n: f"Ngowan na taon, {n.year}.", 6)
    conv("day_period", Q_PERIOD, lambda n: reply_period(n, ""), 12)
    conv("weekend", Q_WEEKEND, reply_weekend, 10)
    conv("greeting_by_time", Q_GREET, reply_greet, 16)
    for cat, questions, bot in FIXED:
        for q in questions:
            cid += 1
            add(cat, context_line(random_now(rng)), q, bot)
    return rows


def build_extra(n_time=420, n_day=40, n_date=60, n_period=30):
    """MORE time/day/date chats for v4b (same reviewed wording rules, different random dates and times).

    v4 saw about 50 time chats with few different minutes and sometimes answered "diri ko isi a oras" or left out
    the minutes. Here every hour of the day is covered, with many different minutes (not only :00, :15, :30).
    The question wordings and the reply patterns are the SAME ones the team already reviewed above.
    """
    rng = random.Random(SEED + 100)
    random.seed(SEED + 100)
    rows, cid = [], 1000   # conv_id 1000+ so they never clash with situational.csv

    def add(cat, now, user, bot):
        nonlocal cid
        cid += 1
        rows.append({"conv_id": cid, "status": "reviewed", "category": cat, "context": context_line(now),
                     "user": user, "bot": bot})

    def date_at(hour, minute):
        base = dt.datetime(2026, 1, 1) + dt.timedelta(days=rng.randrange(0, 730))
        return base.replace(hour=hour, minute=minute)

    # time: all 24 hours, round-robin; minutes: every 3rd is exactly :00, the rest anything 1-59
    for i in range(n_time):
        hour = i % 24
        minute = 0 if i % 3 == 0 else rng.randrange(1, 60)
        now = date_at(hour, minute)
        add("time", now, rng.choice(Q_TIME), reply_time(now))
    for i in range(n_day):
        now = date_at(rng.randrange(24), rng.randrange(60))
        add("day", now, rng.choice(Q_DAY), f"Ngowan, {DAYS[now.weekday()]}.")
    for i in range(n_date):
        now = date_at(rng.randrange(24), rng.randrange(60))
        add("date", now, rng.choice(Q_DATE), f"Ngowan ana {DAYS[now.weekday()]}, {MONTHS[now.month - 1]} {now.day}, {now.year}.")
    for i in range(n_period):
        now = date_at(rng.randrange(24), rng.randrange(60))
        add("day_period", now, rng.choice(Q_PERIOD), reply_period(now, ""))
    return rows


def write_extra():
    rows = build_extra()
    out = HERE / "dataset" / "situational_extra.csv"
    with open(out, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["conv_id", "status", "category", "context", "user", "bot"])
        w.writeheader()
        w.writerows(rows)
    print(f"{len(rows)} extra time/date chats -> {out}")


def main():
    if "--extra" in sys.argv:   # leaves the reviewed situational.csv untouched
        write_extra()
        return
    rows = build()
    out = HERE / "dataset" / "situational.csv"
    with open(out, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["conv_id", "status", "category", "context", "user", "bot"])
        w.writeheader()
        w.writerows(rows)
    print(f"{len(rows)} situational chats -> {out}")
    if "--sheet" in sys.argv:
        make_sheet(rows)


def make_sheet(rows):
    from openpyxl import Workbook
    from openpyxl.styles import Alignment, Font, PatternFill

    wb = Workbook()
    ws = wb.active
    ws.title = "HOW TO"
    for line in [
        "These are chats where LMLinga must use the REAL time/date. The app sends the 'context' line first.",
        "Tab 'chats to check': read 'bot reply (draft)'. If it is good Iriga, leave 'fixed reply' EMPTY. If not, write the correct reply in 'fixed reply'.",
        "Tab 'words to check': these words were GUESSED (clock, days, months, parts of the day). Write the correct Iriga word.",
        "The reply must keep the same time/day/date as the context line. Only the Iriga wording changes.",
        "Health answers do NOT come from here (they come from the verified health documents).",
    ]:
        ws.append([line])
    ws.column_dimensions["A"].width = 120

    seen, picks = set(), []
    for r in rows:
        key = (r["category"], r["bot"] if r["category"] not in ("time", "date", "day", "month", "year", "weekend", "day_period", "greeting_by_time") else r["category"])
        if key not in seen or r["category"] in ("time", "date", "greeting_by_time"):
            if r["category"] in ("time", "date", "greeting_by_time") and sum(1 for p in picks if p["category"] == r["category"]) >= 6:
                continue
            seen.add(key)
            picks.append(r)
    ws2 = wb.create_sheet("chats to check")
    ws2.append(["#", "category", "context sent by the app", "person says", "bot reply (draft)", "fixed reply (only if wrong)"])
    for i, r in enumerate(picks, 1):
        ws2.append([i, r["category"], r["context"], r["user"], r["bot"], ""])
    for col, wd in zip("ABCDEF", [5, 16, 52, 34, 60, 60]):
        ws2.column_dimensions[col].width = wd
    for cell in ws2[1]:
        cell.font = Font(bold=True)
    for row in ws2.iter_rows(min_row=2):
        for c in row:
            c.alignment = Alignment(wrap_text=True, vertical="top")
        row[5].fill = PatternFill("solid", fgColor="FFF2CC")

    ws3 = wb.create_sheet("words to check")
    ws3.append(["word / phrase", "meaning", "guessed word", "correct Iriga word (only if wrong)"])
    words = [("alas otso", "8 o'clock (Spanish numbers una...dose)", "alas una, dos, tres, kuwatro, singko, sais, siyete, otso, nuwebe, dies, onse, dose"),
             ("kana ramrag", "in the morning", "ramrag"), ("kana modto", "at noon", "modto"),
             ("kana hapon", "in the afternoon", "hapon  (GUESS)"), ("kana gab'i", "at night", "gab'i"),
             ("Lunes ... Domingo", "days of the week", "Lunes, Martes, Miyerkoles, Huwebes, Biyernes, Sabado, Domingo"),
             ("Enero ... Disyembre", "months", "Enero, Pebrero, Marso, Abril, Mayo, Hunyo, Hulyo, Agosto, Setyembre, Oktubre, Nobyembre, Disyembre"),
             ("Ngowan, ... na kana", "It is now ... of the", "Ngowan, alas otso na kana gab'i."),
             ("Amo / Boko pa", "Yes / Not yet", "Amo, Sabado ngowan. / Boko pa, Martes ngowan."),
             ("Ramrag pa / Gab'i na ngowan", "still morning / already night", "Gab'i na ngowan."),
             ("Pirang oras na?", "what time is it (person)", "Pirang oras na? / Ono nang oras?"),
             ("Diri ko isi ana panahon", "I don't know the weather", "Diri ko isi ana panawon ngowan. Mas maray kin PAGASA a magsimbag kanimo."),
             ("hapoton mo tabi ana topic", "please ask about the topic", "hapoton = ask (check)")]
    for w in words:
        ws3.append([w[0], w[1], w[2], ""])
    for col, wd in zip("ABCD", [28, 40, 80, 40]):
        ws3.column_dimensions[col].width = wd
    out = Path.home() / "Desktop" / "datasets" / "bikol_situational_REVIEW.xlsx"
    out.parent.mkdir(parents=True, exist_ok=True)
    wb.save(out)
    print("review sheet ->", out)


if __name__ == "__main__":
    main()
