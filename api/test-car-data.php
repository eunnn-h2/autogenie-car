<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/database.php';

try {
    $vehiclesStmt = $pdo->query("
        SELECT
            v.id,
            v.brand_id,
            b.name AS brand_name,
            v.name AS vehicle_name,
            v.model_year,
            v.fuel_type,
            v.base_price,
            v.image_path,
            v.is_best,
            v.is_active,
            v.sort_order
        FROM car_vehicles v
        INNER JOIN car_brands b ON b.id = v.brand_id
        WHERE v.is_active = 1
        ORDER BY b.sort_order ASC, v.sort_order ASC, v.id ASC
    ");

    $vehicles = $vehiclesStmt->fetchAll(PDO::FETCH_ASSOC);

    $colorStmt = $pdo->prepare("
        SELECT
            id,
            vehicle_id,
            name,
            hex_code,
            border_color,
            image_path,
            sort_order,
            is_active
        FROM car_colors
        WHERE vehicle_id = :vehicle_id
          AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");

    $trimStmt = $pdo->prepare("
        SELECT
            id,
            vehicle_id,
            name,
            price,
            description,
            sort_order,
            is_active
        FROM car_trims
        WHERE vehicle_id = :vehicle_id
          AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");

    $priceStmt = $pdo->prepare("
        SELECT
            id,
            vehicle_id,
            trim_id,
            product_type,
            contract_months,
            prepayment_rate,
            annual_mileage,
            monthly_payment,
            is_active
        FROM car_prices
        WHERE vehicle_id = :vehicle_id
          AND trim_id = :trim_id
          AND is_active = 1
        ORDER BY
            product_type ASC,
            contract_months ASC,
            prepayment_rate ASC,
            annual_mileage ASC,
            id ASC
    ");


    // 트림별 차량 옵션. vehicle_options 테이블이 아직 없는 환경에서도
    // 기존 차량/견적 화면은 정상 동작하도록 선택적으로 연결합니다.
    $optionStmt = null;
    try {
        $optionStmt = $pdo->prepare("
            SELECT *
            FROM car_vehicle_options
            WHERE trim_id = :trim_id
            ORDER BY id ASC
        ");
    } catch (Throwable $optionPrepareError) {
        $optionStmt = null;
    }

    foreach ($vehicles as &$vehicle) {
        $vehicleId = (int)$vehicle['id'];

        $colorStmt->execute([':vehicle_id' => $vehicleId]);
        $colors = $colorStmt->fetchAll(PDO::FETCH_ASSOC);

        // vehicles.image_path가 비어 있으면 첫 번째 색상 이미지 사용
        if (empty($vehicle['image_path'])) {
            foreach ($colors as $color) {
                if (!empty($color['image_path'])) {
                    $vehicle['image_path'] = $color['image_path'];
                    break;
                }
            }
        }

        $vehicle['colors'] = $colors;

        $trimStmt->execute([':vehicle_id' => $vehicleId]);
        $trims = $trimStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($trims as &$trim) {
            $trimId = (int)$trim['id'];

            $priceStmt->execute([
                ':vehicle_id' => $vehicleId,
                ':trim_id' => $trimId,
            ]);

            $trim['prices'] = $priceStmt->fetchAll(PDO::FETCH_ASSOC);

            $trim['options'] = [];
            if ($optionStmt) {
                try {
                    $optionStmt->execute([':trim_id' => $trimId]);
                    $trim['options'] = $optionStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $optionLoadError) {
                    $trim['options'] = [];
                }
            }
        }
        unset($trim);

        $vehicle['trims'] = $trims;
    }
    unset($vehicle);

    echo json_encode([
        'success' => true,
        'count' => count($vehicles),
        'vehicles' => $vehicles,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
