@php
    $rows = $rows ?? [];
    $filterAttr = $filterAttr ?? 'zone';
    $emptyHint = $emptyHint ?? 'Try adjusting search or filters.';
    $showClientView = (bool) ($showClientView ?? false);
@endphp

<div class="lml-hr-mc__table-card">
    <div
        class="lml-hr-mc__table-scroll"
        tabindex="0"
        aria-labelledby="lml-hr-mc-heading"
        aria-describedby="lml-hr-mc-desc"
    >
        <table class="lml-hr-mc__table @if ($showClientView) lml-hr-mc__table--with-actions @endif">
            <caption class="visually-hidden">
                Maternal care clients by full name, age group, LMP, gravida and parity,
                EDD, delivery type, trimester, and prenatal visits.
            </caption>
            <colgroup>
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
            </colgroup>
            <thead>
                <tr>
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
                </tr>
            </thead>
            <tbody data-hr-mc-tbody>
                @foreach ($rows as $row)
                    <tr
                        data-hr-mc-row
                        data-name="{{ strtolower($row['full_name']) }}"
                        data-{{ $filterAttr }}="{{ $row[$filterAttr] ?? '' }}"
                        data-year="{{ $row['year'] }}"
                        data-row-key="{{ $row['key'] }}"
                    >
                        <th scope="row" class="lml-hr-mc__cell lml-hr-mc__cell--name">
                            {{ $row['full_name'] }}
                        </th>
                        <td class="lml-hr-mc__cell">{{ $row['age_group'] }}</td>
                        <td class="lml-hr-mc__cell">{{ $row['lmp'] }}</td>
                        <td class="lml-hr-mc__cell">{{ $row['gravida_parity'] }}</td>
                        <td class="lml-hr-mc__cell">{{ $row['edd'] }}</td>
                        <td class="lml-hr-mc__cell">{{ $row['delivery_type'] }}</td>
                        <td class="lml-hr-mc__cell">{{ $row['trimester'] }}</td>
                        <td class="lml-hr-mc__cell">{{ $row['prenatal_visits'] }}</td>
                        @if ($showClientView)
                            <td class="lml-hr-mc__cell lml-hr-mc__cell--action">
                                <a
                                    href="{{ route('health-records.maternal.non-residents.show', ['clientKey' => $row['key']]) }}"
                                    class="lml-hr-mc__view-btn lml-focus-ring"
                                    data-hr-mc-view
                                    aria-label="View maternal record for {{ $row['full_name'] }}"
                                >
                                    View
                                </a>
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
        hidden
    >
        <div class="lml-hr-mc__empty-icon" aria-hidden="true">
            <i class="bi bi-search"></i>
        </div>
        <p class="lml-hr-mc__empty-title">
            No maternal care records match the selected filters.
        </p>
        <p class="lml-hr-mc__empty-hint">{{ $emptyHint }}</p>
    </div>
</div>
