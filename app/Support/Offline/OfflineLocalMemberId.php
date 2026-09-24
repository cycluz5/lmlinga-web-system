<?php

namespace App\Support\Offline;

/**
 * Client-only member identifier until /offline/sync allocates MB-{n}.
 */
final class OfflineLocalMemberId
{
    public const PATTERN = 'MB-L-[A-Za-z0-9]+';

    public static function isLocal(?string $memberId): bool
    {
        return (bool) preg_match('/^MB-L-[A-Za-z0-9]+$/i', trim((string) $memberId));
    }

    /**
     * Keep the URL token as the client minted it. Uppercasing MB-L-* breaks
     * Cache Storage keys and IndexedDB snapshot lookups.
     */
    public static function normalize(string $memberId): string
    {
        $trimmed = trim($memberId);

        return self::isLocal($trimmed) ? $trimmed : strtoupper($trimmed);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentation(string $memberId): array
    {
        return [
            'id' => $memberId,
            'name' => 'Queued member',
            'relation' => '—',
            'relationship' => '—',
            'sex' => '—',
            'birthday' => '—',
            'relationship_status' => '—',
            'occupation' => '—',
            'monthly_income' => '—',
            'religion' => '—',
            'education' => '—',
            'philhealth' => '—',
            'fp_user' => '—',
            'disability' => '—',
            'medical_history' => '—',
            'last_name' => '',
            'first_name' => '',
            'middle_name' => '',
        ];
    }
}
