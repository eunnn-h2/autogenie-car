<?php
/**
 * 관리자 이전 주소 호환 진입점.
 * 차량 관리 기능은 vehicles.php 하나에서 유지합니다.
 * 기존 index.php 링크 및 POST 요청은 동일한 화면/권한 검사로 처리합니다.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isSalesAdmin()) {
    header('Location: ' . adminLandingPage());
    exit;
}

require __DIR__ . '/vehicles.php';
