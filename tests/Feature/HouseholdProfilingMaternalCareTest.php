<?php

namespace Tests\Feature;

use App\Support\StaffRole;
use App\Support\DemoMaternalCare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

/**
 * Feature coverage for Household Profiling → member → Maternal Care Phase 1.
 */
class HouseholdProfilingMaternalCareTest extends TestCase
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
            'memberId' => 'MB-002',
        ];
    }

    public function test_named_routes_resolve_under_household_profiling_member(): void
    {
        $params = $this->memberParams();

        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care'),
            route('household-profiling.members.maternal-care.index', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care/register'),
            route('household-profiling.members.maternal-care.register', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care/history'),
            route('household-profiling.members.maternal-care.history', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care/prenatal'),
            route('household-profiling.members.maternal-care.prenatal', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care/immunizations'),
            route('household-profiling.members.maternal-care.immunizations', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care/supplementations'),
            route('household-profiling.members.maternal-care.supplementations', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care/laboratory'),
            route('household-profiling.members.maternal-care.laboratory', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care/delivery'),
            route('household-profiling.members.maternal-care.delivery', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care/postnatal'),
            route('household-profiling.members.maternal-care.postnatal', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-002/maternal-care/trans-out'),
            route('household-profiling.members.maternal-care.trans-out', $params)
        );
    }

    public function test_routes_are_protected_by_ui_role_middleware(): void
    {
        foreach ([
            'household-profiling.members.maternal-care.index',
            'household-profiling.members.maternal-care.register',
            'household-profiling.members.maternal-care.store',
            'household-profiling.members.maternal-care.history',
            'household-profiling.members.maternal-care.history.show',
            'household-profiling.members.maternal-care.trans-out',
            'household-profiling.members.maternal-care.prenatal',
            'household-profiling.members.maternal-care.immunizations',
            'household-profiling.members.maternal-care.supplementations',
            'household-profiling.members.maternal-care.laboratory',
            'household-profiling.members.maternal-care.delivery',
            'household-profiling.members.maternal-care.postnatal',
            'household-profiling.members.maternal-care.update',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('ui.role', $route->gatherMiddleware());
        }
    }

    public function test_member_view_maternal_uses_named_route_link(): void
    {
        $params = $this->memberParams();
        $response = $this->get(route('household-profiling.members.show', $params));

        $response->assertOk();
        $response->assertSee('data-hh-member-maternal-care', false);
        $response->assertDontSee('data-hh-member-view-record="Maternal"', false);
        $response->assertSee(
            'href="'.e(route('household-profiling.members.maternal-care.index', $params)).'"',
            false
        );
    }

    public function test_landing_shows_no_record_state(): void
    {
        $params = $this->memberParams();
        $response = $this->get(route('household-profiling.members.maternal-care.index', $params));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-mc-mode="landing"', $html);
        $this->assertStringContainsString('data-mc-no-record', $html);
        $this->assertStringContainsString('NO RECORD', $html);
        $this->assertStringContainsString('Register Maternal Record', $html);
        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-002"', $html);
        $this->assertStringContainsString('Kristine Reyes', $html);
    }

    public function test_register_surface_and_session_overview_workflow(): void
    {
        $params = $this->memberParams();

        $register = $this->get(route('household-profiling.members.maternal-care.register', $params));
        $register->assertOk();
        $registerHtml = $register->getContent();
        $this->assertStringContainsString('data-lml-mc-mode="register"', $registerHtml);
        $this->assertStringContainsString('MATERNAL INFORMATION', $registerHtml);
        $this->assertStringContainsString('Last Menstrual Period (LMP)', $registerHtml);
        $this->assertStringContainsString('Gravida', $registerHtml);
        $this->assertStringContainsString('Parity', $registerHtml);
        $this->assertStringContainsString('EDD (Estimated Date of Delivery)', $registerHtml);
        $this->assertStringContainsString('Blood Pressure', $registerHtml);

        $store = $this->post(route('household-profiling.members.maternal-care.store', $params), [
            'lmp' => '2026-01-15',
            'gravida' => '2',
            'parity' => '1',
            'weight' => '58',
            'height' => '160',
            'blood_pressure' => '110/70',
        ]);
        $store->assertRedirect(route('household-profiling.members.maternal-care.index', $params));

        $overview = $this->get(route('household-profiling.members.maternal-care.index', $params));
        $overview->assertOk();
        $html = $overview->getContent();
        $this->assertStringContainsString('data-lml-mc-mode="overview"', $html);
        $this->assertStringContainsString('Active Pregnancy', $html);
        $this->assertStringContainsString('data-mc-service="prenatal"', $html);
        $this->assertStringContainsString('data-mc-service="immunizations"', $html);
        $this->assertStringContainsString('data-mc-service="supplementations"', $html);
        $this->assertStringContainsString('data-mc-service="laboratory"', $html);
        $this->assertStringContainsString('data-mc-service="delivery"', $html);
        $this->assertStringContainsString('data-mc-service="postnatal"', $html);
        $this->assertStringContainsString('Gravida–Parity', $html);
        $this->assertStringContainsString('data-mc-trans-out-link', $html);
        $this->assertStringContainsString('data-mc-history-link', $html);
    }

    public function test_pregnancy_history_empty_state(): void
    {
        $params = $this->memberParams();
        $response = $this->get(route('household-profiling.members.maternal-care.history', $params));

        $response->assertOk();
        $this->assertStringContainsString('data-mc-history-empty', $response->getContent());
        $this->assertStringContainsString('No Record Yet', $response->getContent());
    }

    public function test_prenatal_structure_has_three_trimesters_and_eight_visits(): void
    {
        $params = $this->memberParams();
        $this->registerPregnancy($params);

        $response = $this->get(route('household-profiling.members.maternal-care.prenatal', $params));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-mc-trimester="first"', $html);
        $this->assertStringContainsString('data-mc-trimester="second"', $html);
        $this->assertStringContainsString('data-mc-trimester="third"', $html);
        $this->assertStringContainsString('0–12 weeks', $html);
        $this->assertStringContainsString('13–27 weeks', $html);
        $this->assertStringContainsString('28–40 weeks', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('aria-controls="lml-mc-prenatal-first"', $html);
        $this->assertSame(8, substr_count($html, 'data-mc-visit='));
        $this->assertStringContainsString('1 of 8 Visits', $html);
        $this->assertStringContainsString('Blood Pressure', $html);
    }

    public function test_immunizations_td1_through_td5(): void
    {
        $params = $this->memberParams();
        $this->registerPregnancy($params);

        $response = $this->get(route('household-profiling.members.maternal-care.immunizations', $params));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Tetanus Diphtheria (TD) Vaccine', $html);
        foreach (['td1', 'td2', 'td3', 'td4', 'td5'] as $dose) {
            $this->assertStringContainsString('data-mc-dose="'.$dose.'"', $html);
        }
        $this->assertStringContainsString('TD1 / TT1', $html);
        $this->assertStringContainsString('TD5 / TT5', $html);
        $this->assertStringContainsString('0 of 5 Vaccines', $html);
    }

    public function test_supplementations_sections_and_counts(): void
    {
        $params = $this->memberParams();
        $this->registerPregnancy($params);

        $response = $this->get(route('household-profiling.members.maternal-care.supplementations', $params));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-mc-supp="deworming"', $html);
        $this->assertStringContainsString('data-mc-supp="ifa"', $html);
        $this->assertStringContainsString('data-mc-supp="mms"', $html);
        $this->assertStringContainsString('data-mc-supp="calcium"', $html);
        $this->assertStringContainsString('data-mc-supp="rusf"', $html);
        $this->assertStringContainsString('Deworming Tablet', $html);
        $this->assertStringContainsString('Iron with Folic Acid Supplementation', $html);
        $this->assertStringContainsString('Multiple Micronutrient Supplementation', $html);
        $this->assertStringContainsString('Calcium Carbonate Supplementation', $html);
        $this->assertStringContainsString('Ready-to-Use Supplementary Food (RUSF)', $html);
        $this->assertStringContainsString('For High Risk Only', $html);
        $this->assertStringContainsString('0 of 1 dose', $html);
        $this->assertStringContainsString('0 of 6 Visits', $html);
        $this->assertStringContainsString('0 of 3 Visits', $html);
        $this->assertStringContainsString('0 given', $html);
        $this->assertSame(6, substr_count($html, 'data-mc-supp-visit="ifa-'));
        $this->assertSame(6, substr_count($html, 'data-mc-supp-visit="mms-'));
        $this->assertSame(3, substr_count($html, 'data-mc-supp-visit="calcium-'));
        $this->assertStringContainsString('data-mc-supp-visit="rusf-new"', $html);
    }

    public function test_laboratory_result_options_are_exact(): void
    {
        $params = $this->memberParams();
        $this->registerPregnancy($params);

        $response = $this->get(route('household-profiling.members.maternal-care.laboratory', $params));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-mc-lab="hepatitis_b"', $html);
        $this->assertStringContainsString('data-mc-lab="cbc"', $html);
        $this->assertStringContainsString('data-mc-lab="gdm"', $html);
        $this->assertStringContainsString('value="Reactive"', $html);
        $this->assertStringContainsString('value="Negative"', $html);
        $this->assertStringContainsString('value="With Anemia"', $html);
        $this->assertStringContainsString('value="Without Anemia"', $html);
        $this->assertStringContainsString('value="Positive"', $html);
        $this->assertMatchesRegularExpression('/>\s*Reactive\s*</u', $html);
        $this->assertMatchesRegularExpression('/>\s*With Anemia\s*</u', $html);
        $this->assertMatchesRegularExpression('/>\s*Positive\s*</u', $html);
        $this->assertStringContainsString('Hepatitis B', $html);
        $this->assertStringContainsString('CBC / Hgb &amp; Hct Count', $html);
        $this->assertStringContainsString('Gestational Diabetes Mellitus', $html);
        $this->assertStringContainsString('data-mc-lab="urinalysis"', $html);
        $this->assertStringContainsString('data-mc-lab="ultrasound"', $html);
        $this->assertStringContainsString('data-mc-lab="syphilis"', $html);
        $this->assertStringContainsString('data-mc-lab="hiv"', $html);
        $this->assertStringContainsString('data-mc-lab="cvc"', $html);
        $this->assertStringContainsString('data-mc-lab="gestational"', $html);
        $this->assertStringContainsString('value="REACTIVE"', $html);
        $this->assertStringContainsString('value="NON REACTIVE"', $html);
        $this->assertStringContainsString('name="urinalysis[date]"', $html);
        $this->assertStringNotContainsString('name="urinalysis[result]"', $html);
        $this->assertStringContainsString('name="cvc[value]"', $html);
        $this->assertStringNotContainsString('name="cvc[date]"', $html);
        $this->assertStringContainsString('name="gestational[value]"', $html);
        $this->assertStringNotContainsString('name="gestational[date]"', $html);
    }

    public function test_delivery_outcome_types_attendant_and_conditionals(): void
    {
        $params = $this->memberParams();
        $this->registerPregnancy($params);

        $response = $this->get(route('household-profiling.members.maternal-care.delivery', $params));
        $response->assertOk();
        $html = $response->getContent();

        foreach (['FT', 'PT', 'FD', 'AB'] as $code) {
            $this->assertStringContainsString('data-mc-outcome="'.$code.'"', $html);
        }
        $this->assertStringContainsString('CS - Cesarean', $html);
        $this->assertStringContainsString('VD - Vaginal Delivery', $html);
        $this->assertStringContainsString('CVCD - Combined Vaginal Cesarean Delivery', $html);
        $this->assertStringContainsString('MD - Doctor', $html);
        $this->assertStringContainsString('RN - Nurse', $html);
        $this->assertStringContainsString('MW - Midwife', $html);
        $this->assertStringContainsString('value="Others"', $html);
        $this->assertMatchesRegularExpression('/>\s*Others\s*</u', $html);
        $this->assertStringNotContainsString('data-mc-conditional="fd"', $html);
        $this->assertStringNotContainsString('Date of Fetal Death', $html);
        $this->assertStringNotContainsString('name="fetal_death_date"', $html);
        $this->assertStringNotContainsString('data-mc-conditional="ab"', $html);
        $this->assertStringNotContainsString('Date of Abortion', $html);
        $this->assertStringNotContainsString('id="lml-mc-abortion-date"', $html);
        $this->assertStringContainsString('Newborn Sex', $html);
        $this->assertStringContainsString('name="newborn_sex"', $html);
        $this->assertStringContainsString('name="plurality"', $html);
        $this->assertStringContainsString('data-mc-conditional="plurality-multiple"', $html);
        $this->assertStringContainsString('name="plurality_number"', $html);
        $this->assertStringContainsString('Public Health Facility', $html);
        $this->assertStringContainsString('Private Health Facility', $html);
        $this->assertStringContainsString('Non-Health Facility', $html);
        $this->assertStringContainsString('BEmONC / CEmONC Capable?', $html);
        $this->assertStringContainsString('data-mc-birth-attendant-other', $html);
    }

    public function test_postnatal_four_contacts_and_three_supp_visits(): void
    {
        $params = $this->memberParams();
        $this->registerPregnancy($params);

        $response = $this->get(route('household-profiling.members.maternal-care.postnatal', $params));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('0 of 4 Contact', $html);
        $this->assertStringContainsString('0 of 3 Visits', $html);
        $this->assertStringContainsString('Within 24 hrs after delivery', $html);
        $this->assertStringContainsString('On day 3', $html);
        $this->assertStringContainsString('Between 7–14 days', $html);
        $this->assertStringContainsString('6 weeks after birth', $html);
        $this->assertSame(4, substr_count($html, 'data-mc-contact='));
        $this->assertSame(3, substr_count($html, 'data-mc-pp-supp='));
        $this->assertStringContainsString('name="vitamin_a[date]"', $html);
        $this->assertStringContainsString('Vitamin A', $html);
        $this->assertStringContainsString('name="supplementation[v1][tablets]"', $html);
        $this->assertStringContainsString('name="contacts[c1]"', $html);
    }

    public function test_trans_out_fields_and_section_update_persists_in_session(): void
    {
        $params = $this->memberParams();
        $this->registerPregnancy($params);

        $page = $this->get(route('household-profiling.members.maternal-care.trans-out', $params));
        $page->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('To Facility', $html);
        $this->assertStringContainsString('Occurred at Stage', $html);
        $this->assertStringContainsString('Date Transferred Out', $html);

        $update = $this->put(
            route('household-profiling.members.maternal-care.update', $params + ['section' => 'trans-out']),
            [
                'to_facility' => 'RHU La Medalla',
                'occurred_at_stage' => 'Prenatal',
                'reason' => 'Non-resident/Moved',
                'date_transferred_out' => '2026-06-01',
            ]
        );
        $update->assertRedirect(route('household-profiling.members.maternal-care.history', $params));

        $history = $this->get(route('household-profiling.members.maternal-care.history', $params));
        $history->assertOk();
        $this->assertStringContainsString('data-mc-history-list', $history->getContent());
        $this->assertStringContainsString('Pregnancy 1', $history->getContent());
        $this->assertNull(DemoMaternalCare::activePregnancy($params['householdNo'], $params['memberId']));
    }

    public function test_immunization_save_updates_count_from_recorded_dates(): void
    {
        $params = $this->memberParams();
        $this->registerPregnancy($params);

        $this->put(
            route('household-profiling.members.maternal-care.update', $params + ['section' => 'immunizations']),
            [
                'td1' => '2026-02-01',
                'td2' => '',
                'td3' => '',
                'td4' => '',
                'td5' => '',
            ]
        )->assertRedirect(route('household-profiling.members.maternal-care.immunizations', $params));

        $response = $this->get(route('household-profiling.members.maternal-care.immunizations', $params));
        $response->assertOk();
        $this->assertStringContainsString('1 of 5 Vaccines', $response->getContent());
    }

    public function test_accessibility_markers_on_prenatal_and_delivery(): void
    {
        $params = $this->memberParams();
        $this->registerPregnancy($params);

        $prenatal = $this->get(route('household-profiling.members.maternal-care.prenatal', $params));
        $prenatalHtml = $prenatal->getContent();
        $this->assertStringContainsString('role="region"', $prenatalHtml);
        $this->assertMatchesRegularExpression('/aria-controls="lml-mc-prenatal-first"/', $prenatalHtml);

        $delivery = $this->get(route('household-profiling.members.maternal-care.delivery', $params));
        $deliveryHtml = $delivery->getContent();
        $this->assertStringContainsString('role="radiogroup"', $deliveryHtml);
        $this->assertStringContainsString('<legend class="lml-mc__legend">Outcome</legend>', $deliveryHtml);
        $this->assertStringContainsString('<legend class="lml-mc__legend">Delivery Details</legend>', $deliveryHtml);
        $this->assertStringContainsString('<legend class="lml-mc__legend">Place of Delivery</legend>', $deliveryHtml);
    }

    /**
     * @param  array{householdNo: string, memberId: string}  $params
     */
    private function registerPregnancy(array $params): void
    {
        $this->post(route('household-profiling.members.maternal-care.store', $params), [
            'lmp' => '2026-01-15',
            'gravida' => '1',
            'parity' => '0',
            'weight' => '55',
            'height' => '158',
        ])->assertRedirect(route('household-profiling.members.maternal-care.index', $params));
    }
}
