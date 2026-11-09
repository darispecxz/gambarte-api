-- QR estático de cobro Binance Pay administrado por Tesorería.
-- El endpoint de lectura requiere JWT; la actualización solo está habilitada para el rol administrador.

CREATE TABLE IF NOT EXISTS pos_CriptoConfiguracion (
    clave VARCHAR(64) NOT NULL PRIMARY KEY,
    qr_data MEDIUMBLOB NOT NULL,
    mime_type VARCHAR(32) NOT NULL,
    cod_usuario_actualiza BIGINT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
