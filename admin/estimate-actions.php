<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('estimates');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST 요청만 허용됩니다.'); }



$allowedActions = ['claim', 'single_delete', 'bulk_delete'];
$action = (string)($_POST['action'] ?? '');
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    exit('잘못된 작업입니다.');
}
if ($action !== 'claim') {
    requireDataPermission('delete');
}
require_once __DIR__ . '/../config/database.php';

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
    if ($action === 'claim') {
        if (!isSalesAdmin()) { http_response_code(403); exit('영업사원 계정만 담당 등록할 수 있습니다.'); }
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || empty($_SESSION['estimate_action_csrf']) || !hash_equals($_SESSION['estimate_action_csrf'], $token)) {
            http_response_code(403); exit('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해주세요.');
        }
        $row = parseRowKey((string)($_POST['row_key'] ?? ''));
        if (!$row) { http_response_code(400); exit('잘못된 견적 정보입니다.'); }
        require_once __DIR__ . '/estimate-assignment.php';
        $claimed = claimEstimate($pdo, $row['table'], $row['id'], (int)$_SESSION['admin_id']);
        $_SESSION['estimate_assignment_notice'] = $claimed
            ? '고객의 담당자로 등록했습니다.'
            : '이미 담당자가 배정되었거나 등록할 수 없는 견적입니다. 담당자 정보를 확인해주세요.';
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
    $direct = $pdo->prepare('DELETE FROM estimate_direct WHERE id=?');
    $quick = $pdo->prepare('DELETE FROM estimate_quick WHERE id=?');
    foreach ($parsed as $row) {
        ($row['source'] === 'QUICK' ? $quick : $direct)->execute([$row['id']]);
    }
    $pdo->commit();
    returnToList();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    exit('처리 중 오류가 발생했습니다: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
