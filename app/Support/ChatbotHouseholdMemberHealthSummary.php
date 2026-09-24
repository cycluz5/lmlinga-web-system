<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Support\Facades\Schema;

/**
 * Resident Health Summary row availability for the chatbot member page.
 * Presentation only — detail routes still enforce ChatbotHouseholdMemberAccess.
 */
final class ChatbotHouseholdMemberHealthSummary
{
    public function __construct(
        private readonly ChildImmunizationService $childImmunization,
        private readonly SchoolImmunizationService $schoolImmunization,
        private readonly ChildNutritionService $childNutrition,
        private readonly DewormingRecordService $deworming,
        private readonly RiskAssessmentService $riskAssessment,
        private readonly FamilyPlanningVisitService $familyPlanning,
        private readonly MaternalPregnancyService $maternal,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     icon: string,
     *     applicable: bool,
     *     available: bool,
     *     route: string|null,
     *     status: string|null
     * }>
     */
    public function rowsForResident(Resident $resident): array
    {
        $maternalApplicable = MaternalCareEligibility::allows((string) ($resident->sex ?? ''));

        return [
            $this->row(
                key: 'childCare',
                label: 'Child Care',
                icon: 'bi-heart-pulse',
                applicable: true,
                available: $this->hasChildCareRecord($resident),
                routeName: 'chatbot.household.members.child-care',
            ),
            $this->row(
                key: 'riskAssessment',
                label: 'Risk Assessment',
                icon: 'bi-clipboard2-pulse',
                applicable: true,
                available: $this->riskAssessment->historyRowsForResident($resident) !== [],
                routeName: 'chatbot.household.members.risk-assessment',
            ),
            $this->row(
                key: 'familyPlanning',
                label: 'Family Planning',
                icon: 'bi-people',
                applicable: true,
                available: $this->familyPlanning->historyRowsForResident($resident) !== [],
                routeName: 'chatbot.household.members.family-planning',
            ),
            $this->row(
                key: 'maternal',
                label: 'Maternal',
                icon: 'bi-balloon-heart',
                applicable: $maternalApplicable,
                available: $maternalApplicable && $this->hasMaternalRecord($resident),
                routeName: 'chatbot.household.members.maternal',
            ),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     icon: string,
     *     applicable: bool,
     *     available: bool,
     *     route: string|null,
     *     status: string|null
     * }
     */
    private function row(
        string $key,
        string $label,
        string $icon,
        bool $applicable,
        bool $available,
        string $routeName,
    ): array {
        if (! $applicable) {
            return [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
                'applicable' => false,
                'available' => false,
                'route' => null,
                'status' => 'Not applicable.',
            ];
        }

        if (! $available) {
            return [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
                'applicable' => true,
                'available' => false,
                'route' => null,
                'status' => 'No record available.',
            ];
        }

        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'applicable' => true,
            'available' => true,
            'route' => $routeName,
            'status' => null,
        ];
    }

    private function hasChildCareRecord(Resident $resident): bool
    {
        $immunization = $this->childImmunization->forResident($resident);
        if (($immunization['persisted'] ?? false) === true) {
            return true;
        }

        $school = $this->schoolImmunization->forResident($resident);
        if (($school['persisted'] ?? false) === true) {
            return true;
        }

        $nutrition = $this->childNutrition->forResident($resident);
        if (($nutrition['persisted'] ?? false) === true) {
            return true;
        }

        if (ChildBirthHistoryService::presentationForResident($resident) !== null) {
            return true;
        }

        if (! Schema::hasTable('deworming_records')) {
            return false;
        }

        return $this->deworming->recordsForResident($resident) !== [];
    }

    private function hasMaternalRecord(Resident $resident): bool
    {
        return $this->maternal->hasAnyEpisode($resident);
    }
}
