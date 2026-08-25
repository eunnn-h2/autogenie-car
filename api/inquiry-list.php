<?php

declare(strict_types=1);
require_once __DIR__ . '/inquiry-common.php';

ensure_inquiries_table($pdo);
$member = inquiry_member($pdo);
$guestKey = inquiry_guest_key((string)($_GET['guest_key'] ?? ''));

if ($member) {
    $stmt = $pdo->prepare('SELECT * FROM customer_inquiries WHERE member_id = ? ORDER BY id DESC LIMIT 200');
    $stmt->execute([(int)$member['id']]);
} elseif ($guestKey !== '') {
    $stmt = $pdo->prepare('SELECT * FROM customer_inquiries WHERE member_id IS NULL AND guest_key = ? ORDER BY id DESC LIMIT 200');
    $stmt->execute([$guestKey]);
} else {
    member_response(['ok' => true, 'inquiries' => []]);
}

$items = array_map('inquiry_public', $stmt->fetchAll());
member_response(['ok' => true, 'inquiries' => $items]);
