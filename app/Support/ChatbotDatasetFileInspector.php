<?php

namespace App\Support;

use finfo;
use Illuminate\Http\UploadedFile;

/**
 * SR-07 inspection of an uploaded dataset file: real content, size, encoding and type — not the name.
 *
 * The bytes are read in memory and never stored as a file. Checks:
 * - size limit (before anything else), `.txt` name, detected type is not a binary/executable family,
 *   no binary signatures, valid UTF-8 (BOM removed), no control characters, line length, minimum length;
 * - preliminary privacy screening (blocks mobile numbers, e-mail addresses, LMLinga household/member numbers);
 * - preliminary prompt-injection screening (blocks chat-template/role markers; flags instruction-like
 *   phrases, links, HTML and a language mismatch for the Linguist).
 *
 * Screening is a preliminary safeguard only, not a guarantee: the Linguist's review is the decision.
 * Content-based checks are authoritative because MIME sniffing of plain text is unreliable (some libmagic
 * versions report ordinary text starting with "PA…" as HP-GL).
 */
final class ChatbotDatasetFileInspector
{
    /** Detected types that are never accepted, whatever the file is named. */
    private const BLOCKED_MIME_PREFIXES = [
        'image/', 'audio/', 'video/', 'font/',
        'application/pdf', 'application/zip', 'application/gzip', 'application/x-gzip', 'application/x-rar',
        'application/x-7z', 'application/x-tar', 'application/x-bzip', 'application/x-dosexec',
        'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary', 'application/java',
        'application/msword', 'application/vnd.ms-', 'application/vnd.openxmlformats', 'application/vnd.oasis',
        'application/x-msi', 'application/octet-stream', 'application/x-php', 'text/x-php', 'text/html',
        'application/xhtml', 'image/svg', 'application/x-shellscript', 'text/x-shellscript',
    ];

    /** Leading byte signatures of binary or executable formats. */
    private const BINARY_SIGNATURES = [
        "%PDF", "PK\x03\x04", "PK\x05\x06", "MZ", "\x7FELF", "GIF8", "\x89PNG", "\xFF\xD8\xFF", "{\\rtf",
        "\xD0\xCF\x11\xE0", "\x1F\x8B", "Rar!", "7z\xBC\xAF", "\xCA\xFE\xBA\xBE", "<?php", "#!",
    ];

    private const BLOCKED_INJECTION_PATTERNS = [
        'chat-template marker' => '/<\|\s*[a-z_]+\s*\|>/i',
        'instruction marker' => '/\[\/?INST\]|<<\/?SYS>>/i',
        'role label at the start of a line' => '/^\s*(system|assistant|developer)\s*:/im',
    ];

    private const FLAGGED_PHRASES = [
        'ignore previous', 'ignore all previous', 'ignore the above', 'disregard the context', 'disregard previous',
        'you are now', 'act as', 'pretend to be', 'new instructions', 'system prompt', 'reveal your', 'jailbreak',
        'do anything now', 'override', 'forget your instructions',
    ];

    private const ENGLISH_MARKERS = ['the', 'and', 'is', 'are', 'of', 'to', 'with', 'for', 'your', 'you', 'this', 'that'];

    /**
     * @return array{errors: list<string>, warnings: list<string>, content: string, sha256: string, size_bytes: int, line_count: int, detected_type: string}
     */
    public function inspectUpload(UploadedFile $file): array
    {
        $maxBytes = $this->maxBytes();
        $size = (int) $file->getSize();
        if ($size > $maxBytes) {
            return $this->failed(['The file is larger than '.$this->maxKilobytes().' KB.']);
        }

        $bytes = (string) file_get_contents($file->getRealPath(), false, null, 0, $maxBytes + 1);

        return $this->inspect($bytes, $file->getClientOriginalName());
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>, content: string, sha256: string, size_bytes: int, line_count: int, detected_type: string}
     */
    public function inspect(string $bytes, ?string $originalName, ?string $language = null): array
    {
        $errors = [];
        $warnings = [];

        if (strlen($bytes) > $this->maxBytes()) {
            return $this->failed(['The file is larger than '.$this->maxKilobytes().' KB.']);
        }
        if ($bytes === '') {
            return $this->failed(['The file is empty.']);
        }
        if (strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION)) !== 'txt') {
            $errors[] = 'Only .txt files are accepted.';
        }

        $detected = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        foreach (self::BLOCKED_MIME_PREFIXES as $prefix) {
            if (str_starts_with($detected, $prefix)) {
                $errors[] = 'The file content is not plain text (detected '.$detected.').';
                break;
            }
        }
        foreach (self::BINARY_SIGNATURES as $signature) {
            if (str_starts_with($bytes, $signature)) {
                $errors[] = 'The file content is a binary, document or script format, not plain text.';
                break;
            }
        }

        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            return $this->failed(array_merge($errors, ['The file must be saved as UTF-8 (UTF-16 is not accepted).']), $detected);
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return $this->failed(array_merge($errors, ['The file must be valid UTF-8 text.']), $detected);
        }

        $content = str_replace(["\r\n", "\r"], "\n", $bytes);
        $content = trim((string) preg_replace('/[ \t]+$/m', '', $content));
        $lines = $content === '' ? [] : explode("\n", $content);

        $controlLines = $this->lineNumbers($lines, '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/');
        if ($controlLines !== []) {
            $errors[] = 'The file contains control characters (lines '.$this->list($controlLines).').';
        }
        if (mb_strlen($content, 'UTF-8') < (int) config('lmlinga.chatbot_datasets.min_characters', 200)) {
            $errors[] = 'The file must contain at least '.(int) config('lmlinga.chatbot_datasets.min_characters', 200).' characters of text.';
        }
        $maxLine = (int) config('lmlinga.chatbot_datasets.max_line_length', 5000);
        $longLines = array_keys(array_filter($lines, static fn (string $l): bool => mb_strlen($l, 'UTF-8') > $maxLine));
        if ($longLines !== []) {
            $errors[] = 'Some lines are longer than '.$maxLine.' characters (lines '.$this->list(array_map(static fn (int $i): int => $i + 1, $longLines)).').';
        }

        // Preliminary privacy screening (blocks; not a guarantee).
        foreach ([
            'mobile numbers' => '/(?<!\d)(?:\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}(?!\d)/',
            'e-mail addresses' => '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            'household or member numbers' => '/\b(?:HH|MB)-(?:L-)?[A-Z0-9]*\d[A-Z0-9]*\b/i',
        ] as $label => $pattern) {
            $found = $this->lineNumbers($lines, $pattern);
            if ($found !== []) {
                $errors[] = 'Possible personal information ('.$label.') on lines '.$this->list($found).'. Remove it before uploading.';
            }
        }

        // Preliminary prompt-injection screening.
        foreach (self::BLOCKED_INJECTION_PATTERNS as $label => $pattern) {
            $found = $this->lineNumbers($lines, $pattern);
            if ($found !== []) {
                $errors[] = 'Chatbot control text ('.$label.') on lines '.$this->list($found).' is not allowed.';
            }
        }
        $phraseLines = [];
        foreach ($lines as $i => $line) {
            $lower = mb_strtolower($line, 'UTF-8');
            foreach (self::FLAGGED_PHRASES as $phrase) {
                if (preg_match('/\b'.preg_quote($phrase, '/').'\b/u', $lower) === 1) {
                    $phraseLines[] = $i + 1;
                    break;
                }
            }
        }
        if ($phraseLines !== []) {
            $warnings[] = 'Instruction-like wording on lines '.$this->list($phraseLines).'. Check that it is health information, not instructions to the chatbot.';
        }
        foreach ([
            'Links' => '/https?:\/\/|\bwww\./i',
            'HTML tags' => '/<\/?[a-z][a-z0-9]*(?:\s[^>]*)?>/i',
            'Markdown links' => '/\[[^\]\n]+\]\([^)\n]+\)/',
        ] as $label => $pattern) {
            $found = $this->lineNumbers($lines, $pattern);
            if ($found !== []) {
                $warnings[] = $label.' on lines '.$this->list($found).'. The chatbot shows plain text only.';
            }
        }
        if ($language !== null && ($mismatch = $this->languageMismatch($content, $language)) !== null) {
            $warnings[] = $mismatch;
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'content' => $content,
            'sha256' => hash('sha256', $content),
            'size_bytes' => strlen($content),
            'line_count' => count($lines),
            'detected_type' => $detected,
        ];
    }

    /** Re-run the language check once the selected language is known. */
    public function languageMismatch(string $content, string $language): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($content, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 40) {
            return null;
        }
        $english = count(array_filter($words, static fn (string $w): bool => in_array($w, self::ENGLISH_MARKERS, true)));
        $ratio = $english / count($words);

        return match (true) {
            $language === 'en' && $ratio < 0.03 => 'The text does not look like English. Check the selected language.',
            in_array($language, ['bcl', 'tl'], true) && $ratio > 0.15 => 'The text looks mostly English. Check the selected language.',
            default => null,
        };
    }

    public function maxKilobytes(): int
    {
        return max(1, (int) config('lmlinga.chatbot_datasets.max_kilobytes', 100));
    }

    private function maxBytes(): int
    {
        return $this->maxKilobytes() * 1024;
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    private function lineNumbers(array $lines, string $pattern): array
    {
        $found = [];
        foreach ($lines as $i => $line) {
            if (preg_match($pattern, $line) === 1) {
                $found[] = $i + 1;
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $numbers
     */
    private function list(array $numbers): string
    {
        $shown = array_slice($numbers, 0, 10);

        return implode(', ', $shown).(count($numbers) > 10 ? ' and '.(count($numbers) - 10).' more' : '');
    }

    /**
     * @param  list<string>  $errors
     * @return array{errors: list<string>, warnings: list<string>, content: string, sha256: string, size_bytes: int, line_count: int, detected_type: string}
     */
    private function failed(array $errors, string $detected = ''): array
    {
        return ['errors' => $errors, 'warnings' => [], 'content' => '', 'sha256' => '', 'size_bytes' => 0, 'line_count' => 0, 'detected_type' => $detected];
    }
}
