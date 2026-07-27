-- =========================================================
-- SOCIAL & WEB ANALYTICS MODULE: manual-entry tracking for
-- Google Analytics, Facebook, Instagram and LinkedIn, charted
-- on the staff dashboard.
-- Run AFTER database/schema.sql (or minimal_setup.sql) has been imported.
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS social_analytics_metrics;
DROP TABLE IF EXISTS social_analytics_settings;

-- ---------------------------------------------------------
-- One row per platform: enabled flag, handle/property being
-- tracked, and labels for the 3 metrics staff log for it (each
-- platform's natural metrics differ, so labels are configurable
-- rather than fixed column names).
-- ---------------------------------------------------------
CREATE TABLE social_analytics_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform ENUM('google_analytics','facebook','instagram','linkedin') NOT NULL UNIQUE,
    display_name VARCHAR(60) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    handle_or_property VARCHAR(150) NULL,
    metric_1_label VARCHAR(50) NOT NULL,
    metric_2_label VARCHAR(50) NOT NULL,
    metric_3_label VARCHAR(50) NOT NULL,
    notes VARCHAR(255) NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Periodic manual entries per platform (one per date), the
-- data source for the dashboard trend charts.
-- ---------------------------------------------------------
CREATE TABLE social_analytics_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    setting_id INT NOT NULL,
    entry_date DATE NOT NULL,
    metric_1 DECIMAL(18,2) NOT NULL DEFAULT 0,
    metric_2 DECIMAL(18,2) NOT NULL DEFAULT 0,
    metric_3 DECIMAL(18,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_setting_date (setting_id, entry_date),
    FOREIGN KEY (setting_id) REFERENCES social_analytics_settings(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Seed the 4 platforms with sensible default metric labels.
-- Disabled by default -- staff switches one on once they
-- start logging numbers for it.
-- ---------------------------------------------------------
INSERT IGNORE INTO social_analytics_settings (platform, display_name, is_enabled, metric_1_label, metric_2_label, metric_3_label) VALUES
('google_analytics', 'Google Analytics', 0, 'Sessions', 'Users', 'Pageviews'),
('facebook', 'Facebook', 0, 'Page Likes', 'Reach', 'Engagement'),
('instagram', 'Instagram', 0, 'Followers', 'Reach', 'Engagement'),
('linkedin', 'LinkedIn', 0, 'Followers', 'Impressions', 'Clicks');

-- ---------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------
INSERT IGNORE INTO permissions (permission_key, permission_name, module_name) VALUES
('social_analytics.view', 'View Social & Web Analytics', 'Social Analytics'),
('social_analytics.manage', 'Manage Social & Web Analytics Settings/Entries', 'Social Analytics');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_name = 'Super Admin' AND p.module_name = 'Social Analytics';

-- ---------------------------------------------------------
-- Dashboard widgets
-- ---------------------------------------------------------
INSERT IGNORE INTO dashboard_widgets (widget_code, widget_name, widget_category, display_order, is_active) VALUES
('SOCIAL_ANALYTICS', 'Social & Web Analytics Trends', 'Marketing', 18, 1);

SET FOREIGN_KEY_CHECKS = 1;
