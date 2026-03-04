# T.C. Silivri Belediyesi — Kiosk Sistemi (Dijital Hizmet Noktası)

**Kapsamlı Geliştirici ve Sistem Mimarisi Dokümantasyonu**

Bu belge, Silivri Belediyesi Hizmet Binaları'nda kullanılmak üzere geliştirilen kapalı devre Kiosk (Dijital Hizmet Noktası) uygulamasının **uçtan uca mimarisini**, güvenlik altyapısını, iletişim protokollerini, veritabanı şemasını, optimizasyon süreçlerini ve saha dağıtım (deployment) adımlarını barındırmaktadır.

> **Kritik Uyarı:** Mevcut sistemin güvenliği ve state mantığı son derece kritik olduğundan, kaynak kodlarına müdahale edecek geliştiricilerin bu dokümanı ve `AGENTS.md` dosyasını eksiksiz okuması zorunludur.

---

## İçindekiler

1. [Genel Bakış](#1-genel-bakış)
2. [Teknoloji Yığını](#2-teknoloji-yığını)
3. [Proje Dizin Yapısı](#3-proje-dizin-yapısı)
4. [Sistem Mimarisi](#4-sistem-mimarisi)
5. [Electron.js (Frontend) Katmanı](#5-electronjs-frontend-katmanı)
6. [Backend (PHP) Katmanı](#6-backend-php-katmanı)
7. [Veritabanı Şeması](#7-veritabanı-şeması)
8. [Güvenlik Mimarisi](#8-güvenlik-mimarisi)
9. [Performans Optimizasyonları](#9-performans-optimizasyonları)
10. [Çözülen Temel Krizler](#10-çözülen-temel-krizler)
11. [Kurulum ve Derleme](#11-kurulum-ve-derleme)
12. [Saha Dağıtımı (Deployment)](#12-saha-dağıtımı-deployment)
13. [Geliştirme Rehberi](#13-geliştirme-rehberi)
14. [Sorun Giderme (Troubleshooting)](#14-sorun-giderme-troubleshooting)
15. [Gelecek Vizyonu](#15-gelecek-vizyonu)
16. [İlgili Dokümanlar](#16-ilgili-dokümanlar)

---

## 1. Genel Bakış

**Silivri Belediyesi Kiosk Sistemi**, belediye hizmet binalarına yerleştirilen dokunmatik terminallerde (kiosk cihazlarında) vatandaşların e-Devlet, belediye e-hizmetleri, kent rehberi, kültür sanat etkinlikleri gibi dijital hizmetlere güvenli ve kolay şekilde erişmesini sağlayan bir masaüstü uygulamasıdır.

### Temel Özellikler

| Özellik | Açıklama |
|---------|----------|
| **Tam Ekran Kiosk Modu** | İşletim sistemi kısayolları (Alt+F4, Alt+Tab, Ctrl+Shift+I vb.) bloke edilir; kullanıcı yalnızca izin verilen sitelere erişebilir |
| **Otomatik Oturum Temizliği** | 2 dakika hareketsizlik sonrası cookie, localStorage, IndexDB otomatik silinir — önceki vatandaşın session bilgileri korunmaz |
| **Dinamik Hizmet Kartları** | Yönetim panelinden eklenen/düzenlenen hizmetler kiosk ekranında otomatik güncellenir |
| **Reklam Engelleme** | DoubleClick, Google Analytics, Facebook, Taboola gibi ağlar çekirdek network katmanında bloke edilir |
| **Çevrimdışı Dayanıklılık** | Fontlar ve logolar yerel olarak barındırılır; internet kesilse bile ana ekran render olur |
| **Güvenlik Denetimi** | CSP sıkılaştırma, SRI hash doğrulama, CSRF koruması, rate limiting uygulanmıştır |
| **Erişim Günlüğü** | Her hizmet tıklaması veritabanına loglanır; 90 gün sonra otomatik temizlenir |
| **Sürükle-Bırak Sıralama** | Admin panelinde hizmet kartlarının sırası sürükle-bırak ile anında değiştirilir |

### Uygulama Akışı (Özet)

```
Vatandaş Dokunma → Splash Screen kapanır → Ana Menü açılır → Hizmet Kartı tıklanır
→ BrowserView içinde izin verilen site açılır → 2dk hareketsizlik → Oturum temizlenir
→ Splash Screen'e dönülür (bir sonraki vatandaş için temiz ortam)
```

---

## 2. Teknoloji Yığını

| Katman | Teknoloji | Versiyon | Açıklama |
|--------|-----------|----------|----------|
| **Runtime** | Electron | 29.x | Chromium tabanlı masaüstü uygulama çatısı |
| **Dil (Frontend)** | JavaScript (ES2020+) | — | Strict mode, async/await, optional chaining |
| **Dil (Backend)** | PHP | 8.0.30 | Strict types, PDO, match/switch |
| **Veritabanı** | MariaDB | 10.4.32 | InnoDB, UTF8MB4, scheduled events |
| **Web Sunucusu** | Apache (XAMPP) | — | mod_rewrite, .htaccess |
| **CSS Framework** | — | — | Sıfırdan yazılmış, CSS Custom Properties tabanlı |
| **İkon Kütüphanesi** | Bootstrap Icons | 1.11.3 | CDN + SRI hash ile yüklenir |
| **Font** | Manrope | Variable | WOFF2, yerel barındırma (self-hosted) |
| **Sürükle-Bırak** | SortableJS | 1.15.2 | CDN + SRI hash ile yüklenir (yalnızca admin panel) |
| **UI (Admin)** | Bootstrap | 5.3.2 | CDN + SRI hash (yalnızca admin panel) |
| **Build** | electron-builder | 24.9.x | NSIS (Windows) / AppImage (Linux) |

---

## 3. Proje Dizin Yapısı

```
kiosk-sistem/
├── AGENTS.md                          # AI agent kılavuzu — kısıtlar ve konvansiyonlar
├── OPTIMIZATIONS.md                   # Performans optimizasyon denetim raporu (25 bulgu)
├── SECURITY_AUDIT_REPORT.md           # Güvenlik denetim raporu (7 bulgu)
├── README.md                          # Bu dosya
├── .gitignore                         # config.php, node_modules, .DS_Store
│
├── backend/                           # PHP REST API + Yönetim Paneli
│   ├── api.php                        # Halka açık JSON API (GET only)
│   ├── admin.php                      # Yönetim paneli (login + CRUD + ayarlar + loglar)
│   ├── db.php                         # PDO bağlantı yöneticisi (singleton)
│   ├── config.php                     # DB kimlik bilgileri (git-ignored)
│   ├── config.example.php             # Kimlik bilgisi şablonu
│   ├── silivri_kiosk.sql              # Tam veritabanı şeması + seed data
│   └── assets/
│       ├── css/
│       │   └── style.css              # Admin panel stilleri
│       ├── img/                       # Admin panel görselleri
│       └── js/
│           └── admin.js               # Admin panel istemci JS (tab, sort, form)
│
└── electron-client/                   # Electron masaüstü uygulaması
    ├── package.json                   # Electron bağımlılıkları + build yapılandırması
    ├── index.html                     # Kiosk HTML kabuğu (CSP korumalı)
    └── assets/
        ├── css/
        │   └── style.css              # Kiosk UI stilleri
        ├── fonts/
        │   ├── Manrope-Variable.woff2       # Latin subset (~24KB)
        │   └── Manrope-Variable-ext.woff2   # Latin-ext subset — Türkçe karakterler (~8KB)
        ├── img/
        │   ├── logo.ico               # Uygulama ikonu
        │   ├── new-logo.svg           # Belediye logosu (self-hosted)
        │   └── silivribirlikteguzel.svg # Footer amblem (self-hosted)
        └── js/
            ├── main.js                # Ana süreç — pencere, güvenlik, IPC, BrowserView (270 satır)
            ├── preload.js             # Context bridge — IPC API yüzeyi (18 satır)
            └── renderer.js            # UI mantığı — kartlar, saat, idle, ekranlar (207 satır)
```

---

## 4. Sistem Mimarisi

Sistem birbiriyle izole ancak tam entegre çalışan **iki ana katmandan** oluşmaktadır:

1. **Frontend (Electron Client):** Windows işletim sisteminde "Zırhlı (Kiosk Mode)" çalışan, işletim sistemi fonksiyonlarını bastıran, `BrowserView` kullanarak sanallaştırılmış izolasyon (sandbox) sağlayan Node.js / Electron.js bazlı masaüstü uygulamasıdır.
2. **Backend (PHP & MySQL API):** Hangi adreslere izin verileceğinin belirlendiği, erişim kayıtlarının (log) ve kiosk konfigürasyonlarının dinamik olarak sağlandığı hafif (lightweight) REST API ve yönetim paneli katmanıdır.

Mimaride "Stateless" bir REST API tercih edilmiştir. İstemci, verileri periyodik veya ihtiyaç anında çeker (`fetch-api` IPC Handler).

### Mimari Diyagram

```
┌─────────────────────────────────────────────────────────┐
│                   ELECTRON CLIENT                        │
│                                                          │
│  ┌──────────┐     ┌──────────┐     ┌──────────────────┐ │
│  │ main.js  │◄───►│preload.js│◄───►│  renderer.js     │ │
│  │ (Main    │ IPC │(Context  │ API │  (UI Katmanı)    │ │
│  │ Process) │     │ Bridge)  │     │                  │ │
│  └────┬─────┘     └──────────┘     └──────────────────┘ │
│       │                                                  │
│       │ net.fetch()                                      │
│       ▼                                                  │
│  ┌──────────────────┐                                    │
│  │  BrowserView     │  ◄─── İzin verilen siteler         │
│  │  (Sandbox: true) │       (ALLOWED_DOMAINS)            │
│  └──────────────────┘                                    │
└─────────────────────────────┬───────────────────────────┘
                              │ HTTP GET
                              ▼
┌─────────────────────────────────────────────────────────┐
│                     BACKEND (PHP)                        │
│                                                          │
│  ┌──────────┐     ┌──────────┐     ┌──────────────────┐ │
│  │ api.php  │────►│  db.php  │────►│  MariaDB         │ │
│  │ (REST)   │     │  (PDO)   │     │  silivri_kiosk   │ │
│  └──────────┘     └──────────┘     └──────────────────┘ │
│                                                          │
│  ┌──────────────────────────────────────────────────────┐│
│  │ admin.php  (Yönetim Paneli — ayrı web arayüzü)     ││
│  │ Bootstrap 5.3.2 + SortableJS 1.15.2                ││
│  └──────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────┘
```

### Veri Akış Diyagramı

```
1. Kiosk Başlatma:
   renderer.js → kioskAPI.fetchApi({action:'urls'})
     → preload.js (IPC invoke 'fetch-api')
       → main.js (net.fetch → API_BASE?action=urls)
         → api.php → MariaDB → JSON yanıt
           → main.js → preload.js → renderer.js → DOM kartları

2. Hizmet Tıklama:
   renderer.js → kioskAPI.openUrl(url, id)
     → preload.js (IPC invoke 'open-url')
       → main.js (isAllowedUrl → BrowserView.loadURL)
         → main.js (fetch → api.php?action=log&url=...&url_id=...)

3. Idle Timeout:
   main.js (2dk timer) → handleIdleTimeout()
     → destroyActiveBrowserView(clearSess=true)
       → session.clearStorageData() — cookie/localStorage/IndexDB temizle
     → IPC send 'idle-timeout' → renderer.js → showScreen('splash')
```

### Neden Doğrudan `fetch()` Yerine IPC?

Electron'un güvenlik modelinde renderer process **sandbox içinde** çalışır (`sandbox: true`, `contextIsolation: true`). Renderer'dan doğrudan `http://127.0.0.1` adresine fetch yapmak:

1. CSP `connect-src` ihlali oluşturur
2. `file://` origin'den yapılan isteklerde CORS sorunları çıkar
3. Renderer'a ağ erişimi vermek saldırı yüzeyini genişletir

Bu nedenle tüm API çağrıları **main process'teki `net.fetch()`** üzerinden geçer. Renderer yalnızca `window.kioskAPI` arayüzünü (preload.js tarafından expose edilen) kullanır.

---

## 5. Electron.js (Frontend) Katmanı

### 5.1 Dosya Yapısı ve Entry Point

Electron entrypoint'i `electron-client/package.json` → `"main": "assets/js/main.js"` şeklinde yapılandırılmıştır. Standart Electron projelerinin aksine JS dosyaları `assets/js/` alt dizinindedir.

Bu nedenle `main.js` içindeki tüm `path.join()` çağrıları **iki seviye yukarı** çıkarak `index.html` ve `preload.js`'ye ulaşır:

```javascript
// main.js içinden index.html'ye erişim:
mainWindow.loadFile(path.join(__dirname, '..', '..', 'index.html'));

// preload.js yolu:
preload: path.join(__dirname, '..', '..', 'assets', 'js', 'preload.js'),
```

> **Gotcha:** `main.js`'i taşımak, `package.json`'daki `main` alanını ve tüm `path.join(...)` referanslarını güncellemeyi gerektirir. Aksi halde "White Screen of Death" oluşur.

### 5.2 Kiosk İzolasyonu ve Güvenlik

Kiosk cihazının işletim sistemi fonksiyonlarına erişimi **çok katmanlı** olarak engellenir:

#### a) Global Kısayol Blokajı

```javascript
const shortcuts = [
    'Alt+F4', 'Alt+Tab', 'Alt+Escape',
    'Ctrl+W', 'Ctrl+F4', 'Ctrl+Shift+I', 'Ctrl+Shift+J', 'Ctrl+Shift+C',
    'Ctrl+U', 'Ctrl+R', 'Ctrl+F5', 'Ctrl+P',
    'F1'...'F12', 'Super', 'CommandOrControl+Escape',
];
shortcuts.forEach(s => globalShortcut.register(s, () => {}));
```

Bu kısayollar `app.whenReady()` içinde kaydedilir ve boş fonksiyona bağlanarak işletim sistemi interrupt'ları etkisiz hale getirilir.

#### b) Pencere Koruması

| Özellik | Ayar | Açıklama |
|---------|------|----------|
| `fullscreen` | `true` (prod) | Tam ekran kiosk modu |
| `kiosk` | `true` (prod) | OS görev çubuğunu gizler |
| `alwaysOnTop` | `'screen-saver'` seviyesi | Start Menu ve Edge Panel'i bile ezer |
| `skipTaskbar` | `true` | Görev çubuğunda görünmez |
| `frame` | `false` (prod) | Pencere çerçevesi yok |
| `resizable` | `false` (prod) | Boyutlandırılamaz |
| `movable` | `false` (prod) | Sürüklenemez |

#### c) DevTools Koruması

```javascript
// Üretim modda DevTools açıldığında otomatik kapanır
mainWindow.webContents.on('devtools-opened', () => mainWindow.webContents.closeDevTools());
```

Ayrıca BrowserView içindeki `before-input-event`'te F12, Ctrl+Shift+I/J gibi DevTools kısayolları da bloke edilir.

#### d) Sağ Tık Engelleme

```javascript
// Main process — global
app.on('web-contents-created', (e, contents) => {
    contents.on('context-menu', (event) => event.preventDefault());
});

// Renderer — document seviyesi
document.addEventListener('contextmenu', e => e.preventDefault());
```

#### e) Temiz Kapanma (Quit Mekanizması)

`mainWindow.on('close')` olayı varsayılan olarak engellenir (kiosk koruması). Uygulamayı kapatmak için:

```javascript
let allowQuit = false;

app.on('before-quit', () => {
    allowQuit = true;                              // Bayrak aç
    if (idleTimer) clearTimeout(idleTimer);         // Timer temizle
    if (powerBlockerId !== null) powerSaveBlocker.stop(powerBlockerId);
    globalShortcut.unregisterAll();                 // Kısayolları serbest bırak
});

mainWindow.on('close', e => {
    if (!allowQuit) e.preventDefault();             // Bayrak kapalıysa engelle
});
```

Kapatmak için terminalden `Ctrl+C` veya `app.quit()` çağrısı gerekir.

#### f) Geliştirme Modu

`--dev` argümanı ile başlatıldığında tüm kısıtlamalar gevşetilir:

```bash
npm start -- --dev
```

Bu modda: pencere çerçeveli, boyutlandırılabilir, sürüklenebilir; DevTools otomatik açılır; `alwaysOnTop` kapalı.

### 5.3 GPU Hızlandırma ve Chromium Flagleri

Kiosk cihazları 7/24 açık kaldığından GPU Memory Leak ve DOM reflow/repaint yükleri Chromium argümanlarıyla kalibre edilmiştir:

```javascript
app.commandLine.appendSwitch('ignore-gpu-blocklist');          // Sorunlu GPU driverlarını yoksay
app.commandLine.appendSwitch('enable-gpu-rasterization');      // 2D çizimleri GPU'ya aktar
app.commandLine.appendSwitch('enable-zero-copy');              // RAM↔VRAM arası zero-copy
app.commandLine.appendSwitch('disable-smooth-scrolling');      // Gereksiz animasyonları kapat
app.commandLine.appendSwitch('enable-features',
    'CanvasOopRasterization,UseSkiaRenderer');                 // Skia renderer ile 2D hızlandırma
app.commandLine.appendSwitch('disable-features',
    'CalculateNativeWinOcclusion,TranslateUI');                // Arka plan hesaplamalarını kapat
app.commandLine.appendSwitch('overscroll-history-navigation', '0'); // Dokunmatik geri/ileri jestini kapat
```

Ek olarak `powerSaveBlocker.start('prevent-display-sleep')` ile ekranın kapanması veya uyku moduna geçmesi engellenir.

### 5.4 BrowserView Mimarisi

Hizmet kartı tıklandığında ilgili site **BrowserView** içinde açılır — iframe yerine. BrowserView, izole bir Chromium render süreci sağlar.

```
┌──────────────────────────────────────────────┐
│ mainWindow (BrowserWindow)                    │
│ ┌──────────────────────────────────────────┐ │
│ │ Navbar (100px) — Ana Menü butonu + Timer │ │
│ ├──────────────────────────────────────────┤ │
│ │                                          │ │
│ │         BrowserView (sandboxed)          │ │
│ │         izin verilen site render         │ │
│ │                                          │ │
│ └──────────────────────────────────────────┘ │
└──────────────────────────────────────────────┘
```

#### BrowserView Güvenlik Ayarları

```javascript
new BrowserView({
    webPreferences: {
        nodeIntegration: false,       // Node.js API'sine erişim kapalı
        contextIsolation: true,       // Renderer JS'ten tam izolasyon
        sandbox: true,                // OS erişimi tamamen yok
        backgroundThrottling: false,  // Arka planda haritayı dondurma
        enableWebSQL: false,          // Eski/güvensiz API'leri kapat
        spellcheck: false,            // Yazım denetimi gereksiz (kiosk)
    },
});
```

#### URL Doğrulama (Allowlisting)

Her navigasyon olayı (`will-navigate`, `will-redirect`, `setWindowOpenHandler`) `isAllowedUrl()` fonksiyonundan geçer:

```javascript
const ALLOWED_DOMAINS = [
    'silivri.bel.tr', 'www.silivri.bel.tr',     // Belediye Ana Site
    'turkiye.gov.tr', 'www.turkiye.gov.tr',       // e-Devlet
    'e-devlet.gov.tr', 'cbddo.gov.tr',           // e-Devlet alt domainler
    'eczaneler.gen.tr', 'www.eczaneler.gen.tr',   // Nöbetçi Eczane
    'maps.google.com',                            // Harita
    'localhost', '127.0.0.1',                     // Lokal API
];

function isAllowedUrl(urlString) {
    const { hostname } = new URL(urlString);
    return ALLOWED_DOMAINS.some(d => hostname === d || hostname.endsWith(`.${d}`));
}
```

Alt domain desteği otomatik dahildir: `ebys.silivri.bel.tr`, `360.silivri.bel.tr`, `kultursanat.silivri.bel.tr`, `tesis.silivri.bel.tr` vb. otomatik olarak izin verilir.

### 5.5 Reklam ve Tracker Engelleme

Kamu ağını gereksiz yormamak ve sayfa render hızını artırmak için Chromium'un `webRequest.onBeforeRequest` API'si **session seviyesinde bir kez** kaydedilir:

```javascript
// app.whenReady() içinde tek seferlik kayıt
session.defaultSession.webRequest.onBeforeRequest(
    { urls: AD_BLOCK_LIST },
    (details, callback) => callback({ cancel: true })
);
```

**Engellenen Ağlar:** DoubleClick, GoogleAdServices, GoogleSyndication, Google Analytics, Google Tag Manager, Facebook Ad Network, AdBrite, Exponential, QuantServe, ScoreCardResearch, Zedo, Yandex Metrika, Hotjar, Criteo, Taboola, Outbrain, AdSafe Protected, Rubicon Project, Amazon Ad System.

> **Not:** Önceki sürümde her BrowserView oluşturulduğunda yeniden kaydediliyordu (handler birikmesi riski). Artık session düzeyinde tek seferlik kaydedilir.

### 5.6 IPC (Inter-Process Communication) Köprüsü

#### Preload.js — Güvenli API Yüzeyi

`contextBridge.exposeInMainWorld()` ile renderer'a sunulan arayüz:

| Metod | IPC Kanalı | Yön | Açıklama |
|-------|-----------|-----|----------|
| `kioskAPI.openUrl(url, id)` | `invoke('open-url')` | Renderer → Main | BrowserView'da site aç |
| `kioskAPI.goHome()` | `invoke('go-home')` | Renderer → Main | BrowserView kapat, ana menüye dön |
| `kioskAPI.notifyActivity()` | `send('user-activity')` | Renderer → Main | Idle timer'ı sıfırla |
| `kioskAPI.fetchApi(params)` | `invoke('fetch-api')` | Renderer → Main | API'ye istek at (main process üzerinden) |
| `kioskAPI.on(event, cb)` | `ipcRenderer.on()` | Main → Renderer | Olay dinleyici kaydet |

#### İzin Verilen IPC Olayları (Main → Renderer)

| Olay | Tetiklenme Anı | Renderer'da Yapılan |
|------|----------------|---------------------|
| `browserview-opened` | BrowserView URL yüklendiğinde | Navbar göster, hostname yaz |
| `browserview-closed` | BrowserView kapatıldığında | Navbar gizle |
| `url-blocked` | İzin verilmeyen URL engellendiğinde | Toast bildirimi: "Erişim kısıtlandı" |
| `idle-timeout` | 2dk hareketsizlik sonrası oturum kapatıldığında | Splash screen'e dön |

> **Güvenlik:** `on()` fonksiyonu yalnızca allowlist'teki 4 olayı kabul eder. `kioskAPI.on('arbitrary-event', ...)` çağrısı sessizce reddedilir.

### 5.7 Oturum Yönetimi ve Idle Timeout

Sistemin en can alıcı noktalarından biri **"Unutkan Kiosk"** özelliğidir. Bir vatandaş e-Devlet kapısında işlemini yarıda bırakıp giderse, önceki vatandaşın session token'ları ve verileri temizlenmelidir.

#### Zamanlama

```
Vatandaş dokunma/yazma → notifyActivity()
  → Main: resetIdleTimer() — 2dk setTimeout
  → Renderer: idleRem = 120s (geri sayım)

Son 15 saniye → Renderer: timeout modal gösterilir
  → "Oturumunuz Kapanıyor — [15] saniye"
  → "İşleme Devam Et" butonu mevcuttur

0 saniye → Main: handleIdleTimeout()
  → destroyActiveBrowserView(clearSess=true)
  → IPC 'idle-timeout' → Renderer: splash screen'e dönüş
```

#### Temizlenen vs Korunan Veriler

| Veri Türü | Temizleniyor mu? | Neden |
|-----------|-------------------|-------|
| Cookies | ✅ Evet | Oturum token'ları (e-Devlet, belediye portal) |
| LocalStorage | ✅ Evet | Kullanıcı tercihleri, form verileri |
| IndexedDB | ✅ Evet | Offline veri depoları |
| WebSQL | ✅ Evet | Eski tarayıcı veritabanı API |
| Service Workers | ✅ Evet | Arka plan iş parçacıkları |
| AppCache | ✅ Evet | Uygulama önbelleği |
| **HTTP Cache** | ❌ **Hayır** | Harita karoları ve statik görseller önbellekte kalır — sonraki vatandaş için anlık açılma |
| **Auth Cache** | ✅ Evet | `session.clearAuthCache()` ile temizlenir |

### 5.8 Content Security Policy (CSP)

`index.html` `<meta>` etiketi ile uygulanan politika:

```
default-src 'self';
script-src  'self';
style-src   'self' 'unsafe-inline' https://cdn.jsdelivr.net;
font-src    'self' data: https://cdn.jsdelivr.net;
img-src     'self' data: https:;
connect-src 'self' http://localhost http://127.0.0.1;
```

| Direktif | Detay |
|----------|-------|
| `script-src 'self'` | **Yalnızca** yerel `.js` dosyaları çalışır. `onclick="..."` gibi inline handler'lar **sessizce bloke** edilir |
| `style-src 'unsafe-inline'` | Bootstrap Icons'un inline font yükleme mekanizması ve dinamik stiller için gerekli |
| `font-src 'self'` | Manrope fontu yerel barındırılıyor; Google Fonts kaldırıldı |
| `connect-src` | Renderer'dan doğrudan API çağrısına izin verir (ancak pratikte IPC kullanılır) |

> **Kritik:** Bu CSP nedeniyle `index.html`'e **asla** inline `onclick`, `oninput` veya `<script>` bloğu eklenmemelidir. Tüm event handler'lar `renderer.js` içinde `addEventListener` ile kaydedilir.

### 5.9 İzin Yönetimi (Permission Handling)

```javascript
const GEO_ALLOWED_ORIGINS = ['https://maps.google.com', 'https://www.google.com'];

session.setPermissionRequestHandler((webContents, permission, callback) => {
    if (permission === 'geolocation' && GEO_ALLOWED_ORIGINS.includes(origin)) {
        callback(true);    // Yalnızca Google Maps için konum izni
    } else {
        callback(false);   // media, notifications, clipboard, diğerleri reddedilir
    }
});
```

| İzin Türü | Durum | Açıklama |
|-----------|-------|----------|
| `geolocation` | ✅ Koşullu | Yalnızca `maps.google.com` origin'i |
| `media` (kamera/mikrofon) | ❌ Reddedildi | Kiosk cihazında mikrofon/kamera kullanılmaz |
| `notifications` | ❌ Reddedildi | Bildirim izni verilmez |
| Diğer tüm izinler | ❌ Reddedildi | Varsayılan ret politikası |

### 5.10 Renderer — UI Katmanı

#### Ekran Yapısı

| Ekran | Element ID | İçerik |
|-------|-----------|--------|
| **Splash Screen** | `#screen-splash` | Belediye logosu (CSS mask) + "DİJİTAL HİZMET NOKTASI" + nabız animasyonu + "BAŞLAMAK İÇİN DOKUNUN" |
| **Ana Menü** | `#screen-main` | Header (logo + saat/tarih/gün) + Hizmet kartları grid + Footer |
| **Navbar** | `#kiosk-navbar` | BrowserView açıkken görünür — "Ana Menü" butonu + idle geri sayım |
| **Toast** | `#toast` | URL bloke bildirimi ("Erişim kısıtlandı") |
| **Timeout Modal** | `#timeout-overlay` | Son 15 saniye uyarısı + "İşleme Devam Et" butonu |

#### Hizmet Kartı Oluşturma

```javascript
function mkCard(svc, i) {
    // <a class="card" data-type="external" style="animation-delay: 60ms*i">
    //   <div class="card-icon-wrap"><i class="bi bi-globe2"></i></div>
    //   <span class="card-label">E-Belediye</span>
    // </a>
}
```

- Kartlar `DocumentFragment` ile toplu olarak DOM'a eklenir (tek paint cycle)
- Her kart kademeli animasyon ile belirir (`animation-delay: i * 60ms`)
- Tıklandığında: idle sıfırlanır → IPC `open-url` → navbar başlığı güncellenir

#### Saat ve Tarih

- Format: `HH:MM` (24 saat) + `4 Mart 2026` + `Salı`
- Türkçe ay ve gün adları (`MONTHS`, `DAYS` dizileri)
- Interval yalnızca `screen-main` aktifken çalışır (splash'ta gereksiz DOM yazımı yok)

#### Güvenlik: CSS Enjeksiyon Koruması

`settings.background_image` değeri CSS `url()` içine yazılmadan önce doğrulanır:

```javascript
const u = new URL(raw);
if (u.protocol !== 'https:') return;  // data:, javascript: şemalarını engelle
const safe = u.href.replace(/"/g, '%22').replace(/[()]/g, encodeURIComponent);
document.getElementById('splash-bg').style.backgroundImage = `url("${safe}")`;
```

---

## 6. Backend (PHP) Katmanı

Backend katmanı geleneksel MVC yapısından ziyade, doğrudan I/O hızına odaklanan `api.php` ve `admin.php` routing dosyalarından oluşur. Her iki dosya `declare(strict_types=1)` ile çalışır.

### 6.1 API Kontratı (`api.php`)

REST API yalnızca **GET** kabul eder. `POST`, `PUT`, `DELETE` → `405 Method Not Allowed`.

#### Yanıt Zarfı (Envelope)

```json
// Başarılı:
{ "success": true, "data": [...] }

// Hata:
{ "success": false, "error": "Mesaj" }
```

> **Kural:** Bu zarf yapısı asla değiştirilmemelidir — Electron fetch handler'ları `success` alanına bağımlıdır.

#### Endpoint'ler

| Action | URL | HTTP | Açıklama | Cache-Control |
|--------|-----|------|----------|---------------|
| `urls` | `?action=urls` | GET | Aktif hizmet kartlarını sıralı listele | `public, max-age=60` |
| `log` | `?action=log&url=...&url_id=...` | GET | Erişim kaydı ekle (INSERT) | `no-store` |
| `settings` | `?action=settings` | GET | Arayüz ayarlarını al | `public, max-age=60` |

#### `?action=urls` — Yanıt Örneği

```json
{
  "success": true,
  "data": [
    {
      "id": 3,
      "title": "Kent Rehberi",
      "url": "https://360.silivri.bel.tr/_keos/",
      "icon_class": "bi bi-house-heart-fill",
      "service_type": "external",
      "sort_order": 1
    },
    {
      "id": 5,
      "title": "E-Belediye",
      "url": "https://ebys.silivri.bel.tr/ebelediye",
      "icon_class": "bi bi-globe2",
      "service_type": "external",
      "sort_order": 2
    }
  ],
  "fetched_at": "2026-03-04T10:30:00+03:00"
}
```

SQL: `SELECT id, title, url, icon_class, service_type, sort_order FROM allowed_urls WHERE is_active = 1 ORDER BY sort_order ASC, id ASC`

#### `?action=log` — Erişim Kaydı

Kiosk her hizmet tıklamasında `url` ve `url_id` parametreleriyle bu endpoint'i çağırır.

**Fallback Mekanizması:** Eğer `url_id` gönderilmezse (ön yüz hatası veya eski istemci), backend URL string'i kullanarak `allowed_urls` tablosunda `SELECT id` ile ilgili kaydı arar ve log'a ilişkilendirir.

```php
if (!$urlId) {
    $stmt = $db->prepare("SELECT id FROM allowed_urls WHERE url = ? LIMIT 1");
    $stmt->execute([$url]);
    $urlId = $stmt->fetchColumn() ?: null;
}
$db->prepare("INSERT INTO access_logs (url_id, url) VALUES (?, ?)")->execute([$urlId, $url]);
```

#### `?action=settings` — Güvenlik Filtresi

**Yalnızca `RENDERER_SAFE_KEYS`** listesindeki ayarlar döndürülür. SQL seviyesinde `WHERE IN` ile filtrelenir — gizli ayarlar PHP belleğine bile alınmaz:

```php
$RENDERER_SAFE_KEYS = ['background_image', 'municipality_logo'];
$placeholders = implode(',', array_fill(0, count($RENDERER_SAFE_KEYS), '?'));
$stmt = $db->prepare("SELECT setting_key, setting_value FROM kiosk_settings WHERE setting_key IN ($placeholders)");
$stmt->execute($RENDERER_SAFE_KEYS);
```

#### Hata Yönetimi

```php
} catch (RuntimeException $e) {
    error_log('[API RuntimeException] ' . $e->getMessage());
    sendJson(503, ['success' => false, 'error' => 'Servis geçici olarak kullanılamıyor.']);
}
```

DSN bileşenleri (host, port, db adı) asla istemciye sızdırılmaz.

### 6.2 Yönetim Paneli (`admin.php`)

Tarayıcı tabanlı tek dosya PHP uygulaması. Login, CRUD, ayarlar, erişim logları ve sürükle-bırak sıralama aynı dosyada bulunur.

#### Kimlik Doğrulama

| Özellik | Detay |
|---------|-------|
| **Hash Algoritması** | `password_hash()` / `password_verify()` — bcrypt ($2y$12) |
| **Session Timeout** | 30 dakika (`SESSION_TIMEOUT = 1800`) |
| **Session Regeneration** | Başarılı girişte `session_regenerate_id(true)` |
| **Cookie Güvenliği** | `HttpOnly: true`, `SameSite: Strict`, `Lifetime: 0` (tarayıcı kapanınca silinir) |
| **Rate Limiting** | 5 başarısız deneme → 15 dakika kilit (session tabanlı sayaç) |
| **CSRF Koruması** | Her formda `csrf_token` doğrulaması (`bin2hex(random_bytes(32))`) |

#### CRUD İşlemleri

| Action | Metod | Açıklama |
|--------|-------|----------|
| `add` | POST (form) | Yeni hizmet kartı ekle (title, url, icon_class, sort_order, is_active) |
| `edit` | POST (form) | Mevcut kartı güncelle |
| `delete` | POST (form) | Kartı sil (JavaScript confirm dialog) |
| `toggle` | POST (form) | Aktif/Pasif durumu değiştir |
| `sort` | POST (JSON body) | Sürükle-bırak sıralama — transaction + max 100 öğe |
| `save_settings` | POST (form) | `background_image`, `municipality_logo` kaydet |

#### Sırala (Sort) Endpoint Detayı

```
POST admin.php?action=sort
Header: Content-Type: application/json
Header: X-CSRF-Token: <token>
Body: [{"id": 3, "order": 1}, {"id": 5, "order": 2}, ...]
```

- **Maximum 100 öğe** — aşarsa `{"success": false, "error": "Çok fazla öğe."}` (abuse koruması)
- `beginTransaction()` + `commit()` ile **atomik** güncelleme (ya hepsi ya hiç)
- İstemci tarafında SortableJS kütüphanesi kullanılır

#### Log Otomatik Temizleme

Her başarılı admin girişinde 90 günden eski erişim logları otomatik silinir:

```php
$db->exec("DELETE FROM access_logs WHERE accessed_at < NOW() - INTERVAL 90 DAY");
```

Bu, MySQL scheduled event ile birlikte çift katmanlı koruma sağlar.

### 6.3 Veritabanı Bağlantısı (`db.php` + `config.php`)

```
config.example.php  ──(kopyala)──►  config.php  ──(require_once)──►  db.php
    (şablon, git'te)                (gerçek bilgiler, .gitignore)     (PDO singleton)
```

- `getDB()` fonksiyonu `static $pdo` ile **singleton** pattern kullanır — kaç kez çağırılırsa çağırılsın tek PDO bağlantısı
- PDO ayarları: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES: false`
- Bağlantı hatası loglanır (`error_log`), istemciye genel `RuntimeException` fırlatılır
- `config.php` dosyası `.gitignore`'da — **asla commit edilmez**. Yoksa uygulama `RuntimeException` fırlatır

### 6.4 CORS Politikası

```php
$allowedOrigins = [
    'http://localhost',
    'http://127.0.0.1',
    'file://',                 // Electron file:// origin
    'null',                    // Electron null origin
    'https://www.silivri.bel.tr',
    'https://silivri.bel.tr',
];
```

| Durum | Davranış |
|-------|----------|
| Origin izin listesinde | `Access-Control-Allow-Origin: <origin>` + `Vary: Origin` eklenir |
| Origin yok veya listede değil | **ACAO başlığı hiç eklenmez** — wildcard `*` KULLANILMAZ |

> **Gotcha:** `file://` ve `null` origin'ler Electron'un file-origin istekleri için zorunludur. Bu değerleri listeden çıkarmak Electron - API iletişimini tamamen kırar.

---

## 7. Veritabanı Şeması

**Veritabanı:** `silivri_kiosk` | **Karakter Seti:** `utf8mb4_unicode_ci` | **Motor:** InnoDB

### Tablo: `allowed_urls` — Hizmet Kartları

| Sütun | Tip | Varsayılan | Açıklama |
|-------|-----|-----------|----------|
| `id` | INT(11) AUTO_INCREMENT | — | PK |
| `title` | VARCHAR(100) NOT NULL | — | Kart başlığı |
| `url` | VARCHAR(500) | `''` | Açılacak URL |
| `image_url` | VARCHAR(500) NULL | NULL | **Legacy** — kullanılmıyor, kaldırılmayacak |
| `icon_class` | VARCHAR(100) | `'bi bi-globe'` | Bootstrap Icons sınıfı |
| `service_type` | ENUM('external','weather','pharmacy','contact') | `'external'` | Servis türü |
| `sort_order` | INT(11) | `99` | Sıralama numarası (küçük = önce) |
| `is_active` | TINYINT(1) | `1` | Aktif/Pasif durumu |
| `created_at` | DATETIME | `NOW()` | Oluşturulma tarihi |
| `updated_at` | DATETIME | `NOW() ON UPDATE` | Son güncelleme |

**İndeksler:** `PRIMARY KEY (id)` · `idx_active_sort (is_active, sort_order, id)` · `idx_url (url(191))`

### Tablo: `access_logs` — Erişim Günlüğü

| Sütun | Tip | Varsayılan | Açıklama |
|-------|-----|-----------|----------|
| `id` | INT(11) AUTO_INCREMENT | — | PK |
| `url_id` | INT(11) NULL | NULL | `allowed_urls.id` referansı |
| `url` | VARCHAR(500) NOT NULL | — | Tıklanan tam URL |
| `accessed_at` | DATETIME | `NOW()` | Erişim zamanı |

**İndeksler:** `PRIMARY KEY (id)` · `idx_accessed_at (accessed_at)` · `idx_url_id (url_id)`

**Otomatik Temizleme:**
```sql
-- MySQL Scheduled Event (EVENT privilege gerektirir)
CREATE EVENT purge_old_access_logs
ON SCHEDULE EVERY 1 DAY
DO DELETE FROM access_logs WHERE accessed_at < NOW() - INTERVAL 90 DAY;
```

### Tablo: `admin_users` — Yönetici Hesapları

| Sütun | Tip | Açıklama |
|-------|-----|----------|
| `id` | INT(11) AUTO_INCREMENT PK | Benzersiz kimlik |
| `username` | VARCHAR(50) UNIQUE | Kullanıcı adı |
| `password` | VARCHAR(255) | bcrypt hash ($2y$12) |
| `created_at` | DATETIME | Oluşturulma tarihi |

### Tablo: `kiosk_settings` — Uygulama Ayarları

| Sütun | Tip | Açıklama |
|-------|-----|----------|
| `setting_key` | VARCHAR(100) PK | Ayar anahtarı |
| `setting_value` | TEXT NULL | Ayar değeri |
| `label` | VARCHAR(200) NULL | İnsan okunabilir etiket |
| `updated_at` | DATETIME | Son güncelleme |

**Mevcut Ayarlar:**

| Anahtar | Açıklama | API'den Döner mi? |
|---------|----------|-------------------|
| `background_image` | Splash ekranı arka plan görseli URL | ✅ Evet (`RENDERER_SAFE_KEYS`) |
| `municipality_logo` | Belediye logosu URL | ✅ Evet (`RENDERER_SAFE_KEYS`) |

### ER Diyagramı

```
┌──────────────┐       ┌──────────────┐
│ allowed_urls │       │ access_logs  │
├──────────────┤       ├──────────────┤
│ id (PK)      │◄──1:N─│ url_id (FK?) │
│ title        │       │ url          │
│ url          │       │ accessed_at  │
│ icon_class   │       └──────────────┘
│ service_type │
│ sort_order   │       ┌──────────────┐
│ is_active    │       │ admin_users  │
└──────────────┘       ├──────────────┤
                       │ id (PK)      │
┌──────────────┐       │ username (UQ)│
│kiosk_settings│       │ password     │
├──────────────┤       └──────────────┘
│setting_key(PK│
│setting_value │
│label         │
└──────────────┘
```

---

## 8. Güvenlik Mimarisi

Ayrıntılı güvenlik denetim raporu: `SECURITY_AUDIT_REPORT.md`

### Uygulanan Güvenlik Kontrolleri

| # | Katman | Kontrol | Açıklama |
|---|--------|---------|----------|
| 1 | **Kimlik Bilgileri** | `config.php` (git-ignored) | DB credentials kaynak kodunda yok |
| 2 | **API Sır Koruması** | `RENDERER_SAFE_KEYS` + SQL `WHERE IN` | Gizli ayarlar DB'den hiç çıkmaz |
| 3 | **XSS (Stored)** | `JSON_HEX_TAG \| JSON_HEX_AMP` | `</script>` breakout engellendi |
| 4 | **XSS (CSS)** | URL protokol doğrulama | `javascript:`, `data:` bloklanır |
| 5 | **CSRF** | `bin2hex(random_bytes(32))` token | Her form/sort isteğinde doğrulama |
| 6 | **CORS** | Origin whitelist | Wildcard `*` hiçbir zaman kullanılmaz |
| 7 | **CSP** | `script-src 'self'` | Inline JS sessizce bloke edilir |
| 8 | **SRI** | `integrity` + `crossorigin` | CDN manipülasyonu tespit edilir |
| 9 | **Brute-force** | 5 deneme → 15dk kilit | Session tabanlı rate limiting |
| 10 | **Session** | `HttpOnly`, `SameSite=Strict` | XSS ile cookie çalınamaz |
| 11 | **Permission** | Origin-scoped handler | Yalnızca Google Maps'e geolocation |
| 12 | **Hata Sızıntısı** | Log + genel mesaj | DSN/stack trace istemciye gitmez |
| 13 | **Electron Sandbox** | `nodeIntegration:false`, `sandbox:true` | BrowserView tamamen izole |
| 14 | **URL Allowlist** | `isAllowedUrl()` + domain kontrol | Her navigasyon denetlenir |
| 15 | **Kiosk Kilidi** | Kısayol + DevTools bloğu | OS fonksiyonları erişilemez |

---

## 9. Performans Optimizasyonları

Ayrıntılı denetim raporu: `OPTIMIZATIONS.md` (25 bulgu, 21 uygulandı, 4 ertelendi).

### Uygulanan Optimizasyonlar Özeti

#### Veritabanı

| Optimizasyon | Detay | Etki |
|-------------|-------|------|
| 90 gün log temizleme | MySQL scheduled event + PHP auto-purge | Sınırsız tablo büyümesi engellendi |
| `idx_url_id` index | `access_logs.url_id` üzerinde | 5-10x hızlı JOIN (admin log sorgusu) |
| `idx_active_sort` index | `allowed_urls(is_active, sort_order, id)` | Covering index — filesort yok |
| `idx_url` prefix index | `allowed_urls.url(191)` | Fallback URL araması hızlandı |

#### Backend PHP

| Optimizasyon | Detay | Etki |
|-------------|-------|------|
| SQL seviyesinde settings filtre | `WHERE setting_key IN (?,?)` | Sırlar DB'den hiç çıkmaz |
| `JSON_PRETTY_PRINT` kaldırma | Yalnızca `JSON_UNESCAPED_UNICODE` | ~%30 küçük API yanıtları |
| Per-action `Cache-Control` | urls/settings: 60s, log: no-store | Tekrar istek azaltma |
| Transaction + limit (sort) | `beginTransaction/commit`, max 100 | Atomik + abuse koruması |
| `SELECT *` → explicit columns | Yalnızca kullanılan sütunlar | Gereksiz veri transferi yok |
| O(1) edit lookup | `array_column($urls, null, 'id')` | O(n) foreach scan yerine |
| `sleep(1)` kaldırma | Rate limiting zaten mevcut | PHP worker 1s erken serbest |

#### Electron Client

| Optimizasyon | Detay | Etki |
|-------------|-------|------|
| Clock interval kontrolü | Yalnızca main screen'de çalışır | Splash'ta gereksiz DOM yazımı yok |
| DocumentFragment | Toplu card DOM insert | Tek paint cycle |
| Dead `API_BASE` kaldırma | Renderer'da kullanılmıyordu | DRY ihlali ve drift riski yok |
| IPC guard | `ipcInitialized` flag | Çift listener kayıt riski yok |
| Session-level ad-blocker | Tek seferlik kayıt | Per-view handler birikmesi yok |
| `allowQuit` flag | Kontrollü kapanma | Bakım için temiz çıkış mekanizması |
| Self-hosted font | Manrope WOFF2 yerel | Google Fonts bağımlılığı yok |
| Self-hosted logolar | SVG yerel | Çevrimdışı logo render |

#### Ertelenen Optimizasyonlar

| Bulgu | Neden |
|-------|-------|
| Birden fazla `getDB()` çağrısı | Singleton pattern — performans etkisi yok |
| Admin logları lazy-load | Önemli admin JS refactoring gerektirir |
| Dual idle timeout birleştirme | IPC protokol değişikliği, mevcut hali yeterli |
| CSS değişken paylaşımı | Ayrı deployment'lar, build step gerektirir |

---

## 10. Çözülen Temel Krizler

### Kriz A: Resim (Image URL) Bağımlılığının Getirdiği Görsel Kirlilik

**Durum:** Kiosk butonlarında her URL için farklı boyut ve çözünürlüklerde resim dosyaları (`image_url`) isteniyordu. Veritabanı yapısı buna göre şekillenmiş, HTML'de `img-card` class'ları ve gradient gölgeler yazılmıştı.

**Çözüm:** Tasarım bütünlüğünü bozan bu yaklaşım tamamen iptal edildi. Flat tasarım prensibiyle **Bootstrap Icons** (`icon_class`) yapısına geçildi. `renderer.js` içindeki JSON parser'ı `image_url` if bloğundan kurtarılarak sadece `div.card-icon-wrap > i.icon_class` basacak kadar hafifletildi. DOM node oluşturma süresi milisaniyelere düştü.

> `image_url` sütunu veritabanında legacy olarak durmaktadır; API ve renderer tarafından kullanılmaz.

### Kriz B: Sürükle-Bırak Sıralamanın PHP'ye Ulaşamaması

**Durum:** `Sortable.js` yeni sırayı JSON payload olarak `fetch('admin.php?action=sort')` endpoint'ine POST ediyordu. Ancak PHP `$_POST` süper globali JSON raw body'yi görmüyordu.

**Çözüm:**
```php
// Sorunlu: Sadece $_POST'a bakıyordu
$action = $_POST['action'] ?? '';

// Düzeltme: URL'den action, body'den payload
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$data = json_decode(file_get_contents('php://input'), true);
```

### Kriz C: Erişim Günlüklerinde "Kayıp ID Paradoksu"

**Durum:** Panel yöneticileri log sayfasında servis adı yerine " - " görüyordu. Electron yalnızca URL gönderiyordu, `url_id` yoktu → JOIN sonucu boş.

**Çözüm:**
1. `renderer.js` → `mkCard()` tıklamasında `svc.id` değerini de IPC'ye aktarması sağlandı
2. PHP'ye fallback eklendi: `url_id` yoksa URL'den `SELECT id FROM allowed_urls WHERE url = ?` ile kendisi bulur

### Kriz D: Dosya Yolları (Path) Kırılmaları — White Screen of Death

**Durum:** JS dosyaları `assets/js/` alt dizinine taşınınca `__dirname` bağlamı değişti → `index.html` bulunamadı.

**Çözüm:**
1. `package.json` entrypoint: `"main": "assets/js/main.js"`
2. `path.join(__dirname, '..', '..', 'index.html')` — iki seviye yukarı çıkma

---

## 11. Kurulum ve Derleme

### 11.1 Ön Gereksinimler

| Yazılım | Minimum Versiyon | Açıklama |
|---------|------------------|----------|
| Node.js | 18.x+ | Electron çalıştırmak için |
| npm | 9.x+ | Paket yöneticisi |
| PHP | 8.0+ | `strict_types`, `match`, named arguments |
| MariaDB / MySQL | 10.4+ / 8.0+ | `EVENT` ayrıcalığı gerekli |
| Apache / Nginx | — | PHP çalıştırabilmeli |

### 11.2 Veritabanı Kurulumu

```bash
# 1. MariaDB/MySQL'e bağlan
mysql -u root -p

# 2. Veritabanını oluştur ve import et
CREATE DATABASE silivri_kiosk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE silivri_kiosk;
SOURCE /path/to/backend/silivri_kiosk.sql;

# 3. Uygulama kullanıcısı oluştur (root KULLANMAYIN!)
CREATE USER 'kiosk_app'@'127.0.0.1' IDENTIFIED BY '<güçlü-rastgele-şifre>';
GRANT SELECT, INSERT, UPDATE, DELETE ON silivri_kiosk.* TO 'kiosk_app'@'127.0.0.1';
FLUSH PRIVILEGES;

# 4. MySQL Event Scheduler'ı aktifleştir (log temizleme event'i için)
SET GLOBAL event_scheduler = ON;
```

> **Kalıcılık:** `/etc/mysql/my.cnf` veya `my.ini` dosyasına `event_scheduler=ON` ekleyerek restart sonrası da aktif kalmasını sağlayın.

### 11.3 PHP Backend Kurulumu

```bash
# 1. Config dosyasını oluştur
cd backend/
cp config.example.php config.php

# 2. config.php'yi düzenle — gerçek bilgileri girin
nano config.php

# 3. API'yi test et
curl -s 'http://127.0.0.1/kiosk-sistem/backend/api.php?action=urls'
# Beklenen: {"success":true,"data":[...],"fetched_at":"..."}

# 4. Settings endpoint test
curl -s 'http://127.0.0.1/kiosk-sistem/backend/api.php?action=settings'
# Beklenen: {"success":true,"data":{"background_image":"...","municipality_logo":"..."}}
```

### 11.4 Electron İstemci Kurulumu

```bash
cd electron-client/

# 1. Bağımlılıkları yükle
npm install

# 2. API adresini kontrol et (gerekirse güncelle)
# electron-client/assets/js/main.js → const API_BASE = '...'
# Varsayılan: http://127.0.0.1/kiosk-sistem/backend/api.php

# 3. Geliştirme modunda başlat (pencereli, DevTools açık)
npm start -- --dev

# 4. Üretim modunda başlat (tam ekran kiosk — dikkat: çıkış zordur!)
npm start
```

> **Uyarı:** Üretim modunda `Alt+Tab` çalışmaz. Çıkmak için terminalden `Ctrl+C` kullanın.

### 11.5 Derleme (Production Build)

```bash
cd electron-client/

# Windows (NSIS Installer — .exe)
npm run build:win

# Linux (AppImage)
npm run build:linux
```

| Platform | Çıktı | Format |
|----------|-------|--------|
| Windows | `dist/T.C. Silivri Belediyesi Kiosk Yazılımı Setup X.X.X.exe` | NSIS one-click installer |
| Linux | `dist/T.C. Silivri Belediyesi Kiosk Yazılımı-X.X.X.AppImage` | Portable AppImage |

Build yapılandırması (`package.json` → `"build"`):
- **App ID:** `tr.bel.silivri.kiosk`
- **Copyright:** `2026 T.C. Silivri Belediyesi Bilgi İşlem Müdürlüğü`
- **NSIS:** One-click, per-machine, masaüstü + başlat menüsü kısayolu

---

## 12. Saha Dağıtımı (Deployment)

### 12.1 Kiosk Cihazı Gereksinimleri

| Bileşen | Minimum | Önerilen |
|---------|---------|----------|
| İşlemci | Intel Celeron / AMD Athlon | Intel i3 / AMD Ryzen 3 |
| Bellek | 4 GB RAM | 8 GB RAM |
| Disk | 32 GB SSD | 64 GB SSD |
| Ekran | 21" Dokunmatik | 27" Kapasitif Dokunmatik |
| İşletim Sistemi | Windows 10 LTSC | Windows 11 IoT Enterprise LTSC |
| Ağ | Ethernet (RJ45) | Ethernet + WiFi yedek |

### 12.2 Windows Kiosk Kilitleme

1. **Installer'ı çalıştırın:** `Setup.exe` → otomatik kurulum (per-machine)
2. **Windows Assigned Access** ile uygulamayı kiosk moduna kilitleyin:
   - `Ayarlar → Hesaplar → Aile ve diğer kullanıcılar → Atanmış erişim ayarla`
   - Veya Group Policy: `Computer Configuration → Administrative Templates → System → Logon → Custom User Interface`
3. **Otomatik başlatma:** Uygulama kısayolunu `shell:startup` klasörüne ekleyin
4. **Windows güç ayarları:** "Ekranı kapat" ve "Uyku" süresini "Hiçbir zaman" yapın

### 12.3 Ağ Yapılandırması

```
Kiosk Cihazı (Electron)  ──►  Yerel Ağ / VPN  ──►  Backend Sunucu
       │                                                   │
       └──► İzin verilen siteler                           └──► Apache + PHP + MariaDB
            (silivri.bel.tr, turkiye.gov.tr, vb.)              (127.0.0.1 veya LAN IP)
```

- `main.js` → `API_BASE` adresini backend sunucunun IP/hostname'ine ayarlayın
- Firewall'da izin verilen domainleri beyaz listeye ekleyin (opsiyonel ama önerilen)
- DNS seviyesinde reklam engelleme (Pi-hole, AdGuard) ek güvenlik katmanı sağlar

---

## 13. Geliştirme Rehberi

### 13.1 Yeni Hizmet Kartı Ekleme

1. Admin paneline giriş yapın (`http://<sunucu>/kiosk-sistem/backend/admin.php`)
2. "Yeni Servis Ekle" sekmesinden form doldurun
3. **İkon seçimi:** [Bootstrap Icons](https://icons.getbootstrap.com/) kataloğundan sınıf adını kopyalayın (ör: `bi bi-globe2`)
4. Sıra numarasını ayarlayın (küçük değer = daha önce gösterilir)
5. "Aktif" kutusunu işaretleyin ve kaydedin
6. Kiosk **otomatik** olarak güncellenecektir (max 60 saniye cache süresi)

### 13.2 Yeni Domain İzni Ekleme

`electron-client/assets/js/main.js` → `ALLOWED_DOMAINS` dizisine yeni domain ekleyin:

```javascript
const ALLOWED_DOMAINS = [
    // ... mevcut domainler ...
    'yenidomain.gov.tr',      // Alt domainler (x.yenidomain.gov.tr) otomatik dahil
];
```

> **Uyarı:** Domain değişikliği uygulanması için Electron uygulamasının yeniden **derlenmesi ve dağıtılması** gerekir.

### 13.3 Yeni Ayar Anahtarı Ekleme

1. **Veritabanına ekleyin:**
   ```sql
   INSERT INTO kiosk_settings VALUES ('yeni_anahtar', 'deger', 'Etiket', NOW());
   ```

2. **Karar verin — Renderer'a dönecek mi?**
   - ✅ **Evet →** `api.php` → `$RENDERER_SAFE_KEYS` dizisine ekleyin
   - ❌ **Hayır → Yalnızca admin** → `admin.php` → `save_settings` case'ine ekleyin
   - **ASLA:** Gizli anahtarları `RENDERER_SAFE_KEYS`'e eklemeyin

### 13.4 Kodlama Kuralları

| Kural | Detay |
|-------|-------|
| **PHP** | `declare(strict_types=1)`, prepared statements, parametrize sorgular |
| **JS (Electron)** | `'use strict'`, `const/let`, inline event handler **yasak** |
| **JS (Admin)** | `addEventListener` tercih et, `data-tab` attribute pattern'i kullan |
| **CSS** | Custom Properties (`--brand-*`), BEM benzeri sınıf adları |
| **Güvenlik** | Tüm kullanıcı girdisi escape/validate edilir |
| **DB** | Credentials commitlenmez; `SELECT *` yerine explicit sütun listesi |
| **CDN** | SRI hash (`integrity`) ve `crossorigin="anonymous"` zorunlu |
| **Performans** | `DocumentFragment` ile DOM batching, `Cache-Control` header'ları |

### 13.5 Bilinen Gotcha'lar

| # | Gotcha | Detay |
|---|--------|-------|
| 1 | `config.php` commit etmeyin | `.gitignore`'da. Fresh clone sonrası kopyalayıp doldurun |
| 2 | `main.js` taşımayın | `package.json` `main` ve tüm `path.join()` referansları kırılır |
| 3 | Inline `onclick` eklemeyin | `index.html`'de CSP tarafından sessizce bloke edilir |
| 4 | CDN'e SRI ekleyin | `integrity` + `crossorigin="anonymous"` zorunlu |
| 5 | `API_BASE` tek yerde | Yalnızca `main.js`'te. `renderer.js`'e eklemeyin (IPC kullanılır) |
| 6 | CORS origin'leri kaldırmayın | `file://` ve `null` Electron için zorunlu |
| 7 | JSON enjeksiyonu | Admin `<script>` bloğuna PHP verisi yazarken `JSON_HEX_TAG` kullanın |
| 8 | Event Scheduler | `SET GLOBAL event_scheduler = ON` yapılmazsa log temizleme çalışmaz |

---

## 14. Sorun Giderme (Troubleshooting)

### Beyaz Ekran (White Screen of Death)

| Olası Neden | Çözüm |
|-------------|-------|
| `main.js` yolu hatalı | `package.json` → `"main"` alanını doğrulayın |
| `path.join()` yanlış | `__dirname`'in `assets/js/` olduğunu unutmayın |
| `preload.js` bulunamıyor | `webPreferences.preload` yolunu kontrol edin |
| CSP ihlali | DevTools Console'da `Refused to execute inline script` arayın |

### API Bağlantı Hatası

| Olası Neden | Çözüm |
|-------------|-------|
| `config.php` yok | `cp config.example.php config.php` + değerleri doldurun |
| MySQL/MariaDB kapalı | `systemctl start mariadb` veya XAMPP'ten başlatın |
| CORS hatası | `api.php` → `$allowedOrigins` listesini kontrol edin |
| `API_BASE` yanlış | `main.js` → `const API_BASE` adresini doğrulayın |
| Firewall bloğu | PHP'nin 80/443 portlarında dinlediğinden emin olun |

### Admin Paneli Sorunları

| Olası Neden | Çözüm |
|-------------|-------|
| 15dk kilit | Session çerezlerini silin veya 15dk bekleyin |
| CSRF hatası | Sayfayı yenileyin — eski token süresi dolmuş |
| Sort çalışmıyor | `X-CSRF-Token` header ve JSON format kontrolü |
| Loglar boş | Event scheduler aktif mi? 90 güne kadar loglar temizlenir |

### Kiosk Cihazı Sorunları

| Olası Neden | Çözüm |
|-------------|-------|
| Ekran kapanıyor | `powerSaveBlocker` aktif mi? Windows güç ayarları: "Hiçbir zaman" |
| Dokunmatik çalışmıyor | HID sürücülerini kontrol edin |
| GPU hataları | `--ignore-gpu-blocklist` flag var mı? Sürücüleri güncelleyin |
| Uygulama kapanıyor | Windows Event Viewer'da Application loglarını inceleyin |
| Kiosk'tan çıkılamıyor | Terminalden `Ctrl+C` veya `taskkill /f /im "T.C. Silivri*"` |

---

## 15. Gelecek Vizyonu

Geliştirilecekler listesi (öncelik sırasına göre):

1. **Çevrimdışı Mod (Offline Fallback):** Network kesildiğinde LocalStorage/IndexedDB'ye cache'lenmiş hizmet kartlarını gösterme
2. **Yazıcı (Printer) Entegrasyonu:** COM/Serial (RS-232) veya USB üzerinden Electron native bridge ile bilet/fiş yazdırma
3. **Barkod/QR Okuyucu:** USB HID veya seri port üzerinden barkod/QR kod okuma
4. **NFC/TC Kimlik Kartı Okuyucu:** Vatandaşın TC Kimlik kartını dokundurarak e-Devlet girişini hızlandırma
5. **Çoklu Dil Desteği:** İngilizce, Arapça gibi alternatif dil seçenekleri
6. **Admin Dashboard:** Günlük/haftalık/aylık kullanım istatistikleri ve grafikler
7. **Lazy-load Admin Logları:** Erişim günlüğü sekmesi AJAX ile istek üzerine yükleme
8. **Idle Timeout Senkronizasyonu:** Main process ve renderer arasında IPC tabanlı tek kaynak zamanlayıcı

---

## 16. İlgili Dokümanlar

| Doküman | Açıklama |
|---------|----------|
| `AGENTS.md` | AI agent kılavuzu — kısıtlar, konvansiyonlar, güvenlik kuralları, uygulanan optimizasyonlar |
| `OPTIMIZATIONS.md` | 25 maddelik performans denetim raporu — bulgular, çözümler, doğrulama planı |
| `SECURITY_AUDIT_REPORT.md` | 7 maddelik güvenlik denetim raporu — kritik/yüksek/orta/düşük bulgular ve düzeltmeler |

---

## Lisans

**Proprietary** — T.C. Silivri Belediyesi Bilgi İşlem Müdürlüğü

Bu yazılım T.C. Silivri Belediyesi'ne aittir. İzinsiz kopyalama, dağıtım veya ticari kullanım yasaktır.

---

*Son güncelleme: 4 Mart 2026 — v122.0.6261*
