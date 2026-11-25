CREATE TABLE IF NOT EXISTS pos_whatsapp_dispatch (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dedup_key CHAR(64) NOT NULL UNIQUE,
    document_type VARCHAR(40) NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    agency_id INT NOT NULL,
    user_id INT NOT NULL,
    phone_last4 CHAR(4) NOT NULL,
    status VARCHAR(20) NOT NULL,
    provider_message_id VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_whatsapp_operation (agency_id, document_type, operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
