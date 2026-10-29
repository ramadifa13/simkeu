<?php
$jenisOptions = ['' => 'Semua Jenis'];
foreach ($jenis_filter as $jenis) {
    $jenisOptions[(string)$jenis['id']] = $jenis['nama_jenis'];
}
ui_table_toolbar([
    'action' => 'pelunasan.php',
    'q' => $filter_q,
    'placeholder' => 'Nama, kode anggota/pinjaman',
    'per_page' => isset($pager['per_page']) ? (int)$pager['per_page'] : 10,
    'reset_href' => 'pelunasan.php',
    'filters' => [
        [
            'name' => 'jenis',
            'label' => 'Jenis pinjaman',
            'type' => 'select',
            'value' => $filter_jenis ? (string)$filter_jenis : '',
            'options' => $jenisOptions,
        ],
        [
            'name' => 'jaminan',
            'label' => 'Kategori',
            'type' => 'select',
            'value' => $filter_jaminan,
            'options' => ['' => 'Semua Kategori', '0' => 'Tanpa Jaminan', '1' => 'Jaminan/Gadai'],
        ],
        [
            'name' => 'frekuensi',
            'label' => 'Frekuensi',
            'type' => 'select',
            'value' => $filter_frekuensi,
            'options' => [
                '' => 'Semua Frekuensi',
                'mingguan' => 'Mingguan',
                'dua_mingguan' => '2 Minggu Sekali',
                'bulanan' => 'Bulanan',
            ],
        ],
        ['name' => 'mulai', 'label' => 'Pencairan mulai', 'type' => 'date', 'value' => $filter_mulai],
        ['name' => 'sampai', 'label' => 'Pencairan sampai', 'type' => 'date', 'value' => $filter_sampai],
    ],
]);
