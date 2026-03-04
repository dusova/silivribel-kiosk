<?php
declare(strict_types=1);
/**
 * Örnek yapılandırma dosyası.
 * Bu dosyayı 'config.php' olarak kopyalayın ve gerçek değerleri girin.
 * config.php ASLA versiyon kontrolüne eklenmemelidir.
 */
define('DB_HOST',    '127.0.0.1');
define('DB_PORT',    '3306');
define('DB_NAME',    'silivri_kiosk');

// GÜVENLİK: root kullanmayın — yalnızca uygulama tabloları üzerinde
// SELECT/INSERT/UPDATE/DELETE yetkisi olan ayrı bir MySQL kullanıcısı oluşturun.
// Örnek:
//   CREATE USER 'kiosk_app'@'127.0.0.1' IDENTIFIED BY '<güçlü-şifre>';
//   GRANT SELECT,INSERT,UPDATE,DELETE ON silivri_kiosk.* TO 'kiosk_app'@'127.0.0.1';
define('DB_USER',    'kiosk_app');
define('DB_PASS',    'DEĞIŞTIR_BENI_GÜÇLÜ_ŞİFRE');

define('DB_CHARSET', 'utf8mb4');
