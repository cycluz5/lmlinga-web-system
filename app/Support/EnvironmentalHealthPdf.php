<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Http\Response;

/**
 * Real PDF 1.4 Environmental Health report (no Blade / no browser print).
 *
 * Legal landscape · Poppins body · zone starts on a new page · one merged
 * table per zone (group header row + field header row, one row per
 * household — same layout as the Report Builder's live preview). A
 * household row is atomic: it never splits mid-row across a page break,
 * it moves to the next page whole, behind a small continuation strip
 * identifying the zone and the household the table resumes at.
 */
final class EnvironmentalHealthPdf
{
    private const PAGE_WIDTH = 1008.0;

    private const PAGE_HEIGHT = 612.0;

    private const MARGIN_X = 40.0;

    private const MARGIN_BOTTOM = 44.0;

    private const BODY_SIZE = 11;

    private const TITLE_SIZE = 14;

    private const BANNER_SIZE = 11;

    private const META_SIZE = 9;

    private const FOOTER_SIZE = 8;

    /** Continuation strip (zone + resuming household), on every page after a zone's first. */
    private const CONTINUATION_SIZE = 8;

    private const CONTINUATION_STRIP_H = 15.0;

    private const CONTINUATION_GAP = 8.0;

    /** Data table: dense, small — 23 fields have to share one landscape page width. */
    private const TABLE_BODY_SIZE = 7;

    private const TABLE_HEADER_SIZE = 7;

    private const TABLE_CELL_PAD_X = 4.0;

    private const TABLE_CELL_PAD_Y = 3.0;

    private const TABLE_LINE_GAP = 2.0;

    private const TABLE_GROUP_HEADER_H = 14.0;

    /**
     * Relative column-width weights, keyed by cardSections() field key.
     * Unlisted keys (should the field set change) fall back to 1.0.
     */
    private const TABLE_COLUMN_WEIGHTS = [
        'household_no' => 0.7,
        'house_head' => 1.6,
        'date_surveyed' => 0.9,
        'water_level' => 0.7,
        'water_source_location' => 1.3,
        'water_availability' => 1.2,
        'micro_date' => 1.0,
        'micro_result' => 1.0,
        'physico_date' => 1.0,
        'physico_result' => 1.0,
        'toilet_type' => 2.4,
        'toilet_status' => 0.9,
        'open_defecation' => 1.0,
        'shared_toilet' => 0.9,
        'sewage' => 1.6,
        'waste_segregation' => 1.0,
        'backyard_composting' => 1.1,
        'recycling_reuse' => 1.0,
        'municipal_collection' => 1.3,
        'basic_safe_water' => 1.4,
        'validation_status' => 1.2,
        'basic_sanitation' => 1.4,
        'complete_sanitation' => 1.5,
    ];

    /** Average glyph width in 1/1000 em (Poppins ~520). */
    private static float $avgGlyphWidth = 520.0;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $reportHeader
     * @param  array<string, mixed>  $options
     */
    public static function response(
        array $rows,
        array $reportHeader,
        array $options,
        DateTimeInterface $generatedAt,
        string $filenameBase,
    ): Response {
        return response(self::render($rows, $reportHeader, $options), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filenameBase.'.pdf"',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $reportHeader
     * @param  array<string, mixed>  $options
     */
    public static function render(array $rows, array $reportHeader, array $options): string
    {
        $sections = self::reportSections();
        $byZone = EnvironmentalHealthReport::rowsGroupedByZone($rows);

        $office = (string) ($reportHeader['office_name'] ?? EnvironmentalHealthReportHeader::OFFICE_NAME);
        $banner = (string) ($reportHeader['program_banner'] ?? EnvironmentalHealthReportHeader::DEFAULT_PROGRAM_BANNER);
        $exportDate = (string) ($reportHeader['export_date_short'] ?? '');
        $stats = EnvironmentalHealthDashboard::statistics($rows);
        $footer = $office.' · '.self::footerPeriodSegment($options).' · Date of export: '.$exportDate;
        $logo = self::logoImage();
        $fontRegular = self::loadTtf(public_path('assets/fonts/Poppins-Regular.ttf'));
        $fontBold = self::loadTtf(public_path('assets/fonts/Poppins-SemiBold.ttf'));
        if ($fontRegular !== null) {
            self::$avgGlyphWidth = self::averageGlyphWidth($fontRegular['widths']);
        }

        $contentWidth = self::PAGE_WIDTH - (self::MARGIN_X * 2);
        $widths = self::columnWidths($sections, $contentWidth);
        $flatFields = self::flattenFields($sections);
        $tableHeaderH = self::tableHeaderHeight($sections, $widths);
        // Tall enough that the inline stats line's underline (bannerBottom -
        // 14 - 3) clears the zone title's ascenders drawn just below this
        // header — 104/92 left them touching.
        $pageHeaderH = $logo !== null ? 120.0 : 108.0;

        $pages = [];
        $pageImages = [];
        $commands = [];
        $y = self::PAGE_HEIGHT - self::MARGIN_X;
        $hasLogo = $logo !== null;
        $hasBold = $fontBold !== null;

        $flush = function () use (&$pages, &$pageImages, &$commands, &$y, $footer, $logo): void {
            $commands[] = self::text(self::FOOTER_SIZE, self::MARGIN_X, 22, $footer, 'F1');
            $pages[] = [
                'stream' => implode("\n", $commands),
                'has_logo' => $logo !== null,
            ];
            $pageImages[] = $logo;
            $commands = [];
            $y = self::PAGE_HEIGHT - self::MARGIN_X;
        };

        // $statsForPage lets each zone's pages show that zone's own household
        // count (scope=zone naturally already narrows $rows/$stats to one
        // zone; scope=all_zones instead needs each zone's page recomputed
        // per zone rather than reusing the whole report's aggregate).
        $drawPageHeader = function (array $statsForPage) use (
            &$commands,
            &$y,
            $banner,
            $hasLogo,
            $pageHeaderH,
            $hasBold
        ): void {
            $commands = array_merge(
                $commands,
                self::pageHeaderCommands($banner, $statsForPage, $hasLogo, $hasBold)
            );
            $y = self::PAGE_HEIGHT - self::MARGIN_X - $pageHeaderH;
        };

        $drawZoneTitle = function (string $zoneName) use (&$commands, &$y, $hasBold): void {
            $label = strtoupper($zoneName).' REPORT';
            $commands[] = self::text(self::TITLE_SIZE, self::MARGIN_X, $y, $label, $hasBold ? 'F2' : 'F1');
            $y -= 8;
            $commands[] = sprintf(
                '0.24 0.65 0.33 rg %.2f %.2f %.2f 1.4 re f 0 g',
                self::MARGIN_X,
                $y,
                self::PAGE_WIDTH - (self::MARGIN_X * 2)
            );
            $y -= 18;
        };

        // Zone + resuming-household identity strip. Printed only on pages that
        // continue a zone already started on a previous page (never on a
        // page that begins a brand-new zone — drawZoneTitle covers that).
        // A household row is atomic (never split mid-row), so every
        // continuation strip is, by construction, resuming the table.
        $drawContinuationStrip = function (string $zoneName, string $hhNo, string $head) use (&$commands, &$y): void {
            $label = $zoneName.' · HH No. '.$hhNo.' · '.$head.' (continued)';
            $commands[] = self::filledBar(
                self::MARGIN_X,
                $y - self::CONTINUATION_STRIP_H + 3,
                self::PAGE_WIDTH - (self::MARGIN_X * 2),
                self::CONTINUATION_STRIP_H,
                0.94,
                0.94,
                0.94
            );
            $commands[] = self::text(
                self::CONTINUATION_SIZE,
                self::MARGIN_X + 6,
                $y - 8,
                $label,
                'F1',
                0.4,
                0.4,
                0.4
            );
            $y -= self::CONTINUATION_STRIP_H + self::CONTINUATION_GAP;
        };

        if ($rows === []) {
            $drawPageHeader($stats);
            $commands[] = self::text(self::BODY_SIZE, self::MARGIN_X, $y, 'No records found.');
            $y -= 28;
            $commands[] = self::centeredText(self::BODY_SIZE, $y, 'Nothing follows');
            $flush();

            return self::assemble($pages, $pageImages, $fontRegular, $fontBold);
        }

        $zoneIndex = 0;
        foreach ($byZone as $zoneName => $zoneRows) {
            // Every zone begins on a fresh page (no shared pages, no continuation strip here).
            if ($zoneIndex > 0) {
                $flush();
            }
            $zoneIndex++;

            $zoneStats = EnvironmentalHealthDashboard::statistics($zoneRows);
            $drawPageHeader($zoneStats);
            $drawZoneTitle((string) $zoneName);
            $commands = array_merge($commands, self::drawTableHeader($sections, $widths, self::MARGIN_X, $y, $tableHeaderH, $hasBold));
            $y -= $tableHeaderH;

            $rowIndex = 0;
            foreach ($zoneRows as $row) {
                $hhNo = HouseholdNumber::forDisplay((string) ($row['household_no'] ?? ''));
                $head = trim((string) ($row['house_head'] ?? ''));
                if ($head === '') {
                    $head = '—';
                }

                $rowH = self::measureRowHeight($flatFields, $widths, $row);

                if ($y - $rowH < self::MARGIN_BOTTOM + 8) {
                    // A row is atomic — it always moves to the next page
                    // whole rather than splitting across the break.
                    $flush();
                    $drawPageHeader($zoneStats);
                    $drawContinuationStrip((string) $zoneName, $hhNo, $head);
                    $commands = array_merge($commands, self::drawTableHeader($sections, $widths, self::MARGIN_X, $y, $tableHeaderH, $hasBold));
                    $y -= $tableHeaderH;
                    $rowIndex = 0;
                }

                $commands = array_merge($commands, self::drawTableRow($flatFields, $widths, $row, self::MARGIN_X, $y, $rowH, $rowIndex));
                $y -= $rowH;
                $rowIndex++;
            }
        }

        if ($y - 36 < self::MARGIN_BOTTOM + 8) {
            $flush();
            $drawPageHeader($stats);
        }
        $y -= 8;
        $commands[] = self::centeredText(self::BODY_SIZE, $y, 'Nothing follows');
        $flush();

        return self::assemble($pages, $pageImages, $fontRegular, $fontBold);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function footerPeriodSegment(array $options): string
    {
        $mode = (string) ($options['period_mode'] ?? EnvironmentalHealthReport::PERIOD_MODE_ALL);
        if ($mode === EnvironmentalHealthReport::PERIOD_MODE_ALL) {
            return 'Period: All Time';
        }

        $year = trim((string) ($options['year'] ?? ''));
        if ($year !== '') {
            return 'Year: '.$year;
        }

        return 'Period: '.EnvironmentalHealthReport::periodLabel($options);
    }

    /**
     * @return list<array{key: string, title: string, fields: list<array{key: string, label: string, value_key: string}>}>
     */
    private static function reportSections(): array
    {
        return EnvironmentalHealthReport::cardSections();
    }

    /**
     * @param  list<array{key: string, title: string, fields: list<array{key: string, label: string, value_key: string}>}>  $sections
     * @return list<array{key: string, label: string, value_key: string}>
     */
    private static function flattenFields(array $sections): array
    {
        $fields = [];
        foreach ($sections as $section) {
            foreach ($section['fields'] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * One width per flattened field, proportional to TABLE_COLUMN_WEIGHTS
     * and summing to exactly $contentWidth.
     *
     * @param  list<array{key: string, title: string, fields: list<array{key: string, label: string, value_key: string}>}>  $sections
     * @return list<float>
     */
    private static function columnWidths(array $sections, float $contentWidth): array
    {
        $fields = self::flattenFields($sections);
        $weights = array_map(
            static fn (array $field): float => self::TABLE_COLUMN_WEIGHTS[$field['key']] ?? 1.0,
            $fields
        );
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0.0) {
            $equal = $contentWidth / max(1, count($fields));

            return array_fill(0, count($fields), $equal);
        }

        return array_map(
            static fn (float $weight): float => $contentWidth * $weight / $totalWeight,
            $weights
        );
    }

    /**
     * Fixed header height (group row + field row) — computed once so it's
     * identical every time the header repeats across pages.
     *
     * @param  list<array{key: string, title: string, fields: list<array{key: string, label: string, value_key: string}>}>  $sections
     * @param  list<float>  $widths
     */
    private static function tableHeaderHeight(array $sections, array $widths): float
    {
        $fields = self::flattenFields($sections);
        $maxLines = 1;
        foreach ($fields as $i => $field) {
            $w = $widths[$i] - (self::TABLE_CELL_PAD_X * 2);
            $maxLines = max($maxLines, count(self::wrap($field['label'], $w, self::TABLE_HEADER_SIZE)));
        }

        return self::TABLE_GROUP_HEADER_H + self::tableCellHeight($maxLines, self::TABLE_HEADER_SIZE);
    }

    /**
     * Draws the group-header row (colspan-merged bars) and the field-label
     * row beneath it, at a fixed height — repeated at the top of every page
     * a zone's table spans.
     *
     * @param  list<array{key: string, title: string, fields: list<array{key: string, label: string, value_key: string}>}>  $sections
     * @param  list<float>  $widths
     * @return list<string>
     */
    private static function drawTableHeader(array $sections, array $widths, float $x, float $y, float $headerH, bool $hasBold): array
    {
        $commands = [];
        $fieldRowH = $headerH - self::TABLE_GROUP_HEADER_H;

        $cx = $x;
        $idx = 0;
        foreach ($sections as $section) {
            $span = count($section['fields']);
            $spanWidth = array_sum(array_slice($widths, $idx, $span));
            $commands[] = self::filledBar($cx, $y - self::TABLE_GROUP_HEADER_H, $spanWidth, self::TABLE_GROUP_HEADER_H, 0.24, 0.65, 0.33);
            $commands[] = self::rect($cx, $y - self::TABLE_GROUP_HEADER_H, $spanWidth, self::TABLE_GROUP_HEADER_H);
            $commands[] = self::text(
                self::TABLE_HEADER_SIZE,
                $cx + self::TABLE_CELL_PAD_X,
                $y - self::TABLE_GROUP_HEADER_H + 4,
                strtoupper($section['title']),
                $hasBold ? 'F2' : 'F1',
                1,
                1,
                1
            );
            $cx += $spanWidth;
            $idx += $span;
        }

        $fieldY = $y - self::TABLE_GROUP_HEADER_H;
        $cx = $x;
        foreach (self::flattenFields($sections) as $i => $field) {
            $w = $widths[$i];
            $commands[] = self::filledBar($cx, $fieldY - $fieldRowH, $w, $fieldRowH, 0.90, 0.96, 0.92);
            $commands[] = self::rect($cx, $fieldY - $fieldRowH, $w, $fieldRowH);
            $lines = self::wrap($field['label'], $w - (self::TABLE_CELL_PAD_X * 2), self::TABLE_HEADER_SIZE);
            $baseline = $fieldY - self::TABLE_CELL_PAD_Y - (self::TABLE_HEADER_SIZE * 0.8);
            $step = self::TABLE_HEADER_SIZE + self::TABLE_LINE_GAP;
            foreach ($lines as $li => $line) {
                $commands[] = self::text(
                    self::TABLE_HEADER_SIZE,
                    $cx + self::TABLE_CELL_PAD_X,
                    $baseline - ($li * $step),
                    $line,
                    $hasBold ? 'F2' : 'F1',
                    0.08,
                    0.33,
                    0.16
                );
            }
            $cx += $w;
        }

        return $commands;
    }

    /**
     * @param  list<array{key: string, label: string, value_key: string}>  $fields
     * @param  list<float>  $widths
     */
    private static function measureRowHeight(array $fields, array $widths, array $row): float
    {
        $maxLines = 1;
        foreach ($fields as $i => $field) {
            $value = self::fieldValue($row, $field['value_key']);
            $w = $widths[$i] - (self::TABLE_CELL_PAD_X * 2);
            $maxLines = max($maxLines, count(self::wrap($value, $w, self::TABLE_BODY_SIZE)));
        }

        return self::tableCellHeight($maxLines, self::TABLE_BODY_SIZE);
    }

    /**
     * @param  list<array{key: string, label: string, value_key: string}>  $fields
     * @param  list<float>  $widths
     * @return list<string>
     */
    private static function drawTableRow(array $fields, array $widths, array $row, float $x, float $y, float $rowH, int $rowIndex): array
    {
        $commands = [];
        if ($rowIndex % 2 === 1) {
            $commands[] = self::filledBar($x, $y - $rowH, array_sum($widths), $rowH, 0.97, 0.98, 0.97);
        }

        $cx = $x;
        foreach ($fields as $i => $field) {
            $w = $widths[$i];
            $value = self::fieldValue($row, $field['value_key']);
            $lines = self::wrap($value, $w - (self::TABLE_CELL_PAD_X * 2), self::TABLE_BODY_SIZE);

            $commands[] = self::rect($cx, $y - $rowH, $w, $rowH);
            $baseline = $y - self::TABLE_CELL_PAD_Y - (self::TABLE_BODY_SIZE * 0.8);
            $step = self::TABLE_BODY_SIZE + self::TABLE_LINE_GAP;
            foreach ($lines as $li => $line) {
                $commands[] = self::text(
                    self::TABLE_BODY_SIZE,
                    $cx + self::TABLE_CELL_PAD_X,
                    $baseline - ($li * $step),
                    $line
                );
            }
            $cx += $w;
        }

        return $commands;
    }

    private static function tableCellHeight(int $lines, int $fontSize): float
    {
        $lines = max(1, $lines);

        return (self::TABLE_CELL_PAD_Y * 2)
            + ($lines * $fontSize)
            + (max(0, $lines - 1) * self::TABLE_LINE_GAP);
    }

    private static function fieldValue(array $row, string $valueKey): string
    {
        $value = trim((string) ($row[$valueKey] ?? ''));

        return $value === '' ? '—' : $value;
    }

    /**
     * @param  array<string, mixed>  $stats  Scoped to whatever this page is
     *                                       reporting on — a single zone's
     *                                       rows when the report spans
     *                                       multiple zones, so each zone's
     *                                       pages show that zone's own
     *                                       counts rather than the whole
     *                                       report's aggregate.
     * @return list<string>
     */
    private static function pageHeaderCommands(
        string $banner,
        array $stats,
        bool $withLogo,
        bool $hasBold,
    ): array {
        $width = self::PAGE_WIDTH - (self::MARGIN_X * 2);
        $top = self::PAGE_HEIGHT - self::MARGIN_X;
        $textX = self::MARGIN_X + ($withLogo ? 72 : 0);
        $font = $hasBold ? 'F2' : 'F1';
        $commands = [];

        if ($withLogo) {
            $commands[] = sprintf('q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q', 56.0, 56.0, self::MARGIN_X, $top - 56);
        }

        $commands[] = self::text(self::TITLE_SIZE, $textX, $top - 20, 'La Medalla Iriga City', $font);
        $commands[] = self::text(self::TITLE_SIZE, $textX, $top - 38, 'Health Center', $font);

        // Banner sits below the logo's bottom edge (top - 56) so it never
        // paints over it — it previously started at top - 62 with height 18
        // (top edge at top - 44), overlapping the logo by 12pt.
        $bannerBottom = $top - 76;
        $commands[] = self::filledBar(self::MARGIN_X, $bannerBottom, $width, 18, 0.24, 0.65, 0.33);
        $bannerText = strtoupper($banner);
        $bannerTextW = self::approxTextWidth($bannerText, self::BANNER_SIZE);
        $bannerTextX = self::MARGIN_X + max(0.0, ($width - $bannerTextW) / 2);
        $commands[] = self::text(self::BANNER_SIZE, $bannerTextX, $bannerBottom + 5, $bannerText, $font, 1, 1, 1);

        $overview = is_array($stats['overview'] ?? null) ? $stats['overview'] : [];
        $toilet = is_array($stats['toilet_presence'] ?? null) ? $stats['toilet_presence'] : [];
        $total = (int) ($overview['total_households'] ?? 0);
        $withToilet = (int) ($toilet['with_toilet'] ?? 0);
        $withoutToilet = (int) ($toilet['without_toilet'] ?? 0);

        $statsY = $bannerBottom - 14;
        $statsX = self::MARGIN_X;
        self::drawInlineStat($commands, $statsX, $statsY, 'Household Total', $total, $hasBold);
        self::drawInlineStat($commands, $statsX, $statsY, 'With Toilet', $withToilet, $hasBold);
        self::drawInlineStat($commands, $statsX, $statsY, 'Without Toilet', $withoutToilet, $hasBold);

        return $commands;
    }

    /**
     * Draws "Label : <underlined count>" and advances $x past it, ready for
     * the next stat on the same line.
     *
     * @param  list<string>  $commands
     */
    private static function drawInlineStat(array &$commands, float &$x, float $y, string $label, int $value, bool $hasBold): void
    {
        $font = $hasBold ? 'F2' : 'F1';
        $labelText = $label.' :';
        $commands[] = self::text(self::META_SIZE, $x, $y, $labelText, $font);
        $x += self::approxTextWidth($labelText, self::META_SIZE) + 6;

        $valueText = (string) $value;
        $underlineW = max(16.0, self::approxTextWidth($valueText, self::META_SIZE) + 8);
        $valueX = $x + ($underlineW - self::approxTextWidth($valueText, self::META_SIZE)) / 2;
        $commands[] = self::text(self::META_SIZE, $valueX, $y, $valueText, $hasBold ? 'F2' : 'F1');
        $commands[] = sprintf('0.35 0.35 0.35 RG %.2f %.2f m %.2f %.2f l S 0 G', $x, $y - 3, $x + $underlineW, $y - 3);

        $x += $underlineW + 20;
    }

    private static function approxTextWidth(string $text, int $fontSize): float
    {
        return strlen($text) * (($fontSize * self::$avgGlyphWidth) / 1000);
    }

    /**
     * @return list<string>
     */
    private static function wrap(string $text, float $maxWidth, int $fontSize): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            return ['—'];
        }

        // Use Poppins average width so lines stay inside cell borders.
        $avg = max(4.0, ($fontSize * self::$avgGlyphWidth) / 1000);
        $maxChars = max(6, (int) floor(($maxWidth - 1) / $avg));
        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (strlen($candidate) <= $maxChars) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            if (strlen($word) <= $maxChars) {
                $current = $word;
            } else {
                while (strlen($word) > $maxChars) {
                    $lines[] = substr($word, 0, $maxChars);
                    $word = substr($word, $maxChars);
                }
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? ['—'] : $lines;
    }

    /**
     * @param  list<int>  $widths
     */
    private static function averageGlyphWidth(array $widths): float
    {
        $sum = 0;
        $count = 0;
        for ($code = 65; $code <= 122; $code++) {
            $w = (int) ($widths[$code] ?? 0);
            if ($w > 0) {
                $sum += $w;
                $count++;
            }
        }

        return $count > 0 ? ($sum / $count) : 520.0;
    }

    private static function filledBar(float $x, float $y, float $w, float $h, float $r, float $g, float $b): string
    {
        return sprintf('%.2f %.2f %.2f rg %.2f %.2f %.2f %.2f re f 0 g', $r, $g, $b, $x, $y, $w, $h);
    }

    private static function rect(float $x, float $y, float $w, float $h): string
    {
        return sprintf('0.55 0.55 0.55 RG %.2f %.2f %.2f %.2f re S 0 G', $x, $y, $w, $h);
    }

    private static function centeredText(int $size, float $y, string $value, string $font = 'F1'): string
    {
        $approx = strlen($value) * $size * 0.45;
        $x = max(self::MARGIN_X, (self::PAGE_WIDTH - $approx) / 2);

        return self::text($size, $x, $y, $value, $font);
    }

    private static function text(
        int $size,
        float $x,
        float $y,
        string $value,
        string $font = 'F1',
        float $r = 0,
        float $g = 0,
        float $b = 0,
    ): string {
        $color = ($r === 0.0 && $g === 0.0 && $b === 0.0)
            ? '0 g'
            : sprintf('%.2f %.2f %.2f rg', $r, $g, $b);

        return sprintf(
            '%s BT /%s %d Tf %.2f %.2f Td (%s) Tj ET 0 g',
            $color,
            $font,
            $size,
            $x,
            $y,
            self::escape($value)
        );
    }

    private static function escape(string $value): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        $safe = $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value;
        $safe = preg_replace('/[\r\n\t]+/', ' ', $safe) ?? $safe;

        return strtr($safe, [
            '\\' => '\\\\',
            '(' => '\\(',
            ')' => '\\)',
        ]);
    }

    /**
     * @return array{data: string, width: int, height: int, filter: string}|null
     */
    private static function logoImage(): ?array
    {
        static $cached = false;
        static $image = null;
        if ($cached) {
            return $image;
        }
        $cached = true;

        $path = public_path(EnvironmentalHealthReportHeader::LOGO_PUBLIC_PATH);
        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        if (function_exists('imagecreatefrompng')) {
            $gd = @imagecreatefrompng($path);
            if ($gd !== false) {
                imagepalettetotruecolor($gd);
                imagealphablending($gd, true);
                imagesavealpha($gd, false);
                $w = imagesx($gd);
                $h = imagesy($gd);
                $canvas = imagecreatetruecolor($w, $h);
                if ($canvas !== false) {
                    $white = imagecolorallocate($canvas, 255, 255, 255);
                    imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
                    imagecopy($canvas, $gd, 0, 0, 0, 0, $w, $h);
                    ob_start();
                    imagejpeg($canvas, null, 85);
                    $jpeg = ob_get_clean();
                    imagedestroy($gd);
                    imagedestroy($canvas);
                    if (is_string($jpeg) && $jpeg !== '') {
                        $image = [
                            'data' => $jpeg,
                            'width' => $w,
                            'height' => $h,
                            'filter' => 'DCTDecode',
                        ];

                        return $image;
                    }
                }
                imagedestroy($gd);
            }
        }

        $png = self::decodePngToRgb($path);
        if ($png === null) {
            return null;
        }

        $compressed = gzcompress($png['rgb'], 9);
        if ($compressed === false) {
            return null;
        }

        $image = [
            'data' => $compressed,
            'width' => $png['width'],
            'height' => $png['height'],
            'filter' => 'FlateDecode',
        ];

        return $image;
    }

    /**
     * Minimal PNG decoder for 8-bit RGB/RGBA logos (no GD required).
     *
     * @return array{width: int, height: int, rgb: string}|null
     */
    private static function decodePngToRgb(string $path): ?array
    {
        $binary = @file_get_contents($path);
        if (! is_string($binary) || strlen($binary) < 57 || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        $offset = 8;
        $width = 0;
        $height = 0;
        $bitDepth = 0;
        $colorType = -1;
        $idat = '';
        $length = strlen($binary);

        while ($offset + 8 <= $length) {
            $chunkLen = self::u32($binary, $offset);
            $type = substr($binary, $offset + 4, 4);
            $dataStart = $offset + 8;
            $data = substr($binary, $dataStart, $chunkLen);
            if ($type === 'IHDR' && $chunkLen >= 13) {
                $width = self::u32($data, 0);
                $height = self::u32($data, 4);
                $bitDepth = ord($data[8]);
                $colorType = ord($data[9]);
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
            $offset = $dataStart + $chunkLen + 4;
        }

        if ($width < 1 || $height < 1 || $bitDepth !== 8 || ! in_array($colorType, [2, 6], true) || $idat === '') {
            return null;
        }

        $raw = @zlib_decode($idat);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $channels = $colorType === 6 ? 4 : 3;
        $stride = $width * $channels;
        $rgb = '';
        $prev = str_repeat("\0", $stride);
        $pos = 0;

        for ($row = 0; $row < $height; $row++) {
            if ($pos >= strlen($raw)) {
                return null;
            }
            $filter = ord($raw[$pos]);
            $pos++;
            $scan = substr($raw, $pos, $stride);
            if (strlen($scan) < $stride) {
                return null;
            }
            $pos += $stride;
            $recon = self::paethScanline($filter, $scan, $prev, $channels);
            $prev = $recon;

            for ($i = 0; $i < $width; $i++) {
                $o = $i * $channels;
                $r = ord($recon[$o]);
                $g = ord($recon[$o + 1]);
                $b = ord($recon[$o + 2]);
                if ($channels === 4) {
                    $a = ord($recon[$o + 3]) / 255;
                    $r = (int) round($r * $a + 255 * (1 - $a));
                    $g = (int) round($g * $a + 255 * (1 - $a));
                    $b = (int) round($b * $a + 255 * (1 - $a));
                }
                $rgb .= chr($r).chr($g).chr($b);
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'rgb' => $rgb,
        ];
    }

    private static function paethScanline(int $filter, string $scan, string $prev, int $channels): string
    {
        $len = strlen($scan);
        $out = $scan;
        for ($i = 0; $i < $len; $i++) {
            $x = ord($scan[$i]);
            $a = $i >= $channels ? ord($out[$i - $channels]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $channels ? ord($prev[$i - $channels]) : 0;
            $val = match ($filter) {
                1 => ($x + $a) & 0xFF,
                2 => ($x + $b) & 0xFF,
                3 => ($x + intdiv($a + $b, 2)) & 0xFF,
                4 => ($x + self::paethPredictor($a, $b, $c)) & 0xFF,
                default => $x,
            };
            $out[$i] = chr($val);
        }

        return $out;
    }

    private static function paethPredictor(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }

        return $c;
    }

    /**
     * @return array{data: string, widths: list<int>, ascent: int, descent: int, bbox: array{0: int, 1: int, 2: int, 3: int}}|null
     */
    private static function loadTtf(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        if (! is_string($data) || strlen($data) < 100) {
            return null;
        }

        $tables = self::ttfTables($data);
        if (! isset($tables['head'], $tables['hhea'], $tables['hmtx'], $tables['cmap'])) {
            return null;
        }

        $unitsPerEm = self::u16($data, $tables['head']['offset'] + 18);
        if ($unitsPerEm < 1) {
            return null;
        }

        $ascent = self::i16($data, $tables['hhea']['offset'] + 4);
        $descent = self::i16($data, $tables['hhea']['offset'] + 6);
        $numberOfHMetrics = self::u16($data, $tables['hhea']['offset'] + 34);
        $cmap = self::ttfCmapUnicode($data, $tables['cmap']);
        if ($cmap === null) {
            return null;
        }

        $widths = array_fill(0, 256, 0);
        $hmtx = $tables['hmtx']['offset'];
        for ($code = 32; $code <= 255; $code++) {
            $glyph = $cmap[$code] ?? 0;
            if ($glyph < $numberOfHMetrics) {
                $advance = self::u16($data, $hmtx + ($glyph * 4));
            } else {
                $advance = self::u16($data, $hmtx + (($numberOfHMetrics - 1) * 4));
            }
            $widths[$code] = (int) round(($advance * 1000) / $unitsPerEm);
        }
        $widths[32] = $widths[32] ?: 250;

        return [
            'data' => $data,
            'widths' => $widths,
            'ascent' => (int) round(($ascent * 1000) / $unitsPerEm),
            'descent' => (int) round(($descent * 1000) / $unitsPerEm),
            'bbox' => [
                (int) round((self::i16($data, $tables['head']['offset'] + 36) * 1000) / $unitsPerEm),
                (int) round((self::i16($data, $tables['head']['offset'] + 38) * 1000) / $unitsPerEm),
                (int) round((self::i16($data, $tables['head']['offset'] + 40) * 1000) / $unitsPerEm),
                (int) round((self::i16($data, $tables['head']['offset'] + 42) * 1000) / $unitsPerEm),
            ],
        ];
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    private static function ttfTables(string $data): array
    {
        $numTables = self::u16($data, 4);
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $o = 12 + ($i * 16);
            $tag = substr($data, $o, 4);
            $tables[$tag] = [
                'offset' => self::u32($data, $o + 8),
                'length' => self::u32($data, $o + 12),
            ];
        }

        return $tables;
    }

    /**
     * @param  array{offset: int, length: int}  $cmapTable
     * @return array<int, int>|null
     */
    private static function ttfCmapUnicode(string $data, array $cmapTable): ?array
    {
        $base = $cmapTable['offset'];
        $numTables = self::u16($data, $base + 2);
        $format4Offset = null;
        for ($i = 0; $i < $numTables; $i++) {
            $rec = $base + 4 + ($i * 8);
            $platform = self::u16($data, $rec);
            $encoding = self::u16($data, $rec + 2);
            $offset = self::u32($data, $rec + 4);
            if (($platform === 3 && $encoding === 1) || ($platform === 0)) {
                $format4Offset = $base + $offset;
                break;
            }
        }
        if ($format4Offset === null || self::u16($data, $format4Offset) !== 4) {
            return null;
        }

        $segCount = intdiv(self::u16($data, $format4Offset + 6), 2);
        $endCount = $format4Offset + 14;
        $startCount = $endCount + (2 * $segCount) + 2;
        $idDelta = $startCount + (2 * $segCount);
        $idRangeOffset = $idDelta + (2 * $segCount);
        $map = [];

        for ($code = 32; $code <= 255; $code++) {
            for ($seg = 0; $seg < $segCount; $seg++) {
                $end = self::u16($data, $endCount + (2 * $seg));
                $start = self::u16($data, $startCount + (2 * $seg));
                if ($code < $start || $code > $end) {
                    continue;
                }
                $rangeOffset = self::u16($data, $idRangeOffset + (2 * $seg));
                $delta = self::i16($data, $idDelta + (2 * $seg));
                if ($rangeOffset === 0) {
                    $glyph = ($code + $delta) & 0xFFFF;
                } else {
                    $glyphPos = $idRangeOffset + (2 * $seg) + $rangeOffset + (2 * ($code - $start));
                    $glyphIndex = self::u16($data, $glyphPos);
                    $glyph = $glyphIndex === 0 ? 0 : (($glyphIndex + $delta) & 0xFFFF);
                }
                $map[$code] = $glyph;
                break;
            }
        }

        return $map;
    }

    private static function u16(string $data, int $offset): int
    {
        $chunk = substr($data, $offset, 2);

        return strlen($chunk) < 2 ? 0 : unpack('n', $chunk)[1];
    }

    private static function i16(string $data, int $offset): int
    {
        $value = self::u16($data, $offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private static function u32(string $data, int $offset): int
    {
        $chunk = substr($data, $offset, 4);

        return strlen($chunk) < 4 ? 0 : unpack('N', $chunk)[1];
    }

    /**
     * @param  list<array{stream: string, has_logo: bool}>  $pages
     * @param  list<array{data: string, width: int, height: int, filter: string}|null>  $pageImages
     * @param  array{data: string, widths: list<int>, ascent: int, descent: int, bbox: array{0: int, 1: int, 2: int, 3: int}}|null  $fontRegular
     * @param  array{data: string, widths: list<int>, ascent: int, descent: int, bbox: array{0: int, 1: int, 2: int, 3: int}}|null  $fontBold
     */
    private static function assemble(
        array $pages,
        array $pageImages = [],
        ?array $fontRegular = null,
        ?array $fontBold = null,
    ): string {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $nextId = 3;

        if ($fontRegular !== null) {
            [$objects, $nextId, $fontRegularId] = self::embedTrueTypeFont($objects, $nextId, $fontRegular, 'Poppins');
        } else {
            $fontRegularId = $nextId++;
            $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        }

        if ($fontBold !== null) {
            [$objects, $nextId, $fontBoldId] = self::embedTrueTypeFont($objects, $nextId, $fontBold, 'PoppinsSemiBold');
        } else {
            $fontBoldId = $nextId++;
            $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        }

        $sharedLogoId = null;
        foreach ($pageImages as $img) {
            if (! is_array($img) || ($img['data'] ?? '') === '') {
                continue;
            }
            $sharedLogoId = $nextId++;
            $w = (int) ($img['width'] ?? 56);
            $h = (int) ($img['height'] ?? 56);
            $filter = (string) ($img['filter'] ?? 'FlateDecode');
            $data = (string) $img['data'];
            $objects[$sharedLogoId] = '<< /Type /XObject /Subtype /Image /Width '.$w
                .' /Height '.$h.' /ColorSpace /DeviceRGB /BitsPerComponent 8'
                .' /Filter /'.$filter.' /Length '.strlen($data)
                ." >>\nstream\n".$data."\nendstream";
            break;
        }

        $pageIds = [];
        foreach ($pages as $page) {
            $stream = is_array($page) ? (string) ($page['stream'] ?? '') : (string) $page;
            $hasLogo = is_array($page) && ! empty($page['has_logo']) && $sharedLogoId !== null;

            $contentId = $nextId++;
            $pageId = $nextId++;
            $pageIds[] = $pageId;
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";

            $resources = '/Font << /F1 '.$fontRegularId.' 0 R /F2 '.$fontBoldId.' 0 R >>';
            if ($hasLogo) {
                $resources .= ' /XObject << /Im1 '.$sharedLogoId.' 0 R >>';
            }

            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << %s >> >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentId,
                $resources
            );
        }

        $kids = implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $pageIds));
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', $kids, count($pageIds));

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= sprintf("xref\n0 %d\n", $maxId + 1);
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= "trailer\n<< /Size ".($maxId + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    /**
     * @param  array<int, string>  $objects
     * @param  array{data: string, widths: list<int>, ascent: int, descent: int, bbox: array{0: int, 1: int, 2: int, 3: int}}  $font
     * @return array{0: array<int, string>, 1: int, 2: int}
     */
    private static function embedTrueTypeFont(array $objects, int $nextId, array $font, string $baseFont): array
    {
        $fileId = $nextId++;
        $descriptorId = $nextId++;
        $fontId = $nextId++;

        $fontData = $font['data'];
        $objects[$fileId] = '<< /Length '.strlen($fontData).' /Length1 '.strlen($fontData)
            ." >>\nstream\n".$fontData."\nendstream";

        $bbox = $font['bbox'];
        $objects[$descriptorId] = sprintf(
            '<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $baseFont,
            $bbox[0],
            $bbox[1],
            $bbox[2],
            $bbox[3],
            $font['ascent'],
            $font['descent'],
            max(600, $font['ascent'] - 100),
            $fileId
        );

        $widthParts = [];
        for ($code = 32; $code <= 255; $code++) {
            $widthParts[] = (string) ($font['widths'][$code] ?? 500);
        }
        $objects[$fontId] = sprintf(
            '<< /Type /Font /Subtype /TrueType /BaseFont /%s /FirstChar 32 /LastChar 255 /Widths [%s] /FontDescriptor %d 0 R /Encoding /WinAnsiEncoding >>',
            $baseFont,
            implode(' ', $widthParts),
            $descriptorId
        );

        return [$objects, $nextId, $fontId];
    }
}
