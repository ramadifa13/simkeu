document.addEventListener('DOMContentLoaded', function () {
    if (window.__simkeuAppUiReady) return;
    window.__simkeuAppUiReady = true;

    // Calm toast feedback from flash sources / legacy alerts
    (function initToasts(){
        let stack=document.querySelector('.ui-toast-stack');
        if(!stack){stack=document.createElement('div');stack.className='ui-toast-stack';stack.setAttribute('aria-live','polite');stack.setAttribute('aria-relevant','additions');document.body.appendChild(stack);}
        function iconFor(type){return type==='danger'?'fa-exclamation-circle':(type==='warning'?'fa-exclamation-triangle':'fa-check-circle');}
        function showToast(type,message,opts){
            if(!message)return;
            const toast=document.createElement('div');
            toast.className='ui-toast ui-toast-'+(type||'success');
            toast.setAttribute('role','status');
            const link=opts&&opts.href?('<div class="mt-1"><a href="'+opts.href+'">'+(opts.linkLabel||'Lihat detail')+'</a></div>'):'';
            toast.innerHTML='<span class="ui-toast-icon" aria-hidden="true"><i class="fas '+iconFor(type)+'"></i></span><div class="ui-toast-body"><div>'+message+'</div>'+link+'</div><button type="button" class="ui-toast-close" aria-label="Tutup"><i class="fas fa-times" aria-hidden="true"></i></button>';
            const close=()=>{toast.classList.add('is-leaving');setTimeout(()=>toast.remove(),180);};
            toast.querySelector('.ui-toast-close').addEventListener('click',close);
            stack.appendChild(toast);
            setTimeout(close, type==='danger'?7000:4500);
        }
        const seen=new Set();
        const pushOnce=(type,message,opts)=>{
            const key=(type||'success')+'|'+String(message||'').slice(0,160);
            if(!message||seen.has(key))return;
            seen.add(key);
            showToast(type,message,opts);
        };
        const sources=document.querySelectorAll('.ui-toast-source[data-ui-toast]');
        sources.forEach(el=>{
            pushOnce(el.getAttribute('data-ui-toast')||'success', el.getAttribute('data-ui-toast-message')||'', {
                href: el.getAttribute('data-ui-toast-href')||'',
                linkLabel: el.getAttribute('data-ui-toast-link')||'Lihat bukti'
            });
            el.remove();
        });
        // Legacy Bootstrap alerts → toast hanya jika belum ada ui-toast-source (hindari double)
        if(!sources.length){
            document.querySelectorAll('main > .alert.alert-dismissible, main > .alert[role="alert"]').forEach((alert,i)=>{
                if(i>1)return;
                // Instruksi halaman / info tetap di tempat (jangan diubah jadi toast)
                if(alert.classList.contains('alert-info')||alert.classList.contains('alert-secondary')||alert.classList.contains('info-alert')||alert.hasAttribute('data-ui-toast-skip'))return;
                const type=alert.classList.contains('alert-danger')?'danger':(alert.classList.contains('alert-warning')?'warning':'success');
                const clone=alert.cloneNode(true);
                clone.querySelectorAll('.btn-close,button').forEach(b=>b.remove());
                const text=(clone.textContent||'').replace(/\s+/g,' ').trim();
                if(text){pushOnce(type,text);alert.remove();}
            });
        }
        window.uiToast=showToast;
    })();

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
        // Select native disembunyikan: required dipindah ke input pencarian.
        let mustPick=!!select.required;select.required=false;input.required=mustPick;
        select.parentNode.insertBefore(box,select);box.append(input,toggle,list,select);select.classList.add('ui-combobox-native');select.setAttribute('aria-hidden','true');select.tabIndex=-1;let active=-1;
        function options(){return Array.from(select.options).filter(o=>!o.disabled);}
        function selectedLabel(){const o=select.selectedOptions[0];return o&&o.value?o.textContent.trim():'';}
        function placeList(){
            const rect=box.getBoundingClientRect();
            const gap=6;const maxH=Math.min(270,window.innerHeight*0.42);
            const spaceBelow=window.innerHeight-rect.bottom-gap;
            const spaceAbove=rect.top-gap;
            const openUp=spaceBelow<Math.min(180,maxH) && spaceAbove>spaceBelow;
            list.style.left=Math.max(8,Math.min(rect.left,window.innerWidth-rect.width-8))+'px';
            list.style.width=Math.max(160,rect.width)+'px';
            list.style.right='auto';
            list.style.maxHeight=Math.max(120,Math.min(maxH,openUp?spaceAbove:spaceBelow))+'px';
            if(openUp){list.style.top='auto';list.style.bottom=(window.innerHeight-rect.top+gap)+'px';}
            else{list.style.bottom='auto';list.style.top=(rect.bottom+gap)+'px';}
        }
        let listPinned=false;
        function unpinList(){
            if(!listPinned)return;
            listPinned=false;
            window.removeEventListener('resize',placeList);
            window.removeEventListener('scroll',placeList,true);
        }
        function pinList(){
            if(listPinned){placeList();return;}
            listPinned=true;
            window.addEventListener('resize',placeList);
            window.addEventListener('scroll',placeList,true);
            placeList();
        }
        function close(){
            list.hidden=true;list.classList.remove('ui-combobox-list-open');
            input.setAttribute('aria-expanded','false');active=-1;
            list.style.removeProperty('left');list.style.removeProperty('top');list.style.removeProperty('bottom');list.style.removeProperty('width');list.style.removeProperty('right');list.style.removeProperty('max-height');
            unpinList();
            if(list.parentElement!==box)box.appendChild(list);
        }
        function validate(){input.setCustomValidity(mustPick&&!select.value?'Pilih salah satu data dari daftar.':'');}
        function choose(value){select.value=value;input.value=selectedLabel();validate();select.dispatchEvent(new Event('change',{bubbles:true}));close();}
        function render(term){
            const q=(term||'').trim().toLocaleLowerCase('id');const seen=new Set();
            const matches=options().filter(o=>(o.value||!q)&&o.textContent.toLocaleLowerCase('id').includes(q)).filter(o=>{if(seen.has(o.value))return false;seen.add(o.value);return true;}).slice(0,100);
            list.replaceChildren();active=-1;
            if(!matches.length){const empty=document.createElement('div');empty.className='ui-combobox-empty';empty.textContent='Data tidak ditemukan';list.append(empty);}
            matches.forEach(o=>{
                const item=document.createElement('button');item.type='button';item.className='ui-combobox-option';item.setAttribute('role','option');item.dataset.value=o.value;
                item.textContent=o.textContent.trim()||(o.value?'Pilihan':'Kosongkan pilihan');
                if(o.selected)item.setAttribute('aria-selected','true');
                item.addEventListener('mousedown',e=>e.preventDefault());
                item.addEventListener('click',()=>choose(o.value));
                list.append(item);
            });
            if(list.parentElement!==document.body)document.body.appendChild(list);
            list.classList.add('ui-combobox-list-open');list.hidden=false;
            input.setAttribute('aria-expanded','true');pinList();
        }
        function move(step){const items=Array.from(list.querySelectorAll('.ui-combobox-option'));if(!items.length)return;active=(active+step+items.length)%items.length;items.forEach((el,i)=>el.classList.toggle('is-active',i===active));items[active].scrollIntoView({block:'nearest'});}
        input.value=selectedLabel();
        input.addEventListener('focus',()=>render(''));
        input.addEventListener('input',()=>{if(input.value!==selectedLabel())select.value='';validate();render(input.value);});
        input.addEventListener('keydown',e=>{
            if(e.key==='ArrowDown'){e.preventDefault();if(list.hidden)render(input.value);move(1);}
            else if(e.key==='ArrowUp'){e.preventDefault();if(list.hidden)render(input.value);move(-1);}
            else if(e.key==='Enter'&&!list.hidden){const items=list.querySelectorAll('.ui-combobox-option');if(active>=0&&items[active]){e.preventDefault();choose(items[active].dataset.value);}else if(items.length===1){e.preventDefault();choose(items[0].dataset.value);}}
            else if(e.key==='Escape'){close();input.value=selectedLabel();validate();}
        });
        input.addEventListener('blur',e=>{
            if(e.relatedTarget===toggle||(e.relatedTarget&&list.contains(e.relatedTarget)))return;
            setTimeout(()=>{if(document.activeElement!==toggle&&!list.contains(document.activeElement)){close();input.value=selectedLabel();validate();}},120);
        });
        toggle.addEventListener('mousedown',e=>{e.preventDefault();if(list.hidden){input.focus();render('');}else close();});
        select.addEventListener('change',()=>{input.value=selectedLabel();validate();});
        const sync=()=>{
            if(select.required){mustPick=true;select.required=false;}
            input.disabled=select.disabled;input.required=mustPick;
            box.classList.toggle('is-disabled',select.disabled);
            if(document.activeElement!==input)input.value=selectedLabel();
            else if(!list.hidden)render(input.value);
            validate();
        };
        new MutationObserver(sync).observe(select,{childList:true,subtree:true,attributes:true});sync();
    }
    document.querySelectorAll('main select,.modal select').forEach(initAutocompleteSelect);
    window.uiInitAutocompleteSelect=initAutocompleteSelect;
    // Select yang muncul belakangan (modal dinamis / partial reload) ikut diinisialisasi.
    document.addEventListener('shown.bs.modal',function(e){
        (e.target||document).querySelectorAll('select').forEach(initAutocompleteSelect);
    });

    // Native validation pada input autocomplete sering hanya menampilkan tooltip
    // kecil yang tidak terlihat. Tampilkan alasan yang jelas dan fokus ke fieldnya.
    document.querySelectorAll('form').forEach(form=>{
        form.addEventListener('invalid',e=>{
            const field=e.target;
            if(!field||form.dataset.uiInvalidShown==='1')return;
            form.dataset.uiInvalidShown='1';
            field.dataset.uiInvalidShown='1';
            setTimeout(()=>{delete field.dataset.uiInvalidShown;form.dataset.uiInvalidShown='';},400);
            const label=field.closest('.mb-3,.col-md-6,.col-md-4,.col-12,.form-group')?.querySelector('label')?.textContent?.trim() || field.getAttribute('name') || 'Field';
            const message=field.validity.valueMissing?' wajib diisi.':' belum valid.';
            alert(label.replace(/\s+/g,' ')+message);
            field.focus();
        },true);
    });

    const actionRules=[['danger',/(hapus|delete|tolak|batalkan|koreksi)/],['whatsapp',/(whatsapp|\bwa\b|tagih)/],['export',/(excel|csv|export)/],['print',/(cetak|print|pdf|kwitansi|surat|bast)/],['edit',/(edit|ubah|pengaturan)/],['view',/(lihat|detail|preview|foto|dokumen)/],['pay',/(bayar|pelunasan|cairkan|setujui|approve)/],['muted',/(reset|batal|tutup|kembali|hapus tanda tangan)/],['primary',/(simpan|tambah|buat|proses|kirim|cari|filter|terapkan|login)/]];
    function classifyButton(button){if(button.classList.contains('badge')||button.classList.contains('btn-close')||button.classList.contains('ui-combobox-toggle'))return;const label=((button.getAttribute('aria-label')||'')+' '+(button.title||'')+' '+button.textContent).trim().toLocaleLowerCase('id');let type=label.includes('hapus tanda tangan')?'muted':'';for(const rule of actionRules)if(!type&&rule[1].test(label)){type=rule[0];break;}if(!type){const icon=button.querySelector('i')?.className||'';if(/trash/.test(icon))type='danger';else if(/edit|pen/.test(icon))type='edit';else if(/eye|camera|image/.test(icon))type='view';else if(/print|file-pdf/.test(icon))type='print';else if(/whatsapp/.test(icon))type='whatsapp';else if(/file-excel|download/.test(icon))type='export';}if(type)button.classList.add('ui-action','ui-action-'+type);}
    document.querySelectorAll('main .btn,.modal .btn').forEach(classifyButton);
    const sidebar = document.getElementById('sidebarMenu');
    const toggle = document.querySelector('.ui-menu-button');
    // Samakan breakpoint dengan CSS agar sidebar tidak menimpa konten pada tablet.
    const small = window.matchMedia('(max-width: 1199.98px)');
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
    // Standar tabel: bungkus aksi baris (2+ tombol) jadi menu ⋮ SEBELUM
    // normalisasi .ui-table-actions — kalau dibalik, anak jadi 1 div dan ⋮ dilewati.
    // Setiap item menu wajib ikon + teks (bukan tombol ikon berwarna).
    const actionIconLabels = [
        ['fa-trash', 'Hapus'], ['fa-times', 'Hapus'], ['fa-ban', 'Tolak'],
        ['fa-edit', 'Ubah'], ['fa-pen', 'Ubah'],
        ['fa-eye', 'Detail'], ['fa-search', 'Detail'],
        ['fa-check', 'Setujui'], ['fa-thumbs-up', 'Setujui'],
        ['fa-print', 'Cetak'], ['fa-file-pdf', 'PDF'], ['fa-file-excel', 'Excel'],
        ['fa-key', 'Reset password'], ['fa-flag-checkered', 'Lunaskan'],
        ['fa-whatsapp', 'WhatsApp'], ['fa-download', 'Unduh'],
        ['fa-sync', 'Muat ulang'], ['fa-file-lines', 'Surat'], ['fa-file-alt', 'Surat']
    ];
    function collectActionables(root) {
        return Array.from(root.children).filter(function (el) {
            if (el.nodeType !== 1) return false;
            if (el.classList.contains('modal') || el.classList.contains('dropdown-menu')) return false;
            if (el.classList.contains('ui-action-menu') || el.classList.contains('dropdown')) return false;
            return el.matches('a,button,form') || !!el.querySelector('a,button');
        });
    }
    function resolveActionLabel(ctrl) {
        const raw = ((ctrl.getAttribute('aria-label') || '') + ' ' + (ctrl.title || '') + ' ' + (ctrl.textContent || ''))
            .replace(/\s+/g, ' ').trim();
        if (raw) return raw;
        const icon = ctrl.querySelector('i');
        if (icon) {
            for (let i = 0; i < actionIconLabels.length; i++) {
                if (icon.classList.contains(actionIconLabels[i][0])) return actionIconLabels[i][1];
            }
        }
        return 'Aksi';
    }
    function normalizeActionControl(ctrl, label) {
        const keep = {};
        Array.from(ctrl.attributes).forEach(function (attr) {
            if (/^(data-|href$|target$|rel$|type$|name$|value$|onclick$|id$|form$)/i.test(attr.name)) {
                keep[attr.name] = attr.value;
            }
        });
        const icon = ctrl.querySelector('i');
        const iconClass = icon ? icon.className.replace(/\s+/g, ' ').trim() : 'fas fa-circle';
        // Rapikan kelas tombol berwarna → item menu teks seragam
        ctrl.className = 'dropdown-item ui-action-item';
        if (/(hapus|delete|tolak|batal)/i.test(label)) ctrl.classList.add('text-danger');
        else if (/(setujui|approve|lunaskan|cairkan|bayar)/i.test(label)) ctrl.classList.add('text-success');
        Object.keys(keep).forEach(function (k) { ctrl.setAttribute(k, keep[k]); });
        if (!ctrl.getAttribute('type') && ctrl.tagName === 'BUTTON') ctrl.type = 'button';
        ctrl.setAttribute('title', label);
        ctrl.setAttribute('aria-label', label);
        ctrl.innerHTML = '<i class="' + iconClass + '" aria-hidden="true"></i><span>' + label + '</span>';
        return ctrl;
    }
    function convertCellToActionMenu(cell) {
        if (!cell || cell.dataset.uiActionReady === '1') return false;
        if (cell.querySelector(':scope > .ui-action-menu, :scope > .dropdown .action-menu, :scope > .dropdown .dropdown-menu')) {
            // Sudah dropdown custom — pastikan item di dalamnya ikon+teks
            cell.querySelectorAll('.dropdown-menu .dropdown-item, .action-menu .dropdown-item, .action-menu a, .action-menu button').forEach(function (item) {
                if (item.dataset.uiLabelReady === '1') return;
                item.dataset.uiLabelReady = '1';
                const label = resolveActionLabel(item);
                if (!item.querySelector('span') && item.querySelector('i') && !(item.textContent || '').replace(/\s+/g, '').replace(item.querySelector('i').textContent || '', '')) {
                    normalizeActionControl(item, label);
                } else if (item.querySelector('i') && !(item.textContent || '').trim().replace(item.querySelector('i').textContent || '', '').trim()) {
                    normalizeActionControl(item, label);
                } else {
                    item.classList.add('ui-action-item');
                }
            });
            cell.dataset.uiActionReady = '1';
            return false;
        }
        const nested = cell.querySelector(':scope > .ui-table-actions, :scope > .service-row-actions');
        const root = nested || cell;
        const actionable = collectActionables(root);
        if (actionable.length < 2) return false;
        cell.dataset.uiActionReady = '1';
        const wrap = document.createElement('div');
        wrap.className = 'dropdown ui-action-menu';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-secondary ui-action-toggle';
        btn.setAttribute('data-bs-toggle', 'dropdown');
        btn.setAttribute('data-bs-boundary', 'viewport');
        btn.setAttribute('data-bs-popper-config', '{"strategy":"fixed"}');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-label', 'Aksi baris');
        btn.innerHTML = '<i class="fas fa-ellipsis-v" aria-hidden="true"></i><span class="visually-hidden">Aksi</span>';
        const menu = document.createElement('ul');
        menu.className = 'dropdown-menu dropdown-menu-end shadow';
        actionable.forEach(function (el) {
            const li = document.createElement('li');
            let ctrl = el.matches('a,button') ? el : el.querySelector('a.btn,button.btn,a,button');
            if (!ctrl) {
                li.appendChild(el);
                menu.appendChild(li);
                return;
            }
            const label = resolveActionLabel(ctrl);
            if (el.matches('form')) {
                // Form (mis. reset password): tampilkan tombol submit sebagai item menu
                normalizeActionControl(ctrl, label);
                el.classList.add('ui-action-form');
                li.appendChild(el);
            } else {
                normalizeActionControl(ctrl, label);
                li.appendChild(ctrl);
            }
            menu.appendChild(li);
        });
        wrap.appendChild(btn);
        wrap.appendChild(menu);
        cell.innerHTML = '';
        cell.classList.add('table-actions', 'text-end', 'ui-actions-cell');
        cell.appendChild(wrap);
        if (window.bootstrap && bootstrap.Dropdown) {
            btn.dataset.uiPopperFixed = '1';
            new bootstrap.Dropdown(btn, {
                boundary: 'viewport',
                autoClose: true,
                popperConfig: function (defaultConfig) {
                    return Object.assign({}, defaultConfig, {
                        strategy: 'fixed',
                        modifiers: (defaultConfig.modifiers || []).concat([
                            { name: 'preventOverflow', options: { boundary: 'viewport', padding: 8 } },
                            { name: 'flip', options: { boundary: 'viewport', padding: 8 } }
                        ])
                    });
                }
            });
        }
        return true;
    }
    function convertAllActionMenus(scope) {
        (scope || document).querySelectorAll(
            'td.table-actions, td.ui-actions-cell, .table-actions, .service-row-actions, td.no-print:has(.service-row-actions), main table td:has(> .btn + .btn), main table td:has(> a.btn + a.btn), main table td:has(> .btn + form), main table td:has(> form + .btn)'
        ).forEach(convertCellToActionMenu);
    }
    convertAllActionMenus();

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
                // Sudah menu ⋮ — jangan bungkus lagi ke .ui-table-actions
                if(cell.querySelector('.ui-action-menu, :scope > .dropdown')){
                    cell.dataset.uiActionReady='1';
                    return;
                }
                // Coba konversi ⋮ dulu (tombol masih sejajar)
                if(convertCellToActionMenu(cell))return;
                if(cell.querySelector(':scope > .ui-table-actions'))return;
                const group=document.createElement('div');group.className='ui-table-actions';
                Array.from(cell.childNodes).filter(node=>!(node.nodeType===1 && node.classList.contains('modal'))).forEach(node=>group.appendChild(node));cell.appendChild(group);
                // Setelah grouping, coba lagi (2+ di dalam group)
                if(convertCellToActionMenu(cell))return;
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
        // Satu hint per wrapper; jangan dobel jika skrip/HTML sudah memasang hint.
        let hint=wrapper.nextElementSibling;
        if(!(hint&&hint.classList&&hint.classList.contains('ui-scroll-hint'))){
            hint=document.createElement('div');
            hint.className='ui-scroll-hint';
            hint.textContent='Geser tabel ke samping untuk melihat kolom lainnya.';
            wrapper.insertAdjacentElement('afterend',hint);
        }
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
    // Semua thumbnail/pratinjau dokumen memakai penampil gambar yang sama.
    // Delegasi event membuat preview hasil pemilihan file yang muncul belakangan ikut aktif.
    const previewSelector='.document-preview-image,.thumb-dokumen,img.img-thumbnail,img[id*="preview" i],img[id^="fotoJaminan"],img[data-image-preview],#modalDokumenGambar';
    let imageViewer=null,imageViewerImg=null,imageViewerTitle=null,imageScale=1,imageX=0,imageY=0,imageDragging=false,imageStartX=0,imageStartY=0,imageOriginX=0,imageOriginY=0,imageLastFocus=null;
    function applyImageTransform(){if(imageViewerImg)imageViewerImg.style.transform='translate('+imageX+'px,'+imageY+'px) scale('+imageScale+')';if(imageViewer)imageViewer.querySelector('[data-viewer-zoom]').textContent=Math.round(imageScale*100)+'%';}
    function setImageScale(next){imageScale=Math.min(5,Math.max(.5,next));if(imageScale<=1){imageX=0;imageY=0;}applyImageTransform();}
    function closeImageViewer(){if(!imageViewer||imageViewer.hidden)return;imageViewer.hidden=true;document.body.classList.remove('ui-image-viewer-open');imageViewerImg.removeAttribute('src');if(imageLastFocus&&document.contains(imageLastFocus))imageLastFocus.focus();}
    function ensureImageViewer(){
        if(imageViewer)return;
        imageViewer=document.createElement('div');imageViewer.className='ui-image-viewer';imageViewer.hidden=true;imageViewer.setAttribute('role','dialog');imageViewer.setAttribute('aria-modal','true');imageViewer.setAttribute('aria-labelledby','uiImageViewerTitle');
        imageViewer.innerHTML='<div class="ui-image-viewer-head"><strong id="uiImageViewerTitle">Pratinjau gambar</strong><div class="ui-image-viewer-actions"><button type="button" data-viewer-action="out" aria-label="Perkecil gambar" title="Perkecil">&minus;</button><span data-viewer-zoom>100%</span><button type="button" data-viewer-action="in" aria-label="Perbesar gambar" title="Perbesar">+</button><button type="button" data-viewer-action="reset" aria-label="Ukuran semula" title="Ukuran semula">100%</button><button type="button" data-viewer-action="close" aria-label="Tutup pratinjau" title="Tutup">&times;</button></div></div><div class="ui-image-viewer-stage"><img alt=""></div><small class="ui-image-viewer-help">Klik dua kali atau gunakan roda mouse untuk zoom. Geser gambar saat diperbesar.</small>';
        document.body.appendChild(imageViewer);imageViewerImg=imageViewer.querySelector('img');imageViewerTitle=imageViewer.querySelector('strong');
        imageViewer.addEventListener('click',function(e){const action=e.target.closest('[data-viewer-action]')?.dataset.viewerAction;if(action==='close')closeImageViewer();else if(action==='in')setImageScale(imageScale+.25);else if(action==='out')setImageScale(imageScale-.25);else if(action==='reset'){imageScale=1;imageX=0;imageY=0;applyImageTransform();}else if(e.target===imageViewer)closeImageViewer();});
        imageViewerImg.addEventListener('dblclick',function(){setImageScale(imageScale===1?2:1);});
        imageViewer.querySelector('.ui-image-viewer-stage').addEventListener('wheel',function(e){e.preventDefault();setImageScale(imageScale+(e.deltaY<0?.25:-.25));},{passive:false});
        imageViewerImg.addEventListener('pointerdown',function(e){if(imageScale<=1)return;imageDragging=true;imageStartX=e.clientX;imageStartY=e.clientY;imageOriginX=imageX;imageOriginY=imageY;imageViewerImg.setPointerCapture(e.pointerId);imageViewerImg.classList.add('is-dragging');});
        imageViewerImg.addEventListener('pointermove',function(e){if(!imageDragging)return;imageX=imageOriginX+e.clientX-imageStartX;imageY=imageOriginY+e.clientY-imageStartY;applyImageTransform();});
        imageViewerImg.addEventListener('pointerup',function(){imageDragging=false;imageViewerImg.classList.remove('is-dragging');});
    }
    function openImageViewer(img){
        if(!img.currentSrc&&!img.src)return;ensureImageViewer();imageLastFocus=img;imageScale=1;imageX=0;imageY=0;
        imageViewerImg.src=img.currentSrc||img.src;imageViewerImg.alt=img.alt||'Pratinjau gambar';imageViewerTitle.textContent=img.alt||img.title||'Pratinjau gambar';applyImageTransform();imageViewer.hidden=false;document.body.classList.add('ui-image-viewer-open');imageViewer.querySelector('[data-viewer-action="close"]').focus();
    }
    document.addEventListener('click',function(e){const img=e.target.closest&&e.target.closest(previewSelector);if(!img||img.classList.contains('d-none')||!img.src)return;e.preventDefault();e.stopImmediatePropagation();openImageViewer(img);},true);
    document.addEventListener('keydown',function(e){if(!imageViewer||imageViewer.hidden)return;if(e.key==='Escape'){e.preventDefault();closeImageViewer();}else if(e.key==='+'||e.key==='='){e.preventDefault();setImageScale(imageScale+.25);}else if(e.key==='-'){e.preventDefault();setImageScale(imageScale-.25);}else if(e.key==='0'){e.preventDefault();setImageScale(1);}});
    document.querySelectorAll(previewSelector).forEach(function(img){img.tabIndex=img.tabIndex>=0?img.tabIndex:0;img.setAttribute('role','button');img.setAttribute('aria-label','Perbesar '+(img.alt||'gambar'));});
    document.addEventListener('keydown',function(e){if((e.key==='Enter'||e.key===' ')&&e.target.matches&&e.target.matches(previewSelector)){e.preventDefault();openImageViewer(e.target);}});
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

    // Pastikan semua dropdown aksi ⋮ memakai fixed positioning (tidak ketiban overflow tabel).
    function hardenActionDropdowns(root) {
        (root || document).querySelectorAll('.ui-action-menu [data-bs-toggle="dropdown"], .action-dropdown-toggle').forEach(function (btn) {
            if (btn.dataset.uiPopperFixed === '1') return;
            btn.dataset.uiPopperFixed = '1';
            btn.setAttribute('data-bs-boundary', 'viewport');
            if (!btn.getAttribute('data-bs-popper-config')) {
                btn.setAttribute('data-bs-popper-config', '{"strategy":"fixed"}');
            }
            if (window.bootstrap && bootstrap.Dropdown) {
                const existing = bootstrap.Dropdown.getInstance(btn);
                if (existing) existing.dispose();
                new bootstrap.Dropdown(btn, {
                    boundary: 'viewport',
                    autoClose: true,
                    popperConfig: function (defaultConfig) {
                        return Object.assign({}, defaultConfig, {
                            strategy: 'fixed',
                            modifiers: (defaultConfig.modifiers || []).concat([
                                { name: 'preventOverflow', options: { boundary: 'viewport', padding: 8 } },
                                { name: 'flip', options: { boundary: 'viewport', padding: 8 } }
                            ])
                        });
                    }
                });
            }
        });
    }
    hardenActionDropdowns();

    // Portal menu ke <body> saat dibuka — anti-clip dari .table-responsive / main overflow-x
    document.addEventListener('show.bs.dropdown', function (e) {
        const toggle = e.target;
        if (!toggle || !toggle.closest) return;
        const wrap = toggle.closest('.ui-action-menu, .dropdown');
        if (!wrap || (!wrap.classList.contains('ui-action-menu') && !toggle.classList.contains('ui-action-toggle') && !toggle.classList.contains('action-dropdown-toggle'))) return;
        const menu = wrap.querySelector(':scope > .dropdown-menu');
        if (!menu) return;
        if (!menu.dataset.uiMenuHome) {
            menu.dataset.uiMenuHome = '1';
            menu._uiHomeParent = wrap;
            menu._uiHomeNext = menu.nextSibling;
        }
        if (menu.parentElement !== document.body) {
            document.body.appendChild(menu);
        }
        menu.classList.add('ui-action-menu-open');
        menu.style.zIndex = '2000';
    });
    document.addEventListener('hidden.bs.dropdown', function (e) {
        const toggle = e.target;
        if (!toggle || !toggle.closest) return;
        const wrap = toggle.closest('.ui-action-menu, .dropdown');
        // Menu mungkin sudah di body; cari via class + relasi home
        let menu = wrap && wrap.querySelector(':scope > .dropdown-menu');
        if (!menu) {
            menu = Array.from(document.body.querySelectorAll('.dropdown-menu.ui-action-menu-open')).find(function (m) {
                return m._uiHomeParent === wrap;
            });
        }
        if (!menu || !menu._uiHomeParent) return;
        menu.classList.remove('ui-action-menu-open', 'show');
        menu.style.removeProperty('z-index');
        const home = menu._uiHomeParent;
        if (menu._uiHomeNext && menu._uiHomeNext.parentNode === home) {
            home.insertBefore(menu, menu._uiHomeNext);
        } else {
            home.appendChild(menu);
        }
    });
});
