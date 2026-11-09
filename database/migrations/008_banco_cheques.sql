-- ============================================================
-- 008: Envío y recepción de cheques entre agencias
--
-- EJECUTAR CONTRA LA BASE DE OPERACIONES (`u752765750_cgr`), NO contra
-- contabilidad. Todas las tablas `banco_*`, `cheque`, `chequera` y
-- `registro_cheque` viven en operaciones.
--
-- El módulo de cheques NO escribe nada en contabilidad: el legacy tampoco lo
-- hacía, y el POS nuevo mantiene ese criterio.
--
-- El legacy NO tiene este flujo: `enviocheque` y `recepcheque` son en
-- realidad pantallas de transferencia banco-a-banco (idénticas byte a byte).
-- Se añaden las columnas que hacían falta para mover un cheque físico entre
-- agencias con confirmación.
--
-- Estados de `cheque.estado` (se conservan los del legacy y se añaden dos):
--   I = en chequera, no emitido      (legacy)
--   A = emitido                      (legacy)
--   E = ENVIADO a otra agencia       (nuevo)
--   T = RECIBIDO en la agencia destino (nuevo)
--   C = cobrado                      (legacy)
--   N = anulado                      (legacy)
-- ============================================================

ALTER TABLE banco_cheque_mov
    ADD COLUMN IF NOT EXISTS id_agencia_destino INT NULL
        COMMENT 'Agencia destino en envío/recepción' AFTER id_agencia,
    ADD COLUMN IF NOT EXISTS id_mov_padre BIGINT UNSIGNED NULL
        COMMENT 'Movimiento que origina éste (envío -> recepción)' AFTER id_cheque,
    ADD COLUMN IF NOT EXISTS tercero VARCHAR(200) NULL
        COMMENT 'Tercera persona que cobra el cheque',
    ADD COLUMN IF NOT EXISTS adjunto_path VARCHAR(255) NULL
        COMMENT 'Comprobante adjunto del movimiento';

-- Categorías de cheque: el legacy liga por texto, aquí ya se usa el id real.
-- (categoria_cheque ya existe: 1=FONDEO, 2=TRANSFERENCIA DE OTRA AGENCIA,
--  3=FALLA DE CAJA, 4=TRANSFERENCIA EXTERNA CLIENTE)

-- Trazabilidad del envío pendiente de recibir: evita que un cheque "enviado"
-- se quede sin forma de saber quién lo tiene.
CREATE TABLE IF NOT EXISTS banco_cheque_envio (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                 CHAR(36)        NOT NULL,

    id_chequera          INT             NOT NULL,
    id_cheque            INT             NOT NULL,

    id_agencia_origen    INT             NOT NULL,
    id_agencia_destino   INT             NOT NULL,

    -- Motivo/categoría del envío (categoria_cheque)
    id_categoria         INT             NULL,
    observaciones        TEXT            NULL,

    -- P = pendiente de recepción | R = recibido | X = rechazado
    estado               CHAR(1)         NOT NULL DEFAULT 'P',

    cod_usuario_origen   BIGINT          NOT NULL,
    cod_usuario_destino  BIGINT          NULL,
    fec_envio            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fec_recepcion        DATETIME        NULL,
    motivo_rechazo       TEXT            NULL,
    ip_origen            VARCHAR(45)     NULL,
    ip_recepcion         VARCHAR(45)     NULL,

    UNIQUE KEY uk_uuid (uuid),
    KEY idx_cheque (id_chequera, id_cheque),
    KEY idx_destino_estado (estado, id_agencia_destino),
    KEY idx_origen_estado (estado, id_agencia_origen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
