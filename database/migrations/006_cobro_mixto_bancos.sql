-- ============================================================
-- GambartePOS — Cobros mixtos: vincular métodos de pago con
-- cuentas bancarias para routing correcto de efectivo vs banco
-- ============================================================

-- Ampliar pos_metodo_pago_operacion con referencia a cuenta bancaria
ALTER TABLE pos_metodo_pago_operacion
  ADD COLUMN IF NOT EXISTS num_cuenta_bancaria VARCHAR(30) NULL AFTER referencia_bancaria,
  ADD COLUMN IF NOT EXISTS id_entidad_financiera INT NULL AFTER num_cuenta_bancaria,
  ADD COLUMN IF NOT EXISTS id_agencia INT NULL AFTER id_entidad_financiera,
  ADD COLUMN IF NOT EXISTS id_moneda_cuenta INT NULL AFTER id_agencia;
