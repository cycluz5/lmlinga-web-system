<?php

namespace Tests\Feature;

use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

/**
 * Feature coverage for the Child Nutrition destination screen.
 *
 * New Born anthropometric status is derived in-page from sex + weight + length.
 * Iron/Vitamin A/MNP/LNS-SQ chips stay empty ("No record") until a real completion
 * contract exists. Overall Status / Latest Assessment remain stubs. Nutrition
 * Program Iron / Vitamin A dates come from hydrated worksheet state when present.
 * MAM/SAM progression is intentionally out of scope.
 * MNP/LNS-SQ labels are age-band only (no Figma dosage bleed).
 */
class ChildNutritionDestinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        PersistCatalogHousehold::persist('HH-151');
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function memberParams(): array
    {
        return [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ];
    }

    public function test_named_child_nutrition_route_resolves(): void
    {
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/child-nutrition'),
            route('household-profiling.members.child-nutrition', $this->memberParams())
        );
    }

    public function test_route_preserves_household_no_and_member_id(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $response->assertSee('data-lml-child-nut', false);
        $response->assertSee('data-household-no="HH-151"', false);
        $response->assertSee('data-member-id="MB-001"', false);
    }

    public function test_route_is_protected_by_ui_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName(
            'household-profiling.members.child-nutrition'
        );

        $this->assertNotNull($route);
        $this->assertContains('ui.role', $route->gatherMiddleware());
    }

    public function test_redirect_stub_is_replaced_with_real_destination(): void
    {
        $params = $this->memberParams();
        $showUrl = route('household-profiling.members.show', $params);

        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $params
        ));

        $response->assertOk();
        $this->assertFalse($response->isRedirect());
        $response->assertDontSee('data-pending-health-module="Child Nutrition"', false);
        $this->assertNotSame($showUrl, $response->headers->get('Location'));
        $response->assertSee('data-lml-child-nut', false);
        $response->assertSee('Child Nutrition', false);
        $response->assertSessionMissing('lml_pending_health_module');
    }

    public function test_child_care_accordion_still_links_to_child_nutrition(): void
    {
        $params = $this->memberParams();

        $response = $this->get(route('household-profiling.members.show', $params));

        $response->assertOk();
        $response->assertSee(
            'href="'.e(route('household-profiling.members.child-nutrition', $params)).'"',
            false
        );
    }

    public function test_page_renders_correct_demo_member(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-child-nut-member-name"[^>]*>\s*Kristine Reyes\s*<\/p>/u',
            $html
        );
        $this->assertStringContainsString('data-member-name="Kristine Reyes"', $html);
        $this->assertMatchesRegularExpression(
            '/lml-child-imm__sex-badge--male[^>]*>\s*Male\s*<\/span>/u',
            $html
        );
    }

    public function test_household_profiling_is_active_and_health_records_collapsed(): void
    {
        $response = $this->get(route(
                'household-profiling.members.child-nutrition',
                $this->memberParams()
            ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(
            'household-profiling',
            UiRole::sidebarActiveKey('household-profiling')
        );

        $this->assertMatchesRegularExpression(
            '/lml-sidebar__link--active[^>]*aria-current="page"|aria-current="page"[^>]*lml-sidebar__link--active/u',
            $html
        );
        $this->assertStringContainsString('>Household Profiling</span>', $html);

        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bshow\b/u',
            $html
        );
        $this->assertStringNotContainsString('lml-sidebar__sublink--active', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"/u',
            $html
        );
    }

    public function test_page_contains_one_h1_with_child_nutrition_title(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<h1[^>]*>\s*Child Nutrition\s*<\/h1>/u',
            $html
        );
        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString(
            'Monitor child growth and nutrition for Kristine Reyes in HH-151.',
            $html
        );
    }

    public function test_member_and_birth_history_summary_fields_render(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<dt>Age:</dt>', $html);
        $this->assertStringContainsString('<dt>Date Birth:</dt>', $html);
        $this->assertStringContainsString('<dt>Household No.:</dt>', $html);
        $this->assertStringContainsString('<dt>Zone:</dt>', $html);
        $this->assertStringContainsString('Birth Weight', $html);
        $this->assertStringContainsString('Birth Length', $html);
        $this->assertMatchesRegularExpression('/<dt>\s*Status\s*<\/dt>/u', $html);
        $this->assertStringContainsString('CPAB from Neonatal Tetanus', $html);
        $this->assertStringContainsString('id="lml-child-nut-birth-heading"', $html);
        $this->assertStringContainsString('data-child-nut-birth-edit-link', $html);
        $this->assertStringContainsString('aria-label="Edit birth history"', $html);
        $this->assertMatchesRegularExpression(
            '/data-birth-summary="status"[^>]*>\s*No record\s*</u',
            $html
        );

        $birthEditUrl = route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $this->memberParams()
        );
        $this->assertStringContainsString('href="'.e($birthEditUrl).'"', $html);
    }

    public function test_back_link_points_to_member_view_route(): void
    {
        $params = $this->memberParams();
        $backUrl = route('household-profiling.members.show', $params);

        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $params
        ));

        $response->assertOk();
        $response->assertSee('href="'.e($backUrl).'"', false);
        $response->assertSee(
            'aria-label="Back to Health Summary Records for Kristine Reyes"',
            false
        );
        $response->assertSee('>Back</span>', false);
        $this->assertStringNotContainsString('javascript:history.back()', $response->getContent());
        $this->assertStringNotContainsString('return_to=', $response->getContent());
    }

    public function test_newborn_iron_and_supplementation_sections_exist(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('id="lml-child-nut-newborn-heading"', $html);
        $this->assertStringContainsString('id="lml-child-nut-newborn"', $html);
        $this->assertStringContainsString('New Born (0–28 Days Old)', $html);
        $this->assertStringContainsString('id="lml-child-nut-nb-length"', $html);
        $this->assertStringContainsString('id="lml-child-nut-nb-weight"', $html);
        $this->assertStringContainsString('id="lml-child-nut-nb-breastfeeding"', $html);

        $this->assertStringContainsString('id="lml-child-nut-iron"', $html);
        $this->assertStringContainsString('id="lml-child-nut-iron-heading"', $html);
        $this->assertStringContainsString('For Low Birth Only', $html);
        $this->assertStringContainsString('id="lml-child-nut-iron-1st"', $html);
        $this->assertStringContainsString('id="lml-child-nut-iron-2nd"', $html);
        $this->assertStringContainsString('id="lml-child-nut-iron-3rd"', $html);

        // Capstone structure: New Born and Iron are independent bordered cards.
        $this->assertStringNotContainsString('id="lml-child-nut-growth"', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-child-nut-newborn"[\s\S]+?<\/section>\s*<section[^>]*id="lml-child-nut-iron"/u',
            $html
        );

        $this->assertStringContainsString('id="lml-child-nut-vita-heading"', $html);
        $this->assertStringContainsString('100,000 IU (6–11 Months)', $html);
        $this->assertStringContainsString('id="lml-child-nut-va-6-11"', $html);
        $this->assertStringContainsString('id="lml-child-nut-va-12-59-1"', $html);
        $this->assertStringContainsString('id="lml-child-nut-va-12-59-2"', $html);

        $this->assertStringContainsString('id="lml-child-nut-mnp-heading"', $html);
        $this->assertStringContainsString('id="lml-child-nut-mnp-6-11"', $html);
        $this->assertStringContainsString('id="lml-child-nut-mnp-12-23"', $html);

        $this->assertStringContainsString('id="lml-child-nut-lns-heading"', $html);
        $this->assertStringContainsString('id="lml-child-nut-lns-6-11"', $html);
        $this->assertStringContainsString('id="lml-child-nut-lns-12-23"', $html);
    }

    public function test_figma_preview_status_presentation_is_rendered_without_clinical_derivation(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        // New Born starts neutral until weight + length are present (no hardcoded NORMAL).
        $this->assertStringContainsString('data-child-nut-newborn-status', $html);
        $this->assertStringContainsString('data-result="no_record"', $html);
        $this->assertMatchesRegularExpression(
            '/data-child-nut-newborn-status-label[^>]*>\s*No record\s*</u',
            $html
        );
        $this->assertStringContainsString('data-member-sex="Male"', $html);
        $this->assertStringNotContainsString('data-child-nut-demo-status="newborn-normal"', $html);

        $this->assertStringContainsString('data-child-nut-program-status="iron"', $html);
        $this->assertStringContainsString('data-child-nut-program-status="vitamin-a"', $html);
        $this->assertStringContainsString('data-child-nut-program-status="mnp"', $html);
        $this->assertStringContainsString('data-child-nut-program-status="lns"', $html);
        $this->assertStringNotContainsString('data-child-nut-demo-status="iron-completed"', $html);
        $this->assertStringNotContainsString('data-child-nut-demo-status="vitamin-a-completed"', $html);
        $this->assertStringNotContainsString('data-child-nut-demo-status="mnp-completed"', $html);
        $this->assertStringNotContainsString('data-child-nut-demo-status="lns-completed"', $html);
        $this->assertDoesNotMatchRegularExpression('/>(?:\s*)COMPLETED(?:\s*)</u', $html);
        $this->assertStringNotContainsString(
            'Preview/demo presentation only; no clinical derivation or persistence yet.',
            $html
        );
    }

    public function test_mnp_and_lns_use_age_band_labels_without_vitamin_a_dosage_bleed(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-child-nut-mnp-heading"[\s\S]{0,800}200,000/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-child-nut-lns-heading"[\s\S]{0,800}200,000/u',
            $html
        );
        $this->assertStringNotContainsString('200,000 ui', $html);
        $this->assertStringNotContainsString('200,000, UI', $html);
    }

    public function test_mam_and_sam_sections_with_accessible_yes_no_groups_exist(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('id="lml-child-nut-mam-heading"', $html);
        $this->assertStringContainsString('id="lml-child-nut-sam-heading"', $html);
        $this->assertStringContainsString('Moderate Acute Malnutrition', $html);
        $this->assertStringContainsString('Severe Acute Malnutrition', $html);

        foreach (['mam', 'sam'] as $program) {
            foreach (['identified', 'enrolled', 'cured', 'non-cured', 'default', 'died'] as $outcome) {
                $this->assertSame(1, substr_count($html, 'id="lml-child-nut-'.$program.'-'.$outcome.'-date"'));
                $this->assertSame(1, substr_count($html, 'id="lml-child-nut-'.$program.'-'.$outcome.'-yes"'));
                $this->assertSame(1, substr_count($html, 'id="lml-child-nut-'.$program.'-'.$outcome.'-no"'));
            }
        }

        preg_match_all('/<fieldset\b[^>]*class="[^"]*lml-child-nut__yn[^"]*"/i', $html, $fieldsets);
        $this->assertCount(12, $fieldsets[0]);

        preg_match_all('/<input\b[^>]*type="radio"[^>]*>/i', $html, $radios);
        $this->assertCount(24, $radios[0]);

        foreach ($radios[0] as $input) {
            $this->assertStringContainsString('data-child-nut-field', $input);
            $this->assertMatchesRegularExpression('/\bdisabled\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $input);
        }
    }

    public function test_status_panel_matches_preview_hierarchy_and_labels(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('id="lml-child-nut-status-panel"', $html);
        $this->assertStringContainsString('id="lml-child-nut-status-heading"', $html);
        $this->assertStringContainsString('Child Nutrition Status', $html);
        $this->assertStringContainsString('Overall Status', $html);
        $this->assertStringContainsString('Latest Assessment', $html);
        $this->assertStringContainsString('Nutrition Program', $html);
        $this->assertStringContainsString('MUAC', $html);
        $this->assertStringContainsString('--- Nothing Follows ---', $html);

        $this->assertMatchesRegularExpression(
            '/id="lml-child-nut-status-panel"[\s\S]+/u',
            $html
        );
        preg_match('/id="lml-child-nut-status-panel"[\s\S]*?<\/aside>/u', $html, $panelMatch);
        $this->assertNotEmpty($panelMatch[0] ?? null);
        $panel = $panelMatch[0];

        $this->assertMatchesRegularExpression(
            '/data-child-nut-status-overall[\s\S]{0,280}\bNo record\b/u',
            $panel
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-child-nut-status-overall[\s\S]{0,280}\bNormal\b/u',
            $panel
        );
        $this->assertStringNotContainsString('lml-child-nut__status-pill', $panel);
        $this->assertStringNotContainsString('bi-check-lg', $panel);
        $this->assertStringNotContainsString('July 20, 2026', $panel);
        $this->assertStringNotContainsString('4.0 kg', $panel);
        $this->assertStringNotContainsString('50 cm', $panel);
        $this->assertStringNotContainsString('13.2 cm', $panel);
        $this->assertStringNotContainsString('January 1, 2025', $panel);
        $this->assertStringNotContainsString('June 15, 2026', $panel);
        $this->assertGreaterThanOrEqual(4, substr_count($panel, 'No record'));

        $this->assertMatchesRegularExpression(
            '/name="iron\[1st\]"[^>]*\bmin="1991-05-04"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="newborn\[breastfeeding_date\]"[^>]*\bmin="1991-05-04"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="newborn\[breastfeeding_date\]"[^>]*\bmax="'.preg_quote(now()->toDateString(), '/').'"/s',
            $html
        );
    }

    public function test_all_child_nutrition_inputs_are_optional_and_locked_in_view_mode(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        preg_match_all(
            '/<input\b[^>]*data-child-nut-field[^>]*>/i',
            $html,
            $fields
        );
        $this->assertGreaterThanOrEqual(20, count($fields[0]));

        foreach ($fields[0] as $input) {
            $this->assertMatchesRegularExpression('/\bdisabled\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $input);
        }

        $this->assertStringNotContainsString('aria-required="true"', $html);
        $this->assertStringNotContainsString(' required>', $html);
        $this->assertDoesNotMatchRegularExpression('/\srequired=/i', $html);
    }

    public function test_view_edit_save_preview_contract_exists(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-child-nut-records', $html);
        $this->assertStringContainsString('data-editing="false"', $html);
        $this->assertStringContainsString('data-persistence="db"', $html);
        $this->assertStringContainsString('data-child-nut-edit', $html);
        $this->assertStringContainsString('data-child-nut-save', $html);
        $this->assertStringContainsString('novalidate', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-child-nut-toast', $html);
        $this->assertMatchesRegularExpression(
            '/data-child-nut-records[\s\S]*?action="/u',
            $html
        );
        preg_match('/<form\b[^>]*data-child-nut-records[^>]*>/i', $html, $nutritionForm);
        $this->assertNotEmpty($nutritionForm[0] ?? null);
        $this->assertStringContainsString('method="post"', strtolower($nutritionForm[0]));
        $this->assertTrue(Route::has('household-profiling.members.child-nutrition.store'));
    }

    public function test_frozen_immunization_destinations_remain_unchanged(): void
    {
        $ci = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));
        $ci->assertOk();
        $ciHtml = $ci->getContent();
        $this->assertStringContainsString('data-lml-child-imm', $ciHtml);
        $this->assertStringNotContainsString('data-lml-child-nut', $ciHtml);

        $sbi = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));
        $sbi->assertOk();
        $sbiHtml = $sbi->getContent();
        $this->assertStringContainsString('data-lml-sbi', $sbiHtml);
        $this->assertStringNotContainsString('data-lml-child-nut', $sbiHtml);
    }

    public function test_malformed_identifiers_are_not_routable(): void
    {
        $this->get('/household-profiling/HH151/members/MB-001/child-nutrition')
            ->assertNotFound();
        $this->get('/household-profiling/HH-151/members/MB001/child-nutrition')
            ->assertNotFound();
    }

    public function test_page_script_does_not_auto_derive_status_or_sync_panel(): void
    {
        $jsPath = resource_path('js/pages/child-nutrition.js');
        $this->assertFileExists($jsPath);
        $js = file_get_contents($jsPath);
        $this->assertNotFalse($js);

        $this->assertStringContainsString(
            'New Born anthropometric status IS derived locally',
            $js
        );
        $this->assertStringContainsString(
            'It does NOT auto-derive Iron/Vitamin A/MNP/LNS-SQ COMPLETED badges',
            $js
        );
        $this->assertStringContainsString(
            'Does not sync the status panel from form fields.',
            $js
        );
        $this->assertStringContainsString(
            'Does not auto-fill New Born fields from Birth History.',
            $js
        );
        $this->assertStringContainsString('export function deriveNewbornStatus', $js);
        $this->assertStringNotContainsString('textContent = \'COMPLETED\'', $js);
        $this->assertStringNotContainsString('querySelector(\'[data-child-nut-status-overall\']', $js);
    }
}