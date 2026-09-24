/**
 * Health Records → Death resident form.
 * Client-side submit disable is UX only; server validation is authoritative.
 */

function formatDisplayDate(iso) {
    if (!iso) {
        return '';
    }
    const parts = String(iso).split('-');
    if (parts.length !== 3) {
        return iso;
    }
    const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    if (Number.isNaN(date.getTime())) {
        return iso;
    }
    return date.toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });
}

function basenameOnly(value) {
    const raw = String(value || '');
    const parts = raw.split(/[/\\]/);
    return parts[parts.length - 1] || '';
}

function focusable(container) {
    return Array.from(
        container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
    ).filter((el) => !el.hasAttribute('hidden') && el.offsetParent !== null);
}

function initDeathRecordForm(root) {
    const form = root.querySelector('[data-death-submit-form]');
    const submit = root.querySelector('[data-death-submit]');
    const cause = root.querySelector('[data-death-cause]');
    const date = root.querySelector('[data-death-date]');
    const certNo = root.querySelector('[data-death-certificate-no]');
    const registryNo = root.querySelector('[data-death-registry-no]');
    const fileInput = root.querySelector('[data-death-certificate-input]');
    const fileStatus = root.querySelector('[data-death-file-status]');
    const dialogRoot = root.querySelector('[data-death-confirm]');
    const panel = root.querySelector('[data-death-confirm-panel]');
    const message = root.querySelector('[data-death-confirm-message]');
    const cancel = root.querySelector('[data-death-confirm-cancel]');
    const confirm = root.querySelector('[data-death-confirm-submit]');
    const backdrop = root.querySelector('[data-death-confirm-backdrop]');
    const residentName = root.dataset.residentName || 'this resident';
    const memberId = root.dataset.memberId || '';
    const residentSex = root.dataset.residentSex || '';
    const residentIdentity = [residentName, residentSex, memberId]
        .filter((part) => part !== '')
        .join(', ');

    if (!form || !submit) {
        return;
    }

    let lastFocus = null;
    let allowSubmit = false;

    const syncSubmit = () => {
        const hasFile = Boolean(fileInput?.files && fileInput.files[0]);
        const complete =
            Boolean(cause?.value.trim()) &&
            Boolean(date?.value.trim()) &&
            Boolean(registryNo?.value.trim()) &&
            Boolean(certNo?.value.trim()) &&
            hasFile;
        submit.disabled = !complete;
        submit.setAttribute('aria-disabled', complete ? 'false' : 'true');
    };

    fileInput?.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        if (fileStatus) {
            fileStatus.textContent = file
                ? `Selected: ${basenameOnly(file.name)}`
                : 'No file selected.';
        }
        syncSubmit();
    });

    [cause, date, registryNo, certNo].forEach((field) => {
        field?.addEventListener('input', syncSubmit);
        field?.addEventListener('change', syncSubmit);
    });

    const closeDialog = () => {
        if (!dialogRoot) {
            return;
        }
        dialogRoot.hidden = true;
        document.removeEventListener('keydown', onKeyDown, true);
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    };

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog();
            return;
        }
        if (event.key !== 'Tab' || !panel) {
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

    const openDialog = () => {
        if (!dialogRoot || !panel) {
            return;
        }
        lastFocus = document.activeElement;
        const displayDate = formatDisplayDate(date?.value || '');
        if (message) {
            message.textContent =
                `You are about to record ${residentIdentity} as deceased on ${displayDate || 'the entered date'}. ` +
                'This action requires Admin verification before it takes effect. Continue?';
        }
        document.body.appendChild(dialogRoot);
        dialogRoot.hidden = false;
        document.addEventListener('keydown', onKeyDown, true);
        window.setTimeout(() => panel.focus(), 0);
    };

    form.addEventListener('submit', (event) => {
        if (allowSubmit) {
            return;
        }
        event.preventDefault();
        if (submit.disabled) {
            return;
        }
        openDialog();
    });

    cancel?.addEventListener('click', closeDialog);
    backdrop?.addEventListener('click', closeDialog);
    confirm?.addEventListener('click', () => {
        allowSubmit = true;
        closeDialog();
        form.submit();
    });

    syncSubmit();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-death-form]').forEach((root) => {
        initDeathRecordForm(root);
    });
});
