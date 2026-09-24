<?php

namespace App\Services;

use App\Models\HealthChunk;
use App\Services\Prompts\BikolPrompts;
use App\Services\Prompts\TagalogPrompts;
use App\Services\Prompts\EnglishPrompts;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class RagService
{
    protected string $ollamaBaseUrl = 'http://127.0.0.1:11434';
    protected string $embeddingModel = 'nomic-embed-text';
    protected string $chatModel = 'gemma4:31b-cloud';

    protected int $chunkSize = 500;
    protected int $chunkOverlap = 50;
    protected int $topK = 10;
    protected int $maxChunks = 4;

    /** @var array<string, string> */
    protected array $resolvedDocumentTextCache = [];

    /** Set in ask(); true only when retrieve() actually runs. */
    public bool $retrieveInvoked = false;

    public function indexAllDocuments(): array
    {
        $root = storage_path('app/health_docs');
        $languages = ['en', 'tl', 'bcl'];
        $totalChunks = 0;
        $log = [];

        HealthChunk::truncate();

        foreach ($languages as $lang) {
            $langPath = $root . DIRECTORY_SEPARATOR . $lang;
            if (!File::isDirectory($langPath)) {
                $log[] = "Skipped missing folder: {$langPath}";
                continue;
            }

            $categories = File::directories($langPath);

            foreach ($categories as $categoryPath) {
                $category = basename($categoryPath);
                $files = File::files($categoryPath);

                foreach ($files as $file) {
                    if ($file->getExtension() !== 'txt') {
                        continue;
                    }

                    $filename = $file->getFilename();
                    $text = File::get($file->getPathname());
                    $chunks = $this->chunkText($text);

                    foreach ($chunks as $i => $chunk) {
                        $embedding = $this->embed($chunk);

                        HealthChunk::create([
                            'language'    => $lang,
                            'category'    => $category,
                            'source_file' => $filename,
                            'chunk_index' => $i,
                            'content'     => $chunk,
                            'embedding'   => $embedding,
                        ]);

                        $totalChunks++;
                    }
                }
            }

            $log[] = "Indexed language: {$lang}";
        }

        $log[] = "Done. Total chunks indexed: {$totalChunks}";
        return $log;
    }

    protected function chunkText(string $text): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $chunk = trim(mb_substr($text, $start, $this->chunkSize));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
            $start += $this->chunkSize - $this->chunkOverlap;
        }

        return $chunks;
    }

    protected function embed(string $text): array
    {
        $response = Http::timeout(60)->post("{$this->ollamaBaseUrl}/api/embeddings", [
            'model'  => $this->embeddingModel,
            'prompt' => $text,
        ]);
        $response->throw();
        return $response->json('embedding');
    }

    protected array $stopwords = [
        'uno', 'ano', 'sarin', 'sari', 'saan', 'kuno', 'kailan', 'isay', 'sino',
        'ngata', 'tangata', 'bakit', 'pauno', 'paano', 'pira', 'magkano', 'ilan', 'arin',
        'alin', 'amo', 'amu', 'oo', 'buku', 'hindi', 'pwede', 'ana', 'ang', 'an',
        'a', 'na', 'sa', 'ka', 'kan', 'kin', 'mga', 'para', 'ng',
        'gibuhon', 'maiwasan', 'makaiwas', 'gawin', 'gawa', 'ito', 'adi',
        'with', 'what', 'where', 'when', 'who', 'why', 'how', 'this', 'that',
        // Short particles that must never act as acronym/keywords
        'ba', 'da', 'di', 'pa', 'po', 'ay', 'or', 'to', 'is', 'be', 'of', 'on',
        'in', 'at', 'as', 'if', 'we', 'me', 'my', 'he', 'do', 'so', 'no', 'ok',
    ];

    protected function extractKeywords(string $question): array
    {
        preg_match_all('/[a-zA-Z]+/', mb_strtolower($question), $matches);
        $keywords = array_values(array_filter($matches[0], function ($w) {
            return mb_strlen($w) >= 4 && !in_array($w, $this->stopwords, true);
        }));

        foreach ($this->extractAcronymCandidates($question) as $acronym) {
            if (!in_array($acronym, $keywords, true)) {
                $keywords[] = $acronym;
            }
        }

        return $keywords;
    }

    /**
     * Short medical-style acronyms (e.g. ASD, BCG) kept for retrieval.
     * Case is taken from the original question when possible.
     */
    protected function extractAcronymCandidates(string $question): array
    {
        $candidates = [];

        // Strong signal: already-uppercase tokens in the raw question.
        if (preg_match_all('/\b[A-Z]{2,5}\b/', $question, $upperMatches)) {
            foreach ($upperMatches[0] as $token) {
                $lower = mb_strtolower($token);
                if (!in_array($lower, $this->stopwords, true)) {
                    $candidates[] = $lower;
                }
            }
        }

        // Weaker signal: short alphabetic tokens (often lowercased by users).
        // Length 3–4 only; length-2 lowercase particles are excluded.
        if (preg_match_all('/\b[A-Za-z]{3,4}\b/', $question, $shortMatches)) {
            foreach ($shortMatches[0] as $token) {
                $lower = mb_strtolower($token);
                if (in_array($lower, $this->stopwords, true)) {
                    continue;
                }
                // Skip ordinary words that happen to be 3–4 letters unless uppercase.
                if (!preg_match('/^[A-Z]{3,4}$/', $token) && !$this->looksLikeAcronymToken($lower)) {
                    continue;
                }
                $candidates[] = $lower;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Conservative check for lowercase short tokens that may be acronyms.
     * Final acceptance still requires a KB alias hit in retrieve().
     */
    protected function looksLikeAcronymToken(string $lower): bool
    {
        // All consonants or mostly consonant clusters are typical vaccine/diagnosis codes.
        $vowels = preg_match_all('/[aeiou]/u', $lower);
        $len = mb_strlen($lower);

        return $len >= 3 && $len <= 4 && $vowels <= 1;
    }

    /**
     * True when content presents $acronym as a real alias/abbreviation,
     * e.g. "Autism o ASD (Autism Spectrum Disorder)" or "ASD (...)".
     */
    protected function contentHasAcronymAlias(string $content, string $acronym): bool
    {
        $a = preg_quote($acronym, '/');

        return (bool) preg_match(
            '/(?:\bo\s+' . $a . '\b|\b' . $a . '\s*\(|\(' . $a . '\)|\b' . $a . '\b\s*[\/\-])/iu',
            $content
        );
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0; $normA = 0.0; $normB = 0.0;
        foreach ($a as $i => $valA) {
            $valB = $b[$i] ?? 0.0;
            $dot += $valA * $valB;
            $normA += $valA * $valA;
            $normB += $valB * $valB;
        }
        if ($normA == 0.0 || $normB == 0.0) return 0.0;
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Derive a short health-topic hint from free text using the same
     * keyword/acronym → source-file matching used by retrieve().
     * Returns a compact hint such as "autism" or "family planning", or null.
     */
    public function deriveTopicHintFromText(string $text, string $language, ?string $category = null): ?string
    {
        if ($language === 'en' && $this->looksLikeEnglishNonHealthUtterance($text)) {
            return null;
        }

        if ($language === 'tl') {
            $handled = $this->tagalogConversation()->resolve($text);
            if (($handled['type'] ?? '') === 'canned') {
                return null;
            }
        }

        $keywords = $this->extractKeywords($text);
        $acronyms = $this->extractAcronymCandidates($text);
        if ($language === 'en') {
            $acronyms = array_values(array_filter(
                $acronyms,
                function ($acronym) {
                    $acronym = mb_strtolower(trim((string) $acronym), 'UTF-8');
                    if ($acronym === '' || in_array($acronym, $this->stopwords, true)) {
                        return false;
                    }
                    if (
                        $this->isEnglishSupportIntentCueToken($acronym)
                        || $this->isEnglishNonMedicalFollowUpToken($acronym)
                        || $this->isEnglishFunctionWordToken($acronym)
                    ) {
                        return false;
                    }

                    return true;
                }
            ));
        }
        if ($language === 'tl') {
            $acronyms = array_values(array_filter(
                $acronyms,
                function ($acronym) {
                    $acronym = mb_strtolower(trim((string) $acronym), 'UTF-8');
                    if ($acronym === '' || in_array($acronym, $this->stopwords, true)) {
                        return false;
                    }

                    return !$this->isTagalogNonMedicalFollowUpToken($acronym);
                }
            ));
        }
        $strongKeywords = array_values(array_filter(
            $keywords,
            function ($kw) use ($language) {
                if (mb_strlen($kw) < 4) {
                    return false;
                }
                if ($language === 'bcl' && $this->isBikolTopicIdentityToken($kw)) {
                    return true;
                }
                if ($language === 'en') {
                    if (
                        $this->isEnglishSupportIntentCueToken($kw)
                        || $this->isEnglishNonMedicalFollowUpToken($kw)
                        || $this->isEnglishFunctionWordToken($kw)
                    ) {
                        return false;
                    }
                }
                if ($language === 'tl') {
                    if ($this->isTagalogNonMedicalFollowUpToken($kw)) {
                        return false;
                    }
                }

                return !$this->isWeakExplicitTopicAnchor($kw);
            }
        ));

        if ($strongKeywords === [] && $acronyms === []) {
            return null;
        }

        $query = HealthChunk::query()->where('language', $language);
        if ($category) {
            $query->where('category', $category);
        }

        $files = $query
            ->select('source_file', 'category', 'language')
            ->distinct()
            ->get();

        if ($files->isEmpty()) {
            return null;
        }

        $fullTextCache = [];
        $bestSlug = null;
        $bestScore = 0;
        $bestViaAcronym = false;
        $bestSlugKeywords = [];
        $bestContentKeywords = [];

        $resolveCurrentText = function ($file) use (&$fullTextCache): ?string {
            $cacheKey = $file->language . '|' . $file->category . '|' . $file->source_file;
            if (!array_key_exists($cacheKey, $fullTextCache)) {
                $path = storage_path(
                    "app/health_docs/{$file->language}/{$file->category}/{$file->source_file}"
                );
                $fullTextCache[$cacheKey] = File::exists($path)
                    ? (string) File::get($path)
                    : null;
            }

            return $fullTextCache[$cacheKey];
        };

        foreach ($files as $file) {
            $slug = strtolower(str_replace(['.txt', '_'], ['', ' '], $file->source_file));
            $score = 0;
            $viaAcronym = false;
            $slugKeywords = [];
            $contentKeywords = [];

            foreach ($strongKeywords as $kw) {
                if (str_contains($slug, $kw)) {
                    $score += 10 + mb_strlen($kw);
                    $slugKeywords[] = $kw;
                }
            }

            $fullText = $resolveCurrentText($file);
            if (is_string($fullText) && $fullText !== '') {
                foreach ($strongKeywords as $kw) {
                    $matched = $language === 'bcl'
                        ? $this->bikolDocumentMatchesTopicConcept($fullText, $kw)
                        : $this->textContainsTopicKeyword($fullText, $kw);
                    if ($matched) {
                        $score += mb_strlen($kw);
                        $contentKeywords[] = $kw;
                    }
                }
            }

            if ($score === 0 && $acronyms !== []) {
                $acronymText = $fullText;
                if ($acronymText === null) {
                    $sample = HealthChunk::query()
                        ->where('language', $file->language)
                        ->where('category', $file->category)
                        ->where('source_file', $file->source_file)
                        ->value('content');
                    $acronymText = (string) ($sample ?? '');
                }

                foreach ($acronyms as $acronym) {
                    if ($this->contentHasAcronymAlias($acronymText, $acronym)) {
                        $score += 3;
                        $viaAcronym = true;
                        break;
                    }
                }
            }

            if ($score === 0) {
                continue;
            }

            $prefer = $score > $bestScore
                || (
                    $score === $bestScore
                    && $bestSlug !== null
                    && mb_strlen($slug) < mb_strlen($bestSlug)
                );

            if ($prefer) {
                $bestScore = $score;
                $bestSlug = $slug;
                $bestViaAcronym = $viaAcronym;
                $bestSlugKeywords = $slugKeywords;
                $bestContentKeywords = $contentKeywords;
            }
        }

        if ($bestSlug === null) {
            return null;
        }

        // Prefer compact keyword overlap (generic; not a hard-coded topic list).
        $matchedKeywords = $bestSlugKeywords !== []
            ? $bestSlugKeywords
            : $bestContentKeywords;

        if ($matchedKeywords !== []) {
            usort($matchedKeywords, static function (string $a, string $b) use ($bestSlug): int {
                $posA = mb_strpos($bestSlug, $a);
                $posB = mb_strpos($bestSlug, $b);
                if ($posA === false && $posB === false) {
                    return 0;
                }
                if ($posA === false) {
                    return 1;
                }
                if ($posB === false) {
                    return -1;
                }

                return $posA <=> $posB;
            });

            return implode(' ', array_values(array_unique($matchedKeywords)));
        }

        // Acronym-only matches: use the source slug stem (e.g. "autism").
        if ($bestViaAcronym) {
            return $bestSlug;
        }

        return $bestSlug;
    }

    /**
     * True when the question itself names a health topic present in the KB.
     * Tagalog: deixis / intent-only wording is not treated as a new medical topic.
     */
    public function questionHasExplicitTopic(string $question, string $language, ?string $category = null): bool
    {
        if ($language === 'tl') {
            return $this->tagalogQuestionHasExplicitMedicalTopic($question, $category);
        }

        if ($language === 'en') {
            return $this->englishQuestionHasExplicitMedicalTopic($question, $category);
        }

        return $this->deriveTopicHintFromText($question, $language, $category) !== null;
    }

    /**
     * EN-only: true when the question names a real KB medical topic (not deixis/intent).
     */
    protected function englishQuestionHasExplicitMedicalTopic(string $question, ?string $category = null): bool
    {
        $hint = $this->deriveTopicHintFromText($question, 'en', $category);
        if ($hint === null || trim($hint) === '') {
            return false;
        }

        foreach ($this->extractKeywords($hint) as $kw) {
            if (!$this->isEnglishNonMedicalFollowUpToken((string) $kw)) {
                return true;
            }
        }

        foreach ($this->extractAcronymCandidates($hint) as $acronym) {
            if (!$this->isEnglishNonMedicalFollowUpToken((string) $acronym)) {
                return true;
            }
        }

        foreach (preg_split('/\s+/u', mb_strtolower(trim($hint), 'UTF-8')) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (!$this->isEnglishNonMedicalFollowUpToken($part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pronouns, intent/function words, and chit-chat leftovers that must not
     * count as an explicit English medical topic for topicHint inheritance.
     */
    protected function isEnglishNonMedicalFollowUpToken(string $token): bool
    {
        $token = mb_strtolower(trim($token), 'UTF-8');
        if ($token === '') {
            return true;
        }

        if ($this->isEnglishDeixisToken($token)) {
            return true;
        }

        if ($this->isEnglishSupportIntentCueToken($token) || $this->isEnglishFunctionWordToken($token)) {
            return true;
        }

        if ($this->isWeakExplicitTopicAnchor($token) || $this->isWeakScheduleTopicAnchor($token)) {
            return true;
        }

        return in_array($token, [
            'hello', 'thanks', 'thank', 'goodbye', 'please', 'okay', 'alright',
            'about', 'else', 'more', 'again', 'still',
        ], true);
    }

    /**
     * English deictic / pronoun cues — follow-up anchors, never topic identity.
     */
    protected function isEnglishDeixisToken(string $token): bool
    {
        $token = mb_strtolower(trim($token), 'UTF-8');

        return in_array($token, [
            'it', 'its', 'this', 'that', 'these', 'those', 'them', 'they',
        ], true);
    }

    /**
     * Build an internal EN question that exposes an inherited topic to
     * Phase 1 / Phase 2 only (not stored history / UI).
     */
    protected function buildEnglishInheritedTopicPipelineQuestion(string $question, string $topic): string
    {
        $topic = trim($topic);
        $q = trim($question);
        if ($topic === '' || $q === '') {
            return $q;
        }

        if (preg_match('/\b' . preg_quote($topic, '/') . '\b/ui', $q) === 1) {
            return $q;
        }

        $replaced = preg_replace('/\b(it|this|that)\b/ui', $topic, $q, 1, $count);
        if ($count > 0) {
            return is_string($replaced) ? $replaced : $q;
        }

        $lower = mb_strtolower($q, 'UTF-8');
        $base = preg_replace('/[\s?!.,;:]+$/u', '', $q) ?? $q;
        $base = trim($base);

        if (preg_match('/\b(symptoms?|signs?|causes?|benefits?)\b/u', $lower) === 1) {
            return $base . ' of ' . $topic . '?';
        }

        if (preg_match('/\b(prevent|prevention|prevented|preventing)\b/u', $lower) === 1) {
            return $base . ' for ' . $topic . '?';
        }

        if (preg_match('/\b(treat|treatment|treated|treating)\b/u', $lower) === 1) {
            return $base . ' for ' . $topic . '?';
        }

        if (preg_match('/^\s*what\s+about\b/u', $lower) === 1) {
            return $base . ' ' . $topic . '?';
        }

        if (preg_match('/^\s*why\??\s*$/u', $lower) === 1) {
            return 'Why does ' . $topic . ' happen?';
        }

        return $base . ' about ' . $topic . '?';
    }

    /**
     * Greetings / thanks / chit-chat must never become English health topic hints.
     */
    protected function looksLikeEnglishNonHealthUtterance(string $text): bool
    {
        $q = mb_strtolower(trim($text), 'UTF-8');
        if ($q === '') {
            return true;
        }

        $handled = $this->englishConversation()->resolve($text);
        if (($handled['type'] ?? '') === 'canned') {
            return true;
        }

        if (preg_match(
            '/^(?:thanks|thank\s+you|hello|hi|hey|goodbye|bye|ok|okay|sure|please)\b/u',
            $q
        ) === 1) {
            return true;
        }

        if (preg_match('/^good\s+(?:morning|afternoon|evening|night)\b/u', $q) === 1) {
            return true;
        }

        if (preg_match('/^(?:who\s+are\s+you|what\s+can\s+you\s+do)\b/u', $q) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Ambiguous English follow-ups that inherit a prior topic when none is named.
     */
    protected function looksLikeEnglishAmbiguousFollowUp(string $question): bool
    {
        $q = mb_strtolower(trim($question), 'UTF-8');

        if (preg_match('/^\s*why\??\s*$/u', $q) === 1) {
            return true;
        }

        if (preg_match(
            '/^\s*what\s+about\s+(?:treatment|prevention|symptoms?|signs?|causes?|benefits?)\??\s*$/u',
            $q
        ) === 1) {
            return true;
        }

        if (preg_match(
            '/^\s*what\s+(?:are|is)\s+the\s+(?:symptoms?|signs?|causes?|benefits?)\??\s*$/u',
            $q
        ) === 1) {
            return true;
        }

        if (preg_match('/\b(it|this|that)\b/u', $q) === 1
            && preg_match(
                '/\b(why|what|how|cause|causes|symptom|symptoms|sign|signs|prevent|prevention|treat|treatment|benefit|benefits)\b/u',
                $q
            ) === 1
        ) {
            return true;
        }

        return false;
    }

    /**
     * TL-only: true when the question names a real KB medical topic (not deixis/intent).
     */
    protected function tagalogQuestionHasExplicitMedicalTopic(string $question, ?string $category = null): bool
    {
        $hint = $this->deriveTopicHintFromText($question, 'tl', $category);
        if ($hint === null || trim($hint) === '') {
            return false;
        }

        foreach ($this->extractKeywords($hint) as $kw) {
            if (!$this->isTagalogNonMedicalFollowUpToken((string) $kw)) {
                return true;
            }
        }

        foreach ($this->extractAcronymCandidates($hint) as $acronym) {
            if (!$this->isTagalogNonMedicalFollowUpToken((string) $acronym)) {
                return true;
            }
        }

        // Slug-style hints (e.g. "magandang umaga") — require at least one medical token.
        foreach (preg_split('/\s+/u', mb_strtolower(trim($hint), 'UTF-8')) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (!$this->isTagalogNonMedicalFollowUpToken($part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pronouns, intent/function words, and greeting leftovers that must not
     * count as an explicit Tagalog medical topic for topicHint inheritance.
     */
    protected function isTagalogNonMedicalFollowUpToken(string $token): bool
    {
        $token = mb_strtolower(trim($token), 'UTF-8');
        if ($token === '') {
            return true;
        }

        if ($this->isTagalogDeixisToken($token)) {
            return true;
        }

        if ($this->isTagalogSupportIntentCueToken($token)) {
            return true;
        }

        if ($this->isWeakExplicitTopicAnchor($token) || $this->isWeakScheduleTopicAnchor($token)) {
            return true;
        }

        return in_array($token, [
            // Intent / follow-up cues not always covered above
            'maiiwasan', 'maiwasan', 'pagiwas', 'epekto', 'sanhi', 'senyales',
            'paggamot', 'gamot', 'gamutin', 'ginagamot', 'lunas', 'operasyon',
            'benepisyo', 'benipisyo', 'nangyayari', 'naman',
            // Conversational leftovers that can false-match body text
            'magandang', 'umaga', 'hapon', 'gabi', 'kumusta', 'kamusta', 'hello',
            'salamat', 'maraming', 'thanks', 'thank', 'paalam', 'goodbye',
            'walang', 'anuman',
        ], true);
    }

    /**
     * Tagalog deictic / pronoun cues — follow-up anchors, never topic identity.
     */
    protected function isTagalogDeixisToken(string $token): bool
    {
        $token = mb_strtolower(trim($token), 'UTF-8');

        return in_array($token, [
            'ito', 'nito', 'dito', 'iyan', 'niyan', 'iyon', 'doon', 'nun',
        ], true);
    }

    /**
     * Build an internal TL question that exposes an inherited topic to
     * Priority 1 / TL-C without changing the user-visible utterance.
     */
    protected function buildTagalogInheritedTopicPipelineQuestion(string $question, string $topic): string
    {
        $topic = trim($topic);
        $q = trim($question);
        if ($topic === '' || $q === '') {
            return $q;
        }

        if (preg_match('/\b' . preg_quote($topic, '/') . '\b/ui', $q) === 1) {
            return $q;
        }

        $lower = mb_strtolower($q, 'UTF-8');

        if (preg_match('/^\s*bakit\??\s*$/u', $lower) === 1) {
            return 'Bakit nagkakaroon ng ' . $topic . '?';
        }

        $replaced = preg_replace('/\bsakit\s+na\s+(ito|iyon|iyan)\b/ui', $topic, $q, 1, $count);
        if ($count > 0) {
            return is_string($replaced) ? $replaced : $q;
        }

        $replaced = preg_replace('/\b(nito|niyan)\b/ui', 'ng ' . $topic, $q, 1, $count);
        if ($count > 0) {
            return is_string($replaced) ? $replaced : $q;
        }

        $replaced = preg_replace('/\b(dito|doon)\b/ui', 'sa ' . $topic, $q, 1, $count);
        if ($count > 0) {
            return is_string($replaced) ? $replaced : $q;
        }

        $replaced = preg_replace('/\b(ito|iyan|iyon|nun)\b/ui', $topic, $q, 1, $count);
        if ($count > 0) {
            return is_string($replaced) ? $replaced : $q;
        }

        $base = preg_replace('/[\s?!.,;:]+$/u', '', $q) ?? $q;
        $base = trim($base);

        if (preg_match('/\b(sintomas|senyales|palatandaan|paggamot|gamot|dahilan|sanhi|epekto|benepisyo|benipisyo)\b/u', $lower) === 1) {
            return $base . ' ng ' . $topic . '?';
        }

        if (preg_match('/\bginagamot\b|\bgamutin\b/u', $lower) === 1) {
            return $base . ' ang ' . $topic . '?';
        }

        if (preg_match('/mai+wasan|pag[\s\-]*iwas|\biwasan\b|\bpaano\b/u', $lower) === 1) {
            return $base . ' ang ' . $topic . '?';
        }

        return $base . ' tungkol sa ' . $topic . '?';
    }

    /**
     * Ambiguous Tagalog follow-ups that inherit a prior topic when none is named.
     */
    protected function looksLikeTagalogAmbiguousFollowUp(string $question): bool
    {
        $q = mb_strtolower(trim($question), 'UTF-8');

        if (preg_match('/^\s*bakit\??\s*$/u', $q) === 1) {
            return true;
        }

        if (preg_match(
            '/^\s*paano\s+naman\s+ang\s+(?:paggamot|gamot|gamutan|pag-?iwas|sintomas|senyales|benepisyo|sanhi|dahilan)\b/u',
            $q
        ) === 1) {
            return true;
        }

        if (preg_match(
            '/^\s*ano\s+ang\s+(?:mga\s+)?(?:sintomas|senyales|palatandaan|sanhi|dahilan|benepisyo|benipisyo|paggamot|gamot)\b/u',
            $q
        ) === 1) {
            return true;
        }

        if (
            preg_match('/\b(ito|nito|dito|iyan|niyan|iyon|doon|nun)\b/u', $q) === 1
            && preg_match(
                '/\b(bakit|ano|paano|sanhi|dahilan|sintomas|senyales|palatandaan|maiwasan|maiiwasan|iwasan|ginagamot|gamutin|paggamot|gamot|benepisyo|benipisyo|nangyayari)\b/u',
                $q
            ) === 1
        ) {
            return true;
        }

        return false;
    }

    /**
     * Gate: only health-style follow-ups may inherit a prior topic hint.
     */
    public function looksLikeHealthFollowUp(string $question): bool
    {
        if ($this->looksLikeNonHealthRequest($question)) {
            return false;
        }

        if ($this->looksLikeEnglishNonHealthUtterance($question)) {
            return false;
        }

        $tlHandled = $this->tagalogConversation()->resolve($question);
        if (($tlHandled['type'] ?? '') === 'canned') {
            return false;
        }

        if ($this->looksLikeEnglishAmbiguousFollowUp($question)) {
            return true;
        }

        if ($this->looksLikeTagalogAmbiguousFollowUp($question)) {
            return true;
        }

        $intent = $this->detectBikolIntent($question);
        if (in_array($intent, [
            'symptoms',
            'causes',
            'benefits',
            'management',
            'diagnosis',
            'effects',
            'schedule',
            'warning',
        ], true)) {
            return true;
        }

        $q = mb_strtolower(trim($question));

        // Soft interrogative / deixis follow-ups (topic often omitted).
        // Includes Tagalog deixis (nito/dito/…) used by TL-D2 inheritance.
        if (preg_match(
            '/\b(ano|ngata|ngaya|pauno|paano|kaipwana|bakit|what|how|why|when|adi|ito|nito|dito|iyan|niyan|iyon|doon|nun|this|that|it)\b/u',
            $q
        )) {
            return true;
        }

        return false;
    }

    /**
     * Obvious non-health requests must never inherit a medical topic.
     */
    protected function looksLikeNonHealthRequest(string $question): bool
    {
        $q = mb_strtolower($question);

        foreach ([
            'html', 'css', 'javascript', 'typescript', 'portfolio', 'github',
            'programming', 'python', 'laravel', 'react', 'vue', 'sql',
            'source code', 'write code', 'give me code', 'boilerplate',
        ] as $marker) {
            if (str_contains($q, $marker)) {
                return true;
            }
        }

        return (bool) preg_match('/\bcode\b/u', $q);
    }

    /**
     * Intent/timing words that must not identify a health document by themselves.
     */
    protected function isWeakScheduleTopicAnchor(string $keyword): bool
    {
        return in_array($keyword, [
            'uno', 'edad', 'bulan', 'buwan', 'taon', 'aldow', 'days', 'day',
            'araw', 'stage', 'yugto', 'milestone', 'schedule', 'iskedyul',
            'pagdakulo', 'pagbabago', 'sakop', 'sunod', 'kuno', 'first',
            'dapat',
        ], true);
    }

    /**
     * Extra generic/intent words that must not mark a question as a new topic.
     * Kept separate from retrieval ranking.
     */
    protected function isWeakExplicitTopicAnchor(string $keyword): bool
    {
        if ($this->isWeakScheduleTopicAnchor($keyword)) {
            return true;
        }

        return in_array($keyword, [
            'importante', 'ngata', 'igen', 'agko', 'ataman', 'aatamanon',
            'gibuhon', 'senyales', 'sintomas', 'palatandaan', 'katangian',
            'dahilan', 'rason', 'benepisyo', 'benipisyo', 'ngaya',
            'maiwasan', 'makaiwas', 'likay', 'malikayan', 'pauno', 'paano',
        ], true);
    }

    /**
     * BCL question words that name a topic even when they also appear in
     * schedule/timing phrasing. English/Filipino ranking is unchanged.
     */
    protected function isBikolTopicIdentityToken(string $token): bool
    {
        $token = mb_strtolower(trim($token), 'UTF-8');

        return $token === 'milestone';
    }

    /**
     * Concept aliases for BCL topic identity and retrieval only.
     * Does not rewrite answers or corpus text.
     *
     * @return list<string>
     */
    protected function bikolTopicConceptEquivalents(string $token): array
    {
        $token = mb_strtolower(trim($token), 'UTF-8');
        if ($token === '') {
            return [];
        }

        if ($token === 'milestone' || $token === 'yugto' || $token === 'stage') {
            return ['milestone', 'yugto', 'stage'];
        }

        if ($token === 'bakuna' || $token === 'vaccine' || $token === 'immunization') {
            return ['bakuna', 'vaccine', 'immunization'];
        }

        return [$token];
    }

    /**
     * True when a BCL keyword should participate in retrieve matching.
     */
    protected function isUsableBikolRetrieveTopicKeyword(string $keyword): bool
    {
        if (mb_strlen($keyword) < 4) {
            return false;
        }

        if ($this->isBikolTopicIdentityToken($keyword)) {
            return true;
        }

        return !$this->isWeakScheduleTopicAnchor($keyword);
    }

    /**
     * True when a Tagalog keyword may participate in retrieve matching/scoring.
     * Intent/function words (gamot, kapag, sintomas, …) are excluded so they
     * cannot pull documents via body/substring hits (e.g. gamot ⊂ paggamot).
     * Priority 1 support gating is unchanged.
     */
    protected function isUsableTagalogRetrieveKeyword(string $keyword): bool
    {
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');
        if ($keyword === '' || mb_strlen($keyword) < 4) {
            return false;
        }

        if ($this->isTagalogSupportIntentCueToken($keyword)) {
            return false;
        }

        // Extra function words that can survive extractKeywords but are not topics.
        if (in_array($keyword, [
            'kaya', 'mong', 'naman', 'ito', 'iyan', 'iyon', 'doon', 'dito',
            'kung', 'wala', 'meron', 'mayroon', 'tungkol',
        ], true)) {
            return false;
        }

        return true;
    }

    /**
     * Language-aware retrieve keyword gate.
     */
    protected function isUsableRetrieveTopicKeyword(string $keyword, string $language): bool
    {
        return match ($language) {
            'bcl' => $this->isUsableBikolRetrieveTopicKeyword($keyword),
            'tl' => $this->isUsableTagalogRetrieveKeyword($keyword),
            'en' => mb_strlen($keyword) >= 4
                && !$this->isWeakScheduleTopicAnchor($keyword)
                && !$this->isEnglishSupportIntentCueToken($keyword)
                && !$this->isEnglishFunctionWordToken($keyword),
            default => mb_strlen($keyword) >= 4 && !$this->isWeakScheduleTopicAnchor($keyword),
        };
    }

    /**
     * Small TL/EN identity aliases justified by corpus banners/slugs.
     * Not a disease whitelist — only alternate names for the same topic identity.
     *
     * @return list<string>
     */
    protected function tagalogTopicTokenEquivalents(string $token): array
    {
        $token = mb_strtolower(trim($token), 'UTF-8');
        if ($token === '') {
            return [];
        }

        if ($token === 'malnutrition' || $token === 'malnutrisyon') {
            return ['malnutrition', 'malnutrisyon'];
        }

        if ($token === 'trangkaso' || $token === 'influenza' || $token === 'flu') {
            return ['trangkaso', 'influenza', 'flu'];
        }

        return $this->bikolHeadingTokenEquivalents($token);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    protected function expandTagalogTopicTokensForIdentity(array $tokens): array
    {
        $expanded = [];
        foreach ($tokens as $token) {
            foreach ($this->tagalogTopicTokenEquivalents((string) $token) as $alias) {
                if ($alias !== '') {
                    $expanded[] = $alias;
                }
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Topic tokens used for TL-B document identity (primary + dahil-sa constraints
     * + TL-A usable retrieve keywords). Generic — not a filename map.
     *
     * @return list<string>
     */
    protected function tagalogRetrieveIdentityTopicTokens(string $question, array $keywords = []): array
    {
        $topics = $this->extractTagalogQuestionTopics($question);
        $tokens = array_merge($topics['primary'], $topics['constraints']);
        foreach ($keywords as $kw) {
            if ($this->isUsableTagalogRetrieveKeyword((string) $kw)) {
                $tokens[] = (string) $kw;
            }
        }

        return $this->expandTagalogTopicTokensForIdentity($tokens);
    }

    /**
     * True when a TL document's slug/banner/title identity matches topic tokens.
     * Body-only mentions do not count.
     *
     * @param  list<string>  $topicTokens  already expanded identity tokens
     */
    protected function tagalogDocumentCompatibleWithTopic(
        string $content,
        string $sourceFile,
        array $topicTokens
    ): bool {
        $topicTokens = array_values(array_filter(
            $topicTokens,
            fn ($token) => is_string($token) && mb_strlen($token) >= 4
        ));
        if ($topicTokens === []) {
            return false;
        }

        $slug = strtolower(str_replace(['.txt', '_'], ['', ' '], $sourceFile));
        foreach ($topicTokens as $token) {
            if (str_contains($slug, $token)) {
                return true;
            }
        }

        $identityTokens = $this->expandTagalogTopicTokensForIdentity(
            $this->extractBikolTopicTitleTokens($content, $sourceFile)
        );

        return $this->bikolHeadingTokenOverlap($topicTokens, $identityTokens) !== [];
    }

    /**
     * BCL document match for a topic keyword, including concept aliases.
     */
    protected function bikolDocumentMatchesTopicConcept(string $text, string $keyword): bool
    {
        if ($this->textContainsTopicKeyword($text, $keyword)) {
            return true;
        }

        if (!$this->isBikolTopicIdentityToken($keyword)) {
            return false;
        }

        $lower = mb_strtolower($text, 'UTF-8');
        foreach ($this->bikolTopicConceptEquivalents($keyword) as $alias) {
            if ($alias === $keyword) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/u', $lower) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collapse hyphens/spaces/slashes for birth-word matching only.
     * Does not strip digits, so age ranges stay intact on the original string.
     */
    protected function normalizeBikolMatchKey(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return preg_replace('/[\s\-_\/]+/u', '', $text) ?? $text;
    }

    /**
     * True when $keyword appears in $text as a topic word (raw or hyphen-normalized).
     * Numeric tokens must sit next to an age/time unit so bare counts (e.g. "1000 na igen")
     * cannot identify a document.
     */
    protected function textContainsTopicKeyword(string $text, string $keyword): bool
    {
        if ($this->isWeakScheduleTopicAnchor($keyword)) {
            return false;
        }

        $lower = mb_strtolower($text);
        $quoted = preg_quote($keyword, '/');

        if (preg_match('/^\d{3,4}$/', $keyword) === 1) {
            return preg_match(
                '/\b' . $quoted . '\s*(?:na\s+)?(?:days?|aldow|bulan|buwan|taon|araw)\b/u',
                $lower
            ) === 1;
        }

        if (mb_strlen($keyword) < 4) {
            return false;
        }

        if (preg_match('/\b' . $quoted . '\b/u', $lower) === 1) {
            return true;
        }

        // Only worth normalizing when the keyword itself has a hyphen/space/slash
        // variant to collapse (e.g. "post-natal" vs "postnatal"). Plain keywords
        // have nothing to strip on the keyword side, so this fallback would only
        // ever strip spaces from $text — which lets unrelated adjacent words
        // accidentally spell the keyword (e.g. "...out of every..." -> "...ofevery..."
        // contains "fever"). Skip the fallback entirely in that case.
        if (preg_match('/[\s\-_\/]/u', $keyword) !== 1) {
            return false;
        }

        $normText = $this->normalizeBikolMatchKey($lower);
        $normKw = $this->normalizeBikolMatchKey($keyword);

        return $normKw !== '' && str_contains($normText, $normKw);
    }

    /**
     * Load the current on-disk document when it exists; otherwise reconstruct
     * from ordered health_chunks. Last resort: the single provided chunk.
     */
    protected function resolveCurrentHealthDocumentContent(object $chunk): string
    {
        return $this->resolveHealthDocumentText($chunk);
    }

    /**
     * Preferred full-document text for a KB source:
     * 1) current on-disk TXT
     * 2) all matching health_chunks joined in chunk_index order
     * 3) the single chunk body
     */
    protected function resolveHealthDocumentText(object $chunk): string
    {
        $language = $chunk->language ?? null;
        $category = $chunk->category ?? null;
        $source = $chunk->source_file ?? null;
        $single = trim((string) ($chunk->content ?? ''));

        if (!is_string($language) || !is_string($category) || !is_string($source) || $source === '') {
            return $single;
        }

        $cacheKey = $language . '|' . $category . '|' . $source;
        if (array_key_exists($cacheKey, $this->resolvedDocumentTextCache)) {
            return $this->resolvedDocumentTextCache[$cacheKey];
        }

        $path = storage_path("app/health_docs/{$language}/{$category}/{$source}");
        if (File::exists($path)) {
            return $this->resolvedDocumentTextCache[$cacheKey] = trim((string) File::get($path));
        }

        $reconstructed = $this->reconstructDocumentFromChunks($language, $category, $source);
        if ($reconstructed !== '') {
            return $this->resolvedDocumentTextCache[$cacheKey] = $reconstructed;
        }

        return $this->resolvedDocumentTextCache[$cacheKey] = $single;
    }

    /**
     * Join every stored chunk for one source file, preserving document order.
     */
    protected function reconstructDocumentFromChunks(string $language, string $category, string $source): string
    {
        $parts = HealthChunk::query()
            ->where('language', $language)
            ->where('category', $category)
            ->where('source_file', $source)
            ->orderBy('chunk_index')
            ->pluck('content')
            ->map(fn ($content) => (string) $content)
            ->filter(fn ($content) => trim($content) !== '')
            ->values()
            ->all();

        if ($parts === []) {
            return '';
        }

        return trim($this->joinOverlappingChunkTexts($parts));
    }

    /**
     * Append chunks in order. If the end of chunk N is an exact prefix of chunk N+1,
     * drop only that overlap. Uncertain overlap is kept (small duplicates over lost text).
     *
     * @param  array<int, string>  $parts
     */
    protected function joinOverlappingChunkTexts(array $parts): string
    {
        $joined = '';

        foreach ($parts as $part) {
            if ($joined === '') {
                $joined = $part;
                continue;
            }

            $overlap = $this->chunkOverlapLength($joined, $part);
            $joined .= $overlap > 0 ? mb_substr($part, $overlap) : $part;
        }

        return $joined;
    }

    /**
     * Longest exact suffix/prefix overlap, bounded by the indexer window size.
     */
    protected function chunkOverlapLength(string $left, string $right): int
    {
        $max = min(mb_strlen($left), mb_strlen($right), $this->chunkOverlap + 20);
        $min = 12;

        for ($len = $max; $len >= $min; $len--) {
            if (mb_substr($left, -$len) === mb_substr($right, 0, $len)) {
                return $len;
            }
        }

        return 0;
    }

    public function retrieve(string $question, string $language, ?string $category = null, ?string $topicHint = null): array
    {
        $searchText = ($topicHint !== null && $topicHint !== '')
            ? trim($topicHint . ' ' . $question)
            : $question;

        $query = HealthChunk::where('language', $language);
        if ($category) {
            $query->where('category', $category);
        }
        $candidates = $query->get();

        $resolveFullText = function ($chunk): string {
            return $this->resolveHealthDocumentText($chunk);
        };

        $matchByKeywords = function ($items, $keywords, $acronyms) use ($resolveFullText, $language) {
            return $items->filter(function ($item) use ($keywords, $acronyms, $resolveFullText, $language) {
                $chunk = $item['chunk'];
                $slug = strtolower(str_replace(['.txt', '_'], ['', ' '], $chunk->source_file));

                foreach ($keywords as $kw) {
                    // Slug matching stays limited to strong topic words.
                    if (
                        $this->isUsableRetrieveTopicKeyword($kw, $language)
                        && str_contains($slug, $kw)
                    ) {
                        return true;
                    }
                }

                $fullText = null;
                foreach ($keywords as $kw) {
                    if (!$this->isUsableRetrieveTopicKeyword($kw, $language)) {
                        continue;
                    }
                    $fullText ??= $resolveFullText($chunk);
                    $chunkHit = $language === 'bcl'
                        ? $this->bikolDocumentMatchesTopicConcept((string) $chunk->content, $kw)
                        : $this->textContainsTopicKeyword((string) $chunk->content, $kw);
                    $fullHit = $language === 'bcl'
                        ? $this->bikolDocumentMatchesTopicConcept($fullText, $kw)
                        : $this->textContainsTopicKeyword($fullText, $kw);
                    if ($chunkHit || $fullHit) {
                        return true;
                    }
                }

                if (empty($acronyms)) {
                    return false;
                }

                $fullText ??= $resolveFullText($chunk);
                foreach ($acronyms as $acronym) {
                    // TL: ignore intent/function tokens misclassified as acronyms (e.g. "may").
                    if (
                        $language === 'tl'
                        && $this->isTagalogSupportIntentCueToken((string) $acronym)
                    ) {
                        continue;
                    }
                    if (
                        $this->contentHasAcronymAlias((string) $chunk->content, $acronym)
                        || $this->contentHasAcronymAlias($fullText, $acronym)
                    ) {
                        return true;
                    }
                }

                return false;
            });
        };

        $toFullDocResult = function ($keywordMatches) use ($resolveFullText, &$keywords, &$ageCueForRetrieve, $language, $question): array {
            $scoredFiles = $keywordMatches->pluck('chunk')
                ->unique(fn ($chunk) => $chunk->language . '|' . $chunk->category . '|' . $chunk->source_file)
                ->map(function ($chunk) use ($keywords, $resolveFullText, &$ageCueForRetrieve, $language) {
                    $slug = strtolower(str_replace(['.txt', '_'], ['', ' '], $chunk->source_file));
                    $fullText = $resolveFullText($chunk);
                    $titleTokens = $this->extractBikolTopicTitleTokens(
                        $fullText,
                        (string) $chunk->source_file
                    );
                    $score = 0;
                    $hasTitleOverlap = false;
                    foreach ($keywords as $kw) {
                        if (!$this->isUsableRetrieveTopicKeyword($kw, $language)) {
                            continue;
                        }
                        if (mb_strlen($kw) >= 4 && str_contains($slug, $kw)) {
                            $score += 10;
                        }
                        $textHit = $language === 'bcl'
                            ? $this->bikolDocumentMatchesTopicConcept($fullText, $kw)
                            : $this->textContainsTopicKeyword($fullText, $kw);
                        if ($textHit) {
                            $score += 5;
                        }
                        // Banner/title overlap is awarded once per document, not per keyword.
                        if (
                            !$hasTitleOverlap
                            && mb_strlen($kw) >= 4
                            && !$this->isBikolSemanticIntentCueToken($kw)
                            && !(
                                $language === 'tl'
                                && $this->isTagalogSupportIntentCueToken($kw)
                            )
                            && $this->bikolHeadingTokenOverlap([$kw], $titleTokens) !== []
                        ) {
                            $hasTitleOverlap = true;
                        }
                    }
                    if ($hasTitleOverlap) {
                        $score += 15;
                    }
                    if (
                        is_array($ageCueForRetrieve)
                        && $this->extractBikolAgeScopedSection($fullText, $ageCueForRetrieve) !== []
                    ) {
                        $score += 20;
                    }

                    return [
                        'chunk' => $chunk,
                        'score' => $score,
                        'full_text' => $fullText,
                    ];
                })
                ->values();

            // TL-B: prefer slug/banner/title identity; do not promote body-only docs.
            if ($language === 'tl') {
                $identityTopics = $this->tagalogRetrieveIdentityTopicTokens($question, $keywords);
                if ($identityTopics !== []) {
                    $identityFiles = $scoredFiles->filter(function (array $row) use ($identityTopics) {
                        $chunk = $row['chunk'];

                        return $this->tagalogDocumentCompatibleWithTopic(
                            (string) $row['full_text'],
                            (string) ($chunk->source_file ?? ''),
                            $identityTopics
                        );
                    })->values();

                    if ($identityFiles->isEmpty()) {
                        return [
                            'chunks' => [],
                            'usedKeywordMatch' => true,
                        ];
                    }

                    $scoredFiles = $identityFiles;
                }
            }

            $matchedFiles = $scoredFiles
                ->sortByDesc(fn (array $row) => $row['score'])
                ->take(2)
                ->pluck('chunk');

            $fullDocChunks = $matchedFiles->map(function ($chunk) use ($resolveFullText) {
                return (object) [
                    'content' => trim($resolveFullText($chunk)),
                    'source_file' => $chunk->source_file,
                ];
            });

            return [
                'chunks' => $fullDocChunks->values()->all(),
                'usedKeywordMatch' => true,
            ];
        };

        $ageCueForRetrieve = null;

        $keywordSeed = $candidates->map(fn ($chunk) => [
            'chunk' => $chunk,
            'similarity' => 0.0,
        ]);

        $keywords = $this->extractKeywords($searchText);
        if (preg_match_all('/\b\d{3,4}\b/u', mb_strtolower($searchText), $numericMatches) > 0) {
            foreach ($numericMatches[0] as $num) {
                if (!in_array($num, $keywords, true)) {
                    $keywords[] = $num;
                }
            }
        }
        $acronyms = $this->extractAcronymCandidates($searchText);
        if ($language === 'bcl' && $this->isBikolScheduleQuestion(mb_strtolower($question))) {
            $ageCueForRetrieve = $this->extractAgeCueFromQuestion($question);
        }
        $keywordMatches = $matchByKeywords($keywordSeed, $keywords, $acronyms);

        if ($keywordMatches->isNotEmpty()) {
            return $toFullDocResult($keywordMatches);
        }

        if ($language === 'bcl' && is_array($ageCueForRetrieve)) {
            $ageMatches = $keywordSeed->filter(
                function ($item) use ($ageCueForRetrieve, $resolveFullText) {
                    return $this->extractBikolAgeScopedSection(
                        $resolveFullText($item['chunk']),
                        $ageCueForRetrieve
                    ) !== [];
                }
            );
            if ($ageMatches->isNotEmpty()) {
                return $toFullDocResult($ageMatches);
            }
        }

        // Topic carry-over: if the combined query missed, anchor on the hint alone.
        // Do not fall through to unconstrained embedding (that caused wrong-doc follow-ups).
        if ($topicHint !== null && $topicHint !== '') {
            $hintKeywords = $this->extractKeywords($topicHint);
            $hintAcronyms = $this->extractAcronymCandidates($topicHint);
            $hintMatches = $matchByKeywords($keywordSeed, $hintKeywords, $hintAcronyms);

            if ($hintMatches->isNotEmpty()) {
                return $toFullDocResult($hintMatches);
            }

            return ['chunks' => [], 'usedKeywordMatch' => false];
        }

        $queryEmbedding = $this->embed($searchText);

        $scored = $candidates->map(function ($chunk) use ($queryEmbedding) {
            return [
                'chunk' => $chunk,
                'similarity' => $this->cosineSimilarity($queryEmbedding, $chunk->embedding),
            ];
        });

        $best = $scored->max('similarity');

        if ($best === null || $best < 0.75) {
            return ['chunks' => [], 'usedKeywordMatch' => false];
        }

        $threshold = $best * 0.87;
        $chosen = $scored->filter(fn ($item) => $item['similarity'] >= $threshold)
            ->sortByDesc('similarity')
            ->take($this->maxChunks);

        return [
            'chunks' => $chosen->pluck('chunk')->values()->all(),
            'usedKeywordMatch' => false,
        ];
    }

    protected function bikolConversation(): BikolConversationService
    {
        return app(BikolConversationService::class);
    }

    protected function tagalogConversation(): TagalogConversationService
    {
        return app(TagalogConversationService::class);
    }

    protected function englishConversation(): EnglishConversationService
    {
        return app(EnglishConversationService::class);
    }

    public function ask(string $question, string $language, ?string $category = null, ?string $topicHint = null): array
{
    set_time_limit(180);

    $detectedLanguage = $this->detectLanguageFromQuestion($question, $language);
    if ($detectedLanguage !== $language) {
        Log::info('Language detection overrode selected language', [
            'selected' => $language,
            'detected' => $detectedLanguage,
            'question' => $question,
        ]);
        $language = $detectedLanguage;
    }

    $this->retrieveInvoked = false;
    if ($language === 'bcl') {
        $conversation = $this->bikolConversation();
        $handled = $conversation->resolve($question);
        if ($handled['type'] === 'canned') {
            return [
                'title' => null,
                'answer' => $handled['answer'],
                'points' => [],
                'sources' => [],
                'language' => 'bcl',
                'is_conversation' => true,
            ];
        }
        if ($handled['type'] === 'health') {
            $question = $handled['question'];
        }
    }

    if ($language === 'tl') {
        $conversation = $this->tagalogConversation();
        $handled = $conversation->resolve($question);
        if ($handled['type'] === 'canned') {
            return [
                'title' => null,
                'answer' => $handled['answer'],
                'points' => [],
                'sources' => [],
                'language' => 'tl',
                'is_conversation' => true,
            ];
        }
        if ($handled['type'] === 'health') {
            $question = $handled['question'];
        }
    }

    if ($language === 'en') {
        $conversation = $this->englishConversation();
        $handled = $conversation->resolve($question);
        if ($handled['type'] === 'canned') {
            return [
                'title' => null,
                'answer' => $handled['answer'],
                'points' => [],
                'sources' => [],
                'language' => 'en',
                'is_conversation' => true,
            ];
        }
        if ($handled['type'] === 'health') {
            $question = $handled['question'];
        }
    }

    // User-visible / model question stays as resolved utterance (after TL-D1 strip).
    $userQuestion = $question;

    $effectiveTopicHint = null;
    if (
        $topicHint !== null
        && $topicHint !== ''
        && !$this->questionHasExplicitTopic($question, $language, $category)
        && $this->looksLikeHealthFollowUp($question)
    ) {
        $effectiveTopicHint = $topicHint;
    }

    // TL-D2 / EN-D2: expose inherited topic to gates only (not stored history / UI).
    $pipelineQuestion = $question;
    if (
        $language === 'tl'
        && $effectiveTopicHint !== null
        && $effectiveTopicHint !== ''
    ) {
        $pipelineQuestion = $this->buildTagalogInheritedTopicPipelineQuestion(
            $question,
            $effectiveTopicHint
        );
        if ($pipelineQuestion !== $question) {
            Log::debug('Tagalog follow-up pipeline question', [
                'user_question' => $userQuestion,
                'topic_hint' => $effectiveTopicHint,
                'pipeline_question' => $pipelineQuestion,
            ]);
        }
    }

    if (
        $language === 'en'
        && $effectiveTopicHint !== null
        && $effectiveTopicHint !== ''
    ) {
        $pipelineQuestion = $this->buildEnglishInheritedTopicPipelineQuestion(
            $question,
            $effectiveTopicHint
        );
        if ($pipelineQuestion !== $question) {
            Log::debug('English follow-up pipeline question', [
                'user_question' => $userQuestion,
                'topic_hint' => $effectiveTopicHint,
                'pipeline_question' => $pipelineQuestion,
            ]);
        }
    }

    $this->retrieveInvoked = true;
    $retrieveQuestion = in_array($language, ['en', 'tl'], true) ? $pipelineQuestion : $question;
    $retrieval = $this->retrieve($retrieveQuestion, $language, $category, $effectiveTopicHint);
$chunks = $retrieval['chunks'];

if (empty($chunks)) {
    if ($language === 'bcl') {
        $headingDocument = $this->resolveBikolDocumentByStrongHeading($question);
        if ($headingDocument !== null) {
            $parts = $this->extractBikolStrongHeadingSection(
                $headingDocument['content'],
                $question
            );
            if ($parts !== []) {
                $result = $this->formatBikolStrongHeadingHit(
                    $question,
                    $parts,
                    [$headingDocument['source_file']]
                );
                $result['language'] = $language;
                $result['is_conversation'] = false;

                return $result;
            }
        }
    }

    return [
        'title' => null,
        'answer' => $this->noContextMessage($language),
        'points' => [],
        'sources' => [],
        'is_conversation' => false,
    ];
}

if ($language === 'bcl') {
    $result = $this->buildBikolAnswerFromContext(
        $chunks,
        $question,
        (bool) ($retrieval['usedKeywordMatch'] ?? false)
    );

    $result['language'] = $language;
    $result['is_conversation'] = false;

    return $result;
}

if ($language === 'tl') {
    $chunks = $this->filterTagalogSupportedChunks($chunks, $pipelineQuestion);
    if ($chunks === []) {
        return [
            'title' => null,
            'answer' => $this->noContextMessage($language),
            'points' => [],
            'sources' => [],
            'language' => $language,
            'is_conversation' => false,
        ];
    }

    // TL-C: expose only intent-relevant section(s) to Mistral.
    $chunks = $this->scopeTagalogChunksForMistral($chunks, $pipelineQuestion);
    if ($chunks === []) {
        return [
            'title' => null,
            'answer' => $this->noContextMessage($language),
            'points' => [],
            'sources' => [],
            'language' => $language,
            'is_conversation' => false,
        ];
    }
}

// EN Phase 1–2: identity gate, then intent section scope before Mistral.
if ($language === 'en') {
    $englishGateQuestion = $pipelineQuestion;
    $chunks = $this->filterEnglishSupportedChunks($chunks, $englishGateQuestion);
    if ($chunks === []) {
        return [
            'title' => null,
            'answer' => $this->noContextMessage($language),
            'points' => [],
            'sources' => [],
            'language' => $language,
            'is_conversation' => false,
        ];
    }

    // EN Phase 2: expose only the requested section bucket to Mistral.
    $chunks = $this->scopeEnglishChunksForMistral($chunks, $englishGateQuestion);
    if ($chunks === []) {
        return [
            'title' => null,
            'answer' => $this->noContextMessage($language),
            'points' => [],
            'sources' => [],
            'language' => $language,
            'is_conversation' => false,
        ];
    }
}

// English and Tagalog can continue through Mistral
$context = collect($chunks)->pluck('content')->implode("\n\n");
$messages = $this->buildMessages($context, $userQuestion, $language);

$ollamaOptions = [
    'model'      => $this->chatModel,
    'messages'   => $messages,
    'stream'     => false,
    'format'     => 'json',
    'keep_alive' => '30m',
    'options'    => [
        'temperature' => $language === 'bcl' ? 0.0 : 0.2,
        'num_predict' => 700,
    ],
];

$response = Http::timeout(180)->post("{$this->ollamaBaseUrl}/api/chat", $ollamaOptions);
$response->throw();
$rawContent = $response->json('message.content');
$structured = $this->formatStructuredAnswer($rawContent, $question, $language);

// Deterministic title from detected intent + resolved topic — never trust
// the model to invent one (same principle as Bikol's extractive title).
$responseTitle = null;
if ($language === 'tl') {
    $responseTitle = $this->buildTagalogResponseTitle(
        $pipelineQuestion,
        $this->detectTagalogSupportIntent($pipelineQuestion)
    );
} elseif ($language === 'en') {
    $responseTitle = $this->buildEnglishResponseTitle(
        $pipelineQuestion,
        $this->detectEnglishSupportIntent($pipelineQuestion)
    );
}

/*
|--------------------------------------------------------------------------
| Bikol-Iriga language drift protection
|--------------------------------------------------------------------------
|
| Retrieval may already be correct but Mistral can still rewrite the
| retrieved Bikol context in English. If that happens, regenerate ONCE
| using the SAME retrieved context.
|
*/
if (
    $language === 'bcl'
    && $this->isEnglishDominant(
        $structured['text'] . ' ' . implode(' ', $structured['points'])
    )
) {
    Log::warning('Bikol response drifted to English. Regenerating from same context.', [
        'question' => $question,
        'answer' => $structured['text'],
        'sources' => collect($chunks)
            ->pluck('source_file')
            ->unique()
            ->values()
            ->all(),
    ]);

    $structured = $this->regenerateBikolFromContext(
        $context,
        $question
    );
}

return [
    'title'   => $responseTitle,
    'answer'  => $structured['text'],
    'points'  => $structured['points'],
    'sources' => collect($chunks)->pluck('source_file')->unique()->values()->all(),
    'language' => $language,
    'is_conversation' => false,
];
    }

    protected function trimToLastSentence(string $text): string
    {
        $text = trim($text);
        $lastEnd = max(
            strrpos($text, '.') ?: -1,
            strrpos($text, '!') ?: -1,
            strrpos($text, '?') ?: -1
        );
        if ($lastEnd === -1) {
            return $text;
        }
        return substr($text, 0, $lastEnd + 1);
    }

    /**
     * Strips a wrapping ```json ... ``` / ``` ... ``` markdown code fence
     * some models (e.g. gemma4:31b-cloud) add even when format=json is
     * requested. No-op for already-bare JSON (e.g. Mistral's output).
     */
    protected function stripJsonCodeFence(string $text): string
    {
        $trimmed = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/us', $trimmed, $m) === 1) {
            return trim($m[1]);
        }

        return $trimmed;
    }

    /**
     * Parses the model's JSON response ({"answer": "...", "points": [...]})
     * into clean, consistent display text - deterministic formatting done
     * in PHP instead of trusting the model's own free-text layout.
     */
    protected function formatStructuredAnswer(string $rawContent, string $question, string $language): array
    {
        $rawContent = $this->stripJsonCodeFence($rawContent);
        $decoded = json_decode($rawContent, true);

        if (is_array($decoded) && isset($decoded['answer']) && !$this->isPlaceholderValue($decoded['answer'])) {
    return $this->extractAnswerAndPoints($decoded);
}

        if (is_array($decoded)) {
    foreach (['definition', 'Answer', 'summary', 'response'] as $altKey) {
        if (isset($decoded[$altKey]) && !$this->isPlaceholderValue($decoded[$altKey])) {
            $decoded['answer'] = $decoded[$altKey];
            return $this->extractAnswerAndPoints($decoded);
        }
    }

    $longestValue = '';
    foreach ($decoded as $value) {
        if (is_string($value) && !$this->isPlaceholderValue($value) && mb_strlen(trim($value)) > mb_strlen($longestValue)) {
            $longestValue = trim($value);
        }
    }
    if ($longestValue !== '') {
        $decoded['answer'] = $longestValue;
        return $this->extractAnswerAndPoints($decoded);
    }
}

        // JSON parsing failed entirely (malformed/truncated, or plain free
        // text). Try to salvage just the answer text with a regex first.
        if (preg_match('/"(?:answer|definition|Answer|summary)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/u', $rawContent, $m)) {
            $salvaged = stripcslashes($m[1]);
            return [
                'text' => $this->trimToLastSentence($salvaged),
                'points' => [],
            ];
        }

        // Model returned plain free text instead of JSON at all. Rather than
        // discarding it, clean it up with the legacy text-cleanup pass and
        // use it directly - this recovers a usable answer instead of
        // showing "no information" when the model actually DID answer.
        $trimmedRaw = trim($rawContent);
        if ($trimmedRaw !== '' && !str_starts_with($trimmedRaw, '{')) {
            $cleaned = $this->stripQuestionEcho($trimmedRaw, $question);
            $cleaned = $this->stripHeaderLines($cleaned);
            $cleaned = $this->trimToLastSentence($cleaned);

            if ($cleaned !== '') {
                return [
                    'text' => $cleaned,
                    'points' => [],
                ];
            }
        }

        Log::warning('JSON parsing failed completely', [
            'raw_content' => $rawContent,
        ]);

        return [
            'text' => $this->noContextMessage($language),
            'points' => [],
        ];
    }

    /**
 * Checks if a value is a garbage/placeholder string rather than real
 * content - Mistral sometimes echoes back a field name or generic word
 * (e.g. "answer", "response") instead of actually answering.
 */
protected function isPlaceholderValue($value): bool
{
    if (!is_string($value)) {
        return true;
    }

    $trimmed = trim(mb_strtolower($value));
    $placeholders = ['answer', 'response', 'summary', 'definition', 'text', 'content', 'the answer'];

    if (in_array($trimmed, $placeholders, true) || mb_strlen($trimmed) < 10) {
        return true;
    }

    // If the "answer" is itself short and ends in a question mark, it's
    // almost certainly a restated question, not real content.
    if (str_ends_with($trimmed, '?') && mb_strlen($trimmed) < 80) {
        return true;
    }

    return false;
}

    protected function extractAnswerAndPoints(array $decoded): array
    {
        $answerText = trim((string) $decoded['answer']);
        $rawPoints = is_array($decoded['points'] ?? null) ? $decoded['points'] : [];

        $cleanPoints = [];
        foreach ($rawPoints as $point) {
            $point = trim((string) $point);
            if ($point === '' || $this->isDuplicateContent($point, $answerText)) {
                continue;
            }
            $cleanPoints[] = $point;
        }

        return [
            'text' => $answerText,
            'points' => $cleanPoints,
        ];
    }

    protected function stripQuestionEcho(string $answer, string $question): string
    {
        $normalize = fn($s) => trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($s)));

        $answerWordsRaw = preg_split('/\s+/u', trim($answer));
        $answerWordsNorm = array_map($normalize, $answerWordsRaw);
        $questionWordsNorm = preg_split('/\s+/u', $normalize($question));

        $matchCount = 0;
        foreach ($questionWordsNorm as $i => $qWord) {
            if (!isset($answerWordsNorm[$i]) || $answerWordsNorm[$i] !== $qWord) {
                break;
            }
            $matchCount++;
        }

        if ($matchCount < count($questionWordsNorm) * 0.6) {
            return $answer;
        }

        $rest = implode(' ', array_slice($answerWordsRaw, $matchCount));
        $rest = preg_replace('/^[,\s]*(dahil|kasi|sapagkat|huli ta|because)?[,\s]*/iu', '', $rest);
        $rest = ltrim($rest);

        if ($rest !== '') {
            $rest = mb_strtoupper(mb_substr($rest, 0, 1)) . mb_substr($rest, 1);
            return $rest;
        }

        return $answer;
    }

    protected function stripHeaderLines(string $text): string
    {
        $lines = explode("\n", $text);
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $kept[] = '';
                continue;
            }

            $isShort = mb_strlen($trimmed) <= 45;
            $endsAsQuestion = str_ends_with($trimmed, '?');
            $isAllCaps = $trimmed === mb_strtoupper($trimmed) && preg_match('/[A-Z]/', $trimmed);
            $endsWithPunctuation = preg_match('/[.!?]$/', $trimmed);
            $isPureHeader = $isShort && ($endsAsQuestion || $isAllCaps || !$endsWithPunctuation);

            if ($isPureHeader) {
                continue;
            }

            $content = preg_replace('/^(\d+[.)]|[\-*])\s+/', '', $trimmed);
            $content = preg_replace('/^\x{2022}\s+/u', '', $content);

            $kept[] = $content;
        }

        $result = implode("\n", $kept);
        $result = preg_replace("/\n{3,}/", "\n\n", $result);

        return trim($result);
    }

    protected function noContextMessage(string $language): string
    {
        return match ($language) {
            'bcl' => 'Uda ako sapat na impormasyon manungod sa unga mo. Uliton ulit.',
            'tl'  => 'Wala akong sapat na impormasyon tungkol dito. Pakisubukang muli.',
            default => "I don't have enough information on that yet. Could you try rephrasing?",
        };
    }

    protected function buildMessages(string $context, string $question, string $language): array
    {
        return match ($language) {
            'bcl' => $this->buildBikolMessages($context, $question),
            'tl'  => $this->buildTagalogMessages($context, $question),
            default => $this->buildEnglishMessages($context, $question),
        };
    }

    protected function buildBikolMessages(string $context, string $question): array
    {
        $messages = [['role' => 'system', 'content' => BikolPrompts::systemPrompt()]];

        foreach (BikolPrompts::fewShotExamples() as $ex) {
            $messages[] = ['role' => 'user', 'content' => "CONTEXT:\n{$ex['context']}\n\nHAPOT: {$ex['question']}"];
            $messages[] = ['role' => 'assistant', 'content' => $ex['answer_json']];
        }

        $messages[] = [
            'role' => 'system',
            'content' => 'PAALALA: Ana mga halimbawa amo sana ISTILO ka pagsurat. '
                . 'DIRI mo pwedeng isulat o gamiton ading paalala sa kanimong simbag. '
                . 'Simbagon mo tulos ana unga gamit sana a bagong CONTEXT.',
        ];

        $messages[] = ['role' => 'user', 'content' => "CONTEXT:\n{$context}\n\nHAPOT: {$question}"];

        return $messages;
    }

    protected function buildTagalogMessages(string $context, string $question): array
    {
        return [
            ['role' => 'system', 'content' => TagalogPrompts::systemPrompt()],
            [
                'role' => 'user',
                'content' => "CONTEXT:\n{$context}\n\nTANONG: {$question}\n\n"
                    . 'Sumagot nang natural at magiliw sa Tagalog, gamit LAMANG ang CONTEXT. '
                    . 'Huwag banggitin ang context mismo. JSON lang.',
            ],
        ];
    }

    protected function buildEnglishMessages(string $context, string $question): array
    {
        return [
            ['role' => 'system', 'content' => EnglishPrompts::systemPrompt()],
            [
                'role' => 'user',
                'content' => "CONTEXT:\n{$context}\n\nQUESTION: {$question}\n\n"
                    . 'Write a natural, resident-friendly answer using ONLY this CONTEXT. '
                    . 'Do not mention the context itself. JSON only.',
            ],
        ];
    }

    /**
     * Deterministic EN response title from detected intent + resolved topic
     * (never trusts the model to invent one). Mirrors buildBikolResponseTitle().
     */
    protected function buildEnglishResponseTitle(string $question, string $intent): ?string
    {
        $topicTokens = $this->extractEnglishTopicTokens($question);
        if ($topicTokens === []) {
            return null;
        }

        $topic = $this->titleCaseBikolTitleWords(implode(' ', $topicTokens));
        if ($topic === '') {
            return null;
        }

        return match ($intent) {
            'definition' => "What is {$topic}?",
            'causes' => "Causes of {$topic}",
            'symptoms' => "Symptoms of {$topic}",
            'prevention' => "Prevention of {$topic}",
            'treatment' => "Treatment of {$topic}",
            'benefits' => "Benefits of {$topic}",
            default => "{$topic} Information",
        };
    }

    /**
     * Deterministic TL response title from detected intent + resolved topic
     * (never trusts the model to invent one). Mirrors buildBikolResponseTitle().
     */
    protected function buildTagalogResponseTitle(string $question, string $intent): ?string
    {
        $topicTokens = $this->extractTagalogTopicTokens($question);
        if ($topicTokens === []) {
            return null;
        }

        $topic = $this->titleCaseBikolTitleWords(implode(' ', $topicTokens));
        if ($topic === '') {
            return null;
        }

        return match ($intent) {
            'definition' => "Ano ang {$topic}?",
            'causes' => "Mga Sanhi ng {$topic}",
            'symptoms' => "Mga Sintomas ng {$topic}",
            'prevention' => "Pag-iwas sa {$topic}",
            'treatment' => "Paggamot sa {$topic}",
            'benefits' => "Mga Benepisyo ng {$topic}",
            'action' => "Dapat Gawin Tungkol sa {$topic}",
            default => "Impormasyon Tungkol sa {$topic}",
        };
    }

    /**
     * Checks if two pieces of text share most of their meaningful words,
     * even if phrased differently - catches paraphrased duplicates that
     * character-based similarity (similar_text) misses.
     */
    protected function isDuplicateContent(string $a, string $b): bool
    {
        $extractWords = function ($text) {
            preg_match_all('/[\p{L}]+/u', mb_strtolower($text), $matches);
            return array_filter($matches[0], fn($w) => mb_strlen($w) >= 4);
        };

        $wordsA = $extractWords($a);
        $wordsB = $extractWords($b);

        if (empty($wordsA) || empty($wordsB)) {
            return false;
        }

        $shared = array_intersect($wordsA, $wordsB);
        $smallerSetSize = min(count(array_unique($wordsA)), count(array_unique($wordsB)));

        if ($smallerSetSize === 0) {
            return false;
        }

        $overlapRatio = count(array_unique($shared)) / $smallerSetSize;

        return $overlapRatio > 0.55;
    }

   /**
 * Detects the likely intended language from the question's own wording,
 * as a safety net in case the selected language button doesn't match
 * what the resident actually typed (e.g. they typed in Bikol but forgot
 * to click the Bikol button).
 */
protected function detectLanguageFromQuestion(string $question, string $selectedLanguage): string
{
    $lower = mb_strtolower($question);

    $bikolMarkers = ['uno', 'ngata', 'pauno', 'sarin', 'kadi', 'agko', 'kaipuhan', 'dawa', 'amo'];
    // Exclusive Filipino question words. Shared particles such as "mga" / "naman"
    // appear in genuine Bicol queries and must not steal a selected BCL session.
    $tagalogExclusiveMarkers = ['ano', 'bakit', 'paano', 'saan', 'kailan'];
    $tagalogSharedMarkers = ['mga', 'naman'];
    $englishMarkers = ['what', 'why', 'how', 'where', 'when', 'who'];

    foreach ($bikolMarkers as $w) {
        if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $lower)) {
            return 'bcl';
        }
    }

    if ($selectedLanguage === 'bcl') {
        return 'bcl';
    }

    $tagalogMarkers = array_merge($tagalogExclusiveMarkers, $tagalogSharedMarkers);
    foreach ($tagalogMarkers as $w) {
        if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $lower)) {
            return 'tl';
        }
    }
    foreach ($englishMarkers as $w) {
        if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $lower)) {
            return 'en';
        }
    }

    return $selectedLanguage;
}

protected function isEnglishDominant(string $text): bool
{
    $lower = ' ' . mb_strtolower($text) . ' ';

    $englishMarkers = [
        ' the ',
        ' is ',
        ' are ',
        ' was ',
        ' were ',
        ' and ',
        ' or ',
        ' of ',
        ' to ',
        ' from ',
        ' with ',
        ' where ',
        ' which ',
        ' that ',
        ' this ',
        ' include ',
        ' includes ',
        ' caused ',
        ' causes ',
        ' condition ',
        ' common ',
        ' reasons ',
        ' risk ',
        ' factors ',
        ' excessive ',
        ' regular ',
        ' physical ',
        ' activity ',
        ' family history ',
    ];

    $hits = 0;

    foreach ($englishMarkers as $marker) {
        if (str_contains($lower, $marker)) {
            $hits++;
        }
    }

    return $hits >= 4;
}

protected function regenerateBikolFromContext(
    string $context,
    string $question
): array {
    $messages = [
        [
            'role' => 'system',
            'content' => BikolPrompts::systemPrompt()
                . "\n\n"
                . "KRITIKAL NA PAGTUTUWID:\n"
                . "A naunang generation nagsurat sa English. SALA ADI.\n"
                . "Simbagon liwat a unga gikan SANA sa CONTEXT na ibibigay.\n"
                . "BIKOL-IRIGA SANA a final answer maski a medical terms agko sa English.\n"
                . "Diri gamiton a naunang English answer.\n"
                . "Diri i-translate a naunang answer.\n"
                . "Basahon liwat a CONTEXT asin bumuo sa BAGONG simbag gamit SANA a impormasyon kadi.\n"
                . "Diri magdugang sa impormasyon na uda sa CONTEXT.\n"
                . "Return JSON sana gamit a fields na answer asin points.",
        ],
        [
            'role' => 'user',
            'content' => "CONTEXT:\n{$context}\n\nHAPOT:\n{$question}",
        ],
    ];

    $response = Http::timeout(180)->post(
        "{$this->ollamaBaseUrl}/api/chat",
        [
            'model' => $this->chatModel,
            'messages' => $messages,
            'stream' => false,
            'format' => 'json',
            'keep_alive' => '30m',
            'options' => [
                'temperature' => 0.0,
                'num_predict' => 700,
            ],
        ]
    );

    $response->throw();

    $rawContent = $response->json('message.content');

    return $this->formatStructuredAnswer(
        $rawContent,
        $question,
        'bcl'
    );
}

protected function buildBikolAnswerFromContext(
    array $chunks,
    string $question,
    bool $usedKeywordMatch = false
): array {
    $intent = $this->detectBikolIntent($question);

    $explicitTopicListIntents = [
        'symptoms',
        'causes',
        'management',
        'diagnosis',
        'effects',
        'benefits',
    ];

    $documents = collect($chunks)
        ->map(function ($chunk) {
            $content = $this->resolveCurrentHealthDocumentContent($chunk);
            if ($content === '') {
                $content = trim((string) $chunk->content);
            }

            return [
                'content' => $content,
                'source_file' => $chunk->source_file,
            ];
        })
        ->filter(fn ($doc) => $doc['content'] !== '')
        ->unique('source_file')
        ->values();

    $noContext = [
        'answer' => $this->noContextMessage('bcl'),
        'points' => [],
        'sources' => [],
    ];

    $currentDocument = $documents->first();
    if (is_array($currentDocument)) {
        $fullCurrent = $this->resolveBikolSourceFileContent((string) ($currentDocument['source_file'] ?? ''));
        if ($fullCurrent !== '') {
            $currentDocument['content'] = $fullCurrent;
        }
    }

    $duplicateHeadingDocument = $this->selectBikolRetrievedDuplicateHeadingDocument(
        $question,
        $documents->all()
    );
    if (is_array($duplicateHeadingDocument)) {
        $currentDocument = $duplicateHeadingDocument;
    }

    $selectedParts = [];
    $contributingSources = [];
    $usedHeadingKeywordFallback = false;

    if (is_array($currentDocument)) {
        $parts = $this->extractBikolStrongHeadingSection(
            (string) $currentDocument['content'],
            $question
        );
        if ($parts !== []) {
            return $this->formatBikolStrongHeadingHit(
                $question,
                $parts,
                [$currentDocument['source_file']]
            );
        }
    }

    // Unique corpus heading only. Duplicate generic FAQs stay scoped to the
    // current file above and are never stolen from another topic.
    $headingDocument = $this->resolveBikolDocumentByStrongHeading($question);
    if ($headingDocument !== null) {
        $parts = $this->extractBikolStrongHeadingSection(
            $headingDocument['content'],
            $question
        );
        if ($parts !== []) {
            return $this->formatBikolStrongHeadingHit(
                $question,
                $parts,
                [$headingDocument['source_file']]
            );
        }
    }

    if (!is_array($currentDocument)) {
        return $noContext;
    }

    $currentContent = (string) $currentDocument['content'];
    $currentSource = (string) $currentDocument['source_file'];
    $topicTitleTokens = $this->extractBikolTopicTitleTokens($currentContent, $currentSource);
    $ageCue = $intent === 'schedule'
        ? $this->extractAgeCueFromQuestion($question, $topicTitleTokens)
        : null;
    if ($intent === 'schedule' && $ageCue !== null) {
        $scopedDocument = $this->selectBikolAgeScopedDocument($documents->all(), $ageCue, $question);
        if ($scopedDocument === null) {
            return $noContext;
        }

        $currentDocument = $scopedDocument;
        $currentContent = (string) $scopedDocument['content'];
        $currentSource = (string) $scopedDocument['source_file'];
    }
    $remainingSectionTokens = $this->extractBikolRemainingHeadingSectionTokens(
        $question,
        $currentSource,
        $currentContent
    );
    $remainingTopicTokens = array_values(array_filter(
        $remainingSectionTokens,
        fn ($token) => !$this->isBikolSemanticIntentCueToken((string) $token)
    ));
    $confidentCurrentTopic = $this->isBikolConfidentCurrentTopic(
        $question,
        $currentSource,
        $currentContent
    );

    $applyHeadingKeywordFallback = function () use (
        $currentContent,
        $currentSource,
        $question,
        &$selectedParts,
        &$contributingSources,
        &$usedHeadingKeywordFallback
    ): bool {
        $parts = $this->extractBikolSectionByHeadingKeywords(
            $currentContent,
            $question,
            $currentSource
        );
        if ($parts === []) {
            return false;
        }

        $selectedParts = array_values($parts);
        $contributingSources = [$currentSource];
        $usedHeadingKeywordFallback = true;

        return true;
    };

    if ($intent === 'schedule' && $ageCue !== null) {
        $parts = $this->extractBikolAgeScopedSection($currentContent, $ageCue);
        if ($parts === []) {
            return $noContext;
        }

        $selectedParts = array_values($parts);
        $contributingSources = [$currentSource];
    }

    if ($selectedParts === []) {
        $applyHeadingKeywordFallback();
    }

    if (
        $selectedParts === []
        && $remainingTopicTokens !== []
        && (
            $intent === 'definition'
            || $intent === 'general'
            || (
                $intent !== 'warning'
                && $intent !== 'schedule'
                && !$this->bikolDocumentHeadingsOverlapTokens($currentContent, $remainingTopicTokens)
            )
        )
    ) {
        return $noContext;
    }

    if ($selectedParts === [] && $intent === 'definition') {
        $parts = $this->extractBikolSections(
            $currentContent,
            'definition',
            null,
            $question
        );
        if ($parts !== []) {
            $selectedParts = array_values($parts);
            $contributingSources = [$currentSource];
        }
    }

    if ($selectedParts === [] && $intent === 'general') {
        return $noContext;
    }

    // Bare "ngata <topic>?" stays weak; "ngata nagkaka-agko <topic>?" is etiology.
    $skipWeakCausesExtract = $intent === 'causes'
        && !$this->isBikolCausesHeading(mb_strtolower(trim($question)))
        && !$this->isBikolDiseaseOnsetCausesQuestion($question);

    $semanticListIntents = [
        'symptoms',
        'causes',
        'management',
        'effects',
        'benefits',
    ];

    if (
        $selectedParts === []
        && in_array($intent, array_merge(['schedule', 'warning'], $explicitTopicListIntents), true)
        && !$skipWeakCausesExtract
        && (
            $intent === 'management'
            || !$this->isBikolAmbiguousFaqQuestion($question, $intent)
        )
        && (
            !in_array($intent, $semanticListIntents, true)
            || $confidentCurrentTopic
        )
    ) {
        $parts = $this->extractBikolSections(
            $currentContent,
            $intent,
            $ageCue,
            $question
        );
        if ($parts !== []) {
            $selectedParts = array_values($parts);
            $contributingSources = [$currentSource];
        }
    }

    $selectedParts = array_values(array_unique(
        array_filter(array_map('trim', $selectedParts))
    ));
    $contributingSources = array_values(array_unique($contributingSources));

    if ($selectedParts === []) {
        if (
            $intent === 'definition'
            || (
                $intent === 'diagnosis'
                && $this->isBikolPlainUnoTopicQuestion(mb_strtolower(trim($question)))
            )
        ) {
            $selectedParts = $this->selectBikolDefinitionFallback($currentContent);
            $contributingSources = $selectedParts !== [] ? [$currentSource] : [];
        }
    }

    if ($selectedParts === []) {
        return $noContext;
    }

    $sources = $contributingSources !== [] ? $contributingSources : [];

    $listIntents = [
        'symptoms',
        'causes',
        'management',
        'effects',
        'benefits',
        'diagnosis',
        'schedule',
        'warning',
    ];

    if (in_array($intent, $listIntents, true) || $usedHeadingKeywordFallback) {
        $bodyParts = [];

        foreach ($selectedParts as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            $lower = mb_strtolower($item);

            if ($usedHeadingKeywordFallback) {
                if (
                    $this->isBikolDocumentBannerHeading($lower)
                    || $this->isBikolDefinitionHeading($lower)
                ) {
                    continue;
                }
            } elseif ($this->isSkippableBikolHeadingLine($item, $lower)) {
                continue;
            }

            if (
                $intent === 'benefits'
                && (
                    $this->isBikolBenefitsHeading($lower)
                    || $this->isBikolSloganOrLabelLine($item, $lower)
                )
            ) {
                continue;
            }

            if (
                $intent === 'symptoms'
                && $this->isBikolHeadingLikeLine($item, $lower)
                && $this->isBikolSymptomsHeading($lower)
            ) {
                continue;
            }

            $bodyParts[] = $item;
        }

        if ($bodyParts === []) {
            return [
                'answer' => $this->noContextMessage('bcl'),
                'points' => [],
                'sources' => [],
            ];
        }

        $limit = $usedHeadingKeywordFallback
            ? 12
            : match ($intent) {
            'causes' => 10,
            'management' => 12,
            'effects' => 12,
            'diagnosis' => 10,
            'schedule' => 12,
            'warning' => 12,
            'symptoms' => 12,
            'benefits' => 12,
            default => 8,
        };

        $formatted = $this->normalizeBikolListParts($bodyParts, $limit);

        return [
            'title' => $this->buildBikolResponseTitle($question),
            'answer' => $formatted['answer'],
            'points' => $formatted['points'],
            'sources' => $sources,
        ];
    }

    $definitionParts = array_values(array_filter(
        $selectedParts,
        function ($part) {
            $lower = mb_strtolower(trim($part));
            return !$this->isSkippableBikolHeadingLine($part, $lower);
        }
    ));

    if ($definitionParts === []) {
        return [
            'answer' => $this->noContextMessage('bcl'),
            'points' => [],
            'sources' => [],
        ];
    }

    return [
        'title' => $this->buildBikolResponseTitle($question),
        'answer' => implode("\n\n", array_slice($definitionParts, 0, 3)),
        'points' => [],
        'sources' => $sources,
    ];
}

/**
 * Split extracted Bikol lines into an optional intro paragraph and list points.
 * List-marker whitespace is optional ("-BCG." and "- BCG." are both items).
 *
 * @return array{answer: string, points: array<int, string>}
 */
protected function normalizeBikolListParts(array $parts, int $limit = 12): array
{
    $intro = [];
    $points = [];
    $sawListItem = false;

    foreach ($parts as $item) {
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }

        $stripped = $this->stripBikolListMarker($item);
        if ($stripped !== null) {
            $sawListItem = true;
            if ($stripped !== '') {
                $points[] = $stripped;
            }
            continue;
        }

        if ($sawListItem) {
            break;
        }

        $intro[] = $item;
    }

    $points = array_values(array_unique(array_filter(
        array_map('trim', $points),
        fn ($line) => $line !== ''
    )));

    if ($points === []) {
        $intro = array_values(array_unique(array_filter(
            array_map('trim', $intro),
            fn ($line) => $line !== ''
        )));

        // A single unmarked explanatory sentence is a paragraph, not a one-item list.
        // Several unmarked peer lines (e.g. Family Planning benefits) stay points.
        if (count($intro) === 1) {
            return [
                'answer' => $intro[0],
                'points' => [],
            ];
        }

        return [
            'answer' => '',
            'points' => array_slice($intro, 0, $limit),
        ];
    }

    return [
        'answer' => trim(implode("\n\n", $intro)),
        'points' => array_slice($points, 0, $limit),
    ];
}

/**
 * If $item starts with a list marker, return the remainder; otherwise null.
 */
protected function stripBikolListMarker(string $item): ?string
{
    if (preg_match('/^\s*(?:[-–—•*]|[0-9]+[.)])\s*(.+)$/us', $item, $m) !== 1) {
        return null;
    }

    return trim($m[1]);
}

/**
 * Build a dynamic Bikol definition title from the user question.
 * Examples: "Uno a dengue?" → "Uno a Dengue?"
 */
protected function buildBikolDefinitionTitle(string $question): ?string
{
    return $this->buildBikolResponseTitle($question);
}

/**
 * Bold-title text for any Bikol assistant response, derived from the question.
 * Rendered via the existing frontend `title` + <strong> path.
 */
protected function buildBikolResponseTitle(string $question): ?string
{
    $q = trim($question);
    if ($q === '') {
        return null;
    }

    if (preg_match('/\buno(?:\s+man)?\s+(ana|a)\s+(.+?)(?:\?|$)/iu', $q, $m)) {
        $connector = mb_strtolower($m[1]);
        $topic = trim($m[2], " \t\n\r\0\x0B?.!");

        if ($topic !== '' && mb_strlen($topic) >= 2) {
            $topicTitle = $this->titleCaseBikolTitleWords($topic);
            if ($topicTitle !== '') {
                return 'Uno ' . $connector . ' ' . $topicTitle . '?';
            }
        }
    }

    $core = trim($q, " \t\n\r\0\x0B?");
    if ($core === '') {
        return null;
    }

    $formatted = $this->titleCaseBikolTitleWords($core);

    return $formatted === '' ? null : $formatted . '?';
}

/**
 * Title-case whitespace-separated words without altering digits or hyphens.
 */
protected function titleCaseBikolTitleWords(string $text): string
{
    $words = preg_split('/\s+/u', mb_strtolower(trim($text))) ?: [];
    $formatted = array_map(function ($word) {
        if ($word === '') {
            return $word;
        }

        return mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
    }, $words);

    return trim(implode(' ', $formatted));
}

/**
 * True for Bikol "Uno a/ana <topic>?" definition headings (any topic).
 */
protected function isBikolDefinitionHeading(string $lower): bool
{
    if (!preg_match('/^uno(?:\s+man)?\s+(?:ana|a)\s+.+/u', $lower)) {
        return false;
    }

    // "Uno a mga dahilan?" etc. are cause/list headings, not definitions.
    if (preg_match('/^uno(?:\s+man)?\s+(?:ana|a)\s+mga\b/u', $lower)) {
        return false;
    }

    // "Uno a dapat/gibuhon …" are action/process lines, not "what is X".
    if (preg_match('/^uno(?:\s+man)?\s+(?:ana|a)\s+(dapat|gibuhon|gigibuhon)\b/u', $lower)) {
        return false;
    }

    return true;
}

/**
 * Skip document/section headings when building a definition answer.
 */
protected function isSkippableBikolHeadingLine(string $line, string $lower): bool
{
    if ($line === '') {
        return true;
    }

    if ($this->isBikolDocumentBannerHeading($lower)) {
        return true;
    }

    if ($this->isBikolDefinitionHeading($lower)) {
        return true;
    }

    // Short question-style headings.
    if (mb_strlen($line) <= 80 && str_ends_with($lower, '?')) {
        return true;
    }

    // ALL-CAPS document/section titles (letters only compared).
    if ($this->isBikolAllCapsHeading($line)) {
        return true;
    }

    // Explicit section labels (not every line that starts with "mga").
    if (
        mb_strlen($line) <= 70
        && !preg_match('/[.!]$/u', $line)
        && preg_match(
            '/^(diyagnosis|diagnosis|screening|benipisyo|benepisyo|pambulung|pagbulong|pag-likay|pag likay|pag-iwas|pag iwas)\b/u',
            $lower
        )
    ) {
        return true;
    }

    if ($this->isBikolBenefitsHeading($lower) && mb_strlen($line) <= 90) {
        return true;
    }

    return false;
}

/**
 * ALL-CAPS short titles such as "PAANO MAKAIWAS SA DENGUE?" or "MGA SINTOMAS".
 */
protected function isBikolAllCapsHeading(string $line): bool
{
    $lettersOnly = preg_replace('/[^\p{L}]/u', '', $line) ?? '';

    if ($lettersOnly === '' || mb_strlen($lettersOnly) < 4) {
        return false;
    }

    if (mb_strtoupper($lettersOnly) !== $lettersOnly) {
        return false;
    }

    // CAPITAL FAQ headings remain structural headings at any length.
    if (str_ends_with(rtrim($line), '?')) {
        return true;
    }

    return mb_strlen($line) <= 90;
}

/**
 * Generic section boundary used while walking document lines.
 * Intentionally stricter than answer filtering so body lines like
 * "Mga pantal sa awak" are not treated as new sections.
 */
protected function isBikolGenericSectionBoundary(string $line, string $lower): bool
{
    if (str_ends_with($lower, '?') && $this->isBikolAllCapsHeading($line)) {
        return true;
    }

    if (mb_strlen($line) <= 80 && str_ends_with($lower, '?')) {
        return true;
    }

    return $this->isBikolAllCapsHeading($line);
}

/**
 * Definition text under a definition heading only. No first-line dump.
 */
protected function selectBikolDefinitionFallback(string $content): array
{
    $lines = preg_split('/\R+/u', $content) ?: [];
    $lines = array_values(array_filter(
        array_map('trim', $lines),
        fn ($line) => $line !== ''
    ));

    $collected = [];
    $afterDefinitionHeading = false;

    foreach ($lines as $line) {
        $lower = mb_strtolower($line);

        if ($this->isBikolDefinitionHeading($lower)) {
            $afterDefinitionHeading = true;
            continue;
        }

        if (!$afterDefinitionHeading) {
            continue;
        }

        if ($this->isSkippableBikolHeadingLine($line, $lower)) {
            break;
        }

        $collected[] = $line;
        if (count($collected) >= 3) {
            break;
        }
    }

    return $collected;
}

/**
 * True for Bikol benefits/purpose section headings only.
 * Body sentences that merely mention "benepisyo" must NOT match
 * (e.g. FAQ answers about pills that say "dagdag na benepisyo").
 */
protected function isBikolBenefitsHeading(string $lower): bool
{
    if (
        !str_contains($lower, 'benepisyo')
        && !str_contains($lower, 'benipisyo')
    ) {
        return false;
    }

    // Heading form: "(Mga) Benepisyo/Benipisyo …"
    if (!preg_match('/^(mga\s+)?ben[ei]pisyo\b/u', $lower)) {
        return false;
    }

    // Reject long prose that happens to start with the word.
    if (mb_strlen($lower) > 100) {
        return false;
    }

    // Reject multi-sentence body text.
    if (substr_count($lower, '.') + substr_count($lower, '!') >= 1
        && mb_strlen($lower) > 60
    ) {
        return false;
    }

    return true;
}

/**
 * End a benefits section when a new FAQ/subsection clearly begins.
 * Does not treat role bullets like "Ina - …" / "Ama – …" as boundaries.
 */
protected function isBikolBenefitsEndBoundary(string $line, string $lower): bool
{
    // Legitimate benefit role/label bullets: "Ina - …", "Bilog Pamilya – …"
    if (preg_match('/^[\p{L}][\p{L}\s\.]{0,40}\s*[-–—:]\s+\S+/u', $line)) {
        return false;
    }

    // Numbered FAQ / numbered subsection.
    if (preg_match('/^\s*\d+[\.\)]\s+\S+/u', $line)) {
        return true;
    }

    // Standalone question starting a new topic.
    if (mb_strlen($line) <= 140 && str_ends_with($lower, '?')) {
        return true;
    }

    // ALL-CAPS document subsection titles.
    if ($this->isBikolAllCapsHeading($line)) {
        return true;
    }

    // Next non-benefit "Mga …" title.
    if (
        preg_match('/^mga\s+/u', $lower)
        && !$this->isBikolBenefitsHeading($lower)
        && mb_strlen($line) <= 90
        && !preg_match('/[.!;]$/u', $line)
    ) {
        return true;
    }

    // Definition / other semantic headings ending the benefits block.
    if ($this->isBikolDefinitionHeading($lower)) {
        return true;
    }

    return false;
}

/**
 * Short slogan/title lines that should not be used as answers.
 */
protected function isBikolSloganOrLabelLine(string $line, string $lower): bool
{
    if ($this->isBikolBenefitsHeading($lower)) {
        return true;
    }

    if ($this->isBikolAllCapsHeading($line)) {
        return true;
    }

    // Short slogan / label without sentence punctuation.
    if (
        mb_strlen($line) <= 55
        && !preg_match('/[.!;:]/u', $line)
        && !preg_match('/^\s*\d+[\.\)]\s+/u', $line)
    ) {
        return true;
    }

    return false;
}

/**
 * Prefer content under a benefits heading; otherwise first substantive
 * non-heading/non-slogan lines from retrieved content.
 */
protected function selectBikolBenefitsFallback(string $content): array
{
    $lines = preg_split('/\R+/u', $content) ?: [];
    $lines = array_values(array_filter(
        array_map('trim', $lines),
        fn ($line) => $line !== ''
    ));

    $collected = [];
    $afterBenefitsHeading = false;

    foreach ($lines as $line) {
        $lower = mb_strtolower($line);

        if ($this->isBikolBenefitsHeading($lower)) {
            $afterBenefitsHeading = true;
            continue;
        }

        if (!$afterBenefitsHeading) {
            continue;
        }

        if ($this->isBikolBenefitsEndBoundary($line, $lower)) {
            break;
        }

        if ($this->isBikolSloganOrLabelLine($line, $lower) && mb_strlen($line) <= 40) {
            continue;
        }

        $collected[] = $line;
        if (count($collected) >= 8) {
            break;
        }
    }

    if (!empty($collected)) {
        return $collected;
    }

    foreach ($lines as $line) {
        $lower = mb_strtolower($line);

        if (
            $this->isSkippableBikolHeadingLine($line, $lower)
            || $this->isBikolSloganOrLabelLine($line, $lower)
        ) {
            continue;
        }

        $collected[] = $line;
        if (count($collected) >= 4) {
            break;
        }
    }

    return $collected;
}

protected function detectBikolIntent(string $question): string
{
    $q = mb_strtolower(trim($question));

    // Urgent action / warning — before symptoms & diagnosis so
    // "sinyalis"/"screening"/"gibuhon" do not steal failed/positive/consult asks.
    if ($this->isBikolWarningQuestion($q)) {
        return 'warning';
    }

    // Symptoms / signs / characteristics
    if (
        str_contains($q, 'sintomas') ||
        str_contains($q, 'palatandaan') ||
        str_contains($q, 'senyales') ||
        str_contains($q, 'sinyalis') ||
        str_contains($q, 'katangian') ||
        str_contains($q, 'pag-uugali') ||
        str_contains($q, 'pag uugali') ||
        str_contains($q, 'sensitibo') ||
        str_contains($q, 'tunog') ||
        str_contains($q, 'sulo') ||
        str_contains($q, 'liwanag')
    ) {
        return 'symptoms';
    }

    // Early detection / diagnosis / screening — before benefits so
    // "ngata … maisihan … agko …" is not stolen by ngata+agko → benefits.
    // Plain "Uno a <topic>?" stays definition even if the topic contains
    // "screening" (e.g. "Uno a hearing screening?").
    if (
        str_contains($q, 'maisihan') ||
        str_contains($q, 'pagkaisi') ||
        str_contains($q, 'early detection') ||
        str_contains($q, 'pagsukol') ||
        str_contains($q, 'monitor') ||
        (
            str_contains($q, 'screening')
            && !$this->isBikolPlainUnoTopicQuestion($q)
        )
    ) {
        return 'diagnosis';
    }

    // Purpose / benefits / importance
    // "ngata agko X?" = why does X exist (not "ngata nagkaka-agko X?" disease etiology)
    if (
        str_contains($q, 'ngaya') ||
        str_contains($q, 'benepisyo') ||
        str_contains($q, 'benipisyo') ||
        (
            str_contains($q, 'ngata')
            && preg_match('/\bagko\b/u', $q)
            && !preg_match('/nagkaka[\s\-]*agko/u', $q)
        )
    ) {
        return 'benefits';
    }

    // Causes / risk factors
    if (
        str_contains($q, 'ngata') ||
        str_contains($q, 'dahilan') ||
        str_contains($q, 'rason') ||
        str_contains($q, 'peligro') ||
        str_contains($q, 'risk') ||
        str_contains($q, 'namamana') ||
        str_contains($q, 'genetic')
    ) {
        return 'causes';
    }

    // Prevention
    if (
        str_contains($q, 'maiwasan') ||
        str_contains($q, 'maiiwasan') ||
        str_contains($q, 'likay') ||
        str_contains($q, 'pag-iwas') ||
        str_contains($q, 'pag iwas') ||
        str_contains($q, 'makalikay')
    ) {
        return 'management';
    }

    // Care / support / treatment / management
    if (
        str_contains($q, 'gibuhon') ||
        str_contains($q, 'gigibuhon') ||
        str_contains($q, 'bulong') ||
        str_contains($q, 'gamot') ||
        str_contains($q, 'kontrol') ||
        str_contains($q, 'aatamanon') ||
        str_contains($q, 'ataman') ||
        str_contains($q, 'makatabang') ||
        str_contains($q, 'suporta') ||
        str_contains($q, 'interbensyon') ||
        str_contains($q, 'intervention')
    ) {
        return 'management';
    }

    // Effects / complications
    if (
        str_contains($q, 'epekto') ||
        str_contains($q, 'mangyayari') ||
        str_contains($q, 'komplikasyon') ||
        str_contains($q, 'mangyari')
    ) {
        return 'effects';
    }

    // Schedule / age / stage / eligibility — before definition so
    // "Uno a bakuna sa 9 na bulan?" is not treated as a definition.
    if ($this->isBikolScheduleQuestion($q)) {
        return 'schedule';
    }

    // "Uno ana X?" = definition (only when no more-specific marker matched)
    if (preg_match('/\buno\b/u', $q)) {
        return 'definition';
    }

    return 'general';
}

/**
 * Strong + context-dependent warning / urgent-action question cues.
 * Grammatical a/ana and kung/kin are ignored as signals by themselves.
 */
protected function isBikolWarningQuestion(string $q): bool
{
    // Strong standalone cues.
    if (
        str_contains($q, 'magpakonsulta')
        || str_contains($q, 'dadarahon')
        || $this->bikolTextHasFailedTestCondition($q)
        || str_contains($q, 'kombolsyon')
        || str_contains($q, 'magkombolsyon')
        || str_contains($q, 'health center')
        || str_contains($q, 'ospital')
        || preg_match('/nahihirapan\s+(?:mag[\s\-]*inga|pag[\s\-]*inga)/u', $q)
    ) {
        return true;
    }

    // Positive result / failed test follow-up.
    if (
        (
            str_contains($q, 'positive')
            && (
                str_contains($q, 'resulta')
                || str_contains($q, 'screening')
                || str_contains($q, 'test')
            )
        )
        || (
            (str_contains($q, 'gibuhon') || str_contains($q, 'gigibuhon'))
            && (
                $this->bikolTextHasFailedTestCondition($q)
                || str_contains($q, 'positive')
            )
        )
    ) {
        return true;
    }

    // High fever after vaccine / severe signs needing action.
    if (
        (
            str_contains($q, 'alangkaw')
            && str_contains($q, 'kalintura')
        )
        || (
            str_contains($q, 'malala')
            && (
                str_contains($q, 'sinyalis')
                || str_contains($q, 'senyalis')
                || str_contains($q, 'senyales')
                || str_contains($q, 'sinyales')
            )
        )
        || (
            (str_contains($q, 'agad') || str_contains($q, 'tulos'))
            && (
                str_contains($q, 'magpakonsulta')
                || str_contains($q, 'dadarahon')
                || str_contains($q, 'kaipuhan')
            )
        )
    ) {
        return true;
    }

    return false;
}

/**
 * Schedule / age / stage / eligibility question cues (Bikol-Iriga).
 */
protected function isBikolScheduleQuestion(string $q): bool
{
    $qAge = $this->stripBikolTopicNameDayPhrases($q);

    $hasTimingCore = preg_match(
        '/\b(kuno|edad|bulan|buwan|bukan|taon|aldow|pagka[\s\-]*pangigin|pagka[\s\-]*panganak|pagmamabros|iskedyul|sunod|stage|yugto|sakop|milestone|hanggang|abot)\b/u',
        $qAge
    ) === 1;

    $hasNumericAge = preg_match(
        '/\d+\s*[-–—]\s*\d+\s*(?:na\s+)?(?:bulan|buwan|bukan|taon|aldow|days?|araw)\b/u',
        $qAge
    ) === 1
        || preg_match('/\d+\s+(?:hanggang|abot)\s+\d+/u', $qAge) === 1
        || preg_match('/\b\d+\s*(?:na\s+)?(?:bulan|buwan|bukan|taon|aldow|days?|araw)\b/u', $qAge) === 1;

    if ($hasTimingCore || $hasNumericAge) {
        return true;
    }

    // pagdakulo / pagbabago only with age/stage context.
    if (
        (str_contains($q, 'pagdakulo') || str_contains($q, 'pagbabago') || str_contains($q, 'pag babago'))
        && (
            $hasNumericAge
            || preg_match(
                '/\b(kuno|edad|bulan|buwan|bukan|taon|aldow|stage|yugto|milestone|sakop)\b/u',
                $qAge
            ) === 1
        )
    ) {
        return true;
    }

    return false;
}

/**
 * Strip topic-name day counts such as "first 1000 days" / "unang 1000 aldow"
 * so they are not treated as age-bucket selectors.
 */
protected function stripBikolTopicNameDayPhrases(string $q): string
{
    return trim(preg_replace(
        '/\b(?:first|unang)\s+\d+\s*(?:days?|aldow)\b/u',
        ' ',
        $q
    ) ?? $q);
}

/**
 * Parse a generic age/time cue from the question for subsection matching.
 *
 * @param  list<string>  $topicTitleTokens
 * @return array{
 *     type: string,
 *     min?: int,
 *     max?: int,
 *     value?: int,
 *     unit?: string,
 *     name?: string,
 *     start_value?: int,
 *     start_unit?: string,
 *     end_value?: int,
 *     end_unit?: string,
 *     raw: string
 * }|null
 */
protected function extractAgeCueFromQuestion(string $question, array $topicTitleTokens = []): ?array
{
    $q = mb_strtolower(trim($question));
    $qAge = $this->stripBikolTopicNameDayPhrases($q);
    $unit = 'bulan|buwan|bukan|taon|aldow|days?|araw';

    if (preg_match('/pagka[\s\-]*pangigin|pagka[\s\-]*panganak|kapanganakan|\bbirth\b/u', $q, $m)) {
        return [
            'type' => 'birth',
            'raw' => $m[0],
        ];
    }

    if (preg_match('/\bpagmamabros\b/u', $qAge, $m)) {
        return [
            'type' => 'stage_name',
            'name' => 'pagmamabros',
            'raw' => $m[0],
        ];
    }

    if (preg_match(
        '/(\d+)\s*(?:na\s+)?(' . $unit . ')\s+(?:hanggang|abot)\s+(\d+)\s*(?:na\s+)?(' . $unit . ')\b/u',
        $qAge,
        $m
    )) {
        $startUnit = $this->normalizeBikolAgeUnit($m[2]);
        $endUnit = $this->normalizeBikolAgeUnit($m[4]);
        $cue = $startUnit === $endUnit
            ? [
                'type' => 'range',
                'min' => (int) $m[1],
                'max' => (int) $m[3],
                'unit' => $startUnit,
                'raw' => $m[0],
            ]
            : [
                'type' => 'mixed_range',
                'start_value' => (int) $m[1],
                'start_unit' => $startUnit,
                'end_value' => (int) $m[3],
                'end_unit' => $endUnit,
                'raw' => $m[0],
            ];

        return $this->bikolAgeCueIsTopicTitleNumber($cue, $topicTitleTokens) ? null : $cue;
    }

    if (preg_match(
        '/(\d+)\s+(?:hanggang|abot)\s+(\d+)\s*(?:na\s+)?(' . $unit . ')\b/u',
        $qAge,
        $m
    )) {
        $cue = [
            'type' => 'range',
            'min' => (int) $m[1],
            'max' => (int) $m[2],
            'unit' => $this->normalizeBikolAgeUnit($m[3]),
            'raw' => $m[0],
        ];

        return $this->bikolAgeCueIsTopicTitleNumber($cue, $topicTitleTokens) ? null : $cue;
    }

    if (preg_match(
        '/(\d+)\s*[-–—]\s*(\d+)\s*(?:na\s+)?(' . $unit . ')\b/u',
        $qAge,
        $m
    )) {
        $cue = [
            'type' => 'range',
            'min' => (int) $m[1],
            'max' => (int) $m[2],
            'unit' => $this->normalizeBikolAgeUnit($m[3]),
            'raw' => $m[0],
        ];

        return $this->bikolAgeCueIsTopicTitleNumber($cue, $topicTitleTokens) ? null : $cue;
    }

    if (preg_match(
        '/(\d+)\s*(?:na\s+)?(' . $unit . ')\b/u',
        $qAge,
        $m
    )) {
        $cue = [
            'type' => 'single',
            'value' => (int) $m[1],
            'unit' => $this->normalizeBikolAgeUnit($m[2]),
            'raw' => $m[0],
        ];

        return $this->bikolAgeCueIsTopicTitleNumber($cue, $topicTitleTokens) ? null : $cue;
    }

    if (preg_match('/\bedad\s+(\d+)\s*[-–—]\s*(\d+)\b/u', $qAge, $m)) {
        return [
            'type' => 'range',
            'min' => (int) $m[1],
            'max' => (int) $m[2],
            'unit' => '',
            'raw' => $m[0],
        ];
    }

    if (preg_match('/\bedad\s+(\d+)\b/u', $qAge, $m)) {
        return [
            'type' => 'single',
            'value' => (int) $m[1],
            'unit' => 'edad',
            'raw' => $m[0],
        ];
    }

    return null;
}

/**
 * True when a single day-count cue is already part of the topic/title.
 *
 * @param  array<string, mixed>  $cue
 * @param  list<string>  $topicTitleTokens
 */
protected function bikolAgeCueIsTopicTitleNumber(array $cue, array $topicTitleTokens): bool
{
    if ($topicTitleTokens === [] || ($cue['type'] ?? '') !== 'single') {
        return false;
    }

    if (($cue['unit'] ?? '') !== 'aldow') {
        return false;
    }

    $value = (string) ($cue['value'] ?? '');

    return $value !== '' && in_array($value, $topicTitleTokens, true);
}

protected function normalizeBikolAgeUnit(string $unit): string
{
    $unit = mb_strtolower(trim($unit));

    return match ($unit) {
        'buwan', 'bulan', 'bukan' => 'bulan',
        'day', 'days', 'araw', 'aldow' => 'aldow',
        'taon' => 'taon',
        default => $unit,
    };
}

/**
 * True when a question age unit can select a heading parsed in $headingUnit.
 */
protected function bikolAgeCueUnitCompatible(string $cueUnit, string $headingUnit): bool
{
    $cueUnit = $this->normalizeBikolAgeUnit($cueUnit);
    $headingUnit = $this->normalizeBikolAgeUnit($headingUnit);

    if ($headingUnit === '') {
        return $cueUnit === '' || $cueUnit === 'edad';
    }

    if ($cueUnit === 'edad') {
        return $headingUnit === 'edad';
    }

    if ($cueUnit === '') {
        return $headingUnit === 'edad';
    }

    return $cueUnit === $headingUnit;
}

/**
 * Parse a BCL age/stage heading into exact, discrete, range, or mixed bounds.
 *
 * @return array{
 *     kind: string,
 *     unit?: string,
 *     value?: int,
 *     min?: int,
 *     max?: int,
 *     values?: list<int>,
 *     start_value?: int,
 *     start_unit?: string,
 *     end_value?: int,
 *     end_unit?: string
 * }|null
 */
protected function parseBikolAgeHeading(string $lower): ?array
{
    $lower = mb_strtolower(trim($lower), 'UTF-8');
    $unit = 'bulan|buwan|bukan|taon|aldow|days?|araw';

    if (preg_match('/^edad\s+(\d+)\s*[-–—]\s*(\d+)\s*$/u', $lower, $m)) {
        return [
            'kind' => 'range',
            'unit' => 'edad',
            'min' => (int) $m[1],
            'max' => (int) $m[2],
        ];
    }

    if (preg_match('/^edad\s+(\d+)\s*$/u', $lower, $m)) {
        return [
            'kind' => 'exact',
            'unit' => 'edad',
            'value' => (int) $m[1],
        ];
    }

    // Discrete list: "1,2, PALA 3 NA BULAN" — not a continuous 1-3 range.
    if (preg_match(
        '/(\d+(?:\s*,\s*\d+)+)\s*,?\s*pala\s+(\d+)\s*(?:na\s+)?(' . $unit . ')\b/u',
        $lower,
        $m
    )) {
        $values = array_map('intval', preg_split('/\s*,\s*/u', $m[1]) ?: []);
        $values[] = (int) $m[2];

        return [
            'kind' => 'discrete',
            'unit' => $this->normalizeBikolAgeUnit($m[3]),
            'values' => array_values(array_unique($values)),
        ];
    }

    // "3 BULAN PALA 9 NA BULAN" / "6 NA BULAN ABOT 2 NA TAON" / "9 NA BULAN PALA 1 NA TAON"
    if (preg_match(
        '/(\d+)\s*(?:na\s+)?(' . $unit . ')\s+(?:pala|abot|hanggang)\s+(\d+)\s*(?:na\s+)?(' . $unit . ')\b/u',
        $lower,
        $m
    )) {
        $startUnit = $this->normalizeBikolAgeUnit($m[2]);
        $endUnit = $this->normalizeBikolAgeUnit($m[4]);
        if ($startUnit === $endUnit) {
            return [
                'kind' => 'range',
                'unit' => $startUnit,
                'min' => (int) $m[1],
                'max' => (int) $m[3],
            ];
        }

        return [
            'kind' => 'mixed',
            'start_value' => (int) $m[1],
            'start_unit' => $startUnit,
            'end_value' => (int) $m[3],
            'end_unit' => $endUnit,
        ];
    }

    // "3-6 NA BULAN", "12-18 NA BULAN"
    if (preg_match(
        '/(?<![\d])(\d+)\s*[-–—]\s*(\d+)\s*(?:na\s+)?(' . $unit . ')\b/u',
        $lower,
        $m
    )) {
        return [
            'kind' => 'range',
            'unit' => $this->normalizeBikolAgeUnit($m[3]),
            'min' => (int) $m[1],
            'max' => (int) $m[2],
        ];
    }

    // "0 HANGGANG 6 NA BUKAN"
    if (preg_match(
        '/(\d+)\s+(?:hanggang|abot)\s+(\d+)\s*(?:na\s+)?(' . $unit . ')\b/u',
        $lower,
        $m
    )) {
        return [
            'kind' => 'range',
            'unit' => $this->normalizeBikolAgeUnit($m[3]),
            'min' => (int) $m[1],
            'max' => (int) $m[2],
        ];
    }

    // "PAGKA PANGININ ABOT 3 BULAN" → implied 0-3
    if (preg_match(
        '/(?<!\d)\s*\babot\s+(\d+)\s*(?:na\s+)?(' . $unit . ')\b/u',
        $lower,
        $m
    )) {
        return [
            'kind' => 'range',
            'unit' => $this->normalizeBikolAgeUnit($m[2]),
            'min' => 0,
            'max' => (int) $m[1],
        ];
    }

    return null;
}

/**
 * Match quality for a heading vs age cue. Lower is better.
 * 0 = exact heading, 1 = range that starts at the requested age, 2 = containing range / discrete membership.
 *
 * @param  array{type: string, min?: int, max?: int, value?: int, unit?: string, name?: string, start_value?: int, start_unit?: string, end_value?: int, end_unit?: string, raw: string}  $cue
 */
protected function bikolAgeHeadingMatchRank(string $lower, array $cue): ?int
{
    $type = (string) ($cue['type'] ?? '');

    if (in_array($type, ['birth', 'stage_name', 'mixed_range', 'range'], true)) {
        return $this->bikolHeadingMatchesStructuredAgeCue($lower, $cue) ? 0 : null;
    }

    if ($type !== 'single') {
        return null;
    }

    $value = (int) ($cue['value'] ?? 0);
    $cueUnit = (string) ($cue['unit'] ?? '');
    $parsed = $this->parseBikolAgeHeading($lower);

    if ($parsed === null) {
        return $this->bikolHeadingMatchesLegacySingleAge($lower, $cue) ? 2 : null;
    }

    $kind = (string) ($parsed['kind'] ?? '');

    if ($kind === 'exact') {
        $headingUnit = (string) ($parsed['unit'] ?? '');
        if (
            (int) ($parsed['value'] ?? -1) === $value
            && $this->bikolAgeCueUnitCompatible($cueUnit, $headingUnit)
        ) {
            return 0;
        }

        return null;
    }

    if ($kind === 'discrete') {
        $headingUnit = (string) ($parsed['unit'] ?? '');
        if (
            $this->bikolAgeCueUnitCompatible($cueUnit, $headingUnit)
            && in_array($value, $parsed['values'] ?? [], true)
        ) {
            return 2;
        }

        return null;
    }

    if ($kind === 'range') {
        $headingUnit = (string) ($parsed['unit'] ?? '');
        if (!$this->bikolAgeCueUnitCompatible($cueUnit, $headingUnit)) {
            return null;
        }

        $min = (int) ($parsed['min'] ?? 0);
        $max = (int) ($parsed['max'] ?? 0);
        if ($min === $value) {
            return 1;
        }
        if ($min <= $value && $value <= $max) {
            return 2;
        }

        return null;
    }

    if ($kind === 'mixed') {
        $startUnit = (string) ($parsed['start_unit'] ?? '');
        $startValue = (int) ($parsed['start_value'] ?? 0);
        if (
            $this->bikolAgeCueUnitCompatible($cueUnit, $startUnit)
            && $startValue === $value
        ) {
            return 1;
        }

        return null;
    }

    return null;
}

/**
 * True when a heading line matches a parsed age/time cue.
 *
 * @param  array{type: string, min?: int, max?: int, value?: int, unit?: string, name?: string, start_value?: int, start_unit?: string, end_value?: int, end_unit?: string, raw: string}  $cue
 */
protected function bikolHeadingMatchesAgeCue(string $lower, array $cue): bool
{
    return $this->bikolAgeHeadingMatchRank($lower, $cue) !== null;
}

/**
 * Birth / stage / explicit range cues (not single-age containment).
 *
 * @param  array{type: string, min?: int, max?: int, value?: int, unit?: string, name?: string, start_value?: int, start_unit?: string, end_value?: int, end_unit?: string, raw: string}  $cue
 */
protected function bikolHeadingMatchesStructuredAgeCue(string $lower, array $cue): bool
{
    if ($cue['type'] === 'birth') {
        $norm = $this->normalizeBikolMatchKey($lower);

        return str_contains($norm, 'pagkapangigin')
            || str_contains($norm, 'pagkapanganak')
            || str_contains($norm, 'kapanganakan')
            || str_contains($norm, 'kapanganak')
            || str_contains($lower, 'birth');
    }

    if ($cue['type'] === 'stage_name') {
        $name = mb_strtolower((string) ($cue['name'] ?? $cue['raw'] ?? ''), 'UTF-8');
        if ($name === '') {
            return false;
        }

        $norm = $this->normalizeBikolMatchKey($lower);
        $nameNorm = $this->normalizeBikolMatchKey($name);

        return str_contains($lower, $name)
            || ($nameNorm !== '' && str_contains($norm, $nameNorm));
    }

    if ($cue['type'] === 'mixed_range') {
        $startValue = (int) ($cue['start_value'] ?? 0);
        $endValue = (int) ($cue['end_value'] ?? 0);
        $startUnit = $this->bikolAgeUnitPattern((string) ($cue['start_unit'] ?? ''));
        $endUnit = $this->bikolAgeUnitPattern((string) ($cue['end_unit'] ?? ''));
        if ($startUnit === '' || $endUnit === '') {
            return false;
        }

        return preg_match(
            '/(?<![\d\-–—])' . $startValue . '\s*(?:na\s+)?' . $startUnit . '\b/u',
            $lower
        ) === 1
            && preg_match(
                '/(?<![\d\-–—])' . $endValue . '\s*(?:na\s+)?' . $endUnit . '\b/u',
                $lower
            ) === 1;
    }

    $unit = $cue['unit'] ?? '';
    $unitPattern = $this->bikolAgeUnitPattern($unit);

    if ($cue['type'] === 'range') {
        $min = (int) ($cue['min'] ?? 0);
        $max = (int) ($cue['max'] ?? 0);

        $hasRange = preg_match(
            '/\b' . $min . '\D+' . $max . '\b/u',
            $lower
        ) === 1;

        return $hasRange
            && (
                $unitPattern === ''
                || preg_match('/' . $unitPattern . '/u', $lower) === 1
            );
    }

    return false;
}

/**
 * Pre-containment single-age token match for headings the parser does not cover.
 *
 * @param  array{type: string, min?: int, max?: int, value?: int, unit?: string, raw: string}  $cue
 */
protected function bikolHeadingMatchesLegacySingleAge(string $lower, array $cue): bool
{
    $value = (int) ($cue['value'] ?? 0);
    $unitPattern = $this->bikolAgeUnitPattern((string) ($cue['unit'] ?? ''));

    if (($cue['unit'] ?? '') === 'edad') {
        return preg_match(
            '/\bedad\s+' . $value . '(?!\s*[-–—])/u',
            $lower
        ) === 1;
    }

    $unitAlt = $unitPattern !== ''
        ? $unitPattern
        : '(?:bulan|buwan|bukan|taon|aldow|days?|araw)';

    if (preg_match(
        '/(?<![\d\-–—])' . $value . '\s*(?:na\s+)?' . $unitAlt . '\b/u',
        $lower
    ) === 1) {
        if (preg_match(
            '/\d+\s*(?:na\s+)?' . $unitAlt . '\b.+(?<![\d\-–—])' . $value . '\s*(?:na\s+)?' . $unitAlt . '\b/u',
            $lower
        ) === 1) {
            return false;
        }

        return true;
    }

    if (preg_match(
        '/(?<![\d\-–—])' . $value . '\D+\d+\s*(?:na\s+)?' . $unitAlt . '\b/u',
        $lower
    ) === 1) {
        return true;
    }

    return preg_match('/' . $unitAlt . '/u', $lower) === 1
        && preg_match('/(?<![\d\-–—])' . $value . '\D+\d+\b/u', $lower) === 1;
}

protected function bikolAgeUnitPattern(string $unit): string
{
    return match ($unit) {
        'bulan' => '(?:bulan|buwan|bukan)',
        'aldow' => '(?:aldow|days?|araw)',
        'taon' => 'taon',
        default => $unit !== '' ? preg_quote($unit, '/') : '',
    };
}

/**
 * Eligibility / who-is-included headings (not timing/frequency).
 */
protected function isBikolEligibilityScheduleHeading(string $lower): bool
{
    return str_contains($lower, 'kabilang')
        || str_contains($lower, 'kaiba')
        || preg_match('/\bisay\b/u', $lower) === 1
        || str_contains($lower, 'sakop');
}

/**
 * Timing/frequency cues on a schedule heading (not who/age-eligibility).
 */
protected function isBikolTimingScheduleHeadingCue(string $lower, string $norm): bool
{
    return str_contains($lower, 'iskedyul')
        || str_contains($lower, 'schedule')
        || str_contains($lower, 'kuno')
        || str_contains($lower, 'sunod')
        || str_contains($lower, 'milestone')
        || str_contains($norm, 'pagkapangigin')
        || str_contains($norm, 'pagkapanganak')
        || preg_match(
            '/\d+\s*(?:na\s+)?(?:bulan|buwan|bukan|taon|aldow|days?|araw)\b/u',
            $lower
        ) === 1;
}

/**
 * "When / how often" schedule asks that do not request who/age eligibility.
 */
protected function isBikolTimingOnlyScheduleQuestion(string $question): bool
{
    $q = mb_strtolower(trim($question), 'UTF-8');

    if (preg_match('/\bkuno\b/u', $q) !== 1) {
        return false;
    }

    return preg_match('/\b(edad|sakop|isay|kabilang|kaiba)\b/u', $q) !== 1;
}

/**
 * Generic schedule / age / stage section headings.
 */
protected function isBikolScheduleHeading(string $line, string $lower, ?string $question = null): bool
{
    // Body bullets are never schedule headings (e.g. "- Milestone smile").
    if (preg_match('/^\s*[-–—•*]/u', $line)) {
        return false;
    }

    // Numbered body items ("1. BCG") are not headings; "9 NA BULAN …" is.
    if (preg_match('/^\s*\d+[\.\)]\s+\S/u', $line)) {
        return false;
    }

    if (!(
        $this->isBikolHeadingLikeLine($line, $lower)
        || (mb_strlen($line) <= 120 && str_ends_with($lower, '?'))
    )) {
        return false;
    }

    $norm = $this->normalizeBikolMatchKey($lower);

    if (
        str_contains($lower, 'iskedyul')
        || str_contains($lower, 'schedule')
        || str_contains($lower, 'edad')
        || str_contains($lower, 'stage')
        || str_contains($lower, 'yugto')
        || str_contains($lower, 'pagmamabros')
        || str_contains($lower, 'milestone')
        ||         str_contains($lower, 'sakop')
        || str_contains($norm, 'pagkapangigin')
        || str_contains($norm, 'pagkapanganak')
        || str_contains($norm, 'kapanganakan')
        || str_contains($lower, 'pagdakulo')
        || str_contains($lower, 'pagbabago')
        || str_contains($lower, 'kabilang')
        || (
            str_contains($lower, 'kaiba')
            && preg_match('/\bisay\b/u', $lower) === 1
        )
    ) {
        if (
            $question !== null
            && $this->isBikolTimingOnlyScheduleQuestion($question)
            && $this->isBikolEligibilityScheduleHeading($lower)
            && !$this->isBikolTimingScheduleHeadingCue($lower, $norm)
        ) {
            return false;
        }

        return true;
    }

    // Numeric age / day headings: "3–6 BUWAN", "270 ALDOW", "0 HANGGANG 6 NA BULAN".
    if (preg_match(
        '/\d+\D+\d+\s*(?:na\s+)?(?:bulan|buwan|bukan|taon|aldow|days?|araw)\b/u',
        $lower
    ) === 1) {
        return true;
    }

    if (preg_match(
        '/\b\d+\s*(?:na\s+)?(?:bulan|buwan|bukan|taon|aldow|days?|araw)\b/u',
        $lower
    ) === 1) {
        return true;
    }

    if (preg_match(
        '/\b\d+\s+hanggang\s+\d+/u',
        $lower
    ) === 1) {
        return true;
    }

    return false;
}

/**
 * Generic warning / urgent-action section headings.
 */
protected function isBikolWarningHeading(string $line, string $lower): bool
{
    if (preg_match('/^\s*[-–—•*\d]/u', $line)) {
        return false;
    }

    if (!(
        $this->isBikolHeadingLikeLine($line, $lower)
        || (mb_strlen($line) <= 140 && str_ends_with($lower, '?'))
    )) {
        return false;
    }

    return str_contains($lower, 'magpakonsulta')
        || str_contains($lower, 'dadarahon')
        || str_contains($lower, 'health center')
        || str_contains($lower, 'ospital')
        || $this->bikolTextHasFailedTestCondition($lower)
        || str_contains($lower, 'kombolsyon')
        || preg_match('/\bmalala\b/u', $lower) === 1
        || $this->bikolTextHasPositiveTestCondition($lower)
        || (
            str_contains($lower, 'alangkaw')
            && str_contains($lower, 'kalintura')
        )
        || preg_match('/nahihirapan\s+(?:mag[\s\-]*inga|pag[\s\-]*inga)/u', $lower) === 1
        || (
            (str_contains($lower, 'agad') || str_contains($lower, 'tulos'))
            && (
                str_contains($lower, 'magpakonsulta')
                || str_contains($lower, 'dadarahon')
                || str_contains($lower, 'kailan')
                || str_contains($lower, 'kuno')
            )
        )
        || (
            $this->bikolTextHasWarningActionLanguage($lower)
            && (
                $this->bikolTextHasFailedTestCondition($lower)
                || $this->bikolTextHasPositiveTestCondition($lower)
            )
        );
}

/**
 * Matching-only: "did not pass" wording (pumasa / pinasa / pin-nasa / dira|diri).
 * Does not rewrite displayed dataset text.
 */
protected function bikolTextHasFailedTestCondition(string $lower): bool
{
    $norm = $this->normalizeBikolFailPassKey($lower);

    if (
        preg_match('/\bdir[ai]\b.{0,48}\b(?:pumasa|pinasa)\b/u', $norm) === 1
        || preg_match('/\b(?:pumasa|pinasa)\b.{0,24}\bdir[ai]\b/u', $norm) === 1
    ) {
        return true;
    }

    return str_contains($norm, 'failed test')
        || str_contains($norm, 'failed screening');
}

/**
 * FAQ-style action questions ("Uno a dapat/gibuhon/gigibuhon …") that did not
 * resolve to a supported warning/schedule/definition/management heading.
 * Conservative: do not guess another FAQ or neighbor document.
 */
protected function isBikolAmbiguousFaqQuestion(string $question, string $intent): bool
{
    $q = mb_strtolower(trim($question));

    if (!preg_match('/\buno\b/u', $q)) {
        return false;
    }

    if (!preg_match('/\b(?:dapat|gibuhon|gigibuhon)\b/u', $q)) {
        return false;
    }

    if (in_array($intent, ['warning', 'schedule', 'definition'], true)) {
        return false;
    }

    if ($intent === 'diagnosis' && $this->isBikolDiagnosisHeading($q)) {
        return false;
    }

    if ($intent === 'symptoms' && $this->isBikolSymptomsHeading($q)) {
        return false;
    }

    if ($intent === 'management' && $this->isBikolManagementHeading($q)) {
        return false;
    }

    return true;
}

/**
 * Matching-only: positive test/screening/result wording.
 */
protected function bikolTextHasPositiveTestCondition(string $lower): bool
{
    $norm = $this->normalizeBikolFailPassKey($lower);
    $hasPositive = str_contains($norm, 'positive')
        || str_contains($norm, 'positibo');

    return $hasPositive
        && (
            str_contains($norm, 'resulta')
            || str_contains($norm, 'screening')
            || str_contains($norm, 'test')
        );
}

/**
 * Matching-only: consult/action language on a warning FAQ heading.
 */
protected function bikolTextHasWarningActionLanguage(string $lower): bool
{
    $norm = $this->normalizeBikolFailPassKey($lower);

    return str_contains($norm, 'dapat')
        || str_contains($norm, 'gibuhon')
        || str_contains($norm, 'gigibuhon')
        || str_contains($norm, 'magpakonsulta')
        || str_contains($norm, 'pagpaeksamin')
        || str_contains($norm, 'paeksamin')
        || str_contains($norm, 'tulos')
        || str_contains($norm, 'agad');
}

/**
 * Collapse hyphen/space pass-verb forms for matching only.
 */
protected function normalizeBikolFailPassKey(string $text): string
{
    $text = mb_strtolower($text);
    $text = preg_replace('/[\s\-]+/u', ' ', $text) ?? $text;

    return preg_replace('/\bpin nasa\b/u', 'pinasa', $text) ?? $text;
}

/**
 * Failed/positive FAQ questions only collect the matching warning subsection.
 * Generic consult/warning questions still match all warning headings.
 */
protected function bikolWarningHeadingMatchesQuestion(string $headingLower, ?string $question): bool
{
    if ($question === null || trim($question) === '') {
        return true;
    }

    $q = mb_strtolower(trim($question));
    $questionFailed = $this->bikolTextHasFailedTestCondition($q);
    $questionPositive = $this->bikolTextHasPositiveTestCondition($q);

    if (!$questionFailed && !$questionPositive) {
        return true;
    }

    if ($questionFailed && $this->bikolTextHasFailedTestCondition($headingLower)) {
        return true;
    }

    if ($questionPositive && $this->bikolTextHasPositiveTestCondition($headingLower)) {
        return true;
    }

    return false;
}

/**
 * End schedule/warning collection when a new unrelated block begins.
 */
protected function isBikolScheduleOrWarningEndBoundary(string $line, string $lower): bool
{
    if ($this->isBikolDefinitionHeading($lower)) {
        return true;
    }

    if (
        $this->isBikolHeadingLikeLine($line, $lower)
        && (
            $this->isBikolSymptomsHeading($lower)
            || $this->isBikolCausesHeading($lower)
            || $this->isBikolManagementHeading($lower)
            || $this->isBikolDiagnosisHeading($lower)
            || $this->isBikolBenefitsHeading($lower)
        )
    ) {
        return true;
    }

    if ($this->isBikolWarningHeading($line, $lower)) {
        return true;
    }

    if ($this->isBikolScheduleHeading($line, $lower)) {
        return true;
    }

    if ($this->isBikolGenericSectionBoundary($line, $lower)) {
        return true;
    }

    return false;
}

/**
 * Strip a leading UTF-8 BOM so start-of-line banner matching can use ^.
 */
protected function stripUtf8Bom(string $text): string
{
    if ($text === '') {
        return $text;
    }

    $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
        $text = substr($text, 3);
    }

    return $text;
}

/**
 * Topic-banner / document-header lines (not ordinary body that merely mentions "paksa").
 */
protected function isBikolDocumentBannerHeading(string $lower): bool
{
    $lower = mb_strtolower(trim($this->stripUtf8Bom($lower)), 'UTF-8');

    return preg_match(
        '/^(?:pang\s+unang\s+paksa|pangunahing\s+paksa|pangunahong\s+paksa|main\s+topic)\b/u',
        $lower
    ) === 1;
}

/**
 * True when the whole question is a plain "Uno a/ana <topic>?" definition ask.
 */
protected function isBikolPlainUnoTopicQuestion(string $q): bool
{
    $q = mb_strtolower(trim($q));

    if (!preg_match('/^\s*uno(?:\s+man)?\s+(?:ana|a)\s+(.+?)\s*\??\s*$/u', $q, $m)) {
        return false;
    }

    $topic = trim($m[1]);
    if ($topic === '' || preg_match('/^mga\b/u', $topic) === 1) {
        return false;
    }

    if (preg_match(
        '/\b(dapat|maisihan|pagkaisi|gibuhon|gigibuhon|kin|kung|ngata|importante|amay)\b/u',
        $topic
    ) === 1) {
        return false;
    }

    return true;
}

/**
 * Collect only the subsection under the single winning age/time heading.
 *
 * @param  array{type: string, min?: int, max?: int, value?: int, unit?: string, raw: string}  $ageCue
 */
protected function extractBikolAgeScopedSection(string $content, array $ageCue): array
{
    $lines = preg_split('/\R+/u', $content) ?: [];
    $winner = $this->bikolFindWinningAgeHeading($lines, $ageCue);
    if ($winner === null) {
        return [];
    }

    $results = [];
    $lineCount = count($lines);

    for ($i = $winner['index'] + 1; $i < $lineCount; $i++) {
        $line = trim((string) $lines[$i]);
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line);

        if ($this->isBikolDocumentBannerHeading($lower) || $this->isBikolDefinitionHeading($lower)) {
            break;
        }

        if ($this->isBikolScheduleHeading($line, $lower) || $this->isBikolAllCapsHeading($line)) {
            break;
        }

        if ($this->isBikolScheduleOrWarningEndBoundary($line, $lower)) {
            break;
        }

        $results[] = $line;
    }

    return $results;
}

/**
 * Among already-retrieved documents, pick the one with the best age-heading match.
 * Topic-compatible documents are preferred when the question names a topic.
 * Tighter same-unit ranges beat wider ones. Does not change retrieve scoring.
 *
 * @param  list<array{content: string, source_file: string}>  $documents
 * @param  array{type: string, min?: int, max?: int, value?: int, unit?: string, raw: string}  $ageCue
 * @return array{content: string, source_file: string}|null
 */
protected function selectBikolAgeScopedDocument(array $documents, array $ageCue, string $question = ''): ?array
{
    $topicTokens = $this->bikolMeaningfulQuestionTopicTokens($question);
    $best = null;
    $bestRank = PHP_INT_MAX;
    $bestWidth = PHP_INT_MAX;
    $bestIndex = PHP_INT_MAX;

    foreach ($documents as $index => $document) {
        $sourceFile = (string) ($document['source_file'] ?? '');
        $content = $this->resolveBikolSourceFileContent($sourceFile);
        if ($content === '') {
            $content = (string) ($document['content'] ?? '');
        }
        if ($content === '') {
            continue;
        }

        if (
            $topicTokens !== []
            && !$this->bikolDocumentCompatibleWithQuestionTopic($topicTokens, $sourceFile, $content)
        ) {
            continue;
        }

        $lines = preg_split('/\R+/u', $content) ?: [];
        $winner = $this->bikolFindWinningAgeHeading($lines, $ageCue);
        if ($winner === null) {
            continue;
        }

        $rank = (int) $winner['rank'];
        $width = (int) $winner['width'];
        if (
            $rank < $bestRank
            || ($rank === $bestRank && $width < $bestWidth)
            || ($rank === $bestRank && $width === $bestWidth && $index < $bestIndex)
        ) {
            $best = [
                'content' => $content,
                'source_file' => $sourceFile !== '' ? $sourceFile : (string) ($document['source_file'] ?? ''),
            ];
            $bestRank = $rank;
            $bestWidth = $width;
            $bestIndex = $index;
        }
    }

    return $best;
}

/**
 * Question topic tokens that can select a document, excluding age/child context.
 *
 * @return list<string>
 */
protected function bikolMeaningfulQuestionTopicTokens(string $question): array
{
    if (trim($question) === '') {
        return [];
    }

    return array_values(array_filter(
        $this->bikolQuestionTopicTokens($question),
        fn ($token) => !$this->isBikolAgeOrContextTopicToken((string) $token)
    ));
}

/**
 * Age, schedule, and child-context words that must not pick a document.
 */
protected function isBikolAgeOrContextTopicToken(string $token): bool
{
    $token = mb_strtolower(trim($token), 'UTF-8');
    if ($token === '') {
        return true;
    }

    if ($this->isWeakScheduleTopicAnchor($token)) {
        return true;
    }

    return in_array($token, [
        'igen',
        'igin',
        'bulan',
        'buwan',
        'bukan',
        'taon',
        'edad',
        'aldow',
        'days',
        'day',
        'araw',
    ], true);
}

/**
 * True when a retrieved document matches at least one explicit question topic.
 *
 * @param  list<string>  $topicTokens
 */
protected function bikolDocumentCompatibleWithQuestionTopic(
    array $topicTokens,
    string $sourceFile,
    string $content
): bool {
    if ($topicTokens === []) {
        return true;
    }

    $slug = strtolower(str_replace(['.txt', '_'], ['', ' '], $sourceFile));
    $titleTokens = $this->extractBikolTopicTitleTokens($content, $sourceFile);
    $lowerContent = mb_strtolower($content, 'UTF-8');

    foreach ($topicTokens as $token) {
        $token = (string) $token;
        if ($token === '') {
            continue;
        }

        if ($this->bikolHeadingTokenOverlap([$token], $titleTokens) !== []) {
            return true;
        }

        if ($this->bikolDocumentMatchesTopicConcept($content, $token)) {
            return true;
        }

        if ($this->bikolDocumentMatchesTopicConcept($slug, $token)) {
            return true;
        }

        foreach ($this->bikolTopicConceptEquivalents($token) as $alias) {
            if ($this->bikolHeadingTokenOverlap([$alias], $titleTokens) !== []) {
                return true;
            }
            $quoted = preg_quote($alias, '/');
            if (
                preg_match('/\b' . $quoted . '\b/u', $lowerContent) === 1
                || preg_match('/\b' . $quoted . '\b/u', $slug) === 1
            ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param  list<string>  $lines
 * @param  array{type: string, min?: int, max?: int, value?: int, unit?: string, raw: string}  $ageCue
 * @return array{index: int, rank: int, width: int}|null
 */
protected function bikolFindWinningAgeHeading(array $lines, array $ageCue): ?array
{
    $candidates = [];

    foreach ($lines as $index => $raw) {
        $line = trim((string) $raw);
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line);

        if ($this->isBikolDocumentBannerHeading($lower) || $this->isBikolDefinitionHeading($lower)) {
            continue;
        }

        if (!($this->isBikolScheduleHeading($line, $lower) || $this->isBikolAllCapsHeading($line))) {
            continue;
        }

        $rank = $this->bikolAgeHeadingMatchRank($lower, $ageCue);
        if ($rank === null) {
            continue;
        }

        $candidates[] = [
            'index' => $index,
            'rank' => $rank,
            'width' => $this->bikolAgeHeadingMatchWidth($lower),
        ];
    }

    if ($candidates === []) {
        return null;
    }

    usort($candidates, function ($a, $b) {
        return $a['rank'] <=> $b['rank']
            ?: $a['width'] <=> $b['width']
            ?: $a['index'] <=> $b['index'];
    });

    return $candidates[0];
}

protected function bikolAgeHeadingMatchWidth(string $lower): int
{
    $parsed = $this->parseBikolAgeHeading($lower);
    if ($parsed === null) {
        return PHP_INT_MAX;
    }

    $kind = (string) ($parsed['kind'] ?? '');
    if ($kind === 'exact' || $kind === 'discrete') {
        return 0;
    }

    if ($kind === 'range') {
        return max(0, (int) ($parsed['max'] ?? 0) - (int) ($parsed['min'] ?? 0));
    }

    return PHP_INT_MAX;
}

protected function isBikolHeadingLikeLine(string $line, string $lower): bool
{
    if ($this->isBikolAllCapsHeading($line)) {
        return true;
    }

    // Keep non-ALLCAPS semantic titles short and title-like.
    if (mb_strlen($line) > 70) {
        return false;
    }

    if (preg_match('/[.!;]$/u', $line)) {
        return false;
    }

    // Clause-style body lines are not peer section titles.
    if (preg_match('/^(kung|puwede(?:ng)?|bagama|labing)\b/u', $lower) === 1) {
        return false;
    }

    // Mid-sentence prose usually has multiple commas.
    if (substr_count($line, ',') >= 2) {
        return false;
    }

    return true;
}

/**
 * Signs / characteristics section headings.
 */
protected function isBikolSymptomsHeading(string $lower): bool
{
    return str_contains($lower, 'senyales')
        || str_contains($lower, 'sinyalis')
        || str_contains($lower, 'katangian')
        || str_contains($lower, 'palatandaan')
        || str_contains($lower, 'sintomas')
        || str_contains($lower, 'pag-uugali')
        || str_contains($lower, 'pag uugali');
}

/**
 * Causes / risk-factor section headings.
 */
protected function isBikolCausesHeading(string $lower): bool
{
    return str_contains($lower, 'dahilan')
        || str_contains($lower, 'rason')
        || $this->isBikolCauseRiskRaiseHeading($lower);
}

/**
 * "Ngata nagkaka-agko <topic>?" — why does one develop X (etiology / causes).
 * Distinct from "Ngata agko …" benefits / purpose questions.
 */
protected function isBikolDiseaseOnsetCausesQuestion(string $question): bool
{
    $q = mb_strtolower(trim($question), 'UTF-8');

    return preg_match('/nagkaka[\s\-]*agko/u', $q) === 1;
}

/**
 * "Raises the risk" headings. SA and KANA are linker variants here only.
 */
protected function isBikolCauseRiskRaiseHeading(string $lower): bool
{
    return preg_match('/nagpapalangkaw\s+(?:sa|kana)\s+panganib/u', $lower) === 1;
}

/**
 * Care / support / prevention / management section headings.
 */
protected function isBikolManagementHeading(string $lower): bool
{
    return $this->isBikolPreventionHeading($lower)
        || $this->isBikolTreatmentHeading($lower)
        || str_contains($lower, 'pag-likay pala pamamahala')
        || str_contains($lower, 'uno mga dapat gibuhon');
}

/**
 * Prevention / paglikay / pagiwas section headings.
 */
protected function isBikolPreventionHeading(string $lower): bool
{
    $compact = preg_replace('/[\s\-]+/u', '', $lower) ?? $lower;

    return str_contains($compact, 'paglilikay')
        || str_contains($compact, 'paglikay')
        || str_contains($compact, 'pagiwas')
        || str_contains($lower, 'makaiwas')
        || str_contains($lower, 'malikayan')
        || str_contains($lower, 'pagsugpo');
}

/**
 * Treatment / care / pagbulong section headings.
 */
protected function isBikolTreatmentHeading(string $lower): bool
{
    $compact = preg_replace('/[\s\-]+/u', '', $lower) ?? $lower;

    return str_contains($compact, 'pagbubulong')
        || str_contains($compact, 'pagbulong')
        || str_contains($compact, 'pambulung')
        || str_contains($lower, 'ataman')
        || str_contains($lower, 'kalinigan')
        || str_contains($lower, 'suporta')
        || str_contains($lower, 'interbensyon')
        || str_contains($lower, 'intervention');
}

/**
 * Effects / complications section headings.
 */
protected function isBikolEffectsHeading(string $lower): bool
{
    // Prevention/treatment and "raises the risk" titles are not effects
    // merely because they mention panganib.
    if (
        $this->isBikolPreventionHeading($lower)
        || $this->isBikolTreatmentHeading($lower)
        || $this->isBikolCauseRiskRaiseHeading($lower)
    ) {
        return false;
    }

    return str_contains($lower, 'mga posibleng epekto')
        || str_contains($lower, 'epekto')
        || str_contains($lower, 'panganib')
        || str_contains($lower, 'komplikasyon')
        || $lower === 'ilang sa puso'
        || str_contains($lower, 'stroke pala mga suliranon sa utok')
        || $lower === 'ilang sa heart valve'
        || $lower === 'ilang sa mga bato';
}

/**
 * Early detection / diagnosis / screening section headings.
 */
protected function isBikolDiagnosisHeading(string $lower): bool
{
    return $lower === 'diyagnosis'
        || $lower === 'diagnosis'
        || $lower === 'screening'
        || str_contains($lower, 'maisihan')
        || str_contains($lower, 'pagkaisi')
        || str_contains($lower, 'early detection');
}

protected function extractBikolSections(
    string $content,
    string $intent,
    ?array $ageCue = null,
    ?string $question = null
): array {
    if ($intent === 'schedule' && $ageCue !== null) {
        $scoped = $this->extractBikolAgeScopedSection($content, $ageCue);
        if ($scoped !== []) {
            return $scoped;
        }

        // Parsed age cue with no matching heading: do not merge all schedule buckets.
        return [];
    }

    $lines = preg_split('/\R+/u', $content);

    $results = [];
    $currentSection = 'general';
    $startedDefinition = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line);

        /*
        |--------------------------------------------------------------------------
        | SECTION HEADINGS
        |--------------------------------------------------------------------------
        */

        if (
            $intent === 'definition'
            && $startedDefinition
            && $this->isBikolPeerHeadingBoundary($line, $lower)
        ) {
            break;
        }

        // Definition: "Uno a <topic>?" / "Uno ana <topic>?" / "Uno man ana <topic>?"
        // For definition intent, lock to the first heading; later UNO A/ANA FAQs
        // are peer stops (handled above). Nested mixed-case "Uno a …" stays in-body.
        if ($this->isBikolDefinitionHeading($lower)) {
            $nestedDefinitionChild = $intent === 'definition'
                && $startedDefinition
                && !$this->isBikolPeerHeadingBoundary($line, $lower);

            if (!$nestedDefinitionChild) {
                $currentSection = 'definition';
                if ($intent === 'definition') {
                    $startedDefinition = true;
                }
                continue;
            }
        }

        // Warning / urgent action — before diagnosis & generic boundaries.
        if ($this->isBikolWarningHeading($line, $lower)) {
            if (
                $intent === 'warning'
                && !$this->bikolWarningHeadingMatchesQuestion($lower, $question)
            ) {
                $currentSection = 'general';
                continue;
            }

            $currentSection = 'warning';
            continue;
        }

        // Schedule / age / stage buckets.
        if ($this->isBikolScheduleHeading($line, $lower, $question)) {
            $currentSection = 'schedule';
            continue;
        }

        // Early detection / diagnosis / screening
        if (
            $this->isBikolHeadingLikeLine($line, $lower)
            && $this->isBikolDiagnosisHeading($lower)
        ) {
            $currentSection = 'diagnosis';
            continue;
        }

        // Symptoms / signs / characteristics
        if (
            $this->isBikolHeadingLikeLine($line, $lower)
            && $this->isBikolSymptomsHeading($lower)
        ) {
            $currentSection = 'symptoms';
            continue;
        }

        // Causes / risk factors
        if (
            $this->isBikolHeadingLikeLine($line, $lower)
            && $this->isBikolCausesHeading($lower)
        ) {
            $currentSection = 'causes';
            continue;
        }

        // Purpose / benefits / importance
        if ($this->isBikolBenefitsHeading($lower)) {
            $currentSection = 'benefits';
            continue;
        }

        // Effects / complications
        if (
            $this->isBikolHeadingLikeLine($line, $lower)
            && $this->isBikolEffectsHeading($lower)
        ) {
            $currentSection = 'effects';
            continue;
        }

        // Care / support / prevention / management
        if (
            $this->isBikolHeadingLikeLine($line, $lower)
            && $this->isBikolManagementHeading($lower)
        ) {
            if ($this->bikolManagementHeadingMatchesQuestion($lower, $question)) {
                $currentSection = 'management';
            } elseif ($intent === 'management') {
                $currentSection = 'general';
            }
            continue;
        }

        // Generic short heading / ALL-CAPS label ends the previous section.
        if ($this->isBikolGenericSectionBoundary($line, $lower)) {
            $currentSection = 'general';
            continue;
        }

        // Benefits: stop at FAQ questions, numbered topics, and new subsections.
        if (
            $currentSection === 'benefits'
            && $this->isBikolBenefitsEndBoundary($line, $lower)
        ) {
            $currentSection = 'general';
            continue;
        }

        // Schedule / warning: stop when a new unrelated block begins.
        if (
            in_array($currentSection, ['schedule', 'warning'], true)
            && $this->isBikolScheduleOrWarningEndBoundary($line, $lower)
            && !(
                ($currentSection === 'schedule' && $this->isBikolScheduleHeading($line, $lower, $question))
                || ($currentSection === 'warning' && $this->isBikolWarningHeading($line, $lower))
            )
        ) {
            $currentSection = 'general';
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | COLLECT CONTENT
        |--------------------------------------------------------------------------
        */

        if ($currentSection === $intent) {
            $results[] = $line;
        }
    }

    return $results;
}

/**
 * Matching-only tokens for heading-keyword fallback (length >= 3).
 */
protected function extractBikolHeadingMatchTokens(string $text): array
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[\s\-_\/]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;

    preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

    $weak = array_values(array_unique(array_merge($this->stopwords, [
        'kung', 'igen', 'agko', 'dapat', 'gigibuhon',
    ])));

    $sectionCues = [
        'importante', 'impormasyon', 'benepisyo', 'benipisyo',
        'stage', 'yugto', 'edad', 'pagbabago',
    ];

    $tokens = [];
    foreach ($matches[0] as $word) {
        if (mb_strlen($word, 'UTF-8') < 3) {
            continue;
        }
        if (!in_array($word, $sectionCues, true)) {
            if (in_array($word, $weak, true)) {
                continue;
            }
            if (
                ($this->isWeakScheduleTopicAnchor($word) || $this->isWeakExplicitTopicAnchor($word))
                && !$this->isBikolTopicIdentityToken($word)
            ) {
                continue;
            }
        }
        $tokens[] = $word;
    }

    return array_values(array_unique($tokens));
}

/**
 * Intent words that must not count as leftover topic names.
 */
protected function isBikolSemanticIntentCueToken(string $token): bool
{
    $token = mb_strtolower(trim($token), 'UTF-8');
    if ($token === '') {
        return false;
    }

    if ($this->isBikolTopicIdentityToken($token)) {
        return false;
    }

    if ($this->isWeakExplicitTopicAnchor($token) || $this->isWeakScheduleTopicAnchor($token)) {
        return true;
    }

    return in_array($token, [
        'malikayan',
        'paglikay',
        'pagbulong',
        'pambulung',
        'senyales',
        'sintomas',
        'palatandaan',
        'dahilan',
        'rason',
        'epekto',
        'panganib',
        'benepisyo',
        'benipisyo',
        'likay',
        'iwas',
        'ataman',
        'aatamanon',
        // From "nagkaka-agko" / "nagkaka agko" disease-onset causes cues.
        'nagkaka',
        'nagkakaagko',
    ], true);
}

/**
 * Query tokens that name a topic, excluding semantic intent cues.
 *
 * @return list<string>
 */
protected function bikolQuestionTopicTokens(string $question): array
{
    return array_values(array_filter(
        $this->extractBikolHeadingMatchTokens($question),
        fn ($token) => !$this->isBikolSemanticIntentCueToken((string) $token)
    ));
}

/**
 * True when the current file slug/banner overlaps the question's topic words.
 */
protected function isBikolConfidentCurrentTopic(
    string $question,
    string $sourceFile,
    string $content
): bool {
    $topicTokens = $this->bikolQuestionTopicTokens($question);
    if ($topicTokens === []) {
        return false;
    }

    $titleTokens = $this->extractBikolTopicTitleTokens($content, $sourceFile);

    return $this->bikolHeadingTokenOverlap($topicTokens, $titleTokens) !== [];
}

/**
 * When the user asks prevention vs treatment, keep only that management subtype.
 */
protected function bikolManagementHeadingMatchesQuestion(string $lower, ?string $question): bool
{
    if ($question === null || trim($question) === '') {
        return true;
    }

    $q = mb_strtolower($question, 'UTF-8');
    $asksPrevention = preg_match('/\b(likay|iwas|maiwasan|maiiwasan|malikayan)\b/u', $q) === 1;
    $asksTreatment = preg_match('/\b(aatamanon|ataman|bulong|gamot)\b/u', $q) === 1;
    $prevention = $this->isBikolPreventionHeading($lower);
    $treatment = $this->isBikolTreatmentHeading($lower);

    if ($asksPrevention && !$asksTreatment) {
        return $prevention || !$treatment;
    }

    if ($asksTreatment && !$asksPrevention) {
        return $treatment || !$prevention;
    }

    return true;
}

/**
 * Heading-keyword leftover matches must be the same semantic section the user asked for.
 */
protected function bikolHeadingMatchesSemanticIntent(
    string $lower,
    string $intent,
    string $question
): bool {
    return match ($intent) {
        'symptoms' => $this->isBikolSymptomsHeading($lower),
        'effects' => $this->isBikolEffectsHeading($lower),
        'management' => $this->isBikolManagementHeading($lower)
            && $this->bikolManagementHeadingMatchesQuestion($lower, $question),
        'benefits' => $this->isBikolBenefitsHeading($lower),
        default => true,
    };
}

/**
 * Filename slug + document banner/title tokens for the current topic.
 *
 * @return list<string>
 */
protected function extractBikolTopicTitleTokens(string $content = '', string $sourceFile = ''): array
{
    $slug = strtolower(str_replace(['.txt', '_'], ['', ' '], $sourceFile));
    $tokens = $this->extractBikolHeadingMatchTokens($slug);
    $bannerLabels = [
        'pang', 'unang', 'pangunahing', 'pangunahong', 'paksa', 'main', 'topic',
    ];

    $content = $this->stripUtf8Bom($content);
    $lines = preg_split('/\R+/u', $content) ?: [];
    foreach ($lines as $raw) {
        $line = trim($this->stripUtf8Bom((string) $raw));
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line, 'UTF-8');
        if (!$this->isBikolDocumentBannerHeading($lower)) {
            continue;
        }

        foreach ($this->extractBikolHeadingMatchTokens($line) as $token) {
            if (in_array($token, $bannerLabels, true)) {
                continue;
            }
            $tokens[] = $token;
        }
        break;
    }

    return array_values(array_unique($tokens));
}

/**
 * Query heading-match tokens that are not already in the topic slug or title.
 *
 * @return list<string>
 */
protected function extractBikolRemainingHeadingSectionTokens(
    string $question,
    string $sourceFile = '',
    string $content = ''
): array {
    $queryTokens = $this->extractBikolHeadingMatchTokens($question);
    if ($queryTokens === []) {
        return [];
    }

    $topicTokens = $this->extractBikolTopicTitleTokens($content, $sourceFile);

    return array_values(array_diff($queryTokens, $topicTokens));
}

/**
 * True when leftover query tokens overlap a heading line in this document.
 */
protected function bikolDocumentHeadingsOverlapTokens(string $content, array $tokens): bool
{
    if ($tokens === [] || $content === '') {
        return false;
    }

    $lines = preg_split('/\R+/u', $content) ?: [];
    foreach ($lines as $raw) {
        $line = trim((string) $raw);
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line, 'UTF-8');
        if (
            !$this->isBikolAllCapsHeading($line)
            && !$this->isBikolGenericSectionBoundary($line, $lower)
            && !$this->isBikolHeadingKeywordCandidate($line, $lower)
        ) {
            continue;
        }

        if ($this->bikolHeadingTokenOverlap($this->extractBikolHeadingMatchTokens($line), $tokens) !== []) {
            return true;
        }
    }

    return false;
}

/**
 * Conservative token aliases for heading overlap (not a topic/filename map).
 *
 * @return list<string>
 */
protected function bikolHeadingTokenEquivalents(string $token): array
{
    $token = mb_strtolower($token, 'UTF-8');

    if ($token === 'importante' || $token === 'impormasyon') {
        return ['importante', 'impormasyon'];
    }

    if ($token === 'benepisyo' || $token === 'benipisyo') {
        return ['benepisyo', 'benipisyo'];
    }

    if ($token === 'stage' || $token === 'yugto' || $token === 'milestone') {
        return ['stage', 'yugto', 'milestone'];
    }

    if ($token === 'bakuna' || $token === 'vaccine' || $token === 'immunization') {
        return ['bakuna', 'vaccine', 'immunization'];
    }

    if ($token === 'bukan' || $token === 'bulan' || $token === 'buwan') {
        return ['bukan', 'bulan', 'buwan'];
    }

    return [$token];
}

/**
 * @param  list<string>  $left
 * @param  list<string>  $right
 * @return list<string>
 */
protected function bikolHeadingTokenOverlap(array $left, array $right): array
{
    $overlap = [];

    foreach ($left as $token) {
        $equivalents = $this->bikolHeadingTokenEquivalents($token);
        foreach ($right as $candidate) {
            if (in_array($candidate, $equivalents, true)) {
                $overlap[] = $token;
                break;
            }
        }
    }

    return array_values(array_unique($overlap));
}

/**
 * Numbered child titles such as "1. UNDERNUTRITION ...".
 */
protected function isBikolNumberedSectionLine(string $line): bool
{
    return preg_match('/^\d+[.)]\s+\S/u', $line) === 1;
}

/**
 * Heading-like lines that may be matched (not bullets, not numbered children).
 */
protected function isBikolHeadingKeywordCandidate(string $line, string $lower): bool
{
    if ($line === '' || $this->isBikolNumberedSectionLine($line)) {
        return false;
    }

    if (preg_match('/^\s*[-–—•*]/u', $line) === 1) {
        return false;
    }

    return $this->isBikolHeadingLikeLine($line, $lower)
        || $this->isBikolGenericSectionBoundary($line, $lower)
        || $this->isBikolDefinitionHeading($lower)
        || $this->isBikolWarningHeading($line, $lower)
        || $this->isBikolScheduleHeading($line, $lower)
        || $this->isBikolDocumentBannerHeading($lower)
        || $this->isBikolAllCapsHeading($line)
        || (mb_strlen($line) <= 140 && str_ends_with($lower, '?'));
}

/**
 * Peer/top-level heading that ends the currently collected section.
 */
protected function isBikolPeerHeadingBoundary(string $line, string $lower): bool
{
    if ($this->isBikolNumberedSectionLine($line) || preg_match('/^\s*[-–—•*]/u', $line) === 1) {
        return false;
    }

    // Nested "Uno a …" subheads stay inside the current section.
    if ($this->isBikolDefinitionHeading($lower) && !$this->isBikolAllCapsHeading($line)) {
        return false;
    }

    return $this->isBikolHeadingKeywordCandidate($line, $lower);
}

/**
 * Normalize a heading or question for case-insensitive exact comparison.
 * Dataset capitalization is ignored; original display text is not changed.
 */
protected function normalizeBikolHeadingKey(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/[\s\-_\/]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * True when the user question is a normalized exact match of a dataset heading.
 */
protected function isBikolStrongHeadingMatch(string $question, string $heading): bool
{
    $questionKey = $this->normalizeBikolHeadingKey($question);
    $headingKey = $this->normalizeBikolHeadingKey($heading);
    if ($questionKey !== '' && $headingKey !== '' && $questionKey === $headingKey) {
        return true;
    }

    $questionKey = $this->normalizeBikolArticleHeadingKey($questionKey);
    $headingKey = $this->normalizeBikolArticleHeadingKey($headingKey);

    return $questionKey !== '' && $headingKey !== '' && $questionKey === $headingKey;
}

/**
 * Treat BCL articles "a" / "ana" as the same in exact heading keys.
 */
protected function normalizeBikolArticleHeadingKey(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    $key = preg_replace('/\buno(?:\s+man)?\s+ana\b/u', 'uno a', $key) ?? $key;

    return trim($key);
}

/**
 * When several already-retrieved documents share the same exact heading,
 * pick the tightest title/slug match. Null keeps retrieve order.
 *
 * @param  list<array{content?: string, source_file?: string}>  $documents
 * @return array{content: string, source_file: string}|null
 */
protected function selectBikolRetrievedDuplicateHeadingDocument(string $question, array $documents): ?array
{
    if ($this->normalizeBikolHeadingKey($question) === '' || count($documents) < 2) {
        return null;
    }

    $topicTokens = $this->bikolQuestionTopicTokens($question);
    if ($topicTokens === []) {
        return null;
    }

    $hits = [];
    foreach ($documents as $doc) {
        if (!is_array($doc)) {
            continue;
        }

        $source = (string) ($doc['source_file'] ?? '');
        $content = (string) ($doc['content'] ?? '');
        $full = $this->resolveBikolSourceFileContent($source);
        if ($full !== '') {
            $content = $full;
        }
        if ($source === '' || $content === '') {
            continue;
        }
        if ($this->extractBikolStrongHeadingSection($content, $question) === []) {
            continue;
        }

        $hits[] = [
            'content' => $content,
            'source_file' => $source,
        ];
    }

    if (count($hits) < 2) {
        return null;
    }

    $firstSource = (string) ($documents[0]['source_file'] ?? '');
    $firstIsHit = false;
    foreach ($hits as $hit) {
        if (strcasecmp((string) $hit['source_file'], $firstSource) === 0) {
            $firstIsHit = true;
            break;
        }
    }
    if (!$firstIsHit) {
        return null;
    }

    $scored = [];
    $maxOverlap = 0;
    foreach ($hits as $hit) {
        $score = $this->scoreBikolDuplicateHeadingTitleMatch(
            $topicTokens,
            $hit['content'],
            $hit['source_file']
        );
        if ($score === null) {
            continue;
        }
        $maxOverlap = max($maxOverlap, $score['overlap']);
        $scored[] = $hit + $score;
    }

    if ($maxOverlap < 1 || $scored === []) {
        return null;
    }

    $contenders = array_values(array_filter(
        $scored,
        fn ($row) => ($row['overlap'] ?? 0) === $maxOverlap
    ));
    if ($contenders === []) {
        return null;
    }

    usort($contenders, function (array $a, array $b): int {
        if ($a['leftover'] !== $b['leftover']) {
            return $a['leftover'] <=> $b['leftover'];
        }

        if ($a['ratio'] !== $b['ratio']) {
            return $b['ratio'] <=> $a['ratio'];
        }

        return 0;
    });

    $best = $contenders[0];
    $runnerUp = $contenders[1] ?? null;
    if (
        is_array($runnerUp)
        && $best['leftover'] === $runnerUp['leftover']
        && $best['ratio'] === $runnerUp['ratio']
    ) {
        return null;
    }

    return [
        'content' => (string) $best['content'],
        'source_file' => (string) $best['source_file'],
    ];
}

/**
 * Tightness of a retrieved document title/slug against the question topic.
 *
 * @param  list<string>  $topicTokens
 * @return array{overlap: int, leftover: int, ratio: float}|null
 */
protected function scoreBikolDuplicateHeadingTitleMatch(
    array $topicTokens,
    string $content,
    string $sourceFile
): ?array {
    $titleTokens = $this->extractBikolTopicTitleTokens($content, $sourceFile);
    if ($titleTokens === [] || $topicTokens === []) {
        return null;
    }

    $overlapCount = count($this->bikolHeadingTokenOverlap($topicTokens, $titleTokens));
    $titleCount = count($titleTokens);

    return [
        'overlap' => $overlapCount,
        'leftover' => $titleCount - $overlapCount,
        'ratio' => $overlapCount / $titleCount,
    ];
}

/**
 * True when a line is a structural peer heading that ends an exact-heading body.
 * Reuses isBikolPeerHeadingBoundary() but ignores loose short-prose "heading-like" lines
 * so the first body sentence is not treated as a new section.
 */
protected function isBikolExactHeadingPeerStop(string $line, string $lower): bool
{
    if (!$this->isBikolPeerHeadingBoundary($line, $lower)) {
        return false;
    }

    if (
        $this->isBikolAllCapsHeading($line)
        || $this->isBikolGenericSectionBoundary($line, $lower)
        || $this->isBikolDocumentBannerHeading($lower)
    ) {
        return true;
    }

    return preg_match(
        '/^(?:mga\b|pag[\s\-]|uno\b|ngata\b|normal\s+ba\b|tandaan\b)/u',
        $lower
    ) === 1;
}

/**
 * Collect the body under an exact/strong heading match inside one document.
 * Current-file scoped: corpus uniqueness is not required.
 *
 * @return list<string>
 */
protected function extractBikolStrongHeadingSection(string $content, string $question): array
{
    if ($this->normalizeBikolHeadingKey($question) === '') {
        return [];
    }

    $lines = preg_split('/\R+/u', $content) ?: [];
    $bestIndex = null;

    foreach ($lines as $index => $raw) {
        $line = trim((string) $raw);
        if ($line === '') {
            continue;
        }

        if (
            $this->isBikolNumberedSectionLine($line)
            || preg_match('/^\s*[-–—•*]/u', $line) === 1
        ) {
            continue;
        }

        $lower = mb_strtolower($line, 'UTF-8');
        if (
            !$this->isBikolAllCapsHeading($line)
            && !$this->isBikolGenericSectionBoundary($line, $lower)
            && !$this->isBikolHeadingKeywordCandidate($line, $lower)
        ) {
            continue;
        }

        if (!$this->isBikolStrongHeadingMatch($question, $line)) {
            continue;
        }

        $bestIndex = $index;
        break;
    }

    if ($bestIndex === null) {
        return [];
    }

    $results = [];
    $lineCount = count($lines);

    for ($i = $bestIndex + 1; $i < $lineCount; $i++) {
        $line = trim((string) $lines[$i]);
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line, 'UTF-8');
        if ($this->isBikolExactHeadingPeerStop($line, $lower)) {
            break;
        }

        $results[] = $line;
    }

    return $results;
}

/**
 * FAQ-shaped asks such as "Ngata …?" / "Normal ba …?" with several content tokens.
 */
protected function isBikolFaqStyleQuestion(string $question): bool
{
    $q = mb_strtolower(trim($question), 'UTF-8');
    if (!str_ends_with($q, '?')) {
        return false;
    }

    if (
        preg_match('/^\s*(?:ngata|normal)\b/u', $q) !== 1
        && preg_match('/\bba\b/u', $q) !== 1
    ) {
        return false;
    }

    return count($this->extractBikolHeadingMatchTokens($question)) >= 3;
}

/**
 * Corpus fallback: a BCL document whose heading exactly matches the question.
 * Used only when retrieve() returned no chunks (no current topic file).
 * Multiple files sharing the same generic heading are not chosen here.
 *
 * @return array{content: string, source_file: string}|null
 */
protected function resolveBikolDocumentByStrongHeading(string $question): ?array
{
    if ($this->normalizeBikolHeadingKey($question) === '') {
        return null;
    }

    $root = storage_path('app/health_docs/bcl');
    if (!File::isDirectory($root)) {
        return null;
    }

    $hits = [];
    foreach (File::allFiles($root) as $file) {
        if ($file->getExtension() !== 'txt') {
            continue;
        }

        $content = (string) File::get($file->getPathname());
        if ($this->extractBikolStrongHeadingSection($content, $question) === []) {
            continue;
        }

        $hits[] = [
            'content' => $content,
            'source_file' => $file->getFilename(),
        ];

        if (count($hits) > 1) {
            return null;
        }
    }

    return $hits[0] ?? null;
}

/**
 * Full on-disk BCL TXT for a retrieved source_file, if present.
 */
protected function resolveBikolSourceFileContent(string $sourceFile): string
{
    $sourceFile = trim($sourceFile);
    $root = storage_path('app/health_docs/bcl');
    if ($sourceFile === '' || !File::isDirectory($root)) {
        return '';
    }

    foreach (File::allFiles($root) as $file) {
        if (strcasecmp($file->getFilename(), $sourceFile) !== 0) {
            continue;
        }

        return trim((string) File::get($file->getPathname()));
    }

    return '';
}

/**
 * Exact-heading hit: one section body and only the file that supplied it.
 *
 * @param  list<string>  $parts
 * @param  list<string>  $sources
 * @return array{title?: string, answer: string, points: list<string>, sources: list<string>}
 */
protected function formatBikolStrongHeadingHit(string $question, array $parts, array $sources): array
{
    $bodyParts = [];
    foreach ($parts as $item) {
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }

        $lower = mb_strtolower($item, 'UTF-8');
        if ($this->isBikolDocumentBannerHeading($lower)) {
            continue;
        }

        $bodyParts[] = $item;
    }

    if ($bodyParts === []) {
        return [
            'answer' => $this->noContextMessage('bcl'),
            'points' => [],
            'sources' => [],
        ];
    }

    $formatted = $this->normalizeBikolListParts($bodyParts, 12);
    if ($formatted['answer'] === '' && $formatted['points'] === []) {
        return [
            'answer' => $this->noContextMessage('bcl'),
            'points' => [],
            'sources' => [],
        ];
    }

    return [
        'title' => $this->buildBikolResponseTitle($question),
        'answer' => $formatted['answer'],
        'points' => $formatted['points'],
        'sources' => array_values(array_unique(array_filter($sources))),
    ];
}

/**
 * Conservative heading-keyword section extract inside one document.
 *
 * @return list<string>
 */
protected function extractBikolSectionByHeadingKeywords(
    string $content,
    string $question,
    string $sourceFile = ''
): array {
    $queryTokens = $this->extractBikolHeadingMatchTokens($question);
    $sectionTokens = $this->extractBikolRemainingHeadingSectionTokens($question, $sourceFile, $content);
    if ($queryTokens === [] || $sectionTokens === []) {
        return [];
    }

    $lines = preg_split('/\R+/u', $content) ?: [];
    $bestIndex = null;
    $bestScore = 0;
    $bestCount = 0;
    $bestHeadingTokenCount = 0;
    $questionLower = mb_strtolower($question, 'UTF-8');
    $intent = $this->detectBikolIntent($question);

    foreach ($lines as $index => $raw) {
        $line = trim((string) $raw);
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line, 'UTF-8');
        if (!$this->isBikolHeadingKeywordCandidate($line, $lower)) {
            continue;
        }

        if ($this->isBikolAgeStageChildHeading($lower)) {
            continue;
        }

        if (!$this->bikolHeadingMatchesSemanticIntent($lower, $intent, $question)) {
            continue;
        }

        if ($this->isBikolFaqStyleQuestion($question) && !$this->isBikolStrongHeadingMatch($question, $line)) {
            continue;
        }

        if (
            str_ends_with($lower, '?')
            && str_contains($lower, 'ngata')
            && !str_contains($questionLower, 'ngata')
        ) {
            continue;
        }

        $headingTokens = $this->extractBikolHeadingMatchTokens($line);
        if ($headingTokens === []) {
            continue;
        }

        $sectionOverlap = $this->bikolHeadingTokenOverlap($sectionTokens, $headingTokens);
        if ($sectionOverlap === []) {
            continue;
        }

        $allOverlap = $this->bikolHeadingTokenOverlap($queryTokens, $headingTokens);
        $score = (count($sectionOverlap) * 10) + count($allOverlap);
        $headingTokenCount = count($headingTokens);

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestIndex = $index;
            $bestCount = 1;
            $bestHeadingTokenCount = $headingTokenCount;
        } elseif ($score === $bestScore && $score > 0) {
            if ($headingTokenCount > $bestHeadingTokenCount) {
                $bestIndex = $index;
                $bestCount = 1;
                $bestHeadingTokenCount = $headingTokenCount;
            } elseif ($headingTokenCount === $bestHeadingTokenCount) {
                $bestCount++;
            }
        }
    }

    if ($bestIndex === null || $bestCount !== 1) {
        return [];
    }

    $selectedLower = mb_strtolower(trim((string) $lines[$bestIndex]), 'UTF-8');
    if ($this->isBikolAgeStageContainerHeading($selectedLower)) {
        $section = $this->extractBikolAgeStageContainerSection($lines, $bestIndex);
        if ($section !== []) {
            return $section;
        }
    }
    if ($this->isBikolStageContainerHeading($selectedLower)) {
        $overview = $this->extractBikolStageContainerOverview($lines, $bestIndex);
        if ($overview !== []) {
            return $overview;
        }
    }

    $results = [];
    $lineCount = count($lines);

    for ($i = $bestIndex + 1; $i < $lineCount; $i++) {
        $line = trim((string) $lines[$i]);
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line, 'UTF-8');
        if ($this->isBikolPeerHeadingBoundary($line, $lower)) {
            break;
        }

        $results[] = $line;
    }

    return $results;
}

/**
 * Child age labels such as "EDAD 9" or "EDAD 12-13".
 */
protected function isBikolAgeStageChildHeading(string $lower): bool
{
    return preg_match('/^edad\s+\d+(?:\s*[-–—]\s*\d+)?\s*$/u', $lower) === 1;
}

/**
 * Parent age/level headings that own child EDAD buckets.
 */
protected function isBikolAgeStageContainerHeading(string $lower): bool
{
    if ($this->isBikolAgeStageChildHeading($lower)) {
        return false;
    }

    return str_contains($lower, 'edad');
}

/**
 * Child EDAD headings plus their bodies, stopping at the next peer section.
 *
 * @param  list<string>  $lines
 * @return list<string>
 */
protected function extractBikolAgeStageContainerSection(array $lines, int $containerIndex): array
{
    $results = [];
    $lineCount = count($lines);

    for ($i = $containerIndex + 1; $i < $lineCount; $i++) {
        $line = trim((string) $lines[$i]);
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line, 'UTF-8');

        if ($this->isBikolAgeStageChildHeading($lower)) {
            $results[] = preg_match('/^\s*[-–—•*]/u', $line) === 1
                ? $line
                : '- ' . $line;
            continue;
        }

        if ($this->isBikolPeerHeadingBoundary($line, $lower)) {
            break;
        }

        $results[] = $line;
    }

    return $results;
}

/**
 * Parent stage/yugto headings that introduce child age/stage buckets.
 */
protected function isBikolStageContainerHeading(string $lower): bool
{
    return str_contains($lower, 'yugto')
        || preg_match('/\bstage\b/u', $lower) === 1;
}

/**
 * Child stage headings under a yugto/stage container, without their bodies.
 *
 * @param  list<string>  $lines
 * @return list<string>
 */
protected function extractBikolStageContainerOverview(array $lines, int $containerIndex): array
{
    $results = [];
    $lineCount = count($lines);

    for ($i = $containerIndex + 1; $i < $lineCount; $i++) {
        $line = trim((string) $lines[$i]);
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line, 'UTF-8');

        if (
            !$this->isBikolStageContainerHeading($lower)
            && $this->isBikolHeadingKeywordCandidate($line, $lower)
            && $this->isBikolScheduleHeading($line, $lower)
        ) {
            $results[] = '- ' . $line;
            continue;
        }

        if ($this->isBikolPeerHeadingBoundary($line, $lower)) {
            break;
        }
    }

    return $results;
}

/**
 * EN Phase 1: keep retrieved chunks only when the source document's
 * slug/title identity (or lead-region acronym alias) matches the asked
 * health topic. Intent/section words are not topics. No Mistral call
 * when nothing survives.
 *
 * @param  list<object>  $chunks
 * @return list<object>
 */
protected function filterEnglishSupportedChunks(array $chunks, string $question): array
{
    $topics = $this->extractEnglishTopicTokens($question);
    if ($topics === []) {
        return [];
    }

    $supported = [];
    $decisionBySource = [];

    foreach ($chunks as $chunk) {
        $sourceFile = (string) ($chunk->source_file ?? '');
        if ($sourceFile === '') {
            continue;
        }

        if (!array_key_exists($sourceFile, $decisionBySource)) {
            $fullContent = trim($this->resolveHealthDocumentText($chunk));
            if ($fullContent === '') {
                $fullContent = trim((string) ($chunk->content ?? ''));
            }
            $decisionBySource[$sourceFile] = $fullContent !== ''
                && $this->englishDocumentCompatibleWithTopic(
                    $fullContent,
                    $sourceFile,
                    $topics
                );
        }

        if ($decisionBySource[$sourceFile]) {
            $supported[] = $chunk;
        }
    }

    return $supported;
}

/**
 * Health topic tokens for English identity checks.
 * Strips question/intent cue words so they cannot be the main topic.
 *
 * @return list<string>
 */
protected function extractEnglishTopicTokens(string $question): array
{
    $tokens = [];

    foreach ($this->extractKeywords($question) as $kw) {
        $kw = mb_strtolower(trim((string) $kw), 'UTF-8');
        if ($kw === '' || $this->isEnglishSupportIntentCueToken($kw)) {
            continue;
        }
        if (mb_strlen($kw, 'UTF-8') < 4) {
            continue;
        }
        $tokens[] = $kw;
    }

    foreach ($this->extractAcronymCandidates($question) as $acronym) {
        $acronym = mb_strtolower(trim((string) $acronym), 'UTF-8');
        if ($acronym === '' || $this->isEnglishSupportIntentCueToken($acronym)) {
            continue;
        }
        // Shared acronym extractor can keep short English particles (e.g. "the").
        if (in_array($acronym, $this->stopwords, true)) {
            continue;
        }
        if ($this->isEnglishFunctionWordToken($acronym)) {
            continue;
        }
        $tokens[] = $acronym;
    }

    return array_values(array_unique($tokens));
}

/**
 * Short English particles that must never act as health topics / acronyms.
 */
protected function isEnglishFunctionWordToken(string $token): bool
{
    $token = mb_strtolower(trim($token), 'UTF-8');

    return in_array($token, [
        'the', 'are', 'was', 'were', 'have', 'has', 'had', 'been',
        'from', 'with', 'your', 'their', 'this', 'that', 'these',
        'those', 'into', 'than', 'then', 'them', 'they', 'will', 'would',
        'could', 'should', 'does', 'did', 'doing', 'also', 'only',
        'just', 'very', 'much', 'many', 'some', 'such', 'each', 'both',
    ], true);
}

/**
 * Generic English question/intent words that must not identify a document.
 */
protected function isEnglishSupportIntentCueToken(string $token): bool
{
    $token = mb_strtolower(trim($token), 'UTF-8');
    if ($token === '') {
        return false;
    }

    if ($this->isWeakScheduleTopicAnchor($token) || $this->isWeakExplicitTopicAnchor($token)) {
        return true;
    }

    return in_array($token, [
        'cause', 'causes', 'caused', 'causing',
        'symptom', 'symptoms',
        'sign', 'signs',
        'treatment', 'treatments', 'treated', 'treating', 'treat',
        'benefit', 'benefits',
        'prevention', 'prevent', 'prevented', 'preventing', 'prevents',
        'manage', 'management', 'managing',
        'diagnose', 'diagnosis', 'diagnosed',
        'warning', 'warnings',
        'effect', 'effects', 'complication', 'complications',
        'risk', 'risks', 'factor', 'factors',
        'happen', 'happens', 'happening', 'occur', 'occurs', 'occurring',
        'about', 'definition', 'information', 'explain', 'describe',
    ], true);
}

/**
 * True when an English document's slug/title identity matches topic tokens,
 * or a short acronym topic is presented as an alias in the document lead.
 *
 * @param  list<string>  $topicTokens
 */
protected function englishDocumentCompatibleWithTopic(
    string $content,
    string $sourceFile,
    array $topicTokens
): bool {
    $topicTokens = array_values(array_unique(array_filter(
        $topicTokens,
        static fn ($token) => is_string($token) && trim($token) !== ''
    )));
    if ($topicTokens === []) {
        return false;
    }

    $slug = strtolower(str_replace(['.txt', '_'], ['', ' '], $sourceFile));

    foreach ($topicTokens as $token) {
        $token = mb_strtolower(trim((string) $token), 'UTF-8');
        if (mb_strlen($token, 'UTF-8') >= 4 && str_contains($slug, $token)) {
            return true;
        }
    }

    $identityTokens = $this->extractBikolTopicTitleTokens($content, $sourceFile);
    if ($this->bikolHeadingTokenOverlap($topicTokens, $identityTokens) !== []) {
        return true;
    }

    // Preserve shared acronym/alias support (e.g. ASD → autism lead).
    $lead = mb_substr($this->stripUtf8Bom($content), 0, 1200);
    $titleBlob = trim(implode(' ', $identityTokens) . ' ' . $slug);
    foreach ($topicTokens as $token) {
        $token = mb_strtolower(trim((string) $token), 'UTF-8');
        $len = mb_strlen($token, 'UTF-8');
        if ($len < 2 || $len > 5) {
            continue;
        }
        if (
            $this->contentHasAcronymAlias($lead, $token)
            || $this->contentHasAcronymAlias($titleBlob, $token)
        ) {
            return true;
        }
    }

    return false;
}

/**
 * EN Phase 2: detect the resident's requested English health intent.
 */
protected function detectEnglishSupportIntent(string $question): string
{
    $q = mb_strtolower(trim($question), 'UTF-8');

    if (preg_match('/\b(symptoms?|signs?)\b/u', $q) === 1) {
        return 'symptoms';
    }

    if (
        preg_match('/\b(causes?|caused|causing)\b/u', $q) === 1
        || preg_match('/\bwhy\b.*\b(occur|occurs|happen|happens|develop|develops)\b/u', $q) === 1
        || preg_match('/\brisk\s+factors?\b/u', $q) === 1
    ) {
        return 'causes';
    }

    if (preg_match('/\b(prevention|prevent|prevented|preventing|prevents)\b/u', $q) === 1) {
        return 'prevention';
    }

    if (preg_match('/\b(treatment|treatments|treated|treating|treat)\b/u', $q) === 1) {
        return 'treatment';
    }

    if (preg_match('/\b(benefits?)\b/u', $q) === 1) {
        return 'benefits';
    }

    if (preg_match('/^\s*what\s+(?:is|are)\b/u', $q) === 1) {
        return 'definition';
    }

    return 'general';
}

/**
 * Split an English health document into intent buckets by strict headings.
 *
 * @return array<string, string>
 */
protected function extractEnglishSectionsByIntent(string $content): array
{
    $buckets = [
        'definition' => [],
        'causes' => [],
        'symptoms' => [],
        'prevention' => [],
        'treatment' => [],
        'benefits' => [],
        'other' => [],
    ];
    $current = 'definition';
    $sawContentLine = false;
    $lines = preg_split('/\R+/u', $this->stripUtf8Bom($content)) ?: [];

    foreach ($lines as $raw) {
        $line = trim((string) $raw);
        if ($line === '') {
            continue;
        }

        $lower = mb_strtolower($line, 'UTF-8');

        // First non-empty line is the document title, never a section heading.
        if (!$sawContentLine) {
            $sawContentLine = true;
            continue;
        }

        $headingIntent = $this->matchEnglishSectionHeadingIntent($line, $lower);
        if ($headingIntent !== null) {
            $current = $headingIntent;
            // Keep same-line body after "Heading: remainder".
            if (
                preg_match('/^[^:]{1,80}:\s*(.+)$/u', $line, $m) === 1
                && trim((string) $m[1]) !== ''
            ) {
                $buckets[$current][] = trim((string) $m[1]);
            }
            continue;
        }

        $buckets[$current][] = $line;
    }

    $sections = [];
    foreach ($buckets as $intent => $linesForIntent) {
        $sections[$intent] = implode("\n", $linesForIntent);
    }

    return $sections;
}

/**
 * Strict English section headings only (avoids treating body titles as headings).
 *
 * @return string|null intent bucket name, or null when the line is not a heading
 */
protected function matchEnglishSectionHeadingIntent(string $line, string $lower): ?string
{
    if ($line === '' || preg_match('/^\s*[-–—*•\d]/u', $line) === 1) {
        return null;
    }

    if (mb_strlen($line, 'UTF-8') > 100) {
        return null;
    }

    // Allow "?" on interrogative headings like "What Causes Hypertension?"
    if (preg_match('/[.!;,]$/u', $line) === 1) {
        return null;
    }

    $lower = rtrim($lower, '?:');

    if (
        preg_match('/^(?:benefits?\b|benefits?\s+of\b)/u', $lower) === 1
        || preg_match('/\bbenefits?\s+of\b/u', $lower) === 1
    ) {
        return 'benefits';
    }

    if (
        preg_match('/^(?:what\s+)?causes?\b/u', $lower) === 1
        || preg_match('/^causes?\s+and\s+risk\b/u', $lower) === 1
        || preg_match('/^risk\s+factors?\b/u', $lower) === 1
    ) {
        return 'causes';
    }

    if (
        preg_match('/^(?:signs?\s+and\s+)?symptoms?\b/u', $lower) === 1
        || preg_match('/^symptoms?\s+of\b/u', $lower) === 1
        || preg_match('/^signs?\b/u', $lower) === 1
    ) {
        return 'symptoms';
    }

    if (
        preg_match('/^prevention(?:\s+and\s+control)?\b/u', $lower) === 1
        || preg_match('/^how\s+to\s+prevent\b/u', $lower) === 1
        || preg_match('/^prevent(?:ion)?\b/u', $lower) === 1
    ) {
        return 'prevention';
    }

    if (
        preg_match('/^treatment\b/u', $lower) === 1
        || preg_match('/^how\s+(?:is|to)\s+treat/u', $lower) === 1
    ) {
        return 'treatment';
    }

    if (
        preg_match('/^explanation\b/u', $lower) === 1
        || preg_match('/^what\s+is\b/u', $lower) === 1
    ) {
        return 'definition';
    }

    // Known boundaries that end prior medical sections without being answer buckets.
    if (preg_match(
        '/^(?:transmission|mode\s+of\s+transmission|assessment(?:\s+and\s+care)?|diagnosis|important\s+reminder|facts?\s+and\s+myths|what\s+should\s+you\s+do)\b/u',
        $lower
    ) === 1) {
        return 'other';
    }

    return null;
}

/**
 * Choose only the requested English section text. No cross-intent fallback.
 *
 * @return array{text: string, buckets: list<string>}
 */
protected function selectEnglishContextSections(string $content, string $intent): array
{
    $sections = $this->extractEnglishSectionsByIntent($content);

    // Autism-style leads describe clinical presentation under definition with no
    // "Symptoms" heading. Allow that related lead only when it clearly presents
    // characteristics — never for causes/prevention/treatment/benefits.
    if (
        $intent === 'symptoms'
        && trim((string) ($sections['symptoms'] ?? '')) === ''
        && $this->englishDefinitionReadsAsPresentation((string) ($sections['definition'] ?? ''))
    ) {
        $sections['symptoms'] = trim((string) $sections['definition']);
    }

    $keys = match ($intent) {
        'symptoms' => ['symptoms'],
        'causes' => ['causes'],
        'prevention' => ['prevention'],
        'treatment' => ['treatment'],
        'benefits' => ['benefits'],
        'definition' => ['definition'],
        'general' => ['definition'],
        default => ['definition'],
    };

    $parts = [];
    $used = [];
    foreach ($keys as $key) {
        $body = trim((string) ($sections[$key] ?? ''));
        if ($body === '') {
            continue;
        }
        $parts[] = $body;
        $used[] = $key;
    }

    return [
        'text' => trim(implode("\n\n", $parts)),
        'buckets' => $used,
    ];
}

/**
 * True when definition/lead text is clinical presentation (for symptoms-only fill).
 */
protected function englishDefinitionReadsAsPresentation(string $definition): bool
{
    $definition = trim($definition);
    if ($definition === '') {
        return false;
    }

    $lower = mb_strtolower($definition, 'UTF-8');

    return preg_match(
        '/\b(characterized by|characterised by|symptoms?\s+include|signs?\s+include|other characteristics|clinical (?:features|presentation))\b/u',
        $lower
    ) === 1;
}

/**
 * First non-empty English document title line for scoped Mistral context.
 */
protected function extractEnglishDocumentBannerLine(string $content): string
{
    $lines = preg_split('/\R+/u', $this->stripUtf8Bom($content)) ?: [];
    foreach ($lines as $raw) {
        $line = trim((string) $raw);
        if ($line !== '') {
            return $line;
        }
    }

    return '';
}

/**
 * After EN Phase 1, replace each approved chunk with intent-scoped section text.
 * Missing requested section → drop document (no definition fallback for other intents).
 *
 * @param  list<object>  $chunks
 * @return list<object>
 */
protected function scopeEnglishChunksForMistral(array $chunks, string $question): array
{
    $intent = $this->detectEnglishSupportIntent($question);
    $scoped = [];

    foreach ($chunks as $chunk) {
        $sourceFile = (string) ($chunk->source_file ?? '');
        $fullContent = trim($this->resolveHealthDocumentText($chunk));
        if ($fullContent === '') {
            $fullContent = trim((string) ($chunk->content ?? ''));
        }
        if ($sourceFile === '' || $fullContent === '') {
            continue;
        }

        $selected = $this->selectEnglishContextSections($fullContent, $intent);
        if ($selected['text'] === '') {
            continue;
        }

        $banner = $this->extractEnglishDocumentBannerLine($fullContent);
        $parts = ['SOURCE: ' . $sourceFile];
        if ($banner !== '') {
            $parts[] = $banner;
        }
        $parts[] = $selected['text'];

        $scoped[] = (object) [
            'content' => implode("\n", $parts),
            'source_file' => $sourceFile,
        ];
    }

    return $scoped;
}

/**
 * Tagalog-only support gate. Does not change retrieve scoring.
 * Keep retrieved chunks only when the FULL source document's
 * banner/title identity matches the asked topic and a section
 * actually supports the asked intent. Body-only incidental
 * mentions (e.g. "Lagnat" inside another disease file) are not enough.
 *
 * @param  list<object>  $chunks
 * @return list<object>
 */
protected function filterTagalogSupportedChunks(array $chunks, string $question): array
{
    $intent = $this->detectTagalogSupportIntent($question);
    $topics = $this->extractTagalogQuestionTopics($question);
    if ($topics['primary'] === [] && $topics['constraints'] === []) {
        return [];
    }

    $supported = [];
    $decisionBySource = [];

    foreach ($chunks as $chunk) {
        $sourceFile = (string) ($chunk->source_file ?? '');
        if ($sourceFile === '') {
            continue;
        }

        if (!array_key_exists($sourceFile, $decisionBySource)) {
            $fullContent = trim($this->resolveHealthDocumentText($chunk));
            $decisionBySource[$sourceFile] = $fullContent !== ''
                && $this->tagalogDocumentSupportsQuestion(
                    $fullContent,
                    $sourceFile,
                    $intent,
                    $topics
                );
        }

        if ($decisionBySource[$sourceFile]) {
            $supported[] = $chunk;
        }
    }

    return $supported;
}

/**
 * @return array{primary: list<string>, constraints: list<string>}
 */
protected function extractTagalogQuestionTopics(string $question): array
{
    $lower = mb_strtolower($question, 'UTF-8');
    $constraints = [];
    if (preg_match_all('/\bdahil\s+sa\s+([^?.,;]+)/u', $lower, $matches) > 0) {
        foreach ($matches[1] as $span) {
            foreach ($this->extractTagalogTopicTokens((string) $span) as $token) {
                $constraints[] = $token;
            }
        }
    }
    $constraints = array_values(array_unique($constraints));
    $primary = [];
    foreach ($this->extractTagalogTopicTokens($question) as $token) {
        if (!in_array($token, $constraints, true)) {
            $primary[] = $token;
        }
    }

    return [
        'primary' => array_values(array_unique($primary)),
        'constraints' => $constraints,
    ];
}

/**
 * @return list<string>
 */
protected function extractTagalogTopicTokens(string $text): array
{
    $tokens = [];
    foreach ($this->extractBikolHeadingMatchTokens($text) as $token) {
        if ($this->isTagalogSupportIntentCueToken((string) $token)) {
            continue;
        }
        $tokens[] = $token;
    }

    return array_values(array_unique($tokens));
}

protected function isTagalogSupportIntentCueToken(string $token): bool
{
    $token = mb_strtolower(trim($token), 'UTF-8');
    if ($token === '') {
        return false;
    }

    if ($this->isWeakScheduleTopicAnchor($token) || $this->isWeakExplicitTopicAnchor($token)) {
        return true;
    }

    return in_array($token, [
        'gamot', 'gamit', 'gamutin', 'ginagamot', 'paggamot', 'pagamot', 'gamutan', 'lunas', 'treatment',
        'sintomas', 'senyales', 'palatandaan',
        'iwas', 'iwasan', 'maiwasan', 'maiiwasan', 'pagiwas',
        'sanhi', 'dahilan', 'dahil', 'benepisyo', 'benipisyo',
        'kapag', 'dapat', 'gawin', 'gawa', 'gagawin',
        'paano', 'bakit', 'ano', 'tungkol', 'para', 'may',
        'nagkakaroon', 'nangyayari',
    ], true);
}

protected function detectTagalogSupportIntent(string $question): string
{
    $q = mb_strtolower(trim($question), 'UTF-8');

    if (preg_match('/\b(sintomas|senyales|palatandaan)\b/u', $q) === 1) {
        return 'symptoms';
    }

    if (preg_match('/mai+wasan|pag[\s\-]*iwas|\biwasan\b/u', $q) === 1) {
        return 'prevention';
    }

    // Treatment before definition so "Ano ang paggamot/gamot..." is not treated as definition.
    if (
        preg_match(
            '/paggamot|\bgamutin\b|\bginagamot\b|\bgamot\b|\bgamutan\b|\bgamit\s+sa\b|\blunas\b|\btreatment\b/u',
            $q
        ) === 1
    ) {
        return 'treatment';
    }

    // Causes before definition so "Ano ang sanhi/dahilan..." and "Bakit..." map correctly.
    if (
        preg_match('/\b(sanhi|dahilan|rason)\b/u', $q) === 1
        || preg_match('/\bbakit\b/u', $q) === 1
        || preg_match('/nagkaka[\s\-]*roon|nagkakaroon/u', $q) === 1
    ) {
        return 'causes';
    }

    // Benefits before definition so "Ano ang benepisyo..." is not definition.
    if (preg_match('/\b(benepisyo|benipisyo)\b/u', $q) === 1) {
        return 'benefits';
    }

    if (preg_match('/dapat|\bgawin\b|\bgagawin\b/u', $q) === 1) {
        return 'action';
    }

    // Inherited follow-ups like "Ano ang operasyon dito?" must not fall through
    // to definition and invent answers from the prior topic document.
    if (preg_match('/\boperasyon\b/u', $q) === 1) {
        return 'unsupported';
    }

    if (preg_match('/\bano\s+ang\b/u', $q) === 1) {
        return 'definition';
    }

    return 'general';
}

/**
 * @param  array{primary: list<string>, constraints: list<string>}  $topics
 */
protected function tagalogDocumentSupportsQuestion(
    string $content,
    string $sourceFile,
    string $intent,
    array $topics
): bool {
    $titleTokens = $this->extractBikolTopicTitleTokens($content, $sourceFile);
    $primary = $topics['primary'];
    $constraints = $topics['constraints'];

    $identityPrimary = $primary !== []
        && $this->bikolHeadingTokenOverlap($primary, $titleTokens) !== [];
    $identityConstraint = $constraints !== []
        && $this->bikolHeadingTokenOverlap($constraints, $titleTokens) !== [];

    if (!$identityPrimary && !$identityConstraint) {
        return false;
    }

    $sections = $this->extractTagalogSectionsByIntent($content);

    if ($identityPrimary) {
        return $this->tagalogIdentitySupportsIntent($intent, $sections);
    }

    return $this->tagalogConstraintSectionSupportsPrimary($intent, $sections, $primary);
}

/**
 * @param  array<string, string>  $sections
 */
protected function tagalogIdentitySupportsIntent(string $intent, array $sections): bool
{
    return match ($intent) {
        'symptoms' => trim($sections['symptoms'] ?? '') !== '',
        'prevention' => trim($sections['prevention'] ?? '') !== '',
        'causes' => trim($sections['causes'] ?? '') !== '',
        'benefits' => trim($sections['benefits'] ?? '') !== '',
        'treatment' => trim($sections['treatment'] ?? '') !== ''
            || trim($sections['action'] ?? '') !== '',
        'action' => trim($sections['action'] ?? '') !== ''
            || trim($sections['treatment'] ?? '') !== '',
        'unsupported' => false,
        default => true,
    };
}

/**
 * TL-C: choose intent-relevant section buckets from an already-approved document.
 * Reuses extractTagalogSectionsByIntent(); does not invent a second heading parser.
 *
 * @return array{text: string, buckets: list<string>}
 */
protected function selectTagalogContextSections(string $content, string $intent): array
{
    $sections = $this->extractTagalogSectionsByIntent($content);
    $keys = match ($intent) {
        'symptoms' => ['symptoms'],
        'prevention' => ['prevention'],
        'causes' => ['causes'],
        'benefits' => ['benefits'],
        'treatment' => ['treatment', 'action'],
        'action' => ['action', 'treatment'],
        'definition' => ['definition'],
        default => ['definition'],
    };

    $parts = [];
    $used = [];
    foreach ($keys as $key) {
        $body = trim((string) ($sections[$key] ?? ''));
        if ($body === '') {
            continue;
        }
        $parts[] = $body;
        $used[] = $key;
    }

    return [
        'text' => trim(implode("\n\n", $parts)),
        'buckets' => $used,
    ];
}

/**
 * First document banner line for short source identity in Mistral context.
 */
protected function extractTagalogDocumentBannerLine(string $content): string
{
    $lines = preg_split('/\R+/u', $this->stripUtf8Bom($content)) ?: [];
    foreach ($lines as $raw) {
        $line = trim((string) $raw);
        if ($line === '') {
            continue;
        }
        $lower = mb_strtolower($line, 'UTF-8');
        if ($this->isBikolDocumentBannerHeading($lower)) {
            return $line;
        }
        break;
    }

    return '';
}

/**
 * After Priority 1, replace each approved chunk with intent-scoped context only.
 * Documents with an empty requested section are dropped (no full-document fallback).
 *
 * @param  list<object>  $chunks
 * @return list<object>
 */
protected function scopeTagalogChunksForMistral(array $chunks, string $question): array
{
    $intent = $this->detectTagalogSupportIntent($question);
    $scoped = [];

    foreach ($chunks as $chunk) {
        $sourceFile = (string) ($chunk->source_file ?? '');
        $fullContent = trim($this->resolveHealthDocumentText($chunk));
        if ($fullContent === '') {
            $fullContent = trim((string) ($chunk->content ?? ''));
        }
        if ($sourceFile === '' || $fullContent === '') {
            continue;
        }

        $selected = $this->selectTagalogContextSections($fullContent, $intent);
        if ($selected['text'] === '') {
            continue;
        }

        $banner = $this->extractTagalogDocumentBannerLine($fullContent);
        $parts = ['SOURCE: ' . $sourceFile];
        if ($banner !== '') {
            $parts[] = $banner;
        }
        $parts[] = $selected['text'];

        $scoped[] = (object) [
            'content' => implode("\n\n", $parts),
            'source_file' => $sourceFile,
            'language' => $chunk->language ?? 'tl',
            'category' => $chunk->category ?? null,
            'tl_c_buckets' => $selected['buckets'],
            'tl_c_original_length' => mb_strlen($fullContent),
            'tl_c_scoped_length' => mb_strlen($selected['text']),
        ];
    }

    return $scoped;
}

/**
 * @param  array<string, string>  $sections
 * @param  list<string>  $primary
 */
protected function tagalogConstraintSectionSupportsPrimary(
    string $intent,
    array $sections,
    array $primary
): bool {
    if ($primary === []) {
        return false;
    }

    $haystack = match ($intent) {
        'symptoms' => $sections['symptoms'] ?? '',
        'prevention' => $sections['prevention'] ?? '',
        'causes' => $sections['causes'] ?? '',
        'benefits' => $sections['benefits'] ?? '',
        'treatment' => trim(($sections['treatment'] ?? '') . "\n" . ($sections['action'] ?? '')),
        'action' => trim(($sections['action'] ?? '') . "\n" . ($sections['treatment'] ?? '')),
        default => trim(($sections['definition'] ?? '') . "\n" . ($sections['other'] ?? '')),
    };

    if (trim($haystack) === '') {
        return false;
    }

    foreach ($primary as $token) {
        if ($this->tagalogTextHasWord($haystack, $token)) {
            return true;
        }
    }

    return false;
}

protected function tagalogTextHasWord(string $text, string $keyword): bool
{
    if (mb_strlen($keyword) < 4) {
        return false;
    }

    return preg_match(
        '/\b' . preg_quote($keyword, '/') . '\b/u',
        mb_strtolower($text, 'UTF-8')
    ) === 1;
}

/**
 * @return array<string, string>
 */
protected function extractTagalogSectionsByIntent(string $content): array
{
    $buckets = [
        'definition' => [],
        'causes' => [],
        'symptoms' => [],
        'benefits' => [],
        'treatment' => [],
        'prevention' => [],
        'action' => [],
        'other' => [],
    ];
    $current = 'other';
    $lines = preg_split('/\R+/u', $this->stripUtf8Bom($content)) ?: [];

    foreach ($lines as $raw) {
        $line = trim((string) $raw);
        if ($line === '') {
            continue;
        }
        $lower = mb_strtolower($line, 'UTF-8');
        if ($this->isTagalogSectionHeading($line, $lower)) {
            $current = $this->classifyTagalogSectionIntent($lower);
            continue;
        }
        $buckets[$current][] = $line;
    }

    $sections = [];
    foreach ($buckets as $intent => $linesForIntent) {
        $sections[$intent] = implode("\n", $linesForIntent);
    }

    return $sections;
}

protected function isTagalogSectionHeading(string $line, string $lower): bool
{
    if ($line === '' || preg_match('/^\s*[-–—*]/u', $line) === 1) {
        return false;
    }

    if ($this->isBikolDocumentBannerHeading($lower)) {
        return true;
    }

    if ($this->isBikolHeadingKeywordCandidate($line, $lower)) {
        return true;
    }

    // Title-like Tagalog section labels only (not prose that merely mentions
    // intent words mid-sentence).
    if (
        mb_strlen($line) <= 90
        && preg_match('/[.!;,]$/u', $line) !== 1
        && preg_match(
            '/^(?:mga\s+)?(?:paggamot|gamutan|sintomas|senyales|palatandaan|pag-?iwas|pangangalaga|sanhi|dahilan|benepisyo|benipisyo)\b/u',
            $lower
        ) === 1
    ) {
        return true;
    }

    return false;
}

protected function classifyTagalogSectionIntent(string $lower): string
{
    if ($this->isBikolDocumentBannerHeading($lower)) {
        return 'other';
    }

    if (
        preg_match('/\b(benepisyo|benipisyo)\b/u', $lower) === 1
        && mb_strlen($lower) <= 100
    ) {
        return 'benefits';
    }

    if (preg_match('/paggamot|gamutan|\bgamot\b/u', $lower) === 1 && mb_strlen($lower) <= 80) {
        return 'treatment';
    }

    if (preg_match('/pag[\s\-]*iwas|maiwasan|maiiwasan/u', $lower) === 1 && mb_strlen($lower) <= 100) {
        return 'prevention';
    }

    if (
        preg_match('/\b(sanhi|dahilan)\b/u', $lower) === 1
        && mb_strlen($lower) <= 120
    ) {
        return 'causes';
    }

    if (
        preg_match('/dapat\s+gawin|pangangalaga|pamamahala|paunang\s+dapat/u', $lower) === 1
        && mb_strlen($lower) <= 120
    ) {
        return 'action';
    }

    if (
        preg_match('/\b(sintomas|senyales|palatandaan)\b/u', $lower) === 1
        && mb_strlen($lower) <= 80
    ) {
        return 'symptoms';
    }

    if (preg_match('/^ano\s+ang\b/u', $lower) === 1 && mb_strlen($lower) <= 80) {
        return 'definition';
    }

    return 'other';
}

}