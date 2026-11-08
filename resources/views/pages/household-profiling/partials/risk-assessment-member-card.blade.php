{{-- Shared resident identity card for Risk Assessment history / wizard / view --}}
@php
    use App\Support\DemoRiskAssessment;

    $emptyRecord = $emptyRecord ?? '—';
    $memberName = (string) ($demoMember['name'] ?? 'Member');
    $memberSex = (string) ($demoMember['sex'] ?? '');
    $memberAge = $demoMember['age'] ?? null;
    $dateBirth = $demoMember
        ? lml_demo_member_display($demoMember, 'birthday')
        : $emptyRecord;
    $memberStatus = (string) ($demoMember['relationship_status'] ?? $emptyRecord);
    $sexBadgeClass = strtolower($memberSex) === 'female'
        ? 'lml-risk-assess__sex-badge--female'
        : 'lml-risk-assess__sex-badge--male';
    $backUrl = $backUrl ?? route('household-profiling.members.show', [
        'householdNo' => $householdNo,
        'memberId' => $memberId,
    ]);
    $backLabel = $backLabel ?? 'Back to Health Summary Records for '.$memberName;
    $conductedAt = isset($conductedAt) && is_string($conductedAt) && $conductedAt !== ''
        ? $conductedAt
        : null;
    $dateLabel = isset($dateLabel) && is_string($dateLabel) && $dateLabel !== ''
        ? $dateLabel
        : 'Date Conducted';
@endphp

<article class="lml-risk-assess__member-card" aria-labelledby="lml-risk-assess-member-name">
    <a
        href="{{ $backUrl }}"
        class="lml-risk-assess__back lml-focus-ring"
        aria-label="{{ $backLabel }}"
    >
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
    </a>

    @if ($conductedAt)
        <p class="lml-risk-assess__conducted" data-risk-assess-conducted>
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            <span>
                {{ $dateLabel }}:
                <time datetime="{{ $conductedAt }}">
                    {{ DemoRiskAssessment::formatConductedDate($conductedAt) }}
                </time>
            </span>
        </p>
    @endif

    <div class="lml-risk-assess__member-profile">
        <span class="lml-risk-assess__avatar" aria-hidden="true">
            <i class="bi bi-person-fill"></i>
        </span>
        <div class="lml-risk-assess__member-identity">
            <div class="lml-risk-assess__name-row">
                <h2 id="lml-risk-assess-member-name" class="lml-risk-assess__member-name">
                    {{ $memberName }}
                </h2>
                @if ($memberSex !== '')
                    <span class="lml-risk-assess__sex-badge {{ $sexBadgeClass }}">
                        {{ $memberSex }}
                    </span>
                @endif
            </div>
            <dl class="lml-risk-assess__member-dl">
                <div class="lml-risk-assess__member-item">
                    <dt>Age:</dt>
                    <dd>{{ $memberAge !== null && $memberAge !== '' ? $memberAge : $emptyRecord }}</dd>
                </div>
                <div class="lml-risk-assess__member-item">
                    <dt>Date Birth:</dt>
                    <dd>{{ $dateBirth !== '' ? $dateBirth : $emptyRecord }}</dd>
                </div>
                <div class="lml-risk-assess__member-item">
                    <dt>Status:</dt>
                    <dd>{{ $memberStatus !== '' ? $memberStatus : $emptyRecord }}</dd>
                </div>
            </dl>
        </div>
    </div>
</article>
