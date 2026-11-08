<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ChatbotDatasetWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LanguageManagement\SaveChatbotDatasetRequest;
use App\Models\ChatbotDataset;
use App\Models\User;
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
 * SR-07 Chatbot Datasets (route middleware `ui.admin`): Admin uploads health datasets and submits them for
 * Linguist review. Admin never approves (D-7). Approved datasets are not used by the chatbot yet.
 */
class ChatbotDatasetController extends Controller
{
    private const FILTERS = [
        ChatbotDatasetCatalog::DRAFT, ChatbotDatasetCatalog::SUBMITTED, ChatbotDatasetCatalog::APPROVED,
        ChatbotDatasetCatalog::REJECTED, ChatbotDatasetCatalog::RETIRED,
    ];

    public function __construct(
        private readonly ChatbotDatasetWorkflow $workflow,
        private readonly ChatbotDatasetFileInspector $inspector,
    ) {
    }

    public function index(Request $request): View
    {
        $search = mb_strtolower(trim((string) $request->query('q', '')), 'UTF-8');
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, self::FILTERS, true)) {
            $status = 'all';
        }
        $language = (string) $request->query('language', 'all');
        if (! array_key_exists($language, ChatbotDatasetCatalog::LANGUAGES)) {
            $language = 'all';
        }

        $datasets = ChatbotDataset::query()
            ->with(['versions' => fn ($q) => $q->select(['id', 'chatbot_dataset_id', 'version_no', 'title', 'status'])])
            ->orderByDesc('updated_at')
            ->get();
        $counts = array_fill_keys(self::FILTERS, 0);
        $rows = [];
        foreach ($datasets as $dataset) {
            $row = ChatbotDatasetPresenter::row($dataset);
            foreach ($row['filters'] as $filter) {
                $counts[$filter]++;
            }
            if ($status !== 'all' && ! in_array($status, $row['filters'], true)) {
                continue;
            }
            if ($language !== 'all' && $row['language'] !== $language) {
                continue;
            }
            if ($search !== '' && ! str_contains(mb_strtolower($row['title'].' '.$row['source_name'], 'UTF-8'), $search)) {
                continue;
            }
            $rows[] = $row;
        }

        return view('pages.chatbot-datasets.index', [
            'active' => 'chatbot-datasets',
            'pageTitle' => 'Chatbot Datasets',
            'pageSubtitle' => 'Upload health information datasets and send them to a Linguist for review.',
            'rows' => $rows,
            'counts' => $counts,
            'total' => $datasets->count(),
            'search' => trim((string) $request->query('q', '')),
            'status' => $status,
            'language' => $language,
            'filters' => self::FILTERS,
            'languages' => ChatbotDatasetCatalog::LANGUAGES,
        ]);
    }

    public function create(): View
    {
        return $this->formView(null, null);
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

        return redirect()->route('chatbot-datasets.show', ['datasetId' => $dataset->id])
            ->with('status', 'Dataset saved as a draft. Check the screening results, then submit it for Linguist review.');
    }

    public function show(string $datasetId): View
    {
        $dataset = $this->find($datasetId);

        return view('pages.chatbot-datasets.show', [
            'active' => 'chatbot-datasets',
            'pageTitle' => 'Chatbot Datasets',
            'pageSubtitle' => 'Dataset details, review status and history.',
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

        $message = $dataset->approvedVersion() !== null
            ? 'Saved as a new draft version. The approved version stays current until the Linguist approves the new one.'
            : 'Draft saved.';

        return redirect()->route('chatbot-datasets.show', ['datasetId' => $dataset->id])->with('status', $message);
    }

    public function submit(Request $request, string $datasetId): RedirectResponse
    {
        $dataset = $this->find($datasetId);

        return $this->act($dataset, fn () => $this->workflow->submit($this->admin($request), $dataset), 'Submitted for Linguist review.');
    }

    public function retire(Request $request, string $datasetId): RedirectResponse
    {
        $dataset = $this->find($datasetId);

        return $this->act($dataset, fn () => $this->workflow->retire($this->admin($request), $dataset), 'Dataset retired. It can no longer be used as a chatbot source.');
    }

    public function restore(Request $request, string $datasetId): RedirectResponse
    {
        $dataset = $this->find($datasetId);

        return $this->act($dataset, fn () => $this->workflow->restore($this->admin($request), $dataset), 'Dataset restored as a draft. Submit it for review before it can be approved again.');
    }

    public function destroy(Request $request, string $datasetId): RedirectResponse
    {
        $dataset = $this->find($datasetId);
        try {
            $deleted = $this->workflow->deleteDraft($this->admin($request), $dataset);
        } catch (ChatbotDatasetWorkflowException $e) {
            $this->abortIfForbidden($e);

            return redirect()->route('chatbot-datasets.show', ['datasetId' => $dataset->id])->with('error', $e->getMessage());
        }

        return $deleted
            ? redirect()->route('chatbot-datasets.index')->with('status', 'Draft dataset deleted.')
            : redirect()->route('chatbot-datasets.show', ['datasetId' => $dataset->id])->with('status', 'Unsubmitted draft deleted. The dataset and its review history are kept.');
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function formView(?ChatbotDataset $dataset, ?array $detail): View
    {
        $editing = $dataset !== null;
        $current = $dataset?->currentVersion();

        return view('pages.chatbot-datasets.form', [
            'active' => 'chatbot-datasets',
            'pageTitle' => 'Chatbot Datasets',
            'pageSubtitle' => $editing ? 'Change or replace a dataset.' : 'Upload a dataset.',
            'dataset' => $detail,
            'values' => $editing ? ['title' => $current?->title, 'source_reference' => $current?->source_reference] : [],
            'languages' => ChatbotDatasetCatalog::LANGUAGES,
            'categories' => ChatbotDatasetCatalog::CATEGORIES,
            'maxKilobytes' => $this->inspector->maxKilobytes(),
            'action' => $editing ? route('chatbot-datasets.update', ['datasetId' => $dataset->id]) : route('chatbot-datasets.store'),
            'method' => $editing ? 'PUT' : 'POST',
        ]);
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
