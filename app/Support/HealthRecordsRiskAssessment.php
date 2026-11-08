<?php

namespace App\Support;

use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Barangay-wide Risk Assessment summary (Health Records → Risk Assessment).
 *
 * Listing rows and summary counts come only from persisted risk assessment records.
 */
final class HealthRecordsRiskAssessment
{
    public const MIN_AGE_YEARS = 19;

    public const EMPTY = HealthRecordsClinicalListing::EMPTY;

    /**
     * @return list<string>
     */
    public static function zones(): array
    {
        return HouseholdZoneResolver::DISPLAY_ZONES;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        if (Schema::hasTable('risk_assessments')) {
            return self::rowsFromLegacyTable();
        }

        if (Schema::hasTable('risk_assessment')) {
            return self::rowsFromErdTable();
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array{
     *     this_year: int,
     *     this_month: int,
     *     this_year_label: string,
     *     this_month_label: string
     * }
     */
    public static function summaryCounts(?array $rows = null): array
    {
        $rows ??= self::rows();
        $now = now()->timezone((string) config('app.timezone'));
        $currentYear = $now->format('Y');
        $currentMonth = $now->format('m');
        $thisYear = 0;
        $thisMonth = 0;

        foreach ($rows as $row) {
            $year = (string) ($row['year'] ?? '');
            $month = (string) ($row['month'] ?? '');

            if ($year === $currentYear) {
                $thisYear++;
                if ($month === $currentMonth) {
                    $thisMonth++;
                }
            }
        }

        return [
            'this_year' => $thisYear,
            'this_month' => $thisMonth,
            'this_year_label' => $currentYear,
            'this_month_label' => $now->format('F Y'),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function monthOptions(): array
    {
        return [
            ['value' => '01', 'label' => 'January'],
            ['value' => '02', 'label' => 'February'],
            ['value' => '03', 'label' => 'March'],
            ['value' => '04', 'label' => 'April'],
            ['value' => '05', 'label' => 'May'],
            ['value' => '06', 'label' => 'June'],
            ['value' => '07', 'label' => 'July'],
            ['value' => '08', 'label' => 'August'],
            ['value' => '09', 'label' => 'September'],
            ['value' => '10', 'label' => 'October'],
            ['value' => '11', 'label' => 'November'],
            ['value' => '12', 'label' => 'December'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return list<string>
     */
    public static function years(?array $rows = null): array
    {
        $rows ??= self::rows();
        $years = [];

        foreach ($rows as $row) {
            $year = trim((string) ($row['year'] ?? ''));
            if ($year !== '') {
                $years[$year] = true;
            }
        }

        $list = array_map('strval', array_keys($years));
        rsort($list, SORT_NUMERIC);

        return $list;
    }

    /**
     * Zone/year/month-filtered Risk Assessment rows — the Risk Assessment
     * Report Builder's data source.
     *
     * @return list<array<string, mixed>>
     */
    public static function exportRows(?string $zone = null, ?string $year = null, ?string $month = null): array
    {
        return array_values(array_filter(
            self::rows(),
            static function (array $row) use ($zone, $year, $month): bool {
                if ($zone !== null && trim((string) ($row['zone'] ?? '')) !== $zone) {
                    return false;
                }
                if ($year !== null && trim((string) ($row['year'] ?? '')) !== $year) {
                    return false;
                }
                if ($month !== null && trim((string) ($row['month'] ?? '')) !== $month) {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * "All Time" / "Year 2026" / "September 2026" — depends on which of
     * the year/month filters are active. Used for the report's summary
     * period label.
     */
    public static function periodLabel(string $year, string $month): string
    {
        $year = trim($year);
        $month = trim($month);

        if ($year === '' || $year === 'all') {
            return 'All Time';
        }

        if ($month !== '' && $month !== 'all') {
            return self::monthLabel($month).' '.$year;
        }

        return 'Year '.$year;
    }

    private static function monthLabel(string $month): string
    {
        foreach (self::monthOptions() as $option) {
            if ($option['value'] === $month) {
                return $option['label'];
            }
        }

        return $month;
    }

    /**
     * Fixed pixel widths (not proportional weights) — see
     * HealthRecordsMaternal::SECTIONS for why. self::columnPages() decides
     * how many print pages the full field set needs.
     *
     * @var list<array{key: string, title: string, fields: list<array{key: string, label: string, width: float}>}>
     */
    private const SECTIONS = [
        [
            'key' => 'base',
            'title' => 'CLIENT',
            'fields' => [
                ['key' => 'household_no', 'label' => 'Household No', 'width' => 85.0],
                ['key' => 'full_name', 'label' => 'Name', 'width' => 150.0],
                ['key' => 'age', 'label' => 'Age', 'width' => 40.0],
                ['key' => 'birthday', 'label' => 'Birthday', 'width' => 75.0],
                ['key' => 'date_assessed', 'label' => 'Date Assessed', 'width' => 85.0],
            ],
        ],
        [
            'key' => 'red_flags',
            'title' => 'RED FLAG ASSESSMENT',
            'fields' => [
                ['key' => 'rf_chest_pain', 'label' => 'Chest Pain', 'width' => 55.0],
                ['key' => 'rf_difficulty_breathing', 'label' => 'Difficulty Breathing', 'width' => 60.0],
                ['key' => 'rf_loss_of_consciousness', 'label' => 'Loss of Consciousness', 'width' => 60.0],
                ['key' => 'rf_slurred_speech', 'label' => 'Slurred Speech', 'width' => 55.0],
                ['key' => 'rf_facial_asymmetry', 'label' => 'Facial Asymmetry', 'width' => 55.0],
                ['key' => 'rf_disoriented', 'label' => 'Disoriented (Time/Place/Person)', 'width' => 65.0],
                ['key' => 'rf_chest_retractions', 'label' => 'Chest Retractions', 'width' => 55.0],
                ['key' => 'rf_seizure', 'label' => 'Seizure/Convulsion', 'width' => 55.0],
                ['key' => 'rf_self_harm', 'label' => 'Self-Harm/Suicide', 'width' => 55.0],
                ['key' => 'rf_agitated', 'label' => 'Agitated/Aggressive', 'width' => 55.0],
                ['key' => 'rf_eye_injury', 'label' => 'Eye Injury/Foreign Body', 'width' => 60.0],
                ['key' => 'rf_severe_injuries', 'label' => 'Severe Injuries', 'width' => 55.0],
                ['key' => 'rf_weakness_numbness', 'label' => 'Weakness/Numbness (One Side)', 'width' => 65.0],
                ['key' => 'rf_none', 'label' => 'None of the Above', 'width' => 55.0],
            ],
        ],
        [
            'key' => 'past_medical',
            'title' => 'PAST MEDICAL HISTORY',
            'fields' => [
                ['key' => 'pmh_hypertension', 'label' => 'Hypertension', 'width' => 55.0],
                ['key' => 'pmh_heart_diseases', 'label' => 'Heart Diseases', 'width' => 55.0],
                ['key' => 'pmh_diabetes', 'label' => 'Diabetes', 'width' => 50.0],
                ['key' => 'pmh_cancer', 'label' => 'Cancer', 'width' => 50.0],
                ['key' => 'pmh_copd', 'label' => 'COPD', 'width' => 50.0],
                ['key' => 'pmh_asthma', 'label' => 'Asthma', 'width' => 50.0],
                ['key' => 'pmh_mental_neuro_substance', 'label' => 'Mental/Neuro/Substance Disorder', 'width' => 65.0],
                ['key' => 'pmh_vision_problems', 'label' => 'Vision Problems', 'width' => 55.0],
                ['key' => 'pmh_previous_surgical', 'label' => 'Previous Surgical History', 'width' => 60.0],
                ['key' => 'pmh_thyroid', 'label' => 'Thyroid Disorder', 'width' => 55.0],
                ['key' => 'pmh_kidney', 'label' => 'Kidney Disorder', 'width' => 55.0],
                ['key' => 'pmh_allergies', 'label' => 'Allergies', 'width' => 50.0],
                ['key' => 'pmh_none', 'label' => 'None of the Above', 'width' => 55.0],
            ],
        ],
        [
            'key' => 'family_history',
            'title' => 'FAMILY HISTORY',
            'fields' => [
                ['key' => 'fh_hypertension', 'label' => 'Hypertension', 'width' => 55.0],
                ['key' => 'fh_stroke', 'label' => 'Stroke', 'width' => 50.0],
                ['key' => 'fh_isch_heart_disease', 'label' => 'Isch. Heart Disease', 'width' => 60.0],
                ['key' => 'fh_diabetes_mellitus', 'label' => 'Diabetes Mellitus', 'width' => 55.0],
                ['key' => 'fh_asthma', 'label' => 'Asthma', 'width' => 50.0],
                ['key' => 'fh_cancer', 'label' => 'Cancer', 'width' => 50.0],
                ['key' => 'fh_kidney_disease', 'label' => 'Kidney Disease', 'width' => 55.0],
                ['key' => 'fh_premature_heart_vascular', 'label' => 'Premature Heart/Vascular (1st-Deg)', 'width' => 65.0],
                ['key' => 'fh_family_tb', 'label' => 'Family TB (5yr)', 'width' => 55.0],
                ['key' => 'fh_mental_neuro_substance', 'label' => 'Mental/Neuro/Substance Disorder', 'width' => 65.0],
                ['key' => 'fh_copd', 'label' => 'COPD', 'width' => 50.0],
                ['key' => 'fh_none', 'label' => 'None of the Above', 'width' => 55.0],
            ],
        ],
        [
            'key' => 'lifestyle',
            'title' => 'LIFESTYLE & RISK FACTOR',
            'fields' => [
                ['key' => 'lifestyle_tobacco', 'label' => 'Tobacco/Vape Usage', 'width' => 100.0],
                ['key' => 'lifestyle_alcohol', 'label' => 'Alcohol Intake', 'width' => 100.0],
                ['key' => 'lifestyle_dietary', 'label' => 'Dietary Habits', 'width' => 110.0],
                ['key' => 'lifestyle_physical_activity', 'label' => 'Physical Activity', 'width' => 110.0],
            ],
        ],
        [
            'key' => 'physical',
            'title' => 'PHYSICAL MEASUREMENT AND CLINICAL SCREENING',
            'fields' => [
                ['key' => 'phys_height', 'label' => 'Height (cm)', 'width' => 65.0],
                ['key' => 'phys_weight', 'label' => 'Weight (kg)', 'width' => 65.0],
                ['key' => 'phys_bmi', 'label' => 'BMI', 'width' => 55.0],
                ['key' => 'phys_waist', 'label' => 'Waist (cm)', 'width' => 65.0],
                ['key' => 'phys_blood_pressure', 'label' => 'Blood Pressure', 'width' => 75.0],
                ['key' => 'phys_bp_status', 'label' => 'BP Status', 'width' => 75.0],
                ['key' => 'visual_no_screening_past_year', 'label' => 'No Screening Past Year', 'width' => 65.0],
                ['key' => 'visual_has_blurred_vision', 'label' => 'Has Blurred Vision', 'width' => 65.0],
                ['key' => 'visual_blurred_vision_details', 'label' => 'Blurred Vision Details', 'width' => 130.0],
            ],
        ],
    ];

    /** Matches RiskAssessmentPdf::PAGE_WIDTH minus its left+right margins. */
    private const PAGE_CONTENT_WIDTH = 928.0;

    /**
     * The group/field section definitions, exposed so the Report Builder's
     * live preview can render the same grouped-column table shape as the
     * PDF (RiskAssessmentPdf).
     *
     * @return list<array{key: string, title: string, fields: list<array{key: string, label: string, width: float}>}>
     */
    public static function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * Fixed topic pages, in this order: Client & Red Flag Assessment
     * (combined — see below), Past Medical History, Family History,
     * Lifestyle & Risk Factor, Physical Measurement and Clinical
     * Screening. Page 1 carries the full Client group (Household No/Name/
     * Age/Birthday/Date Assessed) plus as many Red Flag Assessment fields
     * as fit in the remaining page width, so that width is put to use
     * instead of sitting blank; any Red Flag fields that don't fit
     * continue on Name-only anchor pages. From page 2 onward only Name
     * repeats as the identifying anchor column. A topic only splits into
     * further "PART x of y" pages when its own fields don't fit one page
     * width.
     *
     * @return list<array{sections: list<array{key: string, title: string, fields: list<array{key: string, label: string, width: float}>}>, groupTitle: string, part: int, partsInGroup: int}>
     */
    public static function columnPages(): array
    {
        [$baseSection, $redFlagSection] = self::SECTIONS;
        $topicSections = array_slice(self::SECTIONS, 2);

        $nameField = $baseSection['fields'][1];
        $nameOnlySection = ['key' => 'base', 'title' => 'CLIENT', 'fields' => [$nameField]];
        $available = self::PAGE_CONTENT_WIDTH - $nameField['width'];

        $baseWidth = array_sum(array_column($baseSection['fields'], 'width'));
        $page1Available = self::PAGE_CONTENT_WIDTH - $baseWidth;

        $redFlagGroups = self::packFirstPageThenAnchor($redFlagSection['fields'], $page1Available, $available);
        $redFlagPartsTotal = count($redFlagGroups);

        $pages = [
            [
                'sections' => [
                    $baseSection,
                    ['key' => $redFlagSection['key'], 'title' => $redFlagSection['title'], 'fields' => $redFlagGroups[0]],
                ],
                'groupTitle' => 'CLIENT & '.$redFlagSection['title'],
                'part' => 1,
                'partsInGroup' => $redFlagPartsTotal,
            ],
        ];

        foreach (array_slice($redFlagGroups, 1) as $index => $fields) {
            $pages[] = [
                'sections' => [
                    $nameOnlySection,
                    ['key' => $redFlagSection['key'], 'title' => $redFlagSection['title'], 'fields' => $fields],
                ],
                'groupTitle' => $redFlagSection['title'],
                'part' => $index + 2,
                'partsInGroup' => $redFlagPartsTotal,
            ];
        }

        foreach ($topicSections as $section) {
            $groups = self::packFieldsIntoPages($section['fields'], $available);
            $partsInGroup = count($groups);

            foreach ($groups as $index => $fields) {
                $pages[] = [
                    'sections' => [
                        $nameOnlySection,
                        ['key' => $section['key'], 'title' => $section['title'], 'fields' => $fields],
                    ],
                    'groupTitle' => $section['title'],
                    'part' => $index + 1,
                    'partsInGroup' => $partsInGroup,
                ];
            }
        }

        return $pages;
    }

    /**
     * Fills the first group up to $firstPageAvailable (the width left over
     * on the combined Client + Red Flag page), then packs whatever didn't
     * fit into further groups sized for the wider Name-only anchor pages.
     *
     * @param  list<array{key: string, label: string, width: float}>  $fields
     * @return list<list<array{key: string, label: string, width: float}>>
     */
    private static function packFirstPageThenAnchor(array $fields, float $firstPageAvailable, float $anchorAvailable): array
    {
        $first = [];
        $firstWidth = 0.0;
        $consumed = 0;

        foreach ($fields as $field) {
            if ($firstWidth + $field['width'] > $firstPageAvailable && $first !== []) {
                break;
            }

            $first[] = $field;
            $firstWidth += $field['width'];
            $consumed++;
        }

        $remaining = array_slice($fields, $consumed);
        $restGroups = $remaining === [] ? [] : self::packFieldsIntoPages($remaining, $anchorAvailable);

        return array_merge([$first], $restGroups);
    }

    /**
     * @param  list<array{key: string, label: string, width: float}>  $fields
     * @return list<list<array{key: string, label: string, width: float}>>
     */
    private static function packFieldsIntoPages(array $fields, float $available): array
    {
        $pages = [];
        $current = [];
        $currentWidth = 0.0;

        foreach ($fields as $field) {
            if ($currentWidth + $field['width'] > $available && $current !== []) {
                $pages[] = $current;
                $current = [];
                $currentWidth = 0.0;
            }

            $current[] = $field;
            $currentWidth += $field['width'];
        }

        if ($current !== []) {
            $pages[] = $current;
        }

        return $pages === [] ? [[]] : $pages;
    }

    /**
     * Zone/year/month-filtered Risk Assessment rows, each enriched with
     * every Red Flag Assessment / Past Medical History / Family History /
     * Lifestyle & Risk Factor / Physical Measurement and Clinical
     * Screening field as its own column — always present, blank (not
     * '—') when not recorded. Detail is pulled through
     * RiskAssessmentService::findPresentationForResident() — the same
     * service the Household Profiling member Risk Assessment page uses —
     * so legacy (risk_assessments JSON columns) and ERD
     * (risk_assessment + red_flags_assessment/past_medical_history/
     * family_history) both resolve to the same UI-key shape.
     *
     * @return list<array<string, mixed>>
     */
    public static function detailedExportRows(?string $zone = null, ?string $year = null, ?string $month = null): array
    {
        return array_map(
            static fn (array $row): array => self::withFlatDetail($row),
            self::exportRows($zone, $year, $month)
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withFlatDetail(array $row): array
    {
        $detail = self::presentationForRow($row);

        // Left operand wins on key collisions — the row's own base fields
        // (household_no/full_name/age/birthday/date_assessed) are computed
        // independently of the presentation detail, so there is no overlap
        // to worry about; $row is still appended last for safety.
        return self::redFlagFields($detail)
            + self::pastMedicalFields($detail)
            + self::familyHistoryFields($detail)
            + self::lifestyleFields($detail)
            + self::physicalClinicalFields($row, $detail)
            + $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function presentationForRow(array $row): array
    {
        $resident = Resident::find($row['_resident_id'] ?? 0);
        $assessmentNo = trim((string) ($row['_assessment_no'] ?? ''));
        if ($resident === null || $assessmentNo === '') {
            return [];
        }

        $presentation = app(RiskAssessmentService::class)->findPresentationForResident($resident, $assessmentNo);

        return $presentation ?? [];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function redFlagFields(array $detail): array
    {
        return self::checklistFields($detail['red_flags'] ?? [], 'rf_', [
            'chest_pain', 'difficulty_breathing', 'loss_of_consciousness', 'slurred_speech',
            'facial_asymmetry', 'disoriented', 'chest_retractions', 'seizure', 'self_harm',
            'agitated', 'eye_injury', 'severe_injuries', 'weakness_numbness', 'none',
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function pastMedicalFields(array $detail): array
    {
        return self::checklistFields($detail['past_medical'] ?? [], 'pmh_', [
            'hypertension', 'heart_diseases', 'diabetes', 'cancer', 'copd', 'asthma',
            'mental_neuro_substance', 'vision_problems', 'previous_surgical', 'thyroid',
            'kidney', 'allergies', 'none',
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function familyHistoryFields(array $detail): array
    {
        return self::checklistFields($detail['family_history'] ?? [], 'fh_', [
            'hypertension', 'stroke', 'isch_heart_disease', 'diabetes_mellitus', 'asthma',
            'cancer', 'kidney_disease', 'premature_heart_vascular', 'family_tb',
            'mental_neuro_substance', 'copd', 'none',
        ]);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private static function checklistFields(mixed $selected, string $prefix, array $keys): array
    {
        $items = is_array($selected)
            ? array_map(static fn (mixed $v): string => trim((string) $v), $selected)
            : [];

        $fields = [];
        foreach ($keys as $key) {
            $fields[$prefix.$key] = in_array($key, $items, true) ? 'Yes' : '';
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function lifestyleFields(array $detail): array
    {
        $defs = DemoRiskAssessment::fieldDefinitions();

        return [
            'lifestyle_tobacco' => $defs['tobacco'][trim((string) ($detail['tobacco'] ?? ''))] ?? '',
            'lifestyle_alcohol' => $defs['alcohol'][trim((string) ($detail['alcohol'] ?? ''))] ?? '',
            'lifestyle_dietary' => self::dietaryLabel($detail['dietary'] ?? null, $defs['dietary']),
            'lifestyle_physical_activity' => $defs['physical_activity'][trim((string) ($detail['physical_activity'] ?? ''))] ?? '',
        ];
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function dietaryLabel(mixed $value, array $labels): string
    {
        if (is_array($value)) {
            $mapped = array_filter(array_map(
                static fn (mixed $v): string => $labels[trim((string) $v)] ?? '',
                $value
            ));

            return implode(' / ', $mapped);
        }

        return $labels[trim((string) $value)] ?? '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function physicalClinicalFields(array $row, array $detail): array
    {
        $height = trim((string) ($detail['height_cm'] ?? ''));
        $weight = trim((string) ($detail['weight_kg'] ?? ''));
        $bmi = trim((string) ($detail['bmi'] ?? ''));
        if ($bmi === '' && $height !== '' && $weight !== '') {
            $bmi = (string) (RiskAssessmentClinicalValues::calculateBmi((float) $height, (float) $weight) ?? '');
        }

        $systolic = trim((string) ($detail['systolic'] ?? ''));
        $diastolic = trim((string) ($detail['diastolic'] ?? ''));
        $bloodPressure = ($systolic !== '' && $diastolic !== '') ? $systolic.'/'.$diastolic : '';

        return [
            'phys_height' => $height,
            'phys_weight' => $weight,
            'phys_bmi' => $bmi,
            'phys_waist' => trim((string) ($detail['waist_cm'] ?? '')),
            'phys_blood_pressure' => $bloodPressure,
            'phys_bp_status' => trim((string) ($detail['bp_status'] ?? '')),
        ] + self::visualFields($row, $detail);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function visualFields(array $row, array $detail): array
    {
        // Legacy stores visual screening flat on the same row, and the
        // presentation service already resolves it correctly. ERD's
        // presentation doesn't (findPresentationFromErd() always returns
        // false/false/'' for these), so the visual_screening child table
        // is read directly here for ERD.
        if (Schema::hasTable('risk_assessments')) {
            return [
                'visual_no_screening_past_year' => self::boolLabel($detail['visual_no_screening'] ?? false),
                'visual_has_blurred_vision' => self::boolLabel($detail['visual_blurred'] ?? false),
                'visual_blurred_vision_details' => trim((string) ($detail['visual_blurred_note'] ?? '')),
            ];
        }

        $fields = [
            'visual_no_screening_past_year' => '',
            'visual_has_blurred_vision' => '',
            'visual_blurred_vision_details' => '',
        ];

        $id = (int) ($row['_risk_assessment_id'] ?? 0);
        if ($id <= 0 || ! Schema::hasTable('visual_screening')) {
            return $fields;
        }

        $visual = AtRestRecord::openRow(
            'visual_screening',
            DB::table('visual_screening')->where('risk_assessment_id', $id)->first()
        );
        if ($visual === null) {
            return $fields;
        }

        return [
            'visual_no_screening_past_year' => self::boolLabel($visual->no_screening_past_year ?? null),
            'visual_has_blurred_vision' => self::boolLabel($visual->has_blurred_vision ?? null),
            'visual_blurred_vision_details' => trim((string) ($visual->blurred_vision_details ?? '')),
        ];
    }

    private static function boolLabel(mixed $value): string
    {
        return $value ? 'Yes' : '';
    }

    /**
     * @param  array<string, mixed>  $member
     */
    public static function isEligibleResident(array $member, ?Carbon $on = null): bool
    {
        $age = self::ageInYears($member, $on);

        return $age !== null && $age >= self::MIN_AGE_YEARS;
    }

    /**
     * @param  array<string, mixed>  $member
     */
    public static function ageInYears(array $member, ?Carbon $on = null): ?int
    {
        $birthday = $member['birthday'] ?? null;
        if (! is_string($birthday) && ! $birthday instanceof \DateTimeInterface) {
            return null;
        }

        $iso = $birthday instanceof \DateTimeInterface
            ? $birthday->format('Y-m-d')
            : trim($birthday);

        return HealthRecordsClinicalListing::ageInYearsFromBirthday($iso, $on);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowsFromLegacyTable(): array
    {
        if (! HealthRecordsClinicalListing::tablesReady('risk_assessments', 'residents')) {
            return [];
        }

        $records = HealthRecordsClinicalListing::joinResidentsHouseholds('risk_assessments', 'ra')
            ->sortBy([
                ['conducted_at', 'desc'],
                ['assessment_no', 'asc'],
            ]);

        $rows = [];
        foreach ($records as $record) {
            $rows[] = self::mapLegacyRow($record);
        }

        return self::sortRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowsFromErdTable(): array
    {
        if (! HealthRecordsClinicalListing::tablesReady('risk_assessment', 'residents')) {
            return [];
        }

        $records = HealthRecordsClinicalListing::joinResidentsHouseholds('risk_assessment', 'ra')
            ->sortBy([
                ['created_at', 'desc'],
                ['risk_assessment_id', 'asc'],
            ]);

        $rows = [];
        foreach ($records as $record) {
            $rows[] = self::mapErdRow($record);
        }

        return self::sortRows($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sortRows(array $rows): array
    {
        usort(
            $rows,
            static function (array $a, array $b): int {
                $byName = strcasecmp((string) $a['full_name'], (string) $b['full_name']);

                return $byName !== 0
                    ? $byName
                    : strcasecmp((string) $a['member_id'], (string) $b['member_id']);
            }
        );

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapLegacyRow(object $record): array
    {
        $memberId = HealthRecordsClinicalListing::memberId($record);
        $conductedAt = self::isoDate($record->conducted_at ?? null);
        $birthdayIso = self::isoBirthday($record->birthday ?? null);
        $householdNo = DemoCatalog::normalizeHouseholdNo((string) ($record->household_no ?? ''));
        $ageYears = HealthRecordsClinicalListing::ageInYearsFromBirthday($birthdayIso);

        return [
            'key' => 'ra-'.(string) ($record->id ?? $memberId),
            'household_no' => $householdNo,
            'member_id' => $memberId,
            'full_name' => HealthRecordsClinicalListing::fullName($record),
            'age' => $ageYears !== null ? (string) $ageYears : self::EMPTY,
            'birthday' => $birthdayIso !== ''
                ? (DisplayDate::format($birthdayIso) ?: self::EMPTY)
                : self::EMPTY,
            'birthday_iso' => $birthdayIso,
            'date_assessed' => $conductedAt !== ''
                ? (DisplayDate::format($conductedAt) ?: self::EMPTY)
                : self::EMPTY,
            'date_assessed_iso' => $conductedAt,
            'zone' => HealthRecordsClinicalListing::zoneLabel($record),
            'year' => HealthRecordsClinicalListing::yearFromDate($conductedAt),
            'month' => self::monthFromDate($conductedAt),
            'bmi_status' => self::displayBmiStatus(
                (string) ($record->bmi_label ?? ''),
                $record->bmi ?? null,
                $record->height_cm ?? null,
                $record->weight_kg ?? null
            ),
            'bp_status' => self::displayOrEmpty($record->bp_status ?? null),
            'smoking_status' => self::mapSmokingStatus((string) ($record->tobacco ?? '')),
            'alcohol_status' => self::mapAlcoholStatus((string) ($record->alcohol ?? '')),
            'physical_activity_risk' => self::mapPhysicalActivityRisk((string) ($record->physical_activity ?? '')),
            'family_history_risk' => self::mapFamilyHistoryRisk($record->family_history ?? null),
            'chronic_disease' => self::mapChronicDisease($record->past_medical ?? null),
            'view_url' => $householdNo !== '' && $memberId !== ''
                ? route('household-profiling.members.risk-assessment', [
                    'householdNo' => $householdNo,
                    'memberId' => $memberId,
                ])
                : '',
            // Internal identity fields (not for display) — let the detailed
            // export resolve the exact resident + assessment record for the
            // Red Flag/Past Medical/Family History/Lifestyle/Physical
            // sections, regardless of legacy vs ERD schema.
            '_resident_id' => (int) ($record->resident_id ?? 0),
            '_risk_assessment_id' => (int) ($record->id ?? 0),
            '_assessment_no' => (string) ($record->assessment_no ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapErdRow(object $record): array
    {
        $record = AtRestRecord::openRow('risk_assessment', $record);
        $memberId = HealthRecordsClinicalListing::memberId($record);
        $assessmentDate = self::isoDate($record->created_at ?? null);
        $birthdayIso = self::isoBirthday($record->birthday ?? null);
        $householdNo = DemoCatalog::normalizeHouseholdNo((string) ($record->household_no ?? ''));
        $ageYears = HealthRecordsClinicalListing::ageInYearsFromBirthday($birthdayIso);

        return [
            'key' => 'ra-'.(string) ($record->risk_assessment_id ?? $memberId),
            'household_no' => $householdNo,
            'member_id' => $memberId,
            'full_name' => HealthRecordsClinicalListing::fullName($record),
            'age' => $ageYears !== null ? (string) $ageYears : self::EMPTY,
            'birthday' => $birthdayIso !== ''
                ? (DisplayDate::format($birthdayIso) ?: self::EMPTY)
                : self::EMPTY,
            'birthday_iso' => $birthdayIso,
            'date_assessed' => $assessmentDate !== ''
                ? (DisplayDate::format($assessmentDate) ?: self::EMPTY)
                : self::EMPTY,
            'date_assessed_iso' => $assessmentDate,
            'zone' => HealthRecordsClinicalListing::zoneLabel($record),
            'year' => HealthRecordsClinicalListing::yearFromDate($assessmentDate),
            'month' => self::monthFromDate($assessmentDate),
            'bmi_status' => self::displayBmiStatus(
                '',
                null,
                $record->height_cm ?? null,
                $record->weight_kg ?? null
            ),
            'bp_status' => self::displayOrEmpty(RiskAssessmentErdMode::bloodPressureStatusLabel(
                $record->systolic_blood_pressure ?? null,
                $record->diastolic_blood_pressure ?? null
            )),
            'smoking_status' => self::displayOrEmpty($record->tobacco_vape_usage ?? null),
            'alcohol_status' => self::displayOrEmpty($record->alcohol_intake ?? null),
            'physical_activity_risk' => self::displayOrEmpty($record->physical_activity ?? null),
            'family_history_risk' => self::EMPTY,
            'chronic_disease' => self::EMPTY,
            'view_url' => $householdNo !== '' && $memberId !== ''
                ? route('household-profiling.members.risk-assessment', [
                    'householdNo' => $householdNo,
                    'memberId' => $memberId,
                ])
                : '',
            '_resident_id' => (int) ($record->resident_id ?? 0),
            '_risk_assessment_id' => (int) ($record->risk_assessment_id ?? 0),
            '_assessment_no' => sprintf('RA-%03d', (int) ($record->risk_assessment_id ?? 0)),
        ];
    }

    private static function isoBirthday(mixed $birthday): string
    {
        if ($birthday instanceof \DateTimeInterface) {
            return $birthday->format('Y-m-d');
        }

        $raw = trim((string) $birthday);
        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return $raw;
        }
    }

    private static function isoDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return strlen($raw) >= 10 ? substr($raw, 0, 10) : $raw;
        }
    }

    private static function monthFromDate(string $isoDate): string
    {
        if ($isoDate === '') {
            return '';
        }

        return preg_match('/^\d{4}-(\d{2})-/', $isoDate, $match) ? $match[1] : '';
    }

    private static function displayOrEmpty(mixed $value): string
    {
        $raw = trim((string) $value);

        return $raw !== '' ? $raw : self::EMPTY;
    }

    private static function displayBmiStatus(
        string $label,
        mixed $bmi,
        mixed $heightCm,
        mixed $weightKg
    ): string {
        if (trim($label) !== '') {
            return trim($label);
        }

        $computed = self::computeBmi($bmi, $heightCm, $weightKg);
        if ($computed === null) {
            return self::EMPTY;
        }

        return RiskAssessmentClinicalValues::classifyAdultBmi($computed);
    }

    private static function computeBmi(mixed $bmi, mixed $heightCm, mixed $weightKg): ?float
    {
        if (is_numeric($bmi)) {
            return (float) $bmi;
        }

        if (! is_numeric($heightCm) || ! is_numeric($weightKg)) {
            return null;
        }

        $height = ((float) $heightCm) / 100;
        if ($height <= 0) {
            return null;
        }

        return round(((float) $weightKg) / ($height * $height), 2);
    }

    private static function mapSmokingStatus(string $stored): string
    {
        return match ($stored) {
            'never' => 'Never',
            'current' => 'Current',
            'stopped_lt_1y', 'quit' => 'Quit',
            default => self::displayOrEmpty($stored),
        };
    }

    private static function mapAlcoholStatus(string $stored): string
    {
        return match ($stored) {
            'never', '' => 'None',
            'light', 'moderate' => 'Moderate',
            'excessive' => 'Excessive',
            default => self::displayOrEmpty($stored),
        };
    }

    private static function mapPhysicalActivityRisk(string $stored): string
    {
        return match ($stored) {
            'meets' => 'Active',
            'below' => 'Inactive',
            default => self::displayOrEmpty($stored),
        };
    }

    private static function mapFamilyHistoryRisk(mixed $value): string
    {
        $items = HealthRecordsClinicalListing::decodeJsonList($value);
        if ($items === []) {
            return self::EMPTY;
        }

        return in_array('none', $items, true) && count($items) === 1 ? 'No' : 'Yes';
    }

    private static function mapChronicDisease(mixed $value): string
    {
        $items = HealthRecordsClinicalListing::decodeJsonList($value);
        if ($items === []) {
            return self::EMPTY;
        }

        if (in_array('none', $items, true) && count($items) === 1) {
            return 'None';
        }

        $first = $items[0] ?? '';

        return match ($first) {
            'diabetes_mellitus', 'diabetes' => 'Diabetes',
            'isch_heart_disease', 'copd', 'premature_heart_vascular' => 'CVD',
            default => self::displayOrEmpty($first),
        };
    }
}
