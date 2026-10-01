<?php
declare(strict_types=1);
require __DIR__ . '/../config/estimate-region.php';
$checks = 0;
function checkRegion(bool $condition): void { global $checks; if (!$condition) throw new RuntimeException('Region check failed'); $checks++; }
foreach (estimateRegionProvinces() as $province) checkRegion(validateEstimateRegion('EV', ['registration_province'=>$province]) === $province);
checkRegion(validateEstimateRegion('EV', ['registration_province'=>'경기','registration_district'=>'수원시']) === '경기');
checkRegion(validateEstimateRegion('EV', ['registration_province'=>'세종']) === '세종');
foreach (['GASOLINE', 'HYBRID', 'PHEV', 'DIESEL'] as $fuel) checkRegion(validateEstimateRegion($fuel, []) === null);
foreach ([[], ['registration_province'=>''], ['registration_province'=>'가짜'], ['registration_province'=>[]], ['registration_province'=>'<script>']] as $data) {
    $rejected = false;
    try { validateEstimateRegion('EV', $data); } catch (InvalidArgumentException $e) { $rejected = true; }
    checkRegion($rejected);
}
echo "Passed $checks checks.\n";
