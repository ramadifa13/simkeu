<?php

return [
    'admin' => [
        ['label' => 'Dashboard', 'route' => 'portal.dashboard'],
        ['label' => 'Anggota & staf', 'items' => [
            ['label' => 'Data anggota', 'route' => 'portal.members.index'],
            ['label' => 'Teller', 'module' => 'teller'],
        ]],
        ['label' => 'Operasional', 'items' => [
            ['label' => 'Simpanan & kas', 'module' => 'simpanan-kas'],
            ['label' => 'Pinjaman', 'module' => 'pinjaman'],
            ['label' => 'Layanan anggota', 'module' => 'layanan'],
        ]],
        ['label' => 'Laporan & pengaturan', 'items' => [
            ['label' => 'Pusat laporan', 'module' => 'laporan'],
            ['label' => 'SHU', 'module' => 'shu'],
            ['label' => 'Master & pengaturan', 'module' => 'pengaturan'],
            ['label' => 'Migrasi data', 'module' => 'migrasi'],
        ]],
    ],
    'teller' => [
        ['label' => 'Dashboard', 'route' => 'portal.dashboard'],
        ['label' => 'Anggota', 'route' => 'portal.members.index'],
        ['label' => 'Operasional', 'items' => [
            ['label' => 'Simpanan & kas', 'module' => 'simpanan-kas'],
            ['label' => 'Pinjaman', 'module' => 'pinjaman'],
            ['label' => 'Layanan anggota', 'module' => 'layanan'],
        ]],
        ['label' => 'Laporan', 'module' => 'laporan'],
    ],
    'anggota' => [
        ['label' => 'Dashboard', 'route' => 'portal.dashboard'],
        ['label' => 'Keuangan saya', 'items' => [
            ['label' => 'Simpanan saya', 'module' => 'simpanan-saya'],
            ['label' => 'Pinjaman saya', 'module' => 'pinjaman-saya'],
            ['label' => 'SHU saya', 'module' => 'shu-saya'],
        ]],
        ['label' => 'Waserda', 'module' => 'waserda'],
    ],
    'modules' => [
        'teller' => ['title' => 'Teller', 'phase' => 5],
        'simpanan-kas' => ['title' => 'Simpanan & kas', 'phase' => 6],
        'pinjaman' => ['title' => 'Pinjaman', 'phase' => 7],
        'layanan' => ['title' => 'Layanan anggota', 'phase' => 8],
        'laporan' => ['title' => 'Pusat laporan', 'phase' => 6],
        'shu' => ['title' => 'SHU', 'phase' => 8],
        'pengaturan' => ['title' => 'Master & pengaturan', 'phase' => 5],
        'migrasi' => ['title' => 'Migrasi data', 'phase' => 8],
        'simpanan-saya' => ['title' => 'Simpanan saya', 'phase' => 6],
        'pinjaman-saya' => ['title' => 'Pinjaman saya', 'phase' => 7],
        'shu-saya' => ['title' => 'SHU saya', 'phase' => 8],
        'waserda' => ['title' => 'Waserda', 'phase' => 8],
    ],
];
