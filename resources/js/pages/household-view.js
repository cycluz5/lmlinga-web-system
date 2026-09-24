/**
 * Household Profiling — View Household (Phase 3) UI interactions.
 * Demo only. Nothing is persisted. Member delete never removes rows.
 * Offline navigation for dynamic member/amenities links is a client-side cache check.
 */

import {
    HH_NAV_SELECTOR,
    handleHouseholdNavClick,
} from '../offline/offline-nav-guard.js';

function showToast(root, message) {
    const toast = root.querySelector('[data-hh-view-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    const timers = typeof window !== 'undefined' ? window : globalThis;
    if (typeof timers.clearTimeout === 'function') {
        timers.clearTimeout(showToast._timer);
    }
    if (typeof timers.setTimeout === 'function') {
        showToast._timer = timers.setTimeout(() => {
            toast.hidden = true;
            toast.textContent = '';
        }, 3600);
    }
}

function getFocusable(container) {
    return Array.from(
        container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

function lockPageScroll() {
    document.body.dataset.hhViewScrollLocked = 'true';
    document.body.style.overflow = 'hidden';
}

function unlockPageScroll() {
    if (document.body.dataset.hhViewScrollLocked !== 'true') {
        return;
    }

    delete document.body.dataset.hhViewScrollLocked;
    document.body.style.overflow = '';
}

function openDialog(root, memberName, returnFocusEl) {
    const backdrop = root.querySelector('[data-hh-view-dialog]');
    const panel = root.querySelector('[data-hh-view-dialog-panel]');
    const cancelBtn = root.querySelector('[data-hh-view-dialog-cancel]');
    if (!backdrop || !panel) {
        return;
    }

    root._hhViewMember = memberName;
    root._hhViewReturnFocus = returnFocusEl || null;

    backdrop.hidden = false;
    lockPageScroll();

    if (cancelBtn) {
        cancelBtn.focus();
    } else {
        panel.focus();
    }
}

function closeDialog(root, { restoreFocus = true } = {}) {
    const backdrop = root.querySelector('[data-hh-view-dialog]');
    if (!backdrop) {
        return;
    }

    backdrop.hidden = true;
    unlockPageScroll();
    root._hhViewMember = null;

    if (restoreFocus && root._hhViewReturnFocus instanceof HTMLElement) {
        root._hhViewReturnFocus.focus();
    }

    root._hhViewReturnFocus = null;
}

function trapFocus(event, panel) {
    if (event.key !== 'Tab') {
        return;
    }

    const focusables = getFocusable(panel);
    if (!focusables.length) {
        event.preventDefault();
        panel.focus();
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
        return;
    }

    if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function initHouseholdView(root) {
    const dialog = root.querySelector('[data-hh-view-dialog]');
    const dialogPanel = root.querySelector('[data-hh-view-dialog-panel]');
    const cancelBtn = root.querySelector('[data-hh-view-dialog-cancel]');
    const confirmBtn = root.querySelector('[data-hh-view-dialog-confirm]');

    root.addEventListener('click', (event) => {
        const navLink = event.target.closest(HH_NAV_SELECTOR);
        if (navLink && root.contains(navLink)) {
            void handleHouseholdNavClick(event, {
                root,
                link: navLink,
                toastSelector: '[data-hh-view-toast]',
            });
            return;
        }

        const btn = event.target.closest('[data-hh-view-action]');
        if (btn && root.contains(btn)) {
            const action = btn.getAttribute('data-hh-view-action');
            const name = btn.getAttribute('data-member-name') || 'this member';

            if (action === 'delete') {
                openDialog(root, name, btn);
            }

            return;
        }

        if (event.target === dialog) {
            closeDialog(root);
        }
    });

    cancelBtn?.addEventListener('click', () => {
        closeDialog(root);
    });

    confirmBtn?.addEventListener('click', () => {
        closeDialog(root);
        showToast(root, 'No member was deleted because this is the UI phase.');
    });

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && dialog && !dialog.hidden) {
            event.preventDefault();
            closeDialog(root);
            return;
        }

        if (dialog && !dialog.hidden && dialogPanel) {
            trapFocus(event, dialogPanel);
        }
    });
}

if (typeof document !== 'undefined') {
    const boot = () => {
        document.querySelectorAll('[data-lml-hh-view]').forEach((root) => {
            initHouseholdView(root);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}
