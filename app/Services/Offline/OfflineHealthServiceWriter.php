<?php

namespace App\Services\Offline;

use App\Http\Requests\StoreChildBirthHistoryRequest;
use App\Http\Requests\StoreChildImmunizationRequest;
use App\Http\Requests\StoreChildNutritionRequest;
use App\Http\Requests\StoreDewormingRecordRequest;
use App\Http\Requests\StoreFamilyPlanningVisitRequest;
use App\Http\Requests\StoreMaternalPregnancyRequest;
use App\Http\Requests\StoreRiskAssessmentRequest;
use App\Http\Requests\StoreSchoolImmunizationRequest;
use App\Http\Requests\StoreTimbangRecordRequest;
use App\Support\TimbangRecordService;
use App\Http\Requests\UpdateFamilyPlanningVisitRequest;
use App\Http\Requests\UpdateMaternalCareSectionRequest;
use App\Http\Requests\UpdateRiskAssessmentSectionRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildBirthHistoryService;
use App\Support\ChildImmunizationService;
use App\Support\ChildNutritionService;
use App\Support\DewormingRecordService;
use App\Support\FamilyPlanningVisitService;
use App\Support\MaternalPregnancyService;
use App\Support\Offline\OfflineInnerRequestValidator;
use App\Support\Offline\OfflineSyncException;
use App\Support\RiskAssessmentService;
use App\Support\SchoolImmunizationService;

/**
 * Allowlisted member Health Summary writes for POST /offline/sync.
 */
final class OfflineHealthServiceWriter
{
    public const ACTIONS = [
        'child_immunization_store',
        'child_birth_history_store',
        'school_immunization_store',
        'child_nutrition_store',
        'timbang_record_store',
        'deworming_store',
        'risk_assessment_store',
        'risk_assessment_section_update',
        'family_planning_store',
        'family_planning_update',
        'maternal_register',
        'maternal_section_update',
    ];

    public function __construct(
        private readonly ChildImmunizationService $childImmunization,
        private readonly ChildBirthHistoryService $birthHistory,
        private readonly SchoolImmunizationService $schoolImmunization,
        private readonly ChildNutritionService $childNutrition,
        private readonly TimbangRecordService $timbang,
        private readonly DewormingRecordService $deworming,
        private readonly RiskAssessmentService $riskAssessment,
        private readonly FamilyPlanningVisitService $familyPlanning,
        private readonly MaternalPregnancyService $maternal,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{identities: array<string, mixed>, body: array<string, mixed>}
     */
    public function write(Resident $resident, Household $household, array $payload): array
    {
        $action = trim((string) ($payload['_health_action'] ?? ''));
        if (! in_array($action, self::ACTIONS, true)) {
            throw OfflineSyncException::unknownOperation();
        }

        $input = $payload;
        unset(
            $input['_health_action'],
            $input['_health_section'],
            $input['_health_visit_id'],
            $input['_health_assessment_id'],
            $input['client_local_member_id'],
        );

        $householdNo = (string) $household->household_no;
        $memberNo = (string) $resident->member_no;
        $route = [
            'householdNo' => $householdNo,
            'memberId' => $memberNo,
        ];

        match ($action) {
            'child_immunization_store' => $this->childImmunization->saveForResident(
                $resident,
                OfflineInnerRequestValidator::validate(StoreChildImmunizationRequest::class, $input, $route),
            ),
            'child_birth_history_store' => $this->birthHistory->saveForResident(
                $resident,
                OfflineInnerRequestValidator::validate(StoreChildBirthHistoryRequest::class, $input, $route),
            ),
            'school_immunization_store' => $this->schoolImmunization->saveForResident(
                $resident,
                OfflineInnerRequestValidator::validate(StoreSchoolImmunizationRequest::class, $input, $route),
            ),
            'child_nutrition_store' => $this->childNutrition->saveForResident(
                $resident,
                OfflineInnerRequestValidator::validate(StoreChildNutritionRequest::class, $input, $route),
            ),
            'timbang_record_store' => $this->timbang->createForResident(
                $resident,
                $this->timbang->normalizeValidated(
                    OfflineInnerRequestValidator::validate(StoreTimbangRecordRequest::class, $input, $route)
                ),
            ),
            'deworming_store' => $this->deworming->createForResident(
                $resident,
                OfflineInnerRequestValidator::validate(StoreDewormingRecordRequest::class, $input, $route),
            ),
            'risk_assessment_store' => $this->riskAssessment->createForResident(
                $resident,
                OfflineInnerRequestValidator::request(StoreRiskAssessmentRequest::class, $input, $route)
                    ->assessmentPayload(),
            ),
            'risk_assessment_section_update' => $this->updateRiskSection($resident, $payload, $input, $route),
            'family_planning_store' => $this->familyPlanning->createForResident(
                $resident,
                OfflineInnerRequestValidator::request(StoreFamilyPlanningVisitRequest::class, $input, $route)
                    ->visitPayload(),
            ),
            'family_planning_update' => $this->updateFamilyPlanning($resident, $payload, $input, $route),
            'maternal_register' => $this->maternal->createForResident(
                $resident,
                OfflineInnerRequestValidator::request(StoreMaternalPregnancyRequest::class, $input, $route)
                    ->pregnancyPayload(),
            ),
            'maternal_section_update' => $this->updateMaternalSection($resident, $payload, $input, $route),
            default => throw OfflineSyncException::unknownOperation(),
        };

        return [
            'identities' => [
                'household_pk' => (int) $household->getKey(),
                'household_no' => $householdNo,
                'resident_pk' => (int) $resident->getKey(),
                'member_no' => $memberNo,
            ],
            'body' => [
                'household' => [
                    'id' => (int) $household->getKey(),
                    'household_no' => $householdNo,
                ],
                'resident' => [
                    'id' => (int) $resident->getKey(),
                    'member_no' => $memberNo,
                ],
                'health_action' => $action,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $route
     */
    private function updateRiskSection(Resident $resident, array $payload, array $input, array $route): void
    {
        $assessmentId = strtoupper(trim((string) ($payload['_health_assessment_id'] ?? '')));
        $section = strtolower(trim((string) ($payload['_health_section'] ?? '')));
        if ($assessmentId === '' || $section === '') {
            throw OfflineSyncException::malformed(
                'Risk assessment section identifiers are required.',
                ['_health_assessment_id' => ['Risk assessment identifiers are required.']],
            );
        }

        $route['assessmentId'] = $assessmentId;
        $route['section'] = $section;
        $request = OfflineInnerRequestValidator::request(
            UpdateRiskAssessmentSectionRequest::class,
            $input,
            $route,
        );

        $this->riskAssessment->updateSectionForResident(
            $resident,
            $assessmentId,
            $section,
            $request->sectionPayload(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $route
     */
    private function updateFamilyPlanning(Resident $resident, array $payload, array $input, array $route): void
    {
        $visitId = strtoupper(trim((string) ($payload['_health_visit_id'] ?? '')));
        if ($visitId === '') {
            throw OfflineSyncException::malformed(
                'Family planning visit identifier is required.',
                ['_health_visit_id' => ['A visit identifier is required.']],
            );
        }

        $route['visitId'] = $visitId;
        $request = OfflineInnerRequestValidator::request(
            UpdateFamilyPlanningVisitRequest::class,
            $input,
            $route,
        );

        $this->familyPlanning->updateForResident($resident, $visitId, $request->visitPayload());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $route
     */
    private function updateMaternalSection(Resident $resident, array $payload, array $input, array $route): void
    {
        $section = strtolower(trim((string) ($payload['_health_section'] ?? '')));
        $allowed = [
            'prenatal',
            'immunizations',
            'supplementations',
            'laboratory',
            'delivery',
            'postnatal',
            'trans-out',
        ];
        if (! in_array($section, $allowed, true)) {
            throw OfflineSyncException::malformed(
                'Maternal care section is required.',
                ['_health_section' => ['A maternal care section is required.']],
            );
        }

        $route['section'] = $section;
        $request = OfflineInnerRequestValidator::request(
            UpdateMaternalCareSectionRequest::class,
            $input,
            $route,
        );

        $this->maternal->updateSectionForResident(
            $resident,
            $section,
            $request->sectionPayloadForMerge(),
        );
    }
}
