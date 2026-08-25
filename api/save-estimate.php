<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/member-auth-common.php';

function fail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('POST 요청만 허용됩니다.', 405);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        fail('JSON 형식이 올바르지 않습니다.');
    }
} else {
    $data = $_POST;
}

$vehicleId = (int)($data['vehicle_id'] ?? 0);
$trimId = (int)($data['trim_id'] ?? 0);
$colorId = (int)($data['color_id'] ?? 0);
$priceId = (int)($data['price_id'] ?? 0);
$customerName = trim((string)($data['customer_name'] ?? ''));
$customerPhone = trim((string)($data['customer_phone'] ?? ''));
$customerMemo = trim((string)($data['customer_memo'] ?? ''));
$memberId = (int)($_SESSION['member_id'] ?? 0);

if ($vehicleId < 1) fail('차량을 선택해 주세요.');
if ($trimId < 1) fail('트림을 선택해 주세요.');
if ($colorId < 1) fail('외장색상을 선택해 주세요.');
if ($priceId < 1) fail('이용조건을 선택해 주세요.');
if ($customerName === '') fail('성함을 입력해 주세요.');
if ($customerPhone === '') fail('연락처를 입력해 주세요.');

// 브라우저 검증을 우회한 요청도 서버에서 다시 차단합니다.
$customerName = preg_replace('/\s+/u', ' ', $customerName) ?? $customerName;
if (!preg_match('/^[가-힣ㄱ-ㅎㅏ-ㅣA-Za-z]+(?: [가-힣ㄱ-ㅎㅏ-ㅣA-Za-z]+)*$/u', $customerName)) {
    fail('성함은 한글 또는 영문만 입력해 주세요.');
}
$nameWithoutSpaces = preg_replace('/\s+/u', '', $customerName) ?? '';
if (mb_strlen($nameWithoutSpaces, 'UTF-8') < 2 || mb_strlen($customerName, 'UTF-8') > 30) {
    fail('성함은 2자 이상 30자 이하로 입력해 주세요.');
}

$phoneDigits = preg_replace('/\D/', '', $customerPhone) ?? '';
if (!preg_match('/^(?:010\d{8}|01[16789]\d{7,8})$/', $phoneDigits)) {
    fail('연락처는 010, 011, 016, 017, 018, 019로 시작하는 올바른 휴대폰 번호를 입력해 주세요.');
}

// 브라우저 검증을 우회해도 단순 반복형 테스트/허위 번호는 저장하지 않습니다.
// 예: 010-1111-1111, 010-0000-0000, 010-1234-1234, 010-1212-1212
$subscriberDigits = substr($phoneDigits, 3);
$middleLength = strlen($phoneDigits) === 10 ? 3 : 4;
$middleBlock = substr($phoneDigits, 3, $middleLength);
$lastBlock = substr($phoneDigits, 3 + $middleLength);
$repeatedSubscriber = preg_match('/^(\d{1,4})\1+$/', $subscriberDigits) === 1;
$repeatedMiddleBlock = preg_match('/^(\d)\1+$/', $middleBlock) === 1;
$repeatedLastBlock = preg_match('/^(\d)\1+$/', $lastBlock) === 1;

if ($repeatedSubscriber || $repeatedMiddleBlock || $repeatedLastBlock) {
    fail('반복되는 숫자가 많은 번호는 사용할 수 없습니다. 실제 휴대폰 번호를 입력해 주세요.');
}

$middleLength = strlen($phoneDigits) === 10 ? 3 : 4;
$customerPhone = substr($phoneDigits, 0, 3) . '-' . substr($phoneDigits, 3, $middleLength) . '-' . substr($phoneDigits, 3 + $middleLength);

try {
    $vehicleStmt = $pdo->prepare("SELECT v.id, v.name AS vehicle_name, b.name AS brand_name FROM car_vehicles v INNER JOIN car_brands b ON b.id=v.brand_id WHERE v.id=? AND v.is_active=1 LIMIT 1");
    $vehicleStmt->execute([$vehicleId]);
    $vehicle = $vehicleStmt->fetch();
    if (!$vehicle) fail('선택한 차량을 찾을 수 없습니다.');

    $trimStmt = $pdo->prepare("SELECT id, name FROM car_trims WHERE id=? AND vehicle_id=? AND is_active=1 LIMIT 1");
    $trimStmt->execute([$trimId, $vehicleId]);
    $trim = $trimStmt->fetch();
    if (!$trim) fail('선택한 트림이 해당 차량과 일치하지 않습니다.');

    $colorStmt = $pdo->prepare("SELECT id, name FROM car_colors WHERE id=? AND vehicle_id=? AND is_active=1 LIMIT 1");
    $colorStmt->execute([$colorId, $vehicleId]);
    $color = $colorStmt->fetch();
    if (!$color) fail('선택한 색상이 해당 차량과 일치하지 않습니다.');

    $priceStmt = $pdo->prepare("SELECT id, product_type, contract_months, prepayment_rate, annual_mileage, monthly_payment FROM car_prices WHERE id=? AND vehicle_id=? AND trim_id=? AND is_active=1 LIMIT 1");
    $priceStmt->execute([$priceId, $vehicleId, $trimId]);
    $price = $priceStmt->fetch();
    if (!$price) fail('선택한 이용조건이 해당 차량/트림과 일치하지 않습니다.');

    $pdo->beginTransaction();

    $insert = $pdo->prepare("INSERT INTO estimate_direct (
        member_id,
        estimate_no,
        vehicle_id, trim_id, color_id, price_id,
        brand_name, vehicle_name, trim_name, color_name,
        product_type, contract_months, prepayment_rate, annual_mileage, monthly_payment,
        customer_name, customer_phone, customer_memo,
        status
    ) VALUES (
        :member_id,
        :estimate_no,
        :vehicle_id, :trim_id, :color_id, :price_id,
        :brand_name, :vehicle_name, :trim_name, :color_name,
        :product_type, :contract_months, :prepayment_rate, :annual_mileage, :monthly_payment,
        :customer_name, :customer_phone, :customer_memo,
        'NEW'
    )");

    // 먼저 임시 번호로 INSERT 후, AUTO_INCREMENT id를 포함해 최종 견적번호를 만듭니다.
    $temporaryNo = 'TEMP-' . bin2hex(random_bytes(8));
    $insert->execute([
        ':member_id' => $memberId > 0 ? $memberId : null,
        ':estimate_no' => $temporaryNo,
        ':vehicle_id' => $vehicleId,
        ':trim_id' => $trimId,
        ':color_id' => $colorId,
        ':price_id' => $priceId,
        ':brand_name' => $vehicle['brand_name'],
        ':vehicle_name' => $vehicle['vehicle_name'],
        ':trim_name' => $trim['name'],
        ':color_name' => $color['name'],
        ':product_type' => $price['product_type'],
        ':contract_months' => $price['contract_months'],
        ':prepayment_rate' => $price['prepayment_rate'],
        ':annual_mileage' => $price['annual_mileage'],
        ':monthly_payment' => $price['monthly_payment'],
        ':customer_name' => $customerName,
        ':customer_phone' => $customerPhone,
        ':customer_memo' => $customerMemo !== '' ? $customerMemo : null,
    ]);

    $estimateId = (int)$pdo->lastInsertId();
    $estimateNo = date('Ymd') . '-' . str_pad((string)$estimateId, 6, '0', STR_PAD_LEFT);

    $pdo->prepare('UPDATE estimate_direct SET estimate_no=? WHERE id=?')->execute([$estimateNo, $estimateId]);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '견적이 저장되었습니다.',
        'estimate_id' => $estimateId,
        'estimate_no' => $estimateNo,
        'admin_detail_url' => './admin/estimate-detail.php?id=' . $estimateId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof PDOException && str_contains($e->getMessage(), "doesn't exist")) {
        fail('estimates 테이블이 없습니다. 먼저 estimates_table.sql을 phpMyAdmin에서 실행해 주세요.', 500);
    }

    fail('견적 저장 중 오류가 발생했습니다: ' . $e->getMessage(), 500);
}
