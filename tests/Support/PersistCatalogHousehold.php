<?php

namespace Tests\Support;

use App\Models\FamilyPlanningVisit;
use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Support\DemoCatalog;
use App\Support\DemoFamilyPlanning;
use App\Support\DemoRiskAssessment;

/**
 * Test-only: copy a DemoCatalog household into isolated sqlite so HTTP tests
 * that previously relied on silent catalog fallback can keep named fixtures.
 * Never used by production staff routes.
 */
final class PersistCatalogHousehold
{
    public static function persist(string $householdNo): Household
    {
        $demo = DemoCatalog::findHousehold($householdNo);
        if ($demo === null) {
            throw new \RuntimeException('Unknown catalog household '.$householdNo);
        }

        $household = Household::query()->where('household_no', $householdNo)->first();
        if ($household === null) {
            $household = Household::factory()->create([
                'household_no' => $householdNo,
                'zone' => (string) ($demo['zone'] ?? 'Zone 1'),
                'street' => (string) ($demo['street'] ?? 'Layuan St.'),
                'date_registered' => '2026-01-21',
                'latitude' => $demo['lat'] ?? null,
                'longitude' => $demo['lng'] ?? null,
            ]);
        }

        foreach ($demo['memberList'] ?? [] as $member) {
            if (! is_array($member)) {
                continue;
            }

            $memberNo = (string) ($member['id'] ?? '');
            if ($memberNo === '') {
                continue;
            }

            Resident::query()->firstOrCreate(
                [
                    'household_id' => $household->id,
                    'member_no' => $memberNo,
                ],
                [
                    'last_name' => (string) ($member['last_name'] ?? 'Unknown'),
                    'first_name' => (string) ($member['first_name'] ?? 'Unknown'),
                    'middle_name' => filled($member['middle_name'] ?? null) ? (string) $member['middle_name'] : null,
                    'relation' => (string) ($member['relation'] ?? $member['relationship'] ?? 'Son'),
                    'birthday' => (string) ($member['birthday'] ?? '1990-01-01'),
                    'sex' => (string) ($member['sex'] ?? 'Male'),
                    'relationship_status' => (string) ($member['relationship_status'] ?? 'Single'),
                    'occupation' => (string) ($member['occupation'] ?? 'None / N/A'),
                    'monthly_income' => (string) ($member['monthly_income'] ?? 'None / N/A'),
                    'religion' => (string) ($member['religion'] ?? 'Roman Catholic'),
                    'education' => (string) ($member['education'] ?? 'High School Graduate'),
                    'fp_user' => (string) ($member['fp_user'] ?? 'N/A'),
                    'philhealth' => $member['philhealth'] ?? null,
                    'disability' => $member['disability'] ?? ['none'],
                    'medical_history' => $member['medical_history'] ?? ['none'],
                    'disability_others' => (string) ($member['disability_others'] ?? ''),
                    'medical_others' => (string) ($member['medical_others'] ?? ''),
                ]
            );
        }

        return $household->fresh(['residents']);
    }

    /**
     * Copy DemoRiskAssessment catalog rows into sqlite for HH-* fixture tests.
     */
    public static function persistRiskAssessments(string $householdNo): void
    {
        $household = Household::query()->where('household_no', $householdNo)->first();
        if ($household === null) {
            $household = self::persist($householdNo);
        }

        $catalog = DemoRiskAssessment::catalog()[$householdNo] ?? [];
        foreach ($catalog as $memberNo => $rows) {
            if (! is_array($rows)) {
                continue;
            }

            $resident = Resident::query()
                ->where('household_id', $household->id)
                ->where('member_no', $memberNo)
                ->first();
            if ($resident === null) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                RiskAssessment::factory()->create([
                    'resident_id' => $resident->id,
                    'assessment_no' => (string) ($row['id'] ?? ''),
                    'conducted_at' => $row['conducted_at'] ?? now()->toDateString(),
                    'red_flags' => $row['red_flags'] ?? ['none'],
                    'past_medical' => $row['past_medical'] ?? ['none'],
                    'family_history' => $row['family_history'] ?? ['none'],
                    'dietary' => $row['dietary'] ?? [],
                    'tobacco' => $row['tobacco'] ?? '',
                    'alcohol' => $row['alcohol'] ?? '',
                    'physical_activity' => $row['physical_activity'] ?? '',
                    'height_cm' => $row['height_cm'] ?? null,
                    'weight_kg' => $row['weight_kg'] ?? null,
                    'bmi' => $row['bmi'] ?? null,
                    'waist_cm' => $row['waist_cm'] ?? null,
                    'systolic' => $row['systolic'] ?? null,
                    'diastolic' => $row['diastolic'] ?? null,
                    'bp_status' => $row['bp_status'] ?? null,
                    'bp_reading' => $row['bp_reading'] ?? null,
                    'bmi_label' => $row['bmi_label'] ?? null,
                    'visual_no_screening' => (bool) ($row['visual_no_screening'] ?? false),
                    'visual_blurred' => (bool) ($row['visual_blurred'] ?? false),
                    'visual_blurred_note' => $row['visual_blurred_note'] ?? null,
                ]);
            }
        }
    }

    /**
     * Copy DemoFamilyPlanning catalog visits into sqlite for HH-* fixture tests.
     */
    public static function persistFamilyPlanningVisits(string $householdNo, string $memberNo): void
    {
        $household = Household::query()->where('household_no', $householdNo)->first();
        if ($household === null) {
            $household = self::persist($householdNo);
        }

        $resident = Resident::query()
            ->where('household_id', $household->id)
            ->where('member_no', $memberNo)
            ->first();
        if ($resident === null) {
            return;
        }

        foreach (DemoFamilyPlanning::forMember($householdNo, $memberNo) as $row) {
            if (! is_array($row)) {
                continue;
            }

            FamilyPlanningVisit::factory()->create([
                'resident_id' => $resident->id,
                'visit_no' => (string) ($row['id'] ?? ''),
                'visited_at' => $row['visited_at'] ?? now()->toDateString(),
                'remarks' => $row['remarks'] ?? null,
                'commodities' => $row['commodities'] ?? [],
            ]);
        }
    }
}
