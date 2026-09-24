/**
 * Resident AI Chatbot main interface — UI-only interactions.
 * No AI API, persistence, auth, or backend calls.
 */

function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function isMobileViewport() {
    return window.matchMedia('(max-width: 767.98px)').matches;
}

function formatTime(date = new Date()) {
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

/**
 * CSRF token for chatbot fetch calls.
 * Prefer layout meta tag; fall back to any form _token input.
 */
function getCsrfToken() {
    const metaToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');
    if (metaToken) {
        return metaToken;
    }

    const tokenInput = document.querySelector('input[name="_token"]');
    return tokenInput?.value || '';
}

/**
 * Sidebar last-active label from last_message_at (no extra dependency).
 */
function formatRelativeLastActive(value, now = new Date()) {
    if (value == null || value === '') {
        return '';
    }

    const then = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(then.getTime())) {
        return '';
    }

    const diffMs = now.getTime() - then.getTime();
    if (diffMs < 0) {
        return 'Just now';
    }

    const diffSec = Math.floor(diffMs / 1000);
    if (diffSec < 60) {
        return 'Just now';
    }

    const diffMin = Math.floor(diffSec / 60);
    if (diffMin < 60) {
        return `${diffMin} min${diffMin === 1 ? '' : 's'} ago`;
    }

    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) {
        return `${diffHr} hr${diffHr === 1 ? '' : 's'} ago`;
    }

    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startOfThen = new Date(then.getFullYear(), then.getMonth(), then.getDate());
    const dayDiff = Math.round((startOfToday - startOfThen) / 86400000);

    if (dayDiff === 1) {
        return 'Yesterday';
    }

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = months[then.getMonth()];
    const day = then.getDate();

    if (then.getFullYear() === now.getFullYear()) {
        return `${month} ${day}`;
    }

    return `${month} ${day}, ${then.getFullYear()}`;
}

function getFocusableElements(container) {
    if (!container) {
        return [];
    }

    const selector = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'textarea:not([disabled])',
        'select:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',');

    return Array.from(container.querySelectorAll(selector)).filter((el) => {
        if (el.hidden || el.getAttribute('aria-hidden') === 'true') {
            return false;
        }
        if (el.closest('[hidden]')) {
            return false;
        }
        const style = window.getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden') {
            return false;
        }
        return el.getClientRects().length > 0;
    });
}

function trapFocus(event, container) {
    if (event.key !== 'Tab') {
        return;
    }

    const focusables = getFocusableElements(container);
    if (focusables.length === 0) {
        event.preventDefault();
        container.focus();
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement;

    if (event.shiftKey) {
        if (active === first || !container.contains(active)) {
            event.preventDefault();
            last.focus();
        }
    } else if (active === last) {
        event.preventDefault();
        first.focus();
    }
}

const NOTIFICATION_STATUS_LABELS = {
    upcoming: 'Upcoming',
    today: 'Today',
    completed: 'Completed',
    cancelled: 'Cancelled',
    rescheduled: 'Rescheduled',
};

function initChatbotNotifications(root) {
    const notificationsRoot = root.querySelector('[data-lml-notifications]');
    const toggle = root.querySelector('[data-lml-notifications-toggle]');
    const panel = root.querySelector('[data-lml-notifications-panel]');
    const badge = root.querySelector('[data-lml-notifications-badge]');
    const modalRoot =
        root.querySelector('[data-lml-notification-modal]') ||
        document.querySelector('[data-lml-notification-modal]');
    const modalPanel = modalRoot?.querySelector('[data-lml-notification-modal-panel]');
    const modalBackdrop = modalRoot?.querySelector('[data-lml-notification-modal-backdrop]');
    const modalCloseBtn = modalRoot?.querySelector('[data-lml-notification-modal-close]');
    const modalDismissBtn = modalRoot?.querySelector('[data-lml-notification-modal-dismiss]');

    if (!notificationsRoot || !toggle || !panel || !modalRoot || !modalPanel) {
        return;
    }

    let activeNotificationItem = null;
    let returnFocusEl = null;

    function getNotificationItems() {
        return Array.from(root.querySelectorAll('[data-lml-notification-item]'));
    }

    function getUnreadCount() {
        return getNotificationItems().filter(
            (item) => item.dataset.notificationRead !== 'true'
        ).length;
    }

    function syncUnreadBadge() {
        const unread = getUnreadCount();

        if (!badge) {
            if (unread > 0) {
                toggle.setAttribute('aria-label', `Notifications, ${unread} unread`);
            } else {
                toggle.setAttribute('aria-label', 'Notifications');
            }
            return;
        }

        if (unread <= 0) {
            badge.hidden = true;
            badge.textContent = '0';
            badge.setAttribute('aria-label', '0 unread');
            toggle.setAttribute('aria-label', 'Notifications');
            return;
        }

        badge.hidden = false;
        badge.textContent = String(unread);
        badge.setAttribute('aria-label', `${unread} unread`);
        toggle.setAttribute('aria-label', `Notifications, ${unread} unread`);
    }

    function setNotificationsOpen(open) {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            root.querySelectorAll('[data-lml-sidebar-tab]').forEach((el) => {
                el.classList.toggle('is-active', el.dataset.lmlSidebarTab === 'notifications');
            });
        }

        if (open && root.classList.contains('is-sidebar-collapsed') && !isMobileViewport()) {
            root.classList.remove('is-sidebar-collapsed');
            const desktopToggle = root.querySelector('[data-lml-sidebar-toggle]');
            if (desktopToggle) {
                desktopToggle.setAttribute('aria-expanded', 'true');
                desktopToggle.setAttribute('aria-label', 'Collapse sidebar');
                desktopToggle.setAttribute('title', 'Collapse sidebar');
            }
        }
    }

    function clearSelectedNotificationItems() {
        getNotificationItems().forEach((item) => {
            item.classList.remove('is-selected');
        });
    }

    function renderStatusBadge(statusEl, status) {
        if (!statusEl) {
            return;
        }

        statusEl.className = 'lml-chatbot-notifications__status';
        statusEl.classList.add(`lml-chatbot-notifications__status--${status}`);

        const label = NOTIFICATION_STATUS_LABELS[status] || status;
        let icon = '';

        if (status === 'completed') {
            icon = '<i class="bi bi-check2" aria-hidden="true"></i>';
        } else if (status === 'cancelled') {
            icon = '<i class="bi bi-x-circle" aria-hidden="true"></i>';
        } else if (status === 'rescheduled') {
            icon = '<i class="bi bi-arrow-repeat" aria-hidden="true"></i>';
        }

        statusEl.replaceChildren();
        if (icon) {
            statusEl.insertAdjacentHTML('afterbegin', icon);
        }
        const labelSpan = document.createElement('span');
        labelSpan.textContent = label;
        statusEl.appendChild(labelSpan);
    }

    function isScheduleNotification(data) {
        if (data.kind === 'schedule') {
            return true;
        }
        if (data.kind === 'generic') {
            return false;
        }

        return ['upcoming', 'today', 'completed', 'cancelled', 'rescheduled'].includes(
            data.status
        );
    }

    function setDetailText(key, value) {
        const el = modalRoot.querySelector(`[data-lml-notification-modal-detail="${key}"]`);
        if (!el) {
            return;
        }
        el.textContent = value || '—';
    }

    function populateModal(data) {
        const scheduleMode = isScheduleNotification(data);
        const titleEl = modalRoot.querySelector('[data-lml-notification-modal-title]');
        const eyebrowEl = modalRoot.querySelector('[data-lml-notification-modal-eyebrow]');
        const messageEl = modalRoot.querySelector('[data-lml-notification-modal-message]');
        const bodyHeadingEl = modalRoot.querySelector('[data-lml-notification-modal-body-heading]');
        const bodyIconEl = modalRoot.querySelector('[data-lml-notification-modal-body-icon]');
        const genericDetails = modalRoot.querySelector('[data-lml-notification-modal-generic-details]');
        const scheduleDetails = modalRoot.querySelector('[data-lml-notification-modal-schedule-details]');

        if (titleEl) {
            titleEl.textContent = data.title || data.service || 'Notification';
        }

        if (eyebrowEl) {
            eyebrowEl.textContent = scheduleMode ? 'Upcoming Health Schedule' : 'Notification';
        }

        if (bodyHeadingEl) {
            bodyHeadingEl.textContent = scheduleMode ? 'Schedule Reminder' : 'Message';
        }

        if (bodyIconEl) {
            bodyIconEl.className = scheduleMode ? 'bi bi-calendar-event' : 'bi bi-chat-left-text';
        }

        if (genericDetails) {
            genericDetails.hidden = scheduleMode;
        }

        if (scheduleDetails) {
            scheduleDetails.hidden = !scheduleMode;
        }

        if (scheduleMode) {
            setDetailText('service', data.service || data.title);
            setDetailText('member', data.member);
            setDetailText('relationship', data.relationship);
            setDetailText('schedule-date', data.date);
            setDetailText('schedule-time', data.time);
            setDetailText('place', data.place);
            renderStatusBadge(
                modalRoot.querySelector('[data-lml-notification-modal-detail="status"]'),
                data.status
            );
        } else {
            setDetailText('who', data.who);
            setDetailText('where', data.place);
            setDetailText('date', data.date);
            setDetailText('time', data.time);
        }

        if (messageEl) {
            messageEl.textContent = data.message || '';
        }
    }

    function readNotificationData(item) {
        return {
            id: item.dataset.notificationId,
            title: item.dataset.notificationTitle || item.dataset.notificationService || '',
            message: item.dataset.notificationMessage || '',
            type: item.dataset.notificationType || '',
            who: item.dataset.notificationWho || '',
            kind: item.dataset.notificationKind || '',
            service: item.dataset.notificationService || item.dataset.notificationTitle || '',
            member: item.dataset.notificationMember || '',
            relationship: item.dataset.notificationRelationship || '',
            date: item.dataset.notificationDate || '',
            time: item.dataset.notificationTime || '',
            place: item.dataset.notificationPlace || '',
            status: item.dataset.notificationStatus || '',
            read: item.dataset.notificationRead === 'true',
        };
    }

    function updateNotificationItemLabel(item) {
        const title =
            item.querySelector('.lml-chatbot-notifications__item-title')?.textContent?.trim() ||
            item.dataset.notificationTitle ||
            item.dataset.notificationService ||
            'Notification';
        const isUnread = item.dataset.notificationRead !== 'true';

        let label = title;
        if (isUnread) {
            label += ', unread';
        }
        item.setAttribute('aria-label', label);
    }

    function persistNotificationRead(notificationId) {
        if (!notificationId) {
            return;
        }

        try {
            fetch(`/chatbot/notifications/${encodeURIComponent(notificationId)}/read`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({}),
            }).catch(() => {
                // Visual read state already applied; persistence can retry on next open.
            });
        } catch (error) {
            console.warn('Unable to persist notification read state.', error);
        }
    }

    function markNotificationRead(item) {
        if (!item || item.dataset.notificationRead === 'true') {
            return;
        }

        item.dataset.notificationRead = 'true';
        item.classList.remove('is-unread');
        item.classList.add('is-read');

        const dot = item.querySelector('.lml-chatbot-notifications__unread-dot');
        if (dot) {
            dot.remove();
        }

        const title = item.querySelector('.lml-chatbot-notifications__item-title');
        if (title) {
            title.style.fontWeight = '';
        }

        updateNotificationItemLabel(item);
        syncUnreadBadge();
    }

    function openNotificationModal(item) {
        markNotificationRead(item);

        activeNotificationItem = item;
        returnFocusEl = item;

        clearSelectedNotificationItems();
        item.classList.add('is-selected');
        populateModal(readNotificationData(item));

        modalRoot.hidden = false;
        document.body.style.overflow = 'hidden';

        window.requestAnimationFrame(() => {
            modalCloseBtn?.focus();
        });

        try {
            persistNotificationRead(item.dataset.notificationId);
        } catch (error) {
            console.warn('Unable to persist notification read state.', error);
        }
    }

    function closeNotificationModal() {
        modalRoot.hidden = true;
        document.body.style.overflow =
            root.classList.contains('is-mobile-open') && isMobileViewport() ? 'hidden' : '';

        if (returnFocusEl) {
            returnFocusEl.focus();
        }

        activeNotificationItem = null;
    }

    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        setNotificationsOpen(open);
    });

    root.addEventListener('click', (event) => {
        const item = event.target.closest('[data-lml-notification-item]');
        if (item && notificationsRoot.contains(item)) {
            event.preventDefault();
            openNotificationModal(item);
        }
    });

    [modalCloseBtn, modalDismissBtn, modalBackdrop].forEach((el) => {
        el?.addEventListener('click', () => {
            closeNotificationModal();
        });
    });

    document.addEventListener('keydown', (event) => {
        if (modalRoot.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeNotificationModal();
            return;
        }

        trapFocus(event, modalPanel);
    });

    getNotificationItems().forEach(updateNotificationItemLabel);
    syncUnreadBadge();
}

function initChatbotMain(root) {
    const overlay = root.querySelector('[data-lml-sidebar-overlay]');
    const desktopToggle = root.querySelector('[data-lml-sidebar-toggle]');
    const mobileToggle = root.querySelector('[data-lml-mobile-toggle]');
    const historyToggle = root.querySelector('[data-lml-history-toggle]');
    const historyPanel = root.querySelector('[data-lml-history-panel]');
    const newChatBtn = root.querySelector('[data-lml-new-chat]');
    const messages = root.querySelector('[data-lml-messages]');
    const composer = root.querySelector('[data-lml-composer]');
    const composerInput = root.querySelector('[data-lml-composer-input]');
    const langLive = root.querySelector('[data-lml-lang-live]');
    const pinnedList = root.querySelector('[data-lml-chat-list="pinned"]');
    const recentList = root.querySelector('[data-lml-chat-list="recent"]');
    const pinnedEmpty = root.querySelector('[data-lml-pinned-empty]');
    const recentEmpty = root.querySelector('[data-lml-recent-empty]');
    const householdBtn = root.querySelector('[data-lml-household-btn]');
    const sidebar = root.querySelector('[data-lml-sidebar]');
    const sidebarTabs = Array.from(root.querySelectorAll('[data-lml-sidebar-tab]'));

    let lastMobileToggle = mobileToggle;
    let typingIndicator = null;
    let demoReplyTimer = null;
    let currentConversationId = null;
    let historyLoadToken = 0;
    const RECENT_HISTORY_LIMIT = 20;
    let pendingDeleteConversationId = null;
    let pendingDeleteConversationTitle = null;
    let deleteModalTrigger = null;
    let deleteRequestPending = false;

    const deleteModal = root.querySelector('[data-lml-chat-delete-modal]');
    const deleteModalPanel = root.querySelector('[data-lml-chat-delete-panel]');
    const deleteModalBackdrop = root.querySelector('[data-lml-chat-delete-backdrop]');
    const deleteModalTitleEl = root.querySelector('[data-lml-chat-delete-title]');
    const deleteModalErrorEl = root.querySelector('[data-lml-chat-delete-error]');
    const deleteModalConfirmBtn = root.querySelector('[data-lml-chat-delete-confirm]');
    const deleteModalCancelBtns = Array.from(
        root.querySelectorAll('[data-lml-chat-delete-cancel]')
    );

    function getChatItems() {
        return Array.from(root.querySelectorAll('[data-lml-chat-item]'));
    }

    function clearActiveConversationSelection() {
        getChatItems().forEach((item) => {
            item.classList.remove('lml-chatbot-main__chat-row--active');
            const selectBtn = item.querySelector('[data-lml-chat-select]');
            if (selectBtn) {
                selectBtn.removeAttribute('aria-current');
            }
        });
    }

    function getSidebarFocusableElements() {
        if (!sidebar) {
            return [];
        }

        const selector = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled]):not([type="hidden"])',
            'textarea:not([disabled])',
            'select:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
        ].join(',');

        return Array.from(sidebar.querySelectorAll(selector)).filter((el) => {
            if (el.hidden || el.getAttribute('aria-hidden') === 'true') {
                return false;
            }
            if (el.closest('[hidden]')) {
                return false;
            }
            const style = window.getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden') {
                return false;
            }
            return el.getClientRects().length > 0;
        });
    }

    function onMobileSidebarKeydown(event) {
        if (!root.classList.contains('is-mobile-open') || !isMobileViewport()) {
            return;
        }

        if (event.key === 'Escape') {
            closeMobileSidebar();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusables = getSidebarFocusableElements();
        if (focusables.length === 0) {
            event.preventDefault();
            return;
        }

        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        const active = document.activeElement;
        const focusOutside = !sidebar || !sidebar.contains(active);

        if (event.shiftKey) {
            if (focusOutside || active === first) {
                event.preventDefault();
                last.focus();
            }
        } else if (focusOutside || active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function setActiveSidebarTab(tabName) {
        sidebarTabs.forEach((el) => {
            const active = el.dataset.lmlSidebarTab === tabName;
            el.classList.toggle('is-active', active);

            /*
             * aria-current="page" is reserved for the conversation destination:
             * New Chat (blank chat) OR a selected conversation row.
             * History / Household use visual .is-active + aria-expanded only.
             */
            if (el.dataset.lmlSidebarTab === 'new-chat') {
                if (active) {
                    el.setAttribute('aria-current', 'page');
                } else {
                    el.removeAttribute('aria-current');
                }
            } else if (el.dataset.lmlSidebarTab === 'notifications') {
                el.removeAttribute('aria-current');
            } else {
                el.removeAttribute('aria-current');
            }
        });
    }

    function setDesktopCollapsed(collapsed) {
        root.classList.toggle('is-sidebar-collapsed', collapsed);
        if (collapsed) {
            setHistoryOpen(false);
            const notificationsPanel = root.querySelector('[data-lml-notifications-panel]');
            const notificationsToggle = root.querySelector('[data-lml-notifications-toggle]');
            if (notificationsPanel && notificationsToggle) {
                notificationsPanel.hidden = true;
                notificationsToggle.setAttribute('aria-expanded', 'false');
            }
        }
        if (!desktopToggle) {
            return;
        }
        desktopToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        desktopToggle.setAttribute(
            'aria-label',
            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
        );
        desktopToggle.setAttribute(
            'title',
            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
        );
    }

    function setMobileOpen(open) {
        root.classList.toggle('is-mobile-open', open);
        if (overlay) {
            overlay.hidden = !open;
        }
        if (mobileToggle) {
            mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            mobileToggle.setAttribute('aria-label', open ? 'Close sidebar' : 'Open sidebar');
        }
        document.body.style.overflow = open && isMobileViewport() ? 'hidden' : '';

        document.removeEventListener('keydown', onMobileSidebarKeydown);

        if (open && isMobileViewport()) {
            document.addEventListener('keydown', onMobileSidebarKeydown);
            window.requestAnimationFrame(() => {
                const focusables = getSidebarFocusableElements();
                if (focusables.length > 0) {
                    focusables[0].focus();
                }
            });
            return;
        }

        if (!open && lastMobileToggle) {
            lastMobileToggle.focus();
        }
    }

    function closeMobileSidebar() {
        if (root.classList.contains('is-mobile-open')) {
            setMobileOpen(false);
        }
    }

    function setHistoryOpen(open) {
        if (!historyPanel || !historyToggle) {
            return;
        }
        historyPanel.hidden = !open;
        historyToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function updateEmptyStates() {
        function syncSectionEmpty(listEl, emptyEl, emptyLabel) {
            if (!emptyEl) {
                return;
            }

            const items = listEl
                ? Array.from(listEl.querySelectorAll('[data-lml-chat-item]'))
                : [];

            emptyEl.textContent = emptyLabel;
            emptyEl.hidden = items.length > 0;
        }

        syncSectionEmpty(pinnedList, pinnedEmpty, 'No pinned chats');
        syncSectionEmpty(recentList, recentEmpty, 'No recent chats yet');
    }

    function selectChatItem(selected) {
        getChatItems().forEach((item) => {
            const active = item === selected;
            item.classList.toggle('lml-chatbot-main__chat-row--active', active);
            const selectBtn = item.querySelector('[data-lml-chat-select]');
            if (!selectBtn) {
                return;
            }
            if (active) {
                selectBtn.setAttribute('aria-current', 'page');
            } else {
                selectBtn.removeAttribute('aria-current');
            }
        });
        setActiveSidebarTab('history');
        setHistoryOpen(true);
    }

    function setPinnedState(item, pinned) {
        const title = item.dataset.chatTitle || 'chat';
        const pinBtn = item.querySelector('[data-lml-pin]');
        const icon = pinBtn?.querySelector('i');

        item.dataset.pinned = pinned ? 'true' : 'false';

        if (pinBtn) {
            pinBtn.setAttribute('aria-pressed', pinned ? 'true' : 'false');
            pinBtn.setAttribute('aria-label', pinned ? `Unpin ${title}` : `Pin ${title}`);
            pinBtn.setAttribute('title', pinned ? 'Unpin' : 'Pin');
        }

        if (icon) {
            icon.className = pinned ? 'bi bi-pin-angle-fill' : 'bi bi-pin-angle';
        }
    }

    function flashPinFeedback(item) {
        item.classList.remove('is-pin-flash');
        void item.offsetWidth;
        item.classList.add('is-pin-flash');

        const onEnd = () => {
            item.classList.remove('is-pin-flash');
            item.removeEventListener('animationend', onEnd);
        };
        item.addEventListener('animationend', onEnd);

        if (prefersReducedMotion()) {
            window.setTimeout(() => item.classList.remove('is-pin-flash'), 180);
        }
    }

    function togglePin(item) {
        const conversationId = item?.dataset?.conversationId;
        if (!conversationId) {
            return;
        }

        const currentlyPinned = item.dataset.pinned === 'true';
        const nextPinned = !currentlyPinned;
        const pinBtn = item.querySelector('[data-lml-pin]');
        if (pinBtn) {
            pinBtn.disabled = true;
        }

        fetch(`/chatbot/conversation/${encodeURIComponent(conversationId)}/pin`, {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ is_pinned: nextPinned }),
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('Pin update failed: ' + response.status);
                }
                await refreshConversationList();
                if (currentConversationId != null) {
                    markConversationRowActive(currentConversationId);
                }
            })
            .catch((error) => {
                console.error('Failed to update pin state:', error);
                window.alert('Unable to update pin. Please try again.');
                refreshConversationList();
            })
            .finally(() => {
                if (pinBtn) {
                    pinBtn.disabled = false;
                }
            });
    }

    function clearDeleteModalError() {
        if (!deleteModalErrorEl) {
            return;
        }
        deleteModalErrorEl.hidden = true;
        deleteModalErrorEl.textContent = '';
    }

    function showDeleteModalError(message) {
        if (!deleteModalErrorEl) {
            return;
        }
        deleteModalErrorEl.textContent = message;
        deleteModalErrorEl.hidden = false;
    }

    function closeDeleteModal({ restoreFocus = true } = {}) {
        if (!deleteModal) {
            return;
        }

        deleteModal.hidden = true;
        document.body.style.overflow =
            root.classList.contains('is-mobile-open') && isMobileViewport() ? 'hidden' : '';

        pendingDeleteConversationId = null;
        pendingDeleteConversationTitle = null;
        deleteRequestPending = false;
        clearDeleteModalError();

        if (deleteModalConfirmBtn) {
            deleteModalConfirmBtn.disabled = false;
        }

        if (restoreFocus && deleteModalTrigger) {
            deleteModalTrigger.focus();
        }
        deleteModalTrigger = null;
    }

    function openDeleteModal(item, trigger) {
        if (!deleteModal || !deleteModalPanel) {
            return;
        }

        const conversationId = item?.dataset?.conversationId;
        if (!conversationId) {
            return;
        }

        pendingDeleteConversationId = String(conversationId);
        pendingDeleteConversationTitle = item.dataset.chatTitle || 'Untitled chat';
        deleteModalTrigger = trigger || item.querySelector('[data-lml-chat-delete]') || null;
        deleteRequestPending = false;
        clearDeleteModalError();

        if (deleteModalTitleEl) {
            deleteModalTitleEl.textContent = pendingDeleteConversationTitle;
        }
        if (deleteModalConfirmBtn) {
            deleteModalConfirmBtn.disabled = false;
        }

        deleteModal.hidden = false;
        document.body.style.overflow = 'hidden';

        window.requestAnimationFrame(() => {
            (deleteModalCancelBtns[0] || deleteModalPanel)?.focus();
        });
    }

    async function confirmDeleteConversation() {
        if (!pendingDeleteConversationId || deleteRequestPending) {
            return;
        }

        deleteRequestPending = true;
        clearDeleteModalError();
        if (deleteModalConfirmBtn) {
            deleteModalConfirmBtn.disabled = true;
        }

        const conversationId = pendingDeleteConversationId;

        try {
            const response = await fetch(
                `/chatbot/conversation/${encodeURIComponent(conversationId)}`,
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                }
            );

            if (!response.ok) {
                throw new Error('Delete failed: ' + response.status);
            }

            const deletedId = String(conversationId);
            const wasCurrent =
                currentConversationId != null
                && String(currentConversationId) === deletedId;

            closeDeleteModal({ restoreFocus: false });

            if (wasCurrent) {
                resetConversation();
                setActiveSidebarTab('new-chat');
            }

            await refreshConversationList();

            if (!wasCurrent && currentConversationId != null) {
                markConversationRowActive(currentConversationId);
            }
        } catch (error) {
            console.error('Failed to delete conversation:', error);
            deleteRequestPending = false;
            if (deleteModalConfirmBtn) {
                deleteModalConfirmBtn.disabled = false;
            }
            showDeleteModalError('Unable to delete this chat. Please try again.');
        }
    }

    function conversationTitle(conversation) {
        const title = String(conversation?.title || '').trim();
        return title !== '' ? title : 'Untitled chat';
    }

    function buildConversationListItem(conversation, pinned) {
        const id = conversation.conversation_id;
        const title = conversationTitle(conversation);
        const meta = formatRelativeLastActive(conversation.last_message_at);

        const li = document.createElement('li');
        const row = document.createElement('div');
        row.className = 'lml-chatbot-main__chat-row';
        row.setAttribute('data-lml-chat-item', '');
        row.dataset.chatTitle = title;
        row.dataset.pinned = pinned ? 'true' : 'false';
        row.dataset.conversationId = String(id);

        const selectBtn = document.createElement('button');
        selectBtn.type = 'button';
        selectBtn.className = 'lml-chatbot-main__chat-select lml-focus-ring';
        selectBtn.setAttribute('data-lml-chat-select', '');
        selectBtn.title = title;

        const icon = document.createElement('i');
        icon.className = 'bi bi-chat';
        icon.setAttribute('aria-hidden', 'true');

        const titleEl = document.createElement('span');
        titleEl.className = 'lml-chatbot-main__chat-title';
        titleEl.textContent = title;

        selectBtn.appendChild(icon);
        selectBtn.appendChild(titleEl);

        if (meta) {
            const metaEl = document.createElement('span');
            metaEl.className = 'lml-chatbot-main__chat-meta';
            metaEl.textContent = meta;
            selectBtn.appendChild(metaEl);
        }

        const pinBtn = document.createElement('button');
        pinBtn.type = 'button';
        pinBtn.className = 'lml-chatbot-main__chat-pin lml-focus-ring';
        pinBtn.setAttribute('data-lml-pin', '');
        pinBtn.setAttribute('aria-pressed', pinned ? 'true' : 'false');
        pinBtn.setAttribute('aria-label', pinned ? `Unpin ${title}` : `Pin ${title}`);
        pinBtn.title = pinned ? 'Unpin' : 'Pin';

        const pinIcon = document.createElement('i');
        pinIcon.className = pinned ? 'bi bi-pin-angle-fill' : 'bi bi-pin-angle';
        pinIcon.setAttribute('aria-hidden', 'true');
        pinBtn.appendChild(pinIcon);

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'lml-chatbot-main__chat-delete lml-focus-ring';
        deleteBtn.setAttribute('data-lml-chat-delete', '');
        deleteBtn.setAttribute('aria-label', `Delete ${title}`);
        deleteBtn.title = 'Delete chat';

        const deleteIcon = document.createElement('i');
        deleteIcon.className = 'bi bi-trash';
        deleteIcon.setAttribute('aria-hidden', 'true');
        deleteBtn.appendChild(deleteIcon);

        row.appendChild(selectBtn);
        row.appendChild(pinBtn);
        row.appendChild(deleteBtn);
        li.appendChild(row);

        return li;
    }

    function markConversationRowActive(conversationId) {
        const idStr = conversationId == null ? null : String(conversationId);
        getChatItems().forEach((item) => {
            const active = idStr !== null && item.dataset.conversationId === idStr;
            item.classList.toggle('lml-chatbot-main__chat-row--active', active);
            const selectBtn = item.querySelector('[data-lml-chat-select]');
            if (!selectBtn) {
                return;
            }
            if (active) {
                selectBtn.setAttribute('aria-current', 'page');
            } else {
                selectBtn.removeAttribute('aria-current');
            }
        });
    }

    async function refreshConversationList() {
        if (!recentList && !pinnedList) {
            return;
        }

        try {
            const response = await fetch('/chatbot/conversations', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error('Failed to load conversations: ' + response.status);
            }

            const conversations = await response.json();
            const rows = Array.isArray(conversations) ? conversations : [];

            if (pinnedList) {
                pinnedList.innerHTML = '';
            }
            if (recentList) {
                recentList.innerHTML = '';
            }

            const pinned = rows.filter((c) => c && (c.is_pinned === true || c.is_pinned === 1));
            const recent = rows
                .filter((c) => c && !(c.is_pinned === true || c.is_pinned === 1))
                .slice(0, RECENT_HISTORY_LIMIT);

            pinned.forEach((conversation) => {
                if (pinnedList && conversation.conversation_id != null) {
                    pinnedList.appendChild(buildConversationListItem(conversation, true));
                }
            });

            recent.forEach((conversation) => {
                if (recentList && conversation.conversation_id != null) {
                    recentList.appendChild(buildConversationListItem(conversation, false));
                }
            });

            if (currentConversationId != null) {
                markConversationRowActive(currentConversationId);
            }

            updateEmptyStates();
        } catch (error) {
            console.error('Failed to refresh conversation list:', error);
            updateEmptyStates();
        }
    }

    function mapHistorySenderToRole(sender) {
        const value = String(sender || '').trim().toLowerCase();
        if (value === 'resident') {
            return 'user';
        }
        if (value === 'chatbot') {
            return 'assistant';
        }
        return null;
    }

    async function openConversationFromHistory(item) {
        const conversationId = item?.dataset?.conversationId;
        if (!conversationId || !messages) {
            return;
        }

        const loadId = ++historyLoadToken;
        selectChatItem(item);
        setActiveSidebarTab('history');
        setHistoryOpen(true);
        closeMobileSidebar();

        if (demoReplyTimer) {
            window.clearTimeout(demoReplyTimer);
            demoReplyTimer = null;
        }
        removeTypingIndicator();
        messages.innerHTML = '';
        showTypingIndicator();

        try {
            const response = await fetch(
                `/chatbot/conversation/${encodeURIComponent(conversationId)}/history`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                }
            );

            if (!response.ok) {
                throw new Error('Failed to load history: ' + response.status);
            }

            const data = await response.json();
            if (loadId !== historyLoadToken) {
                return;
            }

            removeTypingIndicator();
            messages.innerHTML = '';

            const historyMessages = Array.isArray(data.messages) ? data.messages : [];
            historyMessages.forEach((message) => {
                const role = mapHistorySenderToRole(message.sender);
                if (!role) {
                    return;
                }
                appendMessage(
                    role,
                    String(message.message_text || ''),
                    [],
                    null,
                    message.language || null,
                    false,
                    message.sent_at || message.created_at || null
                );
            });

            // Existing conversation only — never create a new one here.
            currentConversationId = Number(data.conversation_id) || Number(conversationId);
            markConversationRowActive(currentConversationId);

            if (messages) {
                messages.scrollTop = messages.scrollHeight;
            }
        } catch (error) {
            if (loadId !== historyLoadToken) {
                return;
            }
            removeTypingIndicator();
            messages.innerHTML = '';
            appendMessage(
                'assistant',
                'Sorry, we could not load that conversation. Please try again.'
            );
            console.error('Failed to open conversation history:', error);
        }
    }

    function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function renderInlineFormatting(str) {
    let escaped = escapeHtml(String(str || ''));

    // Explicit **bold**
    escaped = escaped.replace(
        /\*\*(.+?)\*\*/g,
        '<strong>$1</strong>'
    );

    // Bold text before " - "
    escaped = escaped.replace(
        /^([^–—-]{2,50})\s+-\s+/u,
        '<strong>$1</strong> – '
    );

    // Bold numbered labels such as "1. Exercise"
    escaped = escaped.replace(
        /^(\d+[.)])\s+/u,
        '<strong>$1</strong> '
    );

    return escaped;
}

/**
 * Per-language health-footer copy. Text mirrors the exact application
 * strings — the "no context" values must match RagService::noContextMessage()
 * verbatim (normalized lowercase) so the frontend can tell a genuine "I
 * don't have enough information" reply apart from a substantive answer
 * without the backend needing to add a dedicated flag for it.
 */
const HEALTH_FOOTER_COPY = {
    bcl: {
        noteLabel: 'NOTE:',
        noteBody:
            'Nagtatao sana sa health information, diri nag bubulong. Kin agko ika namamatian, mas maray na magpa kunsulta ika sa health center o kaya ospital.',
        consultOnly:
            'Kin agko ika namamatian, mas maray na magpa kunsulta ika sa health center o kaya ospital.',
        noContextNormalized:
            'uda ako sapat na impormasyon manungod sa unga mo. uliton ulit.',
    },
    tl: {
        noteLabel: 'PAALALA:',
        noteBody:
            'Nagbibigay lamang ito ng impormasyong pangkalusugan at hindi kapalit ng pagsusuri, diagnosis, o paggamot mula sa isang health professional. Kung may nararamdaman kang hindi maganda o nag-aalala ka tungkol sa iyong kalusugan, mas mabuting kumonsulta sa health center, doktor, o ospital.',
        consultOnly:
            'Kung may nararamdaman kang hindi maganda o nag-aalala ka tungkol sa iyong kalusugan, mas mabuting kumonsulta sa health center, doktor, o ospital.',
        noContextNormalized:
            'wala akong sapat na impormasyon tungkol dito. pakisubukang muli.',
    },
    en: {
        noteLabel: 'NOTE:',
        noteBody:
            'This chatbot provides health information only and is not a substitute for professional medical evaluation, diagnosis, or treatment. If you are feeling unwell or are concerned about your health, it is best to consult your health center, doctor, or hospital.',
        consultOnly:
            'If you are feeling unwell or are concerned about your health, it is best to consult your health center, doctor, or hospital.',
        noContextNormalized:
            "i don't have enough information on that yet. could you try rephrasing?",
    },
};

function normalizeHealthDisplayText(text) {
    return String(text || '')
        .replace(/\s+/gu, ' ')
        .trim()
        .toLowerCase();
}

function isHealthNoContextAnswer(language, text, points = []) {
    const copy = HEALTH_FOOTER_COPY[language];
    if (!copy) {
        return false;
    }

    return (
        normalizeHealthDisplayText(text) === copy.noContextNormalized &&
        (!Array.isArray(points) || points.length === 0)
    );
}

/**
 * Appends the language-appropriate "not a substitute for professional
 * care" safety footer to an assistant bubble. Frontend-presentation only —
 * this text never comes from Gemma/RagService and is never part of the
 * stored message content. Generalized from the original Bikol-only
 * implementation; behavior/text for bcl is unchanged.
 */
function appendHealthPresentationFooter(bubble, language, text, points = [], isConversation = false) {
    const copy = HEALTH_FOOTER_COPY[language];
    if (!bubble || !copy) {
        return;
    }

    if (isConversation === true) {
        return;
    }

    if (bubble.querySelector('[data-lml-health-footer]')) {
        return;
    }

    const existing = normalizeHealthDisplayText(bubble.textContent);
    const noMatch = isHealthNoContextAnswer(language, text, points);
    const hasAnswer = normalizeHealthDisplayText(text) !== '';
    const hasPoints = Array.isArray(points) && points.length > 0;

    if (!hasAnswer && !hasPoints) {
        return;
    }

    if (noMatch && existing.includes(normalizeHealthDisplayText(copy.consultOnly))) {
        return;
    }

    if (
        !noMatch &&
        existing.includes(normalizeHealthDisplayText(copy.noteBody))
    ) {
        return;
    }

    const footer = document.createElement('div');
    footer.className = 'lml-chatbot-main__bubble-footer';
    footer.setAttribute('data-lml-health-footer', noMatch ? 'consult' : 'note');

    if (noMatch) {
        const body = document.createElement('p');
        body.className = 'lml-chatbot-main__bubble-footer-text';
        body.textContent = copy.consultOnly;
        footer.appendChild(body);
    } else {
        const label = document.createElement('p');
        label.className = 'lml-chatbot-main__bubble-footer-label';
        label.textContent = copy.noteLabel;

        const body = document.createElement('p');
        body.className = 'lml-chatbot-main__bubble-footer-text';
        body.textContent = copy.noteBody;

        footer.appendChild(label);
        footer.appendChild(body);
    }

    bubble.appendChild(footer);
}

/**
 * Split chat message text into display paragraphs.
 * Prefers backend newline boundaries; for assistant answers without
 * delimiters, falls back to sentence breaks after letter + .!? only
 * (avoids decimals, mm Hg values, and "1. Item" numbered labels).
 */
function splitMessageParagraphs(text, role = 'assistant') {
    const raw = String(text || '').trim();
    if (!raw) {
        return [];
    }

    let blocks = raw
        .split(/\n{2,}/)
        .map((part) => part.trim())
        .filter(Boolean);

    blocks = blocks.flatMap((block) =>
        block
            .split(/\n+/)
            .map((part) => part.trim())
            .filter(Boolean)
    );

    if (role !== 'assistant') {
        return blocks;
    }

    return blocks.flatMap((block) => {
        // Keep numbered-list style lines intact (e.g. "1. Exercise...").
        if (/^\d+[\.)]\s/u.test(block) || /\n\s*\d+[\.)]\s/u.test(block)) {
            return [block];
        }

        // Split only after a letter + sentence punctuation, before a capital.
        const sentences = block
            .split(/(?<=\p{L}[.!?])\s+(?=\p{Lu})/u)
            .map((part) => part.trim())
            .filter(Boolean);

        return sentences.length > 0 ? sentences : [block];
    });
}

function appendMessage(role, text, points = [], title = null, language = null, isConversation = false, sentAt = null) {
    if (!messages) {
        return null;
    }

    const wrapper = document.createElement('div');
    wrapper.className = `lml-chatbot-main__message lml-chatbot-main__message--${role}`;

    const bubble = document.createElement('div');
    bubble.className = 'lml-chatbot-main__bubble';

    if (role === 'assistant' && title) {
        const heading = document.createElement('div');
        heading.className = 'lml-chatbot-main__bubble-title';

        const strong = document.createElement('strong');
        strong.textContent = title;

        heading.appendChild(strong);
        bubble.appendChild(heading);
    }

    const paragraphs = splitMessageParagraphs(text, role);

    paragraphs.forEach((paragraphText, index) => {
        const body = document.createElement('p');
        body.className = 'lml-chatbot-main__bubble-text';
        if (index > 0) {
            body.style.marginTop = '0.65rem';
        }
        body.innerHTML = renderInlineFormatting(paragraphText);
        bubble.appendChild(body);
    });

    if (Array.isArray(points) && points.length > 0) {
        const list = document.createElement('ul');
        list.className = 'lml-chatbot-main__bubble-list';
        points.forEach((point) => {
            const li = document.createElement('li');
            li.innerHTML = renderInlineFormatting(point);
            list.appendChild(li);
        });
        bubble.appendChild(list);
    }

    if (role === 'assistant') {
        appendHealthPresentationFooter(bubble, language, text, points, isConversation === true);
    }

    const time = document.createElement('time');
    time.className = 'lml-chatbot-main__bubble-time';
    const stamp = sentAt ? new Date(sentAt) : new Date();
    time.textContent = Number.isNaN(stamp.getTime()) ? formatTime() : formatTime(stamp);
    if (sentAt && !Number.isNaN(stamp.getTime())) {
        time.dateTime = stamp.toISOString();
    }
    bubble.appendChild(time);

    wrapper.appendChild(bubble);
    messages.appendChild(wrapper);
    messages.scrollTop = messages.scrollHeight;

    return wrapper;
}

    /**
     * Temporary assistant typing bubble.
     * Reusable later when wiring a real AI backend stream/response.
     */
    function showTypingIndicator() {
        if (!messages) {
            return null;
        }

        removeTypingIndicator();

        const wrapper = document.createElement('div');
        wrapper.className =
            'lml-chatbot-main__message lml-chatbot-main__message--assistant lml-chatbot-main__message--typing';
        wrapper.setAttribute('data-lml-typing-indicator', '');

        const statusDot = document.createElement('span');
        statusDot.className = 'lml-chatbot-main__message-dot';
        statusDot.setAttribute('aria-hidden', 'true');

        const bubble = document.createElement('div');
        bubble.className = 'lml-chatbot-main__bubble';

        const statusText = document.createElement('span');
        statusText.className = 'visually-hidden';
        statusText.textContent = 'Assistant is typing…';

        const dots = document.createElement('div');
        dots.className = 'lml-chatbot-main__typing-dots';
        dots.setAttribute('aria-hidden', 'true');
        dots.innerHTML = '<span></span><span></span><span></span>';

        bubble.appendChild(statusText);
        bubble.appendChild(dots);
        wrapper.appendChild(statusDot);
        wrapper.appendChild(bubble);
        messages.appendChild(wrapper);
        messages.scrollTop = messages.scrollHeight;

        typingIndicator = wrapper;
        return wrapper;
    }

    function removeTypingIndicator() {
        if (typingIndicator && typingIndicator.parentNode) {
            typingIndicator.parentNode.removeChild(typingIndicator);
        }
        typingIndicator = null;
        if (messages) {
            const leftover = messages.querySelector('[data-lml-typing-indicator]');
            if (leftover) {
                leftover.remove();
            }
        }
    }

    function resetConversation() {
        if (!messages) {
            return;
        }
        if (demoReplyTimer) {
            window.clearTimeout(demoReplyTimer);
            demoReplyTimer = null;
        }
        removeTypingIndicator();
        clearActiveConversationSelection();
        // Start a fresh conversation on the next ask (no topic carry-over).
        currentConversationId = null;
        messages.innerHTML = '';
        appendMessage(
            'assistant',
            'This is health chatbot for health center. How can I help you today?'
        );
        if (composerInput) {
            composerInput.value = '';
            composerInput.style.height = 'auto';
            composerInput.focus();
        }
    }

    function autoGrowTextarea() {
        if (!composerInput) {
            return;
        }
        composerInput.style.height = 'auto';
        composerInput.style.height = `${Math.min(composerInput.scrollHeight, 120)}px`;
    }

    if (desktopToggle) {
        desktopToggle.addEventListener('click', () => {
            if (isMobileViewport()) {
                return;
            }
            setDesktopCollapsed(!root.classList.contains('is-sidebar-collapsed'));
        });
    }

    if (mobileToggle) {
        mobileToggle.addEventListener('click', () => {
            lastMobileToggle = mobileToggle;
            setMobileOpen(!root.classList.contains('is-mobile-open'));
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeMobileSidebar);
    }

    window.addEventListener('resize', () => {
        if (!isMobileViewport()) {
            if (root.classList.contains('is-mobile-open')) {
                setMobileOpen(false);
            }
            document.body.style.overflow = '';
            document.removeEventListener('keydown', onMobileSidebarKeydown);
        }
    });

    if (historyToggle) {
        historyToggle.addEventListener('click', () => {
            const collapsed =
                !isMobileViewport() && root.classList.contains('is-sidebar-collapsed');

            setActiveSidebarTab('history');

            if (collapsed) {
                setDesktopCollapsed(false);
                setHistoryOpen(true);
                return;
            }

            const open = historyToggle.getAttribute('aria-expanded') !== 'true';
            setHistoryOpen(open);
        });
    }

    if (householdBtn) {
        householdBtn.addEventListener('click', () => {
            setActiveSidebarTab('household');
            /* Request Household Record uses a real href; Request Sent is status-only. */
        });
    }

    root.addEventListener('click', (event) => {
        const deleteBtn = event.target.closest('[data-lml-chat-delete]');
        if (deleteBtn && root.contains(deleteBtn)) {
            event.preventDefault();
            event.stopPropagation();
            const item = deleteBtn.closest('[data-lml-chat-item]');
            if (item) {
                openDeleteModal(item, deleteBtn);
            }
            return;
        }

        const pinBtn = event.target.closest('[data-lml-pin]');
        if (pinBtn && root.contains(pinBtn)) {
            event.preventDefault();
            event.stopPropagation();
            const item = pinBtn.closest('[data-lml-chat-item]');
            if (item) {
                togglePin(item);
            }
            return;
        }

        const selectBtn = event.target.closest('[data-lml-chat-select]');
        if (selectBtn && root.contains(selectBtn)) {
            const item = selectBtn.closest('[data-lml-chat-item]');
            if (item) {
                if (item.dataset.conversationId) {
                    openConversationFromHistory(item);
                } else {
                    selectChatItem(item);
                }
            }
        }
    });

    deleteModalCancelBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            closeDeleteModal({ restoreFocus: true });
        });
    });

    deleteModalBackdrop?.addEventListener('click', () => {
        closeDeleteModal({ restoreFocus: true });
    });

    deleteModalConfirmBtn?.addEventListener('click', () => {
        confirmDeleteConversation();
    });

    document.addEventListener('keydown', (event) => {
        if (!deleteModal || deleteModal.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeDeleteModal({ restoreFocus: true });
            return;
        }

        if (event.key !== 'Tab' || !deleteModalPanel) {
            return;
        }

        const focusable = Array.from(
            deleteModalPanel.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )
        ).filter((el) => !el.hidden && el.getClientRects().length > 0);

        if (focusable.length === 0) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    if (newChatBtn) {
        newChatBtn.addEventListener('click', () => {
            setActiveSidebarTab('new-chat');
            resetConversation();
            closeMobileSidebar();
        });
    }

    root.querySelectorAll('[data-lml-lang]').forEach((btn) => {
        btn.addEventListener('click', () => {
            root.querySelectorAll('[data-lml-lang]').forEach((other) => {
                const active = other === btn;
                other.classList.toggle('lml-chatbot-main__lang-btn--active', active);
                other.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            if (langLive) {
                langLive.textContent = `Language changed to ${btn.dataset.lmlLang}`;
            }
        });
    });

function syncLanguageButton(languageCode) {
    const labelMap = {
        en: 'English',
        tl: 'Tagalog',
        bcl: 'Bikol – Iriga',
    };

    const targetLabel = labelMap[languageCode];

    if (!targetLabel) {
        return;
    }

    root.querySelectorAll('[data-lml-lang]').forEach((btn) => {
        const active = btn.dataset.lmlLang === targetLabel;

        btn.classList.toggle(
            'lml-chatbot-main__lang-btn--active',
            active
        );

        btn.setAttribute(
            'aria-pressed',
            active ? 'true' : 'false'
        );
    });

    if (langLive) {
        langLive.textContent = `Language changed to ${targetLabel}`;
    }
}

function getSelectedLanguageCode() {
    const activeBtn = root.querySelector('[data-lml-lang].lml-chatbot-main__lang-btn--active');
    const label = activeBtn ? activeBtn.dataset.lmlLang : 'English';
    const map = {
        'English': 'en',
        'Tagalog': 'tl',
        'Bikol – Iriga': 'bcl',
    };
    return map[label] || 'en';
}

if (composer && composerInput) {
    composer.addEventListener('submit', async (event) => {
        event.preventDefault();
        const text = composerInput.value.trim();
        if (!text) {
            return;
        }

        appendMessage('user', text);
        composerInput.value = '';
        autoGrowTextarea();
        showTypingIndicator();

        try {
            const response = await fetch('/chatbot/ask', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    question: text,
                    language: getSelectedLanguageCode(),
                    conversation_id: currentConversationId,
                }),
            });

            if (!response.ok) {
                throw new Error('Request failed: ' + response.status);
            }

            const data = await response.json();
currentConversationId = data.conversation_id;

if (data.language) {
    syncLanguageButton(data.language);
}

removeTypingIndicator();
appendMessage('assistant', data.answer, data.points, data.title, data.language, data.is_conversation === true);
            await refreshConversationList();
            markConversationRowActive(currentConversationId);
        } catch (error) {
            removeTypingIndicator();
            appendMessage(
                'assistant',
                'Sorry, something went wrong. Please try again in a moment.'
            );
            console.error('Chatbot request failed:', error);
        }
    });

    composerInput.addEventListener('input', autoGrowTextarea);
    composerInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            composer.requestSubmit();
        }
    });
}

    updateEmptyStates();
    refreshConversationList();

    initChatbotNotifications(root);
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.location.hash.startsWith('#member-')) {
        history.replaceState(
            null,
            '',
            window.location.pathname + window.location.search
        );
    }

    document.querySelectorAll('[data-lml-chatbot-main]').forEach((root) => {
        initChatbotMain(root);
    });
});
