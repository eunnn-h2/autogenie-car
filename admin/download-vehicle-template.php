<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAdminCategory('vehicles');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/vehicle-template-example.php';

$fileName = '오토지니_차량일괄등록_한장양식.xlsx';
$filePath = __DIR__ . DIRECTORY_SEPARATOR . $fileName;

if (!is_file($filePath)) {
    http_response_code(404);
    exit('엑셀 양식 파일을 찾을 수 없습니다.');
}

$temporaryPath = null;
try {
    $values = vehicleTemplateExampleValues($pdo);
    if ($values !== null) {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'vehicle-template-');
        if ($temporaryPath === false || !copy($filePath, $temporaryPath)) throw new RuntimeException('엑셀 임시 파일을 만들 수 없습니다.');
        $zip = new ZipArchive();
        if ($zip->open($temporaryPath) !== true) throw new RuntimeException('엑셀 양식을 열 수 없습니다.');
        try { addVehicleTemplateExample($zip, $values); }
        finally { $zip->close(); }
        $filePath = $temporaryPath;
    }
} catch (Throwable $e) {
    if (is_string($temporaryPath) && is_file($temporaryPath)) unlink($temporaryPath);
    error_log('Vehicle template example: ' . $e->getMessage());
    http_response_code(500);
    exit('엑셀 양식 생성에 실패했습니다. 잠시 후 다시 시도해주세요.');
}

$downloadName = rawurlencode($fileName);
$fileSize = filesize($filePath);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="vehicle_bulk_template.xlsx"; filename*=UTF-8\'\'' . $downloadName);
header('Content-Length: ' . (string)$fileSize);
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

try { readfile($filePath); }
finally { if (is_string($temporaryPath) && is_file($temporaryPath)) unlink($temporaryPath); }
exit;
