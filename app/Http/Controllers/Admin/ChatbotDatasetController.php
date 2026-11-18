<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ChatbotDatasetWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatbotDatasets\SaveChatbotDatasetRequest;
use App\Models\ChatbotDataset;
use App\Models\User;
use App\Services\ChatbotDatasetImporter;
use App\Services\ChatbotDatasetPublisher;
use App\Services\ChatbotDatasetWorkflow;
use App\Support\ChatbotDatasetCatalog;
use App\Support\ChatbotDatasetFileInspector;
use App\Support\ChatbotDatasetPresenter;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Chatbot Datasets (route middleware `ui.admin`): the Admin adds health datasets in English, Tagalog or
 * Bikol-Iriga. Saving publishes the dataset at once and sends it into the chatbot's health information space
 * (ChatbotDatasetPublisher).
 */
class ChatbotDatasetController extends Controller
{
    public function __construct(
        private readonly ChatbotDatasetWorkflow $workflow,
        private readonly ChatbotDatasetFileInspector $inspector,
        private readonly ChatbotDatasetPublisher $publisher,
        private readonly ChatbotDatasetImporter $importer,
    ) {
    }

    /** List the chatbot's existing documents as datasets (nothing in the chatbot itself changes). */
    public function importExisting(Request $request): RedirectResponse
    {
        $request->validate(['no_personal_data_confirmed' => ['accepted']], [
            'no_personal_data_confirmed.accepted' => 'Confirm that the existing documents contain no resident personal information.',
        ]);

        $counts = $this->importer->run($this->admin($request), true);

        $message = $counts['new'] > 0
            ? $counts['new'].' existing chatbot document(s) are now listed below.'
            : 'The existing chatbot documents were already listed.';
        if ($counts['blocked'] > 0) {
            $message .= ' '.$counts['blocked'].' could not be listed (see the screening rules).';
        }

        return redirect()->route('chatbot-datasets.index')->with('status', $message);
    }

    public function index(Request $request): View
    {
        // One tab per language (English, Tagalog, Bikol-Iriga). Search and category filter in the browser
        // (like the other admin listings) and the rows are paged by the shared pagination component.
        $language = (string) $request->query('language', 'en');
        if (! array_key_exists($language, ChatbotDatasetCatalog::LANGUAGES)) {
            $language = 'en';
        }

        $datasets = ChatbotDataset::query()
            ->with(['versions' => fn ($q) => $q->select(['id', 'chatbot_dataset_id', 'version_no', 'title', 'status'])])
            ->orderBy('category')
            ->orderBy('source_name')
            ->get();

        $languageCounts = array_fill_keys(array_keys(ChatbotDatasetCatalog::LANGUAGES), 0);
        $rows = [];
        foreach ($datasets as $dataset) {
            $languageCounts[$dataset->language] = ($languageCounts[$dataset->language] ?? 0) + 1;
            if ($dataset->language === $language) {
                $rows[] = ChatbotDatasetPresenter::row($dataset);
            }
        }

        return view('pages.chatbot-datasets.index', [
            'active' => 'chatbot-datasets',
            'pageTitle' => 'Chatbot Datasets',
            'pageSubtitle' => 'The health information the chatbot answers from. Add a dataset or change an existing one.',
            'importable' => $this->importer->pendingCount(),
            'rows' => $rows,
            'total' => $datasets->count(),
            'language' => $language,
            'languages' => ChatbotDatasetCatalog::LANGUAGES,
            'languageCounts' => $languageCounts,
            'categories' => ChatbotDatasetCatalog::CATEGORIES,
        ]);
    }

    public function create(Request $request): View
    {
        // "Add Dataset" on a language tab opens the form with that language already chosen.
        $language = (string) $request->query('language', '');

        return $this->formView(null, null, array_key_exists($language, ChatbotDatasetCatalog::LANGUAGES) ? ['language' => $language] : []);
    }

    public function store(SaveChatbotDatasetRequest $request): RedirectResponse
    {
        $file = $request->file('dataset_file');
        $dataset = $this->attempt(fn () => $this->workflow->upload($this->admin($request), [
            'title' => (string) $request->validated('title'),
            'language' => (string) $request->validated('language'),
            'category' => (string) $request->validated('category'),
            'source_reference' => (string) $request->validated('source_reference'),
            'no_personal_data_confirmed' => true,
            'original_filename' => $file->getClientOriginalName(),
        ], $this->inspector->inspectUpload($file)));

        return $this->publishedResponse($dataset, 'Dataset saved');
    }

    public function show(string $datasetId): View
    {
        $dataset = $this->find($datasetId);

        return view('pages.chatbot-datasets.show', [
            'active' => 'chatbot-datasets',
            'pageTitle' => 'Chatbot Datasets',
            'pageSubtitle' => 'Dataset details and history.',
            'dataset' => ChatbotDatasetPresenter::detail($dataset),
        ]);
    }

    public function edit(string $datasetId): View|RedirectResponse
    {
        $dataset = $this->find($datasetId);
        $detail = ChatbotDatasetPresenter::detail($dataset);
        if (! $detail['can_edit']) {
            return redirect()->route('chatbot-datasets.show', ['datasetId' => $dataset->id])->with('error', $detail['edit_blocked_reason']);
        }

        return $this->formView($dataset, $detail);
    }

    public function update(SaveChatbotDatasetRequest $request, string $datasetId): RedirectResponse
    {
        $dataset = $this->find($datasetId);
        $file = $request->file('dataset_file');
        $this->attempt(fn () => $this->workflow->saveDraft($this->admin($request), $dataset, [
            'title' => (string) $request->validated('title'),
            'source_reference' => (string) $request->validated('source_reference'),
            'no_personal_data_confirmed' => true,
            'original_filename' => $file?->getClientOriginalName(),
        ], $file !== null ? $this->inspector->inspectUpload($file) : null));

        return $this->publishedResponse($dataset, 'Changes saved');
    }

    public function retire(Request $request, string $datasetId): RedirectResponse
    {
        $dataset = $this->find($datasetId);

        return $this->act($dataset, function () use ($request, $dataset): void {
            $this->workflow->retire($this->admin($request), $dataset);
            $this->publisher->unpublish($dataset);
        }, 'Dataset retired. It was removed from the chatbot.');
    }

    public function restore(Request $request, string $datasetId): RedirectResponse
    {
        $dataset = $this->find($datasetId);
        try {
            $this->workflow->restore($this->admin($request), $dataset);
        } catch (ChatbotDatasetWorkflowException $e) {
            $this->abortIfForbidden($e);

            return redirect()->route('chatbot-datasets.show', ['datasetId' => $dataset->id])->with('error', $e->getMessage());
        }

        return $this->publishedResponse($dataset->fresh(), 'Dataset restored');
    }

    /** Try again to put the live text into the chatbot's index (for example after the AI model was unreachable). */
    public function reindex(Request $request, string $datasetId): RedirectResponse
    {
        $this->admin($request);

        return $this->publishedResponse($this->find($datasetId), 'Re-index requested');
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function formView(?ChatbotDataset $dataset, ?array $detail, array $defaults = []): View
    {
        $editing = $dataset !== null;
        $current = $dataset?->currentVersion();

        return view('pages.chatbot-datasets.form', [
            'active' => 'chatbot-datasets',
            'pageTitle' => 'Chatbot Datasets',
            'pageSubtitle' => $editing ? 'Change or replace a dataset.' : 'Upload a dataset.',
            'dataset' => $detail,
            'values' => $editing ? ['title' => $current?->title, 'source_reference' => $current?->source_reference] : $defaults,
            'languages' => ChatbotDatasetCatalog::LANGUAGES,
            'categories' => ChatbotDatasetCatalog::CATEGORIES,
            'maxKilobytes' => $this->inspector->maxKilobytes(),
            'action' => $editing ? route('chatbot-datasets.update', ['datasetId' => $dataset->id]) : route('chatbot-datasets.store'),
            'method' => $editing ? 'PUT' : 'POST',
        ]);
    }

    /** Send the live text to the chatbot and tell the Admin what happened. */
    private function publishedResponse(ChatbotDataset $dataset, string $lead): RedirectResponse
    {
        $result = $this->publisher->publish($dataset);
        $show = redirect()->route('chatbot-datasets.show', ['datasetId' => $dataset->id]);

        if ($result['indexed']) {
            return $show->with('status', $lead.' and sent to the chatbot health information. It is live now.');
        }

        if ($result['written']) {
            return $show->with('status', $lead.'. '.$result['error'].' Press Re-index to try again.');
        }

        return $show->with('error', $lead.', but '.lcfirst((string) $result['error']));
    }

    private function act(ChatbotDataset $dataset, Closure $action, string $status): RedirectResponse
    {
        try {
            $action();
        } catch (ChatbotDatasetWorkflowException $e) {
            $this->abortIfForbidden($e);

            return redirect()->route('chatbot-datasets.show', ['datasetId' => $dataset->id])->with('error', $e->getMessage());
        }

        return redirect()->route('chatbot-datasets.show', ['datasetId' => $dataset->id])->with('status', $status);
    }

    private function attempt(Closure $action): mixed
    {
        try {
            return $action();
        } catch (ChatbotDatasetWorkflowException $e) {
            $this->abortIfForbidden($e);

            throw ValidationException::withMessages([$e->field() ?? 'dataset_file' => $e->getMessage()]);
        }
    }

    private function abortIfForbidden(ChatbotDatasetWorkflowException $e): void
    {
        if ($e->status() === 403) {
            abort(403, $e->getMessage());
        }
    }

    private function find(string $datasetId): ChatbotDataset
    {
        return ChatbotDataset::query()->with('versions')->findOrFail((int) $datasetId);
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
