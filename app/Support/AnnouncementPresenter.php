<?php

namespace App\Support;

use App\Models\Announcement;
use Carbon\Carbon;

/**
 * Maps persisted announcements into the view-data contract used by R06-B blades.
 */
final class AnnouncementPresenter
{
    /**
     * @var array<string, string>
     */
    private const PRESET_LABELS = [
        'infants_0_6' => 'Infants 0–6 months',
        'infants_7_11' => 'Infants 7–11 months',
        'young_children' => 'Young Children 1–5 years',
        'school_age' => 'School Age 6–12 years',
        'teens' => 'Teens 13–17 years',
        'adults' => 'Adults 18–59 years',
        'seniors' => 'Senior Citizens 60+ years',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function manage(?Carbon $today = null): array
    {
        return self::recent($today);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function upcoming(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->toDateString();

        return Announcement::query()
            ->whereDate('event_date', '>=', $today)
            ->orderBy('event_date')
            ->get()
            ->map(fn (Announcement $announcement): array => self::present($announcement, $today))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(?Carbon $today = null): array
    {
        $todayString = ($today ?? Carbon::today())->toDateString();

        return Announcement::query()
            ->orderByDesc('posted_at')
            ->orderBy('event_date')
            ->get()
            ->map(fn (Announcement $announcement): array => self::present($announcement, $todayString))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dashboardUpcoming(int $limit = 3, ?Carbon $today = null): array
    {
        return array_slice(self::upcoming($today), 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dashboardRecent(int $limit = 3, ?Carbon $today = null): array
    {
        return array_slice(self::recent($today), 0, $limit);
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     value: int,
     *     hint: string,
     *     icon: string,
     *     tone: string
     * }>
     */
    public static function summaryCards(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->toDateString();
        $total = Announcement::query()->count();
        $upcoming = Announcement::query()->whereDate('event_date', '>=', $today)->count();

        return [
            [
                'key' => 'total',
                'label' => 'Total Announcements',
                'value' => $total,
                'hint' => 'All announcements created',
                'icon' => 'bi-megaphone',
                'tone' => 'green',
            ],
            [
                'key' => 'upcoming',
                'label' => 'Upcoming',
                'value' => $upcoming,
                'hint' => 'Scheduled in the future',
                'icon' => 'bi-calendar-event',
                'tone' => 'blue',
            ],
            [
                'key' => 'published',
                'label' => 'Published',
                'value' => $total,
                'hint' => 'Already published',
                'icon' => 'bi-check2-circle',
                'tone' => 'green',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Announcement $announcement, Carbon|string|null $today = null): array
    {
        $today = $today instanceof Carbon
            ? $today->toDateString()
            : ($today ?? Carbon::today()->toDateString());

        $event = $announcement->event_date->copy()->startOfDay();
        $posted = $announcement->posted_at->copy()->startOfDay();
        $todayCarbon = Carbon::parse($today)->startOfDay();
        $eventDate = $event->toDateString();
        $postedDate = $posted->toDateString();

        $timing = self::timingBadge($eventDate, $today);

        return [
            'id' => (string) $announcement->getKey(),
            'message' => $announcement->message,
            'title' => $announcement->title,
            'event_date' => $eventDate,
            'posted_at' => $postedDate,
            'time' => self::formatEventTime($announcement->event_time),
            'place' => $announcement->place,
            'audience' => $announcement->audience_label,
            'audience_type' => $announcement->target_group,
            'month' => strtoupper($event->format('M')),
            'day' => $event->format('d'),
            'year' => $event->format('Y'),
            'event_label' => DisplayDate::format($eventDate),
            'posted_short' => DisplayDate::format($postedDate),
            'posted_label' => 'Posted '.DisplayDate::format($postedDate),
            'scheduled_label' => 'Scheduled '.DisplayDate::format($eventDate),
            'timing' => $timing,
            'timing_key' => strtolower($timing),
            'status_key' => strtolower($timing) === 'past' ? 'published' : strtolower($timing),
            'status_label' => $timing === 'Past' ? 'Published' : $timing,
            'publication' => 'published',
            'week_key' => $event->isoWeekYear().'-'.$event->isoWeek(),
            'month_key' => $event->format('Y-m'),
            'is_this_week' => $event->isoWeekYear() === $todayCarbon->isoWeekYear()
                && $event->isoWeek() === $todayCarbon->isoWeek(),
            'is_this_month' => $event->format('Y-m') === $todayCarbon->format('Y-m'),
            'is_today_event' => $eventDate === $today,
            'search_text' => strtolower(trim(implode(' ', array_filter([
                $announcement->title,
                $announcement->place ?? '',
                $announcement->audience_label,
                self::formatEventTime($announcement->event_time) ?? '',
            ])))),
        ];
    }

    public static function timingBadge(string $eventDate, ?string $today = null): string
    {
        $today = $today ?? Carbon::today()->toDateString();

        if ($eventDate === $today) {
            return 'Today';
        }

        if ($eventDate > $today) {
            return 'Upcoming';
        }

        return 'Past';
    }

    /**
     * @return array<string, mixed>
     */
    public static function formValues(Announcement $announcement): array
    {
        $zones = is_array($announcement->zones) ? $announcement->zones : [];
        $presetZones = [];
        $customZones = [];

        foreach ($zones as $zone) {
            $label = trim((string) $zone);
            if (preg_match('/^Zone\s+(\d+)$/i', $label, $matches) === 1) {
                $number = (int) $matches[1];
                if ($number >= 1 && $number <= 5) {
                    $presetZones[] = (string) $number;
                    continue;
                }
            }
            if ($label !== '') {
                $customZones[] = $label;
            }
        }

        $time = $announcement->event_time;
        if ($time !== null && $time !== '') {
            $timeString = is_string($time) ? $time : (string) $time;
            $time = strlen($timeString) >= 5 ? substr($timeString, 0, 5) : $timeString;
        } else {
            $time = '';
        }

        return [
            'title' => $announcement->title,
            'message' => $announcement->message,
            'date' => $announcement->event_date->toDateString(),
            'time' => $time,
            'place' => $announcement->place ?? '',
            'audience_type' => $announcement->target_group,
            'zone_coverage' => $announcement->zone_mode,
            'age_groups' => is_array($announcement->age_presets) ? $announcement->age_presets : [],
            'age_from' => $announcement->age_min_months,
            'age_to' => $announcement->age_max_months,
            'age_from_unit' => 'months',
            'age_to_unit' => 'months',
            'zones' => $presetZones,
            'custom_zones' => $customZones,
        ];
    }

    public static function coverageLabel(Announcement $announcement): string
    {
        if ($announcement->zone_mode !== Announcement::ZONE_SPECIFIC) {
            return 'All Zones';
        }

        $zones = is_array($announcement->zones) ? $announcement->zones : [];

        return $zones === [] ? 'Specific Zones' : implode(', ', $zones);
    }

    public static function audienceLabel(
        string $targetGroup,
        array $agePresets = [],
        ?int $ageMinMonths = null,
        ?int $ageMaxMonths = null,
    ): string {
        return match ($targetGroup) {
            Announcement::TARGET_ALL => 'All Residents',
            Announcement::TARGET_ACTIVE_MATERNAL => 'Active Maternal',
            Announcement::TARGET_ACTIVE_FP_USER => 'Active FP User',
            Announcement::TARGET_AGE => self::ageAudienceLabel($agePresets, $ageMinMonths, $ageMaxMonths),
            default => 'All Residents',
        };
    }

    /**
     * @param  list<string>  $agePresets
     */
    private static function ageAudienceLabel(
        array $agePresets,
        ?int $ageMinMonths,
        ?int $ageMaxMonths,
    ): string {
        $labels = [];

        foreach ($agePresets as $preset) {
            if (isset(self::PRESET_LABELS[$preset])) {
                $labels[] = self::PRESET_LABELS[$preset];
            }
        }

        $custom = self::customAgeLabel($ageMinMonths, $ageMaxMonths);
        if ($custom !== null) {
            $labels[] = $custom;
        }

        return $labels !== []
            ? implode(', ', $labels)
            : 'Specific Age Group';
    }

    private static function customAgeLabel(?int $ageMinMonths, ?int $ageMaxMonths): ?string
    {
        if ($ageMinMonths === null && $ageMaxMonths === null) {
            return null;
        }

        $from = $ageMinMonths !== null ? self::formatMonthsLabel($ageMinMonths) : null;
        $to = $ageMaxMonths !== null ? self::formatMonthsLabel($ageMaxMonths) : null;

        if ($from !== null && $to !== null) {
            return "{$from} – {$to}";
        }

        if ($from !== null) {
            return "{$from}+";
        }

        return "Up to {$to}";
    }

    private static function formatMonthsLabel(int $months): string
    {
        if ($months % 12 === 0 && $months >= 12) {
            $years = (int) ($months / 12);

            return $years === 1 ? '1 year' : "{$years} years";
        }

        return $months === 1 ? '1 month' : "{$months} months";
    }

    private static function formatEventTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = is_string($value) ? $value : (string) $value;

        return Carbon::createFromFormat('H:i:s', strlen($time) === 5 ? $time.':00' : $time)
            ->format('g:i A');
    }
}
