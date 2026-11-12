-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 12-11-2026 a las 11:38:47
-- Versión del servidor: 10.11.16-MariaDB
-- Versión de PHP: 8.5.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `inventari_camises_v2`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `compres_recanvis`
--

CREATE TABLE `compres_recanvis` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `qty_entrada` int(11) NOT NULL DEFAULT 0,
  `proveidor` varchar(120) NOT NULL,
  `numero_comanda` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `source` enum('auto','manual') NOT NULL DEFAULT 'manual',
  `estat` enum('demanada','parcial','rebuda','anulada') NOT NULL DEFAULT 'demanada',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `compres_recanvis_units`
--

CREATE TABLE `compres_recanvis_units` (
  `id` int(11) NOT NULL,
  `compra_id` int(11) NOT NULL,
  `item_unit_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `items`
--

CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `stock` int(11) DEFAULT 0,
  `min_stock` int(11) DEFAULT 0,
  `vida_total_default` int(11) NOT NULL DEFAULT 0,
  `plan_file` varchar(255) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reorder_status` varchar(12) DEFAULT NULL,
  `reorder_proveidor` varchar(120) DEFAULT NULL,
  `reorder_notes` varchar(255) DEFAULT NULL,
  `reorder_updated_at` datetime DEFAULT NULL,
  `reorder_qty_comprada` int(11) NOT NULL DEFAULT 0,
  `reorder_qty_entregada` int(11) NOT NULL DEFAULT 0,
  `reorder_qty_entrada` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `item_units`
--

CREATE TABLE `item_units` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `serial` varchar(50) NOT NULL,
  `vida_utilitzada` int(11) DEFAULT 0,
  `vida_total` int(11) DEFAULT 0,
  `cicles_maquina` int(11) DEFAULT 0,
  `ubicacio` enum('magatzem','intermig','preparacio','maquina','baixa') DEFAULT 'magatzem',
  `magatzem_code` varchar(10) NOT NULL DEFAULT 'MAG01',
  `sububicacio` varchar(50) DEFAULT NULL,
  `maquina_actual` varchar(50) DEFAULT NULL,
  `proveidor` varchar(120) DEFAULT NULL,
  `estat` enum('actiu','inactiu') DEFAULT 'actiu',
  `baixa_motiu` varchar(50) DEFAULT NULL,
  `maquina_baixa` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `magatzem_posicions`
--

CREATE TABLE `magatzem_posicions` (
  `id` int(10) UNSIGNED NOT NULL,
  `estanteria` tinyint(3) UNSIGNED NOT NULL,
  `posicio` char(1) NOT NULL,
  `profunditat` tinyint(3) UNSIGNED NOT NULL,
  `codi` varchar(6) NOT NULL,
  `magatzem_code` varchar(10) NOT NULL DEFAULT 'MAG01',
  `item_unit_id` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `maquines`
--

CREATE TABLE `maquines` (
  `id` int(11) NOT NULL,
  `codi` varchar(50) NOT NULL,
  `activa` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `moviments`
--

CREATE TABLE `moviments` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_unit_id` int(11) DEFAULT NULL,
  `tipus` enum('entrada','sortida','retorn','baixa') NOT NULL,
  `quantitat` int(11) DEFAULT 1,
  `ubicacio` varchar(100) DEFAULT NULL,
  `maquina` varchar(100) DEFAULT NULL,
  `observacions` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `peticions`
--

CREATE TABLE `peticions` (
  `id` int(11) NOT NULL,
  `maquina` varchar(50) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `estat` enum('pendent','servida','anulada') DEFAULT 'pendent',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `produccio_events`
--

CREATE TABLE `produccio_events` (
  `id` int(11) NOT NULL,
  `maquina` varchar(50) NOT NULL,
  `unitats_originals` int(11) NOT NULL,
  `unitats_correctes` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `produccio_events_units`
--

CREATE TABLE `produccio_events_units` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `item_unit_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `proveidors`
--

CREATE TABLE `proveidors` (
  `id` int(11) NOT NULL,
  `nom` varchar(120) NOT NULL,
  `actiu` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `compres_recanvis`
--
ALTER TABLE `compres_recanvis`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `ix_compres_item_estat` (`item_id`,`estat`);

--
-- Indices de la tabla `compres_recanvis_units`
--
ALTER TABLE `compres_recanvis_units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_compra_unit` (`compra_id`,`item_unit_id`),
  ADD KEY `idx_compra` (`compra_id`),
  ADD KEY `idx_item_unit` (`item_unit_id`);

--
-- Indices de la tabla `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`);

--
-- Indices de la tabla `item_units`
--
ALTER TABLE `item_units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `serial` (`serial`),
  ADD KEY `item_id` (`item_id`);

--
-- Indices de la tabla `magatzem_posicions`
--
ALTER TABLE `magatzem_posicions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_estanteria_posicio_prof` (`estanteria`,`posicio`,`profunditat`),
  ADD UNIQUE KEY `uq_codi` (`codi`),
  ADD UNIQUE KEY `ux_mag_posicio` (`magatzem_code`,`codi`),
  ADD UNIQUE KEY `uq_item_unit` (`item_unit_id`);

--
-- Indices de la tabla `maquines`
--
ALTER TABLE `maquines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codi` (`codi`);

--
-- Indices de la tabla `moviments`
--
ALTER TABLE `moviments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `item_unit_id` (`item_unit_id`);

--
-- Indices de la tabla `peticions`
--
ALTER TABLE `peticions`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `produccio_events`
--
ALTER TABLE `produccio_events`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `produccio_events_units`
--
ALTER TABLE `produccio_events_units`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_produccio_event` (`event_id`),
  ADD KEY `fk_produccio_unit` (`item_unit_id`);

--
-- Indices de la tabla `proveidors`
--
ALTER TABLE `proveidors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_proveidors_nom` (`nom`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `compres_recanvis`
--
ALTER TABLE `compres_recanvis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `compres_recanvis_units`
--
ALTER TABLE `compres_recanvis_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `items`
--
ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `item_units`
--
ALTER TABLE `item_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `magatzem_posicions`
--
ALTER TABLE `magatzem_posicions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `maquines`
--
ALTER TABLE `maquines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `moviments`
--
ALTER TABLE `moviments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `peticions`
--
ALTER TABLE `peticions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `produccio_events`
--
ALTER TABLE `produccio_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `produccio_events_units`
--
ALTER TABLE `produccio_events_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `proveidors`
--
ALTER TABLE `proveidors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `compres_recanvis`
--
ALTER TABLE `compres_recanvis`
  ADD CONSTRAINT `fk_compres_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `compres_recanvis_units`
--
ALTER TABLE `compres_recanvis_units`
  ADD CONSTRAINT `fk_cru_compra` FOREIGN KEY (`compra_id`) REFERENCES `compres_recanvis` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cru_item_unit` FOREIGN KEY (`item_unit_id`) REFERENCES `item_units` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `item_units`
--
ALTER TABLE `item_units`
  ADD CONSTRAINT `item_units_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `moviments`
--
ALTER TABLE `moviments`
  ADD CONSTRAINT `moviments_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `moviments_ibfk_2` FOREIGN KEY (`item_unit_id`) REFERENCES `item_units` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `produccio_events_units`
--
ALTER TABLE `produccio_events_units`
  ADD CONSTRAINT `fk_produccio_event` FOREIGN KEY (`event_id`) REFERENCES `produccio_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_produccio_unit` FOREIGN KEY (`item_unit_id`) REFERENCES `item_units` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
