{{--
    Child Nutrition — embedded Deworming: latest record + collapsed add form.
    Reuses deworming.store / deworming_records. Standalone deworming routes remain.
--}}
@php
    $dewormingChild = $dewormingChild ?? null;
    $dewormingRecords = is_array($dewormingRecords ?? null) ? $dewormingRecords : [];
    $dewormingCanAdd = (bool) ($dewormingCanAdd ?? false);
    $dewormingRoundOptions = $dewormingRoundOptions ?? \App\Support\HealthRecordsDeworming::roundOptions();
    $dewormingSeStatusOptions = $dewormingSeStatusOptions ?? \App\Support\HealthRecordsDeworming::seStatusOptions();
    $dewormingStoreUrl = $isDbPersisted && $dewormingCanAdd
        ? route('household-profiling.members.deworming.store', [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ])
        : null;
    // Records are already ordered newest-first (year desc, round desc);
    // the embedded summary only ever needs the latest one.
    $latestDeworming = $dewormingRecords[0] ?? null;
@endphp

<section
    class="lml-child-nut__deworming lml-hr-cc-nr lml-hr-dw-record"
    data-lml-child-nut-deworming
    data-lml-hr-dw-record
    data-lml-hr-dw-mode="nutrition"
    data-household-no="{{ $householdNo }}"
    data-member-id="{{ $memberId }}"
    aria-labelledby="lml-child-nut-deworming-title"
>
    <div class="lml-hr-cc-nr__history-head">
        <h2 class="lml-hr-cc-nr__dash-title" id="lml-child-nut-deworming-title">
            <i class="bi bi-capsule" aria-hidden="true"></i>
            <span>Deworming</span>
        </h2>
        @if ($dewormingCanAdd)
            <button
                type="button"
                class="lml-hr-cc-nr__save-btn lml-focus-ring"
                data-child-nut-deworming-toggle
                aria-expanded="false"
                aria-controls="lml-child-nut-deworming-panel"
            >
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                Add Deworming Record
            </button>
        @endif
    </div>

    @if (session('deworming_status'))
        <p class="lml-child-nut__notice" role="status">{{ session('deworming_status') }}</p>
    @endif

    @if ($latestDeworming === null)
        <div class="lml-hr-cc-nr__empty" role="status" data-child-nut-deworming-empty>
            <p class="lml-hr-cc-nr__empty-title">No deworming record has been recorded for this resident.</p>
        </div>
    @else
        <div class="lml-hr-cc-nr__table-scroll" tabindex="0">
            <table class="lml-hr-cc-nr__table lml-hr-cc-nr__table--deworming" data-child-nut-deworming-table>
                <caption class="visually-hidden">Latest deworming record for {{ $memberName }}</caption>
                <thead>
                    <tr>
                        <th scope="col">Year</th>
                        <th scope="col">Round</th>
                        <th scope="col">SE Status</th>
                        <th scope="col">Date Given</th>
                        <th scope="col">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <tr data-child-nut-deworming-row>
                        <td>{{ filled($latestDeworming['year']) ? $latestDeworming['year'] : '—' }}</td>
                        <td>{{ filled($latestDeworming['round']) ? $latestDeworming['round'] : '—' }}</td>
                        <td>{{ filled($latestDeworming['se_status']) ? $latestDeworming['se_status'] : '—' }}</td>
                        <td>{{ filled($latestDeworming['date_given_label']) ? $latestDeworming['date_given_label'] : '—' }}</td>
                        <td class="lml-hr-cc-nr__cell--remarks">{{ filled($latestDeworming['remarks']) ? $latestDeworming['remarks'] : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif

    @if ($dewormingCanAdd)
        <section
            id="lml-child-nut-deworming-panel"
            class="lml-hr-cc-nr__form-panel lml-hr-cc-nr__form-panel--measure"
            aria-labelledby="lml-child-nut-deworming-add-title"
            data-child-nut-deworming-panel
            hidden
        >
            <div class="lml-hr-cc-nr__measure-head">
                <h2 class="lml-hr-cc-nr__measure-title" id="lml-child-nut-deworming-add-title">
                    <i class="bi bi-journal-medical" aria-hidden="true"></i>
                    <span>Add Deworming Record</span>
                </h2>
            </div>

            <form
                class="lml-hr-cc-nr__form"
                data-hr-dw-deworming-form
                data-child-nut-deworming-form
                action="{{ $dewormingStoreUrl ?? '#' }}"
                method="post"
                novalidate
                data-hr-dw-return="{{ route('household-profiling.members.child-nutrition', [
                    'householdNo' => $householdNo,
                    'memberId' => $memberId,
                ]) }}"
                @unless ($isDbPersisted)
                    data-hr-dw-preview-save="Deworming record preview saved for this UI phase. Persistence is not yet implemented."
                @endunless
                data-persistence="{{ $persistenceSource }}"
                @if ($isDbPersisted)
                    @include('offline.form-attrs', [
                        'offlineOperation' => 'HEALTH_SERVICE_WRITE',
                        'offlineHealthAction' => 'deworming_store',
                        'offlineHouseholdNo' => $householdNo,
                        'offlineMemberNo' => $memberId,
                    ])
                @endif
            >
                @csrf

                <fieldset class="lml-hr-cc-nr__fieldset">
                    <legend class="lml-hr-cc-nr__section-title">ROUND INFORMATION</legend>

                    <div class="lml-hr-cc-nr__field-grid lml-hr-cc-nr__field-grid--4">
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-child-nut-dw-year">Year</label>
                            <input
                                id="lml-child-nut-dw-year"
                                name="year"
                                type="number"
                                inputmode="numeric"
                                min="2000"
                                max="2100"
                                step="1"
                                class="lml-hr-cc-nr__input lml-focus-ring"
                                placeholder="{{ now()->year }}"
                                value="{{ old('year') }}"
                            >
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-child-nut-dw-round">Deworming Round</label>
                            <select id="lml-child-nut-dw-round" name="round" class="lml-hr-cc-nr__input lml-focus-ring">
                                <option value="">Select</option>
                                @foreach ($dewormingRoundOptions as $option)
                                    <option value="{{ $option }}" @selected((string) old('round') === (string) $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-child-nut-dw-se">SE Status</label>
                            <select id="lml-child-nut-dw-se" name="se_status" class="lml-hr-cc-nr__input lml-focus-ring">
                                <option value="">Select</option>
                                @foreach ($dewormingSeStatusOptions as $option)
                                    <option value="{{ $option }}" @selected((string) old('se_status') === (string) $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="lml-hr-cc-nr__field">
                            <label for="lml-child-nut-dw-date">Date Given</label>
                            <input
                                id="lml-child-nut-dw-date"
                                name="date_given"
                                type="date"
                                class="lml-hr-cc-nr__input lml-focus-ring"
                                value="{{ old('date_given') }}"
                                max="{{ now()->toDateString() }}"
                            >
                        </div>
                    </div>

                    <div class="lml-hr-cc-nr__field">
                        <label for="lml-child-nut-dw-remarks">Remarks</label>
                        <textarea
                            id="lml-child-nut-dw-remarks"
                            name="remarks"
                            class="lml-hr-cc-nr__input lml-hr-cc-nr__textarea lml-focus-ring"
                            rows="3"
                        >{{ old('remarks') }}</textarea>
                    </div>
                </fieldset>

                <div class="lml-hr-cc-nr__form-actions">
                    <button
                        type="button"
                        class="lml-hr-cc-nr__cancel-btn lml-focus-ring"
                        data-child-nut-deworming-cancel
                    >
                        Cancel
                    </button>
                    <button type="submit" class="lml-hr-cc-nr__save-btn lml-focus-ring" data-hr-dw-save data-child-nut-deworming-save>
                        Save
                    </button>
                </div>
            </form>
        </section>
    @endif
</section>
