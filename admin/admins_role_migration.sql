USE autogenie;

ALTER TABLE admins
ADD COLUMN role ENUM('SUPER_ADMIN','ADMIN','VIEWER') NOT NULL DEFAULT 'ADMIN'
AFTER name;

ALTER TABLE admins
ADD INDEX idx_admins_role (role);

/* 최초 관리자 계정을 SUPER_ADMIN으로 바꿉니다.
   아래 admin_username_here를 실제 현재 관리자 아이디로 교체하세요. */
UPDATE admins
SET role = 'SUPER_ADMIN'
WHERE username = 'admin_username_here';
