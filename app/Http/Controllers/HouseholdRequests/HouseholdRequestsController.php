<?php

namespace App\Http\Controllers\HouseholdRequests;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HouseholdLinkStaffActions;
use App\Support\HouseholdRecordRequestPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HouseholdRequestsController extends Controller
{
    private const ACTION_MESSAGES = [
        HouseholdLinkStaffActions::NOT_FOUND => 'Household request not found.',
        HouseholdLinkStaffActions::NOT_ALLOWED => 'This action is not available for the request in its current state.',
        HouseholdLinkStaffActions::RESIDENT_TAKEN => 'This resident is already linked to another resident account. Review that link before verifying this request.',
    ];

    public function index(): View
    {
        $requests = HouseholdRecordRequestPresenter::listingRows();

        return view('pages.household-requests.index', [
            'active' => 'household-requests',
            'pageTitle' => 'Household Requests',
            'pageSubtitle' => 'Monitor automatic household record verification history and results.',
            'requests' => $requests,
            'zoneOptions' => HouseholdRecordRequestPresenter::zoneFilterOptions(),
            'hasRecords' => count($requests) > 0,
        ]);
    }

    public function show(string $id): View
    {
        return view('pages.household-requests.view', [
            'active' => 'household-requests',
            'pageTitle' => 'Household Request Details',
            'pageSubtitle' => 'Automatic verification result for this household record access request.',
            'requestId' => $id,
            'demoRequest' => HouseholdRecordRequestPresenter::findByPublicId($id),
        ]);
    }

    /** Admin attests identity + mobile ownership were verified by the barangay procedure. */
    public function verifyIdentity(Request $request, string $id, HouseholdLinkStaffActions $actions): RedirectResponse
    {
        $request->validate([
            'identity_verified' => ['accepted'],
            'mobile_verified' => ['accepted'],
        ], [
            'identity_verified.accepted' => 'Confirm that the resident\'s identity was verified.',
            'mobile_verified.accepted' => 'Confirm that the mobile number on this request belongs to the verified resident.',
        ]);

        return $this->respond($id, $actions->verify($this->requestKey($id), $this->staff($request)),
            'Identity verified. The resident can now confirm the link with an SMS code sent to the verified number.');
    }

    public function decline(Request $request, string $id, HouseholdLinkStaffActions $actions): RedirectResponse
    {
        return $this->respond($id, $actions->decline($this->requestKey($id), $this->staff($request)),
            'Request declined. The resident was asked to visit the Barangay Health Station with a valid ID.');
    }

    public function revokeLink(Request $request, string $id, HouseholdLinkStaffActions $actions): RedirectResponse
    {
        return $this->respond($id, $actions->revoke($this->requestKey($id), $this->staff($request)),
            'Household link revoked. The resident account no longer has household access.');
    }

    private function requestKey(string $id): int
    {
        $key = HouseholdRecordRequestPresenter::requestIdFromPublicId($id);
        abort_if($key === null, 404);

        return $key;
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function respond(string $id, string $result, string $success): RedirectResponse
    {
        abort_if($result === HouseholdLinkStaffActions::NOT_FOUND, 404);

        $redirect = redirect()->route('household-requests.view', ['id' => $id]);

        return $result === HouseholdLinkStaffActions::OK
            ? $redirect->with('status', $success)
            : $redirect->with('error', self::ACTION_MESSAGES[$result] ?? self::ACTION_MESSAGES[HouseholdLinkStaffActions::NOT_ALLOWED]);
    }
}
