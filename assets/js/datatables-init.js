/**
 * Inisialisasi DataTables global untuk SIMKEU.
 * Pakai: <table class="table js-datatable"> dengan <thead>/<tbody>.
 * Atribut opsional pada <table>:
 *   data-dt-page-length="10"   data-dt-order="2,desc" | "0,asc;1,desc" (multi)
 *   data-dt-filter="3,4"       data-dt-nosort="0,5"     data-dt-no-search-box
 *   data-dt-ajax="url"         (server-side, kolom dari <th data-data="x">)
 * Kolom bernama "Aksi" otomatis tidak bisa disort/dicari. <th class="no-sort|dt-hidden"> juga dikenali.
 * Responsif: scroll horizontal (bukan collapse kolom).
 */
(function () {
    'use strict';
    if (!window.DataTable || !window.jQuery) { return; }

    var LANG = {
        search: '', searchPlaceholder: 'Cari data...',
        lengthMenu: 'Tampilkan _MENU_',
        info: 'Menampilkan _START_–_END_ dari _TOTAL_ data',
        infoEmpty: 'Tidak ada data', infoFiltered: '(disaring dari _MAX_ data)',
        zeroRecords: 'Data tidak ditemukan', emptyTable: 'Belum ada data',
        loadingRecords: 'Memuat...', processing: 'Memproses...',
        paginate: { first: '«', last: '»', next: '›', previous: '‹' }
    };

    function stripHtml(s) { return String(s == null ? '' : s).replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim(); }

    // Tipe urutan: Rupiah/angka format Indonesia, dan tanggal dd/mm/yyyy | dd-mm-yyyy | yyyy-mm-dd
    function rupiahToNum(s) {
        s = stripHtml(s).replace(/Rp\.?|%|\s/gi, '');
        if (s === '' || s === '-') { return -Infinity; }
        var neg = /^\(.*\)$/.test(s) || s.charAt(0) === '-';
        s = s.replace(/[()\-+]/g, '').replace(/\./g, '').replace(',', '.');
        var n = parseFloat(s);
        return isNaN(n) ? -Infinity : (neg ? -n : n);
    }
    DataTable.type('simkeu-rp', {
        detect: function (d) { return /^\s*[-(]?\s*(Rp\.?\s*)?[-(]?\d{1,3}(\.\d{3})*(,\d+)?\)?\s*%?\s*$/.test(stripHtml(d)) && /\d/.test(d) ? 'simkeu-rp' : null; },
        order: { pre: rupiahToNum }
    });
    function dateToNum(s) {
        s = stripHtml(s);
        var m = s.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})(?:\D+(\d{1,2}):(\d{2}))?/);
        if (m) { return +(m[3] + ('0' + m[2]).slice(-2) + ('0' + m[1]).slice(-2)) * 10000 + (+(m[4] || 0)) * 100 + (+(m[5] || 0)); }
        m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:\D+(\d{1,2}):(\d{2}))?/);
        if (m) { return +(m[1] + m[2] + m[3]) * 10000 + (+(m[4] || 0)) * 100 + (+(m[5] || 0)); }
        return -1;
    }
    DataTable.type('simkeu-date', {
        detect: function (d) { return /^(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4}|\d{4}-\d{2}-\d{2})/.test(stripHtml(d)) ? 'simkeu-date' : null; },
        order: { pre: dateToNum }
    });

    function csvInts(v) { return String(v || '').split(',').map(function (x) { return parseInt(x, 10); }).filter(function (x) { return !isNaN(x); }); }

    function prepare(table) {
        var $t = jQuery(table);
        // Baris "kosong" berisi satu sel colspan merusak DataTables → hapus, DataTables menampilkan pesan sendiri.
        $t.find('tbody > tr').each(function () {
            var $c = jQuery(this).children('td,th');
            if ($c.length === 1 && ($c.attr('colspan') || 1) > 1) { jQuery(this).remove(); }
        });
        if (!table.tHead || !table.tBodies.length) { return false; }
        // Hanya header satu baris yang didukung; baris header ganda → ambil baris terakhir sebagai header kolom.
        var rows = table.tHead.rows;
        if (rows.length > 1) {
            for (var i = 0; i < rows.length - 1; i++) { if (rows[i].querySelector('[colspan],[rowspan]')) { return false; } }
        }
        return true;
    }

    function init(table) {
        if (table.dataset.dtReady) { return; }
        if (!prepare(table)) { return; }
        table.classList.add('table-hover');
        if (!table.classList.contains('table-borderless')) { table.classList.add('align-middle'); }
        table.dataset.dtReady = '1';
        var ds = table.dataset;
        var headers = Array.prototype.slice.call(table.tHead.rows[table.tHead.rows.length - 1].cells);
        var nosort = csvInts(ds.dtNosort);
        var colDefs = [];
        headers.forEach(function (th, i) {
            var txt = th.textContent.trim().toLowerCase();
            var def = {};
            if (th.classList.contains('dt-hidden') || th.classList.contains('d-none')) {
                def.visible = false;
                def.searchable = false;
            }
            if (th.classList.contains('no-sort') || /^(aksi|action|opsi|prio|prioritas|)$/.test(txt) || nosort.indexOf(i) > -1) {
                def.orderable = false;
                def.searchable = false;
                if (!('visible' in def)) { def.className = 'text-nowrap dt-actions'; }
            }
            // Kolom prioritas tersembunyi tetap bisa di-sort (default: butuh aksi dulu)
            if (th.classList.contains('dt-prio')) {
                def.visible = false;
                def.searchable = false;
                def.orderable = true;
                delete def.className;
            }
            if (Object.keys(def).length) {
                def.targets = i;
                colDefs.push(def);
            }
        });
        var order = [];
        if (ds.dtOrder) {
            String(ds.dtOrder).split(';').forEach(function (part) {
                var p = part.trim().split(',');
                var idx = parseInt(p[0], 10);
                if (!isNaN(idx)) { order.push([idx, (p[1] || 'asc').trim()]); }
            });
        }
        var opts = {
            language: LANG,
            responsive: false,
            autoWidth: false,
            scrollX: false,
            pageLength: parseInt(ds.dtPageLength || '10', 10),
            lengthMenu: [10, 25, 50, 100],
            order: order,
            columnDefs: colDefs,
            layout: {
                topStart: null,
                topEnd: ds.dtNoSearchBox !== undefined ? null : 'search',
                bottomStart: ['pageLength', 'info'],
                bottomEnd: 'paging'
            }
        };
        if (ds.dtPlain !== undefined) {
            opts.paging = false;
            opts.searching = false;
            opts.info = false;
            opts.layout = { topStart: null, topEnd: null, bottomStart: null, bottomEnd: null };
        }
        if (ds.dtAjax) {
            opts.serverSide = true;
            opts.processing = true;
            opts.ajax = { url: ds.dtAjax, type: 'GET' };
            opts.columns = headers.map(function (th) { return { data: th.dataset.data || null, orderable: th.dataset.orderable !== 'false' }; });
        }
        var dt = new DataTable(table, opts);
        table.classList.add('simkeu-dt');

        // Toolbar filter (jenis / tanggal + cari). "Tampilkan" tetap di bawah kiri.
        var filterCols = csvInts(ds.dtFilter);
        var dateCol = ds.dtDateCol !== undefined && ds.dtDateCol !== '' ? parseInt(ds.dtDateCol, 10) : NaN;
        var needToolbar = (filterCols.length || !isNaN(dateCol)) && !ds.dtAjax && ds.dtPlain === undefined;
        if (needToolbar) {
            var wrap = table.closest('.dt-container') || table.parentNode;
            var topRow = wrap.querySelector ? wrap.querySelector('.dt-layout-row') : null;
            var toolbar = document.createElement('div');
            toolbar.className = 'dt-toolbar';
            var start = document.createElement('div');
            start.className = 'dt-toolbar-start';
            var end = document.createElement('div');
            end.className = 'dt-toolbar-end';

            filterCols.forEach(function (ci) {
                var sel = document.createElement('select');
                sel.className = 'form-select form-select-sm dt-toolbar-select';
                var label = headers[ci] ? headers[ci].textContent.trim() : 'Kolom ' + ci;
                sel.setAttribute('aria-label', label);
                sel.innerHTML = '<option value="">Semua ' + label.replace(/</g, '&lt;') + '</option>';
                var seen = {};
                function cellFilterText(rowIdx) {
                    var node = dt.cell(rowIdx, ci).node();
                    if (node) {
                        var df = node.getAttribute('data-filter') || node.getAttribute('data-search');
                        if (df != null && String(df).trim() !== '') { return String(df).trim(); }
                        return stripHtml(node.innerHTML);
                    }
                    return stripHtml(dt.cell(rowIdx, ci).data());
                }
                dt.rows({ search: 'none' }).indexes().each(function (rowIdx) {
                    var t = cellFilterText(rowIdx);
                    if (t && !seen[t]) { seen[t] = 1; }
                });
                Object.keys(seen).sort().forEach(function (v) {
                    var o = document.createElement('option'); o.value = v; o.textContent = v; sel.appendChild(o);
                });
                // Jangan pakai column().search() regex pada sel HTML (badge) —
                // "Aktif" tidak match "<span>Aktif</span>" dan ikut kena "Nonaktif".
                var activeVal = '';
                var filterFn = function (settings, _data, dataIndex) {
                    if (settings.nTable !== table) { return true; }
                    if (!activeVal) { return true; }
                    return cellFilterText(dataIndex) === activeVal;
                };
                jQuery.fn.dataTable.ext.search.push(filterFn);
                sel.addEventListener('change', function () {
                    activeVal = sel.value;
                    dt.draw();
                });
                dt.on('destroy', function () {
                    var idx = jQuery.fn.dataTable.ext.search.indexOf(filterFn);
                    if (idx > -1) { jQuery.fn.dataTable.ext.search.splice(idx, 1); }
                });
                start.appendChild(sel);
            });

            if (!isNaN(dateCol)) {
                var from = document.createElement('input');
                from.type = 'date';
                from.className = 'form-control form-control-sm dt-toolbar-date';
                from.title = 'Dari tanggal';
                from.setAttribute('aria-label', 'Dari tanggal');
                var to = document.createElement('input');
                to.type = 'date';
                to.className = 'form-control form-control-sm dt-toolbar-date';
                to.title = 'Sampai tanggal';
                to.setAttribute('aria-label', 'Sampai tanggal');
                var clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'btn btn-sm dt-toolbar-clear';
                clearBtn.title = 'Reset tanggal';
                clearBtn.setAttribute('aria-label', 'Reset tanggal');
                clearBtn.innerHTML = '<i class="ti ti-x" aria-hidden="true"></i>';
                function cellDay(rowIdx) {
                    var node = dt.cell(rowIdx, dateCol).node();
                    if (node && node.getAttribute('data-date')) { return node.getAttribute('data-date'); }
                    var ord = node ? node.getAttribute('data-order') : '';
                    return ord ? String(ord).slice(0, 10) : '';
                }
                var searchFn = function (settings, data, dataIndex) {
                    if (settings.nTable !== table) { return true; }
                    var day = cellDay(dataIndex);
                    if (!day) { return true; }
                    if (from.value && day < from.value) { return false; }
                    if (to.value && day > to.value) { return false; }
                    return true;
                };
                jQuery.fn.dataTable.ext.search.push(searchFn);
                function redraw() { dt.draw(); }
                from.addEventListener('change', redraw);
                to.addEventListener('change', redraw);
                clearBtn.addEventListener('click', function () {
                    from.value = '';
                    to.value = '';
                    redraw();
                });
                start.appendChild(from);
                start.appendChild(to);
                start.appendChild(clearBtn);
                dt.on('destroy', function () {
                    var idx = jQuery.fn.dataTable.ext.search.indexOf(searchFn);
                    if (idx > -1) { jQuery.fn.dataTable.ext.search.splice(idx, 1); }
                });
            }

            var searchEl = wrap.querySelector('.dt-search');
            var controlRow = (searchEl && searchEl.closest('.row, .dt-layout-row')) || topRow;
            if (searchEl) { end.appendChild(searchEl); }
            toolbar.appendChild(start);
            if (end.childNodes.length) { toolbar.appendChild(end); }
            if (controlRow) {
                controlRow.innerHTML = '';
                controlRow.className = (controlRow.className || '').replace(/\brow\b/g, '').trim() + ' dt-toolbar-row';
                controlRow.appendChild(toolbar);
            } else {
                var tableHost = wrap.querySelector('.dt-layout-table, table');
                wrap.insertBefore(toolbar, tableHost || wrap.firstChild);
            }
            wrap.querySelectorAll(':scope > .dt-toolbar').forEach(function (el) {
                if (el !== toolbar) { el.remove(); }
            });
            wrap.classList.add('has-dt-toolbar');
        }
        // Bungkus <table> saja agar bisa digeser ke kanan; filter/paging tetap di luar
        if (!table.parentElement.classList.contains('ui-table-scroll') && !table.parentElement.classList.contains('table-responsive')) {
            var scroller = document.createElement('div');
            scroller.className = 'ui-table-scroll';
            table.parentNode.insertBefore(scroller, table);
            scroller.appendChild(table);
        }
        document.dispatchEvent(new CustomEvent('simkeu:dt-ready', { detail: { table: table, api: dt } }));
        dt.on('draw', function () { document.dispatchEvent(new CustomEvent('simkeu:dt-draw', { detail: { table: table, api: dt } })); });
    }

    /** Pastikan tabel non-DataTables punya wrapper scroll horizontal. */
    function wrapPlainTables() {
        document.querySelectorAll('.card .table, .card-body > table.table, main#konten-utama table.table').forEach(function (table) {
            if (table.classList.contains('js-datatable') || table.classList.contains('dataTable')) { return; }
            if (table.closest('.table-responsive, .ui-table-scroll, .dt-container')) { return; }
            if (table.closest('.modal-body table')) { return; }
            var wrap = document.createElement('div');
            wrap.className = 'table-responsive ui-table-scroll';
            table.parentNode.insertBefore(wrap, table);
            wrap.appendChild(table);
        });
    }

    function boot() {
        wrapPlainTables();
        document.querySelectorAll('table.js-datatable').forEach(init);
    }
    window.SimkeuDT = { init: init, boot: boot };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
