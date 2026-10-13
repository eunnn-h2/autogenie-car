-- 오토지니 관리자 확장용 보조 컬럼/테이블
ALTER TABLE car_vehicles ADD COLUMN IF NOT EXISTS is_recommended TINYINT(1) NOT NULL DEFAULT 0 AFTER is_best;

CREATE TABLE IF NOT EXISTS customer_admin_memos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_phone VARCHAR(30) NOT NULL,
  memo TEXT NOT NULL,
  admin_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_customer_phone(customer_phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estimate_admin_memos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  estimate_type VARCHAR(10) NOT NULL,
  estimate_id BIGINT UNSIGNED NOT NULL,
  memo TEXT NOT NULL,
  admin_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_estimate(estimate_type, estimate_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
