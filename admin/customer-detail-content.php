<?php
declare(strict_types=1);
if (!isset($detailHash, $csrfToken)) {
    http_response_code(404);
    exit;
}
$customerBackUrl = './customers.php?' . http_build_query(['q' => $q, 'view' => $showDeleted ? 'deleted' : 'active']);
?>
<section class="card ag-page-card customer-detail-heading">
    <div class="top"><h1>고객 상세</h1><a class="btn" href="<?= ag_h($customerBackUrl) ?>">← 목록으로</a></div>
</section>
<?php if (($archiveError ?? '') !== ''): ?><p class="customer-error" role="alert"><?= ag_h($archiveError) ?></p><?php endif; ?>
<?php if ($detailCustomer === null): ?>
<section class="panel"><p><?= $archiveReady ? '고객을 찾을 수 없습니다.' : '고객 정보를 불러오지 못했습니다. 새로고침 후 다시 시도해 주세요.' ?></p></section>
<?php else:
    $row = $detailCustomer;
    $providerLabel = ($row['provider'] ?? '비회원') === '이메일' ? '사이트' : ($row['provider'] ?? '비회원');
    $providerKind = match ($providerLabel) { '카카오' => 'kakao', '사이트' => 'site', default => 'guest' };
    $activities = $row['activities'] ?? [];
    usort($activities, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
?>
<section class="panel customer-profile">
    <div class="customer-profile-identity">
        <div class="customer-avatar" aria-hidden="true"><?= ag_h(mb_substr($row['name'] ?: '고객', 0, 1, 'UTF-8')) ?></div>
        <div class="customer-profile-name">
            <div class="customer-profile-name-line"><h2><?= ag_h($row['name'] ?: '비회원') ?></h2><span class="member-provider member-provider--<?= $providerKind ?>"><?= ag_h($providerLabel) ?></span><?php if ($showDeleted): ?><span class="customer-deleted-label">삭제된 고객</span><?php endif; ?></div>
            <p><?= ag_h($row['phone'] ?: '연락처 미등록') ?></p>
        </div>
    </div>
    <div class="customer-summary">
        <div><span>견적 신청</span><strong><?= number_format((int)$row['estimates']) ?><small>건</small></strong></div>
        <div><span>고객 문의</span><strong><?= number_format((int)$row['inquiries']) ?><small>건</small></strong></div>
    </div>
</section>
<div class="customer-detail-layout">
<section class="panel customer-details-panel">
    <div class="customer-section-heading"><h2>기본 정보</h2></div>
    <dl class="customer-info-grid">
        <div><dt>휴대폰</dt><dd><?= ag_h($row['phone'] ?: '미등록') ?></dd></div>
        <div><dt>최근 활동</dt><dd><?= ag_h($row['latest_at'] ?: '-') ?></dd></div>
        <?php foreach ($row['members'] ?? [] as $member): ?>
        <div><dt>이메일</dt><dd><?= ag_h($member['email'] ?: '미등록') ?></dd></div>
        <div><dt>가입일</dt><dd><?= ag_h($member['created_at']) ?></dd></div>
        <div><dt>최근 로그인</dt><dd><?= ag_h($member['last_login_at'] ?: '-') ?></dd></div>
        <?php endforeach; ?>
        <?php if ($showDeleted): ?><div><dt>삭제일</dt><dd><?= ag_h($row['deleted_at']) ?></dd></div><?php endif; ?>
    </dl>
    <div class="customer-section-heading customer-section-heading--secondary"><h2>상담 요약</h2></div>
    <dl class="customer-info-grid">
        <div><dt>최근 견적</dt><dd><?= ag_h(trim($row['latest_estimate']) ?: '-') ?></dd></div>
        <div><dt>상담 상태</dt><dd><span class="status"><?= ag_h($labels[$row['status']] ?? ($row['status'] ?: '상담 내역 없음')) ?></span></dd></div>
    </dl>
</section>
<section class="panel customer-history-panel">
    <div class="customer-section-heading"><h2>견적·문의 내역</h2><span><?= number_format(count($activities)) ?>건</span></div>
    <div class="customer-activity-list">
    <?php if (!$activities): ?><div class="customer-history-empty"><strong>아직 접수된 내역이 없습니다</strong><p>견적이나 문의가 접수되면 여기에 표시됩니다.</p></div><?php endif; ?>
    <?php foreach ($activities as $activity):
        $isEstimate = $activity['kind'] === 'estimate';
        $activityLabel = $isEstimate ? ($activity['source'] === 'direct' ? '차량견적' : '간편견적') : '고객문의';
        $activityStatus = $isEstimate ? ($labels[$activity['status']] ?? $activity['status']) : ($activity['status'] === 'ANSWERED' ? '답변완료' : '미처리');
        $activityHref = $isEstimate
            ? './estimate-detail.php?' . http_build_query(['type' => $activity['source'], 'id' => (int)$activity['id']])
            : './inquiry-detail.php?' . http_build_query(['id' => (int)$activity['id']]);
    ?>
        <article class="customer-activity customer-activity--<?= $isEstimate ? 'estimate' : 'inquiry' ?>">
            <div class="customer-activity-head"><strong><?= ag_h($activityLabel) ?></strong><span class="status"><?= ag_h($activityStatus) ?></span></div>
            <p><?= ag_h(trim((string)$activity['item']) ?: '-') ?></p>
            <div class="customer-activity-foot"><time><?= ag_h($activity['created_at']) ?></time>
            <?php if (canAccessAdminCategory($isEstimate ? 'estimates' : 'inquiries')): ?><a class="customer-action" href="<?= ag_h($activityHref) ?>">상세 보기</a><?php endif; ?></div>
        </article>
    <?php endforeach; ?>
    </div>
</section>
</div>
<?php if ($isMainAdmin): ?>
<section class="panel customer-management-panel">
    <div><h2>고객 관리</h2><p class="customer-note">목록에서 삭제해도 회원 계정과 견적·문의 기록은 보관되며, 삭제된 고객에서 복원할 수 있습니다.</p></div>
    <form method="post" data-customer-action data-customer-name="<?= ag_h($row['name'] ?: '비회원') ?>">
        <input type="hidden" name="csrf_token" value="<?= ag_h($csrfToken) ?>">
        <input type="hidden" name="customer_hash" value="<?= ag_h($row['customer_hash']) ?>">
        <input type="hidden" name="action" value="<?= $showDeleted ? 'restore' : 'delete' ?>">
        <input type="hidden" name="delete_confirmation" value="">
        <button type="submit" class="customer-action <?= $showDeleted ? '' : 'danger' ?>"><?= $showDeleted ? '복원' : '삭제' ?></button>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>
