"""Everyday conversation topics for the teacher (80 topics x situations). Health is NOT a topic: that is RAG's job.

Each topic has a few situations so the dialogs differ. The generator adds a random mood and opening style on top.
"""
import random

TOPICS = {
    "pagkain": ["nagugutom ang tao at nagtatanong kung ano ang masarap", "pinag-uusapan ang paboritong ulam", "nagluto ang tao ng pinakbet", "gustong kumain sa labas"],
    "meryenda": ["naghahanap ng meryenda", "nagkakape sa hapon", "bumili ng turon"],
    "palengke": ["pumunta ang tao sa palengke", "nagtatanong kung saan bibili ng gulay", "nakabili ng sariwang isda"],
    "pag-aaral": ["may pagsusulit bukas", "nahihirapan sa assignment", "bagong paaralan"],
    "trabaho": ["pagod pagkatapos ng trabaho", "bagong trabaho ang tao", "day off bukas"],
    "pamilya": ["bumisita ang lola", "umuwi ang tatay", "nagluluto ang nanay"],
    "kapitbahay": ["nag-ingay ang kapitbahay", "may handaan sa kapitbahay", "tinulungan ng kapitbahay"],
    "panahon": ["mainit ngayon", "umuulan", "malamig ang hangin", "may bagyo"],
    "libangan": ["mahilig magbasa", "gustong manood ng pelikula", "naglalaro sa selpon", "nagsasayaw"],
    "musika": ["paboritong kanta", "natutong tumugtog ng gitara", "nakikinig ng radyo"],
    "palakasan": ["naglalaro ng basketball", "nanonood ng laro", "nagjo-jogging"],
    "selpon": ["bagong selpon", "nasira ang selpon", "walang load"],
    "pista": ["may pista sa barangay", "naghahanda para sa pista", "nanood ng parada"],
    "pasko": ["malapit na ang Pasko", "nagpapalitan ng regalo", "simbang gabi"],
    "kaarawan": ["kaarawan ng tao", "kaarawan ng kaibigan", "naghahanda ng handaan"],
    "paglalakbay": ["pupunta sa Naga", "sumakay ng bus", "gustong mamasyal"],
    "pagod": ["pagod ang tao", "puyat ang tao", "gusto nang matulog"],
    "masaya": ["masaya dahil may magandang balita", "nakapasa sa pagsusulit", "natanggap sa trabaho"],
    "malungkot": ["malungkot ang tao", "nami-miss ang pamilya", "nag-away sila ng kaibigan"],
    "nag-aalala": ["kinakabahan sa presentasyon", "nag-aalala sa pera", "nag-aalala sa kaibigan"],
    "pagbati": ["magandang umaga", "magandang hapon", "magandang gabi", "simpleng kumusta"],
    "pagpapasalamat": ["nagpapasalamat ang tao", "tinulungan ng kaibigan"],
    "paalam": ["nagpapaalam ang tao", "matutulog na ang tao"],
    "pagpapakilala": ["nagpapakilala ang tao", "nagtatanong ng pangalan"],
    "tungkol-sa-bot": ["tinatanong kung sino ang bot", "tinatanong kung tao ba ang bot", "tinatanong kung ano ang kaya ng bot", "tinatanong kung paano nalaman ng bot ang oras"],
    "hindi-alam": ["tinatanong ang presyo ng bigas", "tinatanong kung sino ang pangulo", "tinatanong ang balita", "tinatanong ang resulta ng laro"],
    "biro": ["nagbibiro ang tao", "tumatawa ang tao", "nagpapahula ng bugtong"],
    "papuri": ["pinupuri ang bot", "pinasasalamatan ang magandang usapan"],
    "hindi-naintindihan": ["hindi maintindihan ng bot ang sinabi", "hindi maintindihan ng tao ang sagot"],
    "tanong-balik": ["ang bot ang nagtatanong tungkol sa araw ng tao", "ang bot ang nagtatanong ng paboritong pagkain"],
    "bahay": ["naglilinis ng bahay", "nag-aayos ng hardin", "nagpinta ng pader"],
    "hayop": ["may alagang aso", "may pusa", "may manok sa bakuran"],
    "halaman": ["nagtanim ng gulay", "namumulaklak ang halaman"],
    "damit": ["bibili ng bagong damit", "nag-lalaba"],
    "pera": ["kulang ang pera", "nag-iipon ang tao"],
    "transportasyon": ["naghihintay ng jeep", "traffic", "malayo ang biyahe"],
    "simbahan": ["pupunta sa misa", "pista ng santo"],
    "eskwela-ng-anak": ["may program sa paaralan ng anak", "bagong uniporme"],
    "kaibigan": ["makikipagkita sa kaibigan", "may bagong kaibigan"],
    "pagluluto": ["nagtatanong kung paano magluto ng adobo", "nagluluto ng sinigang"],
    "ilog-bundok": ["gustong pumunta sa bundok", "mamasyal sa ilog"],
    "lungsod-iriga": ["nakatira sa Iriga", "gustong pumunta sa plaza"],
    "gabi": ["hindi makatulog", "gising pa sa hatinggabi"],
    "umaga": ["maaga nagising", "mag-aalmusal"],
    "tag-init": ["sobrang init", "gustong maligo sa ilog"],
    "tag-ulan": ["maulan na naman", "walang kuryente dahil sa ulan"],
    "balita-ng-pamilya": ["ikinuwento ang nangyari sa araw", "may bisita sa bahay"],
    "pangarap": ["pangarap ng tao", "gustong maging guro"],
    "pasensya": ["humihingi ng paumanhin ang tao", "humihingi ng paumanhin ang bot"],
    "oo-hindi": ["maiikling sagot lang ang tao", "nagdadalawang-isip ang tao"],
    "pagkamausisa": ["nagtatanong kung bakit", "gustong matuto ng bagong salita"],
    "wika": ["nagtatanong tungkol sa Bikol-Iriga", "gustong matutong magsalita ng Bikol"],
    "kuryente-tubig": ["walang kuryente", "walang tubig sa gripo"],
    "tulong": ["humihingi ng tulong sa simpleng bagay", "nagtatanong kung ano ang magagawa ng bot"],
    "kwento": ["nagkukuwento tungkol sa kahapon", "nagkukuwento tungkol sa pagkabata"],
    "paboritong-bagay": ["paboritong kulay", "paboritong araw", "paboritong lugar"],
    "pag-ibig-kaibigan": ["may crush ang tao", "namimiss ang kaibigan"],
    "pagod-sa-eskwela": ["maraming gawain sa eskwela", "may proyekto"],
    "kasiyahan": ["may kasiyahan sa plaza", "may sayawan"],
    "bagong-taon": ["bagong taon", "mga plano sa bagong taon"],
    "araw-ng-linggo": ["Sabado ngayon at walang pasok", "Lunes na naman"],
    "pamamasyal": ["mamamasyal ngayong Linggo", "may bagong tindahan sa bayan"],
    "pagbabalik": ["nakabalik na ang tao mula sa biyahe", "umuwi galing Maynila"],
    "pangungulila": ["nasa malayo ang pamilya ng tao", "tumawag ang kapatid"],
    "pagsasama": ["gustong may kausap", "nag-iisa sa bahay"],
    "pasalubong": ["may pasalubong ang tao", "bumili ng pili"],
    "kagamitan": ["nawawala ang susi", "nasira ang payong"],
    "maikling-usapan": ["isang maikling palitan ng pagbati", "isang maikling pasasalamat"],
    "pagpapaliwanag": ["pinapaliwanag ng bot kung ano ang LMLinga", "tinatanong kung bakit health center ang bot"],
    "payo-pang-araw-araw": ["humihingi ng payo kung paano magpahinga", "nagtatanong kung paano maging masipag"],
    "pagkakaibigan-sa-bot": ["gusto ng tao na maging kaibigan ang bot", "nagpapasalamat dahil may kausap"],
    "tanghalian": ["oras ng tanghalian", "hindi pa nakakain"],
    "hapunan": ["ano ang ulam mamaya", "kumain na ba"],
    "pag-iisa": ["boring ang araw ng tao", "walang magawa ngayon"],
    "kaibigang-bago": ["nakilala ang bagong kaklase", "bagong kapitbahay"],
    "kasuotan-sa-pista": ["naghahanda ng damit para sa pista", "nagpapaganda para sa handaan"],
    "alagang-hayop-bot": ["tinatanong kung may alaga ang bot", "tinatanong ang paboritong hayop"],
    "kinabukasan": ["ano ang gagawin bukas", "plano sa katapusan ng linggo"],
}

MOODS = ["masaya", "pagod", "walang magawa", "mausisa", "kalmado", "nagmamadali", "mahiyain", "magiliw"]
OPENINGS = ["nagsisimula sa isang tanong", "nagsisimula sa isang pahayag", "nagsisimula sa pagbati", "nagsisimula sa isang maikling salita lang"]


def requests(per_topic=20, seed=11):
    """Yield (id, topic, situation, mood, opening, turns) for the generator."""
    rng = random.Random(seed)
    n = 0
    for topic, situations in TOPICS.items():
        for k in range(per_topic):
            n += 1
            yield (f"t{n:05d}", topic, situations[k % len(situations)], rng.choice(MOODS), rng.choice(OPENINGS),
                   rng.choice([2, 2, 3, 3, 4]))   # number of Person+LMLinga pairs
