<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/vehicle-template-example.php';

$template = __DIR__ . '/../admin/오토지니_차량일괄등록_한장양식.xlsx';
$temporary = tempnam(sys_get_temp_dir(), 'vehicle-example-test-');
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
try {
    copy($template, $temporary);
    $zip = new ZipArchive();
    $check($zip->open($temporary) === true, 'Open template');
    $originalWorkbook = $zip->getFromName('xl/workbook.xml');
    $values = array_fill(0, 27, '');
    $values[0] = '브랜드 & 예시';
    $values[4] = '=Example <차량>';
    $values[7] = 70000000;
    $values[17] = '트림';
    $values[21] = 'RENT';
    $values[25] = 500000;
    addVehicleTemplateExample($zip, $values);
    $check($zip->close(), 'Save example');
    $check($zip->open($temporary) === true, 'Reopen generated workbook');
    $check($originalWorkbook === $zip->getFromName('xl/workbook.xml'), 'No extra example tab');
    $doc = new DOMDocument();
    $check($doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml')), 'Input sheet XML is valid');
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $check($xp->query('//s:row[number(@r) > 4]')->length > 0, 'Blank input rows preserved');
    $check($xp->query('//s:row[@r="4"]/s:c')->length === 28, 'Template columns and exclusion marker included');
    $check($xp->evaluate('string(//s:c[@r="A4"]/s:is/s:t)') === $values[0], 'Brand value and XML characters preserved');
    $exampleStyle = $xp->query('//s:c[@r="A4"]')->item(0)->getAttribute('s');
    foreach ($xp->query('//s:row[@r="4"]/s:c') as $cell) $check($cell->getAttribute('s') === $exampleStyle, 'Entire example row highlighted');
    $check($xp->query('//s:row[@r="5"]/s:c[@s="' . $exampleStyle . '"]')->length === 0, 'Input rows keep original style');
    $check($xp->evaluate('string(//s:c[@r="E4"]/s:is/s:t)') === $values[4], 'Formula-like names are literal text');
    $check($xp->query('//s:f')->length === 0, 'No formulas created');
    $marker = $xp->evaluate('string(//s:c[@r="AB4"]/s:is/s:t)');
    $check(isVehicleTemplateExampleRow(['행구분' => $marker]), 'Generated example excluded from import');
    $check(!isVehicleTemplateExampleRow(['행구분' => '', '차량명' => '새 차량']), 'New vehicle rows imported');
    $check(!isVehicleTemplateExampleRow(['차량명' => '기존 양식']), 'Older templates without marker still imported');
    $check($xp->evaluate('string(//s:c[@r="AB3"]/s:is/s:t)') === '행구분', 'Exclusion header present');
    $zip->close();
    echo "Vehicle template example checks passed.\n";
} finally {
    if (is_file($temporary)) unlink($temporary);
}
