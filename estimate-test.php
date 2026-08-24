<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';

$vehicles = $pdo->query("SELECT v.id, v.name, v.base_price, v.image_path, b.name AS brand_name FROM car_vehicles v INNER JOIN car_brands b ON b.id=v.brand_id WHERE v.is_active=1 ORDER BY b.sort_order, v.sort_order, v.id")->fetchAll();
$trims = $pdo->query("SELECT id, vehicle_id, name, price FROM car_trims WHERE is_active=1 ORDER BY vehicle_id, sort_order, id")->fetchAll();
$colors = $pdo->query("SELECT id, vehicle_id, name, image_path, hex_code, border_color FROM car_colors WHERE is_active=1 ORDER BY vehicle_id, sort_order, id")->fetchAll();
$prices = $pdo->query("SELECT id, vehicle_id, trim_id, product_type, contract_months, prepayment_rate, annual_mileage, monthly_payment FROM car_prices WHERE is_active=1 ORDER BY vehicle_id, trim_id, product_type, contract_months, prepayment_rate, annual_mileage, id")->fetchAll();

function j(array $value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>오토지니 견적 저장 테스트</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f7fa;color:#18212b;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif}.wrap{width:min(1180px,calc(100% - 32px));margin:40px auto}.head{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:20px}.head h1{font-size:28px;margin:0 0 6px}.head p{margin:0;color:#6b7785}.head a{color:#4534d7;font-weight:700}.grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);gap:20px}.card{background:#fff;border:1px solid #e2e7ee;border-radius:14px;padding:22px}.preview{height:300px;border-radius:12px;background:#f7f9fb;display:grid;place-items:center;overflow:hidden;margin-bottom:18px}.preview img{width:100%;height:100%;object-fit:contain}.empty{color:#9aa5b1}.field{margin-bottom:16px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:7px}.field select,.field input,.field textarea{width:100%;height:44px;border:1px solid #ced6df;border-radius:8px;padding:0 12px;background:#fff;font:inherit}.field textarea{height:96px;padding:12px;resize:vertical}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.summary{border:1px solid #e5e9ef;border-radius:12px;overflow:hidden;margin-bottom:16px}.summary div{display:grid;grid-template-columns:120px 1fr;border-bottom:1px solid #edf0f4}.summary div:last-child{border-bottom:0}.summary span,.summary b{padding:12px}.summary span{background:#fafbfc;color:#6d7884;font-weight:500}.summary b{font-weight:700}.payment{font-size:24px;color:#4534d7}.btn{width:100%;height:52px;border:0;border-radius:9px;background:#3526ba;color:#fff;font-weight:800;font-size:16px;cursor:pointer}.btn:disabled{opacity:.55;cursor:not-allowed}.result{margin-top:14px;padding:13px 14px;border-radius:8px;display:none}.result.ok{display:block;background:#edf9f1;color:#16713a;border:1px solid #bde8ca}.result.error{display:block;background:#fff2f2;color:#a8202c;border:1px solid #f3b9bf}.result a{font-weight:800;text-decoration:underline}.note{font-size:12px;color:#7b8794;line-height:1.6;margin-top:14px}@media(max-width:850px){.grid{grid-template-columns:1fr}.row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div>
            <h1>견적 저장 테스트</h1>
            <p>현재 autogenie DB의 실제 차량/트림/색상/가격 데이터를 사용합니다.</p>
        </div>
        <a href="./admin/estimates.php">관리자 견적목록 →</a>
    </div>

    <div class="grid">
        <section class="card">
            <div class="preview" id="preview"><span class="empty">차량을 선택해 주세요.</span></div>

            <div class="field">
                <label for="vehicle">차량</label>
                <select id="vehicle"><option value="">차량 선택</option></select>
            </div>

            <div class="row">
                <div class="field">
                    <label for="trim">트림</label>
                    <select id="trim" disabled><option value="">트림 선택</option></select>
                </div>
                <div class="field">
                    <label for="color">외장색상</label>
                    <select id="color" disabled><option value="">색상 선택</option></select>
                </div>
            </div>

            <div class="field">
                <label for="price">이용조건 / 월 납입금</label>
                <select id="price" disabled><option value="">이용조건 선택</option></select>
            </div>
        </section>

        <section class="card">
            <div class="summary">
                <div><span>차량</span><b id="sumVehicle">-</b></div>
                <div><span>트림</span><b id="sumTrim">-</b></div>
                <div><span>색상</span><b id="sumColor">-</b></div>
                <div><span>이용조건</span><b id="sumCondition">-</b></div>
                <div><span>월 납입금</span><b class="payment" id="sumPayment">-</b></div>
            </div>

            <form id="estimateForm">
                <div class="row">
                    <div class="field">
                        <label for="customer_name">성함</label>
                        <input id="customer_name" name="customer_name" placeholder="홍길동" required>
                    </div>
                    <div class="field">
                        <label for="customer_phone">연락처</label>
                        <input id="customer_phone" name="customer_phone" placeholder="010-1234-5678" required>
                    </div>
                </div>
                <div class="field">
                    <label for="customer_memo">메모</label>
                    <textarea id="customer_memo" name="customer_memo" placeholder="테스트 견적입니다."></textarea>
                </div>
                <button class="btn" id="saveBtn" type="submit">이 견적 DB에 저장</button>
                <div class="result" id="result"></div>
            </form>
            <p class="note">이 페이지는 실제 견적 UI를 붙이기 전 DB 저장 흐름을 확인하기 위한 테스트 페이지입니다. 저장 시 선택 당시의 차량명·트림명·색상명·월 납입금을 스냅샷으로 함께 보관합니다.</p>
        </section>
    </div>
</div>
<script>
const VEHICLES = <?= j($vehicles) ?>;
const TRIMS = <?= j($trims) ?>;
const COLORS = <?= j($colors) ?>;
const PRICES = <?= j($prices) ?>;

const $ = (s) => document.querySelector(s);
const vehicleEl = $('#vehicle');
const trimEl = $('#trim');
const colorEl = $('#color');
const priceEl = $('#price');

const money = (v) => Number(v || 0).toLocaleString('ko-KR') + '원';
const rateText = (v) => Number(v || 0) + '%';
const mileageText = (v) => Number(v || 0).toLocaleString('ko-KR') + 'km';

VEHICLES.forEach(v => {
    const opt = document.createElement('option');
    opt.value = v.id;
    opt.textContent = `${v.brand_name} · ${v.name}`;
    vehicleEl.appendChild(opt);
});

function resetSelect(el, text) {
    el.innerHTML = `<option value="">${text}</option>`;
    el.disabled = true;
}

function selected(list, el) {
    return list.find(x => String(x.id) === String(el.value));
}

function updateSummary() {
    const v = selected(VEHICLES, vehicleEl);
    const t = selected(TRIMS, trimEl);
    const c = selected(COLORS, colorEl);
    const p = selected(PRICES, priceEl);
    $('#sumVehicle').textContent = v ? `${v.brand_name} ${v.name}` : '-';
    $('#sumTrim').textContent = t ? t.name : '-';
    $('#sumColor').textContent = c ? c.name : '-';
    $('#sumCondition').textContent = p ? `${p.product_type} · ${p.contract_months}개월 · 선납 ${rateText(p.prepayment_rate)} · 연 ${mileageText(p.annual_mileage)}` : '-';
    $('#sumPayment').textContent = p ? money(p.monthly_payment) : '-';
}

function updatePreview() {
    const v = selected(VEHICLES, vehicleEl);
    const c = selected(COLORS, colorEl);
    const path = c?.image_path || v?.image_path || '';
    const preview = $('#preview');
    preview.innerHTML = path ? `<img src="${path.replace(/^\//, '')}" alt="차량 미리보기">` : '<span class="empty">등록된 이미지가 없습니다.</span>';
}

vehicleEl.addEventListener('change', () => {
    resetSelect(trimEl, '트림 선택');
    resetSelect(colorEl, '색상 선택');
    resetSelect(priceEl, '이용조건 선택');
    const vehicleId = Number(vehicleEl.value);
    if (vehicleId) {
        TRIMS.filter(x => Number(x.vehicle_id) === vehicleId).forEach(x => trimEl.add(new Option(x.name, x.id)));
        COLORS.filter(x => Number(x.vehicle_id) === vehicleId).forEach(x => colorEl.add(new Option(x.name, x.id)));
        trimEl.disabled = trimEl.options.length <= 1;
        colorEl.disabled = colorEl.options.length <= 1;
    }
    updatePreview();
    updateSummary();
});

trimEl.addEventListener('change', () => {
    resetSelect(priceEl, '이용조건 선택');
    const vehicleId = Number(vehicleEl.value);
    const trimId = Number(trimEl.value);
    PRICES.filter(x => Number(x.vehicle_id) === vehicleId && Number(x.trim_id) === trimId).forEach(x => {
        const label = `${x.product_type} / ${x.contract_months}개월 / 선납 ${rateText(x.prepayment_rate)} / 연 ${mileageText(x.annual_mileage)} / 월 ${money(x.monthly_payment)}`;
        priceEl.add(new Option(label, x.id));
    });
    priceEl.disabled = priceEl.options.length <= 1;
    updateSummary();
});

colorEl.addEventListener('change', () => { updatePreview(); updateSummary(); });
priceEl.addEventListener('change', updateSummary);

$('#estimateForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const result = $('#result');
    result.className = 'result';
    result.textContent = '';

    const payload = {
        vehicle_id: vehicleEl.value,
        trim_id: trimEl.value,
        color_id: colorEl.value,
        price_id: priceEl.value,
        customer_name: $('#customer_name').value.trim(),
        customer_phone: $('#customer_phone').value.trim(),
        customer_memo: $('#customer_memo').value.trim(),
    };

    if (!payload.vehicle_id || !payload.trim_id || !payload.color_id || !payload.price_id) {
        result.className = 'result error';
        result.textContent = '차량, 트림, 색상, 이용조건을 모두 선택해 주세요.';
        return;
    }

    const btn = $('#saveBtn');
    btn.disabled = true;
    try {
        const res = await fetch('./api/save-estimate.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || '저장 실패');

        result.className = 'result ok';
        result.innerHTML = `저장 완료 · 견적번호 <strong>${data.estimate_no}</strong><br><a href="./admin/estimate-detail.php?id=${data.estimate_id}">관리자에서 저장된 견적 보기 →</a>`;
    } catch (err) {
        result.className = 'result error';
        result.textContent = err.message;
    } finally {
        btn.disabled = false;
    }
});
</script>
</body>
</html>
