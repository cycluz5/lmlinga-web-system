<?php

namespace Tests\Feature;

use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsDeworming;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Household Profiling → Member → Child Care → Deworming resident workflow.
 */
class HouseholdProfilingDewormingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private const HOUSEHOLD_NO = 'HH-151';

    private const ELIGIBLE_MEMBER_ID = 'MB-009';

    private const INELIGIBLE_MEMBER_ID = 'MB-001';

    /** @return array{householdNo: string, memberId: string} */
    private function eligibleMemberParams(): array
    {
        return [
            'householdNo' => self::HOUSEHOLD_NO,
            'memberId' => self::ELIGIBLE_MEMBER_ID,
        ];
    }

    /** @return array{householdNo: string, memberId: string} */
    private function ineligibleMemberParams(): array
    {
        return [
            'householdNo' => self::HOUSEHOLD_NO,
            'memberId' => self::INELIGIBLE_MEMBER_ID,
        ];
    }

    public function test_deworming_member_routes_resolve(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.deworming'));
        $this->assertTrue(Route::has('household-profiling.members.deworming.create'));

        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-009/deworming'),
            route('household-profiling.members.deworming', $this->eligibleMemberParams())
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-009/deworming/create'),
            route('household-profiling.members.deworming.create', $this->eligibleMemberParams())
        );
    }

    public function test_eligibility_rule_matches_child_care_population(): void
    {
        $this->assertSame(59, HealthRecordsChildCare::MAX_AGE_MONTHS);

        $eligible = HealthRecordsDeworming::findChildForMember(
            self::HOUSEHOLD_NO,
            self::ELIGIBLE_MEMBER_ID
        );
        $ineligible = HealthRecordsDeworming::findChildForMember(
            self::HOUSEHOLD_NO,
            self::INELIGIBLE_MEMBER_ID
        );

        $this->assertNotNull($eligible);
        $this->assertNotNull($ineligible);
        $this->assertTrue(HealthRecordsDeworming::memberCanManageRecords(
            lml_demo_find_member(
                \App\Support\DemoCatalog::findHousehold(self::HOUSEHOLD_NO),
                self::ELIGIBLE_MEMBER_ID
            )
        ));
        $this->assertFalse(HealthRecordsDeworming::memberCanManageRecords(
            lml_demo_find_member(
                \App\Support\DemoCatalog::findHousehold(self::HOUSEHOLD_NO),
                self::INELIGIBLE_MEMBER_ID
            )
        ));
    }

    public function test_exactly_59_months_is_eligible_and_60_months_is_ineligible(): void
    {
        $now = Carbon::parse('2026-08-17')->startOfDay();
        Carbon::setTestNow($now);

        $at59Months = [
            'birthday' => $now->copy()->subMonthsNoOverflow(59)->toDateString(),
        ];
        $at60Months = [
            'birthday' => $now->copy()->subMonthsNoOverflow(60)->toDateString(),
        ];

        $this->assertSame(59, HealthRecordsChildCare::ageInMonths($at59Months));
        $this->assertSame(60, HealthRecordsChildCare::ageInMonths($at60Months));

        $this->assertTrue(HealthRecordsChildCare::isChildCarePopulation($at59Months));
        $this->assertFalse(HealthRecordsChildCare::isChildCarePopulation($at60Months));

        $this->assertTrue(HealthRecordsDeworming::memberCanManageRecords($at59Months));
        $this->assertFalse(HealthRecordsDeworming::memberCanManageRecords($at60Months));
    }

    public function test_individual_deworming_page_scopes_to_selected_eligible_member(): void
    {
        $params = $this->eligibleMemberParams();
        $showUrl = route('household-profiling.members.deworming', $params);
        $createUrl = route('household-profiling.members.deworming.create', $params);
        $memberUrl = route('household-profiling.members.show', $params);

        $html = $this->get($showUrl)->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-hr-dw-record', $html);
        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-009"', $html);
        $this->assertStringContainsString('Kristine B. Reyes', $html);
        $this->assertStringContainsString('May 4, 2026', $html);
        $this->assertStringNotContainsString('College Graduate', $html);
        $this->assertStringContainsString('data-hr-dw-add-record', $html);
        $this->assertStringContainsString('href="'.e($createUrl).'"', $html);
        $this->assertStringContainsString('href="'.e($memberUrl).'"', $html);
        $this->assertStringContainsString('aria-label="Back to member health records"', $html);
        $this->assertStringContainsString('2026', $html);
        $this->assertStringContainsString('NHTS', $html);
        $this->assertSame('household-profiling', UiRole::sidebarActiveKey());
    }

    public function test_ineligible_member_resolves_own_identity_without_similar_name_fallback(): void
    {
        $params = $this->ineligibleMemberParams();
        $showUrl = route('household-profiling.members.deworming', $params);

        $html = $this->get($showUrl)->assertOk()->getContent();

        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-001"', $html);
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('May 4, 1991', $html);
        $this->assertStringContainsString('College Graduate', $html);
        $this->assertStringNotContainsString('Kristine B. Reyes', $html);
        $this->assertStringNotContainsString('data-hr-dw-add-record', $html);
        $this->assertStringContainsString('No deworming records recorded for this child.', $html);
        $this->assertStringNotContainsString('NHTS', $html);
        $this->assertStringNotContainsString(
            'href="'.e(route('household-profiling.members.deworming.create', $params)).'"',
            $html
        );
    }

    public function test_similar_names_do_not_cross_contaminate_deworming_history(): void
    {
        $eligibleRecords = HealthRecordsDeworming::recordsForMember(
            self::HOUSEHOLD_NO,
            self::ELIGIBLE_MEMBER_ID
        );
        $ineligibleRecords = HealthRecordsDeworming::recordsForMember(
            self::HOUSEHOLD_NO,
            self::INELIGIBLE_MEMBER_ID
        );

        $this->assertNotSame([], $eligibleRecords);
        $this->assertSame([], $ineligibleRecords);

        $ineligibleHtml = $this->get(route('household-profiling.members.deworming', $this->ineligibleMemberParams()))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('January 20, 2026', $ineligibleHtml);
        $this->assertStringNotContainsString('July 1, 2026', $ineligibleHtml);
    }

    public function test_add_record_form_preserves_member_context(): void
    {
        $params = $this->eligibleMemberParams();
        $showUrl = route('household-profiling.members.deworming', $params);
        $createUrl = route('household-profiling.members.deworming.create', $params);

        $html = $this->get($createUrl)->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-hr-dw-mode="create"', $html);
        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-009"', $html);
        $this->assertStringContainsString('Kristine B. Reyes', $html);
        $this->assertStringContainsString('Add Deworming Record', $html);
        $this->assertStringContainsString('href="'.e($showUrl).'"', $html);
        $this->assertStringContainsString('data-hr-dw-return="'.e($showUrl).'"', $html);
        $this->assertStringContainsString('data-hr-dw-cancel', $html);
        $this->assertStringContainsString('data-hr-dw-save', $html);
        $this->assertStringNotContainsString(
            route('health-records.child-care.deworming.create', ['childKey' => 'kristine-b-reyes']),
            $html
        );
    }

    public function test_ineligible_create_route_redirects_to_member_deworming_show(): void
    {
        $params = $this->ineligibleMemberParams();
        $showUrl = route('household-profiling.members.deworming', $params);

        $this->get(route('household-profiling.members.deworming.create', $params))
            ->assertRedirect($showUrl);
    }

    public function test_eligible_member_view_shows_deworming_link_once(): void
    {
        $params = $this->eligibleMemberParams();
        $dewormingUrl = route('household-profiling.members.deworming', $params);

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Deworming'));
        $this->assertStringContainsString('href="'.e($dewormingUrl).'"', $html);
        $this->assertStringContainsString('bi-capsule', $html);
    }

    public function test_ineligible_member_view_hides_deworming_link(): void
    {
        $params = $this->ineligibleMemberParams();

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('bi-capsule', $html);
        $this->assertStringNotContainsString(
            'href="'.e(route('household-profiling.members.deworming', $params)).'"',
            $html
        );
    }

    public function test_other_child_care_links_remain_intact_on_member_page(): void
    {
        $params = $this->eligibleMemberParams();

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.child-immunization', $params)).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.school-based-immunization', $params)).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.child-nutrition', $params)).'"',
            $html
        );
    }
}
