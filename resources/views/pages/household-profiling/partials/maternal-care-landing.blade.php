@php
    $history = $history ?? [];
    $hasVisibleHistory = $history !== [];
    $hasClosedEpisode = (bool) ($hasClosedEpisode ?? $hasVisibleHistory);
    $mcWriteEligible = (bool) ($mcWriteEligible ?? true);
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-landing-title" data-mc-landing>
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-landing-title" class="lml-mc__panel-title">
                MATERNAL CARE
            </h2>
            <p class="lml-mc__panel-subtitle">
                Record, monitor and manage maternal healthcare throughout pregnancy and beyond.
            </p>
        </div>
    </header>

    @if ($hasVisibleHistory || $hasClosedEpisode)
        <div class="lml-mc__empty" data-mc-has-history>
            <span class="lml-mc__empty-icon" aria-hidden="true">
                <i class="bi bi-clipboard2-pulse"></i>
            </span>
            <p class="lml-mc__empty-title">NO ACTIVE PREGNANCY</p>
            <p class="lml-mc__empty-copy">
                This member has past maternal records. Register a new pregnancy without changing those historical records.
            </p>
            @if (! $mcWriteEligible)
                <p class="lml-mc__empty-copy" data-mc-age-ineligible role="status">
                    {{ \App\Support\MaternalCareEligibility::WORKFLOW_INELIGIBLE_MESSAGE }}
                </p>
            @endif
            <div class="lml-mc__landing-actions">
                @if ($hasVisibleHistory)
                    <a
                        href="{{ route('household-profiling.members.maternal-care.history', [
                            'householdNo' => $householdNo,
                            'memberId' => $memberId,
                        ]) }}"
                        class="lml-mc__btn lml-mc__btn--ghost lml-focus-ring"
                        data-mc-history-link
                    >
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                        <span>Past Maternal Records</span>
                    </a>
                @endif
                @if ($mcWriteEligible)
                    <a
                        href="{{ route('household-profiling.members.maternal-care.register', [
                            'householdNo' => $householdNo,
                            'memberId' => $memberId,
                        ]) }}"
                        class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                        data-mc-register-cta
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        <span>Register Maternal Record</span>
                    </a>
                @endif
            </div>
        </div>
    @else
        <div class="lml-mc__empty" data-mc-no-record>
            <span class="lml-mc__empty-icon" aria-hidden="true">
                <i class="bi bi-clipboard2-pulse"></i>
            </span>
            <p class="lml-mc__empty-title">NO RECORD</p>
            <p class="lml-mc__empty-copy">
                No maternal care pregnancy record has been registered for this member yet.
            </p>
            @if ($mcWriteEligible)
                <a
                    href="{{ route('household-profiling.members.maternal-care.register', [
                        'householdNo' => $householdNo,
                        'memberId' => $memberId,
                    ]) }}"
                    class="lml-mc__btn lml-mc__btn--primary lml-focus-ring"
                    data-mc-register-cta
                >
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    <span>Register Maternal Record</span>
                </a>
            @else
                <p class="lml-mc__empty-copy" data-mc-age-ineligible role="status">
                    {{ \App\Support\MaternalCareEligibility::WORKFLOW_INELIGIBLE_MESSAGE }}
                </p>
            @endif
        </div>
    @endif
</section>
