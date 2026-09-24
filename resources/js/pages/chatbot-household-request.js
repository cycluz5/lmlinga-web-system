function initHouseholdRequestForm(root) {
    const form = root.querySelector('[data-lml-household-request-form]');
    if (!form) {
        return;
    }

    const fieldRules = [
        {
            name: 'householdNo',
            errorId: 'hh-household-no-error',
            validate: (value) =>
                value.trim() === '' ? 'Please enter your household number.' : '',
        },
        {
            name: 'relationship',
            errorId: 'hh-relationship-error',
            validate: (value) =>
                value.trim() === '' ? 'Please select your relationship to the household.' : '',
        },
        {
            name: 'firstName',
            errorId: 'hh-first-name-error',
            validate: (value) => (value.trim() === '' ? 'Please enter your first name.' : ''),
        },
        {
            name: 'middleName',
            errorId: 'hh-middle-name-error',
            validate: (value) => (value.trim() === '' ? 'Please enter your middle name.' : ''),
        },
        {
            name: 'lastName',
            errorId: 'hh-last-name-error',
            validate: (value) => (value.trim() === '' ? 'Please enter your last name.' : ''),
        },
        {
            name: 'mobileNumber',
            errorId: 'hh-mobile-number-error',
            validate: (value) => {
                const raw = value.trim();
                const digits = raw.replace(/\D/g, '');
                if (raw === '') {
                    return 'Please enter a valid mobile number.';
                }
                if (!/^09\d{9}$/.test(digits)) {
                    return 'Please enter a valid mobile number.';
                }
                return '';
            },
        },
        {
            name: 'emailAddress',
            errorId: 'hh-email-address-error',
            validate: (value) => {
                const raw = value.trim();
                if (raw === '') {
                    return 'Please enter a valid email address.';
                }
                const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw);
                return ok ? '' : 'Please enter a valid email address.';
            },
        },
    ];

    function clearError(input, errorEl) {
        if (!input || !errorEl) {
            return;
        }
        input.removeAttribute('aria-invalid');
        const baseDescribedby = input.dataset.lmlBaseDescribedby || '';
        if (baseDescribedby) {
            input.setAttribute('aria-describedby', baseDescribedby);
        } else {
            input.removeAttribute('aria-describedby');
        }
        input.classList.remove('is-invalid');
        errorEl.hidden = true;
        errorEl.textContent = '';
    }

    function setError(input, errorEl, message) {
        if (!input || !errorEl) {
            return;
        }
        input.setAttribute('aria-invalid', 'true');
        const baseDescribedby = input.dataset.lmlBaseDescribedby || '';
        input.setAttribute(
            'aria-describedby',
            [baseDescribedby, errorEl.id].filter(Boolean).join(' ')
        );
        input.classList.add('is-invalid');
        errorEl.hidden = false;
        errorEl.textContent = message;
    }

    fieldRules.forEach(({ name, errorId }) => {
        const input = form.elements.namedItem(name);
        const errorEl = form.querySelector(`#${errorId}`);
        if (!input || !errorEl) {
            return;
        }

        input.dataset.lmlBaseDescribedby = input.getAttribute('aria-describedby') || '';
        input.addEventListener('input', () => clearError(input, errorEl));
        input.addEventListener('change', () => clearError(input, errorEl));
    });

    form.addEventListener('submit', (event) => {
        let firstInvalid = null;

        fieldRules.forEach(({ name, errorId, validate }) => {
            const input = form.elements.namedItem(name);
            const errorEl = form.querySelector(`#${errorId}`);
            if (!input || !errorEl) {
                return;
            }

            const value = typeof input.value === 'string' ? input.value : '';
            const message = validate(value);

            if (message) {
                setError(input, errorEl, message);
                if (!firstInvalid) {
                    firstInvalid = input;
                }
            } else {
                clearError(input, errorEl);
            }
        });

        if (firstInvalid) {
            event.preventDefault();
            firstInvalid.focus();
            return;
        }

        // Valid client check: allow the native POST to Laravel. Do not
        // replace submission with window.location.assign or a local redirect.
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document
        .querySelectorAll('.lml-chatbot-household-request')
        .forEach((root) => initHouseholdRequestForm(root));
});
