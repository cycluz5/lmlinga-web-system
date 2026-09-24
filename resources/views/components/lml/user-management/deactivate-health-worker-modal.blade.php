{{--
    Deactivate Health Worker account confirmation.
    Changes status only — never hard-deletes the staff row.
--}}
<div
    class="lml-ra-modal"
    data-hw-deactivate-modal
    hidden
>
    <div
        class="lml-ra-modal__backdrop"
        data-hw-deactivate-backdrop
    ></div>

    <div
        class="lml-ra-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="lml-hw-deactivate-title"
        aria-describedby="lml-hw-deactivate-message lml-hw-deactivate-warning"
        tabindex="-1"
        data-hw-deactivate-panel
    >
        <header class="lml-ra-modal__header">
            <h2 id="lml-hw-deactivate-title" class="lml-ra-modal__title">
                Deactivate Account Access?
            </h2>
            <button
                type="button"
                class="lml-ra-modal__close lml-focus-ring"
                data-hw-deactivate-cancel
                aria-label="Close deactivation confirmation"
            >
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="lml-ra-modal__body">
            <p id="lml-hw-deactivate-message" class="lml-ra-modal__message">
                Deactivate account access for
                <span class="lml-ra-modal__resident-name">
                    <span data-hw-deactivate-name-label>this health worker</span>?
                </span>
            </p>
            <div id="lml-hw-deactivate-warning" class="lml-ra-modal__warning">
                <p class="lml-ra-modal__warning-text">
                    This will deactivate the account and prevent the user from signing in.
                    Historical records will be preserved.
                </p>
            </div>
        </div>

        <form
            method="post"
            action="#"
            class="lml-ra-modal__actions"
            data-hw-deactivate-form
            data-hw-deactivate-base="{{ url('/user-management/health-workers') }}"
        >
            @csrf

            <button
                type="button"
                class="lml-ra-modal__btn lml-ra-modal__btn--cancel lml-focus-ring"
                data-hw-deactivate-cancel
            >
                Cancel
            </button>
            <button
                type="submit"
                class="lml-ra-modal__btn lml-ra-modal__btn--confirm lml-focus-ring"
                data-hw-deactivate-confirm
            >
                Deactivate Account
            </button>
        </form>
    </div>
</div>
