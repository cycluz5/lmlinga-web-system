{{--
    Household request table row — Admin Household Requests (UI only).
--}}
@props([
    'id' => '',
    'name' => '',
    'firstName' => '',
    'middleName' => '',
    'lastName' => '',
    'zone' => '',
    'status' => 'Rejected',
])

@php
    $requestId = $id !== '' ? $id : 'req-'.uniqid();
    $isApproved = strtolower((string) $status) === 'approved';
@endphp

<tr
    {{ $attributes->class(['lml-hr-table__row']) }}
    data-hr-row
    data-hr-id="{{ $requestId }}"
    data-hr-name="{{ $name }}"
    data-hr-first="{{ $firstName }}"
    data-hr-middle="{{ $middleName }}"
    data-hr-last="{{ $lastName }}"
    data-hr-zone="{{ $zone }}"
    data-hr-status="{{ $status }}"
>
    <td class="lml-hr-table__cell lml-hr-table__cell--name" data-label="Name">
        <span class="lml-hr-table__name">{{ $name }}</span>
    </td>
    <td class="lml-hr-table__cell lml-hr-table__cell--zone" data-label="Zone">
        {{ $zone }}
    </td>
    <td class="lml-hr-table__cell lml-hr-table__cell--status" data-label="Status">
        <span
            @class([
                'lml-hr-table__status',
                'lml-hr-table__status--approved' => $isApproved,
                'lml-hr-table__status--rejected' => ! $isApproved,
            ])
        >
            {{ $status }}
        </span>
    </td>
    <td class="lml-hr-table__cell lml-hr-table__cell--actions" data-label="View">
        <a
            href="{{ route('household-requests.view', ['id' => $requestId]) }}"
            class="lml-hr-table__view-btn lml-focus-ring"
            aria-label="View household request for {{ $name }}"
        >
            <i class="bi bi-eye" aria-hidden="true"></i>
            <span>View</span>
        </a>
    </td>
</tr>
