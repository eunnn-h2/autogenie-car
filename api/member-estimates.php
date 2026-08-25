<?php
declare(strict_types=1);

require_once __DIR__ . '/member-auth-common.php';

$memberId = (int)($_SESSION['member_id'] ?? 0);
if ($memberId < 1) {
    member_response([
        'ok' => false,
        'message' => '로그인이 필요합니다.',
        'estimates' => [],
        'counts' => ['total'=>0,'estimate'=>0,'contacted'=>0,'reviewing'=>0,'approved'=>0,'contracted'=>0,'canceled'=>0],
    ], 401);
}

function estimate_status_label(string $status): string
{
    return [
        'NEW' => '신규',
        'CONTACTED' => '상담중',
        'REVIEWING' => '심사중',
        'APPROVED' => '승인',
        'CONTRACTED' => '계약완료',
        'DONE' => '승인',
        'CANCELED' => '취소',
    ][$status] ?? $status;
}

try {
    $rows = [];

    $stmt = $pdo->prepare("
        SELECT id, estimate_no, status, brand_name, vehicle_name, trim_name,
               product_type, contract_months, monthly_payment, created_at
        FROM estimate_direct
        WHERE member_id = ?
    ");
    $stmt->execute([$memberId]);
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int)$row['id'],
            'estimate_no' => (string)$row['estimate_no'],
            'type' => 'DIRECT',
            'type_label' => '직접견적',
            'status' => (string)$row['status'],
            'status_label' => estimate_status_label((string)$row['status']),
            'title' => trim((string)$row['brand_name'].' '.(string)$row['vehicle_name']),
            'subtitle' => (string)($row['trim_name'] ?? ''),
            'product_type' => (string)($row['product_type'] ?? ''),
            'contract_months' => $row['contract_months'] !== null ? (int)$row['contract_months'] : null,
            'monthly_payment' => $row['monthly_payment'] !== null ? (int)$row['monthly_payment'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    $stmt = $pdo->prepare("
        SELECT id, estimate_no, status, car_type, monthly_budget, product_type, created_at
        FROM estimate_quick
        WHERE member_id = ?
    ");
    $stmt->execute([$memberId]);
    foreach ($stmt->fetchAll() as $row) {
        $carType = trim((string)($row['car_type'] ?? ''));
        $budget = trim((string)($row['monthly_budget'] ?? ''));
        $rows[] = [
            'id' => (int)$row['id'],
            'estimate_no' => (string)$row['estimate_no'],
            'type' => 'QUICK',
            'type_label' => '간편견적',
            'status' => (string)$row['status'],
            'status_label' => estimate_status_label((string)$row['status']),
            'title' => $carType !== '' ? $carType : '상담 후 차량 결정',
            'subtitle' => $budget !== '' ? '희망 월 예산 '.$budget : '이용방식 상담',
            'product_type' => (string)($row['product_type'] ?? ''),
            'contract_months' => null,
            'monthly_payment' => null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    usort($rows, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

    $counts = [
        'total'=>count($rows),
        'estimate'=>0,
        'contacted'=>0,
        'reviewing'=>0,
        'approved'=>0,
        'contracted'=>0,
        'canceled'=>0
    ];
    foreach ($rows as $row) {
        if ($row['status'] === 'NEW') $counts['estimate']++;
        elseif ($row['status'] === 'CONTACTED') $counts['contacted']++;
        elseif ($row['status'] === 'REVIEWING') $counts['reviewing']++;
        elseif (in_array($row['status'], ['APPROVED','DONE'], true)) $counts['approved']++;
        elseif ($row['status'] === 'CONTRACTED') $counts['contracted']++;
        elseif ($row['status'] === 'CANCELED') $counts['canceled']++;
    }

    member_response(['ok'=>true,'estimates'=>$rows,'counts'=>$counts]);
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Unknown column')) {
        member_response([
            'ok'=>false,
            'message'=>'회원 견적 연결용 DB 컬럼이 아직 없습니다. member_estimates_link_migration.sql을 먼저 실행해 주세요.',
            'estimates'=>[]
        ], 500);
    }
    member_response(['ok'=>false,'message'=>'내 견적을 불러오지 못했습니다.'], 500);
}
