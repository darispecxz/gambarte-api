-- ============================================================================
-- MIGRACIÓN RRHH: 5 módulos nuevos
-- Fecha: 2026-08-31
-- Módulos: CSBP, Gestoras AFP, Finiquito, Planilla Tributaria, Horarios
-- ============================================================================
-- IMPORTANTE: Ejecutar en la base de datos u752765750_cgr (MariaDB)
-- ============================================================================

-- ═══════════════════════════════════════════════════════════════════════════
-- 1. CSBP (Caja Nacional de Salud / Caja de Salud de la Banca Privada)
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS rrhh_csbp_alta (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_contrato     INT NOT NULL,
    fecha_alta      DATE NOT NULL COMMENT 'Fecha de registro/alta en la CSBP',
    fecha_efectiva  DATE NULL COMMENT 'Fecha efectiva del seguro',
    nro_asegurado   VARCHAR(30) NULL COMMENT 'Número de asegurado CSBP',
    estado          ENUM('PENDIENTE','EN_TRAMITE','COMPLETADO','RECHAZADO') NOT NULL DEFAULT 'PENDIENTE',
    condicion       ENUM('VIGENTE','VENCIDO') NOT NULL DEFAULT 'VIGENTE',
    pagado          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se realizó el pago del trámite',
    monto_pago      DECIMAL(10,2) NULL COMMENT 'Monto del pago del trámite',
    nro_aviso       VARCHAR(50) NULL COMMENT 'Número de aviso de alta',
    archivo         VARCHAR(255) NULL COMMENT 'Ruta del documento escaneado',
    observaciones   TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_csbp_alta_contrato (id_contrato),
    INDEX idx_csbp_alta_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Altas de empleados en la Caja de Salud';

CREATE TABLE IF NOT EXISTS rrhh_csbp_baja (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_contrato     INT NOT NULL,
    fecha_baja      DATE NOT NULL COMMENT 'Fecha de baja en la CSBP',
    fecha_efectiva  DATE NULL COMMENT 'Fecha efectiva de cese del seguro',
    motivo          ENUM('RETIRO_VOLUNTARIO','DESPIDO','FIN_CONTRATO','JUBILACION','FALLECIMIENTO') NOT NULL,
    estado          ENUM('PENDIENTE','EN_TRAMITE','COMPLETADO','RECHAZADO') NOT NULL DEFAULT 'PENDIENTE',
    pagado          TINYINT(1) NOT NULL DEFAULT 0,
    monto_pago      DECIMAL(10,2) NULL,
    nro_aviso       VARCHAR(50) NULL COMMENT 'Número de aviso de baja',
    archivo         VARCHAR(255) NULL,
    observaciones   TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_csbp_baja_contrato (id_contrato),
    INDEX idx_csbp_baja_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Bajas de empleados en la Caja de Salud';

CREATE TABLE IF NOT EXISTS rrhh_csbp_aporte (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    gestion             INT NOT NULL,
    mes                 TINYINT NOT NULL COMMENT '1-12',
    codigo_planilla     VARCHAR(30) NULL COMMENT 'Código del registro en la plataforma CSBP',
    regional            VARCHAR(50) NOT NULL COMMENT 'Regional CSBP (La Paz, Santa Cruz, etc.)',
    fecha_presentacion  DATE NOT NULL,
    fecha_deposito      DATE NULL,
    total_aporte        DECIMAL(12,2) NOT NULL COMMENT 'Total aportado al periodo',
    multas              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    intereses           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_cancelado     DECIMAL(12,2) NOT NULL COMMENT 'total_aporte + multas + intereses',
    nro_empleados       INT NOT NULL DEFAULT 0 COMMENT 'Cantidad de empleados en la planilla',
    estado              ENUM('PENDIENTE','PRESENTADO','PAGADO','OBSERVADO') NOT NULL DEFAULT 'PENDIENTE',
    archivo_referencia  VARCHAR(255) NULL COMMENT 'Nombre del archivo subido',
    observaciones       TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_csbp_aporte_periodo (gestion, mes, regional),
    INDEX idx_csbp_aporte_gestion (gestion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Resumen mensual de aportes a la Caja de Salud';


-- ═══════════════════════════════════════════════════════════════════════════
-- 2. GESTORAS AFP + CONTRIBUCIONES
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS rrhh_gestora (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_contrato     INT NOT NULL,
    nombre_gestora  VARCHAR(100) NOT NULL COMMENT 'Ej: Futuro de Bolivia, BBVA Previsión',
    nua_cua         VARCHAR(30) NULL COMMENT 'Número Único de Afiliado / CUA',
    fecha_afiliacion DATE NULL,
    estado          ENUM('ACTIVO','INACTIVO','TRASPASO') NOT NULL DEFAULT 'ACTIVO',
    descripcion     TEXT NULL,
    archivo         VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gestora_contrato (id_contrato),
    INDEX idx_gestora_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Gestora AFP asignada por contrato';

CREATE TABLE IF NOT EXISTS rrhh_contribucion_afp (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    gestion                 INT NOT NULL,
    mes                     TINYINT NOT NULL,
    codigo_planilla         VARCHAR(30) NULL COMMENT 'Código FPC',
    nro_fpc                 INT NULL COMMENT 'Número de Formulario de Pago de Contribuciones',
    nombre_gestora          VARCHAR(100) NOT NULL,
    razon_social            VARCHAR(200) NOT NULL DEFAULT 'GIROS Y REMESAS DE DINERO GAMBARTE BOLIVIA S.R.L.',
    sucursal                VARCHAR(100) NULL,
    tipo_pago               ENUM('REGULAR','RETROACTIVO','COMPLEMENTARIO') NOT NULL DEFAULT 'REGULAR',
    total_aporte_laboral    DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'AFP 12.71% total',
    total_aporte_patronal   DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Riesgo prof + pro-vivienda + solidario',
    total_contribucion      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Laboral + patronal',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Contribuciones mensuales a gestoras AFP';


-- ═══════════════════════════════════════════════════════════════════════════
-- 3. FINIQUITO DETALLADO
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS rrhh_finiquito (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    id_liquidacion          INT NULL COMMENT 'FK a rrhh_liquidacion existente (si aplica)',
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
    promedio_indemnizacion  DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Suma 3 meses / 3',
    -- Beneficios
    indemnizacion           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    desahucio               DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '3 sueldos si despido injustificado',
    aguinaldo_navidad       DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Proporcional',
    doble_aguinaldo         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Si aplica D.S. 1802',
    vacaciones_dias         DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT 'Días pendientes',
    vacaciones_monto        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    prima                   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    otros_beneficios        DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Duodécimas último mes',
    total_beneficios        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Deducciones
    retencion_rciva         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '13% sobre (vacaciones + prima)',
    otros_descuentos        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_deducciones       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- Líquido
    liquido_pagable         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado                  ENUM('BORRADOR','CALCULADO','APROBADO','PAGADO','ANULADO') NOT NULL DEFAULT 'BORRADOR',
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_finiquito_empleado (id_empleado),
    INDEX idx_finiquito_liquidacion (id_liquidacion),
    INDEX idx_finiquito_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Finiquito detallado con desglose de remuneraciones y beneficios sociales';


-- ═══════════════════════════════════════════════════════════════════════════
-- 4. PLANILLA TRIBUTARIA (RC-IVA)
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS rrhh_planilla_tributaria (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    id_planilla                     INT NULL COMMENT 'FK a rrhh_planilla',
    id_empleado                     INT NOT NULL,
    gestion                         INT NOT NULL,
    mes                             TINYINT NOT NULL,
    ci                              VARCHAR(20) NULL,
    -- Columnas del formulario tributario
    codigo_dependiente              VARCHAR(20) NULL COMMENT 'Código dependiente RC-IVA',
    total_ganado                    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_aportes_laborales         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'AFP + RC + Comisión + Solidario',
    smn_no_imponibles               DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '2 SMN no imponibles',
    importe_sujeto_impuesto         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'total_ganado - aportes - 2SMN',
    impuesto_rciva                  DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '13% del importe sujeto',
    credito_13pct_2smn              DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '13% de 2 SMN',
    impuesto_neto                   DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'impuesto - crédito 13%',
    -- Formulario 110
    f110_casilla_693                DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'F110 v4 casilla 693 - facturas',
    saldo_favor_fisco               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_favor_dependiente         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Saldo anterior
    saldo_anterior_dependiente      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    mantenimiento_valor             DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'UFV actualización',
    saldo_anterior_actualizado      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    saldo_utilizado                 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Retención final
    rciva_retenido                  DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto final retenido al empleado',
    -- Saldos finales
    saldo_credito_fiscal            DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo que pasa al mes siguiente',
    saldo_pago_cuenta_7rg           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at                      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_trib_emp_periodo (id_empleado, gestion, mes),
    INDEX idx_trib_planilla (id_planilla),
    INDEX idx_trib_periodo (gestion, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Planilla tributaria RC-IVA detallada por empleado/mes';


-- ═══════════════════════════════════════════════════════════════════════════
-- 5. HORARIOS
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS rrhh_horario (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(80) NOT NULL COMMENT 'Ej: Turno Mañana, Horario Continuo, etc.',
    dia             ENUM('LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO') NOT NULL,
    hora_inicio     TIME NOT NULL,
    hora_fin        TIME NOT NULL,
    es_nocturno     TINYINT(1) NOT NULL DEFAULT 0,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_horario_nombre (nombre),
    INDEX idx_horario_dia (dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Definición de horarios de trabajo';

CREATE TABLE IF NOT EXISTS rrhh_contrato_horario (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    id_contrato     INT NOT NULL,
    id_horario      INT NOT NULL,
    fecha_desde     DATE NOT NULL COMMENT 'Desde cuándo aplica este horario',
    fecha_hasta     DATE NULL COMMENT 'Hasta cuándo (NULL = vigente)',
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ch_contrato (id_contrato),
    INDEX idx_ch_horario (id_horario),
    UNIQUE KEY uk_ch_vigente (id_contrato, id_horario, fecha_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Asignación de horarios a contratos (M:N con vigencia)';


-- ═══════════════════════════════════════════════════════════════════════════
-- DATOS INICIALES DE HORARIOS (horario estándar Gambarte)
-- ═══════════════════════════════════════════════════════════════════════════

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
