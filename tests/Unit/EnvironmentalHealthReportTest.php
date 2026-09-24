<?php

namespace Tests\Unit;

use App\Support\EnvironmentalHealthReport;
use Tests\TestCase;

class EnvironmentalHealthReportTest extends TestCase
{
    public function test_normalize_options_defaults_and_period_modes(): void
    {
        $all = EnvironmentalHealthReport::normalizeOptions([]);
        $this->assertSame('all_zones', $all['scope']);
        $this->assertSame('all', $all['period_mode']);
        $this->assertArrayNotHasKey('include_water', $all);
        $this->assertArrayNotHasKey('full_household_list', $all);

        $month = EnvironmentalHealthReport::normalizeOptions([
            'scope' => 'zone',
            'zone' => 'Zone 3',
            'period_mode' => 'month',
            'year' => '2026',
            'month' => '3',
        ]);

        $this->assertSame('zone', $month['scope']);
        $this->assertSame('Zone 3', $month['zone']);
        $this->assertSame('month', $month['period_mode']);
        $this->assertSame('2026-03', $month['period']);
    }

    public function test_active_columns_always_include_every_section(): void
    {
        $columns = EnvironmentalHealthReport::activeColumns([]);
        $groups = array_unique(array_column($columns, 'group'));

        $this->assertContains('household', $groups);
        $this->assertContains('water', $groups);
        $this->assertContains('validation', $groups);
        $this->assertContains('sanitation', $groups);
        $this->assertContains('waste', $groups);
        $this->assertContains('summary', $groups);
        $this->assertNotContains('street', array_column($columns, 'key'));
    }

    public function test_available_periods_come_from_survey_dates(): void
    {
        $periods = EnvironmentalHealthReport::availablePeriods([
            ['date_surveyed' => '2026-01-15'],
            ['date_surveyed' => '2025-11-02'],
            ['date_surveyed' => '2026-03-20'],
            ['date_surveyed' => ''],
        ]);

        $this->assertSame(['2026', '2025'], $periods['years']);
        $this->assertSame(['01', '03'], $periods['months_by_year']['2026']);
        $this->assertSame(['11'], $periods['months_by_year']['2025']);
    }

    public function test_period_and_scope_labels(): void
    {
        $this->assertSame('All Zones', EnvironmentalHealthReport::scopeLabel([
            'scope' => 'all_zones',
        ]));
        $this->assertSame('Zone 2', EnvironmentalHealthReport::scopeLabel([
            'scope' => 'zone',
            'zone' => 'Zone 2',
        ]));
        $this->assertSame('All Time', EnvironmentalHealthReport::periodLabel([
            'period_mode' => 'all',
        ]));
        $this->assertSame('Year 2026', EnvironmentalHealthReport::periodLabel([
            'period_mode' => 'year',
            'year' => '2026',
        ]));
        $this->assertSame('March 2026', EnvironmentalHealthReport::periodLabel([
            'period_mode' => 'month',
            'year' => '2026',
            'month' => '03',
        ]));
    }

    public function test_section_tables_cover_required_topics(): void
    {
        $sections = EnvironmentalHealthReport::sectionTables();
        $keys = array_column($sections, 'key');
        $titles = array_column($sections, 'title');

        $this->assertSame(['household', 'water', 'validation', 'sanitation', 'waste', 'summary'], $keys);
        $this->assertContains('A. HOUSEHOLD INFORMATION TABLE', $titles);
        $this->assertContains('F. SUMMARY TABLE', $titles);
        $this->assertSame('HH No.', $sections[0]['columns'][0]['label']);
        $this->assertSame('Complete Sanitation Facility Status', $sections[5]['columns'][4]['label']);
    }

    public function test_rows_grouped_by_zone(): void
    {
        $grouped = EnvironmentalHealthReport::rowsGroupedByZone([
            ['zone' => 'Zone 2', 'household_no' => 'HH-2'],
            ['zone' => 'Zone 1', 'household_no' => 'HH-1'],
            ['zone' => '', 'household_no' => 'HH-0'],
        ]);

        $this->assertSame(['Unassigned Zone', 'Zone 1', 'Zone 2'], array_keys($grouped));
        $this->assertSame('HH-1', $grouped['Zone 1'][0]['household_no']);
    }
}
