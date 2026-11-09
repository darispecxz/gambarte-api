-- Bancos de Chile para depósito de remesas
CREATE TABLE IF NOT EXISTS pos_banco_chile (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    nombre_corto VARCHAR(30) NOT NULL,
    estado ENUM('A', 'I') NOT NULL DEFAULT 'A',
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed bancos chilenos principales
INSERT INTO pos_banco_chile (nombre, nombre_corto, orden) VALUES
('Banco de Chile', 'BCH', 1),
('Banco Estado', 'BECH', 2),
('Banco Santander Chile', 'SANT', 3),
('Banco BCI', 'BCI', 4),
('Banco Scotiabank Chile', 'SCOT', 5),
('Banco Itaú Chile', 'ITAU', 6),
('Banco BICE', 'BICE', 7),
('Banco Security', 'SECU', 8),
('Banco Falabella', 'FALA', 9),
('Banco Ripley', 'RIPL', 10),
('Banco Consorcio', 'CONS', 11),
('Banco Internacional', 'BINT', 12);

-- Forma de pago en Chile (cómo se paga al destinatario)
-- Complementa la tabla pos_metodo_pago_operacion que ya existe para cobro local
CREATE TABLE IF NOT EXISTS pos_forma_pago_chile (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(50) NOT NULL,
    requiere_cuenta TINYINT(1) NOT NULL DEFAULT 0,
    estado ENUM('A', 'I') NOT NULL DEFAULT 'A'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pos_forma_pago_chile (id, descripcion, requiere_cuenta) VALUES
(1, 'Efectivo por Ventanilla', 0),
(2, 'Depósito a Cuenta Bancaria', 1);

-- Forma de pago en remesa (solo el método, no banco/cuenta — esos van en destinatario)
ALTER TABLE remesa
  ADD COLUMN IF NOT EXISTS id_forma_pago_chile INT UNSIGNED NOT NULL DEFAULT 1 AFTER total_clp;

-- Banco de Chile en destinatario (la cuenta ya existe en destinatario.cuenta)
ALTER TABLE destinatario
  ADD COLUMN IF NOT EXISTS id_banco_chile INT UNSIGNED NULL AFTER cuenta;

-- Cotizaciones de remesas (punto 7-8)
CREATE TABLE IF NOT EXISTS pos_cotizacion_remesa (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(12) NOT NULL UNIQUE,
    cod_usuario INT NOT NULL,
    id_agencia INT NOT NULL,
    id_moneda INT NOT NULL,
    id_destino INT NOT NULL,
    monto DECIMAL(18,2) NOT NULL,
    tipo_comision INT NOT NULL DEFAULT 0,
    incluir_comision TINYINT(1) NOT NULL DEFAULT 0,
    snapshot_comision JSON NOT NULL COMMENT 'Resultado completo de calcularComision',
    estado ENUM('vigente', 'aplicada', 'expirada') NOT NULL DEFAULT 'vigente',
    aplicada_en BIGINT UNSIGNED NULL COMMENT 'id_remesa donde se aplicó',
    valido_hasta DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_codigo (codigo),
    KEY idx_estado_valido (estado, valido_hasta),
    KEY idx_usuario (cod_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ampliar pos_metodo_pago_operacion para soportar montos parciales (mixto)
ALTER TABLE pos_metodo_pago_operacion
  ADD COLUMN IF NOT EXISTS monto DECIMAL(18,2) NULL AFTER id_forma_pago,
  ADD COLUMN IF NOT EXISTS moneda VARCHAR(5) NULL AFTER monto;

-- Catálogo de formas de pago Bolivia (cobro al cliente)
CREATE TABLE IF NOT EXISTS pos_forma_pago_cobro (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(50) NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    estado ENUM('A', 'I') NOT NULL DEFAULT 'A'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO pos_forma_pago_cobro (id, descripcion, codigo) VALUES
(1, 'Efectivo', 'efectivo'),
(2, 'Transferencia Bancaria', 'transferencia'),
(3, 'QR', 'qr');
