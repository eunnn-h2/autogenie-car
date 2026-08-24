USE autogenie;

CREATE TABLE IF NOT EXISTS admin_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) DEFAULT NULL,
    role ENUM('SUPER_ADMIN','ADMIN','VIEWER') NOT NULL DEFAULT 'ADMIN',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_admins_username (username),
    INDEX idx_admins_active (is_active),
    INDEX idx_admins_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*
이미 이전 버전의 admin_accounts 테이블을 만든 경우에는 아래 ALTER만 실행하면 됩니다.
role 컬럼이 이미 존재하면 이 ALTER는 실행하지 마세요.
*/
-- ALTER TABLE admin_accounts
-- ADD COLUMN role ENUM('SUPER_ADMIN','ADMIN','VIEWER') NOT NULL DEFAULT 'ADMIN'
-- AFTER name;

-- ALTER TABLE admin_accounts ADD INDEX idx_admins_role (role);

/*
기존 최초 관리자 계정을 SUPER_ADMIN으로 변경:
아래 username 값을 실제 최초 관리자 아이디로 바꿔서 실행하세요.
*/
-- UPDATE admin_accounts
-- SET role = 'SUPER_ADMIN'
-- WHERE username = '내관리자아이디';
