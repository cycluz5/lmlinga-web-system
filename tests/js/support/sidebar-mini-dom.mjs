/**
 * Minimal DOM harness for dashboard-sidebar.js toggle tests.
 * Zero dependencies — only the APIs the sidebar script actually uses.
 */

function createClassList(initial = []) {
    const set = new Set(initial.filter(Boolean));
    return {
        add(...tokens) {
            tokens.flatMap((t) => String(t).split(/\s+/)).filter(Boolean).forEach((t) => set.add(t));
        },
        remove(...tokens) {
            tokens.flatMap((t) => String(t).split(/\s+/)).filter(Boolean).forEach((t) => set.delete(t));
        },
        contains(token) {
            return set.has(token);
        },
        toggle(token, force) {
            if (force === true) {
                set.add(token);
                return true;
            }
            if (force === false) {
                set.delete(token);
                return false;
            }
            if (set.has(token)) {
                set.delete(token);
                return false;
            }
            set.add(token);
            return true;
        },
        toString() {
            return [...set].join(' ');
        },
    };
}

function matches(el, selector) {
    let base = selector;
    let pseudo = '';
    const pseudoMatch = String(selector || '').match(/^(.*):([a-z-]+)$/i);
    if (pseudoMatch) {
        base = pseudoMatch[1];
        pseudo = pseudoMatch[2].toLowerCase();
    }
    if (pseudo === 'checked' && !el.checked) {
        return false;
    }
    if (pseudo === 'disabled' && !el.disabled) {
        return false;
    }

    if (base.startsWith('#')) {
        return el.id === base.slice(1);
    }

    if (base.includes('[')) {
        // Support compound: tag[attr], [attr], [attr="value"], .class[attr="value"]
        const attrMatches = [...base.matchAll(/\[([^\]]+)\]/g)];
        let rest = base;
        for (const m of attrMatches) {
            rest = rest.replace(m[0], '');
        }
        rest = rest.trim();

        for (const m of attrMatches) {
            const expr = m[1];
            const eq = expr.indexOf('=');
            if (eq === -1) {
                if (!el.hasAttribute(expr)) {
                    return false;
                }
                continue;
            }
            const name = expr.slice(0, eq);
            let value = expr.slice(eq + 1);
            if (
                (value.startsWith('"') && value.endsWith('"')) ||
                (value.startsWith("'") && value.endsWith("'"))
            ) {
                value = value.slice(1, -1);
            }
            if (el.getAttribute(name) !== value) {
                return false;
            }
        }

        if (!rest) {
            return true;
        }
        if (rest.startsWith('.')) {
            return rest
                .slice(1)
                .split('.')
                .filter(Boolean)
                .every((c) => el.classList.contains(c));
        }
        return el.tagName === rest.toUpperCase();
    }

    if (base.startsWith('.')) {
        return base
            .slice(1)
            .split('.')
            .filter(Boolean)
            .every((c) => el.classList.contains(c));
    }

    return el.tagName === base.toUpperCase();
}

function createTextNode(value) {
    return {
        nodeType: 3,
        textContent: String(value),
        parentNode: null,
        cloneNode() {
            return createTextNode(this.textContent);
        },
    };
}

function createNode(tagName, attrs = {}) {
    const listeners = new Map();
    const node = {
        tagName: String(tagName).toUpperCase(),
        nodeType: tagName === '#document-fragment' ? 11 : 1,
        children: [],
        parentNode: null,
        attributes: { ...attrs },
        classList: createClassList(String(attrs.class || '').split(/\s+/)),
        id: attrs.id || '',
        _listeners: listeners,
        _isTemplate: tagName.toLowerCase() === 'template',
        content: null,
        ownerDocument: null,
        get textContent() {
            return this.children.map((c) => c.textContent || '').join('');
        },
        set textContent(value) {
            this.children = [];
            if (value) {
                this.appendChild(createTextNode(value));
            }
        },
        appendChild(child) {
            if (child.nodeType === 11) {
                [...child.children].forEach((c) => this.appendChild(c));
                return child;
            }
            if (child.parentNode) {
                child.parentNode.removeChild(child);
            }
            child.parentNode = this;
            this.children.push(child);
            return child;
        },
        removeChild(child) {
            const idx = this.children.indexOf(child);
            if (idx >= 0) {
                this.children.splice(idx, 1);
                child.parentNode = null;
            }
            return child;
        },
        remove() {
            if (this.parentNode) {
                this.parentNode.removeChild(this);
            }
        },
        cloneNode(deep = false) {
            if (this.nodeType === 11) {
                const frag = createFragment();
                frag.ownerDocument = this.ownerDocument;
                if (deep) {
                    this.children.forEach((c) => frag.appendChild(c.cloneNode(true)));
                }
                return frag;
            }
            const clone = createNode(this.tagName.toLowerCase(), { ...this.attributes });
            clone.id = this.id;
            clone.classList = createClassList(this.classList.toString().split(/\s+/));
            clone.ownerDocument = this.ownerDocument;
            if (this._isTemplate) {
                clone._isTemplate = true;
                clone.content = createFragment();
                clone.content.ownerDocument = this.ownerDocument;
                if (deep && this.content) {
                    this.content.children.forEach((c) =>
                        clone.content.appendChild(c.cloneNode(true))
                    );
                }
            } else if (deep) {
                this.children.forEach((c) => clone.appendChild(c.cloneNode(true)));
            }
            return clone;
        },
        getAttribute(name) {
            if (name === 'class') {
                return this.classList.toString() || null;
            }
            if (name === 'id') {
                return this.id || null;
            }
            return Object.prototype.hasOwnProperty.call(this.attributes, name)
                ? this.attributes[name]
                : null;
        },
        setAttribute(name, value) {
            if (name === 'class') {
                this.classList = createClassList(String(value).split(/\s+/));
                this.attributes.class = this.classList.toString();
                return;
            }
            if (name === 'id') {
                this.id = String(value);
                this.attributes.id = this.id;
                return;
            }
            this.attributes[name] = String(value);
        },
        removeAttribute(name) {
            if (name === 'class') {
                this.classList = createClassList([]);
            }
            if (name === 'id') {
                this.id = '';
            }
            delete this.attributes[name];
        },
        hasAttribute(name) {
            if (name === 'class') {
                return this.classList.toString() !== '';
            }
            if (name === 'id') {
                return this.id !== '';
            }
            return Object.prototype.hasOwnProperty.call(this.attributes, name);
        },
        matches(selector) {
            return matches(this, selector);
        },
        closest(selector) {
            let cur = this;
            while (cur && cur.nodeType === 1) {
                if (matches(cur, selector)) {
                    return cur;
                }
                cur = cur.parentNode;
            }
            return null;
        },
        contains(other) {
            let cur = other;
            while (cur) {
                if (cur === this) {
                    return true;
                }
                cur = cur.parentNode;
            }
            return false;
        },
        querySelector(selector) {
            const all = this.querySelectorAll(selector);
            return all[0] || null;
        },
        querySelectorAll(selector) {
            const out = [];
            const walk = (n) => {
                if (n.nodeType === 1 && matches(n, selector)) {
                    out.push(n);
                }
                const kids =
                    n._isTemplate && n.content ? n.content.children : n.children || [];
                kids.forEach(walk);
            };
            (this.children || []).forEach(walk);
            return out;
        },
        addEventListener(type, handler) {
            if (!listeners.has(type)) {
                listeners.set(type, []);
            }
            listeners.get(type).push(handler);
        },
        dispatchEvent(event) {
            if (!event.target) {
                try {
                    event.target = this;
                } catch {
                    // Native Event.target is read-only; listeners still receive event.type.
                }
            }
            const doc = this.ownerDocument;
            const docEntries = doc?._listeners?.get?.(event.type) || [];
            docEntries
                .filter((entry) => entry && entry.capture)
                .forEach((entry) => entry.handler(event));
            let node = this;
            while (node) {
                const list = (node._listeners && node._listeners.get(event.type)) || [];
                list.forEach((handler) => handler(event));
                if (event.cancelBubble) {
                    break;
                }
                node = node.parentNode;
            }
            if (!event.cancelBubble) {
                docEntries
                    .filter((entry) => entry && !entry.capture)
                    .forEach((entry) => entry.handler(event));
            }
            return !event.defaultPrevented;
        },
        click(detail = 1) {
            const event = {
                type: 'click',
                detail,
                target: this,
                bubbles: true,
                defaultPrevented: false,
                cancelBubble: false,
                preventDefault() {
                    this.defaultPrevented = true;
                },
                stopPropagation() {
                    this.cancelBubble = true;
                },
            };
            this.dispatchEvent(event);
        },
        focus() {
            if (this.ownerDocument) {
                this.ownerDocument.activeElement = this;
            }
        },
        blur() {
            if (this.ownerDocument && this.ownerDocument.activeElement === this) {
                this.ownerDocument.activeElement = null;
            }
        },
    };

    Object.keys(attrs).forEach((key) => {
        if (key === 'class' || key === 'id') {
            return;
        }
        node.setAttribute(key, attrs[key]);
    });

    if (node._isTemplate) {
        node.content = createFragment();
    }

    return node;
}

function createFragment() {
    return createNode('#document-fragment');
}

export function createDocument() {
    const listeners = new Map();
    const doc = {
        nodeType: 9,
        activeElement: null,
        documentElement: null,
        body: null,
        _listeners: listeners,
        addEventListener(type, handler, options) {
            if (!listeners.has(type)) {
                listeners.set(type, []);
            }
            listeners.get(type).push({
                handler,
                capture: options === true || options?.capture === true,
            });
        },
        getElementById(id) {
            return this.documentElement?.querySelector(`#${id}`) || null;
        },
        querySelector(selector) {
            return this.documentElement?.querySelector(selector) || null;
        },
        querySelectorAll(selector) {
            return this.documentElement?.querySelectorAll(selector) || [];
        },
        createElement(tag) {
            const el = createNode(tag);
            el.ownerDocument = this;
            if (el.content) {
                el.content.ownerDocument = this;
            }
            return el;
        },
    };
    return doc;
}

export function mountHealthRecordsFixture(options = {}) {
    const {
        hasActiveChild = false,
        startExpanded = hasActiveChild,
        childLabels = [
            'Child Care',
            'Risk Assessment',
            'Family Planning',
            'Maternal',
            'Death',
        ],
        activeChildLabel = null,
        dashboardActive = !hasActiveChild,
    } = options;

    const doc = createDocument();
    const sidebar = doc.createElement('aside');
    sidebar.id = 'lmlDashboardSidebar';
    sidebar.setAttribute('id', 'lmlDashboardSidebar');

    if (dashboardActive) {
        const dash = doc.createElement('a');
        dash.classList.add('lml-sidebar__link', 'lml-sidebar__link--active');
        dash.setAttribute('aria-current', 'page');
        dash.textContent = 'Dashboard';
        sidebar.appendChild(dash);
    }

    const item = doc.createElement('li');
    item.classList.add('lml-sidebar__item', 'lml-sidebar__item--collapse');

    const row = doc.createElement('div');
    row.classList.add('lml-sidebar__collapse-row');
    row.setAttribute('data-lml-sidebar-collapse-row', '');
    if (hasActiveChild && startExpanded) {
        row.classList.add('lml-sidebar__link--parent-expanded');
        row.setAttribute('data-lml-has-active-child', 'true');
    }

    const parentLabel = doc.createElement('span');
    parentLabel.classList.add('lml-sidebar__parent-link');
    const label = doc.createElement('span');
    label.classList.add('lml-sidebar__label');
    label.textContent = 'Health Records';
    parentLabel.appendChild(label);
    row.appendChild(parentLabel);

    const toggle = doc.createElement('button');
    toggle.setAttribute('type', 'button');
    toggle.classList.add('lml-sidebar__chevron-btn');
    toggle.setAttribute('data-lml-sidebar-collapse-toggle', '');
    toggle.setAttribute('aria-controls', 'lml-sidebar-collapse-health-records');
    toggle.setAttribute('aria-expanded', startExpanded ? 'true' : 'false');
    row.appendChild(toggle);
    item.appendChild(row);

    const panel = doc.createElement('div');
    panel.id = 'lml-sidebar-collapse-health-records';
    panel.setAttribute('id', 'lml-sidebar-collapse-health-records');
    panel.classList.add('lml-sidebar__collapse-panel');
    panel.setAttribute('data-lml-sidebar-collapse-panel', '');

    if (hasActiveChild) {
        panel.setAttribute('data-lml-has-active-child', 'true');
    }

    if (startExpanded) {
        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
    } else {
        panel.setAttribute('hidden', '');
        panel.setAttribute('aria-hidden', 'true');
    }

    const buildSublist = () => {
        const ul = doc.createElement('ul');
        ul.classList.add('lml-sidebar__sublist');
        childLabels.forEach((text) => {
            const li = doc.createElement('li');
            const link = doc.createElement('span');
            link.classList.add('lml-sidebar__sublink');
            if (activeChildLabel && text === activeChildLabel) {
                link.classList.add('lml-sidebar__sublink--active');
                link.setAttribute('aria-current', 'page');
            }
            link.textContent = text;
            li.appendChild(link);
            ul.appendChild(li);
        });
        return ul;
    };

    if (startExpanded) {
        panel.appendChild(buildSublist());
    } else {
        const template = doc.createElement('template');
        template.setAttribute('data-lml-sidebar-collapse-template', '');
        template.content.appendChild(buildSublist());
        panel.appendChild(template);
    }

    item.appendChild(panel);
    sidebar.appendChild(item);

    const root = doc.createElement('div');
    root.appendChild(sidebar);
    doc.documentElement = root;

    // Support getElementById on document for the exported initializer.
    doc.getElementById = (id) => root.querySelector(`#${id}`);

    return { doc, sidebar, row, toggle, panel };
}

export function paintedChildLabels(panel) {
    return panel.children
        .filter((child) => child.nodeType === 1 && child.classList.contains('lml-sidebar__sublist'))
        .flatMap((ul) =>
            ul.children
                .filter((li) => li.nodeType === 1)
                .flatMap((li) =>
                    li.children.filter(
                        (node) =>
                            node.nodeType === 1 && node.classList.contains('lml-sidebar__sublink')
                    )
                )
        )
        .map((el) => String(el.textContent || '').trim())
        .filter(Boolean);
}

export function templateCount(panel) {
    return panel.children.filter(
        (child) =>
            child.nodeType === 1 &&
            child.tagName === 'TEMPLATE' &&
            child.hasAttribute('data-lml-sidebar-collapse-template')
    ).length;
}
