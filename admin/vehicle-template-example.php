<?php
declare(strict_types=1);

function isVehicleTemplateExampleRow(array $row): bool {
    return trim((string)($row['행구분'] ?? '')) === '예시 (등록 제외)';
}

function vehicleTemplateExampleValues(PDO $pdo): ?array {
    $vehicle = $pdo->query('SELECT v.*, b.name AS brand_name, b.origin_type AS brand_origin,
        b.logo_path AS brand_logo, b.sort_order AS brand_sort
        FROM car_vehicles v JOIN car_brands b ON b.id = v.brand_id
        ORDER BY EXISTS(SELECT 1 FROM car_prices p WHERE p.vehicle_id = v.id AND p.trim_id IS NOT NULL) DESC,
        v.is_active DESC, v.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$vehicle) return null;
    $query = $pdo->prepare('SELECT * FROM car_colors WHERE vehicle_id = ? ORDER BY is_active DESC, sort_order, id LIMIT 1');
    $query->execute([$vehicle['id']]);
    $color = $query->fetch(PDO::FETCH_ASSOC) ?: [];
    $query = $pdo->prepare('SELECT t.* FROM car_trims t WHERE t.vehicle_id = ?
        ORDER BY EXISTS(SELECT 1 FROM car_prices p WHERE p.trim_id = t.id AND p.vehicle_id = t.vehicle_id) DESC,
        t.is_active DESC, t.sort_order, t.id LIMIT 1');
    $query->execute([$vehicle['id']]);
    $trim = $query->fetch(PDO::FETCH_ASSOC) ?: [];
    $price = [];
    if ($trim) {
        $query = $pdo->prepare('SELECT * FROM car_prices WHERE vehicle_id = ? AND trim_id = ? ORDER BY is_active DESC, id DESC LIMIT 1');
        $query->execute([$vehicle['id'], $trim['id']]);
        $price = $query->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    return [
        $vehicle['brand_name'], $vehicle['brand_origin'], $vehicle['brand_logo'], $vehicle['brand_sort'],
        $vehicle['name'], $vehicle['model_year'], $vehicle['fuel_type'], $vehicle['base_price'],
        $vehicle['image_path'], $vehicle['is_best'], $vehicle['is_recommended'] ?? 0, $vehicle['sort_order'],
        $color['name'] ?? '', $color['hex_code'] ?? '', $color['border_color'] ?? '', $color['image_path'] ?? '', $color['sort_order'] ?? '',
        $trim['name'] ?? '', $trim['price'] ?? '', $trim['description'] ?? '', $trim['sort_order'] ?? '',
        $price['product_type'] ?? '', $price['contract_months'] ?? '', $price['prepayment_rate'] ?? '',
        $price['annual_mileage'] ?? '', $price['monthly_payment'] ?? '', $vehicle['is_active'],
    ];
}

function addVehicleTemplateExample(ZipArchive $zip, array $values): void {
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $load = static function (string $path) use ($zip): DOMDocument {
        $xml = $zip->getFromName($path);
        $doc = new DOMDocument();
        if ($xml === false || !$doc->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('엑셀 양식을 읽을 수 없습니다.');
        return $doc;
    };
    $write = static function (string $path, DOMDocument $doc) use ($zip): void {
        if (!$zip->addFromString($path, $doc->saveXML())) throw new RuntimeException('엑셀 예시를 저장할 수 없습니다.');
    };
    $sheet = $load('xl/worksheets/sheet1.xml');
    $xp = new DOMXPath($sheet);
    $xp->registerNamespace('s', $ns);
    $sampleRow = $xp->query('//s:row[@r="4"]')->item(0);
    if (!$sampleRow) throw new RuntimeException('엑셀 입력 행을 찾을 수 없습니다.');
    $setCell = static function (DOMElement $cell, mixed $value) use ($sheet, $ns): void {
        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
        $cell->setAttribute('t', 'inlineStr');
        $text = $sheet->createElementNS($ns, 't');
        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $text->appendChild($sheet->createTextNode((string)($value ?? '')));
        $inline = $sheet->createElementNS($ns, 'is');
        $inline->appendChild($text);
        $cell->appendChild($inline);
    };
    $setCell($xp->query('//s:c[@r="A2"]')->item(0), '노란색 4행은 작성예시입니다 (등록 제외). 새 차량은 5행부터 입력하세요. 3행 항목명과 예시 행의 행구분 표시는 변경하지 마세요.');
    foreach ($xp->query('s:c', $sampleRow) as $index => $cell) $setCell($cell, $values[$index] ?? '');
    foreach ([3 => '행구분', 4 => '예시 (등록 제외)'] as $rowNumber => $value) {
        $row = $xp->query('//s:row[@r="' . $rowNumber . '"]')->item(0);
        $marker = $xp->query('s:c[last()]', $row)->item(0)->cloneNode(true);
        $marker->setAttribute('r', 'AB' . $rowNumber);
        $setCell($marker, $value);
        $row->appendChild($marker);
    }
    $sampleRow->setAttribute('ht', '42');
    $sampleRow->setAttribute('customHeight', '1');

    $styles = $load('xl/styles.xml');
    $styleXp = new DOMXPath($styles);
    $styleXp->registerNamespace('s', $ns);
    $fonts = $styleXp->query('//s:fonts')->item(0);
    $fills = $styleXp->query('//s:fills')->item(0);
    $formats = $styleXp->query('//s:cellXfs')->item(0);
    $baseId = (int)$xp->query('//s:c[@r="A4"]')->item(0)->getAttribute('s');
    $base = $styleXp->query('s:xf', $formats)->item($baseId);
    $font = $styleXp->query('s:font', $fonts)->item((int)$base->getAttribute('fontId'))->cloneNode(true);
    foreach (iterator_to_array($font->childNodes) as $child) {
        if (in_array($child->localName, ['b', 'color'], true)) $font->removeChild($child);
    }
    $font->appendChild($styles->createElementNS($ns, 'b'));
    $color = $styles->createElementNS($ns, 'color');
    $color->setAttribute('rgb', 'FF854D0E');
    $font->appendChild($color);
    $fontId = $styleXp->query('s:font', $fonts)->length;
    $fonts->appendChild($font);
    $fonts->setAttribute('count', (string)($fontId + 1));
    $fill = $styles->createElementNS($ns, 'fill');
    $pattern = $styles->createElementNS($ns, 'patternFill');
    $pattern->setAttribute('patternType', 'solid');
    $background = $styles->createElementNS($ns, 'fgColor');
    $background->setAttribute('rgb', 'FFFEF3C7');
    $pattern->appendChild($background);
    $fill->appendChild($pattern);
    $fillId = $styleXp->query('s:fill', $fills)->length;
    $fills->appendChild($fill);
    $fills->setAttribute('count', (string)($fillId + 1));
    $format = $base->cloneNode(true);
    $format->setAttribute('fontId', (string)$fontId);
    $format->setAttribute('fillId', (string)$fillId);
    $format->setAttribute('applyFont', '1');
    $format->setAttribute('applyFill', '1');
    $format->setAttribute('applyAlignment', '1');
    $alignment = $styleXp->query('s:alignment', $format)->item(0);
    if (!$alignment) {
        $alignment = $styles->createElementNS($ns, 'alignment');
        $format->appendChild($alignment);
    }
    $alignment->setAttribute('vertical', 'center');
    $alignment->setAttribute('wrapText', '1');
    $formatId = $styleXp->query('s:xf', $formats)->length;
    $formats->appendChild($format);
    $formats->setAttribute('count', (string)($formatId + 1));
    foreach ($xp->query('s:c', $sampleRow) as $cell) $cell->setAttribute('s', (string)$formatId);
    $write('xl/styles.xml', $styles);
    $columns = $xp->query('//s:cols')->item(0);
    if ($columns) {
        foreach ($xp->query('s:col[@min="1"]', $columns) as $firstColumn) $firstColumn->setAttribute('width', '26');
        $column = $sheet->createElementNS($ns, 'col');
        foreach (['min' => '28', 'max' => '28', 'width' => '22', 'customWidth' => '1'] as $key => $value) $column->setAttribute($key, $value);
        $columns->appendChild($column);
    }
    foreach ($xp->query('//s:dimension') as $dimension) {
        $lastRow = (int)$xp->evaluate('count(//s:row)');
        $dimension->setAttribute('ref', 'A1:AB' . max(4, $lastRow));
    }
    $write('xl/worksheets/sheet1.xml', $sheet);
}
