<?php
declare(strict_types=1);

$allowedOrigins = ['http://localhost', 'http://127.0.0.1', 'file://', 'null', 'https://www.silivri.bel.tr', 'https://silivri.bel.tr', 'https://kiosk.codewithmad.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin) && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Yalnızca GET desteklenmektedir.']);
    exit;
}

require_once __DIR__ . '/db.php';

function sendJson(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim($_GET['action'] ?? 'urls');

try {
    $db = getDB();
    switch ($action) {
        case 'urls':
            header('Cache-Control: public, max-age=60');
            $stmt = $db->prepare(
                "SELECT id, title, url, icon_class, service_type, sort_order
                 FROM allowed_urls
                 WHERE is_active = 1
                 ORDER BY sort_order ASC, id ASC"
            );
            $stmt->execute();
            sendJson(200, [
                'success'    => true,
                'data'       => $stmt->fetchAll(),
                'fetched_at' => date('c'),
            ]);
            break;

        case 'log':
            header('Cache-Control: no-store, no-cache, must-revalidate');
            $urlId = isset($_GET['url_id']) && is_numeric($_GET['url_id']) ? (int)$_GET['url_id'] : null;
            $url   = filter_var($_GET['url'] ?? '', FILTER_VALIDATE_URL);
            if (!$url) sendJson(400, ['success' => false, 'error' => 'Geçerli URL gerekli.']);
            
            if (!$urlId) {
                $stmt = $db->prepare("SELECT id FROM allowed_urls WHERE url = ? LIMIT 1");
                $stmt->execute([$url]);
                $urlId = $stmt->fetchColumn() ?: null;
            }

            $db->prepare("INSERT INTO access_logs (url_id, url) VALUES (?, ?)")
               ->execute([$urlId, $url]);
            sendJson(201, ['success' => true]);
            break;

        case 'settings':
            header('Cache-Control: public, max-age=60');
            $RENDERER_SAFE_KEYS = ['background_image', 'municipality_logo'];
            $placeholders = implode(',', array_fill(0, count($RENDERER_SAFE_KEYS), '?'));
            $stmt = $db->prepare(
                "SELECT setting_key, setting_value FROM kiosk_settings WHERE setting_key IN ($placeholders)"
            );
            $stmt->execute($RENDERER_SAFE_KEYS);
            $settings = [];
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            sendJson(200, ['success' => true, 'data' => $settings]);
            break;

        default:
            sendJson(400, ['success' => false, 'error' => 'Bilinmeyen action.']);
    }
} catch (RuntimeException $e) {
    error_log('[API RuntimeException] ' . $e->getMessage());
    sendJson(503, ['success' => false, 'error' => 'Servis geçici olarak kullanılamıyor.']);
} catch (Throwable $e) {
    error_log('[API] ' . $e->getMessage());
    sendJson(500, ['success' => false, 'error' => 'Sunucu hatası.']);
}