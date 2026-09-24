/**
 * Phase-1 Health Worker update intercept helpers.
 * Passwords and profile photos are never queued.
 */

import { isClientOffline } from './offline-forms.js';
import { OFFLINE_MESSAGES } from './offline-status.js';

function fieldValue(form, selectors) {
    if (!form || typeof form.querySelector !== 'function') {
        return '';
    }
    for (const selector of selectors) {
        const el = form.querySelector(selector);
        if (el && String(el.value || '').trim() !== '') {
            return String(el.value).trim();
        }
    }
    return '';
}

export function hasOfflinePasswordChange(form) {
    const password = fieldValue(form, ['[name="hw_password"]', '[data-hw-field="password"]']);
    const confirmation = fieldValue(form, [
        '[name="hw_password_confirmation"]',
        '[data-hw-field="password_confirmation"]',
    ]);
    return password !== '' || confirmation !== '';
}

export function hasOfflinePhotoChange(form) {
    if (!form) {
        return false;
    }

    const input =
        (typeof form.querySelector === 'function' ? form.querySelector('[data-hw-photo-input], [name="hw_photo"]') : null)
        || null;
    if (input?.files && input.files.length > 0) {
        return true;
    }

    const remove =
        (typeof form.querySelector === 'function' ? form.querySelector('[data-hw-remove-photo], [name="hw_remove_photo"]') : null)
        || null;
    const raw = remove ? String(remove.value || '').trim() : '';
    return raw === '1' || raw.toLowerCase() === 'true';
}

/**
 * @returns {{ allow: true } | { allow: false, reason: 'password' | 'photo', message: string }}
 */
export function healthWorkerUpdateOfflineGuard(form, options = {}) {
    if (!isClientOffline(options)) {
        return { allow: true };
    }
    if (hasOfflinePasswordChange(form)) {
        return {
            allow: false,
            reason: 'password',
            message: OFFLINE_MESSAGES.passwordRequiresConnection,
        };
    }
    if (hasOfflinePhotoChange(form)) {
        return {
            allow: false,
            reason: 'photo',
            message: OFFLINE_MESSAGES.photoRequiresConnection,
        };
    }
    return { allow: true };
}
