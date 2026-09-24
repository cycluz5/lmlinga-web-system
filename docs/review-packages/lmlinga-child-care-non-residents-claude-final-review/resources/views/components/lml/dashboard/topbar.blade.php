{{--
    Dashboard topbar — page title, status, notifications, and user area.
--}}
@props([
    'title' => 'Dashboard',
    'subtitle' => null,
    'userName' => 'User',
    'userRoleLabel' => null,
    'online' => true,
])

<header class="lml-topbar">
    <div class="lml-topbar__start">
        <button
            class="lml-topbar__toggle btn d-lg-none lml-focus-ring"
            type="button"
            data-bs-toggle="offcanvas"
            data-bs-target="#lmlDashboardSidebar"
            aria-controls="lmlDashboardSidebar"
            aria-label="Open navigation menu"
        >
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>

        <div class="lml-topbar__titles">
            <h1 class="lml-topbar__title">{{ $title }}</h1>
            @if ($subtitle)
                <p class="lml-topbar__subtitle mb-0">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    <div class="lml-topbar__end">
        <button type="button" class="lml-topbar__icon-btn lml-focus-ring" aria-label="Notifications">
            <i class="bi bi-bell" aria-hidden="true"></i>
        </button>

        <div class="lml-topbar__user">
            <div class="lml-topbar__user-meta">
                <p class="lml-topbar__user-name mb-0">
                    {{ $userName }}{{ $userRoleLabel ? ' ' . $userRoleLabel : '' }}
                </p>
                <a href="{{ route('login') }}" class="lml-topbar__user-logout">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    <span>Log Out</span>
                </a>
            </div>
            <div class="lml-topbar__avatar" aria-hidden="true">
                <i class="bi bi-person-fill"></i>
            </div>
        </div>

        <span @class(['lml-topbar__status', 'lml-topbar__status--online' => $online])>
            <i class="bi bi-wifi" aria-hidden="true"></i>
            <span>{{ $online ? 'Online' : 'Offline' }}</span>
        </span>
    </div>
</header>
