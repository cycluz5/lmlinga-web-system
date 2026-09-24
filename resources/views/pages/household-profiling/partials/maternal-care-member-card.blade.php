{{-- Shared resident identity card for Maternal Care surfaces --}}
@php
    use App\Support\DemoMaternalCare;

    $emptyRecord = $emptyRecord ?? '—';
    $memberName = (string) ($demoMember['name'] ?? 'Member');
    $memberSex = (string) ($demoMember['sex'] ?? '');
    $memberAge = $demoMember['age'] ?? null;
    $dateBirth = $demoMember
        ? lml_demo_member_display($demoMember, 'birthday')
        : $emptyRecord;
    $memberStatus = (string) ($demoMember['relationship_status'] ?? $emptyRecord);
    $sexBadgeClass = strtolower($memberSex) === 'female'
        ? 'lml-mc__sex-badge--female'
        : 'lml-mc__sex-badge--male';
    $backUrl = $backUrl ?? route('household-profiling.members.show', [
        'householdNo' => $householdNo,
        'memberId' => $memberId,
    ]);
    $backLabel = $backLabel ?? 'Back to Health Summary Records for '.$memberName;
    $showActivePregnancy = ! empty($showActivePregnancy) && is_array($pregnancy ?? null);
    $historyStatus = (string) ($historyStatus ?? '');
    $historyStatusLabel = $historyStatus === 'transferred_out'
        ? 'Trans-Out'
        : ($historyStatus === 'completed' ? 'Completed' : '');
@endphp

<article class="lml-mc__member-card" aria-labelledby="lml-mc-member-name">
    <a
        href="{{ $backUrl }}"
        class="lml-mc__back lml-focus-ring"
        aria-label="{{ $backLabel }}"
    >
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
    </a>

    <div class="lml-mc__member-layout">
        <div class="lml-mc__member-profile">
            <span class="lml-mc__avatar" aria-hidden="true">
                <i class="bi bi-person-fill"></i>
            </span>
            <div class="lml-mc__member-identity">
                <div class="lml-mc__name-row">
                    <h2 id="lml-mc-member-name" class="lml-mc__member-name">
                        {{ $memberName }}
                    </h2>
                    @if ($memberSex !== '')
                        <span class="lml-mc__sex-badge {{ $sexBadgeClass }}">
                            {{ $memberSex }}
                        </span>
                    @endif
                </div>
                <dl class="lml-mc__member-dl">
                    <div class="lml-mc__member-item">
                        <dt>Age:</dt>
                        <dd>{{ $memberAge !== null && $memberAge !== '' ? $memberAge : $emptyRecord }}</dd>
                    </div>
                    <div class="lml-mc__member-item">
                        <dt>Date of Birth:</dt>
                        <dd>{{ $dateBirth !== '' ? $dateBirth : $emptyRecord }}</dd>
                    </div>
                    <div class="lml-mc__member-item">
                        <dt>Status:</dt>
                        <dd>{{ $memberStatus !== '' ? $memberStatus : $emptyRecord }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        @if ($showActivePregnancy)
            <div
                class="lml-mc__pregnancy-badge"
                role="status"
                aria-label="Pregnancy status: Active Pregnancy"
                data-mc-pregnancy-status="active"
            >
                <i class="bi bi-person-arms-up" aria-hidden="true"></i>
                <span>Active Pregnancy</span>
            </div>
        @elseif ($historyStatusLabel !== '')
            <div
                class="lml-mc__pregnancy-badge lml-mc__pregnancy-badge--history"
                role="status"
                aria-label="Pregnancy status: {{ $historyStatusLabel }}"
                data-mc-pregnancy-status="{{ $historyStatus }}"
            >
                <i class="bi bi-clock-history" aria-hidden="true"></i>
                <span>{{ $historyStatusLabel }}</span>
            </div>
        @endif
    </div>
</article>
