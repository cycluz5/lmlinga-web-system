@php
    $readOnly = true;
    $status = (string) ($pregnancy['status'] ?? '');
    $statusLabel = $status === 'transferred_out' ? 'Trans-Out' : 'Completed';
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-history-show-title" data-mc-history-show data-mc-readonly="true">
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-history-show-title" class="lml-mc__panel-title">Past Maternal Record</h2>
            <p class="lml-mc__panel-subtitle">
                Read-only history for Pregnancy {{ $pregnancy['number'] ?? '' }}.
            </p>
        </div>
        <a
            href="{{ route('household-profiling.members.maternal-care.history', $routeParams) }}"
            class="lml-mc__btn lml-mc__btn--ghost lml-focus-ring"
        >
            Back to Pregnancy History
        </a>
    </header>

    <p class="lml-mc__history-status" data-mc-history-record-status="{{ $status }}" role="status">
        Status: <strong>{{ $statusLabel }}</strong>
    </p>
</section>

@include('pages.household-profiling.partials.maternal-care-prenatal', [
    'pregnancy' => $pregnancy,
    'routeParams' => $routeParams,
    'canPersist' => false,
    'readOnly' => true,
])
@include('pages.household-profiling.partials.maternal-care-supplementations', [
    'pregnancy' => $pregnancy,
    'routeParams' => $routeParams,
    'canPersist' => false,
    'readOnly' => true,
])
@include('pages.household-profiling.partials.maternal-care-laboratory', [
    'pregnancy' => $pregnancy,
    'routeParams' => $routeParams,
    'canPersist' => false,
    'readOnly' => true,
])
@include('pages.household-profiling.partials.maternal-care-delivery', [
    'pregnancy' => $pregnancy,
    'routeParams' => $routeParams,
    'canPersist' => false,
    'readOnly' => true,
])
@include('pages.household-profiling.partials.maternal-care-postnatal', [
    'pregnancy' => $pregnancy,
    'routeParams' => $routeParams,
    'canPersist' => false,
    'readOnly' => true,
])
