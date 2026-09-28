<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\MaternalCareErdMode;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErdMaternalCareSchema;
use Tests\TestCase;

/**
 * Immunizations (Td1–Td5 → td_immunization, resident-scoped) and
 * Trans-Out details (→ maternal_trans_outs) on the ERD schema.
 */
class MaternalCareTdImmunizationAndTransOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-12')->startOfDay());
        ErdMaternalCareSchema::ensure();
        $this->assertTrue(MaternalCareErdMode::isPersistenceActive());
        $this->actingAsStaff(StaffRole::BHW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedMember(string $householdNo = 'HH-2001', string $memberNo = 'MB-2001'): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Tessa',
            'last_name' => 'Dose',
            'middle_name' => null,
            'relation' => 'Daughter',
            'sex' => 'Female',
            'birthday' => '1994-05-10',
            'relationship_status' => 'Married',
            'fp_user' => 'No',
        ]);

        $this->post(route('household-profiling.members.maternal-care.store', [
            'householdNo' => $householdNo,
            'memberId' => $memberNo,
        ]), [
            'lmp' => '2026-03-01',
            'gravida' => 1,
            'parity' => 0,
        ])->assertRedirect();

        return ['household' => $household, 'resident' => $resident];
    }

    private function params(Household $household, Resident $resident): array
    {
        return ['householdNo' => $household->household_no, 'memberId' => $resident->member_no];
    }

    private function update(Household $household, Resident $resident, string $section, array $data)
    {
        return $this->put(
            route('household-profiling.members.maternal-care.update', $this->params($household, $resident) + ['section' => $section]),
            $data
        );
    }

    private function careId(Resident $resident): int
    {
        return (int) DB::table('maternal_care')->where('resident_id', $resident->getKey())->value('maternal_care_id');
    }

    public function test_immunization_dates_are_saved_and_shown_again(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $this->update($household, $resident, 'immunizations', [
            'td1' => '2026-04-01',
            'td2' => '2026-05-01',
            'td3' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, DB::table('td_immunization')->where('resident_id', $resident->getKey())->count());
        $this->assertSame('2026-04-01', DB::table('td_immunization')->where('dose_number', 1)->value('date_given'));
        $this->assertSame('2026-05-01', DB::table('td_immunization')->where('dose_number', 2)->value('date_given'));

        $page = $this->get(route('household-profiling.members.maternal-care.immunizations', $this->params($household, $resident)))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('2 of 5 Vaccines', $page);
        $this->assertMatchesRegularExpression('/name="td1"[^>]*value="2026-04-01"/', $page);
        $this->assertMatchesRegularExpression('/name="td2"[^>]*value="2026-05-01"/', $page);
    }

    public function test_immunization_update_is_sparse_and_blank_clears_without_duplicates(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $this->update($household, $resident, 'immunizations', ['td1' => '2026-04-01', 'td2' => '2026-05-01'])
            ->assertRedirect();
        $this->update($household, $resident, 'immunizations', ['td2' => '2026-05-15'])
            ->assertRedirect();

        $this->assertSame(2, DB::table('td_immunization')->count());
        $this->assertSame('2026-04-01', DB::table('td_immunization')->where('dose_number', 1)->value('date_given'));
        $this->assertSame('2026-05-15', DB::table('td_immunization')->where('dose_number', 2)->value('date_given'));

        $this->update($household, $resident, 'immunizations', ['td1' => ''])->assertRedirect();
        $this->assertSame(2, DB::table('td_immunization')->count());
        $this->assertNull(DB::table('td_immunization')->where('dose_number', 1)->value('date_given'));
    }

    public function test_future_immunization_date_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $this->update($household, $resident, 'immunizations', ['td1' => '2027-01-01'])
            ->assertSessionHasErrors('td1');
        $this->assertSame(0, DB::table('td_immunization')->count());
    }

    public function test_immunizations_belong_to_the_resident_not_to_other_residents(): void
    {
        ['household' => $firstHh, 'resident' => $first] = $this->seedMember('HH-2002', 'MB-2002');
        ['household' => $secondHh, 'resident' => $second] = $this->seedMember('HH-2003', 'MB-2003');

        $this->update($firstHh, $first, 'immunizations', ['td1' => '2026-04-01'])->assertRedirect();

        $this->assertSame(1, DB::table('td_immunization')->where('resident_id', $first->getKey())->count());
        $this->assertSame(0, DB::table('td_immunization')->where('resident_id', $second->getKey())->count());

        $page = $this->get(route('household-profiling.members.maternal-care.immunizations', $this->params($secondHh, $second)))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('0 of 5 Vaccines', $page);
    }

    public function test_trans_out_saves_details_closes_pregnancy_and_shows_in_history(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();
        $this->update($household, $resident, 'immunizations', ['td1' => '2026-04-01'])->assertRedirect();

        $this->update($household, $resident, 'trans-out', [
            'to_facility' => 'Iriga City RHU',
            'occurred_at_stage' => 'Prenatal',
            'reason' => 'Referral',
            'date_transferred_out' => '2026-09-10',
        ])->assertRedirect(route('household-profiling.members.maternal-care.history', $this->params($household, $resident)));

        $careId = $this->careId($resident);
        $row = DB::table('maternal_trans_outs')->where('maternal_care_id', $careId)->first();
        $this->assertNotNull($row);
        $this->assertSame('Iriga City RHU', $row->to_facility);
        $this->assertSame('Prenatal', $row->occurred_at_stage);
        $this->assertSame('Referral', $row->reason);
        $this->assertSame('2026-09-10', $row->date_transferred_out);
        $this->assertSame(MaternalCareErdMode::STATUS_TRANS_OUT, DB::table('maternal_care')->value('pregnancy_status'));

        $history = $this->get(route('household-profiling.members.maternal-care.history.show', $this->params($household, $resident) + [
            'pregnancyId' => sprintf('MC-%03d', $careId),
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-mc-trans-out', $history);
        $this->assertMatchesRegularExpression('/name="to_facility"[^>]*value="Iriga City RHU"/', $history);
        $this->assertMatchesRegularExpression('/<option\s+value="Referral"\s+selected/', $history);
        $this->assertMatchesRegularExpression('/name="date_transferred_out"[^>]*value="2026-09-10"/', $history);
        $this->assertMatchesRegularExpression('/name="td1"[^>]*value="2026-04-01"/', $history);
        $this->assertStringNotContainsString('not stored in the current maternal database', $history);
    }

    public function test_trans_out_with_blank_details_still_closes_pregnancy(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedMember();

        $this->update($household, $resident, 'trans-out', [
            'to_facility' => '',
            'occurred_at_stage' => '',
            'reason' => '',
            'date_transferred_out' => '',
        ])->assertRedirect();

        $this->assertSame(MaternalCareErdMode::STATUS_TRANS_OUT, DB::table('maternal_care')->value('pregnancy_status'));
        $this->assertSame(1, DB::table('maternal_trans_outs')->count());
        $this->assertNull(DB::table('maternal_trans_outs')->value('to_facility'));
    }
}
