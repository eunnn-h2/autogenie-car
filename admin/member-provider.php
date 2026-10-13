<?php
declare(strict_types=1);

// Resolve only linked member IDs; names and phone numbers do not prove membership.
function adminMemberProviders(PDO $pdo, array $records): array {
    $ids = array_values(array_unique(array_filter(array_map(
        static fn(array $record): int => max(0, (int)($record['member_id'] ?? 0)), $records
    ))));
    if (!$ids) return [];
    try {
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $check->execute(['member_kakao_accounts']);
        $hasKakao = (int)$check->fetchColumn() > 0;
        $provider = $hasKakao ? "CASE WHEN EXISTS (SELECT 1 FROM member_kakao_accounts k WHERE k.member_id = m.id) THEN 'kakao' ELSE 'site' END" : "'site'";
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT m.id, $provider AS provider FROM member_accounts m WHERE m.id IN ($placeholders)");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $error) {
        error_log('[AutoGenie member provider] ' . $error->getMessage());
        return []; // Unavailable or removed members are shown as unknown, never guessed.
    }
}

function adminMemberProviderBadge(array $record, array $providers): string {
    $id = (int)($record['member_id'] ?? 0);
    $kind = $id > 0 ? ($providers[$id] ?? 'unknown') : 'guest';
    $labels = ['kakao' => '카카오', 'site' => '사이트', 'guest' => '비회원', 'unknown' => '가입경로 미확인'];
    if (!isset($labels[$kind])) $kind = 'unknown';
    return '<span class="member-provider member-provider--' . $kind . '" title="가입경로">' . $labels[$kind] . '</span>';
}
