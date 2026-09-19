-- Employer pay-cycle-aware debit order collection dates (Part 1 -- scaffold
-- only). Deliberately does NOT touch loan_schedules.due_date anywhere --
-- the contractual due date, interest/levy/duty-stamp accounting, and
-- penalty grace-period logic (PenaltyAccrualService) are entirely
-- untouched by this module. This only adjusts WHEN a collection is
-- attempted, never WHAT is legally owed or WHEN it is legally due.
--
-- applyApproved() (see App\Services\CollectionDateAdjustmentService) is
-- built but hard-disabled -- it never calls Collexia in this phase. See
-- pay_cycle_settings.automatic_apply_enabled below.

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Public holidays -- deliberately NOT hrm_holidays (HR's table is
-- date-range based with leave/is_paid semantics this module has no
-- business knowing about). Single calendar dates only. Starts EMPTY --
-- an administrator must enter the real gazetted Namibian public holiday
-- list; nothing here invents or algorithmically derives a date (same
-- "do not invent" precedent as retention_policies).
-- ---------------------------------------------------------------------
CREATE TABLE public_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(150) NOT NULL,
    year INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    source ENUM('Manual','Imported','CopiedDraft') NOT NULL DEFAULT 'Manual',
    copied_from_year INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_holiday_date (holiday_date),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. Pay-cycle policies -- the reusable employer-level template
-- ("Government Payroll": pay day 20, roll to previous business day).
-- ---------------------------------------------------------------------
CREATE TABLE pay_cycle_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    policy_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    normal_pay_day TINYINT UNSIGNED NOT NULL,
    non_business_day_rule ENUM('PREVIOUS_BUSINESS_DAY','NEXT_BUSINESS_DAY','NONE') NOT NULL DEFAULT 'NONE',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. Employer -> policy mapping. There is no normalized employers/
-- companies table in this schema (confirmed: employer_name is free text
-- on borrower_employment and loan_applications) -- rather than invent a
-- new parallel Employer entity, this is a thin, explicitly admin-curated
-- lookup keyed on that same employer_name string. Never inferred from
-- the name (no "contains Ministry" pattern matching) -- an admin maps
-- an exact employer_name to a policy once; every borrower captured
-- under that same employer_name inherits it automatically.
-- ---------------------------------------------------------------------
CREATE TABLE employer_pay_cycle_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employer_name VARCHAR(150) NOT NULL,
    pay_cycle_policy_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employer_name (employer_name),
    FOREIGN KEY (pay_cycle_policy_id) REFERENCES pay_cycle_policies(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. Borrower-level assignment (fallback for an employer not yet
-- mapped) + override (per-borrower exception, e.g. a bespoke
-- arrangement). Resolution order lives in PayCycleResolverService:
-- borrower override fields > employer_pay_cycle_policies match on the
-- borrower's current employer_name > borrowers.pay_cycle_policy_id >
-- no adjustment (unchanged default behaviour).
-- ---------------------------------------------------------------------
ALTER TABLE borrowers
    ADD COLUMN pay_cycle_policy_id INT NULL AFTER status,
    ADD COLUMN pay_day_override TINYINT UNSIGNED NULL AFTER pay_cycle_policy_id,
    ADD COLUMN non_business_day_rule_override ENUM('PREVIOUS_BUSINESS_DAY','NEXT_BUSINESS_DAY','NONE') NULL AFTER pay_day_override,
    ADD CONSTRAINT fk_borrowers_pay_cycle_policy FOREIGN KEY (pay_cycle_policy_id) REFERENCES pay_cycle_policies(id);

-- ---------------------------------------------------------------------
-- 5. Settings. automatic_apply_enabled is stored but ALSO hardcoded to
-- false in CollectionDateAdjustmentService::applyApproved() regardless
-- of this value -- this row exists so the eventual real enablement is a
-- settings change with a visible "unconfirmed" state in the UI, not a
-- silent code toggle. collection_adjustment_lead_days stays NULL
-- (unconfirmed) until Collexia's own rescheduling cutoff is confirmed.
-- ---------------------------------------------------------------------
CREATE TABLE pay_cycle_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO pay_cycle_settings (setting_key, setting_value) VALUES
('collection_adjustment_lead_days', NULL),
('automatic_apply_enabled', '0');

-- ---------------------------------------------------------------------
-- 6. The review queue AND the permanent audit trail -- rows are never
-- deleted, only superseded. A batch groups every adjustment produced by
-- one generation run that share the same maker-checker approval (in
-- particular, every split leg of one debit order's cycle), so approving
-- is one action, not N.
-- ---------------------------------------------------------------------
CREATE TABLE collection_date_adjustment_batches (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    generated_by INT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approval_request_id BIGINT NULL,
    approval_status ENUM('PENDING_REVIEW','SUBMITTED','APPROVED','REJECTED','RETURNED') NOT NULL DEFAULT 'PENDING_REVIEW',
    submitted_by INT NULL,
    submitted_at DATETIME NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_comments TEXT NULL,
    FOREIGN KEY (generated_by) REFERENCES users(id),
    FOREIGN KEY (approval_request_id) REFERENCES approval_requests(id),
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Five distinct dates, per explicit instruction -- never collapse these
-- into one "collection date" column:
--   contractual_due_date    loan_schedules.due_date, snapshot, NEVER changes
--   normal_pay_date         the policy's plain calendar date this cycle,
--                           before any business-day adjustment
--   adjusted_pay_date       normal_pay_date after the policy's
--                           non_business_day_rule is applied
--   proposed_collection_date what would be submitted to Collexia (equals
--                           adjusted_pay_date today; kept separate so a
--                           future lead/lag-day rule doesn't need a
--                           schema change)
--   actual_collection_date  NULL until a real payment is reconciled
--                           against this cycle -- population is a
--                           follow-up phase, not built here (see report)
CREATE TABLE debit_order_collection_adjustments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT NOT NULL,
    debit_order_id BIGINT NOT NULL,
    debit_order_split_leg_id BIGINT NULL,
    loan_schedule_id BIGINT NOT NULL,
    borrower_id BIGINT NOT NULL,
    employer_name VARCHAR(150) NULL,
    pay_cycle_policy_id INT NULL,
    rule_applied ENUM('PREVIOUS_BUSINESS_DAY','NEXT_BUSINESS_DAY','NONE') NOT NULL,

    contractual_due_date DATE NOT NULL,
    normal_pay_date DATE NOT NULL,
    adjusted_pay_date DATE NOT NULL,
    proposed_collection_date DATE NOT NULL,
    actual_collection_date DATE NULL,

    adjustment_reason VARCHAR(255) NOT NULL,
    crosses_period_boundary TINYINT(1) NOT NULL DEFAULT 0,
    requires_manual_flag ENUM('NONE','CROSS_PERIOD_ADJUSTMENT') NOT NULL DEFAULT 'NONE',

    collexia_status ENUM('NOT_APPLIED','READY','APPLIED','FAILED','SUPERSEDED') NOT NULL DEFAULT 'NOT_APPLIED',
    collexia_response TEXT NULL,
    applied_at DATETIME NULL,

    -- Row lifecycle status, distinct from the batch's approval_status
    -- and from collexia_status -- this is "is this row still the live
    -- answer for this debit order + leg + installment", flipped to
    -- SUPERSEDED by a later regeneration run rather than deleting.
    status ENUM('ACTIVE','SUPERSEDED') NOT NULL DEFAULT 'ACTIVE',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    -- NOTE: MySQL allows multiple NULLs through a unique index, so this
    -- does NOT by itself prevent duplicate rows for non-split debit
    -- orders (debit_order_split_leg_id NULL). Idempotency for that case
    -- is enforced in CollectionDateAdjustmentService::generateRollingPreview()
    -- (an explicit "does an ACTIVE row already exist" check before
    -- insert), not by this constraint alone. Kept for split-leg
    -- duplicate protection and query performance.
    UNIQUE KEY unique_active_adjustment (debit_order_id, debit_order_split_leg_id, loan_schedule_id, status),
    FOREIGN KEY (batch_id) REFERENCES collection_date_adjustment_batches(id) ON DELETE CASCADE,
    FOREIGN KEY (debit_order_id) REFERENCES debit_orders(id),
    FOREIGN KEY (debit_order_split_leg_id) REFERENCES debit_order_split_legs(id),
    FOREIGN KEY (loan_schedule_id) REFERENCES loan_schedules(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (pay_cycle_policy_id) REFERENCES pay_cycle_policies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Permissions -- reusing the write-off precedent: the approver uses the
-- SAME manage permission rather than a third, approve-only permission
-- ("what's new is the maker != checker enforcement, not a new
-- permission surface").
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permissions (permission_key, permission_name, module_name) VALUES
('pay_cycle_policies.manage', 'Manage Pay-Cycle Policies & Employer Mapping', 'Collections'),
('public_holidays.manage', 'Manage Public Holidays Calendar', 'Collections'),
('collection_date_adjustments.view', 'View Collection Date Adjustments', 'Collections'),
('collection_date_adjustments.manage', 'Generate & Submit Collection Date Adjustments', 'Collections');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.role_name IN ('Super Admin', 'Admin')
  AND p.permission_key IN ('pay_cycle_policies.manage', 'public_holidays.manage', 'collection_date_adjustments.view', 'collection_date_adjustments.manage');

INSERT INTO approval_policies (policy_key, policy_name, description, module, resource_type, action_type, approver_permission, required_steps, is_active) VALUES
('collection_date_adjustment_approval', 'Collection Date Adjustment Approval', 'A batch of proposed debit-order collection date shifts (aligning collection with an employer pay-cycle policy) must be approved by someone other than the person who generated it, before any Collexia update is ever attempted.', 'Collections', 'collection_date_adjustment_batch', 'approve', 'collection_date_adjustments.manage', 1, 1);

SET FOREIGN_KEY_CHECKS = 1;
