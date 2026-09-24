<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueSpotMappingHandoffRequest;
use App\Http\Requests\StoreSpotMappingHouseholdRequest;
use App\Http\Requests\UpdateSpotMappingCoordinatesRequest;
use App\Models\Resident;
use App\Services\HouseholdService;
use App\Services\ResidentService;
use App\Services\SpotMappingHandoffService;
use App\Services\SpotMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * DB18-C/D — Spot Mapping index, coordinate update, and EH handoff.
 */
class SpotMappingController extends Controller
{
    public function __construct(
        private readonly SpotMappingService $spotMapping,
        private readonly SpotMappingHandoffService $handoff,
    ) {}

    public function index(): View
    {
        $payload = $this->spotMapping->indexPayload();

        return view('pages.spot-mapping.index', [
            'active' => 'spot-mapping',
            'pageTitle' => 'Spot Mapping',
            'pageSubtitle' => 'Real-Time Visualization and Status Tracking for Households in the Barangay.',
            'stats' => $payload['stats'],
            'markers' => $payload['markers'],
            'pendingCandidates' => $payload['pendingCandidates'],
        ]);
    }

    /**
     * Persist latitude/longitude for an existing active household.
     * Does not create households; does not write type/consent/environmental fields.
     */
    public function updateCoordinates(UpdateSpotMappingCoordinatesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->spotMapping->updateCoordinates(
            (string) $validated['household_no'],
            (float) $validated['lat'],
            (float) $validated['lng'],
            (bool) ($validated['confirm_replot'] ?? false),
        );

        if ($result === null) {
            return response()->json([
                'message' => SpotMappingService::GENERIC_PLOT_FAILURE,
            ], 422);
        }

        return response()->json([
            'message' => 'Household coordinates saved.',
            'marker' => $result['marker'],
            'stats' => $result['stats'],
            'household_no' => (string) $result['household']->household_no,
        ]);
    }

    /**
     * Create a new household + household-head resident with captured coordinates,
     * then issue Environmental Health handoff. Does not persist house_head as a column.
     */
    public function plotNewHousehold(
        StoreSpotMappingHouseholdRequest $request,
        HouseholdService $households,
        ResidentService $residents,
    ): JsonResponse {
        $created = $households->createWithHead(
            $request->householdAttributes(),
            $request->headAttributes(),
            $residents,
        );

        $household = $created['household'];
        $token = $this->handoff->issueForHousehold(
            $household,
            (string) $request->validated('household_type'),
        );

        $residentKey = (new Resident)->getKeyName();
        $household->load(['residents' => fn ($q) => $q->orderBy($residentKey)]);

        return response()->json([
            'handoff_token' => $token,
            'redirect_url' => route('environmental-health.household-water-supply', [
                'handoff' => $token,
            ]),
            'marker' => $this->spotMapping->presentMarker($household),
            'stats' => $this->spotMapping->stats(),
            'household_no' => (string) $household->household_no,
            'expires_in_seconds' => SpotMappingHandoffService::TTL_MINUTES * 60,
        ]);
    }

    /**
     * Single write: persist coordinates on an existing household, establish EH handoff.
     */
    public function plotAndHandoff(IssueSpotMappingHandoffRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->spotMapping->updateCoordinates(
            (string) $validated['household_no'],
            (float) $validated['lat'],
            (float) $validated['lng'],
            (bool) ($validated['confirm_replot'] ?? false),
        );

        if ($result === null) {
            return response()->json([
                'message' => SpotMappingService::GENERIC_PLOT_FAILURE,
            ], 422);
        }

        $household = $result['household'];
        // DB19-C: carry validated UI type as transient handoff context only — never write to households.
        $token = $this->handoff->issueForHousehold(
            $household,
            isset($validated['household_type']) ? (string) $validated['household_type'] : null
        );

        return response()->json([
            'handoff_token' => $token,
            'redirect_url' => route('environmental-health.household-water-supply', [
                'handoff' => $token,
            ]),
            'marker' => $result['marker'],
            'stats' => $result['stats'],
            'household_no' => (string) $household->household_no,
            'expires_in_seconds' => SpotMappingHandoffService::TTL_MINUTES * 60,
        ]);
    }
}
