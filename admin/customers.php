<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('customers');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/customer-archive.php';

$isMainAdmin = isSuperAdmin();
$showDeleted = $isMainAdmin && ($_GET['view'] ?? '') === 'deleted';
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireSuperAdmin();
$_SESSION['customer_csrf'] ??= bin2hex(random_bytes(32));
$csrfToken = $_SESSION['customer_csrf'];
$archiveError = '';
$archiveReady = false;
$archived = [];
try {
    customerArchiveInit($pdo);
    $archived = customerArchiveLoad($pdo);
    $archiveReady = true;
} catch (Throwable $error) {
    error_log('[AutoGenie customer archive] ' . $error->getMessage());
    $archiveError = '고객 삭제 정보를 불러오지 못했습니다. 새로고침 후 다시 시도해 주세요.';
}

$q = trim((string)($_GET['q'] ?? ''));
$map = [];

// 견적/문의 기록은 연락처 기준으로 합칩니다. 회원 연락처가 없으면 이름만으로 회원을 합치지 않습니다.
$phoneKey = static function (string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return preg_match('/^01[016789]\d{7,8}$/', $digits) ? 'phone:' . $digits : '';
};

$addActivity = static function (array $record, string $kind) use (&$map, $phoneKey): void {
    $name = (string)($record['customer_name'] ?? $record['member_name'] ?? '');
    $phone = (string)($record['customer_phone'] ?? $record['member_phone'] ?? '');
    $key = $phoneKey($phone);
    if ($key === '') {
        // 연락처가 없는 기록은 기존처럼 이름 단위로 표시하되, 가입 회원과 자동 병합하지 않습니다.
        $key = 'activity:' . ($name !== '' ? $name : '미상');
    }
    if (!isset($map[$key])) {
        $map[$key] = [
            'name' => '', 'phone' => '', 'estimates' => 0, 'inquiries' => 0,
            'latest_estimate' => '', 'latest_at' => '', 'status' => '', 'provider' => '비회원',
        ];
    }
    $row = &$map[$key];
    $row['activities'][] = $record + ['kind' => $kind];
    if ($name !== '') $row['name'] = $name;
    if ($phone !== '') $row['phone'] = $phone;
    $created = (string)($record['created_at'] ?? '');
    if ($kind === 'estimate') {
        $row['estimates']++;
        if ($created > $row['latest_at']) {
            $row['latest_at'] = $created;
            $row['latest_estimate'] = (string)($record['item'] ?? '');
            $row['status'] = (string)($record['status'] ?? '');
        }
    } else {
        $row['inquiries']++;
        if ($created > $row['latest_at']) $row['latest_at'] = $created;
    }
    unset($row);
};

$estimateQueries = [
    'estimate_direct' => "SELECT id, 'direct' AS source, customer_name, customer_phone, CONCAT(COALESCE(brand_name, ''), ' ', COALESCE(vehicle_name, '')) AS item, status, created_at FROM estimate_direct",
    'estimate_quick' => "SELECT id, 'quick' AS source, customer_name, customer_phone, COALESCE(car_type, '상담 후 결정') AS item, status, created_at FROM estimate_quick",
];
foreach ($estimateQueries as $table => $sql) {
    if (!ag_table_exists($pdo, $table)) continue;
    try {
        foreach ($pdo->query($sql) as $record) $addActivity($record, 'estimate');
    } catch (Throwable $error) {
        error_log('[AutoGenie customers] ' . $table . ': ' . $error->getMessage());
    }
}
if (ag_table_exists($pdo, 'customer_inquiries')) {
    try {
        foreach ($pdo->query('SELECT id, member_name, member_phone, member_email, inquiry_no, message AS item, status, created_at FROM customer_inquiries') as $record) {
            $addActivity($record, 'inquiry');
        }
    } catch (Throwable $error) {
        error_log('[AutoGenie customers] customer_inquiries: ' . $error->getMessage());
    }
}

// 카카오 로그인은 member_accounts에 회원을 생성하고, 별도 테이블에 카카오 계정을 연결합니다.
// 기존에는 이 회원 테이블을 조회하지 않아 신청/문의가 없는 회원이 고객 관리에서 보이지 않았습니다.
if (ag_table_exists($pdo, 'member_accounts')) {
    $kakaoMemberIds = [];
    if (ag_table_exists($pdo, 'member_kakao_accounts')) {
        try {
            foreach ($pdo->query('SELECT member_id FROM member_kakao_accounts') as $link) {
                $kakaoMemberIds[(int)$link['member_id']] = true;
            }
        } catch (Throwable $error) {
            error_log('[AutoGenie customers] member_kakao_accounts: ' . $error->getMessage());
        }
    }
    try {
        $sql = 'SELECT id, name, phone, email, created_at, last_login_at FROM member_accounts';
        foreach ($pdo->query($sql) as $member) {
            $id = (int)$member['id'];
            $rawPhone = (string)$member['phone'];
            $realPhone = str_starts_with($rawPhone, 'KAKAO-') ? '' : $rawPhone;
            $key = $phoneKey($realPhone);
            if ($key === '') $key = 'member:' . $id;
            if (!isset($map[$key])) {
                $map[$key] = [
                    'name' => '', 'phone' => '', 'estimates' => 0, 'inquiries' => 0,
                    'latest_estimate' => '', 'latest_at' => '', 'status' => '', 'provider' => '-',
                ];
            }
            $row = &$map[$key];
            $row['name'] = (string)$member['name'];
            $row['phone'] = $realPhone; // 로그인 내부 식별용 KAKAO- 가상 연락처는 표시하지 않습니다.
            $row['provider'] = isset($kakaoMemberIds[$id]) ? '카카오' : '사이트';
            $row['members'][] = [
                'email' => (string)$member['email'],
                'created_at' => (string)$member['created_at'],
                'last_login_at' => (string)($member['last_login_at'] ?? ''),
            ];
            $activityAt = max((string)$member['created_at'], (string)($member['last_login_at'] ?? ''));
            if ($activityAt > $row['latest_at']) $row['latest_at'] = $activityAt;
            unset($row);
        }
    } catch (Throwable $error) {
        error_log('[AutoGenie customers] member_accounts: ' . $error->getMessage());
    }
}

$customers = [];
foreach ($map as $key => $row) {
    $row['customer_hash'] = hash('sha256', $key);
    $customers[$row['customer_hash']] = $row;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $archiveReady) {
    try {
        customerArchiveApply($pdo, $isMainAdmin, $csrfToken, $_POST, $customers, $archived, (int)$_SESSION['admin_id']);
        $_SESSION['customer_notice'] = $_POST['action'] === 'restore' ? '고객을 복원했습니다.' : '고객을 목록에서 삭제했습니다. 회원 계정과 견적·문의 기록은 보관됩니다.';
        header('Location: ./customers.php?' . http_build_query(['q' => $q, 'view' => $showDeleted ? 'deleted' : 'active']), true, 303);
        exit;
    } catch (RuntimeException $error) {
        $code = $error->getCode();
        http_response_code(in_array($code, [400, 403, 409], true) ? $code : 500);
        $archiveError = in_array($code, [400, 403, 409], true) ? $error->getMessage() : '처리하지 못했습니다. 새로고침 후 다시 시도해 주세요.';
        error_log('[AutoGenie customer archive] ' . $error->getMessage());
    }
}
// Fail closed if archive state cannot be read, so hidden customers do not reappear.
$rows = $archiveReady ? array_values($showDeleted ? $archived : array_diff_key($customers, $archived)) : [];
$detailHash = is_string($_GET['customer'] ?? null) ? $_GET['customer'] : '';
$detailCustomer = null;
if ($detailHash !== '') {
    foreach ($rows as $candidate) {
        if ($candidate['customer_hash'] === $detailHash) {
            $detailCustomer = $customers[$detailHash] ?? $candidate;
            if ($showDeleted) $detailCustomer['deleted_at'] = $candidate['deleted_at'];
            break;
        }
    }
    if ($detailCustomer === null) http_response_code($archiveReady ? 404 : 503);
}
$notice = (string)($_SESSION['customer_notice'] ?? '');
unset($_SESSION['customer_notice']);
if ($q !== '') {
    $rows = array_values(array_filter($rows, static fn(array $row): bool =>
        str_contains($row['name'], $q) || str_contains($row['phone'], $q)
    ));
}
usort($rows, static fn(array $a, array $b): int => strcmp($b['latest_at'], $a['latest_at']));
$labels = ag_status_labels();
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>고객 관리 - 오토지니</title>
    <link rel="stylesheet" href="./sidebar.css?v=<?= filemtime(__DIR__ . '/sidebar.css') ?>">
    <link rel="stylesheet" href="./customers-page.css?v=<?= filemtime(__DIR__ . '/customers-page.css') ?>">
    <link rel="stylesheet" href="./admin-ui.css">
    <?php if ($detailHash !== ''): ?><link rel="stylesheet" href="./customer-detail-page.css?v=<?= filemtime(__DIR__ . '/customer-detail-page.css') ?>"><?php endif; ?>
    <script src="./customers.js" defer></script>
</head>
<body>
<div class="layout">
    <?php $currentAdminPage = 'customers'; require __DIR__ . '/sidebar.php'; ?>
    <main class="main">
        <?php if ($detailHash !== ''): ?>
            <?php require __DIR__ . '/customer-detail-content.php'; ?>
        <?php else: ?>
        <section class="card ag-page-card admin-page-sections">
            <div class="top">
                <div>
                    <h1>고객 관리</h1>
                </div>
                <div class="top-stats"><span class="stat"><?= $showDeleted ? '삭제된 고객' : '통합 고객' ?> <b><?= number_format(count($rows)) ?></b></span></div>
            </div>
            <div class="card admin-page-body">
            <form class="filter-box ag-inline-filter" method="get">
                <?php if ($showDeleted): ?><input type="hidden" name="view" value="deleted"><?php endif; ?>
                <div class="filter-item search">
                    <span class="filter-label">검색어</span>
                    <div class="filter-control">
                        <input type="search" name="q" value="<?= ag_h($q) ?>" placeholder="고객명 / 휴대폰 검색">
                        <button type="submit" class="primary">검색</button>
                        <a class="reset" href="./customers.php<?= $showDeleted ? '?view=deleted' : '' ?>">초기화</a>
                    </div>
                </div>
            </form>
            </div>
        </section>
        <section class="panel">
            <?php if ($isMainAdmin): ?>
                <nav class="customer-views" aria-label="고객 목록 구분">
                    <a href="?<?= ag_h(http_build_query(['q' => $q])) ?>" <?= !$showDeleted ? 'aria-current="page"' : '' ?>>고객 목록</a>
                    <a href="?<?= ag_h(http_build_query(['q' => $q, 'view' => 'deleted'])) ?>" <?= $showDeleted ? 'aria-current="page"' : '' ?>>삭제된 고객</a>
                </nav>
            <?php endif; ?>
            <?php if ($notice !== ''): ?><p class="customer-notice" role="status"><?= ag_h($notice) ?></p><?php endif; ?>
            <?php if ($archiveError !== ''): ?><p class="customer-error" role="alert"><?= ag_h($archiveError) ?></p><?php endif; ?>
            <div class="table-wrap customer-table-wrap">
                <table class="table customer-table customer-compact-table">
                    <thead><tr><th>이름</th><th>휴대폰</th><th>가입경로</th><th>최근 활동</th><th>상세</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="5" class="customer-empty"><?= $archiveReady ? '표시할 고객이 없습니다.' : '고객 목록을 불러오지 못했습니다.' ?></td></tr><?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php $customerHref = './customers.php?' . http_build_query(['customer' => $row['customer_hash'], 'q' => $q, 'view' => $showDeleted ? 'deleted' : 'active']); ?>
                            <td><a class="customer-name-link" href="<?= ag_h($customerHref) ?>"><?= ag_h($row['name'] ?: '비회원') ?></a></td>
                            <td><?= ag_h($row['phone'] ?: '-') ?></td>
                            <?php
                            // Older archived snapshots may still use the previous label.
                            $providerLabel = $row['provider'] === '이메일' ? '사이트' : $row['provider'];
                            $providerKind = match ($providerLabel) {
                                '카카오' => 'kakao',
                                '사이트' => 'site',
                                default => 'guest',
                            };
                            ?>
                            <td><span class="member-provider member-provider--<?= $providerKind ?>"><?= ag_h($providerLabel) ?></span></td>
                            <td><?= ag_h($row['latest_at'] ?: '-') ?></td>
                            <td><a class="customer-action" href="<?= ag_h($customerHref) ?>">상세 보기</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
