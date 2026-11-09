-- ============================================================
-- 005: Egresos con flujo de aprobación
-- Permite al cajero registrar egresos inmediatamente
-- y que el contador los apruebe con documento respaldatorio.
-- ============================================================

CREATE TABLE IF NOT EXISTS pos_egreso (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    origen ENUM('caja', 'banca') NOT NULL DEFAULT 'caja',

    -- Cuando origen = 'caja'
    id_caja INT NULL,
    id_saldo_caja BIGINT UNSIGNED NULL COMMENT 'FK saldo_caja del movimiento EA',

    -- Siempre
    id_agencia INT NOT NULL,
    id_moneda INT NOT NULL,
    monto DECIMAL(16,6) NOT NULL,
    concepto TEXT NOT NULL,
    beneficiario VARCHAR(200) NULL COMMENT 'Persona o entidad que recibe el dinero',
    categoria ENUM(
        'gastos_operativos', 'sueldos', 'servicios', 'impuestos',
        'alquileres', 'mantenimiento', 'compras', 'otros'
    ) NOT NULL DEFAULT 'otros',

    -- Aprobación
    estado ENUM('pendiente', 'aprobado', 'rechazado', 'anulado') NOT NULL DEFAULT 'pendiente',
    comprobante_path VARCHAR(500) NULL COMMENT 'Ruta del documento subido por cajero o contador',
    comprobante_nombre VARCHAR(200) NULL COMMENT 'Nombre original del archivo',

    -- Cajero que crea
    cod_usuario_cajero BIGINT NOT NULL,
    ip_cajero VARCHAR(45) NOT NULL,

    -- Contador que aprueba/rechaza
    cod_usuario_aprobador BIGINT NULL,
    fecha_aprobacion DATETIME NULL,
    observaciones_aprobacion TEXT NULL,
    comprobante_aprobacion_path VARCHAR(500) NULL COMMENT 'Documento del contador',
    comprobante_aprobacion_nombre VARCHAR(200) NULL,

    -- Si rechazado, el monto se revierte
    id_saldo_caja_reversion BIGINT UNSIGNED NULL COMMENT 'FK saldo_caja del reverso si rechazado',

    -- Referencia contable
    id_operacion_log BIGINT UNSIGNED NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_uuid (uuid),
    KEY idx_estado (estado),
    KEY idx_agencia_fecha (id_agencia, created_at),
    KEY idx_cajero (cod_usuario_cajero, created_at),
    KEY idx_caja (id_caja, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
