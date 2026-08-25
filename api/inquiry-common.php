<?php

declare(strict_types=1);

require_once __DIR__ . '/member-auth-common.php';

function ensure_inquiries_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_inquiries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        inquiry_no VARCHAR(32) NOT NULL,
        legacy_id VARCHAR(80) NULL,
        member_id BIGINT UNSIGNED NULL,
        guest_key VARCHAR(64) NULL,
        member_name VARCHAR(100) NULL,
        member_email VARCHAR(190) NULL,
        member_phone VARCHAR(30) NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'NEW',
        answer TEXT NULL,
        answered_by BIGINT UNSIGNED NULL,
        answered_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_customer_inquiries_no (inquiry_no),
        UNIQUE KEY uq_customer_inquiries_legacy (legacy_id),
        KEY idx_customer_inquiries_member (member_id, created_at),
        KEY idx_customer_inquiries_guest (guest_key, created_at),
        KEY idx_customer_inquiries_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 기존 customer_inquiries 테이블에도 레거시 문의 식별 컬럼을 안전하게 추가
    $column = $pdo->query("SHOW COLUMNS FROM customer_inquiries LIKE 'legacy_id'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE customer_inquiries ADD COLUMN legacy_id VARCHAR(80) NULL AFTER inquiry_no");
    }
    $index = $pdo->query("SHOW INDEX FROM customer_inquiries WHERE Key_name = 'uq_customer_inquiries_legacy'")->fetch();
    if (!$index) {
        $pdo->exec("ALTER TABLE customer_inquiries ADD UNIQUE KEY uq_customer_inquiries_legacy (legacy_id)");
    }
}

function inquiry_guest_key(string $value): string
{
    $value = trim($value);
    return preg_match('/^[A-Za-z0-9_-]{20,64}$/', $value) ? $value : '';
}

function inquiry_member(PDO $pdo): ?array
{
    $memberId = (int)($_SESSION['member_id'] ?? 0);
    if ($memberId <= 0) return null;

    $stmt = $pdo->prepare('SELECT id, name, phone, email, status FROM member_accounts WHERE id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    return ($row && ($row['status'] ?? '') === 'ACTIVE') ? $row : null;
}

function inquiry_public(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'inquiry_no' => (string)$row['inquiry_no'],
        'message' => (string)$row['message'],
        'status' => (string)$row['status'],
        'answer' => (string)($row['answer'] ?? ''),
        'answered_at' => (string)($row['answered_at'] ?? ''),
        'created_at' => (string)$row['created_at'],
    ];
}
