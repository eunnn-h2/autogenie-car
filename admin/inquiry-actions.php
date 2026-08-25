<?php

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./inquiries.php');
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS customer_inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inquiry_no VARCHAR(32) NOT NULL,
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
    PRIMARY KEY (id), UNIQUE KEY uq_customer_inquiries_no (inquiry_no), UNIQUE KEY uq_customer_inquiries_legacy (legacy_id),
    KEY idx_customer_inquiries_member (member_id, created_at),
    KEY idx_customer_inquiries_guest (guest_key, created_at),
    KEY idx_customer_inquiries_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$legacyColumn = $pdo->query("SHOW COLUMNS FROM customer_inquiries LIKE 'legacy_id'")->fetch();
if (!$legacyColumn) $pdo->exec("ALTER TABLE customer_inquiries ADD COLUMN legacy_id VARCHAR(80) NULL AFTER inquiry_no");
$legacyIndex = $pdo->query("SHOW INDEX FROM customer_inquiries WHERE Key_name = 'uq_customer_inquiries_legacy'")->fetch();
if (!$legacyIndex) $pdo->exec("ALTER TABLE customer_inquiries ADD UNIQUE KEY uq_customer_inquiries_legacy (legacy_id)");

$id = (int)($_POST['id'] ?? 0);
$answer = trim((string)($_POST['answer'] ?? ''));
if ($id <= 0 || $answer === '') {
    header('Location: ./inquiries.php?error=1');
    exit;
}
if (mb_strlen($answer) > 3000) {
    header('Location: ./inquiries.php?error=length');
    exit;
}

$stmt = $pdo->prepare("UPDATE customer_inquiries
    SET answer = ?, status = 'ANSWERED', answered_by = ?, answered_at = NOW()
    WHERE id = ?");
$stmt->execute([$answer, (int)($_SESSION['admin_id'] ?? 0), $id]);

header('Location: ./inquiries.php?saved=1#inquiry-' . $id);
exit;
