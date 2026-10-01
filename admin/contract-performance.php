<?php
declare(strict_types=1);

function contractTeamColumnExists(PDO $pdo): bool {
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = 'admin_accounts'\n          AND COLUMN_NAME = 'team_name'\n    ");
    $stmt->execute();
    return (int)$stmt->fetchColumn() > 0;
}

function contractPerformance(PDO $pdo, bool $isMain, int $currentAdminId): array {
    if ($currentAdminId < 1) throw new RuntimeException('로그인 정보를 확인해주세요.');

    $hasTeam = contractTeamColumnExists($pdo);
    $teamSelect = $hasTeam ? 'team_name' : "NULL AS team_name";
    $accounts = $pdo->prepare("SELECT id, name, username, is_active, {$teamSelect} FROM admin_accounts WHERE role = 'SALES'" . ($isMain ? '' : ' AND id = ?') . ' ORDER BY team_name, name, id');
    $accounts->execute($isMain ? [] : [$currentAdminId]);

    $rows = [];
    foreach ($accounts->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $account['team_name'] = trim((string)($account['team_name'] ?? ''));
        $rows[(int)$account['id']] = $account + ['direct' => 0, 'quick' => 0, 'total' => 0];
    }

    foreach (['estimate_direct' => 'direct', 'estimate_quick' => 'quick'] as $table => $type) {
        $stmt = $pdo->prepare("SELECT assigned_admin_id, COUNT(*) AS contract_count FROM $table WHERE status = 'CONTRACTED'" . ($isMain ? '' : ' AND assigned_admin_id = ?') . ' GROUP BY assigned_admin_id');
        $stmt->execute($isMain ? [] : [$currentAdminId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $count) {
            $id = (int)$count['assigned_admin_id'];
            if (!isset($rows[$id])) continue;
            $rows[$id][$type] = (int)$count['contract_count'];
            $rows[$id]['total'] += (int)$count['contract_count'];
        }
    }

    $rows = array_values($rows);
    usort($rows, static function(array $a, array $b): int {
        $teamA = $a['team_name'] !== '' ? $a['team_name'] : 'ZZZZ';
        $teamB = $b['team_name'] !== '' ? $b['team_name'] : 'ZZZZ';
        return strnatcasecmp($teamA, $teamB)
            ?: (($b['total'] <=> $a['total']) ?: ((int)$a['id'] <=> (int)$b['id']));
    });
    return $rows;
}

function contractTeamSummary(array $rows): array {
    $teams = [];
    foreach ($rows as $row) {
        $team = trim((string)($row['team_name'] ?? '')) ?: '미지정';
        if (!isset($teams[$team])) {
            $teams[$team] = ['team_name' => $team, 'members' => 0, 'direct' => 0, 'quick' => 0, 'total' => 0];
        }
        $teams[$team]['members']++;
        $teams[$team]['direct'] += (int)$row['direct'];
        $teams[$team]['quick'] += (int)$row['quick'];
        $teams[$team]['total'] += (int)$row['total'];
    }
    uasort($teams, static function(array $a, array $b): int {
        if ($a['team_name'] === '미지정') return 1;
        if ($b['team_name'] === '미지정') return -1;
        return strnatcasecmp((string)$a['team_name'], (string)$b['team_name']);
    });
    return array_values($teams);
}
