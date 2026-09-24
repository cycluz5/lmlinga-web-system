<?php

namespace App\Support;

/**
 * Canonical staff role machine values (UiRole / authorization).
 * Frozen UI may display BHW/BNS/BSPO/Admin; persistence uses lowercase codes.
 */
final class StaffRole
{
    public const ADMIN = 'admin';

    public const BHW = 'bhw';

    public const BNS = 'bns';

    public const BSPO = 'bspo';

    /** @var list<string> */
    public const ALL = [
        self::ADMIN,
        self::BHW,
        self::BNS,
        self::BSPO,
    ];

    /**
     * Normalize UI or SQL-style labels to UiRole machine values.
     */
    public static function normalize(?string $role): ?string
    {
        $raw = strtolower(trim((string) $role));

        return match ($raw) {
            'admin', 'administrator' => self::ADMIN,
            'bhw', 'barangay health worker' => self::BHW,
            'bns', 'barangay nutrition scholar' => self::BNS,
            'bspo', 'barangay service point officer' => self::BSPO,
            default => in_array($raw, self::ALL, true) ? $raw : null,
        };
    }

    public static function isValid(?string $role): bool
    {
        return self::normalize($role) !== null;
    }

    /**
     * Display label matching frozen User Management options.
     */
    public static function label(?string $role): string
    {
        return match (self::normalize($role)) {
            self::ADMIN => 'Admin',
            self::BHW => 'BHW',
            self::BNS => 'BNS',
            self::BSPO => 'BSPO',
            default => '',
        };
    }
}
