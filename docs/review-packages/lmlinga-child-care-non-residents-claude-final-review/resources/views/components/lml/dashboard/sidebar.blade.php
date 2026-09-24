{{--
    Dashboard sidebar — shared role-aware navigation shell.
    Menu order and visibility are centralized here. Role comes from the layout (UiRole).
--}}
@props([
    'role' => 'admin',
    'active' => 'dashboard',
    'facilityLabel' => 'Health Center',
    'items' => null,
])

@php
    /*
     | Admin order:
     | Dashboard → User Management → Household Requests → Spot Mapping →
     | Household Profiling → Environmental Health → Health Records (expandable).
     |
     | Health Worker order (after role filter):
     | Dashboard → Spot Mapping → Household Profiling → Environmental Health →
     | Health Records (expandable).
     |
     | Admin-only: User Management, Household Requests.
     |
     | Health Records children (exact order):
     | Child Care → Risk Assessment → Family Planning → Maternal → Death.
     |
     | Immunizations, Operation Timbang, Vitamin A, and Deworming are intentionally
     | removed from this dropdown; they remain application features for a future
     | Child Care internal navigation — not implemented here.
     |
     | Child Immunization / Birth History stay under Household Profiling and must
     | never be remapped as Health Records → Child Care destinations.
     */
    $resolveNamedHref = static function (array $routeNames): ?string {
        foreach ($routeNames as $routeName) {
            if (\Illuminate\Support\Facades\Route::has($routeName)) {
                return route($routeName);
            }
        }

        return null;
    };

    $defaultItems = [
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'icon' => 'bi-grid-1x2-fill',
            'href' => route('dashboard'),
            'roles' => ['admin', 'bhw', 'bns', 'bspo'],
        ],
        [
            'key' => 'user-management',
            'label' => 'User Management',
            'icon' => 'bi-person-fill',
            'href' => route('user-management.index'),
            'roles' => ['admin'],
        ],
        [
            'key' => 'household-requests',
            'label' => 'Household Requests',
            'icon' => 'bi-house-add',
            'href' => route('household-requests.index'),
            'roles' => ['admin'],
        ],
        [
            'key' => 'spot-mapping',
            'label' => 'Spot Mapping',
            'icon' => 'bi-geo-alt-fill',
            'href' => route('spot-mapping.index'),
            'roles' => ['admin', 'bhw', 'bns', 'bspo'],
        ],
        [
            'key' => 'household-profiling',
            'label' => 'Household Profiling',
            'icon' => 'bi-house-door-fill',
            'href' => route('household-profiling.index'),
            'roles' => ['admin', 'bhw', 'bns', 'bspo'],
        ],
        [
            'key' => 'environmental-health',
            'label' => 'Environmental Health',
            'icon' => 'bi-tree-fill',
            'href' => route('environmental-health.index'),
            'roles' => ['admin', 'bhw', 'bns', 'bspo'],
        ],
        [
            'key' => 'health-records',
            'label' => 'Health Records',
            'icon' => 'bi-folder2-open',
            'type' => 'collapse',
            'roles' => ['admin', 'bhw', 'bns', 'bspo'],
            'children' => [
                [
                    'key' => 'child-care',
                    'label' => 'Child Care',
                    'icon' => 'bi-heart-pulse',
                    /*
                     | BLOCKER: no canonical named route for Health Records → Child Care.
                     | Do not reuse household-profiling.members.child-immunization /
                     | birth-history / school-based-immunization (Household Profiling context).
                     */
                    'href' => $resolveNamedHref([
                        'health-records.child-care',
                        'health-records.child-care.index',
                    ]),
                ],
                [
                    'key' => 'risk-assessment',
                    'label' => 'Risk Assessment',
                    'icon' => 'bi-clipboard2-pulse-fill',
                    'href' => $resolveNamedHref([
                        'health-records.risk-assessment',
                        'health-records.risk-assessment.index',
                    ]),
                ],
                [
                    'key' => 'family-planning',
                    'label' => 'Family Planning',
                    'icon' => 'bi-people-fill',
                    'href' => $resolveNamedHref([
                        'health-records.family-planning',
                        'health-records.family-planning.index',
                    ]),
                ],
                [
                    'key' => 'maternal',
                    'label' => 'Maternal',
                    'icon' => 'bi-heart-pulse-fill',
                    'href' => $resolveNamedHref([
                        'health-records.maternal',
                        'health-records.maternal.index',
                    ]),
                ],
                [
                    'key' => 'death',
                    'label' => 'Death',
                    'icon' => 'bi-journal-medical',
                    'href' => $resolveNamedHref([
                        'health-records.death',
                        'health-records.death.index',
                    ]),
                ],
            ],
        ],
    ];

    $menuItems = $items ?? $defaultItems;
    $normalizedRole = strtolower((string) $role);

    $visibleItems = collect($menuItems)->filter(function ($item) use ($normalizedRole) {
        if (! isset($item['roles'])) {
            return true;
        }

        return in_array($normalizedRole, $item['roles'], true);
    })->values();

    $childKeysAreActive = function (array $children) use ($active): bool {
        foreach ($children as $child) {
            if (($child['key'] ?? '') === $active) {
                return true;
            }
        }

        return false;
    };
@endphp

<aside
    id="lmlDashboardSidebar"
    class="lml-sidebar offcanvas-lg offcanvas-start"
    tabindex="-1"
>
    <div class="lml-sidebar__inner">
        <div class="lml-sidebar__mobile-header d-flex d-lg-none justify-content-end">
            <button
                type="button"
                class="lml-sidebar__close lml-focus-ring"
                data-bs-dismiss="offcanvas"
                data-bs-target="#lmlDashboardSidebar"
                aria-label="Close navigation menu"
            >
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>

        <div class="lml-sidebar__brand">
            <a href="{{ route('dashboard') }}" class="lml-sidebar__logo text-decoration-none lml-focus-ring rounded-2">
                <img
                    src="{{ asset('assets/images/logo/logo.png') }}"
                    alt=""
                    class="lml-sidebar__logo-img"
                >
                <span class="lml-sidebar__logo-text">LMLinga</span>
            </a>

            <div class="lml-sidebar__seal-wrap">
                <img
                    src="{{ asset('assets/images/logo/LMLogo.png') }}"
                    alt="La Medalla, Iriga City official seal"
                    class="lml-sidebar__seal"
                >
            </div>

            <p class="lml-sidebar__facility mb-0">{{ $facilityLabel }}</p>
        </div>

        <nav class="lml-sidebar__nav" aria-label="Dashboard">
            <ul class="lml-sidebar__list list-unstyled mb-0">
                @foreach ($visibleItems as $item)
                    @php
                        $itemType = $item['type'] ?? 'link';
                        $isCollapse = $itemType === 'collapse';
                        $children = $item['children'] ?? [];
                        $hasActiveChild = $isCollapse && $childKeysAreActive($children);
                        $isActiveLink = ($item['key'] ?? '') === $active;
                        $collapseId = 'lml-sidebar-collapse-' . ($item['key'] ?? uniqid('item-'));
                        $parentHref = $item['href'] ?? null;
                        $hasParentHref = filled($parentHref) && $parentHref !== '#';
                        /*
                         | Expanded with active child: child gets the glowing active
                         | treatment; parent keeps only a non-green expanded marker
                         | (chevron is the primary expansion signal).
                         | Collapsed with active child (B): JS moves the glowing
                         | treatment onto the parent (see dashboard-sidebar.js).
                         | Outside Health Records (C): neither expands nor activates.
                         */
                        $parentExpandedSubtle = $hasActiveChild;
                        $parentCollapsedActive = false;
                    @endphp

                    <li @class(['lml-sidebar__item', 'lml-sidebar__item--collapse' => $isCollapse])>
                        @if ($isCollapse)
                            {{--
                              Parent label is a normal menu item (does not toggle).
                              Only the chevron button expands/collapses the submenu.
                            --}}
                            <div
                                @class([
                                    'lml-sidebar__collapse-row',
                                    'lml-sidebar__link--parent-expanded' => $parentExpandedSubtle,
                                    'lml-sidebar__link--parent-active' => $parentCollapsedActive || ($isActiveLink && ! $hasActiveChild),
                                ])
                                data-lml-sidebar-collapse-row
                                @if ($hasActiveChild) data-lml-has-active-child="true" @endif
                            >
                                @if ($hasParentHref)
                                    <a
                                        href="{{ $parentHref }}"
                                        class="lml-sidebar__parent-link lml-focus-ring"
                                    >
                                        @if (! empty($item['icon']))
                                            <i class="bi {{ $item['icon'] }} lml-sidebar__icon" aria-hidden="true"></i>
                                        @endif
                                        <span class="lml-sidebar__label">{{ $item['label'] }}</span>
                                    </a>
                                @else
                                    <span class="lml-sidebar__parent-link">
                                        @if (! empty($item['icon']))
                                            <i class="bi {{ $item['icon'] }} lml-sidebar__icon" aria-hidden="true"></i>
                                        @endif
                                        <span class="lml-sidebar__label">{{ $item['label'] }}</span>
                                    </span>
                                @endif

                                <button
                                    type="button"
                                    class="lml-sidebar__chevron-btn lml-focus-ring"
                                    aria-expanded="{{ $hasActiveChild ? 'true' : 'false' }}"
                                    aria-controls="{{ $collapseId }}"
                                    aria-label="Toggle {{ $item['label'] }} submenu"
                                    data-lml-sidebar-collapse-toggle
                                >
                                    {{-- Collapsed: chevron-right; expanded: rotated to point down. --}}
                                    <i class="bi bi-chevron-right lml-sidebar__chevron" aria-hidden="true"></i>
                                </button>
                            </div>

                            <div
                                id="{{ $collapseId }}"
                                @class([
                                    'lml-sidebar__collapse-panel',
                                    'is-open' => $hasActiveChild,
                                ])
                                data-lml-sidebar-collapse-panel
                                @if ($hasActiveChild)
                                    data-lml-has-active-child="true"
                                @else
                                    hidden
                                    aria-hidden="true"
                                @endif
                            >
                                @if ($hasActiveChild)
                                    <x-lml.dashboard.sidebar-collapse-children
                                        :children="$children"
                                        :active="$active"
                                    />
                                @else
                                    {{--
                                      Keep closed children out of the rendered tree so a CSS
                                      cascade failure cannot paint them on /dashboard.
                                      JS materializes this template on first expand.
                                    --}}
                                    <template data-lml-sidebar-collapse-template>
                                        <x-lml.dashboard.sidebar-collapse-children
                                            :children="$children"
                                            :active="$active"
                                        />
                                    </template>
                                @endif
                            </div>
                        @else
                            <a
                                href="{{ $item['href'] ?? '#' }}"
                                @class([
                                    'lml-sidebar__link',
                                    'lml-sidebar__link--active' => $isActiveLink,
                                ])
                                @if ($isActiveLink) aria-current="page" @endif
                            >
                                @if (! empty($item['icon']))
                                    <i class="bi {{ $item['icon'] }} lml-sidebar__icon" aria-hidden="true"></i>
                                @endif
                                <span class="lml-sidebar__label">{{ $item['label'] }}</span>
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="lml-sidebar__footer">
            <a href="{{ route('login') }}" class="lml-sidebar__logout lml-focus-ring">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</aside>
