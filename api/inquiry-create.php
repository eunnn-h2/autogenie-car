<?php

declare(strict_types=1);
require_once __DIR__ . '/inquiry-common.php';
require_once __DIR__ . '/submission-common.php';

if($_SERVER['REQUEST_METHOD']!=='POST')member_response(['ok'=>false,'message'=>'잘못된 요청입니다.'],405);
if(($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='XMLHttpRequest')member_response(['ok'=>false,'message'=>'허용되지 않은 요청입니다.'],403);
ensure_inquiries_table($pdo);
$data = member_json_input();
$submission = agSubmissionStart('inquiry', $data);
$message = trim((string)($data['message'] ?? ''));
$guestKey = inquiry_guest_key((string)($data['guest_key'] ?? ''));

if (mb_strlen($message) < 5 || mb_strlen($message) > 200) {
    member_response(['ok' => false, 'message' => '문의 내용은 5자 이상 200자 이하로 입력해 주세요.'], 422);
}

$member = inquiry_member($pdo);
if (!$member && $guestKey === '') {
    member_response(['ok' => false, 'message' => '문의 저장 정보를 확인할 수 없습니다. 새로고침 후 다시 시도해 주세요.'], 422);
}

$category=trim(is_string($data['category']??null)?$data['category']:'');
if(!in_array($category,['','장기렌트','리스','계약','심사','차량','출고','기타'],true))member_response(['ok'=>false,'message'=>'문의 분류를 확인해 주세요.'],422);
if($category!=='')$message='['.$category.'] '.$message;
$inquiryNo = 'INQ' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$stmt = $pdo->prepare('INSERT INTO customer_inquiries
    (inquiry_no, member_id, guest_key, member_name, member_email, member_phone, message, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    $inquiryNo,
    $member ? (int)$member['id'] : null,
    $member ? null : $guestKey,
    $member['name'] ?? null,
    $member['email'] ?? null,
    $member['phone'] ?? null,
    $message,
    'NEW',
]);

$id = (int)$pdo->lastInsertId();
$stmt = $pdo->prepare('SELECT * FROM customer_inquiries WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
agSubmissionRespond($submission, ['ok' => true, 'inquiry' => inquiry_public($row)]);
