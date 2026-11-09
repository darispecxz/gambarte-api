-- Tabla de notificaciones para el módulo de Gestión Operativa
-- Ejecutar en la base de datos 'cgr'

CREATE TABLE IF NOT EXISTS notificacion (
    id_notificacion     INT AUTO_INCREMENT PRIMARY KEY,
    tipo                ENUM('fondeo','operacion','riesgo','reporte','cumplimiento') NOT NULL,
    urgencia            ENUM('critica','alta','media','baja') NOT NULL DEFAULT 'media',
    codigo              VARCHAR(50) NOT NULL COMMENT 'Identificador único del tipo de notificación',
    titulo              VARCHAR(200) NOT NULL,
    mensaje             TEXT NOT NULL,
    datos               JSON DEFAULT NULL COMMENT 'Payload estructurado para la UI',
    id_agencia          INT DEFAULT NULL COMMENT 'Agencia relacionada (NULL = todas)',
    id_rol_destino      INT DEFAULT NULL COMMENT 'Rol destinatario (NULL = todos los de operaciones)',
    leida               TINYINT(1) NOT NULL DEFAULT 0,
    leida_por           VARCHAR(50) DEFAULT NULL,
    fec_lectura         DATETIME DEFAULT NULL,
    fec_creacion        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_noti_tipo (tipo),
    INDEX idx_noti_urgencia (urgencia),
    INDEX idx_noti_codigo (codigo),
    INDEX idx_noti_leida (leida),
    INDEX idx_noti_fecha (fec_creacion),
    INDEX idx_noti_agencia (id_agencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de códigos de notificación:
-- fondeo_remesas        → Fondeo inteligente por remesas
-- resumen_matutino      → Resumen matutino 09:00
-- cierre_operativo      → Cierre operativo diario
-- utilidad_diaria       → Utilidad diaria por agencia
-- cotizacion_mercado    → Cotización fuera de mercado
-- concentracion_efectivo→ Concentración de efectivo
-- fondeo_sin_atender    → Solicitudes de fondeo sin atender
-- transferencia_inusual → Transferencias de bóveda inusuales
-- cierre_tardio         → Cierre tardío de cajas
-- operacion_elevada     → Operación de monto elevado
-- consumo_efectivo      → Velocidad de consumo de efectivo
-- brecha_cotizacion     → Brecha de cotización entre agencias
