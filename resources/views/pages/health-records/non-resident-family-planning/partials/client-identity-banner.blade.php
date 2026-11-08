{{-- Non-resident FP client identity banner (Figma client header) --}}
@php
    use App\Support\HealthRecordsNonResidentFamilyPlanning;

    $client = $client ?? null;
    $clientKey = $clientKey ?? '';
    $backUrl = $backUrl ?? route('health-records.family-planning.non-residents.index');
    $backLabel = $backLabel ?? 'Back to Non-Residents Client listing';
    $showAddVisit = $showAddVisit ?? false;
    $addVisitUrl = $addVisitUrl ?? null;
    $hideBannerBack = $hideBannerBack ?? false;
    $clientIconClass = $clientIconClass ?? 'bi-person-fill';

    if ($client !== null) {
        $displayName = HealthRecordsNonResidentFamilyPlanning::displayName($client);
        $age = $client['age'] ?? null;
        $sex = (string) ($client['sex'] ?? '');
    } else {
        $displayName = 'Client not found';
        $age = null;
        $sex = '';
    }
@endphp

<header
    class="lml-hr-fp-nr__client-banner @if ($hideBannerBack) lml-hr-fp-nr__client-banner--no-back @endif"
    aria-labelledby="lml-hr-fp-nr-client-name"
>
    @if (! $hideBannerBack)
        <a
            href="{{ $backUrl }}"
            class="lml-hr-fp-nr__client-back lml-focus-ring"
            aria-label="{{ $backLabel }}"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>
    @endif

    <div class="lml-hr-fp-nr__client-banner-body">
        <span class="lml-hr-fp-nr__client-icon" aria-hidden="true">
            <i class="bi {{ $clientIconClass }}"></i>
        </span>
        <div class="lml-hr-fp-nr__client-banner-text">
            <h2 id="lml-hr-fp-nr-client-name" class="lml-hr-fp-nr__client-name">
                {{ $displayName }}
            </h2>
            @if ($client !== null)
                <p class="lml-hr-fp-nr__client-meta">
                    @if ($age !== null)
                        <span>{{ $age }} yrs old</span>
                    @endif
                    @if ($age !== null && $sex !== '')
                        <span class="lml-hr-fp-nr__client-meta-sep" aria-hidden="true">|</span>
                    @endif
                    @if ($sex !== '')
                        <span>{{ $sex }}</span>
                    @endif
                </p>
            @endif
        </div>
    </div>

    @if ($showAddVisit && $addVisitUrl && $client !== null)
        <a
            href="{{ $addVisitUrl }}"
            class="lml-hr-fp-nr__add-visit-btn lml-focus-ring"
        >
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span>Add Visit</span>
        </a>
    @endif
</header>
