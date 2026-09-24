<?php

namespace App\Services;

use App\Models\Announcement;
use App\Support\AnnouncementAgePreset;
use App\Support\AnnouncementPresenter;
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
        $actor = $this->actor();
        $attributes = array_merge($this->persistableAttributes($input), [
            'posted_by_user_id' => Auth::id(),
            'posted_by_name' => $actor['name'],
            'posted_by_role' => $actor['role'],
            'posted_at' => now(),
        ]);

        return DB::transaction(function () use ($attributes): Announcement {
            $announcement = Announcement::query()->create($attributes);
            $this->announcementNotificationService->fanOut($announcement);

            return $announcement;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Announcement $announcement, array $input): Announcement
    {
        $announcement->fill($this->persistableAttributes($input));
        $announcement->save();

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
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function persistableAttributes(array $input): array
    {
        $criteria = $this->targetingCriteriaFromInput($input);
        $targetGroup = $criteria['target_group'];
        $agePresets = $criteria['age_presets'];
        $ageMinMonths = $criteria['age_range_months']['min'];
        $ageMaxMonths = $criteria['age_range_months']['max'];
        $zoneMode = $criteria['zone_mode'];
        $zones = $criteria['zones'];

        $audienceLabel = AnnouncementPresenter::audienceLabel(
            $targetGroup,
            $agePresets,
            $ageMinMonths,
            $ageMaxMonths,
        );

        return [
            'title' => trim((string) $input['title']),
            'message' => trim((string) $input['message']),
            'event_date' => (string) $input['date'],
            'event_time' => filled($input['time'] ?? null) ? (string) $input['time'] : null,
            'place' => filled($input['place'] ?? null) ? trim((string) $input['place']) : null,
            'target_group' => $targetGroup,
            'age_presets' => $agePresets === [] ? null : $agePresets,
            'age_min_months' => $ageMinMonths,
            'age_max_months' => $ageMaxMonths,
            'zone_mode' => $zoneMode,
            'zones' => $zones === [] ? null : $zones,
            'audience_label' => $audienceLabel,
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

    /**
     * @return array{name: string, role: string}
     */
    private function actor(): array
    {
        $role = UiRole::current() ?? UiRole::LEAST_PRIVILEGED;

        return [
            'name' => UiRole::displayName($role),
            'role' => $role,
        ];
    }
}
