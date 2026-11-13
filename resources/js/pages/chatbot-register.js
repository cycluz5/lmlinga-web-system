/**
 * Resident chatbot registration — Gmail-only email check before submit.
 * Mirrors ResidentRegistrationController::GMAIL_PATTERN; the server enforces the same rule.
 */

export const GMAIL_PATTERN = /^(?=[a-z0-9.]{6,30}@)(?!.*\.\.)[a-z0-9](?:[a-z0-9.]*[a-z0-9])?@gmail\.com$/;

export const GMAIL_MESSAGE = 'Please use a Gmail address ending in @gmail.com to register.';

export function normalizeEmail(value) {
    return String(value ?? '').trim().toLowerCase();
}

export function isGmailAddress(value) {
    return GMAIL_PATTERN.test(normalizeEmail(value));
}

function setGmailError(input, errorEl, message) {
    const serverError = input.ownerDocument.getElementById('email-error');
    const describedBy = new Set((input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));

    if (message) {
        errorEl.textContent = message;
        errorEl.hidden = false;
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');
        describedBy.add(errorEl.id);
        // Avoid showing the same server message twice.
        if (serverError && serverError.textContent.trim() === GMAIL_MESSAGE) {
            serverError.hidden = true;
        }
    } else {
        errorEl.textContent = '';
        errorEl.hidden = true;
        describedBy.delete(errorEl.id);
        if (serverError && serverError.textContent.trim() === GMAIL_MESSAGE) {
            serverError.hidden = true;
            describedBy.delete(serverError.id);
        }
        if (!serverError || serverError.hidden) {
            input.classList.remove('is-invalid');
            input.removeAttribute('aria-invalid');
        }
    }

    if (describedBy.size > 0) {
        input.setAttribute('aria-describedby', [...describedBy].join(' '));
    } else {
        input.removeAttribute('aria-describedby');
    }
}

/**
 * Binds the check to a registration form. Returns true when bound.
 * The form-level submit listener runs before the document-level page loader,
 * so a blocked submit never shows the "Creating your account…" overlay.
 */
export function initGmailOnlyRegistration(form) {
    const input = form?.querySelector('[data-lml-register-email]');
    const errorEl = form?.querySelector('[data-lml-register-email-error]');
    if (!input || !errorEl) {
        return false;
    }

    form.addEventListener('submit', (event) => {
        if (isGmailAddress(input.value)) {
            setGmailError(input, errorEl, '');
            return;
        }
        event.preventDefault();
        setGmailError(input, errorEl, GMAIL_MESSAGE);
        input.focus();
    });

    input.addEventListener('input', () => {
        if (!errorEl.hidden && isGmailAddress(input.value)) {
            setGmailError(input, errorEl, '');
        }
    });

    return true;
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-lml-register-form]').forEach((form) => initGmailOnlyRegistration(form));
    });
}
