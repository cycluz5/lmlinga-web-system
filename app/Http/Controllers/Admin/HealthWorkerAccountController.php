<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHealthWorkerRequest;
use App\Http\Requests\Admin\UpdateHealthWorkerRequest;
use App\Models\User;
use App\Services\HealthWorkerAccountService;
use App\Support\HealthWorkerUiCatalog;
use App\Support\Offline\OfflineFieldHasher;
use App\Support\ResidentAccountUiCatalog;
use App\Support\UserManagementErdMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HealthWorkerAccountController extends Controller
{
    public function __construct(
        private readonly HealthWorkerAccountService $accounts,
    ) {}

    public function index(): View
    {
        $workers = HealthWorkerUiCatalog::all();
        $residentAccounts = ResidentAccountUiCatalog::all();
        $isResidents = request()->query('tab') === 'residents';

        return view('pages.user-management.index', [
            'active' => 'user-management',
            'pageTitle' => 'User Management',
            'pageSubtitle' => $isResidents
                ? 'Manage user accounts and access permissions.'
                : 'Manage accounts of the Barangay Health Workers',
            'healthWorkers' => $workers,
            'residentAccounts' => $residentAccounts,
        ]);
    }

    public function create(): View
    {
        return view('pages.user-management.health-worker-create', [
            'active' => 'user-management',
            'pageTitle' => 'Create Account',
            'pageSubtitle' => 'Add a Barangay Health Worker account with a temporary password.',
        ]);
    }

    /**
     * Slim Create Account — persists authentication account + role only.
     * Does not manufacture personal/employment profile demographics.
     */
    public function store(StoreHealthWorkerRequest $request): RedirectResponse
    {
        $user = $this->accounts->createFromSlimForm($request->validated());

        return redirect()
            ->route('user-management.index', ['tab' => 'workers'])
            ->with('status', 'Health Worker account created successfully.');
    }

    public function edit(string $id): View
    {
        $this->abortIfDeletedStaff($id);

        $worker = HealthWorkerUiCatalog::find($id);
        $mutableUser = HealthWorkerUiCatalog::findMutableUser($id);

        return view('pages.user-management.health-worker-edit', [
            'active' => 'user-management',
            'pageTitle' => 'Edit Account Details',
            'pageSubtitle' => "Update the selected health worker's profile and account information.",
            'workerId' => $id,
            'demoWorker' => $worker,
            'workerIsMutable' => $mutableUser !== null,
            'offlineParentUserId' => $mutableUser?->getKey(),
            'offlineFieldHash' => $mutableUser !== null ? OfflineFieldHasher::healthWorker($mutableUser) : null,
        ]);
    }

    public function update(UpdateHealthWorkerRequest $request, string $id): RedirectResponse
    {
        $user = HealthWorkerUiCatalog::findMutableUser($id);

        if ($user === null) {
            return redirect()
                ->route('user-management.health-workers.edit', ['id' => $id])
                ->withErrors([
                    'hw_email' => 'This account could not be updated. Persistable accounts use numeric user IDs.',
                ]);
        }

        $updated = $this->accounts->update($user, $request->validated());

        return redirect()
            ->route('user-management.health-workers.view', ['id' => (string) $updated->id])
            ->with('status', 'Health Worker information updated successfully.');
    }

    public function show(string $id): View
    {
        $this->abortIfDeletedStaff($id);

        $worker = HealthWorkerUiCatalog::find($id);

        return view('pages.user-management.health-worker-view', [
            'active' => 'user-management',
            'pageTitle' => 'View Health Worker Information',
            'pageSubtitle' => "Review the selected health worker's personal, contact, address, employment, and account information.",
            'workerId' => $id,
            'demoWorker' => $worker,
        ]);
    }

    /**
     * Deactivate TARGET Health Worker login access.
     * Never hard-deletes the staff row. Never logs out or rotates CSRF for the ACTOR Admin.
     */
    public function deactivate(string $id): RedirectResponse
    {
        $actorId = $this->currentActorSessionUserId();
        $target = HealthWorkerUiCatalog::findMutableUser($id);

        if ($target === null) {
            return redirect()
                ->route('user-management.index', ['tab' => 'workers'])
                ->withErrors([
                    'hw_status' => 'This account could not be deactivated. Persistable accounts use numeric user IDs.',
                ]);
        }

        $this->accounts->deactivateAccount($target);
        $this->assertActorSessionUnchanged($actorId);

        return redirect()
            ->route('user-management.index', ['tab' => 'workers'])
            ->with('status', 'Account access has been deactivated. Historical records were preserved.');
    }

    /**
     * Activate TARGET Health Worker login access.
     * Never authenticates the target. Never logs out or rotates CSRF for the ACTOR Admin.
     */
    public function activate(string $id): RedirectResponse
    {
        $actorId = $this->currentActorSessionUserId();
        $target = HealthWorkerUiCatalog::findMutableUser($id);

        if ($target === null) {
            return redirect()
                ->route('user-management.index', ['tab' => 'workers'])
                ->withErrors([
                    'hw_status' => 'This account could not be activated. Persistable accounts use numeric user IDs.',
                ]);
        }

        $this->accounts->activateAccount($target);
        $this->assertActorSessionUnchanged($actorId);

        return redirect()
            ->route('user-management.index', ['tab' => 'workers'])
            ->with('status', 'Account access has been activated.');
    }

    /**
     * Soft-delete TARGET login/access. Never hard-deletes identity or history.
     */
    public function destroy(string $id): RedirectResponse
    {
        $actorId = $this->currentActorSessionUserId();
        $target = HealthWorkerUiCatalog::findMutableUser($id);

        if ($target === null) {
            $this->abortIfDeletedStaff($id);

            return redirect()
                ->route('user-management.index', ['tab' => 'workers'])
                ->withErrors([
                    'hw_status' => 'This account could not be deleted. Persistable accounts use numeric user IDs.',
                ]);
        }

        $this->accounts->deleteAccount($target);
        $this->assertActorSessionUnchanged($actorId);

        return redirect()
            ->route('user-management.index', ['tab' => 'workers'])
            ->with('status', 'Account has been deleted. Historical records were preserved.');
    }

    /**
     * ACTOR = session-authenticated Admin performing the User Management action.
     */
    private function currentActorSessionUserId(): int|string|null
    {
        $fromSession = session()->get(Auth::guard()->getName());
        if ($fromSession !== null && $fromSession !== '') {
            return $fromSession;
        }

        $actor = Auth::user();

        return $actor instanceof User ? $actor->getAuthIdentifier() : null;
    }

    /**
     * Ensure ACTOR session id is still the pre-captured Admin after TARGET mutation.
     * Does not Auth::login() / regenerate / invalidate — only rebinds if drift occurred.
     */
    private function assertActorSessionUnchanged(int|string|null $actorId): void
    {
        if ($actorId === null || $actorId === '') {
            return;
        }

        $sessionKey = Auth::guard()->getName();
        $currentId = session()->get($sessionKey) ?? Auth::id();

        if ((string) $currentId === (string) $actorId && Auth::check()) {
            return;
        }

        $fresh = User::query()->whereKey($actorId)->first();
        if (! $fresh instanceof User || ! $fresh->isActive()) {
            return;
        }

        Auth::setUser($fresh);
        session([$sessionKey => $fresh->getAuthIdentifier()]);
    }

    private function abortIfDeletedStaff(string $id): void
    {
        $id = trim($id);
        if (! ctype_digit($id) || ! UserManagementErdMode::staffHasDeletedAt()) {
            return;
        }

        $deleted = User::queryWithDeleted()
            ->whereKey((int) $id)
            ->whereNotNull('deleted_at')
            ->exists();

        if ($deleted) {
            abort(404, 'Health worker not found.');
        }
    }
}
