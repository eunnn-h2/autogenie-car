<?php
/** 차량 상세관리 독립 페이지. 실제 CRUD 및 권한 검사는 vehicles.php와 공유합니다. */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['vehicle_id'])) {
    $_GET['vehicle_id'] = (string)($_POST['vehicle_id'] ?? '');
}
if ((int)($_GET['vehicle_id'] ?? 0) <= 0) {
    header('Location: ./vehicles.php');
    exit;
}

define('AUTOGENIE_VEHICLE_DETAIL_PAGE', true);
require __DIR__ . '/vehicles.php';
