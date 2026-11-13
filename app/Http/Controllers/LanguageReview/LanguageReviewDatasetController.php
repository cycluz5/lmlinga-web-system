<?php

namespace App\Http\Controllers\LanguageReview;

use App\Exceptions\ChatbotDatasetWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LanguageManagement\ReviewChatbotTermRequest;
use App\Models\ChatbotDatasetVersion;
use App\Models\User;
use App\Services\ChatbotDatasetWorkflow;
use App\Support\ChatbotDatasetCatalog;
use App\Support\ChatbotDatasetPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * SR-07 Linguist review of a submitted chatbot dataset version (route middleware `ui.linguist`, gate
 * `review-language-content`). An explicit Linguist approval is the only way a version becomes Approved.
 */
class LanguageReviewDatasetController extends Controller
{
    public function __construct(private readonly ChatbotDatasetWorkflow $workflow)
    {
    }

    public function show(string $datasetVersionId): View
    {
        Gate::authorize('review-language-content');
        $version = $this->find($datasetVersionId);

        return view('pages.language-review.dataset', [
            'active' => 'language-review',
            'pageTitle' => 'Language Review',
            'pageSubtitle' => 'Read the dataset and the screening notes, then approve it or send it back with a note.',
            'item' => ChatbotDatasetPresenter::reviewItem($version),
        ]);
    }

    public function approve(ReviewChatbotTermRequest $request, string $datasetVersionId): RedirectResponse
    {
        Gate::authorize('review-language-content');
        $version = $this->find($datasetVersionId);

        try {
            $this->workflow->approve($this->linguist($request), $version, $request->note());
        } catch (ChatbotDatasetWorkflowException $e) {
            return $this->failed($e, $version);
        }

        return redirect()->route('language-review.index')
            ->with('status', 'Dataset approved. It is not used by the chatbot until dataset indexing is separately approved.');
    }

    public function reject(ReviewChatbotTermRequest $request, string $datasetVersionId): RedirectResponse
    {
        Gate::authorize('review-language-content');
        $version = $this->find($datasetVersionId);

        try {
            $this->workflow->reject($this->linguist($request), $version, (string) $request->note());
        } catch (ChatbotDatasetWorkflowException $e) {
            return $this->failed($e, $version);
        }

        return redirect()->route('language-review.index')->with('status', 'Dataset sent back to the Admin with your note.');
    }

    private function failed(ChatbotDatasetWorkflowException $e, ChatbotDatasetVersion $version): RedirectResponse
    {
        if ($e->status() === 403) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('language-review.datasets.show', ['datasetVersionId' => $version->id])->with('error', $e->getMessage());
    }

    /** Linguists only see versions that were sent for review, never Admin drafts. */
    private function find(string $datasetVersionId): ChatbotDatasetVersion
    {
        return ChatbotDatasetVersion::query()
            ->with('chatbotDataset')
            ->where('status', '!=', ChatbotDatasetCatalog::DRAFT)
            ->findOrFail((int) $datasetVersionId);
    }

    private function linguist(ReviewChatbotTermRequest $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
