<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Isolated DB View Household render regression.
 *
 * Runs in a separate process so lml_demo_* helpers are not accidentally
 * defined by an earlier DemoCatalog load in the same PHPUnit process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class HouseholdProfilingDbViewRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(\App\Support\StaffRole::BHW);
    }

    public function test_db_backed_household_view_renders_member_occupation_without_undefined_helper(): void
    {
        $this->assertFalse(
            function_exists('lml_demo_member_display'),
            'Precondition: helper must not be preloaded before this isolated DB view request.'
        );

        $household = Household::factory()->create([
            'household_no' => 'HH-900',
            'zone' => 'Zone 1',
            'street' => 'Acceptance St.',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-014',
            'last_name' => 'Labubu',
            'first_name' => 'Juan',
            'middle_name' => 'Carlos',
            'relation' => 'Head',
            'birthday' => '1990-01-01',
            'sex' => 'Male',
            'occupation' => 'Teacher',
        ]);

        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-900']));

        $response->assertOk();
        $response->assertDontSee('lml_demo_member_display', false);
        $response->assertSee('Juan Carlos Labubu', false);
        $response->assertSee('Head', false);
        $response->assertSee('Male', false);
        $response->assertSee('Teacher', false);
        $this->assertStringContainsString('data-source="db"', $response->getContent());
    }

    public function test_unknown_household_does_not_fall_back_to_demo_catalog(): void
    {
        $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->assertSee('Household not found', false)
            ->assertDontSee('Kristine Reyes', false)
            ->assertDontSee('data-source="demo"', false);
    }

    public function test_db_backed_member_show_does_not_claim_demo_preview_unsaved(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-900',
            'zone' => 'Zone 1',
            'street' => 'Test Street',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-014',
            'last_name' => 'Labubu',
            'first_name' => 'Juan',
            'middle_name' => 'Carlos',
            'relation' => 'Head',
            'birthday' => '1990-01-01',
            'sex' => 'Male',
            'relationship_status' => 'Single',
            'occupation' => 'Teacher',
            'monthly_income' => '10,000 – 19,999',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'philhealth' => '123456789101',
            'disability' => ['none'],
            'medical_history' => ['none'],
        ]);

        $response = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-900',
            'memberId' => 'MB-014',
        ]))
            ->assertOk()
            ->assertSee('Juan Carlos Labubu', false)
            ->assertSee('Catholic', false)
            ->assertSee("Bachelor's Degree")
            ->assertDontSee('Demo preview for MB-014', false)
            ->assertDontSee('Records are placeholders and are not saved.', false)
            ->assertDontSee('Kristine Reyes', false);

        $html = $response->getContent();
        $this->assertStringContainsString('data-source="db"', $html);
        $this->assertStringContainsString('Registered member MB-014 in household HH-900.', $html);
        $this->assertStringNotContainsString('data-hh-member-view-delete', $html);
        $this->assertStringNotContainsString('data-hh-member-view-dialog', $html);
        $this->assertStringContainsString('lml-hh-member-view__btn--edit', $html);
    }

    public function test_unknown_member_does_not_keep_demo_preview_message(): void
    {
        $html = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))
            ->assertOk()
            ->assertSee('Member not found', false)
            ->assertDontSee('Kristine Reyes', false)
            ->getContent();

        $this->assertStringNotContainsString('data-source="demo"', $html);
        $this->assertStringNotContainsString('Demo preview for MB-001 in household HH-151.', $html);
        $this->assertStringNotContainsString('Records are placeholders and are not saved.', $html);
    }

    public function test_db_member_fields_are_not_overwritten_by_demo_catalog_identity(): void
    {
        // HH-151 exists in DemoCatalog as Kristine Reyes; DB row must win.
        $household = Household::factory()->create([
            'household_no' => 'HH-151',
            'zone' => 'Zone 9',
            'street' => 'Db Only St.',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
            'last_name' => 'DbOnly',
            'first_name' => 'Persisted',
            'middle_name' => null,
            'relation' => 'Head',
            'religion' => 'Islam',
            'education' => 'High School Graduate',
            'occupation' => 'Farmer',
            'sex' => 'Male',
            'birthday' => '1988-02-02',
        ]);

        $html = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Persisted DbOnly', $html);
        $this->assertStringContainsString('Farmer', $html);
        $this->assertStringContainsString('Islam', $html);
        $this->assertStringContainsString('High School Graduate', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('Registered member MB-001 in household HH-151.', $html);
    }
}
