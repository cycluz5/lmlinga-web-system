<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

/**
 * Batch 4 — name suggestions appear only on staff "create a person/household" forms and never on public,
 * login, registration or resident chatbot pages. Existing forms keep their fields and validation.
 */
class NameSuggestFormsTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function staffCreateForms(): array
    {
        return [
            'spot mapping household head' => ['spot-mapping.index'],
            'child care non-resident' => ['health-records.child-care.non-residents.create'],
            'maternal non-resident' => ['health-records.maternal.non-residents.create'],
            'family planning non-resident client' => ['health-records.family-planning.non-residents.create'],
        ];
    }

    #[DataProvider('staffCreateForms')]
    public function test_staff_create_form_enables_suggestions_on_its_three_name_fields(string $route): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $html = $this->get(route($route))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-lml-name-suggest="person"'));
        $this->assertStringContainsString('data-lml-name-suggest-url="'.route('staff.name-suggestions').'"', $html);
        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html); // manual entry fields are unchanged
        }
    }

    public function test_add_member_form_enables_suggestions_but_edit_member_does_not(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-0450', 'zone' => 'Zone 1']);
        $resident = Resident::factory()->create(['household_id' => $household->getKey()]);
        $this->actingAsStaff(StaffRole::ADMIN);

        $create = $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-0450']))->assertOk()->getContent();
        $this->assertSame(3, substr_count($create, 'data-lml-name-suggest="person"'));

        $edit = $this->get(route('household-profiling.members.edit', ['householdNo' => 'HH-0450', 'memberId' => $resident->member_no]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('data-lml-name-suggest', $edit);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicPages(): array
    {
        return [
            'staff login' => ['login'],
            'staff register' => ['register'],
            'staff forgot password' => ['password.request'],
            'resident chatbot registration' => ['chatbot.register'],
            'resident chatbot forgot password' => ['chatbot.password.request'],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_public_and_resident_pages_never_offer_name_suggestions(string $route): void
    {
        $html = $this->get(route($route))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-lml-name-suggest', $html);
        $this->assertStringNotContainsString(route('staff.name-suggestions'), $html);
    }

    public function test_no_chatbot_auth_or_landing_template_uses_name_suggestions(): void
    {
        $dirs = ['pages/chatbot', 'pages/auth', 'pages/landing', 'mail'];
        foreach ($dirs as $dir) {
            $path = resource_path('views/'.$dir);
            if (! is_dir($path)) {
                continue;
            }
            foreach (File::allFiles($path) as $file) {
                $this->assertStringNotContainsString(
                    'data-lml-name-suggest',
                    $file->getContents(),
                    $file->getRelativePathname().' must not offer resident-name suggestions'
                );
            }
        }
    }
}
