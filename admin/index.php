<?php
/**
 * 오토지니 차량 DB 엑셀 업로더
 * - 별도 라이브러리/Composer 없이 XLSX(Office Open XML) 파일을 읽습니다.
 * - 지원 시트(초기등록): brands, vehicles, colors, trims, prices
 * - 지원 시트(업데이트): vehicles_update, colors_update, trims_update, prices_update
 * - 동일 데이터가 있으면 UPDATE, 없으면 INSERT(UPSERT 방식)
 *
 * 요구사항:
 * - PHP ZipArchive 확장 활성화
 * - config/database.php 안에 $pdo(PDO 객체)가 정의되어 있어야 함
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireVehicleEditor();
}


if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('config/database.php에서 $pdo PDO 객체를 찾을 수 없습니다.');
}

// -----------------------------
// XLSX 최소 파서
// -----------------------------
function xlsxColumnToIndex(string $letters): int
{
    $letters = strtoupper($letters);
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

function xlsxReadSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    $dom = new DOMDocument();
    $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $strings = [];
    foreach ($xp->query('//a:si') as $si) {
        $parts = [];
        foreach ($xp->query('.//a:t', $si) as $t) {
            $parts[] = $t->textContent;
        }
        $strings[] = implode('', $parts);
    }
    return $strings;
}

function xlsxReadWorkbookSheets(ZipArchive $zip): array
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        throw new RuntimeException('유효한 XLSX 파일이 아닙니다.');
    }

    $relDom = new DOMDocument();
    $relDom->loadXML($relsXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $relXp = new DOMXPath($relDom);
    $relXp->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $targets = [];
    foreach ($relXp->query('//r:Relationship') as $rel) {
        $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
    }

    $wbDom = new DOMDocument();
    $wbDom->loadXML($workbookXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $wbXp = new DOMXPath($wbDom);
    $wbXp->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wbXp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $sheets = [];
    foreach ($wbXp->query('//a:sheets/a:sheet') as $sheet) {
        $name = $sheet->getAttribute('name');
        $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if (!isset($targets[$rid])) {
            continue;
        }
        $target = $targets[$rid];
        $target = ltrim($target, '/');
        if (!str_starts_with($target, 'xl/')) {
            $target = 'xl/' . $target;
        }
        $sheets[$name] = $target;
    }
    return $sheets;
}

function xlsxReadSheetRows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
{
    $xml = $zip->getFromName($sheetPath);
    if ($xml === false) {
        return [];
    }

    $dom = new DOMDocument();
    $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $rows = [];
    foreach ($xp->query('//a:sheetData/a:row') as $rowNode) {
        $rowNumber = (int)$rowNode->getAttribute('r');
        $row = [];

        foreach ($xp->query('./a:c', $rowNode) as $cell) {
            $ref = $cell->getAttribute('r');
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
            if (!$m) continue;
            $colIndex = xlsxColumnToIndex($m[1]);
            $type = $cell->getAttribute('t');
            $value = null;

            if ($type === 'inlineStr') {
                $parts = [];
                foreach ($xp->query('.//a:is//a:t', $cell) as $t) {
                    $parts[] = $t->textContent;
                }
                $value = implode('', $parts);
            } else {
                $vNode = $xp->query('./a:v', $cell)->item(0);
                if ($vNode !== null) {
                    $raw = $vNode->textContent;
                    if ($type === 's') {
                        $value = $sharedStrings[(int)$raw] ?? '';
                    } elseif ($type === 'b') {
                        $value = $raw === '1' ? 1 : 0;
                    } else {
                        $value = is_numeric($raw) ? (strpos($raw, '.') !== false ? (float)$raw : (int)$raw) : $raw;
                    }
                }
            }
            $row[$colIndex] = $value;
        }

        if ($row) {
            $max = max(array_keys($row));
            $normalized = array_fill(0, $max + 1, null);
            foreach ($row as $i => $v) $normalized[$i] = $v;
            $rows[$rowNumber] = $normalized;
        }
    }
    return $rows;
}

function xlsxLoad(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive 확장이 필요합니다. XAMPP의 php.ini에서 extension=zip을 활성화하세요.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('엑셀 파일을 열 수 없습니다.');
    }

    try {
        $shared = xlsxReadSharedStrings($zip);
        $sheetMap = xlsxReadWorkbookSheets($zip);
        $result = [];
        foreach ($sheetMap as $name => $sheetPath) {
            $result[$name] = xlsxReadSheetRows($zip, $sheetPath, $shared);
        }
        return $result;
    } finally {
        $zip->close();
    }
}

function rowsToRecords(array $rows): array
{
    // 우리가 만든 엑셀은 3행이 컬럼명, 4행부터 데이터
    $header = $rows[3] ?? null;
    if (!$header) return [];

    $header = array_map(fn($v) => trim((string)($v ?? '')), $header);
    $records = [];

    foreach ($rows as $rowNum => $row) {
        if ($rowNum <= 3) continue;

        $record = [];
        $hasValue = false;
        foreach ($header as $i => $key) {
            if ($key === '') continue;
            $value = $row[$i] ?? null;
            if (is_string($value)) $value = trim($value);
            if ($value !== null && $value !== '') $hasValue = true;
            $record[$key] = $value;
        }
        if ($hasValue) $records[] = $record;
    }
    return $records;
}

function nullIfBlank(mixed $v): mixed
{
    return ($v === '' || $v === null) ? null : $v;
}

function intValOr(mixed $v, int $default = 0): int
{
    return ($v === '' || $v === null) ? $default : (int)$v;
}

function floatValOr(mixed $v, float $default = 0): float
{
    return ($v === '' || $v === null) ? $default : (float)$v;
}

function getBrandId(PDO $pdo, string $brandName): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM car_brands WHERE name = ? LIMIT 1');
    $stmt->execute([$brandName]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function getVehicleId(PDO $pdo, string $brandName, string $vehicleName): ?int
{
    $stmt = $pdo->prepare('SELECT v.id FROM car_vehicles v JOIN car_brands b ON b.id=v.brand_id WHERE b.name=? AND v.name=? LIMIT 1');
    $stmt->execute([$brandName, $vehicleName]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function getTrimId(PDO $pdo, string $brandName, string $vehicleName, string $trimName): ?int
{
    $stmt = $pdo->prepare('SELECT t.id FROM car_trims t JOIN car_vehicles v ON v.id=t.vehicle_id JOIN car_brands b ON b.id=v.brand_id WHERE b.name=? AND v.name=? AND t.name=? LIMIT 1');
    $stmt->execute([$brandName, $vehicleName, $trimName]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function importBrands(PDO $pdo, array $rows, array &$log): void
{
    $select = $pdo->prepare('SELECT id FROM car_brands WHERE name=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO car_brands (name, logo_path, origin_type, sort_order, is_active) VALUES (?, ?, ?, ?, ?)');
    $update = $pdo->prepare('UPDATE car_brands SET logo_path=?, origin_type=?, sort_order=?, is_active=? WHERE id=?');

    foreach ($rows as $r) {
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') continue;
        $logo = nullIfBlank($r['logo_path'] ?? null);
        $origin = (string)($r['origin_type'] ?? 'IMPORT');
        $sort = intValOr($r['sort_order'] ?? 0);
        $active = intValOr($r['is_active'] ?? 1, 1);

        $select->execute([$name]);
        $id = $select->fetchColumn();
        if ($id !== false) {
            $update->execute([$logo, $origin, $sort, $active, (int)$id]);
            $log[] = "brands UPDATE: {$name}";
        } else {
            $insert->execute([$name, $logo, $origin, $sort, $active]);
            $log[] = "brands INSERT: {$name}";
        }
    }
}

function importVehicles(PDO $pdo, array $rows, array &$log, bool $partialUpdate = false): void
{
    $select = $pdo->prepare('SELECT v.id FROM car_vehicles v JOIN car_brands b ON b.id=v.brand_id WHERE b.name=? AND v.name=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO car_vehicles (brand_id, name, model_year, fuel_type, base_price, image_path, is_best, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    foreach ($rows as $r) {
        $brandName = trim((string)($r['brand_name'] ?? ''));
        $name = trim((string)($r['name'] ?? ''));
        if ($brandName === '' || $name === '') continue;
        $brandId = getBrandId($pdo, $brandName);
        if (!$brandId) throw new RuntimeException("vehicles: 브랜드를 찾을 수 없습니다 - {$brandName}");

        $select->execute([$brandName, $name]);
        $id = $select->fetchColumn();

        if ($id !== false) {
            $sets = [];
            $params = [];
            $fields = ['model_year','fuel_type','base_price','image_path','is_best','sort_order','is_active'];
            foreach ($fields as $f) {
                $val = $r[$f] ?? null;
                if ($partialUpdate && ($val === null || $val === '')) continue;
                if (in_array($f, ['model_year','base_price','is_best','sort_order','is_active'], true) && $val !== null && $val !== '') $val = (int)$val;
                $sets[] = "{$f}=?";
                $params[] = nullIfBlank($val);
            }
            if ($sets) {
                $params[] = (int)$id;
                $stmt = $pdo->prepare('UPDATE car_vehicles SET '.implode(',', $sets).' WHERE id=?');
                $stmt->execute($params);
            }
            $log[] = "vehicles UPDATE: {$brandName} / {$name}";
        } else {
            $insert->execute([
                $brandId,
                $name,
                nullIfBlank($r['model_year'] ?? null),
                (string)($r['fuel_type'] ?? 'OTHER'),
                intValOr($r['base_price'] ?? 0),
                nullIfBlank($r['image_path'] ?? null),
                intValOr($r['is_best'] ?? 0),
                intValOr($r['sort_order'] ?? 0),
                intValOr($r['is_active'] ?? 1, 1),
            ]);
            $log[] = "vehicles INSERT: {$brandName} / {$name}";
        }
    }
}

function importColors(PDO $pdo, array $rows, array &$log, bool $partialUpdate = false): void
{
    $select = $pdo->prepare('SELECT c.id FROM car_colors c JOIN car_vehicles v ON v.id=c.vehicle_id JOIN car_brands b ON b.id=v.brand_id WHERE b.name=? AND v.name=? AND c.name=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO car_colors (vehicle_id, name, hex_code, border_color, image_path, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');

    foreach ($rows as $r) {
        $brand = trim((string)($r['brand_name'] ?? ''));
        $vehicle = trim((string)($r['vehicle_name'] ?? ''));
        $name = trim((string)($r['name'] ?? ''));
        if ($brand === '' || $vehicle === '' || $name === '') continue;
        $vehicleId = getVehicleId($pdo, $brand, $vehicle);
        if (!$vehicleId) throw new RuntimeException("colors: 차량을 찾을 수 없습니다 - {$brand} / {$vehicle}");

        $select->execute([$brand, $vehicle, $name]);
        $id = $select->fetchColumn();
        if ($id !== false) {
            $sets=[]; $params=[];
            foreach (['hex_code','border_color','image_path','sort_order','is_active'] as $f) {
                $val = $r[$f] ?? null;
                if ($partialUpdate && ($val === null || $val === '')) continue;
                if (in_array($f,['sort_order','is_active'],true) && $val !== null && $val !== '') $val=(int)$val;
                $sets[]="{$f}=?"; $params[]=nullIfBlank($val);
            }
            if ($sets) {
                $params[]=(int)$id;
                $stmt=$pdo->prepare('UPDATE car_colors SET '.implode(',', $sets).' WHERE id=?');
                $stmt->execute($params);
            }
            $log[]="colors UPDATE: {$brand} / {$vehicle} / {$name}";
        } else {
            $insert->execute([
                $vehicleId, $name,
                nullIfBlank($r['hex_code'] ?? null),
                nullIfBlank($r['border_color'] ?? null),
                nullIfBlank($r['image_path'] ?? null),
                intValOr($r['sort_order'] ?? 0),
                intValOr($r['is_active'] ?? 1,1),
            ]);
            $log[]="colors INSERT: {$brand} / {$vehicle} / {$name}";
        }
    }
}

function importTrims(PDO $pdo, array $rows, array &$log, bool $partialUpdate = false): void
{
    $select=$pdo->prepare('SELECT t.id FROM car_trims t JOIN car_vehicles v ON v.id=t.vehicle_id JOIN car_brands b ON b.id=v.brand_id WHERE b.name=? AND v.name=? AND t.name=? LIMIT 1');
    $insert=$pdo->prepare('INSERT INTO car_trims (vehicle_id, name, price, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)');

    foreach ($rows as $r) {
        $brand=trim((string)($r['brand_name'] ?? ''));
        $vehicle=trim((string)($r['vehicle_name'] ?? ''));
        $name=trim((string)($r['name'] ?? ''));
        if ($brand==='' || $vehicle==='' || $name==='') continue;
        $vehicleId=getVehicleId($pdo,$brand,$vehicle);
        if(!$vehicleId) throw new RuntimeException("trims: 차량을 찾을 수 없습니다 - {$brand} / {$vehicle}");

        $select->execute([$brand,$vehicle,$name]);
        $id=$select->fetchColumn();
        if($id!==false){
            $sets=[];$params=[];
            foreach(['price','description','sort_order','is_active'] as $f){
                $val=$r[$f]??null;
                if($partialUpdate && ($val===null || $val==='')) continue;
                if(in_array($f,['price','sort_order','is_active'],true) && $val!==null && $val!=='') $val=(int)$val;
                $sets[]="{$f}=?";$params[]=nullIfBlank($val);
            }
            if($sets){
                $params[]=(int)$id;
                $stmt=$pdo->prepare('UPDATE car_trims SET '.implode(',',$sets).' WHERE id=?');
                $stmt->execute($params);
            }
            $log[]="trims UPDATE: {$brand} / {$vehicle} / {$name}";
        }else{
            $insert->execute([
                $vehicleId,$name,intValOr($r['price']??0),nullIfBlank($r['description']??null),
                intValOr($r['sort_order']??0),intValOr($r['is_active']??1,1)
            ]);
            $log[]="trims INSERT: {$brand} / {$vehicle} / {$name}";
        }
    }
}

function importPrices(PDO $pdo, array $rows, array &$log, bool $partialUpdate = false): void
{
    $select=$pdo->prepare('SELECT id FROM car_prices WHERE trim_id=? AND product_type=? AND contract_months=? AND prepayment_rate=? AND annual_mileage=? LIMIT 1');
    $insert=$pdo->prepare('INSERT INTO car_prices (vehicle_id, trim_id, product_type, contract_months, prepayment_rate, annual_mileage, monthly_payment, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $update=$pdo->prepare('UPDATE car_prices SET vehicle_id=?, monthly_payment=?, is_active=? WHERE id=?');

    foreach($rows as $r){
        $brand=trim((string)($r['brand_name']??''));
        $vehicle=trim((string)($r['vehicle_name']??''));
        $trim=trim((string)($r['trim_name']??''));
        if($brand===''||$vehicle===''||$trim==='') continue;
        $vehicleId=getVehicleId($pdo,$brand,$vehicle);
        $trimId=getTrimId($pdo,$brand,$vehicle,$trim);
        if(!$vehicleId) throw new RuntimeException("prices: 차량을 찾을 수 없습니다 - {$brand} / {$vehicle}");
        if(!$trimId) throw new RuntimeException("prices: 트림을 찾을 수 없습니다 - {$brand} / {$vehicle} / {$trim}");

        $product=(string)($r['product_type']??'RENT');
        $months=intValOr($r['contract_months']??0);
        $prepay=floatValOr($r['prepayment_rate']??0);
        $mileage=intValOr($r['annual_mileage']??0);
        $payment=$r['monthly_payment']??null;
        $active=$r['is_active']??1;

        $select->execute([$trimId,$product,$months,$prepay,$mileage]);
        $id=$select->fetchColumn();
        if($id!==false){
            if (!$partialUpdate || ($payment !== null && $payment !== '')) {
                $update->execute([$vehicleId,intValOr($payment,0),intValOr($active,1),(int)$id]);
            }
            $log[]="prices UPDATE: {$brand} / {$vehicle} / {$trim} / {$product} / {$months}개월 / {$prepay}% / {$mileage}km";
        }else{
            if ($payment === null || $payment === '') {
                throw new RuntimeException("prices 신규 등록에는 monthly_payment가 필요합니다 - {$brand} / {$vehicle} / {$trim}");
            }
            $insert->execute([$vehicleId,$trimId,$product,$months,$prepay,$mileage,intValOr($payment),intValOr($active,1)]);
            $log[]="prices INSERT: {$brand} / {$vehicle} / {$trim} / {$product} / {$months}개월 / {$prepay}% / {$mileage}km";
        }
    }
}

$result = null;
$logs = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['xlsx']) || $_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('엑셀 파일을 선택해주세요.');
        }

        $originalName = $_FILES['xlsx']['name'] ?? '';
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('.xlsx 파일만 업로드할 수 있습니다.');
        }

        $sheets = xlsxLoad($_FILES['xlsx']['tmp_name']);

        $pdo->beginTransaction();

        // 초기등록 시트
        if (isset($sheets['brands'])) importBrands($pdo, rowsToRecords($sheets['brands']), $logs);
        if (isset($sheets['vehicles'])) importVehicles($pdo, rowsToRecords($sheets['vehicles']), $logs, false);
        if (isset($sheets['colors'])) importColors($pdo, rowsToRecords($sheets['colors']), $logs, false);
        if (isset($sheets['trims'])) importTrims($pdo, rowsToRecords($sheets['trims']), $logs, false);
        if (isset($sheets['prices'])) importPrices($pdo, rowsToRecords($sheets['prices']), $logs, false);

        // 업데이트 시트: 빈칸은 기존값 유지
        if (isset($sheets['vehicles_update'])) importVehicles($pdo, rowsToRecords($sheets['vehicles_update']), $logs, true);
        if (isset($sheets['colors_update'])) importColors($pdo, rowsToRecords($sheets['colors_update']), $logs, true);
        if (isset($sheets['trims_update'])) importTrims($pdo, rowsToRecords($sheets['trims_update']), $logs, true);
        if (isset($sheets['prices_update'])) importPrices($pdo, rowsToRecords($sheets['prices_update']), $logs, true);

        if (!$logs) {
            throw new RuntimeException('등록 가능한 시트 또는 데이터가 없습니다.');
        }

        $pdo->commit();
        $result = 'success';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }






// -----------------------------
// 전체DB 체크박스 선택 삭제
// -----------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['crud_action'] ?? '') === 'bulk_delete_vehicles'
) {
    requireVehicleEditor();

    $selectedIds = $_POST['selected_ids'] ?? [];
    if (!is_array($selectedIds)) $selectedIds = [];

    $selectedIds = array_values(array_unique(array_filter(
        array_map('intval', $selectedIds),
        static fn($id) => $id > 0
    )));

    if (!$selectedIds) {
        $crudError = '삭제할 차량을 하나 이상 선택해주세요.';
    } else {
        try {
            $pdo->beginTransaction();

            $ph = implode(',', array_fill(0, count($selectedIds), '?'));

            $stmt = $pdo->prepare("DELETE FROM car_prices WHERE vehicle_id IN ($ph)");
            $stmt->execute($selectedIds);

            $stmt = $pdo->prepare("DELETE FROM car_colors WHERE vehicle_id IN ($ph)");
            $stmt->execute($selectedIds);

            $stmt = $pdo->prepare("
                DELETE FROM car_vehicle_options
                WHERE trim_id IN (
                    SELECT id FROM car_trims WHERE vehicle_id IN ($ph)
                )
            ");
            $stmt->execute($selectedIds);

            $stmt = $pdo->prepare("DELETE FROM car_trims WHERE vehicle_id IN ($ph)");
            $stmt->execute($selectedIds);

            $stmt = $pdo->prepare("DELETE FROM car_vehicles WHERE id IN ($ph)");
            $stmt->execute($selectedIds);

            $pdo->commit();
            $crudMessage = count($selectedIds) . '대의 차량을 삭제했습니다.';
            unset($_GET['vehicle_id']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $crudError = '선택 삭제 실패: ' . $e->getMessage();
        }
    }
}


// -----------------------------
// 전체DB 선택 차량 일괄 변경
// -----------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['crud_action'] ?? '') === 'bulk_update_vehicles'
) {
    requireVehicleEditor();

    $selectedIds = $_POST['selected_ids'] ?? [];
    if (!is_array($selectedIds)) {
        $selectedIds = [];
    }

    $selectedIds = array_values(array_unique(array_filter(
        array_map('intval', $selectedIds),
        static fn($id) => $id > 0
    )));

    $bulkField = (string)($_POST['bulk_field'] ?? '');
    $bulkValue = (string)($_POST['bulk_value'] ?? '');

    if (!$selectedIds) {
        $crudError = '변경할 차량을 하나 이상 선택해주세요.';
    } elseif (!in_array($bulkField, ['is_active', 'brand_id', 'fuel_type'], true)) {
        $crudError = '변경 항목이 올바르지 않습니다.';
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $params = [];

            if ($bulkField === 'is_active') {
                if (!in_array($bulkValue, ['0', '1'], true)) {
                    throw new RuntimeException('상태 값을 선택해주세요.');
                }

                $sql = "UPDATE car_vehicles SET is_active = ? WHERE id IN ($placeholders)";
                $params[] = (int)$bulkValue;
            }

            if ($bulkField === 'brand_id') {
                $brandIdBulk = (int)$bulkValue;

                if ($brandIdBulk <= 0) {
                    throw new RuntimeException('브랜드를 선택해주세요.');
                }

                $check = $pdo->prepare("SELECT COUNT(*) FROM car_brands WHERE id = ?");
                $check->execute([$brandIdBulk]);

                if ((int)$check->fetchColumn() === 0) {
                    throw new RuntimeException('선택한 브랜드가 존재하지 않습니다.');
                }

                $sql = "UPDATE car_vehicles SET brand_id = ? WHERE id IN ($placeholders)";
                $params[] = $brandIdBulk;
            }

            if ($bulkField === 'fuel_type') {
                $allowedFuelTypes = ['GASOLINE','DIESEL','HYBRID','PHEV','EV','LPG','OTHER'];

                if (!in_array($bulkValue, $allowedFuelTypes, true)) {
                    throw new RuntimeException('연료를 선택해주세요.');
                }

                $sql = "UPDATE car_vehicles SET fuel_type = ? WHERE id IN ($placeholders)";
                $params[] = $bulkValue;
            }

            foreach ($selectedIds as $id) {
                $params[] = $id;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $labelMap = [
                'is_active' => '상태',
                'brand_id' => '브랜드',
                'fuel_type' => '연료',
            ];

            $crudMessage = count($selectedIds) . '대의 차량 ' . $labelMap[$bulkField] . '를 일괄 변경했습니다.';
            unset($_GET['vehicle_id']);
        } catch (Throwable $e) {
            $crudError = '일괄 변경 실패: ' . $e->getMessage();
        }
    }
}

// -----------------------------
// 차량 상세 CRUD 처리
// -----------------------------
$crudMessage = null;
$crudError = null;

function nullableInt($value): ?int {
    if ($value === null || $value === '') return null;
    return (int)$value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crud_action'])) {
    $crudAction = (string)$_POST['crud_action'];

    try {
        if ($crudAction === 'update_vehicle') {
            $vehicleIdPost = (int)($_POST['vehicle_id'] ?? 0);
            $stmt = $pdo->prepare("
                UPDATE car_vehicles
                SET name = :name,
                    model_year = :model_year,
                    fuel_type = :fuel_type,
                    base_price = :base_price,
                    image_path = :image_path,
                    is_best = :is_best,
                    sort_order = :sort_order,
                    is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => trim((string)$_POST['name']),
                ':model_year' => nullableInt($_POST['model_year'] ?? null),
                ':fuel_type' => (string)$_POST['fuel_type'],
                ':base_price' => (int)($_POST['base_price'] ?? 0),
                ':image_path' => trim((string)($_POST['image_path'] ?? '')) ?: null,
                ':is_best' => (int)($_POST['is_best'] ?? 0),
                ':sort_order' => (int)($_POST['sort_order'] ?? 0),
                ':is_active' => (int)($_POST['is_active'] ?? 1),
                ':id' => $vehicleIdPost,
            ]);
            $crudMessage = '차량 기본정보를 수정했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'delete_vehicle') {
            $vehicleIdPost = (int)($_POST['vehicle_id'] ?? 0);

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM car_prices WHERE vehicle_id = ?")->execute([$vehicleIdPost]);
            $pdo->prepare("DELETE FROM car_colors WHERE vehicle_id = ?")->execute([$vehicleIdPost]);
            $pdo->prepare("DELETE FROM car_vehicle_options WHERE trim_id IN (SELECT id FROM car_trims WHERE vehicle_id = ?)")->execute([$vehicleIdPost]);
            $pdo->prepare("DELETE FROM car_trims WHERE vehicle_id = ?")->execute([$vehicleIdPost]);
            $pdo->prepare("DELETE FROM car_vehicles WHERE id = ?")->execute([$vehicleIdPost]);
            $pdo->commit();

            $crudMessage = '차량과 연결된 색상·트림·가격 데이터를 삭제했습니다.';
            unset($_GET['vehicle_id']);
        }

        if ($crudAction === 'add_color') {
            $vehicleIdPost = (int)$_POST['vehicle_id'];
            $stmt = $pdo->prepare("
                INSERT INTO car_colors (
                    vehicle_id, name, hex_code, border_color,
                    image_path, sort_order, is_active
                ) VALUES (
                    :vehicle_id, :name, :hex_code, :border_color,
                    :image_path, :sort_order, :is_active
                )
            ");
            $stmt->execute([
                ':vehicle_id' => $vehicleIdPost,
                ':name' => trim((string)$_POST['color_name']),
                ':hex_code' => trim((string)($_POST['hex_code'] ?? '')) ?: null,
                ':border_color' => trim((string)($_POST['border_color'] ?? '')) ?: null,
                ':image_path' => trim((string)($_POST['color_image_path'] ?? '')) ?: null,
                ':sort_order' => (int)($_POST['color_sort_order'] ?? 0),
                ':is_active' => (int)($_POST['color_is_active'] ?? 1),
            ]);
            $crudMessage = '색상을 추가했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'update_color') {
            $vehicleIdPost = (int)$_POST['vehicle_id'];
            $colorId = (int)$_POST['color_id'];
            $stmt = $pdo->prepare("
                UPDATE car_colors
                SET name = :name,
                    hex_code = :hex_code,
                    border_color = :border_color,
                    image_path = :image_path,
                    sort_order = :sort_order,
                    is_active = :is_active
                WHERE id = :id AND vehicle_id = :vehicle_id
            ");
            $stmt->execute([
                ':name' => trim((string)$_POST['color_name']),
                ':hex_code' => trim((string)($_POST['hex_code'] ?? '')) ?: null,
                ':border_color' => trim((string)($_POST['border_color'] ?? '')) ?: null,
                ':image_path' => trim((string)($_POST['color_image_path'] ?? '')) ?: null,
                ':sort_order' => (int)($_POST['color_sort_order'] ?? 0),
                ':is_active' => (int)($_POST['color_is_active'] ?? 1),
                ':id' => $colorId,
                ':vehicle_id' => $vehicleIdPost,
            ]);
            $crudMessage = '색상을 수정했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'delete_color') {
            $vehicleIdPost = (int)$_POST['vehicle_id'];
            $colorId = (int)$_POST['color_id'];
            $pdo->prepare("DELETE FROM car_colors WHERE id = ? AND vehicle_id = ?")->execute([$colorId, $vehicleIdPost]);
            $crudMessage = '색상을 삭제했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'add_trim') {
            $vehicleIdPost = (int)$_POST['vehicle_id'];
            $stmt = $pdo->prepare("
                INSERT INTO car_trims (
                    vehicle_id, name, price, description, sort_order, is_active
                ) VALUES (
                    :vehicle_id, :name, :price, :description, :sort_order, :is_active
                )
            ");
            $stmt->execute([
                ':vehicle_id' => $vehicleIdPost,
                ':name' => trim((string)$_POST['trim_name']),
                ':price' => (int)($_POST['trim_price'] ?? 0),
                ':description' => trim((string)($_POST['trim_description'] ?? '')) ?: null,
                ':sort_order' => (int)($_POST['trim_sort_order'] ?? 0),
                ':is_active' => (int)($_POST['trim_is_active'] ?? 1),
            ]);
            $crudMessage = '트림을 추가했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'update_trim') {
            $vehicleIdPost = (int)$_POST['vehicle_id'];
            $trimId = (int)$_POST['trim_id'];
            $stmt = $pdo->prepare("
                UPDATE car_trims
                SET name = :name,
                    price = :price,
                    description = :description,
                    sort_order = :sort_order,
                    is_active = :is_active
                WHERE id = :id AND vehicle_id = :vehicle_id
            ");
            $stmt->execute([
                ':name' => trim((string)$_POST['trim_name']),
                ':price' => (int)($_POST['trim_price'] ?? 0),
                ':description' => trim((string)($_POST['trim_description'] ?? '')) ?: null,
                ':sort_order' => (int)($_POST['trim_sort_order'] ?? 0),
                ':is_active' => (int)($_POST['trim_is_active'] ?? 1),
                ':id' => $trimId,
                ':vehicle_id' => $vehicleIdPost,
            ]);
            $crudMessage = '트림을 수정했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'delete_trim') {
            $vehicleIdPost = (int)$_POST['vehicle_id'];
            $trimId = (int)$_POST['trim_id'];

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM car_prices WHERE trim_id = ?")->execute([$trimId]);
            $pdo->prepare("DELETE FROM car_vehicle_options WHERE trim_id = ?")->execute([$trimId]);
            $pdo->prepare("DELETE FROM car_trims WHERE id = ? AND vehicle_id = ?")->execute([$trimId, $vehicleIdPost]);
            $pdo->commit();

            $crudMessage = '트림과 연결된 가격 데이터를 삭제했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'add_price') {
            $vehicleIdPost = (int)$_POST['vehicle_id'];
            $trimId = (int)$_POST['price_trim_id'];

            $stmt = $pdo->prepare("
                INSERT INTO car_prices (
                    vehicle_id, trim_id, product_type, contract_months,
                    prepayment_rate, annual_mileage, monthly_payment, is_active
                ) VALUES (
                    :vehicle_id, :trim_id, :product_type, :contract_months,
                    :prepayment_rate, :annual_mileage, :monthly_payment, :is_active
                )
                ON DUPLICATE KEY UPDATE
                    monthly_payment = VALUES(monthly_payment),
                    is_active = VALUES(is_active)
            ");
            $stmt->execute([
                ':vehicle_id' => $vehicleIdPost,
                ':trim_id' => $trimId,
                ':product_type' => (string)$_POST['product_type'],
                ':contract_months' => (int)$_POST['contract_months'],
                ':prepayment_rate' => (float)($_POST['prepayment_rate'] ?? 0),
                ':annual_mileage' => (int)($_POST['annual_mileage'] ?? 0),
                ':monthly_payment' => (int)($_POST['monthly_payment'] ?? 0),
                ':is_active' => (int)($_POST['price_is_active'] ?? 1),
            ]);
            $crudMessage = '가격 조건을 추가하거나 갱신했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'update_price') {
            $vehicleIdPost = (int)$_POST['vehicle_id'];
            $priceId = (int)$_POST['price_id'];

            $stmt = $pdo->prepare("
                UPDATE car_prices
                SET trim_id = :trim_id,
                    product_type = :product_type,
                    contract_months = :contract_months,
                    prepayment_rate = :prepayment_rate,
                    annual_mileage = :annual_mileage,
                    monthly_payment = :monthly_payment,
                    is_active = :is_active
                WHERE id = :id AND vehicle_id = :vehicle_id
            ");
            $stmt->execute([
                ':trim_id' => (int)$_POST['price_trim_id'],
                ':product_type' => (string)$_POST['product_type'],
                ':contract_months' => (int)$_POST['contract_months'],
                ':prepayment_rate' => (float)($_POST['prepayment_rate'] ?? 0),
                ':annual_mileage' => (int)($_POST['annual_mileage'] ?? 0),
                ':monthly_payment' => (int)($_POST['monthly_payment'] ?? 0),
                ':is_active' => (int)($_POST['price_is_active'] ?? 1),
                ':id' => $priceId,
                ':vehicle_id' => $vehicleIdPost,
            ]);
            $crudMessage = '가격 조건을 수정했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'delete_price') {
            $vehicleIdPost = (int)$_POST['vehicle_id'];
            $priceId = (int)$_POST['price_id'];
            $pdo->prepare("DELETE FROM car_prices WHERE id = ? AND vehicle_id = ?")->execute([$priceId, $vehicleIdPost]);
            $crudMessage = '가격 조건을 삭제했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'bulk_update_colors') {
            $vehicleIdPost = (int)($_POST['vehicle_id'] ?? 0);
            $colorIds = $_POST['color_id'] ?? [];
            $colorNames = $_POST['color_name'] ?? [];
            $hexCodes = $_POST['hex_code'] ?? [];
            $borderColors = $_POST['border_color'] ?? [];
            $imagePaths = $_POST['color_image_path'] ?? [];
            $sortOrders = $_POST['color_sort_order'] ?? [];
            $activeStates = $_POST['color_is_active'] ?? [];

            if (!is_array($colorIds) || !$colorIds) {
                throw new RuntimeException('일괄 수정할 색상이 없습니다.');
            }

            $stmt = $pdo->prepare("
                UPDATE car_colors
                SET name = :name,
                    hex_code = :hex_code,
                    border_color = :border_color,
                    image_path = :image_path,
                    sort_order = :sort_order,
                    is_active = :is_active
                WHERE id = :id AND vehicle_id = :vehicle_id
            ");

            $pdo->beginTransaction();

            foreach ($colorIds as $i => $colorId) {
                $stmt->execute([
                    ':name' => trim((string)($colorNames[$i] ?? '')),
                    ':hex_code' => trim((string)($hexCodes[$i] ?? '')) ?: null,
                    ':border_color' => trim((string)($borderColors[$i] ?? '')) ?: null,
                    ':image_path' => trim((string)($imagePaths[$i] ?? '')) ?: null,
                    ':sort_order' => (int)($sortOrders[$i] ?? 0),
                    ':is_active' => (int)($activeStates[$i] ?? 1),
                    ':id' => (int)$colorId,
                    ':vehicle_id' => $vehicleIdPost,
                ]);
            }

            $pdo->commit();

            $crudMessage = count($colorIds) . '개 색상을 일괄 수정했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'bulk_update_trims') {
            $vehicleIdPost = (int)($_POST['vehicle_id'] ?? 0);
            $trimIds = $_POST['trim_id'] ?? [];
            $trimNames = $_POST['trim_name'] ?? [];
            $trimPrices = $_POST['trim_price'] ?? [];
            $trimDescriptions = $_POST['trim_description'] ?? [];
            $sortOrders = $_POST['trim_sort_order'] ?? [];
            $activeStates = $_POST['trim_is_active'] ?? [];

            if (!is_array($trimIds) || !$trimIds) {
                throw new RuntimeException('일괄 수정할 트림이 없습니다.');
            }

            $stmt = $pdo->prepare("
                UPDATE car_trims
                SET name = :name,
                    price = :price,
                    description = :description,
                    sort_order = :sort_order,
                    is_active = :is_active
                WHERE id = :id AND vehicle_id = :vehicle_id
            ");

            $pdo->beginTransaction();

            foreach ($trimIds as $i => $trimId) {
                $stmt->execute([
                    ':name' => trim((string)($trimNames[$i] ?? '')),
                    ':price' => (int)($trimPrices[$i] ?? 0),
                    ':description' => trim((string)($trimDescriptions[$i] ?? '')) ?: null,
                    ':sort_order' => (int)($sortOrders[$i] ?? 0),
                    ':is_active' => (int)($activeStates[$i] ?? 1),
                    ':id' => (int)$trimId,
                    ':vehicle_id' => $vehicleIdPost,
                ]);
            }

            $pdo->commit();

            $crudMessage = count($trimIds) . '개 트림을 일괄 수정했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }

        if ($crudAction === 'bulk_update_prices') {
            $vehicleIdPost = (int)($_POST['vehicle_id'] ?? 0);
            $priceIds = $_POST['price_id'] ?? [];
            $trimIds = $_POST['price_trim_id'] ?? [];
            $productTypes = $_POST['product_type'] ?? [];
            $contractMonths = $_POST['contract_months'] ?? [];
            $prepaymentRates = $_POST['prepayment_rate'] ?? [];
            $annualMileages = $_POST['annual_mileage'] ?? [];
            $monthlyPayments = $_POST['monthly_payment'] ?? [];
            $activeStates = $_POST['price_is_active'] ?? [];

            if (!is_array($priceIds) || !$priceIds) {
                throw new RuntimeException('일괄 수정할 가격 조건이 없습니다.');
            }

            $stmt = $pdo->prepare("
                UPDATE car_prices
                SET trim_id = :trim_id,
                    product_type = :product_type,
                    contract_months = :contract_months,
                    prepayment_rate = :prepayment_rate,
                    annual_mileage = :annual_mileage,
                    monthly_payment = :monthly_payment,
                    is_active = :is_active
                WHERE id = :id AND vehicle_id = :vehicle_id
            ");

            $pdo->beginTransaction();

            foreach ($priceIds as $i => $priceId) {
                $stmt->execute([
                    ':trim_id' => (int)($trimIds[$i] ?? 0),
                    ':product_type' => (string)($productTypes[$i] ?? 'RENT'),
                    ':contract_months' => (int)($contractMonths[$i] ?? 0),
                    ':prepayment_rate' => (float)($prepaymentRates[$i] ?? 0),
                    ':annual_mileage' => (int)($annualMileages[$i] ?? 0),
                    ':monthly_payment' => (int)($monthlyPayments[$i] ?? 0),
                    ':is_active' => (int)($activeStates[$i] ?? 1),
                    ':id' => (int)$priceId,
                    ':vehicle_id' => $vehicleIdPost,
                ]);
            }

            $pdo->commit();

            $crudMessage = count($priceIds) . '개 가격 조건을 일괄 수정했습니다.';
            $_GET['vehicle_id'] = $vehicleIdPost;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $crudError = $e->getMessage();
    }
}


// -----------------------------
// 관리자 목록 / 검색 / 상세
// -----------------------------
$dashboardTables = [
    'brands' => '브랜드',
    'vehicles' => '차량',
    'colors' => '색상',
    'trims' => '트림',
    'prices' => '가격',
];

$dashboardCounts = [];
$dashboardDbError = null;
$brandOptions = [];
$vehicleRows = [];
$vehicleDetail = null;
$detailColors = [];
$detailTrims = [];
$detailPrices = [];

$q = trim((string)($_GET['q'] ?? ''));
$brandId = (int)($_GET['brand_id'] ?? 0);
$fuelType = trim((string)($_GET['fuel_type'] ?? ''));
$active = (string)($_GET['active'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 20, 50, 100], true)) $perPage = 20;
$vehicleId = (int)($_GET['vehicle_id'] ?? 0);

$totalRows = 0;
$totalPages = 1;

try {
    foreach ($dashboardTables as $table => $label) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $dashboardCounts[$table] = (int)$stmt->fetchColumn();
    }

    $brandOptions = $pdo->query("
        SELECT id, name
        FROM car_brands
        ORDER BY sort_order ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $where = [];
    $params = [];

    if ($q !== '') {
        $keyword = '%' . $q . '%';
        $where[] = "(
            v.name LIKE :q_vehicle
            OR b.name LIKE :q_brand
            OR v.fuel_type LIKE :q_fuel
            OR CAST(v.model_year AS CHAR) LIKE :q_year
        )";
        $params[':q_vehicle'] = $keyword;
        $params[':q_brand'] = $keyword;
        $params[':q_fuel'] = $keyword;
        $params[':q_year'] = $keyword;
    }

    if ($brandId > 0) {
        $where[] = "v.brand_id = :brand_id";
        $params[':brand_id'] = $brandId;
    }

    if ($fuelType !== '') {
        $where[] = "v.fuel_type = :fuel_type";
        $params[':fuel_type'] = $fuelType;
    }

    if ($active === '1' || $active === '0') {
        $where[] = "v.is_active = :active";
        $params[':active'] = (int)$active;
    }

    $whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";

    $countSql = "
        SELECT COUNT(*)
        FROM car_vehicles v
        JOIN car_brands b ON b.id = v.brand_id
        {$whereSql}
    ";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalRows = (int)$stmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $perPage;

    $vehicleSql = "
        SELECT
            v.id,
            b.name AS brand_name,
            v.name,
            v.model_year,
            v.fuel_type,
            v.base_price,
            v.image_path,
            v.is_best,
            v.is_active,
            v.sort_order,
            v.created_at,
            (SELECT COUNT(*) FROM car_colors c WHERE c.vehicle_id = v.id) AS color_count,
            (SELECT COUNT(*) FROM car_trims t WHERE t.vehicle_id = v.id) AS trim_count,
            (SELECT COUNT(*) FROM car_prices p WHERE p.vehicle_id = v.id) AS price_count
        FROM car_vehicles v
        JOIN car_brands b ON b.id = v.brand_id
        {$whereSql}
        ORDER BY v.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($vehicleSql);
    $stmt->execute($params);
    $vehicleRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($vehicleId > 0) {
        $stmt = $pdo->prepare("
            SELECT v.*, b.name AS brand_name
            FROM car_vehicles v
            JOIN car_brands b ON b.id = v.brand_id
            WHERE v.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $vehicleId]);
        $vehicleDetail = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($vehicleDetail) {
            $stmt = $pdo->prepare("
                SELECT id, name, hex_code, border_color, image_path, sort_order, is_active
                FROM car_colors
                WHERE vehicle_id = :vehicle_id
                ORDER BY sort_order ASC, id ASC
            ");
            $stmt->execute([':vehicle_id' => $vehicleId]);
            $detailColors = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT id, name, price, description, sort_order, is_active
                FROM car_trims
                WHERE vehicle_id = :vehicle_id
                ORDER BY sort_order ASC, id ASC
            ");
            $stmt->execute([':vehicle_id' => $vehicleId]);
            $detailTrims = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    t.name AS trim_name,
                    p.product_type,
                    p.contract_months,
                    p.prepayment_rate,
                    p.annual_mileage,
                    p.monthly_payment,
                    p.is_active
                FROM car_prices p
                JOIN car_trims t ON t.id = p.trim_id
                WHERE p.vehicle_id = :vehicle_id
                ORDER BY
                    t.sort_order ASC,
                    p.product_type ASC,
                    p.contract_months ASC,
                    p.prepayment_rate ASC,
                    p.annual_mileage ASC
            ");
            $stmt->execute([':vehicle_id' => $vehicleId]);
            $detailPrices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $dashboardDbError = $e->getMessage();
}

function adminQuery(array $overrides = []): string {
    global $q, $brandId, $fuelType, $active, $page, $perPage;
    $base = [
        'q' => $q !== '' ? $q : null,
        'brand_id' => $brandId > 0 ? $brandId : null,
        'fuel_type' => $fuelType !== '' ? $fuelType : null,
        'active' => $active !== '' ? $active : null,
        'page' => $page,
        'per_page' => $perPage,
    ];
    $merged = array_merge($base, $overrides);
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '') unset($merged[$k]);
    }
    return http_build_query($merged);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>오토지니 관리자</title>
<link rel="stylesheet" href="./sidebar.css">
<style>
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif;background:#eef5f8;color:#25384a;font-size:13px}a{text-decoration:none;color:inherit}.admin-layout{display:grid;grid-template-columns:228px minmax(0,1fr);min-height:100vh}.sidebar{position:sticky;top:0;height:100vh;background:#fff;border-right:1px solid #dbe4e9;padding:18px 14px 24px;display:flex;flex-direction:column;gap:14px}.logo-area{height:auto;display:flex;align-items:center;gap:10px;border-bottom:1px solid #edf1f3;margin-bottom:0;padding-bottom:18px}.logo-mark{width:38px;height:38px;border-radius:10px;background:#29bed1;color:#fff;display:grid;place-items:center;font-weight:800}.logo-area strong,.logo-area span{display:block}.logo-area strong{font-size:15px}.logo-area span{font-size:11px;color:#93a3ad;margin-top:2px}.menu-section{margin-bottom:0}.menu-section>p{font-size:11px;font-weight:800;color:#72838f;margin:0 0 10px;letter-spacing:.02em}.menu-item{display:flex;align-items:flex-start;gap:10px;padding:10px 11px;color:#5c7080;border-radius:10px;border:1px solid transparent;transition:.15s}.menu-item + .menu-item{margin-top:6px}.menu-item:hover{background:#f2f6f9;border-color:#e0e7ec}.menu-item.active{background:#3924b9;color:#fff;border-color:#3924b9;box-shadow:0 8px 18px rgba(57,36,185,.18)}.menu-item.active .menu-icon{background:rgba(255,255,255,.16);color:#fff}.menu-item.active small{color:rgba(255,255,255,.82)}.menu-icon{flex:0 0 34px;width:34px;height:34px;border-radius:10px;background:#eef2f7;color:#3924b9;display:grid;place-items:center;font-size:11px;font-weight:800}.menu-text{display:block;min-width:0}.menu-text strong{display:block;font-size:13px;line-height:1.3}.menu-text small{display:block;margin-top:3px;font-size:11px;line-height:1.45;color:#81919b}.admin-menu{display:grid;gap:14px}.admin-menu-group{padding:12px;border:1px solid #e5ebef;border-radius:14px;background:#fbfcfd}.admin-menu-group.current{border-color:#cfc9f4;background:#f7f5ff;box-shadow:0 0 0 1px rgba(57,36,185,.04) inset}.sidebar-stats{margin-top:auto;background:#f6f9fb;border:1px solid #e5ecef;border-radius:12px;padding:10px}.sidebar-stats div{display:flex;justify-content:space-between;padding:5px}.sidebar-stats span{color:#7a8a94}.main{min-width:0}.page-header{height:56px;background:#fff;border-bottom:1px solid #dfe8ec;padding:9px 16px;display:flex;align-items:center}.page-header h1{display:inline;margin:0;color:#3822b9;font-size:18px}.page-header p{display:inline;margin-left:7px;color:#3822b9}.admin-card{margin:28px 14px;background:#fff;border:1px solid #d8e2e7;border-radius:4px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.02)}.card-title{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:16px}.card-title h2{margin:0;font-size:17px;font-weight:500}.card-title p{margin:4px 0 0;color:#a2b2bd}.card-title p strong{color:#6c8190}.new-btn{background:#2499ef;color:#fff;padding:10px 14px;border-radius:2px;font-weight:700}.filter-panel{border-top:1px solid #eef2f4;padding-top:14px}.filter-top{display:flex;gap:9px;align-items:end;flex-wrap:wrap}.filter-group{width:180px}.filter-group label,.keyword-group label{display:block;color:#82939e;font-size:11px;margin-bottom:5px}.filter-group select,.keyword-row select,.keyword-row input{height:36px;border:1px solid #bfcbd2;background:#fff;padding:0 10px;color:#657784}.keyword-group{margin-left:auto;min-width:520px}.keyword-row{display:flex}.keyword-row select,.keyword-row input{border-radius:0}.search-type{width:76px}.per-page{width:88px}.keyword-row input{flex:1;min-width:180px}.search-btn{height:36px;border:0;background:#24bfd1;color:#fff;font-weight:700;padding:0 18px}.filter-actions{text-align:right;margin-top:8px}.filter-actions a{color:#8fa0aa;font-size:11px}.table-wrap{overflow-x:auto;border-top:1px solid #dce4e8;margin-top:14px}.admin-table{border-collapse:collapse;width:100%;min-width:1220px;font-size:12px}.admin-table th,.admin-table td{height:41px;padding:7px 9px;border-right:1px solid #e5eaed;border-bottom:1px solid #dfe5e8;text-align:center;white-space:nowrap}.admin-table th{background:#fff;color:#526875;font-weight:600}.admin-table tbody tr:nth-child(odd){background:#f2f4f7}.admin-table tbody tr:nth-child(even){background:#fff}.admin-table tbody tr:hover{background:#eaf6fb}.admin-table .number{color:#315cff}.admin-table .vehicle-name{color:#284fdb}.check{width:36px}.status{display:inline-block;padding:4px 7px;border-radius:3px;font-weight:800;font-size:11px}.status-active{background:#1f2937;color:#fff}.status-off{background:#ee293d;color:#fff}.best{display:inline-block;margin-left:5px;padding:2px 5px;background:#ffedd5;color:#9a3412;border-radius:3px;font-size:9px}.detail-btn{color:#2261ee;font-weight:700}.empty{height:90px!important;color:#99a9b2}.pagination{display:flex;justify-content:center;align-items:center;margin:14px 0 0}.pagination a{min-width:31px;height:31px;padding:0 8px;border:1px solid #cbd5db;border-right:0;display:flex;align-items:center;justify-content:center;background:#fff;color:#60727d}.pagination a:last-child{border-right:1px solid #cbd5db}.pagination a.active{background:#5e6c77;color:#fff}.gray-btn{padding:8px 12px;border:1px solid #c8d2d8;background:#fff;color:#687b87}.detail-top{display:grid;grid-template-columns:340px 1fr;gap:16px}.preview{border:1px solid #dfe6ea;background:#fafcfd;min-height:220px;display:grid;place-items:center}.preview img{max-width:100%;height:220px;object-fit:contain}.preview span{color:#a1b0b8}.info-table{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #dfe5e8;border-left:1px solid #dfe5e8}.info-table div{display:grid;grid-template-columns:110px 1fr;border-right:1px solid #dfe5e8;border-bottom:1px solid #dfe5e8}.info-table span{background:#f5f7f9;color:#667986;padding:12px}.info-table b{padding:12px;font-weight:600}.detail-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}.detail-box{border:1px solid #dfe6ea;padding:14px}.detail-box h3{margin:0 0 12px;font-size:14px}.detail-box h3 em{font-style:normal;color:#2aaec0}.line-item{display:flex;align-items:center;gap:8px;padding:9px;border-bottom:1px solid #eef1f3}.line-item.split{justify-content:space-between}.color-dot{width:17px;height:17px;border-radius:50%;border:1px solid}.empty-small{color:#9aabb4;text-align:center;padding:20px}.price-box{margin-top:16px}.payment{font-weight:700}.import-flow{padding:12px;background:#f5f8fa;border:1px solid #e4ebef;display:flex;gap:8px;align-items:center;flex-wrap:wrap}.import-flow span{font-weight:700}.upload-form{margin-top:15px}.upload-box{border:2px dashed #c7d5dc;min-height:115px;display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;background:#fbfdfe}.upload-box strong{width:100%;text-align:center}.upload-box span{color:#84959f}.upload-btn{width:100%;margin-top:10px;height:44px;border:0;background:#162030;color:#fff;font-weight:800}.alert{margin:15px 0;padding:12px;border-radius:3px}.alert strong,.alert span{display:block}.alert span{margin-top:4px}.alert.success{background:#edf9f2;border:1px solid #bde8ca;color:#16733a}.alert.error{background:#fff2f2;border:1px solid #ffb9b9;color:#b01625}.log{background:#17202b;color:#dce6eb;padding:12px;max-height:300px;overflow:auto}.footer{padding:0 16px 24px;color:#8da0ab}.footer code{background:#e8eef1;padding:2px 5px}@media(max-width:1050px){.admin-layout{grid-template-columns:1fr}.sidebar{position:static;height:auto}.keyword-group{margin-left:0;min-width:100%;width:100%}.detail-top,.detail-columns{grid-template-columns:1fr}}@media(max-width:650px){.filter-group{width:100%}.keyword-row{flex-wrap:wrap}.keyword-row>*{width:100%!important;flex:auto!important}.detail-top{grid-template-columns:1fr}.info-table{grid-template-columns:1fr}.admin-card{margin:14px 8px}}


.crud-alert{margin:0 14px 14px;padding:12px 14px;border-radius:4px}.crud-alert.ok{background:#edf9f2;border:1px solid #bde8ca;color:#16733a}.crud-alert.error{background:#fff2f2;border:1px solid #ffb9b9;color:#b01625}.crud-toolbar{display:flex;gap:8px;align-items:center}.danger-btn{padding:8px 12px;border:0;background:#ef3340;color:#fff;cursor:pointer}.edit-btn,.add-btn,.save-btn,.small-btn{border:0;cursor:pointer;font-weight:700}.edit-btn,.add-btn{padding:8px 12px;background:#25bcd0;color:#fff}.save-btn{padding:8px 13px;background:#3924b9;color:#fff}.small-btn{padding:5px 8px;background:#eef2f5;color:#4b6270}.small-btn.delete{background:#fff1f1;color:#d02c38}.crud-section{margin-top:16px;border:1px solid #dfe6ea}.crud-section-head{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f5f8fa;border-bottom:1px solid #dfe6ea}.crud-section-head h3{margin:0;font-size:14px}.crud-form{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:10px;padding:12px}.crud-form .wide{grid-column:span 2}.crud-form label{font-size:11px;color:#748792;display:block;margin-bottom:4px}.crud-form input,.crud-form select,.crud-form textarea{width:100%;min-height:35px;border:1px solid #c9d3d9;padding:7px 9px;background:#fff}.crud-form textarea{min-height:68px;resize:vertical}.crud-actions{grid-column:1/-1;display:flex;gap:8px;justify-content:flex-end}.crud-list{padding:0 12px 12px}.crud-row{display:grid;grid-template-columns:minmax(160px,1fr) repeat(4,minmax(90px,.6fr)) auto;gap:8px;align-items:end;padding:10px 0;border-bottom:1px solid #edf1f3}.crud-row.color-row{grid-template-columns:minmax(160px,1fr) 110px 110px minmax(230px,1.4fr) 80px 90px auto}.crud-row.price-row{grid-template-columns:minmax(130px,1fr) 100px 90px 90px 110px 120px 90px auto}.crud-row label{font-size:10px;color:#82939e;display:block;margin-bottom:3px}.crud-row input,.crud-row select{width:100%;height:33px;border:1px solid #cbd5db;padding:0 7px;background:#fff}.crud-row-actions{display:flex;gap:5px;align-items:center}.vehicle-edit-form{margin-top:16px;border:1px solid #dfe6ea;background:#fbfdfe;padding:12px}.vehicle-edit-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.vehicle-edit-grid label{display:block;font-size:11px;color:#748792;margin-bottom:4px}.vehicle-edit-grid input,.vehicle-edit-grid select{width:100%;height:36px;border:1px solid #c8d3d9;padding:0 8px}.vehicle-edit-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}@media(max-width:1100px){.crud-form,.vehicle-edit-grid{grid-template-columns:repeat(2,1fr)}.crud-row,.crud-row.color-row,.crud-row.price-row{grid-template-columns:repeat(2,1fr)}.crud-row-actions{grid-column:1/-1}}@media(max-width:650px){.crud-form,.vehicle-edit-grid,.crud-row,.crud-row.color-row,.crud-row.price-row{grid-template-columns:1fr}.crud-form .wide{grid-column:auto}}


.admin-user-area strong,.admin-user-area span,.admin-user-area a{display:block}
.admin-user-area span{margin-top:2px;font-size:11px;color:#8da0ab}
.admin-user-area .logout{margin-top:5px;font-size:11px;color:#25bcd0;text-decoration:none}
.admin-user-area .logout:hover{text-decoration:underline}

.admin-user-area .role{display:inline-block;margin-top:4px;padding:2px 6px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:10px;font-weight:800}

.filter-top{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;flex-wrap:wrap}
.filter-left-group{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}
.filter-left-group .filter-group{width:145px}
.keyword-group{margin-left:auto;min-width:520px}
.bulk-action-bar{display:flex;align-items:center;justify-content:space-between;margin-top:12px;padding:8px 10px;border:1px solid #dfe7eb;background:#f7f9fb}
.bulk-selection{font-size:12px;color:#647985}
.bulk-delete-btn{border:0;background:#e93442;color:#fff;padding:8px 13px;font-weight:800;cursor:pointer}
.bulk-delete-btn:disabled{opacity:.4;cursor:not-allowed}
.admin-table input[type="checkbox"]{width:15px;height:15px;cursor:pointer}
@media(max-width:1100px){.keyword-group{margin-left:0;min-width:100%;width:100%}}


/* 필터 간격 최종 보정 */
.filter-top{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
}
.filter-left-group{
    display:flex;
    align-items:flex-end;
    gap:6px;
    flex-wrap:nowrap;
}
.filter-left-group .filter-group{
    width:112px;
    flex:0 0 112px;
}
.filter-left-group .filter-group label{
    margin-bottom:4px;
}
.filter-left-group .filter-group select{
    width:100%;
}
.keyword-group{
    margin-left:auto;
}
@media(max-width:900px){
    .filter-left-group{
        flex-wrap:wrap;
    }
    .keyword-group{
        width:100%;
        min-width:0;
        margin-left:0;
    }
}


.vehicle-name-link{
    color:#2457e6;
    font-weight:700;
    text-decoration:none;
}
.vehicle-name-link:hover{
    text-decoration:underline;
}


.bulk-tools{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:8px;
    flex-wrap:wrap;
}
.bulk-update-form{
    display:flex;
    align-items:center;
    gap:6px;
}
.bulk-update-form select{
    height:34px;
    min-width:118px;
    border:1px solid #c8d4da;
    background:#fff;
    padding:0 8px;
    color:#516772;
}
.bulk-change-btn{
    height:34px;
    border:0;
    background:#25bcd0;
    color:#fff;
    padding:0 13px;
    font-weight:800;
    cursor:pointer;
}
.bulk-change-btn:disabled{
    opacity:.4;
    cursor:not-allowed;
}
@media(max-width:800px){
    .bulk-action-bar{
        align-items:flex-start;
        gap:8px;
        flex-direction:column;
    }
    .bulk-tools{
        width:100%;
        justify-content:flex-start;
    }
}


.bulk-save-btn{
    border:0;
    background:#25bcd0;
    color:#fff;
    padding:9px 14px;
    font-weight:800;
    cursor:pointer;
    border-radius:0;
    white-space:nowrap;
}
.bulk-save-btn:hover{opacity:.92}
.bulk-help{
    margin:10px 0 12px;
    padding:9px 12px;
    border:1px solid #dce7ec;
    background:#f7fbfd;
    color:#60737f;
    font-size:12px;
}
.hidden-bulk-form{display:none}

</style>
<style>

@media(max-width:900px){
    html,body{overflow-x:hidden}
    .admin-layout{display:block;min-height:100dvh}
    .sidebar{
        position:static;
        width:100%;
        height:auto;
        padding:0 10px 10px;
        border-right:0;
        border-bottom:1px solid #dbe4e9;
    }
    .logo-area{height:auto;margin-bottom:0;padding-bottom:14px}
    .admin-menu{gap:10px}
    .admin-menu-group{padding:10px}
    .menu-section{margin-bottom:0}
    .menu-section>p{margin-top:0}
    .menu-item{padding:9px 10px}
    .menu-icon{flex-basis:32px;width:32px;height:32px}
    .menu-text strong{font-size:12px}
    .menu-text small{font-size:10px}
    .sidebar-stats{display:none}
    .page-header{
        height:auto;
        min-height:50px;
        padding:10px 12px;
        flex-wrap:wrap;
    }
    .page-header h1{font-size:16px}
    .page-header p{font-size:11px;margin-left:5px}
    .admin-card{margin:10px 8px;padding:12px}
    .card-title{display:block;margin-bottom:12px}
    .card-title h2{font-size:16px}
    .card-title p{font-size:11px;line-height:1.45}
    .new-btn,.gray-btn{display:inline-flex;margin-top:8px}
    .filter-top{display:grid;grid-template-columns:1fr 1fr;gap:7px}
    .filter-group{width:100%}
    .keyword-group{grid-column:1/-1;min-width:0;width:100%;margin:0}
    .keyword-row{display:grid;grid-template-columns:1fr;gap:6px}
    .keyword-row select,.keyword-row input,.search-btn{
        width:100%!important;
        min-width:0!important;
        height:40px;
        font-size:16px;
    }
    .table-wrap{
        width:100%;
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
    }
    .admin-table{min-width:1120px}
    .detail-top,.detail-columns{grid-template-columns:1fr}
    .preview{min-height:180px}
    .preview img{height:190px}
    .info-table{grid-template-columns:1fr}
    .info-table div{grid-template-columns:90px minmax(0,1fr)}
    .info-table span,.info-table b{padding:10px;font-size:11px;overflow-wrap:anywhere}
    .detail-columns{gap:10px}
    .detail-box{padding:11px}
    .import-flow{font-size:11px;line-height:1.5}
    .upload-box{padding:12px;min-height:100px}
    .crud-form,.vehicle-edit-grid,.crud-row,.crud-row.color-row,.crud-row.price-row{
        grid-template-columns:1fr!important;
    }
    .crud-form .wide{grid-column:auto}
    .crud-actions,.vehicle-edit-actions{
        justify-content:stretch;
        flex-wrap:wrap;
    }
    .crud-actions button,.vehicle-edit-actions button{
        flex:1 1 120px;
        min-height:38px;
    }
    .crud-row-actions{grid-column:auto}
    .crud-row input,.crud-row select,
    .crud-form input,.crud-form select,.crud-form textarea,
    .vehicle-edit-grid input,.vehicle-edit-grid select{
        font-size:16px;
    }
}
@media(max-width:500px){
    .filter-top{grid-template-columns:1fr}
    .keyword-group{grid-column:auto}
    .admin-card{margin-left:5px;margin-right:5px}
    .crud-toolbar{flex-wrap:wrap}
}

</style>
<style>.admin-layout{grid-template-columns:228px minmax(0,1fr)!important}@media(max-width:900px){.admin-layout{grid-template-columns:1fr!important}}</style>
</head>
<body>
<div class="admin-layout">
    
    <?php $currentAdminPage = 'products'; require __DIR__ . '/sidebar.php'; ?>


    <main class="main">
        <?php if (!canEditVehicleData()): ?>
            <div class="crud-alert error">현재 계정은 VIEWER 권한입니다. 조회만 가능하며 등록·수정·삭제는 사용할 수 없습니다.</div>
        <?php endif; ?>
        <?php if ($crudMessage): ?>
            <div class="crud-alert ok"><?= h($crudMessage) ?></div>
        <?php endif; ?>
        <?php if ($crudError): ?>
            <div class="crud-alert error"><?= h($crudError) ?></div>
        <?php endif; ?>

        <header class="page-header">
            <div>
                <h1>전체DB</h1>
                <p>등록된 차량 데이터를 검색하고 상세 내용을 확인할 수 있습니다.</p>
            </div>
        </header>

        <?php if ($dashboardDbError): ?>
            <div class="alert error">
                <strong>DB 연결 오류</strong>
                <span><?= h($dashboardDbError) ?></span>
            </div>
        <?php endif; ?>

        <section id="product-list" class="admin-card">
            <div class="card-title">
                <div>
                    <h2>전체DB</h2>
                    <p>전체DB 조회가 가능합니다. 총 <strong><?= number_format($totalRows) ?></strong>개</p>
                </div>
                <a class="new-btn" href="#bulk-import">+ 일괄등록</a>
            </div>

            <form method="get" action="./index.php" class="filter-panel" id="searchForm">
                <input type="hidden" name="page" value="1">
                <div class="filter-top">
                    <div class="filter-left-group">
                        <div class="filter-group">
                            <label>브랜드</label>
                            <select name="brand_id">
                                <option value="0">전체</option>
                                <?php foreach ($brandOptions as $brand): ?>
                                    <option value="<?= (int)$brand['id'] ?>" <?= $brandId === (int)$brand['id'] ? 'selected' : '' ?>>
                                        <?= h($brand['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>연료</label>
                            <select name="fuel_type">
                                <option value="">전체</option>
                                <?php foreach (['GASOLINE','DIESEL','HYBRID','PHEV','EV','LPG','OTHER'] as $fuel): ?>
                                    <option value="<?= h($fuel) ?>" <?= $fuelType === $fuel ? 'selected' : '' ?>><?= h($fuel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>상태</label>
                            <select name="active">
                                <option value="">전체</option>
                                <option value="1" <?= $active === '1' ? 'selected' : '' ?>>사용중</option>
                                <option value="0" <?= $active === '0' ? 'selected' : '' ?>>비활성</option>
                            </select>
                        </div>
                    </div>

                    <div class="keyword-group">
                        <label>검색</label>
                        <div class="keyword-row">
                            <select class="search-type" aria-label="검색 방식">
                                <option>통합</option>
                            </select>
                            <input id="adminSearchInput" type="search" name="q" value="<?= h($q) ?>"
                                   placeholder="브랜드 또는 차량명 검색" autocomplete="off">
                            <select name="per_page" class="per-page">
                                <?php foreach ([10,20,50,100] as $n): ?>
                                    <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?>개씩</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="search-btn">검색</button>
                        </div>
                    </div>
                </div>

                <div class="filter-actions">
                    <a href="./index.php#product-list">검색조건 초기화</a>
                </div>
            </form>

            <div class="bulk-action-bar">
                <div class="bulk-selection">
                    선택 <strong id="selectedCount">0</strong>개
                </div>

                <?php if (canEditVehicleData()): ?>
                <div class="bulk-tools">
                    <form method="post" id="bulkUpdateForm" class="bulk-update-form">
                        <input type="hidden" name="crud_action" value="bulk_update_vehicles">

                        <select name="bulk_field" id="bulkField" aria-label="일괄 변경 항목">
                            <option value="">변경 항목</option>
                            <option value="is_active">상태</option>
                            <option value="brand_id">브랜드</option>
                            <option value="fuel_type">연료</option>
                        </select>

                        <select name="bulk_value" id="bulkValue" disabled aria-label="일괄 변경 값">
                            <option value="">값 선택</option>
                        </select>

                        <button type="submit" class="bulk-change-btn" id="bulkChangeBtn" disabled>
                            선택 변경
                        </button>
                    </form>

                    <form method="post" id="bulkDeleteForm"
                          onsubmit="return confirm('선택한 차량과 연결된 색상·트림·가격 데이터를 삭제할까요?');">
                        <input type="hidden" name="crud_action" value="bulk_delete_vehicles">
                        <button type="submit" id="bulkDeleteBtn" class="bulk-delete-btn" disabled>
                            선택 삭제
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="check"><input type="checkbox" id="checkAll" aria-label="현재 페이지 전체 선택"></th>
                            <th>No.</th>
                            <th>상태</th>
                            <th>등록일시</th>
                            <th>브랜드</th>
                            <th>차량명</th>
                            <th>연식</th>
                            <th>연료</th>
                            <th>차량가</th>
                            <th>색상</th>
                            <th>트림</th>
                            <th>가격</th>
                            <th>상세</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$vehicleRows): ?>
                            <tr><td colspan="13" class="empty">검색 결과가 없습니다.</td></tr>
                        <?php else: ?>
                            <?php foreach ($vehicleRows as $row): ?>
                                <tr>
                                    <td><input type="checkbox" class="row-check" value="<?= (int)$row['id'] ?>" aria-label="<?= h($row['name']) ?> 선택"></td>
                                    <td class="number"><?= number_format((int)$row['id']) ?></td>
                                    <td>
                                        <?= (int)$row['is_active'] === 1
                                            ? '<span class="status status-active">사용중</span>'
                                            : '<span class="status status-off">비활성</span>' ?>
                                    </td>
                                    <td><?= !empty($row['created_at']) ? h(date('y-m-d H:i', strtotime($row['created_at']))) : '-' ?></td>
                                    <td><?= h($row['brand_name']) ?></td>
                                    <td class="vehicle-name">
                                        <a class="vehicle-name-link"
                                           href="./index.php?<?= h(adminQuery(['vehicle_id' => (int)$row['id']])) ?>#vehicle-detail">
                                            <?= h($row['name']) ?>
                                        </a>
                                        <?php if ((int)$row['is_best'] === 1): ?><span class="best">BEST</span><?php endif; ?>
                                    </td>
                                    <td><?= $row['model_year'] ? h((string)$row['model_year']) : '-' ?></td>
                                    <td><?= h($row['fuel_type']) ?></td>
                                    <td><?= (int)$row['base_price'] > 0 ? number_format((int)$row['base_price']).'원' : '-' ?></td>
                                    <td><?= number_format((int)$row['color_count']) ?>개</td>
                                    <td><?= number_format((int)$row['trim_count']) ?>개</td>
                                    <td><?= number_format((int)$row['price_count']) ?>건</td>
                                    <td>
                                        <a class="detail-btn" href="./index.php?<?= h(adminQuery(['vehicle_id' => (int)$row['id']])) ?>#vehicle-detail">보기</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="./index.php?<?= h(adminQuery(['page' => 1, 'vehicle_id' => null])) ?>#product-list">처음</a>
                    <a href="./index.php?<?= h(adminQuery(['page' => $page-1, 'vehicle_id' => null])) ?>#product-list">‹</a>
                <?php endif; ?>

                <?php
                    $start = max(1, $page - 4);
                    $end = min($totalPages, $start + 8);
                    $start = max(1, $end - 8);
                    for ($p = $start; $p <= $end; $p++):
                ?>
                    <a class="<?= $p === $page ? 'active' : '' ?>" href="./index.php?<?= h(adminQuery(['page' => $p, 'vehicle_id' => null])) ?>#product-list"><?= $p ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="./index.php?<?= h(adminQuery(['page' => $page+1, 'vehicle_id' => null])) ?>#product-list">›</a>
                    <a href="./index.php?<?= h(adminQuery(['page' => $totalPages, 'vehicle_id' => null])) ?>#product-list">마지막</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>

        <?php if ($vehicleDetail): ?>
        <section id="vehicle-detail" class="admin-card detail-card">
            <div class="card-title">
                <div>
                    <h2><?= h($vehicleDetail['brand_name']) ?> <?= h($vehicleDetail['name']) ?></h2>
                    <p>차량 기본정보와 연결된 색상·트림·가격을 직접 수정·추가·삭제할 수 있습니다.</p>
                </div>
                <div class="crud-toolbar">
                    <a class="gray-btn" href="./index.php?<?= h(adminQuery(['vehicle_id' => null])) ?>#product-list">목록으로</a>
                    <form method="post" onsubmit="return confirm('이 차량과 연결된 색상·트림·가격 데이터를 모두 삭제할까요?');">
                        <input type="hidden" name="crud_action" value="delete_vehicle">
                        <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                        <button class="danger-btn" type="submit">차량 삭제</button>
                    </form>
                </div>
            </div>

            <div class="detail-top">
                <div class="preview">
                    <?php if (!empty($vehicleDetail['image_path'])): ?>
                        <img src="../<?= h($vehicleDetail['image_path']) ?>" alt="<?= h($vehicleDetail['name']) ?>">
                    <?php else: ?>
                        <span>이미지 없음</span>
                    <?php endif; ?>
                </div>

                <div class="info-table">
                    <div><span>차량 ID</span><b><?= (int)$vehicleDetail['id'] ?></b></div>
                    <div><span>브랜드</span><b><?= h($vehicleDetail['brand_name']) ?></b></div>
                    <div><span>차량명</span><b><?= h($vehicleDetail['name']) ?></b></div>
                    <div><span>연식</span><b><?= $vehicleDetail['model_year'] ?: '-' ?></b></div>
                    <div><span>연료</span><b><?= h($vehicleDetail['fuel_type']) ?></b></div>
                    <div><span>차량가</span><b><?= (int)$vehicleDetail['base_price'] > 0 ? number_format((int)$vehicleDetail['base_price']).'원' : '-' ?></b></div>
                </div>
            </div>

            <form method="post" class="vehicle-edit-form">
                <input type="hidden" name="crud_action" value="update_vehicle">
                <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                <div class="vehicle-edit-grid">
                    <div>
                        <label>차량명</label>
                        <input type="text" name="name" value="<?= h($vehicleDetail['name']) ?>" required>
                    </div>
                    <div>
                        <label>연식</label>
                        <input type="number" name="model_year" value="<?= h((string)($vehicleDetail['model_year'] ?? '')) ?>">
                    </div>
                    <div>
                        <label>연료</label>
                        <select name="fuel_type">
                            <?php foreach (['GASOLINE','DIESEL','HYBRID','PHEV','EV','LPG','OTHER'] as $fuel): ?>
                                <option value="<?= h($fuel) ?>" <?= $vehicleDetail['fuel_type'] === $fuel ? 'selected' : '' ?>><?= h($fuel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>차량가</label>
                        <input type="number" name="base_price" value="<?= (int)$vehicleDetail['base_price'] ?>">
                    </div>
                    <div class="wide">
                        <label>대표 이미지 경로</label>
                        <input type="text" name="image_path" value="<?= h((string)($vehicleDetail['image_path'] ?? '')) ?>">
                    </div>
                    <div>
                        <label>BEST</label>
                        <select name="is_best">
                            <option value="0" <?= (int)$vehicleDetail['is_best'] === 0 ? 'selected' : '' ?>>일반</option>
                            <option value="1" <?= (int)$vehicleDetail['is_best'] === 1 ? 'selected' : '' ?>>BEST</option>
                        </select>
                    </div>
                    <div>
                        <label>정렬순서</label>
                        <input type="number" name="sort_order" value="<?= (int)$vehicleDetail['sort_order'] ?>">
                    </div>
                    <div>
                        <label>상태</label>
                        <select name="is_active">
                            <option value="1" <?= (int)$vehicleDetail['is_active'] === 1 ? 'selected' : '' ?>>사용중</option>
                            <option value="0" <?= (int)$vehicleDetail['is_active'] === 0 ? 'selected' : '' ?>>비활성</option>
                        </select>
                    </div>
                </div>
                <div class="vehicle-edit-actions">
                    <button type="submit" class="save-btn">차량 기본정보 수정</button>
                </div>
            </form>

            <div class="crud-section">
                <div class="crud-section-head">
                    <h3>색상 관리 (<?= count($detailColors) ?>)</h3>
                    <?php if (!empty($detailColors)): ?>
                    <button type="button" class="bulk-save-btn" onclick="submitBulkSection('color')">색상 일괄수정</button>
                    <?php endif; ?>
                </div>

                <form method="post" class="crud-form">
                    <input type="hidden" name="crud_action" value="add_color">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                    <div><label>색상명</label><input type="text" name="color_name" required></div>
                    <div><label>HEX</label><input type="text" name="hex_code" placeholder="#ffffff"></div>
                    <div><label>테두리색</label><input type="text" name="border_color" placeholder="#dddddd"></div>
                    <div class="wide"><label>이미지 경로</label><input type="text" name="color_image_path"></div>
                    <div><label>정렬</label><input type="number" name="color_sort_order" value="<?= count($detailColors)+1 ?>"></div>
                    <div><label>상태</label><select name="color_is_active"><option value="1">사용중</option><option value="0">비활성</option></select></div>
                    <div class="crud-actions"><button class="add-btn" type="submit">+ 색상 추가</button></div>
                </form>

                <form method="post" id="bulkColorForm" class="hidden-bulk-form">
                    <input type="hidden" name="crud_action" value="bulk_update_colors">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                </form>

                <div class="bulk-help">여러 색상 값을 바꾼 뒤 <strong>색상 일괄수정</strong>을 누르면 한 번에 저장됩니다.</div>

                <div class="crud-list">
                    <?php foreach ($detailColors as $color): ?>
                    <form method="post" class="crud-row color-row js-bulk-color-row">
                        <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                        <input type="hidden" name="color_id" value="<?= (int)$color['id'] ?>">
                        <div><label>색상명</label><input type="text" name="color_name" value="<?= h($color['name']) ?>"></div>
                        <div><label>HEX</label><input type="text" name="hex_code" value="<?= h((string)($color['hex_code'] ?? '')) ?>"></div>
                        <div><label>테두리</label><input type="text" name="border_color" value="<?= h((string)($color['border_color'] ?? '')) ?>"></div>
                        <div><label>이미지 경로</label><input type="text" name="color_image_path" value="<?= h((string)($color['image_path'] ?? '')) ?>"></div>
                        <div><label>정렬</label><input type="number" name="color_sort_order" value="<?= (int)$color['sort_order'] ?>"></div>
                        <div><label>상태</label><select name="color_is_active"><option value="1" <?= (int)$color['is_active']===1?'selected':'' ?>>사용</option><option value="0" <?= (int)$color['is_active']===0?'selected':'' ?>>비활성</option></select></div>
                        <div class="crud-row-actions">
                            <button class="small-btn" type="submit" name="crud_action" value="update_color">수정</button>
                            <button class="small-btn delete" type="submit" name="crud_action" value="delete_color" onclick="return confirm('이 색상을 삭제할까요?');">삭제</button>
                        </div>
                    </form>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="crud-section">
                <div class="crud-section-head">
                    <h3>트림 관리 (<?= count($detailTrims) ?>)</h3>
                    <?php if (!empty($detailTrims)): ?>
                    <button type="button" class="bulk-save-btn" onclick="submitBulkSection('trim')">트림 일괄수정</button>
                    <?php endif; ?>
                </div>

                <form method="post" class="crud-form">
                    <input type="hidden" name="crud_action" value="add_trim">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                    <div><label>트림명</label><input type="text" name="trim_name" required></div>
                    <div><label>차량가</label><input type="number" name="trim_price" value="0"></div>
                    <div class="wide"><label>설명</label><input type="text" name="trim_description"></div>
                    <div><label>정렬</label><input type="number" name="trim_sort_order" value="<?= count($detailTrims)+1 ?>"></div>
                    <div><label>상태</label><select name="trim_is_active"><option value="1">사용중</option><option value="0">비활성</option></select></div>
                    <div class="crud-actions"><button class="add-btn" type="submit">+ 트림 추가</button></div>
                </form>

                <form method="post" id="bulkTrimForm" class="hidden-bulk-form">
                    <input type="hidden" name="crud_action" value="bulk_update_trims">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                </form>

                <div class="bulk-help">여러 트림 값을 바꾼 뒤 <strong>트림 일괄수정</strong>을 누르면 한 번에 저장됩니다.</div>

                <div class="crud-list">
                    <?php foreach ($detailTrims as $trim): ?>
                    <form method="post" class="crud-row js-bulk-trim-row">
                        <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                        <input type="hidden" name="trim_id" value="<?= (int)$trim['id'] ?>">
                        <div><label>트림명</label><input type="text" name="trim_name" value="<?= h($trim['name']) ?>"></div>
                        <div><label>차량가</label><input type="number" name="trim_price" value="<?= (int)$trim['price'] ?>"></div>
                        <div class="wide"><label>설명</label><input type="text" name="trim_description" value="<?= h((string)($trim['description'] ?? '')) ?>"></div>
                        <div><label>정렬</label><input type="number" name="trim_sort_order" value="<?= (int)$trim['sort_order'] ?>"></div>
                        <div><label>상태</label><select name="trim_is_active"><option value="1" <?= (int)$trim['is_active']===1?'selected':'' ?>>사용</option><option value="0" <?= (int)$trim['is_active']===0?'selected':'' ?>>비활성</option></select></div>
                        <div class="crud-row-actions">
                            <button class="small-btn" type="submit" name="crud_action" value="update_trim">수정</button>
                            <button class="small-btn delete" type="submit" name="crud_action" value="delete_trim" onclick="return confirm('이 트림과 연결된 가격 데이터도 삭제됩니다. 계속할까요?');">삭제</button>
                        </div>
                    </form>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="crud-section">
                <div class="crud-section-head">
                    <h3>가격 관리 (<?= count($detailPrices) ?>)</h3>
                    <?php if (!empty($detailPrices)): ?>
                    <button type="button" class="bulk-save-btn" onclick="submitBulkSection('price')">가격 일괄수정</button>
                    <?php endif; ?>
                </div>

                <form method="post" class="crud-form">
                    <input type="hidden" name="crud_action" value="add_price">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                    <div><label>트림</label>
                        <select name="price_trim_id" required>
                            <option value="">선택</option>
                            <?php foreach ($detailTrims as $trim): ?><option value="<?= (int)$trim['id'] ?>"><?= h($trim['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>상품</label><select name="product_type"><option value="RENT">장기렌트</option><option value="LEASE">리스</option></select></div>
                    <div><label>기간</label><select name="contract_months"><?php foreach ([12,24,36,48,60] as $m): ?><option value="<?= $m ?>"><?= $m ?>개월</option><?php endforeach; ?></select></div>
                    <div><label>선납금</label><select name="prepayment_rate"><?php foreach ([0,10,20,30,40] as $r): ?><option value="<?= $r ?>"><?= $r ?>%</option><?php endforeach; ?></select></div>
                    <div><label>주행거리</label><select name="annual_mileage"><option value="0">무제한</option><option value="10000">10,000km</option><option value="20000">20,000km</option><option value="30000">30,000km</option><option value="40000">40,000km</option></select></div>
                    <div><label>월 납입금</label><input type="number" name="monthly_payment" required></div>
                    <div><label>상태</label><select name="price_is_active"><option value="1">사용중</option><option value="0">비활성</option></select></div>
                    <div class="crud-actions"><button class="add-btn" type="submit">+ 가격 추가</button></div>
                </form>

                <form method="post" id="bulkPriceForm" class="hidden-bulk-form">
                    <input type="hidden" name="crud_action" value="bulk_update_prices">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                </form>

                <div class="bulk-help">여러 가격 조건 값을 바꾼 뒤 <strong>가격 일괄수정</strong>을 누르면 한 번에 저장됩니다.</div>

                <div class="crud-list">
                    <?php foreach ($detailPrices as $price): ?>
                    <form method="post" class="crud-row price-row">
                        <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                        <input type="hidden" name="price_id" value="<?= (int)$price['id'] ?>">
                        <div><label>트림</label><select name="price_trim_id"><?php foreach ($detailTrims as $trim): ?><option value="<?= (int)$trim['id'] ?>" <?= $trim['name']===$price['trim_name']?'selected':'' ?>><?= h($trim['name']) ?></option><?php endforeach; ?></select></div>
                        <div><label>상품</label><select name="product_type"><option value="RENT" <?= $price['product_type']==='RENT'?'selected':'' ?>>렌트</option><option value="LEASE" <?= $price['product_type']==='LEASE'?'selected':'' ?>>리스</option></select></div>
                        <div><label>기간</label><input type="number" name="contract_months" value="<?= (int)$price['contract_months'] ?>"></div>
                        <div><label>선납금%</label><input type="number" step="0.01" name="prepayment_rate" value="<?= h((string)$price['prepayment_rate']) ?>"></div>
                        <div><label>주행거리</label><input type="number" name="annual_mileage" value="<?= (int)$price['annual_mileage'] ?>"></div>
                        <div><label>월 납입금</label><input type="number" name="monthly_payment" value="<?= (int)$price['monthly_payment'] ?>"></div>
                        <div><label>상태</label><select name="price_is_active"><option value="1" <?= (int)$price['is_active']===1?'selected':'' ?>>사용</option><option value="0" <?= (int)$price['is_active']===0?'selected':'' ?>>비활성</option></select></div>
                        <div class="crud-row-actions">
                            <button class="small-btn" type="submit" name="crud_action" value="update_price">수정</button>
                            <button class="small-btn delete" type="submit" name="crud_action" value="delete_price" onclick="return confirm('이 가격 조건을 삭제할까요?');">삭제</button>
                        </div>
                    </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (canEditVehicleData()): ?>
        <section id="bulk-import" class="admin-card import-card">
            <div class="card-title">
                <div>
                    <h2>엑셀 일괄등록</h2>
                    <p>초기등록 / 업데이트 XLSX 파일을 한 번에 등록합니다.</p>
                </div>
            </div>

            <div class="import-flow">
                <span>brands</span><b>→</b><span>vehicles</span><b>→</b>
                <span>colors</span><b>→</b><span>trims</span><b>→</b><span>prices</span>
            </div>

            <form method="post" enctype="multipart/form-data" class="upload-form">
                <label class="upload-box">
                    <strong>등록할 XLSX 파일 선택</strong>
                    <input id="xlsxFile" type="file" name="xlsx" accept=".xlsx" required>
                    <span id="fileName">선택된 파일 없음</span>
                </label>
                <button type="submit" class="upload-btn">엑셀 데이터 일괄등록</button>
            </form>

            <?php if ($result === 'success'): ?>
                <div class="alert success">
                    <strong>등록 완료</strong>
                    <span><?= count($logs) ?>건의 작업이 처리되었습니다.</span>
                </div>
                <pre class="log"><?php foreach ($logs as $line) echo h($line)."\n"; ?></pre>
            <?php elseif ($error !== null): ?>
                <div class="alert error">
                    <strong>등록 실패</strong>
                    <span><?= h($error) ?></span>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <footer class="footer">
            오토지니 차량 DB 관리자 · <code>admin/index.php</code>
        </footer>
    </main>
</div>

<script>
const fileInput = document.getElementById('xlsxFile');
const fileName = document.getElementById('fileName');
if (fileInput && fileName) {
    fileInput.addEventListener('change', function () {
        fileName.textContent = this.files && this.files[0] ? this.files[0].name : '선택된 파일 없음';
    });
}

const checkAll = document.getElementById('checkAll');
const rowChecks = Array.from(document.querySelectorAll('.row-check'));
const selectedCount = document.getElementById('selectedCount');
const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

function refreshSelection() {
    const count = rowChecks.filter(cb => cb.checked).length;
    if (selectedCount) selectedCount.textContent = String(count);
    if (bulkDeleteBtn) bulkDeleteBtn.disabled = count === 0;

    if (checkAll) {
        checkAll.checked = rowChecks.length > 0 && count === rowChecks.length;
        checkAll.indeterminate = count > 0 && count < rowChecks.length;
    }
}

if (checkAll) {
    checkAll.addEventListener('change', () => {
        rowChecks.forEach(cb => cb.checked = checkAll.checked);
        refreshSelection();
    });
}
rowChecks.forEach(cb => cb.addEventListener('change', refreshSelection));
refreshSelection();

const searchInput = document.getElementById('adminSearchInput');
const searchForm = document.getElementById('searchForm');
if (searchInput && searchForm) {
    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchForm.submit();
        }
    });
}


if (window.location.search && document.getElementById('product-list')) {
    document.getElementById('product-list').scrollIntoView({block:'start'});
}


const bulkUpdateForm = document.getElementById('bulkUpdateForm');
const bulkField = document.getElementById('bulkField');
const bulkValue = document.getElementById('bulkValue');
const bulkChangeBtn = document.getElementById('bulkChangeBtn');

const bulkOptions = {
    is_active: [
        ['1', '사용중'],
        ['0', '비활성']
    ],
    brand_id: [
        <?php foreach ($brandOptions as $brand): ?>
        ['<?= (int)$brand['id'] ?>', '<?= addslashes(h($brand['name'])) ?>'],
        <?php endforeach; ?>
    ],
    fuel_type: [
        ['GASOLINE', 'GASOLINE'],
        ['DIESEL', 'DIESEL'],
        ['HYBRID', 'HYBRID'],
        ['PHEV', 'PHEV'],
        ['EV', 'EV'],
        ['LPG', 'LPG'],
        ['OTHER', 'OTHER']
    ]
};

function selectedVehicleIds() {
    return rowChecks.filter(cb => cb.checked).map(cb => cb.value);
}

function syncSelectedIdsToForm(form) {
    if (!form) return;

    form.querySelectorAll('input[name="selected_ids[]"]').forEach(el => el.remove());

    selectedVehicleIds().forEach(id => {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'selected_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
}

function refreshBulkButtons() {
    const count = selectedVehicleIds().length;

    if (bulkChangeBtn) {
        bulkChangeBtn.disabled = count === 0 || !bulkField?.value || !bulkValue?.value;
    }
}

if (bulkField && bulkValue) {
    bulkField.addEventListener('change', () => {
        bulkValue.innerHTML = '<option value="">값 선택</option>';

        const options = bulkOptions[bulkField.value] || [];

        options.forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            bulkValue.appendChild(option);
        });

        bulkValue.disabled = options.length === 0;
        refreshBulkButtons();
    });

    bulkValue.addEventListener('change', refreshBulkButtons);
}

if (bulkUpdateForm) {
    bulkUpdateForm.addEventListener('submit', (e) => {
        syncSelectedIdsToForm(bulkUpdateForm);

        if (selectedVehicleIds().length === 0) {
            e.preventDefault();
            alert('변경할 차량을 선택해주세요.');
            return;
        }

        if (!bulkField.value || !bulkValue.value) {
            e.preventDefault();
            alert('변경 항목과 값을 선택해주세요.');
            return;
        }

        if (!confirm(`선택한 ${selectedVehicleIds().length}대의 차량 정보를 일괄 변경할까요?`)) {
            e.preventDefault();
        }
    });
}

if (bulkDeleteBtn && bulkDeleteBtn.form) {
    bulkDeleteBtn.form.addEventListener('submit', () => {
        syncSelectedIdsToForm(bulkDeleteBtn.form);
    });
}

rowChecks.forEach(cb => {
    cb.addEventListener('change', refreshBulkButtons);
});

if (checkAll) {
    checkAll.addEventListener('change', refreshBulkButtons);
}

refreshBulkButtons();


function clearDynamicInputs(form) {
    form.querySelectorAll('.js-generated-bulk').forEach(el => el.remove());
}

function appendBulkValue(form, name, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value ?? '';
    input.className = 'js-generated-bulk';
    form.appendChild(input);
}

function submitBulkSection(type) {
    const configs = {
        color: {
            formId: 'bulkColorForm',
            rowSelector: '.js-bulk-color-row',
            confirmText: '현재 보이는 색상 변경사항을 한 번에 저장할까요?',
            fields: [
                ['color_id', 'color_id[]'],
                ['color_name', 'color_name[]'],
                ['hex_code', 'hex_code[]'],
                ['border_color', 'border_color[]'],
                ['color_image_path', 'color_image_path[]'],
                ['color_sort_order', 'color_sort_order[]'],
                ['color_is_active', 'color_is_active[]']
            ]
        },
        trim: {
            formId: 'bulkTrimForm',
            rowSelector: '.js-bulk-trim-row',
            confirmText: '현재 보이는 트림 변경사항을 한 번에 저장할까요?',
            fields: [
                ['trim_id', 'trim_id[]'],
                ['trim_name', 'trim_name[]'],
                ['trim_price', 'trim_price[]'],
                ['trim_description', 'trim_description[]'],
                ['trim_sort_order', 'trim_sort_order[]'],
                ['trim_is_active', 'trim_is_active[]']
            ]
        },
        price: {
            formId: 'bulkPriceForm',
            rowSelector: '.js-bulk-price-row',
            confirmText: '현재 보이는 가격 조건 변경사항을 한 번에 저장할까요?',
            fields: [
                ['price_id', 'price_id[]'],
                ['price_trim_id', 'price_trim_id[]'],
                ['product_type', 'product_type[]'],
                ['contract_months', 'contract_months[]'],
                ['prepayment_rate', 'prepayment_rate[]'],
                ['annual_mileage', 'annual_mileage[]'],
                ['monthly_payment', 'monthly_payment[]'],
                ['price_is_active', 'price_is_active[]']
            ]
        }
    };

    const config = configs[type];
    if (!config) return;

    const form = document.getElementById(config.formId);
    if (!form) return;

    clearDynamicInputs(form);

    const rows = Array.from(document.querySelectorAll(config.rowSelector));
    if (!rows.length) {
        alert('일괄 수정할 항목이 없습니다.');
        return;
    }

    rows.forEach(row => {
        config.fields.forEach(([sourceName, targetName]) => {
            const field = row.querySelector(`[name="${sourceName}"]`);
            appendBulkValue(form, targetName, field ? field.value : '');
        });
    });

    if (!confirm(config.confirmText)) {
        clearDynamicInputs(form);
        return;
    }

    form.submit();
}

</script>
</body>
</html>
