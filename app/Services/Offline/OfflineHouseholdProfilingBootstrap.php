<?php

namespace App\Services\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Support\HouseholdProfilingPresenter;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\OpaqueId;
use Illuminate\Support\Facades\Schema;

/**
 * Compact Household Profiling snapshot for authorized staff offline preload.
 */
final class OfflineHouseholdProfilingBootstrap
{
    public function __construct(
        private readonly OfflineHealthSummaryCompiler $healthSummary,
    ) {}

    /**
     * @return array{
     *     households: list<array<string, mixed>>,
     *     members: list<array<string, mixed>>,
     *     catalogs: array<string, list<string>>,
     *     generated_at: string
     * }
     */
    public function payload(): array
    {
        $households = [];
        $members = [];

        if (Schema::hasTable('households')) {
            $models = Household::query()
                ->excludingNonResidentSentinel()
                ->with(['residents' => fn ($q) => Resident::eagerLoadForProfiling($q)])
                ->orderBy('household_no')
                ->get();

            foreach ($models as $household) {
                $presentation = HouseholdProfilingPresenter::fromModel($household);
                $householdNo = (string) $presentation['householdNo'];
                $households[] = [
                    'household_id' => (int) $household->getKey(),
                    'household_no' => $householdNo,
                    'url_key' => OpaqueId::forUrl('h', $householdNo),
                    'display_no' => (string) ($presentation['displayNo'] ?? $householdNo),
                    'house_head' => (string) ($presentation['houseHead'] ?? '—'),
                    'zone' => (string) ($presentation['zone'] ?? ''),
                    'street' => (string) ($presentation['street'] ?? ''),
                    'address' => (string) ($presentation['address'] ?? ''),
                    'accomplished_date' => (string) ($presentation['accomplishedDate'] ?? '—'),
                    'member_count' => count($presentation['memberList'] ?? []),
                    'water' => $presentation['water'] ?? [
                        'title' => 'Access to Safe Water',
                        'level' => '—',
                        'status' => 'Not recorded',
                    ],
                    'sanitation' => $presentation['sanitation'] ?? [
                        'title' => 'Sanitation Services',
                        'facility' => '—',
                        'status' => 'Not recorded',
                    ],
                ];

                foreach ($household->residents as $resident) {
                    $row = HouseholdProfilingPresenter::memberFromModel($resident);
                    unset($row['birth_history']);
                    $members[] = [
                        'household_id' => (int) $household->getKey(),
                        'household_no' => $householdNo,
                        'resident_id' => (int) $resident->getKey(),
                        'member_no' => (string) $resident->member_no,
                        'url_key' => OpaqueId::forUrl('m', (string) $resident->member_no),
                        'resident_url_key' => OpaqueId::forUrl('r', (string) $resident->getKey()),
                        // Base hash for offline RESIDENT_UPDATE conflict detection (same value the live edit page emits).
                        'field_hash' => OfflineFieldHasher::resident($resident),
                        'name' => (string) ($row['name'] ?? ''),
                        'relationship' => (string) ($row['relationship'] ?? ''),
                        'age' => $row['age'] ?? null,
                        'sex' => (string) ($row['sex'] ?? ''),
                        'occupation' => (string) ($row['occupation'] ?? ''),
                        'last_name' => (string) ($row['last_name'] ?? ''),
                        'first_name' => (string) ($row['first_name'] ?? ''),
                        'middle_name' => (string) ($row['middle_name'] ?? ''),
                        'relation' => (string) ($row['relation'] ?? ''),
                        'birthday' => (string) ($row['birthday'] ?? ''),
                        'relationship_status' => (string) ($row['relationship_status'] ?? ''),
                        'monthly_income' => (string) ($row['monthly_income'] ?? ''),
                        'religion' => (string) ($row['religion'] ?? ''),
                        'education' => (string) ($row['education'] ?? ''),
                        'philhealth' => (string) ($row['philhealth'] ?? ''),
                        'fp_user' => (string) ($row['fp_user'] ?? ''),
                        'occupation_select' => (string) ($row['occupation_select'] ?? ''),
                        'occupation_other' => (string) ($row['occupation_other'] ?? ''),
                        'religion_select' => (string) ($row['religion_select'] ?? ''),
                        'religion_other' => (string) ($row['religion_other'] ?? ''),
                        'disability' => $row['disability'] ?? [],
                        'disability_others' => (string) ($row['disability_others'] ?? ''),
                        'medical_history' => $row['medical_history'] ?? [],
                        'medical_others' => (string) ($row['medical_others'] ?? ''),
                        'health' => $this->healthSummary->forResident($resident, $row),
                    ];
                }
            }
        }

        return [
            'households' => $households,
            'members' => $members,
            'catalogs' => [
                'relations' => [
                    'Head', 'Spouse', 'Son', 'Daughter', 'Parent', 'Sibling',
                    'Grandchild', 'Other Relative', 'Non-Relative',
                ],
                'sexes' => ['Male', 'Female'],
                'relationship_statuses' => ['Single', 'Married', 'Widowed', 'Separated', 'Live-in'],
                'occupations' => [
                    'None / N/A', 'Farmer', 'Fisherfolk', 'Vendor', 'Teacher', 'Nurse', 'Driver',
                    'Construction Worker', 'Government Employee', 'Private Employee', 'Self-employed',
                    'Student', 'Homemaker', 'Unemployed', 'Other',
                ],
                'religions' => ['Roman Catholic', 'Iglesia ni Cristo', 'Protestant', 'Islam', 'Born Again', 'Other', 'None'],
                'education' => [
                    'No Formal Education', 'Elementary Level', 'Elementary Graduate', 'High School Level',
                    'High School Graduate', 'Vocational', 'College Level', 'College Graduate', 'Post-Graduate', 'Not Applicable',
                ],
                'monthly_incomes' => [
                    'None / N/A', 'Below 5,000', '5,000 – 9,999', '10,000 – 19,999',
                    '20,000 – 29,999', '30,000 – 49,999', '50,000 and above',
                ],
                'fp_user' => ['Yes', 'No', 'N/A'],
                'disabilities' => [
                    'none', 'Intellectual Disability (ID)', 'Mental Disability (MD)', 'Physical Disability (PD)', 'others',
                ],
                'medical_history' => [
                    'none', 'Diabetes Mellitus', 'Heart Disease', 'Hypertension', 'Kidney Disease', 'Tuberculosis', 'others',
                ],
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
