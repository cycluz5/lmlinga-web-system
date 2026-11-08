<?php

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use App\Support\Offline\OfflineEnvironmentalHealthShell;
use Illuminate\View\View;

/**
 * Authenticated, household-independent render of the production EH wizard views.
 *
 * Used only to cache a canonical shell while online. Offline hydration rewrites
 * the placeholder household_no. Does not query or insert MySQL households.
 */
class OfflineEnvironmentalHealthShellController extends Controller
{
    public function show(int $step): View
    {
        abort_unless(in_array($step, OfflineEnvironmentalHealthShell::STEPS, true), 404);

        $householdNo = OfflineEnvironmentalHealthShell::PLACEHOLDER_HOUSEHOLD_NO;
        $shared = [
            'active' => 'spot-mapping',
            'pageTitle' => 'Spot Mapping',
            'pageSubtitle' => 'Complete the household environmental information after plotting the household location.',
            'householdNo' => $householdNo,
            'savedRecord' => null,
        ];

        return match ($step) {
            1 => view('pages.environmental-health.household-water-supply', [
                ...$shared,
                'household' => null,
            ]),
            2 => view('pages.environmental-health.household-water-supply-step2', $shared),
            3 => view('pages.environmental-health.household-water-supply-step3', $shared),
            4 => view('pages.environmental-health.household-water-supply-step4', $shared),
        };
    }
}
