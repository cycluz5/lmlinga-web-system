<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateResidentAccountRequest;
use App\Support\ResidentAccountUiCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Admin User Management → Residents tab (portal accounts only).
 * Does not mutate official residents / chatbot auth flows.
 */
class ResidentAccountController extends Controller
{
    public function show(string $id): View|RedirectResponse
    {
        if (preg_match('/^res-\d+$/', $id) === 1) {
            return redirect()->route('household-requests.view', ['id' => $id], 301);
        }

        $resident = ResidentAccountUiCatalog::find($id);

        return view('pages.user-management.residents.view', [
            'active' => 'user-management',
            'pageTitle' => 'Resident Information',
            'pageSubtitle' => 'Manage user accounts and access permissions.',
            'residentId' => $id,
            'demoResident' => $resident,
        ]);
    }

    public function edit(string $id): View
    {
        $resident = ResidentAccountUiCatalog::find($id);

        return view('pages.user-management.residents.edit', [
            'active' => 'user-management',
            'pageTitle' => 'Edit Resident Information',
            'pageSubtitle' => 'Manage user accounts and access permissions.',
            'residentId' => $id,
            'demoResident' => $resident,
        ]);
    }

    public function update(UpdateResidentAccountRequest $request, string $id): RedirectResponse
    {
        $account = ResidentAccountUiCatalog::findAccount($id);
        if ($account === null) {
            abort(404, 'Resident account not found.');
        }

        // Safe portal metadata only — never relink resident_id or touch password.
        $account->fill($request->safeAccountAttributes());
        $account->save();

        return redirect()
            ->route('user-management.residents.view', ['id' => ResidentAccountUiCatalog::publicId($account->getKey())])
            ->with('status', 'Resident account updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $account = ResidentAccountUiCatalog::findAccount($id);
        if ($account === null) {
            abort(404, 'Resident account not found.');
        }

        $account->delete();

        return redirect()
            ->route('user-management.index', ['tab' => 'residents'])
            ->with('status', 'Resident account deleted successfully.');
    }
}
