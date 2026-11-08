/**
 * Admin Create Health Worker account — client validation then real POST.
 * Server persists authentication account + role; profile completion is via Edit.
 */

const PASSWORD_HINT = 'Use at least 8 characters with letters, numbers, and a symbol.';

function hasLetter(value) {
    return /[A-Za-z]/.test(value);
}

function hasNumber(value) {
    return /\d/.test(value);
}

function hasSymbol(value) {
    return /[^A-Za-z0-9]/.test(value);
}

function passwordCategoryCount(value) {
    let count = 0;
    if (hasLetter(value)) count += 1;
    if (hasNumber(value)) count += 1;
    if (hasSymbol(value)) count += 1;
    return count;
}

function passwordMeetsRequirements(value) {
    return value.length >= 8 && hasLetter(value) && hasNumber(value) && hasSymbol(value);
}

function passwordStrengthLevel(value) {
    if (!value) {
        return 'weak';
    }
    if (passwordMeetsRequirements(value)) {
        return 'strong';
    }
    if (value.length < 8 || passwordCategoryCount(value) <= 1) {
        return 'weak';
    }
    return 'medium';
}

function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function isValidMobile(value) {
    const digits = value.replace(/\D/g, '');
    return digits.length >= 10 && digits.length <= 13;
}

function getField(root, name) {
    return root.querySelector(`[data-hw-create-field="${name}"]`);
}

function getError(root, name) {
    return root.querySelector(`[data-hw-create-error="${name}"]`);
}

function fieldValue(root, name) {
    const field = getField(root, name);
    return field && typeof field.value === 'string' ? field.value.trim() : '';
}

function setError(root, name, message) {
    const field = getField(root, name);
    const error = getError(root, name);
    if (!field || !error) {
        return;
    }

    if (message) {
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
        error.hidden = false;
        error.textContent = message;
        return;
    }

    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
    error.hidden = true;
    error.textContent = '';
}

function updatePasswordStrength(root) {
    const passwordField = getField(root, 'password');
    const strengthRoot = root.querySelector('[data-password-strength]');
    const strengthValue = root.querySelector('[data-password-strength-value]');

    if (!passwordField || !strengthRoot || !strengthValue) {
        return;
    }

    const value = passwordField.value || '';
    if (!value) {
        strengthRoot.hidden = true;
        strengthRoot.dataset.level = '';
        strengthValue.textContent = 'Weak';
        strengthValue.dataset.level = 'weak';
        return;
    }

    const level = passwordStrengthLevel(value);
    strengthRoot.hidden = false;
    strengthRoot.dataset.level = level;
    strengthValue.dataset.level = level;
    strengthValue.textContent = level.charAt(0).toUpperCase() + level.slice(1);
}

function validate(root) {
    const required = ['first_name', 'last_name', 'email', 'mobile', 'role', 'status', 'password', 'password_confirmation'];
    let firstInvalid = null;

    required.forEach((name) => {
        const value = fieldValue(root, name);
        let message = '';

        if (!value) {
            message = 'This field is required.';
        } else if (name === 'email' && !isValidEmail(value)) {
            message = 'Enter a valid email address.';
        } else if (name === 'mobile' && !isValidMobile(value)) {
            message = 'Enter a valid mobile number.';
        } else if (name === 'password' && !passwordMeetsRequirements(value)) {
            message = PASSWORD_HINT;
        } else if (name === 'password_confirmation' && value !== fieldValue(root, 'password')) {
            message = 'Password and Confirm Password must match.';
        }

        setError(root, name, message);
        if (message && !firstInvalid) {
            firstInvalid = getField(root, name);
        }
    });

    return firstInvalid;
}

function generatePassword() {
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower = 'abcdefghijkmnopqrstuvwxyz';
    const digits = '23456789';
    const symbols = '!@#$%&*?';
    const all = upper + lower + digits + symbols;

    const pick = (pool) => pool.charAt(Math.floor(Math.random() * pool.length));
    const chars = [pick(upper), pick(lower), pick(digits), pick(symbols)];

    while (chars.length < 12) {
        chars.push(pick(all));
    }

    for (let i = chars.length - 1; i > 0; i -= 1) {
        const j = Math.floor(Math.random() * (i + 1));
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    return chars.join('');
}

function initCreate(root) {
    const form = root.querySelector('[data-hw-create-form]');
    const alertEl = root.querySelector('[data-hw-create-alert]');
    const generateBtn = root.querySelector('[data-hw-create-generate]');
    const generateStatus = root.querySelector('[data-hw-create-generate-status]');
    const passwordField = getField(root, 'password');

    passwordField?.addEventListener('input', () => updatePasswordStrength(root));

    generateBtn?.addEventListener('click', () => {
        const password = generatePassword();
        const confirmField = getField(root, 'password_confirmation');
        if (passwordField) {
            passwordField.value = password;
        }
        if (confirmField) {
            confirmField.value = password;
        }
        setError(root, 'password', '');
        setError(root, 'password_confirmation', '');
        updatePasswordStrength(root);
        if (generateStatus) {
            generateStatus.textContent = 'Temporary password generated. Copy it before leaving this screen.';
        }
    });

    form?.addEventListener('submit', (event) => {
        const firstInvalid = validate(root);
        if (firstInvalid) {
            event.preventDefault();
            if (alertEl) {
                alertEl.hidden = false;
                alertEl.textContent = 'Please complete all required information before creating this account.';
            }
            firstInvalid.focus();
            return;
        }

        if (alertEl && !alertEl.textContent?.trim()) {
            alertEl.hidden = true;
        }
    });
}

document.querySelectorAll('[data-lml-hw-create]').forEach((root) => {
    initCreate(root);
});

export {
    passwordStrengthLevel,
    passwordMeetsRequirements,
};
