<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/config/vehicle-info.php';

$pending = [];
$total = 0;
$missing = 0;
$select = $pdo->prepare('SELECT v.id, i.content FROM car_vehicles v JOIN car_brands b ON b.id=v.brand_id LEFT JOIN car_vehicle_info i ON i.vehicle_id=v.id WHERE b.name=? AND v.name=?');
$files = glob($root . '/images/options/쉐보레/*/*.txt');
if (count($files) !== 2) throw new RuntimeException('차종별 메모 수를 확인해 주세요.');
foreach ($files as $file) {
    $model = basename(dirname($file));
    $select->execute(['쉐보레', $model]);
    $rows = $select->fetchAll();
    if (count($rows) !== 1) throw new RuntimeException("차량 매칭 오류: {$model}");
    $row = $rows[0];
    $text = file_get_contents($file);
    if ($text === false || !mb_check_encoding($text, 'UTF-8')) throw new RuntimeException("메모 읽기 오류: {$model}");
    $blocks = preg_split('/^\[(\d+)\][ \t]*(.+)\n/mu', str_replace("\r\n", "\n", $text), -1, PREG_SPLIT_DELIM_CAPTURE);
    $options = [];
    $withoutImage = 0;
    for ($i = 1; $i < count($blocks); $i += 3) {
        if ((int)$blocks[$i] !== count($options) + 1) throw new RuntimeException("항목 번호 오류: {$model}");
        $name = trim($blocks[$i + 1]);
        $body = trim($blocks[$i + 2]);
        $path = '';
        if (preg_match('/^이미지:[ \t]*(.*)$/mu', $body, $match)) {
            $filename = trim($match[1]);
            if ($filename !== '' && basename($filename) === $filename) {
                $candidate = 'images/options/쉐보레/' . $model . '/' . $filename;
                if (is_file($root . '/' . $candidate)) {
                    $size = getimagesize($root . '/' . $candidate);
                    if (!$size) throw new RuntimeException("이미지 손상: {$candidate}");
                    $path = agVehicleInfoImagePath($candidate);
                }
            }
        }
        if ($path === '') $withoutImage++;
        $description = trim(preg_replace('/^이미지:[^\n]*\n?/mu', '', $body));
        $description = preg_replace('/\A내용:[ \t]*/u', '', $description);
        $description = trim(preg_replace('/^[ \t]*(?:분류|구분|사양[ \t]*구분|적용[ \t]*안내|설명[ \t]*기준|출처|세부[ \t]*사양|이미지[ \t]*(?:구분|크기))[ \t]*:[^\n]*(?:\n|$)/mu', '', $description));
        if (mb_strlen($name, 'UTF-8') > 32) {
            $details = [];
            $short = preg_replace_callback('/\((?:[^()]++|(?R))*\)/u', static function ($match) use (&$details) {
                $details[] = trim(substr($match[0], 1, -1));
                return '';
            }, $name);
            $short = trim(preg_replace('/\s+/u', ' ', $short));
            if ($details && $short !== '') {
                $name = $short;
            }
        }
        if ($name === '' || $description === '' || mb_strlen($name) > 200 || mb_strlen($description) > 5000) throw new RuntimeException("항목 내용 오류: {$model} / {$name}");
        $options[] = ['option_name' => $name, 'description' => $description, 'image_path' => $path, 'image_kind' => preg_match('/^이미지 구분:[ \t]*공식 기능 아이콘/mu', $body) ? 'icon' : 'photo', 'is_active' => 1];
    }
    if (!$options || count($options) > 100) throw new RuntimeException("항목 수 오류: {$model}");
    $existing = $row['content'] === null ? [] : json_decode($row['content'], true, 512, JSON_THROW_ON_ERROR);
    $content = [
        'title' => $existing['title'] ?? '주요 사양 및 옵션',
        'fitment_note' => '차종별 주요 사양 소개입니다. 기본·선택 여부 및 트림별 적용 여부를 확정한 자료가 아닙니다. 실제 적용 여부는 연식·트림·패키지에 따라 달라지므로 해당 차량의 가격표와 확인해 주세요.',
        'hero_image' => $existing['hero_image'] ?? '',
        'options' => $options,
    ];
    $pending[] = ['vehicle_id' => (int)$row['id'], 'model' => $model, 'previous_content' => $row['content'], 'content' => $content];
    $total += count($options);
    $missing += $withoutImage;
    echo "{$model}: " . count($options) . "개 항목, 이미지 없음 {$withoutImage}개\n";
}
echo "총 " . count($pending) . "종 / {$total}개 항목 / 이미지 없음 {$missing}개\n";
if (!in_array('--apply', $argv, true)) exit;

$backup = tempnam(sys_get_temp_dir(), 'autogenie-chevrolet-info-');
if (!$backup || file_put_contents($backup, json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('백업 저장 실패');
echo "백업: {$backup}\n";
$pdo->beginTransaction();
try {
    $save = $pdo->prepare('INSERT INTO car_vehicle_info (vehicle_id, content) VALUES (?, ?) ON DUPLICATE KEY UPDATE content=VALUES(content)');
    $verify = $pdo->prepare('SELECT content FROM car_vehicle_info WHERE vehicle_id=?');
    foreach ($pending as $entry) {
        $verify->execute([$entry['vehicle_id']]);
        $current = $verify->fetchColumn();
        if (($current === false ? null : $current) !== $entry['previous_content']) throw new RuntimeException('동시 수정이 있어 중단합니다.');
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
echo "등록 및 저장 검증 완료\n";
