<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Barangay-wide Maternal Care listing (Health Records → Maternal).
 *
 * Residents come only from Household Profiling DemoCatalog members.
 * Eligibility is female-only (women and girls). Male members are never
 * included in rows() or summaryCounts().
 *
 * Clinical listing cells prefer DemoMaternalCare session state when present;
 * otherwise a UI-phase overlay is used for preview vocabulary. Overlay is
 * never applied to ineligible (male) members because rows() never receives them.
 * Catalog sex values are not rewritten.
 */
final class HealthRecordsMaternal
{
    public const EMPTY = '—';

    /**
     * @return list<string>
     */
    public static function zones(): array
    {
        return [
            'Zone 1',
            'Zone 2',
            'Zone 3',
            'Zone 4',
            'Zone 5',
        ];
    }

    /**
     * Eligible female resident rows for the listing.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::eligibleResidents() as $resident) {
            $rows[] = self::toDisplayRow($resident);
        }

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
     * Female Household Profiling residents only.
     *
     * @return list<array{household_no: string, zone: string, member: array<string, mixed>}>
     */
    public static function eligibleResidents(): array
    {
        $eligible = [];

        foreach (self::catalogResidents() as $resident) {
            if (self::isEligibleResident($resident['member'])) {
                $eligible[] = $resident;
            }
        }

        return $eligible;
    }

    /**
     * Every Household Profiling catalog member (unfiltered). Used to prove exclusions.
     *
     * @return list<array{household_no: string, zone: string, member: array<string, mixed>}>
     */
    public static function catalogResidents(): array
    {
        $residents = [];

        foreach (DemoCatalog::households() as $household) {
            if (! is_array($household)) {
                continue;
            }

            $householdNo = (string) ($household['householdNo'] ?? '');
            $zone = (string) ($household['zone'] ?? '');

            foreach ($household['memberList'] ?? [] as $member) {
                if (! is_array($member)) {
                    continue;
                }

                $memberId = trim((string) ($member['id'] ?? ''));
                if ($memberId === '') {
                    continue;
                }

                $residents[] = [
                    'household_no' => $householdNo,
                    'zone' => $zone,
                    'member' => $member,
                ];
            }
        }

        return $residents;
    }

    /**
     * @param  array<string, mixed>  $member
     */
    public static function isEligibleResident(array $member): bool
    {
        return self::isFemaleSex((string) ($member['sex'] ?? ''));
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

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array{
     *     total: int,
     *     high_risk: int,
     *     due_prenatal: int,
     *     delivered: int,
     *     incomplete_prenatal: int
     * }
     */
    public static function summaryCounts(?array $rows = null): array
    {
        $rows ??= self::rows();
        $highRisk = 0;
        $due = 0;
        $delivered = 0;
        $incomplete = 0;

        foreach ($rows as $row) {
            if (! empty($row['is_high_risk'])) {
                $highRisk++;
            }
            if (! empty($row['is_due_prenatal'])) {
                $due++;
            }
            if (! empty($row['is_delivered'])) {
                $delivered++;
            }
            if (! empty($row['is_incomplete_prenatal'])) {
                $incomplete++;
            }
        }

        return [
            'total' => count($rows),
            'high_risk' => $highRisk,
            'due_prenatal' => $due,
            'delivered' => $delivered,
            'incomplete_prenatal' => $incomplete,
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
        $raw = trim((string) $isoDate);
        if ($raw === '') {
            return self::EMPTY;
        }

        try {
            return Carbon::parse($raw)->format('m-d-y');
        } catch (\Throwable) {
            return self::EMPTY;
        }
    }

    /**
     * @param  array{household_no: string, zone: string, member: array<string, mixed>}  $resident
     * @return array<string, mixed>
     */
    private static function toDisplayRow(array $resident): array
    {
        $member = $resident['member'];
        $householdNo = (string) $resident['household_no'];
        $memberId = strtoupper(trim((string) ($member['id'] ?? '')));
        $ageYears = HealthRecordsRiskAssessment::ageInYears($member);
        $overlay = self::listingOverlay()[$memberId] ?? [];

        $pregnancy = DemoMaternalCare::activePregnancy($householdNo, $memberId);
        if ($pregnancy === null) {
            $history = DemoMaternalCare::history($householdNo, $memberId);
            $pregnancy = $history[0] ?? null;
        }

        $lmp = is_array($pregnancy) ? (string) ($pregnancy['lmp'] ?? '') : '';
        $edd = is_array($pregnancy) ? (string) ($pregnancy['edd'] ?? '') : '';
        $gravida = is_array($pregnancy) ? (string) ($pregnancy['gravida'] ?? '') : '';
        $parity = is_array($pregnancy) ? (string) ($pregnancy['parity'] ?? '') : '';
        $gestation = DemoMaternalCare::gestationalInfo($lmp !== '' ? $lmp : null);
        $prenatalCount = is_array($pregnancy)
            ? self::countPrenatalVisits($pregnancy)
            : null;
        $deliveryType = is_array($pregnancy)
            ? self::deliveryTypeCode($pregnancy)
            : '';

        $hasClinical = is_array($pregnancy);

        $row = [
            'key' => strtolower($householdNo.'-'.$memberId),
            'household_no' => $householdNo,
            'member_id' => $memberId,
            'full_name' => self::displayName($member),
            'sex' => (string) ($member['sex'] ?? ''),
            'sex_normalized' => strtolower(trim((string) ($member['sex'] ?? ''))),
            'age_years' => $ageYears,
            'age_group' => self::ageGroupLetter($ageYears),
            'zone' => (string) $resident['zone'],
            'year' => (string) ($overlay['year'] ?? '2026'),
            'lmp' => self::listingValue(
                $overlay,
                'lmp',
                $hasClinical && $lmp !== '' ? self::formatListingDate($lmp) : ''
            ),
            'gravida_parity' => self::listingValue(
                $overlay,
                'gravida_parity',
                $hasClinical ? self::gravidaParityLabel($gravida, $parity) : ''
            ),
            'edd' => self::listingValue(
                $overlay,
                'edd',
                $hasClinical && $edd !== '' ? self::formatListingDate($edd) : ''
            ),
            'delivery_type' => self::listingValue(
                $overlay,
                'delivery_type',
                $deliveryType
            ),
            'trimester' => self::listingValue(
                $overlay,
                'trimester',
                $hasClinical && ($gestation['trimester_key'] ?? '') !== ''
                    ? self::shortTrimester((string) $gestation['trimester_label'])
                    : ''
            ),
            'prenatal_visits' => self::listingValue(
                $overlay,
                'prenatal_visits',
                $prenatalCount !== null ? (string) $prenatalCount : ''
            ),
            'is_high_risk' => (bool) ($overlay['is_high_risk'] ?? false),
            'is_due_prenatal' => (bool) ($overlay['is_due_prenatal'] ?? false),
            'is_delivered' => (bool) ($overlay['is_delivered'] ?? ($deliveryType !== '')),
            'is_incomplete_prenatal' => (bool) ($overlay['is_incomplete_prenatal'] ?? false),
            'population' => 'resident',
        ];

        return $row;
    }

    private static function listingValue(array $overlay, string $key, string $fromPregnancy): string
    {
        $fromOverlay = trim((string) ($overlay[$key] ?? ''));
        if ($fromOverlay !== '' && $fromOverlay !== self::EMPTY && $fromOverlay !== '-') {
            return $fromOverlay;
        }

        $fromPregnancy = trim($fromPregnancy);
        if ($fromPregnancy !== '' && $fromPregnancy !== self::EMPTY && $fromPregnancy !== '-') {
            return $fromPregnancy;
        }

        return self::defaultClinicalValue($key);
    }

    private static function defaultClinicalValue(string $key): string
    {
        return match ($key) {
            'lmp' => '06-15-25',
            'gravida_parity' => '1-0',
            'edd' => '03-22-26',
            'delivery_type' => 'VD',
            'trimester' => '2nd',
            'prenatal_visits' => '2',
            default => 'VD',
        };
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     */
    private static function countPrenatalVisits(array $pregnancy): int
    {
        $prenatal = is_array($pregnancy['prenatal'] ?? null) ? $pregnancy['prenatal'] : [];
        $count = 0;

        foreach (DemoMaternalCare::prenatalSchedule() as $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $row = is_array($prenatal[$visit['key']] ?? null) ? $prenatal[$visit['key']] : [];
                if (trim((string) ($row['date'] ?? '')) !== '') {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     */
    private static function deliveryTypeCode(array $pregnancy): string
    {
        $delivery = is_array($pregnancy['delivery'] ?? null) ? $pregnancy['delivery'] : $pregnancy;
        $type = strtoupper(trim((string) ($delivery['delivery_type'] ?? $delivery['type'] ?? '')));

        return in_array($type, ['CS', 'VD', 'CVCD'], true) ? $type : '';
    }

    private static function gravidaParityLabel(string $gravida, string $parity): string
    {
        $g = trim($gravida);
        $p = trim($parity);
        if ($g === '' && $p === '') {
            return self::EMPTY;
        }

        return ($g !== '' ? $g : self::EMPTY).'-'.($p !== '' ? $p : self::EMPTY);
    }

    private static function shortTrimester(string $label): string
    {
        if (str_contains($label, '1st')) {
            return '1st';
        }
        if (str_contains($label, '2nd')) {
            return '2nd';
        }
        if (str_contains($label, '3rd')) {
            return '3rd';
        }

        return self::EMPTY;
    }

    /**
     * UI-phase listing vocabulary keyed by catalog member id.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function listingOverlay(): array
    {
        return [
            'MB-002' => [
                'year' => '2026',
                'lmp' => '07-08-24',
                'gravida_parity' => '2-1',
                'edd' => '04-20-25',
                'delivery_type' => 'CS',
                'trimester' => '3rd',
                'prenatal_visits' => '2',
                'is_high_risk' => true,
                'is_due_prenatal' => true,
                'is_delivered' => false,
                'is_incomplete_prenatal' => true,
            ],
            'MB-012' => [
                'year' => '2026',
                'lmp' => '03-12-25',
                'gravida_parity' => '1-0',
                'edd' => '12-17-25',
                'delivery_type' => 'VD',
                'trimester' => '2nd',
                'prenatal_visits' => '1',
                'is_high_risk' => false,
                'is_due_prenatal' => true,
                'is_delivered' => false,
                'is_incomplete_prenatal' => true,
            ],
            'MB-006' => [
                'year' => '2025',
                'lmp' => '01-04-25',
                'gravida_parity' => '3-2',
                'edd' => '10-11-25',
                'delivery_type' => 'VD',
                'trimester' => '3rd',
                'prenatal_visits' => '4',
                'is_high_risk' => false,
                'is_due_prenatal' => false,
                'is_delivered' => true,
                'is_incomplete_prenatal' => false,
            ],
            'MB-008' => [
                'year' => '2026',
                'lmp' => '11-02-25',
                'gravida_parity' => '1-0',
                'edd' => '08-09-26',
                'delivery_type' => 'CS',
                'trimester' => '1st',
                'prenatal_visits' => '1',
                'is_high_risk' => false,
                'is_due_prenatal' => false,
                'is_delivered' => false,
                'is_incomplete_prenatal' => false,
            ],
            'MB-009' => [
                'year' => '2026',
                'lmp' => '09-18-25',
                'gravida_parity' => '1-0',
                'edd' => '06-25-26',
                'delivery_type' => 'VD',
                'trimester' => '1st',
                'prenatal_visits' => '1',
                'is_high_risk' => false,
                'is_due_prenatal' => false,
                'is_delivered' => false,
                'is_incomplete_prenatal' => true,
            ],
        ];
    }
}
