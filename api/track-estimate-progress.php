<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/member-auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false]);
    exit;
}
$data = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($data)) $data = $_POST;

$sessionKey = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($data['session_key'] ?? '')) ?: '';
if (strlen($sessionKey) < 8 || strlen($sessionKey) > 80) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'invalid session']);
    exit;
}
$stages = [
    'LANDING'=>1,
    'VEHICLE_LIST'=>2,
    'VEHICLE_DETAIL'=>3,
    'TRIM_SELECTED'=>4,
    'CONDITIONS_SELECTED'=>5,
    'ESTIMATE_FORM'=>6,
    'CUSTOMER_INPUT'=>7,
    'COMPLETED'=>8,
];
$stage = strtoupper(trim((string)($data['stage'] ?? 'LANDING')));
if (!isset($stages[$stage])) $stage = 'LANDING';
$order = $stages[$stage];
$completed = $stage === 'COMPLETED' ? 1 : 0;
$memberId = (int)($_SESSION['member_id'] ?? 0);

$clean = static function($value, int $len): ?string {
    $v = mb_substr(trim((string)$value), 0, $len, 'UTF-8');
    return $v !== '' ? $v : null;
};

try {
    $sql = "INSERT INTO estimate_abandonments (
                session_key, member_id, stage, stage_order,
                vehicle_id, vehicle_name, trim_id, trim_name, product_type,
                utm_source, utm_medium, utm_campaign, referrer, landing_page,
                is_completed, completed_at
            ) VALUES (
                :session_key, :member_id, :stage, :stage_order,
                :vehicle_id, :vehicle_name, :trim_id, :trim_name, :product_type,
                :utm_source, :utm_medium, :utm_campaign, :referrer, :landing_page,
                :is_completed, IF(:is_completed2=1, NOW(), NULL)
            )
            ON DUPLICATE KEY UPDATE
                member_id = COALESCE(VALUES(member_id), member_id),
                stage = IF(VALUES(stage_order) >= stage_order, VALUES(stage), stage),
                stage_order = GREATEST(stage_order, VALUES(stage_order)),
                vehicle_id = COALESCE(VALUES(vehicle_id), vehicle_id),
                vehicle_name = COALESCE(VALUES(vehicle_name), vehicle_name),
                trim_id = COALESCE(VALUES(trim_id), trim_id),
                trim_name = COALESCE(VALUES(trim_name), trim_name),
                product_type = COALESCE(VALUES(product_type), product_type),
                utm_source = COALESCE(VALUES(utm_source), utm_source),
                utm_medium = COALESCE(VALUES(utm_medium), utm_medium),
                utm_campaign = COALESCE(VALUES(utm_campaign), utm_campaign),
                referrer = COALESCE(VALUES(referrer), referrer),
                landing_page = COALESCE(VALUES(landing_page), landing_page),
                is_completed = GREATEST(is_completed, VALUES(is_completed)),
                completed_at = IF(VALUES(is_completed)=1, COALESCE(completed_at, NOW()), completed_at),
                last_activity_at = NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':session_key'=>$sessionKey,
        ':member_id'=>$memberId > 0 ? $memberId : null,
        ':stage'=>$stage,
        ':stage_order'=>$order,
        ':vehicle_id'=>(int)($data['vehicle_id'] ?? 0) ?: null,
        ':vehicle_name'=>$clean($data['vehicle_name'] ?? '', 150),
        ':trim_id'=>(int)($data['trim_id'] ?? 0) ?: null,
        ':trim_name'=>$clean($data['trim_name'] ?? '', 150),
        ':product_type'=>$clean($data['product_type'] ?? '', 20),
        ':utm_source'=>$clean($data['utm_source'] ?? '', 100),
        ':utm_medium'=>$clean($data['utm_medium'] ?? '', 100),
        ':utm_campaign'=>$clean($data['utm_campaign'] ?? '', 150),
        ':referrer'=>$clean($data['referrer'] ?? '', 500),
        ':landing_page'=>$clean($data['landing_page'] ?? '', 500),
        ':is_completed'=>$completed,
        ':is_completed2'=>$completed,
    ]);
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // 분석 로깅 실패가 사용자 견적 흐름을 막으면 안 됩니다.
    http_response_code(200);
    echo json_encode(['success'=>false], JSON_UNESCAPED_UNICODE);
}
