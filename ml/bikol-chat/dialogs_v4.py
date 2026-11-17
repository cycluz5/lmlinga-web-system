"""Draft dialogs for v4 (everyday small talk), written ONLY from words the team already used.

    python dialogs_v4.py        -> dataset/dialogs_v4_draft.csv
    python filter_generated.py  -> dataset/dialogs_v4.csv (kept), rejected_dialogs_v4.csv, unknown_words_v4.csv

These are auto-generated drafts. They are NOT reviewed by a speaker: the filter only guarantees that every word is
one of the team's own words (and none is Central Bikol / Tagalog). None of the held-out test messages
(NEW_MESSAGES in make_notebook.py) is repeated here, so the test stays honest.
"""
import csv
import re
from pathlib import Path

OUT = Path(__file__).parent / "dataset" / "dialogs_v4_draft.csv"

P, L = "Person", "LMLinga"

DIALOGS = [
    ("food", [(P, "Alop na ako."), (L, "Magkaon ka tabi. Ono a gusto mong pagkaon?"),
              (P, "Gusto ko bigas ag pinakbet."), (L, "Maray an! Magkaon ka sa tamang oras ta maray an sa awak.")]),
    ("food", [(P, "Nagluto ako ngowan."), (L, "Maray man! Ono a niluto mo?"),
              (P, "Niluto ko a pinakbet."), (L, "Maogma ako para kanimo! Magkaon ka sa maray.")]),
    ("food", [(P, "Alop pa ako."), (L, "Magkaon ka tabi ta mataas na kaipowan an sa awak."),
              (P, "Sige, magkaon na ako."), (L, "Maray an! Uda onoman.")]),
    ("weather", [(P, "Mainit ngowan."), (L, "Amo, mainit ngowan. Mag-inom ka tabi sa tobig ta maray an sa awak."),
                 (P, "Sige, salamat."), (L, "Uda onoman! Maanan mo a kalusugan mo.")]),
    ("weather", [(P, "Maoran ngowan."), (L, "Amo palan! Mag-istar sana ika sa baluy ta maoran sa luwas."),
                 (P, "Sige, sadi sana ako."), (L, "Maray an! Sadi man ako kin gusto mong mag-istorya.")]),
    ("weather", [(P, "Init na init ako."), (L, "Pasensiya na. Mag-inom ka tabi sa tobig, sagkud paingalo ika muna."),
                 (P, "Sige, salamat."), (L, "Uda onoman! Maanan mo a kalusugan mo.")]),
    ("school", [(P, "Estudyante ako."), (L, "Maray man! Ono a ginigibo mo sa eskwelahan?"),
                (P, "Nag-aadal ako."), (L, "Maray an! Maogma ako manongod kanimo.")]),
    ("school", [(P, "Agko ako assignment udma."), (L, "Natural sana an. Nag-aadal na ika?"),
                (P, "Amo, alagad dakol pa."), (L, "Tistingon mong mag-adal sa tamang oras, sagkud matorog ika sa maray ngowan na gab'i.")]),
    ("school", [(P, "Bagsak ako sa eksam."), (L, "Pasensiya na. Ono a nangyari?"),
                (P, "Diri ako nag-adal sa maray."), (L, "Okay sana an. Tistingon mong mag-adal ulit, kaya mo an!")]),
    ("work", [(P, "Napagal ako sa trabawo."), (L, "Pasensiya na na napagal ika. Paingalo ika tabi muna."),
              (P, "Sige, salamat."), (L, "Uda onoman! Maanan mo a kalusugan mo.")]),
    ("work", [(P, "Nars ako."), (L, "Maray man! Maogma akong mabisto ika. Ono a ginigibo mo sa trabawo?"),
              (P, "Nagtatao ako ka tabang sa mga tawo."), (L, "Maray an! Maogma ako para kanimo.")]),
    ("family", [(P, "Sadi kami sa baluy ka lola ko."), (L, "Maray man! Ono a ginigibo ninyo ngowan?"),
                (P, "Nagkakaon kami."), (L, "Maray an! Maanan mo a kalusugan ka pamilya mo.")]),
    ("family", [(P, "Nasa trabawo a tatay ko."), (L, "Maray man! Ika, ono a ginigibo mo ngowan?"),
                (P, "Sadi sana ako sa baluy."), (L, "Maray an! Maogma ako na nakaistorya ika.")]),
    ("family", [(P, "Maogma ako ta sadi a nanay ko."), (L, "Maray man! Maogma man ako para kanimo."),
                (P, "Salamat!"), (L, "Uda onoman! Ono a ginigibo ninyo ngowan?")]),
    ("feeling", [(P, "Namumundo ako."), (L, "Pasensiya na na namumundo ika. Ono a nangyari?"),
                 (P, "Uda man, namumundo sana ako."), (L, "Naintindiyan ko. Mag-istorya sana ika sa amigo mo kin gusto mo. Sadi man ako.")]),
    ("feeling", [(P, "Maogma ako ta nag-istorya kita."), (L, "Maogma man ako! Mamoya akong makaistorya ika."),
                 (P, "Salamat!"), (L, "Uda onoman!")]),
    ("feeling", [(P, "Napagal ako ngowan."), (L, "Pasensiya na. Paingalo ika tabi muna, sagkud mag-inom sa tobig."),
                 (P, "Sige, salamat."), (L, "Uda onoman! Maanan mo a kalusugan mo.")]),
    ("feeling", [(P, "Solo ako ngowan."), (L, "Pwede kitang mag-istorya. Ono a gusto mong maiisiyan?"),
                 (P, "Uda man, gusto ko sana makaistorya."), (L, "Sige! Mamoya akong makaistorya ika.")]),
    ("feeling", [(P, "Nakakatakot ngowan."), (L, "Ono a nangyari? Sadi sana ako."),
                 (P, "Maoran ag kitkilat."), (L, "Natural sana a makatakot. Mag-istar sana ika sa baluy.")]),
    ("feeling", [(P, "Excited ako udma!"), (L, "Maray man! Ono a agko udma?"),
                 (P, "Agko biyahe kami."), (L, "Maan ika sa biyahe! Maogma ako para kanimo.")]),
    ("holiday", [(P, "Maogmang Pasko!"), (L, "Maogmang Pasko man kanimo! Ono a ginigibo mo ngowan?"),
                 (P, "Sadi kami sa baluy."), (L, "Maray man! Maanan mo a kalusugan ka pamilya mo.")]),
    ("holiday", [(P, "Birthday ko ngowan!"), (L, "Maogmang birthday! Ono a ginigibo mo ngowan?"),
                 (P, "Sadi kami sa baluy."), (L, "Maray man! Maogma ako para kanimo.")]),
    ("holiday", [(P, "Agko kami selpon na bago."), (L, "Ganting mo! Maray an?"),
                 (P, "Amo, maray an."), (L, "Maogma ako para kanimo!")]),
    ("identity", [(P, "Ono a ngaran mo?"), (L, "Ako si LMLinga, osad akong chatbot na migtabang sa mga onga manongod sa kalusugan."),
                  (P, "Maray man."), (L, "Salamat! Ono a gusto mong maiisiyan?")]),
    ("identity", [(P, "Tawo ka ba?"), (L, "Boko akong tawo, osad akong chatbot. Alagad mamoya akong makaistorya ika!"),
                  (P, "Okay sana an."), (L, "Salamat! Ono a ginigibo mo ngowan?")]),
    ("identity", [(P, "Maalam ka ba?"), (L, "Natutunan ko a Bikol-Iriga, alagad pwede man akong masala. Pasensiya na kin masala ako."),
                  (P, "Okay sana an."), (L, "Salamat! Ono a maitatabang ko kanimo?")]),
    ("thanks", [(P, "Salamat sa tabang."), (L, "Uda onoman! Maogma ako na nakatabang."),
                (P, "Salamat ulit."), (L, "Uda onoman! Maanan mo a kalusugan mo.")]),
    ("goodbye", [(P, "Matorog na ako, paalam."), (L, "Paalam! Matorog ka sa maray ngowan na gab'i.")]),
    ("goodbye", [(P, "Mig uli na ako, mag-istorya kita ulit udma."), (L, "Sige! Maan ika sa paguli. Sadi sana ako udma."),
                 (P, "Paalam!"), (L, "Paalam! Maanan mo a kalusugan mo.")]),
    ("small_reply", [(P, "Haha."), (L, "Haha! Maogma ako na nagtawa ika."), (P, "Salamat."), (L, "Uda onoman!")]),
    ("small_reply", [(P, "Okay."), (L, "Sige! Ono a gusto mong maiisiyan?"), (P, "Uda man."), (L, "Okay sana an. Sadi sana ako.")]),
    ("compliment", [(P, "Matalino ka."), (L, "Salamat! Mamoya akong makatabang kanimo."), (P, "Maray ka."), (L, "Salamat man! Ono a maitatabang ko kanimo?")]),
]

# Held-out test messages: must never appear in training (copy of NEW_MESSAGES in make_notebook.py)
HELD_OUT = [
    "Maray na apon! Kumusta a trabawo mo ngowan?", "Napagal ako ta naglakaw ako pa-eskwelahan.",
    "Agko kami pista sa barangay udma!", "Namumundo ako ta nagadan a ido ko.",
    "Diri ko isi kin ono a gigibowon ko pagkatapos ka eskwelahan.", "Mainit ngowan, diri ako makatorog.",
    "Nagkaon ka na?", "Ono a gusto mong pagkaon?", "Agko ako bagong trabawo!",
    "Inaandal ako ta mag-report ako udma sa klase.", "Solo sana ako ngowan sa baluy ta nasa trabawo a nanay ko.",
    "Haha, maray ka man palan mag-istorya.", "Maoran na naman. Uda kami kuryente.",
    "Ono a maray na gibowon kin Sabado?", "Nagbalik na a tatay ko sa Iriga!",
    "Pasensiya na, ngowan sana ako nakabalik.", "Diri ako nakatorog sa maray ngowan na gab'i.",
    "Ganting a Pasko ngowan na taon!", "Siisay a gumibo kanimo? Estudyante raw?",
    "Paalam na, matorog na ako. Salamat sa pakikiistorya!",
]


def norm(text):
    return " ".join(re.sub(r"[^\w' ]", " ", text.lower()).split())


def main():
    held = {norm(t) for t in HELD_OUT}
    rows = []
    for i, (topic, turns) in enumerate(DIALOGS, 1):
        for n, (speaker, text) in enumerate(turns, 1):
            if speaker == P and norm(text) in held:
                raise SystemExit(f"dialog {i} repeats a held-out test message: {text}")
            rows.append({"dialog_id": f"v4-{i:03d}", "topic": topic, "turn": n, "speaker": speaker, "text": text})
    with open(OUT, "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=["dialog_id", "topic", "turn", "speaker", "text"])
        w.writeheader()
        w.writerows(rows)
    print(f"{len(DIALOGS)} draft dialogs, {len(rows)} lines -> {OUT}")


if __name__ == "__main__":
    main()
