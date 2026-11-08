{{--
    Resident account row — User Management Residents tab.
--}}
@props([
    'id' => '',
    'name' => '',
    'firstName' => '',
    'middleName' => '',
    'lastName' => '',
    'zone' => '',
    'email' => '',
])

@php
    $residentId = $id !== '' ? $id : 'ra-'.uniqid();
@endphp

<tr
    {{ $attributes->class(['lml-ra-table__row']) }}
    data-resident-row
    data-resident-id="{{ $residentId }}"
    data-resident-name="{{ $name }}"
    data-resident-first="{{ $firstName }}"
    data-resident-middle="{{ $middleName }}"
    data-resident-last="{{ $lastName }}"
    data-resident-zone="{{ $zone }}"
    data-resident-email="{{ $email }}"
>
    <td class="lml-ra-table__cell lml-ra-table__cell--name" data-label="Name">
        <span class="lml-ra-table__name">{{ $name }}</span>
    </td>
    <td class="lml-ra-table__cell lml-ra-table__cell--zone" data-label="Zone">{{ $zone }}</td>
    <td class="lml-ra-table__cell lml-ra-table__cell--email" data-label="Email Address">{{ $email }}</td>
    <td class="lml-ra-table__cell lml-ra-table__cell--actions" data-label="Actions">
        <div class="lml-ra-table__actions">
            <a
                href="{{ route('user-management.residents.edit', ['id' => $residentId]) }}"
                class="lml-ra-btn lml-ra-btn--edit lml-focus-ring"
                aria-label="Edit resident account for {{ $name }}"
            >
                <i class="bi bi-pencil" aria-hidden="true"></i>
                <span>Edit</span>
            </a>
            <button
                type="button"
                class="lml-ra-btn lml-ra-btn--delete lml-focus-ring"
                data-resident-delete
                data-resident-delete-id="{{ \App\Support\OpaqueId::forUrl('t', (string) $residentId) }}"
                data-resident-delete-name="{{ $name }}"
                aria-label="Delete resident account for {{ $name }}"
            >
                <i class="bi bi-trash" aria-hidden="true"></i>
                <span>Delete</span>
            </button>
        </div>
    </td>
</tr>
