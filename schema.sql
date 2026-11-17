CREATE TABLE IF NOT EXISTS teams (
  id VARCHAR(50) PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'USD',
  target DECIMAL(12,2) NOT NULL DEFAULT 0,
  metric_type VARCHAR(20) NOT NULL DEFAULT 'revenue',
  currency VARCHAR(3) NULL DEFAULT 'USD',
  progress_mode VARCHAR(20) NOT NULL DEFAULT 'monthly',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  position INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS daily_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  team_id VARCHAR(50) NOT NULL,
  entry_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY team_date (team_id, entry_date)
);

CREATE TABLE IF NOT EXISTS team_user_assignments (
  user_id INT NOT NULL,
  team_id VARCHAR(50) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, team_id),
  INDEX team_lookup (team_id)
);

CREATE TABLE IF NOT EXISTS entry_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  team_id VARCHAR(50) NOT NULL,
  entry_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  action ENUM('add','revoke') NOT NULL,
  metric_type VARCHAR(20) NOT NULL DEFAULT 'revenue',
  currency VARCHAR(3) NULL DEFAULT 'USD',
  note VARCHAR(255) NULL,
  user_id INT NULL,
  created_by INT NULL,
  reverses_adjustment_id INT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX entry_lookup (entry_date, team_id),
  INDEX user_lookup (user_id)
);

CREATE TABLE IF NOT EXISTS user_daily_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_user_id INT NOT NULL,
  entry_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY user_date (entry_user_id, entry_date)
);

CREATE TABLE IF NOT EXISTS user_entry_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_user_id INT NOT NULL,
  entry_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  action ENUM('add','revoke') NOT NULL,
  metric_type VARCHAR(20) NOT NULL DEFAULT 'leads',
  currency VARCHAR(3) NULL,
  note VARCHAR(255) NULL,
  actor_user_id INT NULL,
  created_by INT NULL,
  reverses_adjustment_id INT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX entry_lookup (entry_date, entry_user_id),
  INDEX actor_lookup (actor_user_id)
);

CREATE TABLE IF NOT EXISTS leave_days (
  entry_date DATE PRIMARY KEY,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  role ENUM('owner','manager','employee') NOT NULL DEFAULT 'employee',
  team_id VARCHAR(50) NULL,
  entry_unit VARCHAR(20) NOT NULL DEFAULT 'leads',
  entry_target DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency VARCHAR(3) NOT NULL DEFAULT 'USD',
  sales_type VARCHAR(20) NOT NULL DEFAULT 'leads',
  show_dashboard_card TINYINT(1) NOT NULL DEFAULT 0,
  show_entry_list TINYINT(1) NOT NULL DEFAULT 1,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY username_unique (username)
);

CREATE TABLE IF NOT EXISTS exchange_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  base_currency VARCHAR(3) NOT NULL,
  target_currency VARCHAR(3) NOT NULL,
  rate DECIMAL(18,8) NOT NULL,
  source VARCHAR(80) NULL,
  rate_date DATE NOT NULL,
  fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX rate_lookup (base_currency, target_currency, rate_date)
);
