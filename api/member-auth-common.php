<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

require_once __DIR__.'/../config/member-session.php';
agMemberSessionStart($pdo);

$memberSessionId = (int)($_SESSION['member_id'] ?? 0);
if ($memberSessionId > 0) {
    $memberStatusQuery = $pdo->prepare('SELECT status, password_hash FROM member_accounts WHERE id = ? LIMIT 1');
    $memberStatusQuery->execute([$memberSessionId]);
    $memberStatus=$memberStatusQuery->fetch(PDO::FETCH_ASSOC);
    $memberStamp=$memberStatus ? hash('sha256',(string)$memberStatus['password_hash']) : '';
    if (!$memberStatus || $memberStatus['status'] !== 'ACTIVE' || (isset($_SESSION['member_auth_stamp']) && !hash_equals($_SESSION['member_auth_stamp'],$memberStamp))) {
        unset($_SESSION['member_id'],$_SESSION['member_auth_stamp']);
        session_regenerate_id(true);
    } else { $_SESSION['member_auth_stamp']=$memberStamp; }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie');
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && ($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='XMLHttpRequest')member_response(['ok'=>false,'message'=>'허용되지 않은 요청입니다.'],403);

function member_json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $_POST;
}

function member_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_member_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (!preg_match('/^(010|011|016|017|018|019)(\d{7,8})$/', $digits)) {
        return '';
    }
    $prefix = substr($digits, 0, 3);
    $rest = substr($digits, 3);
    if (strlen($rest) === 7) {
        return $prefix . '-' . substr($rest, 0, 3) . '-' . substr($rest, 3);
    }
    return $prefix . '-' . substr($rest, 0, 4) . '-' . substr($rest, 4);
}

function member_public(array $row): array
{
    global $pdo;
    $isKakao = str_starts_with((string)$row['phone'], 'KAKAO-');
    if (!$isKakao) {
        try {
            $q=$pdo->prepare('SELECT 1 FROM member_kakao_accounts WHERE member_id=? LIMIT 1');
            $q->execute([(int)$row['id']]);$isKakao=(bool)$q->fetchColumn();
        } catch (PDOException $e) { if (($e->errorInfo[1]??0)!==1146) throw $e; }
    }
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'phone' => str_starts_with((string)$row['phone'], 'KAKAO-') ? '' : (string)$row['phone'],
        'email' => str_ends_with((string)$row['email'], '@accounts.invalid') ? '' : (string)$row['email'],
        'provider' => $isKakao ? 'kakao' : 'site',
        'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
    ];
}
