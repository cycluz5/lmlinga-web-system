<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Demo Death Information Phase 1 helpers.
 *
 * Session-backed preview state only (no database / migrations / permanent storage).
 * One Death Information record is stored per household + member.
 */
final class DemoDeath
{
    public const SESSION_KEY = 'lml.demo.death.v1';

    public const ACCEPTED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'pdf'];

    public const ACCEPTED_MIMES = [
        'image/png',
        'image/jpeg',
        'application/pdf',
    ];

    /**
     * @return array{household: array<string, mixed>|null, member: array<string, mixed>|null, householdNo: string, memberId: string}
     */
    public static function resolveMember(string $householdNo, string $memberId): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $household = DemoCatalog::findHousehold($hh);
        $member = $household ? lml_demo_find_member($household, $mb) : null;

        return [
            'household' => $household,
            'member' => $member,
            'householdNo' => $hh,
            'memberId' => $mb,
        ];
    }

    public static function hasRecord(string $householdNo, string $memberId): bool
    {
        return self::record($householdNo, $memberId) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function record(string $householdNo, string $memberId): ?array
    {
        $state = self::memberState($householdNo, $memberId);
        $record = $state['record'] ?? null;

        return is_array($record) ? self::normalizeRecord($record) : null;
    }

    /**
     * Upsert the single Death Information preview record for this member.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function save(
        string $householdNo,
        string $memberId,
        array $payload,
        ?UploadedFile $certificate = null
    ): array {
        $existing = self::record($householdNo, $memberId) ?? [];
        $certificateMeta = is_array($existing['certificate'] ?? null)
            ? $existing['certificate']
            : null;

        if ($certificate instanceof UploadedFile && $certificate->isValid()) {
            $certificateMeta = self::certificateMetaFromUpload($certificate);
        }

        $record = self::normalizeRecord([
            'cause_of_death' => $payload['cause_of_death'] ?? '',
            'date_of_death' => $payload['date_of_death'] ?? '',
            'certificate' => $certificateMeta,
            'updated_at' => now()->toIso8601String(),
        ]);

        self::putMemberState($householdNo, $memberId, ['record' => $record]);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function normalizeRecord(array $record): array
    {
        $cause = trim((string) ($record['cause_of_death'] ?? ''));
        $cause = strip_tags($cause);
        if (mb_strlen($cause) > 500) {
            $cause = mb_substr($cause, 0, 500);
        }

        $date = self::sanitizeDate($record['date_of_death'] ?? null);
        $certificate = self::normalizeCertificateMeta($record['certificate'] ?? null);

        return [
            'cause_of_death' => $cause,
            'date_of_death' => $date,
            'certificate' => $certificate,
            'updated_at' => (string) ($record['updated_at'] ?? ''),
        ];
    }

    /**
     * Safe display-only filename metadata. Never stores a local filesystem path.
     *
     * @return array{original_name: string, mime: string, size: int, extension: string}|null
     */
    public static function certificateMetaFromUpload(UploadedFile $file): ?array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) ($file->getClientMimeType() ?: $file->getMimeType() ?: ''));

        if ($extension === '' || ! in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
            return null;
        }

        if ($mime !== '' && ! in_array($mime, self::ACCEPTED_MIMES, true)) {
            // Some browsers omit/alter MIME; allow by extension when MIME is empty or unknown.
            if (! in_array($mime, ['application/octet-stream', ''], true)
                && ! str_starts_with($mime, 'image/')
                && $mime !== 'application/pdf'
            ) {
                return null;
            }
        }

        $original = self::safeFilename((string) $file->getClientOriginalName());
        if ($original === '') {
            $original = 'death-certificate.'.$extension;
        }

        return [
            'original_name' => $original,
            'mime' => $mime !== '' ? $mime : (self::mimeForExtension($extension) ?? 'application/octet-stream'),
            'size' => (int) $file->getSize(),
            'extension' => $extension,
        ];
    }

    public static function safeFilename(string $name): string
    {
        $name = str_replace(["\0", "\r", "\n"], '', $name);
        $name = str_replace(['\\', '/'], '', $name);
        $name = basename($name);
        $name = trim($name);
        if (mb_strlen($name) > 120) {
            $name = mb_substr($name, 0, 120);
        }

        return $name;
    }

    public static function mimeForPublicExtension(string $extension): ?string
    {
        return self::mimeForExtension($extension);
    }

    public static function formatDateForDisplay(?string $date): string
    {
        $date = self::sanitizeDate($date);
        if ($date === '') {
            return '—';
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y-m-d', $date)->format('F j, Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    private static function sanitizeDate(mixed $value): string
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return '';
        }

        try {
            $parsed = \Carbon\Carbon::createFromFormat('Y-m-d', $raw);

            return $parsed && $parsed->format('Y-m-d') === $raw ? $raw : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array{original_name: string, mime: string, size: int, extension: string}|null
     */
    private static function normalizeCertificateMeta(mixed $meta): ?array
    {
        if (! is_array($meta)) {
            return null;
        }

        $name = self::safeFilename((string) ($meta['original_name'] ?? ''));
        $extension = strtolower((string) ($meta['extension'] ?? pathinfo($name, PATHINFO_EXTENSION)));
        if ($name === '' || ! in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
            return null;
        }

        // Drop any accidental path-like keys from session payloads.
        return [
            'original_name' => $name,
            'mime' => (string) ($meta['mime'] ?? self::mimeForExtension($extension) ?? 'application/octet-stream'),
            'size' => max(0, (int) ($meta['size'] ?? 0)),
            'extension' => $extension,
        ];
    }

    private static function mimeForExtension(string $extension): ?string
    {
        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'pdf' => 'application/pdf',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function memberState(string $householdNo, string $memberId): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $all = session(self::SESSION_KEY, []);
        if (! is_array($all)) {
            $all = [];
        }
        $state = $all[$hh][$mb] ?? null;

        return is_array($state) ? $state : ['record' => null];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function putMemberState(string $householdNo, string $memberId, array $state): void
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $all = session(self::SESSION_KEY, []);
        if (! is_array($all)) {
            $all = [];
        }
        if (! isset($all[$hh]) || ! is_array($all[$hh])) {
            $all[$hh] = [];
        }
        $all[$hh][$mb] = $state;
        session([self::SESSION_KEY => $all]);
    }
}
