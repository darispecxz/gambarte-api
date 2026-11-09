-- Seguimiento de lectura de manuales del POS.
CREATE TABLE IF NOT EXISTS pos_manual_lectura (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cod_usuario BIGINT NOT NULL,
    id_agencia INT NOT NULL,
    manual_key VARCHAR(80) NOT NULL,
    version VARCHAR(20) NOT NULL DEFAULT '1',
    leido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usuario_agencia_manual_version (cod_usuario, id_agencia, manual_key, version),
    KEY idx_manual_agencia (manual_key, id_agencia),
    KEY idx_usuario (cod_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
