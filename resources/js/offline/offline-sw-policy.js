/**
 * OFFLINE-7 cache policy — pure functions shared by the service worker and tests.
 *
 * Cache Storage only. Never touches IndexedDB (OFFLINE-6 durable queue).
 * CSRF tokens are stripped from cached HTML and are never treated as credentials.
 */

export const CACHE_VERSION = 'offline-7-v20';

export const ASSETS_CACHE = `lmlinga-assets-${CACHE_VERSION}`;
export const FALLBACK_CACHE = `lmlinga-fallback-${CACHE_VERSION}`;
export const META_CACHE = `lmlinga-meta-${CACHE_VERSION}`;
export const HTML_CACHE_PREFIX = `lmlinga-html-${CACHE_VERSION}-actor-`;
export const AVATAR_CACHE_PREFIX = `lmlinga-avatar-${CACHE_VERSION}-actor-`;

export const CURRENT_CACHE_NAMES = [ASSETS_CACHE, FALLBACK_CACHE, META_CACHE];

export const ACTOR_META_PATH = '/__lmlinga/sw-current-actor';

export const MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

/**
 * Opaque URL codes (server OpaqueId): kind letter + base32. Raw ids stay accepted in the
 * patterns only for offline-created (local) records and old cached shapes.
 */
export const HOUSEHOLD_KEY = 'h[a-z2-7]{12,}';
export const MEMBER_KEY = 'm[a-z2-7]{12,}';
export const RESIDENT_KEY = 'r[a-z2-7]{12,}';
const ASSESSMENT_ID = '(?:RA-[0-9]+|s[a-z2-7]{12,})';
const FP_VISIT_ID = '(?:FP-[0-9]+|v[a-z2-7]{12,})';
const PREGNANCY_ID = '(?:MC-[0-9]+|p[a-z2-7]{12,})';

const HOUSEHOLD_NO = `(?:HH-[0-9]+|[0-9]{3}|${HOUSEHOLD_KEY})`;
const MEMBER_NO = `(?:MB-[0-9]+|${MEMBER_KEY})`;
const LOCAL_MEMBER_NO = 'MB-L-[A-Za-z0-9]+';
const MEMBER_ANY = `(?:${MEMBER_NO}|${LOCAL_MEMBER_NO})`;
const RESIDENT_ID = `(?:[0-9]+|${RESIDENT_KEY})`;

export const HOUSEHOLD_NO_PATTERN = HOUSEHOLD_NO;
export const MEMBER_NO_PATTERN = MEMBER_NO;
export const LOCAL_MEMBER_NO_PATTERN = LOCAL_MEMBER_NO;

export const STAFF_AVATAR_PATH_PATTERN =
    /^\/storage\/health-workers\/profile-photos\/[A-Za-z0-9._-]+\.(jpe?g|png)$/i;

/**
 * Fixed GET shells that every authenticated staff role can open.
 * Dynamic IDs are never listed here — those stay runtime-cache-only.
 */
export const WARMUP_PATHS_SHARED = Object.freeze([
    '/dashboard',
    '/spot-mapping',
    '/household-profiling',
    '/household-profiling/create',
    '/announcements',
    '/environmental-health',
    '/health-records/child-care',
    '/health-records/risk-assessment',
    '/health-records/maternal',
    '/health-records/death',
    '/health-records/family-planning',
    '/profile',
]);

/**
 * Admin-only GET indexes. Middleware `ui.admin` returns 403 for BHW/BNS/BSPO.
 * Never include these in a non-admin warmup list.
 *
 * /user-management is intentionally omitted: the index hosts Activate/Deactivate
 * forms and must not be served from sanitized SW cache (blank/stale CSRF).
 * View/edit worker pages remain warmable via warmUserManagementListedWorkers.
 */
export const WARMUP_PATHS_ADMIN = Object.freeze([
    '/household-requests',
    '/death-requests',
]);

/**
 * Shared warmup alias. Admin extras live in WARMUP_PATHS_ADMIN and are
 * selected by warmupPathsForRole().
 *
 * /spot-mapping is warmed because Plot New Household is an in-page panel on
 * that page (POST /spot-mapping/plot-new).
 *
 * /household-profiling/create is warmed so staff can open Add Household
 * Information when the map/GPS/network is later unavailable (information-first
 * HOUSEHOLD_CREATE). Coordinates remain optional; plotting stays on Spot Mapping
 * → Pending Households.
 *
 * Dynamic household/member/health URLs are not in this fixed list. Parent pages
 * warm currently visible related records through MESSAGE_WARMUP_RELATED.
 */
export const CORE_WARMUP_PATHS = WARMUP_PATHS_SHARED;

export const SAFE_NAVIGATION_PATTERNS = [
    /^\/dashboard\/?$/,
    /^\/household-profiling\/?$/,
    /^\/household-profiling\/create\/?$/,
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/create/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/edit/?$`),
    /^\/spot-mapping\/?$/,
    /^\/announcements\/?$/,
    /^\/announcements\/upcoming\/?$/,
    /^\/announcements\/recent\/?$/,
    /^\/announcements\/\d+\/?$/,
    /^\/profile\/?$/,
    /^\/environmental-health\/?$/,
    /^\/health-records\/child-care\/?$/,
    /^\/health-records\/risk-assessment\/?$/,
    /^\/health-records\/maternal\/?$/,
    /^\/health-records\/death\/?$/,
    /^\/health-records\/family-planning\/?$/,
    /^\/user-management\/?$/,
    /^\/household-requests\/?$/,
    /^\/death-requests\/?$/,
];

export const NEVER_CACHE_PATH_PREFIXES = [
    '/login',
    '/logout',
    '/register',
    '/forgot-password',
    '/reset-password',
    '/change-password',
    '/offline', // /offline/status and /offline/sync
    '/chatbot',
    '/storage',
    '/sanctum',
    '/up',
    '/announcements/create',
    '/landing',
];

export const NEVER_CACHE_PATH_MATCHERS = [
    /\/export(?:\/|$)/i,
    /\/certificate(?:\/|$)/i,
    /password/i,
    /\/otp(?:\/|$)/i,
    /verification/i,
    /\/announcements\/\d+\/edit(?:\/|$)/i,
];

const WRITE_GET_PATH_PATTERN = /\/(?:create|edit|register)(?:\/|$)/i;

const HP_OFFLINE_WRITE_PATTERNS = [
    /^\/household-profiling\/create\/?$/,
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/amenities/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/create/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/child-immunization/birth-history/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/deworming/create/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/risk-assessment/create/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/risk-assessment/${ASSESSMENT_ID}/(?:red-flags|past-medical|family-history|lifestyle|physical)/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/family-planning/create/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/family-planning/${FP_VISIT_ID}/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/maternal-care/register/?$`),
    // Nutritional Status / Timbang create — form already queues HEALTH_SERVICE_WRITE.
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/nutritional-status/create/?$`),
    new RegExp(`^/households/${HOUSEHOLD_NO}/residents/${RESIDENT_ID}/nutritional-status/create/?$`),
];

/** Numeric Health Worker edit GET only. Create stays online-only. */
const UM_OFFLINE_WRITE_PATTERNS = [
    /^\/user-management\/health-workers\/(?:[0-9]+|w[a-z2-7]{12,})\/edit\/?$/,
];

const SAFE_MODULE_PREFIXES = [
    '/dashboard',
    '/spot-mapping',
    '/household-profiling',
    '/households', // canonical resident-based Nutritional Status destinations
    '/announcements',
    '/environmental-health',
    '/health-records',
    '/user-management',
    '/household-requests',
    '/death-requests',
    '/profile',
];

export const UNSAFE_QUERY_KEYS = [
    'token',
    'signature',
    'expires',
    'password',
    'otp',
    'reset',
    'hash',
    'signed',
    'csrf',
    'csrf_token',
    '_token',
    'handoff',
];

export const MESSAGE_SET_ACTOR = 'lmlinga:set-actor';
export const MESSAGE_CLEAR_HTML = 'lmlinga:clear-html-cache';
export const MESSAGE_WARMUP_CORE = 'lmlinga:warmup-core';
export const MESSAGE_WARMUP_UM_WORKERS = 'lmlinga:warmup-um-workers';
export const MESSAGE_WARMUP_RELATED = 'lmlinga:warmup-related';
export const MESSAGE_WARMUP_PROGRESS = 'lmlinga:warmup-progress';
export const MESSAGE_WARMUP_COMPLETE = 'lmlinga:warmup-complete';
export const MESSAGE_WARMUP_READY_QUERY = 'lmlinga:warmup-ready-query';
export const MESSAGE_WARMUP_READY_RESULT = 'lmlinga:warmup-ready-result';
/** Page → SW: precache + verify current /build/manifest.json assets into ASSETS_CACHE. */
export const MESSAGE_ENSURE_BUILD_ASSETS = 'lmlinga:ensure-build-assets';
export const MESSAGE_ENSURE_BUILD_ASSETS_RESULT = 'lmlinga:ensure-build-assets-result';

/** Vite entry keys that must be offline-ready in BUILD mode. */
export const CRITICAL_BUILD_MANIFEST_ENTRIES = Object.freeze([
    'resources/css/app.css',
    'resources/js/app.js',
]);

/**
 * Normalize a Vite manifest file path to a same-origin /build/... pathname.
 * @param {string} file
 * @returns {string|null}
 */
export function buildAssetPathFromManifestFile(file) {
    const path = String(file || '').replace(/^\/+/, '');
    if (!path) {
        return null;
    }
    if (path.startsWith('build/')) {
        return `/${path}`;
    }
    if (path.startsWith('assets/')) {
        return `/build/${path}`;
    }
    return null;
}

/**
 * Critical hashed assets for Offline Preparation readiness (from current manifest).
 * Includes CSS/JS entry files, JS-associated CSS/assets, and build font files.
 *
 * @param {Record<string, { file?: string, css?: string[], assets?: string[] }>|null|undefined} manifest
 * @returns {string[]}
 */
export function criticalBuildAssetPathsFromManifest(manifest) {
    const files = new Set();
    CRITICAL_BUILD_MANIFEST_ENTRIES.forEach((key) => {
        const entry = manifest?.[key];
        if (!entry || typeof entry !== 'object') {
            return;
        }
        if (typeof entry.file === 'string') {
            files.add(entry.file);
        }
        if (Array.isArray(entry.css)) {
            entry.css.forEach((file) => files.add(file));
        }
        if (Array.isArray(entry.assets)) {
            entry.assets.forEach((file) => files.add(file));
        }
    });
    Object.values(manifest || {}).forEach((entry) => {
        const file = entry && typeof entry.file === 'string' ? entry.file : '';
        if (/\.(woff2?|ttf|eot)$/i.test(file) && file.startsWith('assets/')) {
            files.add(file);
        }
    });
    return [...files]
        .map((file) => buildAssetPathFromManifestFile(file))
        .filter((path) => typeof path === 'string' && path.startsWith('/build/'));
}

/**
 * True when the document is wired to Vite DEV assets (not /build/assets).
 * Offline fidelity requires BUILD URLs; DEV must not claim production Ready.
 *
 * @param {Document|null|undefined} doc
 * @returns {boolean}
 */
export function documentUsesViteDevAssets(doc) {
    if (!doc || typeof doc.querySelectorAll !== 'function') {
        return false;
    }
    const nodes = [
        ...doc.querySelectorAll('link[href]'),
        ...doc.querySelectorAll('script[src]'),
    ];
    const base = typeof doc.baseURI === 'string' && doc.baseURI ? doc.baseURI : 'http://localhost';
    return nodes.some((el) => isViteDevAssetUrl(
        el.getAttribute?.('href') || el.getAttribute?.('src') || '',
        base,
    ));
}

/**
 * True when a stylesheet/script URL points at Vite DEV (not /build/assets).
 * Matches the dev asset patterns themselves, not one hardcoded port.
 *
 * @param {string} rawUrl
 * @param {string} [baseUrl]
 * @returns {boolean}
 */
export function isViteDevAssetUrl(rawUrl, baseUrl = 'http://localhost') {
    const raw = String(rawUrl || '');
    if (!raw) {
        return false;
    }
    if (raw.includes('/@vite/') || raw.includes('/@fs/')) {
        return true;
    }
    if (raw.includes('/resources/css/') || raw.includes('/resources/js/')) {
        return true;
    }
    if (raw.includes('/node_modules/')) {
        return true;
    }
    try {
        const parsed = new URL(raw, baseUrl);
        if (parsed.pathname.startsWith('/resources/')) {
            return true;
        }
        if (/:(5173|5174)$/.test(parsed.host) || parsed.hostname === '[::1]') {
            return parsed.pathname.includes('/resources/') || parsed.pathname.includes('/@vite');
        }
    } catch {
        // Ignore malformed URLs.
    }
    return false;
}

/**
 * True when cached/fetched HTML text references Vite DEV assets in a <link href>
 * or <script src>. Used to detect stale navigation HTML captured during a DEV
 * session so Offline Preparation can refetch it in BUILD mode.
 *
 * @param {string|null|undefined} html
 * @returns {boolean}
 */
export function htmlUsesViteDevAssets(html) {
    if (typeof html !== 'string' || html === '') {
        return false;
    }
    const tags = html.match(/<(?:link|script)\b[^>]*>/gi) || [];
    return tags.some((tag) => {
        const match = /\s(?:href|src)\s*=\s*(?:"([^"]*)"|'([^']*)')/i.exec(tag);
        return Boolean(match) && isViteDevAssetUrl(match[1] ?? match[2] ?? '');
    });
}

/** localStorage key prefix for page-layer preparation gate (actor + version). */
export const PREPARE_STORAGE_PREFIX = 'lmlinga.offline.prepared.';

export function prepareStorageKey(actorId, version = CACHE_VERSION) {
    const id = Number(actorId);
    if (!Number.isInteger(id) || id <= 0) {
        return null;
    }
    return `${PREPARE_STORAGE_PREFIX}${version}:${id}`;
}

/**
 * User-facing preparation line for a warmup path (never raw URLs in normal UI).
 */
export function warmPathStatusLabel(pathname) {
    const module = moduleLabelForPath(pathname);
    if (module === 'This page') {
        return 'Preparing authorized pages…';
    }
    return `Preparing ${module}…`;
}

export const SW_CACHE_META = 'lmlinga-offline-cache';

/** Same-origin branding used by the authenticated dashboard shell. */
export const SHELL_STATIC_PATHS = [
    '/assets/images/logo/logo.png',
    '/assets/images/logo/LMLogo.png',
    '/favicon.ico',
];

export function htmlCacheNameForActor(actorId) {
    const id = Number(actorId);
    if (!Number.isInteger(id) || id <= 0) {
        return null;
    }
    return `${HTML_CACHE_PREFIX}${id}`;
}

export function avatarCacheNameForActor(actorId) {
    const id = Number(actorId);
    if (!Number.isInteger(id) || id <= 0) {
        return null;
    }
    return `${AVATAR_CACHE_PREFIX}${id}`;
}

export function isLmlingaOwnedCache(name) {
    if (typeof name !== 'string') {
        return false;
    }
    return (
        name.startsWith('lmlinga-assets-') ||
        name.startsWith('lmlinga-html-') ||
        name.startsWith('lmlinga-avatar-') ||
        name.startsWith('lmlinga-fallback-') ||
        name.startsWith('lmlinga-meta-')
    );
}

export function isCurrentOwnedCache(name) {
    if (CURRENT_CACHE_NAMES.includes(name)) {
        return true;
    }
    return (
        typeof name === 'string'
        && (name.startsWith(HTML_CACHE_PREFIX) || name.startsWith(AVATAR_CACHE_PREFIX))
    );
}

export function obsoleteOwnedCaches(existingNames) {
    if (!Array.isArray(existingNames)) {
        return [];
    }
    return existingNames.filter((name) => isLmlingaOwnedCache(name) && !isCurrentOwnedCache(name));
}

export function normalizePathname(pathname) {
    if (typeof pathname !== 'string' || pathname === '') {
        return '/';
    }
    const trimmed = pathname.replace(/\/+$/, '');
    return trimmed === '' ? '/' : trimmed;
}

export function isSameOrigin(url, origin) {
    try {
        const parsed = typeof url === 'string' ? new URL(url, origin) : url;
        return parsed.origin === origin;
    } catch {
        return false;
    }
}

export function isMutationMethod(method) {
    return MUTATION_METHODS.includes(String(method || '').toUpperCase());
}

export function isLogoutRequest(request, url) {
    const method = String(request?.method || '').toUpperCase();
    const pathname = normalizePathname(url?.pathname || '');
    return method === 'POST' && pathname === '/logout';
}

export function isStaticAssetPath(pathname) {
    const path = normalizePathname(pathname);
    if (path === '/build/manifest.json' || path === '/favicon.ico') {
        return true;
    }
    if (path.startsWith('/build/assets/')) {
        return true;
    }
    if (SHELL_STATIC_PATHS.includes(path)) {
        return true;
    }
    return /^\/assets\/images\/logo\/[^/]+\.(png|jpe?g|svg|webp|ico)$/i.test(path);
}

export function isDevelopmentOnlyPath(pathname) {
    const path = typeof pathname === 'string' ? pathname : '';
    return (
        path === '/hot' ||
        path.startsWith('/@vite') ||
        path.startsWith('/@fs') ||
        path.startsWith('/resources/') ||
        path.startsWith('/node_modules/')
    );
}

export function isNeverCachePath(pathname) {
    const path = normalizePathname(pathname);
    if (isDevelopmentOnlyPath(path)) {
        return true;
    }
    if (NEVER_CACHE_PATH_PREFIXES.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) {
        return true;
    }
    return NEVER_CACHE_PATH_MATCHERS.some((pattern) => pattern.test(path));
}

export function isManagedStaffAvatarPath(pathname) {
    return STAFF_AVATAR_PATH_PATTERN.test(normalizePathname(pathname));
}

export function avatarPathFromUrl(raw, origin) {
    if (raw == null || raw === '') {
        return null;
    }
    try {
        const parsed = new URL(String(raw), origin || 'http://localhost');
        if (origin && !isSameOrigin(parsed, origin)) {
            return null;
        }
        if (hasUnsafeQuery(parsed)) {
            return null;
        }
        const path = normalizePathname(parsed.pathname);
        return isManagedStaffAvatarPath(path) ? path : null;
    } catch {
        return null;
    }
}

export function shouldCacheActorAvatarResponse(response, finalUrl, origin) {
    if (!response || !response.ok || response.status !== 200) {
        return false;
    }
    if (response.type !== 'basic' && response.type !== 'default') {
        return false;
    }
    const path = avatarPathFromUrl(finalUrl || '', origin);
    if (!path) {
        return false;
    }
    const contentType = String(response.headers?.get?.('content-type') || '').toLowerCase();
    if (contentType && !contentType.startsWith('image/')) {
        return false;
    }
    return true;
}

export function isHouseholdProfilingOfflineWritePath(pathname) {
    const path = normalizePathname(pathname);
    return HP_OFFLINE_WRITE_PATTERNS.some((pattern) => pattern.test(path));
}

export function isLocalMemberId(value) {
    return new RegExp(`^${LOCAL_MEMBER_NO}$`).test(String(value || '').trim());
}

export function isLocalMemberViewPath(pathname) {
    return new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${LOCAL_MEMBER_NO}/?$`).test(
        normalizePathname(pathname),
    );
}

export function isLocalMemberEditPath(pathname) {
    return new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${LOCAL_MEMBER_NO}/edit/?$`).test(
        normalizePathname(pathname),
    );
}

export function isLocalMemberHealthPath(pathname) {
    const path = normalizePathname(pathname);
    if (isDeathCertificateWritePath(path)) {
        return false;
    }
    return new RegExp(
        `^/household-profiling/${HOUSEHOLD_NO}/members/${LOCAL_MEMBER_NO}/(?!edit(?:/|$)).+`,
    ).test(path);
}

export function isDeathCertificateWritePath(pathname) {
    const path = normalizePathname(pathname);
    return new RegExp(
        `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/death/(?:create|edit)/?$`,
    ).test(path);
}

const RELATED_WARM_PATTERNS = [
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/amenities/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/amenities/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/create/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/edit/?$`),
    new RegExp(
        `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/child-immunization(?:/birth-history/edit)?/?$`,
    ),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/school-based-immunization/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/child-nutrition/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/nutritional-status(?:/create)?/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/deworming(?:/create)?/?$`),
    new RegExp(
        `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/risk-assessment(?:/create)?/?$`,
    ),
    new RegExp(
        `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/risk-assessment/${ASSESSMENT_ID}(?:/(?:red-flags|past-medical|family-history|lifestyle|physical)(?:/edit)?)?/?$`,
    ),
    new RegExp(
        `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/family-planning(?:/create)?/?$`,
    ),
    new RegExp(
        `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/family-planning/${FP_VISIT_ID}(?:/edit)?/?$`,
    ),
    new RegExp(
        `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/maternal-care(?:/register|/history(?:/${PREGNANCY_ID})?|/trans-out|/prenatal|/immunizations|/supplementations|/laboratory|/delivery|/postnatal)?/?$`,
    ),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/death/?$`),
    // Canonical resident-based Nutritional Status destination (applies to all residents, not only children).
    new RegExp(`^/households/${HOUSEHOLD_NO}/residents/${RESIDENT_ID}/nutritional-status(?:/create)?/?$`),
    /^\/environmental-health\/household-water-supply\/?$/,
    new RegExp(`^/environmental-health/household-water-supply/${HOUSEHOLD_NO}/step-[234]/?$`),
];

export function isRelatedWarmPath(pathname) {
    const path = normalizePathname(pathname);
    if (isNeverCachePath(path) || isDeathCertificateWritePath(path)) {
        return false;
    }
    if (!isSafeNavigationPath(path)) {
        return false;
    }
    return RELATED_WARM_PATTERNS.some((pattern) => pattern.test(path));
}

export function allowedNavigationSearch(pathname, search) {
    const path = normalizePathname(pathname);
    if (path !== '/environmental-health/household-water-supply') {
        return '';
    }
    const raw = String(search || '');
    const query = raw.startsWith('?') ? raw.slice(1) : raw;
    if (!query) {
        return '';
    }
    try {
        const params = new URLSearchParams(query);
        const keys = [...params.keys()];
        if (keys.length !== 1 || keys[0] !== 'household') {
            return '';
        }
        const household = String(params.get('household') || '').trim();
        if (!new RegExp(`^${HOUSEHOLD_NO}$`, 'i').test(household)) {
            return '';
        }
        return `?household=${encodeURIComponent(household)}`;
    } catch {
        return '';
    }
}

export function isUserManagementOfflineWritePath(pathname) {
    const path = normalizePathname(pathname);
    return UM_OFFLINE_WRITE_PATTERNS.some((pattern) => pattern.test(path));
}

export function isUserManagementHealthWorkerViewPath(pathname) {
    return /^\/user-management\/health-workers\/(?:[0-9]+|w[a-z2-7]{12,})\/view$/.test(normalizePathname(pathname));
}

export function isUserManagementHealthWorkerEditPath(pathname) {
    return isUserManagementOfflineWritePath(pathname);
}

export function isUserManagementHealthWorkerCreatePath(pathname) {
    return /^\/user-management\/health-workers\/create$/.test(normalizePathname(pathname));
}

/** Numeric View/Edit only. Create is never a warmup candidate. */
export function isUserManagementHealthWorkerWarmPath(pathname) {
    const path = normalizePathname(pathname);
    if (isUserManagementHealthWorkerCreatePath(path)) {
        return false;
    }
    return isUserManagementHealthWorkerViewPath(path) || isUserManagementHealthWorkerEditPath(path);
}

export function isExcludedWriteGetPath(pathname) {
    const path = normalizePathname(pathname);
    if (isHouseholdProfilingOfflineWritePath(path) || isUserManagementOfflineWritePath(path)) {
        return false;
    }
    return WRITE_GET_PATH_PATTERN.test(path);
}

export function isAllowedAuthenticatedModulePath(pathname) {
    const path = normalizePathname(pathname);
    return SAFE_MODULE_PREFIXES.some((prefix) => path === prefix || path.startsWith(`${prefix}/`));
}

export function isSafeNavigationPath(pathname) {
    const path = normalizePathname(pathname);
    if (isNeverCachePath(path)) {
        return false;
    }
    if (isExcludedWriteGetPath(path)) {
        return false;
    }
    if (SAFE_NAVIGATION_PATTERNS.some((pattern) => pattern.test(path))) {
        return true;
    }
    return isAllowedAuthenticatedModulePath(path);
}

export function normalizeStaffRole(role) {
    const raw = String(role || '').trim().toLowerCase();
    if (raw === 'admin' || raw === 'administrator') {
        return 'admin';
    }
    if (raw === 'bhw' || raw === 'barangay health worker') {
        return 'bhw';
    }
    if (raw === 'bns' || raw === 'barangay nutrition scholar') {
        return 'bns';
    }
    if (raw === 'bspo' || raw === 'barangay service point officer') {
        return 'bspo';
    }
    return null;
}

export function isAdminRole(role) {
    return normalizeStaffRole(role) === 'admin';
}

/**
 * How a navigation path should behave while the client is offline.
 * Used by the in-app unavailable panel (shell preserved) before SW fallback.
 *
 * @returns {{ kind: 'online-only'|'offline-capable'|'unknown', module: string, support: string }}
 */
export function classifyOfflineNavigation(pathname) {
    const path = normalizePathname(pathname);

    if (
        /^\/user-management\/?$/.test(path)
        || /^\/user-management\/health-workers\/create\/?$/.test(path)
        || /^\/user-management\/residents\//.test(path)
    ) {
        return {
            kind: 'online-only',
            module: 'User Management',
            support: 'Reconnect to the internet to view and manage user accounts.',
        };
    }

    if (/^\/announcements\/(?:create|\d+\/edit)\/?$/.test(path)) {
        return {
            kind: 'online-only',
            module: 'Announcements',
            support: 'Reconnect to the internet to create or edit announcements.',
        };
    }

    if (isDeathCertificateWritePath(path)) {
        return {
            kind: 'online-only',
            module: 'Death Certificate',
            support: 'Death certificate uploads require an internet connection.',
        };
    }

    if (/\/adult-immunization(?:\/|$)/i.test(path)) {
        return {
            kind: 'online-only',
            module: 'Adult Immunization',
            support: 'Reconnect to the internet to record adult immunizations.',
        };
    }

    if (isNeverCachePath(path) || /^\/(login|logout|register|change-password|forgot-password|reset-password)(?:\/|$)/.test(path)) {
        return {
            kind: 'online-only',
            module: 'Account Security',
            support: 'Reconnect to the internet to continue with sign-in or security settings.',
        };
    }

    if (
        isSafeNavigationPath(path)
        || isRelatedWarmPath(path)
        || isHouseholdProfilingOfflineWritePath(path)
        || isUserManagementHealthWorkerWarmPath(path)
    ) {
        return {
            kind: 'offline-capable',
            module: moduleLabelForPath(path),
            support: 'Reconnect to the internet and open this page once to make it available for offline use.',
        };
    }

    return {
        kind: 'unknown',
        module: 'This page',
        support: 'Reconnect to the internet to open this area of LMLinga.',
    };
}

export function moduleLabelForPath(pathname) {
    const path = normalizePathname(pathname);
    if (path === '/dashboard' || path.startsWith('/dashboard/')) {
        return 'Dashboard';
    }
    if (path.startsWith('/spot-mapping')) {
        return 'Spot Mapping';
    }
    if (path.startsWith('/household-profiling') || path.startsWith('/households/')) {
        return 'Household Profiling';
    }
    if (path.startsWith('/environmental-health')) {
        return 'Environmental Health';
    }
    if (path.startsWith('/health-records')) {
        return 'Health Records';
    }
    if (path.startsWith('/announcements')) {
        return 'Announcements';
    }
    if (path.startsWith('/user-management')) {
        return 'User Management';
    }
    if (path.startsWith('/household-requests')) {
        return 'Household Requests';
    }
    if (path.startsWith('/death-requests')) {
        return 'Death Requests';
    }
    if (path.startsWith('/profile')) {
        return 'Profile';
    }
    return 'This page';
}

export function warmupPathsForRole(role) {
    const normalized = normalizeStaffRole(role);
    if (!normalized) {
        return [];
    }
    const paths = [...WARMUP_PATHS_SHARED];
    if (normalized === 'admin') {
        paths.push(...WARMUP_PATHS_ADMIN);
    }
    return paths.filter((path) => isSafeNavigationPath(path) && !isNeverCachePath(path));
}

export function isWarmupPathForRole(pathname, role) {
    const path = normalizePathname(pathname);
    return warmupPathsForRole(role).some((candidate) => normalizePathname(candidate) === path);
}

export function isCoreWarmupPath(pathname) {
    const path = normalizePathname(pathname);
    return WARMUP_PATHS_SHARED.some((candidate) => normalizePathname(candidate) === path);
}

export function coreWarmupPaths() {
    return warmupPathsForRole('bhw');
}

export function hasUnsafeQuery(url) {
    if (!url || !url.searchParams) {
        return false;
    }
    for (const key of url.searchParams.keys()) {
        const lower = String(key).toLowerCase();
        if (UNSAFE_QUERY_KEYS.includes(lower)) {
            return true;
        }
    }
    return false;
}

export function isNavigationRequest(request) {
    if (!request) {
        return false;
    }
    if (String(request.method || 'GET').toUpperCase() !== 'GET') {
        return false;
    }
    return request.mode === 'navigate';
}

export function isHtmlDocumentGet(request) {
    if (!request) {
        return false;
    }
    if (String(request.method || 'GET').toUpperCase() !== 'GET') {
        return false;
    }
    if (request.mode === 'navigate' || request.destination === 'document') {
        return true;
    }
    const requestedWith = String(request.headers?.get?.('x-requested-with') || '').toLowerCase();
    if (requestedWith === 'xmlhttprequest') {
        return false;
    }
    const accept = String(request.headers?.get?.('accept') || '').toLowerCase();
    return accept.includes('text/html');
}

export function navigationCacheUrl(origin, pathname, search = '') {
    return `${origin}${normalizePathname(pathname)}${allowedNavigationSearch(pathname, search)}`;
}

export function isCacheableAssetRequest(request, url, origin) {
    if (!request || !url) {
        return false;
    }
    if (String(request.method || '').toUpperCase() !== 'GET') {
        return false;
    }
    if (!isSameOrigin(url, origin)) {
        return false;
    }
    if (hasUnsafeQuery(url)) {
        return false;
    }
    if (isNeverCachePath(url.pathname)) {
        return false;
    }
    return isStaticAssetPath(url.pathname);
}

export function isCacheableNavigationRequest(request, url, origin) {
    if (!isHtmlDocumentGet(request) || !url) {
        return false;
    }
    if (!isSameOrigin(url, origin)) {
        return false;
    }
    if (hasUnsafeQuery(url)) {
        return false;
    }
    return isSafeNavigationPath(url.pathname);
}

export function shouldCacheNavigationResponse(response, finalUrl, origin) {
    if (!response || !response.ok || response.status !== 200) {
        return false;
    }
    if (response.type !== 'basic' && response.type !== 'default') {
        return false;
    }
    if (finalUrl && !isSameOrigin(finalUrl, origin)) {
        return false;
    }
    if (finalUrl) {
        let parsed;
        try {
            parsed = new URL(finalUrl, origin);
        } catch {
            return false;
        }
        if (isNeverCachePath(parsed.pathname) || !isSafeNavigationPath(parsed.pathname)) {
            return false;
        }
        if (hasUnsafeQuery(parsed)) {
            return false;
        }
        // Mutation hub: never persist sanitized UM index HTML (blank CSRF).
        if (/^\/user-management\/?$/.test(normalizePathname(parsed.pathname))) {
            return false;
        }
    }
    const contentType = String(response.headers?.get?.('content-type') || '').toLowerCase();
    if (contentType && !contentType.includes('text/html')) {
        return false;
    }
    return true;
}

export function sanitizeCachedHtml(html) {
    if (typeof html !== 'string') {
        return '';
    }
    let next = html
        .replace(/<meta\s+name=["']csrf-token["']\s+content=["'][^"']*["']\s*\/?>/gi, '<meta name="csrf-token" content="">')
        .replace(/<meta\s+content=["'][^"']*["']\s+name=["']csrf-token["']\s*\/?>/gi, '<meta name="csrf-token" content="">')
        .replace(/(<input\b[^>]*\bname=["']_token["'][^>]*\bvalue=["'])[^"']*(["'])/gi, '$1$2')
        .replace(/(<input\b[^>]*\bvalue=["'])[^"']*(["'][^>]*\bname=["']_token["'])/gi, '$1$2')
        .replace(/\sdata-hws-bound(?:=(?:"[^"]*"|'[^']*'|[^\s>]+))?/gi, '');

    if (!new RegExp(`<meta\\s+name=["']${SW_CACHE_META}["']`, 'i').test(next)) {
        if (/<\/head>/i.test(next)) {
            next = next.replace(/<\/head>/i, `<meta name="${SW_CACHE_META}" content="1">\n</head>`);
        } else {
            next = `<meta name="${SW_CACHE_META}" content="1">` + next;
        }
    }

    return next;
}

export function documentHasSwCacheMarker(root) {
    if (!root) {
        return false;
    }
    const doc = root.ownerDocument && root.ownerDocument.querySelector ? root.ownerDocument : root;
    const nodes = [];
    if (typeof doc.querySelector === 'function') {
        nodes.push(doc.querySelector(`meta[name="${SW_CACHE_META}"]`));
    }
    if (root !== doc && typeof root.querySelector === 'function') {
        nodes.push(root.querySelector(`meta[name="${SW_CACHE_META}"]`));
    }
    return nodes.some((node) => node && String(node.getAttribute?.('content') || '') === '1');
}

export function fallbackHtml() {
    return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="">
    <title>Offline - LMLinga</title>
    <style>
        :root {
            --lml-background: #DFF5E5;
            --lml-dark-green: #0B3D0B;
            --lml-deep-green: #145214;
            --lml-text-green: #1F7A1F;
            --lml-soft-green: #BBF7D0;
            --lml-radius-lg: 0.75rem;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--lml-background);
            color: var(--lml-dark-green);
            font-family: ui-sans-serif, system-ui, sans-serif;
        }
        main {
            width: min(32rem, 100%);
            padding: 1.35rem 1.4rem 1.25rem;
            background: #fff;
            border: 1px solid var(--lml-soft-green);
            border-radius: var(--lml-radius-lg);
            box-shadow: 0 4px 12px rgba(11, 61, 11, 0.12);
        }
        h1 {
            margin: 0 0 0.65rem;
            font-size: 1.2rem;
            color: var(--lml-deep-green);
        }
        p { margin: 0 0 0.7rem; line-height: 1.5; }
        p:last-child { margin-bottom: 0; }
        a { color: var(--lml-text-green); }
    </style>
</head>
<body>
    <main data-lml-offline-fallback>
        <h1>You are offline</h1>
        <p>This emergency page is shown when LMLinga cannot open a cached screen for this address.</p>
        <p>Reconnect to continue. Pages you already opened while online, or that were prepared after sign-in, may still open from this device's cache.</p>
        <p>Waiting updates stay on this device and will sync automatically when you are back online.</p>
    </main>
</body>
</html>`;
}

export function headersForCachedResponse(headers) {
    const source = headers && typeof headers.entries === 'function' ? headers : new Headers(headers || {});
    const out = new Headers();
    source.forEach((value, key) => {
        if (String(key).toLowerCase() === 'set-cookie') {
            return;
        }
        out.append(key, value);
    });
    out.set('X-Lmlinga-Offline-Cache', '1');
    return out;
}

export function actorIdFromMessage(data) {
    const id = Number(data?.actorId ?? data?.actor_id ?? 0);
    return Number.isInteger(id) && id > 0 ? id : null;
}
