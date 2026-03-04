<?php
declare(strict_types=1);

$_configPath = __DIR__ . '/config.php';
if (!is_file($_configPath)) {
    throw new RuntimeException(
        'config.php bulunamadı. config.example.php dosyasını kopyalayıp düzenleyin.'
    );
}
require_once $_configPath;
unset($_configPath);

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('[KIOSK DB HATA] ' . $e->getMessage());
            throw new RuntimeException('Veritabanı bağlantısı kurulamadı.');
        }
    }

    return $pdo;
}