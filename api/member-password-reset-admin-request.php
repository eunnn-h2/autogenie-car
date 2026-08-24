<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
}

$data = member_json_input();
$name = trim((string)($data['name'] ?? ''));
$phone = normalize_member_phone((string)($data['phone'] ?? ''));

if (!preg_match('/^[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+(?:\s[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+)*$/u', $name)
    || mb_strlen(preg_replace('/\s/u', '', $name) ?? '', 'UTF-8') < 2) {
    member_response(['ok' => false, 'message' => '가입할 때 사용한 이름을 입력해 주세요.'], 422);
}

if ($phone === '') {
    member_response(['ok' => false, 'message' => '가입할 때 사용한 휴대폰 번호를 입력해 주세요.'], 422);
}

try {
    $stmt = $pdo->prepare("
        SELECT id, name, phone, email, status
        FROM members
        WHERE name = ? AND phone = ?
        LIMIT 1
    ");
    $stmt->execute([$name, $phone]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    // 회원 존재 여부를 노출하지 않기 위해 응답 문구는 동일하게 유지합니다.
    if ($member && ($member['status'] ?? '') === 'ACTIVE') {
        $check = $pdo->prepare("
            SELECT id
            FROM password_reset_admin_requests
            WHERE member_id = ?
              AND status = 'PENDING'
              AND requested_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            LIMIT 1
        ");
        $check->execute([(int)$member['id']]);

        if (!$check->fetchColumn()) {
            $insert = $pdo->prepare("
                INSERT INTO password_reset_admin_requests (
                    member_id,
                    member_name,
                    member_phone,
                    member_email,
                    status,
                    requested_at
                ) VALUES (?, ?, ?, ?, 'PENDING', NOW())
            ");
            $insert->execute([
                (int)$member['id'],
                (string)$member['name'],
                (string)$member['phone'],
                (string)$member['email'],
            ]);
        }
    }

    member_response([
        'ok' => true,
        'message' => '입력한 정보와 일치하는 회원이 있다면 관리자에게 비밀번호 재설정 요청이 전달됩니다.'
    ]);
} catch (PDOException $e) {
    member_response(['ok' => false, 'message' => '재설정 요청을 저장하지 못했습니다.'], 500);
}
