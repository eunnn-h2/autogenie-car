<?php
declare(strict_types=1);

require_once __DIR__ . '/vehicle-options.php';

function agVehicleInfoOverrides(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT vehicle_id, content FROM car_vehicle_info')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1146) return [];
        throw $e;
    }
    $result = [];
    foreach ($rows as $row) {
        $content = json_decode($row['content'], true, 512, JSON_THROW_ON_ERROR);
        $result[(int)$row['vehicle_id']] = $content;
    }
    return $result;
}

function agVehicleInfoSave(PDO $pdo, int $vehicleId, array $content): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS car_vehicle_info (
        vehicle_id INT NOT NULL PRIMARY KEY,
        content LONGTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $stmt = $pdo->prepare('INSERT INTO car_vehicle_info (vehicle_id, content) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE content = VALUES(content)');
    $stmt->execute([$vehicleId, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

function agVehicleInfoImagePath(string $path): string
{
    $path = trim($path);
    if (str_starts_with($path, './images/')) {
        $path = substr($path, 2);
    }
    if ($path !== '' && (!preg_match('~^images/[^?#]+\.(?:png|jpe?g|webp|gif)$~iu', $path)
        || str_contains($path, '..') || str_contains($path, '\\'))) {
        throw new InvalidArgumentException('사진 경로는 images/ 아래의 이미지 파일이어야 합니다.');
    }
    return $path;
}
