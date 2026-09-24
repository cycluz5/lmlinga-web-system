{{--
    Health Worker card — User Management grid item.
    Overflow View/Edit navigate to dedicated pages.
    Deactivate is shown only for eligible active non-admin accounts.
    Delete is shown for eligible active or inactive non-admin accounts.
--}}
@props([
    'id' => '',
    'name' => '',
    'role' => '',
    'zone' => '',
    'status' => '',
    'photo' => null,
])

@php
    $workerId = trim((string) $id);
    $menuId = 'lml-hw-menu-'.($workerId !== '' ? $workerId : 'unknown');
    $displayName = trim((string) $name);
    $displayName = $displayName !== '' ? $displayName : '—';
    $displayRole = trim((string) $role);
    $displayRole = $displayRole !== '' ? $displayRole : '—';
    $displayZone = trim((string) $zone);
    $displayZone = $displayZone !== '' ? $displayZone : '—';
    $statusNormalized = \App\Support\StaffAccountStatus::normalize(is_string($status) ? $status : null);
    $statusLabel = $statusNormalized ?? '—';
    $isActive = $statusNormalized === \App\Support\StaffAccountStatus::ACTIVE;
    $isInactive = $statusNormalized === \App\Support\StaffAccountStatus::INACTIVE;
    $numericWorkerId = preg_match('/^[0-9]+$/', $workerId) === 1 ? $workerId : '';
    $roleMachine = \App\Support\StaffRole::normalize(is_string($role) ? $role : null);
    $authId = auth()->id();
    $canDeactivate = $numericWorkerId !== ''
        && $isActive
        && $roleMachine !== \App\Support\StaffRole::ADMIN
        && $authId !== null
        && (int) $numericWorkerId !== (int) $authId;
    $canActivate = $numericWorkerId !== ''
        && $isInactive
        && $roleMachine !== \App\Support\StaffRole::ADMIN;
    $canDelete = $numericWorkerId !== ''
        && $roleMachine !== \App\Support\StaffRole::ADMIN
        && $authId !== null
        && (int) $numericWorkerId !== (int) $authId;
    $workerViewUrl = $numericWorkerId !== ''
        ? route('user-management.health-workers.view', ['id' => $numericWorkerId])
        : '';
    $workerEditUrl = $numericWorkerId !== ''
        ? route('user-management.health-workers.edit', ['id' => $numericWorkerId])
        : '';
    $workerAvatarUrl = is_string($photo) && trim((string) $photo) !== '' ? trim((string) $photo) : '';
@endphp

<article
    {{ $attributes->class(['lml-hw-card']) }}
    role="listitem"
    data-hw-card
    data-hw-id="{{ $workerId }}"
    @if ($numericWorkerId !== '')
        data-um-worker-id="{{ $numericWorkerId }}"
        data-um-worker-view-url="{{ $workerViewUrl }}"
        data-um-worker-edit-url="{{ $workerEditUrl }}"
        @if ($workerAvatarUrl !== '')
            data-um-worker-avatar-url="{{ $workerAvatarUrl }}"
        @endif
    @endif
    data-hw-name="{{ $displayName }}"
    data-hw-role="{{ $displayRole }}"
    data-hw-zone="{{ $displayZone }}"
    data-hw-status="{{ $statusLabel }}"
>
    <div class="lml-hw-card__top">
        <div class="lml-hw-card__identity">
            <div class="lml-hw-card__avatar" aria-hidden="true">
                @if ($photo)
                    <img src="{{ $photo }}" alt="" class="lml-hw-card__avatar-img" data-um-avatar>
                    <i class="bi bi-person-fill" data-um-avatar-fallback hidden></i>
                @else
                    <i class="bi bi-person-fill"></i>
                @endif
            </div>

            <div class="lml-hw-card__meta">
                <h3 class="lml-hw-card__name">{{ $displayName }}</h3>
                <p class="lml-hw-card__role">{{ $displayRole }}</p>
            </div>
        </div>

        <div class="lml-hw-card__menu" data-hw-menu>
            <button
                type="button"
                class="lml-hw-card__menu-btn lml-focus-ring"
                data-hw-menu-toggle
                aria-haspopup="menu"
                aria-expanded="false"
                aria-controls="{{ $menuId }}"
                aria-label="Actions for {{ $displayName }}"
            >
                <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
            </button>

            <ul
                id="{{ $menuId }}"
                class="lml-hw-card__menu-list"
                role="menu"
                hidden
                data-hw-menu-list
            >
                <li role="none">
                    <a
                        href="{{ route('user-management.health-workers.view', ['id' => $workerId !== '' ? $workerId : '0']) }}"
                        class="lml-hw-card__menu-item"
                        role="menuitem"
                        tabindex="-1"
                        data-hw-action="view"
                        data-um-nav="view-worker"
                        data-hw-id="{{ $workerId }}"
                        data-hw-worker="{{ $displayName }}"
                    >
                        <i class="bi bi-eye" aria-hidden="true"></i>
                        <span>View</span>
                    </a>
                </li>
                <li role="none">
                    <a
                        href="{{ route('user-management.health-workers.edit', ['id' => $workerId !== '' ? $workerId : '0']) }}"
                        class="lml-hw-card__menu-item"
                        role="menuitem"
                        tabindex="-1"
                        data-hw-action="edit"
                        data-um-nav="edit-worker"
                        data-hw-id="{{ $workerId }}"
                        data-hw-worker="{{ $displayName }}"
                    >
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                        <span>Edit</span>
                    </a>
                </li>
                @if ($canDeactivate)
                    <li role="none">
                        <button
                            type="button"
                            class="lml-hw-card__menu-item lml-hw-card__menu-item--danger"
                            role="menuitem"
                            tabindex="-1"
                            data-hw-action="deactivate"
                            data-hw-deactivate
                            data-hw-deactivate-id="{{ \App\Support\OpaqueId::forUrl('w', (string) $numericWorkerId) }}"
                            data-hw-deactivate-name="{{ $displayName }}"
                        >
                            <i class="bi bi-person-x" aria-hidden="true"></i>
                            <span>Deactivate Account</span>
                        </button>
                    </li>
                @endif
                @if ($canActivate)
                    <li role="none">
                        <form
                            method="post"
                            action="{{ route('user-management.health-workers.activate', ['id' => $numericWorkerId]) }}"
                        >
                            @csrf
                            <button
                                type="submit"
                                class="lml-hw-card__menu-item lml-hw-card__menu-item--activate"
                                role="menuitem"
                                tabindex="-1"
                                data-hw-action="activate"
                                data-hw-activate
                                data-hw-activate-id="{{ $numericWorkerId }}"
                            >
                                <i class="bi bi-person-check" aria-hidden="true"></i>
                                <span>Activate Account</span>
                            </button>
                        </form>
                    </li>
                @endif
                @if ($canDelete)
                    <li role="none">
                        <button
                            type="button"
                            class="lml-hw-card__menu-item lml-hw-card__menu-item--danger"
                            role="menuitem"
                            tabindex="-1"
                            data-hw-action="delete"
                            data-hw-delete
                            data-hw-delete-id="{{ \App\Support\OpaqueId::forUrl('w', (string) $numericWorkerId) }}"
                            data-hw-delete-name="{{ $displayName }}"
                        >
                            <i class="bi bi-trash" aria-hidden="true"></i>
                            <span>Delete Account</span>
                        </button>
                    </li>
                @endif
            </ul>
        </div>
    </div>

    <dl class="lml-hw-card__details">
        <div class="lml-hw-card__row">
            <dt>Assigned</dt>
            <dd>{{ $displayZone }}</dd>
        </div>
        <div class="lml-hw-card__row">
            <dt>Status</dt>
            <dd @class([
                'lml-hw-card__status',
                'lml-hw-card__status--active' => $isActive,
                'lml-hw-card__status--inactive' => $isInactive,
            ])>
                {{ $statusLabel }}
            </dd>
        </div>
    </dl>

    <div class="lml-hw-card__actions">
        <a
            href="{{ route('user-management.health-workers.edit', ['id' => $workerId !== '' ? $workerId : '0']) }}"
            class="lml-hw-card__action-btn lml-hw-card__action-btn--edit lml-focus-ring"
            data-hw-action="edit"
            data-um-nav="edit-worker"
            data-hw-id="{{ $workerId }}"
            data-hw-worker="{{ $displayName }}"
        >
            Edit
        </a>
        @if ($canActivate)
            <form
                method="post"
                action="{{ route('user-management.health-workers.activate', ['id' => $numericWorkerId]) }}"
                class="lml-hw-card__actions-form"
            >
                @csrf
                <button
                    type="submit"
                    class="lml-hw-card__action-btn lml-hw-card__action-btn--activate lml-focus-ring"
                    data-hw-action="activate"
                    data-hw-activate
                    data-hw-activate-id="{{ $numericWorkerId }}"
                >
                    Activate
                </button>
            </form>
        @else
            <a
                href="{{ route('user-management.health-workers.view', ['id' => $workerId !== '' ? $workerId : '0']) }}"
                class="lml-hw-card__action-btn lml-hw-card__action-btn--view lml-focus-ring"
                data-hw-action="view"
                data-um-nav="view-worker"
                data-hw-id="{{ $workerId }}"
                data-hw-worker="{{ $displayName }}"
            >
                View
            </a>
        @endif
    </div>
</article>
