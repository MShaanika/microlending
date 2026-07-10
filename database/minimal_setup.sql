CREATE DATABASE IF NOT EXISTS micro_lending_system;
USE micro_lending_system;

CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_name VARCHAR(150) NOT NULL,
  branch_code VARCHAR(50) UNIQUE,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NULL,
  name VARCHAR(150) NOT NULL,
  username VARCHAR(100) UNIQUE,
  email VARCHAR(150) UNIQUE,
  password VARCHAR(255) NOT NULL,
  phone VARCHAR(50),
  user_type VARCHAR(50) DEFAULT 'Admin',
  is_active TINYINT(1) DEFAULT 1,
  last_login DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(100) NOT NULL UNIQUE,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(150) NOT NULL UNIQUE,
  permission_name VARCHAR(150) NOT NULL,
  module_name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  UNIQUE KEY unique_role_permission (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  role_id INT NOT NULL,
  UNIQUE KEY unique_user_role (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(150) NOT NULL,
  module_name VARCHAR(100),
  description TEXT,
  ip_address VARCHAR(50),
  user_agent TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_user (user_id),
  INDEX idx_audit_created (created_at)
);

INSERT IGNORE INTO branches (id, branch_name, branch_code, is_active) VALUES (1, 'Head Office', 'HO', 1);
INSERT IGNORE INTO roles (id, role_name, description) VALUES (1, 'Super Admin', 'Full system access');

INSERT IGNORE INTO permissions (permission_key, permission_name, module_name) VALUES
('dashboard.view','View Dashboard','Dashboard'),
('borrowers.view','View Borrowers','Borrowers'),
('loans.view','View Loans','Loans'),
('collections.view','View Collections','Collections'),
('accounting.view','View Accounting','Accounting'),
('reports.operational','View Operational Reports','Reports'),
('documents.view','View Documents','Documents'),
('notifications.view','View Notifications','Notifications'),
('compliance.view','View Compliance','Compliance'),
('admin.system_settings','Manage System Settings','Admin');

INSERT IGNORE INTO users (id, branch_id, name, username, email, password, user_type, is_active)
VALUES (1, 1, 'System Administrator', 'admin', 'admin@example.com', '$2y$12$9Qmtx1ihAeArhnlFAzu5b.cU0yDERmWxacNZ96nd0fDaHkOJMq2HC', 'Super Admin', 1);

INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (1, 1);
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;
