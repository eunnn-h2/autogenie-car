<?php
declare(strict_types=1);

function estimateRegionProvinces(): array {
    return ['서울', '부산', '대구', '인천', '광주', '대전', '울산', '세종', '경기', '강원', '충북', '충남', '전북', '전남', '경북', '경남', '제주'];
}

function validateEstimateRegion(string $fuel, array $data): ?string {
    if (strtoupper(trim($fuel)) !== 'EV') return null;
    $province = $data['registration_province'] ?? '';
    if (!is_string($province) || !in_array($province, estimateRegionProvinces(), true)) throw new InvalidArgumentException('차량 등록 지역의 시·도를 선택해 주세요.');
    return $province;
}

function ensureEstimateRegionColumn(PDO $pdo): void {
    if ($pdo->query("SHOW COLUMNS FROM estimate_direct LIKE 'registration_region'")->fetch()) return;
    try {
        $pdo->exec('ALTER TABLE estimate_direct ADD COLUMN registration_region VARCHAR(100) NULL');
    } catch (PDOException $e) {
        if (!$pdo->query("SHOW COLUMNS FROM estimate_direct LIKE 'registration_region'")->fetch()) throw $e;
    }
}
