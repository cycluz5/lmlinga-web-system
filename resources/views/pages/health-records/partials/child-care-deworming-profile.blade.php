{{-- Resident Deworming member profile. Zone from household purok via HouseholdZoneResolver. --}}
@php
    $child = $child ?? null;
    $isHouseholdProfilingDeworming = filled($householdNo ?? null) && filled($memberId ?? null);
    if ($isHouseholdProfilingDeworming) {
        $summaryUrl = route('household-profiling.members.show', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ]);
        $stubBackLabel = 'Back to member health records';
    } else {
        $summaryUrl = route('health-records.child-care.deworming');
        $stubBackLabel = 'Back to Deworming summary';
    }
@endphp

@if ($child)
    <article class="lml-hr-cc-nr__profile" aria-labelledby="lml-hr-dw-child-name">
        <div class="lml-hr-cc-nr__profile-main">
            <div class="lml-hr-cc-nr__avatar" aria-hidden="true">
                <i class="bi bi-person-fill"></i>
            </div>
            <div class="lml-hr-cc-nr__profile-body">
                <div class="lml-hr-cc-nr__identity">
                    <h2 class="lml-hr-cc-nr__child-name" id="lml-hr-dw-child-name">{{ $child['full_name'] }}</h2>
                    <div class="lml-hr-cc-nr__badges">
                        @if (filled($child['sex'] ?? null))
                            <span class="lml-hr-cc-nr__sex-badge lml-hr-cc-nr__sex-badge--{{ strtolower($child['sex']) }}">
                                {{ $child['sex'] }}
                            </span>
                        @endif
                    </div>
                </div>
                <dl class="lml-hr-cc-nr__facts">
                    <div>
                        <dt>Age</dt>
                        <dd>{{ $child['age_label'] }}</dd>
                    </div>
                    <div>
                        <dt>Date Birth</dt>
                        <dd>{{ $child['birthday_label'] }}</dd>
                    </div>
                    <div>
                        <dt>Zone</dt>
                        <dd>{{ $child['zone'] }}</dd>
                    </div>
                    <div>
                        <dt>School &amp; Grade Level</dt>
                        <dd>{{ $child['school_grade_label'] }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </article>
@else
    <article class="lml-hr-cc-nr__profile">
        <h2 class="lml-hr-cc-nr__child-name">Record not found</h2>
        <p class="lml-hr-cc-nr__stub-note">
            No Deworming member record matches this identifier.
        </p>
        <a href="{{ $summaryUrl }}" class="lml-hr-cc-nr__cancel-btn lml-focus-ring">{{ $stubBackLabel }}</a>
    </article>
@endif
