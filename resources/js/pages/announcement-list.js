/**
 * Announcement list pages — client-side search/filter for demo fixtures only.
 */

function matchesFilter(item, mode, filter) {
    if (filter === 'all') {
        return true;
    }

    if (mode === 'upcoming') {
        if (filter === 'week') {
            return item.getAttribute('data-filter-week') === '1';
        }
        if (filter === 'month') {
            return item.getAttribute('data-filter-month') === '1';
        }
        return true;
    }

    if (mode === 'recent') {
        return item.getAttribute('data-timing') === filter;
    }

    return true;
}

function matchesSearch(item, query) {
    if (!query) {
        return true;
    }

    return (item.getAttribute('data-search') || '').includes(query);
}

function applyListFilters(root) {
    const mode = root.getAttribute('data-announce-list-mode') || 'upcoming';
    const query = (root.querySelector('[data-announce-search]')?.value || '')
        .trim()
        .toLowerCase();
    const activeFilter = root.querySelector('[data-announce-filter].is-active')
        ?.getAttribute('data-announce-filter') || 'all';
    const emptyEl = root.querySelector('[data-announce-empty]');

    let visible = 0;

    root.querySelectorAll('[data-announce-item]').forEach((item) => {
        const show = matchesFilter(item, mode, activeFilter) && matchesSearch(item, query);
        item.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (emptyEl) {
        emptyEl.hidden = visible > 0;
    }
}

function initAnnounceList(root) {
    root.querySelector('[data-announce-search]')?.addEventListener('input', () => {
        applyListFilters(root);
    });

    root.querySelectorAll('[data-announce-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            root.querySelectorAll('[data-announce-filter]').forEach((el) => {
                el.classList.toggle('is-active', el === button);
                el.setAttribute('aria-pressed', el === button ? 'true' : 'false');
            });
            applyListFilters(root);
        });
    });

    applyListFilters(root);
}

document.querySelectorAll('[data-lml-announce-list]').forEach((root) => {
    initAnnounceList(root);
});
