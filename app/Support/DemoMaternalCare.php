<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * Demo Maternal Care helpers.
 *
 * Session-backed preview for demo members. Persisted residents use
 * MaternalPregnancyService (DB-14). Shared sanitization / presentation shapes
 * keep the approved Maternal Care UI payload compatible.
 */
final class DemoMaternalCare
{
    public const SESSION_KEY = 'lml.demo.maternal_care.v1';

    public const OUTCOMES = [
        'FT' => 'Full Term',
        'PT' => 'Pre-Term',
        'FD' => 'Fetal Death',
        'AB' => 'Abortion',
    ];

    public const DELIVERY_TYPES = [
        'CS' => 'CS - Cesarean',
        'VD' => 'VD - Vaginal Delivery',
        'CVCD' => 'CVCD - Combined Vaginal Cesarean Delivery',
    ];

    public const BIRTH_ATTENDANTS = [
        'MD' => 'MD - Doctor',
        'RN' => 'RN - Nurse',
        'MW' => 'MW - Midwife',
        'Others' => 'Others',
    ];

    public const PLACES_OF_DELIVERY = [
        'public' => 'Public Health Facility',
        'private' => 'Private Health Facility',
        'non_health' => 'Non-Health Facility',
    ];

    public const NEWBORN_SEXES = [
        'Female' => 'Female',
        'Male' => 'Male',
    ];

    public const PLURALITIES = [
        'Single' => 'Single',
        'Twins' => 'Twins',
        'Multiple' => 'Multiple',
    ];

    public static function isTerminalOutcome(?string $outcome): bool
    {
        $code = strtoupper(trim((string) $outcome));

        return array_key_exists($code, self::OUTCOMES);
    }

    public static function sectionAllowsCompletedEpisode(string $section): bool
    {
        return in_array(strtolower(trim($section)), ['delivery', 'postnatal'], true);
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    public static function deliveryHistoryDate(array $delivery): string
    {
        $delivered = trim((string) ($delivery['datetime'] ?? ''))
            ?: trim((string) ($delivery['date_terminated'] ?? ''));

        return $delivered !== '' ? substr($delivered, 0, 10) : '';
    }

    /**
     * Read-time Pregnancy History visibility. Does not mutate the episode.
     * Every outcome (FT/PT/FD/AB) waits 42 inclusive days from the delivery
     * date before showing in history, so postnatal/post-loss care stays
     * reachable through the normal journey either way. Trans-Out is
     * immediately visible (the episode continues at another facility).
     *
     * @param  array<string, mixed>  $pregnancy
     */
    public static function isVisibleInPregnancyHistory(array $pregnancy, ?DateTimeInterface $today = null): bool
    {
        $status = strtolower(trim((string) ($pregnancy['status'] ?? '')));
        if ($status === 'transferred_out' || $status === 'trans-out') {
            return true;
        }
        if ($status !== 'completed') {
            return false;
        }

        $delivery = is_array($pregnancy['delivery'] ?? null) ? $pregnancy['delivery'] : [];
        $ref = self::deliveryHistoryDate($delivery);
        if ($ref === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref) !== 1) {
            return false;
        }

        $todayDate = $today instanceof \DateTimeInterface
            ? Carbon::instance($today)->startOfDay()
            : Carbon::today();

        try {
            $eligibleOn = Carbon::createFromFormat('Y-m-d', $ref, $todayDate->getTimezone());
        } catch (\Throwable) {
            return false;
        }

        if ($eligibleOn === false) {
            return false;
        }

        return $todayDate->gte($eligibleOn->startOfDay()->addDays(42));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function visiblePregnancyHistory(array $rows, ?DateTimeInterface $today = null): array
    {
        $visible = [];
        foreach ($rows as $row) {
            if (is_array($row) && self::isVisibleInPregnancyHistory($row, $today)) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    public const HEPATITIS_B_RESULTS = [
        'Reactive',
        'Negative',
    ];

    public const CBC_RESULTS = [
        'With Anemia',
        'Without Anemia',
    ];

    public const GDM_RESULTS = [
        'Positive',
        'Negative',
    ];

    public const SYPHILIS_RESULTS = [
        'REACTIVE',
        'NON REACTIVE',
    ];

    public const HIV_RESULTS = [
        'REACTIVE',
        'NON REACTIVE',
    ];

    /**
     * Prenatal visit schedule (8 visits) by trimester.
     *
     * @return array<string, array{label: string, weeks: string, visits: list<array{key: string, label: string}>}>
     */
    public static function prenatalSchedule(): array
    {
        return [
            'first' => [
                'label' => '1st Trimester',
                'weeks' => '0–12 weeks',
                'visits' => [
                    ['key' => 't1_v1', 'label' => '1st Visit'],
                ],
            ],
            'second' => [
                'label' => '2nd Trimester',
                'weeks' => '13–27 weeks',
                'visits' => [
                    ['key' => 't2_v1', 'label' => '1st Visit'],
                    ['key' => 't2_v2', 'label' => '2nd Visit'],
                ],
            ],
            'third' => [
                'label' => '3rd Trimester',
                'weeks' => '28–40 weeks',
                'visits' => [
                    ['key' => 't3_v1', 'label' => '1st Visit'],
                    ['key' => 't3_v2', 'label' => '2nd Visit'],
                    ['key' => 't3_v3', 'label' => '3rd Visit'],
                    ['key' => 't3_v4', 'label' => '4th Visit'],
                    ['key' => 't3_v5', 'label' => '5th Visit'],
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     deworming: array{label: string, max: int},
     *     ifa: array{label: string, max: int, visits: list<array{key: string, label: string}>},
     *     mms: array{label: string, max: int, visits: list<array{key: string, label: string}>},
     *     calcium: array{label: string, max: int, high_risk_only: bool, visits: list<array{key: string, label: string}>},
     *     rusf: array{label: string}
     * }
     */
    public static function supplementationSchedule(): array
    {
        $sixVisits = [
            ['key' => 'v1', 'label' => 'Visit 1 (1st Trimester)', 'trimester_key' => 'first'],
            ['key' => 'v2', 'label' => 'Visit 2 (2nd Trimester)', 'trimester_key' => 'second'],
            ['key' => 'v3', 'label' => 'Visit 3 (2nd Trimester)', 'trimester_key' => 'second'],
            ['key' => 'v4', 'label' => 'Visit 4 (3rd Trimester)', 'trimester_key' => 'third'],
            ['key' => 'v5', 'label' => 'Visit 5 (3rd Trimester)', 'trimester_key' => 'third'],
            ['key' => 'v6', 'label' => 'Visit 6 (3rd Trimester)', 'trimester_key' => 'third'],
        ];

        return [
            'deworming' => [
                'label' => 'Deworming Tablet',
                'max' => 1,
            ],
            'ifa' => [
                'label' => 'Iron with Folic Acid Supplementation',
                'max' => 6,
                'visits' => $sixVisits,
            ],
            'mms' => [
                'label' => 'Multiple Micronutrient Supplementation',
                'max' => 6,
                'visits' => $sixVisits,
            ],
            'calcium' => [
                'label' => 'Calcium Carbonate Supplementation',
                'max' => 3,
                'high_risk_only' => true,
                'visits' => [
                    ['key' => 'v1', 'label' => 'Visit 1'],
                    ['key' => 'v2', 'label' => 'Visit 2'],
                    ['key' => 'v3', 'label' => 'Visit 3'],
                ],
            ],
            'rusf' => [
                'label' => 'Ready-to-Use Supplementary Food (RUSF)',
            ],
        ];
    }

    /**
     * @return list<array{key: string, label: string, hint: string}>
     */
    public static function postnatalContacts(): array
    {
        return [
            ['key' => 'c1', 'label' => 'Contact 1', 'hint' => 'Within 24 hrs after delivery'],
            ['key' => 'c2', 'label' => 'Contact 2', 'hint' => 'On day 3'],
            ['key' => 'c3', 'label' => 'Contact 3', 'hint' => 'Between 7–14 days'],
            ['key' => 'c4', 'label' => 'Contact 4', 'hint' => '6 weeks after birth'],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function postpartumSupplementationVisits(): array
    {
        return [
            ['key' => 'v1', 'label' => 'Visit 1'],
            ['key' => 'v2', 'label' => 'Visit 2'],
            ['key' => 'v3', 'label' => 'Visit 3'],
        ];
    }

    /**
     * DB-first identity via HouseholdMemberResolver; DemoCatalog read fallback.
     * Presentation shape preserved for frozen Maternal Care UI.
     *
     * @return array{household: array<string, mixed>|null, member: array<string, mixed>|null, householdNo: string, memberId: string}
     */
    public static function resolveMember(string $householdNo, string $memberId): array
    {
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);

        return [
            'household' => $ctx['household'],
            'member' => $ctx['member'],
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
        ];
    }

    /**
     * @return array<string, mixed>|null Active pregnancy record or null when none.
     */
    public static function activePregnancy(string $householdNo, string $memberId): ?array
    {
        $state = self::memberState($householdNo, $memberId);
        $active = $state['active'] ?? null;

        return is_array($active) ? self::normalizePregnancy($active) : null;
    }

    /**
     * Active pregnancy, or the most recently completed one if it hasn't
     * crossed the Pregnancy History visibility gate yet — mirrors
     * MaternalCareErdPersistence::activeOrContinuingPresentation() for
     * demo/session members.
     */
    public static function activeOrContinuingPregnancy(string $householdNo, string $memberId): ?array
    {
        $state = self::memberState($householdNo, $memberId);
        $active = $state['active'] ?? null;
        if (is_array($active)) {
            return self::normalizePregnancy($active);
        }

        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $row = is_array($history[$i] ?? null) ? $history[$i] : null;
            if ($row === null || ($row['status'] ?? '') !== 'completed') {
                continue;
            }
            $normalized = self::normalizePregnancy($row);

            return self::isVisibleInPregnancyHistory($normalized) ? null : $normalized;
        }

        return null;
    }

    public static function historicalPregnancy(string $householdNo, string $memberId, string $pregnancyId): ?array
    {
        $id = strtoupper(trim($pregnancyId));
        foreach (self::closedPregnancies($householdNo, $memberId) as $row) {
            if (strtoupper((string) ($row['id'] ?? '')) !== $id) {
                continue;
            }

            return self::isVisibleInPregnancyHistory($row) ? $row : null;
        }

        return null;
    }

    /**
     * Unfiltered closed episodes (Completed / Trans-Out). Not a visibility list.
     *
     * @return list<array<string, mixed>>
     */
    public static function closedPregnancies(string $householdNo, string $memberId): array
    {
        $state = self::memberState($householdNo, $memberId);
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];

        return array_values(array_map(
            static fn (array $row): array => self::normalizePregnancy($row),
            array_filter($history, static fn ($row): bool => is_array($row))
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function history(string $householdNo, string $memberId): array
    {
        return self::visiblePregnancyHistory(self::closedPregnancies($householdNo, $memberId));
    }

    public static function hasClosedPregnancy(string $householdNo, string $memberId): bool
    {
        return self::closedPregnancies($householdNo, $memberId) !== [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function latestCompletedPregnancy(string $householdNo, string $memberId): ?array
    {
        $rows = self::closedPregnancies($householdNo, $memberId);
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if (($rows[$i]['status'] ?? '') === 'completed') {
                return $rows[$i];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function writableSectionPregnancy(string $householdNo, string $memberId, string $section): ?array
    {
        $active = self::activePregnancy($householdNo, $memberId);
        if ($active !== null) {
            return $active;
        }

        return self::sectionAllowsCompletedEpisode($section)
            ? self::latestCompletedPregnancy($householdNo, $memberId)
            : null;
    }

    public static function hasRecord(string $householdNo, string $memberId): bool
    {
        return self::activePregnancy($householdNo, $memberId) !== null
            || self::hasClosedPregnancy($householdNo, $memberId);
    }

    /**
     * Normalize a pregnancy array into the frozen UI presentation shape.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return self::normalizePregnancy($row);
    }

    /**
     * Build a new pregnancy row from registration input (no session I/O).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function buildRegistrationPregnancy(array $payload, int $sequenceNumber, string $pregnancyId = ''): array
    {
        $lmp = self::sanitizeDate($payload['lmp'] ?? null);
        $pregnancy = self::emptyPregnancy();
        $pregnancy['id'] = $pregnancyId;
        $pregnancy['number'] = max(1, $sequenceNumber);
        $pregnancy['lmp'] = $lmp;
        $pregnancy['gravida'] = self::sanitizeInt($payload['gravida'] ?? null);
        $pregnancy['parity'] = self::sanitizeInt($payload['parity'] ?? null);
        $pregnancy['edd'] = self::sanitizeDate($payload['edd'] ?? null) ?: self::estimateEdd($lmp);
        $pregnancy['weight'] = self::sanitizeDecimal($payload['weight'] ?? null);
        $pregnancy['height'] = self::sanitizeDecimal($payload['height'] ?? null);
        $derivedBmi = self::estimateBmi($pregnancy['weight'], $pregnancy['height']);
        $pregnancy['bmi'] = $derivedBmi;
        $pregnancy['blood_pressure'] = self::sanitizeText($payload['blood_pressure'] ?? null);
        $pregnancy['registered_at'] = now()->toDateString();
        $pregnancy['status'] = 'active';
        $pregnancy['prenatal']['t1_v1'] = [
            'date' => $pregnancy['registered_at'],
            'height' => $pregnancy['height'],
            'weight' => $pregnancy['weight'],
            'bmi' => $derivedBmi,
            'bp' => $pregnancy['blood_pressure'],
        ];

        return $pregnancy;
    }

    /**
     * Apply a section payload onto an in-memory pregnancy row (no session I/O).
     *
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>  $payload
     * @return array{pregnancy: array<string, mixed>, transferred: bool, completed: bool}|null
     */
    public static function applySectionToPregnancy(array $active, string $section, array $payload): ?array
    {
        $section = strtolower(trim($section));
        switch ($section) {
            case 'prenatal':
                $active['prenatal'] = self::mergePrenatal(
                    $active['prenatal'] ?? [],
                    $payload,
                    (string) ($active['lmp'] ?? '')
                );
                break;
            case 'immunizations':
                $active['immunizations'] = self::mergeImmunizations($active['immunizations'] ?? [], $payload);
                break;
            case 'supplementations':
                $active['supplementations'] = self::mergeSupplementations(
                    $active['supplementations'] ?? [],
                    $payload,
                    (string) ($active['lmp'] ?? '')
                );
                break;
            case 'laboratory':
                $active['laboratory'] = self::mergeLaboratory($active['laboratory'] ?? [], $payload);
                break;
            case 'delivery':
                $active['delivery'] = self::mergeDelivery($active['delivery'] ?? [], $payload);
                $completed = self::isTerminalOutcome((string) ($active['delivery']['outcome'] ?? ''));
                if ($completed) {
                    $active['status'] = 'completed';
                }

                return [
                    'pregnancy' => $active,
                    'transferred' => false,
                    'completed' => $completed,
                ];
            case 'postnatal':
                $active['postnatal'] = self::mergePostnatal($active['postnatal'] ?? [], $payload);
                break;
            case 'trans-out':
                $active['trans_out'] = self::mergeTransOut($active['trans_out'] ?? [], $payload);
                $active['status'] = 'transferred_out';

                return [
                    'pregnancy' => $active,
                    'transferred' => true,
                    'completed' => false,
                ];
            default:
                return null;
        }

        return [
            'pregnancy' => $active,
            'transferred' => false,
            'completed' => false,
        ];
    }

    /**
     * Register a new active pregnancy (session preview).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function register(string $householdNo, string $memberId, array $payload): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $state = self::memberState($hh, $mb);

        $pregnancy = self::buildRegistrationPregnancy(
            $payload,
            count($state['history'] ?? []) + 1,
            self::nextPregnancyId($state)
        );

        $state['active'] = $pregnancy;
        self::putMemberState($hh, $mb, $state);

        return self::normalizePregnancy($pregnancy);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function updateSection(
        string $householdNo,
        string $memberId,
        string $section,
        array $payload
    ): ?array {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $state = self::memberState($hh, $mb);
        $active = is_array($state['active'] ?? null) ? $state['active'] : null;
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        $historyIndex = null;
        if ($active === null && self::sectionAllowsCompletedEpisode($section)) {
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $row = is_array($history[$i] ?? null) ? $history[$i] : null;
                if ($row !== null && ($row['status'] ?? '') === 'completed') {
                    $active = $row;
                    $historyIndex = $i;
                    break;
                }
            }
        }
        if ($active === null) {
            return null;
        }

        $result = self::applySectionToPregnancy($active, $section, $payload);
        if ($result === null) {
            return null;
        }

        $active = $result['pregnancy'];
        if ($result['transferred'] || ($result['completed'] && $historyIndex === null)) {
            $history[] = $active;
            $state['history'] = $history;
            $state['active'] = null;
            self::putMemberState($hh, $mb, $state);

            return self::normalizePregnancy($active);
        }

        if ($historyIndex !== null) {
            $history[$historyIndex] = $active;
            $state['history'] = $history;
            self::putMemberState($hh, $mb, $state);

            return self::normalizePregnancy($active);
        }

        $state['active'] = $active;
        self::putMemberState($hh, $mb, $state);

        return self::normalizePregnancy($active);
    }

    /**
     * @return array{gestational_age_label: string, trimester_label: string, trimester_key: string}
     */
    public static function gestationalInfo(?string $lmp): array
    {
        if ($lmp === null || $lmp === '') {
            return [
                'gestational_age_label' => '—',
                'trimester_label' => '—',
                'trimester_key' => '',
            ];
        }

        try {
            $start = \Carbon\Carbon::parse($lmp)->startOfDay();
            $today = \Carbon\Carbon::today();
            if ($start->greaterThan($today)) {
                return [
                    'gestational_age_label' => '0 Weeks, 0 Days',
                    'trimester_label' => '1st Trimester',
                    'trimester_key' => 'first',
                ];
            }

            $days = (int) $start->diffInDays($today);
            $weeks = intdiv($days, 7);
            $remDays = $days % 7;
            $trimesterKey = 'first';
            $trimesterLabel = '1st Trimester';
            if ($weeks >= 28) {
                $trimesterKey = 'third';
                $trimesterLabel = '3rd Trimester';
            } elseif ($weeks >= 13) {
                $trimesterKey = 'second';
                $trimesterLabel = '2nd Trimester';
            }

            return [
                'gestational_age_label' => $weeks.' Weeks, '.$remDays.' Days',
                'trimester_label' => $trimesterLabel,
                'trimester_key' => $trimesterKey,
            ];
        } catch (\Throwable) {
            return [
                'gestational_age_label' => '—',
                'trimester_label' => '—',
                'trimester_key' => '',
            ];
        }
    }

    /**
     * Slot key → gestationalInfo trimester_key (first|second|third).
     *
     * @return array<string, string>
     */
    public static function prenatalSlotTrimesterMap(): array
    {
        $map = [];
        foreach (self::prenatalSchedule() as $trimesterKey => $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $map[$visit['key']] = $trimesterKey;
            }
        }

        return $map;
    }

    public static function prenatalSlotTrimesterKey(string $slotKey): string
    {
        return self::prenatalSlotTrimesterMap()[$slotKey] ?? '';
    }

    /**
     * first=1, second=2, third=3. Unknown/empty=0.
     */
    public static function trimesterRank(?string $trimesterKey): int
    {
        return match (strtolower(trim((string) $trimesterKey))) {
            'first', '1st' => 1,
            'second', '2nd' => 2,
            'third', '3rd' => 3,
            default => 0,
        };
    }

    /**
     * Whether a new prenatal slot may be created for this LMP-derived stage.
     * Missing/invalid LMP allows only 1st-trimester creates; never guesses from EDD.
     */
    /**
     * Meaningful user-supplied values. Does not use array_filter().
     * 0 / false / "0" remain meaningful. NULL / '' / whitespace do not.
     */
    public static function isMeaningfulFieldValue(mixed $value): bool
    {
        if ($value === false || $value === 0 || $value === 0.0 || $value === '0') {
            return true;
        }
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    public static function allowsNewPrenatalSlot(?string $lmp, string $slotKey): bool
    {
        return self::allowsNewTrimesterKeyedSlot($lmp, self::prenatalSlotTrimesterKey($slotKey), false);
    }

    /**
     * Missing/invalid LMP allows only 1st-trimester creates.
     * Unscoped slots (no trimester_key) are not future-gated.
     */
    public static function allowsNewTrimesterKeyedSlot(
        ?string $lmp,
        string $trimesterKey,
        bool $unscopedAllowed = true
    ): bool {
        $slotRank = self::trimesterRank($trimesterKey);
        if ($slotRank < 1) {
            return $unscopedAllowed;
        }

        $currentKey = self::gestationalInfo($lmp)['trimester_key'];
        if ($currentKey === '') {
            return $slotRank === 1;
        }

        return $slotRank <= self::trimesterRank($currentKey);
    }

    public static function supplementationVisitTrimesterKey(string $group, string $visitKey): string
    {
        foreach (self::supplementationSchedule()[$group]['visits'] ?? [] as $visit) {
            if (($visit['key'] ?? '') === $visitKey) {
                return (string) ($visit['trimester_key'] ?? '');
            }
        }

        return '';
    }

    public static function allowsNewSupplementationSlot(?string $lmp, string $group, string $visitKey): bool
    {
        return self::allowsNewTrimesterKeyedSlot(
            $lmp,
            self::supplementationVisitTrimesterKey($group, $visitKey),
            true
        );
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    public static function supplementationIncomingHasContent(array $incoming): bool
    {
        foreach (['date', 'tablets'] as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }
            if (self::isMeaningfulFieldValue($incoming[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function supplementationSlotHasExistingData(array $row): bool
    {
        return trim((string) ($row['date'] ?? '')) !== ''
            || trim((string) ($row['tablets'] ?? '')) !== '';
    }

    public static function futurePrenatalUnavailableMessage(string $trimesterKey): string
    {
        return match (strtolower(trim($trimesterKey))) {
            'second', '2nd' => 'Available when the pregnancy reaches the 2nd trimester.',
            'third', '3rd' => 'Available when the pregnancy reaches the 3rd trimester.',
            default => 'This prenatal visit is not available for the current pregnancy stage.',
        };
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    public static function prenatalIncomingHasContent(array $incoming): bool
    {
        foreach (['date', 'height', 'weight', 'bp'] as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }
            if (self::isMeaningfulFieldValue($incoming[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    public static function laboratoryIncomingHasContent(array $incoming): bool
    {
        foreach (['date', 'result', 'value'] as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }
            if (self::isMeaningfulFieldValue($incoming[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function prenatalSlotHasExistingData(array $row): bool
    {
        foreach (['date', 'height', 'weight', 'bp'] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public static function formatDate(?string $iso): string
    {
        if ($iso === null || trim($iso) === '') {
            return '—';
        }

        try {
            $formatted = DisplayDate::format($iso);

            return $formatted !== '' ? $formatted : $iso;
        } catch (\Throwable) {
            return $iso;
        }
    }

    public static function countFilledDates(array $dates): int
    {
        $count = 0;
        foreach ($dates as $value) {
            if (is_string($value) && trim($value) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     */
    public static function prenatalVisitCount(array $pregnancy): int
    {
        $prenatal = is_array($pregnancy['prenatal'] ?? null) ? $pregnancy['prenatal'] : [];
        $count = 0;
        foreach (self::prenatalSchedule() as $trimester) {
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
    public static function immunizationCount(array $pregnancy): int
    {
        $imm = is_array($pregnancy['immunizations'] ?? null) ? $pregnancy['immunizations'] : [];
        $dates = [];
        foreach (['td1', 'td2', 'td3', 'td4', 'td5'] as $key) {
            $dates[] = (string) ($imm[$key] ?? '');
        }

        return self::countFilledDates($dates);
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     * @return array{deworming: int, ifa: int, mms: int, calcium: int, rusf: int}
     */
    public static function supplementationCounts(array $pregnancy): array
    {
        $supp = is_array($pregnancy['supplementations'] ?? null) ? $pregnancy['supplementations'] : [];
        $deworming = trim((string) ($supp['deworming_date'] ?? '')) !== '' ? 1 : 0;

        $countVisits = static function (array $rows): int {
            $n = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (trim((string) ($row['date'] ?? '')) !== '') {
                    $n++;
                }
            }

            return $n;
        };

        return [
            'deworming' => $deworming,
            'ifa' => $countVisits(is_array($supp['ifa'] ?? null) ? $supp['ifa'] : []),
            'mms' => $countVisits(is_array($supp['mms'] ?? null) ? $supp['mms'] : []),
            'calcium' => $countVisits(is_array($supp['calcium'] ?? null) ? $supp['calcium'] : []),
            'rusf' => $countVisits(is_array($supp['rusf'] ?? null) ? $supp['rusf'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     */
    public static function postnatalContactCount(array $pregnancy): int
    {
        $post = is_array($pregnancy['postnatal'] ?? null) ? $pregnancy['postnatal'] : [];
        $contacts = is_array($post['contacts'] ?? null) ? $post['contacts'] : [];
        $dates = [];
        foreach (self::postnatalContacts() as $contact) {
            $dates[] = (string) ($contacts[$contact['key']] ?? '');
        }

        return self::countFilledDates($dates);
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     */
    public static function postpartumSuppCount(array $pregnancy): int
    {
        $post = is_array($pregnancy['postnatal'] ?? null) ? $pregnancy['postnatal'] : [];
        $visits = is_array($post['supplementation'] ?? null) ? $post['supplementation'] : [];
        $n = 0;
        foreach (self::postpartumSupplementationVisits() as $visit) {
            $row = is_array($visits[$visit['key']] ?? null) ? $visits[$visit['key']] : [];
            if (trim((string) ($row['date'] ?? '')) !== '') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private static function memberState(string $householdNo, string $memberId): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $all = session(self::SESSION_KEY, []);
        if (! is_array($all)) {
            $all = [];
        }
        $state = $all[$hh][$mb] ?? null;

        return is_array($state) ? $state : ['active' => null, 'history' => []];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function putMemberState(string $householdNo, string $memberId, array $state): void
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $all = session(self::SESSION_KEY, []);
        if (! is_array($all)) {
            $all = [];
        }
        if (! isset($all[$hh]) || ! is_array($all[$hh])) {
            $all[$hh] = [];
        }
        $all[$hh][$mb] = $state;
        session([self::SESSION_KEY => $all]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function nextPregnancyId(array $state): string
    {
        $max = 0;
        foreach (($state['history'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (preg_match('/MC-(\d+)/', (string) ($row['id'] ?? ''), $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        if (is_array($state['active'] ?? null)
            && preg_match('/MC-(\d+)/', (string) ($state['active']['id'] ?? ''), $m)
        ) {
            $max = max($max, (int) $m[1]);
        }

        return 'MC-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyPregnancy(): array
    {
        $prenatal = [];
        foreach (self::prenatalSchedule() as $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $prenatal[$visit['key']] = [
                    'date' => '',
                    'height' => '',
                    'weight' => '',
                    'bmi' => '',
                    'bp' => '',
                ];
            }
        }

        $ifa = [];
        $mms = [];
        foreach (self::supplementationSchedule()['ifa']['visits'] as $visit) {
            $ifa[$visit['key']] = ['date' => '', 'tablets' => ''];
            $mms[$visit['key']] = ['date' => '', 'tablets' => ''];
        }
        $calcium = [];
        foreach (self::supplementationSchedule()['calcium']['visits'] as $visit) {
            $calcium[$visit['key']] = ['date' => '', 'tablets' => ''];
        }

        $contacts = [];
        foreach (self::postnatalContacts() as $contact) {
            $contacts[$contact['key']] = '';
        }
        $ppSupp = [];
        foreach (self::postpartumSupplementationVisits() as $visit) {
            $ppSupp[$visit['key']] = ['date' => '', 'tablets' => ''];
        }

        return [
            'id' => '',
            'number' => 1,
            'status' => 'active',
            'lmp' => '',
            'edd' => '',
            'gravida' => '',
            'parity' => '',
            'weight' => '',
            'height' => '',
            'bmi' => '',
            'blood_pressure' => '',
            'registered_at' => '',
            'prenatal' => $prenatal,
            'immunizations' => [
                'td1' => '',
                'td2' => '',
                'td3' => '',
                'td4' => '',
                'td5' => '',
            ],
            'supplementations' => [
                'deworming_date' => '',
                'ifa' => $ifa,
                'mms' => $mms,
                'calcium' => $calcium,
                'rusf' => [],
            ],
            'laboratory' => [
                'hepatitis_b' => ['date' => '', 'result' => ''],
                'cbc' => ['date' => '', 'result' => ''],
                'gdm' => ['date' => '', 'result' => ''],
                'urinalysis' => ['date' => ''],
                'ultrasound' => ['date' => ''],
                'syphilis' => ['date' => '', 'result' => ''],
                'hiv' => ['date' => '', 'result' => ''],
                'cvc' => ['value' => ''],
                'gestational' => ['value' => ''],
            ],
            'delivery' => [
                'outcome' => '',
                'abortion_date' => '',
                'newborn_sex' => '',
                'plurality' => '',
                'plurality_number' => '',
                'delivery_type' => '',
                'birth_weight' => '',
                'status' => '',
                'datetime' => '',
                'date_terminated' => '',
                'birth_attendant' => '',
                'birth_attendant_other' => '',
                'place' => '',
                'facility_name' => '',
                'bemonc_cemonc' => '',
            ],
            'postnatal' => [
                'contacts' => $contacts,
                'supplementation' => $ppSupp,
                'vitamin_a' => [
                    'date' => '',
                ],
            ],
            'trans_out' => [
                'to_facility' => '',
                'occurred_at_stage' => '',
                'reason' => '',
                'date_transferred_out' => '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizePregnancy(array $row): array
    {
        $base = self::emptyPregnancy();
        $merged = array_replace_recursive($base, $row);
        $merged['delivery'] = self::presentDelivery(is_array($merged['delivery'] ?? null) ? $merged['delivery'] : []);
        $gestation = self::gestationalInfo((string) ($merged['lmp'] ?? ''));
        $merged['gestational_age_label'] = $gestation['gestational_age_label'];
        $merged['trimester_label'] = $gestation['trimester_label'];
        $merged['trimester_key'] = $gestation['trimester_key'];
        $merged['lmp_label'] = self::formatDate((string) ($merged['lmp'] ?? ''));
        $merged['edd_label'] = self::formatDate((string) ($merged['edd'] ?? ''));
        $merged['gravida_parity_label'] = trim(
            (string) ($merged['gravida'] ?? '').'–'.(string) ($merged['parity'] ?? ''),
            '–'
        );
        if ($merged['gravida_parity_label'] === '') {
            $merged['gravida_parity_label'] = '—';
        } elseif (! str_contains($merged['gravida_parity_label'], '–')) {
            $g = (string) ($merged['gravida'] ?? '—');
            $p = (string) ($merged['parity'] ?? '—');
            $merged['gravida_parity_label'] = ($g !== '' ? $g : '—').'–'.($p !== '' ? $p : '—');
        }

        $merged['bmi'] = self::estimateBmi(
            (string) ($merged['weight'] ?? ''),
            (string) ($merged['height'] ?? '')
        );
        if (is_array($merged['prenatal'] ?? null)) {
            foreach ($merged['prenatal'] as $key => $visit) {
                if (! is_array($visit)) {
                    continue;
                }
                $merged['prenatal'][$key]['bmi'] = self::estimateBmi(
                    (string) ($visit['weight'] ?? ''),
                    (string) ($visit['height'] ?? '')
                );
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @return array<string, mixed>
     */
    private static function presentDelivery(array $delivery): array
    {
        unset($delivery['fetal_death_date'], $delivery['abortion_date']);

        return $delivery;
    }

    private static function estimateEdd(?string $lmp): string
    {
        if ($lmp === null || $lmp === '') {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($lmp)->addDays(280)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Shared PHP BMI authority. Client-submitted BMI is never used.
     */
    private static function estimateBmi(?string $weight, ?string $height): string
    {
        return RiskAssessmentClinicalValues::calculateBmi($height, $weight) ?? '';
    }

    private static function sanitizeDate(mixed $value): string
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return '';
        }

        return $raw;
    }

    private static function sanitizeDateTime(mixed $value): string
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $raw)) {
            return substr($raw, 0, 16);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw.'T00:00';
        }

        return '';
    }

    private static function sanitizeText(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function sanitizeInt(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return (string) (int) $value;
        }

        return '';
    }

    private static function sanitizeDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergePrenatal(array $current, array $payload, ?string $lmp = null): array
    {
        // Sparse merge: only visit keys present in the payload are updated.
        // Sibling visits not submitted are preserved. Unknown nested keys are stripped.
        $visits = is_array($payload['visits'] ?? null) ? $payload['visits'] : $payload;
        if (! is_array($visits)) {
            return $current;
        }

        foreach (self::prenatalSchedule() as $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $key = $visit['key'];
                if (! array_key_exists($key, $visits)) {
                    continue;
                }
                $row = is_array($visits[$key]) ? $visits[$key] : [];
                $existing = is_array($current[$key] ?? null) ? $current[$key] : [];
                if (! self::prenatalSlotHasExistingData($existing)
                    && ! self::allowsNewPrenatalSlot($lmp, $key)
                ) {
                    if (self::prenatalIncomingHasContent($row)) {
                        throw ValidationException::withMessages([
                            "visits.{$key}" => self::futurePrenatalUnavailableMessage(
                                self::prenatalSlotTrimesterKey($key)
                            ),
                        ]);
                    }

                    continue;
                }
                $height = array_key_exists('height', $row)
                    ? self::sanitizeDecimal($row['height'])
                    : (string) ($existing['height'] ?? '');
                $weight = array_key_exists('weight', $row)
                    ? self::sanitizeDecimal($row['weight'])
                    : (string) ($existing['weight'] ?? '');
                $current[$key] = [
                    'date' => array_key_exists('date', $row)
                        ? self::sanitizeDate($row['date'])
                        : (string) ($existing['date'] ?? ''),
                    'height' => $height,
                    'weight' => $weight,
                    'bmi' => self::estimateBmi($weight, $height),
                    'bp' => array_key_exists('bp', $row)
                        ? self::sanitizeText($row['bp'])
                        : (string) ($existing['bp'] ?? ''),
                ];
            }
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeImmunizations(array $current, array $payload): array
    {
        foreach (['td1', 'td2', 'td3', 'td4', 'td5'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $current[$key] = self::sanitizeDate($payload[$key]);
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeSupplementations(array $current, array $payload, ?string $lmp = null): array
    {
        if (array_key_exists('deworming_date', $payload)) {
            $current['deworming_date'] = self::sanitizeDate($payload['deworming_date']);
        }

        foreach (['ifa', 'mms', 'calcium'] as $group) {
            if (! array_key_exists($group, $payload)) {
                continue;
            }
            $rows = is_array($payload[$group]) ? $payload[$group] : [];
            $schedule = self::supplementationSchedule()[$group]['visits'];
            $merged = is_array($current[$group] ?? null) ? $current[$group] : [];
            foreach ($schedule as $visit) {
                $key = $visit['key'];
                if (! array_key_exists($key, $rows)) {
                    continue;
                }
                $row = is_array($rows[$key]) ? $rows[$key] : [];
                $existing = is_array($merged[$key] ?? null) ? $merged[$key] : [];
                if (! self::supplementationSlotHasExistingData($existing)
                    && ! self::allowsNewSupplementationSlot($lmp, $group, $key)
                ) {
                    if (self::supplementationIncomingHasContent($row)) {
                        throw ValidationException::withMessages([
                            "{$group}.{$key}" => self::futurePrenatalUnavailableMessage(
                                self::supplementationVisitTrimesterKey($group, $key)
                            ),
                        ]);
                    }

                    continue;
                }
                $merged[$key] = [
                    'date' => array_key_exists('date', $row)
                        ? self::sanitizeDate($row['date'])
                        : (string) ($existing['date'] ?? ''),
                    'tablets' => array_key_exists('tablets', $row)
                        ? self::sanitizeInt($row['tablets'])
                        : ($existing['tablets'] ?? ''),
                ];
            }
            $current[$group] = $merged;
        }

        if (array_key_exists('rusf', $payload)) {
            $current['rusf'] = self::mergeRusfDates(
                is_array($current['rusf'] ?? null) ? $current['rusf'] : [],
                is_array($payload['rusf']) ? $payload['rusf'] : []
            );
        }

        return $current;
    }

    /**
     * @param  list<array<string, mixed>>|array<int|string, mixed>  $current
     * @param  list<array<string, mixed>>|array<int|string, mixed>  $incoming
     * @return list<array{date: string}>
     */
    private static function mergeRusfDates(array $current, array $incoming): array
    {
        $merged = [];
        $seen = [];
        foreach ($current as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = self::sanitizeDate($row['date'] ?? '');
            if ($date === '' || isset($seen[$date])) {
                continue;
            }
            $merged[] = ['date' => $date];
            $seen[$date] = true;
        }

        foreach ($incoming as $item) {
            if (! is_array($item)) {
                continue;
            }
            $date = array_key_exists('date', $item) ? self::sanitizeDate($item['date']) : '';
            if ($date === '' || isset($seen[$date])) {
                continue;
            }
            $merged[] = ['date' => $date];
            $seen[$date] = true;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeLaboratory(array $current, array $payload): array
    {
        $dateResultScreens = [
            'hepatitis_b' => self::HEPATITIS_B_RESULTS,
            'cbc' => self::CBC_RESULTS,
            'gdm' => self::GDM_RESULTS,
            'syphilis' => self::SYPHILIS_RESULTS,
            'hiv' => self::HIV_RESULTS,
        ];

        foreach ($dateResultScreens as $key => $allowed) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $incoming = is_array($payload[$key]) ? $payload[$key] : [];
            $existing = is_array($current[$key] ?? null) ? $current[$key] : [];

            $date = array_key_exists('date', $incoming)
                ? self::sanitizeDate($incoming['date'])
                : (string) ($existing['date'] ?? '');

            if (array_key_exists('result', $incoming)) {
                $raw = self::sanitizeText($incoming['result']);
                $result = in_array($raw, $allowed, true) ? $raw : '';
            } else {
                $result = (string) ($existing['result'] ?? '');
            }

            $current[$key] = [
                'date' => $date,
                'result' => $result,
            ];
        }

        foreach (['urinalysis', 'ultrasound'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $incoming = is_array($payload[$key]) ? $payload[$key] : [];
            $existing = is_array($current[$key] ?? null) ? $current[$key] : [];
            $date = array_key_exists('date', $incoming)
                ? self::sanitizeDate($incoming['date'])
                : (string) ($existing['date'] ?? '');

            $current[$key] = [
                'date' => $date,
            ];
        }

        foreach (['cvc', 'gestational'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $incoming = is_array($payload[$key]) ? $payload[$key] : [];
            $existing = is_array($current[$key] ?? null) ? $current[$key] : [];
            $value = array_key_exists('value', $incoming)
                ? self::sanitizeDecimal($incoming['value'])
                : (string) ($existing['value'] ?? '');

            $current[$key] = [
                'value' => $value,
            ];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeDelivery(array $current, array $payload): array
    {
        unset($current['fetal_death_date']);

        $outcome = array_key_exists('outcome', $payload)
            ? self::sanitizeText($payload['outcome'] ?? null)
            : (string) ($current['outcome'] ?? '');
        if (! array_key_exists($outcome, self::OUTCOMES)) {
            $outcome = '';
        }

        $deliveryType = array_key_exists('delivery_type', $payload)
            ? self::sanitizeText($payload['delivery_type'] ?? null)
            : (string) ($current['delivery_type'] ?? '');
        if (! array_key_exists($deliveryType, self::DELIVERY_TYPES)) {
            $deliveryType = '';
        }

        $attendant = array_key_exists('birth_attendant', $payload)
            ? self::sanitizeText($payload['birth_attendant'] ?? null)
            : (string) ($current['birth_attendant'] ?? '');
        if (! array_key_exists($attendant, self::BIRTH_ATTENDANTS)) {
            $attendant = '';
        }
        $attendantOther = $attendant === 'Others'
            ? self::sanitizeText(
                array_key_exists('birth_attendant_other', $payload)
                    ? ($payload['birth_attendant_other'] ?? null)
                    : ($current['birth_attendant_other'] ?? null)
            )
            : '';

        $place = array_key_exists('place', $payload)
            ? self::sanitizeText($payload['place'] ?? null)
            : (string) ($current['place'] ?? '');
        if (! array_key_exists($place, self::PLACES_OF_DELIVERY)) {
            $place = '';
        }

        $bemonc = array_key_exists('bemonc_cemonc', $payload)
            ? self::sanitizeText($payload['bemonc_cemonc'] ?? null)
            : (string) ($current['bemonc_cemonc'] ?? '');
        if (! in_array($bemonc, ['Yes', 'No'], true)) {
            $bemonc = '';
        }

        $current['outcome'] = $outcome;

        if (array_key_exists('newborn_sex', $payload)) {
            $sex = self::sanitizeText($payload['newborn_sex'] ?? null);
            $current['newborn_sex'] = array_key_exists($sex, self::NEWBORN_SEXES) ? $sex : '';
        }

        if (array_key_exists('plurality', $payload)) {
            $plurality = self::sanitizeText($payload['plurality'] ?? null);
            if (! array_key_exists($plurality, self::PLURALITIES)) {
                $plurality = '';
            }
            $current['plurality'] = $plurality;
            $current['plurality_number'] = $plurality === 'Multiple'
                ? self::sanitizeInt($payload['plurality_number'] ?? null)
                : '';
        } elseif (array_key_exists('plurality_number', $payload)) {
            $currentPlurality = (string) ($current['plurality'] ?? '');
            $current['plurality_number'] = $currentPlurality === 'Multiple'
                ? self::sanitizeInt($payload['plurality_number'] ?? null)
                : '';
        }

        $current['delivery_type'] = $deliveryType;
        $current['birth_weight'] = array_key_exists('birth_weight', $payload)
            ? self::sanitizeDecimal($payload['birth_weight'] ?? null)
            : (string) ($current['birth_weight'] ?? '');
        $current['status'] = array_key_exists('status', $payload)
            ? self::sanitizeText($payload['status'] ?? null)
            : (string) ($current['status'] ?? '');
        $current['datetime'] = array_key_exists('datetime', $payload)
            ? self::sanitizeDateTime($payload['datetime'] ?? null)
            : (string) ($current['datetime'] ?? '');
        if (array_key_exists('date_terminated', $payload)) {
            $current['date_terminated'] = self::sanitizeDate($payload['date_terminated'] ?? null);
        }
        $current['birth_attendant'] = $attendant;
        $current['birth_attendant_other'] = $attendantOther;
        $current['place'] = $place;
        $current['facility_name'] = array_key_exists('facility_name', $payload)
            ? self::sanitizeText($payload['facility_name'] ?? null)
            : (string) ($current['facility_name'] ?? '');
        $current['bemonc_cemonc'] = $bemonc;

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergePostnatal(array $current, array $payload): array
    {
        $contacts = is_array($current['contacts'] ?? null) ? $current['contacts'] : [];
        if (array_key_exists('contacts', $payload)) {
            $contactsIn = is_array($payload['contacts']) ? $payload['contacts'] : [];
            foreach (self::postnatalContacts() as $contact) {
                $key = $contact['key'];
                if (! array_key_exists($key, $contactsIn)) {
                    continue;
                }
                $contacts[$key] = self::sanitizeDate($contactsIn[$key]);
            }
        }

        $supp = is_array($current['supplementation'] ?? null) ? $current['supplementation'] : [];
        if (array_key_exists('supplementation', $payload)) {
            $suppIn = is_array($payload['supplementation']) ? $payload['supplementation'] : [];
            foreach (self::postpartumSupplementationVisits() as $visit) {
                $key = $visit['key'];
                if (! array_key_exists($key, $suppIn)) {
                    continue;
                }
                $row = is_array($suppIn[$key]) ? $suppIn[$key] : [];
                $existing = is_array($supp[$key] ?? null) ? $supp[$key] : [];
                $supp[$key] = [
                    'date' => array_key_exists('date', $row)
                        ? self::sanitizeDate($row['date'])
                        : (string) ($existing['date'] ?? ''),
                    'tablets' => array_key_exists('tablets', $row)
                        ? self::sanitizeInt($row['tablets'])
                        : ($existing['tablets'] ?? ''),
                ];
            }
        }

        $current['contacts'] = $contacts;
        $current['supplementation'] = $supp;

        if (array_key_exists('vitamin_a', $payload)) {
            $incomingVitaminA = is_array($payload['vitamin_a'] ?? null) ? $payload['vitamin_a'] : [];
            unset(
                $incomingVitaminA['id'],
                $incomingVitaminA['postpartum_vitamin_a_id'],
                $incomingVitaminA['maternal_care_id'],
            );
            $existingVitaminA = is_array($current['vitamin_a'] ?? null) ? $current['vitamin_a'] : ['date' => ''];
            $current['vitamin_a'] = [
                'date' => array_key_exists('date', $incomingVitaminA)
                    ? self::sanitizeDate($incomingVitaminA['date'] ?? '')
                    : (string) ($existingVitaminA['date'] ?? ''),
            ];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeTransOut(array $current, array $payload): array
    {
        return [
            'to_facility' => self::sanitizeText($payload['to_facility'] ?? null),
            'occurred_at_stage' => self::sanitizeText($payload['occurred_at_stage'] ?? null),
            'reason' => self::sanitizeText($payload['reason'] ?? null),
            'date_transferred_out' => self::sanitizeDate($payload['date_transferred_out'] ?? null),
        ];
    }
}
