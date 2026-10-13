-- 영업사원 CRUD 권한 컬럼 추가
ALTER TABLE admin_accounts
    ADD COLUMN IF NOT EXISTS can_create TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_admin_id,
    ADD COLUMN IF NOT EXISTS can_update TINYINT(1) NOT NULL DEFAULT 0 AFTER can_create,
    ADD COLUMN IF NOT EXISTS can_delete TINYINT(1) NOT NULL DEFAULT 0 AFTER can_update;

-- 기존 SUPER_ADMIN / ADMIN은 권한 전체 허용
UPDATE admin_accounts
SET can_create = 1, can_update = 1, can_delete = 1
WHERE role IN ('SUPER_ADMIN','ADMIN');

-- SALES는 관리자 화면에서 계정별 체크박스로 필요한 권한만 부여하세요.
