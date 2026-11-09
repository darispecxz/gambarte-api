CREATE TABLE IF NOT EXISTS `cotizacion_chile_cache` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_destino` INT NOT NULL,
  `valor` DECIMAL(18,6) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_destino_fecha` (`id_destino`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
