/**
 * Household Profiling — View Member Information (UI phase).
 * Demo toasts and delete confirmation only. Nothing is persisted.
 */

import { readMemberIdentityMap } from '../offline/offline-identity-map.js';
import { fillLocalMemberViewRoot } from '../offline/offline-local-member.js';
import { readPageActorId } from '../offline/offline-nav-guard.js';

function showToast(root, message) {
    const toast = root.querySelector('[data-hh-member-view-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showToast._timer);
    showToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 4200);
}

function getFocusable(container) {
    return Array.from(
        container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

function lockScroll() {
    document.body.dataset.hhMemberViewScrollLocked = 'true';
    document.body.style.overflow = 'hidden';
}

function unlockScroll() {
    if (document.body.dataset.hhMemberViewScrollLocked !== 'true') {
        return;
    }
    delete document.body.dataset.hhMemberViewScrollLocked;
    document.body.style.overflow = '';
}

function openDialog(root, triggerEl) {
    const backdrop = root.querySelector('[data-hh-member-view-dialog]');
    const panel = root.querySelector('[data-hh-member-view-dialog-panel]');
    const cancelBtn = root.querySelector('[data-hh-member-view-dialog-cancel]');
    if (!backdrop || !panel) {
        return;
    }

    root._hhMemberViewReturnFocus = triggerEl instanceof HTMLElement ? triggerEl : null;
    backdrop.hidden = false;
    lockScroll();
    cancelBtn?.focus();
}

function closeDialog(root, { restoreFocus = true } = {}) {
    const backdrop = root.querySelector('[data-hh-member-view-dialog]');
    if (!backdrop || backdrop.hidden) {
        return;
    }

    backdrop.hidden = true;
    unlockScroll();

    if (restoreFocus && root._hhMemberViewReturnFocus instanceof HTMLElement) {
        root._hhMemberViewReturnFocus.focus();
    }
    root._hhMemberViewReturnFocus = null;
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

function recordToastMessage(recordName) {
    const map = {
        'Child Immunization':
            'Child Immunization is not yet implemented in this UI phase. Navigation is prepared for a future module page.',
        'School-Based Immunization':
            'School-Based Immunization is not yet implemented in this UI phase. Navigation is prepared for a future module page.',
    };

    return map[recordName] || `${recordName} details are not yet implemented in this UI phase.`;
}

function setChildCareExpanded(trigger, panel, group, open) {
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.hidden = !open;
    group?.classList.toggle('is-expanded', open);
}

function initChildCareAccordion(root) {
    const trigger = root.querySelector('[data-hh-member-child-care-toggle]');
    const panel = root.querySelector('[data-hh-member-child-care-panel]');
    const group = root.querySelector('[data-hh-member-child-care-group]');
    if (!trigger || !panel) {
        return;
    }

    const routeLocked = trigger.getAttribute('data-route-locked') === 'true';

    trigger.addEventListener('click', () => {
        if (routeLocked) {
            setChildCareExpanded(trigger, panel, group, true);
            return;
        }

        const expanded = trigger.getAttribute('aria-expanded') === 'true';
        setChildCareExpanded(trigger, panel, group, !expanded);
    });
}

async function hydrateQueuedLocalMember(root) {
    if (root.getAttribute('data-offline-local-member') !== '1') {
        return;
    }
    const memberId = String(root.getAttribute('data-member-id') || '').trim();
    const householdNo = String(root.getAttribute('data-household-no') || '').trim();
    if (!memberId || !householdNo) {
        return;
    }
    try {
        const map = await readMemberIdentityMap({
            actorId: readPageActorId(),
            origin: typeof location !== 'undefined' ? location.origin : 'http://localhost',
        });
        const payload = map[memberId];
        if (!payload || typeof payload !== 'object') {
            return;
        }
        fillLocalMemberViewRoot(root, payload, householdNo, memberId);
    } catch {
        // Identity map is optional until the queued create is cached.
    }
}

function initMemberView(root) {
    const dialog = root.querySelector('[data-hh-member-view-dialog]');
    const dialogPanel = root.querySelector('[data-hh-member-view-dialog-panel]');
    const cancelBtn = root.querySelector('[data-hh-member-view-dialog-cancel]');
    const confirmBtn = root.querySelector('[data-hh-member-view-dialog-confirm]');
    const deleteBtn = root.querySelector('[data-hh-member-view-delete]');

    initChildCareAccordion(root);
    void hydrateQueuedLocalMember(root);

    const pendingModule = root.getAttribute('data-pending-health-module');
    if (pendingModule) {
        showToast(root, recordToastMessage(pendingModule));
        root.removeAttribute('data-pending-health-module');
    }

    deleteBtn?.addEventListener('click', () => {
        openDialog(root, deleteBtn);
    });

    cancelBtn?.addEventListener('click', () => {
        closeDialog(root);
    });

    confirmBtn?.addEventListener('click', () => {
        closeDialog(root);
        showToast(root, 'No member was deleted because this is the UI phase.');
    });

    root.addEventListener('click', (event) => {
        const recordBtn = event.target.closest('[data-hh-member-view-record]');
        if (recordBtn && root.contains(recordBtn)) {
            const name = recordBtn.getAttribute('data-hh-member-view-record') || 'This record';
            showToast(root, recordToastMessage(name));
            return;
        }

        if (event.target === dialog) {
            closeDialog(root);
        }
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

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hh-member-view]').forEach((root) => {
        initMemberView(root);
    });
});
