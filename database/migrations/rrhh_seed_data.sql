-- ============================================================================
-- SEED DATA — Módulo RRHH — GAMBARTE BOLIVIA S.R.L.
-- Base de datos: localhost (desarrollo)
-- Fecha: 2026-09-01
-- ============================================================================
-- Ejecutar DESPUÉS de rrhh_migracion_completa.sql y rrhh_fix_collation.sql
-- Este script genera datos operativos para los empleados existentes en `trabajador`.
-- ============================================================================

-- ═══════════════════════════════════════════════════════════════════════════
-- 1. CONTRATOS — Uno por cada trabajador activo
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO rrhh_contrato (id_empleado, id_cargo, id_agencia, tipo_contrato, fecha_inicio, fecha_fin, sueldo_base, jornada_horas, estado)
SELECT
    t.cod_trab,
    t.id_cargo,
    (SELECT ac.id_agencia FROM usuario u JOIN acceso ac ON ac.cod_usuario = u.cod_usuario WHERE u.cod_trab = t.cod_trab LIMIT 1),
    'INDEFINIDO',
    COALESCE(t.fec_ingreso, '2020-01-01'),
    NULL,
    CASE
        WHEN t.id_cargo IN (SELECT id_cargo FROM cargo WHERE descripcion LIKE '%Gerente%') THEN 12000.00
        WHEN t.id_cargo IN (SELECT id_cargo FROM cargo WHERE descripcion LIKE '%Jefe%' OR descripcion LIKE '%Director%') THEN 8500.00
        WHEN t.id_cargo IN (SELECT id_cargo FROM cargo WHERE descripcion LIKE '%Contador%' OR descripcion LIKE '%Auditor%') THEN 6500.00
        WHEN t.id_cargo IN (SELECT id_cargo FROM cargo WHERE descripcion LIKE '%Oficial%' OR descripcion LIKE '%Analista%') THEN 5500.00
        WHEN t.id_cargo IN (SELECT id_cargo FROM cargo WHERE descripcion LIKE '%Asistente%' OR descripcion LIKE '%Auxiliar%') THEN 4000.00
        WHEN t.id_cargo IN (SELECT id_cargo FROM cargo WHERE descripcion LIKE '%Cajero%' OR descripcion LIKE '%Operador%') THEN 3500.00
        ELSE 3800.00
    END,
    8.00,
    CASE
        WHEN (SELECT u.estado FROM usuario u WHERE u.cod_trab = t.cod_trab LIMIT 1) = 'A' THEN 'VIGENTE'
        WHEN t.fec_cese IS NOT NULL AND t.fec_cese != '0000-00-00' THEN 'RESCINDIDO'
        ELSE 'VIGENTE'
    END
FROM trabajador t
WHERE t.cod_trab > 0
  AND NOT EXISTS (SELECT 1 FROM rrhh_contrato c WHERE c.id_empleado = t.cod_trab);


-- ═══════════════════════════════════════════════════════════════════════════
-- 2. CSBP ALTAS — Para cada contrato vigente
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO rrhh_csbp_alta (id_contrato, fecha_alta, fecha_efectiva, nro_asegurado, estado, condicion)
SELECT
    c.id,
    c.fecha_inicio,
    DATE_ADD(c.fecha_inicio, INTERVAL 30 DAY),
    LPAD(c.id_empleado, 8, '0'),
    'COMPLETADO',
    'VIGENTE'
FROM rrhh_contrato c
WHERE c.estado = 'VIGENTE'
  AND NOT EXISTS (SELECT 1 FROM rrhh_csbp_alta a WHERE a.id_contrato = c.id);


-- ═══════════════════════════════════════════════════════════════════════════
-- 3. GESTORA AFP — Asignar a cada contrato vigente
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO rrhh_gestora (id_contrato, nombre_gestora, nua_cua, fecha_afiliacion, estado)
SELECT
    c.id,
    CASE WHEN c.id % 2 = 0 THEN 'Futuro de Bolivia S.A. AFP' ELSE 'BBVA Previsión AFP S.A.' END,
    CONCAT('NUA-', LPAD(c.id_empleado, 6, '0')),
    c.fecha_inicio,
    'ACTIVO'
FROM rrhh_contrato c
WHERE c.estado = 'VIGENTE'
  AND NOT EXISTS (SELECT 1 FROM rrhh_gestora g WHERE g.id_contrato = c.id);


-- ═══════════════════════════════════════════════════════════════════════════
-- 4. ASIGNACIÓN DE HORARIOS — Turno Completo para todos los vigentes
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO rrhh_contrato_horario (id_contrato, id_horario, vigencia_desde)
SELECT c.id, h.id, c.fecha_inicio
FROM rrhh_contrato c
CROSS JOIN rrhh_horario h
WHERE c.estado = 'VIGENTE'
  AND h.nombre = 'Turno Completo'
  AND h.activo = 1
  AND NOT EXISTS (
      SELECT 1 FROM rrhh_contrato_horario ch
      WHERE ch.id_contrato = c.id AND ch.id_horario = h.id
  );


-- ═══════════════════════════════════════════════════════════════════════════
-- 5. CAPACITACIONES — Programas obligatorios ASFI
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO rrhh_capacitacion (titulo, tipo, fecha_inicio, fecha_fin, horas_duracion, es_obligatoria, estado) VALUES
('Prevención de Legitimación de Ganancias Ilícitas y Financiamiento del Terrorismo', 'PLA_FT', '2026-03-01', '2026-03-15', 16, 1, 'COMPLETADA'),
('Atención al Consumidor Financiero', 'ATENCION_CONSUMIDOR', '2026-04-10', '2026-04-12', 8, 1, 'COMPLETADA'),
('Seguridad de la Información — ASFI', 'SEGURIDAD', '2026-06-01', '2026-06-05', 20, 1, 'COMPLETADA'),
('Normativa Cambiaria Vigente', 'REGULATORIA', '2026-07-15', '2026-07-17', 12, 1, 'EN_CURSO'),
('Gestión de Riesgos Operativos', 'SEGURIDAD', '2026-09-01', '2026-09-10', 16, 1, 'PROGRAMADA'),
('Excel Avanzado para Reportes Financieros', 'DESARROLLO', '2026-05-20', '2026-05-22', 8, 0, 'COMPLETADA'),
('Primeros Auxilios y Seguridad Laboral', 'SEGURIDAD', '2026-08-10', '2026-08-11', 6, 0, 'COMPLETADA');

-- Inscribir a todos los empleados activos en las capacitaciones obligatorias completadas
INSERT INTO rrhh_capacitacion_empleado (id_empleado, id_capacitacion, estado, nota)
SELECT e.id, cap.id,
       CASE WHEN cap.estado = 'COMPLETADA' THEN 'APROBADO' ELSE 'INSCRITO' END,
       CASE WHEN cap.estado = 'COMPLETADA' THEN ROUND(70 + RAND() * 30, 1) ELSE NULL END
FROM rrhh_empleado e
JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
CROSS JOIN rrhh_capacitacion cap
WHERE cap.es_obligatoria = 1
  AND e.estado = 'A'
  AND NOT EXISTS (
      SELECT 1 FROM rrhh_capacitacion_empleado ce
      WHERE ce.id_empleado = e.id AND ce.id_capacitacion = cap.id
  );


-- ═══════════════════════════════════════════════════════════════════════════
-- 6. EVALUACIONES — Semestre 1 2026 para todos los activos
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO rrhh_evaluacion (id_empleado, periodo, puntaje, categoria, estado, observaciones)
SELECT
    e.id,
    '2026-S1',
    ROUND(60 + RAND() * 40, 1),
    CASE
        WHEN ROUND(60 + RAND() * 40, 1) >= 90 THEN 'EXCELENTE'
        WHEN ROUND(60 + RAND() * 40, 1) >= 75 THEN 'BUENO'
        WHEN ROUND(60 + RAND() * 40, 1) >= 60 THEN 'REGULAR'
        ELSE 'DEFICIENTE'
    END,
    'COMPLETADA',
    'Evaluación semestral S1 2026'
FROM rrhh_empleado e
JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
WHERE e.estado = 'A'
  AND NOT EXISTS (
      SELECT 1 FROM rrhh_evaluacion ev
      WHERE ev.id_empleado = e.id AND ev.periodo = '2026-S1'
  );


-- ═══════════════════════════════════════════════════════════════════════════
-- 7. APORTES CSBP — Meses enero a agosto 2026
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO rrhh_csbp_aporte (gestion, mes, regional, fecha_presentacion, total_aporte, total_cancelado, nro_empleados, estado)
SELECT
    2026, m.mes, 'La Paz',
    DATE(CONCAT('2026-', LPAD(m.mes, 2, '0'), '-10')),
    ROUND((SELECT COUNT(*) FROM rrhh_contrato WHERE estado = 'VIGENTE') * 450.00, 2),
    ROUND((SELECT COUNT(*) FROM rrhh_contrato WHERE estado = 'VIGENTE') * 450.00, 2),
    (SELECT COUNT(*) FROM rrhh_contrato WHERE estado = 'VIGENTE'),
    'PAGADO'
FROM (SELECT 1 AS mes UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
      UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8) m
WHERE NOT EXISTS (
    SELECT 1 FROM rrhh_csbp_aporte a WHERE a.gestion = 2026 AND a.mes = m.mes AND a.regional = 'La Paz'
);


-- ═══════════════════════════════════════════════════════════════════════════
-- 8. CONTRIBUCIONES AFP — Meses enero a agosto 2026
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO rrhh_contribucion_afp (gestion, mes, nombre_gestora, total_aporte_laboral, total_aporte_patronal, total_contribucion, nro_asegurados, estado)
SELECT
    2026, m.mes, g.nombre_gestora,
    ROUND(g.cnt * 350.00, 2),
    ROUND(g.cnt * 280.00, 2),
    ROUND(g.cnt * 630.00, 2),
    g.cnt,
    'PAGADO'
FROM (SELECT 1 AS mes UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
      UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8) m
CROSS JOIN (
    SELECT nombre_gestora, COUNT(*) AS cnt
    FROM rrhh_gestora WHERE estado = 'ACTIVO'
    GROUP BY nombre_gestora
) g
WHERE NOT EXISTS (
    SELECT 1 FROM rrhh_contribucion_afp ca
    WHERE ca.gestion = 2026 AND ca.mes = m.mes AND ca.nombre_gestora = g.nombre_gestora AND ca.tipo_pago = 'REGULAR'
);


-- ═══════════════════════════════════════════════════════════════════════════
-- VERIFICACIÓN
-- ═══════════════════════════════════════════════════════════════════════════
-- SELECT 'Contratos' AS tabla, COUNT(*) AS registros FROM rrhh_contrato
-- UNION ALL SELECT 'CSBP Altas', COUNT(*) FROM rrhh_csbp_alta
-- UNION ALL SELECT 'Gestoras', COUNT(*) FROM rrhh_gestora
-- UNION ALL SELECT 'Horario Asig.', COUNT(*) FROM rrhh_contrato_horario
-- UNION ALL SELECT 'Capacitaciones', COUNT(*) FROM rrhh_capacitacion
-- UNION ALL SELECT 'Cap. Empleados', COUNT(*) FROM rrhh_capacitacion_empleado
-- UNION ALL SELECT 'Evaluaciones', COUNT(*) FROM rrhh_evaluacion
-- UNION ALL SELECT 'CSBP Aportes', COUNT(*) FROM rrhh_csbp_aporte
-- UNION ALL SELECT 'Contrib. AFP', COUNT(*) FROM rrhh_contribucion_afp;
