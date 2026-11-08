<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\HouseholdProfilingPresenter;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdProfilingMemberInformationB3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array<string, mixed>
     */
    private function validMemberPayload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'relation' => 'Head',
            'birthday' => '1990-03-15',
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Teacher',
            'monthly_income' => '20,000 – 29,999',
            'religion' => 'Roman Catholic',
            'education' => 'College Graduate',
            'fp_user' => 'No',
            'philhealth' => '123456789012',
            'disability' => ['none'],
            'disability_others' => null,
            'medical_history' => ['none'],
            'medical_others' => null,
        ], $overrides);
    }

    public function test_standard_occupation_and_religion_save(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-801']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-801']),
            $this->validMemberPayload()
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame('Teacher', $resident->occupation);
        $this->assertSame('Roman Catholic', $resident->religion);
    }

    public function test_occupation_other_saves_custom_value(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-802']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-802']),
            $this->validMemberPayload([
                'occupation' => 'Other',
                'occupation_other' => 'Basket weaver',
            ])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame('Basket weaver', $resident->occupation);
        if (Schema::hasColumn('residents', 'occupation_other')) {
            $this->assertSame('Basket weaver', $resident->occupation_other);
        }

        $presentation = HouseholdProfilingPresenter::memberFromModel($resident);
        $this->assertSame('Basket weaver', $presentation['occupation']);
        $this->assertSame('Other', $presentation['occupation_select']);
        $this->assertSame('Basket weaver', $presentation['occupation_other']);
    }

    public function test_occupation_other_edit_repopulates_and_switch_clears_custom(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-803']);
        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-803']),
            $this->validMemberPayload([
                'occupation' => 'Other',
                'occupation_other' => 'Basket weaver',
            ])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $memberId = $resident->member_no;

        $html = $this->get(route('household-profiling.members.edit', [
            'householdNo' => 'HH-803',
            'memberId' => $memberId,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('value="Other"', $html);
        $this->assertStringContainsString('Basket weaver', $html);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-803',
                'memberId' => $memberId,
            ]),
            $this->validMemberPayload([
                'occupation' => 'Farmer',
                'occupation_other' => 'Basket weaver',
            ])
        )->assertRedirect();

        $resident->refresh();
        $this->assertSame('Farmer', $resident->occupation);
        if (Schema::hasColumn('residents', 'occupation_other')) {
            $this->assertNull($resident->occupation_other);
        }
        $this->assertSame('Farmer', HouseholdProfilingPresenter::memberFromModel($resident)['occupation_select']);
    }

    public function test_occupation_other_without_custom_value_is_rejected(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-804']);

        $this->from(route('household-profiling.members.create', ['householdNo' => 'HH-804']))
            ->post(
                route('household-profiling.members.store', ['householdNo' => 'HH-804']),
                $this->validMemberPayload([
                    'occupation' => 'Other',
                    'occupation_other' => '',
                ])
            )
            ->assertRedirect(route('household-profiling.members.create', ['householdNo' => 'HH-804']))
            ->assertSessionHasErrors('occupation_other');

        $this->assertSame(0, Resident::query()->where('household_id', $household->id)->count());
    }

    public function test_religion_other_saves_repopulates_and_switch_clears(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-805']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-805']),
            $this->validMemberPayload([
                'religion' => 'Other',
                'religion_other' => 'Seventh-day Adventist',
            ])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame('Seventh-day Adventist', $resident->religion);

        $presentation = HouseholdProfilingPresenter::memberFromModel($resident);
        $this->assertSame('Other', $presentation['religion_select']);
        $this->assertSame('Seventh-day Adventist', $presentation['religion_other']);

        $this->from(route('household-profiling.members.create', ['householdNo' => 'HH-805']))
            ->post(
                route('household-profiling.members.store', ['householdNo' => 'HH-805']),
                $this->validMemberPayload([
                    'relation' => 'Spouse',
                    'religion' => 'Other',
                    'religion_other' => '',
                ])
            )
            ->assertSessionHasErrors('religion_other');

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-805',
                'memberId' => $resident->member_no,
            ]),
            $this->validMemberPayload([
                'religion' => 'Islam',
                'religion_other' => 'Seventh-day Adventist',
            ])
        )->assertRedirect();

        $resident->refresh();
        $this->assertSame('Islam', $resident->religion);
        if (Schema::hasColumn('residents', 'religion_other')) {
            $this->assertNull($resident->religion_other);
        }

        $html = $this->get(route('household-profiling.members.edit', [
            'householdNo' => 'HH-805',
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('value="Islam"', $html);
        $this->assertStringNotContainsString('Seventh-day Adventist', $html);
    }

    public function test_philhealth_blank_valid_and_clear(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-806']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-806']),
            $this->validMemberPayload(['philhealth' => null])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertNull($resident->philhealth);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-806',
                'memberId' => $resident->member_no,
            ]),
            $this->validMemberPayload(['philhealth' => '987654321098'])
        )->assertRedirect();

        $resident->refresh();
        $this->assertSame('987654321098', $resident->philhealth);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-806',
                'memberId' => $resident->member_no,
            ]),
            $this->validMemberPayload(['philhealth' => ''])
        )->assertRedirect();

        $resident->refresh();
        $this->assertNull($resident->philhealth);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-806',
                'memberId' => $resident->member_no,
            ]),
            $this->validMemberPayload(['philhealth' => '12345'])
        )->assertSessionHasErrors('philhealth');
    }

    public function test_health_and_welfare_persisted_values_appear_on_view(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-807']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-807']),
            $this->validMemberPayload([
                'philhealth' => '111122223333',
                'fp_user' => 'Yes',
                'disability' => ['Physical Disability (PD)', 'others'],
                'disability_others' => 'Hearing impairment',
                'medical_history' => ['Hypertension', 'Diabetes Mellitus'],
            ])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();

        $html = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-807',
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('111122223333', $html);
        $this->assertStringContainsString('Yes', $html);
        $this->assertStringContainsString('Physical Disability (PD)', $html);
        $this->assertStringContainsString('Hearing impairment', $html);
        $this->assertStringContainsString('Hypertension', $html);
        $this->assertStringContainsString('Diabetes Mellitus', $html);
    }

    public function test_missing_health_values_do_not_show_fake_static_data(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-808']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-808',
            'relation' => 'Head',
            'philhealth' => null,
            'disability' => null,
            'medical_history' => null,
            'fp_user' => 'N/A',
        ]);

        $presentation = HouseholdProfilingPresenter::memberFromModel($resident);
        $this->assertSame([], $presentation['disability']);
        $this->assertSame([], $presentation['medical_history']);
        $this->assertSame('', $presentation['philhealth']);

        $html = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-808',
            'memberId' => 'MB-808',
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/PhilHealth Number<\/dt>\s*<dd[^>]*>—<\/dd>/', $html);
        $this->assertMatchesRegularExpression('/Disability Type<\/dt>\s*<dd[^>]*>—<\/dd>/', $html);
        $this->assertMatchesRegularExpression('/Medical History<\/dt>\s*<dd[^>]*>—<\/dd>/', $html);
        $this->assertStringNotContainsString('Intellectual Disability (ID)', $html);
        $this->assertStringNotContainsString('Diabetes Mellitus', $html);
    }

    public function test_core_resident_fields_update_authoritative_row_and_view(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-809']);
        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-809']),
            $this->validMemberPayload()
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $memberId = $resident->member_no;

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-809',
                'memberId' => $memberId,
            ]),
            $this->validMemberPayload([
                'first_name' => 'Carla',
                'middle_name' => null,
                'last_name' => 'Dizon',
                'birthday' => '1988-12-01',
                'sex' => 'Female',
                'relationship_status' => 'Widowed',
                'relation' => 'Parent',
                'occupation' => 'Nurse',
                'monthly_income' => 'Below 5,000',
                'religion' => 'Protestant',
                'education' => 'Vocational',
                'fp_user' => 'N/A',
            ])
        )->assertRedirect();

        $resident->refresh();
        $this->assertSame($household->id, $resident->household_id);
        $this->assertSame($memberId, $resident->member_no);
        $this->assertSame('Carla', $resident->first_name);
        $this->assertNull($resident->middle_name);
        $this->assertSame('Dizon', $resident->last_name);
        $this->assertTrue($resident->birthday->equalTo('1988-12-01'));
        $this->assertSame('Female', $resident->sex);
        $this->assertSame('Widowed', $resident->relationship_status);
        $this->assertSame('Parent', $resident->relation);
        $this->assertSame('Nurse', $resident->occupation);
        $this->assertSame('Protestant', $resident->religion);

        $html = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-809',
            'memberId' => $memberId,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Carla Dizon', $html);
        $this->assertStringContainsString('Nurse', $html);
        $this->assertStringContainsString('Protestant', $html);
        $this->assertStringContainsString('Vocational', $html);
    }

    public function test_medical_history_others_persists_and_renders(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-810']);
        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-810']),
            $this->validMemberPayload([
                'medical_history' => ['others'],
                'medical_others' => 'Asthma',
            ])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $presentation = HouseholdProfilingPresenter::memberFromModel($resident);
        $this->assertContains('others', $presentation['medical_history']);
        $this->assertSame('Asthma', $presentation['medical_others']);

        $html = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-810',
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('Asthma', $html);
    }

    public function test_three_digit_household_member_routes_work(): void
    {
        $household = Household::factory()->create(['household_no' => '121']);
        $this->post(
            route('household-profiling.members.store', ['householdNo' => '121']),
            $this->validMemberPayload([
                'occupation' => 'Other',
                'occupation_other' => 'Basket weaver',
                'philhealth' => '',
            ])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $memberId = $resident->member_no;

        $this->get(route('household-profiling.members.show', [
            'householdNo' => '121',
            'memberId' => $memberId,
        ]))->assertOk()->assertSee('Basket weaver', false);

        $this->get(route('household-profiling.members.edit', [
            'householdNo' => '121',
            'memberId' => $memberId,
        ]))->assertOk()->assertSee('Basket weaver', false);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => '121',
                'memberId' => $memberId,
            ]),
            $this->validMemberPayload([
                'first_name' => 'Updated',
                'occupation' => 'Other',
                'occupation_other' => 'Basket weaver',
            ])
        )->assertRedirect(route('household-profiling.members.show', [
            'householdNo' => '121',
            'memberId' => $memberId,
        ]));

        $resident->refresh();
        $this->assertSame('Updated', $resident->first_name);
        $this->assertSame($household->id, $resident->household_id);
    }

    public function test_cross_household_member_update_is_rejected(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-811']);
        $other = Household::factory()->create(['household_no' => 'HH-812']);
        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-811']),
            $this->validMemberPayload(['first_name' => 'Owned'])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-812',
                'memberId' => $resident->member_no,
            ]),
            $this->validMemberPayload(['first_name' => 'Hijacked'])
        )->assertNotFound();

        $resident->refresh();
        $this->assertSame('Owned', $resident->first_name);
        $this->assertSame($household->id, $resident->household_id);
        $this->assertSame(0, Resident::query()->where('household_id', $other->id)->count());
    }
}
