<?php

namespace App\Http\Controllers\LanguageReview;

use App\Exceptions\ChatbotTermWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LanguageManagement\ReviewChatbotTermRequest;
use App\Models\ChatbotTermVersion;
use App\Models\User;
use App\Services\ChatbotTermWorkflow;
use App\Support\ChatbotTermPresenter;
use App\Support\ChatbotTermStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * SR-05 Linguist review of a submitted Bikol-Iriga term version (route middleware `ui.linguist`, gate
 * `review-language-content`). Approve or reject only; the Linguist never edits the text (D-T2).
 */
class LanguageReviewTermController extends Controller
{
    public function __construct(private readonly ChatbotTermWorkflow $workflow)
    {
    }

    public function show(string $versionId): View
    {
        Gate::authorize('review-language-content');
        $version = $this->find($versionId);

        return view('pages.language-review.term', [
            'active' => 'language-review',
            'pageTitle' => 'Language Review',
            'pageSubtitle' => 'Check the term, then approve it or send it back with a note.',
            'item' => ChatbotTermPresenter::reviewItem($version),
        ]);
    }

    public function approve(ReviewChatbotTermRequest $request, string $versionId): RedirectResponse
    {
        Gate::authorize('review-language-content');
        $version = $this->find($versionId);

        try {
            $this->workflow->approve($this->linguist($request), $version, $request->note());
        } catch (ChatbotTermWorkflowException $e) {
            return $this->failed($e, $version);
        }

        return redirect()->route('language-review.index')->with('status', 'Term approved. It is now available for chatbot use.');
    }

    public function reject(ReviewChatbotTermRequest $request, string $versionId): RedirectResponse
    {
        Gate::authorize('review-language-content');
        $version = $this->find($versionId);

        try {
            $this->workflow->reject($this->linguist($request), $version, (string) $request->note());
        } catch (ChatbotTermWorkflowException $e) {
            return $this->failed($e, $version);
        }

        return redirect()->route('language-review.index')->with('status', 'Term sent back to the Admin with your note.');
    }

    private function failed(ChatbotTermWorkflowException $e, ChatbotTermVersion $version): RedirectResponse
    {
        if ($e->status() === 403) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('language-review.terms.show', ['versionId' => $version->id])->with('error', $e->getMessage());
    }

    /** Only versions that were ever sent for review are visible to a Linguist (never Admin drafts). */
    private function find(string $versionId): ChatbotTermVersion
    {
        return ChatbotTermVersion::query()
            ->with('chatbotTerm')
            ->where('status', '!=', ChatbotTermStatus::DRAFT)
            ->whereHas('chatbotTerm', fn ($q) => $q->where('language', ChatbotTermStatus::LANGUAGE_BIKOL))
            ->findOrFail((int) $versionId);
    }

    private function linguist(ReviewChatbotTermRequest $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
