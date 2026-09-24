/**
 * Responsive sidebar controls for the verified household information preview.
 */

function initHouseholdInformation(root) {
    const sidebar = root.querySelector('[data-lml-household-info-sidebar]');
    const overlay = root.querySelector('[data-lml-household-info-overlay]');
    const desktopToggle = root.querySelector('[data-lml-household-info-sidebar-toggle]');
    const mobileToggle = root.querySelector('[data-lml-household-info-mobile-toggle]');

    if (!sidebar || !overlay || !desktopToggle || !mobileToggle) {
        return;
    }

    const mobileQuery = window.matchMedia('(max-width: 767.98px)');

    function getSidebarFocusables() {
        const selector = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
        ].join(',');

        return Array.from(sidebar.querySelectorAll(selector)).filter(
            (element) => !element.hidden && !element.closest('[hidden]')
        );
    }

    function onMobileKeydown(event) {
        if (!mobileQuery.matches || !root.classList.contains('is-mobile-open')) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            setMobileOpen(false, true);
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusables = getSidebarFocusables();
        if (focusables.length === 0) {
            event.preventDefault();
            sidebar.focus();
            return;
        }

        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && (active === first || !sidebar.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function setMobileOpen(open, restoreFocus = false) {
        const shouldOpen = open && mobileQuery.matches;
        root.classList.toggle('is-mobile-open', shouldOpen);
        overlay.hidden = !shouldOpen;
        sidebar.inert = mobileQuery.matches && !shouldOpen;
        mobileToggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        mobileToggle.setAttribute('aria-label', shouldOpen ? 'Close sidebar' : 'Open sidebar');
        document.body.style.overflow = shouldOpen ? 'hidden' : '';

        document.removeEventListener('keydown', onMobileKeydown);
        if (shouldOpen) {
            document.addEventListener('keydown', onMobileKeydown);
            window.requestAnimationFrame(() => {
                getSidebarFocusables()[0]?.focus();
            });
        } else if (restoreFocus) {
            mobileToggle.focus();
        }
    }

    function syncViewport() {
        if (mobileQuery.matches) {
            setMobileOpen(false);
            return;
        }

        root.classList.remove('is-mobile-open');
        overlay.hidden = true;
        sidebar.inert = false;
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onMobileKeydown);
    }

    desktopToggle.addEventListener('click', () => {
        if (mobileQuery.matches) {
            return;
        }

        const collapsed = !root.classList.contains('is-sidebar-collapsed');
        root.classList.toggle('is-sidebar-collapsed', collapsed);
        desktopToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        desktopToggle.setAttribute(
            'aria-label',
            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
        );
        desktopToggle.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    });

    mobileToggle.addEventListener('click', () => {
        setMobileOpen(!root.classList.contains('is-mobile-open'));
    });

    overlay.addEventListener('click', () => {
        setMobileOpen(false, true);
    });

    mobileQuery.addEventListener('change', syncViewport);
    syncViewport();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-household-info]').forEach((root) => {
        initHouseholdInformation(root);
    });
});
