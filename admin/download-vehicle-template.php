<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$fileName = '오토지니_차량일괄등록_한장양식.xlsx';
$filePath = __DIR__ . DIRECTORY_SEPARATOR . $fileName;

if (!is_file($filePath)) {
    http_response_code(404);
    exit('엑셀 양식 파일을 찾을 수 없습니다.');
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

readfile($filePath);
exit;
