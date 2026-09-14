<?php

declare(strict_types=1);
require_once __DIR__ . '/inquiry-common.php';

ensure_inquiries_table($pdo);
$member = inquiry_member($pdo);
$guestKey = inquiry_guest_key((string)($_GET['guest_key'] ?? ''));

if ($member) {
    // 로그인 이전/레거시 문의도 동일 이메일이면 내 문의로 연결합니다.
    $stmt = $pdo->prepare('UPDATE customer_inquiries SET member_id = ?, guest_key = NULL WHERE member_id IS NULL AND member_email = ?');
    $stmt->execute([(int)$member['id'], (string)$member['email']]);

    $stmt = $pdo->prepare('SELECT * FROM customer_inquiries WHERE member_id = ? OR (member_id IS NULL AND member_email = ?) ORDER BY id DESC LIMIT 200');
    $stmt->execute([(int)$member['id'], (string)$member['email']]);
} elseif ($guestKey !== '') {
    $stmt = $pdo->prepare('SELECT * FROM customer_inquiries WHERE member_id IS NULL AND guest_key = ? ORDER BY id DESC LIMIT 200');
    $stmt->execute([$guestKey]);
} else {
    member_response(['ok' => true, 'inquiries' => []]);
}

$items = array_map('inquiry_public', $stmt->fetchAll());
member_response(['ok' => true, 'inquiries' => $items]);
