<?php
declare(strict_types=1);

// Güvenlik: oturum çerezini HttpOnly + SameSite=Strict ile yapılandır.
// Uygulama HTTPS üzerinden sunuluyorsa 'secure' => true yapın.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require_once __DIR__ . '/db.php';

define('SESSION_TIMEOUT', 1800);

if (isset($_SESSION['admin_logged_in'], $_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_destroy();
        header('Location: admin.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_token'];

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $loginError = 'Geçersiz form isteği.';
    } elseif (isset($_SESSION['login_locked_until']) && $_SESSION['login_locked_until'] > time()) {
        // Güvenlik: kaba kuvvet kilidi — hesap geçici olarak kilitlendi
        $remaining = (int)ceil(($_SESSION['login_locked_until'] - time()) / 60);
        $loginError = "Çok fazla başarısız deneme. {$remaining} dakika sonra tekrar deneyin.";
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, password FROM admin_users WHERE username = ? LIMIT 1");
            $stmt->execute([$_POST['username'] ?? '']);
            $user = $stmt->fetch();

            if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['admin_logged_in']    = true;
                $_SESSION['last_activity']       = time();
                // Başarılı girişte deneme sayıcısını sıfırla
                $_SESSION['login_attempts']      = 0;
                unset($_SESSION['login_locked_until']);
                // Optimizasyon: 90 günden eski erişim loglarını otomatik temizle
                try {
                    $db->exec("DELETE FROM access_logs WHERE accessed_at < NOW() - INTERVAL 90 DAY");
                } catch (\Throwable $e) {
                    error_log('[ADMIN] Log temizleme hatası: ' . $e->getMessage());
                }
                header('Location: admin.php');
                exit;
            }

            // Başarısız girişte sayıcıyı artır
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            if ($_SESSION['login_attempts'] >= 5) {
                $_SESSION['login_locked_until'] = time() + 900; // 15 dakika
                $loginError = 'Çok fazla başarısız deneme. 15 dakika sonra tekrar deneyin.';
            } else {
                $left = 5 - $_SESSION['login_attempts'];
                $loginError = "Kullanıcı adı veya şifre hatalı. ({$left} deneme hakkı kaldı)";
            }
        } catch (Throwable $e) {
            $loginError = 'Sistem hatası.';
        }
    }
}

$isLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$msg = '';
$msgType = 'success';

if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $incomingCsrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    if (!hash_equals($csrf, $incomingCsrf)) {
        $msg = 'Geçersiz CSRF token.';
        $msgType = 'danger';
    } else {
        try {
            $db = getDB();
            $action = $_POST['action'] ?? $_GET['action'] ?? '';
            
            switch ($action) {
                case 'add':
                    $title = trim($_POST['title'] ?? '');
                    $url = trim($_POST['url'] ?? '');
                    $icon = trim($_POST['icon_class'] ?? 'bi bi-globe');
                    $type = 'external';
                    $order = (int)($_POST['sort_order'] ?? 99);
                    $active = isset($_POST['is_active']) ? 1 : 0;
                    
                    if (!$title) {
                        throw new \InvalidArgumentException('Başlık zorunludur.');
                    }
                    if ($type === 'external' && !filter_var($url, FILTER_VALIDATE_URL)) {
                        throw new \InvalidArgumentException('Dış link türü için geçerli URL giriniz.');
                    }
                    
                    $db->prepare("INSERT INTO allowed_urls (title, url, icon_class, service_type, sort_order, is_active) VALUES (?,?,?,?,?,?)")
                       ->execute([$title, $url, $icon, $type, $order, $active]);
                    
                    $msg = "'{$title}' eklendi.";
                    break;

                case 'edit':
                    $id = (int)($_POST['id'] ?? 0);
                    $title = trim($_POST['title'] ?? '');
                    $url = trim($_POST['url'] ?? '');
                    $icon = trim($_POST['icon_class'] ?? 'bi bi-globe');
                    $type = 'external';
                    $order = (int)($_POST['sort_order'] ?? 99);
                    $active = isset($_POST['is_active']) ? 1 : 0;
                    
                    if (!$id || !$title) {
                        throw new \InvalidArgumentException('Geçersiz veri.');
                    }
                    
                    $db->prepare("UPDATE allowed_urls SET title=?,url=?,icon_class=?,service_type=?,sort_order=?,is_active=? WHERE id=?")
                       ->execute([$title, $url, $icon, $type, $order, $active, $id]);
                    
                    $msg = "'{$title}' güncellendi.";
                    break;

                case 'delete':
                    $id = (int)($_POST['id'] ?? 0);
                    if (!$id) {
                        throw new \InvalidArgumentException('Geçersiz ID.');
                    }
                    
                    $db->prepare("DELETE FROM allowed_urls WHERE id=?")->execute([$id]);
                    $msg = 'Kayıt silindi.';
                    break;

                case 'toggle':
                    $id = (int)($_POST['id'] ?? 0);
                    $db->prepare("UPDATE allowed_urls SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id=?")->execute([$id]);
                    $msg = 'Durum güncellendi.';
                    break;

                case 'sort':
                    $body = file_get_contents('php://input');
                    $orders = json_decode($body, true) ?? [];

                    // Optimizasyon: boyut sınırı — kötüye kullanımı önle
                    if (count($orders) > 100) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'error' => 'Çok fazla öğe.']);
                        exit;
                    }

                    // Optimizasyon: transaction ile atomik ve hızlı toplu güncelleme
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

                case 'save_settings':
                    $settingsMap = [
                        'background_image' => trim($_POST['background_image'] ?? ''),
                        'municipality_logo' => trim($_POST['municipality_logo'] ?? ''),
                    ];
                    $stmt = $db->prepare(
                        "INSERT INTO kiosk_settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
                    );
                    foreach ($settingsMap as $key => $value) {
                        $stmt->execute([$key, $value]);
                    }
                    $msg = 'Ayarlar başarıyla güncellendi.';
                    break;
            }
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $msgType = 'warning';
        } catch (\Throwable $e) {
            error_log('[ADMIN] ' . $e->getMessage());
            $msg = 'Hata oluştu.';
            $msgType = 'danger';
        }
    }
}

$urls = [];
$logs = [];
$kioskSettings = [];

if ($isLoggedIn) {
    try {
        $db = getDB();
        // Optimizasyon: SELECT * yerine kullanılan sütunları belirt (F25)
        $urls = $db->query("SELECT id, title, url, icon_class, service_type, sort_order, is_active FROM allowed_urls ORDER BY sort_order ASC, id ASC")->fetchAll();
        $logs = $db->query("SELECT l.id, l.url, l.accessed_at, u.title as url_title FROM access_logs l LEFT JOIN allowed_urls u ON l.url_id=u.id ORDER BY l.accessed_at DESC LIMIT 30")->fetchAll();
        $settingsRows = $db->query("SELECT setting_key, setting_value FROM kiosk_settings")->fetchAll();
        
        foreach ($settingsRows as $sr) {
            $kioskSettings[$sr['setting_key']] = $sr['setting_value'];
        }
    } catch (\Throwable $e) {
        $msg = 'Veri yükleme hatası.';
        $msgType = 'danger';
    }
}

// Optimizasyon: O(1) edit item lookup (F14)
$editItem = null;
if ($isLoggedIn && isset($_GET['edit'])) {
    $urlsById = array_column($urls, null, 'id');
    $editItem = $urlsById[(int)$_GET['edit']] ?? null;
}

$activeCount = count(array_filter($urls, fn($u) => $u['is_active'] == 1));
$inactiveCount = count($urls) - $activeCount;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kiosk Yönetim — Silivri Belediyesi</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
      integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
      crossorigin="anonymous">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
      integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
      crossorigin="anonymous">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php if (!$isLoggedIn): ?>
<div class="login-wrap">
  <div class="login-card">
    <div class="text-center mb-4">
        <img src="assets/img/logo.png" alt="Silivri Belediyesi" style="max-height:80px;">
    </div>
    <h2>T.C. Silivri Belediyesi<br>Kiosk Yönetim</h2>
    <?php if (!empty($loginError)): ?><div class="alert alert-danger py-2 small"><?=htmlspecialchars($loginError)?></div><?php endif;?>
    <?php if (isset($_GET['timeout'])): ?><div class="alert alert-warning py-2 small">Oturum zaman aşımına uğradı.</div><?php endif;?>
    <form method="POST">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="csrf_token" value="<?=$csrf?>">
      <div class="mb-3">
        <label class="form-label">Kullanıcı Adı</label>
        <div class="input-group"><span class="input-group-text"><i class="bi bi-person"></i></span>
        <input type="text" name="username" class="form-control" required autocomplete="username"></div>
      </div>
      <div class="mb-4">
        <label class="form-label">Şifre</label>
        <div class="input-group"><span class="input-group-text"><i class="bi bi-lock"></i></span>
        <input type="password" name="password" class="form-control" required autocomplete="current-password"></div>
      </div>
      <button type="submit" class="btn btn-red w-100 py-2 fw-bold">
        <i class="bi bi-box-arrow-in-right me-2"></i>Giriş Yap
      </button>
    </form>
  </div>
  <div class="position-absolute bottom-0 start-0 w-100 pb-4 text-center text-muted small">
    2026 &copy; T.C. Silivri Belediyesi Bilgi İşlem Müdürlüğü
  </div>
</div>

<?php else: ?>
<nav class="top-navbar">
  <div class="sb-brand">
    <img src="assets/img/logo.png" alt="Silivri Belediyesi" style="height:50px;">
    <div class="sb-title">T.C. Silivri Belediyesi<br><span style="color:var(--brand-red)">Kiosk Sistemi</span></div>
  </div>
  <div class="top-navbar-right">
    <div class="d-none d-md-flex align-items-center me-3 border-end pe-4" style="gap:10px">
        <span class="badge bg-success-subtle text-success fs-6 border border-success-subtle rounded-pill"><i class="bi bi-circle-fill me-1" style="font-size:8px"></i> Sistem Aktif</span>
        <small class="text-muted fw-bold"><?=date('d.m.Y H:i')?></small>
    </div>
    <a href="?logout=1" class="btn-sys-out" onclick="return confirm('Çıkmak istediğinize emin misiniz?')">
      <i class="bi bi-box-arrow-right"></i> Çıkış
    </a>
  </div>
</nav>

<div class="pill-nav">
  <button class="sb-link active" data-tab="list"><i class="bi bi-grid-3x3-gap"></i> Servis Listesi</button>
  <button class="sb-link" data-tab="add"><i class="bi bi-plus-circle"></i> Yeni Ekle</button>
  <button class="sb-link" data-tab="logs"><i class="bi bi-journal-text"></i> Erişim Günlüğü</button>
  <button class="sb-link" data-tab="settings"><i class="bi bi-gear"></i> Ayarlar</button>
</div>

<main class="main">
  <div class="topbar">
    <h4 style="font-size:42px;"><span style="color:var(--brand-red)">Yönetim</span> Paneli</h4>
  </div>

  <?php if ($msg): ?>
  <div class="alert alert-<?=$msgType?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?=$msgType==='success'?'check-circle':'exclamation-triangle'?> me-2"></i>
    <?=htmlspecialchars($msg)?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif;?>

  <div class="row g-4 mb-5">
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-icon" style="background:#e0f2fe;color:#0369a1"><i class="bi bi-link-45deg"></i></div>
        <div>
          <div class="stat-val"><?=count($urls)?></div>
          <div class="stat-label">Toplam Servis</div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-icon" style="background:#dcfce7;color:#15803d"><i class="bi bi-check-circle-fill"></i></div>
        <div>
          <div class="stat-val"><?=$activeCount?></div>
          <div class="stat-label">Aktif</div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-icon" style="background:#fee2e2;color:#b91c1c"><i class="bi bi-eye-slash-fill"></i></div>
        <div>
          <div class="stat-val"><?=$inactiveCount?></div>
          <div class="stat-label">Pasif</div>
        </div>
      </div>
    </div>
  </div>

  <div class="tab-content">

    <div id="tab-list" class="tab-section visible">
      <div class="panel">
        <div class="panel-hd"><i class="bi bi-list-ul"></i> Kiosk Servisleri
          <span class="ms-auto small fw-normal opacity-75">Satırları sürükleyerek yeniden sıralayın</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="sort-table">
            <thead><tr>
              <th style="width:32px"></th>
              <th>Görsel</th>
              <th>Başlık</th>
              <th>URL</th>
              <th>Sıra</th>
              <th>Durum</th>
              <th>İşlem</th>
            </tr></thead>
            <tbody id="sortable-body">
            <?php foreach ($urls as $row): ?>
            <tr data-id="<?=(int)$row['id']?>">
              <td><i class="bi bi-grip-vertical drag-handle"></i></td>
              <td>
                <div class="img-placeholder"><i class="<?=htmlspecialchars($row['icon_class'])?>"></i></div>
              </td>
              <td><strong><?=htmlspecialchars($row['title'])?></strong></td>
              <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <small title="<?=htmlspecialchars($row['url'])?>"><?=htmlspecialchars($row['url'] ?: '—')?></small>
              </td>
              <td><span class="badge bg-secondary"><?=(int)$row['sort_order']?></span></td>
              <td>
                <span class="badge <?=$row['is_active']?'badge-on':'badge-off'?>">
                  <?=$row['is_active']?'Aktif':'Pasif'?>
                </span>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button class="btn btn-sm btn-outline-primary" title="Düzenle"
                    onclick="fillEdit(<?=htmlspecialchars(json_encode($row),ENT_QUOTES)?>)">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?=(int)$row['id']?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Aktif/Pasif">
                      <i class="bi bi-toggle-<?=$row['is_active']?'on text-success':'off'?>"></i>
                    </button>
                  </form>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Silmek istediğinize emin misiniz?')">
                    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?=(int)$row['id']?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach;?>
            <?php if (empty($urls)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">Henüz servis eklenmemiş.</td></tr>
            <?php endif;?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div id="tab-add" class="tab-section">
      <div class="panel">
        <div class="panel-hd"><i class="bi bi-plus-circle"></i><span id="form-title">Yeni Servis Ekle</span></div>
        <div class="p-4">
          <form method="POST" id="service-form">
            <input type="hidden" name="csrf_token" value="<?=$csrf?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="editId">

            <div class="row g-3">
              <div class="col-md-12">
                <label class="form-label">Başlık *</label>
                <input type="text" name="title" id="fTitle" class="form-control" placeholder="Servis adı" maxlength="100" required>
              </div>

              <div class="col-12" id="url-row">
                <label class="form-label">URL *</label>
                <input type="url" name="url" id="fUrl" class="form-control" placeholder="https://www.silivri.bel.tr/..." required>
              </div>

              <div class="col-md-6">
                <label class="form-label">İkon Seçimi</label>
                <div class="input-group">
                  <span class="input-group-text"><i id="icon-preview" class="bi bi-globe"></i></span>
                  <input type="text" name="icon_class" id="fIcon" class="form-control"
                         value="bi bi-globe">
                </div>
                <div class="form-text">
                  Bootstrap üzerinden ikon bulabilirsiniz: <a href="https://icons.getbootstrap.com" target="_blank">Tümünü gör →</a>
                </div>
              </div>

              <div class="col-md-3">
                <label class="form-label">Sıra No</label>
                <input type="number" name="sort_order" id="fOrder" class="form-control" value="99" min="0">
              </div>

              <div class="col-md-3 d-flex align-items-end">
                <div class="form-check form-switch pb-2">
                  <input class="form-check-input" type="checkbox" name="is_active" id="fActive" checked>
                  <label class="form-check-label fw-bold" for="fActive">Aktif</label>
                </div>
              </div>
            </div>

            <div class="d-flex gap-2 mt-4">
              <button type="submit" class="btn btn-red px-4">
                <i class="bi bi-check-lg me-1"></i><span id="btnLabel">Kaydet</span>
              </button>
              <button type="button" class="btn btn-outline-secondary" id="btn-reset-form">
                <i class="bi bi-x-lg me-1"></i>İptal
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div id="tab-logs" class="tab-section">
      <div class="panel">
        <div class="panel-hd"><i class="bi bi-journal-text"></i> Son 30 Erişim Kaydı</div>
        <div class="table-responsive">
          <table class="table mb-0">
            <thead><tr><th>Zaman</th><th>Servis</th><th>URL</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
              <tr>
                <td><small><?=htmlspecialchars($l['accessed_at'])?></small></td>
                <td><?=htmlspecialchars($l['url_title'] ?? '—')?></td>
                <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <small><?=htmlspecialchars($l['url'])?></small>
                </td>
              </tr>
            <?php endforeach;?>
            <?php if (empty($logs)): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">Henüz kayıt yok.</td></tr>
            <?php endif;?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div id="tab-settings" class="tab-section">
      <div class="panel">
        <div class="panel-hd"><i class="bi bi-gear"></i> Kiosk Ayarları</div>
        <div class="p-4">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?=$csrf?>">
            <input type="hidden" name="action" value="save_settings">
            <div class="row g-3">
              <div class="col-12">
                <div class="alert alert-info py-2 small">
                  <i class="bi bi-info-circle me-1"></i>
                  Bu ayarlar kiosk uygulamasının davranışını ve görünümünü kontrol eder.
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Belediye Logosu URL</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-image"></i></span>
                  <input type="url" name="municipality_logo" class="form-control"
                    value="<?=htmlspecialchars($kioskSettings['municipality_logo'] ?? '')?>"
                    placeholder="https://www.silivri.bel.tr/...logo.svg">
                </div>
                <div class="form-text">Kiosk başlığında gösterilecek logo URL'si (SVG/PNG).</div>
              </div>

              <div class="col-md-8">
                <label class="form-label">Arka Plan Görseli URL <span class="text-muted fw-normal">(opsiyonel)</span></label>
                <input type="url" name="background_image" id="settingsBgUrl" class="form-control"
                  value="<?=htmlspecialchars($kioskSettings['background_image'] ?? '')?>"
                  placeholder="https://images.unsplash.com/...">
                <div class="form-text">
                  Ana ekranın sinematik arka planı. Karartılmış ve bulanıklaştırılmış olarak gösterilir.
                  Boş bırakılırsa saf gradient kullanılır.
                </div>
              </div>

              <div class="col-md-4">
                <label class="form-label">Önizleme</label>
                <div style="position:relative">
                  <img id="settings-bg-preview" class="img-preview"
                    src="<?=htmlspecialchars($kioskSettings['background_image'] ?? '')?>"
                    alt="Arka plan önizleme"
                    style="<?=empty($kioskSettings['background_image'])?'display:none':''?>"
                    onerror="this.style.display='none'">
                  <div id="settings-bg-placeholder" class="img-preview d-flex align-items-center justify-content-center"
                    style="color:#9ca3af;flex-direction:column;gap:6px;<?=!empty($kioskSettings['background_image'])?'display:none':''?>">
                    <i class="bi bi-image-alt" style="font-size:28px"></i>
                    <span style="font-size:12px">URL girin</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="d-flex gap-2 mt-4">
              <button type="submit" class="btn btn-red px-4">
                <i class="bi bi-check-lg me-1"></i>Ayarları Kaydet
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</main>

<footer class="text-center py-4 mt-auto text-muted small" style="border-top: 1px solid rgba(0,0,0,0.05);">
  2026 &copy; T.C. Silivri Belediyesi Bilgi İşlem Müdürlüğü
</footer>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"
        integrity="sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>
<script>window.CSRF_TOKEN = '<?=$csrf?>';</script>
<script src="assets/js/admin.js"></script>
<script>
<?php if ($editItem): ?>
// Güvenlik: JSON_HEX_TAG — </script> etiketinin script bloğunu parçalamasını önler (CWE-79)
fillEdit(<?=json_encode($editItem, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?>);
<?php endif;?>
</script>

<?php endif;?>
</body>
</html>