-- ============================================================
-- 010: Conciliación de extractos bancarios
--
-- EJECUTAR CONTRA LA BASE DE OPERACIONES (`u752765750_cgr`), NO contra
-- contabilidad.
--
-- Al cerrar la banca el cajero sube el extracto del banco en PDF. El sistema
-- extrae las líneas del extracto y las concilia contra el libro
-- `movimiento_cuenta_bancaria`, de modo que:
--
--   1. Toda línea del extracto debe tener su movimiento en el libro. Las que no
--      lo tengan se reportan como FALTANTES DE REGISTRAR.
--   2. Los movimientos del libro que no estén en el extracto se reportan como
--      REGISTRADOS DE MÁS (posible duplicado o error de digitación).
--   3. El día se marca CONCILIADO cuando no queda ninguna línea del extracto sin
--      registrar y el saldo final del extracto coincide con el del libro.
--
-- Nada de esto reemplaza al libro: el extracto es evidencia y control, no una
-- fuente de movimientos. Los movimientos se siguen registrando por las vías
-- normales (cambio, traspasos, depósitos, o el registro manual de un movimiento
-- bancario detectado en el extracto).
--
-- Nota de nomenclatura: en `cgr` ya existían `extracto_bancario` (cabecera sin
-- detalle), `extracto_usuario` (extracto de CLIENTE, no de banco) y
-- `conciliacion` (avance de conciliación por agencia). Ninguna sirve para esto:
-- las tablas nuevas van con el prefijo `banco_` como el resto del módulo.
-- ============================================================

-- ── Extractos subidos ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `banco_extracto` (
    `id_extracto`             BIGINT       NOT NULL AUTO_INCREMENT,
    `id_agencia`              INT          NOT NULL,
    `id_tipo_cuenta_bancaria` INT          NOT NULL,
    `id_entidad_financiera`   INT          NOT NULL,
    `num_cuenta_bancaria`     VARCHAR(20)  NOT NULL,
    `id_moneda`               INT          NOT NULL,
    `banco_detectado`         VARCHAR(40)  NULL COMMENT 'Clave del parser que reconoció el PDF',
    `formato`                 VARCHAR(30)  NULL COMMENT 'Variante de formato dentro del banco',
    `desde`                   DATE         NULL,
    `hasta`                   DATE         NULL,
    `saldo_inicial`           DECIMAL(18,4) NULL,
    `saldo_final`             DECIMAL(18,4) NULL,
    `total_debitos`           DECIMAL(18,4) NULL,
    `total_creditos`          DECIMAL(18,4) NULL,
    `num_lineas`              INT          NOT NULL DEFAULT 0,
    `sin_movimientos`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'El extracto declara no tener movimientos',
    `archivo_path`            VARCHAR(255) NULL,
    `archivo_nombre`          VARCHAR(255) NULL,
    `archivo_hash`            CHAR(64)     NULL COMMENT 'SHA-256: evita conciliar dos veces el mismo archivo',
    `texto_crudo`             MEDIUMTEXT   NULL COMMENT 'Texto extraído, para auditoría y para re-parsear',
    `estado`                  VARCHAR(20)  NOT NULL DEFAULT 'PENDIENTE' COMMENT 'PENDIENTE|CONCILIADO|CON_DIFERENCIAS|SIN_PARSER|ERROR',
    `observaciones`           TEXT         NULL,
    `cod_usuario`             BIGINT       NOT NULL,
    `fec_crea_extracto`       DATETIME     NOT NULL,
    PRIMARY KEY (`id_extracto`),
    KEY `ix_banco_extracto_cuenta` (`id_agencia`, `id_entidad_financiera`, `num_cuenta_bancaria`, `id_moneda`),
    KEY `ix_banco_extracto_fechas` (`desde`, `hasta`),
    KEY `ix_banco_extracto_hash` (`archivo_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=9000000
  COMMENT='Extractos bancarios en PDF subidos para conciliación';

-- ── Líneas del extracto ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `banco_extracto_mov` (
    `id_mov_extracto`     BIGINT        NOT NULL AUTO_INCREMENT,
    `id_extracto`         BIGINT        NOT NULL,
    `orden`               INT           NOT NULL COMMENT 'Orden de aparición en el extracto',
    `fecha`               DATE          NULL,
    `hora`                VARCHAR(10)   NULL,
    `descripcion`         VARCHAR(500)  NULL,
    `referencia`          VARCHAR(60)   NULL,
    `documento`           VARCHAR(60)   NULL COMMENT 'Nro. de transacción, cheque o lote',
    `monto`               DECIMAL(18,4) NOT NULL COMMENT 'Con signo: positivo abono, negativo cargo',
    `saldo`               DECIMAL(18,4) NULL COMMENT 'Saldo corrido si el extracto lo trae',
    `es_saldo_inicial`    TINYINT(1)    NOT NULL DEFAULT 0,
    `estado_conciliacion` VARCHAR(20)   NOT NULL DEFAULT 'PENDIENTE' COMMENT 'CONCILIADO|PENDIENTE|NO_REGISTRADO|DUDOSO|IGNORADO',
    `id_mov_banco`        VARCHAR(255)  NULL COMMENT 'Clave del movimiento del libro que lo concilió',
    `glosa_libro`         TEXT          NULL,
    `fec_crea_mov`        DATETIME      NOT NULL,
    PRIMARY KEY (`id_mov_extracto`),
    KEY `ix_banco_extracto_mov_extracto` (`id_extracto`, `orden`),
    KEY `ix_banco_extracto_mov_estado` (`estado_conciliacion`),
    CONSTRAINT `fk_banco_extracto_mov_extracto` FOREIGN KEY (`id_extracto`)
        REFERENCES `banco_extracto` (`id_extracto`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=9000000
  COMMENT='Líneas extraídas de un extracto bancario';

-- ── Resumen de la conciliación por cuenta y día ──────────────────────
CREATE TABLE IF NOT EXISTS `banco_conciliacion` (
    `id_conciliacion`         BIGINT       NOT NULL AUTO_INCREMENT,
    `id_extracto`             BIGINT       NULL,
    `id_agencia`              INT          NOT NULL,
    `id_tipo_cuenta_bancaria` INT          NOT NULL,
    `id_entidad_financiera`   INT          NOT NULL,
    `num_cuenta_bancaria`     VARCHAR(20)  NOT NULL,
    `id_moneda`               INT          NOT NULL,
    `fecha`                   DATE         NOT NULL,
    `saldo_extracto`          DECIMAL(18,4) NULL COMMENT 'Saldo final según el banco',
    `saldo_libro`             DECIMAL(18,4) NULL COMMENT 'Saldo calculado del libro',
    `diferencia_saldo`        DECIMAL(18,4) NULL,
    `lineas_extracto`         INT          NOT NULL DEFAULT 0,
    `lineas_libro`            INT          NOT NULL DEFAULT 0,
    `conciliadas`             INT          NOT NULL DEFAULT 0,
    `no_registradas`          INT          NOT NULL DEFAULT 0 COMMENT 'En el extracto pero no en el libro',
    `registradas_de_mas`      INT          NOT NULL DEFAULT 0 COMMENT 'En el libro pero no en el extracto',
    `total_no_registrado`     DECIMAL(18,4) NOT NULL DEFAULT 0,
    `total_de_mas`            DECIMAL(18,4) NOT NULL DEFAULT 0,
    `estado`                  VARCHAR(20)  NOT NULL DEFAULT 'CON_DIFERENCIAS'
        COMMENT 'CONCILIADO|CON_DIFERENCIAS|SIN_MOVIMIENTOS|NO_CONCILIABLE',
    `sin_movimientos`         TINYINT(1)   NOT NULL DEFAULT 0,
    `observaciones`           TEXT         NULL,
    `cod_usuario`             BIGINT       NOT NULL,
    `fec_crea_conciliacion`   DATETIME     NOT NULL,
    `fec_mod_conciliacion`    DATETIME     NULL,
    PRIMARY KEY (`id_conciliacion`),
    UNIQUE KEY `uk_banco_conciliacion_dia` (`id_agencia`, `id_entidad_financiera`, `num_cuenta_bancaria`, `id_moneda`, `fecha`),
    KEY `ix_banco_conciliacion_extracto` (`id_extracto`),
    CONSTRAINT `fk_banco_conciliacion_extracto` FOREIGN KEY (`id_extracto`)
        REFERENCES `banco_extracto` (`id_extracto`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=9000000
  COMMENT='Resultado de conciliar el extracto de una cuenta contra el libro, por día';
