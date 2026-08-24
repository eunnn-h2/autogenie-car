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
    if (!is_array($data)) fail('JSON 형식이 올바르지 않습니다.');
} else {
    $data = $_POST;
}

$customerName = trim((string)($data['customer_name'] ?? ''));
$customerPhone = trim((string)($data['customer_phone'] ?? ''));
$carType = trim((string)($data['car_type'] ?? ''));
$monthlyBudget = trim((string)($data['monthly_budget'] ?? ''));
$productType = strtoupper(trim((string)($data['product_type'] ?? '')));
$memberId = (int)($_SESSION['member_id'] ?? 0);

// 광고/캠페인 유입정보. 프론트에서 전달받되 길이를 제한해 저장합니다.
$utmSource = mb_substr(trim((string)($data['utm_source'] ?? '')), 0, 100, 'UTF-8');
$utmMedium = mb_substr(trim((string)($data['utm_medium'] ?? '')), 0, 100, 'UTF-8');
$utmCampaign = mb_substr(trim((string)($data['utm_campaign'] ?? '')), 0, 150, 'UTF-8');
$utmContent = mb_substr(trim((string)($data['utm_content'] ?? '')), 0, 150, 'UTF-8');
$utmTerm = mb_substr(trim((string)($data['utm_term'] ?? '')), 0, 150, 'UTF-8');
$referrer = mb_substr(trim((string)($data['referrer'] ?? '')), 0, 500, 'UTF-8');
$landingPage = mb_substr(trim((string)($data['landing_page'] ?? '')), 0, 500, 'UTF-8');


if ($customerName === '') fail('성함을 입력해 주세요.');
if ($customerPhone === '') fail('연락처를 입력해 주세요.');

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

$allowedCarTypes = ['', '세단', 'SUV', 'RV/MPV', '전기차', '하이브리드', '기타'];
if (!in_array($carType, $allowedCarTypes, true)) fail('관심 차종 값이 올바르지 않습니다.');

$allowedBudgets = ['', '30만원 이하', '30~50만원', '50~70만원', '70~100만원', '100만원 이상'];
if (!in_array($monthlyBudget, $allowedBudgets, true)) fail('희망 월 예산 값이 올바르지 않습니다.');

if (!in_array($productType, ['', 'RENT', 'LEASE'], true)) fail('이용 방식 값이 올바르지 않습니다.');

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare("INSERT INTO estimate_quick (
        member_id,
        estimate_no,
        customer_name,
        customer_phone,
        car_type,
        monthly_budget,
        product_type,
        utm_source, utm_medium, utm_campaign, utm_content, utm_term, referrer, landing_page,
        status
    ) VALUES (
        :member_id,
        :estimate_no,
        :customer_name,
        :customer_phone,
        :car_type,
        :monthly_budget,
        :product_type,
        :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term, :referrer, :landing_page,
        'NEW'
    )");

    $temporaryNo = 'QTEMP-' . bin2hex(random_bytes(8));
    $insert->execute([
        ':member_id' => $memberId > 0 ? $memberId : null,
        ':estimate_no' => $temporaryNo,
        ':customer_name' => $customerName,
        ':customer_phone' => $customerPhone,
        ':car_type' => $carType !== '' ? $carType : null,
        ':monthly_budget' => $monthlyBudget !== '' ? $monthlyBudget : null,
        ':product_type' => $productType !== '' ? $productType : null,
        ':utm_source' => $utmSource !== '' ? $utmSource : null,
        ':utm_medium' => $utmMedium !== '' ? $utmMedium : null,
        ':utm_campaign' => $utmCampaign !== '' ? $utmCampaign : null,
        ':utm_content' => $utmContent !== '' ? $utmContent : null,
        ':utm_term' => $utmTerm !== '' ? $utmTerm : null,
        ':referrer' => $referrer !== '' ? $referrer : null,
        ':landing_page' => $landingPage !== '' ? $landingPage : null,
    ]);

    $estimateId = (int)$pdo->lastInsertId();
    $estimateNo = 'Q' . date('Ymd') . '-' . str_pad((string)$estimateId, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE estimate_quick SET estimate_no=? WHERE id=?')->execute([$estimateNo, $estimateId]);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '간편견적이 저장되었습니다.',
        'estimate_id' => $estimateId,
        'estimate_no' => $estimateNo,
        'admin_detail_url' => './admin/estimate-detail.php?type=quick&id=' . $estimateId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    if ($e instanceof PDOException && str_contains($e->getMessage(), "doesn't exist")) {
        fail('quick_estimate_direct 테이블이 없습니다. quick_estimates_table.sql을 phpMyAdmin에서 먼저 실행해 주세요.', 500);
    }

    fail('간편견적 저장 중 오류가 발생했습니다: ' . $e->getMessage(), 500);
}
