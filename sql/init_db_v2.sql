-- init_db_v2.sql
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS `inventari_camises_v2`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `inventari_camises_v2`;

DROP VIEW IF EXISTS `vista_estoc_global`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `produccio_events_units`;
DROP TABLE IF EXISTS `produccio_events`;
DROP TABLE IF EXISTS `moviments`;
DROP TABLE IF EXISTS `peticions`;
DROP TABLE IF EXISTS `magatzem_posicions`;
DROP TABLE IF EXISTS `item_units`;
DROP TABLE IF EXISTS `maquines`;
DROP TABLE IF EXISTS `items`;

SET FOREIGN_KEY_CHECKS = 1;

-- ========================
-- TAULA items
-- ========================
CREATE TABLE `items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `stock` int(11) DEFAULT 0,
  `min_stock` int(11) DEFAULT 0,
  `plan_file` varchar(255) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================
-- TAULA item_units
-- ========================
CREATE TABLE `item_units` (
  `id` int(11) NOT NULL AUTO_INC_
