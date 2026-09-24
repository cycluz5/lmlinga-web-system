{{--
    Health Records → Child Care → Deworming → Individual Record (UI-phase).
--}}
@extends('layouts.dashboard')

@section('title', 'Child Care | Deworming - LMLinga')

@section('content')
    @php
        $summaryUrl = route('health-records.child-care.deworming');
        $createUrl = isset($child['create_url'])
            ? $child['create_url']
            : $summaryUrl;
        $records = $records ?? [];
    @endphp

    <div
        class="lml-hr-cc-nr lml-hr-dw-record"
        data-lml-hr-dw-record
        data-lml-hr-dw-mode="show"
    >
        <div class="lml-hr-dw-record__toast" data-hr-dw-record-toast role="status" aria-live="polite" hidden></div>

        <a
            href="{{ $summaryUrl }}"
            class="lml-hr-cc-nr__page-back lml-focus-ring"
            aria-label="Back to Deworming summary"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-deworming-profile', ['child' => $child])

        @if ($child)
            <section class="lml-hr-cc-nr__history-panel" aria-labelledby="lml-hr-dw-history-title">
                <div class="lml-hr-cc-nr__history-head">
                    <h2 class="lml-hr-cc-nr__dash-title" id="lml-hr-dw-history-title">
                        <i class="bi bi-capsule" aria-hidden="true"></i>
                        <span>Deworming Record</span>
                    </h2>
                    <a
                        href="{{ $createUrl }}"
                        class="lml-hr-cc-nr__save-btn lml-focus-ring"
                        data-hr-dw-add-record
                    >
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        Add Record
                    </a>
                </div>

                @if ($records === [])
                    <div class="lml-hr-cc-nr__empty" role="status">
                        <p class="lml-hr-cc-nr__empty-title">No deworming records recorded for this child.</p>
                    </div>
                @else
                    <div class="lml-hr-cc-nr__table-scroll" tabindex="0">
                        <table class="lml-hr-cc-nr__table lml-hr-cc-nr__table--deworming">
                            <caption class="visually-hidden">Deworming records for {{ $child['full_name'] }}</caption>
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
                                @foreach ($records as $row)
                                    <tr>
                                        <td>{{ $row['year'] }}</td>
                                        <td>{{ $row['round'] }}</td>
                                        <td>{{ $row['se_status'] }}</td>
                                        <td>{{ $row['date_given_label'] }}</td>
                                        <td class="lml-hr-cc-nr__cell--remarks">{{ $row['remarks'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
@endsection
