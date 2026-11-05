-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 05, 2026 at 03:22 AM
-- Server version: 8.4.3
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cekapi`
--

-- --------------------------------------------------------

--
-- Table structure for table `alat`
--

CREATE TABLE `alat` (
  `id_alat` bigint UNSIGNED NOT NULL,
  `id_user` bigint UNSIGNED NOT NULL,
  `device_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nama_alat` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lokasi` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_alat` enum('Aktif','Nonaktif','Perawatan') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif',
  `terakhir_terhubung` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `data_sensor`
--

CREATE TABLE `data_sensor` (
  `id_log` bigint UNSIGNED NOT NULL,
  `id_alat` bigint UNSIGNED NOT NULL,
  `suhu` decimal(5,2) DEFAULT NULL,
  `kelembaban` decimal(5,2) DEFAULT NULL,
  `mq2_adc` smallint UNSIGNED DEFAULT NULL,
  `kadar_asap` decimal(10,2) DEFAULT NULL COMMENT 'Estimasi konsentrasi asap; satuan mengikuti kalibrasi firmware',
  `kadar_gas` decimal(10,2) DEFAULT NULL COMMENT 'Estimasi gas; satuan mengikuti kalibrasi firmware',
  `status_api` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = api terdeteksi, 0 = tidak terdeteksi',
  `status_sistem` enum('Aman','Waspada','Bahaya') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aman',
  `waktu_rekam` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `device_controls`
--

CREATE TABLE `device_controls` (
  `id_control` bigint UNSIGNED NOT NULL,
  `id_alat` bigint UNSIGNED NOT NULL,
  `led` tinyint(1) NOT NULL DEFAULT '1',
  `buzzer` tinyint(1) NOT NULL DEFAULT '0',
  `kipas` tinyint(1) NOT NULL DEFAULT '0',
  `updated_by` bigint UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `log_peringatan`
--

CREATE TABLE `log_peringatan` (
  `id_peringatan` bigint UNSIGNED NOT NULL,
  `id_alat` bigint UNSIGNED NOT NULL,
  `id_log` bigint UNSIGNED DEFAULT NULL,
  `tingkat_bahaya` enum('Waspada','Bahaya') COLLATE utf8mb4_unicode_ci NOT NULL,
  `pesan_peringatan` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_penanganan` enum('Belum','Selesai') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Belum',
  `waktu_kejadian` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `waktu_penanganan` datetime DEFAULT NULL,
  `ditangani_oleh` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `id_user` bigint UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `no_hp` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('Admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Admin',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`id_user`, `name`, `username`, `email`, `password`, `no_hp`, `role`, `created_at`, `updated_at`) VALUES
(1, 'Admin Tim Hebat', 'Tim Hebat', 'timhebat@gmail.com', '$2y$10$fYx3IC/xoP2iiCK8w3fnPeaU0/rYbwQv7j5FFKTez.KFTzDNpQMu6', '0857345678', 'Admin', '2026-11-04 05:53:27', '2026-11-04 16:03:36');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alat`
--
ALTER TABLE `alat`
  ADD PRIMARY KEY (`id_alat`),
  ADD UNIQUE KEY `uq_alat_device_id` (`device_id`),
  ADD KEY `idx_alat_user` (`id_user`);

--
-- Indexes for table `data_sensor`
--
ALTER TABLE `data_sensor`
  ADD PRIMARY KEY (`id_log`),
  ADD KEY `idx_sensor_alat_waktu` (`id_alat`,`waktu_rekam`),
  ADD KEY `idx_sensor_waktu` (`waktu_rekam`),
  ADD KEY `idx_sensor_status` (`status_sistem`);

--
-- Indexes for table `device_controls`
--
ALTER TABLE `device_controls`
  ADD PRIMARY KEY (`id_control`),
  ADD UNIQUE KEY `uq_control_alat` (`id_alat`),
  ADD KEY `idx_control_admin` (`updated_by`);

--
-- Indexes for table `log_peringatan`
--
ALTER TABLE `log_peringatan`
  ADD PRIMARY KEY (`id_peringatan`),
  ADD KEY `idx_peringatan_alat_waktu` (`id_alat`,`waktu_kejadian`),
  ADD KEY `idx_peringatan_status` (`status_penanganan`),
  ADD KEY `idx_peringatan_log` (`id_log`),
  ADD KEY `idx_peringatan_admin` (`ditangani_oleh`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`id_user`),
  ADD UNIQUE KEY `uq_user_username` (`username`),
  ADD UNIQUE KEY `uq_user_email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alat`
--
ALTER TABLE `alat`
  MODIFY `id_alat` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `data_sensor`
--
ALTER TABLE `data_sensor`
  MODIFY `id_log` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `device_controls`
--
ALTER TABLE `device_controls`
  MODIFY `id_control` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `log_peringatan`
--
ALTER TABLE `log_peringatan`
  MODIFY `id_peringatan` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `id_user` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alat`
--
ALTER TABLE `alat`
  ADD CONSTRAINT `fk_alat_user` FOREIGN KEY (`id_user`) REFERENCES `user` (`id_user`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `data_sensor`
--
ALTER TABLE `data_sensor`
  ADD CONSTRAINT `fk_sensor_alat` FOREIGN KEY (`id_alat`) REFERENCES `alat` (`id_alat`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `device_controls`
--
ALTER TABLE `device_controls`
  ADD CONSTRAINT `fk_control_admin` FOREIGN KEY (`updated_by`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_control_alat` FOREIGN KEY (`id_alat`) REFERENCES `alat` (`id_alat`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `log_peringatan`
--
ALTER TABLE `log_peringatan`
  ADD CONSTRAINT `fk_peringatan_admin` FOREIGN KEY (`ditangani_oleh`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_peringatan_alat` FOREIGN KEY (`id_alat`) REFERENCES `alat` (`id_alat`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_peringatan_sensor` FOREIGN KEY (`id_log`) REFERENCES `data_sensor` (`id_log`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
