<?php
declare(strict_types=1);

function canEditEstimateDetails(string $role): bool {
    return in_array($role, ['SUPER_ADMIN', 'ADMIN'], true);
}

function estimateEditValues(array $input, bool $quick): array {
    $text = static function (string $key, string $label, int $max, bool $required = false) use ($input): string {
        if (!is_string($input[$key] ?? null)) throw new InvalidArgumentException($label . ' 값을 확인해 주세요.');
        $value = trim($input[$key]);
        if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $max) {
            throw new InvalidArgumentException($label . '은(는) ' . ($required ? '1자 이상 ' : '') . $max . '자 이하로 입력해 주세요.');
        }
        return $value;
    };
    $values = [
        'customer_name' => $text('customer_name', '성함', $quick ? 30 : 80, true),
        'customer_phone' => $text('customer_phone', '연락처', $quick ? 20 : 30, true),
        'product_type' => $text('product_type', '상품', 20),
    ];
    if (!preg_match('/^[0-9+()\s-]+$/', $values['customer_phone']) ||
        strlen(preg_replace('/\D/', '', $values['customer_phone'])) < 9) {
        throw new InvalidArgumentException('연락처를 올바르게 입력해 주세요.');
    }
    if (!in_array($values['product_type'], ['', 'RENT', 'LEASE'], true)) {
        throw new InvalidArgumentException('상품을 확인해 주세요.');
    }
    if ($quick) {
        $values['car_type'] = $text('car_type', '관심 차종', 50);
        $values['monthly_budget'] = $text('monthly_budget', '희망 월 예산', 50);
    } else {
        foreach (['contract_months' => '계약기간', 'annual_mileage' => '연 주행거리', 'monthly_payment' => '월 납입금'] as $key => $label) {
            $value = $text($key, $label, 10);
            if ($value === '') { $values[$key] = null; continue; }
            if (!ctype_digit($value) || (float)$value > 2147483647 || ($key === 'contract_months' && (int)$value < 1)) {
                throw new InvalidArgumentException($label . '을(를) 올바른 정수로 입력해 주세요.');
            }
            $values[$key] = (int)$value;
        }
        $rate = $text('prepayment_rate', '선납률', 6);
        if ($rate !== '' && (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rate) || (float)$rate > 100)) {
            throw new InvalidArgumentException('선납률은 0~100 사이의 값으로 소수점 두 자리까지 입력해 주세요.');
        }
        $values['prepayment_rate'] = $rate === '' ? null : $rate;
        $values['registration_region'] = $text('registration_region', '차량 등록 지역', 100);
    }
    return $values;
}

function loadEstimateVehicleCatalog(PDO $pdo): array {
    return [
        'vehicles' => $pdo->query('SELECT v.id, v.brand_id, v.name, b.name AS brand_name FROM car_vehicles v INNER JOIN car_brands b ON b.id = v.brand_id ORDER BY b.name, v.name, v.id')->fetchAll(PDO::FETCH_ASSOC),
        'trims' => $pdo->query('SELECT id, vehicle_id, name FROM car_trims ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC),
        'colors' => $pdo->query('SELECT id, vehicle_id, name FROM car_colors ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function estimateVehicleEditValues(PDO $pdo, array $input, array $current): array {
    $readId = static function (string $key, bool $required = false) use ($input): ?int {
        $value = $input[$key] ?? null;
        if (!$required && $value === '') return null;
        if (!is_string($value) || !ctype_digit($value) || (float)$value > 2147483647 || (int)$value < 1) {
            throw new InvalidArgumentException('차량·트림·외장색상을 목록에서 선택해 주세요.');
        }
        return (int)$value;
    };
    $vehicleId = $readId('vehicle_id', true);
    $trimId = $readId('trim_id');
    $colorId = $readId('color_id');
    $vehicleQuery = $pdo->prepare('SELECT v.id, v.name, b.name AS brand_name FROM car_vehicles v INNER JOIN car_brands b ON b.id = v.brand_id WHERE v.id = ?');
    $vehicleQuery->execute([$vehicleId]);
    $vehicle = $vehicleQuery->fetch(PDO::FETCH_ASSOC);
    $sameVehicle = $vehicleId === (int)($current['vehicle_id'] ?? 0);
    if (!$vehicle && !$sameVehicle) throw new InvalidArgumentException('선택한 차량을 찾을 수 없습니다.');
    $values = [
        'vehicle_id' => $vehicleId,
        'brand_name' => $vehicle ? $vehicle['brand_name'] : $current['brand_name'],
        'vehicle_name' => $vehicle ? $vehicle['name'] : $current['vehicle_name'],
    ];
    foreach (['trim' => $trimId, 'color' => $colorId] as $kind => $selectedId) {
        $values[$kind . '_id'] = $selectedId;
        $values[$kind . '_name'] = null;
        if ($selectedId === null) continue;
        $query = $pdo->prepare('SELECT name FROM car_' . ($kind === 'trim' ? 'trims' : 'colors') . ' WHERE id = ? AND vehicle_id = ?');
        $query->execute([$selectedId, $vehicleId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $values[$kind . '_name'] = $row['name'];
        } elseif ($sameVehicle && $selectedId === (int)($current[$kind . '_id'] ?? 0)) {
            // Keep historical selections that have since been removed from the catalog.
            $values[$kind . '_name'] = $current[$kind . '_name'] ?? null;
        } else {
            throw new InvalidArgumentException('선택한 트림 또는 외장색상이 해당 차량과 일치하지 않습니다.');
        }
    }
    return $values;
}

function saveEstimateDetails(PDO $pdo, string $table, int $id, string $role, array $input, array $current = []): void {
    if (!canEditEstimateDetails($role)) throw new RuntimeException('관리자만 견적 정보를 수정할 수 있습니다.');
    if (!in_array($table, ['estimate_direct', 'estimate_quick'], true) || $id < 1) {
        throw new InvalidArgumentException('잘못된 견적입니다.');
    }
    $values = estimateEditValues($input, $table === 'estimate_quick');
    if ($table === 'estimate_direct') {
        $values += estimateVehicleEditValues($pdo, $input, $current);
        foreach (['vehicle_id', 'trim_id', 'product_type', 'contract_months', 'prepayment_rate', 'annual_mileage', 'monthly_payment'] as $key) {
            if ((string)($values[$key] ?? '') !== (string)($current[$key] ?? '')) {
                $values['price_id'] = null;
                break;
            }
        }
    }
    $fields = array_map(static fn(string $key): string => "$key = ?", array_keys($values));
    $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute([...array_values($values), $id]);
}
