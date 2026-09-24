{{--
    Notification details modal — populated from sidebar row data attributes.
    Supports generic System/announcement notices and legacy schedule rows.
--}}
<div
    class="lml-chatbot-notification-modal"
    data-lml-notification-modal
    hidden
>
    <div
        class="lml-chatbot-notification-modal__backdrop"
        data-lml-notification-modal-backdrop
    ></div>

    <div
        class="lml-chatbot-notification-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="chatbot-notification-modal-title"
        aria-describedby="chatbot-notification-modal-message"
        tabindex="-1"
        data-lml-notification-modal-panel
    >
        <header class="lml-chatbot-notification-modal__header">
            <div class="lml-chatbot-notification-modal__header-text">
                <p
                    class="lml-chatbot-notification-modal__eyebrow"
                    data-lml-notification-modal-eyebrow
                >
                    Notification
                </p>
                <h2
                    id="chatbot-notification-modal-title"
                    class="lml-chatbot-notification-modal__title"
                    data-lml-notification-modal-title
                ></h2>
            </div>
            <button
                type="button"
                class="lml-chatbot-notification-modal__close lml-focus-ring"
                data-lml-notification-modal-close
                aria-label="Close notification details"
            >
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="lml-chatbot-notification-modal__body">
            {{-- Generic System / announcement details --}}
            <dl
                class="lml-chatbot-notification-modal__details"
                data-lml-notification-modal-generic-details
            >
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Who</dt>
                    <dd data-lml-notification-modal-detail="who"></dd>
                </div>
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Where</dt>
                    <dd data-lml-notification-modal-detail="where"></dd>
                </div>
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Date</dt>
                    <dd data-lml-notification-modal-detail="date"></dd>
                </div>
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Time</dt>
                    <dd data-lml-notification-modal-detail="time"></dd>
                </div>
            </dl>

            {{-- Legacy schedule details (hidden for System / announcement) --}}
            <dl
                class="lml-chatbot-notification-modal__details"
                data-lml-notification-modal-schedule-details
                hidden
            >
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Service</dt>
                    <dd data-lml-notification-modal-detail="service"></dd>
                </div>
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Household Member</dt>
                    <dd data-lml-notification-modal-detail="member"></dd>
                </div>
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Relationship</dt>
                    <dd data-lml-notification-modal-detail="relationship"></dd>
                </div>
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Scheduled Date</dt>
                    <dd data-lml-notification-modal-detail="schedule-date"></dd>
                </div>
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Scheduled Time</dt>
                    <dd data-lml-notification-modal-detail="schedule-time"></dd>
                </div>
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Place</dt>
                    <dd data-lml-notification-modal-detail="place"></dd>
                </div>
                <div class="lml-chatbot-notification-modal__detail">
                    <dt>Status</dt>
                    <dd>
                        <span
                            class="lml-chatbot-notifications__status"
                            data-lml-notification-modal-detail="status"
                        ></span>
                    </dd>
                </div>
            </dl>

            <section
                class="lml-chatbot-notification-modal__reminder"
                aria-labelledby="chatbot-notification-message-heading"
            >
                <div class="lml-chatbot-notification-modal__reminder-heading">
                    <i class="bi bi-chat-left-text" aria-hidden="true" data-lml-notification-modal-body-icon></i>
                    <h3
                        id="chatbot-notification-message-heading"
                        data-lml-notification-modal-body-heading
                    >Message</h3>
                </div>
                <p
                    id="chatbot-notification-modal-message"
                    class="lml-chatbot-notification-modal__reminder-text"
                    data-lml-notification-modal-message
                ></p>
            </section>
        </div>

        <footer class="lml-chatbot-notification-modal__actions">
            <button
                type="button"
                class="lml-chatbot-notification-modal__btn lml-chatbot-notification-modal__btn--secondary lml-focus-ring"
                data-lml-notification-modal-dismiss
            >
                Close
            </button>
        </footer>
    </div>
</div>
