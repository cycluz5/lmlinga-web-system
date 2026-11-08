@php
    $rows = $rows ?? [];
    $filterAttr = $filterAttr ?? 'zone';
    $emptyHint = $emptyHint ?? 'Try adjusting search or filters.';
    $showClientView = (bool) ($showClientView ?? false);
    $hasRecords = (bool) ($hasRecords ?? count($rows) > 0);
    $tableVariant = $tableVariant ?? 'full';
    $isCompact = $tableVariant === 'compact';
@endphp

<div class="lml-hr-mc__table-card">
    <div
        class="lml-hr-mc__table-scroll"
        tabindex="0"
        aria-labelledby="lml-hr-mc-heading"
        aria-describedby="lml-hr-mc-desc"
        @if (! $hasRecords) hidden @endif
    >
        <table class="lml-hr-mc__table @if ($showClientView) lml-hr-mc__table--with-actions @endif">
            <caption class="visually-hidden">
                @if ($isCompact)
                    Maternal care clients by name, age, LMP, gravida and parity, EDD, and action.
                @else
                    Maternal care clients by full name, age group, LMP, gravida and parity,
                    EDD, delivery type, trimester, and prenatal visits.
                @endif
            </caption>
            <colgroup>
                @if ($isCompact)
                    <col class="lml-hr-mc__col lml-hr-mc__col--name">
                    <col class="lml-hr-mc__col lml-hr-mc__col--age-num">
                    <col class="lml-hr-mc__col lml-hr-mc__col--date">
                    <col class="lml-hr-mc__col lml-hr-mc__col--gp">
                    <col class="lml-hr-mc__col lml-hr-mc__col--date">
                    @if ($showClientView)
                        <col class="lml-hr-mc__col lml-hr-mc__col--action">
                    @endif
                @else
                    <col class="lml-hr-mc__col lml-hr-mc__col--name">
                    <col class="lml-hr-mc__col lml-hr-mc__col--age">
                    <col class="lml-hr-mc__col lml-hr-mc__col--date">
                    <col class="lml-hr-mc__col lml-hr-mc__col--gp">
                    <col class="lml-hr-mc__col lml-hr-mc__col--date">
                    <col class="lml-hr-mc__col lml-hr-mc__col--delivery">
                    <col class="lml-hr-mc__col lml-hr-mc__col--tri">
                    <col class="lml-hr-mc__col lml-hr-mc__col--visits">
                    @if ($showClientView)
                        <col class="lml-hr-mc__col lml-hr-mc__col--action">
                    @endif
                @endif
            </colgroup>
            <thead>
                <tr>
                    @if ($isCompact)
                        <th scope="col">Name</th>
                        <th scope="col">Age</th>
                        <th scope="col">LMP</th>
                        <th scope="col">Gravida / Parity</th>
                        <th scope="col">EDD</th>
                        @if ($showClientView)
                            <th scope="col">Action</th>
                        @endif
                    @else
                        <th scope="col">Full Name</th>
                        <th scope="col">Age Group</th>
                        <th scope="col">LMP</th>
                        <th scope="col">Gravida / Parity</th>
                        <th scope="col">EDD</th>
                        <th scope="col">Delivery Type</th>
                        <th scope="col">Trimester</th>
                        <th scope="col">Prenatal Visits</th>
                        @if ($showClientView)
                            <th scope="col">Action</th>
                        @endif
                    @endif
                </tr>
            </thead>
            <tbody data-hr-mc-tbody>
                @foreach ($rows as $row)
                    @php
                        $viewUrl = (string) ($row['view_url'] ?? '');
                        if ($viewUrl === '' && $showClientView && ($row['population'] ?? '') === 'non-resident') {
                            $viewUrl = route('health-records.maternal.non-residents.show', ['clientKey' => $row['key']]);
                        }
                    @endphp
                    <tr
                        data-hr-mc-row
                        data-name="{{ strtolower($row['full_name']) }}"
                        data-{{ $filterAttr }}="{{ $row[$filterAttr] ?? '' }}"
                        data-year="{{ $row['year'] }}"
                        data-month="{{ $row['month'] ?? '' }}"
                        data-delivered-this-month="{{ ! empty($row['is_delivered_this_month']) ? '1' : '0' }}"
                        data-row-key="{{ $row['key'] }}"
                    >
                        <th scope="row" class="lml-hr-mc__cell lml-hr-mc__cell--name">
                            {{ $row['full_name'] }}
                        </th>
                        @if ($isCompact)
                            <td class="lml-hr-mc__cell">{{ $row['age'] ?? $row['age_group'] ?? '—' }}</td>
                            <td class="lml-hr-mc__cell">{{ $row['lmp'] }}</td>
                            <td class="lml-hr-mc__cell">{{ $row['gravida_parity'] }}</td>
                            <td class="lml-hr-mc__cell">{{ $row['edd'] }}</td>
                        @else
                            <td class="lml-hr-mc__cell">{{ $row['age_group'] }}</td>
                            <td class="lml-hr-mc__cell">{{ $row['lmp'] }}</td>
                            <td class="lml-hr-mc__cell">{{ $row['gravida_parity'] }}</td>
                            <td class="lml-hr-mc__cell">{{ $row['edd'] }}</td>
                            <td class="lml-hr-mc__cell">{{ $row['delivery_type'] }}</td>
                            <td class="lml-hr-mc__cell">{{ $row['trimester'] }}</td>
                            <td class="lml-hr-mc__cell">{{ $row['prenatal_visits'] }}</td>
                        @endif
                        @if ($showClientView)
                            <td class="lml-hr-mc__cell lml-hr-mc__cell--action">
                                @if ($viewUrl !== '')
                                    <a
                                        href="{{ $viewUrl }}"
                                        class="lml-hr-mc__view-btn lml-focus-ring"
                                        data-hr-mc-view
                                        aria-label="View maternal record for {{ $row['full_name'] }}"
                                    >
                                        View
                                    </a>
                                @else
                                    <span class="lml-hr-mc__view-btn lml-hr-mc__view-btn--disabled" aria-disabled="true">
                                        View
                                    </span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div
        class="lml-hr-mc__empty"
        data-hr-mc-empty
        role="status"
        @if ($hasRecords) hidden @endif
    >
        <div class="lml-hr-mc__empty-icon" aria-hidden="true">
            <i class="bi bi-{{ $hasRecords ? 'search' : 'inbox' }}"></i>
        </div>
        <p
            class="lml-hr-mc__empty-title"
            data-hr-mc-empty-no-records
            @if ($hasRecords) hidden @endif
        >
            No maternal care records are available.
        </p>
        <p
            class="lml-hr-mc__empty-title"
            data-hr-mc-empty-filtered
            hidden
        >
            No maternal care records match the selected filters.
        </p>
        <p
            class="lml-hr-mc__empty-hint"
            data-hr-mc-empty-hint
            @if (! $hasRecords) hidden @endif
        >
            {{ $emptyHint }}
        </p>
    </div>
</div>
