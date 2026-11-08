{{--
    Delete Resident Account confirmation modal (Admin User Management).
--}}
<div
    class="lml-ra-modal"
    data-resident-delete-modal
    hidden
>
    <div
        class="lml-ra-modal__backdrop"
        data-resident-delete-backdrop
    ></div>

    <div
        class="lml-ra-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="lml-ra-delete-title"
        aria-describedby="lml-ra-delete-message lml-ra-delete-warning"
        tabindex="-1"
        data-resident-delete-panel
    >
        <header class="lml-ra-modal__header">
            <h2 id="lml-ra-delete-title" class="lml-ra-modal__title">
                Delete Resident Account?
            </h2>
            <button
                type="button"
                class="lml-ra-modal__close lml-focus-ring"
                data-resident-delete-cancel
                aria-label="Close delete confirmation"
            >
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="lml-ra-modal__body">
            <p id="lml-ra-delete-message" class="lml-ra-modal__message" data-resident-delete-message>
                Are you sure you want to delete the account of
                <span class="lml-ra-modal__resident-name">
                    <span data-resident-delete-name-label>this resident</span>?
                </span>
            </p>
            <div id="lml-ra-delete-warning" class="lml-ra-modal__warning">
                <p class="lml-ra-modal__warning-text">
                    This action will permanently remove the resident's LMLinga account and chatbot access.
                </p>
                <p class="lml-ra-modal__warning-text">
                    Household records, health records, and all other resident information will remain intact.
                </p>
            </div>
        </div>

        <form
            method="post"
            action="#"
            class="lml-ra-modal__actions"
            data-resident-delete-form
            data-resident-destroy-base="{{ url('/user-management/residents') }}"
        >
            @csrf
            @method('DELETE')

            <button
                type="button"
                class="lml-ra-modal__btn lml-ra-modal__btn--cancel lml-focus-ring"
                data-resident-delete-cancel
            >
                Cancel
            </button>
            <button
                type="submit"
                class="lml-ra-modal__btn lml-ra-modal__btn--confirm lml-focus-ring"
                data-resident-delete-confirm
            >
                Delete Account
            </button>
        </form>
    </div>
</div>
