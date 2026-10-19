<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/database.php';

$rows = $pdo->query("SELECT i.vehicle_id, v.name, i.content FROM car_vehicle_info i JOIN car_vehicles v ON v.id=i.vehicle_id JOIN car_brands b ON b.id=v.brand_id WHERE b.name='제네시스'")->fetchAll();
$updates = [];
$count = 0;
foreach ($rows as $row) {
    $content = json_decode($row['content'], true, 512, JSON_THROW_ON_ERROR);
    $changed = false;
    foreach ($content['options'] as &$option) {
        $title = $option['option_name'];
        if (mb_strlen($title, 'UTF-8') <= 32) continue;
        $details = [];
        $short = preg_replace_callback('/\((?:[^()]++|(?R))*\)/u', static function ($match) use (&$details) {
            $details[] = trim(substr($match[0], 1, -1));
            return '';
        }, $title);
        $short = trim(preg_replace('/\s+/u', ' ', $short));
        if (!$details || $short === '') continue;
        $option['option_name'] = $short;
        $changed = true;
        $count++;
        echo $row['name'] . ': ' . $short . "\n";
    }
    unset($option);
    if ($changed) $updates[] = ['vehicle_id' => $row['vehicle_id'], 'previous' => $row['content'], 'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
}
echo "변경 대상: {$count}개 제목\n";
if (!in_array('--apply', $argv, true) || !$updates) exit;
$backup = tempnam(sys_get_temp_dir(), 'autogenie-genesis-titles-');
if (!$backup || file_put_contents($backup, json_encode($updates, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('백업 실패');
$pdo->beginTransaction();
try {
    $save = $pdo->prepare('UPDATE car_vehicle_info SET content=? WHERE vehicle_id=? AND content=?');
    foreach ($updates as $update) {
        $save->execute([$update['content'], $update['vehicle_id'], $update['previous']]);
        if ($save->rowCount() !== 1) throw new RuntimeException('다른 변경 사항이 있어 중단합니다.');
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
echo "등록 완료. 백업: {$backup}\n";
