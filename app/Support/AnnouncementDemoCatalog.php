<?php

namespace App\Support;

/**
 * Temporary frontend-only Announcement demo fixtures.
 * Replace with database-backed queries later — do not persist these values.
 */
final class AnnouncementDemoCatalog
{
    /**
     * Stable “today” for frontend demo badges/filters.
     * Replace with Carbon::today() when wiring live data.
     */
    public const DEMO_TODAY = '2026-08-27';

    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     event_date: string,
     *     posted_at: string,
     *     time: ?string,
     *     place: ?string,
     *     audience: string,
     *     audience_type: string
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'demo-deworming-aug-30',
                'title' => 'Free Deworming Program — August 30',
                'event_date' => '2026-08-30',
                'posted_at' => '2026-08-27',
                'time' => '8:00 AM',
                'place' => 'Barangay Health Center',
                'audience' => 'Young Children 1–5 years',
                'audience_type' => 'age',
            ],
            [
                'id' => 'demo-prenatal-sep-02',
                'title' => 'Prenatal Check-up Schedule',
                'event_date' => '2026-09-02',
                'posted_at' => '2026-08-27',
                'time' => '9:00 AM',
                'place' => 'Health Center',
                'audience' => 'Active Maternal',
                'audience_type' => 'active_maternal',
            ],
            [
                'id' => 'demo-senior-bp-sep-05',
                'title' => 'Senior Citizen BP Screening',
                'event_date' => '2026-09-05',
                'posted_at' => '2026-08-25',
                'time' => '7:30 AM',
                'place' => 'Barangay Covered Court',
                'audience' => 'Senior Citizens 60+',
                'audience_type' => 'age',
            ],
            [
                'id' => 'demo-infant-vax-aug-28',
                'title' => 'Infant Vaccination Follow-up',
                'event_date' => '2026-08-28',
                'posted_at' => '2026-08-26',
                'time' => '8:30 AM',
                'place' => 'Barangay Health Center',
                'audience' => 'Infants 0–6 months',
                'audience_type' => 'age',
            ],
            [
                'id' => 'demo-nutrition-aug-26',
                'title' => 'Nutrition Month Reminder',
                'event_date' => '2026-08-26',
                'posted_at' => '2026-08-25',
                'time' => null,
                'place' => null,
                'audience' => 'All Residents',
                'audience_type' => 'all',
            ],
            [
                'id' => 'demo-cleanup-zone2',
                'title' => 'Clean-up Drive — Zone 2',
                'event_date' => '2026-08-24',
                'posted_at' => '2026-08-24',
                'time' => '6:00 AM',
                'place' => 'Zone 2 Covered Court',
                'audience' => 'All Residents',
                'audience_type' => 'all',
            ],
            [
                'id' => 'demo-family-planning-sep-10',
                'title' => 'Family Planning Orientation',
                'event_date' => '2026-09-10',
                'posted_at' => '2026-08-22',
                'time' => '1:00 PM',
                'place' => 'Barangay Hall',
                'audience' => 'Active FP User',
                'audience_type' => 'active_fp_user',
            ],
            [
                'id' => 'demo-school-immu-aug-20',
                'title' => 'School-Based Immunization Notice',
                'event_date' => '2026-08-20',
                'posted_at' => '2026-08-18',
                'time' => '9:00 AM',
                'place' => 'La Medalla Elementary School',
                'audience' => 'School Age 6–12 years',
                'audience_type' => 'age',
            ],
            [
                'id' => 'demo-today-opd',
                'title' => 'Open Clinic Hours Reminder',
                'event_date' => '2026-08-27',
                'posted_at' => '2026-08-27',
                'time' => '8:00 AM',
                'place' => 'Barangay Health Center',
                'audience' => 'All Residents',
                'audience_type' => 'all',
            ],
            [
                'id' => 'demo-teens-sep-12',
                'title' => 'Adolescent Health Talk',
                'event_date' => '2026-09-12',
                'posted_at' => '2026-08-21',
                'time' => '2:00 PM',
                'place' => 'Barangay Covered Court',
                'audience' => 'Teens 13–17 years',
                'audience_type' => 'age',
            ],
            [
                'id' => 'demo-zone1-fogging',
                'title' => 'Fogging Schedule — Zone 1',
                'event_date' => '2026-08-29',
                'posted_at' => '2026-08-26',
                'time' => '5:00 AM',
                'place' => 'Zone 1 Streets',
                'audience' => 'All Residents',
                'audience_type' => 'all',
            ],
            [
                'id' => 'demo-zone3-cleanup',
                'title' => 'Barangay Clean-up — Zone 3',
                'event_date' => '2026-08-22',
                'posted_at' => '2026-08-19',
                'time' => '6:30 AM',
                'place' => 'Zone 3 Covered Court',
                'audience' => 'All Residents',
                'audience_type' => 'all',
            ],
            [
                'id' => 'demo-infants-7-11',
                'title' => 'Infant Growth Monitoring (7–11 months)',
                'event_date' => '2026-09-01',
                'posted_at' => '2026-08-24',
                'time' => '9:30 AM',
                'place' => 'Barangay Health Center',
                'audience' => 'Infants 7–11 months',
                'audience_type' => 'age',
            ],
            [
                'id' => 'demo-zone4-meeting',
                'title' => 'Zone 4 Health Assembly',
                'event_date' => '2026-09-06',
                'posted_at' => '2026-08-22',
                'time' => '3:00 PM',
                'place' => 'Zone 4 Chapel Area',
                'audience' => 'All Residents',
                'audience_type' => 'all',
            ],
            [
                'id' => 'demo-zone5-outreach',
                'title' => 'Zone 5 Community Health Outreach',
                'event_date' => '2026-09-07',
                'posted_at' => '2026-08-23',
                'time' => '10:00 AM',
                'place' => 'Zone 5 Covered Court',
                'audience' => 'All Residents',
                'audience_type' => 'all',
            ],
            [
                'id' => 'demo-all-weather',
                'title' => 'Rainy Season Health Advisory',
                'event_date' => '2026-08-15',
                'posted_at' => '2026-08-15',
                'time' => null,
                'place' => null,
                'audience' => 'All Residents',
                'audience_type' => 'all',
            ],
            [
                'id' => 'demo-senior-exercise',
                'title' => 'Senior Citizens Morning Exercise',
                'event_date' => '2026-09-04',
                'posted_at' => '2026-08-23',
                'time' => '6:00 AM',
                'place' => 'Barangay Covered Court',
                'audience' => 'Senior Citizens 60+',
                'audience_type' => 'age',
            ],
            [
                'id' => 'demo-blood-pressure-today',
                'title' => 'Free Blood Pressure Check Today',
                'event_date' => '2026-08-27',
                'posted_at' => '2026-08-26',
                'time' => '7:00 AM',
                'place' => 'Barangay Health Center',
                'audience' => 'All Residents',
                'audience_type' => 'all',
            ],
            [
                'id' => 'demo-dental-sep-15',
                'title' => 'Dental Mission — School Age',
                'event_date' => '2026-09-15',
                'posted_at' => '2026-08-20',
                'time' => '8:00 AM',
                'place' => 'La Medalla Elementary School',
                'audience' => 'School Age 6–12 years',
                'audience_type' => 'age',
            ],
        ];
    }

    /**
     * Full management list — newest posted first.
     *
     * @return list<array<string, mixed>>
     */
    public static function manage(?string $today = null): array
    {
        return self::recent($today);
    }

    /**
     * Upcoming = activity/event date is today or in the future.
     * Ordered by nearest event date first.
     *
     * @return list<array<string, mixed>>
     */
    public static function upcoming(?string $today = null): array
    {
        $today = $today ?? self::DEMO_TODAY;

        $items = array_values(array_filter(
            self::all(),
            static fn (array $item): bool => $item['event_date'] >= $today
        ));

        usort($items, static fn (array $a, array $b): int => strcmp($a['event_date'], $b['event_date']));

        return array_map(static fn (array $item): array => self::present($item, $today), $items);
    }

    /**
     * Recent = recently posted notices.
     * Ordered by posted date descending (most recent first).
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(?string $today = null): array
    {
        $today = $today ?? self::DEMO_TODAY;
        $items = self::all();

        usort($items, static function (array $a, array $b): int {
            $posted = strcmp($b['posted_at'], $a['posted_at']);
            if ($posted !== 0) {
                return $posted;
            }

            return strcmp($a['event_date'], $b['event_date']);
        });

        return array_map(static fn (array $item): array => self::present($item, $today), $items);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dashboardUpcoming(int $limit = 3, ?string $today = null): array
    {
        return array_slice(self::upcoming($today), 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dashboardRecent(int $limit = 3, ?string $today = null): array
    {
        return array_slice(self::recent($today), 0, $limit);
    }

    /**
     * @param  array{
     *     id: string,
     *     title: string,
     *     event_date: string,
     *     posted_at: string,
     *     time: ?string,
     *     place: ?string,
     *     audience: string,
     *     audience_type?: string
     * }  $item
     * @return array<string, mixed>
     */
    public static function present(array $item, ?string $today = null): array
    {
        $today = $today ?? self::DEMO_TODAY;
        $event = \Carbon\Carbon::parse($item['event_date'])->startOfDay();
        $posted = \Carbon\Carbon::parse($item['posted_at'])->startOfDay();
        $todayCarbon = \Carbon\Carbon::parse($today)->startOfDay();

        $timing = self::timingBadge($item['event_date'], $today);
        $audienceType = $item['audience_type'] ?? self::inferAudienceType($item['audience']);

        return [
            ...$item,
            'audience_type' => $audienceType,
            'month' => strtoupper($event->format('M')),
            'day' => $event->format('d'),
            'year' => $event->format('Y'),
            'event_label' => DisplayDate::format($event->toDateString()),
            'posted_short' => DisplayDate::format($posted->toDateString()),
            'posted_label' => 'Posted '.DisplayDate::format($posted->toDateString()),
            'scheduled_label' => 'Scheduled '.DisplayDate::format($event->toDateString()),
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
            'is_today_event' => $item['event_date'] === $today,
            'search_text' => strtolower(trim(implode(' ', array_filter([
                $item['title'],
                $item['place'] ?? '',
                $item['audience'],
                $item['time'] ?? '',
            ])))),
        ];
    }

    public static function timingBadge(string $eventDate, ?string $today = null): string
    {
        $today = $today ?? self::DEMO_TODAY;

        if ($eventDate === $today) {
            return 'Today';
        }

        if ($eventDate > $today) {
            return 'Upcoming';
        }

        return 'Past';
    }

    private static function inferAudienceType(string $audience): string
    {
        $value = strtolower($audience);

        if (str_contains($value, 'all residents') || str_contains($value, 'all households') || $value === 'all') {
            return 'all';
        }

        if (str_contains($value, 'active maternal')) {
            return 'active_maternal';
        }

        if (str_contains($value, 'active fp')) {
            return 'active_fp_user';
        }

        return 'age';
    }
}
