USE autogenie;

/* 영업사원 부계정(SALES) 역할 추가 */
ALTER TABLE admin_accounts
MODIFY COLUMN role ENUM('SUPER_ADMIN','ADMIN','VIEWER','SALES') NOT NULL DEFAULT 'ADMIN';

/* 어떤 본 관리자 계정에서 만든 부계정인지 저장 */
ALTER TABLE admin_accounts
ADD COLUMN parent_admin_id INT UNSIGNED NULL AFTER role;

ALTER TABLE admin_accounts
ADD INDEX idx_admins_parent (parent_admin_id);
