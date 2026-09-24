{{-- Resident Deworming child profile (DemoCatalog fields only; no NR badge / Edit / Delete). --}}
@php
    $child = $child ?? null;
    $summaryUrl = route('health-records.child-care.deworming');
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
                        <dt>Mother's Name</dt>
                        <dd>{{ $child['mother_name'] }}</dd>
                    </div>
                    <div>
                        <dt>Address</dt>
                        <dd>{{ $child['address_line'] }}</dd>
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
            No Deworming child record matches this identifier.
        </p>
        <a href="{{ $summaryUrl }}" class="lml-hr-cc-nr__cancel-btn lml-focus-ring">Back to Deworming summary</a>
    </article>
@endif
