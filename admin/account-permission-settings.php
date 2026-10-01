<?php
declare(strict_types=1);

require_once __DIR__ . '/category-permissions.php';

function parseAccountPermissionChanges(string $payload): array {
    $rows = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rows) || !array_is_list($rows) || !$rows) {
        throw new RuntimeException('변경한 권한이 없습니다.');
    }
    $changes = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1 || isset($changes[$row['id']])) {
            throw new RuntimeException('잘못된 계정 정보입니다.');
        }
        foreach (['can_create', 'can_update', 'can_delete'] as $field) {
            if (!in_array($row[$field] ?? null, [0, 1], true)) throw new RuntimeException('잘못된 작업 권한입니다.');
        }
        if (!is_array($row['categories'] ?? null) || !array_is_list($row['categories'])) {
            throw new RuntimeException('잘못된 카테고리 권한입니다.');
        }
        foreach ($row['categories'] as $category) {
            if (!is_string($category) || !array_key_exists($category, adminCategoryLabels())) {
                throw new RuntimeException('잘못된 카테고리 권한입니다.');
            }
        }
        $row['categories'] = normalizeAdminCategories($row['categories']);
        $changes[$row['id']] = $row;
    }
    return $changes;
}

function saveAccountPermissionChanges(PDO $pdo, array $changes, int $currentAdminId): int {
    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare("SELECT role FROM admin_accounts WHERE id = ? FOR UPDATE");
        $update = $pdo->prepare("UPDATE admin_accounts SET can_create = ?, can_update = ?, can_delete = ?, category_permissions = ? WHERE id = ? AND role = 'SALES'");
        $changed = 0;
        foreach ($changes as $id => $row) {
            $check->execute([$id]);
            if ($id === $currentAdminId || $check->fetchColumn() !== 'SALES') {
                throw new RuntimeException('영업사원 계정의 권한만 수정할 수 있습니다. 새로고침 후 다시 시도해주세요.');
            }
            $update->execute([$row['can_create'], $row['can_update'], $row['can_delete'], json_encode($row['categories'], JSON_THROW_ON_ERROR), $id]);
            $changed += $update->rowCount();
        }
        $pdo->commit();
        return $changed;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
