<?php

namespace App\Services\Offline;

use App\Models\ChildImmunization;
use App\Models\ChildNutrition;
use App\Models\DewormingRecord;
use App\Models\FamilyPlanningVisit;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Models\SchoolImmunization;
use App\Models\TimbangRecord;
use App\Support\AdultImmunizationEligibility;
use App\Support\ChildBirthHistoryService;
use App\Support\FamilyPlanningEligibility;
use App\Support\MaternalCareEligibility;
use App\Support\MaternalPregnancyService;
use App\Support\RiskAssessmentService;
use App\Support\SchoolImmunizationService;
use App\Support\TimbangRecordService;
use Illuminate\Support\Facades\Schema;

/**
 * Compact per-member Health Summary metadata for staff offline preparation.
 * Actor-scoped via the bootstrap payload; does not dump clinical datasets.
 */
final class OfflineHealthSummaryCompiler
{
    public function __construct(
        private readonly TimbangRecordService $timbang,
        private readonly MaternalPregnancyService $maternal,
    ) {}

    /**
     * @param  array<string, mixed>  $memberPresentation
     * @return array<string, mixed>
     */
    public function forResident(Resident $resident, array $memberPresentation = []): array
    {
        $sex = (string) ($memberPresentation['sex'] ?? $resident->sex ?? '');
        $birthday = $memberPresentation['birthday'] ?? $resident->birthday;
        $payload = array_merge($memberPresentation, [
            'sex' => $sex,
            'birthday' => $birthday,
        ]);

        $riskEligible = RiskAssessmentService::isEligibleForRiskAssessment($resident);
        $fpEligible = FamilyPlanningEligibility::allows($sex, $birthday);
        $maternalSex = MaternalCareEligibility::allows($sex);
        $maternalWorkflow = MaternalCareEligibility::allowsWorkflow($sex, $birthday);
        $maternalHistory = $this->maternal->hasAnyEpisode($resident);
        $adultImmEligible = AdultImmunizationEligibility::allows($sex, $birthday);
        $sbiEligible = SchoolImmunizationService::isEligibleForSchoolImmunization($resident);

        $has = [
            'child-immunization' => $this->exists(ChildImmunization::class, $resident),
            'birth-history' => ChildBirthHistoryService::presentationForResident($resident) !== null,
            'school-based-immunization' => $this->exists(SchoolImmunization::class, $resident),
            'child-nutrition' => $this->exists(ChildNutrition::class, $resident),
            'nutritional-status' => $this->exists(TimbangRecord::class, $resident),
            'deworming' => $this->exists(DewormingRecord::class, $resident),
            'risk-assessment' => $this->exists(RiskAssessment::class, $resident),
            'family-planning' => $this->exists(FamilyPlanningVisit::class, $resident),
            'maternal-care' => $maternalHistory || $this->exists(MaternalPregnancy::class, $resident),
        ];

        $warm = [];
        foreach ($has as $module => $present) {
            if ($present) {
                $warm[] = $module;
            }
        }

        return [
            'eligible' => [
                'child_immunization' => true,
                'school_based_immunization' => $sbiEligible,
                'child_nutrition' => true,
                'deworming' => true,
                'nutritional_status' => true,
                'risk_assessment' => $riskEligible,
                'family_planning' => $fpEligible,
                'maternal_care' => $maternalSex,
                'maternal_care_workflow' => $maternalWorkflow,
                'adult_immunization' => $adultImmEligible,
                'death' => true,
            ],
            'has_records' => $has,
            'warm_modules' => $warm,
            'nutrition_card' => $this->timbang->cardStateForResident($resident),
            'maternal_care_has_history' => $maternalHistory,
            'member_age' => $payload['age'] ?? null,
            'member_sex' => $sex,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyForLocalMember(array $memberPresentation = []): array
    {
        $sex = (string) ($memberPresentation['sex'] ?? '');
        $birthday = $memberPresentation['birthday'] ?? null;
        $riskEligible = RiskAssessmentService::isEligibleForRiskAssessment($memberPresentation);
        $fpEligible = FamilyPlanningEligibility::allows($sex, $birthday);
        $maternalSex = MaternalCareEligibility::allows($sex);
        $maternalWorkflow = MaternalCareEligibility::allowsWorkflow($sex, $birthday);
        $adultImmEligible = AdultImmunizationEligibility::allows($sex, $birthday);

        return [
            'eligible' => [
                'child_immunization' => true,
                'school_based_immunization' => true,
                'child_nutrition' => true,
                'deworming' => true,
                'nutritional_status' => true,
                'risk_assessment' => $riskEligible,
                'family_planning' => $fpEligible,
                'maternal_care' => $maternalSex,
                'maternal_care_workflow' => $maternalWorkflow,
                'adult_immunization' => $adultImmEligible,
                'death' => false,
            ],
            'has_records' => [
                'child-immunization' => false,
                'birth-history' => false,
                'school-based-immunization' => false,
                'child-nutrition' => false,
                'nutritional-status' => false,
                'deworming' => false,
                'risk-assessment' => false,
                'family-planning' => false,
                'maternal-care' => false,
            ],
            'warm_modules' => [],
            'nutrition_card' => $this->timbang->emptyCardState(),
            'maternal_care_has_history' => false,
            'member_age' => $memberPresentation['age'] ?? null,
            'member_sex' => $sex,
        ];
    }

    /**
     * @param  class-string  $modelClass
     */
    private function exists(string $modelClass, Resident $resident): bool
    {
        if (! class_exists($modelClass)) {
            return false;
        }

        $model = new $modelClass;
        $table = $model->getTable();
        if (! Schema::hasTable($table)) {
            return false;
        }

        return $modelClass::query()
            ->where('resident_id', $resident->getKey())
            ->exists();
    }
}
