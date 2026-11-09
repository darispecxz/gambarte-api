-- ============================================================================
-- MIGRACIÓN COMPLETA MÓDULO RRHH — GAMBARTE BOLIVIA S.R.L.
-- Base de datos: u752765750_cgr (MariaDB)
-- Fecha: 2026-09-01
-- ============================================================================
-- Este script prepara TODAS las tablas necesarias para el módulo RRHH.
-- Reutiliza tablas CGR existentes: trabajador (via VIEW), cargo (ALTER), agencia.
-- Crea tablas nuevas + módulos (CSBP, Gestoras, Finiquitos, Tributaria, Horarios)
-- + datos iniciales (parámetros, escalas, feriados, horarios).
--
-- ORDEN DE EJECUCIÓN: Ejecutar en orden secuencial (las FK dependen del orden).
-- Tablas externas requeridas: `trabajador`, `usuario`, `sexo`, `estado_civil`,
--                             `expedicion`, `cargo`, `agencia` (todas ya existen en el CGR).
-- ============================================================================


-- ═══════════════════════════════════════════════════════════════════════════
-- PARTE 1: TABLAS BASE DEL MÓDULO RRHH
-- ═══════════════════════════════════════════════════════════════════════════

-- -------------------------------------------------
-- 1.1 TABLA cargo — sin cambios
-- -------------------------------------------------
-- La tabla `cargo` ya existe en el CGR (id_cargo, descripcion).
-- El área se obtiene de trabajador.id_area → area.descripcion (tabla existente).


-- -------------------------------------------------
-- 1.2 EMPLEADOS — Vista sobre `trabajador` (tabla existente CGR)
-- -------------------------------------------------
-- Se agregan columnas RRHH a la tabla trabajador existente.
ALTER TABLE trabajador
    ADD COLUMN IF NOT EXISTS nua_cua           VARCHAR(30) NULL COMMENT 'NUA/CUA para la gestora AFP',
    ADD COLUMN IF NOT EXISTS nro_asegurado_cns VARCHAR(30) NULL COMMENT 'Número de asegurado CNS/CSBP',
    ADD COLUMN IF NOT EXISTS foto_url          VARCHAR(255) NULL;

-- Vista que expone `trabajador` con los nombres de columna que usan los servicios RRHH.
-- Así todo el código PHP funciona sin cambios (SELECT * FROM rrhh_empleado WHERE id = X).
DROP VIEW IF EXISTS rrhh_empleado;
CREATE VIEW rrhh_empleado AS
SELECT
    t.cod_trab          AS id,
    t.cod_trab,
    CONVERT(t.num_doc_identidad USING utf8mb4) AS ci,
    CONVERT(ex.abreviatura USING utf8mb4)      AS ci_expedido,
    CONVERT(t.nom_trab USING utf8mb4)          AS nombres,
    CONVERT(t.ape_pat_trab USING utf8mb4)      AS apellido_paterno,
    CONVERT(t.ape_mat_trab USING utf8mb4)      AS apellido_materno,
    t.fec_nac_trab      AS fecha_nacimiento,
    CONVERT(s.abreviatura USING utf8mb4)       AS genero,
    CONVERT(ec.descripcion USING utf8mb4)      AS estado_civil,
    CONVERT(t.cel_trab USING utf8mb4)          AS telefono,
    CONVERT(t.email_trab USING utf8mb4)        AS email_personal,
    t.nua_cua,
    t.nro_asegurado_cns,
    CASE
        WHEN (SELECT CONVERT(u.estado USING utf8mb4) FROM usuario u WHERE u.cod_trab = t.cod_trab LIMIT 1) = 'A' THEN 'A'
        WHEN t.fec_cese IS NOT NULL AND t.fec_cese != '0000-00-00' THEN 'D'
        ELSE 'I'
    END COLLATE utf8mb4_unicode_ci AS estado,
    t.foto_url,
    t.fec_ingreso       AS fecha_ingreso,
    t.fec_cese          AS fecha_cese,
    t.id_cargo,
    t.id_area,
    CONVERT(ca.descripcion USING utf8mb4) AS cargo,
    CONVERT(ar.descripcion USING utf8mb4) AS area,
    (SELECT ag.id_agencia
     FROM usuario u2
     JOIN acceso ac ON ac.cod_usuario = u2.cod_usuario
     JOIN agencia ag ON ag.id_agencia = ac.id_agencia
     WHERE u2.cod_trab = t.cod_trab LIMIT 1) AS id_agencia,
    (SELECT CONVERT(ag.descripcion USING utf8mb4)
     FROM usuario u3
     JOIN acceso ac ON ac.cod_usuario = u3.cod_usuario
     JOIN agencia ag ON ag.id_agencia = ac.id_agencia
     WHERE u3.cod_trab = t.cod_trab LIMIT 1) AS agencia
FROM trabajador t
LEFT JOIN sexo s          ON s.id_sexo = t.id_sexo
LEFT JOIN estado_civil ec ON ec.id_estado_civil = t.id_estado_civil
LEFT JOIN expedicion ex   ON ex.id_expedicion = t.id_expedicion
LEFT JOIN cargo ca        ON ca.id_cargo = t.id_cargo
LEFT JOIN area ar         ON ar.id_area = t.id_area;


-- -------------------------------------------------
-- 1.3 CONTRATOS
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_contrato (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_empleado     INT NOT NULL COMMENT 'FK lógica a trabajador.cod_trab (expuesto como rrhh_empleado.id via VIEW)',
    id_cargo        INT NULL COMMENT 'FK a cargo.id_cargo (tabla existente CGR)',
    id_agencia      INT NULL COMMENT 'FK a agencia.id_agencia (tabla existente CGR)',
    tipo_contrato   ENUM('INDEFINIDO','PLAZO_FIJO','TEMPORADA','OBRA_SERVICIO','TELETRABAJO') NOT NULL DEFAULT 'INDEFINIDO',
    fecha_inicio    DATE NOT NULL,
    fecha_fin       DATE NULL COMMENT 'NULL = indefinido',
    sueldo_base     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    es_empresa_prod TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=empresa productiva (base bono antigüedad = 1 SMN)',
    jornada_horas   DECIMAL(4,2) NULL COMMENT 'Horas de jornada diaria (default: 8 hombres, 7 mujeres)',
    estado          ENUM('VIGENTE','RESCINDIDO','SUSPENDIDO') NOT NULL DEFAULT 'VIGENTE',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contrato_empleado (id_empleado),
    INDEX idx_contrato_cargo (id_cargo),
    INDEX idx_contrato_agencia (id_agencia),
    INDEX idx_contrato_estado (estado),
    INDEX idx_contrato_fecha_fin (fecha_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contratos laborales vinculados a empleados';


-- -------------------------------------------------
-- 1.4 PARÁMETROS POR GESTIÓN
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_parametro_gestion (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    gestion             INT NOT NULL COMMENT 'Año fiscal (2025, 2026, etc.)',
    codigo              VARCHAR(50) NOT NULL COMMENT 'Código único del parámetro (SMN, APORTE_LAB_AFP, etc.)',
    valor               DECIMAL(12,4) NOT NULL,
    descripcion         VARCHAR(255) NULL,
    norma_referencia    VARCHAR(120) NULL COMMENT 'Norma legal que respalda el valor',
    vigente_desde       DATE NULL,
    vigente_hasta       DATE NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_param_gestion_codigo (gestion, codigo),
    INDEX idx_param_gestion (gestion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Parámetros laborales/tributarios por gestión fiscal';


-- -------------------------------------------------
-- 1.5 ESCALA BONO DE ANTIGÜEDAD
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_escala_bono_antiguedad (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    gestion         INT NOT NULL,
    anios_desde     INT NOT NULL,
    anios_hasta     INT NULL COMMENT 'NULL = sin límite superior',
    porcentaje      DECIMAL(5,2) NOT NULL COMMENT '% sobre base (3 SMN o 1 SMN prod)',
    INDEX idx_eba_gestion (gestion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Escala porcentual del bono de antigüedad (DS 21060)';


-- -------------------------------------------------
-- 1.6 ESCALA DE VACACIONES
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_escala_vacaciones (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    gestion         INT NOT NULL,
    anios_desde     INT NOT NULL,
    anios_hasta     INT NULL COMMENT 'NULL = sin límite superior',
    dias_habiles    INT NOT NULL COMMENT 'Días hábiles de vacación',
    INDEX idx_ev_gestion (gestion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Escala de días de vacación por antigüedad (LGT Art. 44)';


-- -------------------------------------------------
-- 1.7 FERIADOS
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_feriado (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    gestion         INT NOT NULL,
    fecha           DATE NOT NULL,
    nombre          VARCHAR(120) NOT NULL,
    tipo            ENUM('NACIONAL','DEPARTAMENTAL') NOT NULL DEFAULT 'NACIONAL',
    departamento    VARCHAR(30) NULL COMMENT 'Solo si tipo=DEPARTAMENTAL (La Paz, Cochabamba, etc.)',
    UNIQUE KEY uk_feriado_fecha (fecha),
    INDEX idx_feriado_gestion (gestion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Calendario de feriados oficiales por gestión';


-- -------------------------------------------------
-- 1.8 PLANILLA SALARIAL (cabecera)
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_planilla (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    gestion         INT NOT NULL,
    mes             TINYINT NOT NULL COMMENT '1-12',
    id_agencia      INT NULL COMMENT 'FK a agencia.id_agencia',
    estado          ENUM('BORRADOR','CALCULADA','APROBADA','PAGADA') NOT NULL DEFAULT 'BORRADOR',
    total_ganado    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_descuento DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_liquido   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_patronal  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_planilla_periodo (gestion, mes, id_agencia),
    INDEX idx_planilla_gestion (gestion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cabecera de planilla salarial mensual';


-- -------------------------------------------------
-- 1.9 PLANILLA DETALLE (por empleado)
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_planilla_detalle (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_planilla         INT NOT NULL,
    id_empleado         INT NOT NULL,
    id_contrato         INT NOT NULL,
    -- Haberes
    sueldo_base         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    bono_antiguedad     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    antiguedad_anios    INT NOT NULL DEFAULT 0,
    horas_extra         DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    monto_horas_extra   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    dominicales_trab    DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    monto_dominicales   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_ganado        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Descuentos laborales
    aporte_afp          DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '1.71% AFP',
    riesgo_comun        DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '1.71% Riesgo Común',
    comision_afp        DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '0.50% Comisión AFP',
    solidario_aseg      DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '0.50% Solidario Asegurado',
    nacional_solidario  DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Escalonado >13K/25K/35K',
    rciva               DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'RC-IVA retenido',
    total_descuentos    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    liquido_pagable     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Aportes patronales
    pat_cns             DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '10% CNS',
    pat_riesgo_prof     DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '1.71% Riesgo Profesional',
    pat_provivienda     DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '2% Pro-vivienda',
    pat_solidario       DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '3% Solidario Patronal',
    total_patronal      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pd_planilla (id_planilla),
    INDEX idx_pd_empleado (id_empleado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Detalle de planilla salarial por empleado';


-- -------------------------------------------------
-- 1.10 ASISTENCIA
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_asistencia (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_empleado     INT NOT NULL,
    fecha           DATE NOT NULL,
    am_ingreso      TIME NULL COMMENT 'Hora ingreso turno mañana',
    am_salida       TIME NULL COMMENT 'Hora salida turno mañana',
    pm_ingreso      TIME NULL COMMENT 'Hora ingreso turno tarde',
    pm_salida       TIME NULL COMMENT 'Hora salida turno tarde',
    hora_entrada    TIME NULL COMMENT 'Primera marca del día',
    hora_salida     TIME NULL COMMENT 'Última marca del día',
    horas_extra     DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    atrasos_min     INT NOT NULL DEFAULT 0 COMMENT 'Minutos de atraso',
    es_feriado      TINYINT(1) NOT NULL DEFAULT 0,
    es_dominical    TINYINT(1) NOT NULL DEFAULT 0,
    tipo            ENUM('NORMAL','FALTA','FERIADO','PERMISO','LICENCIA') NOT NULL DEFAULT 'NORMAL',
    observaciones   TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_asistencia_emp_fecha (id_empleado, fecha),
    INDEX idx_asist_fecha (fecha),
    INDEX idx_asist_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro diario de asistencia';


-- -------------------------------------------------
-- 1.11 VACACIONES Y PERMISOS
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_vacacion (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_empleado     INT NOT NULL,
    tipo            ENUM('VACACION','PERMISO','LICENCIA') NOT NULL DEFAULT 'VACACION',
    fecha_inicio    DATE NOT NULL,
    fecha_fin       DATE NOT NULL,
    dias            INT NOT NULL DEFAULT 0 COMMENT 'Días hábiles',
    estado          ENUM('SOLICITADA','APROBADA','GOZADA','RECHAZADA') NOT NULL DEFAULT 'SOLICITADA',
    observaciones   TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vac_empleado (id_empleado),
    INDEX idx_vac_estado (estado),
    INDEX idx_vac_fecha (fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solicitudes de vacación, permisos y licencias';


-- (rrhh_liquidacion eliminada — unificada con rrhh_finiquito en Parte 2)


-- -------------------------------------------------
-- 1.12 CAPACITACIONES
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_capacitacion (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    titulo          VARCHAR(200) NOT NULL,
    tipo            VARCHAR(50) NULL COMMENT 'PLA_FT, ATENCION_CONSUMIDOR, SEGURIDAD, REGULATORIA, etc.',
    fecha_inicio    DATE NULL,
    fecha_fin       DATE NULL,
    horas_duracion  INT NOT NULL DEFAULT 0,
    es_obligatoria  TINYINT(1) NOT NULL DEFAULT 0,
    estado          ENUM('PROGRAMADA','EN_CURSO','COMPLETADA','CANCELADA') NOT NULL DEFAULT 'PROGRAMADA',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cap_estado (estado),
    INDEX idx_cap_obligatoria (es_obligatoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Programas de capacitación';


-- -------------------------------------------------
-- 1.13 CAPACITACIÓN - EMPLEADOS (inscripciones)
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_capacitacion_empleado (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    id_empleado         INT NOT NULL,
    id_capacitacion     INT NOT NULL,
    estado              ENUM('INSCRITO','EN_CURSO','APROBADO','REPROBADO') NOT NULL DEFAULT 'INSCRITO',
    nota                DECIMAL(5,2) NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ce_empleado (id_empleado),
    INDEX idx_ce_capacitacion (id_capacitacion),
    UNIQUE KEY uk_ce_emp_cap (id_empleado, id_capacitacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inscripción de empleados a capacitaciones';


-- -------------------------------------------------
-- 1.14 EVALUACIONES DE DESEMPEÑO
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_evaluacion (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_empleado     INT NOT NULL,
    periodo         VARCHAR(20) NOT NULL COMMENT 'Ej: 2026-S1, 2026-S2, 2026',
    puntaje         DECIMAL(5,2) NULL,
    categoria       VARCHAR(30) NULL COMMENT 'EXCELENTE, BUENO, REGULAR, DEFICIENTE',
    estado          ENUM('PENDIENTE','EN_PROCESO','COMPLETADA') NOT NULL DEFAULT 'PENDIENTE',
    observaciones   TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_eval_empleado (id_empleado),
    INDEX idx_eval_periodo (periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Evaluaciones de desempeño del personal';


-- ═══════════════════════════════════════════════════════════════════════════
-- PARTE 2: TABLAS DE MÓDULOS NUEVOS
-- ═══════════════════════════════════════════════════════════════════════════

-- -------------------------------------------------
-- 2.1 CSBP — Altas
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_csbp_alta (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_contrato     INT NOT NULL,
    fecha_alta      DATE NOT NULL COMMENT 'Fecha de registro/alta en la CSBP',
    fecha_efectiva  DATE NULL COMMENT 'Fecha efectiva del seguro',
    nro_asegurado   VARCHAR(30) NULL COMMENT 'Número de asegurado CSBP',
    estado          ENUM('PENDIENTE','EN_TRAMITE','COMPLETADO','RECHAZADO') NOT NULL DEFAULT 'PENDIENTE',
    condicion       ENUM('VIGENTE','VENCIDO') NOT NULL DEFAULT 'VIGENTE',
    pagado          TINYINT(1) NOT NULL DEFAULT 0,
    monto_pago      DECIMAL(10,2) NULL,
    nro_aviso       VARCHAR(50) NULL COMMENT 'Número de aviso de alta',
    archivo         VARCHAR(255) NULL,
    observaciones   TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_csbp_alta_contrato (id_contrato),
    INDEX idx_csbp_alta_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Altas de empleados en la Caja de Salud';

-- -------------------------------------------------
-- 2.2 CSBP — Bajas
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_csbp_baja (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_contrato     INT NOT NULL,
    fecha_baja      DATE NOT NULL,
    fecha_efectiva  DATE NULL,
    motivo          ENUM('RETIRO_VOLUNTARIO','DESPIDO','FIN_CONTRATO','JUBILACION','FALLECIMIENTO') NOT NULL,
    estado          ENUM('PENDIENTE','EN_TRAMITE','COMPLETADO','RECHAZADO') NOT NULL DEFAULT 'PENDIENTE',
    pagado          TINYINT(1) NOT NULL DEFAULT 0,
    monto_pago      DECIMAL(10,2) NULL,
    nro_aviso       VARCHAR(50) NULL,
    archivo         VARCHAR(255) NULL,
    observaciones   TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_csbp_baja_contrato (id_contrato),
    INDEX idx_csbp_baja_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bajas de empleados en la Caja de Salud';

-- -------------------------------------------------
-- 2.3 CSBP — Aportes mensuales
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_csbp_aporte (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    gestion             INT NOT NULL,
    mes                 TINYINT NOT NULL,
    codigo_planilla     VARCHAR(30) NULL,
    regional            VARCHAR(50) NOT NULL COMMENT 'Regional CSBP',
    fecha_presentacion  DATE NOT NULL,
    fecha_deposito      DATE NULL,
    total_aporte        DECIMAL(12,2) NOT NULL,
    multas              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    intereses           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_cancelado     DECIMAL(12,2) NOT NULL,
    nro_empleados       INT NOT NULL DEFAULT 0,
    estado              ENUM('PENDIENTE','PRESENTADO','PAGADO','OBSERVADO') NOT NULL DEFAULT 'PENDIENTE',
    archivo_referencia  VARCHAR(255) NULL,
    observaciones       TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_csbp_aporte_periodo (gestion, mes, regional),
    INDEX idx_csbp_aporte_gestion (gestion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resumen mensual de aportes a la Caja de Salud';

-- -------------------------------------------------
-- 2.4 GESTORA AFP (asignación por contrato)
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_gestora (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_contrato     INT NOT NULL,
    nombre_gestora  VARCHAR(100) NOT NULL COMMENT 'Futuro de Bolivia, BBVA Previsión',
    nua_cua         VARCHAR(30) NULL,
    fecha_afiliacion DATE NULL,
    estado          ENUM('ACTIVO','INACTIVO','TRASPASO') NOT NULL DEFAULT 'ACTIVO',
    descripcion     TEXT NULL,
    archivo         VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gestora_contrato (id_contrato),
    INDEX idx_gestora_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Gestora AFP asignada por contrato';

-- -------------------------------------------------
-- 2.5 CONTRIBUCIONES AFP (pagos mensuales)
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_contribucion_afp (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    gestion                 INT NOT NULL,
    mes                     TINYINT NOT NULL,
    codigo_planilla         VARCHAR(30) NULL,
    nro_fpc                 INT NULL COMMENT 'Número Formulario de Pago de Contribuciones',
    nombre_gestora          VARCHAR(100) NOT NULL,
    razon_social            VARCHAR(200) NOT NULL DEFAULT 'GIROS Y REMESAS DE DINERO GAMBARTE BOLIVIA S.R.L.',
    sucursal                VARCHAR(100) NULL,
    tipo_pago               ENUM('REGULAR','RETROACTIVO','COMPLEMENTARIO') NOT NULL DEFAULT 'REGULAR',
    total_aporte_laboral    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_aporte_patronal   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_contribucion      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    nro_asegurados          INT NOT NULL DEFAULT 0,
    incluye_extranjeros     TINYINT(1) NOT NULL DEFAULT 0,
    fecha_presentacion      DATE NULL,
    estado                  ENUM('BORRADOR','PRESENTADO','PAGADO') NOT NULL DEFAULT 'BORRADOR',
    archivo                 VARCHAR(255) NULL,
    observaciones           TEXT NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_contrib_periodo (gestion, mes, nombre_gestora, tipo_pago),
    INDEX idx_contrib_gestion (gestion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contribuciones mensuales a gestoras AFP';

-- -------------------------------------------------
-- 2.6 FINIQUITO DETALLADO
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_finiquito (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    id_empleado             INT NOT NULL,
    id_contrato             INT NOT NULL,
    motivo_baja             ENUM('VOLUNTARIO','FORZOSO','CONCLUSION_CONTRATO','CONCLUSION_OBRA','INCUMPLIMIENTO_CONTRATO') NOT NULL,
    fecha_ingreso           DATE NOT NULL,
    fecha_retiro            DATE NOT NULL,
    anios_trabajados        INT NOT NULL DEFAULT 0,
    meses_trabajados        INT NOT NULL DEFAULT 0,
    dias_trabajados         INT NOT NULL DEFAULT 0,
    -- Remuneraciones últimos 3 meses
    rem1_mes                VARCHAR(20) NULL,
    rem1_gestion            SMALLINT NULL,
    rem1_sueldo             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem1_bono_antiguedad    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem1_otros_bonos        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem1_total              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem2_mes                VARCHAR(20) NULL,
    rem2_gestion            SMALLINT NULL,
    rem2_sueldo             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem2_bono_antiguedad    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem2_otros_bonos        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem2_total              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem3_mes                VARCHAR(20) NULL,
    rem3_gestion            SMALLINT NULL,
    rem3_sueldo             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem3_bono_antiguedad    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem3_otros_bonos        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rem3_total              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Promedio
    promedio_indemnizacion  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Beneficios
    indemnizacion           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    desahucio               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    aguinaldo_navidad       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    doble_aguinaldo         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vacaciones_dias         DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    vacaciones_monto        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    prima                   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    otros_beneficios        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_beneficios        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Deducciones
    retencion_rciva         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    otros_descuentos        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_deducciones       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Líquido
    liquido_pagable         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado                  ENUM('BORRADOR','CALCULADO','APROBADO','PAGADO','ANULADO') NOT NULL DEFAULT 'BORRADOR',
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_finiquito_empleado (id_empleado),
    INDEX idx_finiquito_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Finiquito detallado con desglose de remuneraciones y beneficios sociales';

-- -------------------------------------------------
-- 2.7 PLANILLA TRIBUTARIA (RC-IVA)
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_planilla_tributaria (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    id_planilla                     INT NULL COMMENT 'FK a rrhh_planilla',
    id_empleado                     INT NOT NULL,
    gestion                         INT NOT NULL,
    mes                             TINYINT NOT NULL,
    ci                              VARCHAR(20) NULL,
    codigo_dependiente              VARCHAR(20) NULL,
    total_ganado                    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_aportes_laborales         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    smn_no_imponibles               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    importe_sujeto_impuesto         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    impuesto_rciva                  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    credito_13pct_2smn              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    impuesto_neto                   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    f110_casilla_693                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_favor_fisco               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_favor_dependiente         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_anterior_dependiente      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    mantenimiento_valor             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_anterior_actualizado      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_utilizado                 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    rciva_retenido                  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_credito_fiscal            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_pago_cuenta_7rg           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at                      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_trib_emp_periodo (id_empleado, gestion, mes),
    INDEX idx_trib_planilla (id_planilla),
    INDEX idx_trib_periodo (gestion, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Planilla tributaria RC-IVA detallada por empleado/mes';

-- -------------------------------------------------
-- 2.8 HORARIOS (definición)
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_horario (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(80) NOT NULL COMMENT 'Turno Mañana, Horario Continuo, etc.',
    dia             ENUM('LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO') NOT NULL,
    hora_inicio     TIME NOT NULL,
    hora_fin        TIME NOT NULL,
    es_nocturno     TINYINT(1) NOT NULL DEFAULT 0,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_horario_nombre (nombre),
    INDEX idx_horario_dia (dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Definición de horarios de trabajo';

-- -------------------------------------------------
-- 2.9 ASIGNACIÓN HORARIO-CONTRATO (con vigencia)
-- NOTA: Columnas vigencia_desde/vigencia_hasta (no fecha_desde/fecha_hasta)
--       para coincidir con las consultas del HorarioService.php
-- -------------------------------------------------
CREATE TABLE IF NOT EXISTS rrhh_contrato_horario (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_contrato     INT NOT NULL,
    id_horario      INT NOT NULL,
    vigencia_desde  DATE NOT NULL COMMENT 'Desde cuándo aplica este horario',
    vigencia_hasta  DATE NULL COMMENT 'Hasta cuándo (NULL = vigente)',
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ch_contrato (id_contrato),
    INDEX idx_ch_horario (id_horario),
    UNIQUE KEY uk_ch_vigente (id_contrato, id_horario, vigencia_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Asignación de horarios a contratos (M:N con vigencia)';


-- ═══════════════════════════════════════════════════════════════════════════
-- PARTE 3: DATOS INICIALES (SEED DATA)
-- ═══════════════════════════════════════════════════════════════════════════

-- -------------------------------------------------
-- 3.1 PARÁMETROS GESTIÓN 2026
-- -------------------------------------------------
INSERT INTO rrhh_parametro_gestion (gestion, codigo, valor, descripcion, norma_referencia, vigente_desde) VALUES
(2026, 'SMN',                   3300.0000, 'Salario Mínimo Nacional (Bs)',                        'DS 5516 / RM 088/26',   '2026-05-01'),
(2026, 'APORTE_LAB_AFP',        1.7100,    '% Aporte laboral AFP (Jubilación)',                   'Ley 065',               '2026-01-01'),
(2026, 'APORTE_LAB_RIESGO_COMUN', 1.7100,  '% Riesgo Común',                                     'Ley 065',               '2026-01-01'),
(2026, 'APORTE_LAB_COMISION',   0.5000,    '% Comisión AFP',                                      'Ley 065',               '2026-01-01'),
(2026, 'APORTE_LAB_SOLIDARIO',  0.5000,    '% Aporte Solidario del Asegurado',                    'Ley 065',               '2026-01-01'),
(2026, 'APORTE_LAB_NACIONAL_SOL', 1.0000,  '% Aporte Nacional Solidario (>Bs13,000)',              'Ley 065',               '2026-01-01'),
(2026, 'APORTE_LAB_NAC_SOL2',   5.0000,    '% Aporte Nacional Solidario (>Bs25,000)',              'Ley 065',               '2026-01-01'),
(2026, 'APORTE_LAB_NAC_SOL3',   10.0000,   '% Aporte Nacional Solidario (>Bs35,000)',              'Ley 065',               '2026-01-01'),
(2026, 'APORTE_PAT_CNS',        10.0000,   '% Aporte patronal CNS',                               'Ley 924',               '2026-01-01'),
(2026, 'APORTE_PAT_RIESGO_PROF', 1.7100,   '% Riesgo Profesional (patronal)',                     'Ley 065',               '2026-01-01'),
(2026, 'APORTE_PAT_PROVIVIENDA', 2.0000,   '% Pro-vivienda',                                      'DS 21637',              '2026-01-01'),
(2026, 'APORTE_PAT_SOLIDARIO',  3.0000,    '% Aporte patronal solidario',                         'Ley 065',               '2026-01-01'),
(2026, 'RCIVA_ALICUOTA',        13.0000,   '% Alícuota RC-IVA',                                   'Ley 843',               '2026-01-01'),
(2026, 'RCIVA_MIN_EXENTO_SMN',  4.0000,    'Múltiplo de SMN mínimo no imponible RC-IVA',          'Ley 843',               '2026-01-01'),
(2026, 'DOBLE_AGUINALDO',       0.0000,    '1=Aplica doble aguinaldo (Esfuerzo por Bolivia), 0=No', 'DS (anual)',           '2026-01-01'),
(2026, 'BONO_ANT_BASE_SMN',     3.0000,    'Nro SMN base para bono antigüedad (empresa no productiva)', 'DS 21060',        '2026-01-01'),
(2026, 'BONO_ANT_BASE_SMN_PROD', 1.0000,   'Nro SMN base para bono antigüedad (empresa productiva)',   'DS 21060',        '2026-01-01');

-- Parámetros gestión 2025 (referencia histórica)
INSERT INTO rrhh_parametro_gestion (gestion, codigo, valor, descripcion, norma_referencia, vigente_desde) VALUES
(2025, 'SMN',                   2500.0000, 'Salario Mínimo Nacional (Bs)',                        'DS 5164',               '2025-05-01'),
(2025, 'APORTE_LAB_AFP',        1.7100,    '% Aporte laboral AFP',                                'Ley 065',               '2025-01-01'),
(2025, 'APORTE_LAB_RIESGO_COMUN', 1.7100,  '% Riesgo Común',                                     'Ley 065',               '2025-01-01'),
(2025, 'APORTE_LAB_COMISION',   0.5000,    '% Comisión AFP',                                      'Ley 065',               '2025-01-01'),
(2025, 'APORTE_LAB_SOLIDARIO',  0.5000,    '% Aporte Solidario del Asegurado',                    'Ley 065',               '2025-01-01'),
(2025, 'APORTE_LAB_NACIONAL_SOL', 1.0000,  '% Aporte Nacional Solidario (>Bs13,000)',              'Ley 065',               '2025-01-01'),
(2025, 'APORTE_LAB_NAC_SOL2',   5.0000,    '% Aporte Nacional Solidario (>Bs25,000)',              'Ley 065',               '2025-01-01'),
(2025, 'APORTE_LAB_NAC_SOL3',   10.0000,   '% Aporte Nacional Solidario (>Bs35,000)',              'Ley 065',               '2025-01-01'),
(2025, 'APORTE_PAT_CNS',        10.0000,   '% Aporte patronal CNS',                               'Ley 924',               '2025-01-01'),
(2025, 'APORTE_PAT_RIESGO_PROF', 1.7100,   '% Riesgo Profesional',                               'Ley 065',               '2025-01-01'),
(2025, 'APORTE_PAT_PROVIVIENDA', 2.0000,   '% Pro-vivienda',                                      'DS 21637',              '2025-01-01'),
(2025, 'APORTE_PAT_SOLIDARIO',  3.0000,    '% Aporte patronal solidario',                         'Ley 065',               '2025-01-01'),
(2025, 'RCIVA_ALICUOTA',        13.0000,   '% Alícuota RC-IVA',                                   'Ley 843',               '2025-01-01'),
(2025, 'RCIVA_MIN_EXENTO_SMN',  4.0000,    'Múltiplo de SMN exento de RC-IVA',                    'Ley 843',               '2025-01-01'),
(2025, 'DOBLE_AGUINALDO',       0.0000,    '1=Aplica, 0=No',                                      'DS (anual)',             '2025-01-01'),
(2025, 'BONO_ANT_BASE_SMN',     3.0000,    'Nro SMN base bono antigüedad',                        'DS 21060',              '2025-01-01'),
(2025, 'BONO_ANT_BASE_SMN_PROD', 1.0000,   'Nro SMN base bono antigüedad (productiva)',           'DS 21060',              '2025-01-01');


-- -------------------------------------------------
-- 3.2 ESCALA BONO ANTIGÜEDAD 2026 (DS 21060)
-- -------------------------------------------------
INSERT INTO rrhh_escala_bono_antiguedad (gestion, anios_desde, anios_hasta, porcentaje) VALUES
(2026,  2,  4,  5.00),
(2026,  5,  7, 11.00),
(2026,  8, 10, 18.00),
(2026, 11, 14, 26.00),
(2026, 15, 19, 34.00),
(2026, 20, 24, 42.00),
(2026, 25, NULL, 50.00);

-- Escala 2025
INSERT INTO rrhh_escala_bono_antiguedad (gestion, anios_desde, anios_hasta, porcentaje) VALUES
(2025,  2,  4,  5.00),
(2025,  5,  7, 11.00),
(2025,  8, 10, 18.00),
(2025, 11, 14, 26.00),
(2025, 15, 19, 34.00),
(2025, 20, 24, 42.00),
(2025, 25, NULL, 50.00);


-- -------------------------------------------------
-- 3.3 ESCALA VACACIONES 2026 (LGT Art. 44)
-- -------------------------------------------------
INSERT INTO rrhh_escala_vacaciones (gestion, anios_desde, anios_hasta, dias_habiles) VALUES
(2026,  1,  4, 15),
(2026,  5,  9, 20),
(2026, 10, NULL, 30);

-- Escala 2025
INSERT INTO rrhh_escala_vacaciones (gestion, anios_desde, anios_hasta, dias_habiles) VALUES
(2025,  1,  4, 15),
(2025,  5,  9, 20),
(2025, 10, NULL, 30);


-- -------------------------------------------------
-- 3.4 FERIADOS 2026 (Bolivia — Nacionales)
-- -------------------------------------------------
INSERT INTO rrhh_feriado (gestion, fecha, nombre, tipo) VALUES
(2026, '2026-01-01', 'Año Nuevo',                        'NACIONAL'),
(2026, '2026-01-22', 'Día del Estado Plurinacional',     'NACIONAL'),
(2026, '2026-02-16', 'Carnaval (lunes)',                 'NACIONAL'),
(2026, '2026-02-17', 'Carnaval (martes)',                'NACIONAL'),
(2026, '2026-04-03', 'Viernes Santo',                    'NACIONAL'),
(2026, '2026-05-01', 'Día del Trabajo',                  'NACIONAL'),
(2026, '2026-06-04', 'Corpus Christi',                   'NACIONAL'),
(2026, '2026-06-21', 'Año Nuevo Aymara',                 'NACIONAL'),
(2026, '2026-08-06', 'Día de la Independencia',          'NACIONAL'),
(2026, '2026-11-02', 'Día de Todos los Santos',          'NACIONAL'),
(2026, '2026-12-25', 'Navidad',                          'NACIONAL');

-- Feriados departamentales (principales)
INSERT INTO rrhh_feriado (gestion, fecha, nombre, tipo, departamento) VALUES
(2026, '2026-07-16', 'Día de La Paz',                   'DEPARTAMENTAL', 'La Paz'),
(2026, '2026-09-14', 'Día de Cochabamba',               'DEPARTAMENTAL', 'Cochabamba'),
(2026, '2026-09-24', 'Día de Santa Cruz',               'DEPARTAMENTAL', 'Santa Cruz'),
(2026, '2026-02-10', 'Día de Oruro',                    'DEPARTAMENTAL', 'Oruro'),
(2026, '2026-05-25', 'Día de Sucre/Chuquisaca',         'DEPARTAMENTAL', 'Chuquisaca'),
(2026, '2026-11-18', 'Día de Beni',                     'DEPARTAMENTAL', 'Beni'),
(2026, '2026-11-10', 'Día de Potosí',                   'DEPARTAMENTAL', 'Potosí'),
(2026, '2026-03-23', 'Día del Mar (Tarija)',             'DEPARTAMENTAL', 'Tarija'),
(2026, '2026-09-24', 'Día de Pando',                    'DEPARTAMENTAL', 'Pando');


-- -------------------------------------------------
-- 3.5 HORARIOS ESTÁNDAR GAMBARTE
-- -------------------------------------------------
INSERT INTO rrhh_horario (nombre, dia, hora_inicio, hora_fin) VALUES
('Turno Completo', 'LUNES',    '08:30', '12:30'),
('Turno Completo', 'LUNES',    '14:30', '18:30'),
('Turno Completo', 'MARTES',   '08:30', '12:30'),
('Turno Completo', 'MARTES',   '14:30', '18:30'),
('Turno Completo', 'MIERCOLES','08:30', '12:30'),
('Turno Completo', 'MIERCOLES','14:30', '18:30'),
('Turno Completo', 'JUEVES',   '08:30', '12:30'),
('Turno Completo', 'JUEVES',   '14:30', '18:30'),
('Turno Completo', 'VIERNES',  '08:30', '12:30'),
('Turno Completo', 'VIERNES',  '14:30', '18:30'),
('Turno Sábado',   'SABADO',   '08:30', '12:30');


-- -------------------------------------------------
-- 3.6 ACTUALIZAR area Y nivel EN CARGOS EXISTENTES
-- -------------------------------------------------
-- Ajustar los valores según los cargos que ya existan en tu tabla `cargo`.
-- Ejemplo (adaptar id_cargo a los valores reales de tu DB):
-- UPDATE cargo SET area = 'Gerencia',      nivel = 'Gerencia'   WHERE descripcion LIKE '%Gerente%';
-- UPDATE cargo SET area = 'Contabilidad',  nivel = 'Jefatura'   WHERE descripcion LIKE '%Contadora%';
-- UPDATE cargo SET area = 'Cumplimiento',  nivel = 'Jefatura'   WHERE descripcion LIKE '%Cumplimiento%';
-- UPDATE cargo SET area = 'Sistemas',      nivel = 'Jefatura'   WHERE descripcion LIKE '%Sistemas%';
-- UPDATE cargo SET area = 'Operaciones',   nivel = 'Operativo'  WHERE descripcion LIKE '%Cajero%';
-- UPDATE cargo SET area = 'Operaciones',   nivel = 'Operativo'  WHERE descripcion LIKE '%Operador%';
-- UPDATE cargo SET area = 'Administración',nivel = 'Operativo'  WHERE descripcion LIKE '%Asistente%';


-- ═══════════════════════════════════════════════════════════════════════════
-- PARTE 4: VERIFICACIÓN
-- ═══════════════════════════════════════════════════════════════════════════

-- Ejecutar después de la migración para verificar que todo se creó correctamente:
-- SELECT TABLE_NAME, TABLE_ROWS, TABLE_COMMENT
-- FROM INFORMATION_SCHEMA.TABLES
-- WHERE TABLE_SCHEMA = 'u752765750_cgr'
--   AND TABLE_NAME LIKE 'rrhh_%'
-- ORDER BY TABLE_NAME;

-- Objetos esperados:
-- ALTER en: cargo (area, nivel), trabajador (nua_cua, nro_asegurado_cns, foto_url)
-- VIEW: rrhh_empleado (sobre trabajador)
-- TABLAS nuevas (20):
-- rrhh_asistencia
-- rrhh_capacitacion
-- rrhh_capacitacion_empleado
-- rrhh_contrato
-- rrhh_contrato_horario
-- rrhh_contribucion_afp
-- rrhh_csbp_alta
-- rrhh_csbp_baja
-- rrhh_csbp_aporte
-- rrhh_escala_bono_antiguedad
-- rrhh_escala_vacaciones
-- rrhh_evaluacion
-- rrhh_feriado
-- rrhh_finiquito
-- rrhh_gestora
-- rrhh_horario
-- rrhh_parametro_gestion
-- rrhh_planilla
-- rrhh_planilla_detalle
-- rrhh_planilla_tributaria
-- rrhh_vacacion
