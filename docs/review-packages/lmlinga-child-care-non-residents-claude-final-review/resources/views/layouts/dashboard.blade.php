{{--
    Authenticated dashboard shell shared by Admin, BHW, BNS, and BSPO.
    Child pages should @extends('layouts.dashboard') and fill @section('content').
--}}
@extends('layouts.app')

@section('body')
    @php
        /*
         | Shell role / topbar identity come only from App\Support\UiRole
         | (auth user → session → least-privileged shell fallback).
         | Page-level name/role props are ignored so the topbar stays consistent.
         | Optional ?role= is handled by PersistUiRole on dashboard route groups only.
         */
        $role = \App\Support\UiRole::shellRole();
        $active = \App\Support\UiRole::sidebarActiveKey($active ?? null);
        $pageTitle = $pageTitle ?? 'Dashboard';
        $pageSubtitle = $pageSubtitle
            ?? 'A central view that summarizes key information for quick monitoring and decision-making.';
        $userName = \App\Support\UiRole::displayName();
        $userRoleLabel = \App\Support\UiRole::label();
        $facilityLabel = $facilityLabel ?? 'Health Center';
    @endphp

    <div class="lml-dashboard" data-role="{{ $role }}">
        <x-lml.dashboard.sidebar
            :role="$role"
            :active="$active"
            :facility-label="$facilityLabel"
        />

        <div class="lml-dashboard__main">
            <x-lml.dashboard.topbar
                :title="$pageTitle"
                :subtitle="$pageSubtitle"
                :user-name="$userName"
                :user-role-label="$userRoleLabel"
            />

            <main class="lml-dashboard__content" id="lml-dashboard-content">
                @yield('content')
            </main>
        </div>
    </div>
@endsection
