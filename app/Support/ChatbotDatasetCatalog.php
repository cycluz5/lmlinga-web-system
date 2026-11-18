<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Dataset version statuses (kept for history), languages and categories (categories match ChatbotController validation).
 */
final class ChatbotDatasetCatalog
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Formerly approved; replaced by a newer approval or retired. */
    public const SUPERSEDED = 'superseded';

    /** Dataset-level display state only (retired_at set). */
    public const RETIRED = 'retired';

    public const OPEN = [self::DRAFT, self::SUBMITTED, self::REJECTED];

    public const LANGUAGES = [
        'bcl' => 'Bikol-Iriga',
        'en' => 'English',
        'tl' => 'Tagalog',
    ];

    public const CATEGORIES = [
        'child_care' => 'Child Care',
        'family_planning' => 'Family Planning',
        'maternal_care' => 'Maternal Care',
        'other' => 'Other Health Topics',
    ];

    /** Characters allowed in a source name (existing corpus names include spaces). */
    public const SOURCE_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 _().-]{0,115}\.txt$/';

    public static function label(string $status): string
    {
        return match ($status) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Submitted for review',
            self::APPROVED => 'Live',
            self::REJECTED => 'Rejected',
            self::SUPERSEDED => 'Superseded',
            self::RETIRED => 'Retired',
            default => ucfirst($status),
        };
    }

    /** Source name used later as the chatbot's source_file (slug matching), e.g. "asthma_care.txt". */
    public static function sourceNameFromTitle(string $title): string
    {
        $slug = Str::ascii(mb_strtolower($title, 'UTF-8'));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim(substr(trim($slug, '_'), 0, 100), '_');

        return ($slug !== '' ? $slug : 'dataset').'.txt';
    }
}
