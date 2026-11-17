<?php

/*
|--------------------------------------------------------------------------
| Other names people type for a health topic (Bikol-Iriga chatbot)
|--------------------------------------------------------------------------
|
| Key = the file name in resources/health_docs_v2 (without .txt).
| Value = single words a resident may type for that topic that are NOT
| already in the topic's title (the English and Tagalog titles of the same
| topic are added automatically).
|
| An entry may also be a PHRASE of several words ("atake sa poso"). A phrase
| only counts when the whole phrase is in the question; its separate words are
| NOT aliases (so "poso" or "ilang" alone never select the topic).
|
*/

return [
    'low_blood_pressure' => ['hypotension', 'lowblood', 'lowbp'],
    'hypertension' => ['highblood', 'highbp', 'altapresyon', 'altrapresyon', 'alangkaw na presyon', 'alangkaw na presyon kana rogo'],
    'high_blood_pressure' => ['highblood', 'highbp', 'altapresyon', 'altrapresyon', 'alangkaw na presyon', 'alangkaw na presyon kana rogo'],
    'chickenpox' => ['otos', 'bulutong', 'bulutung'],
    'diarrhea' => ['pagtatae', 'lbm', 'nag-uudo-udo', 'disenterya', 'pag udo-udo'],
    'diarrhea_2' => ['pagtatae', 'lbm', 'nag-uudo-udo', 'disenterya', 'pag udo-udo'],
    // names from the team's reviewed topic list (the documents' own titles are worded differently)
    'baby_is_a_champion' => ['pag-aataman ka igin'],
    'save_your_baby_mental_retardation' => ['kapansanan sa pag-iisip ka igin'],
    'micronutrient_deficiency' => ['kakulangan sa micronutrient'],
    'fever' => ['lagnat', 'kalintura', 'kalintora'],
    'asthma' => ['hika'],
    'cough' => ['ubo', 'obo'],
    'tetanus' => ['tetano'],
    'constipation' => ['tibi'],
    'influenza' => ['trangkaso', 'flu'],
    'pneumonia' => ['pulmonya'],
    'tuberculosis_proper_coughing' => ['tb', 'tuberkulosis', 'tuberculosis'],
    // Verified Bikol-Iriga words (ml/bikol-intent/vocabulary_guide.csv)
    'gout' => ['rayuma'],
    'cholera' => ['kolera'],
    'deafness' => ['pagkabongog'],
    'dementia' => ['pagkalilipaton'],
    'deworming' => ['purga', 'pagpupurga', 'olod', 'bulate'],
    'common_cold' => ['sipon', "sip'on"],
    'runny_nose' => ['sipon', "sip'on"],
    'nausea_vomiting' => ['isinoka', 'pagsusuka'],
    'rashes' => ['pantal'],
    'smoking' => ['paninigarilyo'],
    'epilepsy' => ['epilepsiya', 'sobisobi', 'kombulsyon'],
    'hiv_and_aids' => ['aids'],
    // Phrases (several words) from vocabulary_guide.csv
    'heart_attack' => ['atake sa poso'],
    'low_back_pain' => ['kolog sa likod'],
    'chronic_kidney_disease' => ['ilang sa bato'],
    'headache' => ['kulog kan payo', 'maklog na payo'],
    'foodborne_illness' => ['nailo sa pagkaon'],
    'blood_donation' => ['pagtao kan rogo', 'pagtao sa rogo'],
    'pre_term_birth' => ['amay na pangingigin'],
    'menopause' => ['tapos na a regla', 'tapos na an regla', 'tapos na an pagregla'],
    'breastfeeding_tips' => [
        'breastfeeding', 'pagpapasuso', 'pagpapasoso', 'magpasoso', 'magpasuso',
        'nagpapasoso', 'nursing',
    ],
];
