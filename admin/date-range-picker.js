document.addEventListener('DOMContentLoaded', () => {
    const iso = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    const parse = value => { const [y,m,d] = value.split('-').map(Number); return new Date(y,m-1,d); };
    const display = value => value.replaceAll('-', '.');
    document.querySelectorAll('[data-date-range]').forEach(host => {
        const from = host.querySelector('[name="from"]');
        const to = host.querySelector('[name="to"]');
        if (!from || !to) return;
        [from,to,...host.querySelectorAll('[data-range-separator]')].forEach(el => { el.hidden = true; });
        const field = document.createElement('div');
        field.className = 'dr-field';
        field.innerHTML = '<button type="button" class="dr-trigger" aria-haspopup="dialog" aria-expanded="false"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18m-14 4h2m3 0h2m3 0h2M7 18h2m3 0h2"/></svg><span></span></button><button type="button" class="dr-clear" aria-label="조회기간 초기화">×</button>';
        host.append(field);
        const trigger = field.querySelector('.dr-trigger');
        const clear = field.querySelector('.dr-clear');
        const refresh = () => {
            const text = from.value || to.value ? `${from.value ? display(from.value) : '시작일'} - ${to.value ? display(to.value) : '종료일'}` : '전체 기간';
            trigger.querySelector('span').textContent = text;
            trigger.setAttribute('aria-label', `${host.dataset.label || '조회기간'}: ${text}`);
            clear.hidden = !from.value && !to.value;
        };
        const commit = (a,b) => { from.value=a; to.value=b; from.dispatchEvent(new Event('change',{bubbles:true})); to.dispatchEvent(new Event('change',{bubbles:true})); refresh(); };
        clear.addEventListener('click', () => commit('',''));
        const dialog = document.createElement('dialog');
        dialog.className = 'dr-dialog';
        dialog.setAttribute('aria-label', `${host.dataset.label || '조회기간'} 선택`);
        dialog.innerHTML = '<div class="dr-navigation"><button type="button" data-prev aria-label="이전 달">‹</button><div><select data-year aria-label="연도"></select><select data-month aria-label="월"></select></div><button type="button" data-next aria-label="다음 달">›</button></div><div class="dr-months"></div><div class="dr-footer"><span class="dr-selection" aria-live="polite"></span><div><button type="button" data-cancel>취소</button><button type="button" data-apply>적용</button></div></div>';
        document.body.append(dialog);
        let start='', end='', month;
        const months = dialog.querySelector('.dr-months');
        const yearSelect = dialog.querySelector('[data-year]');
        const monthSelect = dialog.querySelector('[data-month]');
        for (let m=0;m<12;m++) monthSelect.add(new Option(`${m+1}월`,m));
        const position = () => {
            const box = trigger.getBoundingClientRect();
            const width = Math.min(620,window.innerWidth-24);
            dialog.style.width = `${width}px`;
            dialog.style.left = `${Math.max(12,Math.min(box.left,window.innerWidth-width-12))}px`;
            dialog.style.top = `${Math.max(12,Math.min(box.bottom+6,window.innerHeight-dialog.offsetHeight-12))}px`;
        };
        const render = () => {
            yearSelect.replaceChildren();
            const year = month.getFullYear();
            for (let y=Math.min(1970,year);y<=Math.max(new Date().getFullYear()+10,year);y++) yearSelect.add(new Option(`${y}년`,y));
            yearSelect.value=year; monthSelect.value=month.getMonth();
            months.replaceChildren();
            for(let offset=0;offset<2;offset++) {
                const first=new Date(year,month.getMonth()+offset,1);
                const panel=document.createElement('section');
                panel.innerHTML=`<h3>${first.getFullYear()}년 ${first.getMonth()+1}월</h3><div class="dr-week">${['일','월','화','수','목','금','토'].map(day=>`<span>${day}</span>`).join('')}</div><div class="dr-days"></div>`;
                const grid=panel.querySelector('.dr-days');
                for(let i=0;i<first.getDay();i++) grid.append(document.createElement('span'));
                const last=new Date(first.getFullYear(),first.getMonth()+1,0).getDate();
                for(let day=1;day<=last;day++) {
                    const value=iso(new Date(first.getFullYear(),first.getMonth(),day));
                    const button=document.createElement('button'); button.type='button'; button.textContent=day; button.dataset.date=value;
                    button.setAttribute('aria-label',display(value));
                    button.setAttribute('aria-pressed',String(value===start || value===end));
                    if(value===iso(new Date())) button.setAttribute('aria-current','date');
                    if(start && end && value>start && value<end) button.classList.add('in-range');
                    if(value===start || value===end) button.classList.add('selected');
                    button.addEventListener('click',()=>{
                        if(!start || end) {start=value;end='';} else {end=value;if(end<start) [start,end]=[end,start];}
                        render(); months.querySelector(`[data-date="${value}"]`)?.focus();
                    });
                    grid.append(button);
                }
                months.append(panel);
            }
            dialog.querySelector('.dr-selection').textContent=start ? (end ? `${display(start)} - ${display(end)}` : `${display(start)} · 종료일 선택`) : '시작일을 선택하세요';
            dialog.querySelector('[data-apply]').disabled=!start || !end;
            if(dialog.open) position();
        };
        trigger.addEventListener('click',()=>{
            start=from.value;end=to.value;
            const anchor=start ? parse(start) : new Date(); month=new Date(anchor.getFullYear(),anchor.getMonth(),1);
            render();dialog.showModal();trigger.setAttribute('aria-expanded','true');position();
        });
        dialog.querySelector('[data-prev]').addEventListener('click',()=>{month.setMonth(month.getMonth()-1);render();});
        dialog.querySelector('[data-next]').addEventListener('click',()=>{month.setMonth(month.getMonth()+1);render();});
        const jump=()=>{month=new Date(Number(yearSelect.value),Number(monthSelect.value),1);render();};
        yearSelect.addEventListener('change',jump);monthSelect.addEventListener('change',jump);
        dialog.querySelector('[data-cancel]').addEventListener('click',()=>dialog.close());
        dialog.querySelector('[data-apply]').addEventListener('click',()=>{commit(start,end);dialog.close();});
        dialog.addEventListener('close',()=>{trigger.setAttribute('aria-expanded','false');trigger.focus();});
        dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();}});
        window.addEventListener('resize',()=>{if(dialog.open)position();});
        host.closest('form')?.addEventListener('reset',()=>setTimeout(refresh,0));
        refresh();
    });
});
