-- =========================================================
-- FIXED ASSETS MODULE: DEPRECIATION (tangible) & AMORTIZATION (intangible)
-- Run AFTER database/schema.sql (or minimal_setup.sql) has been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS asset_disposals;
DROP TABLE IF EXISTS asset_depreciation_schedules;
DROP TABLE IF EXISTS fixed_assets;
DROP TABLE IF EXISTS asset_categories;

-- ---------------------------------------------------------
-- Chart of accounts additions used by this module
-- ---------------------------------------------------------
INSERT IGNORE INTO accounting_accounts (account_code, account_name, account_type, normal_balance, is_control_account, is_cash_bank_account, is_active) VALUES
('1060', 'Property, Plant & Equipment', 'Asset', 'Debit', 1, 0, 1),
('1065', 'Accumulated Depreciation', 'Contra Asset', 'Credit', 1, 0, 1),
('1070', 'Intangible Assets', 'Asset', 'Debit', 1, 0, 1),
('1075', 'Accumulated Amortization', 'Contra Asset', 'Credit', 1, 0, 1),
('4050', 'Gain on Disposal of Assets', 'Income', 'Credit', 0, 0, 1),
('5050', 'Depreciation Expense', 'Expense', 'Debit', 0, 0, 1),
('5060', 'Amortization Expense', 'Expense', 'Debit', 0, 0, 1),
('5070', 'Loss on Disposal of Assets', 'Expense', 'Debit', 0, 0, 1);

-- ---------------------------------------------------------
-- Asset categories: defaults for useful life, method and GL accounts
-- ---------------------------------------------------------
CREATE TABLE asset_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(150) NOT NULL UNIQUE,
    asset_nature ENUM('Tangible','Intangible') NOT NULL DEFAULT 'Tangible',
    depreciation_method ENUM('Straight Line','Reducing Balance','No Depreciation') NOT NULL DEFAULT 'Straight Line',
    default_useful_life_months INT NOT NULL DEFAULT 60,
    default_residual_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    default_reducing_balance_rate DECIMAL(6,3) NULL,
    asset_account_id BIGINT NULL,
    depreciation_expense_account_id BIGINT NULL,
    accumulated_depreciation_account_id BIGINT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_account_id) REFERENCES accounting_accounts(id),
    FOREIGN KEY (depreciation_expense_account_id) REFERENCES accounting_accounts(id),
    FOREIGN KEY (accumulated_depreciation_account_id) REFERENCES accounting_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Fixed / intangible asset register
-- ---------------------------------------------------------
CREATE TABLE fixed_assets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    category_id INT NOT NULL,
    asset_no VARCHAR(50) NOT NULL UNIQUE,
    asset_name VARCHAR(150) NOT NULL,
    asset_nature ENUM('Tangible','Intangible') NOT NULL DEFAULT 'Tangible',
    description TEXT,
    serial_no VARCHAR(100),
    location VARCHAR(150),
    supplier_name VARCHAR(150),
    purchase_date DATE NOT NULL,
    purchase_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    additional_costs DECIMAL(18,2) NOT NULL DEFAULT 0,
    capitalized_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    residual_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    useful_life_months INT NOT NULL DEFAULT 60,
    depreciation_method ENUM('Straight Line','Reducing Balance','No Depreciation') NOT NULL DEFAULT 'Straight Line',
    reducing_balance_rate DECIMAL(6,3) NULL,
    depreciation_start_date DATE NOT NULL,
    accumulated_depreciation DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_book_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    status ENUM('Active','Fully Depreciated','Disposed','Written Off') NOT NULL DEFAULT 'Active',
    asset_account_id BIGINT NULL,
    depreciation_expense_account_id BIGINT NULL,
    accumulated_depreciation_account_id BIGINT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (category_id) REFERENCES asset_categories(id),
    FOREIGN KEY (asset_account_id) REFERENCES accounting_accounts(id),
    FOREIGN KEY (depreciation_expense_account_id) REFERENCES accounting_accounts(id),
    FOREIGN KEY (accumulated_depreciation_account_id) REFERENCES accounting_accounts(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Period-by-period depreciation (tangible) / amortization (intangible) schedule
-- ---------------------------------------------------------
CREATE TABLE asset_depreciation_schedules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_id BIGINT NOT NULL,
    period_no INT NOT NULL,
    period_date DATE NOT NULL,
    opening_book_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    depreciation_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    closing_book_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    status ENUM('Pending','Posted','Reversed') NOT NULL DEFAULT 'Pending',
    journal_id BIGINT NULL,
    posted_by INT NULL,
    posted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_asset_period (asset_id, period_no),
    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE,
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id),
    FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Asset disposals (sale/scrap/write-off) with gain/loss calc
-- ---------------------------------------------------------
CREATE TABLE asset_disposals (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_id BIGINT NOT NULL,
    disposal_date DATE NOT NULL,
    disposal_method ENUM('Sold','Scrapped','Written Off','Donated','Other') NOT NULL DEFAULT 'Sold',
    disposal_proceeds DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_book_value_at_disposal DECIMAL(18,2) NOT NULL DEFAULT 0,
    gain_loss_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    notes TEXT,
    journal_id BIGINT NULL,
    disposed_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES fixed_assets(id) ON DELETE CASCADE,
    FOREIGN KEY (journal_id) REFERENCES accounting_journal_entries(id),
    FOREIGN KEY (disposed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Seed default asset categories
-- ---------------------------------------------------------
INSERT INTO asset_categories (category_name, asset_nature, depreciation_method, default_useful_life_months, default_residual_rate, default_reducing_balance_rate, asset_account_id, depreciation_expense_account_id, accumulated_depreciation_account_id) VALUES
('Office Equipment', 'Tangible', 'Straight Line', 60, 0, NULL,
    (SELECT id FROM accounting_accounts WHERE account_code='1060'),
    (SELECT id FROM accounting_accounts WHERE account_code='5050'),
    (SELECT id FROM accounting_accounts WHERE account_code='1065')),
('Motor Vehicles', 'Tangible', 'Reducing Balance', 96, 10, 20.000,
    (SELECT id FROM accounting_accounts WHERE account_code='1060'),
    (SELECT id FROM accounting_accounts WHERE account_code='5050'),
    (SELECT id FROM accounting_accounts WHERE account_code='1065')),
('Furniture & Fittings', 'Tangible', 'Straight Line', 120, 0, NULL,
    (SELECT id FROM accounting_accounts WHERE account_code='1060'),
    (SELECT id FROM accounting_accounts WHERE account_code='5050'),
    (SELECT id FROM accounting_accounts WHERE account_code='1065')),
('Computer Equipment', 'Tangible', 'Straight Line', 36, 0, NULL,
    (SELECT id FROM accounting_accounts WHERE account_code='1060'),
    (SELECT id FROM accounting_accounts WHERE account_code='5050'),
    (SELECT id FROM accounting_accounts WHERE account_code='1065')),
('Software & Licenses (Intangible)', 'Intangible', 'Straight Line', 36, 0, NULL,
    (SELECT id FROM accounting_accounts WHERE account_code='1070'),
    (SELECT id FROM accounting_accounts WHERE account_code='5060'),
    (SELECT id FROM accounting_accounts WHERE account_code='1075'));

-- ---------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------
INSERT IGNORE INTO permissions (permission_key, permission_name, module_name) VALUES
('assets.view', 'View Fixed Assets', 'Assets'),
('assets.create', 'Create Fixed Assets', 'Assets'),
('assets.edit', 'Edit Fixed Assets', 'Assets'),
('assets.depreciate', 'Run Depreciation / Amortization', 'Assets'),
('assets.dispose', 'Dispose Fixed Assets', 'Assets');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_name = 'Super Admin' AND p.module_name = 'Assets';

-- ---------------------------------------------------------
-- Dashboard widgets
-- ---------------------------------------------------------
INSERT IGNORE INTO dashboard_widgets (widget_code, widget_name, widget_category, display_order, is_active) VALUES
('TOTAL_FIXED_ASSETS', 'Total Fixed Assets (Net Book Value)', 'Assets', 16, 1),
('MONTHLY_DEPRECIATION', 'Monthly Depreciation / Amortization', 'Assets', 17, 1);

SET FOREIGN_KEY_CHECKS = 1;
