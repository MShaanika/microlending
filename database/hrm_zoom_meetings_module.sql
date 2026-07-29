-- =========================================================
-- HR ZOOM MEETINGS
-- Schedule and manage Zoom video meetings for HR (staff
-- meetings, interviews, 1:1s) from within the app, backed by
-- the real Zoom Server-to-Server OAuth API. Ported from the
-- reference workdo/ZoomMeeting package.
--
-- Nested under the existing Human Resources module (not a new
-- standalone top-level module like Training/Recruitment) -- the
-- request was explicitly "for HR meetings", and it reuses the
-- existing hrm.view/hrm.manage permission pair rather than
-- introducing new permission keys.
--
-- Deviation from the reference: participants are hrm_employees
-- (this app's real employee directory) rather than the
-- reference's generic `users` table, since most meeting
-- attendees will not have system logins -- host_id stays on
-- `users` since hosting a Zoom meeting requires the host's own
-- Zoom-linked login, matching the same reviewer/host convention
-- established in the Performance and Training modules.
--
-- Zoom API credentials (Server-to-Server OAuth app: account ID,
-- client ID, client secret) are stored in hrm_zoom_settings, a
-- simple key/value table matching the existing
-- notification_settings pattern -- entered via a settings page,
-- the feature no-ops with a clear error until configured.
--
-- Run AFTER database/hrm_module.sql has been imported (for the
-- hrm_employees FK).
-- Target DB: MySQL 8+ / Engine: InnoDB / Charset: utf8mb4
-- =========================================================

USE micro_lending_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS hrm_zoom_meetings;
DROP TABLE IF EXISTS hrm_zoom_settings;

CREATE TABLE hrm_zoom_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE hrm_zoom_meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    meeting_id VARCHAR(50) NULL,
    meeting_password VARCHAR(50) NULL,
    start_url TEXT NULL,
    join_url TEXT NULL,
    start_time DATETIME NOT NULL,
    duration INT NOT NULL DEFAULT 30,
    host_video TINYINT(1) NOT NULL DEFAULT 0,
    participant_video TINYINT(1) NOT NULL DEFAULT 0,
    waiting_room TINYINT(1) NOT NULL DEFAULT 0,
    recording TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Scheduled','Started','Ended','Cancelled') NOT NULL DEFAULT 'Scheduled',
    participants TEXT NULL,
    host_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (host_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
