<?php
$currentAdminPage = $currentAdminPage ?? '';
?>
<aside class="admin-sidebar">
    <div class="admin-sidebar__profile">
        <div class="admin-sidebar__mark">AG</div>
        <div class="admin-sidebar__user">
            <strong>오토지니 관리자</strong>
            <span><?= htmlspecialchars((string)($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? '관리자'), ENT_QUOTES, 'UTF-8') ?></span>
            <small><?= htmlspecialchars(adminRole(), ENT_QUOTES, 'UTF-8') ?></small>
            <a href="./logout.php">로그아웃</a>
        </div>
    </div>

    <nav class="admin-sidebar__nav" aria-label="관리자 메뉴">
        <div class="admin-sidebar__section">
            <p>대시보드</p>
            <a class="<?= $currentAdminPage === 'dashboard' ? 'active' : '' ?>" href="./dashboard.php">운영 현황</a>
        </div>

        <div class="admin-sidebar__section">
            <p>상품</p>
            <a class="<?= $currentAdminPage === 'products' ? 'active' : '' ?>" href="./index.php#product-list">차량 관리</a>
        </div>

        <div class="admin-sidebar__section">
            <p>견적</p>
            <a class="<?= $currentAdminPage === 'estimates' ? 'active' : '' ?>" href="./estimates.php">견적 신청 관리</a>
            <a href="../db-test.html" target="_blank" rel="noopener">사용자 견적 화면</a>
        </div>

        <?php if (isSuperAdmin()): ?>
        <div class="admin-sidebar__section">
            <p>관리자</p>
            <a class="<?= $currentAdminPage === 'admins' ? 'active' : '' ?>" href="./admins.php">관리자 계정 관리</a>
        </div>
        <?php endif; ?>

        <div class="admin-sidebar__section admin-sidebar__section--system">
            <p>시스템</p>
            <a href="http://localhost/phpmyadmin/" target="_blank" rel="noopener">phpMyAdmin</a>
        </div>
    </nav>
</aside>
