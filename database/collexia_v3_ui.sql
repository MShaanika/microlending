-- Collexia EnDO V3 REST API: credentials settings + per-mandate API state.
-- Additive only -- does not touch collexia_status/merchant_system_contract_no,
-- which remain the older "EnDo Batch v1.0" Excel flow's own fields.

CREATE TABLE collexia_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE debit_orders
    ADD COLUMN collexia_api_contract_reference VARCHAR(14) NULL UNIQUE AFTER merchant_system_contract_no,
    ADD COLUMN collexia_api_status ENUM('Not Placed','Load Pending','Registered','Load Failed','Cancelled') NOT NULL DEFAULT 'Not Placed' AFTER collexia_api_contract_reference,
    ADD COLUMN collexia_api_last_response TEXT NULL AFTER collexia_api_status,
    ADD COLUMN collexia_api_synced_at DATETIME NULL AFTER collexia_api_last_response;
