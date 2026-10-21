document.addEventListener('DOMContentLoaded', function () {
    function initAutocompleteSelect(select) {
        if(select.dataset.uiAutocompleteReady==='1'||select.multiple||select.closest('.ui-combobox'))return;
        const entity=/^(anggota_id|pinjaman_id|ketua_kelompok_id|jenis_pinjaman_id|jenis_simpanan_id)$/i.test(select.name||'');
        if(!entity&&!select.classList.contains('ui-autocomplete')&&select.options.length<8)return;
        select.dataset.uiAutocompleteReady='1';
        const box=document.createElement('div');box.className='ui-combobox';
        const input=document.createElement('input');input.type='search';input.className='form-control ui-combobox-input';input.autocomplete='off';input.spellcheck=false;
        input.placeholder=select.dataset.searchPlaceholder||'Ketik untuk mencari...';input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');
        const toggle=document.createElement('button');toggle.type='button';toggle.className='ui-combobox-toggle';toggle.tabIndex=-1;toggle.setAttribute('aria-label','Tampilkan pilihan');toggle.innerHTML='<i class="fas fa-chevron-down" aria-hidden="true"></i>';
        const list=document.createElement('div');list.className='ui-combobox-list';list.setAttribute('role','listbox');list.hidden=true;
        const listId='ui-combobox-'+Math.random().toString(36).slice(2);list.id=listId;input.setAttribute('aria-controls',listId);
        const wasRequired=select.required;select.required=false;input.required=wasRequired;
        select.parentNode.insertBefore(box,select);box.append(input,toggle,list,select);select.classList.add('ui-combobox-native');select.setAttribute('aria-hidden','true');select.tabIndex=-1;let active=-1;
        function options(){return Array.from(select.options).filter(o=>!o.disabled);}
        function selectedLabel(){const o=select.selectedOptions[0];return o&&o.value?o.textContent.trim():'';}
        function close(){list.hidden=true;input.setAttribute('aria-expanded','false');active=-1;}
        function validate(){input.setCustomValidity(select.required&&!select.value?'Pilih salah satu data dari daftar.':'');}
        function choose(value){select.value=value;input.value=selectedLabel();validate();select.dispatchEvent(new Event('change',{bubbles:true}));close();}
        function render(term){const q=(term||'').trim().toLocaleLowerCase('id');const seen=new Set();const matches=options().filter(o=>(o.value||!q)&&o.textContent.toLocaleLowerCase('id').includes(q)).filter(o=>{if(seen.has(o.value))return false;seen.add(o.value);return true;}).slice(0,100);list.replaceChildren();active=-1;if(!matches.length){const empty=document.createElement('div');empty.className='ui-combobox-empty';empty.textContent='Data tidak ditemukan';list.append(empty);}matches.forEach(o=>{const item=document.createElement('button');item.type='button';item.className='ui-combobox-option';item.setAttribute('role','option');item.dataset.value=o.value;item.textContent=o.textContent.trim()||(o.value?'Pilihan':'Kosongkan pilihan');if(o.selected)item.setAttribute('aria-selected','true');item.addEventListener('mousedown',e=>e.preventDefault());item.addEventListener('click',()=>choose(o.value));list.append(item);});list.hidden=false;input.setAttribute('aria-expanded','true');}
        function move(step){const items=Array.from(list.querySelectorAll('.ui-combobox-option'));if(!items.length)return;active=(active+step+items.length)%items.length;items.forEach((el,i)=>el.classList.toggle('is-active',i===active));items[active].scrollIntoView({block:'nearest'});}
        input.value=selectedLabel();input.addEventListener('focus',()=>render(''));input.addEventListener('input',()=>{if(input.value!==selectedLabel())select.value='';validate();render(input.value);});input.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){e.preventDefault();if(list.hidden)render(input.value);move(1);}else if(e.key==='ArrowUp'){e.preventDefault();move(-1);}else if(e.key==='Enter'&&!list.hidden){const items=list.querySelectorAll('.ui-combobox-option');if(active>=0&&items[active]){e.preventDefault();choose(items[active].dataset.value);}}else if(e.key==='Escape'){close();input.value=selectedLabel();validate();}});
        input.addEventListener('blur',e=>{if(e.relatedTarget===toggle)return;setTimeout(()=>{if(document.activeElement!==toggle){close();input.value=selectedLabel();validate();}},120);});toggle.addEventListener('mousedown',e=>{e.preventDefault();if(list.hidden){input.focus();render('');}else close();});select.addEventListener('change',()=>{input.value=selectedLabel();validate();});
        const sync=()=>{input.disabled=select.disabled;input.required=select.required;box.classList.toggle('is-disabled',select.disabled);input.value=selectedLabel();validate();};new MutationObserver(sync).observe(select,{childList:true,subtree:true,attributes:true});sync();
    }
    document.querySelectorAll('main select,.modal select').forEach(initAutocompleteSelect);

    // Native validation pada input autocomplete sering hanya menampilkan tooltip
    // kecil yang tidak terlihat. Tampilkan alasan yang jelas dan fokus ke fieldnya.
    document.querySelectorAll('form').forEach(form=>{
        form.addEventListener('invalid',e=>{
            const field=e.target;
            if(!field||form.dataset.uiInvalidShown==='1')return;
            form.dataset.uiInvalidShown='1';
            field.dataset.uiInvalidShown='1';
            setTimeout(()=>{delete field.dataset.uiInvalidShown;form.dataset.uiInvalidShown='';},400);
            const label=field.closest('.mb-3,.col-md-6,.col-md-4,.col-12')?.querySelector('label')?.textContent?.trim() || field.getAttribute('name') || 'Field';
            const message=field.validity.valueMissing?' wajib diisi.':' belum valid.';
            alert(label.replace(/\\s+/g,' ')+message);
            field.focus();
        },true);
    });

    const actionRules=[['danger',/(hapus|delete|tolak|batalkan|koreksi)/],['whatsapp',/(whatsapp|\bwa\b|tagih)/],['export',/(excel|csv|export)/],['print',/(cetak|print|pdf|kwitansi|surat|bast)/],['edit',/(edit|ubah|pengaturan)/],['view',/(lihat|detail|preview|foto|dokumen)/],['pay',/(bayar|pelunasan|cairkan|setujui|approve)/],['muted',/(reset|batal|tutup|kembali|hapus tanda tangan)/],['primary',/(simpan|tambah|buat|proses|kirim|cari|filter|terapkan|login)/]];
    function classifyButton(button){if(button.classList.contains('badge')||button.classList.contains('btn-close')||button.classList.contains('ui-combobox-toggle'))return;const label=((button.getAttribute('aria-label')||'')+' '+(button.title||'')+' '+button.textContent).trim().toLocaleLowerCase('id');let type=label.includes('hapus tanda tangan')?'muted':'';for(const rule of actionRules)if(!type&&rule[1].test(label)){type=rule[0];break;}if(!type){const icon=button.querySelector('i')?.className||'';if(/trash/.test(icon))type='danger';else if(/edit|pen/.test(icon))type='edit';else if(/eye|camera|image/.test(icon))type='view';else if(/print|file-pdf/.test(icon))type='print';else if(/whatsapp/.test(icon))type='whatsapp';else if(/file-excel|download/.test(icon))type='export';}if(type)button.classList.add('ui-action','ui-action-'+type);}
    document.querySelectorAll('main .btn,.modal .btn').forEach(classifyButton);
    const sidebar = document.getElementById('sidebarMenu');
    const toggle = document.querySelector('.ui-menu-button');
    const small = window.matchMedia('(max-width: 1023.98px)');
    let overlay;
    if (sidebar && toggle) {
        overlay = document.createElement('div');overlay.className='sidebar-overlay';overlay.setAttribute('aria-hidden','true');document.body.appendChild(overlay);
        const main=document.querySelector('main');
        let desktopCollapsed=false;
        try{desktopCollapsed=sessionStorage.getItem('ui-sidebar-collapsed')==='true';}catch(e){}
        function setOpen(open,restore) {
            if(!small.matches){
                sidebar.classList.remove('show');overlay.classList.remove('show');document.body.classList.remove('ui-sidebar-open');
                document.body.classList.toggle('ui-sidebar-collapsed',desktopCollapsed);sidebar.inert=desktopCollapsed;if(main)main.inert=false;
                toggle.setAttribute('aria-expanded',String(!desktopCollapsed));toggle.setAttribute('aria-label',desktopCollapsed?'Buka navigasi':'Tutup navigasi');
            }else{
                document.body.classList.remove('ui-sidebar-collapsed');open=Boolean(open);
                sidebar.classList.toggle('show',open);overlay.classList.toggle('show',open);document.body.classList.toggle('ui-sidebar-open',open);
                toggle.setAttribute('aria-expanded',String(open));toggle.setAttribute('aria-label',open?'Tutup navigasi':'Buka navigasi');sidebar.inert=!open;if(main)main.inert=open;
            }
            if(restore)toggle.focus();
        }
        toggle.addEventListener('click',function(){if(small.matches)setOpen(!sidebar.classList.contains('show'));else{desktopCollapsed=!desktopCollapsed;try{sessionStorage.setItem('ui-sidebar-collapsed',String(desktopCollapsed));}catch(e){}setOpen(false);}});
        overlay.addEventListener('click',function(){setOpen(false,true);});
        document.addEventListener('keydown',function(e){
            if(e.key==='Escape' && sidebar.classList.contains('show')){setOpen(false,true);return;}
            if(e.key!=='Tab'||!small.matches||!sidebar.classList.contains('show'))return;
            const items=[toggle,...sidebar.querySelectorAll('a[href],button,input')].filter(el=>el.getClientRects().length && !el.disabled);
            const first=items[0],last=items[items.length-1];
            if(e.shiftKey && document.activeElement===first){e.preventDefault();last.focus();}
            else if(!e.shiftKey && document.activeElement===last){e.preventDefault();first.focus();}
        });
        small.addEventListener('change',()=>setOpen(false));setOpen(false);
        sidebar.querySelectorAll('a[href]').forEach(a=>a.addEventListener('click',function(){if(!a.hasAttribute('data-bs-toggle') && !a.getAttribute('href').startsWith('#'))setOpen(false);}));
        const current=sidebar.querySelector('a.active');if(current)current.setAttribute('aria-current','page');
    }
    const account=document.querySelector('.ui-account');
    if(account){document.addEventListener('click',e=>{if(!account.contains(e.target))account.open=false;});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&account.open){account.open=false;account.querySelector('summary').focus();}});}
    const notifications=document.querySelector('.ui-notifications');
    if(notifications){document.addEventListener('click',e=>{if(!notifications.contains(e.target))notifications.open=false;});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&notifications.open){notifications.open=false;notifications.querySelector('summary').focus();}});}
    const main=document.querySelector('main');
    if(main){main.id=main.id || 'konten-utama';main.setAttribute('tabindex','-1');document.querySelector('.ui-skip')?.setAttribute('href','#'+main.id);}
    document.querySelectorAll('main .row').forEach(row=>{if(Array.from(row.children).some(col=>Array.from(col.children).some(card=>card.classList.contains('stat-card')))){row.classList.add('ui-stat-grid');row.dataset.count=row.children.length;}});
    // Keep primary values intact; allow only descriptive columns to wrap.
    document.querySelectorAll('main table.table').forEach(table=>{
        table.classList.add('ui-data-table');
        const head=table.tHead?.rows[table.tHead.rows.length-1];
        const columns=[];let column=0;
        if(head)Array.from(head.cells).forEach(th=>{
            const label=th.textContent.trim();
            const action=/^(aksi|action|actions|tindakan)$/i.test(label);
            const prose=!action && /alamat|catatan|keterangan|deskripsi|rincian/i.test(label);
            for(let i=0;i<th.colSpan;i++)columns[column+i]={action,prose};
            if(action)th.classList.add('ui-actions-heading');column+=th.colSpan;
        });
        function normalizeRow(row){
            let index=0;
            Array.from(row.cells).forEach(cell=>{
                const info=columns[index] || {};index+=cell.colSpan;
                if(cell.colSpan!==1)return;
                if(info.prose)cell.classList.add('ui-cell-prose');
                // A manual line break inside a badge is presentation, not a second field.
                cell.querySelectorAll('.badge br').forEach(br=>br.replaceWith(document.createTextNode(' ')));
                if(!info.action && !cell.classList.contains('table-actions') && !cell.classList.contains('ui-actions-cell'))return;
                cell.classList.add('ui-actions-cell');
                if(cell.querySelector(':scope > .ui-table-actions'))return;
                const group=document.createElement('div');group.className='ui-table-actions';
                Array.from(cell.childNodes).filter(node=>!(node.nodeType===1 && node.classList.contains('modal'))).forEach(node=>group.appendChild(node));cell.appendChild(group);
                group.querySelectorAll('a.btn,button.btn').forEach(button=>{classifyButton(button);const label=button.getAttribute('aria-label') || button.title || button.textContent.trim();if(label && !button.title)button.title=label;});
            });
        }
        Array.from(table.tBodies).forEach(body=>{
            Array.from(body.rows).forEach(normalizeRow);
            new MutationObserver(records=>{
                const rows=new Set();records.forEach(record=>Array.from(record.addedNodes).forEach(node=>{
                    if(node.nodeType!==1)return;
                    if(node.matches('tr'))rows.add(node);
                    else if(node.matches('td,th') && node.parentElement?.matches('tr'))rows.add(node.parentElement);
                }));rows.forEach(normalizeRow);
            }).observe(body,{childList:true,subtree:true});
        });
    });
    document.querySelectorAll('.shu-allocation-fields').forEach(group=>{
        const fields=Array.from(group.querySelectorAll('input[type="number"]'));
        const status=document.createElement('p');status.className='small mt-2 mb-3';status.setAttribute('role','status');status.setAttribute('aria-live','polite');group.after(status);
        function update(){const complete=fields.every(field=>field.value.trim()!=='');const sum=fields.reduce((total,field)=>total+Math.round((Number(field.value)||0)*100),0);const valid=complete&&sum===10000;status.classList.toggle('text-success',valid);status.classList.toggle('text-danger',complete&&!valid);status.classList.toggle('text-muted',!complete);status.textContent='Total alokasi: '+(sum/100).toLocaleString('id-ID',{maximumFractionDigits:2})+'% / 100%'+(complete?(valid?' - sudah sesuai.':' - sesuaikan persentase.'): ' - isi seluruh pos, termasuk yang bernilai 0.');if(fields[0])fields[0].setCustomValidity(complete&&!valid?'Total persentase alokasi harus tepat 100%.':'');}
        fields.forEach(field=>field.addEventListener('input',update));update();
    });
    const scrollers=[];
    document.querySelectorAll('table.table').forEach(function(table){
        let wrapper=table.closest('.table-responsive,.table-responsive-sm,.table-responsive-md,.table-responsive-lg,.table-responsive-xl,.table-responsive-xxl,.ui-table-scroll');
        if(!wrapper){wrapper=document.createElement('div');wrapper.className='ui-table-scroll';table.parentNode.insertBefore(wrapper,table);wrapper.appendChild(table);}
        wrapper.classList.add('ui-table-scroll');
        if(scrollers.some(s=>s.wrapper===wrapper))return;
        const hint=document.createElement('div');hint.className='ui-scroll-hint';hint.textContent='Geser tabel ke samping untuk melihat kolom lainnya.';wrapper.insertAdjacentElement('afterend',hint);
        const tbody=table.tBodies[0];
        if(tbody && !tbody.rows.length){
            const row=tbody.insertRow();row.className='ui-empty-row';
            const cell=row.insertCell();cell.colSpan=Math.max(1,table.tHead?.rows[0] ? Array.from(table.tHead.rows[0].cells).reduce((n,c)=>n+c.colSpan,0) : 1);
            const text=document.createElement('div');text.className='ui-empty-table';text.textContent=table.dataset.emptyMessage || 'Belum ada data untuk ditampilkan.';cell.appendChild(text);
            const observer=new MutationObserver(()=>{if(Array.from(tbody.rows).some(r=>r!==row)){row.remove();observer.disconnect();}});observer.observe(tbody,{childList:true});
        }
        if(tbody && tbody.rows.length===1 && table.tHead?.rows[0]){
            const only=tbody.rows[0];const cols=Array.from(table.tHead.rows[0].cells).reduce((n,c)=>n+c.colSpan,0);
            if(only.cells.length===1 && cols>1 && only.cells[0].colSpan>=cols)only.classList.add('ui-placeholder-row');
        }
        wrapper.setAttribute('role','region');wrapper.setAttribute('aria-label',table.caption?.textContent || table.closest('.card')?.querySelector('h2,h3,h5')?.textContent || 'Tabel data, dapat digeser ke samping');
        const update=()=>{const overflow=wrapper.scrollWidth>wrapper.clientWidth+2;hint.classList.toggle('is-visible',overflow);if(overflow)wrapper.setAttribute('tabindex','0');else wrapper.removeAttribute('tabindex');};
        scrollers.push({wrapper,update});const resize=new ResizeObserver(update);resize.observe(wrapper);resize.observe(table);update();
    });
    document.querySelectorAll('button[data-bs-toggle="modal"],a[data-bs-toggle="modal"]').forEach(function(button){
        if(!button.textContent.trim()&&!button.hasAttribute('aria-label'))button.setAttribute('aria-label',button.title || 'Buka detail');
    });
    // Icon actions need an understandable name outside the visual icon font.
    document.querySelectorAll('main button,main a.btn').forEach(button=>{
        if(button.textContent.trim() || button.hasAttribute('aria-label'))return;
        const icon=button.querySelector('i');
        const names=[['fa-search','Cari'],['fa-file-excel','Export Excel'],['fa-file-pdf','Download PDF'],['fa-print','Cetak'],['fa-eye','Lihat detail'],['fa-edit','Ubah data'],['fa-trash','Hapus data'],['fa-sync','Muat ulang']];
        const match=names.find(([cls])=>icon?.classList.contains(cls));
        const label=button.title || match?.[1];if(label){button.setAttribute('aria-label',label);button.title=label;}
    });
    // VisualViewport follows the space left by mobile browser bars and keyboards.
    const viewport = window.visualViewport;
    let modalViewportFrame = 0;
    function updateModalViewport() {
        cancelAnimationFrame(modalViewportFrame);
        modalViewportFrame = requestAnimationFrame(function () {
            const height = viewport ? viewport.height : window.innerHeight;
            const top = viewport ? viewport.offsetTop : 0;
            document.body.style.setProperty('--ui-modal-height', height + 'px');
            document.body.style.setProperty('--ui-modal-top', top + 'px');
            document.body.classList.toggle('ui-modal-short', height <= 360);
            const focused = document.activeElement;
            if (focused && focused.matches('input,select,textarea') && focused.closest('.modal.show')) {
                focused.scrollIntoView({block:'nearest', inline:'nearest'});
            }
        });
    }
    window.addEventListener('resize', updateModalViewport);
    if (viewport) {
        viewport.addEventListener('resize', updateModalViewport);
        viewport.addEventListener('scroll', updateModalViewport);
    }
    document.addEventListener('shown.bs.modal', updateModalViewport);
    updateModalViewport();
    document.querySelectorAll('.modal-dialog:not(.modal-fullscreen)').forEach(el=>el.classList.add('modal-dialog-scrollable'));
    // Ulangi scroll anchor setelah panel dinamis selesai mengubah tinggi halaman.
    function scrollToCurrentHash() {
        if (!location.hash) return;
        let target;
        try { target=document.querySelector(location.hash); } catch (_) { return; }
        if (target) target.scrollIntoView({block:'start',behavior:'auto'});
    }
    if (location.hash) {
        history.scrollRestoration='manual';
        requestAnimationFrame(scrollToCurrentHash);
        setTimeout(scrollToCurrentHash,120);
        setTimeout(scrollToCurrentHash,500);
    }
    window.addEventListener('pageshow',scrollToCurrentHash);
    window.addEventListener('hashchange',scrollToCurrentHash);
});
