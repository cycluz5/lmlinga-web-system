<?php

namespace App\Support;

use App\Models\DeathRequest;

/**
 * Health Records → Death listing helpers.
 * Listing rows come from persisted death_requests. Resident candidates come from DemoCatalog.
 */
final class HealthRecordsDeath
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
     * @return list<array<string, mixed>>
     */
    public static function listingRows(): array
    {
        return DeathRequest::query()
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (DeathRequest $row): array => self::fromRequest($row))
            ->all();
    }

    /**
     * Living and in-progress catalog members a health worker may open a Death form for.
     *
     * @return list<array<string, mixed>>
     */
    public static function residentCandidates(): array
    {
        $latestByMember = DeathRequest::query()
            ->orderByDesc('id')
            ->get()
            ->unique(static fn (DeathRequest $row): string => $row->household_no.'|'.$row->member_id)
            ->keyBy(static fn (DeathRequest $row): string => $row->household_no.'|'.$row->member_id);

        $approved = array_fill_keys(ResidentVitalStatus::deceasedKeys(), true);
        $rows = [];

        foreach (DemoCatalog::households() as $householdNo => $household) {
            if (! is_array($household)) {
                continue;
            }

            foreach ($household['memberList'] ?? [] as $member) {
                if (! is_array($member)) {
                    continue;
                }

                $memberId = DemoCatalog::normalizeMemberId((string) ($member['id'] ?? ''));
                if ($memberId === '') {
                    continue;
                }

                $hh = DemoCatalog::normalizeHouseholdNo((string) $householdNo);
                $key = $hh.'|'.$memberId;
                $latest = $latestByMember->get($key);
                $isDeceased = isset($approved[$key]);
                $fullName = (string) ($member['name'] ?? 'Resident');
                $sex = (string) ($member['sex'] ?? '');
                $age = (string) ($member['age'] ?? self::EMPTY);
                $relationship = (string) ($member['relationship'] ?? '');
                $birthday = function_exists('lml_demo_member_display')
                    ? lml_demo_member_display($member, 'birthday')
                    : (string) ($member['birthday'] ?? '');

                $rows[] = [
                    'household_no' => $hh,
                    'member_id' => $memberId,
                    'full_name' => $fullName,
                    'sex' => $sex,
                    'age' => $age,
                    'relationship' => $relationship,
                    'birthday_display' => $birthday,
                    'identity_search' => strtolower(trim(implode(' ', array_filter([
                        $fullName,
                        $memberId,
                        $relationship,
                        $sex,
                        $birthday,
                    ])))),
                    'zone' => (string) ($household['zone'] ?? $household['purok'] ?? ''),
                    'household_display' => (string) ($household['displayNo'] ?? $hh),
                    'vital_label' => $isDeceased
                        ? ResidentVitalStatus::DECEASED
                        : ($latest?->statusLabel() ?? 'Active'),
                    'status' => $latest?->status ?? 'none',
                    'open_url' => route('health-records.death.show', [
                        'householdNo' => $hh,
                        'memberId' => $memberId,
                    ]),
                    'can_submit' => ! $isDeceased && ($latest === null || $latest->isRejected()),
                ];
            }
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                $byName = strnatcasecmp((string) $a['full_name'], (string) $b['full_name']);

                return $byName !== 0
                    ? $byName
                    : strnatcasecmp((string) $a['member_id'], (string) $b['member_id']);
            }
        );

        return $rows;
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : '?';
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array{total: int, female: int, male: int}
     */
    public static function summaryCounts(?array $rows = null): array
    {
        $rows ??= array_values(array_filter(
            self::listingRows(),
            static fn (array $row): bool => ($row['status'] ?? '') === DeathRequest::STATUS_APPROVED
        ));
        $female = 0;
        $male = 0;

        foreach ($rows as $row) {
            $sex = (string) ($row['sex'] ?? '');
            if (HealthRecordsMaternal::isFemaleSex($sex)) {
                $female++;
            } elseif (HealthRecordsMaternal::isMaleSex($sex)) {
                $male++;
            }
        }

        return [
            'total' => count($rows),
            'female' => $female,
            'male' => $male,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return list<string>
     */
    public static function years(?array $rows = null): array
    {
        $rows ??= self::listingRows();
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
     * @param  list<array<string, mixed>>|null  $rows
     * @return list<string>
     */
    public static function causes(?array $rows = null): array
    {
        $rows ??= self::listingRows();
        $causes = [];

        foreach ($rows as $row) {
            $cause = trim((string) ($row['cause_of_death'] ?? ''));
            if ($cause !== '' && $cause !== self::EMPTY) {
                $causes[$cause] = true;
            }
        }

        $list = array_keys($causes);
        natcasesort($list);

        return array_values($list);
    }

    /**
     * Same matching rules as the listing's client-side filters.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{search?: string, zone?: string, cause?: string, sex?: string, year?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public static function filterRows(array $rows, array $filters): array
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $zone = (string) ($filters['zone'] ?? 'all');
        $cause = (string) ($filters['cause'] ?? 'all');
        $sex = (string) ($filters['sex'] ?? 'all');
        $year = (string) ($filters['year'] ?? 'all');

        $matched = [];

        foreach ($rows as $row) {
            $name = strtolower(trim(
                (string) ($row['full_name'] ?? '').' '.(string) ($row['member_id'] ?? '')
            ));
            $rowZone = (string) ($row['zone'] ?? '');
            $rowCause = (string) ($row['cause_of_death'] ?? '');
            $rowSex = (string) ($row['sex_filter'] ?? '');
            $rowYear = (string) ($row['year'] ?? '');

            $matchesSearch = $search === '' || str_contains($name, $search);
            $matchesZone = $zone === 'all' || $rowZone === $zone;
            $matchesCause = $cause === 'all' || $rowCause === $cause;
            $matchesSex = $sex === 'all' || $rowSex === $sex;
            $matchesYear = $year === 'all' || $rowYear === $year;

            if ($matchesSearch && $matchesZone && $matchesCause && $matchesSex && $matchesYear) {
                $matched[] = $row;
            }
        }

        return $matched;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(DeathRequest $request): array
    {
        $iso = $request->date_of_death?->format('Y-m-d') ?? '';
        $year = preg_match('/^(\d{4})-/', $iso, $match) ? $match[1] : '';
        $sex = (string) $request->resident_sex;

        return [
            'key' => (string) $request->id,
            'request_id' => $request->id,
            'household_no' => $request->household_no,
            'member_id' => $request->member_id,
            'full_name' => $request->resident_name,
            'age' => $request->resident_age !== null ? (string) $request->resident_age : self::EMPTY,
            'sex' => $sex,
            'sex_filter' => HealthRecordsMaternal::isFemaleSex($sex)
                ? 'female'
                : (HealthRecordsMaternal::isMaleSex($sex) ? 'male' : ''),
            'zone' => (string) ($request->zone ?: self::EMPTY),
            'cause_of_death' => $request->cause_of_death,
            'date_of_death' => $request->formattedDateOfDeath(),
            'date_of_death_iso' => $iso,
            'year' => $year,
            'certificate_no' => $request->certificate_no,
            'status' => $request->status,
            'status_label' => $request->statusLabel(),
            'open_url' => route('health-records.death.show', [
                'householdNo' => $request->household_no,
                'memberId' => $request->member_id,
            ]),
        ];
    }
}
