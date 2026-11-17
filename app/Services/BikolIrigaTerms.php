<?php

namespace App\Services;

/**
 * Central Bikol -> Bikol-Iriga term layer for Cloud AI (Gemma).
 *
 * Gemma writes Central Bikol. The words listed in resources/ml/bikol_iriga_terms.json (built from the
 * team's files by ml/bikol-cloud/build_iriga_terms.py) are swapped for the team's Iriga words.
 * Every other word is left exactly as Gemma wrote it, so the rest stays Central Bikol.
 */
class BikolIrigaTerms
{
    /** @var array<string,string>|null central/Tagalog word => Iriga word */
    private ?array $words = null;

    /** @var list<array{0:string,1:string}>|null */
    private ?array $glossary = null;

    /** @var list<array{user:string,bot:string}>|null */
    private ?array $examples = null;

    private string $healthRedirect = '';

    private ?string $pattern = null;

    public function __construct(private ?string $path = null)
    {
        $this->path ??= base_path('resources/ml/bikol_iriga_terms.json');
    }

    private function load(): void
    {
        if ($this->words !== null) {
            return;
        }

        $this->words = [];
        $this->glossary = [];
        $this->examples = [];
        $json = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
        if (!is_array($json)) {
            return;
        }

        foreach ((array) ($json['words'] ?? []) as $central => $iriga) {
            if (is_string($central) && is_string($iriga) && $central !== '' && $iriga !== '') {
                $this->words[mb_strtolower($central, 'UTF-8')] = $iriga;
            }
        }
        $this->glossary = array_values(array_filter((array) ($json['glossary'] ?? []), 'is_array'));
        $this->healthRedirect = is_string($json['health_redirect'] ?? null) ? trim($json['health_redirect']) : '';
        foreach ((array) ($json['examples'] ?? []) as $e) {
            if (is_array($e) && is_string($e['user'] ?? null) && is_string($e['bot'] ?? null)) {
                $this->examples[] = ['user' => $e['user'], 'bot' => $e['bot']];
            }
        }

        if ($this->words !== []) {
            $keys = array_keys($this->words);
            usort($keys, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $this->pattern = '/(?<![\p{L}\p{N}_\'’-])(?:'
                . implode('|', array_map(fn ($k) => preg_quote($k, '/'), $keys))
                . ')(?![\p{L}\p{N}_\'’-])/iu';
        }
    }

    /**
     * The team's reviewed chat replies (user message + bot reply), shown to Gemma as example turns
     * so its small talk follows the team's Bikol-Iriga wording.
     *
     * @return list<array{user:string,bot:string}>
     */
    public function examples(): array
    {
        $this->load();

        return $this->examples;
    }

    /** The team's reviewed reply for a health question the documents do not cover (empty when no file is loaded). */
    public function healthRedirect(): string
    {
        $this->load();

        return $this->healthRedirect;
    }

    public function count(): int
    {
        $this->load();

        return count($this->words);
    }

    /** Swap the listed Central Bikol words for Iriga, keeping capital letters. */
    public function apply(string $text): string
    {
        $this->load();
        if ($this->pattern === null || $text === '') {
            return $text;
        }

        return preg_replace_callback($this->pattern, function (array $m): string {
            $new = $this->words[mb_strtolower($m[0], 'UTF-8')] ?? $m[0];
            if (mb_strlen($m[0]) > 1 && mb_strtoupper($m[0], 'UTF-8') === $m[0]) {
                return mb_strtoupper($new, 'UTF-8');
            }
            if (mb_substr($m[0], 0, 1) !== mb_strtolower(mb_substr($m[0], 0, 1, 'UTF-8'), 'UTF-8')) {
                return mb_strtoupper(mb_substr($new, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($new, 1, null, 'UTF-8');
            }

            return $new;
        }, $text) ?? $text;
    }

    /**
     * One prompt line telling Gemma the preferred words, grouped by Iriga word:
     * "ono (bakong ano/uno), maray (bakong marhay/marahay/magayon), ...". Empty when no list is loaded.
     */
    public function glossaryPrompt(): string
    {
        $this->load();
        $groups = [];
        foreach ($this->glossary as [$central, $iriga]) {
            $groups[$iriga][] = $central;
        }
        if ($groups === []) {
            return '';
        }

        $parts = [];
        foreach ($groups as $iriga => $centrals) {
            $parts[] = $iriga . " (bakong " . implode('/', $centrals) . ')';
        }

        return 'Gamiton an Bikol-Iriga na mga sarita: ' . implode(', ', $parts) . '.';
    }
}
