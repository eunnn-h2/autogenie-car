<?php

declare(strict_types=1);
require_once __DIR__ . '/inquiry-common.php';

ensure_inquiries_table($pdo);
$data = member_json_input();
$items = $data['items'] ?? [];
$guestKey = inquiry_guest_key((string)($data['guest_key'] ?? ''));
$member = inquiry_member($pdo);

if (!$member && $guestKey === '') {
    member_response(['ok' => false, 'message' => '문의 저장 정보를 확인할 수 없습니다.'], 422);
}
if (!is_array($items)) {
    member_response(['ok' => false, 'message' => '이전 문의 데이터 형식이 올바르지 않습니다.'], 422);
}

$imported = 0;
$skipped = 0;
foreach (array_slice($items, 0, 100) as $item) {
    if (!is_array($item)) { $skipped++; continue; }
    $legacyId = trim((string)($item['id'] ?? ''));
    $message = trim((string)($item['message'] ?? ''));
    if ($legacyId === '' || mb_strlen($legacyId) > 80 || mb_strlen($message) < 1 || mb_strlen($message) > 3000) {
        $skipped++; continue;
    }

    $check = $pdo->prepare('SELECT id FROM customer_inquiries WHERE legacy_id = ? LIMIT 1');
    $check->execute([$legacyId]);
    if ($check->fetchColumn()) { $skipped++; continue; }

    $createdAt = trim((string)($item['created_at'] ?? ''));
    $created = null;
    if ($createdAt !== '') {
        $ts = strtotime($createdAt);
        if ($ts !== false) $created = date('Y-m-d H:i:s', $ts);
    }
    $statusRaw = strtoupper(trim((string)($item['status'] ?? '')));
    $answer = trim((string)($item['answer'] ?? ''));
    $answeredAtRaw = trim((string)($item['answered_at'] ?? ''));
    $answeredAt = null;
    if ($answeredAtRaw !== '') {
        $ts = strtotime($answeredAtRaw);
        if ($ts !== false) $answeredAt = date('Y-m-d H:i:s', $ts);
    }
    $status = ($answer !== '' || in_array($statusRaw, ['ANSWERED','답변완료'], true)) ? 'ANSWERED' : 'NEW';
    $inquiryNo = 'INQ' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

    $stmt = $pdo->prepare('INSERT INTO customer_inquiries
        (inquiry_no, legacy_id, member_id, guest_key, member_name, member_email, member_phone, message, status, answer, answered_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    try {
        $stmt->execute([
            $inquiryNo,
            $legacyId,
            $member ? (int)$member['id'] : null,
            $member ? null : $guestKey,
            $member['name'] ?? (string)($item['member_name'] ?? '') ?: null,
            $member['email'] ?? (string)($item['member_email'] ?? '') ?: null,
            $member['phone'] ?? null,
            $message,
            $status,
            $answer !== '' ? $answer : null,
            $answeredAt,
            $created ?: date('Y-m-d H:i:s'),
        ]);
        $imported++;
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') $skipped++;
        else throw $e;
    }
}

member_response(['ok' => true, 'imported' => $imported, 'skipped' => $skipped]);
