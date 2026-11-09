-- =============================================================================
-- Migration 001: cgr_discrepancias
-- Compatible: MySQL 5.5+
-- =============================================================================

CREATE TABLE IF NOT EXISTS cgr_discrepancias (
    id                       INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    code                     VARCHAR(25)     NOT NULL,
    type                     VARCHAR(60)     NOT NULL,

    severity                 VARCHAR(10)     NOT NULL,
    status                   VARCHAR(15)     NOT NULL DEFAULT 'OPEN',

    operation_id             INT UNSIGNED    NULL,
    operation_type           VARCHAR(50)     NULL,
    external_reference       VARCHAR(100)    NULL,

    compared_field           VARCHAR(100)    NOT NULL,
    value_cgr_bo             DECIMAL(18,6)   NULL,
    value_cgr_cl             DECIMAL(18,6)   NULL,
    value_conta              DECIMAL(18,6)   NULL,
    unit                     VARCHAR(20)     NULL,

    description              VARCHAR(500)    NOT NULL,
    detail_technical         LONGTEXT        NULL,
    auto_suggestion          VARCHAR(500)    NULL,

    absolute_difference      DECIMAL(18,6)   NULL,
    percentage_difference    DECIMAL(8,4)    NULL,
    tolerance_threshold      DECIMAL(18,6)   NULL,

    is_auto_detected         TINYINT(1)      NOT NULL DEFAULT 0,
    requires_audit           TINYINT(1)      NOT NULL DEFAULT 0,
    is_recurring             TINYINT(1)      NOT NULL DEFAULT 0,
    parent_discrepancy_id    INT UNSIGNED    NULL,

    resolution_type          VARCHAR(60)     NULL,
    resolution_notes         TEXT            NULL,
    resolved_by              INT UNSIGNED    NULL,
    resolved_at              DATETIME        NULL,
    autoresolve_log          LONGTEXT        NULL,

    detected_by              VARCHAR(100)    NOT NULL,

    created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_code         (code),
    INDEX idx_type_status      (type, status),
    INDEX idx_operation        (operation_id, operation_type),
    INDEX idx_severity_status  (severity, status, created_at),
    INDEX idx_parent           (parent_discrepancy_id),
    FOREIGN KEY fk_parent (parent_discrepancy_id)
        REFERENCES cgr_discrepancias(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- =============================================================================
-- Migration 002: cgr_discrepancia_resoluciones
-- =============================================================================

CREATE TABLE IF NOT EXISTS cgr_discrepancia_resoluciones (
    id                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    discrepancy_id       INT UNSIGNED    NOT NULL,

    resolution_type      VARCHAR(60)     NOT NULL,
    action_description   TEXT            NOT NULL,
    queries_executed     LONGTEXT        NULL,
    result_values        LONGTEXT        NULL,

    user_id              INT UNSIGNED    NOT NULL,
    is_automatic         TINYINT(1)      NOT NULL DEFAULT 0,

    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_discrepancy (discrepancy_id),
    FOREIGN KEY fk_discrepancy_res (discrepancy_id)
        REFERENCES cgr_discrepancias(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- =============================================================================
-- Migration 003: cgr_discrepancia_logs
-- =============================================================================

CREATE TABLE IF NOT EXISTS cgr_discrepancia_logs (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    discrepancy_id   INT UNSIGNED    NOT NULL,

    event            VARCHAR(100)    NOT NULL,
    message          VARCHAR(500)    NOT NULL,
    user_id          INT UNSIGNED    NULL,
    is_system        TINYINT(1)      NOT NULL DEFAULT 0,

    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_discrepancy (discrepancy_id, created_at),
    FOREIGN KEY fk_discrepancy_log (discrepancy_id)
        REFERENCES cgr_discrepancias(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
