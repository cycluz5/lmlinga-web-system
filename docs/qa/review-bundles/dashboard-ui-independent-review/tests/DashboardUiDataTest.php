<?php

namespace Tests\Unit;

use App\Support\DashboardUiData;
use PHPUnit\Framework\TestCase;

/**
 * UI DEVELOPMENT FIXTURE tests — not database aggregation.
 */
class DashboardUiDataTest extends TestCase
{
    public function test_summary_counts_expose_normalized_keys(): void
    {
        $counts = DashboardUiData::summaryCounts();

        $this->assertSame(635, $counts['totalHouseholds']);
        $this->assertSame(2103, $counts['totalResidents']);
        $this->assertSame(418, $counts['nhts']);
        $this->assertSame(217, $counts['nonNhts']);
        $this->assertSame(94, $counts['nonNhtsPoor']);
        $this->assertArrayNotHasKey('totalHealthRecords', $counts);
    }

    public function test_primary_cards_match_figma_top_summary(): void
    {
        $primary = DashboardUiData::primaryCards();

        $this->assertSame(
            ['Total Household', 'Total Residents', 'NHTS', 'Non NHTS', 'Non NHTS Poor'],
            array_column($primary, 'label')
        );
        $this->assertSame(
            ['households', 'residents', 'nhts', 'non-nhts', 'non-nhts-poor'],
            array_column($primary, 'key')
        );
        $this->assertCount(5, $primary);
        $this->assertSame(array_unique(array_column($primary, 'key')), array_column($primary, 'key'));
    }

    public function test_health_indicators_match_approved_thirteen_labels(): void
    {
        $indicators = DashboardUiData::healthIndicators();

        $this->assertSame(
            [
                'Teenage Pregnant',
                'Pregnant',
                'Lactating',
                'FP Current User',
                'FP Unmet Needs',
                'Normal Weight Children',
                'Underweight Children',
                'Overweight Children',
                'Exclusively Breastfed Infants',
                'Infants 0–11 Months',
                'HH With Large Family Size',
                'HH With Potable Water Source',
                'HH With Sanitary Toilet',
            ],
            array_column($indicators, 'label')
        );
        $this->assertCount(13, $indicators);
        $this->assertSame(array_unique(array_column($indicators, 'key')), array_column($indicators, 'key'));
        $this->assertNotContains('Senior Citizens', array_column($indicators, 'label'));
        $this->assertNotContains('Infants Given Complementary Food', array_column($indicators, 'label'));
        $this->assertSame(
            [
                'lml-pregnant',
                'lml-pregnant',
                'lml-breastfeeding',
                'lml-family',
                'lml-family-alert',
                'lml-child-normal',
                'lml-child-under',
                'lml-child-over',
                'lml-breastfeeding',
                'lml-infant',
                'lml-family',
                'lml-droplet',
                'lml-toilet',
            ],
            array_column($indicators, 'icon')
        );
    }
}
