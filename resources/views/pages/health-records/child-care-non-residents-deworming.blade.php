{{-- Health Records → Child Care → Non-Residents Deworming Record (UI-phase). --}}
@extends('layouts.dashboard')

@section('title', 'Deworming Record — Child Care - LMLinga')

@section('content')
    @php
        $listingUrl = route('health-records.child-care.non-residents.index');
        $showUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.show', ['childKey' => $child['key']])
            : $listingUrl;
        $createUrl = isset($child['key'])
            ? route('health-records.child-care.non-residents.deworming.create', ['childKey' => $child['key']])
            : $listingUrl;
        $records = $records ?? [];
    @endphp

    <div class="lml-hr-cc-nr" data-lml-hr-cc-nr data-lml-hr-cc-nr-mode="deworming">
        <div class="lml-hr-cc-nr__toast" data-hr-cc-nr-toast role="status" aria-live="polite" hidden></div>

        <a href="{{ $showUrl }}" class="lml-hr-cc-nr__page-back lml-focus-ring" aria-label="Back to child record">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </a>

        @include('pages.health-records.partials.child-care-non-residents-profile', ['child' => $child])

        @if ($child)
            <section class="lml-hr-cc-nr__history-panel" aria-labelledby="lml-hr-cc-nr-deworming-title">
                <div class="lml-hr-cc-nr__history-head">
                    <h3 class="lml-hr-cc-nr__dash-title" id="lml-hr-cc-nr-deworming-title">Deworming Record</h3>
                    <a
                        href="{{ $createUrl }}"
                        class="lml-hr-cc-nr__save-btn lml-focus-ring"
                        data-hr-cc-nr-add-deworming
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
                            <colgroup>
                                <col class="lml-hr-cc-nr__dw-col lml-hr-cc-nr__dw-col--year">
                                <col class="lml-hr-cc-nr__dw-col lml-hr-cc-nr__dw-col--round">
                                <col class="lml-hr-cc-nr__dw-col lml-hr-cc-nr__dw-col--se">
                                <col class="lml-hr-cc-nr__dw-col lml-hr-cc-nr__dw-col--date">
                                <col class="lml-hr-cc-nr__dw-col lml-hr-cc-nr__dw-col--remarks">
                            </colgroup>
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
                                        <td>{{ $row['year'] !== '' ? $row['year'] : '—' }}</td>
                                        <td>{{ $row['round'] !== '' ? $row['round'] : '—' }}</td>
                                        <td>{{ $row['se_status'] !== '' ? $row['se_status'] : '—' }}</td>
                                        <td>{{ $row['date_given'] !== '' ? $row['date_given_label'] : '—' }}</td>
                                        <td class="lml-hr-cc-nr__cell--remarks">{{ $row['remarks'] !== '' ? $row['remarks'] : '—' }}</td>
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
