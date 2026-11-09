-- ============================================================
-- GambartePOS — Rollback: eliminar tablas del módulo de cajeros
-- Orden inverso al de creación para respetar dependencias
-- ============================================================

DROP TABLE IF EXISTS pos_metodo_pago_operacion;
DROP TABLE IF EXISTS pos_notificacion_operacion;
DROP TABLE IF EXISTS pos_recibo;
DROP TABLE IF EXISTS pos_asiento_pendiente;
DROP TABLE IF EXISTS pos_operacion_log;
DROP TABLE IF EXISTS pos_sesion_boveda;
DROP TABLE IF EXISTS pos_sesion_caja;
