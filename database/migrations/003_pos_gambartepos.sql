-- ============================================================
-- GambartePOS — Tablas nuevas para el módulo de cajeros
-- Ejecutar contra: u752765750_cgr
-- Fecha: 2026-09-18
-- ============================================================

-- Sesión diaria de caja (reemplaza queries frágiles de estado)
CREATE TABLE IF NOT EXISTS pos_sesion_caja (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_caja INT NOT NULL,
    cod_usuario BIGINT NOT NULL,
    id_agencia INT NOT NULL,
    id_moneda INT NOT NULL,
    fecha DATE NOT NULL,
    estado ENUM('abierta', 'cerrada', 'forzada') NOT NULL DEFAULT 'abierta',
    saldo_apertura DECIMAL(14,4) NOT NULL,
    saldo_cierre DECIMAL(14,4) NULL,
    saldo_esperado DECIMAL(14,4) NULL,
    diferencia DECIMAL(14,4) NULL,
    hora_apertura DATETIME NOT NULL,
    hora_cierre DATETIME NULL,
    ip_apertura VARCHAR(45) NOT NULL,
    ip_cierre VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_caja_fecha (id_caja, fecha),
    KEY idx_usuario_fecha (cod_usuario, fecha),
    KEY idx_agencia_fecha (id_agencia, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesión diaria de bóveda
CREATE TABLE IF NOT EXISTS pos_sesion_boveda (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_boveda INT NOT NULL,
    cod_usuario BIGINT NOT NULL,
    id_agencia INT NOT NULL,
    id_moneda INT NOT NULL,
    fecha DATE NOT NULL,
    estado ENUM('abierta', 'cerrada', 'forzada') NOT NULL DEFAULT 'abierta',
    saldo_apertura DECIMAL(14,4) NOT NULL,
    saldo_cierre DECIMAL(14,4) NULL,
    saldo_esperado DECIMAL(14,4) NULL,
    diferencia DECIMAL(14,4) NULL,
    hora_apertura DATETIME NOT NULL,
    hora_cierre DATETIME NULL,
    ip_apertura VARCHAR(45) NOT NULL,
    ip_cierre VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_boveda_fecha (id_boveda, fecha),
    KEY idx_usuario_fecha (cod_usuario, fecha),
    KEY idx_agencia_fecha (id_agencia, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de operaciones (auditoría + idempotencia)
CREATE TABLE IF NOT EXISTS pos_operacion_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    tipo_operacion ENUM(
        'apertura_caja', 'cierre_caja',
        'apertura_boveda', 'cierre_boveda',
        'cambio', 'giro', 'remesa',
        'pago_giro', 'pago_remesa',
        'devolucion_giro', 'devolucion_remesa',
        'traspaso_boveda', 'solicitud_fondos', 'traspaso_cajero',
        'egreso', 'ingreso',
        'transferencia_boveda', 'transaccion_bancaria',
        'anulacion', 'facturacion'
    ) NOT NULL,
    id_referencia BIGINT NULL COMMENT 'PK de la tabla específica (id_cambio, id_giro, etc.)',
    tabla_referencia VARCHAR(50) NULL COMMENT 'Nombre de la tabla referenciada',
    id_caja INT NULL,
    id_boveda INT NULL,
    cod_usuario BIGINT NOT NULL,
    id_agencia INT NOT NULL,
    id_moneda INT NULL,
    monto_total DECIMAL(16,6) NULL,
    monto_total_bob DECIMAL(16,6) NULL COMMENT 'Equivalente BOB para auditoría UIF',
    estado ENUM('completado', 'anulado', 'error', 'pendiente') NOT NULL DEFAULT 'completado',
    ip_origen VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    metadata JSON NULL COMMENT 'Datos adicionales para debug/auditoría',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_uuid (uuid),
    KEY idx_tipo_fecha (tipo_operacion, created_at),
    KEY idx_usuario_fecha (cod_usuario, created_at),
    KEY idx_caja_fecha (id_caja, created_at),
    KEY idx_agencia_fecha (id_agencia, created_at),
    KEY idx_referencia (tabla_referencia, id_referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cola de asientos contables pendientes
CREATE TABLE IF NOT EXISTS pos_asiento_pendiente (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_operacion_log BIGINT UNSIGNED NOT NULL,
    tipo_operacion VARCHAR(30) NOT NULL,
    codejercicio VARCHAR(4) NOT NULL,
    fecha DATE NOT NULL,
    concepto TEXT NOT NULL,
    documento VARCHAR(30) NULL,
    partidas JSON NOT NULL COMMENT '[{codsubcuenta, debe, haber, concepto}]',
    estado ENUM('pendiente', 'sincronizado', 'error', 'reintentando') NOT NULL DEFAULT 'pendiente',
    intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_intentos SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    error_mensaje TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at DATETIME NULL,
    KEY idx_estado (estado),
    KEY idx_operacion_log (id_operacion_log),
    KEY idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recibos/PDFs generados (para re-impresión y envío por email)
CREATE TABLE IF NOT EXISTS pos_recibo (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tipo_operacion VARCHAR(30) NOT NULL,
    id_referencia BIGINT NOT NULL,
    id_operacion_log BIGINT UNSIGNED NULL,
    pdf_path VARCHAR(255) NULL COMMENT 'Ruta al PDF generado en storage',
    pdf_generado TINYINT(1) NOT NULL DEFAULT 0,
    email_cliente VARCHAR(100) NULL,
    email_enviado TINYINT(1) NOT NULL DEFAULT 0,
    email_enviado_at DATETIME NULL,
    impreso TINYINT(1) NOT NULL DEFAULT 0,
    impreso_at DATETIME NULL,
    veces_impreso SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tipo_ref (tipo_operacion, id_referencia),
    KEY idx_operacion_log (id_operacion_log)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notificaciones a clientes (email, SMS, WhatsApp)
CREATE TABLE IF NOT EXISTS pos_notificacion_operacion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canal ENUM('email', 'sms', 'whatsapp', 'telegram') NOT NULL,
    destinatario VARCHAR(150) NOT NULL COMMENT 'Email o número de teléfono',
    tipo_operacion VARCHAR(30) NOT NULL,
    id_referencia BIGINT NOT NULL,
    asunto VARCHAR(255) NULL,
    contenido TEXT NOT NULL,
    estado ENUM('pendiente', 'enviado', 'error') NOT NULL DEFAULT 'pendiente',
    intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error_mensaje TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    KEY idx_estado (estado),
    KEY idx_tipo_ref (tipo_operacion, id_referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Método de pago por operación
CREATE TABLE IF NOT EXISTS pos_metodo_pago_operacion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tipo_operacion VARCHAR(30) NOT NULL,
    id_referencia BIGINT NOT NULL,
    id_forma_pago INT NOT NULL DEFAULT 1 COMMENT 'FK forma_pago: 1=efectivo, 2=transferencia',
    comprobante_path VARCHAR(255) NULL COMMENT 'Ruta al comprobante subido',
    referencia_bancaria VARCHAR(100) NULL COMMENT 'Número de transferencia/depósito',
    observaciones TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tipo_ref (tipo_operacion, id_referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
