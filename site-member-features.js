/* Saved selections and profile editing. Submitted consultation records remain unchanged. */
let savedQuoteItems = [];
let savedQuoteOwner = '';
let savedQuoteCatalogReady = false;
const GUEST_SAVED_QUOTES = 'autogenie_saved_quote_selections_v1';

function savedSelectionKey(selection) {
    return ['vehicle_id','trim_id','color_id','product_type','contract_months','prepayment_rate','annual_mileage'].map(key=>String(selection[key])).join('|');
}
function currentQuoteSelection(key) {
    const quote=quoteStates.get(String(key));if(!quote?.trim)return null;
    const colors=document.querySelector(`[data-color-select="${CSS.escape(String(key))}"]`);
    return {vehicle_id:Number(key),trim_id:Number(quote.trimId),color_id:Number(colors?.dataset.selectedColorId||0),product_type:quote.product,contract_months:Number(quote.months),prepayment_rate:Number(quote.prepayment),annual_mileage:Number(quote.mileage)};
}
function syncSavedQuoteButtons(key) {
    document.querySelectorAll('.save-snapshot-button[data-quote-key]').forEach(button=>{
        if(key!==undefined&&button.dataset.quoteKey!==String(key))return;
        const selection=currentQuoteSelection(button.dataset.quoteKey);
        const isSaved=Boolean(selection&&savedQuoteItems.some(item=>savedSelectionKey(item.selection)===savedSelectionKey(selection)));
        button.textContent=isSaved?'저장 취소':'조건 저장';
        button.setAttribute('aria-pressed',String(isSaved));
    });
}
function guestSavedQuotes() {
    try {
        const items=JSON.parse(localStorage.getItem(GUEST_SAVED_QUOTES)||'[]');
        return Array.isArray(items)?items.filter(item=>item?.selection&&item?.labels).slice(0,30):[];
    } catch (_) { return []; }
}
async function savedQuoteRequest(data) {
    const response=await agFetch('./api/member-saved-quotes.php',data?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{credentials:'same-origin',cache:'no-store'});
    const result=await response.json();
    if (!response.ok||!result.ok) throw new Error(result.message||'저장한 견적을 불러오지 못했습니다.');
    return result.items;
}
function savedQuoteCount() {
    const node=document.getElementById('mypageSavedQuoteCount');if(node)node.textContent=savedQuoteItems.length+'개';
}
async function refreshSavedQuoteCount() {
    const owner=String(currentMember?.id||'guest');savedQuoteOwner=owner;savedQuoteItems=[];savedQuoteCount();
    try {
        const items=currentMember?await savedQuoteRequest():guestSavedQuotes();
        if(String(currentMember?.id||'guest')!==owner)return;
        savedQuoteItems=items;savedQuoteCount();
        syncSavedQuoteButtons();
        if(!document.getElementById('savedQuotesView')?.hidden)renderSavedQuotes();
    } catch (_) { /* Opening the list surfaces the error and retry action. */ }
}
function savedQuoteNotice(message, error=false) {
    const node=document.getElementById('savedQuoteNotice');if(!node)return;
    node.textContent=message;node.hidden=!message;node.setAttribute('role',error?'alert':'status');
}
async function saveCurrentQuote(key,button) {
    if(button.disabled)return;
    const quote=quoteStates.get(String(key));
    if(!quote?.trim){savedQuoteNotice('트림을 먼저 선택해 주세요.',true);return;}
    const colors=document.querySelector(`[data-color-select="${CSS.escape(String(key))}"]`);
    const selection=currentQuoteSelection(key);
    const labels={vehicle_name:getVehicleName(quote.vehicle),brand_name:getBrandName(quote.vehicle),trim_name:quote.trim.name,color_name:colors?.dataset.selectedColorName||'미선택'};
    const owner=String(currentMember?.id||'guest'),selectionKey=savedSelectionKey(selection),existing=savedQuoteItems.find(item=>savedSelectionKey(item.selection)===selectionKey);button.disabled=true;
    try {
        let items;
        if(existing&&currentMember)items=await savedQuoteRequest({action:'delete',id:existing.id});
        else if(existing){items=guestSavedQuotes().filter(item=>String(item.id)!==String(existing.id));localStorage.setItem(GUEST_SAVED_QUOTES,JSON.stringify(items));}
        else if(currentMember)items=await savedQuoteRequest({action:'save',...selection});
        else {
            const currentItems=guestSavedQuotes().filter(item=>savedSelectionKey(item.selection)!==selectionKey);
            if(currentItems.length>=30)throw new Error('견적은 최대 30개까지 저장할 수 있습니다. 기존 항목을 삭제해 주세요.');
            items=[{id:'local_'+(globalThis.crypto?.randomUUID?crypto.randomUUID():Date.now().toString(36)+'_'+Math.random().toString(36).slice(2)),selection,labels,updated_at:new Date().toISOString()},...currentItems];
            localStorage.setItem(GUEST_SAVED_QUOTES,JSON.stringify(items));
        }
        if(String(currentMember?.id||'guest')!==owner)return;
        savedQuoteOwner=owner;savedQuoteItems=items;savedQuoteCount();syncSavedQuoteButtons(key);
        savedQuoteNotice(existing?'저장한 견적 조건을 취소했습니다.':'마이페이지 > 저장한 견적에서 확인할 수 있습니다.');
    } catch(error){savedQuoteNotice(error.message||'견적 조건을 저장하지 못했습니다.',true);}
    finally{button.disabled=false;}
}
async function refreshSavedQuoteCatalog() {
    savedQuoteCatalogReady=false;
    const response=await agFetch(API_URL,{credentials:'same-origin',cache:'no-store'});const data=await response.json();
    if(!response.ok||data.success===false)throw new Error('현재 차량 정보를 확인하지 못했습니다. 다시 시도해 주세요.');
    allVehicles=normalizeData(data);savedQuoteCatalogReady=true;
}
function resolveSavedQuote(item) {
    const s=item.selection,v=allVehicles.find(v=>String(v.vehicle_id??v.id)===String(s.vehicle_id));
    const trim=v?.trims?.find(t=>String(t.id)===String(s.trim_id));
    const price=trim?.prices?.find(p=>String(p.product_type)===s.product_type&&Number(p.contract_months)===Number(s.contract_months)&&Number(p.prepayment_rate||0)===Number(s.prepayment_rate)&&Number(p.annual_mileage||0)===Number(s.annual_mileage)&&Number(p.monthly_payment)>0);
    return {vehicle:v,trim,price};
}
function renderSavedQuotes() {
    const list=document.getElementById('savedQuotesList');if(!list)return;
    document.getElementById('savedQuoteStorageNote').textContent=currentMember?'이 계정에 저장한 견적입니다. 다시 열면 현재 등록된 가격을 확인합니다.':'로그인하지 않고 저장한 견적은 이 브라우저에만 보관됩니다. 브라우저 데이터를 지우면 삭제됩니다.';
    if(!savedQuoteItems.length){list.innerHTML=mypageEmptyCard('estimate','저장한 견적이 없습니다','차량 상세에서 조건 저장을 눌러 보관해 보세요.','vehicle','차량 보러가기');return;}
    list.innerHTML=savedQuoteItems.map(item=>{
        const s=item.selection,r=resolveSavedQuote(item),labels=item.labels||{},ready=savedQuoteCatalogReady&&r.vehicle&&r.trim;
        const title=r.vehicle?getVehicleName(r.vehicle):labels.vehicle_name;
        return `<article class="saved-quote-card"><div class="saved-quote-card-head"><strong>${escapeHtml(title||'차량 정보 없음')}</strong><button type="button" class="saved-quote-delete" data-delete-saved="${escapeHtml(String(item.id))}" aria-label="${escapeHtml(title||'견적')} 저장 삭제">삭제</button></div><p>${escapeHtml(r.trim?.name||labels.trim_name||'트림 미등록')} · ${escapeHtml(labels.color_name||'미선택')}</p><p>${escapeHtml(productLabel(s.product_type))} · ${escapeHtml(yearsFromMonths(s.contract_months))} · 선납 ${escapeHtml(String(s.prepayment_rate))}% · 연 ${Number(s.annual_mileage)===0?'무제한':Number(s.annual_mileage).toLocaleString('ko-KR')+'km'}</p><div class="saved-quote-card-foot"><span>${!savedQuoteCatalogReady?'가격 확인 필요':!ready?'현재 조회 불가':r.price?money(r.price.monthly_payment)+' / 월':'가격 등록 대기'}</span><button type="button" class="saved-quote-open" data-open-saved="${escapeHtml(String(item.id))}" ${ready?'':'disabled'}>이어보기</button></div></article>`;
    }).join('');
}
async function openSavedQuotes() {
    agRememberCurrentScroll();openMainView('mypage',false);
    document.querySelectorAll('.page > .view-section').forEach(section=>section.hidden=true);
    document.getElementById('savedQuotesView').hidden=false;setBottomNavActive('mypage');resetPageScroll();agCommitRoute({kind:'saved-quotes'});
    const list=document.getElementById('savedQuotesList');list.innerHTML='<div class="my-estimates-empty">저장한 견적을 불러오는 중입니다.</div>';
    const owner=String(currentMember?.id||'guest');
    try {
        const items=currentMember?await savedQuoteRequest():guestSavedQuotes();
        await refreshSavedQuoteCatalog();
        if(String(currentMember?.id||'guest')!==owner)return;
        savedQuoteOwner=owner;savedQuoteItems=items;savedQuoteCount();syncSavedQuoteButtons();renderSavedQuotes();
    } catch(error){list.innerHTML='<div class="my-estimates-empty"></div>';list.firstElementChild.textContent=error.message;const button=document.createElement('button');button.type='button';button.className='saved-quote-open';button.textContent='다시 시도';button.onclick=openSavedQuotes;list.firstElementChild.append(document.createElement('br'),button);}
}
async function reopenSavedQuote(item,button) {
    const owner=String(currentMember?.id||'guest');button.disabled=true;
    try {
        await refreshSavedQuoteCatalog();if(String(currentMember?.id||'guest')!==owner)return;
        const {vehicle,trim,price}=resolveSavedQuote(item),s=item.selection;
        if(!vehicle||!trim)throw new Error('저장한 차량 또는 트림을 현재 조회할 수 없습니다.');
        openVehicleDetail(String(s.vehicle_id));
        renderVehicleDetail(vehicle,allVehicles.indexOf(vehicle),{trim,price:price||{product_type:s.product_type,contract_months:s.contract_months,prepayment_rate:s.prepayment_rate,annual_mileage:s.annual_mileage},preserveUnavailable:!price});
        const color=document.querySelector(`[data-color-select="${CSS.escape(String(s.vehicle_id))}"] [data-color-id="${CSS.escape(String(s.color_id))}"]`);
        if(color)selectVehicleColor(String(s.vehicle_id),color);
        if(Number(s.color_id)>0&&!color)savedQuoteNotice('저장한 색상은 현재 선택할 수 없습니다. 색상을 다시 확인해 주세요.',true);
        else if(!price)savedQuoteNotice('저장한 조건의 가격이 아직 없거나 변경되었습니다. 조건 확인 후 상담을 신청해 주세요.');
    } catch(error){alert(error.message);}finally{button.disabled=false;}
}
document.getElementById('savedQuotesList')?.addEventListener('click',async event=>{
    const open=event.target.closest('[data-open-saved]'),remove=event.target.closest('[data-delete-saved]');
    const button=open||remove;if(!button||button.disabled)return;
    const item=savedQuoteItems.find(item=>String(item.id)===String(open?open.dataset.openSaved:remove.dataset.deleteSaved));if(!item)return;
    if(open){await reopenSavedQuote(item,button);return;}
    if(!confirm('저장한 견적 조건을 삭제할까요?'))return;
    const owner=String(currentMember?.id||'guest');button.disabled=true;
    try{
        const items=currentMember?await savedQuoteRequest({action:'delete',id:item.id}):guestSavedQuotes().filter(row=>row.id!==item.id);
        if(!currentMember)localStorage.setItem(GUEST_SAVED_QUOTES,JSON.stringify(items));
        if(String(currentMember?.id||'guest')!==owner)return;
        savedQuoteItems=items;savedQuoteCount();syncSavedQuoteButtons();renderSavedQuotes();
    }catch(error){alert(error.message);button.disabled=false;}
});

let profileBusy=false;
function resetProfileForm() {
    const form=document.getElementById('memberProfileForm');if(!form)return;
    form.elements.name.value=currentMember?.name||'';form.elements.phone.value=currentMember?.phone||'';
    document.getElementById('memberProfileCode').value='';document.getElementById('memberProfileCodeField').hidden=true;
    document.getElementById('memberProfileVerifyState').textContent='변경할 번호로 문자 인증을 완료해 주세요.';
    setMemberMessage('memberProfileMessage');
}
async function profileAction(action) {
    if(profileBusy)return;
    const form=document.getElementById('memberProfileForm');if(!currentMember)return;
    if(action==='save'&&!form.reportValidity())return;
    const owner=String(currentMember.id),submittedName=form.elements.name.value.trim(),submittedPhone=formatEstimatePhone(form.elements.phone.value);
    profileBusy=true;form.querySelectorAll('button').forEach(button=>button.disabled=true);
    try{
        const response=await agFetch('./api/member-profile.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,name:submittedName,phone:submittedPhone,code:document.getElementById('memberProfileCode').value.trim()})});
        const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'회원 정보 요청에 실패했습니다.');
        if(String(currentMember?.id)!==owner)return;
        if(action==='request_code'){
            document.getElementById('memberProfileCodeField').hidden=false;
            document.getElementById('memberProfileVerifyState').textContent='문자로 받은 인증번호를 입력해 주세요. (10분 유효)';
        }
        if(action==='confirm_code')document.getElementById('memberProfileVerifyState').textContent=result.message;
        if(action==='save'){
            updateMemberUI(result.member);document.getElementById('memberInfoName').textContent=result.member.name;
            document.getElementById('memberInfoPhone').textContent=result.member.phone||'-';
            // Preserve any newer edits made while the request was in flight.
            if(form.elements.name.value.trim()===submittedName&&formatEstimatePhone(form.elements.phone.value)===submittedPhone)resetProfileForm();
        }
        setMemberMessage('memberProfileMessage',result.message,'ok');
    }catch(error){setMemberMessage('memberProfileMessage',error.message);}
    finally{profileBusy=false;form.querySelectorAll('button').forEach(button=>button.disabled=false);}
}
document.getElementById('memberProfileForm')?.addEventListener('submit',event=>{event.preventDefault();profileAction('save');});
document.getElementById('memberProfileRequestCode')?.addEventListener('click',()=>profileAction('request_code'));
document.getElementById('memberProfileVerifyCode')?.addEventListener('click',()=>profileAction('confirm_code'));
document.getElementById('memberProfilePhone')?.addEventListener('input',event=>{
    event.target.value=formatEstimatePhone(event.target.value);
    document.getElementById('memberProfileCode').value='';document.getElementById('memberProfileVerifyState').textContent='번호를 변경했다면 새 번호로 인증해 주세요.';
});
refreshSavedQuoteCount();

window.dispatchEvent(new Event('ag-member-features-ready'));
