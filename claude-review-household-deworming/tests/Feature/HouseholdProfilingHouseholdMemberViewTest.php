<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Feature coverage for Household Profiling — View Member Information,
 * focused on Health Summary Records / Child Care accordion.
 */
class HouseholdProfilingHouseholdMemberViewTest extends TestCase
{
    public function test_member_page_renders_child_care_accordion_defaults(): void
    {
        $response = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]));

        $response->assertOk();
        $response->assertSee('data-hh-member-child-care-toggle', false);
        $response->assertSee('>Child Care</span>', false);
        $response->assertSee('aria-expanded="false"', false);
        $response->assertSee('aria-controls="lml-hh-mv-child-care-panel"', false);
        $this->assertMatchesRegularExpression(
            '/id="lml-hh-mv-child-care-panel"[^>]*\bhidden\b/u',
            $response->getContent()
        );
        $this->assertSame(1, substr_count($response->getContent(), 'id="lml-hh-mv-child-care-panel"'));
        $this->assertSame(1, substr_count($response->getContent(), 'id="lml-hh-mv-child-care-toggle"'));
    }

    public function test_child_care_panel_contains_three_module_links_for_ineligible_member(): void
    {
        $response = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'Child Immunization'));
        $this->assertSame(1, substr_count($html, 'School-Based Immunization'));
        $this->assertSame(1, substr_count($html, 'Child Nutrition'));
        $this->assertSame(0, substr_count($html, 'bi-capsule'));
        $this->assertStringNotContainsString(
            'href="'.e(route('household-profiling.members.deworming', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])).'"',
            $html
        );
        // Accordion toggle label (sidebar also lists Child Care under Health Records).
        $this->assertSame(1, substr_count($html, 'id="lml-hh-mv-child-care-toggle"'));
        $this->assertMatchesRegularExpression(
            '/id="lml-hh-mv-child-care-toggle"[\s\S]*?>Child Care<\/span>/u',
            $html
        );
    }

    public function test_child_care_links_use_named_routes(): void
    {
        $response = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]));

        $response->assertOk();

        $params = [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ];

        $response->assertSee(
            'href="'.e(route('household-profiling.members.child-immunization', $params)).'"',
            false
        );
        $response->assertSee(
            'href="'.e(route('household-profiling.members.school-based-immunization', $params)).'"',
            false
        );
        $response->assertSee(
            'href="'.e(route('household-profiling.members.child-nutrition', $params)).'"',
            false
        );
        $response->assertDontSee(
            'href="'.e(route('household-profiling.members.deworming', $params)).'"',
            false
        );
    }

    public function test_remaining_health_summary_records_remain_visible(): void
    {
        $response = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]));

        $response->assertOk();
        $response->assertSee('Risk Assessment', false);
        $response->assertSee('Family Planning', false);
        $response->assertSee('Maternal', false);
        $response->assertSee('Death', false);
        $response->assertSee('data-hh-member-risk-assessment', false);
        $response->assertSee('data-hh-member-family-planning', false);
        $response->assertSee('data-hh-member-maternal-care', false);
        $response->assertSee('data-hh-member-death', false);
        $response->assertDontSee('data-hh-member-view-record="Risk Assessment"', false);
        $response->assertDontSee('data-hh-member-view-record="Family Planning"', false);
        $response->assertDontSee('data-hh-member-view-record="Maternal"', false);
        $response->assertDontSee('data-hh-member-view-record="Death"', false);

        $riskUrl = route('household-profiling.members.risk-assessment', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]);
        $fpUrl = route('household-profiling.members.family-planning.index', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]);
        $mcUrl = route('household-profiling.members.maternal-care.index', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]);
        $deathUrl = route('household-profiling.members.death.index', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]);
        $response->assertSee('href="'.e($riskUrl).'"', false);
        $response->assertSee('href="'.e($fpUrl).'"', false);
        $response->assertSee('href="'.e($mcUrl).'"', false);
        $response->assertSee('href="'.e($deathUrl).'"', false);
        $response->assertSee('data-death-entry="index"', false);
        $response->assertDontSee(
            'href="'.e(route('household-profiling.members.death.create', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])).'"',
            false
        );
        $response->assertDontSee(
            'href="'.e(route('household-profiling.members.death.edit', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])).'"',
            false
        );
    }

    public function test_member_edit_and_delete_controls_remain_present(): void
    {
        $response = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]));

        $response->assertOk();

        $editUrl = route('household-profiling.members.edit', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]);

        $response->assertSee('href="'.e($editUrl).'"', false);
        $response->assertSee('data-hh-member-view-delete', false);
        $response->assertSee('>Edit</span>', false);
        $response->assertSee('Delete', false);
    }

    public function test_child_care_named_routes_remain_resolvable(): void
    {
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/child-immunization'),
            route('household-profiling.members.child-immunization', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/school-based-immunization'),
            route('household-profiling.members.school-based-immunization', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/child-nutrition'),
            route('household-profiling.members.child-nutrition', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/deworming'),
            route('household-profiling.members.deworming', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])
        );
    }

    public function test_child_nutrition_is_no_longer_a_pending_redirect_stub(): void
    {
        $params = [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ];

        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $params
        ));

        $response->assertOk();
        $response->assertSee('data-lml-child-nut', false);
        $response->assertSessionMissing('lml_pending_health_module');
    }

    public function test_school_based_immunization_is_no_longer_a_pending_redirect_stub(): void
    {
        $params = [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ];

        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $params
        ));

        $response->assertOk();
        $response->assertSee('data-lml-sbi', false);
        $response->assertSessionMissing('lml_pending_health_module');
    }
}
