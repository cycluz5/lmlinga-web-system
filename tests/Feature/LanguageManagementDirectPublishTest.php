<?php

namespace Tests\Feature;

use App\Models\ChatbotTerm;
use App\Support\ApprovedBikolTerms;
use App\Support\ChatbotTermStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Language Management without a Linguist step: the Admin adds and manages Bikol-Iriga terms and a saved term is
 * used by the chatbot at once. Fictional placeholder strings only, not Bikol-Iriga words.
 */
class LanguageManagementDirectPublishTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        config(['lmlinga.chatbot.terms_require_review' => false]);
        $this->actingAsStaff(StaffRole::ADMIN, ['email' => 'lm.direct.admin@example.test', 'username' => 'lm.direct.admin']);
    }

    public function test_a_saved_term_is_used_by_the_chatbot_straight_away(): void
    {
        $this->post(route('language-management.store'), self::CONTENT)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Term saved. The chatbot can use it now.');

        $term = ChatbotTerm::query()->firstOrFail();
        $this->assertSame(ChatbotTermStatus::APPROVED, $term->displayStatus());
        $this->assertSame('Live', ChatbotTermStatus::label($term->displayStatus()));
        $this->assertContains('placeholder-term-alpha', array_column(ApprovedBikolTerms::all(), 'term'));
        $this->assertDatabaseMissing('chatbot_term_versions', ['status' => ChatbotTermStatus::SUBMITTED]);
        $this->assertDatabaseHas('chatbot_term_events', ['action' => 'published']);
    }

    public function test_editing_a_term_replaces_what_the_chatbot_uses(): void
    {
        $this->post(route('language-management.store'), self::CONTENT)->assertSessionHasNoErrors();
        $term = ChatbotTerm::query()->firstOrFail();

        $this->put(route('language-management.update', ['termId' => $term->id]), ['english_meaning' => 'placeholder meaning omega'] + self::CONTENT)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Changes saved. The chatbot uses the new version now.');

        $meanings = array_column(ApprovedBikolTerms::all(), 'english_meaning');
        $this->assertContains('placeholder meaning omega', $meanings);
        $this->assertNotContains('placeholder meaning alpha', $meanings);
        $this->assertSame(1, $term->fresh()->versions()->where('status', ChatbotTermStatus::APPROVED)->count());
        $this->assertSame(1, $term->fresh()->versions()->where('status', ChatbotTermStatus::SUPERSEDED)->count());
    }

    public function test_saving_an_unchanged_term_is_refused(): void
    {
        $this->post(route('language-management.store'), self::CONTENT)->assertSessionHasNoErrors();
        $term = ChatbotTerm::query()->firstOrFail();

        $this->put(route('language-management.update', ['termId' => $term->id]), self::CONTENT)->assertSessionHasErrors();

        $this->assertSame(1, $term->fresh()->versions()->count());
    }

    public function test_retiring_stops_chatbot_use_and_restoring_brings_it_back(): void
    {
        $this->post(route('language-management.store'), self::CONTENT)->assertSessionHasNoErrors();
        $term = ChatbotTerm::query()->firstOrFail();

        $this->post(route('language-management.retire', ['termId' => $term->id]))->assertSessionHas('status');
        $this->assertNotContains('placeholder-term-alpha', array_column(ApprovedBikolTerms::all(), 'term'));

        $this->post(route('language-management.restore', ['termId' => $term->id]))
            ->assertSessionHas('status', 'Term restored. The chatbot can use it again.');
        $this->assertContains('placeholder-term-alpha', array_column(ApprovedBikolTerms::all(), 'term'));
    }

    public function test_the_pages_have_no_linguist_wording_when_review_is_off(): void
    {
        $this->post(route('language-management.store'), self::CONTENT);
        $term = ChatbotTerm::query()->firstOrFail();

        $this->get(route('language-management.index'))->assertOk()->assertDontSee('submit them for Linguist review');
        $this->get(route('language-management.show', ['termId' => $term->id]))->assertOk()->assertDontSee('Submit for review');
        $this->get(route('language-management.create'))->assertOk()->assertSee('Save term')->assertDontSee('Save draft');
    }
}
