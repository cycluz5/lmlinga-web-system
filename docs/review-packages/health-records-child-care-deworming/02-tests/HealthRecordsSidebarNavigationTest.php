<?php

namespace Tests\Feature;

use App\Support\UiRole;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Health Records sidebar dropdown reorganization and active-state contracts.
 */
class HealthRecordsSidebarNavigationTest extends TestCase
{
    /** @return list<string> */
    private function expectedChildLabels(): array
    {
        return [
            'Child Care',
            'Risk Assessment',
            'Family Planning',
            'Maternal',
            'Death',
        ];
    }

    /** @return list<string> */
    private function expectedChildKeys(): array
    {
        return [
            'child-care',
            'risk-assessment',
            'family-planning',
            'maternal',
            'death',
        ];
    }

    private function pretendNamedRoute(string $routeName): void
    {
        $route = new Route(['GET'], '/__pretend__/'.$routeName, [
            'as' => $routeName,
            'uses' => static fn () => response('ok'),
        ]);
        $route->bind(Request::create('/__pretend__/'.$routeName, 'GET'));

        $request = Request::create('/__pretend__/'.$routeName, 'GET');
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function extractHealthRecordsPanel(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '/(?s)<button[^>]*aria-controls="lml-sidebar-collapse-health-records"[^>]*>/',
            $html
        );
        preg_match(
            '/(?s)<button[^>]*aria-controls="lml-sidebar-collapse-health-records"[^>]*>/',
            $html,
            $buttonMatch
        );

        $this->assertMatchesRegularExpression(
            '/(?s)<div[^>]*id="lml-sidebar-collapse-health-records"[^>]*>/',
            $html
        );
        preg_match(
            '/(?s)<div[^>]*id="lml-sidebar-collapse-health-records"[^>]*>/',
            $html,
            $panelMatch
        );

        return [$buttonMatch[0], $panelMatch[0]];
    }

    private function assertHealthRecordsCollapsed(string $html): void
    {
        [$button, $panel] = $this->extractHealthRecordsPanel($html);

        $this->assertStringContainsString('aria-expanded="false"', $button);
        $this->assertStringContainsString('aria-controls="lml-sidebar-collapse-health-records"', $button);
        $this->assertStringContainsString('type="button"', $button);
        $this->assertStringContainsString('data-lml-sidebar-collapse-toggle', $button);
        $this->assertStringContainsString('bi-chevron-right', $html);
        $this->assertStringNotContainsString('data-bs-toggle="collapse"', $button);
        $this->assertStringNotContainsString('data-bs-target=', $button);

        $this->assertStringContainsString('lml-sidebar__collapse-panel', $panel);
        $this->assertDoesNotMatchRegularExpression('/\bis-open\b/', $panel);
        $this->assertMatchesRegularExpression('/\bhidden\b/', $panel);
        $this->assertStringContainsString('aria-hidden="true"', $panel);
        $this->assertStringContainsString('data-lml-sidebar-collapse-template', $html);

        // Closed children must live only inside <template>, not in the painted tree.
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*>\s*<ul class="lml-sidebar__sublist/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-lml-sidebar-collapse-template[\s\S]*?lml-sidebar__sublist/u',
            $html
        );

        $this->assertStringNotContainsString('lml-sidebar__sublink--active', $html);
        $this->assertStringNotContainsString('lml-sidebar__link--parent-expanded', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__collapse-row[^>]*lml-sidebar__link--parent-active/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"/u',
            $html
        );
    }

    public function test_dashboard_keeps_health_records_collapsed_and_inactive(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('dashboard'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('dashboard', UiRole::sidebarActiveKey());
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__link--active[^>]*aria-current="page"|aria-current="page"[^>]*lml-sidebar__link--active/u',
            $html
        );
        $this->assertStringContainsString('>Dashboard</span>', $html);

        $this->assertHealthRecordsCollapsed($html);

        // Expansion is allowed later via JS, but SSR must not mark Health Records
        // as a route-active / expanded-active parent on unrelated destinations.
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__collapse-row[^>]*(lml-sidebar__link--parent-active|lml-sidebar__link--parent-expanded)/u',
            $html
        );
    }

    public function test_unrelated_route_health_records_parent_is_not_route_active(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('dashboard'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__collapse-row[^>]*lml-sidebar__link--parent-active/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__collapse-row[^>]*lml-sidebar__link--parent-expanded/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Health Records[\s\S]{0,400}aria-current="page"/u',
            $html
        );
    }

    public function test_health_records_dropdown_contains_only_five_items_in_order(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('dashboard'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('>Health Records</span>', $html);
        $this->assertStringContainsString('id="lml-sidebar-collapse-health-records"', $html);

        foreach ($this->expectedChildLabels() as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $html);
        }

        $positions = [];
        foreach ($this->expectedChildLabels() as $label) {
            $pos = strpos($html, '>'.$label.'</span>');
            $this->assertNotFalse($pos, "Missing Health Records child label: {$label}");
            $positions[$label] = $pos;
        }

        $ordered = array_values($positions);
        $sorted = $ordered;
        sort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $ordered, 'Health Records children must appear in the required order.');

        foreach (['Immunizations', 'Operation Timbang', 'Vitamin A', 'Deworming'] as $removed) {
            $this->assertStringNotContainsString('>'.$removed.'</span>', $html);
        }
    }

    public function test_health_records_children_do_not_use_hash_placeholders(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('dashboard'));

        $response->assertOk();
        $html = $response->getContent();

        preg_match(
            '/id="lml-sidebar-collapse-health-records".*?<\/div>\s*<\/li>/s',
            $html,
            $match
        );

        $this->assertNotEmpty($match, 'Health Records collapse panel not found.');
        $panelHtml = $match[0];

        $this->assertStringNotContainsString('href="#"', $panelHtml);
        $this->assertStringNotContainsString('tabindex="0"', $panelHtml);
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*class="[^"]*lml-sidebar__sublink/u',
            $panelHtml
        );
        $this->assertSame(
            0,
            substr_count($panelHtml, 'lml-sidebar__sublink--unavailable'),
            'All Health Records children must have named destinations.'
        );
        $this->assertSame(
            0,
            substr_count($panelHtml, 'aria-disabled="true"')
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.index')).'"',
            $panelHtml
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.risk-assessment.index')).'"',
            $panelHtml
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.family-planning.index')).'"',
            $panelHtml
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.maternal.index')).'"',
            $panelHtml
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.death.index')).'"',
            $panelHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<span[^>]*lml-sidebar__sublink--unavailable[^>]*>[\s\S]*?<span>\s*Risk Assessment\s*<\/span>/u',
            $panelHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<span[^>]*lml-sidebar__sublink--unavailable[^>]*>[\s\S]*?<span>\s*Family Planning\s*<\/span>/u',
            $panelHtml
        );
    }

    public function test_child_care_sidebar_item_is_not_remapped_to_child_immunization(): void
    {
        $this->assertTrue(RouteFacade::has('health-records.child-care.index'));

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('dashboard'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink[^>]*href="[^"]*child-immunization[^"]*"/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink[^>]*href="[^"]*school-based-immunization[^"]*"/u',
            $html
        );
    }

    public function test_ui_role_maps_health_records_child_route_families(): void
    {
        foreach ($this->expectedChildKeys() as $childKey) {
            $this->pretendNamedRoute('health-records.'.$childKey);

            $this->assertSame(
                $childKey,
                UiRole::sidebarActiveKey('dashboard'),
                "Expected sidebar key {$childKey} for route health-records.{$childKey}"
            );
        }

        $this->pretendNamedRoute('health-records.risk-assessment.show');
        $this->assertSame('risk-assessment', UiRole::sidebarActiveKey('dashboard'));
    }

    public function test_ui_role_does_not_treat_child_immunization_as_health_records_child_care(): void
    {
        $this->get(route(
            'household-profiling.members.child-immunization',
            ['householdNo' => 'HH-151', 'memberId' => 'MB-001']
        ))->assertOk();

        $this->assertSame(
            'household-profiling',
            UiRole::sidebarActiveKey('child-care')
        );
    }

    public function test_expanded_health_records_highlights_only_active_child(): void
    {
        $childCareHref = route('dashboard');

        $html = view('components.lml.dashboard.sidebar', [
            'role' => 'bhw',
            'active' => 'child-care',
            'items' => [
                [
                    'key' => 'health-records',
                    'label' => 'Health Records',
                    'icon' => 'bi-folder2-open',
                    'type' => 'collapse',
                    'roles' => ['bhw'],
                    'children' => [
                        [
                            'key' => 'child-care',
                            'label' => 'Child Care',
                            'icon' => 'bi-heart-pulse',
                            'href' => $childCareHref,
                        ],
                        [
                            'key' => 'risk-assessment',
                            'label' => 'Risk Assessment',
                            'icon' => 'bi-clipboard2-pulse-fill',
                            'href' => $childCareHref,
                        ],
                        [
                            'key' => 'family-planning',
                            'label' => 'Family Planning',
                            'icon' => 'bi-people-fill',
                            'href' => $childCareHref,
                        ],
                        [
                            'key' => 'maternal',
                            'label' => 'Maternal',
                            'icon' => 'bi-heart-pulse-fill',
                            'href' => $childCareHref,
                        ],
                        [
                            'key' => 'death',
                            'label' => 'Death',
                            'icon' => 'bi-journal-medical',
                            'href' => $childCareHref,
                        ],
                    ],
                ],
            ],
        ])->render();

        [$button, $panel] = $this->extractHealthRecordsPanel($html);

        $this->assertStringContainsString('aria-expanded="true"', $button);
        $this->assertMatchesRegularExpression('/\bis-open\b/', $panel);
        $this->assertDoesNotMatchRegularExpression('/\bhidden\b/', $panel);
        $this->assertStringNotContainsString('data-lml-sidebar-collapse-template', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*>\s*<ul class="lml-sidebar__sublist/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-lml-has-active-child="true"/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__link--parent-expanded/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__collapse-row[^>]*lml-sidebar__link--parent-active/u',
            $html
        );

        // Exactly one aria-current, on the child — never on the Health Records parent.
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink[^>]*lml-sidebar__sublink--active[^>]*aria-current="page"|aria-current="page"[^>]*lml-sidebar__sublink--active/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__parent-link[^>]*aria-current="page"|aria-current="page"[^>]*lml-sidebar__parent-link/u',
            $html
        );

        $this->assertSame(1, substr_count($html, 'lml-sidebar__sublink--active'));
        $this->assertStringContainsString('>Child Care</span>', $html);
        $this->assertStringNotContainsString('lml-sidebar__sublink--unavailable', $html);
        $this->assertStringNotContainsString('>Immunizations</span>', $html);
    }

    public function test_built_css_hides_closed_sidebar_panels_with_important(): void
    {
        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $cssRel = $manifest['resources/css/app.css']['file'] ?? null;
        $this->assertNotEmpty($cssRel);

        $css = (string) file_get_contents(public_path('build/'.$cssRel));
        $this->assertStringContainsString(
            '#lmlDashboardSidebar .lml-sidebar__collapse-panel[hidden]',
            $css
        );
        $this->assertStringContainsString(
            '.lml-sidebar__collapse-panel:not(.is-open)',
            $css
        );
        $this->assertStringContainsString('display:none!important', $css);
    }

    public function test_routes_outside_health_records_leave_dropdown_collapsed_and_inactive(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('household-profiling.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('household-profiling', UiRole::sidebarActiveKey());
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertHealthRecordsCollapsed($html);
    }

    public function test_child_immunization_keeps_household_profiling_active_and_health_records_collapsed(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route(
                'household-profiling.members.child-immunization',
                ['householdNo' => 'HH-151', 'memberId' => 'MB-001']
            ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/lml-sidebar__link--active[^>]*aria-current="page"|aria-current="page"[^>]*lml-sidebar__link--active/u',
            $html
        );
        $this->assertStringContainsString('>Household Profiling</span>', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertHealthRecordsCollapsed($html);
    }

    public function test_birth_history_keeps_household_profiling_active_and_health_records_collapsed(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route(
                'household-profiling.members.child-immunization.birth-history.edit',
                ['householdNo' => 'HH-151', 'memberId' => 'MB-001']
            ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('>Household Profiling</span>', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertHealthRecordsCollapsed($html);
    }

    public function test_environmental_health_keeps_health_records_collapsed(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('environmental-health.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('environmental-health', UiRole::sidebarActiveKey());
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertHealthRecordsCollapsed($html);
    }

    public function test_canonical_health_records_child_routes_are_named_index_destinations(): void
    {
        foreach ($this->expectedChildKeys() as $childKey) {
            $this->assertTrue(
                RouteFacade::has('health-records.'.$childKey.'.index'),
                "Missing Health Records destination: health-records.{$childKey}.index"
            );
            $this->assertFalse(
                RouteFacade::has('health-records.'.$childKey),
                "Unexpected alias: health-records.{$childKey}"
            );
        }
    }
}
