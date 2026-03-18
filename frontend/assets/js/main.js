'use strict';

const {
    app, BrowserWindow, BrowserView,
    globalShortcut, ipcMain, net,
    powerSaveBlocker, screen, session,
} = require('electron');
const path = require('path');

app.commandLine.appendSwitch('ignore-gpu-blocklist'); // Sorunlu driverları yoksay ve GPU kullanmaya zorla
app.commandLine.appendSwitch('enable-gpu-rasterization'); // GPU rasterization aç
app.commandLine.appendSwitch('enable-zero-copy'); // RAM-GPU arası doğrudan veri kopyalama yetkisi
app.commandLine.appendSwitch('disable-smooth-scrolling'); // Ekstra işlem yükünü önlemek için pürüzsüz kaydırmayı kapat
app.commandLine.appendSwitch('enable-features', 'CanvasOopRasterization,UseSkiaRenderer'); // 2D çizim hızlandırma
app.commandLine.appendSwitch('disable-features', 'CalculateNativeWinOcclusion,TranslateUI'); // Gereksiz arka plan hesaplamalarını kapat
app.commandLine.appendSwitch('overscroll-history-navigation', '0'); // 3-parmak kaydırma (geri/ileri) jestlerini kapat


/* --- Kiosk izin verilen siteler --- */
const ALLOWED_DOMAINS = [
    'silivri.bel.tr', 'www.silivri.bel.tr',
    'turkiye.gov.tr', 'www.turkiye.gov.tr',
    'e-devlet.gov.tr', 'cbddo.gov.tr',
    'eczaneler.gen.tr', 'www.eczaneler.gen.tr',
    'maps.google.com',
    'localhost', '127.0.0.1', 'kiosk.codewithmad.com',
];

/* --- Reklam engelleme listesi --- */
const AD_BLOCK_LIST = [
    '*://*.doubleclick.net/*', '*://partner.googleadservices.com/*', '*://*.googlesyndication.com/*',
    '*://*.google-analytics.com/*', '*://*.googletagmanager.com/*', '*://*.googletagservices.com/*',
    '*://creative.ak.fbcdn.net/*', '*://*.facebook.net/*', '*://connect.facebook.net/*',
    '*://*.adbrite.com/*', '*://*.exponential.com/*', '*://*.quantserve.com/*',
    '*://*.scorecardresearch.com/*', '*://*.zedo.com/*', '*://*.yandex.ru/metrika/*',
    '*://*.hotjar.com/*', '*://*.criteo.com/*', '*://*.criteo.net/*',
    '*://*.taboola.com/*', '*://*.outbrain.com/*', '*://*.adsafeprotected.com/*',
    '*://*.rubiconproject.com/*', '*://*.amazon-adsystem.com/*',
];

/* --- API adresi --- */
const API_BASE = 'http://kiosk.codewithmad.com/api.php';

/* --- Navbar yüksekliği --- */
const NAVBAR_HEIGHT = 100;

/* --- Oturum süresi --- */
const IDLE_TIMEOUT_MS = 2 * 60 * 1000;

/* --- Ana pencere --- */
let mainWindow = null;

/* --- Aktif BrowserView --- */
let activeBrowserView = null;

/* --- Boşta kalma timeri --- */
let idleTimer = null;

/* --- Güç koruma bloke ID --- */
let powerBlockerId = null;

/* --- Temiz kapanma bayrağı (F23) --- */
let allowQuit = false;

/* --- URL izin kontrolü --- */
function isAllowedUrl(urlString) {
    try {
        const { hostname } = new URL(urlString);
        return ALLOWED_DOMAINS.some(d => hostname === d || hostname.endsWith(`.${d}`));
    } catch { return false; }
}

/* --- Boşta kalma timerini sıfırlama --- */
function resetIdleTimer() {
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(handleIdleTimeout, IDLE_TIMEOUT_MS);
}

/* --- Boşta kalma timerini sıfırlama --- */
async function handleIdleTimeout() {
    await destroyActiveBrowserView(true);
    mainWindow?.webContents.send('idle-timeout');
}

/* --- Aktif BrowserView'ı temizleme --- */
async function destroyActiveBrowserView(clearSess = false) {
    if (!activeBrowserView) return;
    const view = activeBrowserView;
    activeBrowserView = null;
    if (mainWindow && !mainWindow.isDestroyed()) mainWindow.removeBrowserView(view);
    if (clearSess) {
        const sess = view.webContents.session;
        await Promise.all([
            // SADECE kullanıcı özel verilerini sil (cookie, login, localStorage vb)
            // 'caches' öğesini bilerek siliyoruz KI static dosyalar (harita, resim) önbellekte kalsın ve anında açılsın.
            sess.clearStorageData({ storages: ['appcache', 'cookies', 'filesystem', 'indexdb', 'localstorage', 'websql', 'serviceworkers'] }),
            sess.clearAuthCache(),
        ]).catch(() => { });
    }
    try { view.webContents.destroy(); } catch { }
    mainWindow?.webContents.send('browserview-closed');
}

/* --- Yeni URL açma --- */
async function openInBrowserView(url, id) {
    if (!isAllowedUrl(url)) {
        mainWindow?.webContents.send('url-blocked', url);
        return;
    }
    await destroyActiveBrowserView(false);
    const { width, height } = mainWindow.getContentBounds();
    activeBrowserView = new BrowserView({
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            sandbox: true,
            backgroundThrottling: false,
            enableWebSQL: false,
            spellcheck: false,
        },
    });

    mainWindow.addBrowserView(activeBrowserView);
    activeBrowserView.setBounds({ x: 0, y: NAVBAR_HEIGHT, width, height: height - NAVBAR_HEIGHT });
    activeBrowserView.setAutoResize({ width: true, height: true });
    mainWindow?.webContents.send('browserview-opened', url);
    activeBrowserView.webContents.setWindowOpenHandler(({ url: u }) => {
        if (isAllowedUrl(u)) activeBrowserView?.webContents.loadURL(u);
        return { action: 'deny' };
    });
    activeBrowserView.webContents.on('will-navigate', (e, u) => {
        if (!isAllowedUrl(u)) { e.preventDefault(); mainWindow?.webContents.send('url-blocked', u); }
        resetIdleTimer();
    });
    activeBrowserView.webContents.on('will-redirect', (e, u) => { if (!isAllowedUrl(u)) e.preventDefault(); });
    activeBrowserView.webContents.on('before-input-event', (event, input) => {
        resetIdleTimer();
        const block = [
            input.key === 'F12', input.key === 'F5',
            (input.control || input.meta) && input.shift && ['I', 'J'].includes(input.key),
            (input.control || input.meta) && ['r', 'R', 'u', 'p'].includes(input.key),
            input.alt && input.key === 'F4',
        ];
        if (block.some(Boolean)) event.preventDefault();
    });
    activeBrowserView.webContents.loadURL(url).catch(() => { });
    fetch(`${API_BASE}?action=log&url=${encodeURIComponent(url)}&url_id=${id || ''}`, { signal: AbortSignal.timeout(5000) }).catch(() => { });
}

ipcMain.handle('open-url', async (_e, url, id) => { resetIdleTimer(); await openInBrowserView(url, id); return { success: true }; });
ipcMain.handle('go-home', async () => { await destroyActiveBrowserView(true); resetIdleTimer(); return { success: true }; });
ipcMain.on('user-activity', resetIdleTimer);



ipcMain.handle('fetch-api', async (_e, { action }) => {
    try {
        const resp = await net.fetch(`${API_BASE}?action=${encodeURIComponent(action || 'urls')}`, {
            signal: AbortSignal.timeout(10000),
        });
        const data = await resp.json();
        return data;
    } catch (err) {
        return { success: false, error: err.message };
    }
});

function createMainWindow() {
    const display = screen.getPrimaryDisplay();
    const { width, height } = display.bounds;
    const isDev = process.argv.includes('--dev');
    mainWindow = new BrowserWindow({
        width, height,
        x: display.bounds.x, y: display.bounds.y,
        fullscreen: !isDev,
        kiosk: !isDev,
        alwaysOnTop: !isDev,
        skipTaskbar: !isDev,
        frame: isDev, resizable: isDev, movable: isDev,
        minimizable: true, maximizable: true, closable: true,
        icon: path.join(__dirname, '..', '..', 'assets', 'img', 'logo.ico'),
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            preload: path.join(__dirname, '..', '..', 'assets', 'js', 'preload.js'),
        },
        backgroundColor: '#f0f3f8',
        show: false,
    });

    if (!isDev) {
        mainWindow.setAlwaysOnTop(true, 'screen-saver');
    }

    const GEO_ALLOWED_ORIGINS = [
        'https://maps.google.com',
        'https://www.google.com',
    ];
    mainWindow.webContents.session.setPermissionRequestHandler(
        (webContents, permission, callback) => {
            try {
                const origin = new URL(webContents.getURL()).origin;
                if (permission === 'geolocation' &&
                    GEO_ALLOWED_ORIGINS.some(o => origin === o || origin.endsWith('.' + o.replace(/^https?:\/\//, '')))) {
                    return callback(true);
                }
            } catch { /* geçersiz URL */ }
            callback(false);
        }
    );

    mainWindow.loadFile(path.join(__dirname, '..', '..', 'index.html'));
    mainWindow.once('ready-to-show', () => {
        mainWindow.show(); mainWindow.focus(); resetIdleTimer();
        if (process.argv.includes('--dev')) mainWindow.webContents.openDevTools();
    });
    if (!process.argv.includes('--dev')) {
        mainWindow.webContents.on('devtools-opened', () => mainWindow.webContents.closeDevTools());
    }
    mainWindow.webContents.on('before-input-event', () => resetIdleTimer());
    mainWindow.on('resize', () => {
        if (activeBrowserView && !mainWindow.isDestroyed()) {
            const { width: w, height: h } = mainWindow.getContentBounds();
            activeBrowserView.setBounds({ x: 0, y: NAVBAR_HEIGHT, width: w, height: h - NAVBAR_HEIGHT });
        }
    });
    mainWindow.on('close', e => {
        if (!allowQuit) e.preventDefault();
    });
}

app.whenReady().then(() => {
    powerBlockerId = powerSaveBlocker.start('prevent-display-sleep');

    session.defaultSession.webRequest.onBeforeRequest(
        { urls: AD_BLOCK_LIST },
        (details, callback) => callback({ cancel: true })
    );

    const shortcuts = [
        'Alt+F4', 'Alt+Tab', 'Alt+Escape',
        'Ctrl+W', 'Ctrl+F4', 'Ctrl+Shift+I', 'Ctrl+Shift+J', 'Ctrl+Shift+C',
        'Ctrl+U', 'Ctrl+R', 'Ctrl+F5', 'Ctrl+P',
        'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
        'Super', 'CommandOrControl+Escape',
    ];
    shortcuts.forEach(s => { try { globalShortcut.register(s, () => { }); } catch { } });

    app.on('web-contents-created', (e, contents) => {
        contents.on('context-menu', (event) => event.preventDefault());
    });

    createMainWindow();
});

app.on('window-all-closed', e => e.preventDefault());
app.on('before-quit', () => {
    allowQuit = true;
    if (idleTimer) clearTimeout(idleTimer);
    if (powerBlockerId !== null) powerSaveBlocker.stop(powerBlockerId);
    globalShortcut.unregisterAll();
});
