/**
 * Announcement dashboard bulk management.
 * Search / filters / pagination / action menus.
 */

const PAGE_SIZE_DEFAULT = 10;

function resetActionListPosition(list) {
    list.classList.remove('is-fixed');
    list.style.top = '';
    list.style.left = '';
}

function positionActionList(toggle, list) {
    const rect = toggle.getBoundingClientRect();
    const gap = 4;
    const width = Math.max(list.offsetWidth, 152);
    const height = list.offsetHeight || 0;
    let top = rect.bottom + gap;
    let left = rect.right - width;

    if (left < 8) {
        left = 8;
    }
    if (left + width > window.innerWidth - 8) {
        left = Math.max(8, window.innerWidth - width - 8);
    }
    if (height > 0 && top + height > window.innerHeight - 8 && rect.top - gap - height >= 8) {
        top = rect.top - gap - height;
    }

    list.classList.add('is-fixed');
    list.style.top = `${Math.round(top)}px`;
    list.style.left = `${Math.round(left)}px`;
}

function closeAllActionMenus(except = null) {
    document.querySelectorAll('[data-announce-action-menu]').forEach((menu) => {
        if (except && menu === except) {
            return;
        }
        const toggle = menu.querySelector('[data-announce-action-toggle]');
        const list = menu.querySelector('[data-announce-action-list]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
        if (list) {
            list.hidden = true;
            resetActionListPosition(list);
        }
    });
}

function initActionMenus(root) {
    root.querySelectorAll('[data-announce-action-menu]').forEach((menu) => {
        const toggle = menu.querySelector('[data-announce-action-toggle]');
        const list = menu.querySelector('[data-announce-action-list]');
        if (!toggle || !list) {
            return;
        }

        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const shouldOpen = list.hidden;
            closeAllActionMenus(menu);
            if (!shouldOpen) {
                return;
            }
            list.hidden = false;
            positionActionList(toggle, list);
            toggle.setAttribute('aria-expanded', 'true');
        });

        list.querySelectorAll('[data-announce-action]').forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.stopPropagation();
                const action = btn.getAttribute('data-announce-action');
                if (action === 'view' || action === 'edit') {
                    closeAllActionMenus();
                    return;
                }
                if (action === 'delete') {
                    return;
                }
                event.preventDefault();
                closeAllActionMenus();
            });
        });
    });

    if (!document.documentElement.hasAttribute('data-announce-action-menu-bound')) {
        document.documentElement.setAttribute('data-announce-action-menu-bound', '1');
        document.addEventListener('click', () => closeAllActionMenus());
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAllActionMenus();
            }
        });
        window.addEventListener('resize', () => closeAllActionMenus());
        window.addEventListener('scroll', () => closeAllActionMenus(), true);
    }
}

function rowMatches(row, state) {
    const query = state.query;
    if (query && !(row.getAttribute('data-search') || '').includes(query)) {
        return false;
    }

    const status = state.status;
    if (status !== 'all') {
        if (status === 'published') {
            if (row.getAttribute('data-status') !== 'published' && row.getAttribute('data-timing') !== 'past') {
                return false;
            }
        } else if (status === 'past') {
            if (row.getAttribute('data-timing') !== 'past') {
                return false;
            }
        } else if (row.getAttribute('data-status') !== status && row.getAttribute('data-timing') !== status) {
            return false;
        }
    }

    const audience = state.audience;
    if (audience !== 'all') {
        const type = row.getAttribute('data-audience') || '';
        if (audience === 'all_households' && type !== 'all') {
            return false;
        }
        if (audience !== 'all_households' && type !== audience) {
            return false;
        }
    }

    const date = state.date;
    if (date === 'today' && row.getAttribute('data-date-today') !== '1') {
        return false;
    }
    if (date === 'week' && row.getAttribute('data-date-week') !== '1') {
        return false;
    }
    if (date === 'month' && row.getAttribute('data-date-month') !== '1') {
        return false;
    }

    return true;
}

function collectUniqueRows(root) {
    // Table + mobile cards share the same data attributes; prefer visible layout rows.
    const isDesktop = window.matchMedia('(min-width: 768px)').matches;
    if (isDesktop) {
        return Array.from(root.querySelectorAll('[data-announce-manage-table-body] [data-announce-manage-row]'));
    }
    return Array.from(root.querySelectorAll('[data-announce-manage-cards] [data-announce-manage-row]'));
}

function readState(root) {
    return {
        query: (root.querySelector('[data-announce-manage-search]')?.value || '').trim().toLowerCase(),
        status: root.querySelector('[data-announce-manage-status]')?.value || 'all',
        audience: root.querySelector('[data-announce-manage-audience]')?.value || 'all',
        date: root.querySelector('[data-announce-manage-date]')?.value || 'all',
        page: Number(root.dataset.announcePage || '1') || 1,
        pageSize: Number(root.getAttribute('data-announce-page-size') || PAGE_SIZE_DEFAULT) || PAGE_SIZE_DEFAULT,
    };
}

function renderPagination(root, total, page, pageSize) {
    const meta = root.querySelector('[data-announce-manage-meta]');
    const pagesEl = root.querySelector('[data-announce-manage-pages]');
    const prev = root.querySelector('[data-announce-manage-prev]');
    const next = root.querySelector('[data-announce-manage-next]');
    const pager = root.querySelector('[data-announce-manage-pagination]');
    const empty = root.querySelector('[data-announce-manage-empty]');

    const pageCount = Math.max(1, Math.ceil(total / pageSize));
    const safePage = Math.min(Math.max(page, 1), pageCount);
    root.dataset.announcePage = String(safePage);

    if (empty) {
        empty.hidden = total > 0;
    }
    if (pager) {
        pager.hidden = total === 0;
    }

    if (total === 0) {
        if (meta) {
            meta.textContent = 'Showing 0 of 0 announcements';
        }
        if (pagesEl) {
            pagesEl.innerHTML = '';
        }
        if (prev) {
            prev.disabled = true;
        }
        if (next) {
            next.disabled = true;
        }
        return { page: 1, pageCount: 1, start: 0, end: 0 };
    }

    const start = (safePage - 1) * pageSize + 1;
    const end = Math.min(safePage * pageSize, total);

    if (meta) {
        meta.textContent = `Showing ${start}–${end} of ${total} announcements`;
    }

    if (pagesEl) {
        pagesEl.innerHTML = '';
        for (let i = 1; i <= pageCount; i += 1) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'lml-announce__page-num lml-focus-ring' + (i === safePage ? ' is-active' : '');
            btn.textContent = String(i);
            btn.setAttribute('aria-label', `Page ${i}`);
            if (i === safePage) {
                btn.setAttribute('aria-current', 'page');
            }
            btn.addEventListener('click', () => {
                root.dataset.announcePage = String(i);
                applyManageFilters(root);
            });
            pagesEl.appendChild(btn);
        }
    }

    if (prev) {
        prev.disabled = safePage <= 1;
    }
    if (next) {
        next.disabled = safePage >= pageCount;
    }

    return { page: safePage, pageCount, start, end };
}

function applyManageFilters(root) {
    const state = readState(root);

    // Filter both table and card copies so layout switches stay in sync.
    const allRows = Array.from(root.querySelectorAll('[data-announce-manage-row]'));
    const matched = [];

    allRows.forEach((row) => {
        const ok = rowMatches(row, state);
        row.dataset.manageMatch = ok ? '1' : '0';
        if (ok) {
            matched.push(row);
        }
    });

    // Deduplicate by pairing table/card via shared search+title position is fragile;
    // count unique via desktop rows when present.
    const tableMatched = Array.from(
        root.querySelectorAll('[data-announce-manage-table-body] [data-announce-manage-row]'),
    ).filter((row) => row.dataset.manageMatch === '1');
    const total = tableMatched.length;

    const { page, start, end } = renderPagination(root, total, state.page, state.pageSize);

    tableMatched.forEach((row, index) => {
        const visible = index >= start - 1 && index < end;
        row.hidden = !visible;
    });

    const cardMatched = Array.from(
        root.querySelectorAll('[data-announce-manage-cards] [data-announce-manage-row]'),
    ).filter((row) => row.dataset.manageMatch === '1');

    cardMatched.forEach((row, index) => {
        const visible = index >= start - 1 && index < end;
        row.hidden = !visible;
    });

    // Hide non-matching rows entirely.
    allRows.forEach((row) => {
        if (row.dataset.manageMatch !== '1') {
            row.hidden = true;
        }
    });
}

function initAnnounceManage(root) {
    root.dataset.announcePage = '1';

    const refresh = (resetPage = false) => {
        if (resetPage) {
            root.dataset.announcePage = '1';
        }
        applyManageFilters(root);
    };

    root.querySelector('[data-announce-manage-search]')?.addEventListener('input', () => refresh(true));
    root.querySelector('[data-announce-manage-status]')?.addEventListener('change', () => refresh(true));
    root.querySelector('[data-announce-manage-audience]')?.addEventListener('change', () => refresh(true));
    root.querySelector('[data-announce-manage-date]')?.addEventListener('change', () => refresh(true));

    root.querySelector('[data-announce-manage-prev]')?.addEventListener('click', () => {
        const page = Number(root.dataset.announcePage || '1') - 1;
        root.dataset.announcePage = String(Math.max(1, page));
        applyManageFilters(root);
    });

    root.querySelector('[data-announce-manage-next]')?.addEventListener('click', () => {
        const page = Number(root.dataset.announcePage || '1') + 1;
        root.dataset.announcePage = String(page);
        applyManageFilters(root);
    });

    window.addEventListener('resize', () => applyManageFilters(root));

    initActionMenus(root);
    applyManageFilters(root);
}

document.querySelectorAll('[data-lml-announce-manage]').forEach((root) => {
    initAnnounceManage(root);
});
