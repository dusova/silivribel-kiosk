-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Anamakine: 127.0.0.1
-- Üretim Zamanı: 01 Mar 2026, 18:23:20
-- Sunucu sürümü: 10.4.32-MariaDB
-- PHP Sürümü: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `silivri_kiosk`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `access_logs`
--

CREATE TABLE `access_logs` (
  `id` int(11) NOT NULL,
  `url_id` int(11) DEFAULT NULL,
  `url` varchar(500) NOT NULL,
  `accessed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `password`, `created_at`) VALUES
(1, 'mdusova', '$2y$12$s496rZLyKxCAzjgl2moONeMAlGHslOFHrZsVBBDOl3vnlcRsW2Hm2', '2026-02-23 01:42:58');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `allowed_urls`
--

CREATE TABLE `allowed_urls` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL COMMENT 'Kart başlığı',
  `url` varchar(500) NOT NULL DEFAULT '' COMMENT 'Açılacak URL (yerleşik servisler için boş)',
  `image_url` varchar(500) DEFAULT NULL COMMENT 'Kart görseli URL (boşsa varsayılan ikon)',
  `icon_class` varchar(100) NOT NULL DEFAULT 'bi bi-globe' COMMENT 'Görsel yoksa yedek Bootstrap icon',
  `service_type` enum('external','weather','pharmacy','contact') NOT NULL DEFAULT 'external',
  `sort_order` int(11) NOT NULL DEFAULT 99 COMMENT 'Sıra (küçük = önce)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=Aktif, 0=Pasif',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `allowed_urls`
--

INSERT INTO `allowed_urls` (`id`, `title`, `url`, `image_url`, `icon_class`, `service_type`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES
(3, 'Kent Rehberi', 'https://360.silivri.bel.tr/_keos/', NULL, 'bi bi-house-heart-fill', 'external', 1, 1, '2026-02-23 01:42:58', '2026-02-25 16:35:13'),
(5, 'E-Belediye', 'https://ebys.silivri.bel.tr/ebelediye', NULL, 'bi bi-globe2', 'external', 2, 1, '2026-02-23 01:42:58', '2026-02-25 16:35:13'),
(10, 'Afet Bilgi Sistemi', 'https://360.silivri.bel.tr/abis/', NULL, 'bi bi-info-circle', 'external', 3, 1, '2026-02-25 13:06:05', '2026-02-25 16:13:05'),
(11, 'Kültür Sanat Merkezi', 'https://kultursanat.silivri.bel.tr/', NULL, 'bi bi-hearts', 'external', 4, 1, '2026-02-25 13:07:26', '2026-02-25 16:13:05'),
(12, 'Sosyal Tesislerimiz', 'https://tesis.silivri.bel.tr/', NULL, 'bi bi-cup-hot', 'external', 5, 1, '2026-02-25 13:09:00', '2026-02-25 16:35:43');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `kiosk_settings`
--

CREATE TABLE `kiosk_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `label` varchar(200) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `kiosk_settings`
--

INSERT INTO `kiosk_settings` (`setting_key`, `setting_value`, `label`, `updated_at`) VALUES
('background_image', 'https://upload.wikimedia.org/wikipedia/commons/6/67/Silivri01.JPG', 'Arka Plan Görseli URL', '2026-02-25 16:13:14'),
('municipality_logo', 'https://www.silivri.bel.tr/assets/site/silivribelediyesi4/images/new-logo.svg', 'Belediye Logosu URL', '2026-02-25 16:13:14');

--
-- Dökümü yapılmış tablolar için indeksler
--

--
-- Tablo için indeksler `access_logs`
--
ALTER TABLE `access_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_accessed_at` (`accessed_at`),
  ADD KEY `idx_url_id` (`url_id`);

--
-- Tablo için indeksler `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Tablo için indeksler `allowed_urls`
--
ALTER TABLE `allowed_urls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_active_sort` (`is_active`, `sort_order`, `id`),
  ADD KEY `idx_url` (`url`(191));

--
-- Tablo için indeksler `kiosk_settings`
--
ALTER TABLE `kiosk_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Dökümü yapılmış tablolar için AUTO_INCREMENT değeri
--

--
-- Tablo için AUTO_INCREMENT değeri `access_logs`
--
ALTER TABLE `access_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=122;

--
-- Tablo için AUTO_INCREMENT değeri `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Tablo için AUTO_INCREMENT değeri `allowed_urls`
--
ALTER TABLE `allowed_urls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;
COMMIT;

--
-- Eski erişim günlüklerini otomatik temizleme (90 gün)
--
DELIMITER $$
CREATE EVENT IF NOT EXISTS `purge_old_access_logs`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM `access_logs` WHERE `accessed_at` < NOW() - INTERVAL 90 DAY;
$$
DELIMITER ;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
