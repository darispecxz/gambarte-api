CREATE TABLE IF NOT EXISTS control_arqueos_caja (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    id_caja INT NOT NULL,
    fecha_corte DATETIME NOT NULL,
    fecha_conteo DATETIME NOT NULL,
    detalle_json LONGTEXT NOT NULL,
    total_contado DECIMAL(18,2) NOT NULL,
    saldo_teorico_corte DECIMAL(18,2) NOT NULL,
    cierre_declarado_corte DECIMAL(18,2) NOT NULL,
    contado_por VARCHAR(160) NOT NULL,
    presenciado_por VARCHAR(160) NOT NULL,
    observaciones TEXT NULL,
    registrado_por VARCHAR(160) NOT NULL,
    registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_control_arqueos_fecha (fecha, id_caja, id),
    INDEX idx_control_arqueos_caja (id_caja, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS control_arqueos_seguimiento (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    id_caja INT NOT NULL,
    estado VARCHAR(30) NOT NULL,
    responsable VARCHAR(160) NOT NULL,
    fecha_compromiso DATE NULL,
    observacion TEXT NOT NULL,
    registrado_por VARCHAR(160) NOT NULL,
    registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_control_arqueos_seguimiento (fecha, id_caja, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
