<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ChatbotTermWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LanguageManagement\SaveChatbotTermRequest;
use App\Models\ChatbotTerm;
use App\Models\User;
use App\Services\ChatbotTermWorkflow;
use App\Support\ChatbotTermPresenter;
use App\Support\ChatbotTermStatus;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * SR-05 Language Management (route middleware `ui.admin`): Admin adds and manages Bikol-Iriga terms
 * and submits them for Linguist review. Admin never approves (D-7).
 */
class LanguageManagementController extends Controller
{
    private const FILTERS = [
        ChatbotTermStatus::DRAFT, ChatbotTermStatus::SUBMITTED, ChatbotTermStatus::APPROVED,
        ChatbotTermStatus::REJECTED, ChatbotTermStatus::RETIRED,
    ];

    public function __construct(private readonly ChatbotTermWorkflow $workflow)
    {
    }

    public function index(Request $request): View
    {
        $search = mb_strtolower(trim((string) $request->query('q', '')), 'UTF-8');
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, self::FILTERS, true)) {
            $status = 'all';
        }

        $terms = ChatbotTerm::query()->with('versions')->orderByDesc('updated_at')->get();
        $counts = array_fill_keys(self::FILTERS, 0);
        $rows = [];
        foreach ($terms as $term) {
            $row = ChatbotTermPresenter::row($term);
            foreach ($row['filters'] as $filter) {
                $counts[$filter]++;
            }
            if ($status !== 'all' && ! in_array($status, $row['filters'], true)) {
                continue;
            }
            if ($search !== '' && ! str_contains(mb_strtolower($row['term'].' '.$row['english_meaning'], 'UTF-8'), $search)) {
                continue;
            }
            $rows[] = $row;
        }

        return view('pages.language-management.index', [
            'active' => 'language-management',
            'pageTitle' => 'Language Management',
            'pageSubtitle' => 'Add Bikol-Iriga terms and send them to a Linguist for review.',
            'rows' => $rows,
            'counts' => $counts,
            'total' => $terms->count(),
            'search' => trim((string) $request->query('q', '')),
            'status' => $status,
            'filters' => self::FILTERS,
        ]);
    }

    public function create(): View
    {
        return $this->formView(null, null);
    }

    public function store(SaveChatbotTermRequest $request): RedirectResponse
    {
        $term = $this->attempt(fn () => $this->workflow->create($this->admin($request), $request->content()));

        return redirect()->route('language-management.show', ['termId' => $term->id])
            ->with('status', 'Term saved as a draft. Submit it when it is ready for Linguist review.');
    }

    public function show(string $termId): View
    {
        $term = $this->find($termId);

        return view('pages.language-management.show', [
            'active' => 'language-management',
            'pageTitle' => 'Language Management',
            'pageSubtitle' => 'Term details, review status and history.',
            'term' => ChatbotTermPresenter::detail($term),
        ]);
    }

    public function edit(string $termId): View|RedirectResponse
    {
        $term = $this->find($termId);
        $detail = ChatbotTermPresenter::detail($term);
        if (! $detail['can_edit']) {
            return redirect()->route('language-management.show', ['termId' => $term->id])
                ->with('error', $detail['edit_blocked_reason']);
        }

        return $this->formView($term, $detail);
    }

    public function update(SaveChatbotTermRequest $request, string $termId): RedirectResponse
    {
        $term = $this->find($termId);
        $this->attempt(fn () => $this->workflow->saveDraft($this->admin($request), $term, $request->content()));

        $message = $term->approvedVersion() !== null
            ? 'Changes saved as a new draft. The approved version stays in use until the Linguist approves the new one.'
            : 'Draft saved.';

        return redirect()->route('language-management.show', ['termId' => $term->id])->with('status', $message);
    }

    public function submit(Request $request, string $termId): RedirectResponse
    {
        $term = $this->find($termId);

        return $this->act($term, fn () => $this->workflow->submit($this->admin($request), $term), 'Submitted for Linguist review.');
    }

    public function retire(Request $request, string $termId): RedirectResponse
    {
        $term = $this->find($termId);

        return $this->act($term, fn () => $this->workflow->retire($this->admin($request), $term), 'Term retired. It is no longer available for chatbot use.');
    }

    public function restore(Request $request, string $termId): RedirectResponse
    {
        $term = $this->find($termId);

        return $this->act($term, fn () => $this->workflow->restore($this->admin($request), $term), 'Term restored as a draft. Submit it for review before it can be used again.');
    }

    public function destroy(Request $request, string $termId): RedirectResponse
    {
        $term = $this->find($termId);
        try {
            $deleted = $this->workflow->deleteDraft($this->admin($request), $term);
        } catch (ChatbotTermWorkflowException $e) {
            $this->abortIfForbidden($e);

            return redirect()->route('language-management.show', ['termId' => $term->id])->with('error', $e->getMessage());
        }

        return $deleted
            ? redirect()->route('language-management.index')->with('status', 'Draft term deleted.')
            : redirect()->route('language-management.show', ['termId' => $term->id])->with('status', 'Unsubmitted draft deleted. The term and its review history are kept.');
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function formView(?ChatbotTerm $term, ?array $detail): View
    {
        $editing = $term !== null;

        return view('pages.language-management.form', [
            'active' => 'language-management',
            'pageTitle' => 'Language Management',
            'pageSubtitle' => $editing ? 'Edit a Bikol-Iriga term.' : 'Add a Bikol-Iriga term.',
            'term' => $detail,
            'values' => $editing ? ($term->currentVersion()?->content() ?? []) : [],
            'partsOfSpeech' => ChatbotTermStatus::PARTS_OF_SPEECH,
            'action' => $editing ? route('language-management.update', ['termId' => $term->id]) : route('language-management.store'),
            'method' => $editing ? 'PUT' : 'POST',
        ]);
    }

    private function act(ChatbotTerm $term, Closure $action, string $status): RedirectResponse
    {
        try {
            $action();
        } catch (ChatbotTermWorkflowException $e) {
            $this->abortIfForbidden($e);

            return redirect()->route('language-management.show', ['termId' => $term->id])->with('error', $e->getMessage());
        }

        return redirect()->route('language-management.show', ['termId' => $term->id])->with('status', $status);
    }

    private function attempt(Closure $action): mixed
    {
        try {
            return $action();
        } catch (ChatbotTermWorkflowException $e) {
            $this->abortIfForbidden($e);

            throw ValidationException::withMessages([$e->field() ?? 'term' => $e->getMessage()]);
        }
    }

    private function abortIfForbidden(ChatbotTermWorkflowException $e): void
    {
        if ($e->status() === 403) {
            abort(403, $e->getMessage());
        }
    }

    private function find(string $termId): ChatbotTerm
    {
        return ChatbotTerm::query()->with('versions')->findOrFail((int) $termId);
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
