<?php

/*
|--------------------------------------------------------------------------
| Other names people type for a health topic (Bikol-Iriga chatbot)
|--------------------------------------------------------------------------
|
| Key = the file name in resources/health_docs_v2 (without .txt).
| Value = single words a resident may type for that topic that are NOT
| already in the topic's title (the English and Tagalog titles of the same
| topic are added automatically). One word per entry, no spaces.
|
*/

return [
    'low_blood_pressure' => ['hypotension', 'lowblood', 'lowbp'],
    'hypertension' => ['highblood', 'highbp', 'altapresyon', 'altrapresyon'],
    'high_blood_pressure' => ['highblood', 'highbp', 'altapresyon', 'altrapresyon'],
    'chickenpox' => ['otos', 'bulutong', 'bulutung'],
    'diarrhea' => ['pagtatae', 'lbm', 'nag-uudo-udo'],
    'diarrhea_2' => ['pagtatae', 'lbm', 'nag-uudo-udo'],
    'fever' => ['lagnat', 'kalintura', 'kalintora'],
    'breastfeeding_tips' => [
        'breastfeeding', 'pagpapasuso', 'pagpapasoso', 'magpasoso', 'magpasuso',
        'nagpapasoso', 'nursing',
    ],
];
