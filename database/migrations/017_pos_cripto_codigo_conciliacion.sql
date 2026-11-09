-- Referencia corta para identificar pagos Binance Pay y método de asociación.
-- Las notas libres de Binance no se guardan; solo se conserva un código GB válido.
ALTER TABLE pos_CriptoOperacion
    ADD COLUMN codigo_referencia CHAR(9) NULL AFTER concepto,
    ADD UNIQUE KEY uk_pos_cripto_operacion_codigo_referencia (codigo_referencia);

ALTER TABLE pos_CriptoPayTransaccion
    ADD COLUMN note_code CHAR(9) NULL AFTER receptor_verificado,
    ADD COLUMN conciliacion_metodo ENUM('AUTOMATICA','MANUAL') NULL AFTER id_operacion,
    ADD KEY idx_pos_cripto_pay_note_code (note_code);
