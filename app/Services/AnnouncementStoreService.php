<?php

namespace App\Services;

use App\Models\Announcement;
use App\Support\AnnouncementAgePreset;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class AnnouncementStoreService
{
    public function __construct(
        private readonly AnnouncementAudienceMatcher $matcher,
        private readonly AnnouncementNotificationService $announcementNotificationService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function store(array $input): Announcement
    {
        $criteria = $this->targetingCriteriaFromInput($input);
        $attributes = array_merge($this->persistableAttributes($input, $criteria), [
            'posted_by_user_id' => Auth::id(),
            'posted_by_role' => UiRole::current() ?? UiRole::LEAST_PRIVILEGED,
            'posted_at' => now(),
        ]);

        return DB::transaction(function () use ($attributes, $criteria): Announcement {
            $announcement = Announcement::query()->create($attributes);
            $announcement->syncAudience($criteria['zones'], $criteria['age_presets']);
            $this->announcementNotificationService->fanOut($announcement);

            return $announcement;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Announcement $announcement, array $input): Announcement
    {
        $criteria = $this->targetingCriteriaFromInput($input);

        DB::transaction(function () use ($announcement, $input, $criteria): void {
            $announcement->fill($this->persistableAttributes($input, $criteria));
            $announcement->save();
            $announcement->syncAudience($criteria['zones'], $criteria['age_presets']);
        });

        return $announcement->refresh();
    }

    /**
     * Authoritative matched-resident count for the same criteria used on store/fan-out.
     *
     * @param  array<string, mixed>  $input
     */
    public function estimatedReachFromInput(array $input): int
    {
        return $this->matcher->count($this->targetingCriteriaFromInput($input));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     target_group: string,
     *     age_presets: list<string>,
     *     age_range_months: array{min: int|null, max: int|null},
     *     zone_mode: string,
     *     zones: list<string>,
     *     as_of: \Carbon\CarbonInterface
     * }
     */
    public function targetingCriteriaFromInput(array $input): array
    {
        $targetGroup = (string) $input['audience_type'];
        $zoneMode = (string) $input['zone_coverage'];
        $agePresets = $targetGroup === Announcement::TARGET_AGE
            ? $this->normalizeAgePresets($input['age_groups'] ?? [])
            : [];
        [$ageMinMonths, $ageMaxMonths] = $targetGroup === Announcement::TARGET_AGE
            ? $this->normalizeCustomAgeRange($input)
            : [null, null];
        $zones = $this->normalizeZones($zoneMode, $input['zones'] ?? [], $input['custom_zones'] ?? []);

        return [
            'target_group' => $targetGroup,
            'age_presets' => $agePresets,
            'age_range_months' => [
                'min' => $ageMinMonths,
                'max' => $ageMaxMonths,
            ],
            'zone_mode' => $zoneMode,
            'zones' => $zones,
            'as_of' => Carbon::today()->startOfDay(),
        ];
    }

    /**
     * Announcement columns only; zones and age presets are saved by syncAudience().
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $criteria  from targetingCriteriaFromInput()
     * @return array<string, mixed>
     */
    private function persistableAttributes(array $input, array $criteria): array
    {
        return [
            'title' => trim((string) $input['title']),
            'message' => trim((string) $input['message']),
            'event_date' => (string) $input['date'],
            'event_time' => filled($input['time'] ?? null) ? (string) $input['time'] : null,
            'place' => filled($input['place'] ?? null) ? trim((string) $input['place']) : null,
            'target_group' => $criteria['target_group'],
            'age_min_months' => $criteria['age_range_months']['min'],
            'age_max_months' => $criteria['age_range_months']['max'],
            'estimated_reach' => $this->matcher->count($criteria),
        ];
    }

    /**
     * @param  list<mixed>  $presets
     * @return list<string>
     */
    private function normalizeAgePresets(array $presets): array
    {
        return collect($presets)
            ->map(fn ($preset) => strtolower(trim((string) $preset)))
            ->filter(fn (string $preset): bool => $preset !== '' && AnnouncementAgePreset::isValidPreset($preset))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: ?int, 1: ?int}
     */
    private function normalizeCustomAgeRange(array $input): array
    {
        $fromRaw = $input['age_from'] ?? null;
        $toRaw = $input['age_to'] ?? null;
        $fromUnit = (string) ($input['age_from_unit'] ?? 'months');
        $toUnit = (string) ($input['age_to_unit'] ?? 'months');

        $fromMonths = ($fromRaw !== null && $fromRaw !== '')
            ? AnnouncementAgePreset::toMonths($fromRaw, $fromUnit)
            : null;
        $toMonths = ($toRaw !== null && $toRaw !== '')
            ? AnnouncementAgePreset::toMonths($toRaw, $toUnit)
            : null;

        return [$fromMonths, $toMonths];
    }

    /**
     * @param  list<mixed>  $zoneValues
     * @param  list<mixed>  $customZones
     * @return list<string>
     */
    private function normalizeZones(string $zoneMode, array $zoneValues, array $customZones): array
    {
        if ($zoneMode !== Announcement::ZONE_SPECIFIC) {
            return [];
        }

        $normalized = collect($zoneValues)
            ->merge($customZones)
            ->map(fn ($zone) => $this->matcher->normalizeZoneLabel((string) $zone))
            ->filter(fn (string $zone): bool => $zone !== '')
            ->unique()
            ->values()
            ->all();

        return $normalized;
    }
}
