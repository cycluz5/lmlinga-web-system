<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Shared letterhead for Environmental Health PDF / CSV exports.
 *
 * Order: logo → program banner → office name → year + export date.
 * CSV omits the logo and emits the text fields as leading rows.
 */
final class EnvironmentalHealthReportHeader
{
    public const OFFICE_NAME = 'La Medalla Iriga City Health Center';

    public const DEFAULT_PROGRAM_BANNER = 'ENVIRONMENTAL SANITATION AND OCCUPATIONAL HEALTH PROGRAM';

    public const LOGO_PUBLIC_PATH = 'assets/images/logo/LMLogo.png';

    public const PERIOD_ALL = 'all';

    /**
     * @param  array<string, mixed>  $input  Export query / filter bag
     * @return array{
     *     logo_data_uri: string,
     *     program_banner: string,
     *     office_name: string,
     *     report_year: string,
     *     export_date: string,
     *     export_date_iso: string,
     *     period: string
     * }
     */
    public static function make(array $input = [], ?DateTimeInterface $generatedAt = null): array
    {
        $generated = $generatedAt
            ? Carbon::parse($generatedAt)->timezone(config('app.timezone'))
            : Carbon::now(config('app.timezone'));

        $period = self::normalizePeriod($input);
        $banner = self::normalizeProgramBanner($input);

        return [
            'logo_data_uri' => self::logoDataUri(),
            'program_banner' => $banner,
            'office_name' => self::OFFICE_NAME,
            'report_year' => self::reportYearLabel($period),
            'export_date' => DisplayDate::formatDateTime($generated),
            'export_date_short' => $generated->format('m/d/Y'),
            'export_date_iso' => $generated->toIso8601String(),
            'period' => $period,
        ];
    }

    /**
     * Plain-text leading rows for CSV (logo omitted).
     *
     * @param  array<string, mixed>  $header  Result of make()
     * @return list<list<string>>
     */
    public static function csvLeadingRows(array $header): array
    {
        return [
            [(string) ($header['office_name'] ?? self::OFFICE_NAME)],
            [(string) ($header['program_banner'] ?? self::DEFAULT_PROGRAM_BANNER)],
            ['Year: '.(string) ($header['report_year'] ?? 'All Years')],
            ['Date of export: '.(string) ($header['export_date'] ?? '')],
            [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function normalizeProgramBanner(array $input): string
    {
        $raw = trim((string) ($input['program_banner'] ?? $input['banner'] ?? ''));
        if ($raw === '') {
            return self::DEFAULT_PROGRAM_BANNER;
        }

        // Keep a single line for PDF/CSV letterhead safety.
        $collapsed = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        $collapsed = trim($collapsed);

        if ($collapsed === '') {
            return self::DEFAULT_PROGRAM_BANNER;
        }

        return mb_substr($collapsed, 0, 160);
    }

    /**
     * Period filter: "all" | "YYYY" | "YYYY-MM".
     *
     * @param  array<string, mixed>  $input
     */
    public static function normalizePeriod(array $input): string
    {
        $raw = strtolower(trim((string) ($input['period'] ?? $input['report_period'] ?? '')));
        if ($raw === '' || $raw === self::PERIOD_ALL || $raw === 'all_time' || $raw === 'all-time') {
            $yearOnly = trim((string) ($input['year'] ?? ''));
            if ($yearOnly !== '' && preg_match('/^\d{4}$/', $yearOnly) === 1) {
                return $yearOnly;
            }

            return self::PERIOD_ALL;
        }

        if (preg_match('/^\d{4}$/', $raw) === 1) {
            return $raw;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $matches) === 1) {
            $month = (int) $matches[2];
            if ($month >= 1 && $month <= 12) {
                return sprintf('%04d-%02d', (int) $matches[1], $month);
            }
        }

        return self::PERIOD_ALL;
    }

    public static function reportYearLabel(string $period): string
    {
        if ($period === self::PERIOD_ALL) {
            return 'All Years';
        }

        if (preg_match('/^(\d{4})(?:-\d{2})?$/', $period, $matches) === 1) {
            return $matches[1];
        }

        return 'All Years';
    }

    /**
     * Embed the La Medalla seal as a data URI so printed/saved PDFs work offline.
     */
    public static function logoDataUri(): string
    {
        static $cached = null;
        if (is_string($cached)) {
            return $cached;
        }

        $path = public_path(self::LOGO_PUBLIC_PATH);
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            $cached = '';

            return $cached;
        }

        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            $cached = '';

            return $cached;
        }

        $mime = 'image/png';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'jpg' || $extension === 'jpeg') {
            $mime = 'image/jpeg';
        } elseif ($extension === 'webp') {
            $mime = 'image/webp';
        } elseif ($extension === 'svg') {
            $mime = 'image/svg+xml';
        }

        $cached = 'data:'.$mime.';base64,'.base64_encode($binary);

        return $cached;
    }
}
