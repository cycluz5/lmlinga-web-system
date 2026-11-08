{{-- Shared resident identity card for Family Planning history / form / view --}}
@php
    $emptyRecord = $emptyRecord ?? '—';
    $memberName = (string) ($demoMember['name'] ?? 'Member');
    $memberSex = (string) ($demoMember['sex'] ?? '');
    $memberAge = $demoMember['age'] ?? null;
    $dateBirth = $demoMember
        ? lml_demo_member_display($demoMember, 'birthday')
        : $emptyRecord;
    $memberStatus = (string) ($demoMember['relationship_status'] ?? $emptyRecord);
    $sexBadgeClass = strtolower($memberSex) === 'female'
        ? 'lml-fp__sex-badge--female'
        : 'lml-fp__sex-badge--male';
    $backUrl = $backUrl ?? route('household-profiling.members.show', [
        'householdNo' => $householdNo,
        'memberId' => $memberId,
    ]);
    $backLabel = $backLabel ?? 'Back to Health Summary Records for '.$memberName;
    $totalVisits = $totalVisits ?? null;
    $lastVisitLabel = $lastVisitLabel ?? null;
    $lastVisitIso = $lastVisitIso ?? null;
    $showVisitStats = $totalVisits !== null;
@endphp

<article class="lml-fp__member-card" aria-labelledby="lml-fp-member-name">
    <a
        href="{{ $backUrl }}"
        class="lml-fp__back lml-focus-ring"
        aria-label="{{ $backLabel }}"
    >
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
    </a>

    <div class="lml-fp__member-layout">
        <div class="lml-fp__member-profile">
            <span class="lml-fp__avatar" aria-hidden="true">
                <i class="bi bi-person-fill"></i>
            </span>
            <div class="lml-fp__member-identity">
                <div class="lml-fp__name-row">
                    <h2 id="lml-fp-member-name" class="lml-fp__member-name">
                        {{ $memberName }}
                    </h2>
                    @if ($memberSex !== '')
                        <span class="lml-fp__sex-badge {{ $sexBadgeClass }}">
                            {{ $memberSex }}
                        </span>
                    @endif
                </div>
                <dl class="lml-fp__member-dl">
                    <div class="lml-fp__member-item">
                        <dt>Age:</dt>
                        <dd>{{ $memberAge !== null && $memberAge !== '' ? $memberAge : $emptyRecord }}</dd>
                    </div>
                    <div class="lml-fp__member-item">
                        <dt>Date Birth:</dt>
                        <dd>{{ $dateBirth !== '' ? $dateBirth : $emptyRecord }}</dd>
                    </div>
                    <div class="lml-fp__member-item">
                        <dt>Status:</dt>
                        <dd>{{ $memberStatus !== '' ? $memberStatus : $emptyRecord }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        @if ($showVisitStats)
            <dl class="lml-fp__visit-stats" data-fp-visit-stats>
                <div class="lml-fp__visit-stat">
                    <dt>Total Visits:</dt>
                    <dd data-fp-total-visits>{{ $totalVisits }}</dd>
                </div>
                <div class="lml-fp__visit-stat">
                    <dt>Last Visit:</dt>
                    <dd data-fp-last-visit>
                        @if ($lastVisitIso)
                            <time datetime="{{ $lastVisitIso }}">{{ $lastVisitLabel }}</time>
                        @else
                            {{ $lastVisitLabel ?? $emptyRecord }}
                        @endif
                    </dd>
                </div>
            </dl>
        @endif
    </div>
</article>
