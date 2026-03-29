-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 29, 2026 at 08:36 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `caacuprecio`
--
CREATE DATABASE IF NOT EXISTS `caacuprecio` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `caacuprecio`;

-- --------------------------------------------------------

--
-- Table structure for table `busquedas`
--

CREATE TABLE `busquedas` (
  `idbusqueda` int(11) NOT NULL,
  `bus_termino` varchar(255) NOT NULL,
  `bus_normalizado` varchar(255) NOT NULL,
  `bus_total` int(11) NOT NULL DEFAULT 1,
  `bus_usuario_id` int(11) DEFAULT NULL,
  `bus_ultima_fecha` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `busqueda_click_producto`
--

CREATE TABLE `busqueda_click_producto` (
  `id` int(11) NOT NULL,
  `termino` varchar(255) NOT NULL,
  `productos_idproductos` int(11) NOT NULL,
  `usuario_idusuario` int(11) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categorias`
--

CREATE TABLE `categorias` (
  `idcategorias` int(11) NOT NULL,
  `cat_nombre` varchar(100) NOT NULL,
  `cat_descripcion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `favoritos`
--

CREATE TABLE `favoritos` (
  `usuario_idusuario` int(11) NOT NULL,
  `productos_idproductos` int(11) NOT NULL,
  `fav_fecha` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `historial_precios`
--

CREATE TABLE `historial_precios` (
  `idhistorial` int(11) NOT NULL,
  `productos_idproductos` int(11) NOT NULL,
  `his_precio` decimal(10,2) NOT NULL,
  `his_en_stock` tinyint(1) NOT NULL DEFAULT 1,
  `his_fecha` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `productos`
--

CREATE TABLE `productos` (
  `idproductos` int(11) NOT NULL,
  `pro_nombre` varchar(200) NOT NULL,
  `pro_descripcion` varchar(500) DEFAULT NULL,
  `pro_marca` varchar(100) DEFAULT NULL,
  `pro_precio` decimal(10,2) NOT NULL,
  `pro_precio_anterior` decimal(10,2) DEFAULT NULL,
  `pro_imagen` varchar(500) DEFAULT NULL,
  `pro_url` varchar(500) DEFAULT NULL,
  `pro_en_stock` tinyint(1) NOT NULL DEFAULT 1,
  `pro_fecha_scraping` datetime NOT NULL DEFAULT current_timestamp(),
  `pro_activo` tinyint(1) NOT NULL DEFAULT 1,
  `tiendas_idtiendas` int(11) NOT NULL,
  `categorias_idcategorias` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `productos_precios`
--

CREATE TABLE `productos_precios` (
  `proprecio_id` int(11) NOT NULL,
  `productos_idproductos` int(11) NOT NULL,
  `tiendas_idtiendas` int(11) NOT NULL,
  `precio` decimal(12,2) DEFAULT NULL,
  `precio_anterior` decimal(12,2) DEFAULT NULL,
  `proprecio_url` text NOT NULL,
  `proprecio_imagen` text DEFAULT NULL,
  `proprecio_stock` varchar(100) DEFAULT NULL,
  `prop_estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  `proprecio_fecha_actualizacion` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `productos_vistos`
--

CREATE TABLE `productos_vistos` (
  `id` int(11) NOT NULL,
  `usuario_idusuario` int(11) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `productos_idproductos` int(11) NOT NULL,
  `visto_en` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scraper_jobs`
--

CREATE TABLE `scraper_jobs` (
  `id` int(11) NOT NULL,
  `job_key` varchar(100) NOT NULL,
  `job_label` varchar(150) NOT NULL,
  `status` enum('pending','running','done','error','cancelled') NOT NULL DEFAULT 'pending',
  `command_path` varchar(255) NOT NULL,
  `output` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scrape_logs`
--

CREATE TABLE `scrape_logs` (
  `idscrape` int(11) NOT NULL,
  `tiendas_idtiendas` int(11) NOT NULL,
  `scrape_inicio` datetime NOT NULL,
  `scrape_fin` datetime DEFAULT NULL,
  `scrape_productos_encontrados` int(11) DEFAULT 0,
  `scrape_productos_actualizados` int(11) DEFAULT 0,
  `scrape_estado` varchar(20) NOT NULL,
  `scrape_error` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tiendas`
--

CREATE TABLE `tiendas` (
  `idtiendas` int(11) NOT NULL,
  `tie_nombre` varchar(100) NOT NULL,
  `tie_descripcion` varchar(255) DEFAULT NULL,
  `tie_logo` varchar(256) DEFAULT NULL,
  `tie_ubicacion` varchar(256) DEFAULT NULL,
  `tie_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `usuario`
--

CREATE TABLE `usuario` (
  `idusuario` int(11) NOT NULL,
  `usu_nombre` varchar(45) NOT NULL,
  `usu_email` varchar(120) NOT NULL,
  `usu_contra` varchar(255) NOT NULL,
  `usu_tipo` tinyint(2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `busquedas`
--
ALTER TABLE `busquedas`
  ADD PRIMARY KEY (`idbusqueda`),
  ADD KEY `idx_bus_normalizado` (`bus_normalizado`),
  ADD KEY `idx_bus_total` (`bus_total`),
  ADD KEY `idx_bus_usuario` (`bus_usuario_id`);

--
-- Indexes for table `busqueda_click_producto`
--
ALTER TABLE `busqueda_click_producto`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_term` (`termino`),
  ADD KEY `idx_producto` (`productos_idproductos`),
  ADD KEY `idx_fecha` (`creado_en`);

--
-- Indexes for table `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`idcategorias`),
  ADD UNIQUE KEY `uq_cat_nombre` (`cat_nombre`);

--
-- Indexes for table `favoritos`
--
ALTER TABLE `favoritos`
  ADD PRIMARY KEY (`usuario_idusuario`,`productos_idproductos`),
  ADD KEY `idx_favoritos_producto` (`productos_idproductos`);

--
-- Indexes for table `historial_precios`
--
ALTER TABLE `historial_precios`
  ADD PRIMARY KEY (`idhistorial`),
  ADD KEY `idx_historial_producto` (`productos_idproductos`);

--
-- Indexes for table `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`idproductos`),
  ADD UNIQUE KEY `uq_productos_url` (`pro_url`(255)),
  ADD KEY `idx_productos_nombre` (`pro_nombre`),
  ADD KEY `idx_productos_tienda` (`tiendas_idtiendas`),
  ADD KEY `idx_productos_categoria` (`categorias_idcategorias`),
  ADD KEY `pro_marca` (`pro_marca`);

--
-- Indexes for table `productos_precios`
--
ALTER TABLE `productos_precios`
  ADD PRIMARY KEY (`proprecio_id`),
  ADD UNIQUE KEY `uq_precio_producto_tienda_url` (`productos_idproductos`,`tiendas_idtiendas`,`proprecio_url`(255)),
  ADD KEY `idx_producto_tienda` (`productos_idproductos`,`tiendas_idtiendas`);

--
-- Indexes for table `productos_vistos`
--
ALTER TABLE `productos_vistos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vistos_usuario` (`usuario_idusuario`,`visto_en`),
  ADD KEY `idx_vistos_session` (`session_id`,`visto_en`),
  ADD KEY `idx_vistos_producto` (`productos_idproductos`,`visto_en`);

--
-- Indexes for table `scraper_jobs`
--
ALTER TABLE `scraper_jobs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `scrape_logs`
--
ALTER TABLE `scrape_logs`
  ADD PRIMARY KEY (`idscrape`),
  ADD KEY `idx_scrape_tienda` (`tiendas_idtiendas`);

--
-- Indexes for table `tiendas`
--
ALTER TABLE `tiendas`
  ADD PRIMARY KEY (`idtiendas`);

--
-- Indexes for table `usuario`
--
ALTER TABLE `usuario`
  ADD PRIMARY KEY (`idusuario`),
  ADD UNIQUE KEY `uq_usuario_email` (`usu_email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `busquedas`
--
ALTER TABLE `busquedas`
  MODIFY `idbusqueda` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `busqueda_click_producto`
--
ALTER TABLE `busqueda_click_producto`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categorias`
--
ALTER TABLE `categorias`
  MODIFY `idcategorias` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `historial_precios`
--
ALTER TABLE `historial_precios`
  MODIFY `idhistorial` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `productos`
--
ALTER TABLE `productos`
  MODIFY `idproductos` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `productos_precios`
--
ALTER TABLE `productos_precios`
  MODIFY `proprecio_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `productos_vistos`
--
ALTER TABLE `productos_vistos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scraper_jobs`
--
ALTER TABLE `scraper_jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scrape_logs`
--
ALTER TABLE `scrape_logs`
  MODIFY `idscrape` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tiendas`
--
ALTER TABLE `tiendas`
  MODIFY `idtiendas` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `usuario`
--
ALTER TABLE `usuario`
  MODIFY `idusuario` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `busquedas`
--
ALTER TABLE `busquedas`
  ADD CONSTRAINT `fk_busquedas_usuario` FOREIGN KEY (`bus_usuario_id`) REFERENCES `usuario` (`idusuario`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `favoritos`
--
ALTER TABLE `favoritos`
  ADD CONSTRAINT `fk_favoritos_producto` FOREIGN KEY (`productos_idproductos`) REFERENCES `productos` (`idproductos`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_favoritos_usuario` FOREIGN KEY (`usuario_idusuario`) REFERENCES `usuario` (`idusuario`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `historial_precios`
--
ALTER TABLE `historial_precios`
  ADD CONSTRAINT `fk_historial_producto` FOREIGN KEY (`productos_idproductos`) REFERENCES `productos` (`idproductos`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `productos`
--
ALTER TABLE `productos`
  ADD CONSTRAINT `fk_productos_categorias` FOREIGN KEY (`categorias_idcategorias`) REFERENCES `categorias` (`idcategorias`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_productos_tiendas` FOREIGN KEY (`tiendas_idtiendas`) REFERENCES `tiendas` (`idtiendas`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `scrape_logs`
--
ALTER TABLE `scrape_logs`
  ADD CONSTRAINT `fk_scrape_tienda` FOREIGN KEY (`tiendas_idtiendas`) REFERENCES `tiendas` (`idtiendas`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
