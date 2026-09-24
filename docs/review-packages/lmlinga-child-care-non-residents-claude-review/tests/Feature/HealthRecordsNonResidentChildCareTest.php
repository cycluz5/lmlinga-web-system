<?php

namespace Tests\Feature;

use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsNonResidentChildCare;
use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Child Care → Non-Resident / unregistered children.
 */
class HealthRecordsNonResidentChildCareTest extends TestCase
{
    public function test_non_resident_routes_resolve(): void
    {
        foreach ([
            'health-records.child-care.non-residents.index',
            'health-records.child-care.non-residents.create',
            'health-records.child-care.non-residents.show',
            'health-records.child-care.non-residents.edit',
            'health-records.child-care.non-residents.nutrition',
            'health-records.child-care.non-residents.nutrition.create',
            'health-records.child-care.non-residents.nutrition.edit',
            'health-records.child-care.non-residents.deworming',
            'health-records.child-care.non-residents.deworming.create',
            'health-records.child-care.non-residents.immunization',
            'health-records.child-care.non-residents.immunization.birth-history',
            'health-records.child-care.non-residents.school-based-immunization',
            'health-records.child-care.non-residents.child-nutrition',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route: {$name}");
        }

        $this->assertFalse(Route::has('health-records.child-care.non-residents.nutrition.store'));
        $this->assertFalse(Route::has('health-records.child-care.non-residents.deworming.store'));
        $this->assertFalse(Route::has('health-records.child-care.non-residents.update'));
        $this->assertFalse(Route::has('health-records.child-care.non-residents.destroy'));

        $deworming = Route::getRoutes()->getByName('health-records.child-care.non-residents.deworming');
        $this->assertNotNull($deworming);
        $this->assertSame(
            'health-records/child-care/non-residents/{childKey}/deworming',
            $deworming->uri()
        );

        $index = Route::getRoutes()->getByName('health-records.child-care.non-residents.index');
        $this->assertNotNull($index);
        $this->assertSame('health-records/child-care/non-residents', $index->uri());
    }

    public function test_listing_renders_and_keeps_child_care_sidebar_active(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-cc-nr', $html);
        $this->assertMatchesRegularExpression(
            '/<h1 class="lml-topbar__title">\s*Child Care \| Non-Residents\s*<\/h1>/u',
            $html
        );
        $this->assertStringContainsString('Non-resident and unregistered child care records.', $html);
        $this->assertStringNotContainsString('lml-hr-cc-nr__breadcrumb', $html);
        $this->assertStringNotContainsString('lml-hr-cc-nr__title', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/lml-hr-cc-nr__panel[\s\S]*Health Records[\s\S]*&gt;[\s\S]*Child Care/u',
            $html
        );
        $this->assertStringNotContainsString('>Child Care | Non-Residents</h2>', $html);
        $this->assertStringContainsString('data-hr-cc-nr-add', $html);
        $this->assertStringContainsString('data-hr-cc-nr-search', $html);
        $this->assertStringContainsString('data-hr-cc-nr-barangay', $html);
        $this->assertStringContainsString('data-hr-cc-nr-year', $html);
        $this->assertStringContainsString('lml-hr-cc-nr__table', $html);
        $this->assertStringContainsString('lml-hr-cc-nr__view-btn', $html);
        $this->assertStringContainsString('No non-resident child records match the selected filters.', $html);

        foreach (['Full Name', 'Age', 'Health Status', 'Action'] as $heading) {
            $this->assertSame(1, substr_count($html, '>'.$heading.'</th>'));
        }

        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"[^>]*>[\s\S]*>Child Care</u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink[^>]*>\s*(?:<[^>]+>\s*)*Non-Residents\s*</u',
            $html
        );
    }

    public function test_summary_pill_and_service_tabs_remain_unchanged(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-hr-cc-non-residents'));
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.non-residents.index')).'"',
            $html
        );
        $this->assertSame(1, preg_match_all('/>\s*Vitamin A\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Deworming\s*<\/a>/u', $html));
        $this->assertSame(1, preg_match_all('/>\s*Operation Timbang\s*<\/a>/u', $html));
        $this->assertStringContainsString('data-hr-cc-add', $html);
        $this->assertStringContainsString('data-hr-cc-export', $html);
    }

    public function test_listing_excludes_residents_and_includes_non_residents(): void
    {
        $rows = HealthRecordsNonResidentChildCare::rows();
        $names = array_map(static fn (array $row): string => $row['full_name'], $rows);

        $this->assertContains('Andrei B. Malaya', $names);
        $this->assertContains('Crisley F. Fernando', $names);
        $this->assertContains('Gabriel Allan S. Chua', $names);
        $this->assertContains('Roselyn A. Mendoza', $names);
        $this->assertNotContains('Kristine B. Reyes', $names);
        $this->assertNotContains('Jacob A. Magistrado', $names);
        $this->assertNotContains('Haziel H. Santos', $names);

        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.index'))
            ->getContent();

        $this->assertStringContainsString('Andrei B. Malaya', $html);
        $this->assertStringContainsString('Crisley F. Fernando', $html);
        $this->assertStringNotContainsString('>Kristine B. Reyes<', $html);
        $this->assertStringNotContainsString('>Jacob A. Magistrado<', $html);
        $this->assertStringNotContainsString('>Haziel H. Santos<', $html);
    }

    public function test_full_name_normalization_classifies_residents(): void
    {
        $this->assertTrue(
            HealthRecordsNonResidentChildCare::isResidentFullName('  kristine b. reyes  ')
        );
        $this->assertTrue(
            HealthRecordsNonResidentChildCare::isResidentFullName('KRISTINE   B.   REYES')
        );
        $this->assertFalse(
            HealthRecordsNonResidentChildCare::isResidentFullName('Roselyn A. Mendoza')
        );
        $this->assertFalse(
            HealthRecordsNonResidentChildCare::isResidentFullName('Kristine')
        );
        $this->assertFalse(
            HealthRecordsNonResidentChildCare::isResidentFullName('Reyes')
        );

        $resident = HealthRecordsNonResidentChildCare::findResidentByFullName('Kristine B. Reyes');
        $this->assertNotNull($resident);
        $this->assertSame('Kristine B. Reyes', $resident['full_name']);
        $this->assertNotSame('', $resident['view_url']);
    }

    public function test_search_barangay_year_and_combined_filters(): void
    {
        $rows = HealthRecordsNonResidentChildCare::rows();

        $search = HealthRecordsNonResidentChildCare::filterRows($rows, 'cris', 'all', 'all');
        $this->assertCount(1, $search);
        $this->assertSame('Crisley F. Fernando', $search[0]['full_name']);

        $barangay = HealthRecordsNonResidentChildCare::filterRows($rows, '', 'Brgy. San Jose', 'all');
        $barangayNames = array_column($barangay, 'full_name');
        $this->assertContains('Andrei B. Malaya', $barangayNames);
        $this->assertContains('Roselyn A. Mendoza', $barangayNames);
        $this->assertNotContains('Crisley F. Fernando', $barangayNames);

        $year = HealthRecordsNonResidentChildCare::filterRows($rows, '', 'all', '2024');
        foreach ($year as $row) {
            $this->assertSame('2024', $row['year']);
        }
        $this->assertNotEmpty($year);

        $combined = HealthRecordsNonResidentChildCare::filterRows($rows, 'andrei', 'Brgy. San Jose', '2026');
        $this->assertCount(1, $combined);
        $this->assertSame('Andrei B. Malaya', $combined[0]['full_name']);

        $empty = HealthRecordsNonResidentChildCare::filterRows($rows, 'zzzz-no-match', 'all', 'all');
        $this->assertSame([], $empty);
    }

    public function test_add_page_and_cancel_return_to_listing(): void
    {
        $createUrl = route('health-records.child-care.non-residents.create');
        $listingUrl = route('health-records.child-care.non-residents.index');

        $listing = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get($listingUrl);
        $listing->assertOk();
        $this->assertStringContainsString('href="'.e($createUrl).'"', $listing->getContent());
        $this->assertStringContainsString('data-hr-cc-nr-add', $listing->getContent());

        $create = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get($createUrl);
        $create->assertOk();
        $html = $create->getContent();

        $this->assertStringContainsString('Add New Child', $html);
        $this->assertStringContainsString('CHILD INFORMATION', $html);
        $this->assertStringContainsString('data-hr-cc-nr-create-form', $html);
        $this->assertStringContainsString('This child appears to already exist in Household Profiling.', $html);
        $this->assertStringContainsString('href="'.e($listingUrl).'"', $html);
        $this->assertStringContainsString('data-hr-cc-nr-cancel', $html);
        $this->assertStringContainsString('data-hr-cc-nr-residents', $html);
        $this->assertStringContainsString('kristine b. reyes', $html);
        $this->assertSame('child-care', UiRole::sidebarActiveKey());

        $this->assertSame(1, preg_match_all('/for="lml-hr-cc-nr-mother-name">Mother\'s Name</u', $html));
        $this->assertStringNotContainsString("Mother's First Name</label>", $html);
        $this->assertStringNotContainsString("Mother's Middle Name", $html);
        $this->assertStringNotContainsString("Mother's Last Name", $html);
        $this->assertTrue(
            str_contains($html, 'placeholder="Mother&#039;s First Name"')
            || str_contains($html, 'placeholder="Mother\'s First Name"')
        );

        $this->assertMatchesRegularExpression('/<label[^>]+for="lml-hr-cc-nr-address">Address<\/label>/u', $html);
        $this->assertStringNotContainsString('Address / Zone', $html);
        $this->assertStringContainsString('placeholder="Zone"', $html);

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="lml-hr-cc-nr-barangay-field"[^>]*type="text"/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<select[^>]*id="lml-hr-cc-nr-barangay-field"/u',
            $html
        );

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="lml-hr-cc-nr-grade"[^>]*type="text"/u',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<select[^>]*id="lml-hr-cc-nr-grade"/u',
            $html
        );
        $this->assertStringContainsString('placeholder="Grade Level"', $html);

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="lml-hr-cc-nr-school"[^>]*type="text"/u',
            $html
        );
        $this->assertStringContainsString('data-hr-cc-nr-save', $html);
    }

    public function test_view_uses_non_resident_destination_not_household_member(): void
    {
        $row = collect(HealthRecordsNonResidentChildCare::rows())
            ->firstWhere('full_name', 'Andrei B. Malaya');
        $this->assertNotNull($row);

        $expected = route('health-records.child-care.non-residents.show', [
            'childKey' => $row['key'],
        ]);
        $this->assertSame($expected, $row['view_url']);

        $listing = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.index'));
        $this->assertStringContainsString('href="'.e($expected).'"', $listing->getContent());

        $show = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get($expected);
        $show->assertOk();
        $html = $show->getContent();
        $this->assertStringContainsString('Andrei B. Malaya', $html);
        $this->assertStringNotContainsString('household-profiling/members/', $html);
        $this->assertStringNotContainsString(
            'Detailed non-resident child-record workflow is reserved for a later phase.',
            $html
        );
    }

    public function test_show_page_displays_selected_child_profile_and_dashboard(): void
    {
        $andrei = HealthRecordsNonResidentChildCare::find('andrei-b-malaya');
        $sofia = HealthRecordsNonResidentChildCare::find('sofia-l-navarro');
        $this->assertNotNull($andrei);
        $this->assertNotNull($sofia);

        $andreiHtml = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.show', [
                'childKey' => 'andrei-b-malaya',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Andrei B. Malaya', $andreiHtml);
        $this->assertStringContainsString('Male', $andreiHtml);
        $this->assertStringContainsString('>Age</dt>', $andreiHtml);
        $this->assertStringContainsString($andrei['age_label'], $andreiHtml);
        $this->assertStringContainsString('>Date Birth</dt>', $andreiHtml);
        $this->assertStringContainsString($andrei['birthday_label'], $andreiHtml);
        $this->assertStringContainsString(">Mother's Name</dt>", $andreiHtml);
        $this->assertStringContainsString('Liza B. Malaya', $andreiHtml);
        $this->assertStringContainsString('>Address</dt>', $andreiHtml);
        $this->assertStringContainsString($andrei['address_line'], $andreiHtml);
        $this->assertStringContainsString('School &amp; Grade Level', $andreiHtml);
        $this->assertStringContainsString('Not Recorded', $andreiHtml);
        $this->assertStringContainsString('bi-clipboard2-pulse', $andreiHtml);
        $this->assertStringContainsString('bi-activity', $andreiHtml);
        $this->assertStringContainsString('6.1 kg', $andreiHtml);
        $this->assertStringContainsString('data-hr-cc-nr-nutrition-summary="first"', $andreiHtml);
        $this->assertStringContainsString('CHILD CARE RECORD', $andreiHtml);
        $this->assertStringContainsString('Child Immunization', $andreiHtml);
        $this->assertStringContainsString('School Based Immunization', $andreiHtml);
        $this->assertStringContainsString('Child Nutrition', $andreiHtml);
        $this->assertStringContainsString('Deworming', $andreiHtml);
        $this->assertStringContainsString('Nutritional Status', $andreiHtml);
        $this->assertStringContainsString('Track the growth of the child', $andreiHtml);
        $this->assertStringContainsString('aria-label="Back to Non-Residents"', $andreiHtml);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.non-residents.index')).'"',
            $andreiHtml
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.non-residents.nutrition', [
                'childKey' => 'andrei-b-malaya',
            ])).'"',
            $andreiHtml
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.non-residents.deworming', [
                'childKey' => 'andrei-b-malaya',
            ])).'"',
            $andreiHtml
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.non-residents.immunization', [
                'childKey' => 'andrei-b-malaya',
            ])).'"',
            $andreiHtml
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.non-residents.school-based-immunization', [
                'childKey' => 'andrei-b-malaya',
            ])).'"',
            $andreiHtml
        );
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.non-residents.child-nutrition', [
                'childKey' => 'andrei-b-malaya',
            ])).'"',
            $andreiHtml
        );
        $this->assertSame(5, substr_count($andreiHtml, 'View →'));
        $this->assertStringNotContainsString('Unavailable', $andreiHtml);
        $this->assertStringContainsString('Non-Resident', $andreiHtml);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.non-residents.edit', [
                'childKey' => 'andrei-b-malaya',
            ])).'"',
            $andreiHtml
        );
        $this->assertStringContainsString('lml-hr-cc-nr__profile-edit', $andreiHtml);
        $this->assertStringNotContainsString('health-records/child-care/deworming"', $andreiHtml);
        $this->assertSame(1, substr_count($andreiHtml, 'id="lml-hr-cc-nr-child-name"'));
        $this->assertSame(1, substr_count($andreiHtml, 'id="lml-hr-cc-nr-record-title"'));
        $this->assertSame(1, substr_count($andreiHtml, 'id="lml-hr-cc-nr-nutrition-title"'));
        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"[^>]*>[\s\S]*>Child Care</u',
            $andreiHtml
        );

        $sofiaHtml = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.show', [
                'childKey' => 'sofia-l-navarro',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sofia L. Navarro', $sofiaHtml);
        $this->assertStringContainsString('Female', $sofiaHtml);
        $this->assertStringContainsString('San Isidro Learning Center (Kinder)', $sofiaHtml);
        $this->assertStringNotContainsString('Not Recorded', $sofiaHtml);
        $this->assertStringNotContainsString('Andrei B. Malaya', $sofiaHtml);
    }

    public function test_resident_records_cannot_open_through_non_resident_route(): void
    {
        $this->assertNull(HealthRecordsNonResidentChildCare::find('kristine-b-reyes'));

        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.show', [
                'childKey' => 'kristine-b-reyes',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Record not found', $html);
        $this->assertStringNotContainsString('CHILD CARE RECORD', $html);
        $this->assertStringNotContainsString('id="lml-hr-cc-nr-child-name"', $html);
        $this->assertStringNotContainsString('household-profiling/members/', $html);
    }

    public function test_nutrition_history_and_measurement_destinations_resolve(): void
    {
        $nutritionUrl = route('health-records.child-care.non-residents.nutrition', [
            'childKey' => 'andrei-b-malaya',
        ]);
        $createUrl = route('health-records.child-care.non-residents.nutrition.create', [
            'childKey' => 'andrei-b-malaya',
        ]);
        $editUrl = route('health-records.child-care.non-residents.nutrition.edit', [
            'childKey' => 'andrei-b-malaya',
            'measurementId' => 'NR-CC-NUT-AND-001',
        ]);

        $nutrition = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get($nutritionUrl);
        $nutrition->assertOk();
        $nutritionHtml = $nutrition->getContent();
        $this->assertStringContainsString('Andrei B. Malaya', $nutritionHtml);
        $this->assertStringContainsString('0–12 Months Record', $nutritionHtml);
        $this->assertStringContainsString('1–5 Years Old Record', $nutritionHtml);
        $this->assertStringContainsString('data-hr-cc-nr-age-group="infant"', $nutritionHtml);
        $this->assertStringContainsString('data-hr-cc-nr-age-group="child"', $nutritionHtml);
        $this->assertStringContainsString('href="'.e($createUrl).'"', $nutritionHtml);
        $this->assertStringContainsString('href="'.e($editUrl).'"', $nutritionHtml);
        $this->assertStringContainsString('6.1 kg', $nutritionHtml);
        $this->assertStringContainsString('data-hr-cc-nr-measure-row', $nutritionHtml);
        $this->assertStringContainsString('June 12, 2026', $nutritionHtml);
        $this->assertStringContainsString('<dt>Weight Progress</dt>', $nutritionHtml);
        $this->assertStringContainsString('<dt>Height Progress</dt>', $nutritionHtml);
        $this->assertStringNotContainsString('<dt>Progress</dt>', $nutritionHtml);
        $this->assertStringNotContainsString('Edit latest', $nutritionHtml);
        $this->assertSame(2, preg_match_all('/<div class="lml-hr-cc-nr__age-box-head">([\s\S]*?)<\/div>/u', $nutritionHtml, $andreiHeads));
        foreach ($andreiHeads[1] as $headHtml) {
            $this->assertStringNotContainsString('Edit', $headHtml);
        }
        $this->assertSame(1, substr_count($nutritionHtml, 'data-hr-cc-nr-measure-row'));
        $this->assertSame(1, substr_count($nutritionHtml, 'data-hr-cc-nr-measure-edit'));
        $this->assertSame(1, substr_count($nutritionHtml, 'data-hr-cc-nr-add-record'));
        $this->assertSame('child-care', UiRole::sidebarActiveKey());

        $empty = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.nutrition', [
                'childKey' => 'roselyn-a-mendoza',
            ]));
        $empty->assertOk();
        $this->assertStringContainsString(
            'No nutritional measurements are recorded for this child.',
            $empty->getContent()
        );

        $create = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get($createUrl);
        $create->assertOk();
        $createHtml = $create->getContent();
        $this->assertStringContainsString('Add Measurement for Child', $createHtml);
        $this->assertStringContainsString('type="date"', $createHtml);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-m-weight"[^>]*type="number"/u', $createHtml);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-m-height"[^>]*type="number"/u', $createHtml);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-m-muac"[^>]*type="number"/u', $createHtml);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-m-wfa"[^>]*type="text"/u', $createHtml);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="lml-hr-cc-nr-m-wfa"[^>]*type="date"/u', $createHtml);
        $this->assertStringContainsString('href="'.e($nutritionUrl).'"', $createHtml);
        $this->assertStringContainsString('data-hr-cc-nr-cancel', $createHtml);
        $this->assertStringContainsString(
            'Preview only: this measurement was not saved to the database.',
            $createHtml
        );
        $this->assertStringNotContainsString('saved to the database successfully', $createHtml);

        $edit = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get($editUrl);
        $edit->assertOk();
        $editHtml = $edit->getContent();
        $this->assertStringContainsString('Edit Measurement for Child', $editHtml);
        $this->assertStringContainsString('value="2026-06-12"', $editHtml);
        $this->assertStringContainsString('value="6.1"', $editHtml);
        $this->assertStringContainsString('data-hr-cc-nr-cancel', $editHtml);
        $this->assertStringContainsString('data-hr-cc-nr-save', $editHtml);
        $this->assertStringContainsString('>Cancel</a>', $editHtml);
        $this->assertStringContainsString('>Save</button>', $editHtml);
        $this->assertStringNotContainsString('data-hr-cc-nr-measure-delete', $editHtml);
        $this->assertStringNotContainsString('>Delete</button>', $editHtml);
        $this->assertStringNotContainsString(
            'Preview only: this measurement was not deleted. Backend persistence is not yet implemented.',
            $editHtml
        );
    }

    public function test_view_nutritional_summary_uses_first_measurement_while_history_keeps_later_records(): void
    {
        $crisley = HealthRecordsNonResidentChildCare::find('crisley-f-fernando');
        $this->assertNotNull($crisley);
        $this->assertSame('NR-CC-NUT-CRI-001', $crisley['first_measurement']['id'] ?? null);
        $this->assertSame(7.2, $crisley['first_measurement']['weight_kg'] ?? null);
        $this->assertSame(68.0, $crisley['first_measurement']['height_cm'] ?? null);
        $this->assertSame(13.8, $crisley['first_measurement']['muac_cm'] ?? null);
        $this->assertSame('NR-CC-NUT-CRI-003', $crisley['latest_measurement']['id'] ?? null);
        $this->assertSame(8.6, $crisley['latest_measurement']['weight_kg'] ?? null);

        $showHtml = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.show', [
                'childKey' => 'crisley-f-fernando',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Crisley F. Fernando', $showHtml);
        $this->assertStringContainsString('data-hr-cc-nr-nutrition-summary="first"', $showHtml);
        $this->assertStringContainsString('7.2 kg', $showHtml);
        $this->assertStringContainsString('68.0 cm', $showHtml);
        $this->assertStringContainsString('13.8 cm', $showHtml);
        $this->assertStringNotContainsString('8.6 kg', $showHtml);
        $this->assertStringNotContainsString('74.0 cm', $showHtml);

        $nutritionHtml = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.nutrition', [
                'childKey' => 'crisley-f-fernando',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('0–12 Months Record', $nutritionHtml);
        $this->assertStringContainsString('1–5 Years Old Record', $nutritionHtml);
        $this->assertSame(2, substr_count($nutritionHtml, 'data-hr-cc-nr-age-group='));
        $this->assertSame(3, substr_count($nutritionHtml, 'data-hr-cc-nr-measure-row'));
        $this->assertStringContainsString('7.2 kg', $nutritionHtml);
        $this->assertStringContainsString('8.0 kg', $nutritionHtml);
        $this->assertStringContainsString('8.6 kg', $nutritionHtml);
        $this->assertStringContainsString('68.0 cm', $nutritionHtml);
        $this->assertStringContainsString('71.5 cm', $nutritionHtml);
        $this->assertStringContainsString('74.0 cm', $nutritionHtml);
        $this->assertStringContainsString('13.8 cm', $nutritionHtml);
        $this->assertStringContainsString('14.0 cm', $nutritionHtml);
        $this->assertStringContainsString('14.2 cm', $nutritionHtml);
        $this->assertStringContainsString('November 1, 2025', $nutritionHtml);
        $this->assertStringContainsString('February 1, 2026', $nutritionHtml);
        $this->assertStringContainsString('July 1, 2026', $nutritionHtml);
        $this->assertStringContainsString('data-hr-cc-nr-measure-id="NR-CC-NUT-CRI-001"', $nutritionHtml);
        $this->assertStringContainsString('data-hr-cc-nr-measure-id="NR-CC-NUT-CRI-002"', $nutritionHtml);
        $this->assertStringContainsString('data-hr-cc-nr-measure-id="NR-CC-NUT-CRI-003"', $nutritionHtml);
        $this->assertStringContainsString('No records in this age group.', $nutritionHtml);
        $this->assertStringNotContainsString('data-hr-cc-nr-measure-delete', $nutritionHtml);
        $this->assertStringNotContainsString('>Delete</button>', $nutritionHtml);
        $this->assertStringNotContainsString('Edit latest', $nutritionHtml);
        $this->assertSame(2, preg_match_all('/<div class="lml-hr-cc-nr__age-box-head">([\s\S]*?)<\/div>/u', $nutritionHtml, $crisleyHeads));
        foreach ($crisleyHeads[1] as $headHtml) {
            $this->assertStringNotContainsString('Edit', $headHtml);
        }
        $this->assertSame(3, substr_count($nutritionHtml, 'data-hr-cc-nr-measure-edit'));
        $this->assertSame(1, preg_match_all('/lml-hr-cc-nr__profile-edit/u', $nutritionHtml));
        $this->assertSame(4, preg_match_all('/Edit\s*<\/a>/u', $nutritionHtml));
        $this->assertSame(3, substr_count($nutritionHtml, '<dt>Weight Progress</dt>'));
        $this->assertSame(3, substr_count($nutritionHtml, '<dt>Height Progress</dt>'));
        $this->assertStringNotContainsString('<dt>Progress</dt>', $nutritionHtml);
        $this->assertStringContainsString('+0.8 kg', $nutritionHtml);
        $this->assertStringContainsString('+0.6 kg', $nutritionHtml);
        $this->assertStringContainsString('+3.5 cm', $nutritionHtml);
        $this->assertStringContainsString('+2.5 cm', $nutritionHtml);
    }

    public function test_nutrition_history_assigns_records_to_correct_age_group_boxes(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.nutrition', [
                'childKey' => 'gabriel-allan-s-chua',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('0–12 Months Record', $html);
        $this->assertStringContainsString('1–5 Years Old Record', $html);
        $this->assertSame(2, substr_count($html, 'data-hr-cc-nr-age-group='));
        $this->assertSame(3, substr_count($html, 'data-hr-cc-nr-measure-row'));
        $this->assertStringContainsString('January 15, 2025', $html);
        $this->assertStringContainsString('August 15, 2025', $html);
        $this->assertStringContainsString('June 15, 2026', $html);
        $this->assertStringContainsString('7.4 kg', $html);
        $this->assertStringContainsString('9.1 kg', $html);
        $this->assertStringContainsString('11.4 kg', $html);

        $infantStart = strpos($html, 'data-hr-cc-nr-age-group="infant"');
        $childStart = strpos($html, 'data-hr-cc-nr-age-group="child"');
        $this->assertNotFalse($infantStart);
        $this->assertNotFalse($childStart);
        $infantHtml = substr($html, $infantStart, $childStart - $infantStart);
        $childHtml = substr($html, $childStart);

        $this->assertStringContainsString('January 15, 2025', $infantHtml);
        $this->assertStringContainsString('7.4 kg', $infantHtml);
        $this->assertStringNotContainsString('August 15, 2025', $infantHtml);
        $this->assertStringNotContainsString('June 15, 2026', $infantHtml);
        $this->assertSame(1, substr_count($infantHtml, 'data-hr-cc-nr-measure-row'));
        $this->assertSame(1, substr_count($infantHtml, 'data-hr-cc-nr-measure-edit'));
        $this->assertStringContainsString('August 15, 2025', $childHtml);
        $this->assertStringContainsString('June 15, 2026', $childHtml);
        $this->assertStringContainsString('9.1 kg', $childHtml);
        $this->assertStringContainsString('11.4 kg', $childHtml);
        $this->assertStringNotContainsString('January 15, 2025', $childHtml);
        $this->assertSame(2, substr_count($childHtml, 'data-hr-cc-nr-measure-row'));
        $this->assertSame(2, substr_count($childHtml, 'data-hr-cc-nr-measure-edit'));
        $this->assertSame(2, preg_match_all('/<div class="lml-hr-cc-nr__age-box-head">([\s\S]*?)<\/div>/u', $html, $gabrielHeads));
        foreach ($gabrielHeads[1] as $headHtml) {
            $this->assertStringNotContainsString('Edit', $headHtml);
        }
        $this->assertSame(3, substr_count($html, 'data-hr-cc-nr-measure-edit'));
    }

    public function test_immunization_school_based_and_child_nutrition_are_child_scoped(): void
    {
        $immUrl = route('health-records.child-care.non-residents.immunization', [
            'childKey' => 'andrei-b-malaya',
        ]);
        $sbiUrl = route('health-records.child-care.non-residents.school-based-immunization', [
            'childKey' => 'andrei-b-malaya',
        ]);
        $cnUrl = route('health-records.child-care.non-residents.child-nutrition', [
            'childKey' => 'andrei-b-malaya',
        ]);
        $bhUrl = route('health-records.child-care.non-residents.immunization.birth-history', [
            'childKey' => 'andrei-b-malaya',
        ]);
        $showUrl = route('health-records.child-care.non-residents.show', [
            'childKey' => 'andrei-b-malaya',
        ]);

        $this->assertStringContainsString('/non-residents/andrei-b-malaya/immunization', $immUrl);
        $this->assertStringNotContainsString('household-profiling', $immUrl);

        $imm = $this->withSession([UiRole::SESSION_KEY => 'bhw'])->get($immUrl);
        $imm->assertOk();
        $immHtml = $imm->getContent();
        $this->assertStringContainsString('Andrei B. Malaya', $immHtml);
        $this->assertStringContainsString('Non-Resident', $immHtml);
        $this->assertStringContainsString('id="lml-hr-cc-nr-child-name"', $immHtml);
        $this->assertStringContainsString('Immunization', $immHtml);
        $this->assertStringContainsString('BCG', $immHtml);
        $this->assertStringContainsString('MMR', $immHtml);
        $this->assertStringContainsString('FIC', $immHtml);
        $this->assertStringContainsString('CIC', $immHtml);
        $this->assertStringContainsString('1 dose', $immHtml);
        $this->assertStringContainsString('2 doses', $immHtml);
        $this->assertStringContainsString('aria-label="Back to child record"', $immHtml);
        $this->assertStringContainsString('href="'.e($showUrl).'"', $immHtml);
        $this->assertStringContainsString('href="'.e($bhUrl).'?from=immunization"', $immHtml);
        $this->assertStringContainsString('data-persistence="preview"', $immHtml);
        $this->assertStringNotContainsString('household-profiling/members/', $immHtml);
        $this->assertStringNotContainsString('Sofia L. Navarro', $immHtml);
        $this->assertSame('child-care', UiRole::sidebarActiveKey());

        $sbi = $this->withSession([UiRole::SESSION_KEY => 'bhw'])->get($sbiUrl);
        $sbi->assertOk();
        $sbiHtml = $sbi->getContent();
        $this->assertStringContainsString('Andrei B. Malaya', $sbiHtml);
        $this->assertStringContainsString('Non-Resident', $sbiHtml);
        $this->assertStringContainsString('School-Based Immunization', $sbiHtml);
        $this->assertStringContainsString('Grade 1', $sbiHtml);
        $this->assertStringContainsString('Grade 7', $sbiHtml);
        $this->assertStringContainsString('Human Papillomavirus (HPV)', $sbiHtml);
        $this->assertStringNotContainsString('For 9 Years Old Female', $sbiHtml);
        $this->assertStringNotContainsString('lml-sbi__status--recorded', $sbiHtml);
        $this->assertStringContainsString('href="'.e($showUrl).'"', $sbiHtml);
        $this->assertStringNotContainsString('household-profiling/members/', $sbiHtml);

        $cn = $this->withSession([UiRole::SESSION_KEY => 'bhw'])->get($cnUrl);
        $cn->assertOk();
        $cnHtml = $cn->getContent();
        $this->assertStringContainsString('Andrei B. Malaya', $cnHtml);
        $this->assertStringContainsString('Non-Resident', $cnHtml);
        $this->assertStringContainsString('Child Nutrition', $cnHtml);
        $this->assertStringContainsString('New Born (0–28 Days Old)', $cnHtml);
        $this->assertStringContainsString('For Low Birth Only', $cnHtml);
        $this->assertStringContainsString('Vitamin A', $cnHtml);
        $this->assertStringContainsString('MNP', $cnHtml);
        $this->assertStringContainsString('LNS-SQ', $cnHtml);
        $this->assertStringContainsString('Supplementary Feeding Program', $cnHtml);
        $this->assertStringContainsString('MAM', $cnHtml);
        $this->assertStringContainsString('SAM', $cnHtml);
        $this->assertStringContainsString('href="'.e($showUrl).'"', $cnHtml);
        $this->assertStringNotContainsString('0–12 Months Record', $cnHtml);
        $this->assertStringNotContainsString('household-profiling/members/', $cnHtml);

        $bh = $this->withSession([UiRole::SESSION_KEY => 'bhw'])->get($bhUrl.'?from=immunization');
        $bh->assertOk();
        $bhHtml = $bh->getContent();
        $this->assertStringContainsString('Andrei B. Malaya', $bhHtml);
        $this->assertStringContainsString('Non-Resident', $bhHtml);
        $this->assertStringContainsString('Birth History', $bhHtml);
        $this->assertStringContainsString('href="'.e($immUrl).'"', $bhHtml);
        $this->assertStringContainsString('data-persistence="preview"', $bhHtml);
        $this->assertStringContainsString('data-household-no="nr"', $bhHtml);

        foreach (['immunization', 'school-based-immunization', 'child-nutrition'] as $suffix) {
            $resident = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
                ->get('/health-records/child-care/non-residents/kristine-b-reyes/'.$suffix);
            $resident->assertOk();
            $this->assertStringContainsString('Record not found', $resident->getContent());
            $this->assertStringNotContainsString('id="lml-hr-cc-nr-child-name"', $resident->getContent());
        }
    }

    public function test_deworming_record_and_create_pages_resolve_for_selected_child(): void
    {
        $indexUrl = route('health-records.child-care.non-residents.deworming', [
            'childKey' => 'gabriel-allan-s-chua',
        ]);
        $createUrl = route('health-records.child-care.non-residents.deworming.create', [
            'childKey' => 'gabriel-allan-s-chua',
        ]);

        $this->assertStringContainsString('/non-residents/gabriel-allan-s-chua/deworming', $indexUrl);
        $this->assertStringNotContainsString('household-profiling', $indexUrl);

        $index = $this->withSession([UiRole::SESSION_KEY => 'bhw'])->get($indexUrl);
        $index->assertOk();
        $html = $index->getContent();

        $this->assertStringContainsString('Gabriel Allan S. Chua', $html);
        $this->assertStringContainsString('Non-Resident', $html);
        $this->assertStringContainsString('id="lml-hr-cc-nr-child-name"', $html);
        $this->assertStringContainsString('Deworming Record', $html);
        $this->assertStringContainsString('lml-hr-cc-nr__table--deworming', $html);
        $this->assertStringContainsString('>Year</th>', $html);
        $this->assertStringContainsString('>Round</th>', $html);
        $this->assertStringContainsString('>SE Status</th>', $html);
        $this->assertStringContainsString('>Date Given</th>', $html);
        $this->assertStringContainsString('>Remarks</th>', $html);
        $this->assertStringContainsString('data-hr-cc-nr-add-deworming', $html);
        $this->assertStringContainsString('href="'.e($createUrl).'"', $html);
        $this->assertStringContainsString('Non-NHTS', $html);
        $this->assertStringContainsString('July 1, 2026', $html);
        $this->assertStringContainsString('scope="col"', $html);
        $this->assertStringContainsString('aria-label="Back to child record"', $html);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.non-residents.show', [
                'childKey' => 'gabriel-allan-s-chua',
            ])).'"',
            $html
        );
        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertStringNotContainsString('health-records/child-care/deworming"', $html);
        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"[^>]*>[\s\S]*>Child Care</u',
            $html
        );

        $empty = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.deworming', [
                'childKey' => 'roselyn-a-mendoza',
            ]));
        $empty->assertOk();
        $emptyHtml = $empty->getContent();
        $this->assertStringContainsString('Roselyn A. Mendoza', $emptyHtml);
        $this->assertStringContainsString('No deworming records recorded for this child.', $emptyHtml);
        $this->assertStringContainsString('data-hr-cc-nr-add-deworming', $emptyHtml);

        $create = $this->withSession([UiRole::SESSION_KEY => 'bhw'])->get($createUrl);
        $create->assertOk();
        $createHtml = $create->getContent();
        $this->assertStringContainsString('Add Deworming Record', $createHtml);
        $this->assertStringContainsString('ROUND INFORMATION', $createHtml);
        $this->assertStringContainsString('Gabriel Allan S. Chua', $createHtml);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-dw-year"[^>]*type="number"/u', $createHtml);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="lml-hr-cc-nr-dw-year"[^>]*type="date"/u', $createHtml);
        $this->assertMatchesRegularExpression('/<select[^>]*id="lml-hr-cc-nr-dw-round"/u', $createHtml);
        $this->assertMatchesRegularExpression('/<select[^>]*id="lml-hr-cc-nr-dw-se"/u', $createHtml);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-dw-date"[^>]*type="date"/u', $createHtml);
        $this->assertMatchesRegularExpression('/<textarea[^>]*id="lml-hr-cc-nr-dw-remarks"/u', $createHtml);
        $this->assertStringContainsString('>1</option>', $createHtml);
        $this->assertStringContainsString('>2</option>', $createHtml);
        $this->assertStringContainsString('Non-NHTS', $createHtml);
        $this->assertStringContainsString('NHTS', $createHtml);
        $this->assertStringContainsString('href="'.e($indexUrl).'"', $createHtml);
        $this->assertStringContainsString('data-hr-cc-nr-cancel', $createHtml);
        $this->assertStringContainsString('Deworming record preview saved for this UI phase.', $createHtml);
        $this->assertStringNotContainsString('saved to the database', $createHtml);
        $this->assertStringNotContainsString('saved to the database successfully', $createHtml);

        $resident = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.deworming', [
                'childKey' => 'kristine-b-reyes',
            ]));
        $resident->assertOk();
        $residentHtml = $resident->getContent();
        $this->assertStringContainsString('Record not found', $residentHtml);
        $this->assertStringNotContainsString('id="lml-hr-cc-nr-deworming-title"', $residentHtml);
        $this->assertStringNotContainsString('id="lml-hr-cc-nr-child-name"', $residentHtml);
        $this->assertStringNotContainsString('household-profiling/members/', $residentHtml);
    }

    public function test_edit_personal_information_page_prefills_selected_non_resident_child(): void
    {
        $editUrl = route('health-records.child-care.non-residents.edit', [
            'childKey' => 'crisley-f-fernando',
        ]);
        $viewUrl = route('health-records.child-care.non-residents.show', [
            'childKey' => 'crisley-f-fernando',
        ]);

        $this->assertStringContainsString('/non-residents/crisley-f-fernando/edit', $editUrl);
        $this->assertSame(
            'health-records/child-care/non-residents/{childKey}/edit',
            Route::getRoutes()->getByName('health-records.child-care.non-residents.edit')?->uri()
        );

        $showHtml = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get($viewUrl)
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('href="'.e($editUrl).'"', $showHtml);
        $this->assertStringContainsString('lml-hr-cc-nr__profile-edit', $showHtml);
        $this->assertStringContainsString('lml-hr-cc-nr__nr-badge', $showHtml);

        $edit = $this->withSession([UiRole::SESSION_KEY => 'bhw'])->get($editUrl);
        $edit->assertOk();
        $html = $edit->getContent();

        $this->assertStringContainsString('Edit Personal Information', $html);
        $this->assertStringNotContainsString('Add New Child', $html);
        $this->assertMatchesRegularExpression(
            '/<h1 class="lml-topbar__title">\s*Child Care \| Non-Residents\s*<\/h1>/u',
            $html
        );
        $this->assertStringContainsString('CHILD INFORMATION', $html);
        $this->assertStringContainsString('lml-hr-cc-nr__form-banner', $html);
        $this->assertStringContainsString('id="lml-hr-cc-nr-edit-title"', $html);
        $this->assertStringNotContainsString('lml-hr-cc-nr__nr-badge', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/lml-hr-cc-nr__form-banner[\s\S]*Non-Resident/u',
            $html
        );
        $this->assertStringContainsString('bi-person-gear', $html);

        foreach ([
            'First Name',
            'Middle Name',
            'Last Name',
            "Mother's Name",
            'Birthday',
            'Sex',
            'Address',
            'Barangay',
            'Municipality',
            'School Name',
            'Grade Level',
        ] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-first-name"[^>]*value="Crisley"/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-middle-name"[^>]*value="F\."/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-last-name"[^>]*value="Fernando"/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-mother-name"[^>]*value="Ana F\. Fernando"/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-birthday"[^>]*value="2025-08-01"/u', $html);
        $this->assertMatchesRegularExpression('/<option[^>]*value="Female"[^>]*selected/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-address"[^>]*value="Poblacion"/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-barangay-field"[^>]*type="text"/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-barangay-field"[^>]*value="Brgy\. Del Rosario"/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*id="lml-hr-cc-nr-barangay-field"/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-municipality"[^>]*value="Naga City"/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-school"[^>]*type="text"/u', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="lml-hr-cc-nr-grade"[^>]*type="text"/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*id="lml-hr-cc-nr-grade"/u', $html);
        $this->assertStringNotContainsString('value="Not Recorded"', $html);
        $this->assertStringNotContainsString('value="N/A"', $html);
        $this->assertSame(1, preg_match_all('/for="lml-hr-cc-nr-mother-name">Mother\'s Name</u', $html));
        $this->assertStringNotContainsString("Mother's Middle Name", $html);
        $this->assertStringNotContainsString("Mother's Last Name", $html);
        $this->assertStringContainsString('data-hr-cc-nr-cancel', $html);
        $this->assertStringContainsString('data-hr-cc-nr-save', $html);
        $this->assertStringContainsString('href="'.e($viewUrl).'"', $html);
        $this->assertMatchesRegularExpression('/>\s*Cancel\s*<\/a>/u', $html);
        $this->assertMatchesRegularExpression('/>\s*Save\s*<\/button>/u', $html);
        $this->assertStringNotContainsString('data-hr-cc-nr-measure-delete', $html);
        $this->assertStringNotContainsString('>Delete</button>', $html);
        $this->assertStringContainsString(
            "Preview only: this child's personal information was not saved to the database.",
            $html
        );
        $this->assertSame('child-care', UiRole::sidebarActiveKey());

        $sofia = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.edit', [
                'childKey' => 'sofia-l-navarro',
            ]))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="lml-hr-cc-nr-school"[^>]*value="San Isidro Learning Center"/u',
            $sofia
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="lml-hr-cc-nr-grade"[^>]*value="Kinder"/u',
            $sofia
        );

        $resident = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.child-care.non-residents.edit', [
                'childKey' => 'kristine-b-reyes',
            ]));
        $resident->assertOk();
        $residentHtml = $resident->getContent();
        $this->assertStringContainsString('Record not found', $residentHtml);
        $this->assertStringNotContainsString('id="lml-hr-cc-nr-edit-title"', $residentHtml);
        $this->assertStringNotContainsString('value="Kristine"', $residentHtml);
        $this->assertStringNotContainsString('household-profiling/members/', $residentHtml);
    }

    public function test_resident_child_care_summary_still_uses_household_catalog(): void
    {
        $rows = HealthRecordsChildCare::rows();
        $names = array_map(static fn (array $row): string => $row['full_name'], $rows);

        $this->assertContains('Kristine B. Reyes', $names);
        $this->assertNotContains('Andrei B. Malaya', $names);
    }
}
