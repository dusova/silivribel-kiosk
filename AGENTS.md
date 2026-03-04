# AGENTS.md

## Must-follow constraints
- `API_BASE` is defined only in `electron-client/assets/js/main.js`. Renderer uses IPC (`kioskAPI.fetchApi()`) — do not add a direct `API_BASE` to `renderer.js`.
- Preserve the API contract in `backend/api.php`: only `GET` is accepted, and supported actions are `urls`, `log`, `settings`.
- Do not bypass URL allowlisting: all external navigation must continue through `main.js` `open-url` flow and `isAllowedUrl` checks.
- Keep Electron hardening on renderer/webview contexts: `nodeIntegration: false`, `contextIsolation: true`, `sandbox: true`.
- Keep admin drag-sort contract unchanged: `POST` JSON to `backend/admin.php?action=sort` with `X-CSRF-Token` header and payload items `{ id, order }`. Max 100 items per request.
- **Never hardcode DB credentials in source.** Runtime credentials live in `backend/config.php` (git-ignored). Only `backend/config.example.php` is committed.
- **Never expose secrets via `api.php?action=settings`.** The `RENDERER_SAFE_KEYS` allowlist in `api.php` controls which setting keys are returned via SQL `WHERE IN` — future secrets never leave the DB for this endpoint.
- **No inline `onclick` / `onXxx` attributes in `electron-client/index.html`.** All UI event handlers are registered via `addEventListener` in `renderer.js` to comply with the strict CSP (`script-src 'self'`).
- **Admin panel event handlers** should be registered via `addEventListener` in `admin.js` where possible. Pill-nav, icon preview, form reset, and settings preview use `data-tab` attributes and JS listeners.
- **CDN resources require SRI hashes.** Every `<script>` and `<link>` loaded from jsDelivr (or any CDN) must include `integrity` and `crossorigin="anonymous"` attributes.
- **Electron permission grants must be origin-scoped.** `setPermissionRequestHandler` in `main.js` only allows `geolocation` for `maps.google.com`; `media` and all other permissions are denied.
- **Ad-blocker is registered once** on `session.defaultSession` in `app.whenReady()`, not per-BrowserView.

## Repo-specific conventions
- Electron entrypoint is `electron-client/assets/js/main.js` (set in `electron-client/package.json`). Path joins in `main.js` assume this nested location.
- Runtime service cards use DB/API fields `id`, `title`, `url`, `icon_class`, `service_type`, `sort_order`, `is_active`.
- `allowed_urls.image_url` exists in SQL dump but is legacy for current UI/API flow; do not reintroduce image-dependent rendering unless explicitly requested.
- Access logs should include `url_id` when available; `api.php?action=log` has URL-based fallback lookup and should remain backward compatible.
- Access logs are auto-purged: 90-day retention via MySQL scheduled event (`purge_old_access_logs`) and PHP cleanup on admin login.
- DB credentials are loaded via `backend/config.php` → `require_once` in `backend/db.php`. The config file is `.gitignore`'d; `backend/config.example.php` serves as the template.
- Admin login has session-based rate limiting: 5 failed attempts lock the account for 15 minutes. Counter resets on successful login.
- Session cookies are configured with `HttpOnly`, `SameSite=Strict` via `session_set_cookie_params()` before `session_start()`.
- Manrope font is self-hosted in `electron-client/assets/fonts/` (WOFF2 variable font). No Google Fonts external dependency.
- Municipality logo SVGs are self-hosted in `electron-client/assets/img/` for offline kiosk reliability. CSS `mask-image` references use relative paths.
- `api.php` uses per-action `Cache-Control`: `urls` and `settings` have `max-age=60`, `log` has `no-store`.
- App shutdown requires `allowQuit = true` flag in `main.js`; `before-quit` handler sets it. Use `app.quit()` for clean shutdown.

## Important locations
- Electron security/navigation/session logic: `electron-client/assets/js/main.js`
- Renderer card/UI data contract: `electron-client/assets/js/renderer.js`
- Electron IPC surface: `electron-client/assets/js/preload.js`
- Public API contract: `backend/api.php`
- Admin actions + CSRF + sort endpoint: `backend/admin.php`, `backend/assets/js/admin.js`
- DB connection loader: `backend/db.php` (loads `backend/config.php`)
- DB credential template: `backend/config.example.php`
- Security audit report: `SECURITY_AUDIT_REPORT.md`

## Change safety rules
- Preserve response envelope shape in `api.php` (`success`, plus `data` or `error`) to avoid breaking Electron fetch handlers.
- Keep `kiosk_settings` keys stable (`background_image`, `municipality_logo`) unless both backend and renderer/admin flows are updated together.
- When adding new `kiosk_settings` keys, decide whether to include them in `RENDERER_SAFE_KEYS` (public → renderer) or keep them server-only (admin panel only). Never auto-expose all keys.
- When injecting PHP data into `<script>` blocks in `admin.php`, always use `JSON_HEX_TAG | JSON_HEX_AMP` flags to prevent `</script>` XSS breakout.
- When injecting user-controlled values into CSS (e.g., `style.backgroundImage`), validate the URL protocol is `https:` and escape special characters before assignment.

## Known gotchas
- `backend/api.php` CORS allows `file://` and `null` origins for Electron file-origin requests. When `Origin` header is absent, **no** `Access-Control-Allow-Origin` header is returned (wildcard `*` fallback was removed). Tightening the origin list without coordinated client changes will break API calls.
- Moving Electron JS files requires coordinated updates to `electron-client/package.json` `main` and relative `path.join(...)` targets for `index.html` and `preload.js`.
- `electron-client/index.html` CSP is strict: `script-src 'self'` with no `'unsafe-inline'`. Adding inline handlers or inline scripts will be silently blocked by the browser. All JS must go through external `.js` files.
- `backend/config.php` is **not** committed. After a fresh clone, copy `config.example.php` → `config.php` and fill in real credentials; the app will throw `RuntimeException` on startup if the file is missing.

## Implemented Optimizations (4 Mart 2026)

Full audit details are in `OPTIMIZATIONS.md`. Below is the summary of all applied changes.

### Database (SQL)

| # | Finding | Change |
|---|---------|--------|
| F1 | Unbounded `access_logs` growth | Added MySQL scheduled event `purge_old_access_logs` (daily, 90-day retention) in `silivri_kiosk.sql`. Added PHP auto-purge on admin login in `admin.php`. |
| F2 | Missing index on `access_logs.url_id` | Added `KEY idx_url_id (url_id)` to `access_logs` in `silivri_kiosk.sql`. |
| F3 | Missing composite index for `?action=urls` | Added `KEY idx_active_sort (is_active, sort_order, id)` to `allowed_urls` in `silivri_kiosk.sql`. |
| F4 | No index on `allowed_urls.url` (fallback lookup) | Added `KEY idx_url (url(191))` to `allowed_urls` in `silivri_kiosk.sql`. |

### Backend PHP (`api.php`)

| # | Finding | Change |
|---|---------|--------|
| F8 | Settings fetched all rows then filtered in PHP | Replaced with `WHERE setting_key IN (?,?)` parameterized query — secrets never leave DB. |
| F16 | `JSON_PRETTY_PRINT` in production API | Removed flag; responses are ~30% smaller. |
| F17 | No per-action `Cache-Control` | `urls`/`settings` → `public, max-age=60`; `log` → `no-store`. |

### Backend PHP (`admin.php`)

| # | Finding | Change |
|---|---------|--------|
| F1 | Log auto-purge on login | `DELETE FROM access_logs WHERE accessed_at < NOW() - INTERVAL 90 DAY` runs on successful admin login. |
| F7 | Sort endpoint unbounded + no transaction | Wrapped in `beginTransaction()`/`commit()`, max 100 items enforced. |
| F14 | O(n) edit item lookup | Replaced `foreach` scan with `array_column($urls, null, 'id')` O(1) lookup. |
| F21 | `sleep(1)` on failed login | Removed — session-based rate limiting (5 attempts → 15 min lock) is sufficient. |
| F25 | `SELECT *` in URL listing | Replaced with explicit column list (`id, title, url, icon_class, service_type, sort_order, is_active`). |

### Admin Panel (`admin.php` + `admin.js`)

| # | Finding | Change |
|---|---------|--------|
| F13 | Inline `onclick`/`oninput` handlers | Removed from pill-nav buttons (`data-tab` attr + JS listener), icon preview input, form reset button, settings bg preview input. Handlers now in `admin.js` via `addEventListener`. |
| F22 | Duplicate `.sb-brand` CSS rule | Merged into single block in `backend/assets/css/style.css`. |

### Electron Client (`renderer.js`)

| # | Finding | Change |
|---|---------|--------|
| F10/F11 | Dead `API_BASE` constant | Removed from `renderer.js` — all API calls use `kioskAPI.fetchApi()` IPC. Exists only in `main.js`. |
| F9 | Clock interval runs on splash screen | Interval starts on `showScreen('main')`, clears on `showScreen('splash')`. Added `let clockInterval = null`. |
| F18 | Per-element card DOM insertion | Card rendering uses `DocumentFragment` for batch DOM append. |
| F24 | IPC listener double-registration risk | Added `let ipcInitialized = false` guard in `setupIPC()`. |

### Electron Client (`main.js`)

| # | Finding | Change |
|---|---------|--------|
| F15 | Ad-blocker registered per BrowserView | Moved to `session.defaultSession.webRequest.onBeforeRequest()` in `app.whenReady()`. Removed per-view registration. |
| F23 | `close` event unconditionally prevented | Added `let allowQuit = false` flag; `before-quit` sets it `true`; close is only prevented when `allowQuit === false`. |

### Assets / Offline Reliability

| # | Finding | Change |
|---|---------|--------|
| F19 | Google Fonts external dependency | Self-hosted Manrope WOFF2 variable font in `electron-client/assets/fonts/`. Removed Google Fonts `<link>` tags and CSP references. Added `@font-face` declarations in `electron-client/assets/css/style.css`. |
| F20 | Logo SVGs loaded from external URL | Downloaded municipality logos to `electron-client/assets/img/` (`new-logo.svg`, `silivribirlikteguzel.svg`). Updated CSS `mask-image` to local relative paths. |

### Not Implemented (Deferred)

| # | Finding | Reason |
|---|---------|--------|
| F5 | Multiple `getDB()` calls in `admin.php` | `getDB()` uses `static $pdo` singleton — no real overhead. Refactor deferred. |
| F6 | Lazy-load admin logs tab via AJAX | Requires significant admin panel JS refactor. Deferred until needed. |
| F11 (partial) | Dual idle timeout tracking (main + renderer) | Requires IPC protocol changes. Current renderer countdown is synced well enough for kiosk use. |
| F12 | Duplicate CSS variables across stylesheets | Separate deployments (admin vs Electron); shared source requires a build step. Documented only. |