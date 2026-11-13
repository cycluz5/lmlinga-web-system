<?php

namespace App\Support;

/**
 * SR-05 term version statuses and the part-of-speech list (D-T3).
 *
 * A term's own state is derived from its versions: Retired (retired_at), else the open version
 * (draft / submitted / rejected) and/or the approved version that is in use.
 */
final class ChatbotTermStatus
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** A formerly approved version that is no longer in use (replaced by a newer approval, or retired). */
    public const SUPERSEDED = 'superseded';

    /** Term-level display state only (retired_at is set); not a version status. */
    public const RETIRED = 'retired';

    /** Versions an Admin is still working on or a Linguist is reviewing (at most one per term). */
    public const OPEN = [self::DRAFT, self::SUBMITTED, self::REJECTED];

    public const LANGUAGE_BIKOL = 'bcl';

    public const PARTS_OF_SPEECH = [
        'noun' => 'Noun',
        'verb' => 'Verb',
        'adjective' => 'Adjective',
        'adverb' => 'Adverb',
        'phrase' => 'Phrase',
        'other' => 'Other',
    ];

    public static function label(string $status): string
    {
        return match ($status) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Submitted for review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::SUPERSEDED => 'Superseded',
            self::RETIRED => 'Retired',
            default => ucfirst($status),
        };
    }
}
