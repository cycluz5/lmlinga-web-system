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
    /**
     * Batched lookups for the current bootstrap run (see preload()). Null = per-resident queries.
     *
     * @var array{has: array<string, array<int, true>>, maternal: array<int, true>, birth: array<int, true>, timbang: array<int, \App\Models\TimbangRecord>}|null
     */
    private ?array $preloaded = null;

    public function __construct(
        private readonly TimbangRecordService $timbang,
        private readonly MaternalPregnancyService $maternal,
    ) {}

    /**
     * Resolve every per-resident "has records" lookup in a constant number of queries,
     * so forResident() does no database work per member. Call clearPreload() when done.
     *
     * @param  iterable<Resident>  $residents
     */
    public function preload(iterable $residents): void
    {
        $ids = [];
        foreach ($residents as $resident) {
            $ids[] = (int) $resident->getKey();
        }

        $has = [];
        foreach (self::RECORD_MODELS as $module => $modelClass) {
            $has[$module] = $this->residentIdsWithRecords($modelClass, $ids);
        }

        $this->preloaded = [
            'has' => $has,
            'maternal' => $this->maternal->residentIdsWithAnyEpisode($ids),
            'birth' => ChildBirthHistoryService::residentIdsWithPresentation($ids),
            'timbang' => $this->timbang->latestForResidents($ids),
        ];
    }

    public function clearPreload(): void
    {
        $this->preloaded = null;
    }

    private const RECORD_MODELS = [
        'child-immunization' => ChildImmunization::class,
        'school-based-immunization' => SchoolImmunization::class,
        'child-nutrition' => ChildNutrition::class,
        'nutritional-status' => TimbangRecord::class,
        'deworming' => DewormingRecord::class,
        'risk-assessment' => RiskAssessment::class,
        'family-planning' => FamilyPlanningVisit::class,
        'maternal-pregnancy' => MaternalPregnancy::class,
    ];

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
        $rid = (int) $resident->getKey();
        $pre = $this->preloaded;
        $maternalHistory = $pre !== null ? isset($pre['maternal'][$rid]) : $this->maternal->hasAnyEpisode($resident);
        $adultImmEligible = AdultImmunizationEligibility::allows($sex, $birthday);
        $sbiEligible = SchoolImmunizationService::isEligibleForSchoolImmunization($resident);

        $recordExists = fn (string $module): bool => $pre !== null
            ? isset($pre['has'][$module][$rid])
            : $this->exists(self::RECORD_MODELS[$module], $resident);

        $has = [
            'child-immunization' => $recordExists('child-immunization'),
            'birth-history' => $pre !== null
                ? isset($pre['birth'][$rid])
                : ChildBirthHistoryService::presentationForResident($resident) !== null,
            'school-based-immunization' => $recordExists('school-based-immunization'),
            'child-nutrition' => $recordExists('child-nutrition'),
            'nutritional-status' => $recordExists('nutritional-status'),
            'deworming' => $recordExists('deworming'),
            'risk-assessment' => $recordExists('risk-assessment'),
            'family-planning' => $recordExists('family-planning'),
            'maternal-care' => $maternalHistory || $recordExists('maternal-pregnancy'),
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
            'nutrition_card' => $pre !== null
                ? $this->timbang->cardStateForResident($resident, $pre['timbang'][$rid] ?? null, true)
                : $this->timbang->cardStateForResident($resident),
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
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function residentIdsWithRecords(string $modelClass, array $ids): array
    {
        if (! class_exists($modelClass) || ! Schema::hasTable((new $modelClass)->getTable())) {
            return [];
        }

        $found = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($modelClass::query()->whereIn('resident_id', $chunk)->distinct()->pluck('resident_id') as $id) {
                $found[(int) $id] = true;
            }
        }

        return $found;
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
