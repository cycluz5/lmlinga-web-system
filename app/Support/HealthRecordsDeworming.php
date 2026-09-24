<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Deworming support for Health Records monitoring and Household Profiling workflows.
 *
 * Barangay-wide monitoring ({@see monitoringRows()}, {@see summaryCards()},
 * {@see zones()}) reads persisted residents and deworming_records via
 * {@see DewormingMonitoringService}.
 *
 * Individual member resolution uses {@see HealthMemberIdentity} / persisted residents.
 * {@see findChild()} never substitutes DemoCatalog for a missing database row.
 */
final class HealthRecordsDeworming
{
    public const EMPTY_CELL = '';

    public const SCHOOL_NOT_YET = 'Not yet school-aged';

    public const REMARKS_NONE = 'No concerns reported';

    /**
     * @return array{
     *     first_round: string,
     *     second_round: string,
     *     received_1_dose_pct: string,
     *     received_2_dose_pct: string
     * }
     */
    public static function summaryCards(): array
    {
        $service = app(DewormingMonitoringService::class);
        $rows = $service->monitoringRowsForYear($service->currentMonitoringYear());

        return $service->summaryCardsForRows($rows);
    }

    /**
     * Project-supported Deworming round values (same domain as Non-Resident UI-phase).
     *
     * @return list<string>
     */
    public static function roundOptions(): array
    {
        return ['1', '2'];
    }

    /**
     * Project-supported SE Status labels (Household NHTS terminology).
     *
     * @return list<string>
     */
    public static function seStatusOptions(): array
    {
        return ['NHTS', 'Non-NHTS'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function monitoringRows(): array
    {
        $service = app(DewormingMonitoringService::class);

        return $service->monitoringRowsForYear($service->currentMonitoringYear());
    }

    /**
     * Zone/sex/status-filtered current-year monitoring rows — the
     * Deworming Report Builder's data source.
     *
     * @return list<array<string, mixed>>
     */
    public static function exportRows(?string $zone = null, ?string $sex = null, ?string $status = null): array
    {
        return array_values(array_filter(
            self::monitoringRows(),
            static function (array $row) use ($zone, $sex, $status): bool {
                if ($zone !== null && trim((string) ($row['zone'] ?? '')) !== $zone) {
                    return false;
                }
                if ($sex !== null && (string) ($row['sex'] ?? '') !== $sex) {
                    return false;
                }
                if ($status !== null && (string) ($row['status'] ?? '') !== $status) {
                    return false;
                }

                return true;
            }
        ));
    }

    public static function childKeyFromDisplayName(string $name): string
    {
        return self::slugifyName($name);
    }

    public static function resolveCanonicalMemberDewormingUrl(string $childKey): ?string
    {
        return app(DewormingMonitoringService::class)->memberDewormingUrlForChildKey($childKey);
    }

    /**
     * Resolve a resident Deworming child profile for the individual/add workflow.
     * Persisted residents only — unknown slugs return null (no DemoCatalog substitution).
     *
     * @return array<string, mixed>|null
     */
    public static function findChild(string $childKey): ?array
    {
        $resident = app(DewormingMonitoringService::class)->residentForChildKey($childKey);
        if ($resident === null || $resident->household === null) {
            return null;
        }

        return self::findChildForMember(
            (string) $resident->household->household_no,
            (string) $resident->member_no
        );
    }

    /**
     * Resolve a resident Deworming child profile from Household Profiling context.
     * Always scopes workflow URLs to the household member route group.
     *
     * @return array<string, mixed>|null
     */
    public static function findChildForMember(string $householdNo, string $memberId): ?array
    {
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);
        $key = $ctx['householdNo'];
        $memberKey = $ctx['memberId'];
        $household = $ctx['household'];
        $member = $ctx['member'];

        if ($household === null || $member === null) {
            return null;
        }

        $childKey = self::slugifyName(HealthRecordsChildCare::displayName($member));
        $profile = self::buildChildProfileFromHousehold($childKey, $household, $member, $key);

        return self::withHouseholdProfilingUrls($profile, $key, $memberKey);
    }

    /**
     * Whether Deworming records may be managed for this household member.
     *
     * Deworming is available for ALL ages. Age is never a gate.
     * Callers must resolve $member from the requested household first.
     *
     * @param  array<string, mixed>  $member  Member row already scoped to a household
     */
    public static function memberCanManageRecords(array $member): bool
    {
        return $member !== [];
    }

    /**
     * Deworming history for a household member resolved by stable identifiers.
     * Persisted residents read deworming_records; unknown members return no rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function recordsForMember(string $householdNo, string $memberId): array
    {
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);
        $member = $ctx['member'];
        if ($member === null || ! self::memberCanManageRecords($member)) {
            return [];
        }

        if ($ctx['source'] === 'db' && $ctx['resident'] !== null) {
            return app(DewormingRecordService::class)->recordsForResident($ctx['resident']);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function withHouseholdProfilingUrls(
        array $profile,
        string $householdNo,
        string $memberId
    ): array {
        $profile['view_url'] = route('household-profiling.members.deworming', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $profile['create_url'] = route('household-profiling.members.deworming.create', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $profile['back_url'] = route('household-profiling.members.show', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);

        return $profile;
    }

    /**
     * Deworming history for a monitoring child key.
     * Production identity uses {@see recordsForMember()}; slug lookup is DB-only.
     *
     * @return list<array<string, mixed>>
     */
    public static function recordsFor(string $childKey): array
    {
        $child = self::findChild($childKey);
        if ($child === null) {
            return [];
        }

        $householdNo = trim((string) ($child['household_no'] ?? ''));
        $memberId = trim((string) ($child['member_id'] ?? ''));
        if ($householdNo === '' || $memberId === '') {
            return [];
        }

        return self::recordsForMember($householdNo, $memberId);
    }

    /**
     * Status filter options for the Deworming UI-phase toolbar.
     *
     * @return array<string, string>
     */
    public static function statusFilterOptions(): array
    {
        return [
            'all' => 'Status',
            '1-dose' => 'Received 1 dose/year',
            '2-doses' => 'Received 2 dose/year',
            'none' => 'No dose recorded',
        ];
    }

    /**
     * @return list<string>
     */
    public static function zones(): array
    {
        $service = app(DewormingMonitoringService::class);
        $rows = $service->monitoringRowsForYear($service->currentMonitoringYear());

        return $service->zonesForRows($rows);
    }

    /**
     * @param  array<string, mixed>  $household
     * @param  array<string, mixed>  $member
     * @return array<string, mixed>
     */
    private static function buildChildProfileFromHousehold(
        string $childKey,
        array $household,
        array $member,
        string $householdNo
    ): array {
        $birthday = trim((string) ($member['birthday'] ?? ''));
        $ageMonths = HealthRecordsChildCare::ageInMonths($member);
        $sex = trim((string) ($member['sex'] ?? ''));
        $education = trim((string) ($member['education'] ?? ''));
        $schoolGrade = self::schoolGradeLabel($education, $ageMonths);

        return self::withWorkflowUrls([
            'key' => $childKey,
            'household_no' => $householdNo,
            'member_id' => (string) ($member['id'] ?? ''),
            'full_name' => HealthRecordsChildCare::displayName($member),
            'sex' => $sex !== '' ? $sex : 'Sex not recorded',
            'birthday' => $birthday,
            'birthday_label' => self::formatDisplayDate($birthday),
            'age_months' => $ageMonths,
            'age_label' => $ageMonths === null
                ? 'Age not recorded'
                : HealthRecordsChildCare::formatAgeMonths($ageMonths),
            'zone' => self::zoneLabelForHousehold($household),
            'school_grade_label' => $schoolGrade,
        ]);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private static function withWorkflowUrls(array $profile): array
    {
        $childKey = (string) $profile['key'];

        $profile['view_url'] = route('health-records.child-care.deworming.show', [
            'childKey' => $childKey,
        ]);
        $profile['create_url'] = route('health-records.child-care.deworming.create', [
            'childKey' => $childKey,
        ]);
        $profile['summary_url'] = route('health-records.child-care.deworming');

        return $profile;
    }

    private static function schoolGradeLabel(string $education, ?int $ageMonths): string
    {
        if ($education !== '' && strcasecmp($education, 'N/A') !== 0) {
            return $education;
        }

        if ($ageMonths === null || $ageMonths < 36) {
            return self::SCHOOL_NOT_YET;
        }

        return self::SCHOOL_NOT_YET;
    }

    /**
     * Household location for Deworming member profile: presentation Zone label,
     * else purok mapped through HouseholdZoneResolver. Never address/street.
     *
     * @param  array<string, mixed>  $household
     */
    private static function zoneLabelForHousehold(array $household): string
    {
        foreach (['zone', 'purok'] as $key) {
            $raw = trim((string) ($household[$key] ?? ''));
            if ($raw === '') {
                continue;
            }

            $zoneNumber = HouseholdZoneResolver::zoneNumberFromLabel($raw)
                ?? HouseholdZoneResolver::zoneNumberFromStoredValue($raw);

            if ($zoneNumber !== null) {
                return 'Zone '.$zoneNumber;
            }
        }

        return HealthRecordsChildCare::EMPTY_RECORD;
    }

    private static function formatDisplayDate(string $isoDate): string
    {
        $isoDate = trim($isoDate);
        if ($isoDate === '') {
            return 'Date of birth not recorded';
        }

        try {
            $formatted = \App\Support\DisplayDate::format($isoDate);

            return $formatted !== '' ? $formatted : $isoDate;
        } catch (\Throwable) {
            return $isoDate;
        }
    }

    private static function slugifyName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}
