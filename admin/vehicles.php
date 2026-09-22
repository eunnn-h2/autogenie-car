<?php
/**
 * 오토지니 차량 DB 엑셀 업로더
 * - 별도 라이브러리/Composer 없이 XLSX(Office Open XML) 파일을 읽습니다.
 * - 권장 시트(한 장 등록): 차량일괄등록
 * - 기존 지원 시트: brands, vehicles, colors, trims, prices
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
requireAdminCategory('vehicles');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/admin_helpers.php';

$isVehicleDetailPage = defined('AUTOGENIE_VEHICLE_DETAIL_PAGE') && AUTOGENIE_VEHICLE_DETAIL_PAGE === true;

// Old bookmarked detail links should open the dedicated page.
if (!$isVehicleDetailPage && $_SERVER['REQUEST_METHOD'] === 'GET' && (int)($_GET['vehicle_id'] ?? 0) > 0) {
    header('Location: ./vehicle-detail.php?' . http_build_query($_GET));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $permissionAction = (string)($_POST['crud_action'] ?? '');
    if ($permissionAction !== '') {
        requireVehicleCrudAction($permissionAction);
    } elseif (isset($_FILES['xlsx'])) {
        requireVehicleImport();
    }
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
        if ($hasValue) {
            $record['__row_number'] = $rowNum;
            $records[] = $record;
        }
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

function importBrands(PDO $pdo, array $rows, array &$log, bool $partialUpdate = false): void
{
    $select = $pdo->prepare('SELECT id FROM car_brands WHERE name=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO car_brands (name, logo_path, origin_type, sort_order, is_active) VALUES (?, ?, ?, ?, ?)');

    foreach ($rows as $r) {
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') continue;

        $select->execute([$name]);
        $id = $select->fetchColumn();

        if ($id !== false) {
            $sets = [];
            $params = [];
            foreach (['logo_path','origin_type','sort_order','is_active'] as $f) {
                $val = $r[$f] ?? null;
                if ($partialUpdate && ($val === null || $val === '')) continue;
                if (in_array($f, ['sort_order','is_active'], true) && $val !== null && $val !== '') {
                    $val = (int)$val;
                }
                $sets[] = "{$f}=?";
                $params[] = nullIfBlank($val);
            }
            if ($sets) {
                $params[] = (int)$id;
                $stmt = $pdo->prepare('UPDATE car_brands SET '.implode(',', $sets).' WHERE id=?');
                $stmt->execute($params);
                $log[] = "brands UPDATE: {$name}";
            }
        } else {
            $insert->execute([
                $name,
                nullIfBlank($r['logo_path'] ?? null),
                (string)($r['origin_type'] ?? 'IMPORT'),
                intValOr($r['sort_order'] ?? 0),
                intValOr($r['is_active'] ?? 1, 1),
            ]);
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


function singleSheetValue(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            $value = $row[$key];
            if (is_string($value)) $value = trim($value);
            if ($value !== null && $value !== '') return $value;
        }
    }
    return $default;
}

function normalizeSingleFlag(mixed $value, int $default = 0): int
{
    if ($value === null || $value === '') return $default;
    if (is_numeric($value)) return ((int)$value) === 0 ? 0 : 1;

    $v = strtolower(trim((string)$value));
    $yes = ['1','y','yes','true','o','on','사용','노출','활성','best','추천'];
    $no = ['0','n','no','false','x','off','미사용','비노출','비활성','일반'];
    if (in_array($v, $yes, true)) return 1;
    if (in_array($v, $no, true)) return 0;
    return $default;
}

function normalizeSingleOrigin(mixed $value): string
{
    $v = strtoupper(trim((string)($value ?? '')));
    if ($v === '' || in_array($v, ['IMPORT','수입','수입차'], true)) return 'IMPORT';
    if (in_array($v, ['DOMESTIC','국산','국산차'], true)) return 'DOMESTIC';
    return $v;
}

function normalizeSingleFuel(mixed $value): string
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '') return 'OTHER';

    $upper = strtoupper($raw);
    $map = [
        '가솔린' => 'GASOLINE',
        '휘발유' => 'GASOLINE',
        '디젤' => 'DIESEL',
        '경유' => 'DIESEL',
        '하이브리드' => 'HYBRID',
        'HEV' => 'HYBRID',
        '플러그인하이브리드' => 'PHEV',
        '플러그인 하이브리드' => 'PHEV',
        '전기' => 'EV',
        '전기차' => 'EV',
        'LPG' => 'LPG',
        '기타' => 'OTHER',
    ];
    if (isset($map[$raw])) return $map[$raw];
    if (isset($map[$upper])) return $map[$upper];
    return in_array($upper, ['GASOLINE','DIESEL','HYBRID','PHEV','EV','LPG','OTHER'], true) ? $upper : 'OTHER';
}

function normalizeSingleProduct(mixed $value): string
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '') return 'RENT';
    $upper = strtoupper($raw);
    if (in_array($raw, ['렌트','장기렌트','장기 렌트'], true)) return 'RENT';
    if (in_array($raw, ['리스','자동차리스','자동차 리스'], true)) return 'LEASE';
    return in_array($upper, ['RENT','LEASE'], true) ? $upper : 'RENT';
}

function normalizeSingleImagePath(mixed $value, string $brandName): mixed
{
    $path = trim((string)($value ?? ''));
    if ($path === '') return null;

    $path = str_replace('\\', '/', $path);
    if (str_contains($path, '/')) {
        return ltrim($path, '/');
    }

    return 'images/cars/' . $brandName . '/' . $path;
}

function mergeSingleRecord(array &$target, array $source): void
{
    foreach ($source as $key => $value) {
        if ($key === '__row_number') continue;
        if ($value !== null && $value !== '') {
            $target[$key] = $value;
        } elseif (!array_key_exists($key, $target)) {
            $target[$key] = $value;
        }
    }
}

function importSingleVehicleSheet(PDO $pdo, array $rows, array &$log): void
{
    $brands = [];
    $vehicles = [];
    $colors = [];
    $trims = [];
    $prices = [];
    $recommendations = [];

    foreach ($rows as $r) {
        $rowNo = (int)($r['__row_number'] ?? 0);

        $brand = trim((string)singleSheetValue($r, ['브랜드','brand_name','brand'], ''));
        $vehicle = trim((string)singleSheetValue($r, ['차량명','vehicle_name','name'], ''));

        if ($brand === '' && $vehicle === '') continue;
        if ($brand === '' || $vehicle === '') {
            throw new RuntimeException("차량일괄등록 {$rowNo}행: 브랜드와 차량명은 필수입니다.");
        }

        $commonActiveRaw = singleSheetValue($r, ['노출상태','is_active'], null);
        $commonActive = $commonActiveRaw === null ? null : normalizeSingleFlag($commonActiveRaw, 1);

        $originRaw = singleSheetValue($r, ['국산/수입','원산지','origin_type'], null);
        $fuelRaw = singleSheetValue($r, ['연료','fuel_type'], null);

        $brandRecord = [
            'name' => $brand,
            'logo_path' => singleSheetValue($r, ['브랜드로고경로','브랜드 로고 경로','brand_logo_path','logo_path'], null),
            'origin_type' => $originRaw === null ? null : normalizeSingleOrigin($originRaw),
            'sort_order' => singleSheetValue($r, ['브랜드정렬','브랜드 정렬','brand_sort_order'], null),
            'is_active' => $commonActive,
        ];
        if (!isset($brands[$brand])) $brands[$brand] = [];
        mergeSingleRecord($brands[$brand], $brandRecord);

        $vehicleKey = $brand . "\0" . $vehicle;
        $vehicleRecord = [
            'brand_name' => $brand,
            'name' => $vehicle,
            'model_year' => singleSheetValue($r, ['연식','모델연도','model_year'], null),
            'fuel_type' => $fuelRaw === null ? null : normalizeSingleFuel($fuelRaw),
            'base_price' => singleSheetValue($r, ['차량가격','차량가','base_price'], null),
            'image_path' => normalizeSingleImagePath(singleSheetValue($r, ['차량이미지경로','차량 이미지 경로','vehicle_image_path','image_path'], null), $brand),
            'is_best' => (($v = singleSheetValue($r, ['BEST','베스트','is_best'], null)) === null ? null : normalizeSingleFlag($v, 0)),
            'sort_order' => singleSheetValue($r, ['차량정렬','차량 정렬','vehicle_sort_order','sort_order'], null),
            'is_active' => $commonActive,
        ];
        if (!isset($vehicles[$vehicleKey])) $vehicles[$vehicleKey] = [];
        mergeSingleRecord($vehicles[$vehicleKey], $vehicleRecord);

        $recommendedRaw = singleSheetValue($r, ['추천','추천차량','is_recommended'], null);
        if ($recommendedRaw !== null) {
            $recommendations[$vehicleKey] = normalizeSingleFlag($recommendedRaw, 0);
        }

        $colorName = trim((string)singleSheetValue($r, ['외장색','색상','color_name'], ''));
        if ($colorName !== '') {
            $colorKey = $vehicleKey . "\0" . $colorName;
            $colorRecord = [
                'brand_name' => $brand,
                'vehicle_name' => $vehicle,
                'name' => $colorName,
                'hex_code' => singleSheetValue($r, ['색상코드','HEX','hex_code'], null),
                'border_color' => singleSheetValue($r, ['테두리색','border_color'], null),
                'image_path' => normalizeSingleImagePath(singleSheetValue($r, ['색상이미지경로','색상 이미지 경로','color_image_path'], null), $brand),
                'sort_order' => singleSheetValue($r, ['색상정렬','색상 정렬','color_sort_order'], null),
                'is_active' => $commonActive,
            ];
            if (!isset($colors[$colorKey])) $colors[$colorKey] = [];
            mergeSingleRecord($colors[$colorKey], $colorRecord);
        }

        $trimName = trim((string)singleSheetValue($r, ['트림','트림명','trim_name'], ''));
        $monthlyForRow = singleSheetValue($r, ['월납입금','월 납입금','월가격','monthly_payment'], null);
        if ($trimName === '' && $monthlyForRow !== null && $monthlyForRow !== '') {
            throw new RuntimeException("차량일괄등록 {$rowNo}행: 월납입금을 등록하려면 트림명이 필요합니다.");
        }

        if ($trimName !== '') {
            $trimKey = $vehicleKey . "\0" . $trimName;
            $trimRecord = [
                'brand_name' => $brand,
                'vehicle_name' => $vehicle,
                'name' => $trimName,
                'price' => singleSheetValue($r, ['트림가격','트림 가격','trim_price','price'], null),
                'description' => singleSheetValue($r, ['트림설명','트림 설명','trim_description','description'], null),
                'sort_order' => singleSheetValue($r, ['트림정렬','트림 정렬','trim_sort_order'], null),
                'is_active' => $commonActive,
            ];
            if (!isset($trims[$trimKey])) $trims[$trimKey] = [];
            mergeSingleRecord($trims[$trimKey], $trimRecord);

            $monthly = $monthlyForRow;
            if ($monthly !== null && $monthly !== '') {
                $product = normalizeSingleProduct(singleSheetValue($r, ['상품구분','상품 구분','product_type'], 'RENT'));
                $months = intValOr(singleSheetValue($r, ['계약기간','계약기간(개월)','contract_months'], 0));
                $prepay = floatValOr(singleSheetValue($r, ['선납금(%)','선납금%','선납금','prepayment_rate'], 0));
                $mileage = intValOr(singleSheetValue($r, ['연간주행거리','주행거리','annual_mileage'], 0));

                $priceKey = $trimKey . "\0{$product}\0{$months}\0{$prepay}\0{$mileage}";
                $prices[$priceKey] = [
                    'brand_name' => $brand,
                    'vehicle_name' => $vehicle,
                    'trim_name' => $trimName,
                    'product_type' => $product,
                    'contract_months' => $months,
                    'prepayment_rate' => $prepay,
                    'annual_mileage' => $mileage,
                    'monthly_payment' => $monthly,
                    'is_active' => $commonActive ?? 1,
                ];
            }
        }
    }

    importBrands($pdo, array_values($brands), $log, true);
    importVehicles($pdo, array_values($vehicles), $log, true);
    importColors($pdo, array_values($colors), $log, true);
    importTrims($pdo, array_values($trims), $log, true);
    importPrices($pdo, array_values($prices), $log, true);

    if ($recommendations && ag_column_exists($pdo, 'car_vehicles', 'is_recommended')) {
        $stmt = $pdo->prepare('UPDATE car_vehicles v JOIN car_brands b ON b.id=v.brand_id SET v.is_recommended=? WHERE b.name=? AND v.name=?');
        foreach ($recommendations as $key => $flag) {
            [$brandName, $vehicleName] = explode("\0", $key, 2);
            $stmt->execute([$flag, $brandName, $vehicleName]);
            $log[] = "vehicles RECOMMENDED: {$brandName} / {$vehicleName} = {$flag}";
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

        // 신규 권장 방식: 한 장짜리 차량일괄등록 시트
        foreach (['차량일괄등록','bulk','all_in_one','vehicles_all'] as $singleSheetName) {
            if (isset($sheets[$singleSheetName])) {
                importSingleVehicleSheet($pdo, rowsToRecords($sheets[$singleSheetName]), $logs);
                break;
            }
        }

        // 기존 다중 시트 방식도 하위 호환으로 계속 지원
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

$crudMessage = null;
$crudError = null;






// -----------------------------
// 차량 상품 수동 등록
// -----------------------------
$hasRecommended = ag_column_exists($pdo, 'car_vehicles', 'is_recommended');
$hasAdminThumbnail = ag_column_exists($pdo, 'car_vehicles', 'admin_thumbnail_color_id');
$hasEstimateThumbnail = ag_column_exists($pdo, 'car_vehicles', 'estimate_thumbnail_color_id');
$hasThumbnailSelectors = $hasAdminThumbnail && $hasEstimateThumbnail;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['crud_action'] ?? '') === 'add_vehicle_manual'
) {
    requireVehicleEditor();
    try {
        $brandIdPost = (int)($_POST['brand_id'] ?? 0);
        $namePost = trim((string)($_POST['name'] ?? ''));
        if ($brandIdPost <= 0 || $namePost === '') {
            throw new RuntimeException('브랜드와 차량명을 입력해주세요.');
        }

        $uploadedImage = ag_upload_original_name($_FILES['vehicle_image'] ?? [], 'images/cars', dirname(__DIR__));
        $imagePath = $uploadedImage ?: (trim((string)($_POST['image_path'] ?? '')) ?: null);

        $columns = ['brand_id','name','model_year','fuel_type','base_price','image_path','is_best','sort_order','is_active'];
        $values = [
            $brandIdPost,
            $namePost,
            nullableInt($_POST['model_year'] ?? null),
            (string)($_POST['fuel_type'] ?? 'GASOLINE'),
            (int)($_POST['base_price'] ?? 0),
            $imagePath,
            (int)($_POST['is_best'] ?? 0),
            (int)($_POST['sort_order'] ?? 0),
            (int)($_POST['is_active'] ?? 1),
        ];
        if ($hasRecommended) {
            $columns[] = 'is_recommended';
            $values[] = (int)($_POST['is_recommended'] ?? 0);
        }
        $ph = implode(',', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO car_vehicles (`'.implode('`,`', $columns).'`) VALUES ('.$ph.')';
        $pdo->prepare($sql)->execute($values);
        $crudMessage = '차량 상품을 등록했습니다.';
    } catch (Throwable $e) {
        $crudError = '차량 등록 실패: ' . $e->getMessage();
    }
}

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
    } elseif (!in_array($bulkField, ['is_active', 'is_best', 'is_recommended', 'brand_id', 'fuel_type'], true)) {
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

            if ($bulkField === 'is_best' || $bulkField === 'is_recommended') {
                if (!in_array($bulkValue, ['0', '1'], true)) {
                    throw new RuntimeException('값을 선택해주세요.');
                }
                if ($bulkField === 'is_recommended' && !$hasRecommended) {
                    throw new RuntimeException('추천차량 컬럼이 아직 DB에 없습니다. admin_crm_migration.sql을 먼저 적용해주세요.');
                }
                $sql = "UPDATE car_vehicles SET {$bulkField} = ? WHERE id IN ($placeholders)";
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
                'is_best' => 'BEST',
                'is_recommended' => '추천',
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

function nullableInt($value): ?int {
    if ($value === null || $value === '') return null;
    return (int)$value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crud_action'])) {
    $crudAction = (string)$_POST['crud_action'];

    try {
        if ($crudAction === 'update_vehicle') {
            $vehicleIdPost = (int)($_POST['vehicle_id'] ?? 0);

            $sets = [
                'brand_id' => (int)($_POST['brand_id'] ?? 0),
                'name' => trim((string)($_POST['name'] ?? '')),
                'model_year' => nullableInt($_POST['model_year'] ?? null),
                'fuel_type' => (string)($_POST['fuel_type'] ?? 'GASOLINE'),
                'base_price' => (int)($_POST['base_price'] ?? 0),
                'is_best' => (int)($_POST['is_best'] ?? 0),
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active' => (int)($_POST['is_active'] ?? 1),
            ];
            if ($hasRecommended) {
                $sets['is_recommended'] = (int)($_POST['is_recommended'] ?? 0);
            }
            if ($sets['brand_id'] <= 0 || $sets['name'] === '') {
                throw new RuntimeException('브랜드와 차량명은 필수입니다.');
            }
            $sqlSet = implode(', ', array_map(static fn($c) => "`{$c}` = ?", array_keys($sets)));
            $paramsUpdate = array_values($sets);
            $paramsUpdate[] = $vehicleIdPost;
            $pdo->prepare("UPDATE car_vehicles SET {$sqlSet} WHERE id = ?")->execute($paramsUpdate);
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
            $representativeColorId = nullableInt($_POST['representative_color_id'] ?? null);

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

            $validColorIds = array_map('intval', $colorIds);
            $checkColorSelection = static function (?int $colorId) use ($validColorIds): ?int {
                return ($colorId !== null && in_array($colorId, $validColorIds, true)) ? $colorId : null;
            };
            $representativeColorId = $checkColorSelection($representativeColorId);

            if ($representativeColorId !== null) {
                $imageStmt = $pdo->prepare('SELECT image_path FROM car_colors WHERE id = ? AND vehicle_id = ? LIMIT 1');
                $imageStmt->execute([$representativeColorId, $vehicleIdPost]);
                $representativeImagePath = trim((string)($imageStmt->fetchColumn() ?: ''));
                if ($representativeImagePath === '') {
                    throw new RuntimeException('대표 이미지로 선택한 색상에 차량 이미지가 없습니다.');
                }
                $pdo->prepare('UPDATE car_vehicles SET image_path = ? WHERE id = ?')->execute([$representativeImagePath, $vehicleIdPost]);
            }


            $pdo->commit();

            $crudMessage = count($colorIds) . '개 색상과 대표 이미지를 저장했습니다.';
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
    'brands' => ['table' => 'car_brands', 'label' => '브랜드'],
    'vehicles' => ['table' => 'car_vehicles', 'label' => '차량'],
    'colors' => ['table' => 'car_colors', 'label' => '색상'],
    'trims' => ['table' => 'car_trims', 'label' => '트림'],
    'prices' => ['table' => 'car_prices', 'label' => '가격'],
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
$vehicleId = $isVehicleDetailPage ? (int)($_GET['vehicle_id'] ?? 0) : 0;

$totalRows = 0;
$totalPages = 1;

try {
    foreach ($dashboardTables as $key => $meta) {
        $tableName = $meta['table'];
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$tableName}");
        $dashboardCounts[$key] = (int)$stmt->fetchColumn();
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

    $recommendedSelect = $hasRecommended ? 'v.is_recommended,' : '0 AS is_recommended,';
    $adminThumbSelect = "v.image_path AS admin_thumbnail_path,";

    $vehicleSql = "
        SELECT
            v.id,
            b.name AS brand_name,
            v.name,
            v.model_year,
            v.fuel_type,
            v.base_price,
            v.image_path,
            {$adminThumbSelect}
            v.is_best,
            {$recommendedSelect}
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

if ($isVehicleDetailPage && $vehicleId > 0 && $vehicleDetail === null && $crudMessage !== '' && $crudError === '') {
    // Deletion succeeded; return to the previously filtered vehicle list.
    $returnParams = $_GET;
    unset($returnParams['vehicle_id']);
    header('Location: ./vehicles.php?' . http_build_query($returnParams) . '#product-list');
    exit;
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
<title><?= $isVehicleDetailPage ? '차량 상세관리' : '차량 데이터 관리' ?> - 오토지니 관리자</title>
<link rel="stylesheet" href="./sidebar.css">
<style>
* {
    box-sizing:border-box
}
html {
    scroll-behavior:auto
}
body {
    margin:0;
    font-family:Pretendard,"Noto Sans KR",Arial,sans-serif;
    background:#eef5f8;
    color:#25384a;
    font-size:14px
}
a {
    text-decoration:none;
    color:inherit
}
.admin-layout {
    display:grid;
    grid-template-columns:228px minmax(0,1fr);
    min-height:100vh
}
.sidebar {
    position:sticky;
    top:0;
    height:100vh;
    background:#fff;
    border-right:1px solid #dbe4e9;
    padding:18px 14px 24px;
    display:flex;
    flex-direction:column;
    gap:14px
}
.logo-area {
    height:auto;
    display:flex;
    align-items:center;
    gap:10px;
    border-bottom:1px solid #edf1f3;
    margin-bottom:0;
    padding-bottom:18px
}
.logo-mark {
    width:38px;
    height:38px;
    border-radius:10px;
    background:#29bed1;
    color:#fff;
    display:grid;
    place-items:center;
    font-weight:800
}
.logo-area strong,.logo-area span {
    display:block
}
.logo-area strong {
    font-size:15px
}
.logo-area span {
    font-size:14px;
    color:#93a3ad;
    margin-top:2px
}
.menu-section {
    margin-bottom:0
}
.menu-section>p {
    font-size:14px;
    font-weight:800;
    color:#72838f;
    margin:0 0 10px;
    letter-spacing:.02em
}
.menu-item {
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:10px 11px;
    color:#5c7080;
    border-radius:10px;
    border:1px solid transparent;
    transition:.15s
}
.menu-item + .menu-item {
    margin-top:6px
}
.menu-item:hover {
    background:#f2f6f9;
    border-color:#e0e7ec
}
.menu-item.active {
    background:#3924b9;
    color:#fff;
    border-color:#3924b9;
    box-shadow:0 8px 18px rgba(57,36,185,.18)
}
.menu-item.active .menu-icon {
    background:rgba(255,255,255,.16);
    color:#fff
}
.menu-item.active small {
    color:rgba(255,255,255,.82)
}
.menu-icon {
    flex:0 0 34px;
    width:34px;
    height:34px;
    border-radius:10px;
    background:#eef2f7;
    color:#3924b9;
    display:grid;
    place-items:center;
    font-size:14px;
    font-weight:800
}
.menu-text {
    display:block;
    min-width:0
}
.menu-text strong {
    display:block;
    font-size:14px;
    line-height:1.3
}
.menu-text small {
    display:block;
    margin-top:3px;
    font-size:14px;
    line-height:1.45;
    color:#81919b
}
.admin-menu {
    display:grid;
    gap:14px
}
.admin-menu-group {
    padding:12px;
    border:1px solid #e5ebef;
    border-radius:14px;
    background:#fbfcfd
}
.admin-menu-group.current {
    border-color:#cfc9f4;
    background:#f7f5ff;
    box-shadow:0 0 0 1px rgba(57,36,185,.04) inset
}
.sidebar-stats {
    margin-top:auto;
    background:#f6f9fb;
    border:1px solid #e5ecef;
    border-radius:12px;
    padding:10px
}
.sidebar-stats div {
    display:flex;
    justify-content:space-between;
    padding:5px
}
.sidebar-stats span {
    color:#7a8a94
}
.main {
    min-width:0
}
.page-header {
    height:56px;
    background:#fff;
    border-bottom:1px solid #dfe8ec;
    padding:9px 16px;
    display:flex;
    align-items:center
}
.page-header h1 {
    display:inline;
    margin:0;
    color:#3822b9;
    font-size:18px
}
.page-header p {
    display:inline;
    margin-left:7px;
    color:#3822b9
}
.admin-card {
    margin:28px 14px;
    background:#fff;
    border:1px solid #d8e2e7;
    border-radius:4px;
    padding:16px;
    box-shadow:0 1px 2px rgba(0,0,0,.02)
}
.card-title {
    display:flex;
    justify-content:space-between;
    gap:20px;
    align-items:flex-start;
    margin-bottom:16px
}
.card-title h2 {
    margin:0;
    font-size:17px;
    font-weight:500
}
.card-title p {
    margin:4px 0 0;
    color:#a2b2bd
}
.card-title p strong {
    color:#6c8190
}
.new-btn {
    background:#2499ef;
    color:#fff;
    padding:10px 14px;
    border-radius:2px;
    font-weight:700
}
.filter-panel {
    border-top:1px solid #eef2f4;
    padding-top:14px
}
.filter-top {
    display:flex;
    gap:9px;
    align-items:end;
    flex-wrap:wrap
}
.filter-group {
    width:180px
}
.filter-group label,.keyword-group label {
    display:block;
    color:#82939e;
    font-size:14px;
    margin-bottom:5px
}
.filter-group select,.keyword-row select,.keyword-row input {
    height:36px;
    border:1px solid #bfcbd2;
    background:#fff;
    padding:0 10px;
    color:#657784
}
.keyword-group {
    margin-left:auto;
    min-width:520px
}
.keyword-row {
    display:flex;
    gap:6px
}
.keyword-row select,.keyword-row input {
    border-radius:0
}
.search-type {
    width:76px
}
.per-page {
    width:88px
}
.keyword-row input {
    flex:1;
    min-width:180px
}
.search-btn {
    height:36px;
    border:0;
    background:#24bfd1;
    color:#fff;
    font-weight:700;
    padding:0 18px
}
.filter-actions {
    text-align:right;
    margin-top:8px
}
.filter-actions a {
    color:#8fa0aa;
    font-size:14px
}
.table-wrap {
    overflow-x:auto;
    border-top:1px solid #dce4e8;
    margin-top:14px
}
.admin-table {
    border-collapse:collapse;
    width:100%;
    min-width:1220px;
    font-size:14px
}
.admin-table th,.admin-table td {
    height:41px;
    padding:7px 9px;
    border-right:1px solid #e5eaed;
    border-bottom:1px solid #dfe5e8;
    text-align:center;
    white-space:nowrap
}
.admin-table th {
    background:#fff;
    color:#526875;
    font-weight:600
}
.admin-table tbody tr:nth-child(odd) {
    background:#f2f4f7
}
.admin-table tbody tr:nth-child(even) {
    background:#fff
}
.admin-table tbody tr:hover {
    background:#eaf6fb
}
.admin-table .number {
    color:#315cff
}
.admin-table .vehicle-name {
    color:#284fdb
}
.check {
    width:36px
}
.status {
    display:inline-block;
    padding:4px 7px;
    border-radius:3px;
    font-weight:800;
    font-size:14px
}
.status-active {
    background:#1f2937;
    color:#fff
}
.status-off {
    background:#ee293d;
    color:#fff
}
.best {
    display:inline-block;
    margin-left:5px;
    padding:2px 5px;
    background:#ffedd5;
    color:#9a3412;
    border-radius:3px;
    font-size:14px
}
.detail-btn {
    color:#2261ee;
    font-weight:700
}
.empty {
    height:90px!important;
    color:#99a9b2
}
.pagination {
    display:flex;
    justify-content:center;
    align-items:center;
    margin:14px 0 0
}
.pagination a {
    min-width:31px;
    height:31px;
    padding:0 8px;
    border:1px solid #cbd5db;
    border-right:0;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#fff;
    color:#60727d
}
.pagination a:last-child {
    border-right:1px solid #cbd5db
}
.pagination a.active {
    background:#5e6c77;
    color:#fff
}
.gray-btn {
    padding:8px 12px;
    border:1px solid #c8d2d8;
    background:#fff;
    color:#687b87
}
.detail-top {
    display:grid;
    grid-template-columns:340px 1fr;
    gap:16px
}
.preview {
    border:1px solid #dfe6ea;
    background:#fafcfd;
    min-height:220px;
    display:grid;
    place-items:center
}
.preview img {
    max-width:100%;
    height:220px;
    object-fit:contain
}
.preview span {
    color:#a1b0b8
}
.info-table {
    display:grid;
    grid-template-columns:1fr 1fr;
    border-top:1px solid #dfe5e8;
    border-left:1px solid #dfe5e8
}
.info-table div {
    display:grid;
    grid-template-columns:110px 1fr;
    border-right:1px solid #dfe5e8;
    border-bottom:1px solid #dfe5e8
}
.info-table span {
    background:#f5f7f9;
    color:#667986;
    padding:12px
}
.info-table b {
    padding:12px;
    font-weight:600
}
.detail-columns {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
    margin-top:16px
}
.detail-box {
    border:1px solid #dfe6ea;
    padding:14px
}
.detail-box h3 {
    margin:0 0 12px;
    font-size:14px
}
.detail-box h3 em {
    font-style:normal;
    color:#2aaec0
}
.line-item {
    display:flex;
    align-items:center;
    gap:8px;
    padding:9px;
    border-bottom:1px solid #eef1f3
}
.line-item.split {
    justify-content:space-between
}
.color-dot {
    width:17px;
    height:17px;
    border-radius:50%;
    border:1px solid
}
.empty-small {
    color:#9aabb4;
    text-align:center;
    padding:20px
}
.price-box {
    margin-top:16px
}
.payment {
    font-weight:700
}
.import-flow {
    padding:12px;
    background:#f5f8fa;
    border:1px solid #e4ebef;
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap
}
.import-flow span {
    font-weight:700
}
.upload-form {
    margin-top:15px
}
.upload-box {
    border:2px dashed #c7d5dc;
    min-height:115px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:12px;
    flex-wrap:wrap;
    background:#fbfdfe
}
.upload-box strong {
    width:100%;
    text-align:center
}
.upload-box span {
    color:#84959f
}
.upload-btn {
    width:100%;
    margin-top:10px;
    height:44px;
    border:0;
    background:#162030;
    color:#fff;
    font-weight:800
}
.upload-template-btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:36px;
    padding:0 12px;
    border:1px solid #ff5a24;
    border-radius:4px;
    background:#fff;
    color:#ff5a24;
    text-decoration:none;
    font-size:14px;
    font-weight:800;
    white-space:nowrap
}
.upload-template-btn:hover {
    background:#fff0e9
}
.alert {
    margin:15px 0;
    padding:12px;
    border-radius:3px
}
.alert strong,.alert span {
    display:block
}
.alert span {
    margin-top:4px
}
.alert.success {
    background:#edf9f2;
    border:1px solid #bde8ca;
    color:#16733a
}
.alert.error {
    background:#fff2f2;
    border:1px solid #ffb9b9;
    color:#b01625
}
.log {
    background:#17202b;
    color:#dce6eb;
    padding:12px;
    max-height:300px;
    overflow:auto
}
.footer {
    padding:0 16px 24px;
    color:#8da0ab
}
.footer code {
    background:#e8eef1;
    padding:2px 5px
}
@media(max-width:1050px) {
    .admin-layout {
        grid-template-columns:1fr
    }
    .sidebar {
        position:static;
        height:auto
    }
    .keyword-group {
        margin-left:0;
        min-width:100%;
        width:100%
    }
    .detail-top,.detail-columns {
        grid-template-columns:1fr
    }
}
@media(max-width:650px) {
    .filter-group {
        width:100%
    }
    .keyword-row {
        flex-wrap:wrap
    }
    .keyword-row>* {
        width:100%!important;
        flex:auto!important
    }
    .detail-top {
        grid-template-columns:1fr
    }
    .info-table {
        grid-template-columns:1fr
    }
    .admin-card {
        margin:14px 8px
    }
}
.crud-alert {
    margin:0 14px 14px;
    padding:12px 14px;
    border-radius:4px
}
.crud-alert.ok {
    background:#edf9f2;
    border:1px solid #bde8ca;
    color:#16733a
}
.crud-alert.error {
    background:#fff2f2;
    border:1px solid #ffb9b9;
    color:#b01625
}
.crud-toolbar {
    display:flex;
    gap:8px;
    align-items:center
}
.danger-btn {
    padding:8px 12px;
    border:0;
    background:#ef3340;
    color:#fff;
    cursor:pointer
}
.edit-btn,.add-btn,.save-btn,.small-btn {
    border:0;
    cursor:pointer;
    font-weight:700
}
.edit-btn,.add-btn {
    padding:8px 12px;
    background:#25bcd0;
    color:#fff
}
.save-btn {
    padding:8px 13px;
    background:#3924b9;
    color:#fff
}
.small-btn {
    padding:5px 8px;
    background:#eef2f5;
    color:#4b6270
}
.small-btn.delete {
    background:#fff1f1;
    color:#d02c38
}
.crud-section {
    margin-top:24px;
    border:1px solid #dfe6ea
}
.crud-section-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:10px 12px;
    background:#f5f8fa;
    border-bottom:1px solid #dfe6ea
}
.crud-section-head h3 {
    margin:0;
    font-size:14px
}
.crud-form {
    display:grid;
    grid-template-columns:repeat(4,minmax(130px,1fr));
    gap:10px;
    padding:12px
}
.crud-form .wide {
    grid-column:span 2
}
.crud-form label {
    font-size:14px;
    color:#748792;
    display:block;
    margin-bottom:4px
}
.crud-form input,.crud-form select,.crud-form textarea {
    width:100%;
    min-height:35px;
    border:1px solid #c9d3d9;
    padding:7px 9px;
    background:#fff
}
.crud-form textarea {
    min-height:68px;
    resize:vertical
}
.crud-actions {
    grid-column:1/-1;
    display:flex;
    gap:8px;
    justify-content:flex-end
}
.crud-list {
    padding:10px 12px 12px
}
.crud-row {
    display:grid;
    grid-template-columns:minmax(160px,1fr) repeat(4,minmax(90px,.6fr)) auto;
    gap:8px;
    align-items:end;
    padding:10px 0;
    border-bottom:1px solid #edf1f3
}
.crud-row.color-row {
    grid-template-columns:150px 210px 92px 92px minmax(240px,1fr) 68px 82px 92px;
    align-items:center
}
.crud-row.price-row {
    grid-template-columns:minmax(130px,1fr) 100px 90px 90px 110px 120px 90px auto
}
.crud-row label {
    font-size:14px;
    color:#82939e;
    display:block;
    margin-bottom:3px
}
.crud-row input,.crud-row select {
    width:100%;
    height:33px;
    border:1px solid #cbd5db;
    padding:0 7px;
    background:#fff
}
.crud-row-actions {
    display:flex;
    gap:5px;
    align-items:center;
    justify-content:flex-end;
    margin-top:18px;
    white-space:nowrap
}
.color-image-cell>.color-image-label {
    margin:0 0 5px 27px
}
.color-image-preview {
    height:72px;
    border:1px solid #dfe6ea;
    background:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:4px;
    border-radius:4px
}
.color-image-preview img {
    width:100%;
    height:62px;
    object-fit:contain
}
.color-image-preview span {
    font-size:11px;
    color:#a0adb5
}
.color-image-select-wrap {
    display:flex;
    align-items:center;
    gap:9px
}
.color-main-radio {
    display:flex!important;
    align-items:center;
    justify-content:center;
    margin:0!important;
    cursor:pointer
}
.color-main-radio input {
    width:18px!important;
    height:18px!important;
    margin:0;
    accent-color:#3924b9
}
.color-main-radio input:disabled {
    opacity:.35;
    cursor:not-allowed
}
.color-image-select-wrap .color-image-preview {
    flex:1;
    min-width:0
}
.color-image-roles {
    display:flex;
    gap:5px;
    align-items:center;
    justify-content:center;
    padding-bottom:1px
}
.color-role-option {
    display:flex!important;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:4px;
    min-width:42px;
    margin:0!important;
    font-size:11px!important;
    color:#61737f!important;
    cursor:pointer
}
.color-role-option input {
    width:16px!important;
    height:16px!important;
    margin:0;
    accent-color:#3924b9
}
.color-role-option input:disabled+span {
    opacity:.35
}
.color-role-option:has(input:checked) span {
    color:#3924b9;
    font-weight:800
}
.color-role-help {
    display:block;
    margin-top:4px;
    font-size:11px;
    color:#8a9aa4;
    font-weight:400
}
.crud-section-head>div:first-child h3 {
    display:inline-block
}
.vehicle-edit-form {
    margin-top:16px;
    border:1px solid #dfe6ea;
    background:#fbfdfe;
    padding:12px
}
.vehicle-edit-grid {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:10px
}
.vehicle-edit-grid label {
    display:block;
    font-size:14px;
    color:#748792;
    margin-bottom:4px
}
.vehicle-edit-grid input,.vehicle-edit-grid select {
    width:100%;
    height:36px;
    border:1px solid #c8d3d9;
    padding:4px 8px
}
.vehicle-edit-actions {
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:10px
}
@media(max-width:1100px) {
    .crud-form,.vehicle-edit-grid {
        grid-template-columns:repeat(2,1fr)
    }
    .crud-row,.crud-row.color-row,.crud-row.price-row {
        grid-template-columns:repeat(2,1fr)
    }
    .color-image-roles {
        justify-content:flex-start
    }
    .crud-row-actions {
        grid-column:1/-1;
        margin-top:0;
        justify-content:flex-start
    }
}
@media(max-width:650px) {
    .crud-form,.vehicle-edit-grid,.crud-row,.crud-row.color-row,.crud-row.price-row {
        grid-template-columns:1fr
    }
    .crud-form .wide {
        grid-column:auto
    }
}
.admin-user-area strong,.admin-user-area span,.admin-user-area a {
    display:block
}
.admin-user-area span {
    margin-top:2px;
    font-size:14px;
    color:#8da0ab
}
.admin-user-area .logout {
    margin-top:5px;
    font-size:14px;
    color:#25bcd0;
    text-decoration:none
}
.admin-user-area .logout:hover {
    text-decoration:underline
}
.admin-user-area .role {
    display:inline-block;
    margin-top:4px;
    padding:2px 6px;
    border-radius:999px;
    background:#eef2ff;
    color:#4338ca;
    font-size:14px;
    font-weight:800
}
.filter-top {
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:18px;
    flex-wrap:wrap
}
.filter-left-group {
    display:flex;
    align-items:flex-end;
    gap:10px;
    flex-wrap:wrap
}
.filter-left-group .filter-group {
    width:145px
}
.keyword-group {
    margin-left:auto;
    min-width:520px
}
.bulk-action-bar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-top:12px;
    padding:8px 10px;
    border:1px solid #dfe7eb;
    background:#f7f9fb
}
.bulk-selection {
    font-size:14px;
    color:#647985
}
.bulk-delete-btn {
    border:0;
    background:#e93442;
    color:#fff;
    padding:8px 13px;
    font-weight:800;
    cursor:pointer
}
.bulk-delete-btn:disabled {
    opacity:.4;
    cursor:not-allowed
}
.admin-table input[type="checkbox"] {
    width:15px;
    height:15px;
    cursor:pointer
}
@media(max-width:1100px) {
    .keyword-group {
        margin-left:0;
        min-width:100%;
        width:100%
    }
}
/* 필터 간격 최종 보정 */
.filter-top {
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
}
.filter-left-group {
    display:flex;
    align-items:flex-end;
    gap:6px;
    flex-wrap:nowrap;
}
.filter-left-group .filter-group {
    width:112px;
    flex:0 0 112px;
}
.filter-left-group .filter-group label {
    margin-bottom:4px;
}
.filter-left-group .filter-group select {
    width:100%;
}
.keyword-group {
    margin-left:auto;
}
@media(max-width:900px) {
    .filter-left-group {
        flex-wrap:wrap;
    }
    .keyword-group {
        width:100%;
        min-width:0;
        margin-left:0;
    }
}
.vehicle-name-link {
    color:#2457e6;
    font-weight:700;
    text-decoration:none;
}
.vehicle-name-link:hover {
    text-decoration:underline;
}
.bulk-tools {
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:8px;
    flex-wrap:wrap;
}
.bulk-update-form {
    display:flex;
    align-items:center;
    gap:6px;
}
.bulk-update-form select {
    height:34px;
    min-width:118px;
    border:1px solid #c8d4da;
    background:#fff;
    padding:0 8px;
    color:#516772;
}
.bulk-change-btn {
    height:34px;
    border:0;
    background:#25bcd0;
    color:#fff;
    padding:0 13px;
    font-weight:800;
    cursor:pointer;
}
.bulk-change-btn:disabled {
    opacity:.4;
    cursor:not-allowed;
}
@media(max-width:800px) {
    .bulk-action-bar {
        align-items:flex-start;
        gap:8px;
        flex-direction:column;
    }
    .bulk-tools {
        width:100%;
        justify-content:flex-start;
    }
}
.bulk-save-btn {
    border:0;
    background:#25bcd0;
    color:#fff;
    padding:9px 14px;
    font-weight:800;
    cursor:pointer;
    border-radius:0;
    white-space:nowrap;
}
.bulk-save-btn:hover {
    opacity:.92
}
.bulk-help {
    margin:10px 0 12px;
    padding:9px 12px;
    border:1px solid #dce7ec;
    background:#f7fbfd;
    color:#60737f;
    font-size:14px;
}
.hidden-bulk-form {
    display:none
}
</style>
<style>
@media(max-width:900px) {
    html,body {
        overflow-x:hidden
    }
    .admin-layout {
        display:block;
        min-height:100dvh
    }
    .sidebar {
        position:static;
        width:100%;
        height:auto;
        padding:0 10px 10px;
        border-right:0;
        border-bottom:1px solid #dbe4e9;
    }
    .logo-area {
        height:auto;
        margin-bottom:0;
        padding-bottom:14px
    }
    .admin-menu {
        gap:10px
    }
    .admin-menu-group {
        padding:10px
    }
    .menu-section {
        margin-bottom:0
    }
    .menu-section>p {
        margin-top:0
    }
    .menu-item {
        padding:9px 10px
    }
    .menu-icon {
        flex-basis:32px;
        width:32px;
        height:32px
    }
    .menu-text strong {
        font-size:14px
    }
    .menu-text small {
        font-size:14px
    }
    .sidebar-stats {
        display:none
    }
    .page-header {
        height:auto;
        min-height:50px;
        padding:10px 12px;
        flex-wrap:wrap;
    }
    .page-header h1 {
        font-size:16px
    }
    .page-header p {
        font-size:14px;
        margin-left:5px
    }
    .admin-card {
        margin:10px 8px;
        padding:12px
    }
    .card-title {
        display:block;
        margin-bottom:12px
    }
    .card-title h2 {
        font-size:16px
    }
    .card-title p {
        font-size:14px;
        line-height:1.45
    }
    .new-btn,.gray-btn {
        display:inline-flex;
        margin-top:8px
    }
    .filter-top {
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:7px
    }
    .filter-group {
        width:100%
    }
    .keyword-group {
        grid-column:1/-1;
        min-width:0;
        width:100%;
        margin:0
    }
    .keyword-row {
        display:grid;
        grid-template-columns:1fr;
        gap:6px
    }
    .keyword-row select,.keyword-row input,.search-btn {
        width:100%!important;
        min-width:0!important;
        height:40px;
        font-size:16px;
    }
    .table-wrap {
        width:100%;
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
    }
    .admin-table {
        min-width:1120px
    }
    .detail-top,.detail-columns {
        grid-template-columns:1fr
    }
    .preview {
        min-height:180px
    }
    .preview img {
        height:190px
    }
    .info-table {
        grid-template-columns:1fr
    }
    .info-table div {
        grid-template-columns:90px minmax(0,1fr)
    }
    .info-table span,.info-table b {
        padding:10px;
        font-size:14px;
        overflow-wrap:anywhere
    }
    .detail-columns {
        gap:10px
    }
    .detail-box {
        padding:11px
    }
    .import-flow {
        font-size:14px;
        line-height:1.5
    }
    .upload-box {
        padding:12px;
        min-height:100px
    }
    .crud-form,.vehicle-edit-grid,.crud-row,.crud-row.color-row,.crud-row.price-row {
        grid-template-columns:1fr!important;
    }
    .crud-form .wide {
        grid-column:auto
    }
    .crud-actions,.vehicle-edit-actions {
        justify-content:stretch;
        flex-wrap:wrap;
    }
    .crud-actions button,.vehicle-edit-actions button {
        flex:1 1 120px;
        min-height:38px;
    }
    .crud-row-actions {
        grid-column:auto
    }
    .crud-row input,.crud-row select, .crud-form input,.crud-form select,.crud-form textarea, .vehicle-edit-grid input,.vehicle-edit-grid select {
        font-size:16px;
    }
}
@media(max-width:500px) {
    .filter-top {
        grid-template-columns:1fr
    }
    .keyword-group {
        grid-column:auto
    }
    .admin-card {
        margin-left:5px;
        margin-right:5px
    }
    .crud-toolbar {
        flex-wrap:wrap
    }
}
</style>
<style>
.admin-layout {
    grid-template-columns:228px minmax(0,1fr)!important
}
@media(max-width:900px) {
    .admin-layout {
        grid-template-columns:1fr!important
    }
}
</style>
<style>
.product-create-card {
    border-top:3px solid #3924b9
}
.vehicle-create-grid {
    display:grid;
    grid-template-columns:repeat(5,minmax(120px,1fr));
    gap:10px
}
.vehicle-create-grid label {
    display:block;
    font-size:14px;
    color:#6f818d;
    font-weight:700;
    margin-bottom:5px
}
.vehicle-create-grid input,.vehicle-create-grid select {
    width:100%;
    height:38px;
    border:1px solid #c7d2d9;
    background:#fff;
    padding:0 9px
}
.vehicle-create-grid input[type=file] {
    padding:7px
}
.vehicle-create-grid .wide {
    grid-column:span 2
}
.vehicle-create-grid small {
    display:block;
    color:#91a0a9;
    margin-top:4px
}
.create-actions {
    grid-column:1/-1;
    display:flex;
    justify-content:flex-end
}
.product-thumb-link {
    display:inline-block;
    line-height:0;
    border-radius:4px;
    cursor:pointer
}
.product-thumb {
    width:88px;
    height:54px;
    object-fit:contain;
    display:block;
    margin:auto;
    background:#f5f7f8;
    border:1px solid #e3e8eb;
    transition:border-color .15s ease,box-shadow .15s ease
}
.product-thumb-link:hover .product-thumb,.product-thumb-link:focus-visible .product-thumb {
    border-color:#3924b9;
    box-shadow:0 0 0 2px rgba(57,36,185,.12)
}
.no-thumb {
    display:inline-flex;
    width:88px;
    height:54px;
    align-items:center;
    justify-content:center;
    background:#f6f7f8;
    color:#a2adb4;
    font-size:14px
}
.mini-flag {
    display:inline-block;
    padding:3px 6px;
    border-radius:3px;
    font-size:14px;
    font-weight:800
}
.best-flag {
    background:#ff4d26;
    color:#fff
}
.rec-flag {
    background:#3924b9;
    color:#fff
}
.admin-table td {
    vertical-align:middle
}
.admin-table tbody tr {
    height:68px
}
.admin-table .vehicle-name {
    font-weight:800
}
.page-header {
    position:sticky;
    top:0;
    z-index:15
}
.bulk-action-bar {
    position:static;
    top:auto;
    z-index:auto
}
.card-title .gray-btn {
    display:inline-flex;
    align-items:center;
    background:#fff
}
.detail-card {
    scroll-margin-top:120px
}
@media(max-width:1200px) {
    .vehicle-create-grid {
        grid-template-columns:repeat(3,1fr)
    }
}
@media(max-width:700px) {
    .vehicle-create-grid {
        grid-template-columns:1fr
    }
    .vehicle-create-grid .wide {
        grid-column:auto
    }
}
</style>
<style>
/* 차량 상세관리: 추가와 수정 영역 명확하게 구분 */
.crud-section-head {
    gap:12px
}
.crud-section-head-actions {
    display:flex;
    align-items:center;
    gap:8px
}
.new-item-btn {
    height:34px;
    padding:0 13px;
    border:1px solid #3924b9;
    background:#fff;
    color:#3924b9;
    font-weight:800;
    cursor:pointer
}
.new-item-btn:hover {
    background:#f4f1ff
}
.new-item-btn.is-open {
    background:#3924b9;
    color:#fff
}
.crud-add-panel {
    display:none;
    margin:12px;
    border:1px solid #b9afea;
    background:#f8f7ff
}
.crud-add-panel.is-open {
    display:block
}
.crud-add-panel-title {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:10px 12px;
    border-bottom:1px solid #ddd7f5;
    background:#f0edff
}
.crud-add-panel-title strong {
    font-size:14px;
    color:#3924b9
}
.crud-add-panel-title span {
    font-size:13px;
    color:#798691
}
.crud-add-panel .crud-form {
    padding:12px
}
.crud-add-panel .add-btn {
    background:#3924b9;
    padding:9px 14px
}
.crud-row-actions .small-btn:not(.delete) {
    background:#eef0ff;
    color:#3924b9;
    border:1px solid #d8d3ff
}
.bulk-save-btn {
    background:#25384a
}
.vehicle-edit-form {
    margin-top:20px;
    border:1px solid #d9e1e6;
    background:#fff;
    padding:0;
    overflow:hidden
}
.vehicle-edit-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:14px 16px;
    background:#f6f8fa;
    border-bottom:1px solid #e1e7eb
}
.vehicle-edit-header h3 {
    margin:0;
    font-size:15px;
    font-weight:800;
    color:#25384a
}
.vehicle-edit-header span {
    font-size:13px;
    color:#8a9aa5
}
.vehicle-edit-header-actions {
    display:flex;
    align-items:center;
    gap:8px;
    margin-left:auto
}
.vehicle-edit-header-actions .save-btn {
    min-width:72px;
    height:34px;
    padding:0 16px;
    font-size:13px
}
.vehicle-edit-body {
    padding:16px
}
.vehicle-edit-group {
    padding:15px;
    border:1px solid #e4eaee;
    background:#fbfcfd
}
.vehicle-edit-group+.vehicle-edit-group {
    margin-top:12px
}
.vehicle-edit-group-title {
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:12px
}
.vehicle-edit-group-title strong {
    font-size:14px;
    color:#25384a
}
.vehicle-edit-group-title span {
    font-size:12px;
    color:#94a2ab
}
.vehicle-edit-grid {
    display:grid;
    grid-template-columns:repeat(5,minmax(120px,1fr));
    gap:12px
}
.vehicle-edit-grid.basic-grid {
    grid-template-columns:1.1fr 1.4fr .8fr 1fr 1fr
}
.vehicle-edit-grid.setting-grid {
    grid-template-columns:repeat(4,minmax(120px,1fr))
}
.vehicle-edit-field label {
    display:block;
    font-size:13px;
    font-weight:700;
    color:#657985;
    margin-bottom:6px
}
.vehicle-edit-field input,.vehicle-edit-field select {
    width:100%;
    height:40px;
    border:1px solid #c9d4da;
    background:#fff;
    padding:0 10px;
    color:#25384a;
    border-radius:3px
}
.vehicle-edit-field input:focus,.vehicle-edit-field select:focus {
    outline:none;
    border-color:#3924b9;
    box-shadow:0 0 0 2px rgba(57,36,185,.08)
}
.vehicle-edit-image-grid {
    display:grid;
    grid-template-columns:180px minmax(0,1fr);
    gap:14px;
    align-items:stretch
}
.vehicle-edit-image-preview {
    min-height:132px;
    border:1px solid #dfe6ea;
    background:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:8px
}
.vehicle-edit-image-preview img {
    max-width:100%;
    max-height:116px;
    object-fit:contain
}
.vehicle-edit-image-preview span {
    font-size:13px;
    color:#9aa8b0
}
.vehicle-edit-image-fields {
    display:grid;
    grid-template-columns:1fr;
    gap:10px
}
.vehicle-edit-image-fields input[type=file] {
    height:auto;
    min-height:40px;
    padding:7px 9px
}
.vehicle-edit-help {
    display:block;
    margin-top:5px;
    font-size:12px;
    color:#97a5ae
}
.vehicle-edit-actions {
    display:flex;
    justify-content:flex-end;
    gap:8px;
    padding:14px 16px;
    border-top:1px solid #e1e7eb;
    background:#fafbfc;
    margin-top:0
}
.vehicle-edit-actions .save-btn {
    min-width:110px;
    height:40px;
    padding:0 22px;
    font-size:14px
}
.thumbnail-choice-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px
}
.thumbnail-choice-box {
    border:1px solid #dfe6ea;
    background:#fff;
    padding:12px
}
.thumbnail-choice-box>strong {
    display:block;
    font-size:13px;
    color:#25384a;
    margin-bottom:9px
}
.thumbnail-options {
    display:flex;
    gap:8px;
    flex-wrap:wrap
}
.thumbnail-option {
    position:relative;
    display:block;
    width:112px;
    cursor:pointer
}
.thumbnail-option input {
    position:absolute;
    opacity:0;
    pointer-events:none
}
.thumbnail-option-card {
    height:86px;
    border:2px solid #e1e7eb;
    background:#f8fafb;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:5px;
    transition:.15s
}
.thumbnail-option-card img {
    width:100%;
    height:56px;
    object-fit:contain;
    display:block
}
.thumbnail-option-card span {
    font-size:11px;
    color:#687b87;
    max-width:100%;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap
}
.thumbnail-option input:checked+.thumbnail-option-card {
    border-color:#3924b9;
    background:#f4f1ff;
    box-shadow:0 0 0 2px rgba(57,36,185,.08)
}
.thumbnail-option input:checked+.thumbnail-option-card span {
    color:#3924b9;
    font-weight:800
}
.vehicle-edit-empty {
    padding:14px;
    border:1px dashed #d8e0e6;
    background:#f8fafb;
    color:#62727d;
    font-size:13px;
    border-radius:10px
}
.thumbnail-migration-note {
    padding:12px;
    border:1px solid #f0c36d;
    background:#fff9e9;
    color:#795b1d;
    font-size:13px
}
@media(max-width:900px) {
    .thumbnail-choice-grid {
        grid-template-columns:1fr
    }
}
@media(max-width:1200px) {
    .vehicle-edit-grid.basic-grid {
        grid-template-columns:repeat(3,1fr)
    }
    .vehicle-edit-grid.setting-grid {
        grid-template-columns:repeat(2,1fr)
    }
}
@media(max-width:750px) {
    .vehicle-edit-header {
        align-items:center;
        flex-direction:row
    }
    .vehicle-edit-header-actions {
        margin-left:auto
    }
    .vehicle-edit-grid.basic-grid,.vehicle-edit-grid.setting-grid {
        grid-template-columns:1fr
    }
    .vehicle-edit-image-grid {
        grid-template-columns:1fr
    }
    .vehicle-edit-image-preview {
        min-height:170px
    }
}
@media(max-width:650px) {
    .crud-section-head {
        align-items:flex-start;
        flex-direction:column
    }
    .crud-section-head-actions {
        width:100%;
        flex-wrap:wrap
    }
    .new-item-btn,.bulk-save-btn {
        flex:1
    }
    .crud-add-panel-title {
        align-items:flex-start;
        gap:4px;
        flex-direction:column
    }
}
</style>
<link rel="stylesheet" href="./admin-ui.css"></head>
<body>
<div class="admin-layout">
    
    <?php $currentAdminPage = 'vehicles'; require __DIR__ . '/sidebar.php'; ?>


    <main class="main">
        <?php if (!canEditVehicleData()): ?>
            <div class="crud-alert error">현재 계정은 조회만 가능합니다. SUPER_ADMIN에게 등록·수정·삭제 권한을 요청해주세요.</div>
        <?php elseif (isSalesAdmin()): ?>
            <div class="crud-alert ok">
                영업사원 작업 권한 ·
                등록 <?= canCreateVehicleData() ? '허용' : '차단' ?> /
                수정 <?= canUpdateVehicleData() ? '허용' : '차단' ?> /
                삭제 <?= canDeleteVehicleData() ? '허용' : '차단' ?>
            </div>
        <?php endif; ?>
        <?php if ($crudMessage): ?>
            <div class="crud-alert ok"><?= h($crudMessage) ?></div>
        <?php endif; ?>
        <?php if ($crudError): ?>
            <div class="crud-alert error"><?= h($crudError) ?></div>
        <?php endif; ?>

        <section class="card ag-page-card">
            <div class="top">
                <div>
                    <h1><?= $isVehicleDetailPage ? '차량 상세관리' : '차량 데이터 관리' ?></h1>
                    <p><?= $isVehicleDetailPage ? '차량 기본정보와 색상·트림·가격을 관리합니다.' : '차량·이미지·트림·색상·렌트/리스 가격을 통합 관리합니다.' ?></p>
                </div>
                <?php if ($isVehicleDetailPage): ?>
                    <a class="gray-btn" href="./vehicles.php?<?= h(adminQuery(['vehicle_id' => null])) ?>#product-list">← 차량 목록</a>
                <?php else: ?>
                    <div class="top-stats"><span class="stat">등록 차량 <b><?= number_format($totalRows) ?></b></span></div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($dashboardDbError): ?>
            <div class="alert error">
                <strong>DB 연결 오류</strong>
                <span><?= h($dashboardDbError) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$isVehicleDetailPage && canCreateVehicleData()): ?>
        <section id="vehicle-create" class="admin-card product-create-card" hidden>
            <div class="card-title">
                <div>
                    <h2>차량 상품 등록</h2>
                    <p>기본 상품정보를 먼저 등록한 뒤, 아래 상세관리에서 색상·트림·가격을 연결할 수 있습니다.</p>
                </div>
            </div>
            <form method="post" enctype="multipart/form-data" class="vehicle-create-grid">
                <input type="hidden" name="crud_action" value="add_vehicle_manual">
                <div><label>브랜드 *</label><select name="brand_id" required><option value="">선택</option><?php foreach ($brandOptions as $brand): ?><option value="<?= (int)$brand['id'] ?>"><?= h($brand['name']) ?></option><?php endforeach; ?></select></div>
                <div><label>차량명 *</label><input type="text" name="name" required placeholder="예: 5시리즈"></div>
                <div><label>연식</label><input type="number" name="model_year" placeholder="2027"></div>
                <div><label>연료</label><select name="fuel_type"><?php foreach (['GASOLINE','DIESEL','HYBRID','PHEV','EV','LPG','OTHER'] as $fuel): ?><option value="<?= h($fuel) ?>"><?= h($fuel) ?></option><?php endforeach; ?></select></div>
                <div><label>차량가</label><input type="number" name="base_price" value="0"></div>
                <div><label>정렬순서</label><input type="number" name="sort_order" value="0"></div>
                <div><label>BEST</label><select name="is_best"><option value="0">일반</option><option value="1">BEST</option></select></div>
                <?php if ($hasRecommended): ?><div><label>추천차량</label><select name="is_recommended"><option value="0">일반</option><option value="1">추천</option></select></div><?php endif; ?>
                <div><label>노출상태</label><select name="is_active"><option value="1">노출</option><option value="0">비노출</option></select></div>
                <div class="wide"><label>대표 이미지 업로드</label><input type="file" name="vehicle_image" accept="image/*"><small>원본 파일명을 그대로 유지합니다.</small></div>
                <div class="wide"><label>또는 기존 이미지 경로</label><input type="text" name="image_path" placeholder="images/cars/.../차량.webp"></div>
                <div class="create-actions"><button type="submit" class="save-btn">차량 등록</button></div>
            </form>
        </section>
        <?php endif; ?>

        <?php if (!$isVehicleDetailPage): ?>
        <section id="product-list" class="admin-card">
            <div class="card-title">
                <div>
                    <h2>차량 상품 목록</h2>
                    <p>등록된 차량 상품 <strong><?= number_format($totalRows) ?></strong>개를 관리합니다.</p>
                </div>
                <div style="display:flex;gap:8px">
                    <?php if (canCreateVehicleData()): ?><button type="button" class="gray-btn" id="toggleVehicleCreate" aria-controls="vehicle-create" aria-expanded="false">+ 차량등록</button><?php endif; ?>
                    <?php if (canCreateVehicleData() && canUpdateVehicleData()): ?><a class="new-btn" href="#bulk-import">+ 엑셀 일괄등록</a><?php endif; ?>
                </div>
            </div>

            <form method="get" action="./vehicles.php" class="filter-panel" id="searchForm">
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
                            <select name="per_page" class="per-page" onchange="this.form.submit()">
                                <?php foreach ([10,20,50,100] as $n): ?>
                                    <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?>개씩</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="search-btn">검색</button>
                        </div>
                    </div>
                </div>

                <div class="filter-actions">
                    <a href="./vehicles.php#product-list">검색조건 초기화</a>
                </div>
            </form>

            <div class="bulk-action-bar">
                <div class="bulk-selection">
                    선택 <strong id="selectedCount">0</strong>개
                </div>

                <?php if (canUpdateVehicleData() || canDeleteVehicleData()): ?>
                <div class="bulk-tools">
                    <?php if (canUpdateVehicleData()): ?>
                    <form method="post" id="bulkUpdateForm" class="bulk-update-form">
                        <input type="hidden" name="crud_action" value="bulk_update_vehicles">

                        <select name="bulk_field" id="bulkField" aria-label="일괄 변경 항목">
                            <option value="">변경 항목</option>
                            <option value="is_active">노출상태</option>
                            <option value="is_best">BEST 여부</option>
                            <?php if ($hasRecommended): ?><option value="is_recommended">추천 여부</option><?php endif; ?>
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
                    <?php endif; ?>

                    <?php if (canDeleteVehicleData()): ?>
                    <form method="post" id="bulkDeleteForm"
                          onsubmit="return confirm('선택한 차량과 연결된 색상·트림·가격 데이터를 삭제할까요?');">
                        <input type="hidden" name="crud_action" value="bulk_delete_vehicles">
                        <button type="submit" id="bulkDeleteBtn" class="bulk-delete-btn" disabled>
                            선택 삭제
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="check"><input type="checkbox" id="checkAll" aria-label="현재 페이지 전체 선택"></th>
                            <th>No.</th>
                            <th>이미지</th>
                            <th>상태</th>
                            <th>등록일시</th>
                            <th>브랜드</th>
                            <th>차량명</th>
                            <th>연식</th>
                            <th>연료</th>
                            <th>차량가</th>
                            <th>BEST</th>
                            <?php if ($hasRecommended): ?><th>추천</th><?php endif; ?>
                            <th>색상</th>
                            <th>트림</th>
                            <th>가격</th>
                            <th>상세</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$vehicleRows): ?>
                            <tr><td colspan="16" class="empty">검색 결과가 없습니다.</td></tr>
                        <?php else: ?>
                            <?php foreach ($vehicleRows as $row): ?>
                                <tr>
                                    <td><input type="checkbox" class="row-check" value="<?= (int)$row['id'] ?>" aria-label="<?= h($row['name']) ?> 선택"></td>
                                    <td class="number"><?= number_format((int)$row['id']) ?></td>
                                    <td><?php if (!empty($row['admin_thumbnail_path'])): ?><a class="product-thumb-link" href="./vehicle-detail.php?<?= h(adminQuery(['vehicle_id' => (int)$row['id']])) ?>" aria-label="<?= h($row['name']) ?> 상세 보기"><img class="product-thumb" src="../<?= h($row['admin_thumbnail_path']) ?>" alt="<?= h($row['name']) ?>"></a><?php else: ?><span class="no-thumb">No image</span><?php endif; ?></td>
                                    <td>
                                        <?= (int)$row['is_active'] === 1
                                            ? '<span class="status status-active">사용중</span>'
                                            : '<span class="status status-off">비활성</span>' ?>
                                    </td>
                                    <td><?= !empty($row['created_at']) ? h(date('y-m-d H:i', strtotime($row['created_at']))) : '-' ?></td>
                                    <td><?= h($row['brand_name']) ?></td>
                                    <td class="vehicle-name">
                                        <a class="vehicle-name-link"
                                           href="./vehicle-detail.php?<?= h(adminQuery(['vehicle_id' => (int)$row['id']])) ?>">
                                            <?= h($row['name']) ?>
                                        </a>
                                        <?php if ((int)$row['is_best'] === 1): ?><span class="best">BEST</span><?php endif; ?>
                                    </td>
                                    <td><?= $row['model_year'] ? h((string)$row['model_year']) : '-' ?></td>
                                    <td><?= h($row['fuel_type']) ?></td>
                                    <td><?= (int)$row['base_price'] > 0 ? number_format((int)$row['base_price']).'원' : '-' ?></td>
                                    <td><?= (int)$row['is_best'] === 1 ? '<span class="mini-flag best-flag">BEST</span>' : '-' ?></td>
                                    <?php if ($hasRecommended): ?><td><?= (int)$row['is_recommended'] === 1 ? '<span class="mini-flag rec-flag">추천</span>' : '-' ?></td><?php endif; ?>
                                    <td><?= number_format((int)$row['color_count']) ?>개</td>
                                    <td><?= number_format((int)$row['trim_count']) ?>개</td>
                                    <td><?= number_format((int)$row['price_count']) ?>건</td>
                                    <td>
                                        <a class="detail-btn" href="./vehicle-detail.php?<?= h(adminQuery(['vehicle_id' => (int)$row['id']])) ?>">보기</a>
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
                    <a href="./vehicles.php?<?= h(adminQuery(['page' => 1, 'vehicle_id' => null])) ?>#product-list">처음</a>
                    <a href="./vehicles.php?<?= h(adminQuery(['page' => $page-1, 'vehicle_id' => null])) ?>#product-list">‹</a>
                <?php endif; ?>

                <?php
                    $start = max(1, $page - 4);
                    $end = min($totalPages, $start + 8);
                    $start = max(1, $end - 8);
                    for ($p = $start; $p <= $end; $p++):
                ?>
                    <a class="<?= $p === $page ? 'active' : '' ?>" href="./vehicles.php?<?= h(adminQuery(['page' => $p, 'vehicle_id' => null])) ?>#product-list"><?= $p ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="./vehicles.php?<?= h(adminQuery(['page' => $page+1, 'vehicle_id' => null])) ?>#product-list">›</a>
                    <a href="./vehicles.php?<?= h(adminQuery(['page' => $totalPages, 'vehicle_id' => null])) ?>#product-list">마지막</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($isVehicleDetailPage && !$vehicleDetail && !$dashboardDbError): ?>
            <section class="admin-card"><div class="card-title"><div><h2>차량 정보를 찾을 수 없습니다.</h2><p>삭제되었거나 존재하지 않는 차량입니다.</p></div><a class="gray-btn" href="./vehicles.php?<?= h(adminQuery(['vehicle_id' => null])) ?>#product-list">목록으로</a></div></section>
        <?php endif; ?>

        <?php if ($isVehicleDetailPage && $vehicleDetail): ?>
        <section id="vehicle-detail" class="admin-card detail-card">
            <div class="card-title">
                <div>
                    <h2><?= h($vehicleDetail['brand_name']) ?> <?= h($vehicleDetail['name']) ?></h2>
                    <p>차량 기본정보와 연결된 색상·트림·가격을 직접 수정·추가·삭제할 수 있습니다.</p>
                </div>
                <div class="crud-toolbar">
                    <a class="gray-btn" href="./vehicles.php?<?= h(adminQuery(['vehicle_id' => null])) ?>#product-list">목록으로</a>
                    <?php if (canDeleteVehicleData()): ?>
                    <form method="post" onsubmit="return confirm('이 차량과 연결된 색상·트림·가격 데이터를 모두 삭제할까요?');">
                        <input type="hidden" name="crud_action" value="delete_vehicle">
                        <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                        <button class="danger-btn" type="submit">차량 삭제</button>
                    </form>
                    <?php endif; ?>
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

            <form method="post" enctype="multipart/form-data" class="vehicle-edit-form">
                <input type="hidden" name="crud_action" value="update_vehicle">
                <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">

                <div class="vehicle-edit-header">
                    <h3>차량 기본정보 수정</h3>
                    <div class="vehicle-edit-header-actions">
                        <?php if (canUpdateVehicleData()): ?>
                        <button type="submit" class="save-btn">저장</button>
                        <?php else: ?>
                        <span class="bulk-help">수정 권한이 없습니다.</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="vehicle-edit-body">
                    <div class="vehicle-edit-group">
                        <div class="vehicle-edit-group-title">
                            <strong>기본 정보</strong>
                            <span>차량을 구분하는 핵심 정보입니다.</span>
                        </div>
                        <div class="vehicle-edit-grid basic-grid">
                            <div class="vehicle-edit-field">
                                <label>브랜드</label>
                                <select name="brand_id" required>
                                    <?php foreach ($brandOptions as $brand): ?>
                                        <option value="<?= (int)$brand['id'] ?>" <?= (int)$vehicleDetail['brand_id']===(int)$brand['id']?'selected':'' ?>><?= h($brand['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="vehicle-edit-field">
                                <label>차량명</label>
                                <input type="text" name="name" value="<?= h($vehicleDetail['name']) ?>" required>
                            </div>
                            <div class="vehicle-edit-field">
                                <label>연식</label>
                                <input type="number" name="model_year" value="<?= h((string)($vehicleDetail['model_year'] ?? '')) ?>" placeholder="예: 2027">
                            </div>
                            <div class="vehicle-edit-field">
                                <label>연료</label>
                                <select name="fuel_type">
                                    <?php foreach (['GASOLINE','DIESEL','HYBRID','PHEV','EV','LPG','OTHER'] as $fuel): ?>
                                        <option value="<?= h($fuel) ?>" <?= $vehicleDetail['fuel_type'] === $fuel ? 'selected' : '' ?>><?= h($fuel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="vehicle-edit-field">
                                <label>차량가</label>
                                <input type="number" name="base_price" value="<?= (int)$vehicleDetail['base_price'] ?>" step="1">
                            </div>
                        </div>
                    </div>

                    <div class="vehicle-edit-group">
                        <div class="vehicle-edit-group-title">
                            <strong>노출 · 정렬 설정</strong>
                            <span>목록 노출 여부와 표시 순서를 설정합니다.</span>
                        </div>
                        <div class="vehicle-edit-grid setting-grid">
                            <div class="vehicle-edit-field">
                                <label>BEST</label>
                                <select name="is_best">
                                    <option value="0" <?= (int)$vehicleDetail['is_best'] === 0 ? 'selected' : '' ?>>일반</option>
                                    <option value="1" <?= (int)$vehicleDetail['is_best'] === 1 ? 'selected' : '' ?>>BEST</option>
                                </select>
                            </div>
                            <?php if ($hasRecommended): ?>
                            <div class="vehicle-edit-field">
                                <label>추천차량</label>
                                <select name="is_recommended">
                                    <option value="0" <?= (int)($vehicleDetail['is_recommended'] ?? 0) === 0 ? 'selected' : '' ?>>일반</option>
                                    <option value="1" <?= (int)($vehicleDetail['is_recommended'] ?? 0) === 1 ? 'selected' : '' ?>>추천</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="vehicle-edit-field">
                                <label>정렬순서</label>
                                <input type="number" name="sort_order" value="<?= (int)$vehicleDetail['sort_order'] ?>">
                            </div>
                            <div class="vehicle-edit-field">
                                <label>상태</label>
                                <select name="is_active">
                                    <option value="1" <?= (int)$vehicleDetail['is_active'] === 1 ? 'selected' : '' ?>>사용중</option>
                                    <option value="0" <?= (int)$vehicleDetail['is_active'] === 0 ? 'selected' : '' ?>>비활성</option>
                                </select>
                            </div>
                        </div>
                    </div>

                </div>

            </form>

            <div class="crud-section">
                <div class="crud-section-head">
                    <div><h3>색상 관리 (<?= count($detailColors) ?>)</h3><span class="color-role-help">차량 이미지 왼쪽의 동그란 버튼으로 공통 대표 이미지를 하나 선택한 뒤 전체 변경사항 저장을 눌러주세요.</span></div>
                    <div class="crud-section-head-actions">
                        <?php if (canCreateVehicleData()): ?>
                        <button type="button" class="new-item-btn" data-target="addColorPanel" onclick="toggleAddPanel(this)">색상추가</button>
                        <?php endif; ?>
                        <?php if (!empty($detailColors) && canUpdateVehicleData()): ?>
                        <button type="button" class="bulk-save-btn" onclick="submitBulkSection('color')">전체 변경사항 저장</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (canCreateVehicleData()): ?>
                <div class="crud-add-panel" id="addColorPanel">
                    <div class="crud-add-panel-title"><strong>새 색상 등록</strong><span>기존 색상을 수정하려면 아래 등록된 색상에서 변경하세요.</span></div>
                <form method="post" class="crud-form">
                    <input type="hidden" name="crud_action" value="add_color">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                    <div><label>색상명</label><input type="text" name="color_name" required></div>
                    <div><label>HEX</label><input type="text" name="hex_code" placeholder="#ffffff"></div>
                    <div><label>테두리색</label><input type="text" name="border_color" placeholder="#dddddd"></div>
                    <div class="wide"><label>이미지 경로</label><input type="text" name="color_image_path"></div>
                    <div><label>정렬</label><input type="number" name="color_sort_order" value="<?= count($detailColors)+1 ?>"></div>
                    <div><label>상태</label><select name="color_is_active"><option value="1">사용중</option><option value="0">비활성</option></select></div>
                    <div class="crud-actions"><button class="add-btn" type="submit">새 색상 등록</button></div>
                </form>
                </div>
                <?php endif; ?>

                <?php if (canUpdateVehicleData()): ?>
                <form method="post" id="bulkColorForm" class="hidden-bulk-form">
                    <input type="hidden" name="crud_action" value="bulk_update_colors">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                </form>
                <?php endif; ?>

                <div class="crud-list">
                    <?php foreach ($detailColors as $color): ?>
                    <form method="post" class="crud-row color-row js-bulk-color-row">
                        <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                        <input type="hidden" name="color_id" value="<?= (int)$color['id'] ?>">
                        <div class="color-image-cell">
                            <label class="color-image-label">차량 이미지</label>
                            <div class="color-image-select-wrap">
                                <label class="color-main-radio">
                                    <input type="radio" form="bulkColorForm" name="representative_color_id" value="<?= (int)$color['id'] ?>" <?= !empty($color['image_path']) && (string)($vehicleDetail['image_path'] ?? '') === (string)$color['image_path'] ? 'checked' : '' ?> <?= empty($color['image_path']) ? 'disabled' : '' ?>>
                                </label>
                                <div class="color-image-preview">
                                    <?php if (!empty($color['image_path'])): ?>
                                        <img src="../<?= h($color['image_path']) ?>" alt="<?= h($color['name']) ?>">
                                    <?php else: ?>
                                        <span>이미지 없음</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div><label>색상명</label><input type="text" name="color_name" value="<?= h($color['name']) ?>"></div>
                        <div><label>HEX</label><input type="text" name="hex_code" value="<?= h((string)($color['hex_code'] ?? '')) ?>"></div>
                        <div><label>테두리</label><input type="text" name="border_color" value="<?= h((string)($color['border_color'] ?? '')) ?>"></div>
                        <div class="color-path-cell"><label>이미지 경로</label><input type="text" name="color_image_path" value="<?= h((string)($color['image_path'] ?? '')) ?>"></div>
                        <div><label>정렬</label><input type="number" name="color_sort_order" value="<?= (int)$color['sort_order'] ?>"></div>
                        <div><label>상태</label><select name="color_is_active"><option value="1" <?= (int)$color['is_active']===1?'selected':'' ?>>사용</option><option value="0" <?= (int)$color['is_active']===0?'selected':'' ?>>비활성</option></select></div>
                        <div class="crud-row-actions">
                            <?php if (canUpdateVehicleData()): ?><button class="small-btn" type="submit" name="crud_action" value="update_color">저장</button><?php endif; ?>
                            <?php if (canDeleteVehicleData()): ?><button class="small-btn delete" type="submit" name="crud_action" value="delete_color" onclick="return confirm('이 색상을 삭제할까요?');">삭제</button><?php endif; ?>
                        </div>
                    </form>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="crud-section">
                <div class="crud-section-head">
                    <h3>트림 관리 (<?= count($detailTrims) ?>)</h3>
                    <div class="crud-section-head-actions">
                        <?php if (canCreateVehicleData()): ?>
                        <button type="button" class="new-item-btn" data-target="addTrimPanel" onclick="toggleAddPanel(this)">트림추가</button>
                        <?php endif; ?>
                        <?php if (!empty($detailTrims) && canUpdateVehicleData()): ?>
                        <button type="button" class="bulk-save-btn" onclick="submitBulkSection('trim')">전체 변경사항 저장</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (canCreateVehicleData()): ?>
                <div class="crud-add-panel" id="addTrimPanel">
                    <div class="crud-add-panel-title"><strong>새 트림 등록</strong><span>기존 트림을 수정하려면 아래 등록된 트림에서 변경하세요.</span></div>
                <form method="post" class="crud-form">
                    <input type="hidden" name="crud_action" value="add_trim">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                    <div><label>트림명</label><input type="text" name="trim_name" required></div>
                    <div><label>차량가</label><input type="number" name="trim_price" value="0"></div>
                    <div class="wide"><label>설명</label><input type="text" name="trim_description"></div>
                    <div><label>정렬</label><input type="number" name="trim_sort_order" value="<?= count($detailTrims)+1 ?>"></div>
                    <div><label>상태</label><select name="trim_is_active"><option value="1">사용중</option><option value="0">비활성</option></select></div>
                    <div class="crud-actions"><button class="add-btn" type="submit">새 트림 등록</button></div>
                </form>
                </div>
                <?php endif; ?>

                <?php if (canUpdateVehicleData()): ?>
                <form method="post" id="bulkTrimForm" class="hidden-bulk-form">
                    <input type="hidden" name="crud_action" value="bulk_update_trims">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                </form>
                <?php endif; ?>

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
                            <?php if (canUpdateVehicleData()): ?><button class="small-btn" type="submit" name="crud_action" value="update_trim">저장</button><?php endif; ?>
                            <?php if (canDeleteVehicleData()): ?><button class="small-btn delete" type="submit" name="crud_action" value="delete_trim" onclick="return confirm('이 트림과 연결된 가격 데이터도 삭제됩니다. 계속할까요?');">삭제</button><?php endif; ?>
                        </div>
                    </form>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="crud-section">
                <div class="crud-section-head">
                    <h3>가격 관리 (<?= count($detailPrices) ?>)</h3>
                    <div class="crud-section-head-actions">
                        <?php if (canCreateVehicleData()): ?>
                        <button type="button" class="new-item-btn" data-target="addPricePanel" onclick="toggleAddPanel(this)">가격추가</button>
                        <?php endif; ?>
                        <?php if (!empty($detailPrices) && canUpdateVehicleData()): ?>
                        <button type="button" class="bulk-save-btn" onclick="submitBulkSection('price')">전체 변경사항 저장</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (canCreateVehicleData()): ?>
                <div class="crud-add-panel" id="addPricePanel">
                    <div class="crud-add-panel-title"><strong>새 가격조건 등록</strong><span>기존 가격조건을 수정하려면 아래 등록된 가격조건에서 변경하세요.</span></div>
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
                    <div class="crud-actions"><button class="add-btn" type="submit">새 가격조건 등록</button></div>
                </form>
                </div>
                <?php endif; ?>

                <?php if (canUpdateVehicleData()): ?>
                <form method="post" id="bulkPriceForm" class="hidden-bulk-form">
                    <input type="hidden" name="crud_action" value="bulk_update_prices">
                    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
                </form>
                <?php endif; ?>

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
                            <?php if (canUpdateVehicleData()): ?><button class="small-btn" type="submit" name="crud_action" value="update_price">저장</button><?php endif; ?>
                            <?php if (canDeleteVehicleData()): ?><button class="small-btn delete" type="submit" name="crud_action" value="delete_price" onclick="return confirm('이 가격 조건을 삭제할까요?');">삭제</button><?php endif; ?>
                        </div>
                    </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!$isVehicleDetailPage && canCreateVehicleData() && canUpdateVehicleData()): ?>
        <section id="bulk-import" class="admin-card import-card">
            <div class="card-title">
                <div>
                    <h2>엑셀 일괄등록</h2>
                    <p>한 장의 엑셀에 차량 정보를 입력하면 브랜드 → 차량 → 색상 → 트림 → 가격 순서로 자동 저장합니다.</p>
                </div>
                <a class="upload-template-btn" href="./download-vehicle-template.php">엑셀 양식 다운로드</a>
            </div>

            <div class="import-flow">
                <span>한 행 입력</span><b>→</b><span>브랜드 자동 생성</span><b>→</b>
                <span>차량 연결</span><b>→</b><span>색상·트림 연결</span><b>→</b><span>가격 저장</span>
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




const bulkUpdateForm = document.getElementById('bulkUpdateForm');
const bulkField = document.getElementById('bulkField');
const bulkValue = document.getElementById('bulkValue');
const bulkChangeBtn = document.getElementById('bulkChangeBtn');

const bulkOptions = {
    is_active: [
        ['1', '노출'],
        ['0', '비노출']
    ],
    is_best: [
        ['1', 'BEST 설정'],
        ['0', 'BEST 해제']
    ],
    is_recommended: [
        ['1', '추천 설정'],
        ['0', '추천 해제']
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

function toggleAddPanel(button) {
    const targetId = button.getAttribute('data-target');
    const panel = document.getElementById(targetId);
    if (!panel) return;

    const willOpen = !panel.classList.contains('is-open');
    document.querySelectorAll('.crud-add-panel.is-open').forEach(function(openPanel) {
        openPanel.classList.remove('is-open');
    });
    document.querySelectorAll('.new-item-btn.is-open').forEach(function(openButton) {
        openButton.classList.remove('is-open');
        const label = openButton.getAttribute('data-default-label');
        if (label) openButton.textContent = label;
    });

    if (willOpen) {
        if (!button.getAttribute('data-default-label')) button.setAttribute('data-default-label', button.textContent.trim());
        panel.classList.add('is-open');
        button.classList.add('is-open');
        button.textContent = '추가 취소';
        const firstField = panel.querySelector('input:not([type="hidden"]), select, textarea');
        if (firstField) firstField.focus();
        panel.scrollIntoView({behavior:'smooth', block:'nearest'});
    }
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



(function setupVehicleCreateToggle() {
    const button = document.getElementById('toggleVehicleCreate');
    const panel = document.getElementById('vehicle-create');
    if (!button || !panel) return;

    button.addEventListener('click', () => {
        const willOpen = panel.hasAttribute('hidden');
        if (willOpen) {
            panel.removeAttribute('hidden');
            button.setAttribute('aria-expanded', 'true');
            button.textContent = '등록폼 닫기';
            requestAnimationFrame(() => {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        } else {
            panel.setAttribute('hidden', '');
            button.setAttribute('aria-expanded', 'false');
            button.textContent = '+ 차량등록';
        }
    });
})();

// 차량 상세 편집 중 POST 후에도 현재 스크롤 위치를 유지합니다.
// 색상/트림/가격을 연속 등록할 때 페이지가 위에서 다시 내려오는 현상을 방지합니다.
(function preserveAdminVehicleScroll() {
    const scrollKey = 'autogenieAdminVehicleScroll:' + window.location.pathname + window.location.search;

    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }

    const saved = sessionStorage.getItem(scrollKey);
    if (saved !== null) {
        const y = Number(saved);
        sessionStorage.removeItem(scrollKey);

        if (Number.isFinite(y)) {
            // 브라우저의 기본 앵커/복원 동작보다 뒤에서 현재 위치를 확정합니다.
            requestAnimationFrame(() => {
                window.scrollTo(0, y);
                requestAnimationFrame(() => window.scrollTo(0, y));
            });
        }
    }

    document.querySelectorAll('form').forEach(form => {
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post') return;

        form.addEventListener('submit', () => {
            // 현재 URL 기준 키와, POST 후 query string이 달라질 때를 대비한 pathname 키를 함께 저장합니다.
            const y = String(window.scrollY || document.documentElement.scrollTop || 0);
            sessionStorage.setItem(scrollKey, y);
            sessionStorage.setItem('autogenieAdminVehicleScrollPath:' + window.location.pathname, y);
        });
    });

    // 같은 vehicles.php 안에서 query string만 바뀐 POST 응답도 복원할 수 있게 보조 키를 확인합니다.
    const pathKey = 'autogenieAdminVehicleScrollPath:' + window.location.pathname;
    if (saved === null) {
        const pathSaved = sessionStorage.getItem(pathKey);
        if (pathSaved !== null) {
            const y = Number(pathSaved);
            sessionStorage.removeItem(pathKey);
            if (Number.isFinite(y)) {
                requestAnimationFrame(() => {
                    window.scrollTo(0, y);
                    requestAnimationFrame(() => window.scrollTo(0, y));
                });
            }
        }
    } else {
        sessionStorage.removeItem(pathKey);
    }
})();

</script>
</body>
</html>
