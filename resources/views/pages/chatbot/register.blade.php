@extends('layouts.app')

@section('title', 'Create an Account - LMLinga')

@section('body')
    <div class="lml-chatbot-register">
        <div class="lml-chatbot-register__layout">
            {{-- Left promotional panel --}}
            <aside class="lml-chatbot-register__promo" aria-label="LMLinga chatbot information">
                <a
                    href="{{ route('chatbot.landing') }}"
                    class="lml-chatbot-register__brand lml-focus-ring rounded-2"
                >
                    <img
                        class="lml-chatbot-register__brand-mark"
                        src="{{ asset('assets/images/logo/logo.png') }}"
                        alt=""
                        width="72"
                        height="72"
                        decoding="async"
                    >
                    <span>LMLinga</span>
                </a>

                <div class="lml-chatbot-register__promo-group">
                    <div class="lml-chatbot-register__bot-wrap">
                        <img
                            class="lml-chatbot-register__bot"
                            src="{{ asset('assets/images/logo/bot-trimmed.png') }}"
                            alt="LMLinga multilingual health chatbot"
                            width="486"
                            height="500"
                            decoding="async"
                            fetchpriority="high"
                        >
                    </div>

                    <p class="lml-chatbot-register__promo-text">
                        A multilingual chatbot for health information and education only.
                    </p>

                    <ul class="lml-chatbot-register__languages" aria-label="Supported chatbot languages">
                        <li>
                            <button type="button" class="lml-chatbot-register__language">
                                English
                            </button>
                        </li>
                        <li>
                            <button type="button" class="lml-chatbot-register__language">
                                Tagalog
                            </button>
                        </li>
                        <li>
                            <button type="button" class="lml-chatbot-register__language">
                                Bikol – Iriga
                            </button>
                        </li>
                    </ul>
                </div>
            </aside>

            {{-- Right registration panel --}}
            <main class="lml-chatbot-register__panel" id="main-content">
                <a
                    href="{{ route('chatbot.landing') }}"
                    class="lml-chatbot-register__close lml-focus-ring"
                    aria-label="Close registration and return to chatbot landing"
                >
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>

                <section class="lml-chatbot-register__card" aria-labelledby="chatbot-register-heading">
                    <header class="lml-chatbot-register__card-header">
                        <i class="bi bi-person-fill lml-chatbot-register__card-icon" aria-hidden="true"></i>
                        <h1 id="chatbot-register-heading" class="lml-chatbot-register__card-heading">
                            Create an Account
                        </h1>
                    </header>

                    {{--
                        Resident registration: POST + CSRF only. Credentials never in the URL.
                        Owned by Chatbot\ResidentRegistrationController@store (chatbot.register.store).
                        Single-submit guard below disables the button after the first valid submit.
                    --}}
                    <form
                        class="lml-chatbot-register__form"
                        action="{{ route('chatbot.register.store') }}"
                        method="post"
                        novalidate
                        data-lml-register-form
                    >
                        @csrf

                        <fieldset class="lml-chatbot-register__name-group">
                            <legend>
                                <i class="bi bi-person" aria-hidden="true"></i>
                                Name
                            </legend>

                            <div class="lml-chatbot-register__name-grid">
                                {{-- Visible labels removed for Figma; keep screen-reader labels. --}}
                                <x-lml.form-group
                                    name="first_name"
                                    class="lml-chatbot-register__field"
                                >
                                    <label for="first_name" class="visually-hidden">First Name</label>
                                    <x-lml.text-input
                                        name="first_name"
                                        id="first_name"
                                        :required="true"
                                        placeholder="First Name"
                                        autocomplete="given-name"
                                        class="lml-chatbot-register__control w-100"
                                    />
                                </x-lml.form-group>

                                <x-lml.form-group
                                    name="middle_name"
                                    class="lml-chatbot-register__field"
                                >
                                    <label for="middle_name" class="visually-hidden">Middle Name</label>
                                    <x-lml.text-input
                                        name="middle_name"
                                        id="middle_name"
                                        :required="true"
                                        placeholder="Middle Name"
                                        autocomplete="additional-name"
                                        class="lml-chatbot-register__control w-100"
                                    />
                                </x-lml.form-group>

                                <x-lml.form-group
                                    name="last_name"
                                    class="lml-chatbot-register__field"
                                >
                                    <label for="last_name" class="visually-hidden">Last Name</label>
                                    <x-lml.text-input
                                        name="last_name"
                                        id="last_name"
                                        :required="true"
                                        placeholder="Last Name"
                                        autocomplete="family-name"
                                        class="lml-chatbot-register__control w-100"
                                    />
                                </x-lml.form-group>
                            </div>
                        </fieldset>

                        <x-lml.form-group
                            label="Zone / Purok"
                            name="zone"
                            :required="true"
                            icon="bi-geo-alt"
                            class="lml-chatbot-register__field"
                        >
                            <x-lml.select-input
                                name="zone"
                                id="zone"
                                :required="true"
                                placeholder="Select Zone"
                                :options="[
                                    '1' => 'Zone 1',
                                    '2' => 'Zone 2',
                                    '3' => 'Zone 3',
                                    '4' => 'Zone 4',
                                    '5' => 'Zone 5',
                                ]"
                                class="lml-chatbot-register__control w-100"
                            />
                        </x-lml.form-group>

                        <x-lml.form-group
                            label="Email"
                            name="email"
                            :required="true"
                            icon="bi-envelope"
                            class="lml-chatbot-register__field"
                        >
                            <input
                                type="email"
                                name="email"
                                id="email"
                                value="{{ old('email') }}"
                                required
                                aria-required="true"
                                autocomplete="email"
                                inputmode="email"
                                placeholder="Email"
                                @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif
                                class="form-control lml-form-control lml-chatbot-register__control w-100{{ $errors->has('email') ? ' is-invalid' : '' }}"
                            >
                        </x-lml.form-group>

                        <x-lml.form-group
                            label="Password"
                            name="password"
                            :required="true"
                            icon="bi-lock"
                            class="lml-chatbot-register__field"
                        >
                            <x-lml.password-input
                                name="password"
                                id="password"
                                :required="true"
                                placeholder="Password"
                                :toggle="true"
                                autocomplete="new-password"
                                class="lml-chatbot-register__control w-100"
                            />
                        </x-lml.form-group>

                        <div class="lml-chatbot-register__actions">
                            <button
                                type="submit"
                                class="lml-chatbot-register__submit lml-focus-ring"
                                data-lml-register-submit
                            >
                                Register
                            </button>

                            <p class="lml-chatbot-register__footer">
                                Already have an account?
                                <a href="{{ route('chatbot.login') }}" class="lml-chatbot-register__login-link lml-focus-ring">
                                    Login
                                </a>
                            </p>
                        </div>
                    </form>
                </section>
            </main>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.querySelector('[data-lml-register-form]');
            if (! form) {
                return;
            }

            const submitButton = form.querySelector('[data-lml-register-submit]');
            if (!submitButton) {
                return;
            }

            form.addEventListener('submit', function (event) {
                if (submitButton.disabled) {
                    event.preventDefault();
                    return;
                }

                // Do not lock the button when constraint validation fails so the
                // resident can correct fields and submit again (form has novalidate,
                // but checkValidity still reflects required/type constraints).
                if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    return;
                }

                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
                submitButton.textContent = 'Creating account...';
            });
        });
    </script>
@endpush
