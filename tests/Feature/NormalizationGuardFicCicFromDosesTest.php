<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildImmunizationService;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HybridChildImmunizationSchema;
use Tests\TestCase;

/**
 * Normalization guard (Phase 1): FIC/CIC shown to users is computed from immunization_doses.
 * fic_cic_status (legacy manual flags) must never override the dose-based result, whether the
 * row is missing or stale. Protects the future removal of fic_cic_status.
 *
 * Uses the hybrid schema that matches the live database (plural header + fic_cic_status).
 */
class NormalizationGuardFicCicFromDosesTest extends TestCase
{
    use RefreshDatabase;

    private ChildImmunizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        HybridChildImmunizationSchema::ensure();
        $this->service = app(ChildImmunizationService::class);
        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedChild(): array
    {
        $household = Household::factory()->create(['household_no' => 'HH-931', 'zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-931',
            'first_name' => 'Guard',
            'last_name' => 'Child',
            'birthday' => now()->subMonths(20)->format('Y-m-d'),
            'sex' => 'Female',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * Doses that complete FIC and CIC (BCG 1, OPV 3, DPT-HiB-HepB 3, MMR 2).
     * No vaccine_types key: the current UI never posts FIC/CIC checkboxes.
     */
    private function saveCompleteDoses(Resident $resident): void
    {
        $this->service->saveForResident($resident, [
            'vaccines' => [
                'bcg' => [0 => '2025-01-01'],
                'opv' => [0 => '2025-01-15', 1 => '2025-02-15', 2 => '2025-03-15'],
                'dpt-hib-hepb' => [0 => '2025-01-20', 1 => '2025-02-20', 2 => '2025-03-20'],
                'mmr' => [0 => '2025-09-01', 1 => '2025-12-01'],
            ],
        ]);
    }

    private function headerId(): int
    {
        return (int) DB::table('child_immunizations')->value('id');
    }

    private function writeStaleStatus(int $fic, int $cic): void
    {
        DB::table('fic_cic_status')->updateOrInsert(
            ['child_immunization_id' => $this->headerId()],
            ['fic_completed' => $fic, 'cic_completed' => $cic, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function assertDisplayed(Household $household, Resident $resident, bool $fic, bool $cic): void
    {
        $state = $this->service->forResident($resident);
        $this->assertSame($fic, $state['fic']['completed'], 'Read contract FIC must come from doses.');
        $this->assertSame($cic, $state['cic']['completed'], 'Read contract CIC must come from doses.');

        $html = $this->get(route('household-profiling.members.child-immunization', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-fic-completed="'.($fic ? 'true' : 'false').'"', $html);
        $this->assertStringContainsString('data-cic-completed="'.($cic ? 'true' : 'false').'"', $html);
    }

    public function test_complete_doses_show_fic_and_cic_complete_without_a_status_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $this->saveCompleteDoses($resident);

        $this->assertSame(0, DB::table('fic_cic_status')->count(), 'Dose-only saves do not write fic_cic_status.');
        $this->assertDisplayed($household, $resident, fic: true, cic: true);
    }

    public function test_stale_incomplete_status_row_does_not_hide_dose_based_completion(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $this->saveCompleteDoses($resident);
        $this->writeStaleStatus(fic: 0, cic: 0);

        $this->assertDisplayed($household, $resident, fic: true, cic: true);
    }

    public function test_stale_complete_status_row_does_not_fake_completion(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild();
        $this->service->saveForResident($resident, ['vaccines' => ['bcg' => [0 => '2025-01-01']]]);
        $this->writeStaleStatus(fic: 1, cic: 1);

        $this->assertDisplayed($household, $resident, fic: false, cic: false);
    }
}
