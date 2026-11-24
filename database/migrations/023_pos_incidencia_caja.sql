CREATE TABLE IF NOT EXISTS pos_incidencia_caja (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_caja INT NOT NULL, id_agencia INT NOT NULL, cod_usuario BIGINT NOT NULL,
    fecha DATE NOT NULL, tipo ENUM('faltante','sobrante') NOT NULL,
    diferencia DECIMAL(14,4) NOT NULL, justificacion TEXT NOT NULL,
    declaracion_firmada_en DATETIME NOT NULL, firma_path VARCHAR(255) NULL,
    comprobante_path VARCHAR(255) NULL, comprobante_nombre VARCHAR(255) NULL,
    notificado_rrhh_en DATETIME NULL, creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_incidencia_caja (id_caja, fecha), KEY idx_incidencia_usuario (cod_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
