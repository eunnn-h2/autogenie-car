<?php
$infoOverrides = agVehicleInfoOverrides($pdo);
$info = $infoOverrides[(int)$vehicleDetail['id']] ?? agVehicleOptionSummary((string)$vehicleDetail['brand_name'], (string)$vehicleDetail['name']) ?? [];
$infoItems = $info['options'] ?? [];
?>
<style>
#vehicle-info-editor label { display:block; margin:12px 0; font-weight:600; }
#vehicle-info-editor input:not([type=hidden]), #vehicle-info-editor textarea { display:block; box-sizing:border-box; width:100%; margin-top:6px; padding:10px; border:1px solid #ccd7e4; border-radius:6px; font:inherit; font-weight:400; }
#vehicle-info-editor textarea { resize:vertical; }
#vehicle-info-items { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; align-items:start; margin:16px 0; }
#vehicle-info-editor .vehicle-info-item { min-width:0; margin:0; padding:16px; }
#vehicle-info-editor .vehicle-info-preview { display:flex; align-items:center; justify-content:center; width:320px; max-width:100%; height:180px; margin:12px 0; overflow:hidden; }
#vehicle-info-editor .vehicle-info-preview img { display:block; width:auto; height:auto; max-width:100%; max-height:100%; object-fit:contain; }
#vehicle-info-editor .vehicle-info-item-title { margin:0; color:#17324d; font-size:18px; font-weight:700; line-height:1.5; cursor:pointer; overflow-wrap:anywhere; }
#vehicle-info-editor details[open] > .vehicle-info-item-title { margin-bottom:16px; }
#vehicle-info-editor p { color:#64748b; line-height:1.6; }
@media (max-width:900px) {
    #vehicle-info-items { grid-template-columns:minmax(0,1fr); }
}
</style>
<form method="post" enctype="multipart/form-data" class="vehicle-edit-form vehicle-section-collapsed" id="vehicle-info-editor">
    <input type="hidden" name="crud_action" value="update_vehicle_info">
    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleDetail['id'] ?>">
    <div class="vehicle-edit-header"><h3>차량 정보</h3>
        <?php if (canUpdateVehicleData()): ?><button class="save-btn" type="submit">차량 정보 저장</button><?php endif; ?>
    </div>
    <div class="vehicle-edit-body">
        <p>차량찾기 → 차량 정보에 표시되는 내용입니다. 저장하면 이 차량의 모든 트림에 적용됩니다. 항목은 위에서부터 표시됩니다.</p>
        <label>상단 차량 사진 경로<input name="info_hero" value="<?= h($info['hero_image'] ?? '') ?>" placeholder="비워두면 기존 차량 사진을 사용합니다."></label>
        <label>상단 차량 사진 업로드<input type="file" name="info_hero_upload" accept="image/jpeg,image/png,image/webp,image/gif"></label>
        <label>영역 제목<input name="info_title" value="<?= h($info['title'] ?? '주요 사양 및 옵션') ?>" required maxlength="100"></label>
        <label>안내 문구<textarea name="info_note" rows="2" maxlength="2000"><?= h($info['fitment_note'] ?? '차종별 주요 사양입니다. 실제 적용 여부와 기본·선택 구분은 연식·트림·패키지에 따라 달라집니다.') ?></textarea></label>
        <div id="vehicle-info-items">
            <?php foreach ($infoItems as $index => $item): ?>
                <?php require __DIR__ . '/vehicle-info-item.php'; ?>
            <?php endforeach; ?>
        </div>
        <button class="gray-btn" type="button" id="vehicle-info-add">항목 추가</button>
        <p>사진은 JPG, PNG, WebP, GIF를 지원합니다. 새 파일을 선택하면 기존 사진을 교체합니다. 사진 경로를 비우면 사진이 제거됩니다.</p>
    </div>
</form>
<template id="vehicle-info-template">
    <?php $index = '__INDEX__'; $item = []; require __DIR__ . '/vehicle-info-item.php'; ?>
</template>
<script src="./vehicle-info-editor.js" defer></script>
