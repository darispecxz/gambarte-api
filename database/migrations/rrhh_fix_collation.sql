-- ============================================================================
-- FIX COLLATION — Módulo RRHH (versión definitiva)
--
-- Contexto:
--   - Conexión PDO (config/database.php): utf8mb4 / utf8mb4_unicode_ci
--   - Tablas CGR existentes: utf8 / utf8_bin
--   - Algunas columnas de trabajador (cel_trab, tel_trab): latin1
--   - Tablas RRHH nuevas: creadas con default del servidor (utf8mb4_general_ci)
--
-- Solución:
--   1. Convertir tablas RRHH a utf8mb4_unicode_ci (coincide con la conexión PDO)
--   2. Recrear VIEW con CONVERT para columnas latin1 y COLLATE en el CASE
--
-- MySQL promueve utf8→utf8mb4 automáticamente en JOINs, así que las tablas
-- CGR existentes (utf8_bin) funcionan sin cambios con la conexión utf8mb4.
-- ============================================================================

-- ── Paso 1: Tablas RRHH → utf8mb4_unicode_ci (match conexión PDO) ──
ALTER TABLE rrhh_contrato             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_parametro_gestion    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_escala_bono_antiguedad CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_escala_vacaciones    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_feriado              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_planilla             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_planilla_detalle     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_asistencia           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_vacacion             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_capacitacion         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_capacitacion_empleado CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_evaluacion           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_csbp_alta            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_csbp_baja            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_csbp_aporte          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_gestora              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_contribucion_afp     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_finiquito            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_planilla_tributaria  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_horario              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE rrhh_contrato_horario     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── Paso 2: Recrear VIEW ──
-- CONVERT(... USING utf8mb4) para columnas latin1 (cel_trab).
-- COLLATE utf8mb4_unicode_ci en el CASE para forzar coercibilidad EXPLICIT
-- (evita conflicto COERCIBLE vs COERCIBLE con parámetros de conexión).
-- Quitar columnas area y nivel de cargo (ya no se usan, el area viene de trabajador.id_area)
ALTER TABLE cargo DROP COLUMN IF EXISTS area;
ALTER TABLE cargo DROP COLUMN IF EXISTS nivel;

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
