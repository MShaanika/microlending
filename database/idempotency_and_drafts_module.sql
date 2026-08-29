-- Submission Safety, Draft Autosave, Recovery & Duplicate Prevention.
--
-- idempotency_keys: server-side duplicate-submission guard for financial
-- writes (loan disbursement, write-off posting, recovery recording, bad
-- debt provisioning, payment recording). A client-generated UUID + an
-- operation-type string together form the uniqueness key, so a resubmitted
-- request (double-click, network retry, browser-back-then-resubmit) is
-- detected and replayed rather than re-executed. See app/Core/Idempotency.php.
--
-- form_drafts / draft_documents: autosaved partial-form progress, entirely
-- separate from the existing live 'Draft' ENUM value already used on
-- loans.loan_status / accounting_journal_entries.status -- those are real,
-- reportable, already-counted records; this table is scratch state that
-- should never be confused with them. See app/Models/FormDraft.php.
--
-- system_settings rows: admin-configurable retention/TTL, not hardcoded.

CREATE TABLE idempotency_keys (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    idempotency_key VARCHAR(64) NOT NULL,
    operation_type VARCHAR(100) NOT NULL,
    user_id INT NULL,
    status ENUM('PENDING','COMPLETED','FAILED') NOT NULL DEFAULT 'PENDING',
    response_type ENUM('REDIRECT','JSON') NULL,
    response_payload TEXT NULL,
    locked_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_idem (idempotency_key, operation_type),
    INDEX idx_idem_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE form_drafts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    draft_uuid VARCHAR(36) NOT NULL,
    module VARCHAR(100) NOT NULL,
    workflow_key VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    related_entity_id INT NULL,
    form_data LONGTEXT NOT NULL,
    current_step VARCHAR(50) NULL,
    status ENUM('DRAFT','READY_FOR_SUBMISSION','SUBMITTING','SUBMITTED','PROCESSING','COMPLETED','FAILED','CANCELLED','EXPIRED') NOT NULL DEFAULT 'DRAFT',
    device_info VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_autosaved_at TIMESTAMP NULL,
    submitted_at TIMESTAMP NULL,
    expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_draft_uuid (draft_uuid),
    INDEX idx_drafts_user_module (user_id, module),
    INDEX idx_drafts_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE draft_documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    draft_uuid VARCHAR(36) NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(500) NOT NULL,
    size_bytes INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_draft_docs (draft_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO system_settings (setting_key, setting_value, module_name) VALUES
('idempotency_ttl_hours', '24', 'Submission Safety'),
('draft_retention_days', '14', 'Submission Safety'),
-- Sentinel row: BadDebtProvisionController::post() takes SELECT ... FOR
-- UPDATE on this specific row to serialize concurrent provisioning runs
-- (the batch operation has no single business row to lock the way a
-- single loan/write-off record does). The value itself is never read.
('provision_run_lock', '1', 'Submission Safety');
