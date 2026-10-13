<?php
declare(strict_types=1);

// Only list visibility is stored here; member and activity records are untouched.
function customerArchiveInit(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_customer_archive (
        customer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        snapshot LONGTEXT NOT NULL,
        deleted_by BIGINT UNSIGNED NOT NULL,
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function customerArchiveLoad(PDO $pdo): array {
    $archived = [];
    foreach ($pdo->query('SELECT customer_hash, snapshot, deleted_at FROM admin_customer_archive') as $record) {
        $row = json_decode($record['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $row['customer_hash'] = $record['customer_hash'];
        $row['deleted_at'] = $record['deleted_at'];
        $archived[$record['customer_hash']] = $row;
    }
    return $archived;
}

function customerArchiveApply(PDO $pdo, bool $isMainAdmin, string $sessionToken, array $post, array $customers, array $archived, int $adminId): void {
    if (!$isMainAdmin) throw new RuntimeException('메인관리자만 고객을 삭제하거나 복원할 수 있습니다.', 403);
    if (!is_string($post['csrf_token'] ?? null) || !hash_equals($sessionToken, $post['csrf_token'])) {
        throw new RuntimeException('요청이 만료되었습니다. 새로고침 후 다시 시도해 주세요.', 403);
    }
    $hash = $post['customer_hash'] ?? '';
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
        throw new RuntimeException('고객 정보가 올바르지 않습니다.', 400);
    }
    $action = $post['action'] ?? '';
    if ($action === 'delete') {
        if (($post['delete_confirmation'] ?? '') !== '삭제') {
            throw new RuntimeException('삭제하려면 2차 확인란에 "삭제"를 정확히 입력해 주세요.', 400);
        }
        if (!isset($customers[$hash]) || isset($archived[$hash])) {
            throw new RuntimeException('삭제할 고객이 없거나 이미 삭제되었습니다.', 409);
        }
        $stmt = $pdo->prepare('INSERT INTO admin_customer_archive (customer_hash, snapshot, deleted_by) VALUES (?, ?, ?)');
        $stmt->execute([$hash, json_encode($customers[$hash], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $adminId]);
    } elseif ($action === 'restore') {
        if (!isset($archived[$hash])) throw new RuntimeException('복원할 고객을 찾을 수 없습니다.', 409);
        $pdo->prepare('DELETE FROM admin_customer_archive WHERE customer_hash = ?')->execute([$hash]);
    } else {
        throw new RuntimeException('올바르지 않은 요청입니다.', 400);
    }
}
