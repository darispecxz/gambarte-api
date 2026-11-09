
-- GambartePOS · Operaciones de activos digitales, fase 1 (liquidación manual).
-- No almacena llaves privadas ni firma transacciones.

CREATE TABLE IF NOT EXISTS pos_CriptoActivo (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    simbolo VARCHAR(16) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    red VARCHAR(48) NOT NULL,
    contrato VARCHAR(128) NULL,
    decimales TINYINT UNSIGNED NOT NULL DEFAULT 6,
    tasa_compra_bob DECIMAL(18,8) NOT NULL,
    tasa_venta_bob DECIMAL(18,8) NOT NULL,
    comision_porcentaje DECIMAL(7,4) NOT NULL DEFAULT 0,
    direccion_recepcion VARCHAR(180) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    cod_usuario_actualiza BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pos_cripto_activo_red (simbolo, red),
    KEY idx_pos_cripto_activo_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_CriptoOperacion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    tipo ENUM('COMPRA','VENTA') NOT NULL,
    id_activo BIGINT UNSIGNED NOT NULL,
    id_agencia INT NOT NULL,
    id_caja INT NOT NULL,
    cod_usuario_cajero BIGINT NOT NULL,
    cantidad_cripto DECIMAL(28,10) NOT NULL,
    tasa_bob DECIMAL(18,8) NOT NULL,
    porcentaje_comision DECIMAL(7,4) NOT NULL DEFAULT 0,
    monto_bruto_bob DECIMAL(18,2) NOT NULL,
    comision_bob DECIMAL(18,2) NOT NULL DEFAULT 0,
    monto_bob DECIMAL(18,2) NOT NULL,
    direccion_cliente VARCHAR(180) NULL,
    direccion_tesoreria VARCHAR(180) NULL,
    hash_transaccion VARCHAR(180) NULL,
    estado ENUM('PENDIENTE_RECEPCION','PENDIENTE_PAGO_BOB','PENDIENTE_COBRO_BOB','PENDIENTE_ENVIO','COMPLETADA','RECHAZADA','CANCELADA','INCIDENCIA') NOT NULL,
    concepto VARCHAR(240) NULL,
    id_saldo_caja BIGINT NULL,
    cod_usuario_tesoreria BIGINT NULL,
    fecha_confirmacion DATETIME NULL,
    motivo_cierre VARCHAR(500) NULL,
    ip_creacion VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pos_cripto_operacion_uuid (uuid),
    KEY idx_pos_cripto_operacion_agencia_estado (id_agencia, estado, created_at),
    KEY idx_pos_cripto_operacion_caja_fecha (id_caja, created_at),
    KEY idx_pos_cripto_operacion_activo (id_activo, created_at),
    CONSTRAINT fk_pos_cripto_operacion_activo FOREIGN KEY (id_activo) REFERENCES pos_CriptoActivo(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_CriptoEvento (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_operacion BIGINT UNSIGNED NOT NULL,
    accion VARCHAR(32) NOT NULL,
    estado_anterior VARCHAR(32) NULL,
    estado_nuevo VARCHAR(32) NOT NULL,
    cod_usuario BIGINT NOT NULL,
    referencia VARCHAR(180) NULL,
    motivo VARCHAR(500) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pos_cripto_evento_operacion_fecha (id_operacion, created_at),
    CONSTRAINT fk_pos_cripto_evento_operacion FOREIGN KEY (id_operacion) REFERENCES pos_CriptoOperacion(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_CriptoMovimientoTesoreria (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_activo BIGINT UNSIGNED NOT NULL,
    id_operacion BIGINT UNSIGNED NULL,
    tipo ENUM('RECEPCION','ENVIO','AJUSTE') NOT NULL,
    cantidad DECIMAL(28,10) NOT NULL COMMENT 'Entrada positiva; salida negativa.',
    referencia VARCHAR(180) NULL,
    cod_usuario BIGINT NOT NULL,
    motivo VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pos_cripto_mov_activo_fecha (id_activo, created_at),
    KEY idx_pos_cripto_mov_operacion (id_operacion),
    CONSTRAINT fk_pos_cripto_mov_activo FOREIGN KEY (id_activo) REFERENCES pos_CriptoActivo(id) ON UPDATE CASCADE,
    CONSTRAINT fk_pos_cripto_mov_operacion FOREIGN KEY (id_operacion) REFERENCES pos_CriptoOperacion(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
