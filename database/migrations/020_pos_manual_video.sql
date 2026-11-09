-- Video asociado a cada manual del POS; el archivo vive en el almacenamiento privado del backend.
CREATE TABLE IF NOT EXISTS pos_manual_video (
    manual_key VARCHAR(80) NOT NULL PRIMARY KEY,
    archivo_path VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'video/mp4',
    tamano_bytes BIGINT UNSIGNED NOT NULL,
    cod_usuario_actualiza BIGINT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_manual_video_actualiza (cod_usuario_actualiza)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
