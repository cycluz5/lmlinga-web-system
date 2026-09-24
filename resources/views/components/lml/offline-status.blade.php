{{--
    Authenticated-shell offline status: persistent banner, toast, and
    Offline Changes viewer. Messages/list filled by offline-status.js.
--}}
<div class="lml-offline-status" data-lml-offline-chrome>
    <div
        class="lml-offline-live visually-hidden"
        data-lml-offline-live
        role="status"
        aria-live="polite"
        aria-atomic="true"
    ></div>

    <div
        class="lml-offline-banner"
        data-lml-offline-banner
        role="status"
        aria-live="polite"
        aria-atomic="true"
        hidden
    >
        <i class="bi bi-wifi-off lml-offline-banner__icon" data-lml-offline-banner-icon aria-hidden="true"></i>
        <p class="lml-offline-banner__text mb-0" data-lml-offline-banner-text></p>
        <button
            type="button"
            class="lml-offline-banner__review lml-focus-ring"
            data-lml-offline-banner-review
            hidden
        >
            Review
        </button>
    </div>

    <div
        class="lml-offline-toast"
        data-lml-offline-toast
        role="status"
        aria-live="polite"
        aria-atomic="true"
        hidden
    ></div>

    <div
        class="lml-offline-dialog lml-offline-changes"
        data-lml-offline-dialog
        data-lml-offline-changes
        role="dialog"
        aria-modal="true"
        aria-labelledby="lml-offline-dialog-title"
        aria-describedby="lml-offline-changes-list"
        hidden
    >
        <div class="lml-offline-dialog__backdrop" data-lml-offline-dialog-backdrop></div>
        <div class="lml-offline-dialog__card lml-offline-changes__card lml-surface">
            <header class="lml-offline-changes__header">
                <h2 id="lml-offline-dialog-title" class="lml-offline-dialog__title" data-lml-offline-dialog-title>
                    Offline Changes
                </h2>
                <button
                    type="button"
                    class="lml-offline-changes__close lml-focus-ring"
                    data-lml-offline-dialog-dismiss
                    aria-label="Close offline changes"
                >
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </header>

            <p
                id="lml-offline-dialog-body"
                class="lml-offline-dialog__body lml-offline-changes__summary mb-0"
                data-lml-offline-dialog-body
            ></p>

            <div class="lml-offline-changes__tabs" data-lml-offline-changes-tabs role="tablist" aria-label="Offline change filters">
                <button type="button" class="lml-offline-changes__tab is-active lml-focus-ring" data-lml-offline-changes-tab="all" role="tab" aria-selected="true">
                    All <span data-lml-offline-changes-count="all">0</span>
                </button>
                <button type="button" class="lml-offline-changes__tab lml-focus-ring" data-lml-offline-changes-tab="waiting" role="tab" aria-selected="false">
                    Waiting <span data-lml-offline-changes-count="waiting">0</span>
                </button>
                <button type="button" class="lml-offline-changes__tab lml-focus-ring" data-lml-offline-changes-tab="attention" role="tab" aria-selected="false">
                    Needs Attention <span data-lml-offline-changes-count="attention">0</span>
                </button>
            </div>

            <div
                id="lml-offline-changes-list"
                class="lml-offline-changes__list"
                data-lml-offline-changes-list
            ></div>

            {{-- Legacy single-item edit link kept for tests / deep links --}}
            <div class="lml-offline-dialog__actions lml-offline-changes__actions">
                <a
                    class="btn btn-outline-primary lml-focus-ring fw-medium"
                    data-lml-offline-dialog-edit
                    data-hh-nav="edit-member"
                    hidden
                >Review Record</a>
                <button
                    type="button"
                    class="btn btn-primary lml-focus-ring fw-medium"
                    data-lml-offline-changes-done
                >
                    Close
                </button>
            </div>
        </div>
    </div>

    {{-- Don't Sync confirmation (nested over Offline Changes) --}}
    <div
        class="lml-offline-dialog lml-offline-discard"
        data-lml-offline-discard-dialog
        role="dialog"
        aria-modal="true"
        aria-labelledby="lml-offline-discard-title"
        aria-describedby="lml-offline-discard-body"
        hidden
    >
        <div class="lml-offline-dialog__backdrop" data-lml-offline-discard-backdrop></div>
        <div class="lml-offline-dialog__card lml-offline-discard__card lml-surface">
            <h2 id="lml-offline-discard-title" class="lml-offline-dialog__title" data-lml-offline-discard-title>
                Don't sync this change?
            </h2>
            <div
                id="lml-offline-discard-body"
                class="lml-offline-dialog__body lml-offline-discard__body"
                data-lml-offline-discard-body
            ></div>
            <div class="lml-offline-dialog__actions lml-offline-discard__actions">
                <button
                    type="button"
                    class="btn btn-outline-secondary lml-focus-ring fw-medium"
                    data-lml-offline-discard-keep
                >
                    Keep Change
                </button>
                <button
                    type="button"
                    class="btn btn-danger lml-focus-ring fw-medium"
                    data-lml-offline-discard-confirm
                >
                    Don't Sync
                </button>
            </div>
        </div>
    </div>
</div>
