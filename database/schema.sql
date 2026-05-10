CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  full_name VARCHAR(190) NOT NULL,
  dob DATE NULL,
  address VARCHAR(255) NULL,
  id_front_path VARCHAR(255) NULL,
  id_back_path VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('pending_verification','verified','disabled','waiting_updates') NOT NULL DEFAULT 'pending_verification',
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,

  consecutive_failed INT NOT NULL DEFAULT 0,
  temp_lock_until DATETIME NULL,
  abnormal_login_count INT NOT NULL DEFAULT 0,
  abnormal_login_at DATETIME NULL,
  indefinite_lock_at DATETIME NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_status_created (status, created_at),
  KEY idx_users_indef_lock (indefinite_lock_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallets (
  user_id BIGINT UNSIGNED NOT NULL,
  balance BIGINT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose VARCHAR(64) NOT NULL,
  code VARCHAR(6) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_otps_user_purpose (user_id, purpose),
  CONSTRAINT fk_otps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  type ENUM('deposit','withdraw','transfer_out','transfer_in','phone_card') NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  related_user_id BIGINT UNSIGNED NULL,
  amount BIGINT NOT NULL,
  fee BIGINT NOT NULL DEFAULT 0,
  status ENUM('success','pending_otp','pending_admin','cancelled','rejected') NOT NULL,
  note TEXT NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tx_code_user_type (code, user_id, type),
  KEY idx_tx_user_time (user_id, created_at),
  KEY idx_tx_pending (status, created_at),
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_related_user FOREIGN KEY (related_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
