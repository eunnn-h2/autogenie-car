<?php
declare(strict_types=1);

function ag_has_vehicle_price_basis(PDO $pdo): bool
{
    $stmt = $pdo->query("SHOW COLUMNS FROM car_vehicles LIKE 'price_basis_product'");
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function ag_validate_vehicle_price_basis($value): string
{
    if (!in_array($value, ['RENT', 'LEASE'], true)) {
        throw new InvalidArgumentException('기준가 상품은 장기렌트 또는 리스를 선택해주세요.');
    }
    return $value;
}

// 기존 DB는 관리자에서 차량을 처음 저장할 때 기본값과 함께 확장합니다.
function ag_ensure_vehicle_price_basis(PDO $pdo): void
{
    if (ag_has_vehicle_price_basis($pdo)) return;
    try {
        $pdo->exec("ALTER TABLE car_vehicles ADD COLUMN price_basis_product VARCHAR(5) NOT NULL DEFAULT 'RENT'");
    } catch (PDOException $e) {
        // 동시에 저장한 다른 요청이 컬럼을 추가한 경우에는 계속 진행합니다.
        if (!ag_has_vehicle_price_basis($pdo)) throw $e;
    }
}
