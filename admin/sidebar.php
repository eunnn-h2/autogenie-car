<?php $currentAdminPage = $currentAdminPage ?? ''; ?>
<aside class="admin-sidebar">
  <div class="admin-sidebar__profile">
    <div class="admin-sidebar__brand"><div class="admin-sidebar__mark" aria-hidden="true">AG</div><div><strong>오토지니</strong><span>관리자 센터</span></div></div>
    <div class="admin-sidebar__user"><strong><?= htmlspecialchars((string)($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? '관리자'), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(['SUPER_ADMIN'=>'메인관리자','ADMIN'=>'관리자','SALES'=>'영업사원','VIEWER'=>'조회 전용'][adminRole()] ?? adminRole(), ENT_QUOTES, 'UTF-8') ?></small></div>
    <a class="admin-sidebar__logout" href="./logout.php">로그아웃 <span aria-hidden="true">↗</span></a>
  </div>
  <nav class="admin-sidebar__nav" aria-label="관리자 메뉴">
    <?php if (canAccessAdminCategory('dashboard')): ?>
    <div class="admin-sidebar__section"><p>대시보드</p><a class="<?= $currentAdminPage==='dashboard'?'active':'' ?>" href="./dashboard.php">운영 현황</a></div>
    <?php endif; ?>
    <?php if (canViewVehicleData()): ?>
      <div class="admin-sidebar__section"><p>차량 데이터</p><a class="<?= $currentAdminPage==='vehicles'?'active':'' ?>" href="./vehicles.php">차량 데이터 관리</a></div>
    <?php endif; ?>
    <?php if (canAccessAdminCategory('estimates') || canAccessAdminCategory('inquiries') || canAccessAdminCategory('customers')): ?>
    <div class="admin-sidebar__section"><p>상담</p><?php if (canAccessAdminCategory('estimates')): ?><a class="<?= $currentAdminPage==='estimates'?'active':'' ?>" href="./estimates.php">견적문의</a><?php endif; ?><?php if (canAccessAdminCategory('inquiries')): ?><a class="<?= $currentAdminPage==='inquiries'?'active':'' ?>" href="./inquiries.php">고객문의</a><?php endif; ?><?php if (canAccessAdminCategory('customers')): ?><a class="<?= $currentAdminPage==='customers'?'active':'' ?>" href="./customers.php">고객관리</a><?php endif; ?></div>
    <?php endif; ?>
    <?php if (canViewAnalytics()): ?>
      <div class="admin-sidebar__section"><p>분석</p><a class="<?= $currentAdminPage==='traffic'?'active':'' ?>" href="./traffic.php">유입 분석</a></div>
    <?php endif; ?>
    <div class="admin-sidebar__section"><p>계정</p><a class="<?= $currentAdminPage==='account-settings'?'active':'' ?>" href="./account-settings.php">내 계정</a><?php if (canManageAdminAccounts()): ?><a class="<?= $currentAdminPage==='admins'?'active':'' ?>" href="./admins.php">관리자 계정 관리</a><?php endif; ?></div>
    <div class="admin-sidebar__section admin-sidebar__section--system"><p>바로가기</p><a href="../db-test.html" target="_blank" rel="noopener">사용자 견적 화면</a><?php if (!isSalesAdmin()): ?><a href="http://localhost/phpmyadmin/" target="_blank" rel="noopener">phpMyAdmin</a><?php endif; ?></div>
  </nav>
</aside>
