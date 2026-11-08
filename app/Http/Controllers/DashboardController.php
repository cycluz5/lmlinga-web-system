<?php

namespace App\Http\Controllers;

use App\Services\SpotMappingService;
use Illuminate\View\View;

/**
 * Dashboard home — read-only. Mapped households come from SpotMappingService
 * (same plotted marker query as Spot Mapping). This action never writes.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly SpotMappingService $spotMapping,
    ) {}

    public function index(): View
    {
        return view('pages.dashboard.index', [
            'active' => 'dashboard',
            'pageTitle' => 'Dashboard',
            'markers' => $this->spotMapping->mappedMarkers(),
        ]);
    }
}
