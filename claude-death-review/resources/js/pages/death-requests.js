/**
 * Admin Death Requests list filters and verify-page dialogs.
 */

function applyDeathRequestFilters(root) {
    const rows = Array.from(root.querySelectorAll('[data-dr-row]'));
    const empty = root.querySelector('[data-dr-empty]');
    const wrap = root.querySelector('[data-dr-table-wrap]');
    const searchInput = root.querySelector('[data-dr-search]');
    const statusSelect = root.querySelector('[data-dr-status]');

    const query = (searchInput?.value || '').trim().toLowerCase();
    const status = statusSelect?.value || 'all';

    let visible = 0;

    rows.forEach((row) => {
        const name = (row.dataset.drName || '').toLowerCase();
        const rowStatus = row.dataset.drStatus || '';
        const matchesSearch = !query || name.includes(query);
        const matchesStatus = status === 'all' || rowStatus === status;
        const show = matchesSearch && matchesStatus;
        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (empty) {
        empty.hidden = visible > 0;
    }
    if (wrap) {
        wrap.hidden = visible === 0;
    }
}

function focusable(container) {
    return Array.from(
        container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
    ).filter((el) => el.offsetParent !== null);
}

function bindDialog({ root, openBtn, dialogSelector, panelSelector, cancelSelector, backdropSelector }) {
    const dialog = root.querySelector(dialogSelector);
    const panel = root.querySelector(panelSelector);
    const cancel = root.querySelector(cancelSelector);
    const backdrop = root.querySelector(backdropSelector);
    const opener = root.querySelector(openBtn);
    if (!dialog || !panel) {
        return () => {};
    }

    let lastFocus = null;

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        const items = focusable(panel);
        if (items.length === 0) {
            return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    const close = () => {
        dialog.hidden = true;
        document.removeEventListener('keydown', onKeyDown, true);
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    };

    const open = () => {
        lastFocus = document.activeElement;
        document.body.appendChild(dialog);
        dialog.hidden = false;
        document.addEventListener('keydown', onKeyDown, true);
        const reason = panel.querySelector('[data-dr-rejection-reason]');
        window.setTimeout(() => (reason || panel).focus(), 0);
    };

    opener?.addEventListener('click', open);
    cancel?.addEventListener('click', close);
    backdrop?.addEventListener('click', close);

    return open;
}

function initDeathRequestsList(root) {
    root.querySelector('[data-dr-search]')?.addEventListener('input', () => applyDeathRequestFilters(root));
    root.querySelector('[data-dr-status]')?.addEventListener('change', () => applyDeathRequestFilters(root));
    applyDeathRequestFilters(root);
}

function initDeathVerify(root) {
    bindDialog({
        root,
        openBtn: '[data-dr-open-approve]',
        dialogSelector: '[data-dr-approve]',
        panelSelector: '[data-dr-approve-panel]',
        cancelSelector: '[data-dr-approve-cancel]',
        backdropSelector: '[data-dr-approve-backdrop]',
    });
    const openReject = bindDialog({
        root,
        openBtn: '[data-dr-open-reject]',
        dialogSelector: '[data-dr-reject]',
        panelSelector: '[data-dr-reject-panel]',
        cancelSelector: '[data-dr-reject-cancel]',
        backdropSelector: '[data-dr-reject-backdrop]',
    });

    if (root.dataset.drHasRejectErrors === 'true') {
        openReject();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-death-requests]').forEach((root) => {
        initDeathRequestsList(root);
    });
    document.querySelectorAll('[data-lml-death-verify]').forEach((root) => {
        initDeathVerify(root);
    });
});
