<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseholdProfilingEducationalAttainmentTest extends TestCase
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
            'relation' => 'Spouse',
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

    private function educationSelectHtml(string $html): string
    {
        $this->assertGreaterThan(
            0,
            preg_match('/<select[^>]*id="lml-hh-education"[^>]*>.*?<\/select>/s', $html, $matches)
        );

        return $matches[0];
    }

    public function test_create_rejects_education_na_abbreviation_before_persistence(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-861']);

        $this->from(route('household-profiling.members.create', ['householdNo' => 'HH-861']))
            ->post(
                route('household-profiling.members.store', ['householdNo' => 'HH-861']),
                $this->validMemberPayload(['education' => 'N/A'])
            )
            ->assertRedirect(route('household-profiling.members.create', ['householdNo' => 'HH-861']))
            ->assertSessionHasErrors('education');

        $this->postJson(
            route('household-profiling.members.store', ['householdNo' => 'HH-861']),
            $this->validMemberPayload(['education' => 'N/A'])
        )->assertStatus(422)->assertJsonValidationErrors('education');

        $this->assertSame(0, Resident::query()->where('household_id', $household->id)->count());
    }

    public function test_create_and_update_accept_not_applicable_for_young_residents(): void
    {
        $ages = [
            '2025-09-19' => 'HH-871',
            '2024-09-19' => 'HH-872',
            '2023-09-19' => 'HH-873',
        ];
        $index = 0;

        foreach ($ages as $birthday => $householdNo) {
            $index++;
            $household = Household::factory()->create(['household_no' => $householdNo]);
            Resident::factory()->head()->create([
                'household_id' => $household->id,
                'member_no' => 'MB-H'.sprintf('%02d', $index),
                'education' => 'College Graduate',
            ]);

            $this->post(
                route('household-profiling.members.store', ['householdNo' => $householdNo]),
                $this->validMemberPayload([
                    'birthday' => $birthday,
                    'relation' => 'Daughter',
                    'relationship_status' => 'Single',
                    'occupation' => 'None / N/A',
                    'monthly_income' => 'None / N/A',
                    'education' => 'Not Applicable',
                    'fp_user' => 'N/A',
                    'philhealth' => sprintf('1234567890%02d', $index),
                ])
            )->assertRedirect();

            $resident = Resident::query()
                ->where('household_id', $household->id)
                ->where('relation', 'Daughter')
                ->firstOrFail();
            $this->assertSame('Not Applicable', $resident->education);

            $this->put(
                route('household-profiling.members.update', [
                    'householdNo' => $householdNo,
                    'memberId' => $resident->member_no,
                ]),
                $this->validMemberPayload([
                    'relation' => 'Daughter',
                    'birthday' => $birthday,
                    'relationship_status' => 'Single',
                    'occupation' => 'None / N/A',
                    'monthly_income' => 'None / N/A',
                    'education' => 'Not Applicable',
                    'fp_user' => 'N/A',
                    'philhealth' => sprintf('1234567890%02d', $index),
                ])
            )->assertRedirect();

            $this->assertSame('Not Applicable', $resident->fresh()->education);

            $edit = $this->educationSelectHtml(
                $this->get(route('household-profiling.members.edit', [
                    'householdNo' => $householdNo,
                    'memberId' => $resident->member_no,
                ]))->assertOk()->getContent()
            );
            $this->assertStringContainsString('value="Not Applicable"', $edit);
            $this->assertTrue(
                str_contains($edit, 'value="Not Applicable" selected')
                || str_contains($edit, 'value="Not Applicable" selected=')
            );
        }
    }

    public function test_update_rejects_education_na_before_persistence(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-862']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-001',
            'relation' => 'Head',
            'education' => 'College Graduate',
        ]);

        $this->from(route('household-profiling.members.edit', [
            'householdNo' => 'HH-862',
            'memberId' => 'MB-001',
        ]))->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-862',
                'memberId' => 'MB-001',
            ]),
            $this->validMemberPayload([
                'relation' => 'Head',
                'education' => 'N/A',
            ])
        )->assertRedirect(route('household-profiling.members.edit', [
            'householdNo' => 'HH-862',
            'memberId' => 'MB-001',
        ]))->assertSessionHasErrors('education');

        $this->assertSame('College Graduate', $resident->fresh()->education);
    }

    public function test_create_and_update_accept_college_graduate(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-863']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-863']),
            $this->validMemberPayload(['education' => 'College Graduate'])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame('College Graduate', $resident->education);

        $this->put(
            route('household-profiling.members.update', [
                'householdNo' => 'HH-863',
                'memberId' => $resident->member_no,
            ]),
            $this->validMemberPayload([
                'education' => 'High School Graduate',
            ])
        )->assertRedirect();

        $this->assertSame('High School Graduate', $resident->fresh()->education);
    }

    public function test_create_accepts_post_graduate_ui_value(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-866']);

        $this->post(
            route('household-profiling.members.store', ['householdNo' => 'HH-866']),
            $this->validMemberPayload(['education' => 'Post-Graduate'])
        )->assertRedirect();

        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame('Post-Graduate', $resident->education);
    }

    public function test_create_and_edit_forms_include_not_applicable_and_omit_na_abbreviation(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-864']);
        Resident::factory()->head()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-010',
            'education' => 'Vocational',
        ]);

        $create = $this->educationSelectHtml(
            $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-864']))
                ->assertOk()
                ->getContent()
        );
        $edit = $this->educationSelectHtml(
            $this->get(route('household-profiling.members.edit', [
                'householdNo' => 'HH-864',
                'memberId' => 'MB-010',
            ]))->assertOk()->getContent()
        );

        foreach ([$create, $edit] as $select) {
            $this->assertStringNotContainsString('value="N/A"', $select);
            $this->assertStringContainsString('value="Not Applicable"', $select);
            $this->assertStringContainsString('value="No Formal Education"', $select);
            $this->assertStringContainsString('value="College Graduate"', $select);
            $this->assertStringContainsString('value="Post-Graduate"', $select);
            $this->assertStringContainsString('<option value="">Select</option>', $select);
        }
    }
}
