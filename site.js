const agSubmissionRequests = new Map();
const AG_SUBMISSION_STORAGE = 'autogenie_submission_requests_v1';

function readSubmissionKeys() {
    try {
        const items = JSON.parse(sessionStorage.getItem(AG_SUBMISSION_STORAGE) || '{}');
        return Object.fromEntries(Object.entries(items).filter(([, item]) => item && item.expires > Date.now()));
    } catch (_) { return {}; }
}

function writeSubmissionKeys(items) {
    try { sessionStorage.setItem(AG_SUBMISSION_STORAGE, JSON.stringify(Object.fromEntries(Object.entries(items).slice(-30)))); } catch (_) {}
}

async function fetchSubmissionOnce(url, options) {
    const signature = new URL(url, location.href).pathname + '\n' + String(options.body || '');
    let record = agSubmissionRequests.get(signature);
    if (!record) {
        let storageKey = '';
        if (globalThis.crypto?.subtle) {
            const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(signature));
            storageKey = Array.from(new Uint8Array(hash), byte => byte.toString(16).padStart(2, '0')).join('');
        }
        record = agSubmissionRequests.get(signature);
        if (!record) {
            const cached = storageKey ? readSubmissionKeys()[storageKey] : null;
            const bytes = new Uint8Array(16);
            if (globalThis.crypto?.getRandomValues) crypto.getRandomValues(bytes);
            const key = cached?.key || (bytes.some(Boolean)
                ? 'ag_' + Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
                : 'ag_' + Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2));
            record = {key, storageKey, pending: null};
            agSubmissionRequests.set(signature, record);
            if (storageKey) {
                const items = readSubmissionKeys();
                items[storageKey] = {key, expires: Date.now() + 3600000};
                writeSubmissionKeys(items);
            }
        }
    }
    if (record.pending) return (await record.pending).clone();
    const headers = new Headers(options.headers);
    headers.set('X-Idempotency-Key', record.key);
    record.pending = fetch(url, {...options, headers});
    try {
        const response = await record.pending;
        const result = await response.clone().json().catch(() => null);
        if ((response.ok && (result?.ok === true || result?.success === true)) || (response.status >= 400 && response.status < 500 && result)) {
            agSubmissionRequests.delete(signature);
            if (record.storageKey) {
                const items = readSubmissionKeys();
                delete items[record.storageKey];
                writeSubmissionKeys(items);
            }
        }
        return response.clone();
    } finally { record.pending = null; }
}

function agFetch(url, options = {}) {
    const headers = new Headers(options.headers || {});
    const target = new URL(url, location.href);
    if (String(options.method || 'GET').toUpperCase() === 'POST' && target.origin === location.origin) {
        headers.set('X-Requested-With', 'XMLHttpRequest');
        if (['inquiry-create.php', 'save-estimate.php', 'save-quick-estimate.php'].includes(target.pathname.split('/').pop())) {
            return fetchSubmissionOnce(url, {...options, headers});
        }
    }
    return fetch(url, {...options, headers});
}

/* 오토지니 사용자 화면 · 원본 JavaScript 로직 변경 없이 분리 */
const API_URL = './api/test-car-data.php?v=chazm-style-20260819';
const SAVE_ESTIMATE_URL = './api/save-estimate.php';
const SAVE_QUICK_ESTIMATE_URL = './api/save-quick-estimate.php';
const TRACK_ESTIMATE_URL = './api/track-estimate-progress.php';

// 최초 랜딩 시점의 UTM/referrer를 세션스토리지에 보존합니다.
function getAcquisitionData(){
    const key = 'autogenie_acquisition_v1';
    let saved = {};
    try { saved = JSON.parse(sessionStorage.getItem(key) || '{}') || {}; } catch (_) {}
    if (!saved.landing_page) {
        const params = new URLSearchParams(location.search);
        saved = {
            utm_source: params.get('utm_source') || '',
            utm_medium: params.get('utm_medium') || '',
            utm_campaign: params.get('utm_campaign') || '',
            utm_content: params.get('utm_content') || '',
            utm_term: params.get('utm_term') || '',
            referrer: document.referrer || '',
            landing_page: location.href
        };
        try { sessionStorage.setItem(key, JSON.stringify(saved)); } catch (_) {}
    }
    return saved;
}
function getEstimateSessionKey(){
    const key = 'autogenie_estimate_session';
    let value = sessionStorage.getItem(key);
    if (!value) {
        value = 'ag_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 12);
        sessionStorage.setItem(key, value);
    }
    return value;
}
let lastTrackedStageOrder = 0;
const TRACK_STAGE_ORDER = {LANDING:1,VEHICLE_LIST:2,VEHICLE_DETAIL:3,TRIM_SELECTED:4,CONDITIONS_SELECTED:5,ESTIMATE_FORM:6,CUSTOMER_INPUT:7,COMPLETED:8};
const TRACK_STAGE_BY_ORDER = Object.fromEntries(Object.entries(TRACK_STAGE_ORDER).map(([stage, order]) => [order, stage]));

// 실제 화면을 보고 있는 시간만 누적합니다. 숨겨진 탭의 시간은 제외합니다.
const STAY_TIME_KEY = 'autogenie_active_seconds';
let activeStaySeconds = Number(sessionStorage.getItem(STAY_TIME_KEY) || 0) || 0;
let activeStayStartedAt = document.visibilityState === 'visible' ? Date.now() : 0;
function getActiveStaySeconds(){
    let total = activeStaySeconds;
    if (activeStayStartedAt) total += Math.floor((Date.now() - activeStayStartedAt) / 1000);
    return Math.max(0, total);
}
function persistActiveStaySeconds(){
    activeStaySeconds = getActiveStaySeconds();
    activeStayStartedAt = document.visibilityState === 'visible' ? Date.now() : 0;
    try { sessionStorage.setItem(STAY_TIME_KEY, String(activeStaySeconds)); } catch (_) {}
    return activeStaySeconds;
}
function trackEstimateProgress(stage, extra = {}){
    const order = TRACK_STAGE_ORDER[stage] || 0;
    if (!order || (order < lastTrackedStageOrder && stage !== 'COMPLETED')) return;
    lastTrackedStageOrder = Math.max(lastTrackedStageOrder, order);
    const payload = {session_key:getEstimateSessionKey(), stage, active_seconds:getActiveStaySeconds(), ...getAcquisitionData(), ...extra};
    try {
        agFetch(TRACK_ESTIMATE_URL, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),keepalive:true}).catch(()=>{});
    } catch (_) {}
}
function trackEstimateHeartbeat(){
    const stage = TRACK_STAGE_BY_ORDER[lastTrackedStageOrder] || 'LANDING';
    const payload = {session_key:getEstimateSessionKey(), stage, active_seconds:getActiveStaySeconds(), ...getAcquisitionData()};
    try {
        agFetch(TRACK_ESTIMATE_URL, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),keepalive:true}).catch(()=>{});
    } catch (_) {}
}
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        persistActiveStaySeconds();
        trackEstimateHeartbeat();
    } else {
        activeStayStartedAt = Date.now();
    }
});
getAcquisitionData();
trackEstimateProgress('LANDING');
// 10초마다 체류시간을 갱신하고, 페이지 이탈 직전에도 마지막 값을 전송합니다.
setInterval(() => {
    if (document.visibilityState === 'visible') trackEstimateHeartbeat();
}, 10000);
window.addEventListener('pagehide', () => {
    persistActiveStaySeconds();
    trackEstimateHeartbeat();
});

const quoteStates = new Map();
let pendingEstimate = null;
let allVehicles = [];
let activeListCategory = 'ALL';
let vehicleSearchKeyword = '';
let vehicleSortMode = 'PRICE_ASC';
let vehicleProductFilter = 'ALL';
let activeHomeRecommend = 'SUV';
let vehicleDetailReturnView = 'vehicle';
const DOMESTIC_BRANDS = new Set(['현대','기아','제네시스','쉐보레','르노코리아','르노','KG모빌리티','KGM','쌍용']);

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
}
function normalizeHex(value) {
    if (!value) return null;
    let hex = String(value).trim();
    if (/^[0-9a-fA-F]{6}$/.test(hex)) hex = '#' + hex;
    return /^#[0-9a-fA-F]{3}$/.test(hex) || /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : null;
}
function money(value) {
    const n = Number(value || 0);
    return n > 0 ? n.toLocaleString('ko-KR') + '원' : '-';
}
function shortMoney(value) {
    const n = Number(value || 0);
    return n > 0 ? n.toLocaleString('ko-KR') : '-';
}
function formatMileageButton(value) {
    const n = Number(value || 0);
    if (n === 0) return '무제한';
    if (n % 10000 === 0) return `${n / 10000}만`;
    return (n / 10000).toFixed(1).replace(/\.0$/,'') + '만';
}
function yearsFromMonths(months) {
    const m = Number(months || 0);
    return m ? (m % 12 === 0 ? `${m / 12}년` : `${m}개월`) : '-';
}
function productLabel(value) {
    if (value === 'RENT') return '장기렌트';
    if (value === 'LEASE') return '리스';
    return value || '-';
}
function productDesc(value) {
    if (value === 'RENT') return '보험·세금 조건 확인\n하·허·호 번호판';
    if (value === 'LEASE') return '보험 가입 조건 확인\n일반 번호판 · 심사조건 확인';
    return '';
}
function getVehicleName(v){ return v.vehicle_name ?? v.name ?? v.vehicle ?? '-'; }
function getBrandName(v){ return v.brand_name ?? v.brand ?? '-'; }
function getImagePath(v){ return v.image_path ?? v.vehicle_image_path ?? v.image ?? ''; }

function imageCandidates(v) {
    // 상세 화면의 기본 선택 색상(colors[0])과 상단 차량 이미지를 동일하게 맞춥니다.
    // 색상 이미지가 있으면 첫 번째 색상 이미지를 우선 사용하고,
    // 없을 때만 차량 대표 이미지를 fallback으로 사용합니다.
    const colorCandidates = Array.isArray(v.colors)
        ? v.colors.map(c => c.image_path ?? c.color_image_path ?? c.image)
        : [];

    const rawCandidates = [
        v.image_path, v.vehicle_image_path, v.image, v.representative_image, v.thumbnail,
        ...colorCandidates
    ].filter(Boolean);

    const urls = [];
    rawCandidates.forEach(raw => {
        let path = String(raw).trim().replaceAll('\\', '/');
        if (!path) return;
        urls.push(path);
        if (path.startsWith('/images/')) urls.push('/autogenie' + path);
        if (path.startsWith('images/')) {
            urls.push('./' + path);
            urls.push('/autogenie/' + path);
        }
        if (path.startsWith('autogenie/')) urls.push('/' + path);
        const imageIndex = path.toLowerCase().indexOf('images/');
        if (imageIndex >= 0) {
            const rel = path.slice(imageIndex);
            urls.push('./' + rel);
            urls.push('/autogenie/' + rel);
        }
    });
    return [...new Set(urls)];
}
function vehicleImageHtml(v, vehicleKey) {
    const candidates = imageCandidates(v);
    if (!candidates.length) return '<div class="empty">대표 이미지 없음</div>';
    return `
        <img
            src="${escapeHtml(candidates[0])}"
            alt="${escapeHtml(getVehicleName(v))}"
            loading="lazy" decoding="async" data-vehicle-preview="${escapeHtml(vehicleKey)}"
            data-image-candidates='${escapeHtml(JSON.stringify(candidates))}'
            data-image-index="0"
            onload="normalizeVehicleListImage(this)"
            onerror="tryNextVehicleImage(this)"
        >
    `;
}
function tryNextVehicleImage(img) {
    let candidates = [];
    try { candidates = JSON.parse(img.dataset.imageCandidates || '[]'); } catch(e) {}
    const nextIndex = Number(img.dataset.imageIndex || 0) + 1;
    if (nextIndex < candidates.length) {
        img.dataset.imageIndex = String(nextIndex);
        img.src = candidates[nextIndex];
        return;
    }
    img.onerror = null;
    img.style.display = 'none';
}
function groupFlatRows(rows) {
    const map = new Map();
    rows.forEach(row => {
        const vehicleId = row.vehicle_id ?? row.id ?? `${row.brand_name}-${row.vehicle_name}`;
        if (!map.has(vehicleId)) {
            map.set(vehicleId, {
                vehicle_id: row.vehicle_id ?? row.id,
                brand_name: row.brand_name ?? row.brand,
                vehicle_name: row.vehicle_name ?? row.name ?? row.vehicle,
                model_year: row.model_year,
                fuel_type: row.fuel_type,
                price_basis_product: row.price_basis_product ?? 'RENT',
                origin_type: row.origin_type ?? row.brand_origin_type ?? row.origin,
                category: row.category,
                category_slug: row.category_slug,
                category_name: row.category_name,
                is_best: row.is_best ?? row.best ?? row.best_yn,
                base_price: row.base_price,
                image_path: row.vehicle_image_path ?? row.image_path,
                colors: [],
                trims: []
            });
        }
        const vehicle = map.get(vehicleId);

        const colorId = row.color_id;
        if (colorId || row.color_name) {
            if (!vehicle.colors.some(c => String(c.id ?? c.name) === String(colorId ?? row.color_name))) {
                vehicle.colors.push({
                    id: colorId,
                    name: row.color_name,
                    hex_code: row.hex_code ?? row.color_hex ?? row.hex,
                    border_color: row.border_color,
                    image_path: row.color_image_path
                });
            }
        }

        const trimId = row.trim_id;
        if (trimId || row.trim_name) {
            let trim = vehicle.trims.find(t => String(t.id ?? t.name) === String(trimId ?? row.trim_name));
            if (!trim) {
                trim = {
                    id: trimId,
                    name: row.trim_name,
                    price: row.trim_price,
                    prices: []
                };
                vehicle.trims.push(trim);
            }
            if (row.price_id || row.monthly_payment) {
                const priceKey = [row.product_type,row.contract_months,row.prepayment_rate,row.annual_mileage,row.monthly_payment].join('|');
                if (!trim.prices.some(p => p.__key === priceKey)) {
                    trim.prices.push({
                        __key: priceKey,
                        product_type: row.product_type,
                        contract_months: row.contract_months,
                        prepayment_rate: row.prepayment_rate,
                        annual_mileage: row.annual_mileage,
                        monthly_payment: row.monthly_payment
                    });
                }
            }
        }
    });
    return [...map.values()];
}
function normalizeData(data) {
    if (!data) return [];
    if (Array.isArray(data)) {
        if (data.some(v => Array.isArray(v.colors) || Array.isArray(v.trims))) return data;
        return groupFlatRows(data);
    }
    if (Array.isArray(data.vehicles)) return data.vehicles;
    if (Array.isArray(data.data)) return normalizeData(data.data);
    return [];
}

function rgbToHex(r, g, b) {
    return '#' + [r, g, b].map(v => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('').toUpperCase();
}
function rgbToHsv(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r,g,b), min = Math.min(r,g,b), d = max - min;
    let h = 0;
    if (d !== 0) {
        if (max === r) h = ((g - b) / d) % 6;
        else if (max === g) h = (b - r) / d + 2;
        else h = (r - g) / d + 4;
        h *= 60; if (h < 0) h += 360;
    }
    const s = max === 0 ? 0 : d / max;
    return {h,s,v:max};
}
function colorNameHint(name) {
    const n = String(name || '').toLowerCase();
    const rules = [
        { keys:['화이트','white','아이보리'], fallback:'#F1F1F1' },
        { keys:['블랙','black'], fallback:'#252729' },
        { keys:['실버','silver'], fallback:'#A9B0B6' },
        { keys:['그레이','gray','grey'], fallback:'#7B8085' },
        { keys:['블루','blue'], fallback:'#40637C' },
        { keys:['레드','red'], fallback:'#A53A35' },
        { keys:['그린','green'], fallback:'#4A6559' },
        { keys:['브라운','brown'], fallback:'#6F5444' },
        { keys:['베이지','beige'], fallback:'#CBB79E' },
    ];
    for (const rule of rules) {
        if (rule.keys.some(k => n.includes(k))) return rule.fallback;
    }
    return '#8D949B';
}
function colorImageCandidates(color) {
    const rawCandidates = [color.image_path, color.color_image_path, color.image].filter(Boolean);
    const urls = [];
    rawCandidates.forEach(raw => {
        let path = String(raw).trim().replaceAll('\\', '/');
        if (!path) return;
        urls.push(path);
        if (path.startsWith('/images/')) urls.push('/autogenie' + path);
        if (path.startsWith('images/')) { urls.push('./' + path); urls.push('/autogenie/' + path); }
        const idx = path.toLowerCase().indexOf('images/');
        if (idx >= 0) { const rel = path.slice(idx); urls.push('./' + rel); urls.push('/autogenie/' + rel); }
    });
    return [...new Set(urls)];
}
function extractTemporaryCarColor(img, colorName) {
    try {
        const maxSize = 180;
        const scale = Math.min(1, maxSize / Math.max(img.naturalWidth, img.naturalHeight));
        const width = Math.max(1, Math.round(img.naturalWidth * scale));
        const height = Math.max(1, Math.round(img.naturalHeight * scale));
        const canvas = document.createElement('canvas');
        canvas.width = width; canvas.height = height;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, width, height);
        const data = ctx.getImageData(0, 0, width, height).data;
        let rs=0, gs=0, bs=0, c=0;
        for (let y = Math.floor(height*0.25); y < Math.floor(height*0.75); y++) {
            for (let x = Math.floor(width*0.1); x < Math.floor(width*0.9); x++) {
                const i = (y * width + x) * 4;
                const a = data[i+3];
                if (a < 100) continue;
                const r=data[i], g=data[i+1], b=data[i+2];
                const hsv = rgbToHsv(r,g,b);
                if (hsv.v > 0.98 && hsv.s < 0.04) continue;
                if (hsv.v < 0.08) continue;
                rs += r; gs += g; bs += b; c++;
            }
        }
        if (!c) return colorNameHint(colorName);
        return rgbToHex(rs/c, gs/c, bs/c);
    } catch(e) {
        return colorNameHint(colorName);
    }
}
function applyTemporaryColorFromImage(img, chipId, colorName) {
    const chip = document.getElementById(chipId);
    if (!chip) return;
    chip.style.background = extractTemporaryCarColor(img, colorName);
}

function normalizeImagePathForCompare(value) {
    let path = String(value || '').trim().replaceAll('\\', '/');
    if (!path) return '';

    path = path.replace(/^https?:\/\/[^/]+\//i, '/');
    path = path.replace(/^\.\//, '');
    path = path.replace(/^\/autogenie\//i, '');
    path = path.replace(/^\//, '');

    const imageIndex = path.toLowerCase().indexOf('images/');
    if (imageIndex >= 0) path = path.slice(imageIndex);

    try { path = decodeURIComponent(path); } catch (e) {}
    return path.toLowerCase();
}

function renderColorSelect(colors, vehicleKey, representativeImagePath = '') {
    if (!Array.isArray(colors) || !colors.length) {
        return `
            <div class="color-select">
                <button class="color-select-button" type="button" disabled>
                    <span class="color-select-label">색상</span>
                    <span class="color-select-value">
                        <span class="color-select-value-name">등록된 색상이 없습니다.</span>
                    </span>
                </button>
            </div>
        `;
    }

    const representativePath = normalizeImagePathForCompare(representativeImagePath);
    let selectedColorIndex = 0;

    if (representativePath) {
        const matchedIndex = colors.findIndex(color => {
            const colorPath = color.image_path ?? color.color_image_path ?? color.image ?? '';
            return normalizeImagePathForCompare(colorPath) === representativePath;
        });
        if (matchedIndex >= 0) selectedColorIndex = matchedIndex;
    }

    const firstColor = colors[selectedColorIndex];
    const firstHex = normalizeHex(
        firstColor.hex_code ??
        firstColor.color_hex ??
        firstColor.hex ??
        firstColor.hexCode
    ) || colorNameHint(firstColor.name);

    return `
        <div class="color-select" data-color-select="${escapeHtml(vehicleKey)}" data-selected-color-id="${escapeHtml(firstColor.id ?? '')}" data-selected-color-name="${escapeHtml(firstColor.name ?? '-')}">
            <button
                class="color-select-button"
                type="button"
                onclick="toggleColorSelect('${escapeHtml(vehicleKey)}')"
            >
                <span class="color-select-label">색상</span>
                <span class="color-select-value">
                    <span class="color-select-chip">
                        <span
                            data-selected-color-chip="${escapeHtml(vehicleKey)}"
                            style="background:${escapeHtml(firstHex)}"
                        ></span>
                    </span>
                    <span
                        class="color-select-value-name"
                        data-selected-color-name="${escapeHtml(vehicleKey)}"
                    >${escapeHtml(firstColor.name ?? '-')}</span>
                </span>
            </button>

            <div class="color-select-menu">
                ${colors.map((color, index) => {
                    const hex = normalizeHex(
                        color.hex_code ??
                        color.color_hex ??
                        color.hex ??
                        color.hexCode
                    ) || colorNameHint(color.name);

                    const imagePath = color.image_path ?? color.color_image_path ?? color.image ?? '';

                    return `
                        <button
                            class="color-option ${index === selectedColorIndex ? 'active' : ''}"
                            type="button"
                            data-color-option="${escapeHtml(vehicleKey)}"
                            data-color-id="${escapeHtml(color.id ?? '')}"
                            data-color-name="${escapeHtml(color.name ?? '-')}"
                            data-color-hex="${escapeHtml(hex)}"
                            data-color-image="${escapeHtml(imagePath)}"
                            onclick="selectVehicleColor('${escapeHtml(vehicleKey)}', this)"
                        >
                            <span class="color-chip">
                                <span class="color-chip-inner" style="background:${escapeHtml(hex)}"></span>
                            </span>
                            <span>${escapeHtml(color.name ?? '-')}</span>
                        </button>
                    `;
                }).join('')}
            </div>
        </div>
    `;
}

function toggleColorSelect(vehicleKey) {
    document.querySelectorAll('.trim-select-wrap.open').forEach(el => {
        el.classList.remove('open');
        el.querySelector('.trim-select-button')?.setAttribute('aria-expanded', 'false');
    });
    const current = document.querySelector(
        `[data-color-select="${CSS.escape(String(vehicleKey))}"]`
    );

    document.querySelectorAll('.color-select.open').forEach(el => {
        if (el !== current) el.classList.remove('open');
    });

    current?.classList.toggle('open');
}

function selectVehicleColor(vehicleKey, optionButton) {
    const wrapper = document.querySelector(
        `[data-color-select="${CSS.escape(String(vehicleKey))}"]`
    );

    const selectedName = document.querySelector(
        `[data-selected-color-name="${CSS.escape(String(vehicleKey))}"]`
    );

    const selectedChip = document.querySelector(
        `[data-selected-color-chip="${CSS.escape(String(vehicleKey))}"]`
    );

    wrapper?.querySelectorAll('.color-option').forEach(el => el.classList.remove('active'));
    optionButton.classList.add('active');

    if (selectedName) {
        selectedName.textContent = optionButton.dataset.colorName || '-';
    }

    if (selectedChip) {
        selectedChip.style.background = optionButton.dataset.colorHex || '#8D949B';
    }

    if (wrapper) {
        wrapper.dataset.selectedColorId = optionButton.dataset.colorId || '';
        wrapper.dataset.selectedColorName = optionButton.dataset.colorName || '';
    }
    if (typeof syncSavedQuoteButtons === 'function') syncSavedQuoteButtons(vehicleKey);
    wrapper?.classList.remove('open');

    changeVehicleColorImage(
        vehicleKey,
        optionButton.dataset.colorImage || '',
        null
    );
}

// 색상 드롭다운 바깥을 클릭하면 목록을 닫습니다.
document.addEventListener('click', event => {
    if (!event.target.closest('.color-select')) {
        document.querySelectorAll('.color-select.open').forEach(el => {
            el.classList.remove('open');
            el.querySelector('.color-select-button')?.setAttribute('aria-expanded', 'false');
        });
    }

    if (!event.target.closest('.trim-select-wrap')) {
        document.querySelectorAll('.trim-select-wrap.open').forEach(el => {
            el.classList.remove('open');
            el.querySelector('.trim-select-button')?.setAttribute('aria-expanded', 'false');
        });
    }
});

function resolveProjectImagePath(path) {
    if (!path) return '';
    let p = String(path).trim().replaceAll('\\', '/');
    if (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('data:')) return p;
    if (p.startsWith('/autogenie/')) return p;
    if (p.startsWith('/images/')) return '/autogenie' + p;
    if (p.startsWith('autogenie/')) return '/' + p;
    if (p.startsWith('images/')) return './' + p;
    const imageIndex = p.toLowerCase().indexOf('images/');
    if (imageIndex >= 0) return './' + p.slice(imageIndex);
    return p;
}
function changeVehicleColorImage(vehicleKey, imagePath, colorElement) {
    const img = document.querySelector(`[data-vehicle-preview="${CSS.escape(String(vehicleKey))}"]`);
    if (!img || !imagePath) return;
    const resolved = resolveProjectImagePath(imagePath);

    img.style.opacity = '0.35';
    const testImg = new Image();
    testImg.onload = () => { img.src = resolved; img.style.opacity = '1'; };
    testImg.onerror = () => { img.style.opacity = '1'; };
    testImg.src = resolved;
}

function getTrimById(trims, id) {
    return trims.find(trim => String(trim.id ?? trim.trim_id ?? '') === String(id)) || null;
}
function uniqueValues(rows, field, numeric = false) {
    const values = [...new Set(
        rows.map(row => row?.[field])
            .filter(v => v !== null && v !== undefined && v !== '')
            .map(v => numeric ? Number(v) : String(v))
    )];
    return values.sort((a,b) => numeric ? a-b : String(a).localeCompare(String(b)));
}
function createOptionButtons(allValues, availableValues, formatter, selectedValue, extraClass='') {
    return allValues.map((value, idx) => {
        const isAvailable = availableValues.some(v => String(v) === String(value));
        const isActive = isAvailable && String(value) === String(selectedValue);
        const badge = allValues.length > 1 && idx === allValues.length - 1 ? '<span class="badge">최대</span>' : '';
        return `
            <button
                class="option-btn ${extraClass} ${isActive ? 'active' : ''}"
                type="button"
                data-value="${escapeHtml(value)}"
                ${isAvailable ? '' : 'disabled'}
            >
                ${badge}
                <span>${escapeHtml(formatter(value))}</span>
            </button>
        `;
    }).join('');
}
function createProductButtons(allValues, availableValues, selectedValue) {
    return allValues.map(value => {
        const isAvailable = availableValues.some(v => String(v) === String(value));
        const isActive = isAvailable && String(value) === String(selectedValue);
        return `
            <button
                class="option-btn product-btn ${isActive ? 'active' : ''}"
                type="button"
                data-value="${escapeHtml(value)}"
                ${isAvailable ? '' : 'disabled'}
            >
                <span class="title">${escapeHtml(productLabel(value))}</span>
                <span class="desc">${escapeHtml(productDesc(value)).replaceAll('\\n','<br>')}</span>
            </button>
        `;
    }).join('');
}
function toggleTrimSelect(event, vehicleKey) {
    event?.stopPropagation();

    const target = document.querySelector(
        `[data-trim-select="${CSS.escape(String(vehicleKey))}"]`
    );
    if (!target) return;

    document.querySelectorAll('.color-select.open').forEach(el => {
        el.classList.remove('open');
        el.querySelector('.color-select-button')?.setAttribute('aria-expanded', 'false');
    });

    document.querySelectorAll('.trim-select-wrap.open').forEach(el => {
        if (el !== target) {
            el.classList.remove('open');
            el.querySelector('.trim-select-button')?.setAttribute('aria-expanded', 'false');
        }
    });

    const isOpen = target.classList.toggle('open');
    target.querySelector('.trim-select-button')?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}

function syncTrimSelectDisplay(vehicleKey, trimId) {
    const wrapper = document.querySelector(
        `[data-trim-select="${CSS.escape(String(vehicleKey))}"]`
    );
    if (!wrapper) return;

    const selected = Array.from(wrapper.querySelectorAll('.trim-option')).find(
        option => String(option.dataset.trimId || '') === String(trimId || '')
    );
    const name = selected?.dataset.trimName || '트림 선택';
    const value = wrapper.querySelector(
        `[data-selected-trim-name="${CSS.escape(String(vehicleKey))}"]`
    );

    wrapper.querySelectorAll('.trim-option').forEach(option => option.classList.remove('active'));
    selected?.classList.add('active');
    if (value) value.textContent = name;
}

function selectTrimOption(event, vehicleKey, optionButton) {
    trackEstimateProgress('TRIM_SELECTED', {trim_id:Number(optionButton?.dataset?.trimId || 0), trim_name:optionButton?.dataset?.trimName || ''});
    event?.stopPropagation();

    const wrapper = document.querySelector(
        `[data-trim-select="${CSS.escape(String(vehicleKey))}"]`
    );
    const input = document.querySelector(
        `[data-quote="${CSS.escape(String(vehicleKey))}-trim"]`
    );
    if (!wrapper || !input || !optionButton) return;

    input.value = optionButton.dataset.trimId || '';
    syncTrimSelectDisplay(vehicleKey, input.value);

    wrapper.classList.remove('open');
    wrapper.querySelector('.trim-select-button')?.setAttribute('aria-expanded', 'false');

    input.dispatchEvent(new Event('change', { bubbles:true }));
}

function renderQuotePanel(vehicle, vehicleKey) {
    const trims = Array.isArray(vehicle.trims) ? vehicle.trims : [];

    return `
        <div class="trim-select-wrap" data-trim-select="${escapeHtml(vehicleKey)}">
            <input class="trim-select" type="hidden" data-quote="${escapeHtml(vehicleKey)}-trim" value="">
            <button
                class="trim-select-button"
                type="button"
                aria-expanded="false"
                onclick="toggleTrimSelect(event, '${escapeHtml(vehicleKey)}')"
            >
                <span class="trim-select-label">트림</span>
                <span class="trim-select-value" data-selected-trim-name="${escapeHtml(vehicleKey)}">트림 선택</span>
            </button>
            <div class="trim-select-menu">
                ${trims.length
                    ? trims.map(trim => `
                        <button
                            class="trim-option"
                            type="button"
                            data-trim-id="${escapeHtml(trim.id ?? '')}"
                            data-trim-name="${escapeHtml(trim.name ?? '-')}"
                            onclick="selectTrimOption(event, '${escapeHtml(vehicleKey)}', this)"
                        >${escapeHtml(trim.name ?? '-')}</button>
                    `).join('')
                    : `
                        <button
                            class="trim-option"
                            type="button"
                            data-trim-id="__NO_TRIM__"
                            data-trim-name="트림 미등록"
                            onclick="selectTrimOption(event, '${escapeHtml(vehicleKey)}', this)"
                        >트림 미등록</button>
                    `}
            </div>
        </div>

        <div class="quote-box" data-quote-panel="${escapeHtml(vehicleKey)}">
            <div class="quote-price-head">
                <div class="quote-price-main">
                    <span class="quote-price-value-wrap" data-role="monthly-wrap">
                        <strong class="quote-price-digits" data-role="monthly-main">-</strong>
                    </span>
                    <span class="quote-price-unit">원/월</span><span class="quote-price-chevron" aria-hidden="true">›</span>
                </div>
                <div class="price-tags">
                    <span class="price-tag active" data-role="insurance-info">보험·세금 조건 확인</span>
                    <span class="price-tag" data-role="tax-info">부가세 적용 여부 확인</span>
                </div>
            </div>

            <div class="section-row">
                <div class="section-label">
                    <strong>계약기간</strong>
                </div>
                <div class="option-group" data-role="months"></div>
            </div>

            <div class="section-row">
                <div class="section-label">
                    <strong>선납금</strong>
                    <span class="sub" data-role="prepayment-view">0만원</span>
                </div>
                <div class="option-group" data-role="prepayment"></div>
            </div>

            <div class="section-row">
                <div class="section-label">
                    <strong>1년에 주행할 거리</strong>
                    <span class="sub">km</span>
                </div>
                <div class="option-group" data-role="mileage"></div>
            </div>

            <div class="section-row">
                <div class="section-label">
                    <strong>이용상품</strong>
                </div>
                <div class="option-group two" data-role="product"></div>
            </div>

            <div class="notice" data-role="notice"></div>
            <div class="quote-choice-summary" data-role="choice-summary" aria-live="polite"></div>
            <p id="savedQuoteNotice" hidden></p><p class="quote-condition-footnote">월 이용료는 등록된 참고 금액이며, 최종 보험·세금·부가세 적용 및 계약 조건은 상담 시 확인됩니다.</p>
            <div class="quote-action-row">
                <button class="save-snapshot-button" type="button" data-quote-key="${escapeHtml(vehicleKey)}" onclick="saveCurrentQuote('${escapeHtml(vehicleKey)}', this)">조건 저장</button>
                <button class="action-btn" type="button" data-role="save-estimate">견적 신청하기</button>
            </div>
        </div>
    `;
}

function normalizePriceText(value) {
    const text = String(value ?? '').trim();
    if (!text || text === '-') return '-';

    const number = Number(text.replaceAll(',', ''));
    if (!Number.isFinite(number)) return '-';
    return Math.max(0, Math.round(number)).toLocaleString('ko-KR');
}

/* ============================================================
   월 납입금 오도미터
   - price-odometer-test.html에서 확정한 구조를 실제 견적 패널에 적용
   - 각 자리의 0~9 레일은 최초 1회만 생성
   - 이후 가격 변경은 transform만 갱신
   ============================================================ */
const PRICE_ODOMETER = Object.freeze({ maxDigits:7, cycles:21, centerCycle:10 });
const monthlyOdometers = new WeakMap();

function getPriceSlotHeight(panel) {
    const raw = getComputedStyle(panel.querySelector('.quote-price-main'))
        .getPropertyValue('--price-slot-height').trim();
    const value = parseFloat(raw);
    return Number.isFinite(value) && value > 0 ? value : 48;
}

function createOdometerTrack() {
    const track = document.createElement('span');
    track.className = 'price-digit-track';
    for (let cycle = 0; cycle < PRICE_ODOMETER.cycles; cycle++) {
        for (let digit = 0; digit <= 9; digit++) {
            const cell = document.createElement('span');
            cell.className = 'price-digit-cell';
            cell.textContent = String(digit);
            track.appendChild(cell);
        }
    }
    return track;
}

function setTrackPosition(track, position, slotHeight) {
    track.style.transform = `translate3d(0, ${-(position * slotHeight)}px, 0)`;
}

function createOdometer(panel, value) {
    const wrap = panel.querySelector('[data-role="monthly-wrap"]');
    if (!wrap) return null;

    const rounded = Math.max(0, Math.round(Number(String(value).replaceAll(',', ''))));
    if (!Number.isFinite(rounded)) return null;
    const text = rounded.toLocaleString('ko-KR');
    const maxDigits = Math.max(PRICE_ODOMETER.maxDigits, String(rounded).length);
    const slotHeight = getPriceSlotHeight(panel);
    const digits = String(rounded).padStart(maxDigits, '0').split('').map(Number);
    const container = document.createElement('strong');
    container.className = 'quote-price-digits';
    container.dataset.role = 'monthly-main';
    container.dataset.rawValue = text;
    container.setAttribute('aria-label', text);
    const slots = [];
    const commas = [];

    digits.forEach((digit, index) => {
        const slot = document.createElement('span');
        slot.className = 'price-digit';
        slot.dataset.digit = String(digit);
        const track = createOdometerTrack();
        const position = PRICE_ODOMETER.centerCycle * 10 + digit;
        slot._digit = digit;
        slot._position = position;
        slot.appendChild(track);
        track.style.transition = 'none';
        setTrackPosition(track, position, slotHeight);
        container.appendChild(slot);
        slots.push(slot);

        const remaining = maxDigits - index - 1;
        if (remaining > 0 && remaining % 3 === 0) {
            const comma = document.createElement('span');
            comma.className = 'price-digit-comma';
            comma.textContent = ',';
            container.appendChild(comma);
            commas.push({ afterIndex:index, el:comma });
        }
    });

    wrap.replaceChildren(container);
    const state = { container, slots, commas, maxDigits, slotHeight, currentValue:rounded, currentDigits:digits };
    monthlyOdometers.set(panel, state);
    updateOdometerLeadingVisibility(state, rounded, false);
    requestAnimationFrame(() => slots.forEach(slot => {
        const track = slot.querySelector('.price-digit-track');
        if (track) track.style.transition = '';
    }));
    return state;
}

function signedShortestDigitDelta(fromDigit, toDigit) {
    let delta = toDigit - fromDigit;
    if (delta > 5) delta -= 10;
    if (delta < -5) delta += 10;
    return delta;
}

function updateOdometerLeadingVisibility(state, value, animate = true) {
    const firstVisibleIndex = state.maxDigits - String(Math.max(0, Math.round(value))).length;
    state.slots.forEach((slot, index) => slot.classList.toggle('is-leading-hidden', index < firstVisibleIndex));
    state.commas.forEach(({afterIndex, el}) => {
        const visible = afterIndex >= firstVisibleIndex && state.maxDigits - afterIndex - 1 > 0;
        if (!animate) el.style.transition = 'none';
        el.classList.toggle('is-hidden', !visible);
    });
    if (!animate) requestAnimationFrame(() => state.commas.forEach(({el}) => { el.style.transition = ''; }));
}

function showEmptyMonthlyPrice(panel) {
    monthlyOdometers.delete(panel);
    const wrap = panel.querySelector('[data-role="monthly-wrap"]');
    if (!wrap) return;
    const empty = document.createElement('strong');
    empty.className = 'quote-price-digits quote-price-empty';
    empty.dataset.role = 'monthly-main';
    empty.dataset.rawValue = '-';
    empty.textContent = '-';
    wrap.replaceChildren(empty);
}

function animateMonthlyPrice(panel, nextValue) {
    const nextText = normalizePriceText(nextValue);
    if (nextText === '-') {
        showEmptyMonthlyPrice(panel);
        return;
    }

    const nextNumber = Number(nextText.replaceAll(',', ''));
    if (!Number.isFinite(nextNumber)) {
        showEmptyMonthlyPrice(panel);
        return;
    }

    let state = monthlyOdometers.get(panel);
    if (!state || String(nextNumber).length > state.maxDigits) {
        createOdometer(panel, nextNumber);
        return;
    }
    if (state.currentValue === nextNumber) return;

    const nextDigits = String(nextNumber).padStart(state.maxDigits, '0').split('').map(Number);
    state.slots.forEach((slot, index) => {
        const fromDigit = Number(slot._digit ?? state.currentDigits[index] ?? 0);
        const toDigit = nextDigits[index];
        if (fromDigit === toDigit) return;
        const delta = signedShortestDigitDelta(fromDigit, toDigit);
        let nextPosition = Number(slot._position ?? PRICE_ODOMETER.centerCycle * 10 + fromDigit) + delta;
        if (nextPosition < 10 || nextPosition > PRICE_ODOMETER.cycles * 10 - 11) {
            nextPosition = PRICE_ODOMETER.centerCycle * 10 + fromDigit;
            slot._position = nextPosition;
            const track = slot.querySelector('.price-digit-track');
            if (track) {
                track.style.transition = 'none';
                setTrackPosition(track, nextPosition, state.slotHeight);
                void track.offsetHeight;
                track.style.transition = '';
            }
            nextPosition += delta;
        }
        slot._digit = toDigit;
        slot.dataset.digit = String(toDigit);
        slot._position = nextPosition;
        const track = slot.querySelector('.price-digit-track');
        if (track) setTrackPosition(track, nextPosition, state.slotHeight);
    });

    state.currentValue = nextNumber;
    state.currentDigits = nextDigits;
    state.container.dataset.rawValue = nextText;
    state.container.setAttribute('aria-label', nextText);
    updateOdometerLeadingVisibility(state, nextNumber, true);
}

function initQuotePanel(vehicle, vehicleKey, initialQuote = null) {
    const trims = Array.isArray(vehicle.trims) ? vehicle.trims : [];
    const trimSelect = document.querySelector(`[data-quote="${CSS.escape(String(vehicleKey))}-trim"]`);
    const panel = document.querySelector(`[data-quote-panel="${CSS.escape(String(vehicleKey))}"]`);
    if (!trimSelect || !panel) return;

    let monthlyMain = panel.querySelector('[data-role="monthly-main"]');
    if (monthlyMain) monthlyMain.dataset.rawValue = monthlyMain.textContent.trim();

    const monthsWrap = panel.querySelector('[data-role="months"]');
    const prepaymentWrap = panel.querySelector('[data-role="prepayment"]');
    const prepaymentView = panel.querySelector('[data-role="prepayment-view"]');
    const mileageWrap = panel.querySelector('[data-role="mileage"]');
    const productWrap = panel.querySelector('[data-role="product"]');
    const notice = panel.querySelector('[data-role="notice"]');
    const saveButton = panel.querySelector('[data-role="save-estimate"]');

    // 화면에 항상 보여줄 기본 슬롯
    const ALL_PRODUCTS = ['RENT', 'LEASE'];
    const ALL_MONTHS = [12, 24, 36, 48, 60];
    const ALL_PREPAYMENT = [0, 10, 20, 30];
    const ALL_MILEAGE = [10000, 15000, 20000, 30000];

    const state = {
        trimId: '',
        product: '',
        months: '',
        prepayment: '',
        mileage: '',
        priceId: ''
    };

    let preserveUnavailable = Boolean(initialQuote?.preserveUnavailable);
    function getCurrentTrim() {
        if (!state.trimId || state.trimId === '__NO_TRIM__') return null;
        return getTrimById(trims, state.trimId);
    }

    function getTrimPrices() {
        const trim = getCurrentTrim();
        return Array.isArray(trim?.prices) ? trim.prices : [];
    }

    function chooseDefaultTrim() {
        const withPrices = trims.find(trim => Array.isArray(trim.prices) && trim.prices.length);
        const first = withPrices || trims[0];
        if (first) state.trimId = String(first.id ?? '');
        else state.trimId = '__NO_TRIM__';
        trimSelect.value = state.trimId;
        syncTrimSelectDisplay(vehicleKey, state.trimId);
    }

    function getAvailableProducts() {
        const prices = getTrimPrices();
        return prices.length ? uniqueValues(prices, 'product_type') : ALL_PRODUCTS;
    }

    function getAvailableMonths() {
        const prices = getTrimPrices().filter(row =>
            !state.product || String(row.product_type) === String(state.product)
        );
        return prices.length ? uniqueValues(prices, 'contract_months', true) : ALL_MONTHS;
    }

    function getAvailablePrepayment() {
        const prices = getTrimPrices().filter(row =>
            (!state.product || String(row.product_type) === String(state.product)) &&
            (!state.months || Number(row.contract_months) === Number(state.months))
        );
        return prices.length ? uniqueValues(prices, 'prepayment_rate', true) : ALL_PREPAYMENT;
    }

    function getAvailableMileage() {
        const prices = getTrimPrices().filter(row =>
            (!state.product || String(row.product_type) === String(state.product)) &&
            (!state.months || Number(row.contract_months) === Number(state.months)) &&
            ((!state.prepayment && state.prepayment !== 0) || Number(row.prepayment_rate || 0) === Number(state.prepayment))
        );
        return prices.length ? uniqueValues(prices, 'annual_mileage', true) : ALL_MILEAGE;
    }

    function getAllOptionValues(defaultValues, field) {
        return [...new Set([...defaultValues, ...uniqueValues(getTrimPrices(), field, true), ...(preserveUnavailable && initialQuote?.price?.[field] !== undefined ? [Number(initialQuote.price[field])] : [])])]
            .sort((a, b) => a - b);
    }

    function getMatchedPrice() {
        return getTrimPrices().find(row =>
            String(row.product_type) === String(state.product) &&
            Number(row.contract_months) === Number(state.months) &&
            Number(row.prepayment_rate || 0) === Number(state.prepayment) &&
            Number(row.annual_mileage || 0) === Number(state.mileage)
        ) || null;
    }

    function selectFirstAvailable(list, fallback = '') {
        return list.length ? String(list[0]) : String(fallback);
    }

    function bindButtons(container, onSelect) {
        container.querySelectorAll('button[data-value]:not(:disabled)').forEach(btn => {
            btn.addEventListener('click', () => {
                preserveUnavailable = false;
                onSelect(btn.dataset.value);
                const selectedTrim = getCurrentTrim();
                trackEstimateProgress('CONDITIONS_SELECTED', {
                    vehicle_id:Number((vehicle.vehicle_id ?? vehicle.id) || 0),
                    vehicle_name:getVehicleName(vehicle),
                    trim_id:Number(selectedTrim?.id || 0),
                    trim_name:selectedTrim?.name || '',
                    product_type:state.product || ''
                });
            });
        });
    }

    function getPrepaymentText() {
        const basePrice = Number(getCurrentTrim()?.price || vehicle.base_price || 0);
        const percent = Number(state.prepayment || 0);

        if (!basePrice || !percent) return '0만원';

        const amountWon = Math.round(basePrice * (percent / 100));
        const amountManwon = Math.round(amountWon / 10000);

        return `${amountManwon.toLocaleString('ko-KR')}만원`;
    }

    function render() {
        const availableProducts = getAvailableProducts();
        if (!preserveUnavailable && !availableProducts.some(v => String(v) === String(state.product))) {
            state.product = selectFirstAvailable(availableProducts, ALL_PRODUCTS[0]);
        }

        const availableMonths = getAvailableMonths();
        if (!preserveUnavailable && !availableMonths.some(v => String(v) === String(state.months))) {
            state.months = selectFirstAvailable(availableMonths, ALL_MONTHS[ALL_MONTHS.length - 1]);
        }

        const availablePrepayments = getAvailablePrepayment();
        if (!preserveUnavailable && !availablePrepayments.some(v => String(v) === String(state.prepayment))) {
            state.prepayment = selectFirstAvailable(availablePrepayments, 0);
        }

        const availableMileages = getAvailableMileage();
        if (!preserveUnavailable && !availableMileages.some(v => String(v) === String(state.mileage))) {
            state.mileage = selectFirstAvailable(availableMileages, ALL_MILEAGE[0]);
        }

        monthsWrap.innerHTML = createOptionButtons(
            getAllOptionValues(ALL_MONTHS, 'contract_months'),
            availableMonths,
            yearsFromMonths,
            state.months
        );

        prepaymentWrap.innerHTML = createOptionButtons(
            getAllOptionValues(ALL_PREPAYMENT, 'prepayment_rate'),
            availablePrepayments,
            v => `${v}%`,
            state.prepayment
        );

        mileageWrap.innerHTML = createOptionButtons(
            getAllOptionValues(ALL_MILEAGE, 'annual_mileage'),
            availableMileages,
            formatMileageButton,
            state.mileage
        );

        productWrap.innerHTML = createProductButtons(
            ALL_PRODUCTS,
            availableProducts,
            state.product
        );

        bindButtons(monthsWrap, value => { state.months = String(value); render(); });
        bindButtons(prepaymentWrap, value => { state.prepayment = String(value); render(); });
        bindButtons(mileageWrap, value => { state.mileage = String(value); render(); });
        bindButtons(productWrap, value => { state.product = String(value); render(); });

        const selectedTrim = getCurrentTrim();
        const matchedPrice = getMatchedPrice();
        state.priceId = matchedPrice?.id ? String(matchedPrice.id) : '';
        quoteStates.set(String(vehicleKey), {
            vehicle,
            trim: selectedTrim,
            trimId: state.trimId,
            product: state.product,
            months: state.months,
            prepayment: state.prepayment,
            mileage: state.mileage,
            price: matchedPrice,
            priceId: state.priceId
        });
        if (typeof syncSavedQuoteButtons === 'function') syncSavedQuoteButtons(vehicleKey);
        prepaymentView.textContent = getPrepaymentText();
        const insuranceInfo = panel.querySelector('[data-role="insurance-info"]');
        const taxInfo = panel.querySelector('[data-role="tax-info"]');
        if (insuranceInfo) insuranceInfo.textContent = state.product === 'LEASE' ? '보험 가입 조건 확인' : '보험·자동차세 조건 확인';
        if (taxInfo) taxInfo.textContent = '부가세 적용 여부 확인';
        const choiceSummary = panel.querySelector('[data-role="choice-summary"]');
        if (choiceSummary) {
            const choices = [
                ['계약기간', yearsFromMonths(state.months)],
                ['선납금', `${Number(state.prepayment || 0)}%`],
                ['연 주행거리', Number(state.mileage) === 0 ? '무제한' : `${Number(state.mileage).toLocaleString('ko-KR')}km`],
                ['이용상품', productLabel(state.product)]
            ];
            choiceSummary.innerHTML = `<strong class="quote-choice-title">선택한 견적 조건</strong><div class="quote-choice-grid">${choices.map(([label, value]) => `<div><span>${escapeHtml(label)}</span><b>${escapeHtml(value)}</b></div>`).join('')}</div>`;
        }
        if (saveButton) saveButton.disabled = !matchedPrice || Number(matchedPrice.monthly_payment) <= 0 || !selectedTrim;

        if (matchedPrice?.monthly_payment) {
            animateMonthlyPrice(panel, shortMoney(matchedPrice.monthly_payment));
            monthlyMain = panel.querySelector('[data-role="monthly-main"]');
            notice.textContent = '';
        } else {
            animateMonthlyPrice(panel, '-');
            monthlyMain = panel.querySelector('[data-role="monthly-main"]');

            if (!selectedTrim) {
                notice.textContent = '트림 데이터가 없어 기본 슬롯만 표시합니다.';
            } else if (!getTrimPrices().length) {
                notice.textContent = '선택한 트림의 가격 조건이 아직 등록되지 않았습니다.';
            } else {
                notice.textContent = '비활성화된 옵션은 현재 트림/상품 조합에서 선택할 수 없는 조건입니다.';
            }
        }
    }

    saveButton?.addEventListener('click', () => openEstimateModal(vehicleKey));

    trimSelect.addEventListener('change', () => {
        preserveUnavailable = false;
        state.trimId = trimSelect.value;
        state.product = '';
        state.months = '';
        state.prepayment = '';
        state.mileage = '';
        render();
    });

    chooseDefaultTrim();
    if (initialQuote) {
        const { trim, price } = initialQuote;
        state.trimId = String(trim.id ?? '');
        state.product = String(price.product_type);
        state.months = String(price.contract_months);
        state.prepayment = String(price.prepayment_rate ?? 0);
        state.mileage = String(price.annual_mileage ?? 0);
        trimSelect.value = state.trimId;
        syncTrimSelectDisplay(vehicleKey, state.trimId);
    }
    render();
}

function openEstimateModal(vehicleKey) {
    const quote = quoteStates.get(String(vehicleKey));
    const colorWrap = document.querySelector(`[data-color-select="${CSS.escape(String(vehicleKey))}"]`);
    const colorId = colorWrap?.dataset.selectedColorId || '';
    const colorName = colorWrap?.dataset.selectedColorName || '-';

    if (!quote?.trim || !quote?.price?.id) {
        alert('저장할 수 있는 가격 조건을 먼저 선택해 주세요.');
        return;
    }
    if (!colorId) {
        alert('외장색상을 선택해 주세요.');
        return;
    }

    trackEstimateProgress('ESTIMATE_FORM', {
        vehicle_id: Number(quote.vehicle.vehicle_id ?? quote.vehicle.id),
        vehicle_name: getVehicleName(quote.vehicle),
        trim_id: Number(quote.trim.id),
        trim_name: quote.trim.name || '',
        product_type: quote.product || ''
    });

    pendingEstimate = {
        vehicle_id: Number(quote.vehicle.vehicle_id ?? quote.vehicle.id),
        trim_id: Number(quote.trim.id),
        color_id: Number(colorId),
        price_id: Number(quote.price.id)
    };

    // 이미 한 번 접수한 뒤 다시 열어도 새 견적을 신청할 수 있게 입력 상태를 초기화합니다.
    const estimateForm = document.getElementById('estimateForm');
    if (estimateForm) {
        estimateForm.reset();
        estimateForm.querySelectorAll('input, select, textarea').forEach(el => { el.disabled = false; });
        const submit = estimateForm.querySelector('.estimate-submit');
        if (submit) { submit.disabled = false; submit.textContent = '이 견적으로 신청하기'; }
    }
    configureEstimateRegion(quote.vehicle.fuel_type);
    const summary = document.getElementById('estimateSummary');
    summary.innerHTML = `
        <strong>${escapeHtml(getBrandName(quote.vehicle))} ${escapeHtml(getVehicleName(quote.vehicle))}</strong>
        ${escapeHtml(quote.trim.name ?? '-')} · ${escapeHtml(colorName)}<br>
        ${escapeHtml(productLabel(quote.product))} · ${escapeHtml(yearsFromMonths(quote.months))} · 선납금 ${escapeHtml(String(quote.prepayment))}% · 연 ${Number(quote.mileage)===0?'무제한':escapeHtml(Number(quote.mileage).toLocaleString('ko-KR'))+'km'}<br>
        월 <b>${escapeHtml(Number(quote.price.monthly_payment || 0).toLocaleString('ko-KR'))}원</b>
    `;

    const result = document.getElementById('estimateResult');
    result.className = 'estimate-result';
    result.textContent = '';
    const modal = document.getElementById('estimateModal');
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    fillEstimateMemberFields('estimate');
    // 모바일에서는 신청창이 열리자마자 키보드가 올라오는 것을 방지합니다.
    if (window.matchMedia('(min-width: 769px) and (pointer: fine)').matches) {
        setTimeout(() => {
            const name = document.getElementById('estimateName');
            const phone = document.getElementById('estimatePhone');
            if (!name?.value.trim()) name?.focus();
            else if (!phone?.value.trim()) phone?.focus();
        }, 50);
    }
}

function configureEstimateRegion(fuelType) {
    const field = document.getElementById('estimateRegionField');
    const province = document.getElementById('estimateProvince');
    if (!field || !province) return;
    const electric = String(fuelType || '').toUpperCase() === 'EV';
    field.hidden = !electric;
    field.style.display = electric ? '' : 'none';
    province.disabled = !electric;
    province.required = electric;
    if (!electric) province.value = '';
}

function closeEstimateModal() {
    const modal = document.getElementById('estimateModal');
    if (modal?.contains(document.activeElement)) document.activeElement.blur();
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open', 'ag-mobile-field-focus');
}

document.querySelectorAll('[data-estimate-close]').forEach(btn => btn.addEventListener('click', closeEstimateModal));
document.getElementById('estimateModal')?.addEventListener('click', e => { if (e.target.id === 'estimateModal') closeEstimateModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEstimateModal(); });

const estimateNameInput = document.getElementById('estimateName');
const estimatePhoneInput = document.getElementById('estimatePhone');

// 이름: 한글/영문/공백만 허용하고 연속 공백은 1칸으로 정리합니다.
estimateNameInput?.addEventListener('focus', () => trackEstimateProgress('CUSTOMER_INPUT', pendingEstimate || {}));
estimatePhoneInput?.addEventListener('focus', () => trackEstimateProgress('CUSTOMER_INPUT', pendingEstimate || {}));

estimateNameInput?.addEventListener('input', event => {
    const input = event.currentTarget;
    const cleaned = input.value
        .replace(/[^가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z\s]/g, '')
        .replace(/\s{2,}/g, ' ')
        .slice(0, 30);
    if (input.value !== cleaned) input.value = cleaned;
    input.setCustomValidity('');
});

estimateNameInput?.addEventListener('blur', event => {
    event.currentTarget.value = event.currentTarget.value.trim().replace(/\s{2,}/g, ' ');
});

// 연락처: 010과 기존 이동전화 식별번호(011/016/017/018/019)를 지원합니다.
// 010은 11자리(010-1234-5678), 기존 번호는 10~11자리(011-123-4567 / 011-1234-5678)를 허용합니다.
function formatEstimatePhone(value) {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 11);
    if (digits.length <= 3) return digits;

    const prefix = digits.slice(0, 3);
    const isLegacyMobile = /^(011|016|017|018|019)$/.test(prefix);

    if (isLegacyMobile && digits.length <= 10) {
        if (digits.length <= 6) return `${prefix}-${digits.slice(3)}`;
        return `${prefix}-${digits.slice(3, 6)}-${digits.slice(6)}`;
    }

    if (digits.length <= 7) return `${prefix}-${digits.slice(3)}`;
    return `${prefix}-${digits.slice(3, 7)}-${digits.slice(7)}`;
}

estimatePhoneInput?.addEventListener('input', event => {
    const input = event.currentTarget;
    input.value = formatEstimatePhone(input.value);
    input.setCustomValidity('');
});

document.getElementById('estimateForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    if (!pendingEstimate) return;

    const form = event.currentTarget;
    if (form.dataset.agSubmitting === '1') return;
    const submit = form.querySelector('.estimate-submit');
    const result = document.getElementById('estimateResult');
    const formData = new FormData(form);
    const payload = {
        ...pendingEstimate,
        customer_name: String(formData.get('customer_name') || '').trim().replace(/\s{2,}/g, ' '),
        customer_phone: formatEstimatePhone(formData.get('customer_phone')),
        customer_memo: String(formData.get('customer_memo') || '').trim(),
        registration_province: String(formData.get('registration_province') || ''),
        privacy_consent: formData.get('privacy_consent') === '1',
        ...getAcquisitionData()
    };

    const validName = /^[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+(?:\s[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+)*$/.test(payload.customer_name)
        && payload.customer_name.replace(/\s/g, '').length >= 2;
    if (!validName) {
        estimateNameInput?.setCustomValidity('성함은 한글 또는 영문만 2자 이상 입력해 주세요.');
        estimateNameInput?.reportValidity();
        estimateNameInput?.focus();
        return;
    }

    const phoneDigits = payload.customer_phone.replace(/\D/g, '');
    if (!/^(?:010\d{8}|01[16789]\d{7,8})$/.test(phoneDigits)) {
        estimatePhoneInput?.setCustomValidity('010, 011, 016, 017, 018, 019로 시작하는 올바른 휴대폰 번호를 입력해 주세요.');
        estimatePhoneInput?.reportValidity();
        estimatePhoneInput?.focus();
        return;
    }

    form.dataset.agSubmitting = '1';
    submit.disabled = true;
    submit.textContent = '접수 중...';
    result.className = 'estimate-result';

    try {
        const response = await agFetch(SAVE_ESTIMATE_URL, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify(payload)
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) throw new Error(data.message || `HTTP ${response.status}`);
        trackEstimateProgress('COMPLETED', {...pendingEstimate});

        result.className = 'estimate-result show ok';
        result.innerHTML = `견적 신청이 완료되었습니다.<br><strong>접수번호 ${escapeHtml(data.estimate_no || '')}</strong><br>확인 후 입력하신 연락처로 안내드리겠습니다.`;
        submit.textContent = '신청 완료';
        form.querySelectorAll('input, textarea').forEach(el => el.disabled = true);
    } catch (error) {
        result.className = 'estimate-result show error';
        result.textContent = error.message || '견적 저장에 실패했습니다.';
        submit.disabled = false;
        submit.textContent = '이 견적으로 신청하기';
    } finally {
        delete form.dataset.agSubmitting;
    }
});

function getVehicleId(vehicle, index = 0) {
    return String(vehicle.vehicle_id ?? vehicle.id ?? index);
}

function getMinMonthlyPrice(vehicle, productFilter = vehicleProductFilter) {
    return getMinMonthlyQuote(vehicle, productFilter)?.monthlyPayment || 0;
}

function getMinMonthlyQuote(vehicle, productFilter = vehicleProductFilter) {
    if (productFilter === 'ALL') {
        const preferred = vehicle.price_basis_product === 'LEASE' ? 'LEASE' : 'RENT';
        return getMinMonthlyQuote(vehicle, preferred)
            || getMinMonthlyQuote(vehicle, preferred === 'RENT' ? 'LEASE' : 'RENT');
    }
    let best = null;
    const trims = Array.isArray(vehicle.trims) ? vehicle.trims : [];
    trims.forEach(trim => {
        (Array.isArray(trim.prices) ? trim.prices : []).forEach(price => {
            const product = String(price.product_type ?? '').toUpperCase();
            if (productFilter !== 'ALL' && product !== productFilter) return;

            const value = Number(price.monthly_payment ?? price.monthly_price ?? 0);
            if (Number.isFinite(value) && value > 0 && (!best || value < best.monthlyPayment)) {
                best = { trim, price, monthlyPayment: value };
            }
        });
    });
    return best;
}

function vehicleHasProduct(vehicle, productFilter = vehicleProductFilter) {
    if (productFilter === 'ALL') return true;
    return (Array.isArray(vehicle.trims) ? vehicle.trims : []).some(trim =>
        (Array.isArray(trim.prices) ? trim.prices : []).some(price =>
            String(price.product_type ?? '').toUpperCase() === productFilter
        )
    );
}

function getListProductLabel(vehicle) {
    const quote = getMinMonthlyQuote(vehicle);
    const product = String(quote?.price.product_type ?? '').toUpperCase();
    if (product === 'RENT') return '장기렌트 기준가';
    if (product === 'LEASE') return '리스 기준가';
    return '가격 문의';
}

function getVehicleFuelText(vehicle) {
    return String(vehicle.fuel_type ?? vehicle.fuel ?? '').toUpperCase();
}

function getVehicleOrigin(vehicle) {
    // DB에 origin_type이 있으면 그 값을 최우선 사용
    const dbOrigin = String(
        vehicle.origin_type ??
        vehicle.brand_origin_type ??
        vehicle.origin ??
        ''
    ).trim().toUpperCase();

    if (['DOMESTIC', 'KOREA', 'KR', '국산', '국산차'].includes(dbOrigin)) return 'DOMESTIC';
    if (['IMPORT', 'FOREIGN', '수입', '수입차'].includes(dbOrigin)) return 'IMPORT';

    // 기존 데이터에는 origin_type이 없을 수 있으므로 브랜드명으로 fallback
    const brand = String(getBrandName(vehicle) || '').trim();
    return DOMESTIC_BRANDS.has(brand) ? 'DOMESTIC' : 'IMPORT';
}

function isElectricVehicle(vehicle) {
    const fuel = getVehicleFuelText(vehicle);
    const category = String(
        vehicle.category ??
        vehicle.category_slug ??
        vehicle.category_name ??
        ''
    ).toUpperCase();

    return (
        fuel.includes('EV') ||
        fuel.includes('전기') ||
        fuel.includes('ELECTRIC') ||
        category.includes('EV') ||
        category.includes('ELECTRIC') ||
        category.includes('전기')
    );
}

function isHybridVehicle(vehicle) {
    const fuel = getVehicleFuelText(vehicle);
    const category = String(
        vehicle.category ??
        vehicle.category_slug ??
        vehicle.category_name ??
        ''
    ).toUpperCase();

    return (
        fuel.includes('HYBRID') ||
        fuel.includes('하이브리드') ||
        category.includes('HYBRID') ||
        category.includes('하이브리드')
    );
}

function matchesVehicleCategory(vehicle, index, category) {
    // 카테고리는 서로 배타적이지 않음.
    // 예: 국산 하이브리드 -> 국산차 + HYBRID 둘 다 노출
    //     수입 전기차     -> 수입차 + 전기차 둘 다 노출
    switch (category) {
        case 'ALL':
            return true;
        case 'BEST':
            return isBestVehicle(vehicle, index);
        case 'DOMESTIC':
            return getVehicleOrigin(vehicle) === 'DOMESTIC';
        case 'IMPORT':
            return getVehicleOrigin(vehicle) === 'IMPORT';
        case 'EV':
            return isElectricVehicle(vehicle);
        case 'HYBRID':
            return isHybridVehicle(vehicle);
        default:
            return true;
    }
}

function isBestVehicle(vehicle, index) {
    const raw = vehicle.is_best ?? vehicle.best ?? vehicle.best_yn ?? '';

    // DB에서 명시적으로 BEST로 지정된 차량만 노출
    return (
        raw === true ||
        raw === 1 ||
        String(raw).toUpperCase() === 'Y' ||
        String(raw).toUpperCase() === 'YES' ||
        String(raw).toUpperCase() === 'BEST'
    );
}

function listVehicleImageHtml(vehicle, vehicleKey) {
    const candidates = imageCandidates(vehicle);
    if (!candidates.length) return '';
    return `
        <img
            src="${escapeHtml(candidates[0])}"
            alt="${escapeHtml(getVehicleName(vehicle))}"
            data-vehicle-key="${escapeHtml(vehicleKey)}"
            data-vehicle-brand="${escapeHtml(getBrandName(vehicle))}"
            data-vehicle-name="${escapeHtml(getVehicleName(vehicle))}"
            data-image-candidates='${escapeHtml(JSON.stringify(candidates))}'
            data-image-index="0"
            onload="normalizeVehicleListImage(this)"
            onerror="tryNextVehicleImage(this)"
        >
    `;
}

// x/y are offsets relative to the image holder; scale adjusts the automatic fit.
const VEHICLE_LIST_IMAGE_ADJUSTMENTS = window.VEHICLE_LIST_IMAGE_ADJUSTMENTS || {
    default: {},
    // Brand defaults apply to every model; vehicles can be keyed by model name or vehicle ID.
    // Example: '기아': {default: {scale: 1}, vehicles: {'쏘렌토': {scale: 1.05, x: -0.02, y: 0}}}
    brands: {
        '현대': {default: {}, vehicles: {}},
        '기아': {default: {}, vehicles: {}},
        '쉐보레': {default: {}, vehicles: {}},
        '르노': {default: {}, vehicles: {}},
        'KGM': {default: {}, vehicles: {}},
        'BMW': {default: {}, vehicles: {}},
        '벤츠': {default: {}, vehicles: {}},
        '아우디': {default: {}, vehicles: {}},
        '폭스바겐': {default: {}, vehicles: {}},
        '볼보': {default: {}, vehicles: {}},
        '랜드로버': {default: {}, vehicles: {}},
        '테슬라': {default: {}, vehicles: {}}
    },
    vehicles: {}
};

const vehicleListThumbBoundsCache = new Map();

function getVehicleListImageBounds(img) {
    const srcKey = img.currentSrc || img.src || '';
    if (vehicleListThumbBoundsCache.has(srcKey)) {
        return vehicleListThumbBoundsCache.get(srcKey);
    }

    const nw = img.naturalWidth || 0;
    const nh = img.naturalHeight || 0;
    if (!nw || !nh) return null;

    // 큰 원본도 매번 전체 픽셀을 읽지 않도록 분석용 캔버스만 축소합니다.
    const maxSample = 360;
    const sampleScale = Math.min(1, maxSample / Math.max(nw, nh));
    const sw = Math.max(1, Math.round(nw * sampleScale));
    const sh = Math.max(1, Math.round(nh * sampleScale));
    const canvas = document.createElement('canvas');
    canvas.width = sw;
    canvas.height = sh;
    const ctx = canvas.getContext('2d', {willReadFrequently:true});
    if (!ctx) return null;

    try {
        ctx.clearRect(0, 0, sw, sh);
        ctx.drawImage(img, 0, 0, sw, sh);
        const pixels = ctx.getImageData(0, 0, sw, sh).data;

        let minX = sw, minY = sh, maxX = -1, maxY = -1;
        const alphaThreshold = 48;
        let visibleCount = 0;
        const colCount = new Uint16Array(sw);
        const rowCount = new Uint16Array(sh);

        // 투명 그림자나 외곽의 희미한 픽셀은 차량 본체 크기/중심 계산에서 제외합니다.
        for (let y = 0; y < sh; y++) {
            for (let x = 0; x < sw; x++) {
                const i = (y * sw + x) * 4;
                if (pixels[i + 3] >= alphaThreshold) {
                    visibleCount++;
                    colCount[x]++;
                    rowCount[y]++;
                }
            }
        }

        const alphaCoverage = visibleCount / (sw * sh);

        // 한두 픽셀짜리 안테나/그림자/노이즈가 경계가 되지 않도록
        // 실제 차량 픽셀이 일정량 존재하는 행/열만 피사체 경계로 사용합니다.
        const minColumnPixels = Math.max(2, Math.round(sh * 0.012));
        const minRowPixels = Math.max(2, Math.round(sw * 0.012));
        for (let x = 0; x < sw; x++) {
            if (colCount[x] >= minColumnPixels) {
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
            }
        }
        for (let y = 0; y < sh; y++) {
            if (rowCount[y] >= minRowPixels) {
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
            }
        }

        // 2차: 배경이 완전 불투명한 이미지라면 모서리 배경색과 다른 영역을 차량으로 추정합니다.
        if (alphaCoverage > 0.94) {
            const cornerSize = Math.max(2, Math.round(Math.min(sw, sh) * 0.035));
            let rs = 0, gs = 0, bs = 0, cs = 0;
            const addCorner = (x0, y0) => {
                for (let y = y0; y < Math.min(sh, y0 + cornerSize); y++) {
                    for (let x = x0; x < Math.min(sw, x0 + cornerSize); x++) {
                        const i = (y * sw + x) * 4;
                        rs += pixels[i]; gs += pixels[i+1]; bs += pixels[i+2]; cs++;
                    }
                }
            };
            addCorner(0, 0);
            addCorner(Math.max(0, sw - cornerSize), 0);
            addCorner(0, Math.max(0, sh - cornerSize));
            addCorner(Math.max(0, sw - cornerSize), Math.max(0, sh - cornerSize));
            const br = rs / Math.max(1, cs), bg = gs / Math.max(1, cs), bb = bs / Math.max(1, cs);

            minX = sw; minY = sh; maxX = -1; maxY = -1;
            for (let y = 0; y < sh; y++) {
                for (let x = 0; x < sw; x++) {
                    const i = (y * sw + x) * 4;
                    const dr = pixels[i] - br;
                    const dg = pixels[i+1] - bg;
                    const db = pixels[i+2] - bb;
                    const distance = Math.sqrt(dr*dr + dg*dg + db*db);
                    if (distance > 30) {
                        if (x < minX) minX = x;
                        if (x > maxX) maxX = x;
                        if (y < minY) minY = y;
                        if (y > maxY) maxY = y;
                    }
                }
            }
        }

        if (maxX < minX || maxY < minY) return null;

        // 1~2px의 잡음이 외곽 경계가 되는 것을 줄이기 위한 아주 작은 인셋입니다.
        const padX = Math.max(0, Math.round((maxX - minX + 1) * 0.006));
        const padY = Math.max(0, Math.round((maxY - minY + 1) * 0.006));
        minX = Math.min(maxX, minX + padX);
        maxX = Math.max(minX, maxX - padX);
        minY = Math.min(maxY, minY + padY);
        maxY = Math.max(minY, maxY - padY);

        const result = {
            left: minX / sw,
            top: minY / sh,
            right: (maxX + 1) / sw,
            bottom: (maxY + 1) / sh
        };
        vehicleListThumbBoundsCache.set(srcKey, result);
        return result;
    } catch (error) {
        // 같은 출처가 아닌 이미지 등 캔버스를 읽을 수 없는 경우 기존 contain 방식으로 안전하게 표시합니다.
        return null;
    }
}

function normalizeVehicleListImage(img) {
    if (!img || !img.complete || !img.naturalWidth || !img.naturalHeight) return;
    const holder = img.closest('.vehicle-list-image');
    if (!holder) return;

    const bounds = getVehicleListImageBounds(img);
    if (!bounds) {
        img.classList.add('vehicle-thumb-fallback');
        return;
    }

    const cw = holder.clientWidth;
    const ch = holder.clientHeight;
    if (!cw || !ch) return;

    const nw = img.naturalWidth;
    const nh = img.naturalHeight;
    const boxW = Math.max(1, (bounds.right - bounds.left) * nw);
    const boxH = Math.max(1, (bounds.bottom - bounds.top) * nh);

    // 리스트에서는 차종마다 높이 차이가 커도 가로 폭이 지나치게 작아지지 않게 합니다.
    // 기본은 피사체 가로폭을 맞추고, 너무 높은 차량만 높이 상한으로 제한합니다.
    const brand = VEHICLE_LIST_IMAGE_ADJUSTMENTS.brands?.[img.dataset.vehicleBrand] || {};
    const defaults = {...VEHICLE_LIST_IMAGE_ADJUSTMENTS.default, ...brand.default};
    const targetW = cw * (defaults.fitWidth ?? 0.92);
    const targetH = ch * (defaults.fitHeight ?? 0.84);
    let scale = targetW / boxW;
    if (boxH * scale > targetH) scale = targetH / boxH;

    const adjustment = {
        ...brand.vehicles?.[img.dataset.vehicleName],
        ...brand.vehicles?.[img.dataset.vehicleKey],
        ...VEHICLE_LIST_IMAGE_ADJUSTMENTS.vehicles?.[img.dataset.vehicleKey]
    };
    const requestedScale = Number(adjustment.scale ?? defaults.scale);
    // Keep manual adjustments visible and editable in DevTools, separate from automatic fitting.
    if (!img.style.getPropertyValue('--vehicle-image-scale')) {
        if (Number.isFinite(requestedScale) && requestedScale > 0) {
            img.style.setProperty('--vehicle-image-scale', String(requestedScale));
        }
        if (adjustment.x !== undefined) img.style.setProperty('--vehicle-image-x', String(Number(adjustment.x) || 0));
        if (adjustment.y !== undefined) img.style.setProperty('--vehicle-image-y', String(Number(adjustment.y) || 0));
    }
    img.style.setProperty('--vehicle-image-holder-width', `${cw}px`);
    img.style.setProperty('--vehicle-image-holder-height', `${ch}px`);
    img.style.setProperty('--vehicle-image-origin', `${(bounds.left + bounds.right) * 50}% ${bounds.bottom * 100}%`);
    const displayScale = scale;
    const displayW = nw * displayScale;
    const displayH = nh * displayScale;
    const subjectCenterX = ((bounds.left + bounds.right) / 2) * displayW;
    const subjectBottomY = bounds.bottom * displayH;

    // 차량 본체의 중심과 바닥선을 고정 앵커에 맞춥니다.
    // 우측 끝으로 몰려 보이지 않도록 기존보다 중심을 약간 왼쪽에 둡니다.
    const targetCenterX = cw * ((defaults.centerX ?? 0.47) + (Number(defaults.x) || 0));
    const targetBottomY = ch * ((defaults.bottomY ?? 0.89) + (Number(defaults.y) || 0));

    img.classList.remove('vehicle-thumb-fallback');
    img.style.width = `${displayW.toFixed(2)}px`;
    img.style.height = `${displayH.toFixed(2)}px`;
    img.style.left = `${(targetCenterX - subjectCenterX).toFixed(2)}px`;
    img.style.top = `${(targetBottomY - subjectBottomY).toFixed(2)}px`;
}

function normalizeVehicleListThumbnails(scope = document) {
    const images = scope.querySelectorAll?.('.vehicle-list-image img') || [];
    images.forEach(img => {
        if (img.complete && img.naturalWidth) normalizeVehicleListImage(img);
    });
}

function getFilteredVehicles() {
    const keyword = vehicleSearchKeyword.trim().toLowerCase();
    const items = allVehicles
        .map((vehicle, index) => ({ vehicle, index }))
        .filter(({vehicle, index}) => {
            if (!matchesVehicleCategory(vehicle, index, activeListCategory)) return false;
            if (!vehicleHasProduct(vehicle, vehicleProductFilter)) return false;

            if (keyword) {
                const haystack = `${getBrandName(vehicle)} ${getVehicleName(vehicle)} ${vehicle.model_year ?? ''} ${vehicle.fuel_type ?? ''}`.toLowerCase();
                if (!haystack.includes(keyword)) return false;
            }
            return true;
        });

    items.sort((a, b) => {
        if (vehicleSortMode === 'NAME_ASC') {
            return String(getVehicleName(a.vehicle)).localeCompare(String(getVehicleName(b.vehicle)), 'ko');
        }

        const pa = getMinMonthlyPrice(a.vehicle, vehicleProductFilter) || Number.MAX_SAFE_INTEGER;
        const pb = getMinMonthlyPrice(b.vehicle, vehicleProductFilter) || Number.MAX_SAFE_INTEGER;

        if (vehicleSortMode === 'PRICE_DESC') {
            const da = pa === Number.MAX_SAFE_INTEGER ? -1 : pa;
            const db = pb === Number.MAX_SAFE_INTEGER ? -1 : pb;
            return db - da;
        }
        return pa - pb;
    });

    return items;
}

const HOME_RECOMMEND_LABELS = {
    SUV:'넉넉한 SUV',
    SEDAN:'세련된 세단',
    COMPACT:'가성비 경차',
    HYBRID:'하이브리드 차량',
    EV:'친환경 전기차',
    FIRST:'생애 첫 차'
};

function homeVehicleSearchText(vehicle) {
    return `${getBrandName(vehicle)} ${getVehicleName(vehicle)} ${vehicle.fuel_type ?? ''}`.toLowerCase().replace(/\s+/g,'');
}

function getHomeVehiclesByKeywords(keywords, source = allVehicles) {
    const used = new Set();
    const result = [];
    keywords.forEach(keyword => {
        const normalized = String(keyword).toLowerCase().replace(/\s+/g,'');
        const found = source.find((vehicle, index) => {
            const key = getVehicleId(vehicle, index);
            return !used.has(key) && homeVehicleSearchText(vehicle).includes(normalized);
        });
        if (found) {
            const index = allVehicles.indexOf(found);
            used.add(getVehicleId(found, index));
            result.push(found);
        }
    });
    return result;
}

function getHomeRecommendVehicles(type = activeHomeRecommend) {
    const priceSorted = [...allVehicles].sort((a, b) => {
        const pa = getMinMonthlyPrice(a, 'ALL') || Number.MAX_SAFE_INTEGER;
        const pb = getMinMonthlyPrice(b, 'ALL') || Number.MAX_SAFE_INTEGER;
        return pa - pb;
    });
    let result = [];

    if (type === 'SUV') {
        result = getHomeVehiclesByKeywords(['셀토스','스포티지','EV3','EV5','쏘렌토','싼타페','카니발','Model Y']);
    } else if (type === 'SEDAN') {
        result = getHomeVehiclesByKeywords(['아반떼','K3','쏘나타','K5','그랜저','K8','G70','G80','3시리즈','5시리즈'], priceSorted);
    } else if (type === 'COMPACT') {
        result = getHomeVehiclesByKeywords(['모닝','레이','캐스퍼','아반떼','K3'], priceSorted);
    } else if (type === 'HYBRID') {
        result = priceSorted.filter(vehicle => /hybrid|하이브리드/i.test(homeVehicleSearchText(vehicle)));
    } else if (type === 'EV') {
        result = priceSorted.filter(vehicle => /전기|electric|bev|\bev\b|아이오닉|ioniq|model|i[4-9]|ix/i.test(`${getVehicleName(vehicle)} ${vehicle.fuel_type ?? ''}`));
    } else if (type === 'FIRST') {
        result = priceSorted;
    }

    const used = new Set(result.map(vehicle => getVehicleId(vehicle, allVehicles.indexOf(vehicle))));
    priceSorted.forEach(vehicle => {
        if (result.length >= 8) return;
        const index = allVehicles.indexOf(vehicle);
        const key = getVehicleId(vehicle, index);
        if (!used.has(key)) {
            used.add(key);
            result.push(vehicle);
        }
    });
    return result.slice(0, 8);
}

let homePopularSliderState = {
    index: 2,
    timer: null,
    startX: 0,
    currentX: 0,
    startTranslate: 0,
    dragging: false,
    moved: false,
    pressedVehicleKey: null,
    originalCount: 0,
    cloneCount: 2
};

function getHomePopularStep() {
    const grid = document.getElementById('homePopularGrid');
    const first = grid?.querySelector('.home-popular-card');
    if (!grid || !first) return 0;
    const gap = parseFloat(getComputedStyle(grid).gap || '12') || 12;
    return first.getBoundingClientRect().width + gap;
}

function setHomePopularPosition(index, animate = true) {
    const viewport = document.querySelector('.home-popular-marquee');
    const grid = document.getElementById('homePopularGrid');
    if (!viewport || !grid) return;
    const cards = grid.querySelectorAll('.home-popular-card');
    if (!cards.length) return;

    const step = getHomePopularStep();
    homePopularSliderState.index = index;
    grid.style.transition = animate ? 'transform .42s cubic-bezier(.22,.61,.36,1)' : 'none';
    grid.style.transform = `translate3d(${-homePopularSliderState.index * step}px,0,0)`;
}

function normalizeHomePopularPosition() {
    const count = homePopularSliderState.originalCount;
    const clones = homePopularSliderState.cloneCount;
    if (!count) return;

    // 뒤에 붙인 첫 차량 복제본까지 자연스럽게 보여준 뒤,
    // 같은 화면인 원본 첫 위치로 애니메이션 없이 순간 치환한다.
    if (homePopularSliderState.index >= clones + count) {
        setHomePopularPosition(clones, false);
    } else if (homePopularSliderState.index <= 0) {
        // 반대 방향 드래그도 끊기지 않도록 앞쪽 복제본을 원본 끝 위치로 치환한다.
        setHomePopularPosition(count, false);
    }
}

function stopHomePopularAutoplay() {
    if (homePopularSliderState.timer) {
        clearInterval(homePopularSliderState.timer);
        homePopularSliderState.timer = null;
    }
}

function startHomePopularAutoplay() {
    stopHomePopularAutoplay();
    if (homePopularSliderState.originalCount < 2) return;
    homePopularSliderState.timer = setInterval(() => {
        setHomePopularPosition(homePopularSliderState.index + 1, true);
    }, 3000);
}

function setupHomePopularSlider() {
    const viewport = document.querySelector('.home-popular-marquee');
    const grid = document.getElementById('homePopularGrid');
    if (!viewport || !grid) return;

    const originals = Array.from(grid.querySelectorAll('.home-popular-card'));
    const count = originals.length;
    if (!count) return;

    homePopularSliderState.originalCount = count;
    homePopularSliderState.cloneCount = Math.min(2, count);

    // 두 칸이 항상 보이므로 양쪽에 2개씩 복제해서 무한 루프를 만든다.
    if (count > 1) {
        const cloneCount = homePopularSliderState.cloneCount;
        const prepend = originals.slice(-cloneCount).map(card => card.outerHTML).join('');
        const originalMarkup = originals.map(card => card.outerHTML).join('');
        const append = originals.slice(0, cloneCount).map(card => card.outerHTML).join('');
        grid.innerHTML = prepend + originalMarkup + append;
        homePopularSliderState.index = cloneCount;
    } else {
        homePopularSliderState.index = 0;
    }

    setHomePopularPosition(homePopularSliderState.index, false);
    startHomePopularAutoplay();

    if (viewport.dataset.sliderReady === '1') return;
    viewport.dataset.sliderReady = '1';

    const pointerDown = (event) => {
        if (event.pointerType === 'mouse' && event.button !== 0) return;

        const pressedCard = event.target.closest('.home-popular-card');

        stopHomePopularAutoplay();
        homePopularSliderState.dragging = true;
        homePopularSliderState.moved = false;
        homePopularSliderState.pressedVehicleKey = pressedCard?.dataset.vehicleKey || null;
        homePopularSliderState.startX = event.clientX;
        homePopularSliderState.currentX = event.clientX;
        homePopularSliderState.startTranslate = -homePopularSliderState.index * getHomePopularStep();
        viewport.classList.add('is-dragging');
        viewport.setPointerCapture?.(event.pointerId);
    };

    const pointerMove = (event) => {
        if (!homePopularSliderState.dragging) return;
        homePopularSliderState.currentX = event.clientX;
        const delta = event.clientX - homePopularSliderState.startX;

        if (Math.abs(delta) > 5) {
            homePopularSliderState.moved = true;
            homePopularSliderState.pressedVehicleKey = null;
        }

        grid.style.transform = `translate3d(${homePopularSliderState.startTranslate + delta}px,0,0)`;
    };

    const pointerUp = (event) => {
        if (!homePopularSliderState.dragging) return;

        const isCancelled = event.type === 'pointercancel';
        const tappedVehicleKey = !isCancelled && !homePopularSliderState.moved
            ? homePopularSliderState.pressedVehicleKey
            : null;

        homePopularSliderState.dragging = false;
        homePopularSliderState.pressedVehicleKey = null;
        viewport.classList.remove('is-dragging');
        viewport.releasePointerCapture?.(event.pointerId);

        // 드래그가 아닌 단순 탭/클릭이면 누른 카드의 차량 상세로 이동
        if (tappedVehicleKey) {
            homePopularSliderState.moved = false;
            setHomePopularPosition(homePopularSliderState.index, false);
            openVehicleDetail(tappedVehicleKey);
            return;
        }

        const delta = homePopularSliderState.currentX - homePopularSliderState.startX;
        const threshold = Math.min(70, getHomePopularStep() * .22);
        let nextIndex = homePopularSliderState.index;
        if (delta <= -threshold) nextIndex += 1;
        else if (delta >= threshold) nextIndex -= 1;

        setHomePopularPosition(nextIndex, true);
        homePopularSliderState.moved = false;
        setTimeout(startHomePopularAutoplay, 1200);
    };

    viewport.addEventListener('pointerdown', pointerDown);
    viewport.addEventListener('pointermove', pointerMove);
    viewport.addEventListener('pointerup', pointerUp);
    viewport.addEventListener('pointercancel', pointerUp);
    viewport.addEventListener('mouseenter', stopHomePopularAutoplay);
    viewport.addEventListener('mouseleave', () => {
        if (!homePopularSliderState.dragging) startHomePopularAutoplay();
    });

    // 키보드 Enter/Space 접근성용.
    // 포인터 입력은 pointerUp에서 처리하므로 중복 이동시키지 않는다.
    viewport.addEventListener('click', event => {
        if (event.detail !== 0) return;

        const card = event.target.closest('.home-popular-card');
        const vehicleKey = card?.dataset.vehicleKey;
        if (!vehicleKey) return;

        event.preventDefault();
        openVehicleDetail(vehicleKey);
    });

    grid.addEventListener('transitionend', event => {
        if (event.propertyName !== 'transform') return;
        normalizeHomePopularPosition();
    });

    window.addEventListener('resize', () => setHomePopularPosition(homePopularSliderState.index, false));
}

function renderHomePopularVehicles() {
    const grid = document.getElementById('homePopularGrid');
    const hero = document.getElementById('homeHeroImage');
    if (!grid || !allVehicles.length) return;

    const best = allVehicles.filter((vehicle, index) => isBestVehicle(vehicle, index));
    const priceSorted = [...allVehicles].sort((a,b) => (getMinMonthlyPrice(a,'ALL') || Number.MAX_SAFE_INTEGER) - (getMinMonthlyPrice(b,'ALL') || Number.MAX_SAFE_INTEGER));
    const preferred = getHomeVehiclesByKeywords(['쏘렌토','5시리즈','셀토스','스포티지','카니발','X1']);
    const popular = [...preferred, ...best, ...priceSorted.filter(vehicle => !best.includes(vehicle))]
        .filter((vehicle, index, arr) => arr.findIndex(item => getVehicleId(item, allVehicles.indexOf(item)) === getVehicleId(vehicle, allVehicles.indexOf(vehicle))) === index)
        .slice(0,6);

    grid.innerHTML = popular.map(vehicle => {
        const index = allVehicles.indexOf(vehicle);
        const vehicleKey = getVehicleId(vehicle, index);
        const minMonthly = getMinMonthlyPrice(vehicle, 'ALL');
        const isBest = isBestVehicle(vehicle, index);
        return `
            <button class="home-popular-card" type="button" data-vehicle-key="${escapeHtml(vehicleKey)}">
                ${isBest ? '<span class="home-popular-best">BEST</span>' : ''}
                <span class="home-popular-image">${listVehicleImageHtml(vehicle, vehicleKey)}</span>
                <span class="home-popular-name">${escapeHtml(getVehicleName(vehicle))}</span>
                <span class="home-popular-price">월 <strong>${minMonthly ? shortMoney(minMonthly) : '-'}</strong>원부터</span>
            </button>
        `;
    }).join('');

    setupHomePopularSlider();

    const heroVehicle = popular[0] || allVehicles[0];
    const heroIndex = allVehicles.indexOf(heroVehicle);
    hero.innerHTML = listVehicleImageHtml(heroVehicle, getVehicleId(heroVehicle, heroIndex));
}

// 홈 추천 차량: 이미지 캔버스가 아니라 실제 차량 피사체 기준으로 중앙 정렬
// - 투명 배경의 alpha 영역을 분석해 피사체 중심점을 계산합니다.
// - CSS의 width/height 110%는 그대로 유지하고 위치만 보정합니다.
const homeRecommendSubjectCache = new Map();
let homeRecommendSubjectResizeTimer = null;

function analyzeHomeRecommendSubject(img) {
    const width = img.naturalWidth || 0;
    const height = img.naturalHeight || 0;
    if (!width || !height) return null;

    const sourceKey = img.currentSrc || img.src || '';
    if (sourceKey && homeRecommendSubjectCache.has(sourceKey)) {
        return homeRecommendSubjectCache.get(sourceKey);
    }

    try {
        // 분석 비용을 낮추되 중심점 정밀도는 충분히 유지
        const maxSide = 360;
        const ratio = Math.min(1, maxSide / Math.max(width, height));
        const scanWidth = Math.max(1, Math.round(width * ratio));
        const scanHeight = Math.max(1, Math.round(height * ratio));

        const canvas = document.createElement('canvas');
        canvas.width = scanWidth;
        canvas.height = scanHeight;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return null;

        ctx.clearRect(0, 0, scanWidth, scanHeight);
        ctx.drawImage(img, 0, 0, scanWidth, scanHeight);
        const pixels = ctx.getImageData(0, 0, scanWidth, scanHeight).data;

        // 흐린 그림자/안티앨리어싱은 제외하고 실제 차량 영역에 가깝게 잡음
        const alphaThreshold = 48;
        let minX = scanWidth;
        let minY = scanHeight;
        let maxX = -1;
        let maxY = -1;

        for (let y = 0; y < scanHeight; y++) {
            for (let x = 0; x < scanWidth; x++) {
                const alpha = pixels[(y * scanWidth + x) * 4 + 3];
                if (alpha < alphaThreshold) continue;
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
            }
        }

        if (maxX < minX || maxY < minY) return null;

        const subject = {
            centerX: ((minX + maxX + 1) / 2) / scanWidth,
            centerY: ((minY + maxY + 1) / 2) / scanHeight
        };

        if (sourceKey) homeRecommendSubjectCache.set(sourceKey, subject);
        return subject;
    } catch (error) {
        // 다른 출처 이미지 등 canvas 접근이 제한되는 경우 기존 가운데 정렬 유지
        return null;
    }
}

function positionHomeRecommendSubject(img, subject) {
    const thumb = img.closest('.home-recommend-thumb');
    if (!thumb || !subject || !img.naturalWidth || !img.naturalHeight) {
        img.style.setProperty('--subject-tx', '0px');
        img.style.setProperty('--subject-ty', '0px');
        return;
    }

    const imageRect = img.getBoundingClientRect();
    const imageWidth = imageRect.width;
    const imageHeight = imageRect.height;
    if (!imageWidth || !imageHeight) return;

    // object-fit: contain으로 실제 원본 캔버스가 img 요소 내부에서 차지하는 영역 계산
    const naturalRatio = img.naturalWidth / img.naturalHeight;
    const elementRatio = imageWidth / imageHeight;
    let renderedWidth;
    let renderedHeight;
    let offsetX;
    let offsetY;

    if (naturalRatio >= elementRatio) {
        renderedWidth = imageWidth;
        renderedHeight = imageWidth / naturalRatio;
        offsetX = 0;
        offsetY = (imageHeight - renderedHeight) / 2;
    } else {
        renderedHeight = imageHeight;
        renderedWidth = imageHeight * naturalRatio;
        offsetX = (imageWidth - renderedWidth) / 2;
        offsetY = 0;
    }

    const subjectX = offsetX + subject.centerX * renderedWidth;
    const subjectY = offsetY + subject.centerY * renderedHeight;

    // img 요소 자체는 회색 박스 중앙에 있으므로, 피사체 중심과 img 중심의 차이만큼 반대로 이동
    let translateX = imageWidth / 2 - subjectX;
    let translateY = imageHeight / 2 - subjectY;

    // 잘못된 이미지가 들어와도 과도하게 튀지 않도록 보정값 제한
    const maxX = imageWidth * 0.22;
    const maxY = imageHeight * 0.22;
    translateX = Math.max(-maxX, Math.min(maxX, translateX));
    translateY = Math.max(-maxY, Math.min(maxY, translateY));

    img.style.setProperty('--subject-tx', `${translateX.toFixed(2)}px`);
    img.style.setProperty('--subject-ty', `${translateY.toFixed(2)}px`);
}

function centerHomeRecommendSubject(img) {
    if (!img || !img.complete || !img.naturalWidth || !img.naturalHeight) return;
    const subject = analyzeHomeRecommendSubject(img);
    if (!subject) {
        img.style.setProperty('--subject-tx', '0px');
        img.style.setProperty('--subject-ty', '0px');
        return;
    }
    positionHomeRecommendSubject(img, subject);
}

function centerHomeRecommendSubjects(root = document) {
    const images = root.querySelectorAll('.home-recommend-thumb img');
    images.forEach(img => {
        if (!img.dataset.subjectCenterBound) {
            img.dataset.subjectCenterBound = '1';
            img.addEventListener('load', () => {
                requestAnimationFrame(() => centerHomeRecommendSubject(img));
            });
        }

        if (img.complete && img.naturalWidth) {
            requestAnimationFrame(() => centerHomeRecommendSubject(img));
        }
    });
}

window.addEventListener('resize', () => {
    clearTimeout(homeRecommendSubjectResizeTimer);
    homeRecommendSubjectResizeTimer = setTimeout(() => {
        centerHomeRecommendSubjects(document);
    }, 80);
});

function renderHomeRecommendations() {
    const list = document.getElementById('homeRecommendList');
    const title = document.getElementById('homeRecommendTitle');
    if (!list) return;

    if (title) {
        title.textContent = HOME_RECOMMEND_LABELS[activeHomeRecommend] || HOME_RECOMMEND_LABELS.SUV;
    }

    // 홈이 먼저 열린 상태에서도 차량 데이터가 들어오는 즉시 목록이 보이도록 처리
    if (!allVehicles.length) {
        list.innerHTML = '<div class="home-recommend-loading">차량 정보를 불러오는 중입니다.</div>';
        return;
    }

    let vehicles = getHomeRecommendVehicles(activeHomeRecommend);

    // 추천 조건에 맞는 차량을 찾지 못해도 홈 목록이 비지 않도록 전체 차량에서 보충
    if (!vehicles.length) {
        vehicles = [...allVehicles]
            .sort((a, b) => {
                const pa = getMinMonthlyPrice(a, 'ALL') || Number.MAX_SAFE_INTEGER;
                const pb = getMinMonthlyPrice(b, 'ALL') || Number.MAX_SAFE_INTEGER;
                return pa - pb;
            })
            .slice(0, 8);
    }

    list.innerHTML = vehicles.map(vehicle => {
        const index = allVehicles.indexOf(vehicle);
        const vehicleKey = getVehicleId(vehicle, index);
        const minMonthly = getMinMonthlyPrice(vehicle, 'ALL');
        return `
            <button class="home-recommend-item" type="button" onclick="openVehicleDetail('${escapeHtml(vehicleKey)}')">
                <span class="home-recommend-thumb">${listVehicleImageHtml(vehicle, vehicleKey)}</span>
                <span>
                    <span class="home-recommend-name">${escapeHtml(getVehicleName(vehicle))}</span>
                    <span class="home-recommend-price">월 ${minMonthly ? shortMoney(minMonthly) : '-'}원</span>
                </span>
            </button>
        `;
    }).join('');

    centerHomeRecommendSubjects(list);
}

function renderHomeContent() {
    renderHomePopularVehicles();
    renderHomeRecommendations();
}

let homeRecommendScrollY = 0;

function openHomeRecommendModal() {
    const modal = document.getElementById('homeRecommendModal');
    const trigger = document.getElementById('homeRecommendLabel');
    if (!modal || !modal.hidden) return;
    homeRecommendScrollY = window.scrollY;
    modal.hidden = false;
    trigger?.setAttribute('aria-expanded','true');
    document.documentElement.classList.add('home-modal-open');
    window.scrollTo(0, homeRecommendScrollY);
}

function closeHomeRecommendModal() {
    const modal = document.getElementById('homeRecommendModal');
    const trigger = document.getElementById('homeRecommendLabel');
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    trigger?.setAttribute('aria-expanded','false');
    document.documentElement.classList.remove('home-modal-open');
    window.scrollTo(0, homeRecommendScrollY);
}

function selectHomeRecommend(type) {
    activeHomeRecommend = HOME_RECOMMEND_LABELS[type] ? type : 'SUV';
    document.querySelectorAll('[data-home-recommend]').forEach(button => {
        button.classList.toggle('active', button.dataset.homeRecommend === activeHomeRecommend);
    });
    renderHomeRecommendations();
    closeHomeRecommendModal();
}

function openHomeBrand(brand) {
    const keyword = String(brand || '').trim();

    activeListCategory = 'ALL';
    vehicleSearchKeyword = keyword;

    document.querySelectorAll('.vehicle-category-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.category === 'ALL');
    });

    const input = document.getElementById('vehicleSearchInput');
    if (input) input.value = keyword;

    openMainView('vehicle');
    renderVehicleList();
}

function renderVehicleList() {
    const list = document.getElementById('vehicleList');
    const status = document.getElementById('status');
    if (!list) return;

    const filtered = getFilteredVehicles();
    status.style.display = 'none';

    if (!filtered.length) {
        list.innerHTML = '<div class="vehicle-list-empty">조건에 맞는 차량이 없습니다.</div>';
        return;
    }

    list.innerHTML = filtered.map(({vehicle, index}) => {
        const vehicleKey = getVehicleId(vehicle, index);
        const minMonthly = getMinMonthlyPrice(vehicle, vehicleProductFilter);
        const productBadge = getListProductLabel(vehicle);
        return `
            <div class="vehicle-list-item"><button class="vehicle-list-card" type="button" onclick="openVehicleDetail('${escapeHtml(vehicleKey)}', true)">
                <div class="vehicle-list-copy">
                    <div class="vehicle-list-name">
                        <span>${escapeHtml(getVehicleName(vehicle))}</span>
                        <span class="vehicle-list-badge">${escapeHtml(productBadge)}</span>
                        ${isBestVehicle(vehicle, index) ? '<span class="vehicle-list-best-badge">BEST</span>' : ''}
                    </div>
                    <div class="vehicle-list-price">
                        <strong>${minMonthly ? shortMoney(minMonthly) : '-'}</strong>
                        <span>원 부터</span>
                    </div>
                </div>
                <span class="vehicle-list-arrow" aria-hidden="true"></span>
                <div class="vehicle-list-image">${listVehicleImageHtml(vehicle, vehicleKey)}</div>
            </button><button class="vehicle-compare-select" type="button" data-compare-vehicle="${escapeHtml(vehicleKey)}" aria-pressed="${agCompareIds.includes(vehicleKey)}">${agCompareIds.includes(vehicleKey)?'✓ 비교 선택됨':'+ 비교하기'}</button></div>

        `;
    }).join('');

    agRenderCompareTray();
    requestAnimationFrame(() => normalizeVehicleListThumbnails(list));
}

function renderVehicleDetail(vehicle, index = 0, initialQuote = null) {
    const app = document.getElementById('app');
    const vehicleKey = getVehicleId(vehicle, index);
    const detailFavoriteSlot = document.getElementById('detailFavoriteSlot');
    if (detailFavoriteSlot) {
        detailFavoriteSlot.innerHTML = mypageHeartButton(
            vehicleKey,
            readMypageVehicleIds(MYPAGE_FAVORITES_STORAGE).includes(String(vehicleKey)),
            'vehicle-detail-favorite'
        );
    }

    app.innerHTML = `
        <article class="vehicle-card">
            <div class="vehicle-hero">
                <h2 class="vehicle-title">${escapeHtml(getVehicleName(vehicle))}</h2>
                <button class="info-chip" type="button" onclick="openVehicleInfo('${escapeHtml(vehicleKey)}')">차량 정보</button>
                <div class="vehicle-image">${vehicleImageHtml(vehicle, vehicleKey)}</div>
            </div>
            ${renderColorSelect(vehicle.colors ?? [], vehicleKey, getImagePath(vehicle))}
            ${renderQuotePanel(vehicle, vehicleKey)}
        </article>
    `;

    const colorList = app.querySelector('.color-list');
    const firstColor = colorList?.querySelector('.color-item');
    if (firstColor) firstColor.classList.add('active');

    initQuotePanel(vehicle, vehicleKey, initialQuote);
}

function getCurrentVehicleByKey(vehicleKey) {
    return allVehicles.find((vehicle, i) => getVehicleId(vehicle, i) === String(vehicleKey)) || null;
}

function getCurrentInfoTrim(vehicleKey, vehicle) {
    const quote = quoteStates.get(String(vehicleKey));
    if (quote?.trim) return quote.trim;
    const trims = Array.isArray(vehicle?.trims) ? vehicle.trims : [];
    return trims[0] || null;
}

function getOptionField(option, keys, fallback = '') {
    for (const key of keys) {
        const value = option?.[key];
        if (value !== undefined && value !== null && String(value).trim() !== '') return value;
    }
    return fallback;
}

function getOptionName(option, index) {
    const known = getOptionField(option, ['option_name','name','title','item_name','option_value','value','label']);
    if (known) return String(known);

    const ignored = new Set(['id','trim_id','vehicle_id','sort_order','is_active','created_at','updated_at']);
    for (const [key, value] of Object.entries(option || {})) {
        if (ignored.has(key) || value === null || value === '') continue;
        if (typeof value === 'string' && value.trim()) return value;
    }
    return `옵션 ${index + 1}`;
}

function getOptionDescription(option) {
    return String(getOptionField(option, ['description','option_description','detail','contents','content','memo','note'], '') || '');
}

function getOptionImage(option) {
    const direct = String(getOptionField(option, ['image_path','image','option_image','image_url','thumbnail'], '') || '');
    if (direct) return direct;

    const name = String(getOptionField(option, ['option_name','name','title','item_name','option_value','value','label'], '') || '').trim();
    const bmwFallback = {
        'BMW 커브드 디스플레이': './images/options/BMW/BMW 커브드 디스플레이.webp',
        '드라이빙 어시스턴트 프로페셔널': './images/options/BMW/드라이빙 어시스턴트 프로페셔널.webp',
        '파킹 어시스턴트 플러스': './images/options/BMW/파킹 어시스턴트 플러스.webp',
        '하이빔 어시스턴트': './images/options/BMW/하이빔 어시스턴트.webp',
        'BMW 디지털 키': './images/options/BMW/BMW 디지털 키.webp',
        'BMW 디지털 키 플러스': './images/options/BMW/BMW 디지털 키.webp',
        '무선 충전 트레이': './images/options/BMW/무선 충전 트레이.webp'
    };
    return bmwFallback[name] || '';
}

function getOptionPrice(option) {
    const raw = getOptionField(option, ['option_price','price','additional_price','add_price','extra_price'], '');
    const value = Number(String(raw).replace(/[^0-9.-]/g, ''));
    if (!Number.isFinite(value) || value <= 0) return '';
    return `+${value.toLocaleString('ko-KR')}원`;
}

function isStandardOption(option) {
    const raw = getOptionField(option, ['is_standard','is_basic','included','is_included','standard_yn','basic_yn'], '');
    return raw === true || raw === 1 || ['1','Y','YES','TRUE','기본','기본옵션','포함'].includes(String(raw).trim().toUpperCase());
}

function fitVehicleOptionImage(img) {
    // Uploaded WebP images can include transparent borders around the actual photo.
    // Measure those borders for display while keeping the uploaded file intact.
    try {
        const frame = img.closest('.vehicle-info-option-image');
        frame.style.maxWidth = `${img.naturalWidth}px`;
        frame.style.aspectRatio = `${img.naturalWidth} / ${img.naturalHeight}`;
        const canvas = document.createElement('canvas');
        const scale = Math.min(1, 1000 / Math.max(img.naturalWidth, img.naturalHeight));
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        const { data } = ctx.getImageData(0, 0, canvas.width, canvas.height);
        let left = canvas.width, top = canvas.height, right = -1, bottom = -1;
        for (let y = 0; y < canvas.height; y++) {
            for (let x = 0; x < canvas.width; x++) {
                if (data[(y * canvas.width + x) * 4 + 3] < 16) continue;
                left = Math.min(left, x);
                right = Math.max(right, x);
                top = Math.min(top, y);
                bottom = Math.max(bottom, y);
            }
        }
        if (right < left || (left === 0 && top === 0 && right === canvas.width - 1 && bottom === canvas.height - 1)) return;
        const width = right - left + 1, height = bottom - top + 1;
        frame.style.maxWidth = `${img.naturalWidth * width / canvas.width}px`;
        frame.style.aspectRatio = `${width} / ${height}`;
        Object.assign(img.style, {
            position: 'absolute',
            width: `${canvas.width / width * 100}%`,
            height: `${canvas.height / height * 100}%`,
            maxWidth: 'none',
            left: `${-left / width * 100}%`,
            top: `${-top / height * 100}%`
        });
    } catch (_) {
        // Remote images without canvas access still use the regular cover layout.
    } finally {
        img.classList.add('is-ready');
    }
}

function renderVehicleOptionCards(options) {
    return options.map((option, index) => {
        const name = getOptionName(option, index);
        const desc = getOptionDescription(option);
        const price = getOptionPrice(option);
        const standard = isStandardOption(option);
        const image = getOptionImage(option);
        const imageNote = getOptionField(option, ['image_note'], '');
        const imageWidth = Math.floor(Number(getOptionField(option, ['image_width'], 0)));
        const imageHeight = Math.floor(Number(getOptionField(option, ['image_height'], 0)));
        const imageSize = imageWidth > 0 && imageHeight > 0 ? ` width="${imageWidth}" height="${imageHeight}"` : '';
        return `
            <div class="vehicle-info-option ${image ? 'has-image' : ''}">
                ${image ? `<div class="vehicle-info-option-image${getOptionField(option, ['image_kind'], '') === 'icon' ? ' is-icon' : ''}"><img src="${escapeHtml(image)}" alt="${escapeHtml(name)}"${imageSize} loading="lazy" decoding="async" onload="fitVehicleOptionImage(this)" onerror="this.closest('.vehicle-info-option-image').remove()"></div>` : ''}
                ${image && imageNote ? `<div class="vehicle-info-option-image-note">${escapeHtml(imageNote)}</div>` : ''}
                <div class="vehicle-info-option-body">
                    <div class="vehicle-info-option-top">
                        <div class="vehicle-info-option-name">
                            ${escapeHtml(name)}
                            ${standard ? '<span class="vehicle-info-option-badge">기본</span>' : ''}
                        </div>
                        ${price ? `<div class="vehicle-info-option-price">${escapeHtml(price)}</div>` : ''}
                    </div>
                    ${desc ? `<div class="vehicle-info-option-desc">${escapeHtml(desc)}</div>` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function renderVehicleInfo(vehicleKey) {
    const vehicle = getCurrentVehicleByKey(vehicleKey);
    const content = document.getElementById('vehicleInfoContent');
    if (!vehicle || !content) return;

    const trim = getCurrentInfoTrim(vehicleKey, vehicle);
    const dbOptions = Array.isArray(trim?.options) ? trim.options.filter(option => {
        const active = option?.is_active;
        return active === undefined || active === null || Number(active) !== 0;
    }) : [];
    const usingTemporaryOptions = dbOptions.length === 0;
    const options = dbOptions;
    const modelOptions = Array.isArray(trim?.model_options) ? trim.model_options.filter(option => Number(option?.is_active ?? 1) !== 0) : [];
    const usingOfficialSiteSummary = options.some(option => Number(option?.is_official_site_summary || 0) === 1);

    const trimName = trim?.name || '트림 미선택';
    // Old seeded trim summaries can contain placeholder specifications.
    const trimDescription = String(trim?.description || '').split(/\r?\n/)
        .filter(line => !/^\s*주요\s*옵션\s*\(\s*임시\s*\)\s*[:：]/.test(line))
        .join('\n').trim();

    content.innerHTML = `
        <div class="vehicle-info-hero">
            <h2 class="vehicle-info-vehicle-name">${escapeHtml(getVehicleName(vehicle))}</h2>
            <div class="vehicle-info-trim">${escapeHtml(trimName)}</div>
            <div class="vehicle-info-image">${vehicle.info_content?.hero_image ? `<img src="${escapeHtml(vehicle.info_content.hero_image)}" alt="${escapeHtml(getVehicleName(vehicle))}">` : vehicleImageHtml(vehicle, `info-${vehicleKey}`)}</div>
        </div>

        <section class="vehicle-info-card">
            <h3 class="vehicle-info-section-title">${vehicle.info_content ? escapeHtml(vehicle.info_content.title) : (usingOfficialSiteSummary ? '주요 사양 및 옵션' : escapeHtml(trimName))}</h3>
            <p class="vehicle-info-section-desc">
                ${vehicle.info_content ? escapeHtml(vehicle.info_content.fitment_note || '') : usingOfficialSiteSummary
                    ? '차종별 주요 사양입니다. 실제 적용 여부와 기본·선택 구분은 연식·트림·패키지에 따라 달라집니다.'
                    : (usingTemporaryOptions
                        ? '상세 사양은 담당자 상담으로 확인해 주세요.'
                        : '선택한 트림에 포함되거나 등록된 옵션입니다.')}
            </p>
            ${trimDescription ? `<div class="vehicle-info-trim-description">${escapeHtml(trimDescription)}</div>` : ''}

            ${options.length ? `
                <div class="vehicle-info-option-list">
                    ${renderVehicleOptionCards(options)}
                </div>
            ` : `
                <div class="vehicle-info-empty">
                    이 트림에 등록된 옵션 정보가 없습니다.<br>
                    자세한 사양은 담당자 상담으로 안내해드립니다.
                </div>
            `}
        </section>
        ${modelOptions.length ? `
            <section class="vehicle-info-card">
                <h3 class="vehicle-info-section-title">차량 주요 사양</h3>
                <p class="vehicle-info-section-desc">차종별 주요 사양입니다. 실제 적용 여부와 기본·선택 구분은 연식·트림·패키지에 따라 달라집니다.</p>
                <div class="vehicle-info-option-list">${renderVehicleOptionCards(modelOptions)}</div>
            </section>
        ` : ''}
    `;
}

function openVehicleInfo(vehicleKey) {
    setBottomNavVisible(true);
    renderVehicleInfo(vehicleKey);
    document.body.classList.add('detail-mode');
    document.getElementById('vehicleDetailView').hidden = true;
    document.getElementById('vehicleInfoView').hidden = false;
    resetPageScroll();
}

function closeVehicleInfo() {
    document.getElementById('vehicleInfoView').hidden = true;
    document.getElementById('vehicleDetailView').hidden = false;
    resetPageScroll();
}

function openVehicleDetail(vehicleKey, useListPrice = false) {
    const trackedVehicle = allVehicles.find((v, i) => getVehicleId(v, i) === String(vehicleKey));
    trackEstimateProgress('VEHICLE_DETAIL', trackedVehicle ? {vehicle_id:Number((trackedVehicle.vehicle_id ?? trackedVehicle.id) || 0), vehicle_name:getVehicleName(trackedVehicle)} : {});
    const homeView = document.getElementById('homeView');
    vehicleDetailReturnView = homeView && !homeView.hidden ? 'home' : (document.getElementById('myVehiclesView') && !document.getElementById('myVehiclesView').hidden ? 'mypage-vehicles' : 'vehicle');
    setBottomNavVisible(true);
    const index = allVehicles.findIndex((vehicle, i) => getVehicleId(vehicle, i) === String(vehicleKey));
    if (index < 0) return;
    rememberMypageRecentVehicle(String(vehicleKey));

    const initialQuote = getMinMonthlyQuote(allVehicles[index], useListPrice ? vehicleProductFilter : 'ALL');
    renderVehicleDetail(allVehicles[index], index, initialQuote);

    // 홈 전용 배경/하단 여백 규칙이 차량 상세에 남지 않도록 먼저 해제
    document.body.classList.remove('home-main-active');
    document.body.classList.add('detail-mode');
    ['homeView','vehicleListView','quickEstimateView','faqView','mypageView','myInquiriesView','myEstimatesView','myVehiclesView','vehicleInfoView','savedQuotesView'].forEach(id => {
        const view = document.getElementById(id);
        if (view) view.hidden = true;
    });
    document.getElementById('vehicleDetailView').hidden = false;
    resetPageScroll();

    // URL changes are managed by the shared screen history controller.
}

function closeVehicleDetail() {
    document.body.classList.remove('detail-mode');
    document.getElementById('vehicleDetailView').hidden = true;
    document.getElementById('vehicleInfoView').hidden = true;
    document.getElementById('app').innerHTML = '';
    quoteStates.clear();
    // URL changes are managed by the shared screen history controller.
    if (vehicleDetailReturnView === 'mypage-vehicles') openMypageVehiclePage(mypageVehiclePanelMode || 'recent');
    else openMainView(vehicleDetailReturnView);
}

function bindVehicleListControls() {
    document.getElementById('vehicleSearchInput')?.addEventListener('input', event => {
        vehicleSearchKeyword = event.target.value || '';
        renderVehicleList();
        renderHomeContent();
    });

    document.getElementById('vehicleCategoryTabs')?.addEventListener('click', event => {
        const button = event.target.closest('.vehicle-category-tab');
        if (!button) return;

        activeListCategory = button.dataset.category || 'ALL';
        document.querySelectorAll('.vehicle-category-tab').forEach(tab => tab.classList.toggle('active', tab === button));
        renderVehicleList();
    });

    const sortButton = document.getElementById('sortButton');
    const filterButton = document.getElementById('filterButton');
    const sortMenu = document.getElementById('sortMenu');
    const filterMenu = document.getElementById('filterMenu');

    sortButton?.addEventListener('click', event => {
        event.stopPropagation();
        const willOpen = !sortMenu.classList.contains('open');
        sortMenu.classList.toggle('open', willOpen);
        filterMenu?.classList.remove('open');
        sortButton.setAttribute('aria-expanded', String(willOpen));
        filterButton?.setAttribute('aria-expanded', 'false');
    });

    filterButton?.addEventListener('click', event => {
        event.stopPropagation();
        const willOpen = !filterMenu.classList.contains('open');
        filterMenu.classList.toggle('open', willOpen);
        sortMenu?.classList.remove('open');
        filterButton.setAttribute('aria-expanded', String(willOpen));
        sortButton?.setAttribute('aria-expanded', 'false');
    });

    sortMenu?.addEventListener('click', event => {
        const option = event.target.closest('[data-sort]');
        if (!option) return;
        vehicleSortMode = option.dataset.sort || 'PRICE_ASC';
        sortMenu.querySelectorAll('[data-sort]').forEach(btn => btn.classList.toggle('active', btn === option));
        sortButton.textContent = option.textContent.trim();
        sortMenu.classList.remove('open');
        sortButton.setAttribute('aria-expanded', 'false');
        renderVehicleList();
    });

    filterMenu?.addEventListener('click', event => {
        const option = event.target.closest('[data-product]');
        if (!option) return;
        vehicleProductFilter = option.dataset.product || 'ALL';
        filterMenu.querySelectorAll('[data-product]').forEach(btn => btn.classList.toggle('active', btn === option));
        filterButton.textContent = option.textContent.trim();
        filterMenu.classList.remove('open');
        filterButton.setAttribute('aria-expanded', 'false');
        renderVehicleList();
    });

    document.addEventListener('click', event => {
        if (!event.target.closest('.vehicle-list-tools')) {
            sortMenu?.classList.remove('open');
            filterMenu?.classList.remove('open');
            sortButton?.setAttribute('aria-expanded', 'false');
            filterButton?.setAttribute('aria-expanded', 'false');
        }
    });

    document.getElementById('detailBackButton')?.addEventListener('click', closeVehicleDetail);
    document.getElementById('vehicleInfoBackButton')?.addEventListener('click', closeVehicleInfo);
}

async function loadData() {
    const status = document.getElementById('status');
    try {
        const response = await agFetch(API_URL, { cache:'no-store' });
        if (!response.ok) throw new Error(`API 오류: HTTP ${response.status}`);
        const raw = await response.json();
        allVehicles = normalizeData(raw);

        if (!allVehicles.length) {
            status.className = 'status error';
            status.textContent = '표시할 차량 데이터가 없습니다.';
            return;
        }

        renderVehicleList();
        renderHomeContent();
        updateMypageVehicleCounts();
        if (!document.getElementById('myVehiclesView')?.hidden) renderMypageVehiclePage();

        const params = new URLSearchParams(location.hash.replace(/^#/, ''));
        const vehicleKey = params.get('vehicle');
        if (vehicleKey) openVehicleDetail(vehicleKey);
    } catch (error) {
        status.className = 'status error';
        status.style.display = '';
        status.innerHTML = `<strong>차량 정보를 불러오지 못했습니다.</strong><br>잠시 후 다시 시도해 주세요.<br><button class="vehicle-data-retry" type="button" onclick="loadData()">다시 시도</button>`;
    }
}

function showRentLeaseComparison() {
    const comparison = document.getElementById('rentLeaseComparison');
    if (!comparison) return;
    comparison.open = true;
    comparison.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

document.body.classList.remove('detail-mode');
bindBottomNavigation();
bindVehicleListControls();
bindHomeQnaControls();
loadData();
openMainView('home', false);

// ============================================================
// 하단 3메뉴 화면 전환
// - 차량 목록 데이터/렌더링은 기존 코드를 그대로 사용
// ============================================================
function setBottomNavVisible(visible){
    const nav = document.getElementById('bottomNav');
    if (nav) nav.hidden = !visible;
}

function setBottomNavActive(name){
    document.querySelectorAll('[data-main-nav]').forEach(button => {
        button.classList.toggle('active', button.dataset.mainNav === name);
    });
}

function resetPageScroll(){
    const page = document.querySelector('.page');
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
    if (page) page.scrollTop = 0;
    window.scrollTo(0, 0);

    // hidden 화면을 교체한 직후 브라우저가 이전 스크롤 위치를 복원하는 경우까지 방지
    requestAnimationFrame(() => {
        document.documentElement.scrollTop = 0;
        document.body.scrollTop = 0;
        if (page) page.scrollTop = 0;
        window.scrollTo(0, 0);
    });
}

function openMainView(name, updateUrl = true){
    if (updateUrl) {
        const params = new URLSearchParams(location.hash.replace(/^#/, ''));
        if (params.has('vehicle')) {
            params.delete('vehicle');
            const remainingHash = params.toString();
            history.replaceState(null, '', location.pathname + location.search + (remainingHash ? `#${remainingHash}` : ''));
        }
    }
    document.body.classList.toggle('home-main-active', name === 'home');
    const homeView = document.getElementById('homeView');
    const listView = document.getElementById('vehicleListView');
    const detailView = document.getElementById('vehicleDetailView');
    const infoView = document.getElementById('vehicleInfoView');
    const quickView = document.getElementById('quickEstimateView');
    const faqView = document.getElementById('faqView');
    const qnaInquiryView = document.getElementById('qnaInquiryView');
    const mypageView = document.getElementById('mypageView');

    if (name !== 'faq') {
        faqView?.classList.remove('mypage-qna-mode');
        const qnaDesc = document.getElementById('mypageQnaDesc');
        if (qnaDesc) qnaDesc.hidden = true;
    }
    const myEstimatesView = document.getElementById('myEstimatesView');
    const myInquiriesView = document.getElementById('myInquiriesView');
    const myVehiclesView = document.getElementById('myVehiclesView');

    if (homeView) homeView.hidden = true;
    if (detailView) detailView.hidden = true;
    if (infoView) infoView.hidden = true;
    if (listView) listView.hidden = true;
    if (quickView) quickView.hidden = true;
    if (faqView) faqView.hidden = true;
    if (qnaInquiryView) qnaInquiryView.hidden = true;
    if (mypageView) mypageView.hidden = true;
    if (myEstimatesView) myEstimatesView.hidden = true;
    if (myInquiriesView) myInquiriesView.hidden = true;
    if (myVehiclesView) myVehiclesView.hidden = true;
    const savedQuotesView = document.getElementById('savedQuotesView');
    if (savedQuotesView) savedQuotesView.hidden = true;
    document.body.classList.remove('detail-mode');

    if (name === 'home') {
        homeView.hidden = false;
        // 데이터 로딩 전/후와 관계없이 홈 진입 시 추천 차량 목록을 다시 그린다.
        renderHomeContent();
    } else if (name === 'quick') {
        quickView.hidden = false;
        fillEstimateMemberFields('quick');
        trackEstimateProgress('ESTIMATE_FORM');
    } else if (name === 'faq') {
        faqView.hidden = false;
    } else if (name === 'mypage') {
        mypageView.hidden = false;
        if (currentMember) loadMyEstimates(false);
        updateInquiryCount();
        updateMypageVehicleCounts();
        if (!document.getElementById('myVehiclesView')?.hidden) renderMypageVehiclePage();
    } else {
        listView.hidden = false;
        name = 'vehicle';
        trackEstimateProgress('VEHICLE_LIST');
        // 기존에 받아둔 차량 데이터로 목록을 다시 렌더링
        if (allVehicles.length) renderVehicleList();
    }

    setBottomNavActive(name);
    setBottomNavVisible(true);
    resetPageScroll();
}

function openMypageQna(){
    openMainView('faq');
    const faqView = document.getElementById('faqView');
    const qnaDesc = document.getElementById('mypageQnaDesc');
    faqView?.classList.add('mypage-qna-mode');
    if (qnaDesc) qnaDesc.hidden = false;
    setBottomNavActive('mypage');
    setBottomNavVisible(true);
    resetPageScroll();
}

function closeMypageQna(){
    const faqView = document.getElementById('faqView');
    const qnaDesc = document.getElementById('mypageQnaDesc');
    faqView?.classList.remove('mypage-qna-mode');
    if (qnaDesc) qnaDesc.hidden = true;
    openMainView('mypage');
}

// 문의 작성 화면의 진입 경로에 따라 뒤로가기 목적지를 유지합니다.
let qnaInquiryReturnView = 'faq';
function openQnaInquiryPage(origin = 'faq'){
    qnaInquiryReturnView = origin === 'mypage' ? 'mypage' : 'faq';
    const faqView = document.getElementById('faqView');
    const inquiryView = document.getElementById('qnaInquiryView');
    // 다른 화면에서 진입한 경우에도 화면이 둘 이상 겹치지 않도록 처리.
    document.querySelectorAll('.page > .view-section').forEach(view => { view.hidden = true; });
    if (faqView) faqView.hidden = true;
    if (inquiryView) inquiryView.hidden = false;
    document.body.classList.remove('detail-mode');
    setBottomNavActive('faq');
    setBottomNavVisible(true);
    resetPageScroll();
}

function closeQnaInquiryPage(){
    if (qnaInquiryReturnView === 'mypage') {
        qnaInquiryReturnView = 'faq';
        openMainView('mypage');
        return;
    }
    const faqView = document.getElementById('faqView');
    const inquiryView = document.getElementById('qnaInquiryView');
    if (inquiryView) inquiryView.hidden = true;
    if (faqView) faqView.hidden = false;
    setBottomNavActive('faq');
    setBottomNavVisible(true);
    resetPageScroll();
}

function bindBottomNavigation(){
    document.querySelectorAll('[data-main-nav]').forEach(button => {
        button.addEventListener('click', () => openMainView(button.dataset.mainNav));
    });

    document.getElementById('agQnaContactButton')?.addEventListener('click', () => {
        openQnaInquiryPage();
        setTimeout(() => document.getElementById('qnaInquiryInput')?.focus({ preventScroll: true }), 220);
    });

    // Existing FAQ text and accordion behavior are retained; filters only hide nonmatching rows.
    const faqSearch = document.getElementById('agFaqSearch');
    const faqCategories = Array.from(document.querySelectorAll('#faqView [data-faq-filter]'));
    let selectedFaqCategory = '전체';
    let showAllFaqItems = false;
    let faqFilterInitialized = false;
    const faqShowAll = document.getElementById('agQnaShowAll');
    const faqShowAllLabel = document.getElementById('agQnaShowAllLabel');
    const faqItemAnimations = new WeakMap();
    const setFaqItemVisibility = (item, visible, delay = 0) => {
        const currentAnimation = faqItemAnimations.get(item);
        if (currentAnimation?.visible === visible) return;
        if (!currentAnimation && item.hidden === !visible) return;

        const currentStyle = currentAnimation ? getComputedStyle(item) : null;
        const startHeight = currentAnimation ? item.getBoundingClientRect().height : (visible ? 0 : item.getBoundingClientRect().height);
        const startOpacity = currentStyle ? Number(currentStyle.opacity) : (visible ? 0 : 1);
        const startTransform = currentStyle ? currentStyle.transform : (visible ? 'translateY(-8px)' : 'translateY(0px)');
        currentAnimation?.animation.cancel();

        if (!faqFilterInitialized || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            item.hidden = !visible;
            faqItemAnimations.delete(item);
            return;
        }

        if (visible) item.hidden = false;
        const endHeight = visible ? item.getBoundingClientRect().height : 0;
        const animation = item.animate([
            { height: `${startHeight}px`, opacity: startOpacity, transform: startTransform },
            { height: `${endHeight}px`, opacity: visible ? 1 : 0, transform: visible ? 'translateY(0px)' : 'translateY(-8px)' }
        ], {
            duration: 260,
            delay,
            easing: 'cubic-bezier(0.22, 0.7, 0.25, 1)',
            fill: 'both'
        });
        faqItemAnimations.set(item, { animation, visible });
        animation.onfinish = () => {
            if (faqItemAnimations.get(item)?.animation !== animation) return;
            if (!visible) item.hidden = true;
            animation.cancel();
            faqItemAnimations.delete(item);
        };
    };
    const filterFaqItems = () => {
        const query = String(faqSearch?.value || '').trim().toLocaleLowerCase('ko-KR');
        let visibleCount = 0;
        let matchingCount = 0;
        const limitResults = selectedFaqCategory === '전체' && !showAllFaqItems;
        document.querySelectorAll('#faqList .faq-item').forEach(item => {
            const category = String(item.querySelector('.faq-category')?.textContent || '').trim();
            const matchesCategory = selectedFaqCategory === '전체' ||
                (selectedFaqCategory === '계약·이용' ? ['계약', '이용'].includes(category) :
                 selectedFaqCategory === '차량' ? ['차량', '이용'].includes(category) : category === selectedFaqCategory);
            const matchesQuery = !query || String(item.textContent || '').toLocaleLowerCase('ko-KR').includes(query);
            const matches = matchesCategory && matchesQuery;
            if (matches) matchingCount += 1;
            const visible = matches && (!limitResults || matchingCount <= 5);
            setFaqItemVisibility(item, visible, matchingCount > 5 ? Math.min((matchingCount - 5) * 24, 96) : 0);
            if (!visible) {
                item.classList.remove('open');
                item.querySelector('.faq-question')?.setAttribute('aria-expanded', 'false');
            }
            if (visible) visibleCount += 1;
        });
        faqFilterInitialized = true;
        const empty = document.getElementById('agFaqEmpty');
        if (empty) empty.hidden = visibleCount > 0;
        if (faqShowAll) {
            faqShowAll.hidden = selectedFaqCategory !== '전체' || matchingCount <= 5;
            faqShowAll.setAttribute('aria-expanded', String(showAllFaqItems));
            if (faqShowAllLabel) faqShowAllLabel.textContent = showAllFaqItems ? '접기' : '전체보기';
        }
    };
    faqShowAll?.addEventListener('click', () => {
        selectedFaqCategory = '전체';
        showAllFaqItems = !showAllFaqItems;
        faqCategories.forEach(tab => {
            const active = tab.dataset.faqFilter === '전체';
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-pressed', String(active));
        });
        filterFaqItems();
    });
    faqSearch?.addEventListener('input', () => {
        showAllFaqItems = false;
        filterFaqItems();
    });
    faqCategories.forEach(button => button.addEventListener('click', () => {
        selectedFaqCategory = button.dataset.faqFilter || '전체';
        showAllFaqItems = false;
        faqCategories.forEach(tab => {
            const active = tab === button;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-pressed', String(active));
        });
        filterFaqItems();
    }));

    filterFaqItems();

    document.getElementById('faqList')?.addEventListener('click', event => {
        const question = event.target.closest('.faq-question');
        if (!question) return;
        const item = question.closest('.faq-item');
        const willOpen = !item.classList.contains('open');
        document.querySelectorAll('.faq-item.open').forEach(openItem => openItem.classList.remove('open'));
        item.classList.toggle('open', willOpen);
        document.querySelectorAll('#faqList .faq-question').forEach(btn => {
            btn.setAttribute('aria-expanded', String(btn.closest('.faq-item')?.classList.contains('open')));
        });
    });
}

function bindHomeQnaControls(){
    const recommendTrigger = document.getElementById('homeRecommendLabel');
    const recommendModal = document.getElementById('homeRecommendModal');
    recommendTrigger?.addEventListener('click', openHomeRecommendModal);
    recommendModal?.addEventListener('click', event => {
        if (event.target === recommendModal) closeHomeRecommendModal();
        const option = event.target.closest('[data-home-recommend]');
        if (option) selectHomeRecommend(option.dataset.homeRecommend);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && recommendModal && !recommendModal.hidden) closeHomeRecommendModal();
    });

    const input = document.getElementById('qnaQuestionInput');
    const count = document.getElementById('qnaQuestionCount');
    const submit = document.getElementById('qnaSubmitButton');
    const result = document.getElementById('qnaComposeResult');
    const inquiryInput = document.getElementById('qnaInquiryInput');
    const inquiryCount = document.getElementById('qnaInquiryCount');
    const inquirySubmit = document.getElementById('qnaInquirySubmit');
    const inquiryResult = document.getElementById('qnaInquiryResult');
    const inquiryCategory = document.getElementById('qnaInquiryCategory');
    let inquirySending = false;
    const syncQuestionState = () => {
        const length = String(input?.value || '').length;
        if (count) count.textContent = String(length);
        if (submit) submit.disabled = inquirySending || length < 5;
        if (result) result.classList.remove('show');
    };
    const syncInquiryState = () => {
        const length = String(inquiryInput?.value || '').length;
        if (inquiryCount) inquiryCount.textContent = String(length);
        if (inquirySubmit) inquirySubmit.disabled = inquirySending || length < 5;
        if (inquiryResult) inquiryResult.classList.remove('show');
    };
    input?.addEventListener('input', syncQuestionState);
    inquiryInput?.addEventListener('input', syncInquiryState);
    const sendQuestion = async (field, button, notice, category = '') => {
        const message=String(field?.value || '').trim();
        if(message.length<5 || inquirySending || button.disabled)return;
        inquirySending=true;
        if(submit)submit.disabled=true;
        if(inquirySubmit)inquirySubmit.disabled=true;
        if(notice){notice.textContent='문의 접수 중입니다.';notice.classList.add('show');notice.setAttribute('role','status');}
        try {
            await saveInquiry(message,category);
            if (String(field.value || '').trim() === message) {
                field.value='';
                if(inquiryCategory && field===inquiryInput && inquiryCategory.value===category)inquiryCategory.value='';
            }
            syncQuestionState();syncInquiryState();
            if(notice){notice.textContent='문의가 접수되었습니다. 마이페이지 > 문의 내역에서 확인할 수 있습니다.';notice.classList.add('show');}
        }catch(error){
            if(notice){notice.textContent=error.message || '문의 접수에 실패했습니다. 작성 내용을 유지했습니다. 다시 시도해 주세요.';notice.classList.add('show');notice.setAttribute('role','alert');}
        }finally{
            inquirySending=false;
            if(submit)submit.disabled=String(input?.value||'').trim().length<5;
            if(inquirySubmit)inquirySubmit.disabled=String(inquiryInput?.value||'').trim().length<5;
        }
    };
    submit?.addEventListener('click',()=>sendQuestion(input,submit,result));
    inquirySubmit?.addEventListener('click',()=>sendQuestion(inquiryInput,inquirySubmit,inquiryResult,String(inquiryCategory?.value||'')));

}

// ============================================================
// 마이페이지 - 로그인 회원의 내 견적
// ============================================================
function escapeEstimateText(value) {
    return String(value ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function productTypeLabel(value) {
    if (value === 'RENT') return '장기렌트';
    if (value === 'LEASE') return '리스';
    return '';
}
function isInquiryCompleted(item) {
    const status = String(item.status || '').toUpperCase();
    return status ? status === 'ANSWERED' : Boolean(String(item.answer || '').trim());
}

function isNewInquiry(item) {
    return !isInquiryCompleted(item);
}

function updateMyEstimateCounts(counts = {}) {
    const estimate = Number(counts.estimate || 0);
    const contacted = Number(counts.contacted || 0);
    const reviewing = Number(counts.reviewing || 0);
    const approved = Number(counts.approved || 0);
    const contracted = Number(counts.contracted || 0);
    const total = Number(counts.total || (estimate + contacted + reviewing + approved + contracted));

    const map = {
        mypageEstimateTotalCount: estimate,
        mypageContactedCount: contacted,
        mypageReviewingCount: reviewing,
        mypageApprovedCount: approved,
        mypageContractCount: contracted
    };

    Object.entries(map).forEach(([id, value]) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = String(value);
        el.classList.toggle('has-count', Number(value) > 0);
    });

    const menu = document.getElementById('mypageEstimateMenuCount');
    if (menu) menu.textContent = `${total}개`;
    const badge = document.getElementById('mypageEstimateNew');
    if (badge) badge.hidden = estimate <= 0;
}
let myEstimateRecordCache = [];
let myEstimateRecordFilter = 'ALL';
let myEstimateStatusFilter = 'ALL';

function estimateViewTitle(filter) {
    return {ALL:'내 견적', ACTIVE:'상담중', NEW:'신규 견적', CONTACTED:'상담중', REVIEWING:'심사중', APPROVED:'승인', CONTRACTED:'계약완료', CANCELED:'취소'}[filter] || '내 견적';
}

function estimateRecordFilterMatch(item, filter) {
    const status = item.status === 'DONE' ? 'APPROVED' : item.status;
    if (filter === 'ALL') return true;
    if (filter === 'CONTRACTED') return status === 'CONTRACTED';
    if (filter === 'ACTIVE') return ['CONTACTED','REVIEWING','APPROVED'].includes(status);
    return true;
}

function updateEstimateRecordTabs(estimates = []) {
    const all = estimates.length;
    const active = estimates.filter(item => estimateRecordFilterMatch(item, 'ACTIVE') &&
        (myEstimateRecordFilter !== 'ACTIVE' || myEstimateStatusFilter === 'ALL' ||
            (item.status === 'DONE' ? 'APPROVED' : item.status) === myEstimateStatusFilter)).length;
    const contracted = estimates.filter(item => estimateRecordFilterMatch(item, 'CONTRACTED')).length;
    const values = {estimateTabAll:all, estimateTabActive:active, estimateTabContracted:contracted};
    Object.entries(values).forEach(([id,value]) => { const el=document.getElementById(id); if(el) el.textContent=String(value); });
}

function estimateVehicleImageUrl(item) {
    if (item.type !== 'DIRECT') return '';
    const raw = String(item.image_path || '').trim().replaceAll('\\', '/');
    // Only allow the site's vehicle image directory; no arbitrary remote URLs.
    if (/^(?:\.\/)?images\/cars\//i.test(raw)) return raw.replace(/^\.\//, './');
    if (/^\/autogenie-car\/images\/cars\//i.test(raw)) return raw;
    return '';
}

function mypageEmptyCard(type, title, description, action = '', label = '') {
    const icons = {
        estimate:'<path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm7 0v5h4M9 12h6M9 16h6"/>',
        inquiry:'<path d="M4 5h16v12H9l-5 4V5Zm4 4h11M8 13h6"/>',
        favorites:'<path d="M20.6 5.2a5 5 0 0 0-7.1 0L12 6.6l-1.5-1.4a5 5 0 1 0-7.1 7.1L12 21l8.6-8.7a5 5 0 0 0 0-7.1Z"/>',
        recent:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'
    };
    return `<div class="mypage-record-empty"><span class="mypage-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">${icons[type] || icons.estimate}</svg></span><strong>${escapeHtml(title)}</strong><p>${escapeHtml(description)}</p>${action ? `<button type="button" data-mypage-browse="${escapeHtml(action)}">${escapeHtml(label)} <span aria-hidden="true">→</span></button>` : ''}</div>`;
}
function updateMypageOverview(id, count, unit) {
    const target = document.getElementById(id);
    if (target) target.innerHTML = `${Number(count) || 0}<small>${unit}</small>`;
}
function renderMyEstimates(estimates = [], emptyLabel = '해당 상태') {
    const content = document.getElementById('myEstimatesPageContent');
    if (!content) return;
    myEstimateRecordCache = estimates.slice();
    updateEstimateRecordTabs(myEstimateRecordCache);

    const filtered = myEstimateRecordCache.filter(item =>
        estimateRecordFilterMatch(item, myEstimateRecordFilter) &&
        (myEstimateStatusFilter === 'ALL' || (item.status === 'DONE' ? 'APPROVED' : item.status) === myEstimateStatusFilter)
    );
    if (!filtered.length) {
        content.innerHTML = mypageEmptyCard('estimate', `${emptyLabel} 견적이 없습니다`, '차량을 살펴보고 조건에 맞는 견적을 신청해 보세요.', 'vehicle', '차량 둘러보기');
        return;
    }
    content.innerHTML = `<div class="my-estimates-list">${filtered.map((item,index) => {
        const typeClass = item.type === 'QUICK' ? 'quick' : 'direct';
        const statusClass = item.status === 'CANCELED' ? ' canceled' : '';
        const product = productTypeLabel(item.product_type) || '상담 후 결정';
        const month = item.contract_months ? `${item.contract_months}개월` : '-';
        const monthly = item.monthly_payment ? `${Number(item.monthly_payment).toLocaleString('ko-KR')}원` : '-';
        const dateText = item.created_at ? String(item.created_at).slice(0,16).replace('T',' ') : '';
        const imageUrl = estimateVehicleImageUrl(item);
        const imageMarkup = imageUrl
            ? `<span class="estimate-summary-vehicle-image"><img src="${escapeEstimateText(imageUrl)}" alt="${escapeEstimateText(item.title)}" loading="lazy" onerror="this.closest('.estimate-summary-vehicle-image').classList.add('image-failed');this.remove()"></span>`
            : `<span class="estimate-summary-image-missing" aria-hidden="true">${item.type === 'QUICK' ? '차종<br>상담' : '이미지<br>없음'}</span>`;
        return `<article class="my-estimate-item">
            <details class="account-estimate-accordion">
                <summary class="account-estimate-summary">
                    ${imageMarkup}
                    <span class="account-estimate-summary-main">
                        <span class="account-estimate-title">${escapeEstimateText(item.title)}</span>
                        <span class="estimate-summary-meta">
                            <span>${escapeEstimateText(item.type === 'DIRECT' ? '직접신청' : '간편신청')}</span>
                            <i aria-hidden="true">·</i>
                            <span class="${statusClass ? 'estimate-state-muted' : ''}">${escapeEstimateText(item.status_label)}</span>
                        </span>
                        <time class="estimate-summary-date">${escapeEstimateText(dateText)}</time>
                    </span>
                    <span class="account-estimate-summary-right">
                        <span class="account-record-chevron" aria-hidden="true"></span>
                    </span>
                </summary>
                <div class="account-estimate-expanded">
                    <div class="estimate-detail-title">견적 상세 내역</div>
                    <dl class="estimate-detail-grid">
                        <div><dt>견적번호</dt><dd>${escapeEstimateText(item.estimate_no)}</dd></div>
                        <div><dt>차량</dt><dd>${escapeEstimateText(item.title)}</dd></div>
                        ${item.subtitle ? `<div><dt>세부 모델</dt><dd>${escapeEstimateText(item.subtitle)}</dd></div>` : ''}
                        <div><dt>이용 방식</dt><dd>${escapeEstimateText(product)}</dd></div>
                        <div><dt>계약 기간</dt><dd>${escapeEstimateText(month)}</dd></div>
                        ${item.type==='DIRECT'?`<div><dt>외장색상</dt><dd>${escapeEstimateText(item.color_name||'-')}</dd></div><div><dt>선납률</dt><dd>${escapeEstimateText(String(item.prepayment_rate??0))}%</dd></div><div><dt>연 주행거리</dt><dd>${Number(item.annual_mileage)===0?'무제한':Number(item.annual_mileage).toLocaleString('ko-KR')+'km'}</dd></div>`:''}
                        ${item.registration_region?`<div><dt>등록 지역</dt><dd>${escapeEstimateText(item.registration_region)}</dd></div>`:''}
                        ${item.consultant_name?`<div><dt>담당자</dt><dd>${escapeEstimateText(item.consultant_name)}</dd></div>`:''}
                        ${item.contracted_at?`<div><dt>계약일</dt><dd>${escapeEstimateText(item.contracted_at)}</dd></div>`:''}
                        <div class="price-row"><dt>월 납입금</dt><dd>${escapeEstimateText(monthly)}</dd></div>
                        <div><dt>견적 상태</dt><dd>${escapeEstimateText(item.status_label)}</dd></div>
                        <div><dt>신청일</dt><dd>${escapeEstimateText(dateText)}</dd></div>
                    </dl>
                    <div class="estimate-consultation-actions"><a href="./consultation.php?type=${item.type==='QUICK'?'QUICK':'DIRECT'}&amp;id=${Number(item.id)||0}">${item.consultant_name?'담당자 확인 · 카카오톡 상담':'신청 내역 · 담당자 확인'}</a>${/^01[016789]\d{7,8}$/.test(String(item.consultation_phone||'').replace(/\D/g,''))?`<a href="tel:${String(item.consultation_phone).replace(/\D/g,'')}">담당자에게 전화하기</a>`:''}</div>
                </div>
            </details>
        </article>`;
    }).join('')}</div>`;
}
async function loadMyEstimates(showError = false, statusFilter = 'ALL', titleLabel = '내 견적') {
    if (!currentMember) {
        updateMyEstimateCounts();
        if (showError) openMemberModal('login');
        return [];
    }

    try {
        const response = await agFetch('./api/member-estimates.php', {
            credentials:'same-origin',
            cache:'no-store'
        });
        const data = await response.json();

        if (!response.ok || !data.ok) {
            if (showError) {
                const content = document.getElementById('myEstimatesPageContent');
                if (content) {
                    content.innerHTML = `<div class="my-estimates-empty">${escapeEstimateText(data.message || '내 견적을 불러오지 못했습니다.')}</div>`;
                }
            }
            return [];
        }

        updateMyEstimateCounts(data.counts || {});

        const allEstimates = data.estimates || [];
        const normalizeEstimateStatus = status => status === 'DONE' ? 'APPROVED' : status;
        const filtered = statusFilter === 'ALL'
            ? allEstimates
            : statusFilter === 'ACTIVE'
                ? allEstimates.filter(item => estimateRecordFilterMatch(item, 'ACTIVE'))
                : allEstimates.filter(item => normalizeEstimateStatus(item.status) === statusFilter);

        renderMyEstimates(allEstimates, titleLabel);
        return filtered;
    } catch (error) {
        if (showError) {
            const content = document.getElementById('myEstimatesPageContent');
            if (content) content.innerHTML = '<div class="my-estimates-empty">내 견적을 불러오지 못했습니다.</div>';
        }
        return [];
    }
}
async function openMyEstimates(statusFilter = 'ALL', titleLabel = '내 견적') {
    if (['CONTACTED','REVIEWING','APPROVED','DONE'].includes(statusFilter)) statusFilter = 'ACTIVE';
    if (statusFilter === 'NEW') statusFilter = 'ALL';
    titleLabel = estimateViewTitle(statusFilter);
    if (!currentMember) {
        openMemberModal('login');
        return;
    }

    const mypageView = document.getElementById('mypageView');
    const view = document.getElementById('myEstimatesView');
    const content = document.getElementById('myEstimatesPageContent');
    const title = document.getElementById('myEstimatesPageTitle');

    if (!view || !content) return;

    const inquiriesView = document.getElementById('myInquiriesView');
    if (inquiriesView) inquiriesView.hidden = true;
    const vehicleView = document.getElementById('myVehiclesView');
    if (vehicleView) vehicleView.hidden = true;
    if (mypageView) mypageView.hidden = true;
    view.hidden = false;
    if (title) title.textContent = estimateViewTitle(statusFilter);
    myEstimateStatusFilter = statusFilter === 'ACTIVE' ? 'ALL' : statusFilter;
    myEstimateRecordFilter = statusFilter === 'CONTRACTED' ? 'CONTRACTED' : (statusFilter === 'ALL' ? 'ALL' : 'ACTIVE');
    document.querySelectorAll('[data-estimate-record-filter]').forEach(tab => tab.classList.toggle('active', tab.dataset.estimateRecordFilter === myEstimateRecordFilter));
    content.innerHTML = '<div class="my-estimates-empty">견적 정보를 불러오는 중입니다.</div>';

    setBottomNavActive('mypage');
    setBottomNavVisible(true);
    resetPageScroll();

    await loadMyEstimates(true, statusFilter, titleLabel);
}

// 마이페이지 상태바 / 내 견적 메뉴 클릭
document.querySelectorAll('[data-estimate-status]').forEach(button => {
    button.addEventListener('click', () => {
        const status = button.dataset.estimateStatus || 'ALL';
        const title = button.dataset.estimateTitle || '내 견적';
        openMyEstimates(status, title);
    });
});

document.getElementById('estimateRecordTabs')?.addEventListener('click', event => {
    const button = event.target.closest('[data-estimate-record-filter]');
    if (!button) return;
    myEstimateStatusFilter = 'ALL';
    myEstimateRecordFilter = button.dataset.estimateRecordFilter || 'ALL';
    document.querySelectorAll('[data-estimate-record-filter]').forEach(tab => tab.classList.toggle('active', tab === button));
    const title = estimateViewTitle(myEstimateRecordFilter);
    const heading = document.getElementById('myEstimatesPageTitle');
    if (heading) heading.textContent = title;
    renderMyEstimates(myEstimateRecordCache, title);
    if (history.state?.ag_view?.kind === 'estimates') {
        history.replaceState({...history.state, ag_view:{kind:'estimates', filter:myEstimateRecordFilter, title}}, '', location.href);
    }
});

document.getElementById('inquiryRecordTabs')?.addEventListener('click', event => {
    const button = event.target.closest('[data-inquiry-record-filter]');
    if (!button) return;
    myInquiryRecordFilter = button.dataset.inquiryRecordFilter || 'ALL';
    document.querySelectorAll('[data-inquiry-record-filter]').forEach(tab => tab.classList.toggle('active', tab === button));
    renderInquiryRecordList();
});

function closeMyEstimates() {
    const view = document.getElementById('myEstimatesView');
    const mypageView = document.getElementById('mypageView');
    if (view) view.hidden = true;
    if (mypageView) mypageView.hidden = false;
    setBottomNavActive('mypage');
    setBottomNavVisible(true);
    resetPageScroll();
}

// ============================================================
// Q&A 문의 접수 / 마이페이지 문의 내역
// ============================================================

const INQUIRY_GUEST_KEY_STORAGE = 'autogenie_inquiry_guest_key_v1';
let inquiryRemoteCache = [];

function getInquiryGuestKey() {
    if (currentMember) return '';
    let key = localStorage.getItem(INQUIRY_GUEST_KEY_STORAGE) || '';
    if (!/^[A-Za-z0-9_-]{20,64}$/.test(key)) {
        key = `g_${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}_${Math.random().toString(36).slice(2)}`.slice(0, 64);
        localStorage.setItem(INQUIRY_GUEST_KEY_STORAGE, key);
    }
    return key;
}

async function loadMyInquiriesFromServer() {
    // 이전 구현은 목록 조회마다 localStorage 문의를 DB로 다시 이관하여
    // 관리자에서 삭제한 문의를 재생성했습니다. 이관은 조회 경로에서 실행하지 않습니다.
    try {
        const guest = getInquiryGuestKey();
        const url = './api/inquiry-list.php' + (guest ? `?guest_key=${encodeURIComponent(guest)}&_=${Date.now()}` : `?_=${Date.now()}`);
        const response = await agFetch(url, {credentials:'same-origin', cache:'no-store'});
        const data = await response.json();
        if (response.ok && data?.ok && Array.isArray(data.inquiries)) {
            inquiryRemoteCache = data.inquiries;
            return inquiryRemoteCache;
        }
    } catch (error) {
        console.error('문의 내역을 불러오지 못했습니다.', error);
    }
    // 실패 시에도 삭제된 문의가 로컬 저장소에서 되살아나지 않게 합니다.
    inquiryRemoteCache = [];
    return inquiryRemoteCache;
}

async function saveInquiry(message, category = '') {
    const response = await agFetch('./api/inquiry-create.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({message,category,guest_key:getInquiryGuestKey()})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.message || '문의 접수에 실패했습니다. 작성 내용은 유지됩니다. 잠시 후 다시 시도해 주세요.');
    await updateInquiryCount();
    return data.inquiry;
}

async function updateInquiryCount() {
    const items = await loadMyInquiriesFromServer();
    const el = document.getElementById('mypageInquiryCount');
    if (el) el.textContent = `${items.length}개`;
    const badge = document.getElementById('mypageInquiryNew');
    if (badge) badge.hidden = !items.some(isNewInquiry);
}

function formatInquiryDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('ko-KR', {
        year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit'
    }).format(date);
}

let myInquiryRecordCache = [];
let myInquiryRecordFilter = 'ALL';

function updateInquiryRecordTabs(items = []) {
    const answered = items.filter(isInquiryCompleted).length;
    const waiting = items.length - answered;
    const values = {inquiryTabAll:items.length, inquiryTabAnswered:answered, inquiryTabWaiting:waiting};
    Object.entries(values).forEach(([id,value]) => { const el=document.getElementById(id); if(el) el.textContent=String(value); });
}

function renderInquiryRecordList() {
    const content = document.getElementById('myInquiriesPageContent');
    if (!content) return;
    const filtered = myInquiryRecordCache.filter(item => {
        const answered = isInquiryCompleted(item);
        if (myInquiryRecordFilter === 'ANSWERED') return answered;
        if (myInquiryRecordFilter === 'WAITING') return !answered;
        return true;
    });
    if (!filtered.length) {
        content.innerHTML = mypageEmptyCard('inquiry', '해당하는 문의가 없습니다', '다른 상태의 문의를 확인하거나 새 문의를 남겨보세요.', 'inquiry', '1:1 문의하기');
        return;
    }
    content.innerHTML = `<div class="my-estimates-list">${filtered.map((item,index) => {
        const answered = isInquiryCompleted(item);
        const statusText = answered ? '처리완료' : '답변대기';
        const hasAnswer = Boolean(String(item.answer || '').trim());
        const answerText = hasAnswer ? escapeHtml(item.answer)
            : answered ? '<span class="my-inquiry-answer-empty">상담 처리가 완료되었습니다. 추가 문의가 있으면 새 문의를 남겨 주세요.</span>'
            : '<span class="my-inquiry-answer-empty">아직 답변이 등록되지 않았습니다. 빠르게 확인 후 안내드리겠습니다.</span>';
        const message = String(item.message || '문의 내용 없음');
        const title = message.split(/\r?\n/)[0].slice(0,42) || '문의 내용';
        return `<article class="my-inquiry-item">
            <details class="my-inquiry-accordion">
                <summary class="my-inquiry-summary">
                    <span class="inquiry-summary-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 5h14v10H9l-4 4V5Z" stroke-linejoin="round"/></svg>
                    </span>
                    <span class="my-inquiry-summary-main">
                        <span class="my-inquiry-question">${escapeHtml(title)}</span>
                        <span class="my-inquiry-summary-meta"><time>${escapeHtml(formatInquiryDate(item.created_at))}</time><span>${escapeHtml(item.inquiry_no || '')}</span></span>
                    </span>
                    <span class="my-inquiry-summary-right">
                        <span class="my-inquiry-status ${answered ? 'answered' : ''}">${escapeHtml(statusText)}</span>
                        <span class="my-inquiry-chevron" aria-hidden="true"></span>
                    </span>
                </summary>
                <div class="my-inquiry-expanded">
                    <div class="inquiry-message-box">
                        <div class="my-inquiry-expanded-label">문의 내용</div>
                        <div class="my-inquiry-expanded-text">${escapeHtml(message)}</div>
                    </div>
                    <div class="my-inquiry-expanded-answer">
                        <div class="answer-box-head"><span class="answer-box-icon" aria-hidden="true">↩</span><strong>${hasAnswer && !answered ? '이전 답변' : 'AutoGenie 답변'}</strong></div>
                        <div class="my-inquiry-answer-body">${answerText}</div>
                    </div>
                </div>
            </details>
        </article>`;
    }).join('')}</div>`;
}

async function openMyInquiries() {
    const mypageView = document.getElementById('mypageView');
    const view = document.getElementById('myInquiriesView');
    const content = document.getElementById('myInquiriesPageContent');
    if (!view || !content) return;

    const estimatesView = document.getElementById('myEstimatesView');
    if (estimatesView) estimatesView.hidden = true;
    const vehicleView = document.getElementById('myVehiclesView');
    if (vehicleView) vehicleView.hidden = true;
    if (mypageView) mypageView.hidden = true;
    view.hidden = false;
    myInquiryRecordFilter = 'ALL';
    document.querySelectorAll('[data-inquiry-record-filter]').forEach(btn => btn.classList.toggle('active', btn.dataset.inquiryRecordFilter === 'ALL'));
    setBottomNavActive('mypage');
    setBottomNavVisible(true);
    resetPageScroll();

    content.innerHTML = '<div class="my-estimates-empty">문의 내역을 불러오는 중입니다.</div>';

    const items = await loadMyInquiriesFromServer();
    myInquiryRecordCache = items.slice();
    updateInquiryRecordTabs(myInquiryRecordCache);

    if (!items.length) {
        content.innerHTML = mypageEmptyCard('inquiry', '아직 접수한 문의가 없습니다', '궁금한 사항이 있다면 언제든 문의를 남겨 주세요.', 'inquiry', '1:1 문의하기');
        return;
    }
    renderInquiryRecordList();
}

function closeMyInquiries() {
    const view = document.getElementById('myInquiriesView');
    const mypageView = document.getElementById('mypageView');
    if (view) view.hidden = true;
    if (mypageView) mypageView.hidden = false;
    setBottomNavActive('mypage');
    setBottomNavVisible(true);
    resetPageScroll();
}

// Customer inquiries are managed exclusively in the authenticated PHP admin pages.

// ============================================================
// 회원 로그인 / 회원가입 / 세션
// ============================================================
let currentMember = null;
try{const consultation=new URLSearchParams(location.search).get('consultation');if(/^(DIRECT|QUICK)-[1-9]\d{0,9}$/.test(consultation||''))sessionStorage.setItem('ag_pending_consultation',consultation);}catch(_){}
let memberWithdrawalInProgress = false;

// 견적에 사용할 연락처는 회원정보와 별도로 입력/수정하며, 회원 DB를 수정하지 않습니다.
function fillEstimateMemberFields(mode) {
    const isQuick = mode === 'quick';
    const name = document.getElementById(isQuick ? 'quickEstimateName' : 'estimateName');
    const phone = document.getElementById(isQuick ? 'quickEstimatePhone' : 'estimatePhone');
    const note = document.getElementById(isQuick ? 'quickEstimateMemberNote' : 'estimateMemberNote');
    const contactNote = document.getElementById(isQuick ? 'quickEstimateContactNote' : 'estimateContactNote');
    if (!name || !phone || !note || !contactNote) return;

    // 카카오 닉네임은 실제 상담 신청자 성함으로 간주하지 않습니다.
    const memberName = currentMember?.provider === 'kakao' ? '' : String(currentMember?.name || '').trim();
    const memberPhone = formatEstimatePhone(currentMember?.phone || '');
    const signedIn = Boolean(currentMember);
    name.value = signedIn ? memberName : '';
    phone.value = signedIn ? memberPhone : '';
    name.setCustomValidity('');
    phone.setCustomValidity('');
    name.classList.toggle('member-prefilled', signedIn && Boolean(memberName));
    phone.classList.toggle('member-prefilled', signedIn && Boolean(memberPhone));
    // 회원정보가 모두 있으면 자동입력 안내 배너를 표시하지 않습니다.
    // 필수 회원정보가 누락된 경우에만 입력 안내를 노출합니다.
    const missingMemberInfo = signedIn && (!memberName || !memberPhone);
    note.hidden = isQuick || !missingMemberInfo;
    note.textContent = !isQuick && missingMemberInfo ? '회원정보에 없는 항목은 직접 입력해 주세요.' : '';
    contactNote.hidden = !signedIn;
}

function setMemberMessage(elementId, message = '', type = 'error') {
    const el = document.getElementById(elementId);
    if (!el) return;
    el.textContent = message;
    el.className = `member-form-message${message ? ` show ${type}` : ''}`;
}

function resumeEstimateConsultation(member) {
    const query=new URLSearchParams(location.search).get('consultation')||'';
    let pending='';
    try {
        if(/^(DIRECT|QUICK)-[1-9]\d{0,9}$/.test(query))sessionStorage.setItem('ag_pending_consultation',query);
        pending=sessionStorage.getItem('ag_pending_consultation')||query;
    } catch (_) { pending=query; }
    const match=/^(DIRECT|QUICK)-([1-9]\d{0,9})$/.exec(pending);
    if(!match)return;
    if(member){try{sessionStorage.removeItem('ag_pending_consultation');}catch(_){}location.replace(`./consultation.php?type=${match[1]}&id=${match[2]}`);}
    else if(query)openMemberModal('login');
}
let memberStateRevision = 0;
function updateMemberUI(member) {
    memberStateRevision++;
    currentMember = member || null;
    resumeEstimateConsultation(currentMember);
    favoriteOwnerId=String(currentMember?.id||'');
    if (typeof refreshSavedQuoteCount === 'function') refreshSavedQuoteCount();
    if(currentMember)loadMemberFavorites();else updateMypageVehicleCounts();
    fillEstimateMemberFields('quick');
    // 열린 신청 모달의 정보는 로그인 상태가 바뀐 경우에만 새로 불러옵니다.
    if (document.getElementById('estimateModal')?.classList.contains('open')) fillEstimateMemberFields('estimate');
    const nameEl = document.getElementById('mypageUserName');
    const guestArea = document.getElementById('memberGuestArea');
    const signedText = document.getElementById('memberSignedInText');

    if (currentMember) {
        if (nameEl) nameEl.textContent = `${currentMember.name} 고객님`;
        if (guestArea) guestArea.hidden = true;
        if (signedText) {
            signedText.hidden = false;
            signedText.textContent = currentMember.provider === 'kakao'
                ? '카카오 계정으로 로그인 중입니다.'
                : `${currentMember.phone || currentMember.email}로 로그인 중입니다.`;
        }
        loadMyEstimates(false);
        updateInquiryCount();
    } else {
        if (nameEl) nameEl.textContent = '고객님';
        if (guestArea) guestArea.hidden = false;
        if (signedText) signedText.hidden = true;
        updateMyEstimateCounts();
        updateInquiryCount();
    }
}

async function loadCurrentMember(attempt = 0) {
    const revision = memberStateRevision;
    try {
        const response = await agFetch('./api/member-me.php', {credentials:'same-origin', cache:'no-store'});
        if (!response.ok) throw new Error('member lookup unavailable');
        const data = await response.json();
        if (data.ok !== true || !Object.prototype.hasOwnProperty.call(data, 'member')) throw new Error('invalid member lookup');
        if (revision === memberStateRevision) updateMemberUI(data.member || null);
    } catch (error) {
        // A temporary network/server error does not mean the account has logged out.
        if (revision === memberStateRevision && attempt < 2) {
            setTimeout(() => { if (revision === memberStateRevision) loadCurrentMember(attempt + 1); }, attempt ? 3000 : 1000);
        }
    }
}
let lastMemberRecheck = Date.now();
function recheckMemberOnReturn() {
    if (document.visibilityState !== 'visible' || Date.now() - lastMemberRecheck < 60000) return;
    lastMemberRecheck = Date.now();
    loadCurrentMember();
}
window.addEventListener('online', recheckMemberOnReturn);
window.addEventListener('pageshow', recheckMemberOnReturn);
document.addEventListener('visibilitychange', recheckMemberOnReturn);

function openMemberModal(mode = 'login') {
    const modal = document.getElementById('memberModal');
    const login = document.getElementById('memberLoginPanel');
    const register = document.getElementById('memberRegisterPanel');
    const reset = document.getElementById('memberResetPanel');
    const resetSupport = document.getElementById('memberResetSupportPanel');
    const settings = document.getElementById('memberSettingsPanel');
    const withdrawal = document.getElementById('memberWithdrawPanel');
    if (!modal) return;

    login.hidden = mode !== 'login';
    if (mode === 'login') {
        const emailDetails = document.getElementById('memberEmailDetails');
        if (emailDetails) emailDetails.open = true;
    }
    register.hidden = mode !== 'register';
    reset.hidden = mode !== 'reset';
    resetSupport.hidden = mode !== 'reset-support';
    settings.hidden = mode !== 'settings';
    withdrawal.hidden = mode !== 'withdraw';
    const titleIds = {
        login: 'memberModalTitle',
        register: 'memberRegisterTitle',
        reset: 'memberResetTitle',
        'reset-support': 'memberResetSupportTitle',
        settings: 'memberSettingsTitle',
        withdraw: 'memberWithdrawNoticeTitle'
    };
    modal.querySelector('.member-modal-card')?.setAttribute('aria-labelledby', titleIds[mode] || 'memberModalTitle');
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    setMemberMessage('memberLoginMessage');
    setMemberMessage('memberRegisterMessage');
    setMemberMessage('memberResetMessage');
    setMemberMessage('memberResetSupportMessage');
}

function closeMemberModal() {
    const modal = document.getElementById('memberModal');
    if (modal?.contains(document.activeElement)) document.activeElement.blur();
    if (modal) modal.hidden = true;
    document.body.classList.remove('ag-mobile-field-focus');
    document.body.style.overflow = '';
}

function openMemberSettings() {
    if (!currentMember) {
        openMemberModal('login');
        return;
    }
    document.getElementById('memberInfoName').textContent = currentMember.name || '-';
    document.getElementById('memberInfoPhone').textContent = currentMember.phone || '-';
    document.getElementById('memberInfoEmail').textContent = currentMember.email || '등록된 이메일 없음';
    document.getElementById('memberInfoCreatedAt').textContent = currentMember.created_at || '-';
    if (typeof resetProfileForm === 'function') resetProfileForm();
    openMemberModal('settings');
}

function beginMemberWithdrawal() {
    if (!currentMember) return;
    const acknowledged = document.getElementById('memberWithdrawAcknowledged');
    const nextButton = document.getElementById('memberWithdrawNextButton');
    const confirmation = document.getElementById('memberWithdrawConfirmation');
    const finalButton = document.getElementById('memberWithdrawFinalButton');
    if (acknowledged) acknowledged.checked = false;
    if (nextButton) nextButton.disabled = true;
    if (confirmation) confirmation.value = '';
    if (finalButton) finalButton.disabled = true;
    document.getElementById('memberWithdrawNoticeStage').hidden = false;
    document.getElementById('memberWithdrawConfirmStage').hidden = true;
    setMemberMessage('memberWithdrawMessage');
    openMemberModal('withdraw');
    document.getElementById('memberWithdrawAcknowledged')?.focus();
}

function cancelMemberWithdrawal() {
    openMemberModal('settings');
    document.querySelector('.member-withdraw-btn')?.focus();
}

function advanceMemberWithdrawal(step) {
    if (step === 2 && !document.getElementById('memberWithdrawAcknowledged')?.checked) return;
    const noticeStage = document.getElementById('memberWithdrawNoticeStage');
    const confirmStage = document.getElementById('memberWithdrawConfirmStage');
    noticeStage.hidden = step !== 1;
    confirmStage.hidden = step !== 2;
    document.querySelector('#memberModal .member-modal-card')?.setAttribute(
        'aria-labelledby', step === 2 ? 'memberWithdrawConfirmTitle' : 'memberWithdrawNoticeTitle'
    );
    if (step === 2) document.getElementById('memberWithdrawConfirmation')?.focus();
    else {
        const confirmation = document.getElementById('memberWithdrawConfirmation');
        const finalButton = document.getElementById('memberWithdrawFinalButton');
        if (confirmation) confirmation.value = '';
        if (finalButton) finalButton.disabled = true;
        setMemberMessage('memberWithdrawMessage');
        document.getElementById('memberWithdrawAcknowledged')?.focus();
    }
}

document.getElementById('memberWithdrawAcknowledged')?.addEventListener('change', event => {
    document.getElementById('memberWithdrawNextButton').disabled = !event.currentTarget.checked;
});

document.getElementById('memberWithdrawConfirmation')?.addEventListener('input', event => {
    document.getElementById('memberWithdrawFinalButton').disabled = memberWithdrawalInProgress || event.currentTarget.value.trim() !== '탈퇴';
    setMemberMessage('memberWithdrawMessage');
});

document.getElementById('memberWithdrawFinalForm')?.addEventListener('submit', event => {
    event.preventDefault();
    withdrawMember();
});

// 로그인/회원가입 팝업은 바깥 배경을 눌러도 닫히지 않도록 유지합니다.
// 사용자가 오른쪽 위 X 버튼을 눌렀을 때만 직접 닫힙니다.
document.getElementById('memberModal')?.addEventListener('click', event => {
    if (event.target.id === 'memberModal') {
        event.preventDefault();
        event.stopPropagation();
    }
});

document.getElementById('memberRegisterPhone')?.addEventListener('input', event => {
    event.currentTarget.value = formatEstimatePhone(event.currentTarget.value);
});

let memberRegisterPhoneVerified = '';

function resetMemberRegisterPhoneVerification() {
    memberRegisterPhoneVerified = '';
    const state = document.getElementById('memberRegisterPhoneVerifyState');
    if (state) {
        state.textContent = '문자로 받은 6자리 인증번호를 입력해 주세요. (10분 유효)';
        state.style.color = '';
    }
}

document.getElementById('memberRegisterPhone')?.addEventListener('input', () => {
    resetMemberRegisterPhoneVerification();
});

document.getElementById('memberRegisterPhoneCode')?.addEventListener('input', event => {
    event.currentTarget.value = event.currentTarget.value.replace(/\D/g, '').slice(0, 6);
});

document.getElementById('memberRegisterPhoneCodeButton')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const phoneInput = document.getElementById('memberRegisterPhone');
    const codeInput = document.getElementById('memberRegisterPhoneCode');
    const phone = String(phoneInput?.value || '').replace(/\D/g, '');
    setMemberMessage('memberRegisterMessage');
    resetMemberRegisterPhoneVerification();

    if (!/^01[016789]\d{7,8}$/.test(phone)) {
        setMemberMessage('memberRegisterMessage', '올바른 휴대폰 번호를 입력해 주세요.');
        phoneInput?.focus();
        return;
    }

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = '발송 중...';

    try {
        const response = await agFetch('./api/member-phone-verification-request.php', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({phone})
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || '인증문자를 발송하지 못했습니다.');
        codeInput.value = '';
        codeInput?.focus();
        let message = data.message || '인증번호를 문자로 발송했습니다.';
        // 로컬 전용 디버그 설정을 명시적으로 켠 경우에만 테스트 코드가 제공됩니다.
        if (data.debug_code) {
            message += ` (로컬 테스트 인증번호: ${data.debug_code})`;
            codeInput.value = data.debug_code;
        }
        setMemberMessage('memberRegisterMessage', message, 'ok');
    } catch (error) {
        setMemberMessage('memberRegisterMessage', error.message || '인증문자를 발송하지 못했습니다.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
});

document.getElementById('memberRegisterPhoneVerifyButton')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const phoneInput = document.getElementById('memberRegisterPhone');
    const codeInput = document.getElementById('memberRegisterPhoneCode');
    const phone = String(phoneInput?.value || '').replace(/\D/g, '');
    const code = String(codeInput?.value || '').replace(/\D/g, '');
    setMemberMessage('memberRegisterMessage');

    if (!/^01[016789]\d{7,8}$/.test(phone)) {
        setMemberMessage('memberRegisterMessage', '올바른 휴대폰 번호를 입력해 주세요.');
        phoneInput?.focus();
        return;
    }
    if (code.length !== 6) {
        setMemberMessage('memberRegisterMessage', '문자로 받은 6자리 인증번호를 입력해 주세요.');
        codeInput?.focus();
        return;
    }

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = '확인 중...';
    try {
        const response = await agFetch('./api/member-phone-verification-confirm.php', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({phone, code})
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || '휴대폰 인증에 실패했습니다.');
        memberRegisterPhoneVerified = phone;
        const state = document.getElementById('memberRegisterPhoneVerifyState');
        if (state) {
            state.textContent = '휴대폰 인증 완료';
            state.style.color = '#287044';
        }
        setMemberMessage('memberRegisterMessage', '휴대폰 인증이 완료되었습니다.', 'ok');
    } catch (error) {
        resetMemberRegisterPhoneVerification();
        setMemberMessage('memberRegisterMessage', error.message || '휴대폰 인증에 실패했습니다.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
});

document.getElementById('memberRegisterForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = form.querySelector('[type="submit"]');
    setMemberMessage('memberRegisterMessage');

    const registerPhone = String(document.getElementById('memberRegisterPhone')?.value || '').replace(/\D/g, '');
    if (!memberRegisterPhoneVerified || memberRegisterPhoneVerified !== registerPhone) {
        setMemberMessage('memberRegisterMessage', '휴대폰 인증을 먼저 완료해 주세요.');
        document.getElementById('memberRegisterPhone')?.focus();
        return;
    }

    submit.disabled = true;

    try {
        const response = await agFetch('./api/member-register.php', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest'},
            body:JSON.stringify(Object.fromEntries(new FormData(form).entries()))
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || '회원가입에 실패했습니다.');

        updateMemberUI(data.member);
        setMemberMessage('memberRegisterMessage', '회원가입이 완료되었습니다.', 'ok');
        setTimeout(() => {
            closeMemberModal();
            form.reset();
            resetMemberRegisterPhoneVerification();
        }, 450);
    } catch (error) {
        setMemberMessage('memberRegisterMessage', error.message || '회원가입에 실패했습니다.');
    } finally {
        submit.disabled = false;
    }
});

document.getElementById('memberLoginForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = form.querySelector('[type="submit"]');
    setMemberMessage('memberLoginMessage');
    submit.disabled = true;

    try {
        const response = await agFetch('./api/member-login.php', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body:JSON.stringify(Object.fromEntries(new FormData(form).entries()))
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || '로그인에 실패했습니다.');

        updateMemberUI(data.member);
        closeMemberModal();
        form.reset();
    } catch (error) {
        setMemberMessage('memberLoginMessage', error.message || '로그인에 실패했습니다.');
    } finally {
        submit.disabled = false;
    }
});

document.getElementById('memberResetSupportPhone')?.addEventListener('input', event => {
    event.currentTarget.value = formatEstimatePhone(event.currentTarget.value);
});

document.getElementById('memberResetSupportForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = form.querySelector('[type="submit"]');
    setMemberMessage('memberResetSupportMessage');
    submit.disabled = true;

    try {
        const response = await agFetch('./api/member-password-reset-admin-request.php', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(Object.fromEntries(new FormData(form).entries()))
        });
        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.message || '재설정 요청을 보내지 못했습니다.');
        }

        setMemberMessage('memberResetSupportMessage', data.message, 'ok');
        form.reset();
    } catch (error) {
        setMemberMessage('memberResetSupportMessage', error.message || '재설정 요청을 보내지 못했습니다.');
    } finally {
        submit.disabled = false;
    }
});

document.getElementById('memberResetCode')?.addEventListener('input', event => {
    event.currentTarget.value = event.currentTarget.value.replace(/\D/g, '').slice(0, 6);
});

document.getElementById('memberResetCodeButton')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const emailInput = document.getElementById('memberResetEmail');
    const email = String(emailInput?.value || '').trim().toLowerCase();
    setMemberMessage('memberResetMessage');

    if (!email || !emailInput.checkValidity()) {
        emailInput?.reportValidity();
        emailInput?.focus();
        return;
    }

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = '발송 중...';

    try {
        const response = await agFetch('./api/member-password-reset-request.php', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({email})
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || '인증번호 발송에 실패했습니다.');

        let message = data.message || '인증번호를 발송했습니다.';
        if (data.debug_code) {
            message += ` 로컬 테스트 인증번호: ${data.debug_code}`;
        }
        setMemberMessage('memberResetMessage', message, 'ok');
    } catch (error) {
        setMemberMessage('memberResetMessage', error.message || '인증번호 발송에 실패했습니다.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
});

document.getElementById('memberResetForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = form.querySelector('[type="submit"]');
    setMemberMessage('memberResetMessage');

    const payload = Object.fromEntries(new FormData(form).entries());
    if (payload.password !== payload.password_confirm) {
        setMemberMessage('memberResetMessage', '새 비밀번호 확인이 일치하지 않습니다.');
        return;
    }

    submit.disabled = true;
    try {
        const response = await agFetch('./api/member-password-reset-confirm.php', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || '비밀번호 변경에 실패했습니다.');

        setMemberMessage('memberResetMessage', '비밀번호가 변경되었습니다. 새 비밀번호로 로그인해 주세요.', 'ok');
        setTimeout(() => {
            form.reset();
            openMemberModal('login');
            const loginEmail = document.getElementById('memberLoginEmail');
            if (loginEmail) loginEmail.value = String(payload.email || '');
        }, 900);
    } catch (error) {
        setMemberMessage('memberResetMessage', error.message || '비밀번호 변경에 실패했습니다.');
    } finally {
        submit.disabled = false;
    }
});

async function logoutMember() {
    try {
        await agFetch('./api/member-logout.php', {method:'POST', credentials:'same-origin'});
    } finally {
        updateMemberUI(null);
        closeMemberModal();
    }
}

async function withdrawMember() {
    const confirmation = document.getElementById('memberWithdrawConfirmation');
    if (memberWithdrawalInProgress || !currentMember || confirmation?.value.trim() !== '탈퇴') return;

    const button = document.getElementById('memberWithdrawFinalButton');
    memberWithdrawalInProgress = true;
    if (button) button.disabled = true;
    try {
        const response = await agFetch('./api/member-withdraw.php', {
            method: 'POST', credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json'},
            body: JSON.stringify({confirmation: confirmation.value.trim()})
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || '탈퇴 처리에 실패했습니다.');
        updateMemberUI(null);
        ['memberInfoName', 'memberInfoPhone', 'memberInfoEmail', 'memberInfoCreatedAt'].forEach(id => {
            const field = document.getElementById(id);
            if (field) field.textContent = '-';
        });
        closeMemberModal();
        window.alert('회원 탈퇴가 완료되었습니다.');
    } catch (error) {
        setMemberMessage('memberWithdrawMessage', error.message || '탈퇴 처리에 실패했습니다. 잠시 후 다시 시도해 주세요.');
    } finally {
        memberWithdrawalInProgress = false;
        if (button) button.disabled = confirmation?.value.trim() !== '탈퇴';
    }
}

loadCurrentMember();
// 카카오 인증이 취소되거나 실패한 경우 이용자에게 안내합니다.
const kakaoLoginResult = new URLSearchParams(window.location.search).get('kakao_login');
if (kakaoLoginResult === 'cancel' || kakaoLoginResult === 'error') {
    openMemberModal('login');
    setMemberMessage('memberLoginMessage', kakaoLoginResult === 'cancel'
        ? '카카오 로그인이 취소되었습니다.'
        : '카카오 로그인을 완료하지 못했습니다. 설정을 확인한 뒤 다시 시도해 주세요.');
    document.getElementById('memberEmailDetails')?.setAttribute('open', '');
}
if (kakaoLoginResult && window.history && window.history.replaceState) {
    const cleanedUrl = new URL(window.location.href);
    cleanedUrl.searchParams.delete('kakao_login');
    window.history.replaceState(null, '', cleanedUrl.pathname + cleanedUrl.search + cleanedUrl.hash);
}

// ============================================================
// 간편견적 입력 처리
// - 이름/연락처는 기존 견적신청과 같은 규칙 적용
// - 저장 API에 로그인 회원정보를 자동 입력한 신청 내용을 전달
// ============================================================
const quickEstimateNameInput = document.getElementById('quickEstimateName');
const quickEstimatePhoneInput = document.getElementById('quickEstimatePhone');

quickEstimateNameInput?.addEventListener('focus', () => trackEstimateProgress('CUSTOMER_INPUT'));
quickEstimatePhoneInput?.addEventListener('focus', () => trackEstimateProgress('CUSTOMER_INPUT'));

quickEstimateNameInput?.addEventListener('input', event => {
    const input = event.currentTarget;
    const cleaned = input.value
        .replace(/[^가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z\s]/g, '')
        .replace(/\s{2,}/g, ' ')
        .slice(0, 30);

    if (input.value !== cleaned) input.value = cleaned;
    input.setCustomValidity('');
});

quickEstimateNameInput?.addEventListener('blur', event => {
    event.currentTarget.value = event.currentTarget.value.trim().replace(/\s{2,}/g, ' ');
});

quickEstimatePhoneInput?.addEventListener('input', event => {
    const input = event.currentTarget;
    input.value = formatEstimatePhone(input.value);
    input.setCustomValidity('');
});

document.getElementById('quickEstimateForm')?.addEventListener('submit', async event => {
    event.preventDefault();

    const form = event.currentTarget;
    if (form.dataset.agSubmitting === '1') return;
    const formData = new FormData(form);
    const result = document.getElementById('quickEstimateResult');

    const customerName = String(formData.get('customer_name') || '')
        .trim()
        .replace(/\s{2,}/g, ' ');
    const customerPhone = formatEstimatePhone(formData.get('customer_phone'));
    const phoneDigits = customerPhone.replace(/\D/g, '');

    const validName = /^[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+(?:\s[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+)*$/.test(customerName)
        && customerName.replace(/\s/g, '').length >= 2;

    if (!validName) {
        quickEstimateNameInput?.setCustomValidity('성함은 한글 또는 영문만 2자 이상 입력해 주세요.');
        quickEstimateNameInput?.reportValidity();
        quickEstimateNameInput?.focus();
        return;
    }

    if (!/^(?:010\d{8}|01[16789]\d{7,8})$/.test(phoneDigits)) {
        quickEstimatePhoneInput?.setCustomValidity('010, 011, 016, 017, 018, 019로 시작하는 올바른 휴대폰 번호를 입력해 주세요.');
        quickEstimatePhoneInput?.reportValidity();
        quickEstimatePhoneInput?.focus();
        return;
    }

    const submitButton = form.querySelector('.quick-estimate-submit');
    form.dataset.agSubmitting = '1';
    if (submitButton) submitButton.disabled = true;
    result.className = 'quick-estimate-result';
    result.textContent = '';

    const payload = {
        customer_name: customerName,
        customer_phone: customerPhone,
        car_type: String(formData.get('car_type') || ''),
        monthly_budget: String(formData.get('monthly_budget') || ''),
        product_type: String(formData.get('product_type') || ''),
        privacy_consent: formData.get('privacy_consent') === '1',
        ...getAcquisitionData()
    };

    try {
        const response = await agFetch(SAVE_QUICK_ESTIMATE_URL, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || '간편견적 저장에 실패했습니다.');
        }
        trackEstimateProgress('COMPLETED');

        result.className = 'quick-estimate-result show ok';
        result.innerHTML = `
            간편견적 신청이 완료되었습니다.<br>
            접수번호 <strong>${escapeHtml(data.estimate_no)}</strong><br>
            확인 후 입력하신 연락처로 안내드리겠습니다.
        `;
        form.reset();
        fillEstimateMemberFields('quick');
    } catch (error) {
        result.className = 'quick-estimate-result show error';
        result.textContent = error.message || '간편견적 저장 중 오류가 발생했습니다.';
    } finally {
        delete form.dataset.agSubmitting;
        if (submitButton) submitButton.disabled = false;
    }
});

// 모바일 가상 키보드가 나타날 때 하단 고정 메뉴/CTA가 입력칸을 덮지 않게 합니다.
(function bindMobileFieldFocus() {
    const isTextField = el => el && el.matches?.('input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), textarea, select, [contenteditable="true"]');
    const sync = () => document.body.classList.toggle('ag-mobile-field-focus', Boolean(isTextField(document.activeElement)));
    document.addEventListener('focusin', event => {
        if (isTextField(event.target)) document.body.classList.add('ag-mobile-field-focus');
    });
    document.addEventListener('focusout', () => requestAnimationFrame(sync));
    window.addEventListener('pageshow', sync);
})();

/* 마이페이지 하단 관심 차량 / 최근 본 차량: 현재 브라우저에만 저장 */
const MYPAGE_RECENT_STORAGE = 'autogenie_mypage_recent_v1';
const MYPAGE_FAVORITES_STORAGE = 'autogenie_mypage_favorites_v1';
let mypageVehiclePanelMode = '';
let favoriteOwnerId = '';
function memberVehicleStorageKey(key){ return key===MYPAGE_FAVORITES_STORAGE ? `${key}:${favoriteOwnerId||'guest'}` : key; }
function readMypageVehicleIds(key) {
    key=memberVehicleStorageKey(key);
    try {
        const legacy=key===`${MYPAGE_FAVORITES_STORAGE}:guest` ? localStorage.getItem(MYPAGE_FAVORITES_STORAGE) : null;
        const raw = JSON.parse(localStorage.getItem(key) || legacy || '[]');
        return Array.isArray(raw) ? [...new Set(raw.filter(v => typeof v === 'string' && v.length < 100))] : [];
    } catch (_) { return []; }
}
function writeMypageVehicleIds(key, ids) {
    key=memberVehicleStorageKey(key);
    try { localStorage.setItem(key, JSON.stringify(ids)); } catch (_) { /* 저장 제한된 브라우저 */ }
}
function rememberMypageRecentVehicle(key) {
    if (!key) return;
    const ids = readMypageVehicleIds(MYPAGE_RECENT_STORAGE).filter(id => id !== key);
    writeMypageVehicleIds(MYPAGE_RECENT_STORAGE, [key, ...ids].slice(0, 20));
    updateMypageVehicleCounts();
}
function updateMypageVehicleCounts() {
    const favoriteCount = document.getElementById('mypageFavoriteCount');
    const recentCount = document.getElementById('mypageRecentCount');
    if (favoriteCount) favoriteCount.textContent = `${readMypageVehicleIds(MYPAGE_FAVORITES_STORAGE).length}대`;
    if (recentCount) recentCount.textContent = `${readMypageVehicleIds(MYPAGE_RECENT_STORAGE).length}대`;
}
function mypageHeartButton(id, active, extraClass = '') {
    const label = active ? '관심 차량에서 해제' : '관심 차량에 추가';
    return `<button class="mypage-saved-vehicle-heart ${extraClass}" type="button" data-mypage-toggle-favorite="${escapeHtml(String(id))}" aria-label="${label}" title="${label}" aria-pressed="${active}"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.9a5.4 5.4 0 0 0-7.6 0L12 6.1l-1.2-1.2a5.4 5.4 0 0 0-7.6 7.6l1.2 1.2L12 21l7.6-7.3 1.2-1.2a5.4 5.4 0 0 0 0-7.6Z"/></svg></button>`;
}
async function toggleMypageVehicleFavorite(id) {
    if (!id) return;
    const current = readMypageVehicleIds(MYPAGE_FAVORITES_STORAGE);
    const active = !current.includes(id);
    if(currentMember){
        try{const response=await agFetch('./api/member-favorites.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({vehicle_id:Number(id),active})});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'관심 차량 저장에 실패했습니다.');}
        catch(error){alert(error.message||'관심 차량 저장에 실패했습니다. 잠시 후 다시 시도해 주세요.');return;}
    }
    writeMypageVehicleIds(MYPAGE_FAVORITES_STORAGE, active ? [id, ...current].slice(0, 40) : current.filter(v => v !== id));
    updateMypageVehicleCounts();
    // Keep all hearts in sync without rebuilding and jumping the vehicle list.
    document.querySelectorAll('[data-mypage-toggle-favorite]').forEach(button => {
        if (button.dataset.mypageToggleFavorite !== id) return;
        button.setAttribute('aria-pressed', String(active));
        const label = active ? '관심 차량에서 해제' : '관심 차량에 추가';
        button.setAttribute('aria-label', label);
        button.title = label;
    });
    const view = document.getElementById('myVehiclesView');
    if (view && !view.hidden) renderMypageVehiclePage();
}
function renderMypageVehiclePage() {
    const view = document.getElementById('myVehiclesView');
    const list = document.getElementById('myVehiclesPageList');
    if (!view || !list || !mypageVehiclePanelMode || view.hidden) return;
    const isFavorites = mypageVehiclePanelMode === 'favorites';
    const favorites = readMypageVehicleIds(MYPAGE_FAVORITES_STORAGE);
    const ids = readMypageVehicleIds(isFavorites ? MYPAGE_FAVORITES_STORAGE : MYPAGE_RECENT_STORAGE);
    const vehicles = ids.map(id => ({id,vehicle:getCurrentVehicleByKey(id)})).filter(item => item.vehicle);
    const title = isFavorites ? '관심 차량' : '최근 본 차량';
    const pageTitle = document.getElementById('myVehiclesPageTitle');
    if (pageTitle) pageTitle.textContent = title;

    if (!vehicles.length) {
        list.innerHTML = isFavorites
            ? mypageEmptyCard('favorites','아직 저장한 관심 차량이 없습니다','차량 상세 화면에서 하트(♡)를 누르면 관심 차량에 저장됩니다.','vehicle','차량 찾아보기')
            : mypageEmptyCard('recent','최근 본 차량이 없습니다','차량찾기에서 원하는 차종을 살펴보세요.','vehicle','차량 찾아보기');
        return;
    }
    list.innerHTML = `<div class="mypage-vehicle-section-caption"><strong>${title}</strong><span>${vehicles.length}대의 차량</span></div><div class="mypage-vehicle-cards">${vehicles.map(({id,vehicle}) => {
        const src = imageCandidates(vehicle)[0];
        const image = src ? `<img src="${escapeHtml(src)}" alt="" loading="lazy" onerror="this.style.display='none'">` : '<span class="image-missing">이미지 준비 중</span>';
        const active = favorites.includes(id);
        const label = `${getBrandName(vehicle)} ${getVehicleName(vehicle)}`.trim();
        return `<article class="mypage-saved-vehicle">
            <button class="mypage-saved-vehicle-info" type="button" data-mypage-open-vehicle="${escapeHtml(id)}" aria-label="${escapeHtml(label)} 견적 보기">
                <span class="mypage-saved-vehicle-image">${image}</span>
                <span class="mypage-saved-vehicle-text"><small class="mypage-vehicle-brand">${escapeHtml(getBrandName(vehicle))}</small><strong>${escapeHtml(getVehicleName(vehicle))}</strong><span class="mypage-vehicle-link">차량 견적 보기 <span aria-hidden="true">→</span></span></span>
            </button>
            ${mypageHeartButton(id, active)}
        </article>`;
    }).join('')}</div>`;
}
function openMypageVehiclePage(mode) {
    if (!['favorites','recent'].includes(mode)) return;
    const view = document.getElementById('myVehiclesView');
    if (!view) return;
    mypageVehiclePanelMode = mode;
    document.querySelectorAll('.page > .view-section').forEach(section => { section.hidden = true; });
    document.body.classList.remove('home-main-active','detail-mode');
    view.hidden = false;
    renderMypageVehiclePage();
    setBottomNavActive('mypage');
    setBottomNavVisible(true);
    resetPageScroll();
}
function closeMypageVehiclePage() {
    openMainView('mypage');
}
function bindMypageVehicleMenu() {
    const mypage = document.getElementById('mypageView');
    const view = document.getElementById('myVehiclesView');
    if (!mypage || !view) return;
    mypage.addEventListener('click', event => {
        const trigger = event.target.closest('[data-mypage-vehicle-list]');
        if (trigger) openMypageVehiclePage(trigger.dataset.mypageVehicleList);
    });
    document.querySelector('.page')?.addEventListener('click', event => {
        const browse = event.target.closest('[data-mypage-browse]');
        if (browse && (view.contains(browse) || event.target.closest('#myEstimatesView, #myInquiriesView'))) {
            const action = browse.dataset.mypageBrowse;
            if (action === 'recent') openMypageVehiclePage('recent');
            else if (action === 'vehicle') openMainView('vehicle');
            else if (action === 'inquiry') openQnaInquiryPage();
            return;
        }
        const save = event.target.closest('[data-mypage-toggle-favorite]');
        if (save && save.closest('#myVehiclesView, #vehicleListView, #vehicleDetailView')) {
            event.preventDefault();
            event.stopPropagation();
            toggleMypageVehicleFavorite(save.dataset.mypageToggleFavorite);
            return;
        }
        if (view.hidden) return;
        const open = event.target.closest('[data-mypage-open-vehicle]');
        if (open && view.contains(open)) openVehicleDetail(open.dataset.mypageOpenVehicle);
    });
    updateMypageVehicleCounts();
}
bindMypageVehicleMenu();

async function loadMemberFavorites(){
    if(!currentMember)return;
    const owner=String(currentMember.id);
    try{const response=await agFetch('./api/member-favorites.php',{credentials:'same-origin',cache:'no-store'});const data=await response.json();if(response.ok&&data.ok&&String(currentMember?.id)===owner){writeMypageVehicleIds(MYPAGE_FAVORITES_STORAGE,data.ids);updateMypageVehicleCounts();if(!document.getElementById('myVehiclesView')?.hidden)renderMypageVehiclePage();}}
    catch(error){console.error('관심 차량 조회 실패',error);}
}

// Vehicle comparison uses one identical product/term/prepayment/mileage combination.
let agCompareIds=[];
let agComparisonQuotes=new Map();
let agCompareSelection=null;
const agCompareBaseline={product_type:'RENT',contract_months:'36',prepayment_rate:'0',annual_mileage:'10000'};
function agCompareKey(price){return [price.product_type,Number(price.contract_months),Number(price.prepayment_rate||0),Number(price.annual_mileage||0)].join('|');}
function agCompareConditionOptions(vehicles,field,defaults){
    const values=new Set(defaults.map(String));
    vehicles.forEach(vehicle=>(vehicle.trims||[]).forEach(trim=>(trim.prices||[]).forEach(price=>{
        let value=price[field];
        if((value===undefined||value===null||value==='')&&(field==='prepayment_rate'||field==='annual_mileage'))value=0;
        if(value!==undefined&&value!==null&&value!=='')values.add(String(value));
    })));
    return [...values].sort(field==='product_type'
        ?(a,b)=>defaults.indexOf(a)-defaults.indexOf(b)
        :(a,b)=>Number(a)-Number(b));
}
function agRenderCompareTray(){
    const tray=document.getElementById('vehicleCompareTray');if(!tray)return;
    tray.hidden=agCompareIds.length===0 || document.getElementById('vehicleListView')?.hidden;
    tray.querySelector('[data-compare-count]').textContent=String(agCompareIds.length);
    tray.querySelector('[data-open-compare]').disabled=agCompareIds.length<2;
}
function agToggleCompare(id){
    if(agCompareIds.includes(id))agCompareIds=agCompareIds.filter(x=>x!==id);
    else{if(agCompareIds.length>=3){alert('차량은 최대 3대까지 비교할 수 있습니다.');return;}agCompareIds.push(id);}
    document.querySelectorAll('[data-compare-vehicle]').forEach(button=>{const active=agCompareIds.includes(button.dataset.compareVehicle);button.setAttribute('aria-pressed',String(active));button.textContent=active?'✓ 비교 선택됨':'+ 비교하기';});agRenderCompareTray();
}
function agRenderComparison(){
    const vehicles=agCompareIds.map(getCurrentVehicleByKey).filter(Boolean);if(vehicles.length<2)return;
    const maps=vehicles.map(vehicle=>{const map=new Map();for(const trim of vehicle.trims||[])for(const price of trim.prices||[]){const key=agCompareKey(price);const previous=map.get(key);if(!previous||Number(price.monthly_payment)<Number(previous.price.monthly_payment))map.set(key,{price,trim});}return map;});
    const options={
        product_type:agCompareConditionOptions(vehicles,'product_type',['RENT','LEASE']),
        contract_months:agCompareConditionOptions(vehicles,'contract_months',[12,24,36,48,60]),
        prepayment_rate:agCompareConditionOptions(vehicles,'prepayment_rate',[0,10,20,30]),
        annual_mileage:agCompareConditionOptions(vehicles,'annual_mileage',[0,10000,15000,20000,30000])
    };
    const hasSelection=agCompareSelection&&Object.keys(options).every(field=>options[field].includes(String(agCompareSelection[field])));
    if(!hasSelection)agCompareSelection={...agCompareBaseline};
    const selected=agCompareKey(agCompareSelection);
    agComparisonQuotes=new Map(vehicles.map((vehicle,index)=>[getVehicleId(vehicle),maps[index].get(selected)]));
    const content=document.getElementById('vehicleCompareContent');if(!content)return;
    const renderControl=(label,field,format)=>`<label class="compare-condition-label">${label}<select data-compare-condition="${field}">${options[field].map(value=>`<option value="${escapeHtml(value)}" ${String(value)===String(agCompareSelection[field])?'selected':''}>${escapeHtml(format(value))}</option>`).join('')}</select></label>`;
    content.innerHTML=`<p class="compare-desc">기본 조건: 장기렌트 · 3년 · 선납 0% · 연 10,000km. 원하는 항목만 변경할 수 있습니다.</p><div class="compare-condition-controls">${renderControl('이용상품','product_type',productLabel)}${renderControl('계약기간','contract_months',yearsFromMonths)}${renderControl('선납률','prepayment_rate',value=>`${value}%`)}${renderControl('연 주행거리','annual_mileage',value=>Number(value)===0?'무제한':`연 ${Number(value).toLocaleString('ko-KR')}km`)}</div><div class="compare-table-wrap"><table class="compare-table ${vehicles.length===2?'compare-table--two':''}"><thead><tr><th>항목</th>${vehicles.map(vehicle=>`<th>${escapeHtml(getVehicleName(vehicle))}</th>`).join('')}</tr></thead><tbody>
    <tr><th>브랜드</th>${vehicles.map(v=>`<td>${escapeHtml(getBrandName(v))}</td>`).join('')}</tr>
    <tr><th>연료</th>${vehicles.map(v=>`<td>${escapeHtml(({GASOLINE:'휘발유',DIESEL:'경유',HYBRID:'하이브리드',PHEV:'플러그인 하이브리드',EV:'전기',LPG:'LPG',OTHER:'기타'})[v.fuel_type]||'-')}</td>`).join('')}</tr>
    <tr><th>비교 트림</th>${maps.map(map=>`<td>${escapeHtml(map.get(selected)?.trim.name||'조건 확인 필요')}</td>`).join('')}</tr>
    <tr class="compare-price-row"><th>월 이용료</th>${maps.map(map=>`<td>${map.has(selected)?money(map.get(selected).price.monthly_payment):'조건 확인 필요'}</td>`).join('')}</tr>
    <tr><th>선납금</th>${maps.map((map,index)=>{const q=map.get(selected);return `<td>${q?Math.round(Number(q.trim.price||vehicles[index].base_price||0)*Number(q.price.prepayment_rate||0)/100).toLocaleString('ko-KR')+'원':'조건 확인 필요'}</td>`;}).join('')}</tr>
    <tr><th>계약기간</th>${maps.map(map=>`<td>${map.has(selected)?yearsFromMonths(map.get(selected).price.contract_months):'조건 확인 필요'}</td>`).join('')}</tr>
    <tr><th>연 주행거리</th>${maps.map(map=>{const q=map.get(selected);return `<td>${q?(Number(q.price.annual_mileage)===0?'무제한':Number(q.price.annual_mileage).toLocaleString('ko-KR')+'km'):'조건 확인 필요'}</td>`;}).join('')}</tr>
    <tr><th>차량 상세</th>${vehicles.map(v=>`<td><button type="button" data-compare-detail="${escapeHtml(getVehicleId(v))}">견적 보기</button></td>`).join('')}</tr></tbody></table></div>${vehicles.length===3?'<p class="compare-scroll-hint">모바일에서는 표를 좌우로 밀어 세 차량을 확인해 주세요.</p>':''}<p class="compare-desc">표시 금액은 등록된 참고 금액입니다. 최종 조건은 상담 시 확인해 주세요.</p>`;
    content.querySelectorAll('[data-compare-condition]').forEach(select=>select.addEventListener('change',event=>{agCompareSelection={...agCompareSelection,[event.currentTarget.dataset.compareCondition]:event.currentTarget.value};agRenderComparison();}));
}
function agOpenCompare(){if(agCompareIds.length<2)return;agRenderComparison();const modal=document.getElementById('vehicleCompareModal');modal.hidden=false;document.body.classList.add('modal-open');modal.querySelector('[data-close-compare]')?.focus();}
function agCloseCompare(){document.getElementById('vehicleCompareModal').hidden=true;document.body.classList.remove('modal-open');}
document.addEventListener('click',event=>{const toggle=event.target.closest('[data-compare-vehicle]');if(toggle)agToggleCompare(toggle.dataset.compareVehicle);if(event.target.closest('[data-open-compare]'))agOpenCompare();if(event.target.closest('[data-clear-compare]')){agCompareIds=[];renderVehicleList();}if(event.target.closest('[data-close-compare]')||event.target.id==='vehicleCompareModal')agCloseCompare();const detail=event.target.closest('[data-compare-detail]');if(detail){const id=detail.dataset.compareDetail;const quote=agComparisonQuotes.get(id);agCloseCompare();openVehicleDetail(id,true);const vehicle=getCurrentVehicleByKey(id);if(vehicle&&quote)renderVehicleDetail(vehicle,allVehicles.indexOf(vehicle),quote);}});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!document.getElementById('vehicleCompareModal')?.hidden)agCloseCompare();});

// History follows visible screens. Public paths/filenames are unchanged.
let agRestoringView=false;
let agHistoryReady=false;
function agCurrentRoute(){return history.state?.ag_view||{kind:'main',name:'home'};}
function agRouteHash(route){
    const params=new URLSearchParams();
    if(route.kind==='vehicle'||route.kind==='info')params.set('vehicle',route.key);
    else if(route.kind==='main'){if(route.name!=='home')params.set('view',route.name);}
    else {params.set('view',route.kind);if(route.mode)params.set('mode',route.mode);}
    if(route.kind==='info')params.set('info','1');
    if(route.modal)params.set('modal',route.modal.type);
    return params.toString()?`#${params}`:'';
}
function agRememberCurrentScroll(){
    const state=history.state||{};
    const route=state.ag_view;
    const quote=(route?.kind==='vehicle'||route?.kind==='info')?quoteStates.get(String(route.key)):null;
    const color=route?.key?document.querySelector(`[data-color-select="${CSS.escape(route.key)}"]`)?.dataset.selectedColorId:null;
    const selected=quote?{trimId:quote.trimId,priceId:quote.priceId,colorId:color,product:quote.product,months:quote.months,prepayment:quote.prepayment,mileage:quote.mileage}:state.ag_quote;
    history.replaceState({...state,ag_scroll:window.scrollY,ag_quote:selected},'',location.href);
}
function agCommitRoute(route){
    if(!agHistoryReady||agRestoringView)return;
    const url=location.pathname+location.search+agRouteHash(route);
    const same=JSON.stringify(agCurrentRoute())===JSON.stringify(route);
    const depth=Number(history.state?.ag_depth||0);
    if(same)history.replaceState({...history.state,ag_view:route},'',url);
    else history.pushState({ag_view:route,ag_depth:depth+1,ag_scroll:0},'',url);
    agRenderCompareTray();
}
function agBackOr(fallback){if(agHistoryReady&&!agRestoringView&&Number(history.state?.ag_depth)>0){history.back();return true;}fallback();return false;}
const agOriginalMain=openMainView;
openMainView=function(name,updateUrl=true){if(updateUrl&&!agRestoringView)agRememberCurrentScroll();const value=agOriginalMain(name,false);if(updateUrl)agCommitRoute({kind:'main',name});agRenderCompareTray();return value;};
const agOriginalDetail=openVehicleDetail;
openVehicleDetail=function(key,useListPrice=false){if(!agRestoringView)agRememberCurrentScroll();const value=agOriginalDetail(key,useListPrice);if(getCurrentVehicleByKey(key))agCommitRoute({kind:'vehicle',key:String(key),useListPrice});return value;};
// The original direct replacement is removed below; only the history controller writes screen URLs.
const agOriginalCloseDetail=closeVehicleDetail;
closeVehicleDetail=function(){agBackOr(()=>{agRestoringView=true;agOriginalCloseDetail();agRestoringView=false;agCommitRoute({kind:'main',name:'vehicle'});});};
const agOriginalInfo=openVehicleInfo;
openVehicleInfo=function(key){agRememberCurrentScroll();agOriginalInfo(key);agCommitRoute({kind:'info',key:String(key)});};
const agOriginalCloseInfo=closeVehicleInfo;
closeVehicleInfo=function(){agBackOr(()=>{agOriginalCloseInfo();const route=agCurrentRoute();agCommitRoute({kind:'vehicle',key:route.key});});};
const agOriginalEstimate=openEstimateModal;
openEstimateModal=function(key){agRememberCurrentScroll();agOriginalEstimate(key);if(document.getElementById('estimateModal')?.classList.contains('open'))agCommitRoute({...agCurrentRoute(),modal:{type:'estimate',key:String(key)}});};
const agOriginalCloseEstimate=closeEstimateModal;
closeEstimateModal=function(){if(agCurrentRoute().modal?.type==='estimate'&&Number(history.state?.ag_depth)>0&&!agRestoringView){history.back();return;}agOriginalCloseEstimate();};
const agOriginalInquiry=openQnaInquiryPage;
openQnaInquiryPage=function(origin='faq'){agRememberCurrentScroll();agOriginalInquiry(origin);agCommitRoute({kind:'inquiry',origin});};
const agOriginalCloseInquiry=closeQnaInquiryPage;
closeQnaInquiryPage=function(){agBackOr(agOriginalCloseInquiry);};
const agOriginalSaved=openMypageVehiclePage;
openMypageVehiclePage=function(mode){agRememberCurrentScroll();agOriginalSaved(mode);agCommitRoute({kind:'saved',mode});};
const agOriginalCloseSaved=closeMypageVehiclePage;
closeMypageVehiclePage=function(){agBackOr(agOriginalCloseSaved);};
const agOriginalEstimates=openMyEstimates;
openMyEstimates=async function(filter='ALL',title='내 견적'){
    agRememberCurrentScroll();
    await agOriginalEstimates(filter,title);
    if(!document.getElementById('myEstimatesView')?.hidden){
        const selected=myEstimateStatusFilter==='ALL'?myEstimateRecordFilter:myEstimateStatusFilter;
        agCommitRoute({kind:'estimates',filter:selected,title:estimateViewTitle(selected)});
    }
};
const agOriginalMyInquiries=openMyInquiries;
openMyInquiries=async function(){agRememberCurrentScroll();await agOriginalMyInquiries();agCommitRoute({kind:'my-inquiries'});};
const agOriginalCloseMyInquiries=closeMyInquiries;
closeMyInquiries=function(){agBackOr(agOriginalCloseMyInquiries);};
const agOriginalCloseMyEstimates=closeMyEstimates;
closeMyEstimates=function(){agBackOr(agOriginalCloseMyEstimates);};
async function agRestoreRoute(route,scroll=0){
    agRestoringView=true;
    try{
        agOriginalCloseEstimate();agCloseCompare();closeMemberModal();
        if(route.kind==='vehicle'||route.kind==='info'){
            agOriginalMain('vehicle',false);if(allVehicles.length){
                const vehicle=getCurrentVehicleByKey(route.key);const selected=history.state?.ag_quote;
                const trim=vehicle?.trims?.find(x=>String(x.id)===String(selected?.trimId));const price=trim?.prices?.find(x=>String(x.id)===String(selected?.priceId));
                agOriginalDetail(route.key,route.useListPrice||false);
                if(trim&&(price||selected?.product)){renderVehicleDetail(vehicle,allVehicles.indexOf(vehicle),{trim,price:price||{product_type:selected.product,contract_months:selected.months,prepayment_rate:selected.prepayment,annual_mileage:selected.mileage},preserveUnavailable:!price});const colorButton=document.querySelector(`[data-color-select="${CSS.escape(route.key)}"] [data-color-id="${CSS.escape(String(selected.colorId||''))}"]`);if(colorButton)selectVehicleColor(route.key,colorButton);}
if(route.kind==='info')agOriginalInfo(route.key);if(route.modal?.type==='estimate')agOriginalEstimate(route.modal.key);}
        }else if(route.kind==='inquiry'){agOriginalMain(route.origin||'faq',false);agOriginalInquiry(route.origin);}
        else if(route.kind==='saved-quotes'){if(typeof openSavedQuotes==='function')await openSavedQuotes();else window.addEventListener('ag-member-features-ready',()=>agRestoreRoute(route,scroll),{once:true});}
        else if(route.kind==='saved')agOriginalSaved(route.mode||'favorites');
        else if(route.kind==='my-inquiries'){agOriginalMain('mypage',false);await agOriginalMyInquiries();}
        else if(route.kind==='estimates'){agOriginalMain('mypage',false);if(!currentMember)await loadCurrentMember(2);if(currentMember)await agOriginalEstimates(route.filter||'ALL',route.title||'내 견적');}
        else agOriginalMain(route.name||'home',false);
        requestAnimationFrame(()=>requestAnimationFrame(()=>window.scrollTo(0,scroll)));
    }finally{agRestoringView=false;agRenderCompareTray();}
}
window.addEventListener('popstate',event=>agRestoreRoute(event.state?.ag_view||{kind:'main',name:'home'},Number(event.state?.ag_scroll||0)));
// Initialize after synchronous startup; deep-linked vehicle data restores when loadData completes.
const agOriginalLoadData=loadData;
loadData=async function(){await agOriginalLoadData();if(agHistoryReady&&(agCurrentRoute().kind==='vehicle'||agCurrentRoute().kind==='info'))await agRestoreRoute(agCurrentRoute());};
setTimeout(()=>{
    history.scrollRestoration='manual';
    const params=new URLSearchParams(location.hash.replace(/^#/,''));
    const route=history.state?.ag_view||(params.get('view')==='saved-quotes'?{kind:'saved-quotes'}:params.has('vehicle')?{kind:params.get('info')==='1'?'info':'vehicle',key:params.get('vehicle')}:{kind:'main',name:['vehicle','quick','faq','mypage'].includes(params.get('view'))?params.get('view'):'home'});
    history.replaceState({ag_view:route,ag_depth:0,ag_scroll:0},'',location.pathname+location.search+agRouteHash(route));agHistoryReady=true;agRestoreRoute(route);
},0);

document.addEventListener('keydown',event=>{
    if(event.key!=='Tab')return;
    const modal=document.querySelector('#vehicleCompareModal:not([hidden]) .vehicle-compare-dialog');
    if(!modal)return;
    const focusable=[...modal.querySelectorAll('button:not(:disabled),select,a[href],input')].filter(el=>el.getClientRects().length);
    if(!focusable.length)return;
    const first=focusable[0],last=focusable[focusable.length-1];
    if(event.shiftKey&&document.activeElement===first){last.focus();event.preventDefault();}
    else if(!event.shiftKey&&document.activeElement===last){first.focus();event.preventDefault();}
});
