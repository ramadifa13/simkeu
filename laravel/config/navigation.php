<?php

return [
    'admin' => [
        ['label' => 'Dashboard', 'route' => 'portal.dashboard', 'icon' => 'squares-2x2'],
        ['label' => 'Anggota & staf', 'icon' => 'users', 'items' => [
            ['label' => 'Data anggota', 'route' => 'portal.members.index'],
            ['label' => 'Teller', 'route' => 'portal.tellers.index'],
        ]],
        ['label' => 'Operasional', 'icon' => 'briefcase', 'items' => [
            ['label' => 'Simpanan & kas', 'route' => 'portal.savings.index'],
            ['label' => 'Buku kas', 'route' => 'portal.cash.index', 'match' => 'exact'],
            ['label' => 'Rekonsiliasi kas', 'route' => 'portal.cash.reconciliation.index'],
            ['label' => 'Koreksi transaksi', 'route' => 'portal.savings.corrections.index'],
            ['label' => 'Waserda', 'route' => 'portal.waserda.index'],
        ]],
        ['label' => 'Pinjaman', 'icon' => 'banknotes', 'items' => [
            ['label' => 'Pengajuan', 'route' => 'portal.loans.applications.index', 'active_when' => ['portal.loans.applications']],
            ['label' => 'Angsuran', 'route' => 'portal.loans.index', 'active_when' => ['portal.loans.index', 'portal.loans.show', 'portal.loans.receipt']],
            ['label' => 'Keranjang', 'route' => 'portal.loans.cart', 'badge' => 'loan_cart', 'active_when' => ['portal.loans.cart']],
            ['label' => 'Denda', 'route' => 'portal.loans.penalties', 'active_when' => ['portal.loans.penalties']],
            ['label' => 'Pelunasan', 'route' => 'portal.loans.settlements', 'active_when' => ['portal.loans.settlements', 'portal.loans.settlement']],
            ['label' => 'Layanan pinjaman', 'route' => 'portal.layanan.index', 'active_when' => ['portal.layanan']],
            ['label' => 'Penutupan periode', 'route' => 'portal.loans.closing.index', 'active_when' => ['portal.loans.closing']],
        ]],
        ['label' => 'Laporan & pengaturan', 'icon' => 'chart-bar', 'items' => [
            ['label' => 'Pusat laporan', 'route' => 'portal.reports.index'],
            ['label' => 'SHU', 'route' => 'portal.shu.index', 'active_when' => ['portal.shu']],
            ['label' => 'Master data', 'route' => 'portal.master.index'],
            ['label' => 'Pengaturan koperasi', 'route' => 'portal.settings.cooperative.edit'],
            ['label' => 'Perjanjian & layanan', 'route' => 'portal.settings.agreement.edit'],
            ['label' => 'Audit aktivitas', 'route' => 'portal.audit.index'],
            ['label' => 'Pengingat WhatsApp', 'route' => 'portal.whatsapp.index', 'active_when' => ['portal.whatsapp']],
            ['label' => 'Migrasi data', 'route' => 'portal.migration.index', 'active_when' => ['portal.migration']],
            ['label' => 'Backup data', 'route' => 'portal.backup.index', 'active_when' => ['portal.backup']],
        ]],
    ],
    'teller' => [
        ['label' => 'Dashboard', 'route' => 'portal.dashboard', 'icon' => 'squares-2x2'],
        ['label' => 'Anggota', 'route' => 'portal.members.index', 'icon' => 'users'],
        ['label' => 'Operasional', 'icon' => 'briefcase', 'items' => [
            ['label' => 'Simpanan & kas', 'route' => 'portal.savings.index'],
            ['label' => 'Waserda', 'route' => 'portal.waserda.index'],
        ]],
        ['label' => 'Pinjaman', 'icon' => 'banknotes', 'items' => [
            ['label' => 'Pengajuan', 'route' => 'portal.loans.applications.index', 'active_when' => ['portal.loans.applications']],
            ['label' => 'Angsuran', 'route' => 'portal.loans.index', 'active_when' => ['portal.loans.index', 'portal.loans.show', 'portal.loans.receipt']],
            ['label' => 'Keranjang', 'route' => 'portal.loans.cart', 'badge' => 'loan_cart', 'active_when' => ['portal.loans.cart']],
            ['label' => 'Denda', 'route' => 'portal.loans.penalties', 'active_when' => ['portal.loans.penalties']],
            ['label' => 'Pelunasan', 'route' => 'portal.loans.settlements', 'active_when' => ['portal.loans.settlements', 'portal.loans.settlement']],
            ['label' => 'Layanan pinjaman', 'route' => 'portal.layanan.index', 'active_when' => ['portal.layanan']],
        ]],
        ['label' => 'Laporan', 'route' => 'portal.reports.index', 'icon' => 'chart-bar', 'active_when' => ['portal.reports']],
    ],
    'anggota' => [
        ['label' => 'Dashboard', 'route' => 'portal.dashboard', 'icon' => 'squares-2x2'],
        ['label' => 'Keuangan saya', 'icon' => 'wallet', 'items' => [
            ['label' => 'Simpanan saya', 'route' => 'portal.savings.mine'],
            ['label' => 'Pinjaman saya', 'route' => 'portal.loans.index'],
            ['label' => 'SHU saya', 'route' => 'portal.shu.mine'],
            ['label' => 'Waserda saya', 'route' => 'portal.waserda.mine'],
        ]],
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
        'waserda' => ['title' => 'Waserda', 'phase' => 6],
    ],
];
