<?php

namespace Tests\Feature;

use App\Models\DewormingRecord;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

class ChildNutritionDewormingMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        PersistCatalogHousehold::persist('HH-151');

        $infant = \App\Models\Resident::query()
            ->where('member_no', 'MB-009')
            ->whereHas('household', static fn ($q) => $q->where('household_no', 'HH-151'))
            ->firstOrFail();

        DewormingRecord::factory()->create([
            'resident_id' => $infant->id,
            'year' => 2026,
            'round' => 1,
            'se_status' => 'NHTS',
            'date_given' => '2026-07-01',
            'remarks' => 'No concerns reported',
        ]);
    }

    public function test_child_nutrition_displays_deworming_section_and_existing_records(): void
    {
        $html = $this->get(route('household-profiling.members.child-nutrition', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-child-nut-deworming', $html);
        $this->assertStringContainsString('>Deworming</', $html);
        $this->assertStringContainsString('data-child-nut-deworming-table', $html);
        $this->assertSame(1, substr_count($html, 'data-child-nut-deworming-row'));
        $this->assertStringContainsString('07/01/2026', $html);
        $this->assertStringContainsString('NHTS', $html);
        $this->assertStringContainsString('data-child-nut-deworming-form', $html);
        $this->assertStringContainsString('Add Deworming Record', $html);
        $this->assertStringContainsString('data-lml-child-nut', $html);

        // Add form starts collapsed behind the toggle button.
        $this->assertStringContainsString('data-child-nut-deworming-toggle', $html);
        $this->assertMatchesRegularExpression(
            '/data-child-nut-deworming-panel[^>]*\bhidden\b/s',
            $html
        );
    }

    public function test_deworming_can_be_entered_from_child_nutrition_and_does_not_duplicate(): void
    {
        $infant = \App\Models\Resident::query()
            ->where('member_no', 'MB-009')
            ->whereHas('household', static fn ($q) => $q->where('household_no', 'HH-151'))
            ->firstOrFail();

        $this->assertSame(1, DewormingRecord::query()->where('resident_id', $infant->id)->count());

        $this->post(route('household-profiling.members.deworming.store', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]), [
            'year' => 2026,
            'round' => '2',
            'se_status' => 'Non-NHTS',
            'date_given' => '2026-08-15',
            'remarks' => 'Second round',
        ])->assertRedirect(route('household-profiling.members.child-nutrition', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]));

        $this->assertSame(2, DewormingRecord::query()->where('resident_id', $infant->id)->count());
        $this->assertDatabaseHas('deworming_records', [
            'resident_id' => $infant->id,
            'year' => 2026,
            'round' => 2,
            'se_status' => 'Non-NHTS',
        ]);
        $this->assertSame(
            '2026-08-15',
            DewormingRecord::query()
                ->where('resident_id', $infant->id)
                ->where('year', 2026)
                ->where('round', 2)
                ->firstOrFail()
                ->date_given
                ->toDateString()
        );

        $html = $this->get(route('household-profiling.members.child-nutrition', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]))->assertOk()->getContent();

        // Embedded summary shows only the latest round — full history stays
        // on the dedicated Deworming page, not duplicated here.
        $this->assertStringContainsString('08/15/2026', $html);
        $this->assertStringContainsString('Second round', $html);
        $this->assertStringNotContainsString('07/01/2026', $html);
        $this->assertSame(1, substr_count($html, 'data-child-nut-deworming-row'));
    }

    public function test_nutrition_page_remains_intact_when_deworming_is_shown(): void
    {
        $html = $this->get(route('household-profiling.members.child-nutrition', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Child Nutrition Status', $html);
        $this->assertStringContainsString('data-child-nut-status-overall', $html);
        $this->assertStringContainsString('Latest Assessment', $html);
        $this->assertStringContainsString('data-lml-child-nut-deworming', $html);
    }

    public function test_member_view_no_longer_lists_deworming_as_a_child_care_sibling(): void
    {
        foreach (['MB-009', 'MB-001', 'MB-003'] as $memberId) {
            $html = $this->get(route('household-profiling.members.show', [
                'householdNo' => 'HH-151',
                'memberId' => $memberId,
            ]))->assertOk()->getContent();

            $this->assertSame(0, substr_count($html, '>Deworming</'));
            $this->assertStringContainsString('Child Nutrition', $html);
            $this->assertStringContainsString(
                'href="'.e(route('household-profiling.members.child-nutrition', [
                    'householdNo' => 'HH-151',
                    'memberId' => $memberId,
                ])).'"',
                $html
            );
        }
    }
}
