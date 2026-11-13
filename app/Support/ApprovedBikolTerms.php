<?php

namespace App\Support;

use App\Models\ChatbotTermVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * SR-05 read-only list of Linguist-approved Bikol-Iriga terms — the only source the chatbot may use.
 *
 * Returns approved versions of terms that are not retired. Drafts, submitted, rejected and superseded
 * versions are never included. Not called by the chatbot yet (D-T6): wiring it into RagService
 * (retrieval aliases and/or a prompt glossary, behind a default-off setting) is a later approved step.
 */
final class ApprovedBikolTerms
{
    private const CACHE_KEY = 'lmlinga.chatbot.approved-terms.bcl';

    /**
     * @return list<array{term: string, english_meaning: string, tagalog_meaning: ?string, part_of_speech: ?string}>
     */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, static function (): array {
            if (! Schema::hasTable('chatbot_term_versions')) {
                return [];
            }

            return ChatbotTermVersion::query()
                ->join('chatbot_terms', 'chatbot_terms.id', '=', 'chatbot_term_versions.chatbot_term_id')
                ->where('chatbot_terms.language', ChatbotTermStatus::LANGUAGE_BIKOL)
                ->whereNull('chatbot_terms.retired_at')
                ->where('chatbot_term_versions.status', ChatbotTermStatus::APPROVED)
                ->orderBy('chatbot_term_versions.term')
                ->get([
                    'chatbot_term_versions.term',
                    'chatbot_term_versions.english_meaning',
                    'chatbot_term_versions.tagalog_meaning',
                    'chatbot_term_versions.part_of_speech',
                ])
                ->map(static fn (ChatbotTermVersion $v): array => [
                    'term' => $v->term,
                    'english_meaning' => $v->english_meaning,
                    'tagalog_meaning' => $v->tagalog_meaning,
                    'part_of_speech' => $v->part_of_speech,
                ])
                ->all();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
