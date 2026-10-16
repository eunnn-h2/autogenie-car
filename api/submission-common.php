<?php
declare(strict_types=1);

/** Keep successful submissions replayable while the PHP session lock serializes retries. */
function agSubmissionStart(string $scope, array $data): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('접수 세션을 확인할 수 없습니다.');
    }
    $canonicalize = static function (array $value) use (&$canonicalize): array {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item = $canonicalize($item);
        unset($item);
        return $value;
    };
    $digest = hash('sha256', json_encode($canonicalize($data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $requestKey = trim((string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
    if ($requestKey !== '' && !preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $requestKey)) {
        member_response(['ok' => false, 'success' => false, 'message' => '접수 요청 정보를 확인해 주세요.'], 422);
    }
    // Older open pages remain usable; identical legacy requests are replayed for 30 seconds.
    $ticket = ['key' => hash('sha256', $scope . ':member:' . (int)($_SESSION['member_id'] ?? 0) . ':' . ($requestKey ?: 'legacy:' . $digest)),
        'digest' => $digest, 'expires' => time() + ($requestKey === '' ? 30 : 3600)];
    $cache = $_SESSION['ag_submission_replays_v1'] ?? [];
    foreach ($cache as $key => $entry) if (($entry['expires'] ?? 0) <= time()) unset($cache[$key]);
    $_SESSION['ag_submission_replays_v1'] = $cache;
    $existing = $cache[$ticket['key']] ?? null;
    if ($existing) {
        if (!hash_equals($existing['digest'], $digest)) {
            member_response(['ok' => false, 'success' => false, 'message' => '이미 사용한 접수 요청입니다. 입력 내용을 확인한 뒤 다시 신청해 주세요.'], 409);
        }
        header('X-Idempotency-Replayed: 1');
        member_response($existing['response']);
    }
    return $ticket;
}

function agSubmissionRespond(array $ticket, array $response): never {
    $cache = $_SESSION['ag_submission_replays_v1'] ?? [];
    $cache[$ticket['key']] = ['digest' => $ticket['digest'], 'expires' => $ticket['expires'], 'response' => $response];
    $_SESSION['ag_submission_replays_v1'] = array_slice($cache, -50, null, true);
    member_response($response);
}
