<?php
// Reuse submitted values when validation or upload fails.
$draft = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
$draftValue = static fn(string $key, string $fallback = ''): string => h((string)($draft[$key] ?? $fallback));
$draftSelected = static fn(string $key, string $value, string $fallback = ''): string => (string)($draft[$key] ?? $fallback) === $value ? 'selected' : '';
?>
<section class="admin-card detail-card" id="vehicle-create">
    <div class="card-title"><h2 id="createSummaryTitle">새 차량 등록</h2><div class="crud-toolbar"><a class="gray-btn" href="./vehicles.php">목록으로</a><button type="submit" form="vehicleCreateForm" class="save-btn">전체 저장</button></div></div>
    <form method="post" enctype="multipart/form-data" id="vehicleCreateForm">
        <input type="hidden" name="crud_action" value="add_vehicle_manual">
        <input type="hidden" name="batch_create" value="1">
        <input id="vehicleCreateImage" type="file" name="vehicle_image" accept=".jpg,.jpeg,.png,.webp,.gif" hidden>
        <div class="detail-top">
            <div class="preview create-summary-preview"><img id="vehicleCreatePreview" alt="등록할 차량 사진 미리보기" hidden><span id="vehicleCreatePlaceholder"><?= $crudError ? '첨부했던 사진은 다시 선택해 주세요.' : '차량 사진을 첨부해 주세요' ?></span><button type="button" class="gray-btn create-photo-button" onclick="document.getElementById('vehicleCreateImage').click()">사진 첨부</button></div>
            <div class="info-table">
                <div><span>차량 ID</span><b>저장 후 생성</b></div><div><span>브랜드</span><b data-summary="createBrand">-</b></div>
                <div><span>차량명</span><b data-summary="createName">-</b></div><div><span>연식</span><b data-summary="createYear">-</b></div>
                <div><span>연료</span><b data-summary="createFuel">-</b></div><div><span>차량가</span><b data-summary="createPrice">-</b></div>
                <div><span>등록일시</span><b>저장 후 표시</b></div><div><span>수정일시</span><b>저장 후 표시</b></div>
            </div>
        </div>
        <nav class="vehicle-section-nav" aria-label="차량 등록 영역"><a href="#create-basic">기본정보</a><a href="#create-colors">색상 <span data-nav-count="colors">0</span></a><a href="#create-trims">트림 <span data-nav-count="trims">0</span></a><a href="#create-prices">가격 <span data-nav-count="prices">0</span></a></nav>
        <section class="vehicle-edit-form" id="create-basic">
            <div class="vehicle-edit-header"><h3>차량 기본정보 등록</h3><button type="submit" class="save-btn">전체 저장</button></div>
            <div class="vehicle-edit-body">
            <div class="vehicle-edit-group">
                <div class="vehicle-edit-group-title"><strong>기본 정보</strong><span>브랜드와 차량명은 필수입니다.</span></div>
                <div class="vehicle-edit-grid basic-grid">
                    <div class="vehicle-edit-field"><label for="createBrand">브랜드 *</label><select id="createBrand" name="brand_id" required><option value="">브랜드 선택</option><?php foreach ($brandOptions as $brand): ?><option value="<?= (int)$brand['id'] ?>" <?= $draftSelected('brand_id', (string)$brand['id']) ?>><?= h($brand['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="vehicle-edit-field"><label for="createName">차량명 *</label><input id="createName" name="name" required value="<?= $draftValue('name') ?>" placeholder="예: 모델 Y"></div>
                    <div class="vehicle-edit-field"><label for="createYear">연식</label><input id="createYear" type="number" name="model_year" value="<?= $draftValue('model_year') ?>" placeholder="예: 2027"></div>
                    <div class="vehicle-edit-field"><label for="createFuel">연료</label><select id="createFuel" name="fuel_type"><?php foreach (['GASOLINE'=>'가솔린', 'DIESEL'=>'디젤', 'HYBRID'=>'하이브리드', 'PHEV'=>'플러그인 하이브리드', 'EV'=>'전기', 'LPG'=>'LPG', 'OTHER'=>'기타'] as $value=>$label): ?><option value="<?= $value ?>" <?= $draftSelected('fuel_type', $value, 'GASOLINE') ?>><?= $label ?></option><?php endforeach; ?></select></div>
                    <div class="vehicle-edit-field"><label for="createPrice">차량가 (원)</label><input id="createPrice" type="number" name="base_price" min="0" step="1" value="<?= $draftValue('base_price', '0') ?>"></div>
                </div>
            </div>
            <div class="vehicle-edit-group">
                <div class="vehicle-edit-group-title"><strong>판매 · 노출 설정</strong><span>기준가 상품과 목록 표시 방식을 설정합니다.</span></div>
                <div class="vehicle-edit-grid setting-grid">
                    <div class="vehicle-edit-field"><label for="createBasis">기준가 상품</label><select id="createBasis" name="price_basis_product"><option value="RENT" <?= $draftSelected('price_basis_product', 'RENT', 'RENT') ?>>장기렌트 기준가</option><option value="LEASE" <?= $draftSelected('price_basis_product', 'LEASE') ?>>리스 기준가</option></select></div>
                    <div class="vehicle-edit-field"><label for="createBest">BEST</label><select id="createBest" name="is_best"><option value="0" <?= $draftSelected('is_best', '0', '0') ?>>일반</option><option value="1" <?= $draftSelected('is_best', '1') ?>>BEST</option></select></div>
                    <?php if ($hasRecommended): ?><div class="vehicle-edit-field"><label for="createRecommended">추천차량</label><select id="createRecommended" name="is_recommended"><option value="0" <?= $draftSelected('is_recommended', '0', '0') ?>>일반</option><option value="1" <?= $draftSelected('is_recommended', '1') ?>>추천</option></select></div><?php endif; ?>
                    <div class="vehicle-edit-field"><label for="createOrder">정렬순서</label><input id="createOrder" type="number" name="sort_order" value="<?= $draftValue('sort_order', '0') ?>"></div>
                    <div class="vehicle-edit-field"><label for="createActive">판매상태</label><select id="createActive" name="is_active"><option value="1" <?= $draftSelected('is_active', '1', '1') ?>>판매중</option><option value="0" <?= $draftSelected('is_active', '0') ?>>판매중지</option></select></div>
                </div>
            </div>
            </div>
        </section>
            <?php foreach (['colors' => '색상', 'trims' => '트림', 'prices' => '가격'] as $group => $label): ?>
            <section class="crud-section" id="create-<?= $group ?>" data-create-group="<?= $group ?>">
                <div class="crud-section-head"><h3><?= $label ?> 관리 (<span data-row-count>0</span>)</h3><div class="crud-section-head-actions"><button type="button" class="new-item-btn" data-add-row="<?= $group ?>"><?= $label ?>추가</button><button type="submit" class="bulk-save-btn">전체 등록내용 저장</button></div></div>
                <div data-create-rows="<?= $group ?>"></div>
                <p class="vehicle-form-help" data-empty-rows>추가 버튼을 눌러 입력 행을 만드세요. 항목 없이 차량만 저장할 수도 있습니다.</p>
            </section>
            <?php endforeach; ?>
            <input type="hidden" name="batch_complete" value="1">
            <div class="vehicle-create-footer"><p class="vehicle-form-help">차량·색상·트림·가격 조건을 한 번에 저장합니다. 오류가 있으면 전체 등록을 취소하고 입력 내용을 유지합니다.</p><button class="save-btn" type="submit">전체 저장</button></div>
    </form>
</section>
<script id="vehicleCreateDraft" type="application/json"><?= json_encode(array_intersect_key($draft, array_flip(['colors', 'trims', 'prices', 'representative_color_key'])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="./vehicle-create.js?v=<?= filemtime(__DIR__ . '/vehicle-create.js') ?>" defer></script>
