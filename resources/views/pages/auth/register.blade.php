@extends('layouts.app')

@section('title', 'Create an Account - LMLinga')

@section('body')
    <div class="lml-register-page">
        <header class="lml-register-header">
            <a href="{{ route('landing') }}" class="logo lml-register-logo text-decoration-none lml-focus-ring rounded-2">
                <img src="{{ asset('assets/images/logo/logo.png') }}" alt="">
                <span>LMLinga</span>
            </a>
        </header>

        <main class="lml-register-main">
            <section class="lml-register-card" aria-labelledby="register-heading">
                <header class="lml-register-card__header">
                    <div class="lml-register-card__title">
                        <i class="bi bi-person-fill lml-register-card__icon" aria-hidden="true"></i>
                        <h1 id="register-heading" class="lml-register-card__heading">Create an Account</h1>
                    </div>

                    <a
                        href="{{ route('landing') }}"
                        class="lml-register-card__close lml-focus-ring"
                        aria-label="Close registration"
                    >
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                </header>

                {{-- UI-only form — no backend registration logic. --}}
                <form class="lml-register-form" action="#" method="get" novalidate>
                    <fieldset class="lml-register-name-group">
                        <legend class="lml-register-field__legend">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <span>Full Name</span>
                        </legend>

                        <div class="lml-register-name-grid">
                            <x-lml.form-group name="first_name" class="lml-register-field lml-register-field--name">
                                <label for="first_name" class="visually-hidden">First Name</label>
                                <x-lml.text-input
                                    name="first_name"
                                    id="first_name"
                                    placeholder="First Name"
                                    autocomplete="given-name"
                                    class="w-100"
                                />
                            </x-lml.form-group>

                            <x-lml.form-group name="middle_name" class="lml-register-field lml-register-field--name">
                                <label for="middle_name" class="visually-hidden">Middle Name</label>
                                <x-lml.text-input
                                    name="middle_name"
                                    id="middle_name"
                                    placeholder="Middle Name"
                                    autocomplete="additional-name"
                                    class="w-100"
                                />
                            </x-lml.form-group>

                            <x-lml.form-group name="last_name" class="lml-register-field lml-register-field--name">
                                <label for="last_name" class="visually-hidden">Last Name</label>
                                <x-lml.text-input
                                    name="last_name"
                                    id="last_name"
                                    placeholder="Last Name"
                                    autocomplete="family-name"
                                    class="w-100"
                                />
                            </x-lml.form-group>
                        </div>
                    </fieldset>

                    <x-lml.form-group
                        label="Email"
                        name="email"
                        icon="bi-envelope"
                        class="lml-register-field"
                    >
                        <x-lml.text-input
                            type="email"
                            name="email"
                            id="email"
                            placeholder="Email"
                            autocomplete="email"
                            inputmode="email"
                            class="w-100"
                        />
                    </x-lml.form-group>

                    <x-lml.form-group
                        label="Role"
                        name="role"
                        icon="bi-person-plus"
                        class="lml-register-field"
                    >
                        <x-lml.select-input
                            name="role"
                            id="role"
                            placeholder="Select"
                            :options="[
                                'BHW' => 'BHW',
                                'BNS' => 'BNS',
                                'BSPO' => 'BSPO',
                                'Admin' => 'Admin',
                            ]"
                            class="w-100"
                        />
                    </x-lml.form-group>

                    <x-lml.form-group
                        label="Password"
                        name="password"
                        icon="bi-lock"
                        class="lml-register-field"
                    >
                        <x-lml.password-input
                            name="password"
                            id="password"
                            placeholder="Password"
                            :toggle="true"
                            autocomplete="new-password"
                            class="w-100"
                        />
                    </x-lml.form-group>

                    <div class="lml-register-actions">
                        <button type="submit" class="lml-register-submit lml-focus-ring">
                            Register
                        </button>

                        <p class="lml-register-footer">
                            Already have an account?
                            <a href="{{ route('login') }}" class="lml-register-login-link">Login</a>
                        </p>
                    </div>
                </form>
            </section>
        </main>
    </div>
@endsection
