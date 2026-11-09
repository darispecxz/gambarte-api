-- ============================================================
-- 011_uif_mejoras_plaft.sql
-- Parametrizar constantes PLAFT + campos UIF en cambio/remesa/giro
-- ============================================================

-- ── 1. Parámetros generales PLAFT (RiesgoPLAFT.php) ────────
INSERT INTO parametros (nombre, slug, descripcion, valor, unidad, fecha_registro, estado)
VALUES
  ('Umbral BOB PLAFT',              'plaft_umbral_bob',              'PLAFT: Umbral operación relevante en BOB',         '70000', 'BOB', NOW(), 'A'),
  ('Fraccionamiento min ops',       'plaft_fraccionamiento_minops',  'PLAFT: Mín. operaciones para fraccionamiento',     '3',     '0',   NOW(), 'A'),
  ('Frecuencia ops PLAFT',          'plaft_frecuencia_ops',          'PLAFT: Mín. operaciones para alerta frecuencia',   '15',    '0',   NOW(), 'A'),
  ('Umbral origen destino USD',     'umbral_origen_destino_usd',     'Umbral USD para requerir origen/destino sin PCC',  '1000',  'USD', NOW(), 'A'),
  ('Usuario habitual ops',          'usuario_habitual_ops',          'Nro ops en ventana para considerar usuario habitual', '3',   '0',   NOW(), 'A'),
  ('Usuario habitual dias',         'usuario_habitual_dias',         'Días de ventana para usuario habitual',             '30',    '0',   NOW(), 'A')
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

-- ── 2. Parámetros UIF cambio de moneda (CajeroCambioService) ──
INSERT INTO parametros (nombre, slug, descripcion, valor, unidad, fecha_registro, estado)
VALUES
  ('Cambio formulario op única',                  'monto_cambio_moneda_operacion_unica',                  'Cambio: umbral formulario op. única (USD)',           '10000', 'USD', NOW(), 'A'),
  ('Cambio PCC-06 op única',                      'monto_cambio_moneda_pcc06_operacion_unica',            'Cambio: umbral PCC-06 op. única (USD)',               '10000', 'USD', NOW(), 'A'),
  ('Cambio formulario ops múltiples',             'monto_cambio_moneda_operaciones_multiples',            'Cambio: umbral formulario ops. múltiples (USD)',      '10000', 'USD', NOW(), 'A'),
  ('Cambio PCC-06 ops múltiples',                 'monto_cambio_moneda_pcc06_operaciones_multiples',      'Cambio: umbral PCC-06 ops. múltiples (USD)',          '10000', 'USD', NOW(), 'A'),
  ('Cambio días formulario',                      'dias_control_multiples_cambio_moneda_formulario',      'Cambio: días ventana múltiples ops formulario',       '30',    '0',   NOW(), 'A'),
  ('Cambio días PCC-06',                          'dias_control_multiples_cambio_moneda_pcc06',           'Cambio: días ventana múltiples ops PCC-06',           '30',    '0',   NOW(), 'A'),
  ('Cambio nro ops formulario',                   'numero_operaciones_multiples_cambio_moneda_formulario','Cambio: nro ops múltiples formulario',                '3',     '0',   NOW(), 'A'),
  ('Cambio nro ops PCC-06',                       'numero_operaciones_multiples_cambio_moneda_pcc06',     'Cambio: nro ops múltiples PCC-06',                    '3',     '0',   NOW(), 'A')
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

-- ── 3. Parámetros UIF remesa internacional (CajeroRemesaService) ──
INSERT INTO parametros (nombre, slug, descripcion, valor, unidad, fecha_registro, estado)
VALUES
  ('Remesa formulario op única',                  'monto_remesa_formulario_operacion_unica',              'Remesa: umbral formulario op. única (USD)',            '500',   'USD', NOW(), 'A'),
  ('Remesa formulario ops múltiples',             'monto_remesa_formulario_operaciones_multiples',        'Remesa: umbral formulario ops. múltiples (USD)',       '1000',  'USD', NOW(), 'A'),
  ('Remesa PCC-08 op única',                      'monto_remesa_pcc08_operacion_unica',                   'Remesa: umbral PCC-08 op. única (USD)',                '1000',  'USD', NOW(), 'A'),
  ('Remesa PCC-08 ops múltiples',                 'monto_remesa_pcc08_operaciones_multiples',             'Remesa: umbral PCC-08 ops. múltiples (USD)',           '2000',  'USD', NOW(), 'A'),
  ('Remesa días formulario',                      'dias_control_multiples_remesa_formulario',             'Remesa: días ventana múltiples ops formulario',        '30',    '0',   NOW(), 'A'),
  ('Remesa días PCC-08',                          'dias_control_multiples_remesa_pcc08',                  'Remesa: días ventana múltiples ops PCC-08',            '30',    '0',   NOW(), 'A'),
  ('Remesa nro ops formulario',                   'numero_operaciones_multiples_remesa_formulario',       'Remesa: nro ops múltiples formulario',                 '3',     '0',   NOW(), 'A'),
  ('Remesa nro ops PCC-08',                       'numero_operaciones_multiples_remesa_pcc08',            'Remesa: nro ops múltiples PCC-08',                     '3',     '0',   NOW(), 'A'),
  ('Monto máximo remesa',                         'monto_maximo_remesa',                                  'Remesa: monto máximo permitido (USD)',                 '50000', 'USD', NOW(), 'A'),
  ('Validez cotización remesa',                   'cotizacion_remesa_validez_horas',                      'Remesa: horas de validez de cotización',               '4',     '0',   NOW(), 'A')
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

-- ── 4. Parámetros UIF giro nacional (CajeroGiroService) ────
INSERT INTO parametros (nombre, slug, descripcion, valor, unidad, fecha_registro, estado)
VALUES
  ('Giro formulario op única',                    'monto_giro_operacion_unica',                           'Giro: umbral formulario op. única (BOB)',              '50000', 'BOB', NOW(), 'A'),
  ('Giro formulario ops múltiples',               'monto_giro_operaciones_multiples',                     'Giro: umbral formulario ops. múltiples (BOB)',         '50000', 'BOB', NOW(), 'A'),
  ('Giro PCC-08 op única',                        'monto_giro_pcc08_operacion_unica',                     'Giro: umbral PCC-08 op. única (BOB)',                  '50000', 'BOB', NOW(), 'A'),
  ('Giro PCC-08 ops múltiples',                   'monto_giro_pcc08_operaciones_multiples',               'Giro: umbral PCC-08 ops. múltiples (BOB)',             '50000', 'BOB', NOW(), 'A'),
  ('Giro días formulario',                        'dias_control_multiples_giros_formulario',              'Giro: días ventana múltiples ops formulario',          '30',    '0',   NOW(), 'A'),
  ('Giro días PCC-08',                            'dias_control_multiples_giros_pcc08',                   'Giro: días ventana múltiples ops PCC-08',              '30',    '0',   NOW(), 'A'),
  ('Giro nro ops formulario',                     'numero_operaciones_multiples_giros_formulario',        'Giro: nro ops múltiples formulario',                   '3',     '0',   NOW(), 'A'),
  ('Giro nro ops PCC-08',                         'numero_operaciones_multiples_giros_pcc08',             'Giro: nro ops múltiples PCC-08',                       '3',     '0',   NOW(), 'A')
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

-- ── 5. Columnas nuevas en tablas de operación ──────────────
-- Verificamos existencia antes de agregar para ser idempotente

SET @dbname = DATABASE();

-- 5a. cambio: impresion_pcc06
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
  WHERE table_schema = @dbname AND table_name = 'cambio' AND column_name = 'impresion_pcc06';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE cambio ADD COLUMN impresion_pcc06 CHAR(1) NOT NULL DEFAULT \'N\'',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5b. cambio: observaciones_uif
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
  WHERE table_schema = @dbname AND table_name = 'cambio' AND column_name = 'observaciones_uif';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE cambio ADD COLUMN observaciones_uif TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5c. cambio: usuario_financiero_id
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
  WHERE table_schema = @dbname AND table_name = 'cambio' AND column_name = 'usuario_financiero_id';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE cambio ADD COLUMN usuario_financiero_id INT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5d. cambio: persona_factura_id
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
  WHERE table_schema = @dbname AND table_name = 'cambio' AND column_name = 'persona_factura_id';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE cambio ADD COLUMN persona_factura_id INT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5e. remesa: impresion_pcc08
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
  WHERE table_schema = @dbname AND table_name = 'remesa' AND column_name = 'impresion_pcc08';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE remesa ADD COLUMN impresion_pcc08 CHAR(1) NOT NULL DEFAULT \'N\'',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5f. remesa: observaciones_uif
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
  WHERE table_schema = @dbname AND table_name = 'remesa' AND column_name = 'observaciones_uif';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE remesa ADD COLUMN observaciones_uif TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5g. giro: observaciones_uif
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
  WHERE table_schema = @dbname AND table_name = 'giro' AND column_name = 'observaciones_uif';
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE giro ADD COLUMN observaciones_uif TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
