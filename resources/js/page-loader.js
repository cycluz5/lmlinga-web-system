/**
 * Full-page loading overlay for server round-trips (login, register, …).
 *
 * Opt in per form or link with `data-lml-page-loader`; the attribute value is
 * the status text (defaults to "Loading…"). Page-specific submit handlers run
 * first, so a submit they cancel (client-side validation) never shows it.
 */

const DEFAULT_MESSAGE = 'Loading…';

let overlay = null;

function ensureOverlay() {
    if (overlay) {
        return overlay;
    }

    overlay = document.createElement('div');
    overlay.className = 'lml-page-loader';
    overlay.hidden = true;
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'polite');
    overlay.innerHTML =
        '<div class="lml-page-loader__panel">' +
        '<span class="lml-page-loader__spinner" aria-hidden="true"></span>' +
        '<p class="lml-page-loader__text" data-lml-page-loader-text></p>' +
        '</div>';
    document.body.appendChild(overlay);

    return overlay;
}

export function showPageLoader(message = DEFAULT_MESSAGE) {
    const el = ensureOverlay();
    el.querySelector('[data-lml-page-loader-text]').textContent = message || DEFAULT_MESSAGE;
    el.hidden = false;
    document.body.setAttribute('aria-busy', 'true');
}

export function hidePageLoader() {
    if (overlay) {
        overlay.hidden = true;
    }
    document.body.removeAttribute('aria-busy');
    document
        .querySelectorAll('form[data-lml-page-loader][data-lml-submitting]')
        .forEach((form) => form.removeAttribute('data-lml-submitting'));
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-lml-page-loader')) {
        return;
    }

    if (event.defaultPrevented) {
        return;
    }

    // Block a second submit (double click / Enter) while the first is in flight.
    if (form.hasAttribute('data-lml-submitting')) {
        event.preventDefault();
        return;
    }

    form.setAttribute('data-lml-submitting', '');
    showPageLoader(form.getAttribute('data-lml-page-loader'));
});

document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[data-lml-page-loader][href]');
    if (
        !link ||
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey ||
        (link.target && link.target !== '_self')
    ) {
        return;
    }

    showPageLoader(link.getAttribute('data-lml-page-loader'));
});

// Back/forward cache restores the page as it was left — overlay included.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        hidePageLoader();
    }
});
