<?php
if (!isset($vehicleCatalog)) {
    try { $vehicleCatalog = loadEstimateVehicleCatalog($pdo); }
    catch (Throwable $catalogError) {
        error_log('Estimate vehicle catalog unavailable: ' . $catalogError->getMessage());
        $vehicleCatalog = ['vehicles' => [], 'trims' => [], 'colors' => []];
        $vehicleCatalogUnavailable = true;
    }
}
$selectedVehicleId = (string)($editValues['vehicle_id'] ?? $e['vehicle_id']);
$selectedTrimId = (string)($editValues['trim_id'] ?? $e['trim_id'] ?? '');
$selectedColorId = (string)($editValues['color_id'] ?? $e['color_id'] ?? '');
$brands = [];
$selectedBrandId = 'current';
$hasCurrentVehicle = false;
foreach ($vehicleCatalog['vehicles'] as $vehicle) {
    $brands[(string)$vehicle['brand_id']] = $vehicle['brand_name'];
    if ((string)$vehicle['id'] === $selectedVehicleId) {
        $selectedBrandId = (string)$vehicle['brand_id'];
        $hasCurrentVehicle = true;
    }
}
?>
<div class="section"><h2>차량 정보</h2>
<?php if (!empty($vehicleCatalogUnavailable)): ?><p class="assignment-help">차량 목록을 불러오지 못했습니다. 차량 변경은 새로고침 후 다시 시도해 주세요.</p><?php endif; ?>
<div class="info" id="estimateVehicleFields">
<div class="item"><span><label for="edit-brand_id">브랜드</label></span><div class="item-value"><select id="edit-brand_id">
<?php if (!$hasCurrentVehicle): ?><option value="current" selected><?=h($e['brand_name'])?> (현재 정보)</option><?php endif; ?>
<?php foreach ($brands as $brandId => $name): ?><option value="<?=h($brandId)?>" <?= (string)$brandId === $selectedBrandId ? 'selected' : '' ?>><?=h($name)?></option><?php endforeach; ?>
</select></div></div>
<div class="item"><span><label for="edit-vehicle_id">차량</label></span><div class="item-value"><select id="edit-vehicle_id" name="vehicle_id" form="estimateStatusForm" required>
<?php if (!$hasCurrentVehicle): ?><option value="<?=h($selectedVehicleId)?>" selected><?=h($e['vehicle_name'])?> (현재 정보)</option><?php endif; ?>
<?php foreach ($vehicleCatalog['vehicles'] as $vehicle): if ((string)$vehicle['brand_id'] !== $selectedBrandId) continue; ?>
<option value="<?=(int)$vehicle['id']?>" <?= (string)$vehicle['id'] === $selectedVehicleId ? 'selected' : '' ?>><?=h($vehicle['name'])?></option>
<?php endforeach; ?>
</select></div></div>
<?php foreach (['trim' => ['트림', 'trims', $selectedTrimId], 'color' => ['외장색상', 'colors', $selectedColorId]] as $kind => [$label, $catalogKey, $selectedId]): $found = $selectedId === ''; ?>
<div class="item"><span><label for="edit-<?=$kind?>_id"><?=h($label)?></label></span><div class="item-value"><select id="edit-<?=$kind?>_id" name="<?=$kind?>_id" form="estimateStatusForm">
<option value="" <?=$selectedId === '' ? 'selected' : ''?>>미정</option>
<?php foreach ($vehicleCatalog[$catalogKey] as $option): if ((string)$option['vehicle_id'] !== $selectedVehicleId) continue; if ((string)$option['id'] === $selectedId) $found = true; ?>
<option value="<?=(int)$option['id']?>" <?=(string)$option['id'] === $selectedId ? 'selected' : ''?>><?=h($option['name'])?></option>
<?php endforeach; ?>
<?php if (!$found): ?><option value="<?=h($selectedId)?>" selected><?=h($e[$kind . '_name'] ?? '')?> (현재 정보)</option><?php endif; ?>
</select></div></div>
<?php endforeach; ?>
</div></div>
<script type="application/json" id="estimateVehicleCatalog"><?=json_encode($vehicleCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)?></script>
<script src="./estimate-vehicle-edit.js?v=<?=filemtime(__DIR__ . '/estimate-vehicle-edit.js')?>" defer></script>
