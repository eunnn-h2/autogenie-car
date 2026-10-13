<?php
$isEditingDetails = $canEditDetails && ((string)($_GET['edit'] ?? '') === '1' || $editDraft !== null);
$editValues = $editDraft ?? $e;
$detailUrl = './estimate-detail.php?' . http_build_query(['type' => $isQuick ? 'quick' : 'direct', 'id' => $id]);
$renderDetailField = static function (string $key, string $label, string $display, string $type = 'text', array $attributes = []) use ($isEditingDetails, $editValues): void {
    ?>
    <div class="item"><span><?php if ($isEditingDetails): ?><label for="edit-<?=h($key)?>"><?=h($label)?></label><?php else: ?><?=h($label)?><?php endif; ?></span>
    <?php if ($isEditingDetails): ?><div class="item-value">
    <?php if ($key === 'product_type'): ?><select id="edit-product_type" name="product_type" form="estimateStatusForm">
    <?php foreach (['' => '미정', 'RENT' => '장기렌트', 'LEASE' => '리스'] as $value => $text): ?><option value="<?=h($value)?>" <?=($editValues[$key] ?? '') === $value ? 'selected' : ''?>><?=h($text)?></option><?php endforeach; ?>
    </select><?php else: ?><input id="edit-<?=h($key)?>" type="<?=h($type)?>" name="<?=h($key)?>" form="estimateStatusForm" value="<?=h($editValues[$key] ?? '')?>" <?php foreach ($attributes as $name => $value): ?><?=h($name)?>="<?=h($value)?>" <?php endforeach; ?>><?php endif; ?>
    </div><?php else: ?><b><?=$display?></b><?php endif; ?></div>
    <?php
};
?>
<?php if ($isEditingDetails): ?>
<div class="estimate-edit-form">
<input type="hidden" name="edit_details" value="1" form="estimateStatusForm">
<?php endif; ?>
<div class="section"><div class="section-title-row"><h2>고객 정보</h2>
<?php if ($canEditDetails && !$isEditingDetails): ?><a class="detail-edit-link" href="<?=h($detailUrl . '&edit=1')?>">수정</a><?php endif; ?>
</div><div class="info">
<?php
$renderDetailField('customer_name', '성함', h($e['customer_name']), 'text', ['maxlength' => $isQuick ? 30 : 80, 'required' => 'required', 'autocomplete' => 'name']);
$renderDetailField('customer_phone', '연락처', h($e['customer_phone']), 'tel', ['maxlength' => $isQuick ? 20 : 30, 'required' => 'required', 'autocomplete' => 'tel']);
?>
</div></div>
<?php if ($isQuick): ?>
<div class="section"><h2>간편견적 요청 조건</h2><div class="info">
<?php
$renderDetailField('car_type', '관심 차종', val($e['car_type']), 'text', ['maxlength' => 50, 'list' => 'estimateCarTypes']);
$renderDetailField('monthly_budget', '희망 월 예산', val($e['monthly_budget']), 'text', ['maxlength' => 50, 'list' => 'estimateBudgets']);
$renderDetailField('product_type', '이용 방식', productLabel($e['product_type']));
?>
<div class="item"><span>신청 유형</span><b>상담 후 차량·조건 결정</b></div>
</div></div>
<?php if ($isEditingDetails): ?>
<datalist id="estimateCarTypes"><?php foreach (['세단', 'SUV', 'RV/MPV', '전기차', '하이브리드', '기타'] as $option): ?><option value="<?=h($option)?>"></option><?php endforeach; ?></datalist>
<datalist id="estimateBudgets"><?php foreach (['30만원 이하', '30~50만원', '50~70만원', '70~100만원', '100만원 이상'] as $option): ?><option value="<?=h($option)?>"></option><?php endforeach; ?></datalist>
<?php endif; ?>
<?php else: ?>
<?php if ($isEditingDetails): require __DIR__ . '/estimate-vehicle-fields.php'; else: ?>
<div class="section"><h2>차량 정보</h2><div class="info"><div class="item"><span>브랜드</span><b><?=h($e['brand_name'])?></b></div><div class="item"><span>차량</span><b><?=h($e['vehicle_name'])?></b></div><div class="item"><span>트림</span><b><?=val($e['trim_name'])?></b></div><div class="item"><span>외장색상</span><b><?=val($e['color_name'])?></b></div></div></div>
<?php endif; ?>
<div class="section"><h2>이용 조건</h2><div class="info">
<?php
$renderDetailField('product_type', '상품', productLabel($e['product_type']));
$renderDetailField('contract_months', '계약기간 (개월)', val($e['contract_months']), 'number', ['min' => 1, 'max' => 2147483647, 'step' => 1]);
$renderDetailField('prepayment_rate', '선납률 (%)', val($e['prepayment_rate']), 'number', ['min' => 0, 'max' => 100, 'step' => '0.01']);
$renderDetailField('annual_mileage', '연 주행거리 (km)', isset($e['annual_mileage']) ? number_format((int)$e['annual_mileage']) : '-', 'number', ['min' => 0, 'max' => 2147483647, 'step' => 1]);
$renderDetailField('monthly_payment', '월 납입금 (원)', isset($e['monthly_payment']) ? number_format((int)$e['monthly_payment']) : '-', 'number', ['min' => 0, 'max' => 2147483647, 'step' => 1]);
if ($isEditingDetails || !empty($e['registration_region'])) $renderDetailField('registration_region', '차량 등록 지역', val($e['registration_region'] ?? null), 'text', ['maxlength' => 100]);
?>
</div></div>
<div class="section"><h2>고객 메모</h2><div class="memo"><?=val($e['customer_memo'])?></div></div>
<?php endif; ?>
<?php if ($isEditingDetails): ?>
</div>
<?php endif; ?>
