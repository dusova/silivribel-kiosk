# OPTIMIZATIONS.md — Full Optimization Audit

**Audit Date:** 4 Mart 2026  
**Scope:** Full codebase — backend PHP, SQL schema, Electron main/renderer/preload, admin panel, CSS assets

---

## 1) Optimization Summary

**Current Health:** The codebase is small, well-structured, and has strong security posture. However, it has several database-level inefficiencies, duplicated logic across layers, unbounded growth risks in `access_logs`, and missed caching opportunities that will compound under real kiosk traffic.

### Top 3 Highest-Impact Improvements

1. **Unbounded `access_logs` table growth** — no partition, TTL, or cleanup. In continuous kiosk usage this table will grow indefinitely, degrading admin panel load times (the `ORDER BY … DESC LIMIT 30` query) and consuming disk.
2. **Missing database indexes on `access_logs.url_id` and `allowed_urls.sort_order`** — causes full table scans on the admin panel's JOIN query and the API's `ORDER BY sort_order` query.
3. **Multiple redundant `getDB()` calls and full-table scans per admin page load** — `admin.php` calls `getDB()` up to 3× per request and runs 3 separate queries that could be batched or deferred.

### Biggest Risk If No Changes Are Made

The `access_logs` table will grow without bound. Over weeks/months of kiosk operation, the admin panel's log query (`LEFT JOIN … ORDER BY accessed_at DESC LIMIT 30`) will degrade from milliseconds to seconds as the table reaches hundreds of thousands of rows with no covering index, and storage will be silently consumed.

---

## 2) Findings (Prioritized)

### F1: Unbounded `access_logs` Table Growth

* **Category:** DB / Cost / Reliability
* **Severity:** Critical
* **Impact:** Disk exhaustion, degraded query performance, admin panel slowdown
* **Evidence:** `access_logs` table in [silivri_kiosk.sql](backend/silivri_kiosk.sql) has no TTL, partitioning, or scheduled purge. Every kiosk tap inserts a row via `api.php?action=log`. No `DELETE` or archive mechanism exists anywhere in the codebase.
* **Why it's inefficient:** A kiosk receiving ~200 taps/day accumulates ~73K rows/year. The `ORDER BY accessed_at DESC LIMIT 30` with a LEFT JOIN will scan increasingly large result sets. There is no cleanup, so the table only grows.
* **Recommended fix:**
  1. Add a scheduled MySQL event or cron job: `DELETE FROM access_logs WHERE accessed_at < NOW() - INTERVAL 90 DAY`.
  2. Alternatively, partition by month on `accessed_at`.
  3. Add a "purge old logs" button in admin panel or auto-purge in `admin.php` on login.
* **Tradeoffs / Risks:** Historical analytics beyond 90 days would be lost unless exported first.
* **Expected impact estimate:** Prevents unbounded growth; keeps admin panel query < 10ms indefinitely.
* **Removal Safety:** Safe (adding retention policy, not removing anything)
* **Reuse Scope:** Service-wide

---

### F2: Missing Index on `access_logs.url_id`

* **Category:** DB
* **Severity:** High
* **Impact:** Admin panel log query latency
* **Evidence:** [silivri_kiosk.sql](backend/silivri_kiosk.sql) — `access_logs` only has indexes on `id` (PK) and `accessed_at`. The admin query in [admin.php](backend/admin.php#L195) does `LEFT JOIN allowed_urls u ON l.url_id=u.id` — this join scans `url_id` without an index.
* **Why it's inefficient:** Without an index on `url_id`, the JOIN requires a full scan of `access_logs` for each row to match `allowed_urls.id`. As the table grows, this becomes O(n).
* **Recommended fix:**
  ```sql
  ALTER TABLE access_logs ADD KEY idx_url_id (url_id);
  ```
* **Tradeoffs / Risks:** Negligible write overhead (one extra index update per INSERT).
* **Expected impact estimate:** 5-10× faster JOIN on large tables.
* **Removal Safety:** Safe
* **Reuse Scope:** Service-wide

---

### F3: Missing Composite Index on `allowed_urls (is_active, sort_order, id)`

* **Category:** DB
* **Severity:** Medium
* **Impact:** API `?action=urls` query efficiency
* **Evidence:** [api.php](backend/api.php#L39) runs `WHERE is_active = 1 ORDER BY sort_order ASC, id ASC`. The table has only a PK on `id` — no index covers `is_active` + `sort_order`.
* **Why it's inefficient:** With ~5 rows this is invisible, but the schema supports growth. Without a covering index the query does a full table scan + filesort.
* **Recommended fix:**
  ```sql
  ALTER TABLE allowed_urls ADD KEY idx_active_sort (is_active, sort_order, id);
  ```
* **Tradeoffs / Risks:** Tiny write overhead. Almost zero risk.
* **Expected impact estimate:** Low now (small table), preventive for future growth.
* **Removal Safety:** Safe
* **Reuse Scope:** Service-wide

---

### F4: `api.php?action=log` URL Lookup Is an Extra Query When `url_id` Is Available

* **Category:** DB / I/O
* **Severity:** Medium
* **Impact:** Extra query per kiosk tap (latency + DB load)
* **Evidence:** [api.php](backend/api.php#L54-L58) — when `url_id` is not provided, it runs `SELECT id FROM allowed_urls WHERE url = ? LIMIT 1` before the INSERT. But the Electron client in [main.js](electron-client/assets/js/main.js#L126) **always** sends `url_id` when available: `fetch(…&url_id=${id || ''})`.
* **Why it's inefficient:** The fallback lookup has no index on `allowed_urls.url`. For the common case where `url_id` is provided, this is fine. But the fallback path does a full scan on a `varchar(500)` column.
* **Recommended fix:**
  1. Add index: `ALTER TABLE allowed_urls ADD KEY idx_url (url(191));` (191 for InnoDB key length limit on utf8mb4).
  2. Or, since the Electron client always sends `url_id`, consider making the fallback a true edge-case comment and skipping it if `url_id` is truthy (already done — just add the index for safety).
* **Tradeoffs / Risks:** Index on `varchar(500)` uses prefix; exact-match semantics preserved for typical URLs.
* **Expected impact estimate:** Prevents rare slow fallback path.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F5: `admin.php` Calls `getDB()` Multiple Times Per Request

* **Category:** I/O / Code Efficiency
* **Severity:** Medium
* **Impact:** Unnecessary function call overhead, minor readability issue
* **Evidence:** [admin.php](backend/admin.php) calls `getDB()` in three separate locations:
  - [Line ~54](backend/admin.php#L54) (login flow)
  - [Line ~102](backend/admin.php#L102) (POST action handling)
  - [Line ~193](backend/admin.php#L193) (data loading for display)
  
  `getDB()` uses a `static $pdo` singleton in [db.php](backend/db.php#L18), so the actual PDO connection is created only once. However, calling `getDB()` in 3 different scopes creates unnecessary cognitive load and makes it easy to accidentally create non-singleton patterns in the future.
* **Why it's inefficient:** Not a runtime bottleneck (the static cache works), but a maintainability concern. Each scope independently catches exceptions with different messages.
* **Recommended fix:** Call `getDB()` once after `require_once __DIR__ . '/db.php'` and assign to a file-scoped `$db` variable. Use conditional checks rather than re-fetching.
* **Tradeoffs / Risks:** Minor refactor; must ensure `$db` is available in all code paths that need it.
* **Expected impact estimate:** Low runtime impact; moderate maintainability improvement.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F6: `admin.php` Loads All Data Regardless of Active Tab

* **Category:** I/O / DB
* **Severity:** Medium
* **Impact:** Unnecessary queries on every admin page load
* **Evidence:** [admin.php](backend/admin.php#L192-L198) always runs 3 queries (`allowed_urls`, `access_logs` with JOIN, `kiosk_settings`) even if the admin is only viewing the "Add" or "Settings" tab. The logs query with `LEFT JOIN … ORDER BY … DESC LIMIT 30` is the most expensive.
* **Why it's inefficient:** On every page load (including POST-redirect-GET after form submission), all 3 queries run. The logs query is O(n) without the `url_id` index.
* **Recommended fix:**
  1. **Quick fix:** Lazy-load logs via AJAX only when the "Erişim Günlüğü" tab is clicked.
  2. **Alternative:** Add a query parameter (`?tab=logs`) and only run the logs query when that tab is active.
* **Tradeoffs / Risks:** Adds JS complexity to admin panel; but the tab system already exists in `admin.js`.
* **Expected impact estimate:** ~33% reduction in DB queries per admin page load.
* **Removal Safety:** Likely Safe
* **Reuse Scope:** Local file

---

### F7: `admin.php` Sort Endpoint Has No Batch Size Limit

* **Category:** Security-impacting inefficiency / Reliability
* **Severity:** Medium
* **Impact:** An attacker (or bug) could POST thousands of sort items, causing thousands of UPDATE queries in a loop
* **Evidence:** [admin.php](backend/admin.php#L155-L163) — the `sort` action reads `php://input`, decodes unbounded JSON, and loops `foreach ($orders as $item)` executing individual UPDATEs.
* **Why it's inefficient:** No limit on array size. Each item executes a separate prepared statement. With 1000 items, this is 1000 UPDATE queries.
* **Recommended fix:**
  1. Add a size check: `if (count($orders) > 100) sendJson(400, …)`.
  2. Wrap in a transaction for atomicity and performance:
     ```php
     $db->beginTransaction();
     foreach ($orders as $item) { … }
     $db->commit();
     ```
* **Tradeoffs / Risks:** Transaction adds atomicity (good); size limit may need adjustment if more than 100 services are added.
* **Expected impact estimate:** Prevents abuse vector; 2-5× faster bulk sort updates via transaction batching.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F8: `api.php?action=settings` Scans Full `kiosk_settings` and Filters In PHP

* **Category:** DB / Algorithm
* **Severity:** Low
* **Impact:** Minor unnecessary data transfer
* **Evidence:** [api.php](backend/api.php#L68-L74) — `SELECT setting_key, setting_value FROM kiosk_settings` fetches ALL rows, then filters in PHP via `in_array`.
* **Why it's inefficient:** With a few rows it's trivial, but the pattern is wrong — it fetches all settings from DB into PHP memory, then discards non-public ones. Better to never fetch them.
* **Recommended fix:**
  ```php
  $placeholders = implode(',', array_fill(0, count($RENDERER_SAFE_KEYS), '?'));
  $stmt = $db->prepare("SELECT setting_key, setting_value FROM kiosk_settings WHERE setting_key IN ($placeholders)");
  $stmt->execute($RENDERER_SAFE_KEYS);
  ```
* **Tradeoffs / Risks:** Negligible. Cleaner security posture — secrets never leave DB for this endpoint.
* **Expected impact estimate:** Low performance impact; meaningful security-in-depth improvement.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F9: Renderer `setInterval(updateClock, 1000)` Runs Continuously Including Splash Screen

* **Category:** CPU / Frontend
* **Severity:** Low
* **Impact:** Unnecessary DOM updates every second when on splash screen (clock is not visible)
* **Evidence:** [renderer.js](electron-client/assets/js/renderer.js#L150) — `setInterval(updateClock, 1000)` starts at init and never stops. The clock elements (`#clock`, `#date-display`, `#day-display`) are only visible on `screen-main`, not on `screen-splash`.
* **Why it's inefficient:** 3 DOM writes/second when the splash screen is active, hitting elements that are `display: none`.
* **Recommended fix:** Start the interval when entering `screen-main`, clear it when returning to `screen-splash`.
* **Tradeoffs / Risks:** Minor added complexity; risk of clock showing stale time for a split second on transition.
* **Expected impact estimate:** Negligible CPU savings but cleaner resource usage.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F10: Duplicate `API_BASE` Constant in `main.js` and `renderer.js`

* **Category:** Code Reuse / Maintainability
* **Severity:** Medium
* **Impact:** Drift risk — if one is updated and the other isn't, the app breaks silently
* **Evidence:**
  - [main.js](electron-client/assets/js/main.js#L49): `const API_BASE = 'http://127.0.0.1/kiosk-sistem/backend/api.php';`
  - [renderer.js](electron-client/assets/js/renderer.js#L3): `const API_BASE = 'http://127.0.0.1/kiosk-sistem/backend/api.php';`
  
  AGENTS.md explicitly warns about keeping these identical, confirming it's a known fragility.
* **Why it's inefficient:** Violates DRY. Human error can cause subtle breakage. The renderer's `API_BASE` is actually **dead code** — renderer never uses it directly for fetch (all API calls go through `window.kioskAPI.fetchApi()` → IPC → `main.js` `net.fetch`).
* **Recommended fix:** Remove `API_BASE` from `renderer.js` since it's unused there. All API calls from the renderer go through IPC to `main.js`.
* **Tradeoffs / Risks:** If a future developer adds direct fetch in renderer bypassing IPC, they'd need to re-add it. But that would violate the CSP `connect-src` and the Electron security model anyway.
* **Expected impact estimate:** Eliminates a drift-risk constant; simplifies renderer.
* **Removal Safety:** Needs Verification — confirm `API_BASE` is truly unused in `renderer.js`
* **Reuse Scope:** Module-wide

---

### F11: Dead Code — `API_BASE` and `IDLE_MS` / `IDLE_WARN` in `renderer.js`

* **Category:** Dead Code
* **Severity:** Low
* **Impact:** Cognitive overhead, bundle size (minor)
* **Evidence:**
  - `API_BASE` in [renderer.js](electron-client/assets/js/renderer.js#L3) — never used in any `fetch()` or API call in that file. All API calls use `window.kioskAPI.fetchApi()`.
  - `IDLE_MS` in [renderer.js](electron-client/assets/js/renderer.js#L4) — used only for `idleRem` initialization and `idleTick`. But the **authoritative** idle timeout is in `main.js` (`IDLE_TIMEOUT_MS = 2 * 60 * 1000`). The renderer has its own parallel idle tracking that could drift from `main.js`.
* **Why it's inefficient:** Two independent idle tracking mechanisms (main process + renderer) that should be in sync but have no synchronization. The renderer's `idleTick` synthetic countdown could show "0:00" while `main.js` hasn't timed out yet (or vice versa).
* **Recommended fix:**
  1. Remove `API_BASE` from `renderer.js`.
  2. For idle tracking: either (a) rely solely on `main.js` timer and have it send a `idle-warning` IPC event at 15s remaining, or (b) keep renderer-side countdown but sync it with the main process timer via IPC.
* **Tradeoffs / Risks:** Removing renderer-side idle requires adding IPC events for the warning countdown.
* **Expected impact estimate:** Eliminates drift bug; reduces dead/redundant code.
* **Removal Safety:** Needs Verification
* **Reuse Scope:** Module-wide

---

### F12: Duplicate CSS Variables Across Two Stylesheets

* **Category:** Code Reuse / Maintainability
* **Severity:** Low
* **Impact:** Maintenance drift, inconsistent theming
* **Evidence:** Both [backend/assets/css/style.css](backend/assets/css/style.css#L1-L15) and [electron-client/assets/css/style.css](electron-client/assets/css/style.css#L1-L20) define identical `:root` variables (`--brand-blue`, `--brand-red`, `--brand-yellow`, etc.) independently.
* **Why it's inefficient:** If a brand color changes, both files must be updated. These are separate deployments (admin panel vs Electron client) so a shared file isn't trivially possible, but the duplication is a drift vector.
* **Recommended fix:** Document the canonical color values in a shared reference (e.g., a comment block in AGENTS.md) and consider a build step that generates both from a single source (e.g., CSS custom properties file or SCSS variables).
* **Tradeoffs / Risks:** Adding a build step adds complexity for a small project.
* **Expected impact estimate:** Low; preventive for brand consistency.
* **Removal Safety:** Safe
* **Reuse Scope:** Service-wide

---

### F13: `admin.php` Inline `onclick` Handlers Violate Own CSP Pattern

* **Category:** Maintainability / Security
* **Severity:** Low
* **Impact:** Inconsistency between admin panel (inline handlers) and Electron client (strict CSP)
* **Evidence:** [admin.php](backend/admin.php#L300-L304) uses inline `onclick="showTab('list',this)"` on pill-nav buttons. While admin.php doesn't enforce a strict CSP header (unlike the Electron client), this is inconsistent with AGENTS.md's stated constraint about event handlers.
* **Why it's inefficient:** Not a performance issue, but a maintainability/consistency concern. If CSP is later added to admin.php, all inline handlers will silently break.
* **Recommended fix:** Move tab button click handlers to `admin.js` using `addEventListener`, matching the Electron client's pattern.
* **Tradeoffs / Risks:** Minor refactor.
* **Expected impact estimate:** Preventive; no runtime change.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F14: `admin.php` Edit Item Lookup Is O(n) Linear Scan

* **Category:** Algorithm
* **Severity:** Low
* **Impact:** Negligible with small datasets
* **Evidence:** [admin.php](backend/admin.php#L206-L211) — `foreach ($urls as $u) { if ((int)$u['id'] === (int)$_GET['edit']) { $editItem = $u; break; } }` linearly scans the `$urls` array to find an item by ID.
* **Why it's inefficient:** O(n) scan instead of O(1) lookup. With 5-50 services, irrelevant. But poor pattern.
* **Recommended fix:** Build an `$urlsById` associative array once: `$urlsById = array_column($urls, null, 'id');` then `$editItem = $urlsById[(int)$_GET['edit']] ?? null;`.
* **Tradeoffs / Risks:** None.
* **Expected impact estimate:** Negligible.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F15: Electron `BrowserView` Ad Blocker Registered Per View

* **Category:** I/O / Efficiency
* **Severity:** Low
* **Impact:** Repeated `onBeforeRequest` handler setup
* **Evidence:** [main.js](electron-client/assets/js/main.js#L98-L100) — every call to `openInBrowserView()` registers a new `onBeforeRequest` handler on the session. Since all `BrowserView` instances share the default session (unless a custom partition is used), this stacks multiple identical handlers.
* **Why it's inefficient:** Multiple identical `onBeforeRequest` callbacks checking the same URL patterns. Each network request invokes all registered handlers.
* **Recommended fix:** Register the ad-block handler once on the default session in `app.whenReady()`, not per-`BrowserView` creation.
* **Tradeoffs / Risks:** Must verify all BrowserViews share the same session (they do by default).
* **Expected impact estimate:** Eliminates redundant handler invocations; minor CPU saving per network request.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F16: `api.php` Uses `JSON_PRETTY_PRINT` in Production API

* **Category:** Network / I/O
* **Severity:** Low
* **Impact:** ~30-50% larger JSON payloads due to whitespace
* **Evidence:** [api.php](backend/api.php#L28) — `json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)`.
* **Why it's inefficient:** Pretty-printing adds whitespace to every API response. The Electron client parses JSON programmatically and doesn't benefit from formatting.
* **Recommended fix:** Remove `JSON_PRETTY_PRINT` flag. Keep `JSON_UNESCAPED_UNICODE`.
* **Tradeoffs / Risks:** Harder to debug with `curl`. Mitigate by using `jq` for debugging.
* **Expected impact estimate:** ~30% smaller API responses.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F17: No `Cache-Control` for Static API Responses

* **Category:** Caching / Network
* **Severity:** Low
* **Impact:** Every `?action=urls` and `?action=settings` call hits PHP + DB
* **Evidence:** [api.php](backend/api.php#L17) sets `Cache-Control: no-store, no-cache, must-revalidate` for all actions. The `urls` and `settings` data change infrequently (only when admin modifies them).
* **Why it's inefficient:** Kiosk restarts or page reloads always hit the DB. For a single-kiosk deployment this is negligible; for multiple kiosks hitting the same backend, it wastes DB connections.
* **Recommended fix:** For `urls` and `settings` actions, use a short TTL: `Cache-Control: public, max-age=60`. For `log` (write action), keep `no-store`.
* **Tradeoffs / Risks:** Admin changes take up to 60s to propagate. Acceptable for a kiosk.
* **Expected impact estimate:** Reduces DB queries for repeat requests within 60s window.
* **Removal Safety:** Likely Safe
* **Reuse Scope:** Local file

---

### F18: `renderer.js` `fetchServices()` Clears Grid with `querySelectorAll('.card').forEach(c => c.remove())`

* **Category:** Frontend / DOM
* **Severity:** Low
* **Impact:** Unnecessary per-element DOM removal
* **Evidence:** [renderer.js](electron-client/assets/js/renderer.js#L99) — iterates over all `.card` elements and removes them one by one, then later may set `grid.innerHTML` in the empty-list case.
* **Why it's inefficient:** Multiple DOM mutations instead of a single clear. With 5-12 cards, this is negligible, but the pattern is suboptimal.
* **Recommended fix:** Use a `DocumentFragment` for building all cards, then replace children in one operation. Or simply clear with `grid.replaceChildren(loader)` before rebuilding.
* **Tradeoffs / Risks:** None.
* **Expected impact estimate:** Negligible; cleaner pattern.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F19: `electron-client/index.html` Google Fonts Not Loaded with SRI

* **Category:** Security / Reliability
* **Severity:** Low
* **Impact:** Google Fonts CSS loaded without `integrity` attribute
* **Evidence:** [index.html](electron-client/index.html#L19-L22) — `<link href="https://fonts.googleapis.com/css2?family=Manrope…" rel="stylesheet">` has no `integrity` or `crossorigin` attribute. Same in [admin.php](backend/admin.php#L234).
* **Why it's inefficient:** AGENTS.md requires SRI hashes for CDN resources. Google Fonts dynamically generates CSS responses based on User-Agent, making SRI impractical. However, this violates the stated constraint.
* **Recommended fix:** Self-host the Manrope font files to comply with SRI requirements and eliminate external dependency. This also improves offline/air-gapped kiosk reliability.
* **Tradeoffs / Risks:** Increases local asset size (~200KB for WOFF2 files). Requires periodic manual font updates.
* **Expected impact estimate:** Eliminates external font dependency; compliant with SRI policy; faster font loading (local).
* **Removal Safety:** Safe
* **Reuse Scope:** Service-wide

---

### F20: CSS Logo Mask Images Loaded from External URL

* **Category:** Network / Reliability
* **Severity:** Medium
* **Impact:** Kiosk UI breaks if external logo URL is unreachable
* **Evidence:** [electron-client/assets/css/style.css](electron-client/assets/css/style.css#L220-L248) — `.splash-logo-mask`, `.header-logo-mask`, and `.footer-brand-mask` all use `mask-image: url('https://www.silivri.bel.tr/…')`. If the municipality website is down, the kiosk shows no logo.
* **Why it's inefficient:** External dependency for critical UI elements. Network latency on each page load. No fallback.
* **Recommended fix:** Download SVG files to `electron-client/assets/img/` and reference them locally. The kiosk is an offline-first application.
* **Tradeoffs / Risks:** Must update local copies if the municipality changes its logo.
* **Expected impact estimate:** Eliminates ~3 external HTTP requests; ensures logo renders offline.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F21: `sleep(1)` on Failed Login in `admin.php`

* **Category:** Reliability / Concurrency
* **Severity:** Low
* **Impact:** Blocks PHP-FPM worker for 1 second on every failed login
* **Evidence:** [admin.php](backend/admin.php#L67) — `sleep(1)` after a failed password check.
* **Why it's inefficient:** While the intent is rate-limiting (anti-brute-force), blocking a PHP worker thread for 1s is wasteful. With the existing session-based lockout (5 attempts → 15min lock), the `sleep(1)` provides marginal additional protection.
* **Recommended fix:** Remove `sleep(1)` since session-based rate limiting already handles brute-force. If timing-based delay is desired, consider moving it to the client side or using `usleep()` with a shorter delay (100ms).
* **Tradeoffs / Risks:** Slightly faster brute-force attempts (mitigated by session lockout). Keep if defense-in-depth is strongly valued.
* **Expected impact estimate:** Frees PHP worker 1s sooner per failed login.
* **Removal Safety:** Likely Safe
* **Reuse Scope:** Local file

---

### F22: Duplicate `.sb-brand` CSS Rule Block

* **Category:** Dead Code / CSS
* **Severity:** Low
* **Impact:** Duplicate CSS rule causes confusion; second block overrides first
* **Evidence:** [backend/assets/css/style.css](backend/assets/css/style.css#L38-L42) and [lines 44-50](backend/assets/css/style.css#L44-L50) — `.sb-brand` is defined twice. The first sets `gap: 16px`, the second sets `gap: 14px` and adds `padding: 30px 24px`, `border-bottom`. The second silently overrides the first.
* **Recommended fix:** Merge into a single `.sb-brand` rule block with the intended values.
* **Tradeoffs / Risks:** None.
* **Expected impact estimate:** Eliminates CSS confusion.
* **Removal Safety:** Safe
* **Reuse Scope:** Local file

---

### F23: `main.js` `mainWindow.on('close', e => e.preventDefault())` Prevents Clean Shutdown

* **Category:** Reliability
* **Severity:** Medium
* **Impact:** App cannot be closed except by killing the process
* **Evidence:** [main.js](electron-client/assets/js/main.js#L180) — `mainWindow.on('close', e => e.preventDefault())` unconditionally prevents window close.
* **Why it's inefficient:** This is intentional for kiosk mode, but there's no escape hatch. `before-quit` event handler does cleanup but window close is prevented, so `app.quit()` or `app.exit()` are the only ways to terminate. If the admin needs to close the app (e.g., for maintenance), there's no mechanism.
* **Recommended fix:** Add a flag (`let allowQuit = false`) that is set to `true` before intentional quit (e.g., via a hidden admin shortcut or IPC command). Only prevent close when `allowQuit === false`.
* **Tradeoffs / Risks:** Adds a potential exit path that must be secured.
* **Expected impact estimate:** Enables clean shutdown for maintenance.
* **Removal Safety:** Needs Verification
* **Reuse Scope:** Local file

---

### F24: `preload.js` IPC Listener Leak — `on()` Returns Cleanup But Nothing Calls It

* **Category:** Memory / Reliability
* **Severity:** Medium
* **Impact:** Potential IPC listener accumulation
* **Evidence:** [preload.js](electron-client/assets/js/preload.js#L11-L15) — the `on()` function returns a cleanup function (`() => ipcRenderer.removeListener(event, fn)`), but the callers in [renderer.js](electron-client/assets/js/renderer.js#L65-L78) (`setupIPC()`) never store or invoke the returned cleanup functions.
* **Why it's inefficient:** If `setupIPC()` were called multiple times (currently it's called once in `init()`), listeners would stack. Currently safe due to single invocation, but the pattern is fragile.
* **Recommended fix:** Either (a) store cleanup references in renderer.js and call them on teardown, or (b) add a guard in `setupIPC()` to prevent double-registration.
* **Tradeoffs / Risks:** None.
* **Expected impact estimate:** Preventive; no current leak.
* **Removal Safety:** Safe
* **Reuse Scope:** Module-wide

---

### F25: `admin.php` `SELECT *` in URL Listing Query

* **Category:** DB
* **Severity:** Low
* **Impact:** Fetches unnecessary columns (`image_url`, `created_at`, `updated_at`)
* **Evidence:** [admin.php](backend/admin.php#L192) — `SELECT * FROM allowed_urls ORDER BY sort_order ASC, id ASC`. Fetches `image_url`, `created_at`, `updated_at` which are only partially used.
* **Why it's inefficient:** Minor data transfer overhead. `image_url` is explicitly documented as legacy/unused.
* **Recommended fix:** Use explicit column list matching what's actually rendered in the admin table.
* **Tradeoffs / Risks:** Must update if new columns are added that are displayed. The `fillEdit()` JS function uses `json_encode($row)` which passes all columns — would need adjustment.
* **Expected impact estimate:** Negligible.
* **Removal Safety:** Needs Verification
* **Reuse Scope:** Local file

---

## 3) Quick Wins (Do First)

| Priority | Finding | Effort | Impact |
|----------|---------|--------|--------|
| 1 | **F2** — Add `idx_url_id` index on `access_logs` | 1 line SQL | High |
| 2 | **F16** — Remove `JSON_PRETTY_PRINT` from `api.php` | 1 line change | Medium |
| 3 | **F8** — Filter settings in SQL, not PHP | 5 lines | Medium (security) |
| 4 | **F7** — Add transaction + size limit to sort endpoint | 5 lines | Medium (reliability) |
| 5 | **F22** — Merge duplicate `.sb-brand` CSS rule | 2 min | Low (clarity) |
| 6 | **F10/F11** — Remove unused `API_BASE` from `renderer.js` | 1 line | Low (maintenance) |

---

## 4) Deeper Optimizations (Do Next)

| Priority | Finding | Effort | Impact |
|----------|---------|--------|--------|
| 1 | **F1** — Implement `access_logs` retention policy (cron/event) | 1-2 hours | Critical (prevents disk/perf degradation) |
| 2 | **F20** — Self-host logo SVGs for offline reliability | 1 hour | Medium (offline resilience) |
| 3 | **F19** — Self-host Manrope font for SRI compliance | 1 hour | Medium (compliance + offline) |
| 4 | **F6** — Lazy-load admin logs tab via AJAX | 2-3 hours | Medium (admin perf) |
| 5 | **F15** — Move ad-blocker registration to session level | 30 min | Low (cleaner architecture) |
| 6 | **F11** — Unify idle timeout tracking to main process | 2 hours | Medium (eliminates drift bug) |
| 7 | **F23** — Add controlled quit mechanism for maintenance | 1 hour | Medium (operational) |
| 8 | **F13** — Move admin inline handlers to `admin.js` | 1 hour | Low (consistency) |

---

## 5) Validation Plan

### Benchmarks

1. **`access_logs` query performance:**
   - Before: `EXPLAIN SELECT l.*, u.title FROM access_logs l LEFT JOIN allowed_urls u ON l.url_id=u.id ORDER BY l.accessed_at DESC LIMIT 30;`
   - After adding `idx_url_id`: re-run EXPLAIN, confirm index usage.
   - Seed 100K rows with `INSERT INTO access_logs (url, accessed_at) SELECT … FROM …` and measure query time.

2. **API response size:**
   - `curl -s http://127.0.0.1/kiosk-sistem/backend/api.php?action=urls | wc -c` (before/after removing PRETTY_PRINT).

3. **Sort endpoint stress test:**
   - POST 500 sort items without transaction → measure time.
   - POST 500 sort items with transaction → compare.

### Profiling Strategy

- **DB:** Enable MySQL slow query log (`long_query_time = 0.1`) and monitor for full scans.
- **PHP:** Use `microtime(true)` at start/end of `admin.php` to measure page generation time.
- **Electron:** Use `--inspect` flag and Chrome DevTools Performance tab to profile main process IPC overhead and renderer paint times.

### Metrics to Compare Before/After

| Metric | Tool | Target |
|--------|------|--------|
| `access_logs` query time at 100K rows | MySQL `EXPLAIN` / `BENCHMARK` | < 5ms |
| API `?action=urls` response size | `curl | wc -c` | -30% |
| Admin page load queries | MySQL general log | 3 → 1-2 |
| Electron main process IPC handlers | DevTools Listeners | No duplicates |

### Test Cases to Preserve Correctness

1. `api.php?action=urls` returns same JSON structure (minus whitespace).
2. `api.php?action=settings` returns only `RENDERER_SAFE_KEYS` — never secret keys.
3. `api.php?action=log` with `url_id` param still inserts correctly.
4. `api.php?action=log` without `url_id` still performs fallback lookup.
5. Admin sort endpoint still updates `sort_order` correctly.
6. Admin sort endpoint rejects payloads > 100 items.
7. Kiosk idle timeout still fires at 2 minutes.
8. Kiosk logo renders when offline (after self-hosting).

---

## 6) Optimized Code / Patches

### Patch A: Add Missing Indexes (F2, F3, F4)

```sql
-- F2: Index for access_logs JOIN on url_id
ALTER TABLE access_logs ADD KEY idx_url_id (url_id);

-- F3: Covering index for api.php urls query
ALTER TABLE allowed_urls ADD KEY idx_active_sort (is_active, sort_order, id);

-- F4: Prefix index for URL fallback lookup
ALTER TABLE allowed_urls ADD KEY idx_url (url(191));
```

### Patch B: Remove `JSON_PRETTY_PRINT` (F16)

```php
// In api.php sendJson():
// BEFORE:
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
// AFTER:
echo json_encode($data, JSON_UNESCAPED_UNICODE);
```

### Patch C: Filter Settings in SQL (F8)

```php
// In api.php case 'settings':
// BEFORE:
$stmt = $db->query("SELECT setting_key, setting_value FROM kiosk_settings");
$settings = [];
while ($row = $stmt->fetch()) {
    if (in_array($row['setting_key'], $RENDERER_SAFE_KEYS, true)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// AFTER:
$placeholders = implode(',', array_fill(0, count($RENDERER_SAFE_KEYS), '?'));
$stmt = $db->prepare(
    "SELECT setting_key, setting_value FROM kiosk_settings WHERE setting_key IN ($placeholders)"
);
$stmt->execute($RENDERER_SAFE_KEYS);
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
```

### Patch D: Transaction + Size Limit for Sort (F7)

```php
// In admin.php case 'sort':
$body = file_get_contents('php://input');
$orders = json_decode($body, true) ?? [];

if (count($orders) > 100) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Too many items']);
    exit;
}

$db->beginTransaction();
$stmt = $db->prepare("UPDATE allowed_urls SET sort_order=? WHERE id=?");
foreach ($orders as $item) {
    if (isset($item['id'], $item['order'])) {
        $stmt->execute([(int)$item['order'], (int)$item['id']]);
    }
}
$db->commit();

header('Content-Type: application/json');
echo json_encode(['success' => true]);
exit;
```

### Patch E: Remove Dead `API_BASE` from Renderer (F10/F11)

```javascript
// In renderer.js — remove line 3:
// BEFORE:
const API_BASE = 'http://127.0.0.1/kiosk-sistem/backend/api.php';
// AFTER:
// (line removed — all API calls go through kioskAPI.fetchApi() IPC)
```

### Patch F: Register Ad-Blocker Once at Session Level (F15)

```javascript
// In main.js app.whenReady() — add:
const { session } = require('electron');
session.defaultSession.webRequest.onBeforeRequest(
    { urls: AD_BLOCK_LIST },
    (details, callback) => callback({ cancel: true })
);

// In openInBrowserView() — remove:
// activeBrowserView.webContents.session.webRequest.onBeforeRequest(…)
```

### Patch G: Access Logs Retention (F1)

```sql
-- MySQL scheduled event (requires EVENT privilege):
CREATE EVENT IF NOT EXISTS purge_old_access_logs
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM access_logs WHERE accessed_at < NOW() - INTERVAL 90 DAY;
```

Or via PHP (add to `admin.php` on login success, after session setup):

```php
// Auto-purge logs older than 90 days on admin login
$db->exec("DELETE FROM access_logs WHERE accessed_at < NOW() - INTERVAL 90 DAY");
```
