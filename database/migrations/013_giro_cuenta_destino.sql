-- Preserve the exact beneficiary bank account selected for each giro.
ALTER TABLE giro
    ADD COLUMN id_cuenta_bancaria_giro INT NULL AFTER cuenta_bancaria,
    ADD INDEX idx_giro_cuenta_bancaria_giro (id_cuenta_bancaria_giro);
