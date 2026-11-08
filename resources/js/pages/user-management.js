/**
 * User Management — Health Workers + Residents tabs (UI only).
 * Health Worker filters/menus remain here. Resident filters live in
 * user-management-residents.js.
 */

import {
    bindUserManagementOfflineNav,
    bindUserManagementSyncReload,
    handleUserManagementNavClick,
} from '../offline/offline-um-nav-guard.js';
import {
    bindManagedAvatarFallback,
    warmListedUserManagementWorkers,
} from '../offline/offline-um-warmup.js';

const toastTimers = new WeakMap();

/**
 * Keep modal/card POST forms aligned with the live session CSRF token.
 * Offline SW cache sanitization can empty _token inputs; a stale page token
 * after session rotation yields 419. Always prefer a fresh token from
 * /offline/status when submitting activate/deactivate/delete.
 */
function readCsrfToken(doc = document) {
    const meta = doc.querySelector?.('meta[name="csrf-token"]');
    return String(meta?.getAttribute?.('content') || '').trim();
}

function applyCsrfToken(doc, token) {
    const next = String(token || '').trim();
    if (!next) {
        return '';
    }

    const meta = doc.querySelector?.('meta[name="csrf-token"]');
    if (meta) {
        meta.setAttribute('content', next);
    }
    doc.querySelectorAll?.('input[name="_token"]').forEach((input) => {
        input.value = next;
    });

    return next;
}

function syncFormCsrfToken(form, doc = document) {
    if (!form) {
        return '';
    }

    const token = readCsrfToken(doc);
    const input = form.querySelector?.('input[name="_token"]');
    if (token && input) {
        input.value = token;
    }

    return token;
}

async function refreshCsrfFromServer(doc = document) {
    const root = doc.querySelector?.('[data-lml-offline-root]');
    const statusUrl = root?.getAttribute?.('data-offline-status-url') || '/offline/status';

    try {
        const response = await fetch(statusUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!response.ok) {
            return '';
        }
        const payload = await response.json();
        return applyCsrfToken(doc, typeof payload?.csrf_token === 'string' ? payload.csrf_token : '');
    } catch {
        return '';
    }
}

async function ensureFormCsrfToken(form, doc = document) {
    // Always refresh from /offline/status before UM mutations. A non-empty but
    // stale page token (SW-cached HTML or prior session rotation) still 419s.
    // Fall back to the page token only when the live endpoint is unreachable.
    const refreshed = await refreshCsrfFromServer(doc);
    if (refreshed) {
        syncFormCsrfToken(form, doc);
        return refreshed;
    }

    return syncFormCsrfToken(form, doc);
}

function submitFormAfterCsrfReady(form) {
    form.dataset.lmlCsrfReady = '1';
    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
    } else {
        form.submit();
    }
}

function showToast(root, message, toastSelector = '[data-um-toast]') {
    const toast = root.querySelector(toastSelector);
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    const previousTimer = toastTimers.get(toast);
    if (previousTimer) {
        window.clearTimeout(previousTimer);
    }

    const timerId = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
        toastTimers.delete(toast);
    }, 3600);

    toastTimers.set(toast, timerId);
}

function getMenuItems(menuList) {
    return Array.from(menuList.querySelectorAll('[role="menuitem"]'));
}

function closeMenu(menuRoot, { restoreFocus = false } = {}) {
    const toggle = menuRoot.querySelector('[data-hw-menu-toggle]');
    const list = menuRoot.querySelector('[data-hw-menu-list]');

    if (!toggle || !list || list.hidden) {
        return;
    }

    list.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');

    if (restoreFocus) {
        toggle.focus();
    }
}

function closeAllMenus(root, except = null) {
    root.querySelectorAll('[data-hw-menu]').forEach((menuRoot) => {
        if (except && menuRoot === except) {
            return;
        }
        closeMenu(menuRoot);
    });
}

function openMenu(menuRoot) {
    const toggle = menuRoot.querySelector('[data-hw-menu-toggle]');
    const list = menuRoot.querySelector('[data-hw-menu-list]');

    if (!toggle || !list) {
        return;
    }

    list.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');

    const items = getMenuItems(list);
    if (items[0]) {
        items[0].focus();
    }
}

function applyWorkerFilters(root) {
    const workersPanel = root.querySelector('[data-um-panel="workers"]');
    if (!workersPanel) {
        return;
    }

    const cards = Array.from(workersPanel.querySelectorAll('[data-hw-card]'));
    const empty = workersPanel.querySelector('[data-um-empty]');
    const seedEmpty = workersPanel.querySelector('[data-um-empty-seed]');
    const grid = workersPanel.querySelector('[data-um-grid]');
    const searchInput = workersPanel.querySelector('[data-um-search]');
    const categorySelect = workersPanel.querySelector('[data-um-category]');

    const query = (searchInput?.value || '').trim().toLowerCase();
    const category = categorySelect?.value || 'all';
    const normalizedCategory = !category || category === 'all' ? 'all' : category;

    let visible = 0;

    cards.forEach((card) => {
        const name = (card.dataset.hwName || '').toLowerCase();
        const role = card.dataset.hwRole || '';
        const matchesSearch = !query || name.includes(query) || role.toLowerCase().includes(query);
        const matchesCategory = normalizedCategory === 'all' || role === normalizedCategory;
        const show = matchesSearch && matchesCategory;

        card.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (cards.length === 0) {
        if (empty) {
            empty.hidden = true;
        }
        if (seedEmpty) {
            seedEmpty.hidden = false;
        }
        if (grid) {
            grid.hidden = true;
        }
        return;
    }

    if (seedEmpty) {
        seedEmpty.hidden = true;
    }

    if (empty) {
        empty.hidden = visible > 0;
    }

    if (grid) {
        grid.hidden = visible === 0;
    }
}

function updatePageSubtitle(root, tabKey) {
    const subtitle = document.querySelector('.lml-topbar__subtitle');
    if (!subtitle) {
        return;
    }

    if (tabKey === 'residents') {
        subtitle.textContent = root.dataset.subtitleResidents
            || 'Manage user accounts and access permissions.';
        return;
    }

    subtitle.textContent = root.dataset.subtitleWorkers
        || 'Manage accounts of the Barangay Health Workers';
}

function activateTab(root, tabKey) {
    const tabs = Array.from(root.querySelectorAll('[data-um-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-um-panel]'));

    tabs.forEach((tab) => {
        const isActive = tab.dataset.umTab === tabKey;
        tab.classList.toggle('lml-user-mgmt__tab--active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.tabIndex = isActive ? 0 : -1;
    });

    panels.forEach((panel) => {
        panel.hidden = panel.dataset.umPanel !== tabKey;
    });

    updatePageSubtitle(root, tabKey);
    closeAllMenus(root);

    try {
        const url = new URL(window.location.href);
        if (tabKey === 'residents') {
            url.searchParams.set('tab', 'residents');
        } else {
            url.searchParams.delete('tab');
        }
        window.history.replaceState({}, '', url);
    } catch {
        // Ignore history errors in non-browser contexts.
    }
}

function resolveInitialTab(root) {
    try {
        const params = new URLSearchParams(window.location.search);
        const tab = (params.get('tab') || '').toLowerCase();
        if (tab === 'residents' && root.querySelector('[data-um-tab="residents"]')) {
            return 'residents';
        }
        if (tab === 'workers' && root.querySelector('[data-um-tab="workers"]')) {
            return 'workers';
        }
    } catch {
        // Fall through.
    }

    return 'workers';
}

function initMenuWidget(menuRoot) {
    menuRoot.addEventListener('focusout', (event) => {
        const list = menuRoot.querySelector('[data-hw-menu-list]');
        if (!list || list.hidden) {
            return;
        }

        const nextTarget = event.relatedTarget;
        if (nextTarget && menuRoot.contains(nextTarget)) {
            return;
        }

        closeMenu(menuRoot);
    });
}

function initUserManagement(root) {
    const workersPanel = root.querySelector('[data-um-panel="workers"]');
    const searchInput = workersPanel?.querySelector('[data-um-search]');
    const categorySelect = workersPanel?.querySelector('[data-um-category]');
    const tabs = Array.from(root.querySelectorAll('[data-um-tab]'));

    root.querySelectorAll('[data-hw-menu]').forEach((menuRoot) => {
        initMenuWidget(menuRoot);
    });

    searchInput?.addEventListener('input', () => applyWorkerFilters(root));
    categorySelect?.addEventListener('change', () => applyWorkerFilters(root));

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            activateTab(root, tab.dataset.umTab);
        });

        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                return;
            }

            event.preventDefault();

            let nextIndex = index;
            if (event.key === 'ArrowRight') {
                nextIndex = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = tabs.length - 1;
            }

            const nextTab = tabs[nextIndex];
            if (nextTab) {
                activateTab(root, nextTab.dataset.umTab);
                nextTab.focus();
            }
        });
    });

    root.addEventListener('click', (event) => {
        const hwActionBtn = event.target.closest('[data-hw-action]');
        if (hwActionBtn && root.contains(hwActionBtn)) {
            const action = hwActionBtn.dataset.hwAction;

            closeAllMenus(root);

            if (action === 'edit' || action === 'view' || action === 'activate') {
                return;
            }

            event.preventDefault();
            return;
        }

        const hwToggle = event.target.closest('[data-hw-menu-toggle]');
        if (hwToggle && root.contains(hwToggle)) {
            const menuRoot = hwToggle.closest('[data-hw-menu]');
            const list = menuRoot?.querySelector('[data-hw-menu-list]');
            if (!menuRoot || !list) {
                return;
            }

            const willOpen = list.hidden;
            closeAllMenus(root, menuRoot);

            if (willOpen) {
                openMenu(menuRoot);
            } else {
                closeMenu(menuRoot);
            }
            return;
        }

        if (!event.target.closest('[data-hw-menu]')) {
            closeAllMenus(root);
        }
    });

    root.addEventListener('keydown', (event) => {
        const menuRoot = event.target.closest('[data-hw-menu]');
        if (!menuRoot || !root.contains(menuRoot)) {
            return;
        }

        const list = menuRoot.querySelector('[data-hw-menu-list]');
        if (!list || list.hidden) {
            return;
        }

        const items = getMenuItems(list);
        const currentIndex = items.indexOf(document.activeElement);

        if (event.key === 'Escape') {
            event.preventDefault();
            closeMenu(menuRoot, { restoreFocus: true });
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            const next = items[(currentIndex + 1) % items.length] || items[0];
            next?.focus();
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            const prev = items[(currentIndex - 1 + items.length) % items.length] || items[0];
            prev?.focus();
            return;
        }

        if (event.key === 'Home') {
            event.preventDefault();
            items[0]?.focus();
            return;
        }

        if (event.key === 'End') {
            event.preventDefault();
            items[items.length - 1]?.focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) {
            closeAllMenus(root);
        }
    });

    activateTab(root, resolveInitialTab(root));
    applyWorkerFilters(root);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-lml-user-mgmt]').forEach((root) => {
        initUserManagement(root);
        void warmListedUserManagementWorkers({ root });
    });
    bindUserManagementOfflineNav();
    bindUserManagementSyncReload();
    bindManagedAvatarFallback(document);
    initHealthWorkerDeactivateModal(document);
    initHealthWorkerDeleteModal(document);
    initHealthWorkerActivateForms(document);
}

/**
 * Activate uses plain POST forms (card + view). Always refresh CSRF from the
 * live session before submit so a stale/empty token cannot 419 or bounce the
 * admin to /login.
 */
function initHealthWorkerActivateForms(root) {
    const doc = root.ownerDocument || document;

    root.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (!form.querySelector?.('[data-hw-activate]')) {
            return;
        }
        if (root !== form && typeof root.contains === 'function' && !root.contains(form)) {
            return;
        }
        if (form.dataset.lmlCsrfReady === '1') {
            delete form.dataset.lmlCsrfReady;
            return;
        }

        event.preventDefault();
        void ensureFormCsrfToken(form, doc).then((refreshed) => {
            if (!refreshed) {
                return;
            }
            submitFormAfterCsrfReady(form);
        });
    }, true);
}

function initHealthWorkerDeactivateModal(root) {
    const modal = root.querySelector('[data-hw-deactivate-modal]');
    const panel = root.querySelector('[data-hw-deactivate-panel]');
    const form = root.querySelector('[data-hw-deactivate-form]');
    const nameTarget = modal?.querySelector('[data-hw-deactivate-name-label]');
    const confirmBtn = root.querySelector('[data-hw-deactivate-confirm]');
    const backdrop = root.querySelector('[data-hw-deactivate-backdrop]');
    const cancelButtons = Array.from(root.querySelectorAll('[data-hw-deactivate-cancel]'));

    if (!modal || !panel || !form) {
        return;
    }

    let lastTrigger = null;
    let submitting = false;

    const closeModal = ({ restoreFocus = true } = {}) => {
        modal.hidden = true;
        submitting = false;
        delete form.dataset.lmlCsrfReady;
        if (confirmBtn) {
            confirmBtn.disabled = false;
        }
        if (restoreFocus && lastTrigger) {
            lastTrigger.focus();
        }
        lastTrigger = null;
    };

    const openModal = (trigger) => {
        const workerId = trigger.dataset.hwDeactivateId || '';
        const workerName = trigger.dataset.hwDeactivateName || 'this health worker';
        const doc = form.ownerDocument || document;

        lastTrigger = trigger;
        submitting = false;
        delete form.dataset.lmlCsrfReady;
        if (confirmBtn) {
            confirmBtn.disabled = false;
        }

        form.action = `${form.dataset.hwDeactivateBase || '/user-management/health-workers'}/${encodeURIComponent(workerId)}/deactivate`;
        if (nameTarget) {
            nameTarget.textContent = workerName;
        }
        void ensureFormCsrfToken(form, doc);

        modal.hidden = false;
        panel.focus();
    };

    root.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-hw-deactivate]');
        if (trigger && root.contains(trigger)) {
            event.preventDefault();
            openModal(trigger);
        }
    });

    cancelButtons.forEach((btn) => {
        btn.addEventListener('click', () => closeModal({ restoreFocus: true }));
    });

    backdrop?.addEventListener('click', () => closeModal({ restoreFocus: true }));

    form.addEventListener('submit', (event) => {
        // CSRF-ready pass must run before the submitting guard, otherwise the
        // resubmit is blocked and the confirm button stays disabled forever.
        if (form.dataset.lmlCsrfReady === '1') {
            delete form.dataset.lmlCsrfReady;
            submitting = true;
            if (confirmBtn) {
                confirmBtn.disabled = true;
            }
            return;
        }

        if (submitting) {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        const doc = form.ownerDocument || document;
        if (confirmBtn) {
            confirmBtn.disabled = true;
        }
        void ensureFormCsrfToken(form, doc).then((refreshed) => {
            if (!refreshed) {
                submitting = false;
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                }
                return;
            }
            submitFormAfterCsrfReady(form);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (modal.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal({ restoreFocus: true });
        }
    });
}

function initHealthWorkerDeleteModal(root) {
    const modal = root.querySelector('[data-hw-delete-modal]');
    const panel = root.querySelector('[data-hw-delete-panel]');
    const form = root.querySelector('[data-hw-delete-form]');
    const nameTarget = modal?.querySelector('[data-hw-delete-name-label]');
    const confirmBtn = root.querySelector('[data-hw-delete-confirm]');
    const backdrop = root.querySelector('[data-hw-delete-backdrop]');
    const cancelButtons = Array.from(root.querySelectorAll('[data-hw-delete-cancel]'));

    if (!modal || !panel || !form) {
        return;
    }

    let lastTrigger = null;
    let submitting = false;

    const closeModal = ({ restoreFocus = true } = {}) => {
        modal.hidden = true;
        submitting = false;
        delete form.dataset.lmlCsrfReady;
        if (confirmBtn) {
            confirmBtn.disabled = false;
        }
        if (restoreFocus && lastTrigger) {
            lastTrigger.focus();
        }
        lastTrigger = null;
    };

    const openModal = (trigger) => {
        const workerId = trigger.dataset.hwDeleteId || '';
        const workerName = trigger.dataset.hwDeleteName || 'this health worker';
        const doc = form.ownerDocument || document;

        lastTrigger = trigger;
        submitting = false;
        delete form.dataset.lmlCsrfReady;
        if (confirmBtn) {
            confirmBtn.disabled = false;
        }

        form.action = `${form.dataset.hwDeleteBase || '/user-management/health-workers'}/${encodeURIComponent(workerId)}`;
        if (nameTarget) {
            nameTarget.textContent = workerName;
        }
        void ensureFormCsrfToken(form, doc);

        modal.hidden = false;
        panel.focus();
    };

    root.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-hw-delete]');
        if (trigger && root.contains(trigger)) {
            event.preventDefault();
            openModal(trigger);
        }
    });

    cancelButtons.forEach((btn) => {
        btn.addEventListener('click', () => closeModal({ restoreFocus: true }));
    });

    backdrop?.addEventListener('click', () => closeModal({ restoreFocus: true }));

    form.addEventListener('submit', (event) => {
        if (form.dataset.lmlCsrfReady === '1') {
            delete form.dataset.lmlCsrfReady;
            submitting = true;
            if (confirmBtn) {
                confirmBtn.disabled = true;
            }
            return;
        }

        if (submitting) {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        const doc = form.ownerDocument || document;
        if (confirmBtn) {
            confirmBtn.disabled = true;
        }
        void ensureFormCsrfToken(form, doc).then((refreshed) => {
            if (!refreshed) {
                submitting = false;
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                }
                return;
            }
            submitFormAfterCsrfReady(form);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (modal.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal({ restoreFocus: true });
        }
    });
}

export { handleUserManagementNavClick };
