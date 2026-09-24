<?php

namespace App\Support\Offline;

/**
 * Canonical offline Environmental Health wizard shells.
 *
 * These are templates, not households. The placeholder is rewritten client-side
 * to the local household_no (e.g. "132") and is never inserted into MySQL.
 */
final class OfflineEnvironmentalHealthShell
{
    public const PLACEHOLDER_HOUSEHOLD_NO = 'LML-EH';

    /** @var list<int> */
    public const STEPS = [1, 2, 3, 4];
}
