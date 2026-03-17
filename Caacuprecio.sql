SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE SCHEMA IF NOT EXISTS `Caacuprecio` DEFAULT CHARACTER SET utf8mb4;
USE `Caacuprecio`;

-- -----------------------------------------------------
-- Table: tiendas
-- -----------------------------------------------------
CREATE TABLE `tiendas` (
  `idtiendas` INT NOT NULL AUTO_INCREMENT,
  `tie_nombre` VARCHAR(100) NOT NULL,
  `tie_descripcion` VARCHAR(255) NULL,
  `tie_logo` VARCHAR(256) NULL,
  `tie_ubicacion` VARCHAR(256) NULL,
  `tie_url` VARCHAR(255) NULL,
  PRIMARY KEY (`idtiendas`)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: categorias
-- -----------------------------------------------------
CREATE TABLE `categorias` (
  `idcategorias` INT NOT NULL AUTO_INCREMENT,
  `cat_nombre` VARCHAR(100) NOT NULL,
  `cat_descripcion` VARCHAR(255) NULL,
  PRIMARY KEY (`idcategorias`),
  UNIQUE KEY `uq_cat_nombre` (`cat_nombre`)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: usuario
-- -----------------------------------------------------
CREATE TABLE `usuario` (
  `idusuario` INT NOT NULL AUTO_INCREMENT,
  `usu_nombre` VARCHAR(45) NOT NULL,
  `usu_email` VARCHAR(120) NOT NULL,
  `usu_contra` VARCHAR(255) NOT NULL,
  `usu_tipo` TINYINT(2) NOT NULL,
  PRIMARY KEY (`idusuario`),
  UNIQUE KEY `uq_usuario_email` (`usu_email`)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: productos
-- cada fila = producto scrapeado de una tienda
-- -----------------------------------------------------
CREATE TABLE `productos` (
  `idproductos` INT NOT NULL AUTO_INCREMENT,
  `pro_nombre` VARCHAR(200) NOT NULL,
  `pro_descripcion` VARCHAR(500) NULL,
  `pro_precio` DECIMAL(10,2) NOT NULL,
  `pro_precio_anterior` DECIMAL(10,2) NULL,
  `pro_imagen` VARCHAR(500) NULL,
  `pro_url` VARCHAR(500) NULL,
  `pro_en_stock` TINYINT(1) NOT NULL DEFAULT 1,
  `pro_moneda` VARCHAR(10) NOT NULL DEFAULT 'PYG',
  `pro_fecha_scraping` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pro_activo` TINYINT(1) NOT NULL DEFAULT 1,
  `tiendas_idtiendas` INT NOT NULL,
  `categorias_idcategorias` INT NULL,
  PRIMARY KEY (`idproductos`),

  INDEX `idx_productos_nombre` (`pro_nombre`),
  INDEX `idx_productos_tienda` (`tiendas_idtiendas`),
  INDEX `idx_productos_categoria` (`categorias_idcategorias`),

  CONSTRAINT `fk_productos_tiendas`
    FOREIGN KEY (`tiendas_idtiendas`)
    REFERENCES `tiendas` (`idtiendas`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT `fk_productos_categorias`
    FOREIGN KEY (`categorias_idcategorias`)
    REFERENCES `categorias` (`idcategorias`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: favoritos
-- usuarios pueden seguir productos
-- -----------------------------------------------------
CREATE TABLE `favoritos` (
  `usuario_idusuario` INT NOT NULL,
  `productos_idproductos` INT NOT NULL,
  `fav_fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`usuario_idusuario`, `productos_idproductos`),

  INDEX `idx_favoritos_producto` (`productos_idproductos`),

  CONSTRAINT `fk_favoritos_usuario`
    FOREIGN KEY (`usuario_idusuario`)
    REFERENCES `usuario` (`idusuario`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,

  CONSTRAINT `fk_favoritos_producto`
    FOREIGN KEY (`productos_idproductos`)
    REFERENCES `productos` (`idproductos`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: historial_precios
-- guarda cambios de precio y stock
-- -----------------------------------------------------
CREATE TABLE `historial_precios` (
  `idhistorial` INT NOT NULL AUTO_INCREMENT,
  `productos_idproductos` INT NOT NULL,
  `his_precio` DECIMAL(10,2) NOT NULL,
  `his_en_stock` TINYINT(1) NOT NULL DEFAULT 1,
  `his_fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`idhistorial`),

  INDEX `idx_historial_producto` (`productos_idproductos`),

  CONSTRAINT `fk_historial_producto`
    FOREIGN KEY (`productos_idproductos`)
    REFERENCES `productos` (`idproductos`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Table: scrape_logs
-- registra cada ejecución de scraping
-- -----------------------------------------------------
CREATE TABLE `scrape_logs` (
  `idscrape` INT NOT NULL AUTO_INCREMENT,
  `tiendas_idtiendas` INT NOT NULL,
  `scrape_inicio` DATETIME NOT NULL,
  `scrape_fin` DATETIME NULL,
  `scrape_productos_encontrados` INT DEFAULT 0,
  `scrape_productos_actualizados` INT DEFAULT 0,
  `scrape_estado` VARCHAR(20) NOT NULL,
  `scrape_error` TEXT NULL,

  PRIMARY KEY (`idscrape`),

  INDEX `idx_scrape_tienda` (`tiendas_idtiendas`),

  CONSTRAINT `fk_scrape_tienda`
    FOREIGN KEY (`tiendas_idtiendas`)
    REFERENCES `tiendas` (`idtiendas`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE productos_precios (
    proprecio_id INT AUTO_INCREMENT PRIMARY KEY,
    productos_idproductos INT NOT NULL,
    tiendas_idtiendas INT NOT NULL,
    precio DECIMAL(12,2) NULL,
    precio_anterior DECIMAL(12,2) NULL,
    proprecio_url TEXT NOT NULL,
    proprecio_imagen TEXT NULL,
    proprecio_stock VARCHAR(100) NULL,
    prop_estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    proprecio_fecha_actualizacion DATETIME NOT NULL,
    KEY idx_producto_tienda (productos_idproductos, tiendas_idtiendas)
);

ALTER TABLE productos
ADD UNIQUE KEY uq_productos_url (pro_url(255));

ALTER TABLE productos_precios
ADD UNIQUE KEY uq_precio_producto_tienda_url (
    productos_idproductos,
    tiendas_idtiendas,
    proprecio_url(255)
);

SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;