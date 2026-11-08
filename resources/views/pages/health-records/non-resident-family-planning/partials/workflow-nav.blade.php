@php
    $backUrl = $backUrl ?? route('health-records.family-planning.non-residents.index');
    $backLabel = $backLabel ?? 'Back to Non Residents Client listing';
    $navTitle = $navTitle ?? 'Non Residents Client';
@endphp

<nav class="lml-hr-fp-nr__workflow-nav" aria-label="Non-resident family planning workflow">
    <a
        href="{{ $backUrl }}"
        class="lml-hr-fp-nr__workflow-back lml-focus-ring"
        aria-label="{{ $backLabel }}"
    >
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        <span>{{ $navTitle }}</span>
    </a>
</nav>
