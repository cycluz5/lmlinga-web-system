/**
 * User Profile — hybrid Back: same-origin history.back() or Dashboard href.
 */

export const PROFILE_BACK_EXCLUDED_PATHS = Object.freeze([
    '/profile',
    '/login',
    '/logout',
    '/change-password',
]);

export function normalizeAppPathname(pathname) {
    if (typeof pathname !== 'string' || pathname === '') {
        return '/';
    }

    const trimmed = pathname.replace(/\/+$/, '');

    return trimmed === '' ? '/' : trimmed;
}

export function shouldHistoryBackFromReferrer(referrer, locationLike) {
    if (typeof referrer !== 'string' || referrer.trim() === '') {
        return false;
    }

    let previous;
    try {
        previous = new URL(referrer);
    } catch {
        return false;
    }

    const currentHref = typeof locationLike === 'string'
        ? locationLike
        : locationLike?.href;
    if (typeof currentHref !== 'string' || currentHref === '') {
        return false;
    }

    let current;
    try {
        current = new URL(currentHref);
    } catch {
        return false;
    }

    if (previous.origin !== current.origin) {
        return false;
    }

    const previousPath = normalizeAppPathname(previous.pathname);
    if (PROFILE_BACK_EXCLUDED_PATHS.includes(previousPath)) {
        return false;
    }

    if (previousPath === normalizeAppPathname(current.pathname)) {
        return false;
    }

    return true;
}

export function bindProfileBackNavigation(root, deps = {}) {
    const link = root.querySelector('[data-profile-back]');
    if (!link) {
        return;
    }

    link.addEventListener('click', (event) => {
        const referrer = typeof deps.getReferrer === 'function'
            ? deps.getReferrer()
            : (typeof document !== 'undefined' ? document.referrer : '');
        const locationLike = deps.location || (typeof window !== 'undefined' ? window.location : null);

        if (!shouldHistoryBackFromReferrer(referrer, locationLike)) {
            return;
        }

        event.preventDefault();
        if (typeof deps.historyBack === 'function') {
            deps.historyBack();
            return;
        }
        if (typeof window !== 'undefined') {
            window.history.back();
        }
    });
}

export function initUserProfilePage(root, deps = {}) {
    if (!root || typeof root.getAttribute !== 'function') {
        return;
    }

    if (root.getAttribute('data-lml-user-profile-page') === null && !root.hasAttribute?.('data-lml-user-profile-page')) {
        return;
    }

    bindProfileBackNavigation(root, deps);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-lml-user-profile-page]').forEach((root) => {
        initUserProfilePage(root);
    });
}
