{{--
    Delete Health Worker account confirmation.
    Soft-deletes login/access — never hard-deletes the staff row or history.
--}}
<div
    class="lml-ra-modal"
    data-hw-delete-modal
    hidden
>
    <div
        class="lml-ra-modal__backdrop"
        data-hw-delete-backdrop
    ></div>

    <div
        class="lml-ra-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="lml-hw-delete-title"
        aria-describedby="lml-hw-delete-message lml-hw-delete-warning"
        tabindex="-1"
        data-hw-delete-panel
    >
        <header class="lml-ra-modal__header">
            <h2 id="lml-hw-delete-title" class="lml-ra-modal__title">
                Delete Account?
            </h2>
            <button
                type="button"
                class="lml-ra-modal__close lml-focus-ring"
                data-hw-delete-cancel
                aria-label="Close delete confirmation"
            >
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="lml-ra-modal__body">
            <p id="lml-hw-delete-message" class="lml-ra-modal__message">
                Are you sure you want to delete the account of
                <span class="lml-ra-modal__resident-name">
                    <span data-hw-delete-name-label>this health worker</span>?
                </span>
            </p>
            <div id="lml-hw-delete-warning" class="lml-ra-modal__warning">
                <p class="lml-ra-modal__warning-text">
                    This is different from deactivating — they'll be removed from User Management
                    and won't be able to sign in anymore.
                </p>
                <p class="lml-ra-modal__warning-text">
                    Their employment history, assigned zones, and any health records they've authored
                    will still be preserved.
                </p>
            </div>
        </div>

        <form
            method="post"
            action="#"
            class="lml-ra-modal__actions"
            data-hw-delete-form
            data-hw-delete-base="{{ url('/user-management/health-workers') }}"
        >
            @csrf
            @method('DELETE')

            <button
                type="button"
                class="lml-ra-modal__btn lml-ra-modal__btn--cancel lml-focus-ring"
                data-hw-delete-cancel
            >
                Cancel
            </button>
            <button
                type="submit"
                class="lml-ra-modal__btn lml-ra-modal__btn--confirm lml-focus-ring"
                data-hw-delete-confirm
            >
                Delete Account
            </button>
        </form>
    </div>
</div>
