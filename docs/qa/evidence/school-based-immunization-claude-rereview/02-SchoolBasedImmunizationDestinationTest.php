<?php

namespace Tests\Feature;

use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Feature coverage for the School-Based Immunization destination screen.
 *
 * HPV dose labels use "1st Dose" / "2nd Dose" when Figma layer text appears
 * duplicated or conflicts with the Vaccines Type panel structure.
 */
class SchoolBasedImmunizationDestinationTest extends TestCase
{
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

    public function test_named_school_based_immunization_route_resolves(): void
    {
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-001/school-based-immunization'),
            route('household-profiling.members.school-based-immunization', $this->memberParams())
        );
    }

    public function test_route_preserves_household_no_and_member_id(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $response->assertSee('data-lml-sbi', false);
        $response->assertSee('data-household-no="HH-151"', false);
        $response->assertSee('data-member-id="MB-001"', false);
    }

    public function test_route_is_protected_by_ui_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName(
            'household-profiling.members.school-based-immunization'
        );

        $this->assertNotNull($route);
        $this->assertContains('ui.role', $route->gatherMiddleware());
    }

    public function test_redirect_stub_is_replaced_with_real_destination(): void
    {
        $params = $this->memberParams();
        $showUrl = route('household-profiling.members.show', $params);

        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $params
        ));

        $response->assertOk();
        $this->assertFalse($response->isRedirect());
        $response->assertDontSee('data-pending-health-module="School-Based Immunization"', false);
        $this->assertNotSame($showUrl, $response->headers->get('Location'));
        $response->assertSee('data-lml-sbi', false);
        $response->assertSee('School-Based Immunization', false);
    }

    public function test_child_nutrition_remains_redirect_stub(): void
    {
        $params = $this->memberParams();
        $showUrl = route('household-profiling.members.show', $params);

        $response = $this->get(route(
            'household-profiling.members.child-nutrition',
            $params
        ));

        $response->assertRedirect($showUrl);
        $response->assertSessionHas('lml_pending_health_module', 'Child Nutrition');
    }

    public function test_child_immunization_destination_remains_unchanged(): void
    {
        $response = $this->get(route(
            'household-profiling.members.child-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-child-imm', $html);
        $this->assertStringContainsString('id="lml-child-imm-vax-mmr"', $html);
        $this->assertStringContainsString('2nd Dose (12 months)', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-vax-fic"[\s\S]*?lml-child-imm__completion-label">MMR<\/span>\s*<span class="lml-child-imm__completion-doses">1 dose<\/span>/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-child-imm-vax-cic"[\s\S]*?lml-child-imm__completion-label">MMR<\/span>\s*<span class="lml-child-imm__completion-doses">2 doses<\/span>/u',
            $html
        );
        $this->assertStringNotContainsString('data-lml-sbi', $html);
        $this->assertStringNotContainsString('id="lml-sbi-grade-1"', $html);
    }

    public function test_child_care_accordion_still_links_to_all_three_named_routes(): void
    {
        $params = $this->memberParams();

        $response = $this->get(route('household-profiling.members.show', $params));

        $response->assertOk();
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
    }

    public function test_page_renders_correct_demo_member(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-sbi-member-name"[^>]*>\s*Kristine Reyes\s*<\/p>/u',
            $html
        );
        $this->assertStringContainsString('data-member-name="Kristine Reyes"', $html);
        // Demo member sex comes from households.php (Male), not Figma sample Female.
        $this->assertMatchesRegularExpression(
            '/lml-child-imm__sex-badge--male[^>]*>\s*Male\s*<\/span>/u',
            $html
        );
    }

    public function test_household_profiling_is_active_and_health_records_collapsed(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route(
                'household-profiling.members.school-based-immunization',
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

    public function test_page_contains_one_h1_with_school_based_immunization_title(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<h1[^>]*>\s*School-Based Immunization\s*<\/h1>/u',
            $html
        );
        $this->assertSame(1, preg_match_all('/<h1\b/i', $html));
        $this->assertStringContainsString(
            'Vaccination records for Kristine Reyes in HH-151.',
            $html
        );
    }

    public function test_member_and_birth_history_summary_fields_render(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<dt>Age:</dt>', $html);
        $this->assertStringContainsString('<dt>Date Birth:</dt>', $html);
        $this->assertStringContainsString("<dt>Mother's Name:</dt>", $html);
        $this->assertStringContainsString('Birth Weight', $html);
        $this->assertStringContainsString('Birth Length', $html);
        $this->assertMatchesRegularExpression('/<dt>\s*Status\s*<\/dt>/u', $html);
        $this->assertStringContainsString('PCAB from Neonatal Tetanus', $html);
        $this->assertStringContainsString('id="lml-sbi-birth-heading"', $html);
        $this->assertStringContainsString('data-sbi-birth-edit-link', $html);
        $this->assertStringContainsString('aria-label="Edit birth history"', $html);
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'No record'));
    }

    public function test_grade_1_and_grade_7_groups_render(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('id="lml-sbi-grade-1"', $html);
        $this->assertStringContainsString('id="lml-sbi-grade-7"', $html);
        $this->assertStringContainsString('id="lml-sbi-grade-1-td"', $html);
        $this->assertStringContainsString('id="lml-sbi-grade-1-mr"', $html);
        $this->assertStringContainsString('id="lml-sbi-grade-7-td"', $html);
        $this->assertStringContainsString('id="lml-sbi-grade-7-mr"', $html);
        $this->assertStringContainsString('Tetanus Diphtheria (TD)', $html);
        $this->assertStringContainsString('Measles Rubella', $html);
    }

    public function test_hpv_first_and_second_dose_render(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('id="lml-sbi-hpv-1"', $html);
        $this->assertStringContainsString('id="lml-sbi-hpv-2"', $html);
        $this->assertStringContainsString('Human Papillomavirus (1st Dose)', $html);
        $this->assertStringContainsString('Human Papillomavirus (2nd Dose)', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-sbi-hpv-heading"[^>]*>\s*Human Papillomavirus \(HPV\)\s*<\/h3>/u',
            $html
        );
        // Decision: Vaccines Type HPV items use short "1st Dose" / "2nd Dose" labels.
        $this->assertMatchesRegularExpression(
            '/lml-sbi__types-legend[^>]*>\s*Human Papillomavirus\s*<\/legend>[\s\S]*?id="lml-sbi-type-hpv-1"[\s\S]*?1st Dose/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-sbi-type-hpv-2"[\s\S]*?2nd Dose/u',
            $html
        );
    }

    public function test_hpv_section_uses_neutral_heading_without_member_contradiction(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Human Papillomavirus (HPV)', $html);
        $this->assertStringNotContainsString('For 9 Years Old Female', $html);
        $this->assertStringNotContainsString('9 Years Old', $html);
        // Demo member remains Male (households.php) — no conflicting female claim.
        $this->assertMatchesRegularExpression(
            '/lml-child-imm__sex-badge--male[^>]*>\s*Male\s*<\/span>/u',
            $html
        );
    }

    public function test_empty_demo_record_does_not_show_false_completion_presentation(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('lml-sbi__status--recorded', $html);
        $this->assertStringNotContainsString('bi-check-circle-fill', $html);
        $this->assertStringNotContainsString('lml-sbi__hpv-badge', $html);
        $this->assertStringNotContainsString('Demo status: recorded', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sbi__hpv-card[\s\S]*?>\s*Completed\s*</u',
            $html
        );

        preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/i', $html, $checkboxes);
        $this->assertCount(6, $checkboxes[0]);
        foreach ($checkboxes[0] as $input) {
            $this->assertDoesNotMatchRegularExpression('/\bchecked\b/i', $input);
        }
    }

    public function test_six_vaccine_type_checkboxes_render_once_with_unique_ids(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $ids = [
            'lml-sbi-type-g1-td',
            'lml-sbi-type-g1-mr',
            'lml-sbi-type-g7-td',
            'lml-sbi-type-g7-mr',
            'lml-sbi-type-hpv-1',
            'lml-sbi-type-hpv-2',
        ];

        foreach ($ids as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'));
            $this->assertMatchesRegularExpression(
                '/<label\b[^>]*for="'.preg_quote($id, '/').'"/u',
                $html
            );
        }

        preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/i', $html, $checkboxes);
        $this->assertCount(6, $checkboxes[0]);

        $this->assertStringContainsString('<fieldset', $html);
        $this->assertStringContainsString('GRADE 1', $html);
        $this->assertStringContainsString('GRADE 7', $html);
        $this->assertMatchesRegularExpression(
            '/<legend\b[^>]*>\s*Human Papillomavirus\s*<\/legend>/u',
            $html
        );
    }

    public function test_all_date_and_checkbox_inputs_are_optional(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        preg_match_all('/<input\b[^>]*type="date"[^>]*>/i', $html, $dateInputs);
        $this->assertCount(6, $dateInputs[0]);

        foreach ($dateInputs[0] as $input) {
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $input);
            $this->assertStringContainsString('data-sbi-field', $input);
        }

        preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/i', $html, $checkboxes);
        $this->assertCount(6, $checkboxes[0]);

        foreach ($checkboxes[0] as $input) {
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $input);
            $this->assertStringContainsString('data-sbi-field', $input);
            $this->assertDoesNotMatchRegularExpression('/\bchecked\b/i', $input);
        }

        $this->assertStringNotContainsString('aria-required="true"', $html);
        $this->assertStringNotContainsString('bi-calendar3', $html);
    }

    public function test_page_has_no_pagination_stepper_or_previous_next_controls(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('Previous', $html);
        $this->assertStringNotContainsString('Next page', $html);
        $this->assertDoesNotMatchRegularExpression('/\bpagination\b/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\bstepper\b/i', $html);
        $this->assertStringNotContainsString('lml-sbi__workspace', $html);
    }

    public function test_default_view_mode_shows_edit_and_hides_save(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/data-sbi-edit[^>]*aria-label="Edit school-based immunization"|aria-label="Edit school-based immunization"[^>]*data-sbi-edit/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-sbi-edit[^>]*\bhidden\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*type="submit"[^>]*data-sbi-save[^>]*\bhidden\b|<button\b[^>]*data-sbi-save[^>]*type="submit"[^>]*\bhidden\b|<button\b[^>]*type="submit"[^>]*\bhidden\b[^>]*data-sbi-save/u',
            $html
        );
        $this->assertStringContainsString('aria-label="Save school-based immunization"', $html);
        $this->assertStringContainsString('data-editing="false"', $html);
        $this->assertStringContainsString('data-sbi-records', $html);
        $this->assertStringContainsString('data-persistence="preview"', $html);
    }

    public function test_view_mode_locks_school_based_controls_and_birth_history_stays_separate(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        preg_match_all(
            '/<input\b[^>]*type="date"[^>]*data-sbi-field[^>]*>|<input\b[^>]*data-sbi-field[^>]*type="date"[^>]*>/i',
            $html,
            $dateInputs
        );
        $this->assertCount(6, $dateInputs[0]);

        foreach ($dateInputs[0] as $input) {
            $this->assertMatchesRegularExpression('/\bdisabled\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
        }

        preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/i', $html, $checkboxes);
        $this->assertCount(6, $checkboxes[0]);

        foreach ($checkboxes[0] as $input) {
            $this->assertMatchesRegularExpression('/\bdisabled\b/i', $input);
            $this->assertStringContainsString('data-sbi-field', $input);
        }

        // Birth History remains a dedicated-page link — not inline SBI editing.
        $this->assertStringContainsString('data-sbi-birth-edit-link', $html);
        $this->assertStringNotContainsString('data-sbi-edit="birth-history"', $html);
        $this->assertStringNotContainsString('data-child-imm-immunization', $html);

        $birthEditUrl = route(
            'household-profiling.members.child-immunization.birth-history.edit',
            $this->memberParams()
        );
        $this->assertStringContainsString('href="'.e($birthEditUrl).'"', $html);
    }

    public function test_preview_safe_save_markup_allows_blank_and_has_no_persistence_endpoint(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-persistence="preview"', $html);
        $this->assertStringContainsString('novalidate', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-sbi-records[^>]*action=/u',
            $html
        );
        $this->assertStringNotContainsString('method="post"', strtolower($html));
        $this->assertFalse(
            Route::has('household-profiling.members.school-based-immunization.store')
            || Route::has('household-profiling.members.school-based-immunization.update')
        );
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-sbi-toast', $html);
    }

    public function test_date_and_checkbox_controls_are_independent_markup(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        // Dates and checkboxes share no coupling attributes or shared state keys.
        $this->assertStringNotContainsString('data-sync-checkbox', $html);
        $this->assertStringNotContainsString('data-sync-date', $html);
        $this->assertStringNotContainsString('data-auto-check', $html);
        $this->assertStringNotContainsString('data-linked-date', $html);
        $this->assertStringNotContainsString('data-linked-checkbox', $html);
        $this->assertStringNotContainsString('data-auto-date', $html);

        preg_match_all('/<input\b[^>]*type="date"[^>]*>/i', $html, $dates);
        preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/i', $html, $boxes);
        $this->assertCount(6, $dates[0]);
        $this->assertCount(6, $boxes[0]);

        // Dates and checkboxes both use data-sbi-field but have no shared value coupling.
        foreach ($dates[0] as $input) {
            $this->assertStringContainsString('data-sbi-field', $input);
            $this->assertStringNotContainsString('type="checkbox"', $input);
        }
        foreach ($boxes[0] as $input) {
            $this->assertStringContainsString('data-sbi-field', $input);
            $this->assertStringNotContainsString('type="date"', $input);
        }
    }

    public function test_vaccine_type_checkboxes_are_manual_optional_and_unlocked_only_via_edit_fields(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $this->memberParams()
        ));

        $response->assertOk();
        $html = $response->getContent();

        $ids = [
            'lml-sbi-type-g1-td',
            'lml-sbi-type-g1-mr',
            'lml-sbi-type-g7-td',
            'lml-sbi-type-g7-mr',
            'lml-sbi-type-hpv-1',
            'lml-sbi-type-hpv-2',
        ];

        foreach ($ids as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'));
            $this->assertMatchesRegularExpression(
                '/<input\b[^>]*id="'.preg_quote($id, '/').'"[^>]*>/u',
                $html
            );
        }

        preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/i', $html, $checkboxes);
        $this->assertCount(6, $checkboxes[0]);

        foreach ($checkboxes[0] as $input) {
            // View-mode lock; Edit mode enables via JS (data-sbi-field).
            $this->assertMatchesRegularExpression('/\bdisabled\b/i', $input);
            $this->assertStringContainsString('data-sbi-field', $input);
            $this->assertDoesNotMatchRegularExpression('/\brequired\b/i', $input);
            $this->assertDoesNotMatchRegularExpression('/aria-required\s*=\s*["\']true["\']/i', $input);
            $this->assertDoesNotMatchRegularExpression('/\bchecked\b/i', $input);
        }

        // Preview save accepts blank dates with any checkbox combination — no server validation.
        $this->assertStringContainsString('novalidate', $html);
        $this->assertStringContainsString('data-persistence="preview"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-sbi-records[^>]*action=/u',
            $html
        );
    }

    /**
     * Source-level guard: page script must not implement date↔checkbox sync.
     * Preview Save therefore accepts checked+blank-date and date+unchecked independently.
     */
    public function test_page_script_does_not_implement_date_checkbox_synchronization(): void
    {
        $jsPath = resource_path('js/pages/school-based-immunization.js');
        $this->assertFileExists($jsPath);
        $js = file_get_contents($jsPath);
        $this->assertNotFalse($js);

        $this->assertStringContainsString(
            'Does not sync Vaccines Type checkboxes with date inputs.',
            $js
        );
        $this->assertStringNotContainsString('data-sync-checkbox', $js);
        $this->assertStringNotContainsString('data-sync-date', $js);
        $this->assertStringNotContainsString('data-auto-check', $js);
        $this->assertStringNotContainsString('data-auto-date', $js);
        $this->assertStringNotContainsString('.checked = ', $js);
        $this->assertStringNotContainsString('.checked=', $js);
        $this->assertDoesNotMatchRegularExpression(
            '/addEventListener\(\s*[\'"](?:change|input)[\'"].{0,200}(?:checkbox|type="date"|type=\'date\')/is',
            $js
        );
    }

    public function test_back_link_points_to_member_view_route(): void
    {
        $params = $this->memberParams();
        $backUrl = route('household-profiling.members.show', $params);

        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            $params
        ));

        $response->assertOk();
        $response->assertSee('href="'.e($backUrl).'"', false);
        $response->assertSee(
            'aria-label="Back to Health Summary Records for Kristine Reyes"',
            false
        );
    }

    public function test_malformed_identifiers_are_not_routable(): void
    {
        $this->get('/household-profiling/HH151/members/MB-001/school-based-immunization')
            ->assertNotFound();
        $this->get('/household-profiling/HH-151/members/MB001/school-based-immunization')
            ->assertNotFound();
    }

    public function test_unknown_member_shows_not_found_state(): void
    {
        $response = $this->get(route(
            'household-profiling.members.school-based-immunization',
            [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-999',
            ]
        ));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Member not found', $html);
        $this->assertStringNotContainsString('data-sbi-records', $html);
    }
}
