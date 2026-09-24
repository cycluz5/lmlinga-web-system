<?php

namespace Tests\Feature;

use App\Support\HealthRecordsMaternal;
use App\Support\HealthRecordsNonResidentMaternal;
use App\Support\UiRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Health Records → Maternal Care resident and non-resident listings.
 */
class HealthRecordsMaternalTest extends TestCase
{
    public function test_resident_maternal_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.maternal.index'));
        $this->assertFalse(Route::has('health-records.maternal'));

        $route = Route::getRoutes()->getByName('health-records.maternal.index');
        $this->assertNotNull($route);
        $this->assertSame('health-records/maternal', $route->uri());
    }

    public function test_non_resident_maternal_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.maternal.non-residents.index'));

        $route = Route::getRoutes()->getByName('health-records.maternal.non-residents.index');
        $this->assertNotNull($route);
        $this->assertSame('health-records/maternal/non-residents', $route->uri());
    }

    public function test_resident_maternal_page_renders(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.maternal.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-mc', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-mc-heading"[^>]*>\s*Maternal Care\s*</u',
            $html
        );
        $this->assertStringNotContainsString('lml-hr-mc__title', $html);
        $this->assertStringNotContainsString('lml-hr-mc__description', $html);
        $this->assertStringNotContainsString('lml-hr-mc__card-icon', $html);
        $this->assertStringContainsString(
            'Record and management of maternal care details for monitoring and tracking maternal health status.',
            $html
        );
        $this->assertStringContainsString('Total Pregnancy Clients', $html);
        $this->assertStringContainsString('High Risk Pregnancies', $html);
        $this->assertStringContainsString('Due for Prenatal Visit', $html);
        $this->assertStringContainsString('Delivered Cases', $html);
        $this->assertStringContainsString('Incomplete Prenatal', $html);
        $this->assertStringContainsString('data-hr-mc-search', $html);
        $this->assertStringContainsString('data-hr-mc-zone', $html);
        $this->assertStringContainsString('data-hr-mc-year', $html);
        $this->assertStringContainsString('Full Name', $html);
        $this->assertStringContainsString('Age Group', $html);
        $this->assertStringContainsString('Gravida / Parity', $html);
        $this->assertStringContainsString('Prenatal Visits', $html);
    }

    public function test_non_resident_maternal_page_renders(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.non-residents.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-mc-mode="non-resident"', $html);
        $this->assertMatchesRegularExpression(
            '/class="lml-topbar__title"[^>]*>\s*Maternal Care \| Non Residents\s*</u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-mc-heading"[^>]*>\s*Maternal Care \| Non Residents\s*</u',
            $html
        );
        $this->assertStringNotContainsString('lml-hr-mc__title', $html);
        $this->assertStringNotContainsString('lml-hr-mc__description', $html);
        $this->assertStringNotContainsString('data-hr-mc-scope-current', $html);
        $this->assertStringNotContainsString('lml-hr-mc__scope-pill', $html);
        $this->assertStringNotContainsString('lml-hr-mc__card-icon', $html);
        $this->assertStringContainsString('data-hr-mc-action-row', $html);
        $this->assertStringContainsString('data-hr-mc-back', $html);
        $this->assertStringContainsString('aria-label="Back to resident Maternal Care listing"', $html);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.maternal.index')).'"',
            $html
        );
        $this->assertStringContainsString('data-hr-mc-add', $html);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.maternal.non-residents.create')).'"',
            $html
        );
        $this->assertStringContainsString('data-hr-mc-export', $html);
        $this->assertStringContainsString('Total Pregnancy Clients', $html);
        $this->assertStringContainsString('data-hr-mc-barangay', $html);
        $this->assertStringNotContainsString('data-hr-mc-zone', $html);
        $this->assertStringNotContainsString('data-hr-mc-non-residents', $html);
    }

    public function test_female_resident_appears_and_male_resident_is_excluded(): void
    {
        $rows = HealthRecordsMaternal::rows();
        $names = array_map(static fn (array $row): string => (string) $row['full_name'], $rows);
        $sexes = array_map(static fn (array $row): string => strtolower((string) $row['sex']), $rows);

        $this->assertContains('Liza M. Evangelista', $names);
        $this->assertContains('Maria Santos', $names);
        $this->assertContains('Rosa Lim', $names);
        $this->assertNotContains('Angelo David Reyes', $names);
        $this->assertNotContains('Marco M. Evangelista', $names);
        $this->assertNotContains('Jacob A. Magistrado', $names);

        foreach ($sexes as $sex) {
            $this->assertTrue(HealthRecordsMaternal::isFemaleSex($sex), 'Listing included non-female sex: '.$sex);
        }

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.index'));
        $html = $response->getContent();

        $this->assertStringContainsString('Liza M. Evangelista', $html);
        $this->assertStringContainsString('Maria Santos', $html);
        $this->assertStringNotContainsString('Angelo David Reyes', $html);
        $this->assertStringNotContainsString('Marco M. Evangelista', $html);
        $this->assertStringNotContainsString('Ramon T. Bautista', $html);
    }

    public function test_female_non_resident_appears_and_male_non_resident_is_excluded(): void
    {
        $candidates = HealthRecordsNonResidentMaternal::candidateRecords();
        $candidateNames = array_map(static fn (array $row): string => (string) $row['full_name'], $candidates);
        $this->assertContains('Ramon T. Bautista', $candidateNames);
        $this->assertContains('Maria Santos', $candidateNames);

        $rows = HealthRecordsNonResidentMaternal::rows();
        $names = array_map(static fn (array $row): string => (string) $row['full_name'], $rows);
        $sexes = array_map(static fn (array $row): string => strtolower((string) $row['sex']), $rows);

        $this->assertContains('Ana P. Villanueva', $names);
        $this->assertContains('Hazel D. Cruz', $names);
        $this->assertNotContains('Ramon T. Bautista', $names);
        $this->assertNotContains('Maria Santos', $names);
        $this->assertNotContains('Liza M. Evangelista', $names);

        foreach ($sexes as $sex) {
            $this->assertTrue(HealthRecordsMaternal::isFemaleSex($sex));
        }

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.non-residents.index'));
        $html = $response->getContent();

        $this->assertStringContainsString('Ana P. Villanueva', $html);
        $this->assertStringContainsString('Hazel D. Cruz', $html);
        $this->assertStringNotContainsString('Ramon T. Bautista', $html);
        $this->assertStringNotContainsString('Liza M. Evangelista', $html);
    }

    public function test_resident_and_non_resident_populations_remain_separated(): void
    {
        $residentKeys = array_map(
            static fn (array $row): string => (string) $row['key'],
            HealthRecordsMaternal::rows()
        );
        $nonResidentKeys = array_map(
            static fn (array $row): string => (string) $row['key'],
            HealthRecordsNonResidentMaternal::rows()
        );

        $this->assertSame([], array_values(array_intersect($residentKeys, $nonResidentKeys)));

        foreach (HealthRecordsMaternal::rows() as $row) {
            $this->assertSame('resident', $row['population']);
        }
        foreach (HealthRecordsNonResidentMaternal::rows() as $row) {
            $this->assertSame('non-resident', $row['population']);
        }

        $residentHtml = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.index'))
            ->getContent();
        $nonResidentHtml = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.non-residents.index'))
            ->getContent();

        $this->assertStringContainsString('Rosa Lim', $residentHtml);
        $this->assertStringNotContainsString('Ana P. Villanueva', $residentHtml);
        $this->assertStringContainsString('Ana P. Villanueva', $nonResidentHtml);
        $this->assertStringNotContainsString('Rosa Lim', $nonResidentHtml);
    }

    public function test_non_residents_navigation_control_exists_and_destination_is_correct(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.index'));

        $response->assertOk();
        $html = $response->getContent();
        $destination = route('health-records.maternal.non-residents.index');

        $this->assertStringContainsString('data-hr-mc-non-residents', $html);
        $this->assertStringContainsString('href="'.e($destination).'"', $html);
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*data-hr-mc-non-residents[^>]*>/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*href="'.preg_quote(e($destination), '/').'"[^>]*>/u',
            $html
        );
        $this->assertStringContainsString('aria-label="Open Maternal Care Non Residents listing"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/lml-hr-mc__action-right[\s\S]*data-hr-mc-non-residents/u',
            $html
        );
        $this->assertStringContainsString('data-hr-mc-action-row', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-hr-mc__title"/u',
            $html
        );
    }

    public function test_resident_listing_rows_have_complete_clinical_values(): void
    {
        $placeholders = ['', '—', '-', 'N/A', 'n/a'];

        foreach (HealthRecordsMaternal::rows() as $row) {
            foreach ([
                'full_name',
                'age_group',
                'lmp',
                'gravida_parity',
                'edd',
                'delivery_type',
                'trimester',
                'prenatal_visits',
            ] as $field) {
                $value = trim((string) ($row[$field] ?? ''));
                $this->assertNotContains(
                    $value,
                    $placeholders,
                    $row['full_name'].' missing '.$field
                );
            }
        }

        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.index'))
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/data-lml-hr-mc-mode="resident"[\s\S]*data-hr-mc-tbody[\s\S]*<td class="lml-hr-mc__cell">\s*(?:—|-|N\/A)\s*<\/td>/u',
            $html
        );
    }

    public function test_non_resident_listing_rows_have_complete_clinical_values(): void
    {
        $placeholders = ['', '—', '-', 'N/A', 'n/a'];

        foreach (HealthRecordsNonResidentMaternal::rows() as $row) {
            foreach ([
                'full_name',
                'age_group',
                'lmp',
                'gravida_parity',
                'edd',
                'delivery_type',
                'trimester',
                'prenatal_visits',
            ] as $field) {
                $value = trim((string) ($row[$field] ?? ''));
                $this->assertNotContains(
                    $value,
                    $placeholders,
                    $row['full_name'].' missing '.$field
                );
            }
        }

        $html = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.non-residents.index'))
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/data-lml-hr-mc-mode="non-resident"[\s\S]*data-hr-mc-tbody[\s\S]*<td class="lml-hr-mc__cell">\s*(?:—|-|N\/A)\s*<\/td>/u',
            $html
        );
    }

    public function test_maternal_sidebar_is_active_on_resident_and_non_resident_pages(): void
    {
        $resident = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.index'));
        $resident->assertOk();
        $this->assertSame('maternal', UiRole::sidebarActiveKey());
        $residentHtml = $resident->getContent();

        $this->assertMatchesRegularExpression(
            '/id="lml-sidebar-collapse-health-records"[^>]*\bis-open\b/u',
            $residentHtml
        );
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active[^>]*aria-current="page"[^>]*>[\s\S]*>Maternal</u',
            $residentHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink--active[^>]*>[\s\S]*>Family Planning</u',
            $residentHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/lml-sidebar__sublink--unavailable[^>]*>[\s\S]*?<span>\s*Maternal\s*<\/span>/u',
            $residentHtml
        );
        $this->assertStringNotContainsString('>Non Residents</span></a>', str_replace("\n", '', $residentHtml));

        $nonResident = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.non-residents.index'));
        $nonResident->assertOk();
        $this->assertSame('maternal', UiRole::sidebarActiveKey());
        $nrHtml = $nonResident->getContent();
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink--active[^>]*aria-current="page"[^>]*>[\s\S]*>Maternal</u',
            $nrHtml
        );
    }

    public function test_remains_independent_of_household_profiling_maternal_care(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.maternal-care.index'));
        $this->assertTrue(Route::has('health-records.maternal.index'));

        $hh = Route::getRoutes()->getByName('household-profiling.members.maternal-care.index');
        $hr = Route::getRoutes()->getByName('health-records.maternal.index');

        $this->assertNotNull($hh);
        $this->assertNotNull($hr);
        $this->assertNotSame($hh->uri(), $hr->uri());
    }

    public function test_catalog_still_contains_male_residents_that_listing_filters_out(): void
    {
        $catalog = HealthRecordsMaternal::catalogResidents();
        $maleFound = false;
        foreach ($catalog as $resident) {
            if (HealthRecordsMaternal::isMaleSex((string) ($resident['member']['sex'] ?? ''))) {
                $maleFound = true;
                break;
            }
        }

        $this->assertTrue($maleFound);
        $this->assertGreaterThan(count(HealthRecordsMaternal::rows()), count($catalog));
    }

    public function test_non_resident_add_button_reaches_create_page(): void
    {
        $this->assertTrue(Route::has('health-records.maternal.non-residents.create'));
        $this->assertTrue(Route::has('health-records.maternal.non-residents.store'));

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.non-residents.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString(
            'href="'.e(route('health-records.maternal.non-residents.create')).'"',
            $html
        );
        $this->assertStringContainsString('data-hr-mc-add', $html);
    }

    public function test_add_non_resident_maternal_page_renders_required_structure(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.maternal.non-residents.create'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Add Non-Resident Maternal Client', $html);
        $this->assertStringContainsString('Add New Non Resident', $html);
        $this->assertStringContainsString('data-hr-mc-add-banner', $html);
        $this->assertSame(1, substr_count($html, 'data-hr-mc-add-card="personal"'));
        $this->assertSame(1, substr_count($html, 'data-hr-mc-add-card="pregnancy"'));
        $this->assertSame(1, substr_count($html, 'data-hr-mc-add-nutrition'));
        $this->assertMatchesRegularExpression(
            '/data-hr-mc-add-card="pregnancy"[\s\S]*data-hr-mc-add-nutrition[\s\S]*id="lml-hr-mc-weight"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-hr-mc-add-card="personal"[\s\S]*data-hr-mc-add-nutrition[\s\S]*data-hr-mc-add-card="pregnancy"/',
            $html
        );
        $this->assertStringNotContainsString('lml-hr-mc-add__panel', $html);
        $this->assertStringContainsString('data-hr-mc-add-back', $html);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.maternal.non-residents.index')).'"',
            $html
        );
        $this->assertStringNotContainsString('javascript:history.back()', $html);

        $this->assertStringContainsString('id="lml-hr-mc-add-personal-heading"', $html);
        $this->assertSame(1, substr_count(strtolower($html), '>personal information<'));
        $this->assertSame(1, substr_count(strtolower($html), '>pregnancy information<'));
        $this->assertSame(1, substr_count(strtolower($html), '>nutritional assessment<'));
        $this->assertStringContainsString('id="lml-hr-mc-first-name"', $html);
        $this->assertStringContainsString('name="first_name"', $html);
        $this->assertStringContainsString('id="lml-hr-mc-middle-name"', $html);
        $this->assertStringContainsString('name="middle_name"', $html);
        $this->assertStringContainsString('id="lml-hr-mc-last-name"', $html);
        $this->assertStringContainsString('name="last_name"', $html);
        $this->assertStringContainsString('id="lml-hr-mc-birthday"', $html);
        $this->assertStringContainsString('name="birthday"', $html);
        $this->assertStringContainsString('id="lml-hr-mc-status"', $html);
        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('Complete Address', $html);
        $this->assertStringContainsString('name="complete_address"', $html);

        $this->assertStringNotContainsString('name="barangay"', $html);
        $this->assertStringNotContainsString('name="municipality"', $html);
        $this->assertStringNotContainsString('name="zone"', $html);
        $this->assertStringNotContainsString('name="sex"', $html);
        $this->assertStringNotContainsString('name="gender"', $html);
        $this->assertDoesNotMatchRegularExpression('/\bfor="[^"]*sex[^"]*"/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<label[^>]*>\s*Sex\s*</i', $html);

        $this->assertStringContainsString('Pregnancy Information', $html);
        $this->assertStringContainsString('Last Menstrual Period', $html);
        $this->assertStringContainsString('name="lmp"', $html);
        $this->assertStringContainsString('name="gravida"', $html);
        $this->assertStringContainsString('name="parity"', $html);
        $this->assertStringContainsString('name="edd"', $html);
        $this->assertStringContainsString('EDD (Expected Date of Delivery)', $html);

        $this->assertStringContainsString('Nutritional Assessment', $html);
        $this->assertStringContainsString('name="weight"', $html);
        $this->assertStringContainsString('name="height"', $html);
        $this->assertStringContainsString('id="lml-hr-mc-bmi"', $html);
        $this->assertMatchesRegularExpression('/id="lml-hr-mc-bmi"[^>]*readonly/i', $html);
        $this->assertStringNotContainsString('name="bmi"', $html);
        $this->assertStringContainsString('Auto calculated', $html);
        $this->assertStringContainsString('name="blood_pressure"', $html);
        $this->assertStringNotContainsString('Auto computed', $html);
        $this->assertStringNotContainsString('lml-hr-mc-add__hint', $html);
        $this->assertStringNotContainsString('lml-hr-mc-weight-hint', $html);
        $this->assertStringNotContainsString('lml-hr-mc-height-hint', $html);
        $this->assertDoesNotMatchRegularExpression('/<p[^>]*>\s*kg\s*</u', $html);
        $this->assertDoesNotMatchRegularExpression('/<p[^>]*>\s*cm\s*</u', $html);

        $this->assertStringContainsString('data-hr-mc-add-cancel', $html);
        $this->assertStringContainsString('data-hr-mc-add-save', $html);
        $this->assertMatchesRegularExpression('/>\s*Cancel\s*</', $html);
        $this->assertMatchesRegularExpression('/>\s*Save\s*</', $html);
    }

    public function test_server_derives_bmi_and_ignores_submitted_bmi(): void
    {
        $this->assertSame(23.4, HealthRecordsNonResidentMaternal::calculateBmi(60, 160));
        $this->assertNull(HealthRecordsNonResidentMaternal::calculateBmi(60, 0));
        $this->assertNull(HealthRecordsNonResidentMaternal::calculateBmi('', 160));

        $payload = $this->validNonResidentMaternalPayload();
        $payload['bmi'] = '99';
        $payload['sex'] = 'Male';
        $payload['gender'] = 'Male';

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->from(route('health-records.maternal.non-residents.create'))
            ->post(route('health-records.maternal.non-residents.store'), $payload);

        $response->assertRedirect(route('health-records.maternal.non-residents.index'));

        $rows = HealthRecordsNonResidentMaternal::rows();
        $names = array_map(static fn (array $row): string => (string) $row['full_name'], $rows);
        $this->assertContains('Teresa W. Addnrclient', $names);

        $saved = null;
        foreach ($rows as $row) {
            if ($row['full_name'] === 'Teresa W. Addnrclient') {
                $saved = $row;
                break;
            }
        }

        $this->assertNotNull($saved);
        $this->assertTrue(HealthRecordsMaternal::isFemaleSex((string) $saved['sex']));
        $this->assertFalse(HealthRecordsMaternal::isMaleSex((string) $saved['sex']));
        $this->assertSame('Female', $saved['sex']);
        $this->assertEquals(23.4, $saved['bmi']);
        $this->assertNotEquals(99, $saved['bmi']);
        $this->assertSame('non-resident', $saved['population']);
        $this->assertSame(60.0, (float) $saved['weight']);
        $this->assertSame(160.0, (float) $saved['height']);

        $residentNames = array_map(
            static fn (array $row): string => (string) $row['full_name'],
            HealthRecordsMaternal::rows()
        );
        $this->assertNotContains('Teresa W. Addnrclient', $residentNames);

        $listing = $this->get(route('health-records.maternal.non-residents.index'));
        $listing->assertOk();
        $listing->assertSee('Teresa W. Addnrclient', false);

        $residentListing = $this->get(route('health-records.maternal.index'));
        $residentListing->assertOk();
        $residentListing->assertDontSee('Teresa W. Addnrclient', false);
    }

    public function test_cancel_does_not_create_a_non_resident_maternal_client(): void
    {
        $before = count(HealthRecordsNonResidentMaternal::rows());

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.non-residents.create'))
            ->assertOk();

        $this->assertSame($before, count(HealthRecordsNonResidentMaternal::rows()));

        $listing = $this->get(route('health-records.maternal.non-residents.index'));
        $listing->assertOk();
        $listing->assertDontSee('Teresa W. Addnrclient', false);
    }

    public function test_add_non_resident_maternal_validation_rejects_incomplete_payload(): void
    {
        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->from(route('health-records.maternal.non-residents.create'))
            ->post(route('health-records.maternal.non-residents.store'), [
                'first_name' => '',
                'sex' => 'Male',
                'bmi' => '99',
            ]);

        $response->assertRedirect(route('health-records.maternal.non-residents.create'));
        $response->assertSessionHasErrors([
            'first_name',
            'last_name',
            'birthday',
            'status',
            'complete_address',
            'lmp',
            'gravida',
            'parity',
            'weight',
            'height',
            'blood_pressure',
        ]);

        $this->assertSame(0, count(HealthRecordsNonResidentMaternal::sessionCreated()));
    }

    public function test_non_resident_listing_has_view_action_for_each_eligible_client(): void
    {
        $this->assertTrue(Route::has('health-records.maternal.non-residents.show'));

        $residentListing = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.index'));
        $residentListing->assertOk();
        $this->assertStringNotContainsString('data-hr-mc-view', $residentListing->getContent());
        $this->assertStringNotContainsString('>Action<', $residentListing->getContent());

        $response = $this->get(route('health-records.maternal.non-residents.index'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('>Action<', $html);

        $rows = HealthRecordsNonResidentMaternal::rows();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $url = route('health-records.maternal.non-residents.show', ['clientKey' => $row['key']]);
            $this->assertStringContainsString('href="'.e($url).'"', $html);
            $this->assertStringContainsString('View maternal record for '.$row['full_name'], $html);
        }

        $this->assertStringNotContainsString(
            route('health-records.maternal.non-residents.show', ['clientKey' => 'ramon-t-bautista']),
            $html
        );
        $this->assertStringNotContainsString(
            route('health-records.maternal.non-residents.show', ['clientKey' => 'maria-santos-collision']),
            $html
        );
    }

    public function test_non_resident_individual_page_renders_selected_client_only(): void
    {
        $ana = $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->get(route('health-records.maternal.non-residents.show', ['clientKey' => 'ana-p-villanueva']));
        $ana->assertOk();
        $anaHtml = $ana->getContent();
        $this->assertStringContainsString('Ana P. Villanueva', $anaHtml);
        $this->assertMatchesRegularExpression(
            '/class="lml-topbar__title"[^>]*>\s*Maternal Care \| Non Residents\s*</u',
            $anaHtml
        );
        $this->assertStringContainsString(
            'Record and management of maternal care details for monitoring and tracking maternal health status.',
            $anaHtml
        );
        $this->assertStringNotContainsString('Hazel D. Cruz', $anaHtml);
        $this->assertStringContainsString('Female', $anaHtml);
        $this->assertStringContainsString('>28</dd>', $anaHtml);
        $this->assertStringContainsString('March 12, 1998', $anaHtml);
        $this->assertStringContainsString('Married', $anaHtml);
        $this->assertStringContainsString('14 Mabini Street, Barangay San Roque, Iriga City, Camarines Sur', $anaHtml);
        $this->assertMatchesRegularExpression(
            '/<dt>Date Birth:<\/dt>\s*<dd>\s*March 12, 1998\s*<\/dd>/u',
            $anaHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dt>Date Birth:<\/dt>\s*<dd>\s*(?:—|-|N\/A)\s*<\/dd>/u',
            $anaHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dt>Status:<\/dt>\s*<dd>\s*(?:—|-|N\/A)\s*<\/dd>/u',
            $anaHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dt>Address:<\/dt>\s*<dd>\s*(?:—|-|N\/A)\s*<\/dd>/u',
            $anaHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dt>Age:<\/dt>\s*<dd>\s*(?:—|-|N\/A)\s*<\/dd>/u',
            $anaHtml
        );
        $this->assertStringContainsString('Date Birth:', $anaHtml);
        $this->assertStringContainsString('Pregnancy History', $anaHtml);
        $this->assertStringContainsString('Maternal Care', $anaHtml);
        $this->assertStringContainsString('data-hr-mc-nr-add-record', $anaHtml);
        $this->assertStringContainsString('data-hr-mc-show-back', $anaHtml);
        $this->assertStringContainsString(
            'href="'.e(route('health-records.maternal.non-residents.index')).'"',
            $anaHtml
        );
        $this->assertStringNotContainsString('javascript:history.back()', $anaHtml);
        $this->assertStringNotContainsString('household-profiling.members.maternal-care', $anaHtml);
        $this->assertSame('non-resident', HealthRecordsNonResidentMaternal::findEligible('ana-p-villanueva')['population']);

        $hazel = $this->get(route('health-records.maternal.non-residents.show', ['clientKey' => 'hazel-d-cruz']));
        $hazel->assertOk();
        $this->assertStringContainsString('Hazel D. Cruz', $hazel->getContent());
        $this->assertStringNotContainsString('Ana P. Villanueva', $hazel->getContent());
    }

    public function test_non_resident_detail_route_rejects_ineligible_and_resident_keys(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.maternal.non-residents.show', ['clientKey' => 'ramon-t-bautista']))
            ->assertNotFound();

        $this->get(route('health-records.maternal.non-residents.show', ['clientKey' => 'maria-santos-collision']))
            ->assertNotFound();

        $this->get(route('health-records.maternal.non-residents.show', ['clientKey' => 'MB-002']))
            ->assertNotFound();

        $this->assertNull(HealthRecordsNonResidentMaternal::findEligible('ramon-t-bautista'));
        $this->assertNull(HealthRecordsNonResidentMaternal::findEligible('maria-santos-collision'));
        $this->assertNull(HealthRecordsNonResidentMaternal::findEligible('MB-002'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validNonResidentMaternalPayload(): array
    {
        return [
            'first_name' => 'Teresa',
            'middle_name' => 'W.',
            'last_name' => 'Addnrclient',
            'birthday' => '1995-04-12',
            'status' => 'Married',
            'complete_address' => '12 Mabini St, Barangay San Isidro, Lucena City, Quezon',
            'lmp' => '2026-01-15',
            'gravida' => '2',
            'parity' => '1',
            'edd' => '',
            'weight' => '60',
            'height' => '160',
            'blood_pressure' => '110/70',
        ];
    }
}
