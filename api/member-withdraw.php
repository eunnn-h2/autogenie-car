<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';
require_once __DIR__ . '/member-retention-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    member_response(['ok' => false, 'message' => '허용되지 않은 요청입니다.'], 403);
}
$data = member_json_input();
if (($data['confirmation'] ?? '') !== '탈퇴') {
    member_response(['ok' => false, 'message' => '탈퇴 확인 문구를 입력해 주세요.'], 422);
}

$memberId = (int)($_SESSION['member_id'] ?? 0);
if ($memberId <= 0) {
    member_response(['ok' => false, 'message' => '로그인이 필요합니다.'], 401);
}

try {
    member_retention_init($pdo);
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM member_saved_quotes WHERE member_id=?')->execute([$memberId]);
    $pdo->prepare('DELETE FROM member_vehicle_favorites WHERE member_id=?')->execute([$memberId]);
    $memberQuery = $pdo->prepare('SELECT phone, email, status, withdrawn_at FROM member_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
    $memberQuery->execute([$memberId]);
    $member = $memberQuery->fetch(PDO::FETCH_ASSOC);
    if (!$member || $member['status'] !== 'ACTIVE' || $member['withdrawn_at'] !== null) {
        $pdo->rollBack();
        member_response(['ok' => false, 'message' => '이미 탈퇴했거나 계정을 확인할 수 없습니다.'], 409);
    }

    $oldPhone = (string)$member['phone'];
    $oldEmail = (string)$member['email'];
    if ($oldEmail !== '' && member_retention_table_exists($pdo, 'customer_inquiries')) {
        $pdo->prepare('UPDATE customer_inquiries SET member_id = ? WHERE (member_id IS NULL OR member_id = 0) AND member_email = ?')
            ->execute([$memberId, $oldEmail]);
    }
    if ($oldPhone !== '' && !str_starts_with($oldPhone, 'KAKAO-')) {
        foreach (['estimate_direct', 'estimate_quick'] as $table) {
            if (!member_retention_table_exists($pdo, $table)) continue;
            $pdo->prepare("UPDATE {$table} SET member_id = ? WHERE (member_id IS NULL OR member_id = 0) AND customer_phone = ?")
                ->execute([$memberId, $oldPhone]);
        }
    }
    member_retention_cleanup_identity_shadows($pdo, $memberId, $oldPhone);
    foreach(['DIRECT'=>'estimate_direct','QUICK'=>'estimate_quick'] as $source=>$table){
        $pdo->prepare("DELETE m FROM ag_estimate_messages m INNER JOIN $table e ON e.id=m.estimate_id WHERE m.estimate_source=? AND e.member_id=?")->execute([$source,$memberId]);
    }
    member_retention_withdraw_records($pdo, $memberId);

    foreach (['member_kakao_accounts', 'admin_password_reset_requests', 'estimate_abandonments'] as $table) {
        if (!member_retention_table_exists($pdo, $table)) continue;
        $pdo->prepare("DELETE FROM {$table} WHERE member_id = ?")->execute([$memberId]);
    }
    if ($oldPhone !== '' && member_retention_table_exists($pdo, 'member_sms_send_limits')) {
        $pdo->prepare('DELETE FROM member_sms_send_limits WHERE limit_key = ?')
            ->execute([hash('sha256', 'phone:' . $oldPhone)]);
    }

    $suffix = bin2hex(random_bytes(12));
    $anonymousPhone = 'WDR-' . substr($suffix, 0, 16);
    $anonymousEmail = 'withdrawn-' . $memberId . '-' . $suffix . '@accounts.invalid';
    $anonymousPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE member_accounts SET
        status = 'INACTIVE', withdrawn_at = NOW(), name = '탈퇴 회원', phone = ?, email = ?, password_hash = ?,
        created_at = NOW(), last_login_at = NULL, email_verified_at = NULL, password_reset_code_hash = NULL,
        password_reset_expires_at = NULL, password_reset_requested_at = NULL
        WHERE id = ? AND status = 'ACTIVE'");
    $stmt->execute([$anonymousPhone, $anonymousEmail, $anonymousPasswordHash, $memberId]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('회원 상태가 변경되었습니다. 다시 시도해 주세요.');
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[AutoGenie member withdrawal] ' . $error->getMessage());
    member_response(['ok' => false, 'message' => '탈퇴 처리에 실패했습니다. 잠시 후 다시 시도해 주세요.'], 500);
}

unset(
    $_SESSION['member_id'],
    $_SESSION['signup_phone'], $_SESSION['signup_phone_code_hash'], $_SESSION['signup_phone_code_expires_at'],
    $_SESSION['signup_phone_code_requested_at'], $_SESSION['signup_phone_code_attempts'],
    $_SESSION['signup_phone_verified'], $_SESSION['signup_phone_verified_at'],
    $_SESSION['signup_email'], $_SESSION['signup_email_code_hash'], $_SESSION['signup_email_code_expires_at'],
    $_SESSION['signup_email_code_requested_at'], $_SESSION['signup_email_verified'], $_SESSION['signup_email_verified_at']
);
session_regenerate_id(true);
member_response(['ok' => true]);