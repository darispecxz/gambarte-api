-- Add the cash-box to vault operation to the audit log enum.
-- Older inserts were coerced to an empty enum value on databases with strict mode disabled.
ALTER TABLE pos_operacion_log
    MODIFY COLUMN tipo_operacion ENUM(
        'apertura_caja', 'cierre_caja',
        'apertura_boveda', 'cierre_boveda',
        'cambio', 'giro', 'remesa',
        'pago_giro', 'pago_remesa',
        'devolucion_giro', 'devolucion_remesa',
        'traspaso_boveda', 'solicitud_fondos', 'traspaso_cajero',
        'transferencia_caja_agencia',
        'egreso', 'ingreso',
        'transferencia_boveda', 'transaccion_bancaria',
        'anulacion', 'facturacion'
    ) NOT NULL;

-- Recover older cash-to-vault entries identifiable by their destination-vault metadata.
UPDATE pos_operacion_log
SET tipo_operacion = 'transferencia_caja_agencia'
WHERE tipo_operacion = ''
  AND id_caja IS NOT NULL
  AND JSON_CONTAINS_PATH(metadata, 'one', '$.id_boveda_destino') = 1;
