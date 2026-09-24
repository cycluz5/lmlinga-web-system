<?php

namespace Tests\Feature;

use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

/**
 * Feature coverage for the Child Immunization destination screen
 * and dedicated Birth History edit page.
 */
class ChildImmunizationDestinationTest extends TestCase
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

    public function test_named_child_immunization_route_resolves(): void
    {
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/child-immunization'),
            route('household-profiling.members.child-immunization', $this->memberParams())
        );
    }

    public function test_valid_household_and_member_return_ok(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $response->assertSee('data-lml-child-imm', false);
        $response->assertSee('data-household-no="HH-151"', false);
        $response->assertSee('data-member-id="MB-001"', false);
        $response->assertSee('Kristine Reyes', false);
        $response->assertSee('Immunization', false);
        $response->assertSee(
            'Vaccination records that support immunity and protection against infectious diseases.',
            false
        );
    }

    public function test_visible_topbar_title_and_subtitle_use_single_h1(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<h1[^>]*>\s*Child Immunization\s*<\/h1>/u',
            $html
        );
        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString(
            'Vaccination records for Kristine Reyes in HH-151.',
            $html
        );
        $this->assertStringContainsString('data-lml-child-imm', $html);
        $this->assertStringNotContainsString('lml-child-imm__workspace', $html);
        $this->assertStringNotContainsString('Child Immunization Record', $html);
    }

    public function test_route_is_protected_by_ui_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName('household-profiling.members.child-immunization');

        $this->assertNotNull($route);
        $this->assertContains('ui.role', $route->gatherMiddleware());
    }

    public function test_all_nine_vaccine_type_options_are_present(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        foreach (['BCG', 'Hepa B', 'DPT-HIB-HepB', 'OPV', 'IPV', 'PCV', 'MMR', 'FIC', 'CIC'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertSame(1, substr_count($html, 'id="lml-child-imm-type-bcg"'));
        $this->assertSame(1, substr_count($html, 'id="lml-child-imm-type-hepa-b"'));
        $this->assertSame(1, substr_count($html, 'id="lml-child-imm-type-dpt-hib-hepb"'));
        $this->assertSame(1, substr_count($html, 'id="lml-child-imm-type-opv"'));
        $this->assertSame(1, substr_count($html, 'id="lml-child-imm-type-ipv"'));
        $this->assertSame(1, substr_count($html, 'id="lml-child-imm-type-pcv"'));
        $this->assertSame(1, substr_count($html, 'id="lml-child-imm-type-mmr"'));
        $this->assertSame(1, substr_count($html, 'id="lml-child-imm-type-fic"'));
        $this->assertSame(1, substr_count($html, 'id="lml-child-imm-type-cic"'));
    }

    public function test_vaccine_date_and_type_inputs_are_optional(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        preg_match_all('/<input\b[^>]*type="date"[^>]*>/i', $html, $dateInputs);
        $this->assertNotEmpty($dateInputs[0]);

        foreach ($dateInputs[0] as $input) {
            $this->assertStringContainsString('type="date"', $input);
            $this->assertStringNotContainsString('required', strtolower($input));
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $input);
        }

        preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/i', $html, $checkboxes);
        $this->assertCount(9, $checkboxes[0]);

        foreach ($checkboxes[0] as $input) {
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $input);
        }

        $this->assertStringNotContainsString('lml-child-imm__date-icon', $html);
        $this->assertStringNotContainsString('bi-calendar3', $html);
    }

    public function test_household_profiling_is_active_primary_sidebar_item(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('household-profiling.members.child-immunization', $this->memberParams()));

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

    public function test_back_link_points_to_member_view_route(): void
    {
        $params = $this->memberParams();
        $backUrl = route('household-profiling.members.show', $params);

        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $params
        ));

        $response->assertOk();
        $response->assertSee('href="'.e($backUrl).'"', false);
        $response->assertSee('aria-label="Back to Health Summary Records for Kristine Reyes"', false);
        $response->assertSee('>Back</span>', false);
        $this->assertStringNotContainsString('javascript:history.back()', $response->getContent());
        $this->assertStringNotContainsString('return_to=', $response->getContent());
    }

    public function test_school_based_immunization_is_real_destination_not_redirect_stub(): void
    {
        $params = $this->memberParams();

        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $params
        ));

        $response->assertOk();
        $response->assertSee('data-lml-sbi', false);
        $response->assertSessionMissing('lml_pending_health_module');
    }

    public function test_child_nutrition_is_no_longer_a_redirect_stub(): void
    {
        $params = $this->memberParams();

        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $params
        ));

        $response->assertOk();
        $this->assertFalse($response->isRedirect());
        $response->assertSessionMissing('lml_pending_health_module');
        $response->assertSee('data-lml-child-nut', false);
    }

    public function test_completion_cards_use_approved_age_ranges_and_cic_label(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $response->assertSee('0–12 months', false);
        $response->assertSee('13–24 months', false);
        $response->assertSee('id="lml-child-imm-vax-fic"', false);
        $response->assertSee('id="lml-child-imm-vax-cic"', false);
        $this->assertStringContainsString('lml-child-imm__completion-list', $html);
        $response->assertDontSee('12-59 months', false);
        $response->assertDontSee('0-11 months', false);
    }

    public function test_mmr_dose_label_and_fic_cic_mmr_requirements_match_approved_copy(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('2nd Dose (12 months)', $html);
        $this->assertStringNotContainsString('2nd Dose (1 year old)', $html);

        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-vax-fic"[\s\S]*?lml-child-imm__completion-label">MMR<\/span>\s*<span class="lml-child-imm__completion-doses">1 dose<\/span>/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-vax-cic"[\s\S]*?lml-child-imm__completion-label">MMR<\/span>\s*<span class="lml-child-imm__completion-doses">2 doses<\/span>/u',
            $html
        );
    }

    public function test_page_is_single_continuous_document_without_pagination_controls(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('id="lml-child-imm-vax-bcg"', $html);
        $this->assertStringContainsString('id="lml-child-imm-vax-pcv"', $html);
        $this->assertStringContainsString('id="lml-child-imm-vax-mmr"', $html);
        $this->assertStringContainsString('id="lml-child-imm-vax-fic"', $html);
        $this->assertStringContainsString('id="lml-child-imm-vax-cic"', $html);
        $this->assertStringNotContainsString('Previous', $html);
        $this->assertStringNotContainsString('Next page', $html);
        $this->assertDoesNotMatchRegularExpression('/\bpagination\b/i', $html);
    }

    public function test_default_view_mode_shows_immunization_edit_and_hides_save(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/data-child-imm-edit="immunization"[^>]*aria-label="Edit child immunization"|aria-label="Edit child immunization"[^>]*data-child-imm-edit="immunization"/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-child-imm-edit="immunization"[^>]*\bhidden\b/u',
            $html
        );

        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*type="submit"[^>]*data-child-imm-save[^>]*\bhidden\b|<button\b[^>]*data-child-imm-save[^>]*type="submit"[^>]*\bhidden\b|<button\b[^>]*type="submit"[^>]*\bhidden\b[^>]*data-child-imm-save/u',
            $html
        );
        $this->assertStringContainsString('aria-label="Save child immunization"', $html);
        $this->assertStringContainsString('data-editing="false"', $html);
        $this->assertStringContainsString('data-child-imm-immunization', $html);
        $this->assertStringContainsString('class="lml-child-imm__save lml-focus-ring"', $html);
        $this->assertStringNotContainsString('lml-child-imm__save btn btn-primary', $html);
    }

    public function test_immunization_fields_default_to_view_only_and_remain_optional(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        preg_match_all(
            '/<input\b[^>]*type="date"[^>]*data-child-imm-field[^>]*>|<input\b[^>]*data-child-imm-field[^>]*type="date"[^>]*>/i',
            $html,
            $dateInputs
        );
        $this->assertNotEmpty($dateInputs[0]);

        foreach ($dateInputs[0] as $input) {
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $input);
            $this->assertMatchesRegularExpression('/\bdisabled\b/i', $input);
            $this->assertStringContainsString('data-child-imm-field', $input);
        }

        preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/i', $html, $checkboxes);
        $this->assertCount(9, $checkboxes[0]);

        foreach ($checkboxes[0] as $input) {
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $input);
            $this->assertMatchesRegularExpression('/\bdisabled\b/i', $input);
            $this->assertStringContainsString('data-child-imm-type-status', $input);
            $this->assertStringNotContainsString('data-child-imm-field', $input);
            $this->assertStringNotContainsString('name="vaccine_types[]"', $input);
            $this->assertStringNotContainsString('data-manual-selection', $input);
        }
    }

    public function test_preview_safe_save_markup_does_not_claim_server_persistence(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-persistence="db"', $html);
        $this->assertStringContainsString('novalidate', $html);
        $this->assertMatchesRegularExpression(
            '/data-child-imm-immunization[^>]*action=/u',
            $html
        );
        $this->assertTrue(Route::has('household-profiling.members.child-immunization.store'));
        $this->assertTrue(Route::has('household-profiling.members.child-immunization.birth-history.store'));
    }

    public function test_named_birth_history_edit_route_resolves(): void
    {
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/child-immunization/birth-history/edit'),
            route('household-profiling.members.child-immunization.birth-history.edit', $this->memberParams())
        );
    }

    public function test_birth_history_edit_route_is_protected_by_ui_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName(
            'household-profiling.members.child-immunization.birth-history.edit'
        );

        $this->assertNotNull($route);
        $this->assertContains('ui.role', $route->gatherMiddleware());
    }

    public function test_birth_history_edit_link_on_child_immunization_uses_named_route(): void
    {
        $params = $this->memberParams();
        $editUrl = route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $params
        );

        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $params
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-child-imm-birth-edit-link'));
        $this->assertStringContainsString('aria-label="Edit birth history"', $html);
        $this->assertStringContainsString('href="'.e($editUrl).'"', $html);
        $this->assertStringContainsString('lml-child-imm__birth-edit-link', $html);
        $this->assertStringNotContainsString('data-child-imm-edit="birth-history"', $html);
        $this->assertStringNotContainsString('data-child-imm-birth-editor', $html);
        $this->assertStringNotContainsString('data-child-imm-birth-form', $html);
        $this->assertStringNotContainsString('id="lml-child-imm-bh-weight"', $html);
    }

    public function test_dedicated_birth_history_page_renders_form_without_immunization_content(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-bh-edit', $html);
        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-001"', $html);
        $this->assertStringContainsString('data-child-imm-birth-form', $html);
        $this->assertStringContainsString('id="lml-child-imm-birth-editor-heading"', $html);
        $this->assertMatchesRegularExpression(
            '/<h1[^>]*>\s*Birth History\s*<\/h1>/u',
            $html
        );
        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString(
            'Birth history information for Kristine Reyes in HH-151.',
            $html
        );

        $this->assertStringNotContainsString('data-child-imm-immunization', $html);
        $this->assertStringNotContainsString('data-child-imm-edit="immunization"', $html);
        $this->assertStringNotContainsString('id="lml-child-imm-vax-bcg"', $html);
        $this->assertStringNotContainsString('id="lml-child-imm-type-bcg"', $html);
        $this->assertStringNotContainsString('id="lml-child-imm-vax-fic"', $html);
        $this->assertStringNotContainsString('id="lml-child-imm-vax-cic"', $html);
        $this->assertStringNotContainsString('Vaccines Type', $html);
        $this->assertStringNotContainsString('data-bh-edit-toast', $html);
        $this->assertStringNotContainsString('lml-bh-edit__toast', $html);
    }

    public function test_dedicated_birth_history_headings_have_distinct_accessible_names(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertMatchesRegularExpression(
            '/<h1[^>]*>\s*Birth History\s*<\/h1>/u',
            $html
        );

        $this->assertMatchesRegularExpression(
            '/id="lml-bh-edit-birth-summary-heading"[\s\S]*?visually-hidden[^>]*>\s*Birth History summary\s*<\/span>/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-birth-editor-heading"[\s\S]*?visually-hidden[^>]*>\s*Edit Birth History form\s*<\/span>/u',
            $html
        );
        $this->assertStringContainsString('data-persistence="db"', $html);
        $this->assertStringNotContainsString('data-bh-edit-toast', $html);
    }

    public function test_dedicated_birth_history_page_shows_member_summary_card(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-bh-edit-member-summary', $html);
        $this->assertStringContainsString('lml-bh-edit__summary', $html);
        $this->assertStringContainsString('id="lml-bh-edit-member-name"', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-bh-edit-member-name"[^>]*>\s*Kristine Reyes\s*<\/p>/u',
            $html
        );
        $this->assertStringContainsString('lml-child-imm__sex-badge--male', $html);
        $this->assertMatchesRegularExpression(
            '/lml-child-imm__sex-badge--male[^>]*>\s*Male\s*<\/span>/u',
            $html
        );
        $this->assertStringContainsString('<dt>Age:</dt>', $html);
        $this->assertStringContainsString('<dd>35</dd>', $html);
        $this->assertStringContainsString('<dt>Date Birth:</dt>', $html);
        $this->assertStringContainsString('05/04/1991', $html);
        $this->assertStringContainsString('<dt>Household No.:</dt>', $html);
        $this->assertMatchesRegularExpression(
            '/Household No\.:<\/dt>\s*<dd>HH-151<\/dd>/u',
            $html
        );
        $this->assertStringContainsString('<dt>Zone:</dt>', $html);
        $this->assertMatchesRegularExpression(
            '/Zone:<\/dt>\s*<dd>Zone 2<\/dd>/u',
            $html
        );

        $this->assertStringContainsString('id="lml-bh-edit-birth-summary-heading"', $html);
        $this->assertStringContainsString('Birth Weight', $html);
        $this->assertStringContainsString('Birth Length', $html);
        $this->assertMatchesRegularExpression('/<dt>\s*Status\s*<\/dt>/u', $html);
        $this->assertStringContainsString('CPAB from Neonatal Tetanus', $html);
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'No record'));

        // Dedicated page summary is informational — no nested Edit control.
        $this->assertStringNotContainsString('data-child-imm-birth-edit-link', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-bh-edit-member-summary[\s\S]*?aria-label="Edit birth history"/u',
            $html
        );
        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-bh-edit-member-name"[^>]*<\/?h1/i',
            $html
        );
    }

    public function test_birth_history_back_and_close_target_child_immunization_route(): void
    {
        $params = $this->memberParams();
        $childImmUrl = route('household-profiling.members.child-immunization', $params);

        $response = $this->get(route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $params
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'aria-label="Back to Child Immunization for Kristine Reyes"',
            $html
        );
        $this->assertStringContainsString('class="lml-bh-edit__back lml-focus-ring"', $html);
        $this->assertStringContainsString('>Back</span>', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'href="'.e($childImmUrl).'"'));
        $this->assertStringContainsString('data-child-imm-birth-close', $html);
        $this->assertStringContainsString('aria-label="Close birth history editor"', $html);
        $this->assertStringContainsString('aria-label="Save birth history"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-child-imm-birth-close[^>]*href="#"|href="#"[^>]*data-child-imm-birth-close/u',
            $html
        );
    }

    public function test_birth_history_optional_fields_are_not_required(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $controlIds = [
            'lml-child-imm-bh-last-name',
            'lml-child-imm-bh-first-name',
            'lml-child-imm-bh-middle-name',
            'lml-child-imm-bh-dob',
            'lml-child-imm-bh-sex',
            'lml-child-imm-bh-weight',
            'lml-child-imm-bh-length',
            'lml-child-imm-bh-pcab',
            'lml-child-imm-bh-breastfeeding',
        ];

        foreach ($controlIds as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'));
            $this->assertMatchesRegularExpression(
                '/<label\b[^>]*for="'.preg_quote($id, '/').'"/u',
                $html
            );
        }

        preg_match('/<(?:input|select)\b[^>]*id="lml-child-imm-bh-weight"[^>]*>/iu', $html, $weight);
        preg_match('/<(?:input|select)\b[^>]*id="lml-child-imm-bh-length"[^>]*>/iu', $html, $length);
        preg_match('/<select\b[^>]*id="lml-child-imm-bh-pcab"[^>]*>/iu', $html, $pcab);
        preg_match('/<input\b[^>]*id="lml-child-imm-bh-breastfeeding"[^>]*>/iu', $html, $bf);

        foreach ([$weight[0] ?? '', $length[0] ?? '', $pcab[0] ?? '', $bf[0] ?? ''] as $tag) {
            $this->assertNotSame('', $tag);
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $tag);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $tag);
        }

        $this->assertStringContainsString('type="number"', $weight[0]);
        $this->assertStringContainsString('type="number"', $length[0]);
        $this->assertStringContainsString('type="date"', $bf[0]);
        $this->assertStringNotContainsString('bi-calendar3', $html);
    }

    public function test_birth_history_pcab_select_has_empty_default_and_two_approved_choices(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-bh-pcab"[\s\S]*?<option\b[^>]*value=""[^>]*>\s*Select\s*<\/option>/u',
            $html
        );
        $this->assertStringContainsString('value="at_least_2_doses_1_month_prior"', $html);
        $this->assertStringContainsString('value="tt3_td3_to_tt5_td5_prior"', $html);
        $this->assertMatchesRegularExpression('/name="pcab"/', $html);
        $this->assertStringContainsString('CPAB from Neonatal Tetanus', $html);
        $this->assertStringContainsString(
            'At least 2 doses received at least 1 month prior to delivery',
            $html
        );
        $this->assertStringContainsString(
            'TT3/TD3 – TT5/TD5 given to the mother anytime prior to delivery',
            $html
        );

        preg_match(
            '/<select\b[^>]*id="lml-child-imm-bh-pcab"[^>]*>([\s\S]*?)<\/select>/u',
            $html,
            $select
        );
        $this->assertNotEmpty($select[1] ?? null);
        $this->assertSame(3, preg_match_all('/<option\b/i', $select[1]));
    }

    public function test_birth_history_identity_fields_use_selected_member_data(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-bh-last-name"[^>]*value="Reyes"|value="Reyes"[^>]*id="lml-child-imm-bh-last-name"/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-bh-first-name"[^>]*value="Kristine"|value="Kristine"[^>]*id="lml-child-imm-bh-first-name"/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-bh-middle-name"[^>]*value=""|value=""[^>]*id="lml-child-imm-bh-middle-name"/u',
            $html
        );
        $this->assertStringContainsString('05/04/1991', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-child-imm-bh-dob"[^>]*value="March 21, 2023"|value="March 21, 2023"[^>]*id="lml-child-imm-bh-dob"/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="lml-child-imm-bh-middle-name"[^>]*value="Iglesia"|value="Iglesia"[^>]*id="lml-child-imm-bh-middle-name"/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-bh-sex"[^>]*value="Male"|value="Male"[^>]*id="lml-child-imm-bh-sex"/u',
            $html
        );

        foreach (['lml-child-imm-bh-last-name', 'lml-child-imm-bh-first-name', 'lml-child-imm-bh-middle-name', 'lml-child-imm-bh-dob', 'lml-child-imm-bh-sex'] as $id) {
            $this->assertMatchesRegularExpression(
                '/id="'.preg_quote($id, '/').'"[^>]*\breadonly\b|\breadonly\b[^>]*id="'.preg_quote($id, '/').'"/u',
                $html
            );
        }
    }

    public function test_birth_history_edit_page_keeps_household_profiling_active(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route(
                'household-profiling.members.child-immunization.birth-history.edit',
                $this->memberParams()
            ));

        $response->assertOk();
        $html = $response->getContent();

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
    }

    public function test_birth_history_edit_unknown_member_shows_not_found_state(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization.birth-history.edit',
            [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-999',
            ]
        ));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Member not found', $html);
        $this->assertStringNotContainsString('data-child-imm-birth-form', $html);
    }

    public function test_birth_history_edit_malformed_identifiers_are_not_routable(): void
    {
        $this->get('/household-profiling/HH151/members/MB-001/child-immunization/birth-history/edit')
            ->assertNotFound();
        $this->get('/household-profiling/HH-151/members/MB001/child-immunization/birth-history/edit')
            ->assertNotFound();
    }

    public function test_birth_history_edit_remains_separate_from_immunization_actions(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-child-imm-birth-edit-link'));
        $this->assertSame(1, substr_count($html, 'data-child-imm-edit="immunization"'));
        $this->assertSame(1, substr_count($html, 'data-child-imm-save'));
        $this->assertStringNotContainsString('data-child-imm-birth-save', $html);
        $this->assertStringNotContainsString('data-child-imm-save="birth-history"', $html);
    }
}
