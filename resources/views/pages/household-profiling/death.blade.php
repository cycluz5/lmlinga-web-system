{{--
    Household Profiling — Death Information.
    DB-backed residents read the same DeathRequest / death_records row as Health Records.
    DemoCatalog-only members keep session preview and cannot write death_records.
--}}
@extends('layouts.dashboard')

@section('title', ($demoMember['name'] ?? 'Member') . ' — Death Information - LMLinga')

@section('content')
    @php
        use App\Support\DemoDeath;

        $deathMode = $deathMode ?? 'empty';
        $deathRecord = $deathRecord ?? null;
        $deathRequest = $deathRequest ?? null;
        $deathSource = $deathSource ?? null;
        $isDbResident = $deathSource === 'db' && $deathRequest !== null;
        $routeParams = [
            'householdNo' => $householdNo,
            'memberId' => $memberId,
        ];
        $healthRecordsDeathUrl = route('health-records.death.show', $routeParams);
        $recordCtaUrl = $deathSource === 'db'
            ? $healthRecordsDeathUrl
            : route('household-profiling.members.death.create', $routeParams);
        $statusMessage = session('status');
        $emptyRecord = '—';
        $memberName = (string) ($demoMember['name'] ?? 'Member');
        $memberSex = (string) ($demoMember['sex'] ?? '');
        $memberAge = $demoMember['age'] ?? null;
        $dateBirth = $demoMember
            ? lml_demo_member_display($demoMember, 'birthday')
            : $emptyRecord;
        $memberStatus = (string) ($demoMember['relationship_status'] ?? $emptyRecord);
        $sexBadgeClass = strtolower($memberSex) === 'female'
            ? 'lml-death__sex-badge--female'
            : 'lml-death__sex-badge--male';
        $backUrl = route('household-profiling.members.show', $routeParams);
        $backLabel = 'Back to Health Summary Records for '.$memberName;
        $causeValue = old('cause_of_death', $deathRecord['cause_of_death'] ?? '');
        $dateValue = old('date_of_death', $deathRecord['date_of_death'] ?? '');
        $certificate = is_array($deathRecord['certificate'] ?? null)
            ? $deathRecord['certificate']
            : null;
        $isFormMode = in_array($deathMode, ['create', 'edit'], true);
        $formAction = $deathMode === 'edit'
            ? route('household-profiling.members.death.update', $routeParams)
            : route('household-profiling.members.death.store', $routeParams);
        $formMethod = $deathMode === 'edit' ? 'PUT' : 'POST';
        $registryDisplay = $isDbResident ? $deathRequest->displayRegistryNo() : '';
        $certificateFileName = $isDbResident ? $deathRequest->displayCertificateFileName() : '';
    @endphp

    <div
        class="lml-death"
        data-lml-death
        data-lml-death-mode="{{ $deathMode }}"
        data-death-source="{{ $deathSource ?? '' }}"
        data-demo="{{ $deathSource === 'db' ? 'false' : 'true' }}"
        data-persistence="{{ $deathSource === 'db' ? 'database' : 'session-preview' }}"
        data-household-no="{{ $householdNo }}"
        data-member-id="{{ $memberId }}"
        @if ($demoMember)
            data-member-name="{{ $demoMember['name'] }}"
        @endif
    >
        @if ($statusMessage)
            <p class="lml-death__toast" role="status" data-death-toast>
                {{ $statusMessage }}
            </p>
        @endif

        @if (! $demoHousehold || ! $demoMember)
            <section class="lml-death__not-found" aria-labelledby="lml-death-nf-title">
                <h2 id="lml-death-nf-title" class="lml-death__not-found-title">
                    Member not found
                </h2>
                <p class="lml-death__not-found-message">
                    @if (! $demoHousehold)
                        No household matches <strong>{{ $householdNo }}</strong>.
                    @else
                        No member <strong>{{ $memberId }}</strong> belongs to household
                        <strong>{{ $householdNo }}</strong>.
                    @endif
                </p>
                <a
                    href="{{ $demoHousehold ? route('household-profiling.view', ['householdNo' => $householdNo]) : route('household-profiling.index') }}"
                    class="lml-death__not-found-link lml-focus-ring"
                >
                    {{ $demoHousehold ? 'Back to Household' : 'Return to Household List' }}
                </a>
            </section>
        @else
            <article class="lml-death__member-card" aria-labelledby="lml-death-member-name">
                <a
                    href="{{ $backUrl }}"
                    class="lml-death__back lml-focus-ring"
                    aria-label="{{ $backLabel }}"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </a>

                <div class="lml-death__member-profile">
                    <span class="lml-death__avatar" aria-hidden="true">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <div class="lml-death__member-identity">
                        <div class="lml-death__name-row">
                            <h2 id="lml-death-member-name" class="lml-death__member-name">
                                {{ $memberName }}
                            </h2>
                            @if ($memberSex !== '')
                                <span class="lml-death__sex-badge {{ $sexBadgeClass }}">
                                    {{ $memberSex }}
                                </span>
                            @endif
                        </div>
                        <dl class="lml-death__member-dl" data-death-member-meta>
                            <div class="lml-death__member-item">
                                <dt>Age:</dt>
                                <dd>{{ $memberAge !== null && $memberAge !== '' ? $memberAge : $emptyRecord }}</dd>
                            </div>
                            <div class="lml-death__member-item">
                                <dt>Birth Date:</dt>
                                <dd>{{ $dateBirth !== '' ? $dateBirth : $emptyRecord }}</dd>
                            </div>
                            <div class="lml-death__member-item">
                                <dt>Status:</dt>
                                <dd>{{ $memberStatus !== '' ? $memberStatus : $emptyRecord }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </article>

            @if ($deathMode === 'empty')
                <section
                    class="lml-death__empty-section"
                    aria-labelledby="lml-death-section-title"
                    data-death-empty
                >
                    <header class="lml-death__panel-head">
                        <div class="lml-death__panel-titles">
                            <h2 id="lml-death-section-title" class="lml-death__panel-title">
                                DEATH INFORMATION
                            </h2>
                            <p class="lml-death__panel-subtitle">
                                Track and monitor mortality of individual
                            </p>
                        </div>
                    </header>

                    <div class="lml-death__empty" data-death-no-record>
                        <span class="lml-death__empty-icon" aria-hidden="true">
                            <i class="bi bi-heart-pulse-fill"></i>
                        </span>
                        <p class="lml-death__empty-copy">No death record found.</p>
                        <p class="lml-death__empty-hint">
                            No death information has been recorded for this resident.
                        </p>
                        <a
                            href="{{ $recordCtaUrl }}"
                            class="lml-death__btn lml-death__btn--outline lml-focus-ring"
                            data-death-record-cta
                            @if ($deathSource !== 'db') data-hh-nav="death-write" @endif
                        >
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            <span>Record death information</span>
                        </a>
                    </div>
                </section>
            @elseif ($isFormMode)
                <section
                    class="lml-death__panel"
                    aria-labelledby="lml-death-section-title"
                    data-death-form-surface
                    data-death-mode="{{ $deathMode }}"
                >
                    <form
                        method="post"
                        action="{{ $formAction }}"
                        class="lml-death__form"
                        data-death-form
                        enctype="multipart/form-data"
                        novalidate
                    >
                        @csrf
                        @if ($formMethod === 'PUT')
                            @method('PUT')
                        @endif

                        <header class="lml-death__panel-head">
                            <div class="lml-death__panel-titles">
                                <h2 id="lml-death-section-title" class="lml-death__panel-title">
                                    DEATH INFORMATION
                                </h2>
                                <p class="lml-death__panel-subtitle">
                                    Track and monitor mortality of individual
                                </p>
                            </div>
                            <div class="lml-death__panel-controls">
                                <button
                                    type="submit"
                                    class="lml-death__btn lml-death__btn--primary lml-focus-ring"
                                    data-death-save
                                >
                                    SAVE
                                </button>
                            </div>
                        </header>

                        @if ($errors->any())
                            <div
                                class="lml-death__alert"
                                role="alert"
                                data-death-errors
                            >
                                <p class="lml-death__alert-title">Please review the highlighted fields.</p>
                                <ul class="lml-death__alert-list">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="lml-death__grid lml-death__grid--2">
                            <div class="lml-death__field">
                                <label for="lml-death-cause" class="lml-death__label">
                                    Cause of Death
                                </label>
                                <input
                                    type="text"
                                    id="lml-death-cause"
                                    name="cause_of_death"
                                    class="lml-death__input lml-focus-ring{{ $errors->has('cause_of_death') ? ' is-invalid' : '' }}"
                                    placeholder="Enter cause..."
                                    value="{{ $causeValue }}"
                                    maxlength="500"
                                    autocomplete="off"
                                    aria-invalid="{{ $errors->has('cause_of_death') ? 'true' : 'false' }}"
                                    @if ($errors->has('cause_of_death'))
                                        aria-describedby="lml-death-cause-error"
                                    @endif
                                    data-death-cause
                                >
                                @error('cause_of_death')
                                    <p id="lml-death-cause-error" class="lml-death__field-error">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <div class="lml-death__field">
                                <label for="lml-death-date" class="lml-death__label">
                                    Date of Death
                                </label>
                                <input
                                    type="date"
                                    id="lml-death-date"
                                    name="date_of_death"
                                    class="lml-death__input lml-focus-ring{{ $errors->has('date_of_death') ? ' is-invalid' : '' }}"
                                    value="{{ $dateValue }}"
                                    aria-invalid="{{ $errors->has('date_of_death') ? 'true' : 'false' }}"
                                    @if ($errors->has('date_of_death'))
                                        aria-describedby="lml-death-date-error"
                                    @endif
                                    data-death-date
                                >
                                @error('date_of_death')
                                    <p id="lml-death-date-error" class="lml-death__field-error">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>
                        </div>

                        <div class="lml-death__upload-block" data-death-upload>
                            <div class="lml-death__upload-copy">
                                <h3 class="lml-death__upload-title" id="lml-death-cert-heading">
                                    Death Certificate
                                </h3>
                                <p class="lml-death__upload-hint" id="lml-death-cert-hint">
                                    Upload the death certificate.
                                </p>
                            </div>

                            <div class="lml-death__upload-panel">
                                <span class="lml-death__upload-icon" aria-hidden="true">
                                    <i class="bi bi-arrow-up-circle-fill"></i>
                                </span>
                                <p class="lml-death__upload-lead">Upload New File</p>
                                <p class="lml-death__upload-formats" id="lml-death-cert-formats">
                                    PNG, JPG, PDF
                                </p>

                                <input
                                    type="file"
                                    id="lml-death-certificate"
                                    name="death_certificate"
                                    class="lml-death__file-input"
                                    accept=".png,.jpg,.jpeg,.pdf,image/png,image/jpeg,application/pdf"
                                    aria-labelledby="lml-death-cert-heading"
                                    aria-describedby="lml-death-cert-hint lml-death-cert-formats lml-death-cert-status"
                                    @if ($errors->has('death_certificate'))
                                        aria-invalid="true"
                                        aria-errormessage="lml-death-cert-error"
                                    @endif
                                    data-death-certificate-input
                                >

                                <label
                                    for="lml-death-certificate"
                                    class="lml-death__btn lml-death__btn--primary lml-death__choose-file lml-focus-ring"
                                    data-death-choose-file
                                >
                                    Choose File
                                </label>

                                <p
                                    id="lml-death-cert-status"
                                    class="lml-death__file-status"
                                    data-death-file-status
                                    @if (! $certificate) hidden @endif
                                >
                                    @if ($certificate)
                                        Selected for this session (preview only):
                                        <span data-death-file-name>{{ $certificate['original_name'] }}</span>
                                    @else
                                        No file selected.
                                    @endif
                                </p>

                                @error('death_certificate')
                                    <p id="lml-death-cert-error" class="lml-death__field-error" role="alert">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>

                            <p class="lml-death__preview-note">
                                Phase 1 preview: selected certificate metadata is kept in this browser session only.
                                The file is not permanently stored.
                            </p>
                        </div>
                    </form>
                </section>
            @else
                <section
                    class="lml-death__panel"
                    aria-labelledby="lml-death-section-title"
                    data-death-recorded
                >
                    <header class="lml-death__panel-head">
                        <div class="lml-death__panel-titles">
                            <h2 id="lml-death-section-title" class="lml-death__panel-title">
                                DEATH INFORMATION
                            </h2>
                            <p class="lml-death__panel-subtitle">
                                Track and monitor mortality of individual
                            </p>
                        </div>
                        <div class="lml-death__panel-controls">
                            @if ($isDbResident)
                                <a
                                    href="{{ $healthRecordsDeathUrl }}"
                                    class="lml-death__btn lml-death__btn--outline lml-focus-ring"
                                    data-death-open-health-records
                                >
                                    <span>Open in Health Records</span>
                                </a>
                            @else
                                <a
                                    href="{{ route('household-profiling.members.death.edit', $routeParams) }}"
                                    class="lml-death__btn lml-death__btn--primary lml-focus-ring"
                                    data-death-edit
                                    data-hh-nav="death-write"
                                >
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                    <span>EDIT</span>
                                </a>
                            @endif
                        </div>
                    </header>

                    @if ($isDbResident)
                        <div class="lml-death__grid lml-death__grid--2" data-death-record-fields>
                            <div class="lml-death__field">
                                <p class="lml-death__label" id="lml-death-view-cause-label">
                                    Cause of Death
                                </p>
                                <p
                                    class="lml-death__readonly"
                                    aria-labelledby="lml-death-view-cause-label"
                                    data-death-view-cause
                                >
                                    {{ $deathRequest->cause_of_death !== '' ? $deathRequest->cause_of_death : $emptyRecord }}
                                </p>
                            </div>
                            <div class="lml-death__field">
                                <p class="lml-death__label" id="lml-death-view-date-label">
                                    Date of Death
                                </p>
                                <p
                                    class="lml-death__readonly"
                                    aria-labelledby="lml-death-view-date-label"
                                    data-death-view-date
                                >
                                    {{ $deathRequest->formattedDateOfDeath() }}
                                </p>
                            </div>
                            <div class="lml-death__field">
                                <p class="lml-death__label" id="lml-death-view-registry-label">
                                    Registry Number
                                </p>
                                <p
                                    class="lml-death__readonly"
                                    aria-labelledby="lml-death-view-registry-label"
                                    data-death-view-registry
                                >
                                    {{ $registryDisplay !== '' ? $registryDisplay : $emptyRecord }}
                                </p>
                            </div>
                            <div class="lml-death__field">
                                <p class="lml-death__label" id="lml-death-view-status-label">
                                    Verification Status
                                </p>
                                <p
                                    class="lml-death__readonly"
                                    aria-labelledby="lml-death-view-status-label"
                                    data-death-view-status
                                >
                                    {{ $deathRequest->statusLabel() }}
                                </p>
                            </div>
                        </div>

                        <div class="lml-death__upload-block" data-death-certificate-view>
                            <div class="lml-death__upload-copy">
                                <h3 class="lml-death__upload-title">Death Certificate</h3>
                                <p class="lml-death__upload-hint">Official certificate stored with this death record.</p>
                            </div>

                            <div
                                class="lml-death__upload-panel lml-death__upload-panel--view"
                                @if ($certificateFileName !== '')
                                    data-death-certificate-selected="true"
                                @else
                                    data-death-certificate-selected="false"
                                @endif
                            >
                                <span class="lml-death__upload-icon" aria-hidden="true">
                                    <i class="bi bi-file-earmark-text"></i>
                                </span>
                                @if ($certificateFileName !== '')
                                    <p class="lml-death__upload-lead">Stored with the death record.</p>
                                    <a
                                        href="{{ route('health-records.death.certificate', $routeParams) }}"
                                        class="lml-death__file-name lml-focus-ring"
                                        data-death-view-file-name
                                        data-death-certificate-download
                                    >
                                        {{ $certificateFileName }}
                                    </a>
                                @else
                                    <p class="lml-death__upload-lead">No certificate on file</p>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="lml-death__grid lml-death__grid--2" data-death-record-fields>
                            <div class="lml-death__field">
                                <p class="lml-death__label" id="lml-death-view-cause-label">
                                    Cause of Death
                                </p>
                                <p
                                    class="lml-death__readonly"
                                    aria-labelledby="lml-death-view-cause-label"
                                    data-death-view-cause
                                >
                                    {{ ($deathRecord['cause_of_death'] ?? '') !== ''
                                        ? $deathRecord['cause_of_death']
                                        : $emptyRecord }}
                                </p>
                            </div>
                            <div class="lml-death__field">
                                <p class="lml-death__label" id="lml-death-view-date-label">
                                    Date of Death
                                </p>
                                <p
                                    class="lml-death__readonly"
                                    aria-labelledby="lml-death-view-date-label"
                                    data-death-view-date
                                >
                                    {{ DemoDeath::formatDateForDisplay($deathRecord['date_of_death'] ?? null) }}
                                </p>
                            </div>
                        </div>

                        <div class="lml-death__upload-block" data-death-certificate-view>
                            <div class="lml-death__upload-copy">
                                <h3 class="lml-death__upload-title">Death Certificate</h3>
                                <p class="lml-death__upload-hint">Upload the death certificate.</p>
                            </div>

                            <div
                                class="lml-death__upload-panel lml-death__upload-panel--view"
                                @if ($certificate)
                                    data-death-certificate-selected="true"
                                @else
                                    data-death-certificate-selected="false"
                                @endif
                            >
                                <span class="lml-death__upload-icon" aria-hidden="true">
                                    <i class="bi bi-file-earmark-text"></i>
                                </span>
                                @if ($certificate)
                                    <p class="lml-death__upload-lead">
                                        Selected file (session preview)
                                    </p>
                                    <p class="lml-death__file-name" data-death-view-file-name>
                                        {{ $certificate['original_name'] }}
                                    </p>
                                    <p class="lml-death__upload-formats">
                                        Not permanently uploaded — preview metadata only.
                                    </p>
                                @else
                                    <p class="lml-death__upload-lead">No certificate selected</p>
                                    <p class="lml-death__upload-formats">
                                        PNG, JPG, PDF can be added in Edit mode (session preview).
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endif
                </section>
            @endif

            @if ($deathSource !== 'db')
                <p class="lml-death__demo-note">
                    Demo preview for {{ $demoMember['id'] }} in household {{ $demoHousehold['householdNo'] }}.
                    Death Information uses session/preview persistence only and is not saved to a database.
                </p>
            @endif
        @endif
    </div>
@endsection
