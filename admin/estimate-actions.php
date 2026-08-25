<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

requireVehicleEditor();

$allowedStatuses = ['NEW', 'CONTACTED', 'REVIEWING', 'APPROVED', 'CONTRACTED', 'CANCELED'];
$allowedActions = ['single_status', 'single_delete', 'bulk_status', 'bulk_delete'];
$action = (string)($_POST['action'] ?? '');
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    exit('잘못된 작업입니다.');
}

function parseRowKey(string $key): ?array {
    if (!preg_match('/^(DIRECT|QUICK):(\d+)$/', $key, $m)) return null;
    $id = (int)$m[2];
    if ($id < 1) return null;
    return [
        'source' => $m[1],
        'table' => $m[1] === 'QUICK' ? 'estimate_quick' : 'estimate_direct',
        'id' => $id,
    ];
}

function returnToList(): never {
    $query = trim((string)($_POST['return_query'] ?? ''));
    $target = './estimates.php';
    if ($query !== '') $target .= '?' . $query;
    header('Location: ' . $target);
    exit;
}

try {
    if ($action === 'single_status') {
        $row = parseRowKey((string)($_POST['row_key'] ?? ''));
        $status = (string)($_POST['status'] ?? '');
        if (!$row || !in_array($status, $allowedStatuses, true)) {
            http_response_code(400); exit('견적 또는 상태 값이 올바르지 않습니다.');
        }
        $stmt = $pdo->prepare("UPDATE {$row['table']} SET status=? WHERE id=?");
        $stmt->execute([$status, $row['id']]);
        returnToList();
    }

    if ($action === 'single_delete') {
        $row = parseRowKey((string)($_POST['row_key'] ?? ''));
        if (!$row) { http_response_code(400); exit('견적 값이 올바르지 않습니다.'); }
        $stmt = $pdo->prepare("DELETE FROM {$row['table']} WHERE id=?");
        $stmt->execute([$row['id']]);
        returnToList();
    }

    $selected = $_POST['selected'] ?? [];
    if (!is_array($selected) || !$selected) {
        http_response_code(400); exit('선택된 견적이 없습니다.');
    }

    $parsed = [];
    foreach ($selected as $key) {
        $row = parseRowKey((string)$key);
        if ($row) $parsed[] = $row;
    }
    if (!$parsed) { http_response_code(400); exit('유효한 견적이 없습니다.'); }

    $pdo->beginTransaction();
    if ($action === 'bulk_status') {
        $status = (string)($_POST['bulk_status'] ?? '');
        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('상태 값이 올바르지 않습니다.');
        }
        $direct = $pdo->prepare('UPDATE estimate_direct SET status=? WHERE id=?');
        $quick = $pdo->prepare('UPDATE estimate_quick SET status=? WHERE id=?');
        foreach ($parsed as $row) {
            ($row['source'] === 'QUICK' ? $quick : $direct)->execute([$status, $row['id']]);
        }
    } else {
        $direct = $pdo->prepare('DELETE FROM estimate_direct WHERE id=?');
        $quick = $pdo->prepare('DELETE FROM estimate_quick WHERE id=?');
        foreach ($parsed as $row) {
            ($row['source'] === 'QUICK' ? $quick : $direct)->execute([$row['id']]);
        }
    }
    $pdo->commit();
    returnToList();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    exit('처리 중 오류가 발생했습니다: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
