<?php
declare(strict_types=1);
require __DIR__ . '/../admin/estimate-contact-tags.php';

function checkColor(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$defaults = estimateContactTagDefaultColors();
checkColor(array_keys($defaults) === array_keys(estimateContactTagOptions()), 'Every tag needs a default color.');
$css = estimateContactTagColorCss(['MANAGED'=>'#123456', 'SPECIAL'=>'#ffffff', 'SIMPLE'=>'</style><script>']);
checkColor(str_contains($css, 'estimate-row--managed{background:#123456;color:#ffffff}'), 'Dark row must have readable text.');
checkColor(str_contains($css, 'contact-tag-option--managed input:checked+span{background:#123456'), 'Detail selection must use the same color.');
checkColor(str_contains($css, 'estimate-row--special{background:#ffffff;color:#243746}'), 'Light row must have dark text.');
checkColor(!str_contains($css, '<script>'), 'Invalid CSS values must fall back safely.');

class ColorValidationPDO extends PDO {
    public function __construct() {}
    public function exec(string $statement): int|false { throw new RuntimeException('Invalid input reached the database.'); }
}
foreach ([[], array_merge($defaults, ['MANAGED'=>'red']), array_merge($defaults, ['MANAGED'=>[]])] as $invalid) {
    try {
        saveEstimateContactTagColors(new ColorValidationPDO(), $invalid, 1);
        throw new RuntimeException('Invalid color was accepted.');
    } catch (InvalidArgumentException $expected) {}
}
echo "Contact tag color checks passed.\n";
