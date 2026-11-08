function setFieldError(input, errorEl, message) {
    if (!input || !errorEl) {
        return;
    }

    if (message) {
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');
        errorEl.hidden = false;
        errorEl.textContent = message;
        return;
    }

    input.classList.remove('is-invalid');
    input.removeAttribute('aria-invalid');
    errorEl.hidden = true;
    errorEl.textContent = '';
}

function hasLetter(value) {
    return /[A-Za-z]/.test(value);
}

function hasNumber(value) {
    return /\d/.test(value);
}

function hasSymbol(value) {
    return /[^A-Za-z0-9]/.test(value);
}

function passwordMeetsRequirements(value) {
    return value.length >= 8 && hasLetter(value) && hasNumber(value) && hasSymbol(value);
}

const PASSWORD_HINT = 'Use at least 8 characters with letters, numbers, and a symbol.';

function initChangePasswordForm(root) {
    const form = root.querySelector('[data-lml-change-password-form]');
    if (!form) {
        return;
    }

    const password = form.querySelector('#new_password');
    const confirm = form.querySelector('#new_password_confirmation');
    const passwordError = form.querySelector('#new_password-error');
    const confirmError = form.querySelector('#new_password_confirmation-error');
    const alertEl = root.querySelector('[data-change-password-alert]');

    form.addEventListener('submit', (event) => {
        const passwordValue = password?.value || '';
        const confirmValue = confirm?.value || '';
        let firstInvalid = null;

        if (!passwordValue) {
            setFieldError(password, passwordError, 'New Password is required.');
            firstInvalid = password;
        } else if (!passwordMeetsRequirements(passwordValue)) {
            setFieldError(password, passwordError, PASSWORD_HINT);
            firstInvalid = password;
        } else {
            setFieldError(password, passwordError, '');
        }

        if (!confirmValue) {
            setFieldError(confirm, confirmError, 'Confirm New Password is required.');
            firstInvalid = firstInvalid || confirm;
        } else if (confirmValue !== passwordValue) {
            setFieldError(confirm, confirmError, 'Password and Confirm New Password must match.');
            firstInvalid = firstInvalid || confirm;
        } else {
            setFieldError(confirm, confirmError, '');
        }

        if (firstInvalid) {
            event.preventDefault();
            if (alertEl) {
                alertEl.hidden = false;
                alertEl.textContent = 'Replace the temporary password before continuing.';
            }
            firstInvalid.focus();
            return;
        }

        if (alertEl) {
            alertEl.hidden = true;
            alertEl.textContent = '';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lml-change-password-page').forEach((root) => {
        initChangePasswordForm(root);
    });
});
