<details class="vehicle-edit-group vehicle-info-item" open>
    <summary class="vehicle-info-item-title"><?= h($item['option_name'] ?? '새 사양 항목') ?></summary>
    <label>제목<input name="info_items[<?= h($index) ?>][option_name]" value="<?= h($item['option_name'] ?? '') ?>" required maxlength="200"></label>
    <label>설명<textarea name="info_items[<?= h($index) ?>][description]" rows="3" maxlength="5000"><?= h($item['description'] ?? '') ?></textarea></label>
    <?php if (!empty($item['image_path'])): ?><img src="../<?= h($item['image_path']) ?>" alt="현재 사진" style="display:block;width:320px;max-height:180px;object-fit:contain;margin:12px 0"><?php endif; ?>
    <label>사진 경로<input name="info_items[<?= h($index) ?>][image_path]" value="<?= h($item['image_path'] ?? '') ?>" placeholder="images/options/..."></label>
    <label>사진 업로드<input type="file" name="info_image_<?= h($index) ?>" accept="image/jpeg,image/png,image/webp,image/gif"></label>
    <input type="hidden" name="info_items[<?= h($index) ?>][is_active]" value="<?= (int)($item['is_active'] ?? 1) ?>">
    <input type="hidden" name="info_items[<?= h($index) ?>][image_kind]" value="<?= h($item['image_kind'] ?? 'photo') ?>">
    <button type="button" class="gray-btn" data-info-action="up">위로</button>
    <button type="button" class="gray-btn" data-info-action="down">아래로</button>
    <button type="button" class="danger-btn" data-info-action="remove">항목 삭제</button>
</details>
