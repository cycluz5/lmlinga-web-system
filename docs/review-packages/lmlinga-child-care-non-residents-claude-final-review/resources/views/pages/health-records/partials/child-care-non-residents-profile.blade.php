@php
    $child = $child ?? null;
    $listingUrl = route('health-records.child-care.non-residents.index');
@endphp

@if ($child)
    <article class="lml-hr-cc-nr__profile" aria-labelledby="lml-hr-cc-nr-child-name">
        <div class="lml-hr-cc-nr__profile-main">
            <div class="lml-hr-cc-nr__avatar" aria-hidden="true">
                <i class="bi bi-person-fill"></i>
            </div>
            <div class="lml-hr-cc-nr__profile-body">
                <div class="lml-hr-cc-nr__identity">
                    <h2 class="lml-hr-cc-nr__child-name" id="lml-hr-cc-nr-child-name">{{ $child['full_name'] }}</h2>
                    <div class="lml-hr-cc-nr__badges">
                        @if (filled($child['sex'] ?? null))
                            <span class="lml-hr-cc-nr__sex-badge lml-hr-cc-nr__sex-badge--{{ strtolower($child['sex']) }}">
                                {{ $child['sex'] }}
                            </span>
                        @endif
                        <span class="lml-hr-cc-nr__nr-badge">Non-Resident</span>
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
        <div class="lml-hr-cc-nr__profile-actions">
            <button
                type="button"
                class="lml-hr-cc-nr__ghost-btn lml-focus-ring"
                data-hr-cc-nr-delete
                data-hr-cc-nr-preview="Preview only: this child was not deleted. Backend persistence is not yet implemented."
            >
                Delete
            </button>
            <a
                href="{{ $child['edit_url'] }}"
                class="lml-hr-cc-nr__save-btn lml-hr-cc-nr__profile-edit lml-focus-ring"
                aria-label="Edit personal information for {{ $child['full_name'] }}"
            >
                Edit
            </a>
        </div>
    </article>
@else
    <article class="lml-hr-cc-nr__profile">
        <h2 class="lml-hr-cc-nr__child-name">Record not found</h2>
        <p class="lml-hr-cc-nr__stub-note">No non-resident child record matches this identifier.</p>
        <a href="{{ $listingUrl }}" class="lml-hr-cc-nr__cancel-btn lml-focus-ring">Back to Non-Residents listing</a>
    </article>
@endif
