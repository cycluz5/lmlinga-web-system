/**
 * LMLinga dashboard sidebar — Health Records submenu toggle.
 *
 * Intentionally does NOT use Bootstrap Collapse / the `.collapse` class.
 * Tailwind v4 ships a `.collapse` utility that collides with Bootstrap and can
 * leave submenu children visually open on non-Health-Records routes.
 *
 * Closed menus keep child markup inside <template> until first expand so a CSS
 * failure cannot paint Child Care / Risk Assessment / etc. on /dashboard.
 *
 * State A (expanded + active child): glowing treatment on the child only;
 * parent keeps a subtle expanded indication.
 * State B (manually collapsed + active child): glowing treatment moves to the parent.
 * State C (route outside Health Records): server markup stays collapsed/inactive.
 */

const PARENT_ACTIVE = 'lml-sidebar__link--parent-active';
const PARENT_EXPANDED = 'lml-sidebar__link--parent-expanded';
const PANEL_OPEN = 'is-open';

/**
 * @param {ParentNode | Document} root Document or sidebar subtree root used in tests.
 */
export function initDashboardSidebarCollapseActiveState(root = document) {
    const doc = root.ownerDocument || root;
    const sidebar =
        typeof root.getElementById === 'function'
            ? root.getElementById('lmlDashboardSidebar')
            : root.querySelector?.('#lmlDashboardSidebar');

    if (!sidebar) {
        return;
    }

    function getToggleForPanel(panel) {
        const panelId = panel.getAttribute('id');
        if (!panelId) {
            return null;
        }

        return sidebar.querySelector(
            `[data-lml-sidebar-collapse-toggle][aria-controls="${panelId}"]`
        );
    }

    function materializeTemplate(panel) {
        const template = panel.querySelector('template[data-lml-sidebar-collapse-template]');
        if (!template) {
            return;
        }

        panel.appendChild(template.content.cloneNode(true));
        template.remove();
    }

    function syncParentRow(panel, isExpanded) {
        if (!panel || panel.getAttribute('data-lml-has-active-child') !== 'true') {
            return;
        }

        const item = panel.closest('.lml-sidebar__item--collapse');
        const row = item?.querySelector('[data-lml-sidebar-collapse-row]');
        if (!row) {
            return;
        }

        if (isExpanded) {
            row.classList.remove(PARENT_ACTIVE);
            row.classList.add(PARENT_EXPANDED);
            return;
        }

        row.classList.remove(PARENT_EXPANDED);
        row.classList.add(PARENT_ACTIVE);
    }

    function setExpanded(panel, toggle, shouldExpand) {
        if (!panel || !toggle) {
            return;
        }

        toggle.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');

        if (shouldExpand) {
            materializeTemplate(panel);
            panel.classList.add(PANEL_OPEN);
            panel.removeAttribute('hidden');
            panel.setAttribute('aria-hidden', 'false');
        } else {
            panel.classList.remove(PANEL_OPEN);
            panel.setAttribute('hidden', '');
            panel.setAttribute('aria-hidden', 'true');
        }

        syncParentRow(panel, shouldExpand);
    }

    function isExpanded(panel, toggle) {
        return (
            toggle.getAttribute('aria-expanded') === 'true' ||
            (panel.classList.contains(PANEL_OPEN) && !panel.hasAttribute('hidden'))
        );
    }

    sidebar.querySelectorAll('[data-lml-sidebar-collapse-panel]').forEach((panel) => {
        const toggle = getToggleForPanel(panel);
        if (!toggle) {
            return;
        }

        // Honor server-rendered initial state; never auto-open on /dashboard.
        const startsExpanded =
            panel.classList.contains(PANEL_OPEN) && !panel.hasAttribute('hidden');
        setExpanded(panel, toggle, startsExpanded);

        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            setExpanded(panel, toggle, !isExpanded(panel, toggle));

            // Pointer taps can leave sticky focus/hover greens that look route-active.
            // Keyboard activation (detail === 0) keeps focus for continued navigation.
            if (event.detail > 0 && doc.activeElement === toggle) {
                toggle.blur();
            }
        });
    });
}

export const ADMIN_ONLY_MESSAGE = 'This page is available to administrators only.';

const toastTimers = new WeakMap();

/**
 * @param {ParentNode | Document} root
 */
export function initAdminOnlySidebarItems(root = document) {
    const doc = root.ownerDocument || root;
    const sidebar =
        typeof root.getElementById === 'function'
            ? root.getElementById('lmlDashboardSidebar')
            : root.querySelector?.('#lmlDashboardSidebar');

    if (!sidebar) {
        return;
    }

    const toast =
        (typeof root.querySelector === 'function'
            ? root.querySelector('[data-lml-shell-toast]')
            : null) || doc.querySelector?.('[data-lml-shell-toast]');

    const showMessage = () => {
        if (!toast) {
            return;
        }

        toast.textContent = ADMIN_ONLY_MESSAGE;
        toast.hidden = false;

        const previous = toastTimers.get(toast);
        if (previous) {
            clearTimeout(previous);
        }

        const timer = setTimeout(() => {
            toast.hidden = true;
            toast.textContent = '';
            toastTimers.delete(toast);
        }, 3600);

        toastTimers.set(toast, timer);
    };

    const onActivate = (event) => {
        const target = event.target?.closest?.('[data-lml-admin-only]');
        if (!target || !sidebar.contains(target)) {
            return;
        }

        if (target.closest('[data-lml-sidebar-collapse-toggle]')) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        showMessage();
    };

    sidebar.addEventListener('click', onActivate);
    sidebar.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        onActivate(event);
    });
}

if (typeof document !== 'undefined' && document.getElementById('lmlDashboardSidebar')) {
    initDashboardSidebarCollapseActiveState(document);
    initAdminOnlySidebarItems(document);
}
