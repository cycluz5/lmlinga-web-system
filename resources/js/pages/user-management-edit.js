/**
 * Edit Health Worker — 5-step guided form.
 * Client-side step validation; mutable DB workers submit via native PUT.
 */

import { healthWorkerUpdateOfflineGuard } from '../offline/offline-um-forms.js';
import { isClientOffline } from '../offline/offline-forms.js';
import { OFFLINE_EVENTS } from '../offline/offline-status.js';
import { bindManagedAvatarFallback } from '../offline/offline-um-warmup.js';

const STEP_LABELS = {
    1: 'Personal Information',
    2: 'Contact Information',
    3: 'Residential Address',
    4: 'Employment Information',
    5: 'Account Information',
};

const STEP_FIELDS = {
    1: [
        'sex',
        'first_name',
        'last_name',
        'middle_name',
        'suffix',
        'date_of_birth',
        'age',
        'civil_status',
        'nationality',
        'photo',
    ],
    2: ['mobile', 'email'],
    3: ['house_no', 'street', 'purok_zone', 'barangay', 'municipality', 'province', 'zip_code'],
    4: ['role', 'assigned_barangay', 'assigned_zone', 'date_appointed', 'end_of_appointment'],
    5: ['username', 'status', 'password', 'password_confirmation'],
};

const toastTimers = new WeakMap();

function showToast(root, message) {
    const toast = root.querySelector('[data-hw-wizard-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    const previousTimer = toastTimers.get(root);
    if (previousTimer) {
        window.clearTimeout(previousTimer);
    }

    const timerId = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
        toastTimers.delete(root);
    }, 3600);

    toastTimers.set(root, timerId);
}

function showAlert(root, message) {
    const alert = root.querySelector('[data-hw-wizard-alert]');
    if (!alert) {
        return;
    }

    alert.textContent = message;
    alert.hidden = !message;
}

function clearAlert(root) {
    showAlert(root, '');
}

export function localCalendarDateIso(date = new Date()) {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

export function localYesterdayIso(date = new Date()) {
    const yesterday = new Date(date.getFullYear(), date.getMonth(), date.getDate() - 1);

    return localCalendarDateIso(yesterday);
}

export function refreshHealthWorkerDateMax(root, now = new Date()) {
    const todayIso = localCalendarDateIso(now);
    const yesterdayIso = localYesterdayIso(now);
    const dob = root.querySelector('#hw_dob, [name="hw_dob"], [data-hw-dob]');
    const appointed = root.querySelector('#hw_date_appointed, [name="hw_date_appointed"], [data-hw-field="date_appointed"]');

    if (dob) {
        dob.setAttribute('max', yesterdayIso);
    }

    if (appointed && appointed !== dob) {
        appointed.setAttribute('max', todayIso);
    }
}

function computeAgeFromDob(dobValue) {
    if (!dobValue) {
        return '';
    }

    const dob = new Date(`${dobValue}T00:00:00`);
    if (Number.isNaN(dob.getTime())) {
        return '';
    }

    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const monthDiff = today.getMonth() - dob.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
        age -= 1;
    }

    return age >= 0 ? String(age) : '';
}

function updateAge(root) {
    const dob = root.querySelector('[data-hw-dob]');
    const age = root.querySelector('[data-hw-age]');
    if (!dob || !age) {
        return;
    }

    age.value = computeAgeFromDob(dob.value);
}

function getFieldControl(root, field) {
    if (field === 'sex') {
        return root.querySelector('[data-hw-field="sex"]:checked')
            || root.querySelector('[data-hw-field="sex"]');
    }

    if (field === 'assigned_zone') {
        return root.querySelector('[data-hw-field="assigned_zone"]:checked')
            || root.querySelector('[data-hw-field="assigned_zone"]');
    }

    if (field === 'photo') {
        return root.querySelector('[data-hw-photo-change]');
    }

    return root.querySelector(`[data-hw-field="${field}"]`);
}

function getFieldValue(root, field) {
    if (field === 'sex') {
        const checked = root.querySelector('[data-hw-field="sex"]:checked');
        return checked ? checked.value.trim() : '';
    }

    if (field === 'assigned_zone') {
        return Array.from(root.querySelectorAll('[data-hw-field="assigned_zone"]:checked'))
            .map((el) => String(el.value || '').trim())
            .filter(Boolean)
            .join(',');
    }

    if (field === 'photo') {
        return root.dataset.hwPhotoState === 'empty' ? '' : 'present';
    }

    const el = root.querySelector(`[data-hw-field="${field}"]`);
    return el ? String(el.value || '').trim() : '';
}

function setControlDescribedBy(control, errorId, hasError) {
    const base = (control.getAttribute('data-hw-describedby-base') || '')
        .split(/\s+/)
        .filter(Boolean);
    const parts = [...base];

    if (hasError && errorId) {
        parts.push(errorId);
    }

    const value = [...new Set(parts)].join(' ');
    if (value) {
        control.setAttribute('aria-describedby', value);
    } else {
        control.removeAttribute('aria-describedby');
    }
}

function setFieldInvalid(root, field, message) {
    const error = root.querySelector(`[data-hw-error="${field}"]`);
    const control = getFieldControl(root, field);

    if (error) {
        error.textContent = message || '';
        error.hidden = !message;
    }

    if (field === 'sex') {
        const group = root.querySelector('[data-hw-field-group="sex"]');
        group?.classList.toggle('is-invalid', Boolean(message));
        root.querySelectorAll('[data-hw-field="sex"]').forEach((radio) => {
            radio.setAttribute('aria-invalid', message ? 'true' : 'false');
            if (error?.id) {
                radio.setAttribute('aria-describedby', message ? error.id : '');
            }
        });
        return;
    }

    if (field === 'assigned_zone') {
        const group = root.querySelector('[data-hw-field-group="assigned_zone"]');
        group?.classList.toggle('is-invalid', Boolean(message));
        root.querySelectorAll('[data-hw-field="assigned_zone"]').forEach((box) => {
            box.setAttribute('aria-invalid', message ? 'true' : 'false');
            if (error?.id) {
                setControlDescribedBy(box, error.id, Boolean(message));
            }
        });
        return;
    }

    if (field === 'photo') {
        const changeBtn = root.querySelector('[data-hw-photo-change]');
        const actions = root.querySelector('[data-hw-photo-actions]');
        actions?.classList.toggle('is-invalid', Boolean(message));

        if (changeBtn) {
            changeBtn.setAttribute('aria-invalid', message ? 'true' : 'false');
            if (error?.id) {
                setControlDescribedBy(changeBtn, error.id, Boolean(message));
            }
        }
        return;
    }

    if (control) {
        control.classList.toggle('is-invalid', Boolean(message));
        control.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (error?.id) {
            setControlDescribedBy(control, error.id, Boolean(message));
        }
    }
}

function clearFieldErrors(root, fields) {
    fields.forEach((field) => setFieldInvalid(root, field, ''));
}

function clearAllErrors(root) {
    Object.values(STEP_FIELDS).flat().forEach((field) => setFieldInvalid(root, field, ''));
    clearAlert(root);
}

function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function isValidMobile(value) {
    const digits = value.replace(/\D/g, '');
    return digits.length >= 10 && digits.length <= 13;
}

function validateField(root, field) {
    const value = getFieldValue(root, field);

    if (field === 'photo') {
        return '';
    }

    // Edit page: leave password blank to keep the existing credential.
    if (field === 'password') {
        const confirmation = getFieldValue(root, 'password_confirmation');
        if (!value && confirmation) {
            return 'This field is required.';
        }
        if (!value) {
            return '';
        }
        if (value.length < 8) {
            return 'Password must be at least 8 characters.';
        }
        return '';
    }

    if (field === 'password_confirmation') {
        const password = getFieldValue(root, 'password');
        if (!password && !value) {
            return '';
        }
        if (password && !value) {
            return 'This field is required.';
        }
        if (!password && value) {
            // Password field carries the required error for confirm-only.
            return '';
        }
        if (value !== password) {
            return 'Password and Confirm Password must match.';
        }
        return '';
    }

    // Suffix is optional on Edit Worker.
    if (field === 'suffix') {
        return '';
    }

    // Ongoing appointments may have no known official end date.
    if (field === 'end_of_appointment') {
        if (!value) {
            return '';
        }
        const appointed = getFieldValue(root, 'date_appointed');
        if (appointed && value < appointed) {
            return 'End of Appointment cannot be earlier than Date Appointed.';
        }
        return '';
    }

    if (!value) {
        return 'This field is required.';
    }

    if (field === 'email' && !isValidEmail(value)) {
        return 'Enter a valid email address.';
    }

    if (field === 'mobile' && !isValidMobile(value)) {
        return 'Enter a valid mobile number.';
    }

    if (field === 'age') {
        const ageNum = Number(value);
        if (!Number.isFinite(ageNum) || ageNum < 0 || ageNum > 120) {
            return 'Age must be a valid computed value.';
        }
    }

    if (field === 'date_of_birth') {
        const todayIso = localCalendarDateIso();
        if (value >= todayIso) {
            return 'Date of birth must be before today.';
        }
        const age = computeAgeFromDob(value);
        if (age === '') {
            return 'Enter a valid date of birth.';
        }
    }

    if (field === 'date_appointed') {
        if (value > localCalendarDateIso()) {
            return 'Date appointed cannot be after today.';
        }
    }

    if (field === 'zip_code' && !/^\d{4,5}$/.test(value)) {
        return 'Enter a valid zip code.';
    }

    return '';
}

function validateStep(root, step) {
    const fields = STEP_FIELDS[step] || [];
    clearFieldErrors(root, fields);

    if (step === 1) {
        updateAge(root);
    }

    let firstInvalid = null;
    const errors = {};

    fields.forEach((field) => {
        const message = validateField(root, field);
        if (message) {
            errors[field] = message;
            setFieldInvalid(root, field, message);
            if (!firstInvalid) {
                firstInvalid = field;
            }
        }
    });

    return {
        valid: Object.keys(errors).length === 0,
        firstInvalid,
        errors,
    };
}

function validateAllSteps(root) {
    for (let step = 1; step <= 5; step += 1) {
        const result = validateStep(root, step);
        if (!result.valid) {
            return { step, ...result };
        }
    }

    return { valid: true, step: 5, firstInvalid: null, errors: {} };
}

function focusField(root, field) {
    if (field === 'sex') {
        root.querySelector('[data-hw-field="sex"]')?.focus();
        return;
    }

    if (field === 'assigned_zone') {
        root.querySelector('[data-hw-field="assigned_zone"]')?.focus();
        return;
    }

    if (field === 'photo') {
        root.querySelector('[data-hw-photo-change]')?.focus();
        return;
    }

    getFieldControl(root, field)?.focus();
}

function setStep(root, step, { focusHeading = true } = {}) {
    const current = Number(root.dataset.hwCurrentStep || 1);
    root.dataset.hwCurrentStep = String(step);

    root.querySelectorAll('[data-hw-wizard-panel]').forEach((panel) => {
        const panelStep = Number(panel.dataset.hwWizardPanel);
        panel.hidden = panelStep !== step;
    });

    root.querySelectorAll('[data-hw-wizard-step-item]').forEach((item) => {
        const itemStep = Number(item.dataset.hwWizardStepItem);
        item.classList.remove('is-current', 'is-complete', 'is-upcoming');

        if (itemStep < step) {
            item.classList.add('is-complete');
            item.removeAttribute('aria-current');
        } else if (itemStep === step) {
            item.classList.add('is-current');
            item.setAttribute('aria-current', 'step');
        } else {
            item.classList.add('is-upcoming');
            item.removeAttribute('aria-current');
        }
    });

    const currentLabel = root.querySelector('[data-hw-wizard-current-label]');
    if (currentLabel) {
        currentLabel.textContent = `Step ${step} of 5: ${STEP_LABELS[step]}`;
    }

    const prevBtn = root.querySelector('[data-hw-wizard-prev]');
    const nextBtn = root.querySelector('[data-hw-wizard-next]');
    const saveBtn = root.querySelector('[data-hw-wizard-save]');

    if (prevBtn) {
        prevBtn.hidden = step === 1;
    }

    if (nextBtn) {
        nextBtn.hidden = step === 5;
    }

    if (saveBtn) {
        saveBtn.hidden = step !== 5;
    }

    if (focusHeading) {
        const heading = root.querySelector(`[data-hw-wizard-panel="${step}"] .lml-hw-wizard__panel-title`);
        heading?.focus();
    }

    if (current !== step) {
        clearAlert(root);
    }
}

function setAvatar(root, photoUrl) {
    const img = root.querySelector('[data-hw-avatar-img]');
    const icon = root.querySelector('[data-hw-avatar-icon]');
    if (!img || !icon) {
        return;
    }

    if (root._hwPhotoObjectUrl) {
        URL.revokeObjectURL(root._hwPhotoObjectUrl);
        root._hwPhotoObjectUrl = null;
    }

    if (photoUrl) {
        img.src = photoUrl;
        img.hidden = false;
        icon.hidden = true;
        root.dataset.hwPhotoState = 'present';
        setFieldInvalid(root, 'photo', '');
        return;
    }

    img.removeAttribute('src');
    img.hidden = true;
    icon.hidden = false;
    root.dataset.hwPhotoState = 'empty';
}

function setRemovePhotoFlag(root, shouldRemove) {
    const flag = root.querySelector('[data-hw-remove-photo]');
    if (flag) {
        flag.value = shouldRemove ? '1' : '0';
    }
}

function initPhotoState(root) {
    const img = root.querySelector('[data-hw-avatar-img]');
    const src = (img?.getAttribute('src') || '').trim();
    const present = Boolean(img && !img.hidden && src);
    root.dataset.hwPhotoState = present ? 'present' : 'empty';
    setRemovePhotoFlag(root, false);
}

function initWizard(root) {
    const form = root.querySelector('[data-hw-wizard-form]');
    const dob = root.querySelector('[data-hw-dob]');
    const role = root.querySelector('[data-hw-role]');
    const photoInput = root.querySelector('[data-hw-photo-input]');

    refreshHealthWorkerDateMax(root);
    initPhotoState(root);
    bindManagedAvatarFallback(root);
    root.dataset.hwCurrentStep = '1';
    updateAge(root);
    setStep(root, 1, { focusHeading: false });

    dob?.addEventListener('change', () => updateAge(root));
    dob?.addEventListener('input', () => updateAge(root));

    role?.addEventListener('change', () => {
        const label = root.querySelector('[data-hw-role-label]');
        if (label) {
            label.textContent = `${role.value || 'BHW'} (Role)`;
        }
    });

    root.querySelector('[data-hw-photo-change]')?.addEventListener('click', () => {
        photoInput?.click();
    });

    root.querySelector('[data-hw-photo-remove]')?.addEventListener('click', () => {
        if (photoInput) {
            photoInput.value = '';
        }
        setRemovePhotoFlag(root, true);
        setAvatar(root, null);
    });

    photoInput?.addEventListener('change', () => {
        const file = photoInput.files && photoInput.files[0];
        if (!file) {
            return;
        }

        setRemovePhotoFlag(root, false);
        const objectUrl = URL.createObjectURL(file);
        setAvatar(root, objectUrl);
        root._hwPhotoObjectUrl = objectUrl;
    });

    root.querySelector('[data-hw-wizard-prev]')?.addEventListener('click', () => {
        const current = Number(root.dataset.hwCurrentStep || 1);
        if (current > 1) {
            clearFieldErrors(root, STEP_FIELDS[current] || []);
            setStep(root, current - 1);
        }
    });

    root.querySelector('[data-hw-wizard-next]')?.addEventListener('click', () => {
        const current = Number(root.dataset.hwCurrentStep || 1);
        const result = validateStep(root, current);

        if (!result.valid) {
            showAlert(root, 'Please complete all required information before continuing.');
            focusField(root, result.firstInvalid);
            return;
        }

        clearAlert(root);
        setStep(root, Math.min(5, current + 1));
    });

    form?.addEventListener('submit', (event) => {
        const saveBtn = root.querySelector('[data-hw-wizard-save]');
        if (saveBtn?.disabled) {
            event.preventDefault();
            return;
        }

        const result = validateAllSteps(root);
        if (!result.valid) {
            event.preventDefault();
            setStep(root, result.step, { focusHeading: false });
            showAlert(root, 'Please complete all required information before continuing.');
            focusField(root, result.firstInvalid);
            return;
        }

        clearAllErrors(root);

        const mutable = form.getAttribute('data-hw-mutable') === '1';
        if (!mutable) {
            event.preventDefault();
            if (saveBtn) {
                saveBtn.disabled = true;
            }
            showToast(root, 'Demo catalog workers are read-only. Database accounts use numeric IDs.');
            window.setTimeout(() => {
                if (saveBtn) {
                    saveBtn.disabled = false;
                }
            }, 900);
            return;
        }

        const guard = healthWorkerUpdateOfflineGuard(form, { root: form.closest('[data-lml-offline-root]') || root });
        if (!guard.allow) {
            event.preventDefault();
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.removeAttribute('aria-disabled');
            }
            showToast(root, guard.message);
            const win = typeof window !== 'undefined' ? window : globalThis;
            if (win?.LmlingaOffline?.emit) {
                win.LmlingaOffline.emit(OFFLINE_EVENTS.NOTICE, { message: guard.message });
            } else if (typeof win?.dispatchEvent === 'function') {
                const notice =
                    typeof CustomEvent === 'function'
                        ? new CustomEvent(OFFLINE_EVENTS.NOTICE, { detail: { message: guard.message } })
                        : { type: OFFLINE_EVENTS.NOTICE, detail: { message: guard.message } };
                win.dispatchEvent(notice);
            }
            return;
        }

        if (saveBtn) {
            saveBtn.disabled = true;
        }
        if (isClientOffline({ root: form.closest('[data-lml-offline-root]') || root })) {
            return;
        }
        // Valid mutable worker — allow native PUT submit.
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-lml-hw-wizard]').forEach((root) => {
        initWizard(root);
    });
}
