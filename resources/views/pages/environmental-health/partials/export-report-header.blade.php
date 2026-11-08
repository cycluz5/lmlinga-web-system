{{--
    Shared Environmental Health report letterhead (PDF / printable HTML).
    Logo + office name on one row; program banner beneath.
--}}
@php
    /** @var array<string, mixed> $reportHeader */
    $logo = (string) ($reportHeader['logo_data_uri'] ?? '');
    $banner = (string) ($reportHeader['program_banner'] ?? \App\Support\EnvironmentalHealthReportHeader::DEFAULT_PROGRAM_BANNER);
    $office = (string) ($reportHeader['office_name'] ?? \App\Support\EnvironmentalHealthReportHeader::OFFICE_NAME);
@endphp
<header class="eh-report-header" aria-label="Environmental Health report letterhead">
    <div class="eh-report-header__brand">
        @if ($logo !== '')
            <img
                class="eh-report-header__logo"
                src="{{ $logo }}"
                width="64"
                height="64"
                alt="La Medalla, Iriga City official seal"
            >
        @endif
        <p class="eh-report-header__office">{{ $office }}</p>
    </div>

    <p class="eh-report-header__banner" role="note">{{ $banner }}</p>
</header>
