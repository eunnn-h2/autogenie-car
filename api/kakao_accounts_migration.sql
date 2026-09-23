-- phpMyAdmin에서 실행 가능. 첫 카카오 로그인 시에도 권한이 있으면 자동 생성됩니다.
-- 기존 member_accounts 테이블은 변경하지 않습니다.
CREATE TABLE IF NOT EXISTS member_kakao_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kakao_app_hash CHAR(64) NOT NULL,
    kakao_user_id VARCHAR(32) NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kakao_identity (kakao_app_hash, kakao_user_id),
    UNIQUE KEY uq_kakao_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
