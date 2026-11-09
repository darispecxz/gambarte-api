-- Conserva una versión limitada de la nota para que caja y administración
-- puedan revisar notas que no contienen un código GB válido.
ALTER TABLE pos_CriptoPayTransaccion
    ADD COLUMN nota_binance VARCHAR(128) NULL AFTER note_code;
