<?php
$currentAdminPage = $currentAdminPage ?? '';

// 현재 페이지 값이 누락되거나 오래된 키를 사용하는 화면에서도
// 모바일 상단 메뉴에 실제 페이지명이 표시되도록 파일명 기준으로 보정합니다.
$currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$pageAliases = [
    'dashboard.php' => 'dashboard',
    'index.php' => 'dashboard',
    'vehicles.php' => 'vehicles',
    'vehicle-detail.php' => 'vehicles',
    'vehicle-edit.php' => 'vehicles',
    'estimates.php' => 'estimates',
    'estimate-detail.php' => 'estimates',
    'inquiries.php' => 'inquiries',
    'inquiry-detail.php' => 'inquiries',
    'customers.php' => 'customers',
    'customer-detail.php' => 'customers',
    'traffic.php' => 'traffic',
    'account-settings.php' => 'account-settings',
    'contact-tag-colors.php' => 'contact-tag-colors',
    'contracts.php' => 'contracts',
    'admins.php' => 'admins',
];
if ($currentAdminPage === '' || !in_array($currentAdminPage, array_values($pageAliases), true)) {
    $currentAdminPage = $pageAliases[$currentScript] ?? $currentAdminPage;
}
$sidebarItems = [
    ['dashboard', '운영 현황', './dashboard.php', canAccessAdminCategory('dashboard'), '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>'],
    ['vehicles', '차량관리', './vehicles.php', canViewVehicleData(), '<path d="m5 6-2 7v7h3v-3h12v3h3v-7l-2-7zM3 13h18M7 6h10M7 16h.01M17 16h.01"/>'],
    ['estimates', '견적문의', './estimates.php', canAccessAdminCategory('estimates'), '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h6M9 11h6M9 15h3"/>'],
    ['inquiries', '고객문의', './inquiries.php', canAccessAdminCategory('inquiries'), '<path d="M21 11a8 8 0 0 1-8 8H7l-4 3V5a2 2 0 0 1 2-2h8a8 8 0 0 1 8 8Z"/><path d="M7 8h10M7 12h7"/>'],
    ['customers', '고객관리', './customers.php', canAccessAdminCategory('customers'), '<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 4a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-4-5"/>'],
    ['traffic', '유입 분석', './traffic.php', canViewAnalytics(), '<path d="M4 3v18h17M8 16v-4M13 16V8M18 16V5"/>'],
    ['account-settings', '내 계정', './account-settings.php', true, '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>'],
    ['contracts', '계약 실적', './contracts.php', canAccessAdminCategory('contracts'), '<path d="M4 3v18h17M8 16v-4M13 16V8M18 16V5"/>'],
    ['admins', '관리자 계정 관리', './admins.php', canManageAdminAccounts(), '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6z"/><path d="m8 12 3 3 5-6"/>'],
    ['estimate-screen', '사용자 견적 화면', '../db-test.html', !isSalesAdmin(), '<rect x="3" y="3" width="18" height="13" rx="2"/><path d="M8 21h8M12 16v5M8 8h8M8 11h5"/>', true],
    ['database', 'phpMyAdmin', 'http://localhost/phpmyadmin/', !isSalesAdmin(), '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>', true],
];
$sidebarSubItems = [];
if (isSuperAdmin()) {
    $sidebarItems[] = ['contact-tag-colors', '상담 색상', './contact-tag-colors.php', true, ''];
    $sidebarSubItems = array_filter($sidebarItems, static fn(array $item): bool => in_array($item[0], ['admins', 'contracts', 'estimate-screen', 'database', 'contact-tag-colors'], true) && $item[3]);
    $sidebarItems = array_filter($sidebarItems, static fn(array $item): bool => !in_array($item[0], ['admins', 'contracts', 'estimate-screen', 'database', 'contact-tag-colors'], true));
}
$currentSidebarLabel = '관리자 메뉴';
foreach (array_merge(array_values($sidebarItems), array_values($sidebarSubItems)) as $item) {
    if (($item[0] ?? '') === $currentAdminPage) {
        $currentSidebarLabel = (string)($item[1] ?? '관리자 메뉴');
        break;
    }
}
?>
<aside class="admin-sidebar" id="adminSidebar">
  <div class="admin-sidebar__mobile-head">
    <div class="admin-sidebar__brand" aria-label="오토지니 관리자 센터"><strong><span>AUTO</span> GENIE</strong><small>관리자 센터</small></div>
    <button class="admin-sidebar__toggle" type="button" aria-label="관리자 메뉴 열기" aria-controls="adminSidebarMenu" aria-expanded="false">
      <span class="admin-sidebar__current"><?= htmlspecialchars($currentSidebarLabel, ENT_QUOTES, 'UTF-8') ?></span>
      <svg class="admin-sidebar__mobile-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>
  </div>
  <div class="admin-sidebar__dropdown">
  <nav class="admin-sidebar__nav" id="adminSidebarMenu" aria-label="관리자 메뉴">
    <?php foreach ($sidebarItems as $sidebarItem): ?>
      <?php [$sidebarPage, $label, $href, $allowed, $icon] = $sidebarItem; if (!$allowed) continue; ?>
      <a class="admin-sidebar__link<?= $currentAdminPage === $sidebarPage ? ' active' : '' ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $currentAdminPage === $sidebarPage ? ' aria-current="page"' : '' ?><?= !empty($sidebarItem[5]) ? ' target="_blank" rel="noopener"' : '' ?>>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon ?></svg>
        <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
      </a>
    <?php endforeach; ?>
    <?php if ($sidebarSubItems): ?>
      <details class="admin-sidebar__group"<?= in_array($currentAdminPage, ['admins', 'contracts', 'contact-tag-colors'], true) ? ' open' : '' ?>>
        <summary class="admin-sidebar__link">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          <span>관리자 설정</span>
          <svg class="admin-sidebar__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="admin-sidebar__submenu">
          <?php foreach ($sidebarSubItems as $sidebarItem): ?>
            <?php [$sidebarPage, $label, $href] = $sidebarItem; ?>
            <a class="admin-sidebar__link<?= $currentAdminPage === $sidebarPage ? ' active' : '' ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $currentAdminPage === $sidebarPage ? ' aria-current="page"' : '' ?><?= !empty($sidebarItem[5]) ? ' target="_blank" rel="noopener"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </nav>
  <div class="admin-sidebar__profile">
    <div class="admin-sidebar__user"><strong><?= htmlspecialchars((string)($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? '관리자'), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(['SUPER_ADMIN'=>'메인관리자','ADMIN'=>'관리자','SALES'=>'영업사원','VIEWER'=>'조회 전용'][adminRole()] ?? adminRole(), ENT_QUOTES, 'UTF-8') ?></small></div>
    <a class="admin-sidebar__logout" href="./logout.php">로그아웃 <span aria-hidden="true">↗</span></a>
  </div>
  </div>
</aside>
<script src="./admin-delete-guard.js" defer></script>
<script src="./admin-mobile.js" defer></script>
