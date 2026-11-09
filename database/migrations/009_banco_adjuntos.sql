-- ============================================================
-- 009: Comprobantes adjuntos de operaciones bancarias
--
-- EJECUTAR CONTRA LA BASE DE OPERACIONES (`u752765750_cgr`), NO contra
-- contabilidad.
--
-- El legacy subía los adjuntos a disco con el nombre que mandaba el cliente
-- (`$_POST['namefile']`) y no guardaba ninguna referencia en base de datos: solo
-- `guardarcheque.php` escribía `{cuenta}_{cheque}_an.{ext}` y `_re.{ext}` en una
-- carpeta por fecha, y la relación con el cheque se reconstruía parseando la
-- glosa del movimiento.
--
-- Aquí la ruta se guarda en la propia operación, con nombre generado por el
-- servidor (nunca el del cliente) y extensión validada.
--
-- Los cheques llevan DOS caras: anverso y reverso.
-- ============================================================

-- Traspasos
ALTER TABLE banco_traspaso
    ADD COLUMN IF NOT EXISTS comprobante_path VARCHAR(255) NULL
        COMMENT 'Ruta del comprobante subido',
    ADD COLUMN IF NOT EXISTS comprobante_nombre VARCHAR(255) NULL
        COMMENT 'Nombre original del archivo';

-- Depósitos y retiros
ALTER TABLE banco_deposito
    ADD COLUMN IF NOT EXISTS comprobante_nombre VARCHAR(255) NULL
        COMMENT 'Nombre original del archivo (comprobante_path ya existía)';

-- Cheques: anverso y reverso
ALTER TABLE banco_cheque_mov
    ADD COLUMN IF NOT EXISTS adjunto_anverso_path VARCHAR(255) NULL
        COMMENT 'Imagen del anverso del cheque',
    ADD COLUMN IF NOT EXISTS adjunto_anverso_nombre VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS adjunto_reverso_path VARCHAR(255) NULL
        COMMENT 'Imagen del reverso del cheque',
    ADD COLUMN IF NOT EXISTS adjunto_reverso_nombre VARCHAR(255) NULL;

-- Envíos de cheque entre agencias
ALTER TABLE banco_cheque_envio
    ADD COLUMN IF NOT EXISTS comprobante_path VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS comprobante_nombre VARCHAR(255) NULL;
