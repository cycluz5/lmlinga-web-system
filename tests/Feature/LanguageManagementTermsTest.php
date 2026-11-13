<?php

namespace Tests\Feature;

use App\Exceptions\ChatbotTermWorkflowException;
use App\Models\ChatbotTerm;
use App\Models\ChatbotTermEvent;
use App\Models\ChatbotTermVersion;
use App\Models\User;
use App\Services\BikolConversationService;
use App\Services\ChatbotTermWorkflow;
use App\Support\ApprovedBikolTerms;
use App\Support\ChatbotTermStatus;
use App\Support\StaffAccountStatus;
use App\Support\DemoStaffLogin;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SR-05 Bikol-Iriga terms: Admin submits, Linguist approves/rejects, versioning keeps the approved
 * version in use (D-T1), audit history, deletion limited to never-submitted drafts (D-T5).
 *
 * All strings are fictional placeholders, not Bikol-Iriga words or translations.
 */
class LanguageManagementTermsTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT = [
        'term' => 'placeholder-term-alpha',
        'english_meaning' => 'placeholder meaning alpha',
        'tagalog_meaning' => '',
        'part_of_speech' => 'noun',
        'example_usage' => 'placeholder example sentence',
        'notes' => '',
    ];

    /** @var array<string, User> */
    private array $staff = [];

    private function admin(): User
    {
        return $this->staffAs(StaffRole::ADMIN, ['email' => 'lm.admin@example.test', 'username' => 'lm.admin']);
    }

    private function linguist(array $overrides = []): User
    {
        return $this->staffAs(StaffRole::LINGUIST, $overrides + ['email' => 'lm.linguist@example.test', 'username' => 'lm.linguist']);
    }

    /** Sign in as the same account each time (created once per test). */
    private function staffAs(string $role, array $attributes): User
    {
        $key = $attributes['username'];
        if (! isset($this->staff[$key])) {
            return $this->staff[$key] = $this->actingAsStaff($role, $attributes);
        }
        $user = $this->staff[$key];
        $this->actingAs($user);
        $user->syncUiRoleSession();
        UiRole::set($role);
        session([
            Auth::guard()->getName() => $user->getAuthIdentifier(),
            DemoStaffLogin::SESSION_DISPLAY_NAME => $user->composeDisplayName(),
            DemoStaffLogin::SESSION_EMAIL => (string) $user->email,
            DemoStaffLogin::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $user;
    }

    private function workflow(): ChatbotTermWorkflow
    {
        return app(ChatbotTermWorkflow::class);
    }

    /** Create and submit a term as Admin; returns the submitted version. */
    private function submittedTerm(array $content = []): ChatbotTermVersion
    {
        $admin = $this->admin();
        $term = $this->workflow()->create($admin, $content + self::CONTENT);

        return $this->workflow()->submit($admin, $term);
    }

    public function test_admin_creates_submits_and_linguist_approves_over_http(): void
    {
        $this->admin();
        $this->get(route('language-management.index'))->assertOk()->assertSee('No terms yet.');

        $response = $this->post(route('language-management.store'), self::CONTENT);
        $term = ChatbotTerm::query()->sole();
        $response->assertRedirect(route('language-management.show', ['termId' => $term->id]));
        $this->assertSame(ChatbotTermStatus::DRAFT, $term->displayStatus());
        $this->assertSame([], ApprovedBikolTerms::all());

        $this->get(route('language-management.show', ['termId' => $term->id]))->assertOk()
            ->assertSee('placeholder-term-alpha')->assertSee('Not available for chatbot use.');
        $this->post(route('language-management.submit', ['termId' => $term->id]))
            ->assertRedirect(route('language-management.show', ['termId' => $term->id]));
        $version = $term->fresh()->openVersion();
        $this->assertSame(ChatbotTermStatus::SUBMITTED, $version->status);

        $this->linguist();
        $queue = $this->get(route('language-review.index'))->assertOk()->getContent();
        $this->assertStringContainsString('placeholder-term-alpha', $queue);
        $this->assertMatchesRegularExpression('/data-lml-language-review-count>\s*1\s*</', $queue);
        $this->get(route('language-review.terms.show', ['versionId' => $version->id]))->assertOk()->assertSee('New term');

        $this->post(route('language-review.terms.approve', ['versionId' => $version->id]), ['review_note' => 'looks fine'])
            ->assertRedirect(route('language-review.index'));

        $this->assertSame(ChatbotTermStatus::APPROVED, $version->fresh()->status);
        $this->assertSame([['term' => 'placeholder-term-alpha', 'english_meaning' => 'placeholder meaning alpha', 'tagalog_meaning' => null, 'part_of_speech' => 'noun']], ApprovedBikolTerms::all());
        $this->assertSame(
            ['created', 'submitted', 'approved'],
            ChatbotTermEvent::query()->orderBy('id')->pluck('action')->all()
        );
        $approval = ChatbotTermEvent::query()->where('action', 'approved')->sole();
        $this->assertSame('Linguist', $approval->actor_role);
        $this->assertSame('looks fine', $approval->note);
    }

    public function test_approved_version_stays_in_use_while_a_revision_is_reviewed(): void
    {
        $v1 = $this->submittedTerm();
        $linguist = $this->linguist();
        $this->workflow()->approve($linguist, $v1);
        $term = $v1->chatbotTerm->fresh();

        // Admin edits the approved term: a new draft version, v1 stays approved and in use.
        $admin = $this->admin();
        $this->put(route('language-management.update', ['termId' => $term->id]), ['english_meaning' => 'placeholder meaning beta'] + self::CONTENT)
            ->assertSessionHasNoErrors();
        $term->refresh();
        $this->assertSame(2, $term->versions()->count());
        $this->assertSame(ChatbotTermStatus::APPROVED, $v1->fresh()->status);
        $this->assertSame('placeholder meaning alpha', ApprovedBikolTerms::all()[0]['english_meaning']);

        // Revision rejected: v1 still in use; the rejected v2 is kept unchanged.
        $v2 = $this->workflow()->submit($admin, $term);
        $this->assertSame(2, $v2->version_no);
        $this->workflow()->reject($this->linguist(), $v2, 'fix the meaning');
        $this->assertSame(ChatbotTermStatus::REJECTED, $v2->fresh()->status);
        $this->assertSame('placeholder meaning alpha', ApprovedBikolTerms::all()[0]['english_meaning']);

        // Editing after a rejection creates v3; v2 keeps exactly what was reviewed.
        $v3 = $this->workflow()->saveDraft($this->admin(), $term->fresh(), ['english_meaning' => 'placeholder meaning gamma'] + self::CONTENT);
        $this->assertSame(3, $v3->version_no);
        $this->assertSame('placeholder meaning beta', $v2->fresh()->english_meaning);

        $this->workflow()->submit($this->admin(), $term->fresh());
        $this->workflow()->approve($this->linguist(), $v3->fresh());

        $this->assertSame(ChatbotTermStatus::SUPERSEDED, $v1->fresh()->status);
        $this->assertSame(ChatbotTermStatus::APPROVED, $v3->fresh()->status);
        $this->assertCount(1, ApprovedBikolTerms::all());
        $this->assertSame('placeholder meaning gamma', ApprovedBikolTerms::all()[0]['english_meaning']);
        $this->assertSame(1, ChatbotTermVersion::query()->where('status', ChatbotTermStatus::APPROVED)->count());
    }

    public function test_review_page_for_a_revision_shows_the_version_in_use(): void
    {
        $v1 = $this->submittedTerm();
        $this->workflow()->approve($this->linguist(), $v1);
        $admin = $this->admin();
        $this->workflow()->saveDraft($admin, $v1->chatbotTerm->fresh(), ['english_meaning' => 'placeholder meaning beta'] + self::CONTENT);
        $v2 = $this->workflow()->submit($admin, $v1->chatbotTerm->fresh());

        $this->linguist();
        $this->get(route('language-review.index'))->assertOk()->assertSee('Revision');
        $this->get(route('language-review.terms.show', ['versionId' => $v2->id]))->assertOk()
            ->assertSee('Revised term')
            ->assertSee('placeholder meaning beta')
            ->assertSee('placeholder meaning alpha');
    }

    public function test_only_admin_manages_terms_and_only_linguist_reviews(): void
    {
        $version = $this->submittedTerm();
        $termId = $version->chatbot_term_id;

        foreach ([StaffRole::BHW, StaffRole::BNS, StaffRole::BSPO] as $role) {
            $this->actingAsStaff($role, ['email' => $role.'.lm@example.test', 'username' => $role.'.lm']);
            $this->get(route('language-management.index'))->assertForbidden();
            $this->post(route('language-management.store'), self::CONTENT)->assertForbidden();
            $this->get(route('language-management.show', ['termId' => $termId]))->assertForbidden();
            $this->get(route('language-review.terms.show', ['versionId' => $version->id]))->assertForbidden();
            $this->post(route('language-review.terms.approve', ['versionId' => $version->id]))->assertForbidden();
        }

        $this->linguist();
        $this->get(route('language-management.index'))->assertForbidden();
        $this->post(route('language-management.store'), self::CONTENT)->assertForbidden();
        $this->post(route('language-management.submit', ['termId' => $termId]))->assertForbidden();

        // D-7 / LQ-2: Admin never approves.
        $this->admin();
        $this->get(route('language-review.terms.show', ['versionId' => $version->id]))->assertForbidden();
        $this->post(route('language-review.terms.approve', ['versionId' => $version->id]))->assertForbidden();
        $this->post(route('language-review.terms.reject', ['versionId' => $version->id]), ['review_note' => 'x'])->assertForbidden();

        $this->assertSame(ChatbotTermStatus::SUBMITTED, $version->fresh()->status);
        $this->assertSame(1, ChatbotTerm::query()->count());
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('language-management.index'))->assertRedirect(route('login'));
        $this->post(route('language-management.store'), self::CONTENT)->assertRedirect(route('login'));
        $this->assertSame(0, ChatbotTerm::query()->count());
    }

    public function test_workflow_service_refuses_wrong_roles_and_self_review(): void
    {
        $version = $this->submittedTerm();
        $admin = $this->admin();
        $bhw = $this->actingAsStaff(StaffRole::BHW, ['email' => 'svc.bhw@example.test', 'username' => 'svc.bhw']);
        $inactive = $this->linguist(['email' => 'svc.inactive@example.test', 'username' => 'svc.inactive', 'status' => StaffAccountStatus::INACTIVE]);
        $linguist = $this->linguist();

        foreach ([
            fn () => $this->workflow()->approve($admin, $version),
            fn () => $this->workflow()->approve($bhw, $version),
            fn () => $this->workflow()->approve($inactive, $version),
            fn () => $this->workflow()->create($linguist, self::CONTENT),
            fn () => $this->workflow()->create($bhw, self::CONTENT),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected refusal');
            } catch (ChatbotTermWorkflowException $e) {
                $this->assertSame(403, $e->status());
            }
        }

        $version->update(['created_by_user_id' => $linguist->getKey()]);
        $this->expectException(ChatbotTermWorkflowException::class);
        $this->workflow()->approve($linguist, $version->fresh());
    }

    public function test_illegal_transitions_are_refused(): void
    {
        $admin = $this->admin();
        $term = $this->workflow()->create($admin, self::CONTENT);
        $draft = $term->fresh()->openVersion();

        // A Linguist cannot see or act on a draft that was never submitted.
        $this->linguist();
        $this->get(route('language-review.terms.show', ['versionId' => $draft->id]))->assertNotFound();
        $this->post(route('language-review.terms.approve', ['versionId' => $draft->id]))->assertNotFound();

        $this->admin();
        $this->post(route('language-management.submit', ['termId' => $term->id]));
        $submitted = $draft->fresh();

        // Edit, retire and delete are refused while under review.
        $this->get(route('language-management.edit', ['termId' => $term->id]))->assertRedirect(route('language-management.show', ['termId' => $term->id]));
        $this->put(route('language-management.update', ['termId' => $term->id]), self::CONTENT)->assertSessionHasErrors('term');
        $this->post(route('language-management.retire', ['termId' => $term->id]))->assertSessionHas('error');
        $this->delete(route('language-management.destroy', ['termId' => $term->id]))->assertSessionHas('error');
        $this->post(route('language-management.submit', ['termId' => $term->id]))->assertSessionHas('error');

        // Reject needs a note; the version stays submitted without one.
        $this->linguist();
        $this->post(route('language-review.terms.reject', ['versionId' => $submitted->id]), ['review_note' => '  '])
            ->assertSessionHasErrors('review_note');
        $this->assertSame(ChatbotTermStatus::SUBMITTED, $submitted->fresh()->status);

        // A second decision on the same version is refused.
        $this->post(route('language-review.terms.approve', ['versionId' => $submitted->id]))->assertRedirect(route('language-review.index'));
        $this->post(route('language-review.terms.reject', ['versionId' => $submitted->id]), ['review_note' => 'late'])
            ->assertSessionHas('error');
        $this->assertSame(ChatbotTermStatus::APPROVED, $submitted->fresh()->status);

        // Submitting a revision identical to the approved version is refused.
        $admin = $this->admin();
        $this->workflow()->saveDraft($admin, $term->fresh(), self::CONTENT);
        $this->expectException(ChatbotTermWorkflowException::class);
        $this->workflow()->submit($admin, $term->fresh());
    }

    public function test_only_never_submitted_drafts_can_be_deleted(): void
    {
        $admin = $this->admin();

        // A brand-new draft is deleted with its rows.
        $draftTerm = $this->workflow()->create($admin, ['term' => 'placeholder-term-delete'] + self::CONTENT);
        $this->delete(route('language-management.destroy', ['termId' => $draftTerm->id]))->assertRedirect(route('language-management.index'));
        $this->assertNull(ChatbotTerm::query()->find($draftTerm->id));
        $this->assertSame(0, ChatbotTermEvent::query()->where('chatbot_term_id', $draftTerm->id)->count());

        // A rejected term cannot be deleted; its history stays.
        $rejected = $this->submittedTerm(['term' => 'placeholder-term-rejected']);
        $this->workflow()->reject($this->linguist(), $rejected, 'needs work');
        $this->admin();
        $this->delete(route('language-management.destroy', ['termId' => $rejected->chatbot_term_id]))->assertSessionHas('error');
        $this->assertNotNull(ChatbotTerm::query()->find($rejected->chatbot_term_id));

        // An unsubmitted revision of an approved term is discarded; the reviewed version and history stay.
        $approved = $this->submittedTerm();
        $this->workflow()->approve($this->linguist(), $approved);
        $admin = $this->admin();
        $revision = $this->workflow()->saveDraft($admin, $approved->chatbotTerm->fresh(), ['english_meaning' => 'placeholder meaning beta'] + self::CONTENT);
        $this->delete(route('language-management.destroy', ['termId' => $approved->chatbot_term_id]))
            ->assertRedirect(route('language-management.show', ['termId' => $approved->chatbot_term_id]));
        $this->assertNull(ChatbotTermVersion::query()->find($revision->id));
        $this->assertSame(ChatbotTermStatus::APPROVED, $approved->fresh()->status);
        $actions = ChatbotTermEvent::query()->where('chatbot_term_id', $approved->chatbot_term_id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['created', 'submitted', 'approved', 'discarded'], $actions);
    }

    public function test_retire_removes_from_use_and_restore_requires_new_review(): void
    {
        $version = $this->submittedTerm();
        $this->workflow()->approve($this->linguist(), $version);
        $this->assertCount(1, ApprovedBikolTerms::all());

        $this->admin();
        $termId = $version->chatbot_term_id;
        $this->post(route('language-management.retire', ['termId' => $termId]))->assertSessionHas('status');
        $this->assertSame([], ApprovedBikolTerms::all());
        $this->assertSame(ChatbotTermStatus::SUPERSEDED, $version->fresh()->status);
        $this->assertSame(ChatbotTermStatus::RETIRED, ChatbotTerm::query()->find($termId)->displayStatus());
        $this->put(route('language-management.update', ['termId' => $termId]), self::CONTENT)->assertSessionHasErrors('term');

        $this->post(route('language-management.restore', ['termId' => $termId]))->assertSessionHas('status');
        $term = ChatbotTerm::query()->find($termId);
        $this->assertSame(ChatbotTermStatus::DRAFT, $term->displayStatus());
        $this->assertSame([], ApprovedBikolTerms::all());
        $this->assertSame(2, $term->versions()->count());
        $this->assertSame(['created', 'submitted', 'approved', 'retired', 'restored'], $term->events()->pluck('action')->all());
    }

    public function test_duplicate_term_with_same_meaning_is_refused(): void
    {
        $this->admin();
        $this->post(route('language-management.store'), self::CONTENT)->assertSessionHasNoErrors();
        $this->post(route('language-management.store'), ['term' => '  PLACEHOLDER-term-ALPHA ', 'english_meaning' => 'Placeholder  Meaning alpha'] + self::CONTENT)
            ->assertSessionHasErrors('term');
        $this->post(route('language-management.store'), ['english_meaning' => 'placeholder meaning other'] + self::CONTENT)
            ->assertSessionHasNoErrors();
        $this->assertSame(2, ChatbotTerm::query()->count());
    }

    public function test_validation_limits_and_allowed_values(): void
    {
        $this->admin();
        $this->post(route('language-management.store'), ['term' => '', 'english_meaning' => ''] + self::CONTENT)
            ->assertSessionHasErrors(['term', 'english_meaning']);
        $this->post(route('language-management.store'), ['term' => str_repeat('a', 121)] + self::CONTENT)->assertSessionHasErrors('term');
        $this->post(route('language-management.store'), ['part_of_speech' => 'interjection-x'] + self::CONTENT)->assertSessionHasErrors('part_of_speech');
        $this->post(route('language-management.store'), ['example_usage' => str_repeat('a', 1001)] + self::CONTENT)->assertSessionHasErrors('example_usage');
        $this->assertSame(0, ChatbotTerm::query()->count());
    }

    public function test_term_text_is_escaped_on_admin_and_review_pages(): void
    {
        $version = $this->submittedTerm(['term' => '<script>alert(1)</script>', 'notes' => '<b>bold</b>']);

        $this->admin();
        $this->get(route('language-management.show', ['termId' => $version->chatbot_term_id]))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->linguist();
        $this->get(route('language-review.terms.show', ['versionId' => $version->id]))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>bold</b>', false);
    }

    public function test_term_urls_are_opaque(): void
    {
        $version = $this->submittedTerm();
        $this->admin();

        $url = route('language-management.show', ['termId' => $version->chatbot_term_id]);
        $this->assertStringNotContainsString('/language-management/'.$version->chatbot_term_id, $url);
        $this->get('/language-management/'.$version->chatbot_term_id)->assertNotFound();

        $this->linguist();
        $this->get('/language-review/terms/'.$version->id)->assertNotFound();
    }

    public function test_sidebar_shows_language_management_to_admin_only(): void
    {
        $this->admin();
        $this->get(route('language-management.index'))->assertOk()->assertSee('Language Management');
        $this->get(route('dashboard'))->assertOk()->assertSee(route('language-management.index'), false);

        $this->actingAsStaff(StaffRole::BHW, ['email' => 'side.bhw@example.test', 'username' => 'side.bhw']);
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('language-management.index'), false);
    }

    public function test_chatbot_behaviour_is_unchanged_by_approved_terms(): void
    {
        $version = $this->submittedTerm(['term' => 'placeholder-greeting']);
        $this->workflow()->approve($this->linguist(), $version);

        // D-T6: approved terms are not wired into the chatbot yet.
        $this->assertSame(['type' => 'none'], app(BikolConversationService::class)->resolve('placeholder-greeting'));
        foreach (['app/Services/RagService.php', 'app/Services/BikolConversationService.php', 'app/Services/Prompts/BikolPrompts.php'] as $file) {
            $this->assertStringNotContainsString('ApprovedBikolTerms', (string) file_get_contents(base_path($file)), $file);
        }
    }
}
