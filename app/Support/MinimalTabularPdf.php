<?php

namespace App\Support;

use Illuminate\Http\Response;

/**
 * Lightweight PDF generator for tabular staff reports (no external PDF dependency).
 */
final class MinimalTabularPdf
{
    private const PAGE_WIDTH = 612;

    private const PAGE_HEIGHT = 792;

    private const MARGIN_X = 40;

    private const MARGIN_TOP = 760;

    private const MARGIN_BOTTOM = 40;

    private const LINE_HEIGHT = 14;

    /** @var list<array{lines: list<string>, y: float}> */
    private array $pages = [];

    /** @var list<string> */
    private array $currentLines = [];

    private float $cursorY = self::MARGIN_TOP;

    /**
     * @param  list<string>  $metaLines
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public static function render(string $title, array $metaLines, array $headers, array $rows): string
    {
        $pdf = new self;
        $pdf->addLine($title, 16, true);
        $pdf->addBlankLine();

        foreach ($metaLines as $metaLine) {
            $pdf->addLine($metaLine, 10);
        }

        $pdf->addBlankLine();
        $pdf->addLine(implode(' | ', $headers), 10, true);
        $pdf->addLine(str_repeat('-', 90), 9);

        if ($rows === []) {
            $pdf->addLine('No records match the current filters.', 10);
        } else {
            foreach ($rows as $row) {
                $pdf->addLine(implode(' | ', array_map(
                    static fn (string $cell): string => self::truncateCell($cell),
                    $row
                )), 9);
            }
        }

        $pdf->finalizePage();

        return $pdf->build();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  list<string>  $extraMeta
     */
    public static function download(
        string $title,
        string $module,
        array $headers,
        array $rows,
        string $filenameBase,
        array $extraMeta = [],
    ): Response {
        $meta = array_merge([
            'LMLinga Health Center',
            'Module: '.$module,
            'Exported: '.now()->format('Y-m-d H:i'),
        ], $extraMeta);

        $pdf = self::render($title, $meta, $headers, $rows);
        $filename = $filenameBase.'-'.now()->format('Ymd-His').'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private static function truncateCell(string $value): string
    {
        $trimmed = trim($value);

        return strlen($trimmed) > 42 ? substr($trimmed, 0, 39).'...' : $trimmed;
    }

    private function addBlankLine(): void
    {
        $this->ensureSpace(self::LINE_HEIGHT);
        $this->cursorY -= self::LINE_HEIGHT;
    }

    private function addLine(string $text, int $fontSize, bool $bold = false): void
    {
        $this->ensureSpace(self::LINE_HEIGHT + 2);
        $prefix = $bold ? 'B|' : '';
        $this->currentLines[] = sprintf(
            '%s%.0f|%.0f|%s',
            $prefix,
            (float) self::MARGIN_X,
            $this->cursorY,
            self::escapeText($text)
        );
        $this->cursorY -= self::LINE_HEIGHT;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->cursorY - $needed < self::MARGIN_BOTTOM) {
            $this->finalizePage();
            $this->currentLines = [];
            $this->cursorY = self::MARGIN_TOP;
        }
    }

    private function finalizePage(): void
    {
        if ($this->currentLines === []) {
            return;
        }

        $this->pages[] = [
            'lines' => $this->currentLines,
            'y' => self::MARGIN_TOP,
        ];
    }

    private static function escapeText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function build(): string
    {
        if ($this->pages === [] && $this->currentLines !== []) {
            $this->finalizePage();
        }

        if ($this->pages === []) {
            $this->pages[] = ['lines' => ['10|760|No data'], 'y' => self::MARGIN_TOP];
        }

        $objects = [];
        $pageObjectIds = [];
        $fontRegularId = 0;
        $fontBoldId = 0;
        $nextId = 1;

        $catalogId = $nextId++;
        $pagesId = $nextId++;
        $fontRegularId = $nextId++;
        $fontBoldId = $nextId++;

        foreach ($this->pages as $page) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            $pageObjectIds[] = $pageId;

            $stream = '';
            foreach ($page['lines'] as $line) {
                if (! preg_match('/^(B\|)?(\d+\.?\d*)\|(\d+\.?\d*)\|(.*)$/', $line, $matches)) {
                    continue;
                }

                $bold = $matches[1] !== '';
                $x = (float) $matches[2];
                $y = (float) $matches[3];
                $text = $matches[4];
                $fontSize = $bold ? 11 : 10;
                $fontRef = $bold ? $fontBoldId : $fontRegularId;

                $stream .= sprintf(
                    "BT /F%d %d Tf %.2F %.2F Td (%s) Tj ET\n",
                    $fontRef,
                    $fontSize,
                    $x,
                    $y,
                    $text
                );
            }

            $objects[$contentId] = "<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream";
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources << /Font << /F%d %d 0 R /F%d %d 0 R >> >> >>',
                $pagesId,
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentId,
                $fontRegularId,
                $fontRegularId,
                $fontBoldId,
                $fontBoldId
            );
        }

        $kids = implode(' ', array_map(static fn (int $id): string => "{$id} 0 R", $pageObjectIds));
        $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $objects[$pagesId] = "<< /Type /Pages /Kids [{$kids}] /Count ".count($pageObjectIds).' >>';
        $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        $maxId = max(array_keys($objects));

        for ($id = 1; $id <= $maxId; $id++) {
            if (! isset($objects[$id])) {
                continue;
            }

            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$objects[$id]}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 ".($maxId + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id <= $maxId; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size ".($maxId + 1)." /Root {$catalogId} 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }
}
