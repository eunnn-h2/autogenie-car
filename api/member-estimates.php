<?php
declare(strict_types=1);

require_once __DIR__ . '/member-auth-common.php';
require_once __DIR__.'/../config/consultation.php';
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/estimate-region.php';
ensureEstimateRegionColumn($pdo);

$memberId = (int)($_SESSION['member_id'] ?? 0);
if ($memberId < 1) {
    member_response([
        'ok' => false,
        'message' => '로그인이 필요합니다.',
        'estimates' => [],
        'counts' => ['total'=>0,'new'=>0,'contacted'=>0,'done'=>0,'canceled'=>0],
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
        SELECT e.id, e.estimate_no, e.status, e.brand_name, e.vehicle_name, e.trim_name,
               e.product_type, e.contract_months, e.monthly_payment, e.created_at, e.color_name,e.prepayment_rate,e.annual_mileage,e.registration_region,e.contracted_at,
               a.name AS consultant_name,a.business_phone,a.kakao_chat_url,
               COALESCE(NULLIF(chosen_color.image_path, ''),
                        NULLIF(thumbnail_color.image_path, ''),
                        NULLIF(vehicle.image_path, '')) AS vehicle_image_path
        FROM estimate_direct AS e
        LEFT JOIN admin_accounts a ON a.id=e.assigned_admin_id AND a.is_active=1 AND a.role='SALES'
        LEFT JOIN car_vehicles AS vehicle ON vehicle.id = e.vehicle_id
        LEFT JOIN car_colors AS chosen_color
            ON chosen_color.id = e.color_id AND chosen_color.vehicle_id = e.vehicle_id
        LEFT JOIN car_colors AS thumbnail_color
            ON thumbnail_color.id = vehicle.estimate_thumbnail_color_id
            AND thumbnail_color.vehicle_id = e.vehicle_id
        WHERE e.member_id = ?
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
            'image_path' => (string)($row['vehicle_image_path'] ?? ''),
            'color_name'=>$row['color_name']??'', 'prepayment_rate'=>$row['prepayment_rate'], 'annual_mileage'=>$row['annual_mileage'],
            'registration_region'=>$row['registration_region']??'', 'contracted_at'=>$row['contracted_at'],
            'consultant_name'=>$row['consultant_name']??'', 'consultation_phone'=>$row['business_phone']??'',
            'consultation_url'=>'./consultation.php?type=DIRECT&id='.(int)$row['id'],
            'product_type' => (string)($row['product_type'] ?? ''),
            'contract_months' => $row['contract_months'] !== null ? (int)$row['contract_months'] : null,
            'monthly_payment' => $row['monthly_payment'] !== null ? (int)$row['monthly_payment'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    $stmt = $pdo->prepare("
        SELECT e.id, e.estimate_no, e.status, e.car_type, e.monthly_budget, e.product_type, e.created_at,a.name consultant_name,a.business_phone
        FROM estimate_quick e LEFT JOIN admin_accounts a ON a.id=e.assigned_admin_id AND a.is_active=1 AND a.role='SALES'
        WHERE e.member_id = ?
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
            'consultant_name'=>$row['consultant_name']??'', 'consultation_phone'=>$row['business_phone']??'',
            'consultation_url'=>'./consultation.php?type=QUICK&id='.(int)$row['id'],
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
