{{--
    Dashboard topbar — page title, status, and user area.
--}}
@props([
    'title' => 'Dashboard',
    'subtitle' => null,
    'userName' => 'User',
    'userRoleLabel' => null,
    'userPhotoUrl' => null,
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
        <div class="lml-topbar__user">
            <div class="lml-topbar__user-meta">
                <a
                    href="{{ route('profile.show') }}"
                    class="lml-topbar__profile-link lml-focus-ring"
                    data-lml-user-profile
                    aria-label="Open user profile for {{ $userName }}"
                >
                    <p class="lml-topbar__user-name mb-0">
                        {{ $userName }}
                    </p>
                </a>
                <form method="post" action="{{ route('logout') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="lml-topbar__user-logout border-0 bg-transparent p-0">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                        <span>Log Out</span>
                    </button>
                </form>
            </div>
            <a
                href="{{ route('profile.show') }}"
                class="lml-topbar__avatar-link lml-focus-ring"
                data-lml-user-profile-avatar
                aria-label="Open user profile"
            >
                <div class="lml-topbar__avatar" aria-hidden="true">
                    @if ($userPhotoUrl)
                        <img
                            src="{{ $userPhotoUrl }}"
                            alt=""
                            class="lml-topbar__avatar-img"
                            data-lml-topbar-avatar
                            onerror="this.hidden=true;this.setAttribute('hidden','');var n=this.nextElementSibling;if(n){n.hidden=false;n.removeAttribute('hidden');}"
                        >
                        <i class="bi bi-person-fill lml-topbar__avatar-fallback" hidden></i>
                    @else
                        <i class="bi bi-person-fill"></i>
                    @endif
                </div>
            </a>
        </div>

        <span
            class="lml-topbar__status lml-topbar__status--checking"
            data-lml-offline-topbar
            data-connectivity="checking"
            aria-live="polite"
            aria-label="Connection status: checking"
        >
            <i class="bi bi-arrow-repeat" data-lml-offline-topbar-icon aria-hidden="true"></i>
            <span data-lml-offline-topbar-label>Checking</span>
        </span>
        <span
            class="lml-topbar__pending"
            data-lml-offline-pending
            data-pending-count="0"
            hidden
        >
            <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
            <span data-lml-offline-pending-label>0 waiting</span>
        </span>
    </div>
</header>
