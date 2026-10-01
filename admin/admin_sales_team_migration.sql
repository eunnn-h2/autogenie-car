-- 영업팀 선택형 관리 기능
-- 1) 영업사원 계정에 팀명이 없는 기존 DB용 컬럼
ALTER TABLE admin_accounts
    ADD COLUMN team_name VARCHAR(50) NULL AFTER parent_admin_id;

CREATE INDEX idx_admin_accounts_team_name
    ON admin_accounts (team_name);

-- 2) 메인관리자가 관리하는 영업팀 목록
CREATE TABLE IF NOT EXISTS sales_teams (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_name VARCHAR(50) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_teams_name (team_name),
    KEY idx_sales_teams_active_sort (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) 기존 계정에 입력되어 있던 팀명은 팀 목록으로 이관
INSERT IGNORE INTO sales_teams (team_name, is_active, sort_order)
SELECT DISTINCT TRIM(team_name), 1, 0
FROM admin_accounts
WHERE role = 'SALES'
  AND team_name IS NOT NULL
  AND TRIM(team_name) <> '';
