<?php

namespace Tests\Unit\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Support\Offline\OfflineFieldHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineFieldHasherTest extends TestCase
{
    use RefreshDatabase;

    public function test_household_hash_is_stable_for_the_same_editable_state(): void
    {
        $household = Household::factory()->create([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'address' => '123 Layuan',
            'latitude' => '7.12345678',
            'longitude' => '125.12345678',
            'accomplished_by' => 'Maria BHW',
        ]);

        $first = OfflineFieldHasher::household($household);
        $second = OfflineFieldHasher::household($household->fresh());

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
    }

    public function test_household_hash_ignores_timestamps_and_primary_keys(): void
    {
        $household = Household::factory()->create([
            'household_no' => '122',
            'zone' => 'Zone 1',
            'street' => 'Dalipay St.',
            'date_registered' => '2026-01-15',
        ]);

        $before = OfflineFieldHasher::household($household);

        $household->forceFill([
            'updated_at' => now()->addHour(),
        ])->saveQuietly();

        $this->assertSame($before, OfflineFieldHasher::household($household->fresh()));
        $snapshot = OfflineFieldHasher::householdSnapshot($household->fresh());
        $this->assertArrayNotHasKey('id', $snapshot);
        $this->assertArrayNotHasKey('household_id', $snapshot);
        $this->assertArrayNotHasKey('household_no', $snapshot);
        $this->assertArrayNotHasKey('created_at', $snapshot);
        $this->assertArrayNotHasKey('updated_at', $snapshot);
    }

    public function test_household_hash_changes_when_an_editable_field_changes(): void
    {
        $household = Household::factory()->create([
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
        ]);

        $before = OfflineFieldHasher::household($household);
        $household->forceFill(['street' => 'Cateel Bay St.'])->save();

        $this->assertNotSame($before, OfflineFieldHasher::household($household->fresh()));
    }

    public function test_resident_hash_is_stable_and_excludes_server_owned_fields(): void
    {
        $household = Household::factory()->create();
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
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
            'medical_history' => ['none'],
        ]);

        $first = OfflineFieldHasher::resident($resident);
        $second = OfflineFieldHasher::resident($resident->fresh());
        $this->assertSame($first, $second);

        $snapshot = OfflineFieldHasher::residentSnapshot($resident->fresh());
        $this->assertArrayNotHasKey('id', $snapshot);
        $this->assertArrayNotHasKey('resident_id', $snapshot);
        $this->assertArrayNotHasKey('household_id', $snapshot);
        $this->assertArrayNotHasKey('member_no', $snapshot);
        $this->assertArrayNotHasKey('created_at', $snapshot);
        $this->assertArrayNotHasKey('updated_at', $snapshot);

        $resident->forceFill(['first_name' => 'Anita'])->save();
        $this->assertNotSame($first, OfflineFieldHasher::resident($resident->fresh()));
    }

    public function test_household_hash_covers_every_writable_shell_field(): void
    {
        $household = Household::factory()->create([
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'address' => 'Old address',
            'latitude' => '7.10000000',
            'longitude' => '125.10000000',
            'accomplished_by' => 'Maria BHW',
        ]);

        $replacements = [
            'zone' => 'Zone 3',
            'street' => 'Cateel Bay St.',
            'date_registered' => '2026-02-01',
            'address' => 'New address',
            'latitude' => '7.20000000',
            'longitude' => '125.20000000',
            'accomplished_by' => 'Pedro BNS',
            'household_type' => 'HHTS',
        ];

        foreach (OfflineFieldHasher::HOUSEHOLD_FIELDS as $field) {
            if (! array_key_exists($field, $replacements)) {
                continue;
            }
            if ($field !== 'zone' && ! \Illuminate\Support\Facades\Schema::hasColumn($household->getTable(), $field)) {
                continue;
            }

            $fresh = $household->fresh();
            $before = OfflineFieldHasher::household($fresh);
            $fresh->forceFill([$field => $replacements[$field]])->save();
            $this->assertNotSame(
                $before,
                OfflineFieldHasher::household($fresh->fresh()),
                "Household field [{$field}] must be included in the conflict hash."
            );
        }
    }

    public function test_resident_hash_covers_every_writable_member_field(): void
    {
        $household = Household::factory()->create();
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
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
        ]);

        $replacements = [
            'last_name' => 'Reyes',
            'first_name' => 'Anita',
            'middle_name' => 'Diaz',
            'relation' => 'Daughter',
            'birthday' => '1991-04-16',
            'sex' => 'Male',
            'relationship_status' => 'Single',
            'occupation' => 'Nurse',
            'occupation_other' => 'Custom job',
            'monthly_income' => 'Below 5,000',
            'religion' => 'Islam',
            'religion_other' => 'Custom faith',
            'education' => 'High School Graduate',
            'fp_user' => 'Yes',
            'philhealth' => '999988887777',
            'disability' => ['Physical Disability (PD)'],
            'disability_others' => 'Hearing',
            'medical_history' => ['Hypertension'],
            'medical_others' => 'Asthma',
        ];

        foreach (OfflineFieldHasher::RESIDENT_FIELDS as $field) {
            if (! array_key_exists($field, $replacements)) {
                continue;
            }
            if (! \Illuminate\Support\Facades\Schema::hasColumn($resident->getTable(), $field)
                && ! in_array($field, ['relation', 'relationship_status', 'education', 'fp_user', 'philhealth'], true)
            ) {
                continue;
            }

            $fresh = $resident->fresh();
            $before = OfflineFieldHasher::resident($fresh);
            $fresh->forceFill([$field => $replacements[$field]])->save();
            $this->assertNotSame(
                $before,
                OfflineFieldHasher::resident($fresh->fresh()),
                "Resident field [{$field}] must be included in the conflict hash."
            );
        }
    }
}
