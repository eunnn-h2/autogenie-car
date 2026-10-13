<?php
declare(strict_types=1);

function validateVehicleCreateRows(array $post): array {
    if (!empty($post['batch_create']) && ($post['batch_complete'] ?? '') !== '1') throw new RuntimeException('입력 항목이 서버의 처리 한도를 초과했습니다. 행 수를 줄인 후 다시 저장해 주세요.');
    $groups = [];
    foreach (['colors', 'trims', 'prices'] as $group) {
        $rows = $post[$group] ?? [];
        if (!is_array($rows) || count($rows) > 100) throw new RuntimeException('각 항목은 최대 100개까지 등록할 수 있습니다.');
        foreach ($rows as $key => $row) {
            if (!preg_match('/^\d+$/', (string)$key) || !is_array($row)) throw new RuntimeException('등록 항목이 올바르지 않습니다.');
            foreach ($row as $value) if (!is_scalar($value)) throw new RuntimeException('입력값이 올바르지 않습니다.');
        }
        $groups[$group] = $rows;
    }
    $number = static function (mixed $value, string $label, float $min = 0, float $max = PHP_INT_MAX, bool $integer = true): void {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < $min || (float)$value > $max || ($integer && (float)$value !== floor((float)$value))) {
            throw new RuntimeException($label . ' 값을 확인해 주세요.');
        }
    };
    foreach ($groups as $rows) foreach ($rows as $row) {
        if (isset($row['sort_order'])) $number($row['sort_order'], '정렬순서');
        if (isset($row['is_active']) && !in_array((string)$row['is_active'], ['0', '1'], true)) throw new RuntimeException('상태 값을 확인해 주세요.');
    }
    foreach (['colors' => '색상', 'trims' => '트림'] as $group => $label) {
        foreach ($groups[$group] as $row) {
            if (trim((string)($row['name'] ?? '')) === '') throw new RuntimeException($label . '명을 입력하거나 빈 행을 제거해 주세요.');
            if ($group === 'trims') $number($row['price'] ?? 0, '트림 차량가');
            if ($group === 'colors') foreach (['hex_code', 'border_color'] as $field) {
                if (!empty($row[$field]) && !preg_match('/^#[0-9a-fA-F]{6}$/', $row[$field])) throw new RuntimeException('색상 코드는 #FFFFFF 형식으로 입력해 주세요.');
            }
        }
    }
    $seen = [];
    foreach ($groups['prices'] as $row) {
        $key = (string)($row['trim_key'] ?? '');
        if (!array_key_exists($key, $groups['trims'])) throw new RuntimeException('가격 조건에 연결할 트림을 선택해 주세요.');
        if (!in_array($row['product_type'] ?? '', ['RENT', 'LEASE'], true)) throw new RuntimeException('가격 상품을 선택해 주세요.');
        $number($row['contract_months'] ?? '', '계약 기간', 1, 120);
        $number($row['prepayment_rate'] ?? 0, '선납금 비율', 0, 100, false);
        $number($row['annual_mileage'] ?? '', '연간 주행거리');
        $number($row['monthly_payment'] ?? '', '월 납입금', 1);
        $identity = implode(':', [$key, $row['product_type'], (int)$row['contract_months'], number_format((float)($row['prepayment_rate'] ?? 0), 2, '.', ''), (int)$row['annual_mileage']]);
        if (isset($seen[$identity])) throw new RuntimeException('같은 트림의 상품·기간·선납금·주행거리 조건이 중복됩니다.');
        $seen[$identity] = true;
    }
    $representative = (string)($post['representative_color_key'] ?? '');
    if ($representative !== '' && !array_key_exists($representative, $groups['colors'])) throw new RuntimeException('대표 이미지 색상을 다시 선택해 주세요.');
    return $groups;
}

// The caller owns the transaction, including the vehicle insert.
function insertVehicleCreateRows(PDO $pdo, int $vehicleId, array $groups, callable $colorImage, string $representative = ''): void {
    $representativePath = null;
    foreach ($groups['colors'] as $key => $row) {
        $path = $colorImage($key) ?: (trim((string)($row['image_path'] ?? '')) ?: null);
        if ((string)$key === $representative) {
            if (!$path) throw new RuntimeException('대표 이미지로 선택한 색상에 사진을 첨부해 주세요.');
            $representativePath = $path;
        }
        $pdo->prepare('INSERT INTO car_colors (vehicle_id, name, hex_code, border_color, image_path, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$vehicleId, trim($row['name']), trim($row['hex_code'] ?? '') ?: null, trim($row['border_color'] ?? '') ?: null, $path, (int)($row['sort_order'] ?? $key), (int)($row['is_active'] ?? 1)]);
    }
    if ($representative !== '') {
        if (!$representativePath) throw new RuntimeException('대표 이미지 색상을 다시 선택해 주세요.');
        $pdo->prepare('UPDATE car_vehicles SET image_path = ? WHERE id = ?')->execute([$representativePath, $vehicleId]);
    }
    $trimIds = [];
    foreach ($groups['trims'] as $key => $row) {
        $pdo->prepare('INSERT INTO car_trims (vehicle_id, name, price, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$vehicleId, trim($row['name']), (int)($row['price'] ?? 0), trim($row['description'] ?? '') ?: null, (int)($row['sort_order'] ?? $key), (int)($row['is_active'] ?? 1)]);
        $trimIds[$key] = (int)$pdo->lastInsertId();
    }
    foreach ($groups['prices'] as $row) {
        $pdo->prepare('INSERT INTO car_prices (vehicle_id, trim_id, product_type, contract_months, prepayment_rate, annual_mileage, monthly_payment, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$vehicleId, $trimIds[$row['trim_key']], $row['product_type'], (int)$row['contract_months'], (float)($row['prepayment_rate'] ?? 0), (int)$row['annual_mileage'], (int)$row['monthly_payment'], (int)($row['is_active'] ?? 1)]);
    }
}
