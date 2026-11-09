-- =============================================================================
-- Migration 002: RRHH – Recursos Humanos (Bolivia)
-- Compatible: MySQL 5.5+ / MariaDB 10+
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Parámetros normativos versionados por gestión
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_parametro_gestion (
    id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    gestion           SMALLINT        NOT NULL COMMENT 'Año fiscal (e.g. 2026)',
    codigo            VARCHAR(60)     NOT NULL COMMENT 'Clave del parámetro',
    valor             DECIMAL(18,6)   NOT NULL,
    descripcion       VARCHAR(255)    NOT NULL,
    norma_referencia  VARCHAR(255)    NULL     COMMENT 'DS/RM que lo respalda',
    vigente_desde     DATE            NOT NULL,
    vigente_hasta     DATE            NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_gestion_codigo (gestion, codigo),
    INDEX idx_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Datos iniciales gestión 2026
INSERT IGNORE INTO rrhh_parametro_gestion (gestion, codigo, valor, descripcion, norma_referencia, vigente_desde) VALUES
(2026, 'SMN',                     3300.00, 'Salario Mínimo Nacional (Bs)', 'DS 5516 / RM 088/26', '2026-05-01'),
(2026, 'APORTE_LAB_AFP',           1.71, '% Aporte laboral AFP (Gestora Pública)', 'Ley 065', '2026-01-01'),
(2026, 'APORTE_LAB_RIESGO_COMUN',  1.71, '% Riesgo Común', 'Ley 065', '2026-01-01'),
(2026, 'APORTE_LAB_COMISION',      0.50, '% Comisión AFP', 'Ley 065', '2026-01-01'),
(2026, 'APORTE_LAB_SOLIDARIO',     0.50, '% Aporte Solidario del Asegurado', 'Ley 065', '2026-01-01'),
(2026, 'APORTE_LAB_NACIONAL_SOL',  1.00, '% Aporte Nacional Solidario (>Bs13000)', 'Ley 065', '2026-01-01'),
(2026, 'APORTE_LAB_NAC_SOL2',      5.00, '% Aporte Nacional Solidario (>Bs25000)', 'Ley 065', '2026-01-01'),
(2026, 'APORTE_LAB_NAC_SOL3',     10.00, '% Aporte Nacional Solidario (>Bs35000)', 'Ley 065', '2026-01-01'),
(2026, 'APORTE_PAT_CNS',          10.00, '% Aporte patronal CNS', 'Ley 924', '2026-01-01'),
(2026, 'APORTE_PAT_RIESGO_PROF',   1.71, '% Riesgo Profesional', 'Ley 065', '2026-01-01'),
(2026, 'APORTE_PAT_PROVIVIENDA',   2.00, '% Pro-vivienda', 'DS 21637', '2026-01-01'),
(2026, 'APORTE_PAT_SOLIDARIO',     3.00, '% Aporte patronal solidario', 'Ley 065', '2026-01-01'),
(2026, 'RCIVA_ALICUOTA',          13.00, '% Alícuota RC-IVA', 'Ley 843', '2026-01-01'),
(2026, 'RCIVA_MIN_EXENTO_SMN',     4.00, 'Múltiplo de SMN exento de RC-IVA', 'Ley 843', '2026-01-01'),
(2026, 'DOBLE_AGUINALDO',          0.00, '1=Aplica doble aguinaldo, 0=No aplica', 'DS (anual, según PIB)', '2026-01-01'),
(2026, 'BONO_ANT_BASE_SMN',        3.00, 'Nro de SMN base para bono antigüedad (empresas no productivas)', 'DS 21060', '2026-01-01'),
(2026, 'BONO_ANT_BASE_SMN_PROD',   1.00, 'Nro de SMN base para bono antigüedad (empresas productivas)', 'DS 21060', '2026-01-01');

-- -----------------------------------------------------------------------------
-- 2. Escala de bono de antigüedad
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_escala_bono_antiguedad (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    gestion         SMALLINT        NOT NULL,
    anios_desde     SMALLINT        NOT NULL,
    anios_hasta     SMALLINT        NULL     COMMENT 'NULL = sin tope',
    porcentaje      DECIMAL(5,2)    NOT NULL COMMENT '% sobre base de SMN',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_gestion_rango (gestion, anios_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT IGNORE INTO rrhh_escala_bono_antiguedad (gestion, anios_desde, anios_hasta, porcentaje) VALUES
(2026,  2,  4,   5.00),
(2026,  5,  7,  11.00),
(2026,  8, 10,  18.00),
(2026, 11, 14,  26.00),
(2026, 15, 19,  34.00),
(2026, 20, 24,  42.00),
(2026, 25, NULL, 50.00);

-- -----------------------------------------------------------------------------
-- 3. Escala de vacaciones
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_escala_vacaciones (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    gestion         SMALLINT        NOT NULL,
    anios_desde     SMALLINT        NOT NULL,
    anios_hasta     SMALLINT        NULL,
    dias_habiles    SMALLINT        NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_gestion_rango (gestion, anios_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT IGNORE INTO rrhh_escala_vacaciones (gestion, anios_desde, anios_hasta, dias_habiles) VALUES
(2026,  1,  4, 15),
(2026,  5,  9, 20),
(2026, 10, NULL, 30);

-- -----------------------------------------------------------------------------
-- 4. Empleados (extiende la tabla trabajador existente)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_empleado (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    cod_trab            INT             NULL     COMMENT 'FK a trabajador existente (puede ser NULL si no existe)',
    ci                  VARCHAR(20)     NOT NULL COMMENT 'Cédula de identidad',
    ci_expedido         VARCHAR(5)      NULL     COMMENT 'LP, CB, SC, OR, PT, TJ, CH, BE, PD',
    nombres             VARCHAR(100)    NOT NULL,
    apellido_paterno    VARCHAR(100)    NOT NULL,
    apellido_materno    VARCHAR(100)    NULL,
    fecha_nacimiento    DATE            NULL,
    genero              CHAR(1)         NULL     COMMENT 'M/F',
    estado_civil        VARCHAR(20)     NULL     COMMENT 'SOLTERO, CASADO, DIVORCIADO, VIUDO, UNION_LIBRE',
    direccion           VARCHAR(255)    NULL,
    telefono            VARCHAR(30)     NULL,
    email_personal      VARCHAR(120)    NULL,
    nua_cua             VARCHAR(30)     NULL     COMMENT 'Número Único Asignado / CUA (AFP)',
    nro_asegurado_cns   VARCHAR(30)     NULL     COMMENT 'Número de asegurado CNS',
    cuenta_bancaria     VARCHAR(30)     NULL,
    banco               VARCHAR(60)     NULL,
    foto_url            VARCHAR(255)    NULL,
    estado              CHAR(1)         NOT NULL DEFAULT 'A' COMMENT 'A=Activo, I=Inactivo, D=Desvinculado',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_ci (ci),
    INDEX idx_cod_trab (cod_trab),
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Cargos
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_cargo (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(120)    NOT NULL,
    nivel           VARCHAR(60)     NULL     COMMENT 'Gerencia, Jefatura, Operativo, etc.',
    area            VARCHAR(100)    NULL     COMMENT 'Área funcional',
    id_agencia      INT             NULL     COMMENT 'FK a agencia (NULL = todas)',
    estado          CHAR(1)         NOT NULL DEFAULT 'A',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_area (area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Contratos laborales
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_contrato (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_empleado         INT UNSIGNED    NOT NULL,
    id_cargo            INT UNSIGNED    NOT NULL,
    id_agencia          INT             NOT NULL COMMENT 'FK a agencia',
    tipo_contrato       VARCHAR(30)     NOT NULL COMMENT 'INDEFINIDO, PLAZO_FIJO, TEMPORADA, OBRA_SERVICIO, TELETRABAJO',
    fecha_inicio        DATE            NOT NULL,
    fecha_fin           DATE            NULL     COMMENT 'NULL = indefinido',
    fecha_fin_prueba    DATE            NULL     COMMENT 'Fin del periodo de prueba (3 meses)',
    sueldo_base         DECIMAL(12,2)   NOT NULL,
    moneda              VARCHAR(5)      NOT NULL DEFAULT 'BOB',
    jornada_horas       DECIMAL(4,2)    NOT NULL DEFAULT 8.00,
    es_empresa_prod     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1=empresa productiva (afecta base bono antigüedad)',
    estado              VARCHAR(15)     NOT NULL DEFAULT 'VIGENTE' COMMENT 'VIGENTE, FINALIZADO, RESCINDIDO',
    motivo_fin          VARCHAR(255)    NULL,
    observaciones       TEXT            NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_empleado (id_empleado),
    INDEX idx_estado (estado),
    INDEX idx_fecha_fin (fecha_fin),
    FOREIGN KEY fk_contrato_empleado (id_empleado)
        REFERENCES rrhh_empleado(id) ON DELETE RESTRICT,
    FOREIGN KEY fk_contrato_cargo (id_cargo)
        REFERENCES rrhh_cargo(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. Planilla mensual (cabecera)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_planilla (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    gestion         SMALLINT        NOT NULL,
    mes             TINYINT         NOT NULL,
    id_agencia      INT             NULL     COMMENT 'NULL = consolidada',
    estado          VARCHAR(15)     NOT NULL DEFAULT 'BORRADOR' COMMENT 'BORRADOR, APROBADA, PAGADA, ANULADA',
    total_ganado    DECIMAL(14,2)   NOT NULL DEFAULT 0,
    total_descuento DECIMAL(14,2)   NOT NULL DEFAULT 0,
    total_liquido   DECIMAL(14,2)   NOT NULL DEFAULT 0,
    total_patronal  DECIMAL(14,2)   NOT NULL DEFAULT 0,
    aprobado_por    INT UNSIGNED    NULL,
    aprobado_at     DATETIME        NULL,
    observaciones   TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_periodo (gestion, mes, id_agencia),
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Planilla detalle (una fila por empleado por mes)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_planilla_detalle (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_planilla         INT UNSIGNED    NOT NULL,
    id_empleado         INT UNSIGNED    NOT NULL,
    id_contrato         INT UNSIGNED    NOT NULL,

    -- Haberes
    sueldo_base         DECIMAL(12,2)   NOT NULL DEFAULT 0,
    bono_antiguedad     DECIMAL(12,2)   NOT NULL DEFAULT 0,
    horas_extra         DECIMAL(6,2)    NOT NULL DEFAULT 0,
    monto_horas_extra   DECIMAL(12,2)   NOT NULL DEFAULT 0,
    dominicales_trab    DECIMAL(6,2)    NOT NULL DEFAULT 0 COMMENT 'Domingos/feriados trabajados',
    monto_dominicales   DECIMAL(12,2)   NOT NULL DEFAULT 0,
    otros_ingresos      DECIMAL(12,2)   NOT NULL DEFAULT 0,
    total_ganado        DECIMAL(12,2)   NOT NULL DEFAULT 0,

    -- Descuentos laborales
    aporte_afp          DECIMAL(12,2)   NOT NULL DEFAULT 0,
    riesgo_comun        DECIMAL(12,2)   NOT NULL DEFAULT 0,
    comision_afp        DECIMAL(12,2)   NOT NULL DEFAULT 0,
    solidario_aseg      DECIMAL(12,2)   NOT NULL DEFAULT 0,
    nacional_solidario  DECIMAL(12,2)   NOT NULL DEFAULT 0 COMMENT 'Tramo según ingreso',
    rciva               DECIMAL(12,2)   NOT NULL DEFAULT 0,
    otros_descuentos    DECIMAL(12,2)   NOT NULL DEFAULT 0,
    total_descuentos    DECIMAL(12,2)   NOT NULL DEFAULT 0,

    liquido_pagable     DECIMAL(12,2)   NOT NULL DEFAULT 0,

    -- Aportes patronales
    pat_cns             DECIMAL(12,2)   NOT NULL DEFAULT 0,
    pat_riesgo_prof     DECIMAL(12,2)   NOT NULL DEFAULT 0,
    pat_provivienda     DECIMAL(12,2)   NOT NULL DEFAULT 0,
    pat_solidario       DECIMAL(12,2)   NOT NULL DEFAULT 0,
    total_patronal      DECIMAL(12,2)   NOT NULL DEFAULT 0,

    dias_trabajados     SMALLINT        NOT NULL DEFAULT 30,
    observaciones       VARCHAR(255)    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_planilla_empleado (id_planilla, id_empleado),
    INDEX idx_empleado (id_empleado),
    FOREIGN KEY fk_det_planilla (id_planilla)
        REFERENCES rrhh_planilla(id) ON DELETE CASCADE,
    FOREIGN KEY fk_det_empleado (id_empleado)
        REFERENCES rrhh_empleado(id) ON DELETE RESTRICT,
    FOREIGN KEY fk_det_contrato (id_contrato)
        REFERENCES rrhh_contrato(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Liquidaciones / Finiquitos
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_liquidacion (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_empleado             INT UNSIGNED    NOT NULL,
    id_contrato             INT UNSIGNED    NOT NULL,
    fecha_desvinculacion    DATE            NOT NULL,
    motivo                  VARCHAR(30)     NOT NULL COMMENT 'RENUNCIA, DESPIDO_JUSTIFICADO, DESPIDO_INJUSTIFICADO, FIN_CONTRATO, FALLECIMIENTO',

    anios_trabajados        DECIMAL(6,2)   NOT NULL DEFAULT 0,
    sueldo_promedio         DECIMAL(12,2)  NOT NULL DEFAULT 0 COMMENT 'Promedio últimos 3 meses',

    -- Componentes
    indemnizacion           DECIMAL(12,2)  NOT NULL DEFAULT 0 COMMENT '1 sueldo x año (>90 días)',
    desahucio               DECIMAL(12,2)  NOT NULL DEFAULT 0 COMMENT '3 sueldos (solo despido injustificado)',
    aguinaldo_proporcional  DECIMAL(12,2)  NOT NULL DEFAULT 0,
    vacaciones_pendientes   DECIMAL(6,2)   NOT NULL DEFAULT 0 COMMENT 'Días',
    monto_vacaciones        DECIMAL(12,2)  NOT NULL DEFAULT 0,
    doble_aguinaldo_prop    DECIMAL(12,2)  NOT NULL DEFAULT 0,
    otros_pagos             DECIMAL(12,2)  NOT NULL DEFAULT 0,

    total_liquidacion       DECIMAL(14,2)  NOT NULL DEFAULT 0,
    total_descuentos        DECIMAL(14,2)  NOT NULL DEFAULT 0,
    liquido_final           DECIMAL(14,2)  NOT NULL DEFAULT 0,

    estado                  VARCHAR(15)    NOT NULL DEFAULT 'PENDIENTE' COMMENT 'PENDIENTE, APROBADA, PAGADA',
    aprobado_por            INT UNSIGNED   NULL,
    observaciones           TEXT           NULL,
    created_at              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_empleado (id_empleado),
    FOREIGN KEY fk_liq_empleado (id_empleado)
        REFERENCES rrhh_empleado(id) ON DELETE RESTRICT,
    FOREIGN KEY fk_liq_contrato (id_contrato)
        REFERENCES rrhh_contrato(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. Asistencia / Marcaciones
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_asistencia (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_empleado     INT UNSIGNED    NOT NULL,
    fecha           DATE            NOT NULL,
    hora_entrada    TIME            NULL,
    hora_salida     TIME            NULL,
    horas_extra     DECIMAL(4,2)    NOT NULL DEFAULT 0,
    es_feriado      TINYINT(1)      NOT NULL DEFAULT 0,
    es_dominical    TINYINT(1)      NOT NULL DEFAULT 0,
    tipo            VARCHAR(20)     NOT NULL DEFAULT 'NORMAL' COMMENT 'NORMAL, FALTA, PERMISO, LICENCIA, FERIADO',
    observaciones   VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_empleado_fecha (id_empleado, fecha),
    INDEX idx_fecha (fecha),
    FOREIGN KEY fk_asist_empleado (id_empleado)
        REFERENCES rrhh_empleado(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. Vacaciones y licencias
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_vacacion (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_empleado     INT UNSIGNED    NOT NULL,
    tipo            VARCHAR(30)     NOT NULL COMMENT 'VACACION, MATERNIDAD, PATERNIDAD, CAPACITACION, DUELO, MATRIMONIO, MEDICA, OTRA',
    fecha_inicio    DATE            NOT NULL,
    fecha_fin       DATE            NOT NULL,
    dias            SMALLINT        NOT NULL,
    estado          VARCHAR(15)     NOT NULL DEFAULT 'SOLICITADA' COMMENT 'SOLICITADA, APROBADA, RECHAZADA, CANCELADA, GOZADA',
    aprobado_por    INT UNSIGNED    NULL,
    observaciones   VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_empleado (id_empleado),
    INDEX idx_tipo_estado (tipo, estado),
    FOREIGN KEY fk_vac_empleado (id_empleado)
        REFERENCES rrhh_empleado(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. Capacitación
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_capacitacion (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    titulo              VARCHAR(200)    NOT NULL,
    descripcion         TEXT            NULL,
    tipo                VARCHAR(60)     NOT NULL COMMENT 'PLA_FT, ATENCION_CONSUMIDOR, TECNICA, SEGURIDAD, REGULATORIA, OTRA',
    proveedor           VARCHAR(150)    NULL,
    fecha_inicio        DATE            NOT NULL,
    fecha_fin           DATE            NULL,
    horas_duracion      DECIMAL(6,2)    NULL,
    es_obligatoria      TINYINT(1)      NOT NULL DEFAULT 0,
    estado              VARCHAR(15)     NOT NULL DEFAULT 'PROGRAMADA' COMMENT 'PROGRAMADA, EN_CURSO, COMPLETADA, CANCELADA',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_tipo (tipo),
    INDEX idx_estado_fecha (estado, fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS rrhh_capacitacion_empleado (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_capacitacion     INT UNSIGNED    NOT NULL,
    id_empleado         INT UNSIGNED    NOT NULL,
    asistio             TINYINT(1)      NOT NULL DEFAULT 0,
    nota                DECIMAL(5,2)    NULL,
    certificado_url     VARCHAR(255)    NULL,
    estado              VARCHAR(15)     NOT NULL DEFAULT 'INSCRITO' COMMENT 'INSCRITO, APROBADO, REPROBADO, AUSENTE',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_cap_emp (id_capacitacion, id_empleado),
    FOREIGN KEY fk_capemp_cap (id_capacitacion)
        REFERENCES rrhh_capacitacion(id) ON DELETE CASCADE,
    FOREIGN KEY fk_capemp_emp (id_empleado)
        REFERENCES rrhh_empleado(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. Feriados por gestión
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_feriado (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    gestion     SMALLINT        NOT NULL,
    fecha       DATE            NOT NULL,
    nombre      VARCHAR(120)    NOT NULL,
    tipo        VARCHAR(20)     NOT NULL DEFAULT 'NACIONAL' COMMENT 'NACIONAL, DEPARTAMENTAL',
    departamento VARCHAR(5)     NULL     COMMENT 'LP, CB, SC, etc. (solo si departamental)',

    PRIMARY KEY (id),
    UNIQUE KEY uq_gestion_fecha (gestion, fecha, departamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT IGNORE INTO rrhh_feriado (gestion, fecha, nombre, tipo) VALUES
(2026, '2026-01-01', 'Año Nuevo', 'NACIONAL'),
(2026, '2026-01-22', 'Día del Estado Plurinacional', 'NACIONAL'),
(2026, '2026-02-16', 'Carnaval (lunes)', 'NACIONAL'),
(2026, '2026-02-17', 'Carnaval (martes)', 'NACIONAL'),
(2026, '2026-04-03', 'Viernes Santo', 'NACIONAL'),
(2026, '2026-05-01', 'Día del Trabajo', 'NACIONAL'),
(2026, '2026-06-04', 'Corpus Christi', 'NACIONAL'),
(2026, '2026-06-21', 'Año Nuevo Aymara', 'NACIONAL'),
(2026, '2026-08-06', 'Día de la Independencia', 'NACIONAL'),
(2026, '2026-11-02', 'Día de Todos los Santos', 'NACIONAL'),
(2026, '2026-12-25', 'Navidad', 'NACIONAL');

-- -----------------------------------------------------------------------------
-- 14. Evaluación de desempeño (estructura básica)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rrhh_evaluacion (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_empleado     INT UNSIGNED    NOT NULL,
    periodo         VARCHAR(20)     NOT NULL COMMENT 'Ej: 2026-S1, 2026-ANUAL',
    evaluador_id    INT UNSIGNED    NULL,
    puntaje         DECIMAL(5,2)    NULL,
    categoria       VARCHAR(30)     NULL     COMMENT 'SOBRESALIENTE, BUENO, REGULAR, DEFICIENTE',
    observaciones   TEXT            NULL,
    estado          VARCHAR(15)     NOT NULL DEFAULT 'PENDIENTE',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_empleado (id_empleado),
    FOREIGN KEY fk_eval_empleado (id_empleado)
        REFERENCES rrhh_empleado(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
