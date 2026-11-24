-- Preferencias personales del POS y firma digital del operador.
CREATE TABLE IF NOT EXISTS pos_usuario_preferencia (
    cod_usuario BIGINT NOT NULL PRIMARY KEY,
    configuracion_json JSON NOT NULL,
    firma_path VARCHAR(255) NULL,
    firma_nombre VARCHAR(255) NULL,
    firma_mime VARCHAR(100) NULL,
    firma_actualizada_en DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
