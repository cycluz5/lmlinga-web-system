{{--
    Edit Resident — Admin User Management (demo session persistence).
--}}
@extends('layouts.dashboard')

@section('title', 'Edit Resident - LMLinga')

@php
    $resident = $demoResident ?? null;
    $zoneOptions = [
        'Zone 1' => 'Zone 1',
        'Zone 2' => 'Zone 2',
        'Zone 3' => 'Zone 3',
        'Zone 4' => 'Zone 4',
        'Zone 5' => 'Zone 5',
    ];
@endphp

@section('content')
    <div class="lml-ra-edit">
        <div class="lml-ra-edit__toolbar">
            <a
                href="{{ route('user-management.index', ['tab' => 'residents']) }}"
                class="lml-ra-view__back lml-focus-ring"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to Residents</span>
            </a>
        </div>

        <article class="lml-ra-edit__card" aria-labelledby="lml-ra-edit-title">
            @if (! $resident)
                <h1 id="lml-ra-edit-title" class="lml-ra-view__title">Resident not found</h1>
                <p class="lml-ra-view__text">
                    The selected resident account could not be loaded for editing.
                </p>
                <div class="lml-ra-view__actions">
                    <a
                        href="{{ route('user-management.index', ['tab' => 'residents']) }}"
                        class="lml-ra-view__btn lml-ra-view__btn--back lml-focus-ring"
                    >
                        Back to Residents
                    </a>
                </div>
            @else
                <header class="lml-ra-view__header">
                    <span class="lml-ra-view__header-icon" aria-hidden="true">
                        <i class="bi bi-pencil-square"></i>
                    </span>
                    <div>
                        <h1 id="lml-ra-edit-title" class="lml-ra-view__title">
                            Edit Resident Information
                        </h1>
                        <p class="lml-ra-view__subtitle">
                            Update account details for {{ $resident['name'] }}.
                        </p>
                    </div>
                </header>

                <form
                    method="post"
                    action="{{ route('user-management.residents.update', ['id' => $resident['id']]) }}"
                    class="lml-ra-edit__form"
                    novalidate
                >
                    @csrf
                    @method('PUT')

                    <div class="lml-ra-edit__grid">
                        <x-lml.form-group name="first_name" label="First Name" :required="true">
                            <x-lml.text-input
                                name="first_name"
                                :value="$resident['first_name'] ?? ''"
                                required
                                autocomplete="given-name"
                            />
                        </x-lml.form-group>

                        <x-lml.form-group name="middle_name" label="Middle Name">
                            <x-lml.text-input
                                name="middle_name"
                                :value="$resident['middle_name'] ?? ''"
                                autocomplete="additional-name"
                            />
                        </x-lml.form-group>

                        <x-lml.form-group name="last_name" label="Last Name" :required="true">
                            <x-lml.text-input
                                name="last_name"
                                :value="$resident['last_name'] ?? ''"
                                required
                                autocomplete="family-name"
                            />
                        </x-lml.form-group>

                        <x-lml.form-group name="zone" label="Zone" :required="true">
                            <x-lml.select-input
                                name="zone"
                                :options="$zoneOptions"
                                :selected="$resident['zone'] ?? ''"
                                placeholder="Select zone"
                                required
                            />
                        </x-lml.form-group>

                        <x-lml.form-group name="email" label="Email Address" :required="true" class="lml-ra-edit__field--span">
                            <x-lml.text-input
                                type="email"
                                name="email"
                                :value="$resident['email'] ?? ''"
                                required
                                autocomplete="email"
                            />
                        </x-lml.form-group>
                    </div>

                    <div class="lml-ra-view__actions">
                        <a
                            href="{{ route('user-management.residents.view', ['id' => $resident['id']]) }}"
                            class="lml-ra-view__btn lml-ra-view__btn--back lml-focus-ring"
                        >
                            Cancel
                        </a>
                        <button
                            type="submit"
                            class="lml-ra-view__btn lml-ra-view__btn--save lml-focus-ring"
                        >
                            Save Changes
                        </button>
                    </div>
                </form>
            @endif
        </article>
    </div>
@endsection
