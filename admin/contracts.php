<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('contracts');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/contract-performance.php';

$isMain = isSuperAdmin();
$rows = [];
$error = null;
try {
    $rows = contractPerformance($pdo, $isMain, (int)$_SESSION['admin_id']);
} catch (Throwable $e) {
    error_log('Contract performance query failed: ' . $e->getMessage());
    $error = '계약 실적을 불러오지 못했습니다. 견적 DB 연결과 담당자 정보를 확인해주세요.';
}

$view = $isMain ? (string)($_GET['view'] ?? 'team') : 'individual';
if (!in_array($view, ['team', 'individual'], true)) $view = $isMain ? 'team' : 'individual';

$selectedTeam = $isMain ? trim((string)($_GET['team'] ?? '')) : '';
$teamSummary = contractTeamSummary($rows);
$teamNames = array_column($teamSummary, 'team_name');
if ($selectedTeam !== '' && !in_array($selectedTeam, $teamNames, true)) $selectedTeam = '';

$visibleRows = $rows;
if ($isMain && $selectedTeam !== '') {
    $visibleRows = array_values(array_filter($rows, static function(array $row) use ($selectedTeam): bool {
        $team = trim((string)($row['team_name'] ?? '')) ?: '미지정';
        return $team === $selectedTeam;
    }));
}

$visibleTeamSummary = $teamSummary;
if ($isMain && $selectedTeam !== '') {
    $visibleTeamSummary = array_values(array_filter($teamSummary, static function(array $team) use ($selectedTeam): bool {
        return (string)$team['team_name'] === $selectedTeam;
    }));
}

$total = array_sum(array_column($visibleRows, 'total'));
$direct = array_sum(array_column($visibleRows, 'direct'));
$quick = array_sum(array_column($visibleRows, 'quick'));
$maxTeamTotal = max(1, ...array_map(static fn(array $team): int => (int)$team['total'], $visibleTeamSummary ?: [['total' => 0]]));
$maxMemberTotal = max(1, ...array_map(static fn(array $row): int => (int)$row['total'], $visibleRows ?: [['total' => 0]]));

function contractChartScaleMax(int $maxValue): int {
    if ($maxValue <= 5) return 5;
    if ($maxValue <= 10) return 10;
    if ($maxValue <= 20) return (int)(ceil($maxValue / 5) * 5);
    return (int)(ceil($maxValue / 10) * 10);
}

$teamScaleMax = contractChartScaleMax($maxTeamTotal);
$memberScaleMax = contractChartScaleMax($maxMemberTotal);

function contractsUrl(string $view, string $team = ''): string {
    $params = ['view' => $view];
    if ($team !== '') $params['team'] = $team;
    return './contracts.php?' . http_build_query($params);
}
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>계약 실적 - 오토지니</title>
<link rel="stylesheet" href="./sidebar.css?v=<?= filemtime(__DIR__ . '/sidebar.css') ?>">
<link rel="stylesheet" href="./admin-ui.css"><link rel="stylesheet" href="./contracts-page.css?v=<?= filemtime(__DIR__ . '/contracts-page.css') ?>">
</head><body><div class="admin-shell">
<?php $currentAdminPage = 'contracts'; require __DIR__ . '/sidebar.php'; ?>
<main class="main"><section class="card">
    <div class="contract-head">
        <div>
            <h1><?= $isMain ? '영업사원 계약 실적' : '내 계약 실적' ?></h1>
            <p class="description">전체 기간 · 현재 계약완료 상태인 견적을 담당자 기준으로 집계합니다.</p>
        </div>
        <?php if ($isMain): ?><a class="account-link" href="./admins.php">영업사원 팀 설정</a><?php endif; ?>
    </div>

    <?php if ($error !== null): ?>
        <p role="alert" class="error"><?= ag_h($error) ?></p>
    <?php else: ?>

    <?php if ($isMain): ?>
    <div class="performance-view-tabs" role="tablist" aria-label="계약 실적 보기 방식">
        <a href="<?= ag_h(contractsUrl('team')) ?>" class="<?= $view === 'team' ? 'is-active' : '' ?>">팀별 실적</a>
        <a href="<?= ag_h(contractsUrl('individual', $selectedTeam)) ?>" class="<?= $view === 'individual' ? 'is-active' : '' ?>">개인별 실적</a>
    </div>
    <?php endif; ?>

    <?php if ($isMain): ?>
    <nav class="team-tabs" aria-label="계약 실적 팀 필터">
        <a href="<?= ag_h(contractsUrl($view)) ?>" class="<?= $selectedTeam === '' ? 'is-active' : '' ?>">전체 <b><?= number_format(count($rows)) ?></b></a>
        <?php foreach ($teamSummary as $team): ?>
        <a href="<?= ag_h(contractsUrl($view, (string)$team['team_name'])) ?>" class="<?= $selectedTeam === $team['team_name'] ? 'is-active' : '' ?>">
            <?= ag_h((string)$team['team_name']) ?> <b><?= number_format((int)$team['members']) ?></b>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <div class="contract-totals">
        <div><span><?= $isMain ? ($selectedTeam !== '' ? ag_h($selectedTeam) . ' 계약 합계' : '전체 계약 합계') : '내 계약 건수' ?></span><strong><?= number_format($total) ?><small>건</small></strong></div>
        <div><span>차량견적 계약</span><strong><?= number_format($direct) ?><small>건</small></strong></div>
        <div><span>간편견적 계약</span><strong><?= number_format($quick) ?><small>건</small></strong></div>
    </div>

    <?php if ($isMain && $view === 'team'): ?>
    <section class="performance-panel" aria-labelledby="team-performance-heading">
        <div class="section-title-row">
            <h2 id="team-performance-heading"><?= $selectedTeam !== '' ? ag_h($selectedTeam) . ' 팀 실적' : '팀별 계약 실적' ?></h2>
            <div class="chart-key"><span><i class="key-box is-direct"></i>차량견적</span><span><i class="key-box is-quick"></i>간편견적</span></div>
        </div>

        <div class="vertical-chart" aria-label="팀별 계약 실적 세로 막대 차트">
            <div class="chart-y-label">계약 건수</div>
            <div class="vertical-chart-grid">
                <?php foreach ($visibleTeamSummary as $team):
                    $teamTotal = (int)$team['total'];
                    $directCount = (int)$team['direct'];
                    $quickCount = (int)$team['quick'];
                    $directH = ($directCount / $teamScaleMax) * 100;
                    $quickH = ($quickCount / $teamScaleMax) * 100;
                    $totalH = ($teamTotal / $teamScaleMax) * 100;
                ?>
                <a class="column-group" href="<?= ag_h(contractsUrl('individual', (string)$team['team_name'])) ?>" title="<?= ag_h((string)$team['team_name']) ?> 개인별 실적 보기" style="--bar-height:<?= number_format($totalH, 2, '.', '') ?>%">
                    <div class="columns-wrap">
                        <div class="column-value"><?= number_format($teamTotal) ?>건</div>
                        <div class="column-stack">
                            <?php if ($quickCount > 0): ?><div class="column-segment is-quick" style="height:<?= number_format($quickH, 2, '.', '') ?>%"></div><?php endif; ?>
                            <?php if ($directCount > 0): ?><div class="column-segment is-direct" style="height:<?= number_format($directH, 2, '.', '') ?>%"></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="column-label"><strong><?= ag_h((string)$team['team_name']) ?></strong><small><?= number_format((int)$team['members']) ?>명</small></div>
                    <div class="column-meta"><span>차량 <?= number_format($directCount) ?></span><span>간편 <?= number_format($quickCount) ?></span></div>
                </a>
                <?php endforeach; ?>
                <?php if (!$visibleTeamSummary): ?><div class="empty chart-empty">등록된 영업팀이 없습니다.</div><?php endif; ?>
            </div>
        </div>

        <div class="contract-table-wrap"><table>
            <thead><tr><th scope="col">소속 팀</th><th scope="col">영업사원</th><th scope="col">차량견적</th><th scope="col">간편견적</th><th scope="col">계약 합계</th></tr></thead>
            <tbody>
            <?php foreach ($visibleTeamSummary as $team): ?>
                <tr>
                    <th scope="row"><span class="team-name"><?= ag_h((string)$team['team_name']) ?></span></th>
                    <td><?= number_format((int)$team['members']) ?>명</td>
                    <td><?= number_format((int)$team['direct']) ?>건</td>
                    <td><?= number_format((int)$team['quick']) ?>건</td>
                    <td class="total-cell"><strong><?= number_format((int)$team['total']) ?>건</strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$visibleTeamSummary): ?><tr><td colspan="5" class="empty">등록된 영업팀이 없습니다.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>

    <?php if ($view === 'individual'): ?>
    <section class="performance-panel" aria-labelledby="individual-performance-heading">
        <div class="section-title-row">
            <h2 id="individual-performance-heading"><?= $isMain ? ($selectedTeam !== '' ? ag_h($selectedTeam) . ' 개인별 실적' : '개인별 계약 실적') : '개인 계약 실적' ?></h2>
            <div class="chart-key"><span><i class="key-box is-direct"></i>차량견적</span><span><i class="key-box is-quick"></i>간편견적</span></div>
        </div>

        <div class="vertical-chart" aria-label="개인별 계약 실적 세로 막대 차트">
            <div class="chart-y-label">계약 건수</div>
            <div class="vertical-chart-grid is-members">
                <?php foreach ($visibleRows as $row):
                    $rowTotal = (int)$row['total'];
                    $directCount = (int)$row['direct'];
                    $quickCount = (int)$row['quick'];
                    $directH = ($directCount / $memberScaleMax) * 100;
                    $quickH = ($quickCount / $memberScaleMax) * 100;
                    $totalH = ($rowTotal / $memberScaleMax) * 100;
                    $displayName = $row['name'] ?: $row['username'];
                ?>
                <div class="column-group is-static" style="--bar-height:<?= number_format($totalH, 2, '.', '') ?>%">
                    <div class="columns-wrap">
                        <div class="column-value"><?= number_format($rowTotal) ?>건</div>
                        <div class="column-stack">
                            <?php if ($quickCount > 0): ?><div class="column-segment is-quick" style="height:<?= number_format($quickH, 2, '.', '') ?>%"></div><?php endif; ?>
                            <?php if ($directCount > 0): ?><div class="column-segment is-direct" style="height:<?= number_format($directH, 2, '.', '') ?>%"></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="column-label"><strong><?= ag_h($displayName) ?></strong><small><?= $isMain ? ag_h(trim((string)($row['team_name'] ?? '')) ?: '미지정') : ag_h($row['username']) ?></small></div>
                    <div class="column-meta"><span>차량 <?= number_format($directCount) ?></span><span>간편 <?= number_format($quickCount) ?></span></div>
                </div>
                <?php endforeach; ?>
                <?php if (!$visibleRows): ?><div class="empty chart-empty">조회할 영업사원 계정이 없습니다.</div><?php endif; ?>
            </div>
        </div>

        <div class="contract-table-wrap"><table>
            <thead><tr><?php if ($isMain): ?><th scope="col">소속 팀</th><?php endif; ?><th scope="col">영업사원</th><th scope="col">아이디</th><th scope="col">계정 상태</th><th scope="col">차량견적</th><th scope="col">간편견적</th><th scope="col">계약 합계</th></tr></thead>
            <tbody>
            <?php foreach ($visibleRows as $row): ?>
                <tr>
                    <?php if ($isMain): ?><td><span class="team-name"><?= ag_h(trim((string)($row['team_name'] ?? '')) ?: '미지정') ?></span></td><?php endif; ?>
                    <th scope="row"><?= ag_h($row['name'] ?: $row['username']) ?></th>
                    <td><?= ag_h($row['username']) ?></td>
                    <td><span class="account-status <?= $row['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $row['is_active'] ? '재직' : '비활성' ?></span></td>
                    <td><?= number_format($row['direct']) ?>건</td><td><?= number_format($row['quick']) ?>건</td><td class="total-cell"><strong><?= number_format($row['total']) ?>건</strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$visibleRows): ?><tr><td colspan="<?= $isMain ? 7 : 6 ?>" class="empty">조회할 영업사원 계정이 없습니다.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>

    <?php if ($isMain): ?><p class="description foot-note">담당자가 없거나 영업사원 계정에 배정되지 않은 견적은 합계에 포함되지 않습니다. 팀이 지정되지 않은 기존 영업사원은 ‘미지정’으로 표시됩니다.</p><?php endif; ?>
    <?php endif; ?>
</section></main></div></body></html>
