-- Historial Binance Pay de solo lectura y conciliación por caja.
-- La clave/secret de Binance se configuran como variables de entorno, nunca en estas tablas.
CREATE TABLE IF NOT EXISTS Pos_CriptoPayTransaccion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id VARCHAR(64) NOT NULL,
    order_id VARCHAR(64) NULL,
    order_type VARCHAR(40) NOT NULL,
    transaction_time DATETIME NOT NULL,
    amount DECIMAL(28,10) NOT NULL,
    currency VARCHAR(16) NOT NULL,
    wallet_type VARCHAR(24) NULL,
    payer_binance_id VARCHAR(32) NULL,
    receiver_binance_id VARCHAR(32) NULL,
    receptor_verificado TINYINT(1) NOT NULL DEFAULT 0,
    id_operacion BIGINT UNSIGNED NULL,
    cod_usuario_confirma BIGINT NULL,
    confirmado_at DATETIME NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pos_cripto_pay_transaction (transaction_id),
    UNIQUE KEY uk_pos_cripto_pay_operation (id_operacion),
    KEY idx_pos_cripto_pay_time_type (transaction_time, order_type),
    KEY idx_pos_cripto_pay_currency_amount (currency, amount),
    CONSTRAINT fk_pos_cripto_pay_operation FOREIGN KEY (id_operacion)
      REFERENCES Pos_CriptoOperacion(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Pos_CriptoPaySync (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    last_error VARCHAR(240) NULL,
    last_records INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO Pos_CriptoPaySync (id) VALUES (1);
