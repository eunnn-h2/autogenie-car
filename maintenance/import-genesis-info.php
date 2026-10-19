<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/config/vehicle-info.php';

$models = ['G70', 'G80', 'G80 EV', 'G90', 'GV60 EV', 'GV70', 'GV70 EV', 'GV80'];
$pending = [];
$select = $pdo->prepare('SELECT v.id, i.content FROM car_vehicles v JOIN car_brands b ON b.id=v.brand_id LEFT JOIN car_vehicle_info i ON i.vehicle_id=v.id WHERE b.name=? AND v.name=?');
foreach ($models as $model) {
    $select->execute(['제네시스', $model]);
    $rows = $select->fetchAll();
    if (count($rows) !== 1) throw new RuntimeException("차량 매칭 오류: {$model}");
    $row = $rows[0];
    $relativeDir = 'images/options/제네시스/' . $model;
    $text = file_get_contents($root . '/' . $relativeDir . '/' . $model . '.txt');
    if ($text === false || !mb_check_encoding($text, 'UTF-8')) throw new RuntimeException("메모 읽기 오류: {$model}");
    // Only unindented dash headings start options; package lists are indented.
    $blocks = preg_split('/^-([^\r\n]+)\R/mu', str_replace("\r\n", "\n", $text), -1, PREG_SPLIT_DELIM_CAPTURE);
    $options = [];
    for ($i = 1; $i < count($blocks); $i += 2) {
        $name = trim($blocks[$i]);
        $body = trim($blocks[$i + 1]);
        if (!preg_match('/^이미지:\s*(.+)$/mu', $body, $match)) throw new RuntimeException("이미지 지정 누락: {$model} / {$name}");
        $filename = trim($match[1]);
        if (basename($filename) !== $filename) throw new RuntimeException('이미지 파일명 오류');
        $path = agVehicleInfoImagePath($relativeDir . '/' . $filename);
        $size = is_file($root . '/' . $path) ? getimagesize($root . '/' . $path) : false;
        if (!$size || ($size['mime'] ?? '') !== 'image/webp') throw new RuntimeException("이미지 확인 실패: {$model} / {$filename}");
        $description = trim(preg_replace('/^(?:이미지|소속):[^\n]*\n?/mu', '', $body));
        $description = trim(preg_replace('/^[ \t]*(?:분류|구분|사양[ \t]*구분|적용[ \t]*안내|설명[ \t]*기준|출처)[ \t]*:[^\n]*(?:\n|$)/mu', '', $description));
        if ($description === '') throw new RuntimeException("설명 누락: {$model} / {$name}");
        $options[] = ['option_name' => $name, 'description' => $description, 'image_path' => $path, 'is_active' => 1];
    }
    if (!$options || count($options) > 100) throw new RuntimeException("항목 수 오류: {$model}");
    $existing = $row['content'] === null ? [] : json_decode($row['content'], true, 512, JSON_THROW_ON_ERROR);
    $content = [
        'title' => $existing['title'] ?? '주요 사양 및 옵션',
        'fitment_note' => $existing['fitment_note'] ?? '차종별 주요 사양 소개입니다. 실제 적용 여부와 기본·선택 구분은 연식·트림·패키지에 따라 달라집니다.',
        'hero_image' => $existing['hero_image'] ?? '',
        'options' => $options,
    ];
    $pending[] = ['vehicle_id' => (int)$row['id'], 'model' => $model, 'previous_content' => $row['content'], 'content' => $content];
    echo $model . ': ' . count($options) . "개 항목 및 이미지 확인\n";
}

if (!in_array('--apply', $argv, true)) { echo "검증 완료. --apply로 등록합니다.\n"; exit; }

$backup = tempnam(sys_get_temp_dir(), 'autogenie-genesis-info-');
if ($backup === false || file_put_contents($backup, json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('백업 저장 실패');
echo "백업: {$backup}\n";
$pdo->beginTransaction();
try {
    $save = $pdo->prepare('INSERT INTO car_vehicle_info (vehicle_id, content) VALUES (?, ?) ON DUPLICATE KEY UPDATE content=VALUES(content)');
    $verify = $pdo->prepare('SELECT content FROM car_vehicle_info WHERE vehicle_id=?');
    foreach ($pending as $entry) {
        $json = json_encode($entry['content'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $save->execute([$entry['vehicle_id'], $json]);
        $verify->execute([$entry['vehicle_id']]);
        if ($verify->fetchColumn() !== $json) throw new RuntimeException('저장 검증 실패');
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
echo "8종 등록 및 저장 검증 완료\n";
