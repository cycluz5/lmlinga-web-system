<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTimbangRecordRequest;
use App\Models\Resident;
use App\Services\NutritionAssessmentService;
use App\Support\HealthMemberIdentity;
use App\Support\TimbangRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Resident-based, age-adaptive Nutritional Status (FR-12).
 *
 * Applies to every resident — infants, children, adolescents, adults, and
 * the elderly — not only children. Which indicators are shown/enabled and
 * how they are classified is driven entirely by NutritionAssessmentService,
 * computed from the resident's birthday + the measurement date. This
 * controller never contains classification logic itself.
 */
class NutritionalStatusController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly TimbangRecordService $service,
        private readonly NutritionAssessmentService $assessment,
    ) {}

    public function index(string $householdNo, string $memberId): View
    {
        return $this->renderIndex($this->identity->resolve($householdNo, $memberId), 'member');
    }

    public function create(string $householdNo, string $memberId): View
    {
        return $this->renderCreate($this->identity->resolve($householdNo, $memberId), 'member');
    }

    public function store(
        StoreTimbangRecordRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);
        $this->persist($ctx['resident'], $request);

        return redirect()
            ->route('household-profiling.members.nutritional-status', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Measurement saved.');
    }

    /*
     | Canonical resident-based destinations (/households/{householdNo}/residents/{residentId}/...).
     | Database-only: residentId is the resident's primary key, never a synthetic MB-xxx
     | identifier. Kept separate from the members/{memberId} methods above so the legacy
     | URL and its behavior (including redirect target) stay unchanged.
     */
    public function indexByResident(string $householdNo, string $residentId): View
    {
        return $this->renderIndex($this->identity->resolveByResidentId($householdNo, (int) $residentId), 'resident');
    }

    public function createByResident(string $householdNo, string $residentId): View
    {
        return $this->renderCreate($this->identity->resolveByResidentId($householdNo, (int) $residentId), 'resident');
    }

    public function storeByResident(
        StoreTimbangRecordRequest $request,
        string $householdNo,
        string $residentId,
    ): RedirectResponse {
        $ctx = $this->identity->resolveResidentPersistedOrFail($householdNo, (int) $residentId);
        $this->persist($ctx['resident'], $request);

        return redirect()
            ->route('households.residents.nutritional-status', [
                'householdNo' => $ctx['householdNo'],
                'residentId' => $ctx['resident']->getKey(),
            ])
            ->with('status', 'Measurement saved.');
    }

    public function previewAssessment(Request $request, string $householdNo, string $memberId): JsonResponse
    {
        return $this->previewResponse($request, $this->identity->resolve($householdNo, $memberId));
    }

    public function previewAssessmentByResident(Request $request, string $householdNo, string $residentId): JsonResponse
    {
        return $this->previewResponse($request, $this->identity->resolveByResidentId($householdNo, (int) $residentId));
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function renderIndex(array $ctx, string $urlStyle): View
    {
        $persistenceSource = HealthMemberIdentity::persistenceSource($ctx);
        $history = [];

        if ($ctx['resident'] !== null) {
            $history = $this->service->historyPresentationForResident($ctx['resident']);
        }

        return view('pages.household-profiling.nutritional-status', [
            'active' => 'household-profiling',
            'pageTitle' => 'Nutritional Status',
            'pageSubtitle' => $ctx['member']
                ? 'Track growth measurements for '.$ctx['member']['name'].' in '.$ctx['householdNo'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'residentId' => $ctx['resident']?->getKey(),
            'urlStyle' => $urlStyle,
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'persistenceSource' => $persistenceSource,
            'history' => $history,
            'memberProfileUrl' => $this->memberProfileUrl($ctx),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function renderCreate(array $ctx, string $urlStyle): View
    {
        $persistenceSource = HealthMemberIdentity::persistenceSource($ctx);
        $resident = $ctx['resident'];

        return view('pages.household-profiling.nutritional-status-form', [
            'active' => 'household-profiling',
            'pageTitle' => 'Add Measurement',
            'pageSubtitle' => $ctx['member']
                ? 'Add a nutritional measurement for '.$ctx['member']['name'].'.'
                : 'No record found.',
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'residentId' => $resident?->getKey(),
            'residentBirthday' => $resident?->birthday?->toDateString(),
            'urlStyle' => $urlStyle,
            'demoHousehold' => $ctx['household'],
            'demoMember' => $ctx['member'],
            'persistenceSource' => $persistenceSource,
            'assessment' => $resident instanceof Resident
                ? $this->assessment->assessForToday($resident)
                : null,
            'memberProfileUrl' => $this->memberProfileUrl($ctx),
            'previewUrl' => $urlStyle === 'resident'
                ? route('households.residents.nutritional-status.preview', [
                    'householdNo' => $ctx['householdNo'],
                    'residentId' => $resident?->getKey(),
                ])
                : route('household-profiling.members.nutritional-status.preview', [
                    'householdNo' => $ctx['householdNo'],
                    'memberId' => $ctx['memberId'],
                ]),
        ]);
    }

    /**
     * Always the legacy MB-xxx member_no — never the raw residentId, even
     * when $ctx came from the resident-based routes — because
     * household-profiling.members.show only accepts the MB-xxx shape.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function memberProfileUrl(array $ctx): ?string
    {
        $memberNo = $ctx['member']['id'] ?? null;
        if ($memberNo === null) {
            return null;
        }

        return route('household-profiling.members.show', [
            'householdNo' => $ctx['householdNo'],
            'memberId' => $memberNo,
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function previewResponse(Request $request, array $ctx): JsonResponse
    {
        $resident = $ctx['resident'];
        if (! $resident instanceof Resident) {
            return response()->json(['found' => false]);
        }

        $measurementDate = trim((string) $request->query('measurement_date', ''));
        if ($measurementDate === '') {
            $measurementDate = now()->toDateString();
        }

        $measurement = [
            'measurement_date' => $measurementDate,
            'weight_kg' => $this->previewDecimal($request->query('weight_kg')),
            'height_cm' => $this->previewDecimal($request->query('height_cm')),
            'muac_cm' => $this->previewDecimal($request->query('muac_cm')),
        ];

        try {
            $result = $this->assessment->assess($resident, $measurement);
        } catch (ValidationException) {
            // MUAC outside its applicable band — preview as if it were absent
            // rather than surfacing a form error from a background request.
            $measurement['muac_cm'] = null;
            $result = $this->assessment->assess($resident, $measurement);
        }

        return response()->json(array_merge($result, ['found' => true]));
    }

    private function previewDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || ! is_numeric($trimmed) || (float) $trimmed <= 0) {
            return null;
        }

        return $trimmed;
    }

    private function persist(Resident $resident, StoreTimbangRecordRequest $request): void
    {
        $rawPayload = $this->service->normalizeValidated($request->validated());
        $result = $this->assessment->assess($resident, $rawPayload);

        $this->service->createForResident($resident, array_merge($rawPayload, [
            'weight_for_age' => $result['weight_for_age'],
            'height_for_age' => $result['height_for_age'],
            'weight_for_height' => $result['weight_for_height'],
            'muac_status' => $result['muac_status'],
            'bmi_value' => $result['bmi_value'],
            'bmi_status' => $result['bmi_status'],
            'overall_nutritional_status' => $result['overall_nutritional_status'],
        ]));
    }
}
