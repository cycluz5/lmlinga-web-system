<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Support\ChatbotHouseholdMemberAccess;
use App\Support\ChildBirthHistoryService;
use App\Support\ChildImmunizationService;
use App\Support\ChildNutritionService;
use App\Support\DewormingRecordService;
use App\Support\FamilyPlanningVisitService;
use App\Support\HouseholdProfilingPresenter;
use App\Support\MaternalCareEligibility;
use App\Support\MaternalPregnancyService;
use App\Support\RiskAssessmentService;
use App\Support\SchoolImmunizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Resident-facing GET-only health module pages for verified household members.
 */
class HouseholdMemberHealthController extends Controller
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

    public function childCare(Request $request, string $member): View|RedirectResponse
    {
        $ctx = $this->authorizeMember($request, $member);
        if ($ctx instanceof RedirectResponse) {
            return $ctx;
        }

        $resident = $ctx['resident'];
        $immunization = $this->childImmunization->forResident($resident);
        $school = $this->schoolImmunization->forResident($resident);
        $nutrition = $this->childNutrition->forResident($resident);
        $birthHistory = ChildBirthHistoryService::presentationForResident($resident);
        $dewormingRows = Schema::hasTable('deworming_records')
            ? $this->deworming->recordsForResident($resident)
            : [];

        return view('pages.chatbot.household-member-health-child-care', [
            'memberId' => (string) $resident->getKey(),
            'memberName' => $ctx['memberName'],
            'moduleTitle' => 'Child Care',
            'birthHistory' => $birthHistory,
            'immunization' => $immunization,
            'immunizationDoses' => $this->flattenVaccineDoses($immunization['vaccines'] ?? []),
            'school' => $school,
            'schoolDoses' => $this->flattenVaccineDoses($school['vaccines'] ?? []),
            'nutrition' => $nutrition,
            'dewormingRows' => $dewormingRows,
        ]);
    }

    public function riskAssessment(Request $request, string $member): View|RedirectResponse
    {
        $ctx = $this->authorizeMember($request, $member);
        if ($ctx instanceof RedirectResponse) {
            return $ctx;
        }

        $rows = $this->riskAssessment->historyRowsForResident($ctx['resident']);

        return view('pages.chatbot.household-member-health-risk-assessment', [
            'memberId' => (string) $ctx['resident']->getKey(),
            'memberName' => $ctx['memberName'],
            'moduleTitle' => 'Risk Assessment',
            'rows' => $rows,
        ]);
    }

    public function familyPlanning(Request $request, string $member): View|RedirectResponse
    {
        $ctx = $this->authorizeMember($request, $member);
        if ($ctx instanceof RedirectResponse) {
            return $ctx;
        }

        $rows = $this->familyPlanning->historyRowsForResident($ctx['resident']);

        return view('pages.chatbot.household-member-health-family-planning', [
            'memberId' => (string) $ctx['resident']->getKey(),
            'memberName' => $ctx['memberName'],
            'moduleTitle' => 'Family Planning',
            'rows' => $rows,
        ]);
    }

    public function maternal(Request $request, string $member): View|RedirectResponse
    {
        $ctx = $this->authorizeMember($request, $member);
        if ($ctx instanceof RedirectResponse) {
            return $ctx;
        }

        $resident = $ctx['resident'];
        if (! MaternalCareEligibility::allows((string) ($resident->sex ?? ''))) {
            abort(404);
        }

        $active = $this->maternal->activePresentationForResident($resident);
        $history = $this->maternal->historyRowsForResident($resident);

        return view('pages.chatbot.household-member-health-maternal', [
            'memberId' => (string) $resident->getKey(),
            'memberName' => $ctx['memberName'],
            'moduleTitle' => 'Maternal Care',
            'active' => $active,
            'history' => $history,
        ]);
    }

    /**
     * @return array{resident: Resident, memberName: string}|RedirectResponse
     */
    private function authorizeMember(Request $request, string $member): array|RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');
        abort_unless($account instanceof ResidentAccount, 403);

        $resolved = ChatbotHouseholdMemberAccess::resolveAuthorizedMember($account, $member);
        if ($resolved === null) {
            return redirect()->route('chatbot.main');
        }

        /** @var Resident $resident */
        $resident = $resolved['resident'];
        $presented = HouseholdProfilingPresenter::memberFromModel($resident);

        return [
            'resident' => $resident,
            'memberName' => (string) ($presented['name'] ?? 'Resident'),
        ];
    }

    /**
     * @param  array<string, mixed>  $vaccines
     * @return list<array{label: string, value: string}>
     */
    private function flattenVaccineDoses(array $vaccines): array
    {
        $rows = [];

        foreach ($vaccines as $type => $doses) {
            if (! is_array($doses)) {
                continue;
            }

            foreach ($doses as $index => $date) {
                $date = trim((string) $date);
                if ($date === '') {
                    continue;
                }

                $label = strtoupper((string) $type);
                if (is_numeric($index) || (is_string($index) && $index !== '')) {
                    $label .= ' · Dose '.(string) $index;
                }

                $rows[] = [
                    'label' => $label,
                    'value' => $date,
                ];
            }
        }

        return $rows;
    }
}
