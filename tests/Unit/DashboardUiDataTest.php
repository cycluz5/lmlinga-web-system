<?php

namespace Tests\Unit;

use App\Support\DashboardStatistics;
use App\Support\DashboardUiData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard presentation shape — DB-backed values via DashboardStatistics.
 */
class DashboardUiDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_expose_normalized_keys_without_fixture_literals(): void
    {
        $counts = DashboardUiData::summaryCounts();

        $this->assertSame(0, $counts['totalHouseholds']);
        $this->assertSame(0, $counts['totalResidents']);
        $this->assertSame(0, $counts['nhts']);
        $this->assertSame(0, $counts['nonNhts']);
        $this->assertArrayNotHasKey('nonNhtsPoor', $counts);
        $this->assertArrayNotHasKey('totalHealthRecords', $counts);
        $this->assertNotSame(635, $counts['totalHouseholds']);
        $this->assertNotSame(2103, $counts['totalResidents']);
    }

    public function test_primary_cards_match_figma_top_summary(): void
    {
        $primary = DashboardUiData::primaryCards();

        $this->assertSame(
            ['Total Household', 'Total Residents', 'NHTS', 'Non NHTS'],
            array_column($primary, 'label')
        );
        $this->assertSame(
            ['households', 'residents', 'nhts', 'non-nhts'],
            array_column($primary, 'key')
        );
        $this->assertCount(4, $primary);
        $this->assertSame(array_unique(array_column($primary, 'key')), array_column($primary, 'key'));
        $this->assertSame(0, $primary[3]['value']);
    }

    public function test_health_indicators_match_approved_ten_labels(): void
    {
        $indicators = DashboardUiData::healthIndicators();

        $this->assertSame(
            [
                'Teenage Pregnant',
                'Pregnant',
                'FP Current User',
                'Normal Weight Children',
                'Underweight Children',
                'Overweight Children',
                'Infants 0–11 Months',
                'HH With Large Family Size',
                'HH With Potable Water Source',
                'HH With Sanitary Toilet',
            ],
            array_column($indicators, 'label')
        );
        $this->assertCount(10, $indicators);
        $this->assertSame(array_unique(array_column($indicators, 'key')), array_column($indicators, 'key'));
        $this->assertNotContains('Lactating', array_column($indicators, 'label'));
        $this->assertNotContains('Senior Citizens', array_column($indicators, 'label'));
        $this->assertNotContains('Infants Given Complementary Food', array_column($indicators, 'label'));
        $this->assertSame(
            [
                'lml-pregnant',
                'lml-pregnant',
                'lml-family',
                'lml-child-normal',
                'lml-child-under',
                'lml-child-over',
                'lml-infant',
                'lml-family',
                'lml-droplet',
                'lml-toilet',
            ],
            array_column($indicators, 'icon')
        );
        $this->assertSame(
            [
                'maternal',
                'maternal',
                'fp',
                'nutrition',
                'attention',
                'attention',
                'infant',
                'household',
                'household',
                'household',
            ],
            array_column($indicators, 'tone')
        );
        $this->assertSame(0, $indicators[0]['value']);
        $this->assertSame(0, $indicators[1]['value']);
        $this->assertSame(0, $indicators[8]['value']);
    }
}
