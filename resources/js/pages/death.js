/**
 * Household Profiling — Death Information (Phase 1 UI).
 * Session/preview only. Updates selected certificate filename display.
 */

function basenameOnly(value) {
    const raw = String(value || '');
    const parts = raw.split(/[/\\]/);
    return parts[parts.length - 1] || '';
}

function initDeath(root) {
    const input = root.querySelector('[data-death-certificate-input]');
    const status = root.querySelector('[data-death-file-status]');
    const nameEl = root.querySelector('[data-death-file-name]');
    const form = input?.form
        || root.querySelector('form[enctype="multipart/form-data"]')
        || root.querySelector('form');

    if (form) {
        form.addEventListener('submit', (event) => {
            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                event.preventDefault();
                const toast = root.querySelector('[data-death-toast], [data-hh-member-view-toast], .lml-death__empty-copy');
                const message = 'Death certificate uploads require a connection.';
                if (window.LmlingaOffline?.emit) {
                    window.LmlingaOffline.emit('lmlinga:notice', { message, source: 'death' });
                }
                if (toast) {
                    toast.textContent = message;
                    toast.hidden = false;
                }
            }
        });
    }

    if (!input || !status) {
        return;
    }

    input.addEventListener('change', () => {
        const file = input.files && input.files[0] ? input.files[0] : null;
        if (!file) {
            if (!nameEl || !nameEl.textContent.trim()) {
                status.hidden = true;
            }
            return;
        }

        const safeName = basenameOnly(file.name);
        status.hidden = false;
        status.replaceChildren();
        status.append(
            document.createTextNode('Selected for this session (preview only): ')
        );
        const strong = document.createElement('span');
        strong.setAttribute('data-death-file-name', '');
        strong.textContent = safeName;
        status.append(strong);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-death]').forEach((root) => {
        initDeath(root);
    });
});
