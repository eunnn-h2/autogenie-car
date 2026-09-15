<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

$id = (int)($_SESSION['member_id'] ?? 0);
if ($id <= 0) {
    member_response(['ok' => true, 'member' => null]);
}

$stmt = $pdo->prepare('SELECT id, name, phone, email, status, created_at FROM members WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member || $member['status'] !== 'ACTIVE') {
    unset($_SESSION['member_id']);
    member_response(['ok' => true, 'member' => null]);
}

member_response(['ok' => true, 'member' => member_public($member)]);
