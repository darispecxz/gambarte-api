-- ============================================================
-- 007: Módulo de BANCOS en el POS nuevo
--
-- Objetivo: operar bancos desde GambartePOS SIN depender del legacy
-- (C:\wamp64\www\bo_cgr_hostinger\bancos), que se apagará.
--
-- Convivencia: se REUTILIZAN las tablas de negocio del legacy
-- (`traspaso_cuenta_bancaria`, `movimiento_cuenta_bancaria`,
-- `cuenta_bancaria`) para que los mismos datos sigan alimentando la
-- contabilidad y los reportes, y se AÑADEN tablas propias para lo que
-- el legacy no puede dar:
--
--   · idempotencia por uuid (el legacy no la tiene: un doble submit
--     crea dos traspasos)
--   · estados con motivo y auditoría de recepción/reversión
--   · trazabilidad operación ↔ asiento contable
--   · cheque ↔ movimiento bancario (el legacy solo lo liga por el
--     texto de la glosa)
--
-- Los IDs de `banco_*` arrancan en 9.000.000 para no colisionar con la
-- secuencia del legacy (hoy en ~4730).
-- ============================================================

-- ------------------------------------------------------------
-- 1. Traspasos entre cuentas bancarias (con idempotencia y auditoría)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS banco_traspaso (
    id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                       CHAR(36)        NOT NULL COMMENT 'Idempotencia del POS',

    -- Origen
    id_agencia_origen          INT             NOT NULL,
    id_entidad_origen          INT             NOT NULL,
    id_tipo_cuenta_origen      INT             NOT NULL,
    num_cuenta_origen          VARCHAR(20)     NOT NULL,
    id_moneda                  INT             NOT NULL,
    documento_origen           VARCHAR(30)     NULL COMMENT 'N° de transacción/comprobante del origen',

    -- Destino
    id_agencia_destino         INT             NOT NULL,
    id_entidad_destino         INT             NOT NULL,
    id_tipo_cuenta_destino     INT             NOT NULL,
    num_cuenta_destino         VARCHAR(20)     NOT NULL,
    documento_destino          VARCHAR(30)     NULL COMMENT 'N° que teclea el destino al aceptar',

    monto                      DECIMAL(16,4)   NOT NULL,
    glosa                      TEXT            NULL,
    observaciones              TEXT            NULL COMMENT 'Motivo en rechazo/reversión',

    -- P = pendiente (cargo hecho al origen, esperando al destino)
    -- A = aceptada  (acreditada al destino + asiento contable)
    -- X = rechazada por el destino (se devuelve al origen)
    -- R = revertida por el origen
    estado                     CHAR(1)         NOT NULL DEFAULT 'P',
    motivo_rechazo             TEXT            NULL,

    -- Auditoría
    cod_usuario_origen         BIGINT          NOT NULL,
    cod_usuario_destino        BIGINT          NULL,
    cod_usuario_reversion      BIGINT          NULL,
    fec_recepcion              DATETIME        NULL,
    fec_reversion              DATETIME        NULL,
    ip_origen                  VARCHAR(45)     NULL,
    ip_recepcion               VARCHAR(45)     NULL,

    created_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_uuid (uuid),
    KEY idx_estado_destino (estado, id_agencia_destino),
    KEY idx_estado_origen (estado, id_agencia_origen),
    KEY idx_origen (id_agencia_origen, num_cuenta_origen, created_at),
    KEY idx_destino (id_agencia_destino, num_cuenta_destino, created_at),
    KEY idx_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Trazabilidad operación → asiento contable
--
-- Permite revertir el asiento correcto sin depender de la glosa.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS banco_operacion_asiento (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid           CHAR(36)        NOT NULL COMMENT 'uuid de la operación de origen',
    tipo_operacion VARCHAR(30)     NOT NULL COMMENT 'traspaso_aceptacion | traspaso_reversion | deposito | retiro | cobro_cheque',
    base_datos     VARCHAR(10)     NOT NULL DEFAULT 'conta' COMMENT 'Dónde vive el asiento',
    idasiento      BIGINT          NOT NULL,
    codejercicio   VARCHAR(4)      NULL,
    numero         INT             NULL,
    glosa          TEXT            NULL,
    monto_bob      DECIMAL(16,4)   NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_uuid (uuid),
    KEY idx_tipo (tipo_operacion),
    KEY idx_asiento (base_datos, idasiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Depósitos y retiros caja ↔ banco
--
-- El legacy los dispersa entre saldo_caja, saldo_cuenta_bancaria y
-- deposito_cuenta, sin un registro único que los ligue. Aquí se
-- centraliza el documento operativo.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS banco_deposito (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)        NOT NULL,

    -- 'D' = depósito (efectivo de caja → banco)
    -- 'R' = retiro   (banco → efectivo de caja)
    tipo                CHAR(1)         NOT NULL DEFAULT 'D',

    id_agencia          INT             NOT NULL,
    id_caja             INT             NULL COMMENT 'Caja que entrega/recibe el efectivo',
    id_entidad          INT             NOT NULL,
    id_tipo_cuenta      INT             NOT NULL,
    num_cuenta          VARCHAR(20)     NOT NULL,
    id_moneda           INT             NOT NULL,

    monto               DECIMAL(16,4)   NOT NULL,
    monto_bob           DECIMAL(16,4)   NULL,
    tipo_cambio         DECIMAL(16,6)   NULL,
    referencia          VARCHAR(100)    NULL COMMENT 'N° de boleta/comprobante del banco',
    glosa               TEXT            NULL,
    comprobante_path    VARCHAR(255)    NULL,

    -- P = pendiente de aprobación | A = aprobado | X = rechazado
    estado              CHAR(1)         NOT NULL DEFAULT 'A',

    cod_usuario         BIGINT          NOT NULL,
    cod_usuario_aprobador BIGINT        NULL,
    fec_aprobacion      DATETIME        NULL,
    observaciones       TEXT            NULL,
    ip_origen           VARCHAR(45)     NULL,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_uuid (uuid),
    KEY idx_agencia_fecha (id_agencia, created_at),
    KEY idx_cuenta (id_agencia, num_cuenta, created_at),
    KEY idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Trazabilidad de cheques
--
-- `movimiento_cuenta_bancaria` (legacy) NO guarda id_cheque, así que
-- hoy la única liga es el texto de la glosa. Esta tabla da la relación
-- real y permite revertir una anulación.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS banco_cheque_mov (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid               CHAR(36)        NOT NULL,

    id_chequera        INT             NOT NULL,
    id_cheque          INT             NOT NULL,

    id_agencia         INT             NOT NULL,
    id_entidad         INT             NOT NULL,
    id_tipo_cuenta     INT             NOT NULL,
    num_cuenta         VARCHAR(20)     NOT NULL,
    id_moneda          INT             NOT NULL,

    monto              DECIMAL(16,4)   NOT NULL,
    monto_bob          DECIMAL(16,4)   NULL,
    tipo_cambio        DECIMAL(16,6)   NULL,

    -- E = emitido | C = cobrado | N = anulado | R = reversion de anulacion
    tipo_movimiento    CHAR(1)         NOT NULL,
    estado_anterior    CHAR(1)         NULL,
    estado_nuevo       CHAR(1)         NULL,

    beneficiario       VARCHAR(200)    NULL,
    concepto           VARCHAR(1000)   NULL,
    id_categoria       INT             NULL COMMENT 'FK categoria_cheque',
    glosa              TEXT            NULL,
    cod_usuario        BIGINT          NOT NULL,
    ip_origen          VARCHAR(45)     NULL,

    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_uuid (uuid),
    KEY idx_cheque (id_chequera, id_cheque),
    KEY idx_agencia_fecha (id_agencia, created_at),
    KEY idx_tipo (tipo_movimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Reversión de anulación de cheque (evita dobles reversiones)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS banco_cheque_anulacion (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)        NOT NULL,
    id_chequera     INT             NOT NULL,
    id_cheque       INT             NOT NULL,
    motivo          TEXT            NULL,
    estado_anterior CHAR(1)         NOT NULL,
    cod_usuario     BIGINT          NOT NULL,
    revertida       TINYINT(1)      NOT NULL DEFAULT 0,
    uuid_reversion  CHAR(36)        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_uuid (uuid),
    KEY idx_cheque (id_chequera, id_cheque),
    KEY idx_revertida (revertida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
