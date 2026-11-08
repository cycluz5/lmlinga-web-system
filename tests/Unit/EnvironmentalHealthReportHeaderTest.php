<?php

namespace Tests\Unit;

use App\Support\EnvironmentalHealthReportHeader;
use Carbon\Carbon;
use Tests\TestCase;

class EnvironmentalHealthReportHeaderTest extends TestCase
{
    public function test_make_defaults_and_embeds_logo_data_uri(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:15:00', config('app.timezone')));

        $header = EnvironmentalHealthReportHeader::make([]);

        $this->assertSame(EnvironmentalHealthReportHeader::OFFICE_NAME, $header['office_name']);
        $this->assertSame(EnvironmentalHealthReportHeader::DEFAULT_PROGRAM_BANNER, $header['program_banner']);
        $this->assertSame('All Years', $header['report_year']);
        $this->assertSame('all', $header['period']);
        $this->assertNotSame('', $header['export_date']);
        $this->assertStringStartsWith('data:image/png;base64,', $header['logo_data_uri']);
        $this->assertGreaterThan(100, strlen($header['logo_data_uri']));
        $this->assertSame('09/16/2026', $header['export_date_short']);

        Carbon::setTestNow();
    }

    public function test_period_year_and_month_map_to_report_year_label(): void
    {
        $this->assertSame('2026', EnvironmentalHealthReportHeader::make(['period' => '2026'])['report_year']);
        $this->assertSame('2025', EnvironmentalHealthReportHeader::make(['period' => '2025-11'])['report_year']);
        $this->assertSame('All Years', EnvironmentalHealthReportHeader::make(['period' => 'all'])['report_year']);
        $this->assertSame('2024', EnvironmentalHealthReportHeader::make(['year' => '2024'])['report_year']);
    }

    public function test_csv_leading_rows_omit_logo_and_keep_text_order(): void
    {
        $header = EnvironmentalHealthReportHeader::make([
            'program_banner' => 'CUSTOM BANNER',
            'period' => '2026',
        ], Carbon::parse('2026-09-16 10:15:00', config('app.timezone')));

        $rows = EnvironmentalHealthReportHeader::csvLeadingRows($header);

        $this->assertSame(['La Medalla Iriga City Health Center'], $rows[0]);
        $this->assertSame(['CUSTOM BANNER'], $rows[1]);
        $this->assertSame(['Year: 2026'], $rows[2]);
        $this->assertStringStartsWith('Date of export:', $rows[3][0]);
        $this->assertSame([], $rows[4]);
    }
}
