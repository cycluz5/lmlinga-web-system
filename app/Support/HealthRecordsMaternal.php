<?php

namespace App\Support;

use App\Models\MaternalPregnancy;
use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Barangay-wide Maternal Care listing (Health Records → Maternal).
 *
 * Listing rows and summary counts come only from persisted maternal care records.
 */
final class HealthRecordsMaternal
{
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
        if (Schema::hasTable('maternal_pregnancies')) {
            return self::rowsFromLegacyTable();
        }

        if (Schema::hasTable('maternal_care')) {
            return self::rowsFromErdTable();
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array{total: int, delivered: int, delivered_month_label: string}
     */
    public static function summaryCounts(?array $rows = null): array
    {
        $rows ??= self::rows();
        $delivered = 0;

        foreach ($rows as $row) {
            if (! empty($row['is_delivered_this_month'])) {
                $delivered++;
            }
        }

        return [
            'total' => count($rows),
            'delivered' => $delivered,
            'delivered_month_label' => self::currentDeliveredMonthLabel(),
        ];
    }

    public static function currentDeliveredMonthLabel(): string
    {
        return now()->timezone((string) config('app.timezone'))->format('F Y');
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
     * Zone/year/month-filtered Maternal Care rows — the Maternal Report
     * Builder's data source.
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
     * the year/month filters are active. Used for the Maternal report's
     * summary period label.
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
     * Zone/year/month-filtered Maternal Care rows, each enriched with a
     * 'sections' key: an ordered list of {title, columns, rows} groups —
     * Registration (always shown), then Prenatal Visits / Supplementations
     * / Laboratory Screening / Delivery Outcome / Postnatal Care (only
     * when that section has at least one filled-in entry). Detail is
     * pulled through MaternalPregnancyService — the same service the
     * Household Profiling member Maternal Care page uses — so legacy
     * (maternal_pregnancies JSON columns) and ERD (maternal_care +
     * prenatal_visits/*_supplementation/*_screening/delivery_outcomes/
     * postnatal_care_visits) both resolve to the same shape with no
     * schema branching needed here.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Fixed pixel widths (not proportional weights) — proportional weights
     * squeeze every column smaller as more columns are added, which is
     * exactly what made the ~104-column single-page table unreadable.
     * Fixed widths keep each column legible regardless of column count;
     * self::columnPages() is what decides how many print pages that needs.
     *
     * @var list<array{key: string, title: string, fields: list<array{key: string, label: string, width: float}>}>
     */
    private const SECTIONS = [
        [
            'key' => 'client',
            'title' => 'CLIENT',
            'fields' => [
                ['key' => 'full_name', 'label' => 'Name', 'width' => 170.0],
                ['key' => 'age', 'label' => 'Age', 'width' => 45.0],
                ['key' => 'birthday', 'label' => 'Birthday', 'width' => 80.0],
            ],
        ],
        [
            'key' => 'registration',
            'title' => 'REGISTRATION',
            'fields' => [
                ['key' => 'registered_date', 'label' => 'Registered Date', 'width' => 80.0],
                ['key' => 'lmp', 'label' => 'LMP', 'width' => 75.0],
                ['key' => 'gravida_parity', 'label' => 'Gravida-Parity', 'width' => 75.0],
                ['key' => 'edd', 'label' => 'EDD', 'width' => 75.0],
                ['key' => 'weight', 'label' => 'Weight (kg)', 'width' => 60.0],
                ['key' => 'height', 'label' => 'Height (cm)', 'width' => 60.0],
                ['key' => 'bmi', 'label' => 'BMI', 'width' => 55.0],
                ['key' => 'blood_pressure', 'label' => 'Blood Pressure', 'width' => 75.0],
                ['key' => 'status', 'label' => 'Status', 'width' => 65.0],
            ],
        ],
        [
            'key' => 'prenatal',
            'title' => 'PRENATAL VISITS',
            'fields' => [
                ['key' => 'pv_t1_v1_date', 'label' => '1st Tri V1', 'width' => 85.0],
                ['key' => 'pv_t2_v1_date', 'label' => '2nd Tri V1', 'width' => 85.0],
                ['key' => 'pv_t2_v2_date', 'label' => '2nd Tri V2', 'width' => 85.0],
                ['key' => 'pv_t3_v1_date', 'label' => '3rd Tri V1', 'width' => 85.0],
                ['key' => 'pv_t3_v2_date', 'label' => '3rd Tri V2', 'width' => 85.0],
                ['key' => 'pv_t3_v3_date', 'label' => '3rd Tri V3', 'width' => 85.0],
                ['key' => 'pv_t3_v4_date', 'label' => '3rd Tri V4', 'width' => 85.0],
                ['key' => 'pv_t3_v5_date', 'label' => '3rd Tri V5', 'width' => 85.0],
            ],
        ],
        [
            'key' => 'supplementation',
            'title' => 'SUPPLEMENTATION',
            'fields' => [
                ['key' => 'supp_deworming_date', 'label' => 'Deworming Date', 'width' => 80.0],
                ['key' => 'supp_ifa_v1_date', 'label' => 'IFA V1 Date', 'width' => 75.0],
                ['key' => 'supp_ifa_v1_tablets', 'label' => 'IFA V1 Tabs', 'width' => 55.0],
                ['key' => 'supp_ifa_v2_date', 'label' => 'IFA V2 Date', 'width' => 75.0],
                ['key' => 'supp_ifa_v2_tablets', 'label' => 'IFA V2 Tabs', 'width' => 55.0],
                ['key' => 'supp_ifa_v3_date', 'label' => 'IFA V3 Date', 'width' => 75.0],
                ['key' => 'supp_ifa_v3_tablets', 'label' => 'IFA V3 Tabs', 'width' => 55.0],
                ['key' => 'supp_ifa_v4_date', 'label' => 'IFA V4 Date', 'width' => 75.0],
                ['key' => 'supp_ifa_v4_tablets', 'label' => 'IFA V4 Tabs', 'width' => 55.0],
                ['key' => 'supp_ifa_v5_date', 'label' => 'IFA V5 Date', 'width' => 75.0],
                ['key' => 'supp_ifa_v5_tablets', 'label' => 'IFA V5 Tabs', 'width' => 55.0],
                ['key' => 'supp_ifa_v6_date', 'label' => 'IFA V6 Date', 'width' => 75.0],
                ['key' => 'supp_ifa_v6_tablets', 'label' => 'IFA V6 Tabs', 'width' => 55.0],
                ['key' => 'supp_mms_v1_date', 'label' => 'MMS V1 Date', 'width' => 75.0],
                ['key' => 'supp_mms_v1_tablets', 'label' => 'MMS V1 Tabs', 'width' => 55.0],
                ['key' => 'supp_mms_v2_date', 'label' => 'MMS V2 Date', 'width' => 75.0],
                ['key' => 'supp_mms_v2_tablets', 'label' => 'MMS V2 Tabs', 'width' => 55.0],
                ['key' => 'supp_mms_v3_date', 'label' => 'MMS V3 Date', 'width' => 75.0],
                ['key' => 'supp_mms_v3_tablets', 'label' => 'MMS V3 Tabs', 'width' => 55.0],
                ['key' => 'supp_mms_v4_date', 'label' => 'MMS V4 Date', 'width' => 75.0],
                ['key' => 'supp_mms_v4_tablets', 'label' => 'MMS V4 Tabs', 'width' => 55.0],
                ['key' => 'supp_mms_v5_date', 'label' => 'MMS V5 Date', 'width' => 75.0],
                ['key' => 'supp_mms_v5_tablets', 'label' => 'MMS V5 Tabs', 'width' => 55.0],
                ['key' => 'supp_mms_v6_date', 'label' => 'MMS V6 Date', 'width' => 75.0],
                ['key' => 'supp_mms_v6_tablets', 'label' => 'MMS V6 Tabs', 'width' => 55.0],
                ['key' => 'supp_calcium_v1_date', 'label' => 'Calcium V1 Date', 'width' => 75.0],
                ['key' => 'supp_calcium_v1_tablets', 'label' => 'Calcium V1 Tabs', 'width' => 55.0],
                ['key' => 'supp_calcium_v2_date', 'label' => 'Calcium V2 Date', 'width' => 75.0],
                ['key' => 'supp_calcium_v2_tablets', 'label' => 'Calcium V2 Tabs', 'width' => 55.0],
                ['key' => 'supp_calcium_v3_date', 'label' => 'Calcium V3 Date', 'width' => 75.0],
                ['key' => 'supp_calcium_v3_tablets', 'label' => 'Calcium V3 Tabs', 'width' => 55.0],
            ],
        ],
        [
            'key' => 'laboratory',
            'title' => 'LABORATORY SCREENING',
            'fields' => [
                ['key' => 'lab_hepatitis_b_date', 'label' => 'Hepatitis B Date', 'width' => 80.0],
                ['key' => 'lab_hepatitis_b_result', 'label' => 'Hepatitis B Result', 'width' => 95.0],
                ['key' => 'lab_cbc_date', 'label' => 'CBC Date', 'width' => 80.0],
                ['key' => 'lab_cbc_result', 'label' => 'CBC Result', 'width' => 95.0],
                ['key' => 'lab_gdm_date', 'label' => 'GDM Date', 'width' => 80.0],
                ['key' => 'lab_gdm_result', 'label' => 'GDM Result', 'width' => 95.0],
            ],
        ],
        [
            'key' => 'delivery',
            'title' => 'PREGNANCY DELIVERY & OUTCOME',
            'fields' => [
                ['key' => 'delivery_outcome', 'label' => 'Outcome', 'width' => 90.0],
                ['key' => 'delivery_type', 'label' => 'Delivery Type', 'width' => 130.0],
                ['key' => 'delivery_birth_weight', 'label' => 'Birth Weight (kg)', 'width' => 85.0],
                ['key' => 'delivery_status', 'label' => 'Status', 'width' => 90.0],
                ['key' => 'delivery_datetime', 'label' => 'Date & Time of Delivery', 'width' => 115.0],
                ['key' => 'delivery_date_terminated', 'label' => 'Date Terminated', 'width' => 90.0],
                ['key' => 'delivery_fetal_death_date', 'label' => 'Date of Fetal Death', 'width' => 90.0],
                ['key' => 'delivery_abortion_date', 'label' => 'Date of Abortion', 'width' => 90.0],
                ['key' => 'delivery_birth_attendant', 'label' => 'Birth Attendant', 'width' => 120.0],
                ['key' => 'delivery_birth_attendant_other', 'label' => 'Attendant (Other)', 'width' => 110.0],
                ['key' => 'delivery_place', 'label' => 'Place of Delivery', 'width' => 130.0],
                ['key' => 'delivery_facility_name', 'label' => 'Facility Name', 'width' => 140.0],
                ['key' => 'delivery_bemonc_cemonc', 'label' => 'BEmONC/CEmONC', 'width' => 85.0],
            ],
        ],
        [
            'key' => 'postnatal',
            'title' => 'POSTNATAL CARE',
            'fields' => [
                ['key' => 'postnatal_c1', 'label' => 'Contact 1 Date', 'width' => 75.0],
                ['key' => 'postnatal_c2', 'label' => 'Contact 2 Date', 'width' => 75.0],
                ['key' => 'postnatal_c3', 'label' => 'Contact 3 Date', 'width' => 75.0],
                ['key' => 'postnatal_c4', 'label' => 'Contact 4 Date', 'width' => 75.0],
                ['key' => 'postnatal_supp_v1_date', 'label' => 'PP IFA V1 Date', 'width' => 75.0],
                ['key' => 'postnatal_supp_v1_tablets', 'label' => 'PP IFA V1 Tabs', 'width' => 55.0],
                ['key' => 'postnatal_supp_v2_date', 'label' => 'PP IFA V2 Date', 'width' => 75.0],
                ['key' => 'postnatal_supp_v2_tablets', 'label' => 'PP IFA V2 Tabs', 'width' => 55.0],
                ['key' => 'postnatal_supp_v3_date', 'label' => 'PP IFA V3 Date', 'width' => 75.0],
                ['key' => 'postnatal_supp_v3_tablets', 'label' => 'PP IFA V3 Tabs', 'width' => 55.0],
            ],
        ],
    ];

    /** Matches MaternalCarePdf::PAGE_WIDTH minus its left+right margins. */
    private const PAGE_CONTENT_WIDTH = 928.0;

    /**
     * The group/field section definitions, exposed so the Report Builder's
     * live preview can render the same grouped-column table shape as the
     * PDF (MaternalCarePdf).
     *
     * @return list<array{key: string, title: string, fields: list<array{key: string, label: string, width: float}>}>
     */
    public static function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * Splits the full column set into print-width pages: Name / Age /
     * Birthday / Registered Date repeat as anchor columns on every page,
     * and the remaining fields (grouped by their section, in order) are
     * packed in as many as fit — starting a new page whenever the next
     * field would overflow the content width — so every column stays a
     * legible fixed width instead of being squeezed to fit one page.
     *
     * @return list<list<array{key: string, title: string, fields: list<array{key: string, label: string, width: float}>}>>
     */
    /**
     * Six fixed topic pages, in this order: Client & Registration,
     * Prenatal Visits, Supplementation, Laboratory Screening, Pregnancy
     * Delivery & Outcome, Postnatal Care. Page 1 carries the full Client
     * group (Name/Age/Birthday) alongside Registration; from page 2
     * onward only Name repeats as the identifying anchor column, so the
     * client stays identifiable on every page without re-showing Age/
     * Birthday/Registered Date each time. A topic only splits into
     * further "PART x of y" pages when its own fields genuinely don't
     * fit one page width — Supplementation and Delivery typically do;
     * Prenatal (date-only), Laboratory, and Postnatal typically don't.
     *
     * @return list<array{sections: list<array{key: string, title: string, fields: list<array{key: string, label: string, width: float}>}>, groupTitle: string, part: int, partsInGroup: int}>
     */
    public static function columnPages(): array
    {
        [$clientSection, $registrationSection] = self::SECTIONS;
        $topicSections = array_slice(self::SECTIONS, 2);

        $nameField = $clientSection['fields'][0];

        $pages = [
            [
                'sections' => [$clientSection, $registrationSection],
                'groupTitle' => 'CLIENT & REGISTRATION',
                'part' => 1,
                'partsInGroup' => 1,
            ],
        ];

        $nameOnlySection = ['key' => 'client', 'title' => 'CLIENT', 'fields' => [$nameField]];
        $available = self::PAGE_CONTENT_WIDTH - $nameField['width'];

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

    public static function detailedExportRows(?string $zone = null, ?string $year = null, ?string $month = null): array
    {
        return array_map(
            static fn (array $row): array => self::withFlatDetail($row),
            self::exportRows($zone, $year, $month)
        );
    }

    /**
     * Straight-row export: every maternal record gets exactly one row, and
     * every field from Registration / Prenatal Visits / Supplementation /
     * Laboratory Screening / Pregnancy Delivery & Outcome / Postnatal Care
     * is its own column and is always present — blank (not '—') when not
     * yet recorded — mirroring the Household Profiling member Maternal
     * Care history form field-for-field, which always renders every
     * visit/test/contact slot regardless of whether it has data.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withFlatDetail(array $row): array
    {
        $detail = self::presentationForRow($row);

        // Left operand wins on key collisions — the freshly computed detail
        // fields (sourced from MaternalPregnancyService's presentation, the
        // same data the history form reads) must come first so they take
        // precedence over the base listing row's own same-named fields
        // (e.g. 'lmp', 'gravida_parity', 'edd').
        return ['birthday' => self::detailDate($row['_birthday'] ?? null)]
            + self::registrationFields($detail)
            + self::prenatalFields($detail)
            + self::supplementationFields($detail)
            + self::laboratoryFields($detail)
            + self::deliveryFields($detail)
            + self::postnatalFields($detail)
            + $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function presentationForRow(array $row): array
    {
        $resident = Resident::find($row['_resident_id'] ?? 0);
        if ($resident === null) {
            return [];
        }

        $service = app(MaternalPregnancyService::class);
        $status = (string) ($row['_status'] ?? '');

        $presentation = $status === MaternalPregnancy::STATUS_ACTIVE
            ? $service->activePresentationForResident($resident)
            : $service->historicalPresentationForResident(
                $resident,
                sprintf('MC-%03d', (int) ($row['_record_id'] ?? 0))
            );

        return $presentation ?? [];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function registrationFields(array $detail): array
    {
        $status = strtolower(trim((string) ($detail['status'] ?? '')));
        $statusLabel = match ($status) {
            'active' => 'Active',
            'completed' => 'Completed',
            'transferred_out' => 'Trans-Out',
            default => '',
        };

        $gravida = self::detailText($detail['gravida'] ?? null);
        $parity = self::detailText($detail['parity'] ?? null);
        $gravidaParity = $gravida === '' && $parity === ''
            ? ''
            : $gravida.'-'.$parity;

        return [
            'lmp' => self::detailDate($detail['lmp'] ?? null),
            'gravida_parity' => $gravidaParity,
            'edd' => self::detailDate($detail['edd'] ?? null),
            'weight' => self::detailText($detail['weight'] ?? null),
            'height' => self::detailText($detail['height'] ?? null),
            'bmi' => self::detailText($detail['bmi'] ?? null),
            'blood_pressure' => self::detailText($detail['blood_pressure'] ?? null),
            'registered_date' => self::detailDate($detail['registered_at'] ?? null),
            'status' => $statusLabel,
        ];
    }

    /**
     * One column per prenatal visit sub-field (Date / Height / Weight /
     * BMI) — every one of the 8 scheduled visits always has all four
     * columns present, blank when that visit hasn't happened yet.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function prenatalFields(array $detail): array
    {
        $prenatal = is_array($detail['prenatal'] ?? null) ? $detail['prenatal'] : [];
        $fields = [];

        foreach (DemoMaternalCare::prenatalSchedule() as $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $entry = is_array($prenatal[$visit['key']] ?? null) ? $prenatal[$visit['key']] : [];
                $prefix = 'pv_'.$visit['key'].'_';
                $fields[$prefix.'date'] = self::detailDate($entry['date'] ?? null);
                $fields[$prefix.'height'] = self::detailText($entry['height'] ?? null);
                $fields[$prefix.'weight'] = self::detailText($entry['weight'] ?? null);
                $fields[$prefix.'bmi'] = self::detailText($entry['bmi'] ?? null);
            }
        }

        return $fields;
    }

    /**
     * One column per supplementation visit sub-field (Date / Tablets) for
     * Deworming (date only), IFA, MMS, and Calcium — every scheduled visit
     * slot always has its columns present, blank when not yet given.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function supplementationFields(array $detail): array
    {
        $supp = is_array($detail['supplementations'] ?? null) ? $detail['supplementations'] : [];

        $fields = [
            'supp_deworming_date' => self::detailDate($supp['deworming_date'] ?? null),
        ];

        foreach (['ifa' => 'supp_ifa', 'mms' => 'supp_mms', 'calcium' => 'supp_calcium'] as $groupKey => $fieldPrefix) {
            $group = is_array($supp[$groupKey] ?? null) ? $supp[$groupKey] : [];
            $visits = DemoMaternalCare::supplementationSchedule()[$groupKey]['visits'] ?? [];

            foreach ($visits as $visit) {
                $entry = is_array($group[$visit['key']] ?? null) ? $group[$visit['key']] : [];
                $prefix = $fieldPrefix.'_'.$visit['key'].'_';
                $fields[$prefix.'date'] = self::detailDate($entry['date'] ?? null);
                $fields[$prefix.'tablets'] = self::detailText($entry['tablets'] ?? null);
            }
        }

        return $fields;
    }

    /**
     * One Date + one Result column per screening test (Hepatitis B, CBC,
     * GDM) — all three tests always present, blank when not yet screened.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function laboratoryFields(array $detail): array
    {
        $lab = is_array($detail['laboratory'] ?? null) ? $detail['laboratory'] : [];
        $map = [
            'lab_hepatitis_b' => 'hepatitis_b',
            'lab_cbc' => 'cbc',
            'lab_gdm' => 'gdm',
        ];

        $fields = [];
        foreach ($map as $fieldPrefix => $key) {
            $entry = is_array($lab[$key] ?? null) ? $lab[$key] : [];
            $fields[$fieldPrefix.'_date'] = self::detailDate($entry['date'] ?? null);
            $fields[$fieldPrefix.'_result'] = self::detailText($entry['result'] ?? null);
        }

        return $fields;
    }

    /**
     * One column per Pregnancy Delivery & Outcome form field — the same
     * fields the household-profiling delivery form itself collects
     * (Outcome, Delivery Type, Birth Weight, Status, Date & Time of
     * Delivery, Date Terminated, Date of Fetal Death, Date of Abortion,
     * Birth Attendant + Other, Place of Delivery, Facility Name,
     * BEmONC/CEmONC) — always present, blank when the pregnancy hasn't
     * reached that stage yet.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function deliveryFields(array $detail): array
    {
        $delivery = is_array($detail['delivery'] ?? null) ? $detail['delivery'] : [];
        $outcome = trim((string) ($delivery['outcome'] ?? ''));

        return [
            'delivery_outcome' => $outcome !== '' ? (DemoMaternalCare::OUTCOMES[$outcome] ?? $outcome) : '',
            'delivery_type' => DemoMaternalCare::DELIVERY_TYPES[trim((string) ($delivery['delivery_type'] ?? ''))] ?? '',
            'delivery_birth_weight' => self::detailText($delivery['birth_weight'] ?? null),
            'delivery_status' => self::detailText($delivery['status'] ?? null),
            'delivery_datetime' => self::detailDate($delivery['datetime'] ?? null),
            'delivery_date_terminated' => self::detailDate($delivery['date_terminated'] ?? null),
            'delivery_fetal_death_date' => self::detailDate($delivery['fetal_death_date'] ?? null),
            'delivery_abortion_date' => self::detailDate($delivery['abortion_date'] ?? null),
            'delivery_birth_attendant' => DemoMaternalCare::BIRTH_ATTENDANTS[trim((string) ($delivery['birth_attendant'] ?? ''))] ?? '',
            'delivery_birth_attendant_other' => self::detailText($delivery['birth_attendant_other'] ?? null),
            'delivery_place' => DemoMaternalCare::PLACES_OF_DELIVERY[trim((string) ($delivery['place'] ?? ''))] ?? '',
            'delivery_facility_name' => self::detailText($delivery['facility_name'] ?? null),
            'delivery_bemonc_cemonc' => self::detailText($delivery['bemonc_cemonc'] ?? null),
        ];
    }

    /**
     * One column per postnatal contact (4) and one Date + Tablets pair per
     * postpartum IFA supplementation visit (3) — always present, blank
     * when not yet recorded.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, string>
     */
    private static function postnatalFields(array $detail): array
    {
        $postnatal = is_array($detail['postnatal'] ?? null) ? $detail['postnatal'] : [];
        $contacts = is_array($postnatal['contacts'] ?? null) ? $postnatal['contacts'] : [];
        $supplementation = is_array($postnatal['supplementation'] ?? null) ? $postnatal['supplementation'] : [];

        $fields = [];
        foreach (DemoMaternalCare::postnatalContacts() as $index => $contact) {
            $fields['postnatal_c'.($index + 1)] = self::detailDate($contacts[$contact['key']] ?? null);
        }

        foreach (DemoMaternalCare::postpartumSupplementationVisits() as $visit) {
            $entry = is_array($supplementation[$visit['key']] ?? null) ? $supplementation[$visit['key']] : [];
            $prefix = 'postnatal_supp_'.$visit['key'].'_';
            $fields[$prefix.'date'] = self::detailDate($entry['date'] ?? null);
            $fields[$prefix.'tablets'] = self::detailText($entry['tablets'] ?? null);
        }

        return $fields;
    }

    private static function detailDate(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));

        return $raw !== '' ? self::formatListingDate($raw) : '';
    }

    private static function detailText(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    public static function isFemaleSex(string $sex): bool
    {
        $normalized = strtolower(trim($sex));

        return in_array($normalized, ['female', 'f', 'woman', 'girl', 'female/girl'], true);
    }

    public static function isMaleSex(string $sex): bool
    {
        $normalized = strtolower(trim($sex));

        return in_array($normalized, ['male', 'm', 'man', 'boy', 'male/boy'], true);
    }

    public static function ageGroupLetter(?int $ageYears): string
    {
        if ($ageYears === null) {
            return self::EMPTY;
        }

        return match (true) {
            $ageYears < 15 => 'A',
            $ageYears <= 19 => 'B',
            $ageYears <= 49 => 'C',
            default => 'D',
        };
    }

    public static function formatListingDate(?string $isoDate): string
    {
        return HealthRecordsClinicalListing::formatMaternalDate($isoDate);
    }

    /**
     * @param  array<string, mixed>  $member
     */
    public static function displayName(array $member): string
    {
        $name = trim((string) ($member['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $parts = array_filter([
            $member['first_name'] ?? null,
            $member['middle_name'] ?? null,
            $member['last_name'] ?? null,
        ], static fn ($part) => filled($part));

        return $parts !== [] ? implode(' ', $parts) : 'Unknown';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowsFromLegacyTable(): array
    {
        if (! HealthRecordsClinicalListing::tablesReady('maternal_pregnancies', 'residents')) {
            return [];
        }

        $records = HealthRecordsClinicalListing::joinResidentsHouseholds('maternal_pregnancies', 'mc')
            ->sortBy([
                ['registered_at', 'desc'],
                ['pregnancy_number', 'desc'],
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
        if (! HealthRecordsClinicalListing::tablesReady('maternal_care', 'residents')) {
            return [];
        }

        $records = HealthRecordsClinicalListing::joinResidentsHouseholds('maternal_care', 'mc')
            ->sortBy([
                ['created_at', 'desc'],
                ['maternal_care_id', 'desc'],
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
        $lmp = self::isoDate($record->lmp ?? null);
        $edd = self::isoDate($record->edd ?? null);
        $deliveryType = self::legacyDeliveryType($record);
        $prenatalCount = self::legacyPrenatalVisitCount($record->prenatal ?? null);
        $status = strtolower(trim((string) ($record->status ?? '')));
        $isDelivered = $status !== MaternalPregnancy::STATUS_ACTIVE && $deliveryType !== '';
        $ageYears = HealthRecordsClinicalListing::ageInYearsFromBirthday(self::isoBirthday($record->birthday ?? null));
        $registeredAt = self::isoDate($record->registered_at ?? $record->created_at ?? null);
        $deliveryDate = self::legacyDeliveryDate($record);

        $row = self::buildRow(
            key: 'mc-'.(string) ($record->id ?? $memberId),
            record: $record,
            memberId: $memberId,
            ageYears: $ageYears,
            year: HealthRecordsClinicalListing::yearFromDate($registeredAt),
            month: self::monthFromDate($registeredAt),
            lmp: $lmp,
            gravida: $record->gravida ?? null,
            parity: $record->parity ?? null,
            edd: $edd,
            deliveryType: $deliveryType,
            trimester: HealthRecordsClinicalListing::shortTrimesterFromLmp($lmp),
            prenatalVisits: $prenatalCount !== null ? (string) $prenatalCount : self::EMPTY,
            isHighRisk: false,
            isDuePrenatal: ! $isDelivered && $prenatalCount !== null && $prenatalCount < 4,
            isDelivered: $isDelivered,
            isDeliveredThisMonth: self::isDeliveredThisMonth($deliveryDate, $isDelivered),
            isIncompletePrenatal: ! $isDelivered && $prenatalCount !== null && $prenatalCount < 4,
        );

        // Internal identity fields (not for display) — let the detailed
        // export resolve the exact resident + pregnancy record for the
        // Registration/Prenatal/Supplementations/Laboratory/Delivery/
        // Postnatal sections, regardless of legacy vs ERD schema.
        $row['_resident_id'] = (int) ($record->resident_id ?? 0);
        $row['_record_id'] = (int) ($record->id ?? 0);
        $row['_status'] = $status;
        $row['_birthday'] = self::isoBirthday($record->birthday ?? null);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapErdRow(object $record): array
    {
        $memberId = HealthRecordsClinicalListing::memberId($record);
        $lmp = self::isoDate($record->lmp_date ?? null);
        $edd = self::isoDate($record->edd ?? null);
        $status = strtolower(trim((string) ($record->pregnancy_status ?? '')));
        $isDelivered = in_array($status, ['delivered', 'completed'], true);
        $ageYears = HealthRecordsClinicalListing::ageInYearsFromBirthday(self::isoBirthday($record->birthday ?? null));
        $createdAt = self::isoDate($record->created_at ?? null);
        $deliveryDate = self::isoDate($record->date_time_of_delivery ?? null);

        $row = self::buildRow(
            key: 'mc-'.(string) ($record->maternal_care_id ?? $memberId),
            record: $record,
            memberId: $memberId,
            ageYears: $ageYears,
            year: HealthRecordsClinicalListing::yearFromDate($createdAt),
            month: self::monthFromDate($createdAt),
            lmp: $lmp,
            gravida: $record->gravida ?? null,
            parity: $record->parity ?? null,
            edd: $edd,
            deliveryType: self::EMPTY,
            trimester: HealthRecordsClinicalListing::shortTrimesterFromLmp($lmp),
            prenatalVisits: self::EMPTY,
            isHighRisk: false,
            isDuePrenatal: ! $isDelivered && $lmp !== '',
            isDelivered: $isDelivered,
            isDeliveredThisMonth: self::isDeliveredThisMonth($deliveryDate, $isDelivered),
            isIncompletePrenatal: ! $isDelivered && $lmp !== '',
        );

        $row['_resident_id'] = (int) ($record->resident_id ?? 0);
        $row['_record_id'] = (int) ($record->maternal_care_id ?? 0);
        $row['_status'] = $status === 'trans-out' ? 'transferred_out' : $status;
        $row['_birthday'] = self::isoBirthday($record->birthday ?? null);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildRow(
        string $key,
        object $record,
        string $memberId,
        ?int $ageYears,
        string $year,
        string $month,
        string $lmp,
        mixed $gravida,
        mixed $parity,
        string $edd,
        string $deliveryType,
        string $trimester,
        string $prenatalVisits,
        bool $isHighRisk,
        bool $isDuePrenatal,
        bool $isDelivered,
        bool $isDeliveredThisMonth,
        bool $isIncompletePrenatal
    ): array {
        $householdNo = DemoCatalog::normalizeHouseholdNo((string) ($record->household_no ?? ''));

        return [
            'key' => $key,
            'household_no' => $householdNo,
            'member_id' => $memberId,
            'full_name' => HealthRecordsClinicalListing::fullName($record),
            'sex' => (string) ($record->sex ?? ''),
            'sex_normalized' => strtolower(trim((string) ($record->sex ?? ''))),
            'age_years' => $ageYears,
            'age' => $ageYears !== null ? (string) $ageYears : self::EMPTY,
            'age_group' => self::ageGroupLetter($ageYears),
            'zone' => HealthRecordsClinicalListing::zoneLabel($record),
            'year' => $year,
            'month' => $month,
            'lmp' => $lmp !== '' ? self::formatListingDate($lmp) : self::EMPTY,
            'gravida_parity' => HealthRecordsClinicalListing::gravidaParityLabel($gravida, $parity),
            'edd' => $edd !== '' ? self::formatListingDate($edd) : self::EMPTY,
            'delivery_type' => $deliveryType !== '' ? $deliveryType : self::EMPTY,
            'trimester' => $trimester !== '' ? $trimester : self::EMPTY,
            'prenatal_visits' => $prenatalVisits,
            'is_high_risk' => $isHighRisk,
            'is_due_prenatal' => $isDuePrenatal,
            'is_delivered' => $isDelivered,
            'is_delivered_this_month' => $isDeliveredThisMonth,
            'is_incomplete_prenatal' => $isIncompletePrenatal,
            'population' => 'resident',
            'view_url' => $householdNo !== '' && $memberId !== ''
                ? route('household-profiling.members.maternal-care.index', [
                    'householdNo' => $householdNo,
                    'memberId' => $memberId,
                ])
                : '',
        ];
    }

    private static function legacyDeliveryDate(object $record): string
    {
        $raw = $record->delivery ?? null;
        if ($raw === null) {
            return '';
        }

        $delivery = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($delivery)) {
            return '';
        }

        return DemoMaternalCare::deliveryHistoryDate($delivery);
    }

    private static function monthFromDate(string $isoDate): string
    {
        if ($isoDate === '') {
            return '';
        }

        return preg_match('/^\d{4}-(\d{2})-/', $isoDate, $match) ? $match[1] : '';
    }

    private static function isDeliveredThisMonth(string $deliveryDateIso, bool $isDelivered): bool
    {
        if (! $isDelivered || $deliveryDateIso === '') {
            return false;
        }

        try {
            $delivery = Carbon::parse($deliveryDateIso);
            $now = now()->timezone((string) config('app.timezone'));

            return $delivery->year === $now->year && $delivery->month === $now->month;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function legacyDeliveryType(object $record): string
    {
        $raw = $record->delivery ?? null;
        if ($raw === null) {
            return '';
        }

        $delivery = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($delivery)) {
            return '';
        }

        $type = strtoupper(trim((string) ($delivery['delivery_type'] ?? $delivery['type'] ?? '')));

        return in_array($type, ['CS', 'VD', 'CVCD'], true) ? $type : '';
    }

    private static function legacyPrenatalVisitCount(mixed $prenatal): ?int
    {
        if ($prenatal === null) {
            return null;
        }

        if (is_string($prenatal) && trim($prenatal) !== '') {
            $prenatal = json_decode($prenatal, true);
        }

        $data = is_array($prenatal) ? $prenatal : [];

        if ($data === []) {
            return 0;
        }

        $count = 0;
        foreach ($data as $visit) {
            if (! is_array($visit)) {
                continue;
            }

            if (trim((string) ($visit['date'] ?? '')) !== '') {
                $count++;
            }
        }

        return $count;
    }

    private static function isoBirthday(mixed $birthday): string
    {
        if ($birthday instanceof \DateTimeInterface) {
            return $birthday->format('Y-m-d');
        }

        $raw = trim((string) $birthday);

        return $raw;
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
            return $raw;
        }
    }
}
