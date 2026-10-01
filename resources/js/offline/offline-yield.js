/**
 * Background offline work yields to the person using the page.
 *
 * `php artisan serve` answers one request at a time, so a preparation fetch
 * that starts while staff click or type makes their own request wait. Before
 * each background fetch we wait until input has been quiet for a moment.
 */

const QUIET_MS = 800;
const MAX_WAIT_MS = 5000;
const POLL_MS = 150;

let lastActivityAt = 0;
let listening = false;

function listen(win) {
    if (listening || !win || typeof win.addEventListener !== 'function') {
        return;
    }
    listening = true;
    const mark = () => {
        lastActivityAt = Date.now();
    };
    ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach((type) => {
        win.addEventListener(type, mark, { capture: true, passive: true });
    });
}

/**
 * Resolves at once when nobody has interacted recently; otherwise waits for a
 * quiet gap (capped so background work never stalls for good).
 *
 * @param {{ window?: Window|null }} [options]
 */
export async function waitForQuietUser(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    listen(win);
    if (!lastActivityAt || typeof win?.setTimeout !== 'function') {
        return;
    }
    const started = Date.now();
    while (Date.now() - lastActivityAt < QUIET_MS && Date.now() - started < MAX_WAIT_MS) {
        await new Promise((resolve) => win.setTimeout(resolve, POLL_MS));
    }
}

/**
 * Run `task` once the browser is idle (or after `timeoutMs` at the latest).
 *
 * @param {() => void} task
 * @param {{ window?: Window|null, timeoutMs?: number }} [options]
 */
export function whenIdle(task, options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const timeoutMs = options.timeoutMs ?? 3000;
    listen(win);
    if (typeof win?.requestIdleCallback === 'function') {
        win.requestIdleCallback(() => task(), { timeout: timeoutMs });
        return;
    }
    if (typeof win?.setTimeout === 'function') {
        win.setTimeout(task, 1500);
        return;
    }
    task();
}
