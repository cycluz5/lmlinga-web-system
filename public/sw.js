(() => {
  // resources/js/offline/offline-sw-policy.js
  var CACHE_VERSION = "offline-7-v20";
  var ASSETS_CACHE = `lmlinga-assets-${CACHE_VERSION}`;
  var FALLBACK_CACHE = `lmlinga-fallback-${CACHE_VERSION}`;
  var META_CACHE = `lmlinga-meta-${CACHE_VERSION}`;
  var HTML_CACHE_PREFIX = `lmlinga-html-${CACHE_VERSION}-actor-`;
  var AVATAR_CACHE_PREFIX = `lmlinga-avatar-${CACHE_VERSION}-actor-`;
  var CURRENT_CACHE_NAMES = [ASSETS_CACHE, FALLBACK_CACHE, META_CACHE];
  var ACTOR_META_PATH = "/__lmlinga/sw-current-actor";
  var MUTATION_METHODS = ["POST", "PUT", "PATCH", "DELETE"];
  var HOUSEHOLD_KEY = "h[a-z2-7]{12,}";
  var MEMBER_KEY = "m[a-z2-7]{12,}";
  var RESIDENT_KEY = "r[a-z2-7]{12,}";
  var ASSESSMENT_ID = "(?:RA-[0-9]+|s[a-z2-7]{12,})";
  var FP_VISIT_ID = "(?:FP-[0-9]+|v[a-z2-7]{12,})";
  var PREGNANCY_ID = "(?:MC-[0-9]+|p[a-z2-7]{12,})";
  var HOUSEHOLD_NO = `(?:HH-[0-9]+|[0-9]{3}|${HOUSEHOLD_KEY})`;
  var MEMBER_NO = `(?:MB-[0-9]+|${MEMBER_KEY})`;
  var LOCAL_MEMBER_NO = "MB-L-[A-Za-z0-9]+";
  var MEMBER_ANY = `(?:${MEMBER_NO}|${LOCAL_MEMBER_NO})`;
  var RESIDENT_ID = `(?:[0-9]+|${RESIDENT_KEY})`;
  var STAFF_AVATAR_PATH_PATTERN = /^\/storage\/health-workers\/profile-photos\/[A-Za-z0-9._-]+\.(jpe?g|png)$/i;
  var WARMUP_PATHS_SHARED = Object.freeze([
    "/dashboard",
    "/spot-mapping",
    "/household-profiling",
    "/household-profiling/create",
    "/announcements",
    "/environmental-health",
    "/health-records/child-care",
    "/health-records/risk-assessment",
    "/health-records/maternal",
    "/health-records/death",
    "/health-records/family-planning",
    "/profile"
  ]);
  var WARMUP_PATHS_ADMIN = Object.freeze([
    "/household-requests",
    "/death-requests"
  ]);
  var SAFE_NAVIGATION_PATTERNS = [
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
    /^\/death-requests\/?$/
  ];
  var NEVER_CACHE_PATH_PREFIXES = [
    "/login",
    "/logout",
    "/register",
    "/forgot-password",
    "/reset-password",
    "/change-password",
    "/offline",
    // /offline/status and /offline/sync
    "/chatbot",
    "/storage",
    "/sanctum",
    "/up",
    "/announcements/create",
    "/landing"
  ];
  var NEVER_CACHE_PATH_MATCHERS = [
    /\/export(?:\/|$)/i,
    /\/certificate(?:\/|$)/i,
    /password/i,
    /\/otp(?:\/|$)/i,
    /verification/i,
    /\/announcements\/\d+\/edit(?:\/|$)/i
  ];
  var WRITE_GET_PATH_PATTERN = /\/(?:create|edit|register)(?:\/|$)/i;
  var HP_OFFLINE_WRITE_PATTERNS = [
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
    new RegExp(`^/households/${HOUSEHOLD_NO}/residents/${RESIDENT_ID}/nutritional-status/create/?$`)
  ];
  var UM_OFFLINE_WRITE_PATTERNS = [
    /^\/user-management\/health-workers\/(?:[0-9]+|w[a-z2-7]{12,})\/edit\/?$/
  ];
  var SAFE_MODULE_PREFIXES = [
    "/dashboard",
    "/spot-mapping",
    "/household-profiling",
    "/households",
    // canonical resident-based Nutritional Status destinations
    "/announcements",
    "/environmental-health",
    "/health-records",
    "/user-management",
    "/household-requests",
    "/death-requests",
    "/profile"
  ];
  var UNSAFE_QUERY_KEYS = [
    "token",
    "signature",
    "expires",
    "password",
    "otp",
    "reset",
    "hash",
    "signed",
    "csrf",
    "csrf_token",
    "_token",
    "handoff"
  ];
  var MESSAGE_SET_ACTOR = "lmlinga:set-actor";
  var MESSAGE_CLEAR_HTML = "lmlinga:clear-html-cache";
  var MESSAGE_WARMUP_CORE = "lmlinga:warmup-core";
  var MESSAGE_WARMUP_UM_WORKERS = "lmlinga:warmup-um-workers";
  var MESSAGE_WARMUP_RELATED = "lmlinga:warmup-related";
  var MESSAGE_WARMUP_PROGRESS = "lmlinga:warmup-progress";
  var MESSAGE_WARMUP_COMPLETE = "lmlinga:warmup-complete";
  var MESSAGE_WARMUP_READY_QUERY = "lmlinga:warmup-ready-query";
  var MESSAGE_WARMUP_READY_RESULT = "lmlinga:warmup-ready-result";
  var MESSAGE_ENSURE_BUILD_ASSETS = "lmlinga:ensure-build-assets";
  var MESSAGE_ENSURE_BUILD_ASSETS_RESULT = "lmlinga:ensure-build-assets-result";
  var CRITICAL_BUILD_MANIFEST_ENTRIES = Object.freeze([
    "resources/css/app.css",
    "resources/js/app.js"
  ]);
  function buildAssetPathFromManifestFile(file) {
    const path = String(file || "").replace(/^\/+/, "");
    if (!path) {
      return null;
    }
    if (path.startsWith("build/")) {
      return `/${path}`;
    }
    if (path.startsWith("assets/")) {
      return `/build/${path}`;
    }
    return null;
  }
  function criticalBuildAssetPathsFromManifest(manifest) {
    const files = /* @__PURE__ */ new Set();
    CRITICAL_BUILD_MANIFEST_ENTRIES.forEach((key) => {
      const entry = manifest?.[key];
      if (!entry || typeof entry !== "object") {
        return;
      }
      if (typeof entry.file === "string") {
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
      const file = entry && typeof entry.file === "string" ? entry.file : "";
      if (/\.(woff2?|ttf|eot)$/i.test(file) && file.startsWith("assets/")) {
        files.add(file);
      }
    });
    return [...files].map((file) => buildAssetPathFromManifestFile(file)).filter((path) => typeof path === "string" && path.startsWith("/build/"));
  }
  function isViteDevAssetUrl(rawUrl, baseUrl = "http://localhost") {
    const raw = String(rawUrl || "");
    if (!raw) {
      return false;
    }
    if (raw.includes("/@vite/") || raw.includes("/@fs/")) {
      return true;
    }
    if (raw.includes("/resources/css/") || raw.includes("/resources/js/")) {
      return true;
    }
    if (raw.includes("/node_modules/")) {
      return true;
    }
    try {
      const parsed = new URL(raw, baseUrl);
      if (parsed.pathname.startsWith("/resources/")) {
        return true;
      }
      if (/:(5173|5174)$/.test(parsed.host) || parsed.hostname === "[::1]") {
        return parsed.pathname.includes("/resources/") || parsed.pathname.includes("/@vite");
      }
    } catch {
    }
    return false;
  }
  function htmlUsesViteDevAssets(html) {
    if (typeof html !== "string" || html === "") {
      return false;
    }
    const tags = html.match(/<(?:link|script)\b[^>]*>/gi) || [];
    return tags.some((tag) => {
      const match = /\s(?:href|src)\s*=\s*(?:"([^"]*)"|'([^']*)')/i.exec(tag);
      return Boolean(match) && isViteDevAssetUrl(match[1] ?? match[2] ?? "");
    });
  }
  function warmPathStatusLabel(pathname) {
    const module = moduleLabelForPath(pathname);
    if (module === "This page") {
      return "Preparing authorized pages\u2026";
    }
    return `Preparing ${module}\u2026`;
  }
  var SW_CACHE_META = "lmlinga-offline-cache";
  var SHELL_STATIC_PATHS = [
    "/assets/images/logo/logo.png",
    "/assets/images/logo/LMLogo.png",
    "/favicon.ico"
  ];
  function htmlCacheNameForActor(actorId) {
    const id = Number(actorId);
    if (!Number.isInteger(id) || id <= 0) {
      return null;
    }
    return `${HTML_CACHE_PREFIX}${id}`;
  }
  function avatarCacheNameForActor(actorId) {
    const id = Number(actorId);
    if (!Number.isInteger(id) || id <= 0) {
      return null;
    }
    return `${AVATAR_CACHE_PREFIX}${id}`;
  }
  function isLmlingaOwnedCache(name) {
    if (typeof name !== "string") {
      return false;
    }
    return name.startsWith("lmlinga-assets-") || name.startsWith("lmlinga-html-") || name.startsWith("lmlinga-avatar-") || name.startsWith("lmlinga-fallback-") || name.startsWith("lmlinga-meta-");
  }
  function isCurrentOwnedCache(name) {
    if (CURRENT_CACHE_NAMES.includes(name)) {
      return true;
    }
    return typeof name === "string" && (name.startsWith(HTML_CACHE_PREFIX) || name.startsWith(AVATAR_CACHE_PREFIX));
  }
  function obsoleteOwnedCaches(existingNames) {
    if (!Array.isArray(existingNames)) {
      return [];
    }
    return existingNames.filter((name) => isLmlingaOwnedCache(name) && !isCurrentOwnedCache(name));
  }
  function normalizePathname(pathname) {
    if (typeof pathname !== "string" || pathname === "") {
      return "/";
    }
    const trimmed = pathname.replace(/\/+$/, "");
    return trimmed === "" ? "/" : trimmed;
  }
  function isSameOrigin(url, origin) {
    try {
      const parsed = typeof url === "string" ? new URL(url, origin) : url;
      return parsed.origin === origin;
    } catch {
      return false;
    }
  }
  function isMutationMethod(method) {
    return MUTATION_METHODS.includes(String(method || "").toUpperCase());
  }
  function isLogoutRequest(request, url) {
    const method = String(request?.method || "").toUpperCase();
    const pathname = normalizePathname(url?.pathname || "");
    return method === "POST" && pathname === "/logout";
  }
  function isStaticAssetPath(pathname) {
    const path = normalizePathname(pathname);
    if (path === "/build/manifest.json" || path === "/favicon.ico") {
      return true;
    }
    if (path.startsWith("/build/assets/")) {
      return true;
    }
    if (SHELL_STATIC_PATHS.includes(path)) {
      return true;
    }
    return /^\/assets\/images\/logo\/[^/]+\.(png|jpe?g|svg|webp|ico)$/i.test(path);
  }
  function isDevelopmentOnlyPath(pathname) {
    const path = typeof pathname === "string" ? pathname : "";
    return path === "/hot" || path.startsWith("/@vite") || path.startsWith("/@fs") || path.startsWith("/resources/") || path.startsWith("/node_modules/");
  }
  function isNeverCachePath(pathname) {
    const path = normalizePathname(pathname);
    if (isDevelopmentOnlyPath(path)) {
      return true;
    }
    if (NEVER_CACHE_PATH_PREFIXES.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) {
      return true;
    }
    return NEVER_CACHE_PATH_MATCHERS.some((pattern) => pattern.test(path));
  }
  function isManagedStaffAvatarPath(pathname) {
    return STAFF_AVATAR_PATH_PATTERN.test(normalizePathname(pathname));
  }
  function avatarPathFromUrl(raw, origin) {
    if (raw == null || raw === "") {
      return null;
    }
    try {
      const parsed = new URL(String(raw), origin || "http://localhost");
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
  function shouldCacheActorAvatarResponse(response, finalUrl, origin) {
    if (!response || !response.ok || response.status !== 200) {
      return false;
    }
    if (response.type !== "basic" && response.type !== "default") {
      return false;
    }
    const path = avatarPathFromUrl(finalUrl || "", origin);
    if (!path) {
      return false;
    }
    const contentType = String(response.headers?.get?.("content-type") || "").toLowerCase();
    if (contentType && !contentType.startsWith("image/")) {
      return false;
    }
    return true;
  }
  function isHouseholdProfilingOfflineWritePath(pathname) {
    const path = normalizePathname(pathname);
    return HP_OFFLINE_WRITE_PATTERNS.some((pattern) => pattern.test(path));
  }
  function isLocalMemberViewPath(pathname) {
    return new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${LOCAL_MEMBER_NO}/?$`).test(
      normalizePathname(pathname)
    );
  }
  function isLocalMemberEditPath(pathname) {
    return new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${LOCAL_MEMBER_NO}/edit/?$`).test(
      normalizePathname(pathname)
    );
  }
  function isLocalMemberHealthPath(pathname) {
    const path = normalizePathname(pathname);
    if (isDeathCertificateWritePath(path)) {
      return false;
    }
    return new RegExp(
      `^/household-profiling/${HOUSEHOLD_NO}/members/${LOCAL_MEMBER_NO}/(?!edit(?:/|$)).+`
    ).test(path);
  }
  function isDeathCertificateWritePath(pathname) {
    const path = normalizePathname(pathname);
    return new RegExp(
      `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/death/(?:create|edit)/?$`
    ).test(path);
  }
  var RELATED_WARM_PATTERNS = [
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/amenities/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/amenities/edit/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/create/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/edit/?$`),
    new RegExp(
      `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/child-immunization(?:/birth-history/edit)?/?$`
    ),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/school-based-immunization/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/child-nutrition/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/nutritional-status(?:/create)?/?$`),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/deworming(?:/create)?/?$`),
    new RegExp(
      `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/risk-assessment(?:/create)?/?$`
    ),
    new RegExp(
      `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/risk-assessment/${ASSESSMENT_ID}(?:/(?:red-flags|past-medical|family-history|lifestyle|physical)(?:/edit)?)?/?$`
    ),
    new RegExp(
      `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/family-planning(?:/create)?/?$`
    ),
    new RegExp(
      `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/family-planning/${FP_VISIT_ID}(?:/edit)?/?$`
    ),
    new RegExp(
      `^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/maternal-care(?:/register|/history(?:/${PREGNANCY_ID})?|/trans-out|/prenatal|/immunizations|/supplementations|/laboratory|/delivery|/postnatal)?/?$`
    ),
    new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_NO}/death/?$`),
    // Canonical resident-based Nutritional Status destination (applies to all residents, not only children).
    new RegExp(`^/households/${HOUSEHOLD_NO}/residents/${RESIDENT_ID}/nutritional-status(?:/create)?/?$`),
    /^\/environmental-health\/household-water-supply\/?$/,
    new RegExp(`^/environmental-health/household-water-supply/${HOUSEHOLD_NO}/step-[234]/?$`)
  ];
  function isRelatedWarmPath(pathname) {
    const path = normalizePathname(pathname);
    if (isNeverCachePath(path) || isDeathCertificateWritePath(path)) {
      return false;
    }
    if (!isSafeNavigationPath(path)) {
      return false;
    }
    return RELATED_WARM_PATTERNS.some((pattern) => pattern.test(path));
  }
  function allowedNavigationSearch(pathname, search) {
    const path = normalizePathname(pathname);
    if (path !== "/environmental-health/household-water-supply") {
      return "";
    }
    const raw = String(search || "");
    const query = raw.startsWith("?") ? raw.slice(1) : raw;
    if (!query) {
      return "";
    }
    try {
      const params = new URLSearchParams(query);
      const keys = [...params.keys()];
      if (keys.length !== 1 || keys[0] !== "household") {
        return "";
      }
      const household = String(params.get("household") || "").trim();
      if (!new RegExp(`^${HOUSEHOLD_NO}$`, "i").test(household)) {
        return "";
      }
      return `?household=${encodeURIComponent(household)}`;
    } catch {
      return "";
    }
  }
  function isUserManagementOfflineWritePath(pathname) {
    const path = normalizePathname(pathname);
    return UM_OFFLINE_WRITE_PATTERNS.some((pattern) => pattern.test(path));
  }
  function isUserManagementHealthWorkerViewPath(pathname) {
    return /^\/user-management\/health-workers\/(?:[0-9]+|w[a-z2-7]{12,})\/view$/.test(normalizePathname(pathname));
  }
  function isUserManagementHealthWorkerEditPath(pathname) {
    return isUserManagementOfflineWritePath(pathname);
  }
  function isUserManagementHealthWorkerCreatePath(pathname) {
    return /^\/user-management\/health-workers\/create$/.test(normalizePathname(pathname));
  }
  function isUserManagementHealthWorkerWarmPath(pathname) {
    const path = normalizePathname(pathname);
    if (isUserManagementHealthWorkerCreatePath(path)) {
      return false;
    }
    return isUserManagementHealthWorkerViewPath(path) || isUserManagementHealthWorkerEditPath(path);
  }
  function isExcludedWriteGetPath(pathname) {
    const path = normalizePathname(pathname);
    if (isHouseholdProfilingOfflineWritePath(path) || isUserManagementOfflineWritePath(path)) {
      return false;
    }
    return WRITE_GET_PATH_PATTERN.test(path);
  }
  function isAllowedAuthenticatedModulePath(pathname) {
    const path = normalizePathname(pathname);
    return SAFE_MODULE_PREFIXES.some((prefix) => path === prefix || path.startsWith(`${prefix}/`));
  }
  function isSafeNavigationPath(pathname) {
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
  function normalizeStaffRole(role) {
    const raw = String(role || "").trim().toLowerCase();
    if (raw === "admin" || raw === "administrator") {
      return "admin";
    }
    if (raw === "bhw" || raw === "barangay health worker") {
      return "bhw";
    }
    if (raw === "bns" || raw === "barangay nutrition scholar") {
      return "bns";
    }
    if (raw === "bspo" || raw === "barangay service point officer") {
      return "bspo";
    }
    return null;
  }
  function moduleLabelForPath(pathname) {
    const path = normalizePathname(pathname);
    if (path === "/dashboard" || path.startsWith("/dashboard/")) {
      return "Dashboard";
    }
    if (path.startsWith("/spot-mapping")) {
      return "Spot Mapping";
    }
    if (path.startsWith("/household-profiling") || path.startsWith("/households/")) {
      return "Household Profiling";
    }
    if (path.startsWith("/environmental-health")) {
      return "Environmental Health";
    }
    if (path.startsWith("/health-records")) {
      return "Health Records";
    }
    if (path.startsWith("/announcements")) {
      return "Announcements";
    }
    if (path.startsWith("/user-management")) {
      return "User Management";
    }
    if (path.startsWith("/household-requests")) {
      return "Household Requests";
    }
    if (path.startsWith("/death-requests")) {
      return "Death Requests";
    }
    if (path.startsWith("/profile")) {
      return "Profile";
    }
    return "This page";
  }
  function warmupPathsForRole(role) {
    const normalized = normalizeStaffRole(role);
    if (!normalized) {
      return [];
    }
    const paths = [...WARMUP_PATHS_SHARED];
    if (normalized === "admin") {
      paths.push(...WARMUP_PATHS_ADMIN);
    }
    return paths.filter((path) => isSafeNavigationPath(path) && !isNeverCachePath(path));
  }
  function hasUnsafeQuery(url) {
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
  function isHtmlDocumentGet(request) {
    if (!request) {
      return false;
    }
    if (String(request.method || "GET").toUpperCase() !== "GET") {
      return false;
    }
    if (request.mode === "navigate" || request.destination === "document") {
      return true;
    }
    const requestedWith = String(request.headers?.get?.("x-requested-with") || "").toLowerCase();
    if (requestedWith === "xmlhttprequest") {
      return false;
    }
    const accept = String(request.headers?.get?.("accept") || "").toLowerCase();
    return accept.includes("text/html");
  }
  function navigationCacheUrl(origin, pathname, search = "") {
    return `${origin}${normalizePathname(pathname)}${allowedNavigationSearch(pathname, search)}`;
  }
  function isCacheableAssetRequest(request, url, origin) {
    if (!request || !url) {
      return false;
    }
    if (String(request.method || "").toUpperCase() !== "GET") {
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
  function isCacheableNavigationRequest(request, url, origin) {
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
  function shouldCacheNavigationResponse(response, finalUrl, origin) {
    if (!response || !response.ok || response.status !== 200) {
      return false;
    }
    if (response.type !== "basic" && response.type !== "default") {
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
      if (/^\/user-management\/?$/.test(normalizePathname(parsed.pathname))) {
        return false;
      }
    }
    const contentType = String(response.headers?.get?.("content-type") || "").toLowerCase();
    if (contentType && !contentType.includes("text/html")) {
      return false;
    }
    return true;
  }
  function sanitizeCachedHtml(html) {
    if (typeof html !== "string") {
      return "";
    }
    let next = html.replace(/<meta\s+name=["']csrf-token["']\s+content=["'][^"']*["']\s*\/?>/gi, '<meta name="csrf-token" content="">').replace(/<meta\s+content=["'][^"']*["']\s+name=["']csrf-token["']\s*\/?>/gi, '<meta name="csrf-token" content="">').replace(/(<input\b[^>]*\bname=["']_token["'][^>]*\bvalue=["'])[^"']*(["'])/gi, "$1$2").replace(/(<input\b[^>]*\bvalue=["'])[^"']*(["'][^>]*\bname=["']_token["'])/gi, "$1$2").replace(/\sdata-hws-bound(?:=(?:"[^"]*"|'[^']*'|[^\s>]+))?/gi, "");
    if (!new RegExp(`<meta\\s+name=["']${SW_CACHE_META}["']`, "i").test(next)) {
      if (/<\/head>/i.test(next)) {
        next = next.replace(/<\/head>/i, `<meta name="${SW_CACHE_META}" content="1">
</head>`);
      } else {
        next = `<meta name="${SW_CACHE_META}" content="1">` + next;
      }
    }
    return next;
  }
  function fallbackHtml() {
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
  function headersForCachedResponse(headers) {
    const source = headers && typeof headers.entries === "function" ? headers : new Headers(headers || {});
    const out = new Headers();
    source.forEach((value, key) => {
      if (String(key).toLowerCase() === "set-cookie") {
        return;
      }
      out.append(key, value);
    });
    out.set("X-Lmlinga-Offline-Cache", "1");
    return out;
  }

  // resources/js/offline/offline-sw-runtime.js
  var FALLBACK_URL_PATH = "/__lmlinga/offline-unavailable";
  function createServiceWorkerRuntime(env = {}) {
    const origin = env.origin || "http://localhost";
    const cachesApi = env.caches;
    const fetchImpl = env.fetch;
    const skipWaiting = env.skipWaiting;
    const clientsClaim = env.clientsClaim;
    const clientsApi = env.clients || null;
    let warmupInFlight = null;
    let umWarmupInFlight = null;
    let relatedWarmupInFlight = null;
    function requestUrl(request) {
      try {
        return new URL(request.url, origin);
      } catch {
        return null;
      }
    }
    async function readMeta() {
      try {
        const cache = await cachesApi.open(META_CACHE);
        const hit = await cache.match(new Request(`${origin}${ACTOR_META_PATH}`));
        if (!hit) {
          return { actorId: null, avatarPath: null };
        }
        const payload = await hit.json();
        const id = Number(payload?.actorId);
        return {
          actorId: Number.isInteger(id) && id > 0 ? id : null,
          avatarPath: avatarPathFromUrl(payload?.avatarPath || "", origin)
        };
      } catch {
        return { actorId: null, avatarPath: null };
      }
    }
    async function putMeta(actorId, avatarPath = null) {
      const cache = await cachesApi.open(META_CACHE);
      const body = JSON.stringify({
        actorId: actorId || null,
        avatarPath: avatarPathFromUrl(avatarPath || "", origin)
      });
      await cache.put(
        new Request(`${origin}${ACTOR_META_PATH}`),
        new Response(body, {
          headers: { "Content-Type": "application/json", "X-Lmlinga-Offline-Cache": "1" }
        })
      );
    }
    async function getActorId() {
      const meta = await readMeta();
      return meta.actorId;
    }
    async function deletePrivateCaches(exceptNames = []) {
      const keep = new Set(exceptNames.filter(Boolean));
      const names = await cachesApi.keys();
      await Promise.all(
        names.filter((name) => typeof name === "string" && (name.startsWith("lmlinga-html-") || name.startsWith("lmlinga-avatar-"))).filter((name) => !keep.has(name)).map((name) => cachesApi.delete(name))
      );
    }
    async function setActor(actorId) {
      const nextId = Number.isInteger(actorId) && actorId > 0 ? actorId : null;
      const current = await getActorId();
      if (current && nextId && current !== nextId) {
        await deletePrivateCaches([
          htmlCacheNameForActor(nextId),
          avatarCacheNameForActor(nextId)
        ]);
      }
      if (!nextId) {
        await deletePrivateCaches();
      }
      await putMeta(nextId, nextId && current === nextId ? (await readMeta()).avatarPath : null);
    }
    async function clearPrivateHtml() {
      await deletePrivateCaches();
      await putMeta(null, null);
    }
    function fallbackResponse() {
      return new Response(fallbackHtml(), {
        status: 200,
        headers: {
          "Content-Type": "text/html; charset=utf-8",
          "Cache-Control": "no-store",
          "X-Lmlinga-Offline-Fallback": "1"
        }
      });
    }
    function assetUnavailableResponse() {
      return new Response("", {
        status: 504,
        statusText: "Asset Unavailable Offline",
        headers: {
          "Cache-Control": "no-store",
          "X-Lmlinga-Offline-Asset": "miss"
        }
      });
    }
    async function precacheFallback() {
      const cache = await cachesApi.open(FALLBACK_CACHE);
      await cache.put(
        new Request(`${origin}${FALLBACK_URL_PATH}`),
        fallbackResponse()
      );
    }
    async function precacheBuiltAssets() {
      if (typeof fetchImpl !== "function") {
        return;
      }
      let manifest;
      try {
        const response = await fetchImpl(`${origin}/build/manifest.json`, {
          credentials: "same-origin",
          headers: { Accept: "application/json" }
        });
        if (!response || !response.ok) {
          return;
        }
        manifest = await response.json();
      } catch {
        return;
      }
      const cache = await cachesApi.open(ASSETS_CACHE);
      try {
        await cache.put(
          new Request(`${origin}/build/manifest.json`),
          new Response(JSON.stringify(manifest), {
            headers: { "Content-Type": "application/json", "X-Lmlinga-Offline-Cache": "1" }
          })
        );
      } catch {
      }
      const files = /* @__PURE__ */ new Set();
      Object.values(manifest || {}).forEach((entry) => {
        if (entry && typeof entry.file === "string") {
          files.add(entry.file);
        }
        if (entry && Array.isArray(entry.css)) {
          entry.css.forEach((file) => files.add(file));
        }
        if (entry && Array.isArray(entry.assets)) {
          entry.assets.forEach((file) => files.add(file));
        }
      });
      await Promise.all(
        [...files].map(async (file) => {
          const path = String(file || "").replace(/^\/+/, "");
          if (!path.startsWith("assets/") && !path.startsWith("build/assets/")) {
            return;
          }
          const assetPath = path.startsWith("build/") ? `/${path}` : `/build/${path}`;
          try {
            const response = await fetchImpl(`${origin}${assetPath}`, { credentials: "same-origin" });
            if (response && response.ok && (response.type === "basic" || response.type === "default")) {
              await cache.put(new Request(`${origin}${assetPath}`), response.clone());
            }
          } catch {
          }
        })
      );
    }
    async function precacheShellAssets() {
      if (typeof fetchImpl !== "function") {
        return;
      }
      const cache = await cachesApi.open(ASSETS_CACHE);
      await Promise.all(
        SHELL_STATIC_PATHS.map(async (assetPath) => {
          try {
            const response = await fetchImpl(`${origin}${assetPath}`, { credentials: "same-origin" });
            if (response && response.ok && (response.type === "basic" || response.type === "default")) {
              await cache.put(new Request(`${origin}${assetPath}`), response.clone());
            }
          } catch {
          }
        })
      );
    }
    async function readBuildManifest() {
      if (typeof fetchImpl === "function") {
        try {
          const response = await fetchImpl(`${origin}/build/manifest.json`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" }
          });
          if (response && response.ok) {
            return await response.json();
          }
        } catch {
        }
      }
      try {
        const cache = await cachesApi.open(ASSETS_CACHE);
        const hit = await cache.match(new Request(`${origin}/build/manifest.json`));
        if (hit) {
          return await hit.json();
        }
      } catch {
      }
      return null;
    }
    async function verifyBuiltAssets(manifest) {
      const checked = criticalBuildAssetPathsFromManifest(manifest);
      if (!checked.length) {
        return {
          ok: false,
          reason: "empty-manifest",
          checked: [],
          missing: [],
          version: CACHE_VERSION
        };
      }
      const cache = await cachesApi.open(ASSETS_CACHE);
      const missing = [];
      for (const assetPath of checked) {
        const hit = await cache.match(new Request(`${origin}${assetPath}`));
        if (!hit) {
          missing.push(assetPath);
        }
      }
      return {
        ok: missing.length === 0,
        reason: missing.length === 0 ? "ok" : "missing-assets",
        checked,
        missing,
        version: CACHE_VERSION
      };
    }
    async function ensureBuiltAssets() {
      await precacheBuiltAssets();
      const manifest = await readBuildManifest();
      if (!manifest || typeof manifest !== "object") {
        return {
          ok: false,
          reason: "no-manifest",
          checked: [],
          missing: [],
          version: CACHE_VERSION
        };
      }
      return verifyBuiltAssets(manifest);
    }
    async function install() {
      await precacheFallback();
      await precacheBuiltAssets();
      await precacheShellAssets();
      if (typeof skipWaiting === "function") {
        skipWaiting();
      }
    }
    async function activate() {
      const names = await cachesApi.keys();
      await Promise.all(obsoleteOwnedCaches(names).map((name) => cachesApi.delete(name)));
      if (typeof clientsClaim === "function") {
        clientsClaim();
      }
    }
    async function cacheFirstAsset(request, url) {
      const cache = await cachesApi.open(ASSETS_CACHE);
      const hit = await cache.match(request);
      if (hit) {
        return hit;
      }
      const keyed = await cache.match(new Request(`${origin}${url.pathname}`));
      if (keyed) {
        return keyed;
      }
      const response = await fetchImpl(request);
      if (response && response.ok && (response.type === "basic" || response.type === "default")) {
        await cache.put(new Request(`${origin}${url.pathname}`), response.clone());
      }
      return response;
    }
    async function sanitizeHtmlResponse(response) {
      const html = sanitizeCachedHtml(await response.text());
      return new Response(html, {
        status: 200,
        statusText: "OK",
        headers: headersForCachedResponse(response.headers)
      });
    }
    async function responseUsesViteDevAssets(response) {
      try {
        return htmlUsesViteDevAssets(await response.clone().text());
      } catch {
        return false;
      }
    }
    async function storeNavigation(url, response) {
      const actorId = await getActorId();
      const cacheName = htmlCacheNameForActor(actorId);
      if (!cacheName) {
        return;
      }
      const cache = await cachesApi.open(cacheName);
      const sanitized = await sanitizeHtmlResponse(response.clone());
      await cache.put(new Request(navigationCacheUrl(origin, url.pathname, url.search)), sanitized);
    }
    async function matchNavigation(url) {
      const actorId = await getActorId();
      const cacheName = htmlCacheNameForActor(actorId);
      if (!cacheName) {
        return null;
      }
      const cache = await cachesApi.open(cacheName);
      return cache.match(new Request(navigationCacheUrl(origin, url.pathname, url.search)));
    }
    async function storeActorAvatar(actorId, avatarPath, response) {
      const cacheName = avatarCacheNameForActor(actorId);
      if (!cacheName || !avatarPath) {
        return;
      }
      const cache = await cachesApi.open(cacheName);
      const headers = new Headers();
      if (response.headers && typeof response.headers.forEach === "function") {
        response.headers.forEach((value, key) => {
          if (String(key).toLowerCase() !== "set-cookie") {
            headers.append(key, value);
          }
        });
      }
      headers.set("X-Lmlinga-Offline-Cache", "1");
      const body = await response.clone().arrayBuffer();
      await cache.put(
        new Request(`${origin}${avatarPath}`),
        new Response(body, {
          status: 200,
          statusText: "OK",
          headers
        })
      );
    }
    async function matchActorAvatar(url) {
      const meta = await readMeta();
      if (!meta.actorId) {
        return null;
      }
      const path = normalizePathname(url.pathname);
      if (!isManagedStaffAvatarPath(path)) {
        return null;
      }
      const cacheName = avatarCacheNameForActor(meta.actorId);
      if (!cacheName) {
        return null;
      }
      const cache = await cachesApi.open(cacheName);
      return cache.match(new Request(`${origin}${path}`));
    }
    async function handleActorAvatar(request, url) {
      const meta = await readMeta();
      try {
        const response = await fetchImpl(request);
        const finalUrl = response?.url || url.href;
        if (meta.actorId && shouldCacheActorAvatarResponse(response, finalUrl, origin)) {
          const storedPath = avatarPathFromUrl(finalUrl, origin);
          if (storedPath) {
            try {
              await storeActorAvatar(meta.actorId, storedPath, response);
            } catch {
            }
          }
        }
        return response;
      } catch {
        const cached = await matchActorAvatar(url);
        if (cached) {
          return cached;
        }
        return new Response("", { status: 404, headers: { "Cache-Control": "no-store" } });
      }
    }
    async function warmActorAvatar(actorId, avatarUrl) {
      const avatarPath = avatarPathFromUrl(avatarUrl, origin);
      if (!actorId || !avatarPath) {
        return false;
      }
      const current = await readMeta();
      if (current.actorId !== actorId) {
        return false;
      }
      await putMeta(actorId, avatarPath);
      const cacheName = avatarCacheNameForActor(actorId);
      if (cacheName) {
        const cache = await cachesApi.open(cacheName);
        const existing = await cache.match(new Request(`${origin}${avatarPath}`));
        if (existing) {
          return true;
        }
      }
      if (typeof fetchImpl !== "function") {
        return false;
      }
      try {
        const request = new Request(`${origin}${avatarPath}`, {
          method: "GET",
          credentials: "same-origin"
        });
        const response = await fetchImpl(request);
        const finalUrl = response?.url || request.url;
        if (!shouldCacheActorAvatarResponse(response, finalUrl, origin)) {
          return false;
        }
        if (avatarPathFromUrl(finalUrl, origin) !== avatarPath) {
          return false;
        }
        await storeActorAvatar(actorId, avatarPath, response);
        return true;
      } catch {
        return false;
      }
    }
    async function notifyClients(message, ports = []) {
      const list = Array.isArray(ports) ? ports.filter((port) => port && typeof port.postMessage === "function") : [];
      list.forEach((port) => {
        try {
          port.postMessage(message);
        } catch {
        }
      });
      if (typeof clientsApi?.matchAll === "function") {
        try {
          const windows = await clientsApi.matchAll({ type: "window", includeUncontrolled: true });
          windows.forEach((client) => {
            try {
              client.postMessage(message);
            } catch {
            }
          });
        } catch {
        }
      }
    }
    async function coreWarmupReadyState(actorId, role) {
      const paths = warmupPathsForRole(role);
      if (!actorId || !paths.length) {
        return {
          ready: false,
          actorId,
          role: role || null,
          version: CACHE_VERSION,
          total: paths.length,
          present: 0,
          missing: paths.slice()
        };
      }
      const cacheName = htmlCacheNameForActor(actorId);
      if (!cacheName || typeof cachesApi?.open !== "function") {
        return {
          ready: false,
          actorId,
          role,
          version: CACHE_VERSION,
          total: paths.length,
          present: 0,
          missing: paths.slice()
        };
      }
      const cache = await cachesApi.open(cacheName);
      const missing = [];
      let present = 0;
      for (const path of paths) {
        const requestUrl2 = navigationCacheUrl(origin, path);
        const hit = await cache.match(new Request(requestUrl2));
        if (hit && !await responseUsesViteDevAssets(hit)) {
          present += 1;
        } else {
          missing.push(path);
        }
      }
      return {
        ready: missing.length === 0 && present === paths.length,
        actorId,
        role,
        version: CACHE_VERSION,
        total: paths.length,
        present,
        missing
      };
    }
    async function warmCoreNavigation(requestedActorId = null, options = {}) {
      const actorId = await getActorId();
      const ports = Array.isArray(options.ports) ? options.ports : [];
      if (!actorId) {
        const result = { ok: false, reason: "no-actor", warmed: [], failed: [], total: 0, completed: 0 };
        await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result, version: CACHE_VERSION }, ports);
        return result;
      }
      if (requestedActorId && requestedActorId !== actorId) {
        const result = { ok: false, reason: "actor-mismatch", warmed: [], failed: [], total: 0, completed: 0 };
        await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result, version: CACHE_VERSION, actorId }, ports);
        return result;
      }
      if (typeof fetchImpl !== "function") {
        const result = { ok: false, reason: "no-fetch", warmed: [], failed: [], total: 0, completed: 0 };
        await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result, version: CACHE_VERSION, actorId }, ports);
        return result;
      }
      const paths = warmupPathsForRole(options.role);
      if (!paths.length) {
        const result = { ok: false, reason: "no-role", warmed: [], failed: [], total: 0, completed: 0 };
        await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result, version: CACHE_VERSION, actorId }, ports);
        return result;
      }
      if (warmupInFlight) {
        return warmupInFlight;
      }
      const total = paths.length;
      warmupInFlight = (async () => {
        const warmed = [];
        const failed = [];
        const cacheName = htmlCacheNameForActor(actorId);
        const cache = cacheName ? await cachesApi.open(cacheName) : null;
        await notifyClients({
          type: MESSAGE_WARMUP_PROGRESS,
          actorId,
          version: CACHE_VERSION,
          completed: 0,
          total,
          percentage: 0,
          label: "Starting offline preparation\u2026",
          url: null
        }, ports);
        for (const path of paths) {
          const requestUrl2 = navigationCacheUrl(origin, path);
          const request = new Request(requestUrl2, {
            method: "GET",
            credentials: "same-origin",
            headers: { Accept: "text/html" }
          });
          let okPath = false;
          let staleDevHtml = false;
          const diag = {
            pathname: path,
            success: false,
            httpStatus: null,
            redirected: false,
            cacheable: null,
            failReason: null,
            fromCache: false,
            staleDevAssets: false
          };
          if (cache) {
            const existing = await cache.match(request);
            if (existing && await responseUsesViteDevAssets(existing)) {
              staleDevHtml = true;
              diag.staleDevAssets = true;
            } else if (existing) {
              warmed.push(path);
              okPath = true;
              diag.success = true;
              diag.fromCache = true;
              diag.httpStatus = existing.status || 200;
              diag.cacheable = true;
              diag.failReason = null;
            }
          }
          if (!okPath) {
            try {
              const response = await fetchImpl(request);
              const parsed = new URL(requestUrl2, origin);
              const finalUrl = response?.url || requestUrl2;
              let finalPathname = path;
              try {
                finalPathname = new URL(finalUrl, origin).pathname || path;
              } catch {
                finalPathname = path;
              }
              diag.httpStatus = typeof response?.status === "number" ? response.status : null;
              diag.redirected = Boolean(response?.redirected) || normalizePathname(finalPathname) !== normalizePathname(path);
              const responseCacheable = shouldCacheNavigationResponse(response, finalUrl, origin);
              const requestCacheable = isCacheableNavigationRequest(request, parsed, origin);
              diag.cacheable = responseCacheable && requestCacheable;
              if (!responseCacheable) {
                failed.push(path);
                diag.failReason = "response-not-cacheable";
              } else if (!requestCacheable) {
                failed.push(path);
                diag.failReason = "request-not-cacheable";
              } else if (staleDevHtml && await responseUsesViteDevAssets(response)) {
                failed.push(path);
                diag.cacheable = false;
                diag.failReason = "stale-dev-assets";
              } else {
                await storeNavigation(parsed, response);
                warmed.push(path);
                okPath = true;
                diag.success = true;
                diag.failReason = null;
              }
            } catch {
              failed.push(path);
              diag.failReason = "fetch-error";
              diag.cacheable = false;
            }
          }
          console.log("[PREP PERF] core-path", diag);
          const completed = warmed.length;
          const percentage = total > 0 ? Math.round(warmed.length / total * 100) : 0;
          await notifyClients({
            type: MESSAGE_WARMUP_PROGRESS,
            actorId,
            version: CACHE_VERSION,
            completed,
            failed: failed.length,
            total,
            percentage,
            label: warmPathStatusLabel(path),
            url: path,
            ok: okPath,
            diag
          }, ports);
        }
        if (options.avatarUrl) {
          await warmActorAvatar(actorId, options.avatarUrl);
        }
        const allOk = failed.length === 0 && warmed.length === total;
        const result = {
          ok: allOk,
          reason: allOk ? "warmed" : "partial",
          warmed,
          failed,
          total,
          completed: warmed.length,
          percentage: allOk ? 100 : Math.round(warmed.length / total * 100),
          actorId,
          role: options.role || null,
          version: CACHE_VERSION
        };
        await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result }, ports);
        return result;
      })();
      try {
        return await warmupInFlight;
      } finally {
        warmupInFlight = null;
      }
    }
    function uniqueNonEmpty(values) {
      const seen = /* @__PURE__ */ new Set();
      const out = [];
      (Array.isArray(values) ? values : []).forEach((value) => {
        const next = String(value || "").trim();
        if (!next || seen.has(next)) {
          return;
        }
        seen.add(next);
        out.push(next);
      });
      return out;
    }
    async function warmUserManagementListedWorkers(requestedActorId = null, options = {}) {
      const actorId = await getActorId();
      if (!actorId) {
        return { ok: false, reason: "no-actor", warmed: [], avatars: [] };
      }
      if (requestedActorId && requestedActorId !== actorId) {
        return { ok: false, reason: "actor-mismatch", warmed: [], avatars: [] };
      }
      if (typeof fetchImpl !== "function") {
        return { ok: false, reason: "no-fetch", warmed: [], avatars: [] };
      }
      const paths = uniqueNonEmpty(options.paths).filter((path) => isUserManagementHealthWorkerWarmPath(path));
      const avatars = uniqueNonEmpty(options.avatars).map((raw) => avatarPathFromUrl(raw, origin)).filter(Boolean);
      if (!paths.length && !avatars.length) {
        return { ok: true, reason: "empty", warmed: [], avatars: [] };
      }
      if (umWarmupInFlight) {
        return umWarmupInFlight;
      }
      umWarmupInFlight = (async () => {
        const warmed = [];
        const warmedAvatars = [];
        for (const path of paths) {
          const requestUrl2 = navigationCacheUrl(origin, path);
          const request = new Request(requestUrl2, {
            method: "GET",
            credentials: "same-origin",
            headers: { Accept: "text/html" }
          });
          try {
            const response = await fetchImpl(request);
            const parsed = new URL(requestUrl2, origin);
            const finalUrl = response?.url || requestUrl2;
            if (!shouldCacheNavigationResponse(response, finalUrl, origin)) {
              continue;
            }
            if (!isCacheableNavigationRequest(request, parsed, origin)) {
              continue;
            }
            if (!isUserManagementHealthWorkerWarmPath(parsed.pathname)) {
              continue;
            }
            await storeNavigation(parsed, response);
            warmed.push(path);
          } catch {
          }
        }
        for (const avatarPath of avatars) {
          const request = new Request(`${origin}${avatarPath}`, {
            method: "GET",
            credentials: "same-origin"
          });
          try {
            const response = await fetchImpl(request);
            const finalUrl = response?.url || request.url;
            if (!shouldCacheActorAvatarResponse(response, finalUrl, origin)) {
              continue;
            }
            const storedPath = avatarPathFromUrl(finalUrl, origin);
            if (!storedPath) {
              continue;
            }
            await storeActorAvatar(actorId, storedPath, response);
            warmedAvatars.push(storedPath);
          } catch {
          }
        }
        return { ok: true, reason: "warmed", warmed, avatars: warmedAvatars };
      })();
      try {
        return await umWarmupInFlight;
      } finally {
        umWarmupInFlight = null;
      }
    }
    async function warmRelatedPages(requestedActorId = null, options = {}) {
      const actorId = await getActorId();
      if (!actorId) {
        return { ok: false, reason: "no-actor", warmed: [] };
      }
      if (requestedActorId && requestedActorId !== actorId) {
        return { ok: false, reason: "actor-mismatch", warmed: [] };
      }
      if (typeof fetchImpl !== "function") {
        return { ok: false, reason: "no-fetch", warmed: [] };
      }
      const paths = uniqueNonEmpty(options.paths).filter((entry) => {
        try {
          const parsed = new URL(String(entry), origin);
          return isSameOrigin(parsed, origin) && !hasUnsafeQuery(parsed) && isRelatedWarmPath(parsed.pathname);
        } catch {
          return isRelatedWarmPath(entry);
        }
      });
      if (!paths.length) {
        return { ok: true, reason: "empty", warmed: [] };
      }
      if (relatedWarmupInFlight) {
        return relatedWarmupInFlight;
      }
      relatedWarmupInFlight = (async () => {
        const warmed = [];
        for (const entry of paths) {
          let requestUrl2;
          let parsed;
          try {
            parsed = new URL(String(entry), origin);
            requestUrl2 = navigationCacheUrl(origin, parsed.pathname, parsed.search);
            parsed = new URL(requestUrl2, origin);
          } catch {
            requestUrl2 = navigationCacheUrl(origin, entry);
            parsed = new URL(requestUrl2, origin);
          }
          if (!isRelatedWarmPath(parsed.pathname)) {
            continue;
          }
          const request = new Request(requestUrl2, {
            method: "GET",
            credentials: "same-origin",
            headers: { Accept: "text/html" }
          });
          try {
            const response = await fetchImpl(request);
            const finalUrl = response?.url || requestUrl2;
            let finalParsed;
            try {
              finalParsed = new URL(finalUrl, origin);
            } catch {
              continue;
            }
            if (normalizePathname(finalParsed.pathname) !== normalizePathname(parsed.pathname)) {
              continue;
            }
            if (!shouldCacheNavigationResponse(response, finalUrl, origin)) {
              continue;
            }
            if (!isCacheableNavigationRequest(request, parsed, origin)) {
              continue;
            }
            await storeNavigation(parsed, response);
            warmed.push(parsed.pathname + (parsed.search || ""));
          } catch {
          }
        }
        return { ok: true, reason: "warmed", warmed };
      })();
      try {
        return await relatedWarmupInFlight;
      } finally {
        relatedWarmupInFlight = null;
      }
    }
    function isLocalMemberPath(pathname) {
      return isLocalMemberViewPath(pathname) || isLocalMemberEditPath(pathname) || isLocalMemberHealthPath(pathname);
    }
    async function handleNavigation(request, url) {
      try {
        const response = await fetchImpl(request);
        if (response && response.status === 404 && isLocalMemberPath(url.pathname)) {
          const local = await matchNavigation(url);
          if (local) {
            return local;
          }
        }
        const finalUrl = response?.url || url.href;
        if (shouldCacheNavigationResponse(response, finalUrl, origin) && isCacheableNavigationRequest(request, url, origin)) {
          try {
            await storeNavigation(url, response);
          } catch {
          }
        }
        return response;
      } catch {
        const cached = await matchNavigation(url);
        if (cached) {
          return cached;
        }
        return fallbackResponse();
      }
    }
    async function handleFetch(request) {
      const url = requestUrl(request);
      if (!url) {
        return fetchImpl(request);
      }
      if (!isSameOrigin(url, origin)) {
        return fetchImpl(request);
      }
      if (isLogoutRequest(request, url)) {
        await clearPrivateHtml();
        return fetchImpl(request);
      }
      if (isMutationMethod(request.method)) {
        return fetchImpl(request);
      }
      if (String(request.method || "GET").toUpperCase() !== "GET") {
        return fetchImpl(request);
      }
      if (isManagedStaffAvatarPath(url.pathname) && String(request.method || "GET").toUpperCase() === "GET") {
        try {
          return await handleActorAvatar(request, url);
        } catch {
          return new Response("", { status: 404, headers: { "Cache-Control": "no-store" } });
        }
      }
      if (isNeverCachePath(url.pathname) || isDevelopmentOnlyPath(url.pathname) || hasUnsafeQuery(url)) {
        if (request.mode === "navigate") {
          try {
            return await fetchImpl(request);
          } catch {
            return fallbackResponse();
          }
        }
        return fetchImpl(request);
      }
      if (isStaticAssetPath(url.pathname) && isCacheableAssetRequest(request, url, origin)) {
        try {
          return await cacheFirstAsset(request, url);
        } catch {
          const cache = await cachesApi.open(ASSETS_CACHE);
          const hit = await cache.match(new Request(`${origin}${url.pathname}`));
          if (hit) {
            return hit;
          }
          return assetUnavailableResponse();
        }
      }
      if (isCacheableNavigationRequest(request, url, origin)) {
        return handleNavigation(request, url);
      }
      if (request.mode === "navigate") {
        try {
          return await fetchImpl(request);
        } catch {
          return fallbackResponse();
        }
      }
      return fetchImpl(request);
    }
    async function handleMessage(data, extras = {}) {
      const type = data && typeof data.type === "string" ? data.type : "";
      const ports = Array.isArray(extras.ports) ? extras.ports : [];
      if (type === MESSAGE_SET_ACTOR) {
        const id = Number(data.actorId || data.actor_id || 0);
        await setActor(Number.isInteger(id) && id > 0 ? id : null);
        return;
      }
      if (type === MESSAGE_CLEAR_HTML) {
        await clearPrivateHtml();
        return;
      }
      if (type === MESSAGE_ENSURE_BUILD_ASSETS) {
        const state = await ensureBuiltAssets();
        const payload = { type: MESSAGE_ENSURE_BUILD_ASSETS_RESULT, ...state };
        await notifyClients(payload, ports);
        return payload;
      }
      if (type === MESSAGE_WARMUP_READY_QUERY) {
        const id = Number(data.actorId || data.actor_id || 0);
        const state = await coreWarmupReadyState(
          Number.isInteger(id) && id > 0 ? id : await getActorId(),
          data.role
        );
        await notifyClients({ type: MESSAGE_WARMUP_READY_RESULT, ...state }, ports);
        return state;
      }
      if (type === MESSAGE_WARMUP_CORE) {
        const id = Number(data.actorId || data.actor_id || 0);
        await warmCoreNavigation(Number.isInteger(id) && id > 0 ? id : null, {
          role: data.role,
          avatarUrl: data.avatarUrl || data.avatar_url || null,
          ports
        });
        return;
      }
      if (type === MESSAGE_WARMUP_UM_WORKERS) {
        const id = Number(data.actorId || data.actor_id || 0);
        await warmUserManagementListedWorkers(Number.isInteger(id) && id > 0 ? id : null, {
          paths: data.paths,
          avatars: data.avatars || data.avatarUrls || data.avatar_urls
        });
        return;
      }
      if (type === MESSAGE_WARMUP_RELATED) {
        const id = Number(data.actorId || data.actor_id || 0);
        await warmRelatedPages(Number.isInteger(id) && id > 0 ? id : null, {
          paths: data.paths
        });
      }
    }
    return {
      install,
      activate,
      handleFetch,
      handleMessage,
      getActorId,
      setActor,
      clearPrivateHtml,
      warmCoreNavigation,
      warmUserManagementListedWorkers,
      warmRelatedPages,
      coreWarmupReadyState,
      ensureBuiltAssets,
      verifyBuiltAssets,
      fallbackResponse,
      assetUnavailableResponse,
      normalizePathname
    };
  }

  // resources/js/offline/offline-sw.js
  var runtime = createServiceWorkerRuntime({
    origin: self.location.origin,
    caches,
    clients,
    fetch: (request, init) => fetch(request, init),
    skipWaiting: () => self.skipWaiting(),
    clientsClaim: () => self.clients.claim()
  });
  self.addEventListener("install", (event) => {
    event.waitUntil(runtime.install());
  });
  self.addEventListener("activate", (event) => {
    event.waitUntil(runtime.activate());
  });
  self.addEventListener("fetch", (event) => {
    event.respondWith(
      runtime.handleFetch(event.request).catch(() => {
        if (event.request.mode === "navigate") {
          return runtime.fallbackResponse();
        }
        return runtime.assetUnavailableResponse ? runtime.assetUnavailableResponse() : new Response("", { status: 504, headers: { "Cache-Control": "no-store" } });
      })
    );
  });
  self.addEventListener("message", (event) => {
    const data = event.data;
    if (!data || typeof data !== "object") {
      return;
    }
    event.waitUntil(
      runtime.handleMessage(data, { source: event.source, ports: event.ports }).then((result) => {
        event.ports?.[0]?.postMessage(result && typeof result === "object" ? { ok: true, ...result } : { ok: true });
      })
    );
  });
})();
