<?php

namespace App\Http\Controllers\EnvironmentalHealth;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHouseholdWaterSupplyRequest;
use App\Http\Requests\StoreHouseholdWaterSupplyStep2Request;
use App\Http\Requests\StoreHouseholdWaterSupplyStep3Request;
use App\Http\Requests\StoreHouseholdWaterSupplyStep4Request;
use App\Models\Household;
use App\Services\HouseholdEnvironmentalProfileService;
use App\Services\SpotMappingHandoffService;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalHealthReturnContext;
use App\Support\Offline\OfflineFieldHasher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * DB19-D — wizard Steps 1–4 are MySQL-authoritative for real households.
 * DemoHouseholdWaterSupply remains for navigation/link helpers and demo-only keys.
 */
class HouseholdWaterSupplyController extends Controller
{
    public function __construct(
        private readonly SpotMappingHandoffService $handoff,
        private readonly HouseholdEnvironmentalProfileService $profiles,
    ) {}

    /**
     * Step 1 — Household Water Supply Information.
     * Authority comes from a consumed Spot Mapping handoff or verified linked context.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $handoffToken = trim((string) $request->query('handoff', ''));

        if ($handoffToken !== '') {
            $consumed = $this->handoff->consume($handoffToken);

            if ($consumed === null) {
                return redirect()
                    ->route('spot-mapping.index')
                    ->withErrors([
                        'handoff' => SpotMappingHandoffService::INVALID_MESSAGE,
                    ]);
            }

            $household = $consumed['household'];
            DemoHouseholdWaterSupply::linkFromHousehold(
                $household,
                $consumed['household_type'] ?? null
            );

            return redirect()->route('environmental-health.household-water-supply', [
                'household' => $household->household_no,
            ]);
        }

        $rawHouseholdNo = trim((string) $request->query('household', ''));

        if ($rawHouseholdNo === '') {
            return $this->step1View(null);
        }

        $household = DemoHouseholdWaterSupply::resolveLinkedHousehold($rawHouseholdNo);
        if ($household === null) {
            return redirect()
                ->route('spot-mapping.index')
                ->withErrors([
                    'handoff' => SpotMappingHandoffService::INVALID_MESSAGE,
                ]);
        }

        return $this->step1View($household);
    }

    /**
     * Persist Step 1 and continue to Step 2.
     */
    public function store(StoreHouseholdWaterSupplyRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $householdNo = (string) $validated['household_no'];

        DemoHouseholdWaterSupply::saveStep1($householdNo, $validated);

        return redirect()->route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]);
    }

    /**
     * Step 2 — Validation / Random Sampling / Testing (optional).
     */
    public function showStep2(string $householdNo): View|RedirectResponse
    {
        $gate = $this->guardWizardStep($householdNo, 1, [
            'message' => 'Please complete Household Water Supply Information (Step 1) before continuing to Step 2.',
            'fallbackToStep1' => true,
        ]);
        if ($gate instanceof RedirectResponse) {
            return $gate;
        }

        [$normalized, $savedRecord] = $gate;

        return view('pages.environmental-health.household-water-supply-step2', [
            'active' => 'spot-mapping',
            'pageTitle' => 'Spot Mapping',
            'pageSubtitle' => 'Complete the household environmental information after plotting the household location.',
            'householdNo' => $normalized,
            'savedRecord' => $savedRecord,
            'validationTestingStatus' => DemoHouseholdWaterSupply::validationTestingStatus($savedRecord),
            ...$this->offlineEnvPayloadByNo($normalized),
        ]);
    }

    /**
     * Persist optional Step 2 and continue to Basic Sanitation.
     */
    public function storeStep2(
        StoreHouseholdWaterSupplyStep2Request $request,
        string $householdNo
    ): RedirectResponse {
        $validated = $request->validated();
        $normalized = DemoHouseholdWaterSupply::normalizeHouseholdNo($householdNo);

        DemoHouseholdWaterSupply::saveStep2($normalized, $validated);

        return redirect()->route('environmental-health.household-water-supply.step3', [
            'householdNo' => $normalized,
        ]);
    }

    /**
     * Step 3 — Basic Sanitation Facility.
     */
    public function showStep3(string $householdNo): View|RedirectResponse
    {
        $gate = $this->guardWizardStep($householdNo, 2, [
            'message' => 'Please complete Validation / Random Sampling / Testing (Step 1.2) before continuing to Basic Sanitation Facility.',
            'fallbackStep' => 1,
            'fallbackMessage' => 'Please complete Household Water Supply Information (Step 1) before continuing.',
        ]);
        if ($gate instanceof RedirectResponse) {
            return $gate;
        }

        [$normalized, $savedRecord] = $gate;

        return view('pages.environmental-health.household-water-supply-step3', [
            'active' => 'spot-mapping',
            'pageTitle' => 'Spot Mapping',
            'pageSubtitle' => 'Complete the household environmental information after plotting the household location.',
            'householdNo' => $normalized,
            'savedRecord' => $savedRecord,
            ...$this->offlineEnvPayloadByNo($normalized),
        ]);
    }

    /**
     * Persist Basic Sanitation and continue to Solid Waste.
     */
    public function storeStep3(StoreHouseholdWaterSupplyStep3Request $request): RedirectResponse
    {
        $validated = $request->validated();
        $householdNo = (string) $validated['household_no'];

        DemoHouseholdWaterSupply::saveStep3($householdNo, $validated);

        return redirect()->route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]);
    }

    /**
     * Step 4 — Solid Waste Management.
     */
    public function showStep4(string $householdNo): View|RedirectResponse
    {
        $gate = $this->guardWizardStep($householdNo, 3, [
            'message' => 'Please complete Basic Sanitation Facility before continuing to Step 3.',
            'fallbackStep' => 2,
            'fallbackMessage' => 'Please complete Validation / Random Sampling / Testing (Step 1.2) before continuing.',
            'fallbackStep1Message' => 'Please complete Household Water Supply Information (Step 1) before continuing.',
        ]);
        if ($gate instanceof RedirectResponse) {
            return $gate;
        }

        [$normalized, $savedRecord] = $gate;

        return view('pages.environmental-health.household-water-supply-step4', [
            'active' => 'spot-mapping',
            'pageTitle' => 'Spot Mapping',
            'pageSubtitle' => 'Complete the household environmental information after plotting the household location.',
            'householdNo' => $normalized,
            'savedRecord' => $savedRecord,
            ...$this->offlineEnvPayloadByNo($normalized),
        ]);
    }

    /**
     * Persist Solid Waste Management.
     * Default: Spot Mapping. Household Profiling create may set a one-shot return context.
     */
    public function storeStep4(StoreHouseholdWaterSupplyStep4Request $request): RedirectResponse
    {
        $validated = $request->validated();
        $householdNo = (string) $validated['household_no'];

        DemoHouseholdWaterSupply::saveStep4($householdNo, $validated);

        if (EnvironmentalHealthReturnContext::consumeIfMatches($householdNo)) {
            return redirect()
                ->route('household-profiling.view', [
                    'householdNo' => $householdNo,
                ])
                ->with('status', 'Environmental health information saved.');
        }

        return redirect()
            ->route('spot-mapping.index')
            ->with('status', 'Household plotted successfully with environmental health information saved.');
    }

    private function step1View(?Household $household): View
    {
        $savedRecord = $household !== null
            ? $this->profiles->findPresentation($household)
            : null;

        return view('pages.environmental-health.household-water-supply', [
            'active' => 'spot-mapping',
            'pageTitle' => 'Spot Mapping',
            'pageSubtitle' => 'Complete the household environmental information after plotting the household location.',
            'householdNo' => $household !== null ? (string) $household->household_no : '',
            'household' => $household,
            'savedRecord' => $savedRecord,
            ...$this->offlineEnvPayload($household),
        ]);
    }

    /**
     * Shared wizard gate: valid number, completed_step from DB for real households,
     * actor-scoped recognition/link, then MySQL presentation (or demo find fallback).
     *
     * @param  array{message: string, fallbackToStep1?: bool, fallbackStep?: int, fallbackMessage?: string, fallbackStep1Message?: string}  $options
     * @return array{0: string, 1: array<string, mixed>|null}|RedirectResponse
     */
    private function guardWizardStep(string $householdNo, int $requiredStep, array $options): array|RedirectResponse
    {
        if (! DemoHouseholdWaterSupply::isValidHouseholdNo($householdNo)) {
            return redirect()
                ->route('spot-mapping.index')
                ->withErrors([
                    'handoff' => SpotMappingHandoffService::INVALID_MESSAGE,
                ]);
        }

        $normalized = DemoHouseholdWaterSupply::normalizeHouseholdNo($householdNo);

        if (! $this->hasCompletedWizardStep($normalized, $requiredStep)) {
            return $this->redirectIncompleteStep($normalized, $requiredStep, $options);
        }

        if (! DemoHouseholdWaterSupply::isRecognized($normalized)) {
            return redirect()
                ->route('spot-mapping.index')
                ->withErrors([
                    'handoff' => SpotMappingHandoffService::INVALID_MESSAGE,
                ]);
        }

        return [$normalized, $this->wizardSavedRecord($normalized)];
    }

    private function hasCompletedWizardStep(string $householdNo, int $step): bool
    {
        return match ($step) {
            1 => DemoHouseholdWaterSupply::hasCompletedStep1($householdNo),
            2 => DemoHouseholdWaterSupply::hasCompletedStep2($householdNo),
            3 => DemoHouseholdWaterSupply::hasCompletedStep3($householdNo),
            default => DemoHouseholdWaterSupply::hasCompletedStep4($householdNo),
        };
    }

    /**
     * @param  array{message: string, fallbackToStep1?: bool, fallbackStep?: int, fallbackMessage?: string, fallbackStep1Message?: string}  $options
     */
    private function redirectIncompleteStep(string $normalized, int $requiredStep, array $options): RedirectResponse
    {
        if ($requiredStep >= 3 && DemoHouseholdWaterSupply::hasCompletedStep2($normalized)) {
            return redirect()
                ->route('environmental-health.household-water-supply.step3', [
                    'householdNo' => $normalized,
                ])
                ->withErrors(['household_no' => $options['message']]);
        }

        if ($requiredStep >= 2 && DemoHouseholdWaterSupply::hasCompletedStep1($normalized)) {
            $message = $requiredStep === 2
                ? $options['message']
                : ($options['fallbackMessage'] ?? $options['message']);

            return redirect()
                ->route('environmental-health.household-water-supply.step2', [
                    'householdNo' => $normalized,
                ])
                ->withErrors(['household_no' => $message]);
        }

        $params = DemoHouseholdWaterSupply::isLinkedForActor($normalized)
            ? ['household' => $normalized]
            : [];

        $message = $options['fallbackStep1Message']
            ?? $options['fallbackMessage']
            ?? $options['message'];

        return redirect()
            ->route(
                $params === []
                    ? 'spot-mapping.index'
                    : 'environmental-health.household-water-supply',
                $params
            )
            ->withErrors(['household_no' => $message]);
    }

    /**
     * Presentation for wizard steps: persisted household environmental records only.
     *
     * @return array<string, mixed>|null
     */
    private function wizardSavedRecord(string $householdNo): ?array
    {
        $household = DemoHouseholdWaterSupply::resolveLinkedHousehold($householdNo)
            ?? DemoHouseholdWaterSupply::findDbHousehold($householdNo);

        if ($household === null) {
            return null;
        }

        return $this->profiles->findPresentation($household);
    }

    /**
     * @return array{offlineHouseholdPk?: int, offlineFieldHash?: string|null}
     */
    private function offlineEnvPayload(?Household $household): array
    {
        if ($household === null) {
            return [];
        }

        return [
            'offlineHouseholdPk' => (int) $household->getKey(),
            'offlineFieldHash' => OfflineFieldHasher::environmentalExists($household)
                ? OfflineFieldHasher::environmental($household)
                : null,
        ];
    }

    /**
     * @return array{offlineHouseholdPk?: int, offlineFieldHash?: string|null}
     */
    private function offlineEnvPayloadByNo(string $householdNo): array
    {
        return $this->offlineEnvPayload(
            DemoHouseholdWaterSupply::findDbHousehold($householdNo)
        );
    }
}
