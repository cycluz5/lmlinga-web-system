<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateHouseholdAmenitiesRequest;
use App\Services\HouseholdEnvironmentalProfileService;
use App\Support\DemoCatalog;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\HouseholdMemberResolver;
use App\Support\HouseholdProfilingPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Amenities View/Edit shares the same environmental profile as the EH wizard.
 * Unknown households render not-found. DemoCatalog is never substituted.
 */
class HouseholdAmenitiesController extends Controller
{
    public function __construct(
        private readonly HouseholdMemberResolver $resolver,
        private readonly HouseholdEnvironmentalProfileService $profiles,
    ) {}

    public function show(string $householdNo): View
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $resolved = $this->resolver->resolveHousehold($key);

        if ($resolved === null) {
            return view('pages.household-profiling.amenities-show', [
                'active' => 'household-profiling',
                'pageTitle' => 'Household Profiling',
                'pageSubtitle' => 'Household was not found.',
                'householdNo' => $key,
                'demoHousehold' => null,
                'amenitiesRecord' => null,
                'linkedContext' => null,
                'householdSource' => null,
                'socioeconomicStatus' => 'Not Yet Determined',
                'validationTestingStatus' => 'not_yet_determined',
                'completeSanitationStatus' => 'Not Yet Determined',
            ]);
        }

        $household = $resolved['household'];
        // MySQL wins — never overlay demo/session environmental values for real households.
        $record = $this->profiles->findPresentation($household);
        $presentation = $resolved['presentation'];

        return view('pages.household-profiling.amenities-show', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Review household amenities details.',
            'householdNo' => $key,
            'demoHousehold' => $presentation,
            'amenitiesRecord' => $record,
            'linkedContext' => DemoHouseholdWaterSupply::findLinkedForActor($key),
            'householdSource' => 'db',
            'socioeconomicStatus' => DemoHouseholdWaterSupply::socioeconomicStatusLabel($record, $presentation),
            'validationTestingStatus' => DemoHouseholdWaterSupply::validationTestingStatus($record),
            'completeSanitationStatus' => DemoHouseholdWaterSupply::deriveCompleteSanitationFacilityStatus($record),
        ]);
    }

    public function edit(string $householdNo): View|RedirectResponse
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);

        try {
            $household = $this->resolver->resolveDbHouseholdOrFail($key);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return redirect()
                ->route('household-profiling.amenities.show', ['householdNo' => $key])
                ->withErrors([
                    'household_no' => 'Amenities can only be edited for registered database households.',
                ]);
        }

        $presentation = HouseholdProfilingPresenter::fromModel($household->load('residents'));
        $record = $this->profiles->findPresentation($household);

        return view('pages.household-profiling.amenities-edit', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Edit household amenities details.',
            'householdNo' => $key,
            'demoHousehold' => $presentation,
            'amenitiesRecord' => $record,
            'linkedContext' => DemoHouseholdWaterSupply::findLinkedForActor($key),
            'householdSource' => 'db',
            'socioeconomicStatus' => DemoHouseholdWaterSupply::socioeconomicStatusLabel(
                $record,
                $presentation
            ),
            'offlineHouseholdPk' => (int) $household->getKey(),
            'offlineFieldHash' => \App\Support\Offline\OfflineFieldHasher::environmentalExists($household)
                ? \App\Support\Offline\OfflineFieldHasher::environmental($household)
                : null,
        ]);
    }

    public function update(UpdateHouseholdAmenitiesRequest $request, string $householdNo): RedirectResponse
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $household = $this->resolver->resolveDbHouseholdOrFail($key);

        // Same MySQL profile / solid-waste row as the EH wizard (saveAll → steps 1–4).
        $this->profiles->saveAll($household, $request->validated());
        DemoHouseholdWaterSupply::forgetSessionEnvironmentalRecord($key);

        return redirect()
            ->route('household-profiling.amenities.show', ['householdNo' => $key])
            ->with('status', 'Household amenities details saved successfully.');
    }
}
