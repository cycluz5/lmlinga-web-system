<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Support\Facades\DB;

/**
 * Panel refinement S-02: PhilHealth membership type of a household member.
 *
 * Sources: PMRF UHC v.1 (January 2020), Section IV "Member Type" for the first 20 options;
 * PhilHealth Circular 2026-0015, Section V(B), for Solo Parent; PhilHealth Circular 2026-0017,
 * Section V(D)(3), for Employed Person with Disability. Groups follow RA 11223, Section 8.
 *
 * Classification only: no membership status, and never inferred from other resident fields.
 * The list is repeated in the migration and in resources/js/offline/offline-philhealth-membership-types.js;
 * PhilhealthMembershipTypeTest keeps the three identical.
 */
final class PhilhealthMembershipTypes
{
    public const TABLE = 'philhealth_membership_type';

    public const COLUMN = 'philhealth_membership_type_id';

    public const GROUP_DIRECT = 'direct';

    public const GROUP_INDIRECT = 'indirect';

    /** @var array<string, array{label: string, group: string}> */
    public const OPTIONS = [
        'employed_private' => ['label' => 'Employed Private', 'group' => self::GROUP_DIRECT],
        'employed_government' => ['label' => 'Employed Government', 'group' => self::GROUP_DIRECT],
        'professional_practitioner' => ['label' => 'Professional Practitioner', 'group' => self::GROUP_DIRECT],
        'self_earning_individual' => ['label' => 'Self-Earning Individual', 'group' => self::GROUP_DIRECT],
        'kasambahay' => ['label' => 'Kasambahay', 'group' => self::GROUP_DIRECT],
        'family_driver' => ['label' => 'Family Driver', 'group' => self::GROUP_DIRECT],
        'migrant_worker' => ['label' => 'Migrant Worker', 'group' => self::GROUP_DIRECT],
        'lifetime_member' => ['label' => 'Lifetime Member', 'group' => self::GROUP_DIRECT],
        'dual_citizen_living_abroad' => ['label' => 'Filipinos with Dual Citizenship / Living Abroad', 'group' => self::GROUP_DIRECT],
        'foreign_national' => ['label' => 'Foreign National', 'group' => self::GROUP_DIRECT],
        'listahanan' => ['label' => 'Listahanan', 'group' => self::GROUP_INDIRECT],
        'four_ps_mcct' => ['label' => '4Ps/MCCT', 'group' => self::GROUP_INDIRECT],
        'senior_citizen' => ['label' => 'Senior Citizen', 'group' => self::GROUP_INDIRECT],
        'pamana' => ['label' => 'PAMANA', 'group' => self::GROUP_INDIRECT],
        'kia_kipo' => ['label' => 'KIA/KIPO', 'group' => self::GROUP_INDIRECT],
        'bangsamoro_normalization' => ['label' => 'Bangsamoro/Normalization', 'group' => self::GROUP_INDIRECT],
        'lgu_sponsored' => ['label' => 'LGU-sponsored', 'group' => self::GROUP_INDIRECT],
        'nga_sponsored' => ['label' => 'NGA-sponsored', 'group' => self::GROUP_INDIRECT],
        'private_sponsored' => ['label' => 'Private-sponsored', 'group' => self::GROUP_INDIRECT],
        'person_with_disability' => ['label' => 'Person with Disability', 'group' => self::GROUP_INDIRECT],
        'solo_parent' => ['label' => 'Solo Parent', 'group' => self::GROUP_INDIRECT],
        'employed_person_with_disability' => ['label' => 'Employed Person with Disability', 'group' => self::GROUP_INDIRECT],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::OPTIONS);
    }

    /** @return array<string, array<string, string>> group heading => [code => label] */
    public static function grouped(): array
    {
        $headings = [
            self::GROUP_DIRECT => 'Direct Contributor',
            self::GROUP_INDIRECT => 'Indirect Contributor',
        ];
        $grouped = [];
        foreach (self::OPTIONS as $code => $option) {
            $grouped[$headings[$option['group']]][$code] = $option['label'];
        }

        return $grouped;
    }

    public static function label(?string $code): string
    {
        return self::OPTIONS[(string) $code]['label'] ?? '';
    }

    /** False until the migration has run, so the form never offers a field that cannot be saved. */
    public static function available(): bool
    {
        $key = self::class.'.available';
        if (! app()->bound($key)) {
            $guard = app(DatabaseSchemaGuard::class);
            app()->instance($key, $guard->tableExists(self::TABLE)
                && $guard->columnExists((new Resident)->getTable(), self::COLUMN));
        }

        return app($key);
    }

    public static function idForCode(?string $code): ?int
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        $id = self::idsByCode()[$code] ?? null;

        return $id === null ? null : (int) $id;
    }

    public static function codeForId(mixed $id): string
    {
        if (! is_numeric($id)) {
            return '';
        }

        $code = array_search((int) $id, self::idsByCode(), true);

        return $code === false ? '' : (string) $code;
    }

    /** @return array<string, int> */
    private static function idsByCode(): array
    {
        $key = self::class.'.ids';
        if (! app()->bound($key)) {
            app()->instance($key, DB::table(self::TABLE)
                ->pluck('philhealth_membership_type_id', 'type_code')
                ->map(static fn ($id): int => (int) $id)
                ->all());
        }

        return app($key);
    }
}
