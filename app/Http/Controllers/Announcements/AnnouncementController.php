<?php

namespace App\Http\Controllers\Announcements;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewAnnouncementReachRequest;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Http\Requests\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Services\AnnouncementStoreService;
use App\Support\AnnouncementPresenter;
use App\Support\UiRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('pages.announcements.index', [
            'active' => 'announcement',
            'pageTitle' => '',
            'pageSubtitle' => '',
            'manageAnnouncements' => AnnouncementPresenter::manage(),
            'upcomingAnnouncements' => AnnouncementPresenter::dashboardUpcoming(3),
            'recentAnnouncements' => AnnouncementPresenter::dashboardRecent(3),
            'summaryCards' => AnnouncementPresenter::summaryCards(),
        ]);
    }

    public function create(): View
    {
        return view('pages.announcements.create', [
            'active' => 'announcement',
            'pageTitle' => 'Add Announcement',
            'pageSubtitle' => 'Create and publish a health notice for residents.',
            'formMode' => 'create',
            'formValues' => [],
        ]);
    }

    public function upcoming(): View
    {
        return view('pages.announcements.upcoming', [
            'active' => 'announcement',
            'pageTitle' => '',
            'pageSubtitle' => '',
            'announcements' => AnnouncementPresenter::upcoming(),
        ]);
    }

    public function recent(): View
    {
        return view('pages.announcements.recent', [
            'active' => 'announcement',
            'pageTitle' => '',
            'pageSubtitle' => '',
            'announcements' => AnnouncementPresenter::recent(),
        ]);
    }

    public function store(
        StoreAnnouncementRequest $request,
        AnnouncementStoreService $service,
    ): RedirectResponse {
        $service->store($request->validated());

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement posted successfully.');
    }

    /**
     * Live matched-resident count for the composer reach preview.
     * Uses the same AnnouncementAudienceMatcher criteria as store/fan-out.
     */
    public function estimateReach(
        PreviewAnnouncementReachRequest $request,
        AnnouncementStoreService $service,
    ): JsonResponse {
        $count = $service->estimatedReachFromInput($request->validated());

        return response()->json([
            'estimated_reach' => $count,
        ]);
    }

    public function show(Announcement $announcement): View
    {
        return view('pages.announcements.show', [
            'active' => 'announcement',
            'pageTitle' => '',
            'pageSubtitle' => '',
            'announcement' => $announcement,
            'item' => AnnouncementPresenter::present($announcement),
            'coverageLabel' => AnnouncementPresenter::coverageLabel($announcement),
        ]);
    }

    public function edit(Announcement $announcement): View
    {
        return view('pages.announcements.create', [
            'active' => 'announcement',
            'pageTitle' => 'Edit Announcement',
            'pageSubtitle' => 'Update this health notice for residents.',
            'announcement' => $announcement,
            'formMode' => 'edit',
            'formValues' => AnnouncementPresenter::formValues($announcement),
        ]);
    }

    public function update(
        UpdateAnnouncementRequest $request,
        Announcement $announcement,
        AnnouncementStoreService $service,
    ): RedirectResponse {
        $service->update($announcement, $request->validated());

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement updated successfully.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $role = UiRole::current();

        if ($role === null || ! in_array($role, UiRole::ALLOWED, true)) {
            abort(403);
        }

        $announcement->delete();

        return redirect()
            ->route('announcements.index')
            ->with('status', 'Announcement deleted successfully.');
    }
}
