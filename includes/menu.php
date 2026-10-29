<?php
/**
 * Single source of truth for sidebar menus per role.
 * Setiap item (termasuk anak) wajib punya title + icon; href untuk item klik.
 *
 * @return array<int,array{title:string,icon?:string,href?:string,pages?:array,children?:array,badge?:string}>
 */
function simkeu_menu_for_role(string $role): array
{
    if ($role === 'teller') {
        return [
            ['title' => 'Dashboard', 'icon' => 'ti ti-dashboard', 'href' => 'dashboard.php', 'pages' => ['dashboard.php']],
            [
                'title' => 'Transaksi', 'icon' => 'ti ti-arrows-exchange',
                'pages' => ['setoran.php', 'penarikan.php', 'pembelian.php'],
                'children' => [
                    ['title' => 'Setoran', 'icon' => 'ti ti-cash-banknote', 'href' => 'setoran.php', 'pages' => ['setoran.php']],
                    ['title' => 'Penarikan', 'icon' => 'ti ti-cash-off', 'href' => 'penarikan.php', 'pages' => ['penarikan.php']],
                    ['title' => 'Pembelian', 'icon' => 'ti ti-shopping-cart', 'href' => 'pembelian.php', 'pages' => ['pembelian.php']],
                ],
            ],
            [
                'title' => 'Peminjaman', 'icon' => 'ti ti-cash',
                'pages' => ['pengajuan.php', 'persetujuan.php', 'pencairan.php', 'angsuran.php', 'detail_angsuran.php', 'denda.php', 'pelunasan.php', 'layanan.php', 'detail_layanan.php'],
                'children' => [
                    ['title' => 'Pengajuan', 'icon' => 'ti ti-file-plus', 'href' => 'pengajuan.php', 'pages' => ['pengajuan.php']],
                    ['title' => 'Persetujuan', 'icon' => 'ti ti-file-check', 'href' => 'persetujuan.php', 'pages' => ['persetujuan.php']],
                    ['title' => 'Pencairan', 'icon' => 'ti ti-cash-banknote', 'href' => 'pencairan.php', 'pages' => ['pencairan.php']],
                    ['title' => 'Angsuran', 'icon' => 'ti ti-calendar-due', 'href' => 'angsuran.php', 'pages' => ['angsuran.php', 'detail_angsuran.php']],
                    ['title' => 'Macet & Layanan', 'icon' => 'ti ti-alert-triangle', 'href' => 'layanan.php?tab=macet', 'pages' => ['layanan.php', 'detail_layanan.php']],
                    ['title' => 'Denda', 'icon' => 'ti ti-coin', 'href' => 'denda.php', 'pages' => ['denda.php']],
                    ['title' => 'Pelunasan', 'icon' => 'ti ti-flag-check', 'href' => 'pelunasan.php', 'pages' => ['pelunasan.php']],
                ],
            ],
            [
                'title' => 'Data Anggota', 'icon' => 'ti ti-users',
                'pages' => ['anggota.php', 'tambah_anggota.php', 'detail_anggota.php'],
                'children' => [
                    ['title' => 'Daftar', 'icon' => 'ti ti-list', 'href' => 'anggota.php', 'pages' => ['anggota.php', 'detail_anggota.php', 'tambah_anggota.php']],
                ],
            ],
            [
                'title' => 'Laporan', 'icon' => 'ti ti-chart-bar',
                'pages' => ['laporan_harian.php', 'laporan_simpanan.php', 'laporan_lunas.php', 'laporan_denda.php', 'closing_angsuran.php'],
                'children' => [
                    ['title' => 'Laporan Lunas', 'icon' => 'ti ti-circle-check', 'href' => 'laporan_lunas.php', 'pages' => ['laporan_lunas.php']],
                    ['title' => 'Laporan Denda', 'icon' => 'ti ti-receipt-2', 'href' => 'laporan_denda.php', 'pages' => ['laporan_denda.php']],
                    ['title' => 'Harian', 'icon' => 'ti ti-calendar-stats', 'href' => 'laporan_harian.php', 'pages' => ['laporan_harian.php']],
                    ['title' => 'Simpanan', 'icon' => 'ti ti-pig-money', 'href' => 'laporan_simpanan.php', 'pages' => ['laporan_simpanan.php']],
                    ['title' => 'Closing Angsuran', 'icon' => 'ti ti-calendar-off', 'href' => 'closing_angsuran.php', 'pages' => ['closing_angsuran.php']],
                ],
            ],
            [
                'title' => 'Profil', 'icon' => 'ti ti-user-cog',
                'pages' => ['profil.php', 'ubah_password.php'],
                'children' => [
                    ['title' => 'Profil', 'icon' => 'ti ti-user', 'href' => 'profil.php', 'pages' => ['profil.php']],
                    ['title' => 'Password', 'icon' => 'ti ti-lock', 'href' => 'ubah_password.php', 'pages' => ['ubah_password.php']],
                ],
            ],
        ];
    }

    if ($role === 'admin') {
        return [
            ['title' => 'Dashboard', 'icon' => 'ti ti-dashboard', 'href' => 'dashboard.php', 'pages' => ['dashboard.php']],
            ['title' => 'Data Anggota', 'icon' => 'ti ti-users', 'href' => 'anggota.php', 'pages' => ['anggota.php', 'detail_anggota.php', 'export_anggota.php']],
            [
                'title' => 'Laporan', 'icon' => 'ti ti-chart-bar',
                'pages' => ['laporan_simpanan.php', 'laporan_transaksi.php', 'laporan_kas.php', 'laporan_pinjaman.php', 'laporan_angsuran.php', 'laporan_lunas.php', 'laporan_denda.php', 'closing_angsuran.php'],
                'children' => [
                    ['title' => 'Laporan Lunas', 'icon' => 'ti ti-circle-check', 'href' => 'laporan_lunas.php', 'pages' => ['laporan_lunas.php']],
                    ['title' => 'Laporan Denda', 'icon' => 'ti ti-receipt-2', 'href' => 'laporan_denda.php', 'pages' => ['laporan_denda.php']],
                    ['title' => 'Simpanan', 'icon' => 'ti ti-pig-money', 'href' => 'laporan_simpanan.php', 'pages' => ['laporan_simpanan.php']],
                    ['title' => 'Pinjaman', 'icon' => 'ti ti-report-money', 'href' => 'laporan_pinjaman.php', 'pages' => ['laporan_pinjaman.php']],
                    ['title' => 'Angsuran', 'icon' => 'ti ti-calendar-due', 'href' => 'laporan_angsuran.php', 'pages' => ['laporan_angsuran.php']],
                    ['title' => 'Closing Angsuran', 'icon' => 'ti ti-calendar-off', 'href' => 'closing_angsuran.php', 'pages' => ['closing_angsuran.php']],
                    ['title' => 'Transaksi', 'icon' => 'ti ti-transfer', 'href' => 'laporan_transaksi.php', 'pages' => ['laporan_transaksi.php']],
                    ['title' => 'Kas', 'icon' => 'ti ti-wallet', 'href' => 'laporan_kas.php', 'pages' => ['laporan_kas.php']],
                ],
            ],
            [
                'title' => 'Data Master', 'icon' => 'ti ti-database',
                'pages' => ['jenis_simpanan.php', 'jenis_pinjaman.php', 'teller.php'],
                'children' => [
                    ['title' => 'Jenis Simpanan', 'icon' => 'ti ti-stack-2', 'href' => 'jenis_simpanan.php', 'pages' => ['jenis_simpanan.php']],
                    ['title' => 'Jenis Pinjaman', 'icon' => 'ti ti-stack-3', 'href' => 'jenis_pinjaman.php', 'pages' => ['jenis_pinjaman.php']],
                    ['title' => 'Data Teller', 'icon' => 'ti ti-user-star', 'href' => 'teller.php', 'pages' => ['teller.php']],
                ],
            ],
            [
                'title' => 'Transaksi', 'icon' => 'ti ti-arrows-exchange',
                'pages' => ['setoran.php', 'penarikan.php', 'pembelian.php', 'koreksi.php'],
                'children' => [
                    ['title' => 'Setoran', 'icon' => 'ti ti-cash-banknote', 'href' => 'setoran.php', 'pages' => ['setoran.php']],
                    ['title' => 'Penarikan', 'icon' => 'ti ti-cash-off', 'href' => 'penarikan.php', 'pages' => ['penarikan.php']],
                    ['title' => 'Pembelian', 'icon' => 'ti ti-shopping-cart', 'href' => 'pembelian.php', 'pages' => ['pembelian.php']],
                    ['title' => 'Koreksi', 'icon' => 'ti ti-adjustments', 'href' => 'koreksi.php', 'pages' => ['koreksi.php']],
                ],
            ],
            [
                'title' => 'Pinjaman', 'icon' => 'ti ti-cash',
                'pages' => ['pengajuan.php', 'persetujuan.php', 'pencairan.php', 'angsuran.php', 'detail_angsuran.php', 'denda.php', 'pelunasan.php', 'layanan.php', 'detail_layanan.php'],
                'children' => [
                    ['title' => 'Pengajuan', 'icon' => 'ti ti-file-plus', 'href' => 'pengajuan.php', 'pages' => ['pengajuan.php']],
                    ['title' => 'Persetujuan', 'icon' => 'ti ti-file-check', 'href' => 'persetujuan.php', 'pages' => ['persetujuan.php']],
                    ['title' => 'Pencairan', 'icon' => 'ti ti-cash-banknote', 'href' => 'pencairan.php', 'pages' => ['pencairan.php']],
                    ['title' => 'Angsuran', 'icon' => 'ti ti-calendar-due', 'href' => 'angsuran.php', 'pages' => ['angsuran.php', 'detail_angsuran.php']],
                    ['title' => 'Macet & Layanan', 'icon' => 'ti ti-alert-triangle', 'href' => 'layanan.php?tab=macet', 'pages' => ['layanan.php', 'detail_layanan.php']],
                    ['title' => 'Denda', 'icon' => 'ti ti-coin', 'href' => 'denda.php', 'pages' => ['denda.php']],
                    ['title' => 'Pelunasan', 'icon' => 'ti ti-flag-check', 'href' => 'pelunasan.php', 'pages' => ['pelunasan.php']],
                ],
            ],
            [
                'title' => 'SHU', 'icon' => 'ti ti-calculator',
                'pages' => ['shu.php', 'shu_anggota.php', 'shu_laporan.php'],
                'children' => [
                    ['title' => 'Dashboard SHU', 'icon' => 'ti ti-chart-pie', 'href' => 'shu.php', 'pages' => ['shu.php']],
                    ['title' => 'Pembayaran SHU', 'icon' => 'ti ti-cash', 'href' => 'shu_anggota.php', 'pages' => ['shu_anggota.php']],
                    ['title' => 'Laporan SHU', 'icon' => 'ti ti-file-analytics', 'href' => 'shu_laporan.php', 'pages' => ['shu_laporan.php']],
                ],
            ],
            [
                'title' => 'Pengaturan', 'icon' => 'ti ti-settings',
                'pages' => ['pengaturan.php', 'pengaturan_pinjaman.php', 'backup.php', 'import_migrasi.php'],
                'children' => [
                    ['title' => 'Profil Koperasi', 'icon' => 'ti ti-building-bank', 'href' => 'pengaturan.php', 'pages' => ['pengaturan.php']],
                    ['title' => 'Pinjaman', 'icon' => 'ti ti-settings-dollar', 'href' => 'pengaturan_pinjaman.php', 'pages' => ['pengaturan_pinjaman.php']],
                    ['title' => 'Backup', 'icon' => 'ti ti-database-export', 'href' => 'backup.php', 'pages' => ['backup.php']],
                    ['title' => 'Import Migrasi', 'icon' => 'ti ti-file-import', 'href' => 'import_migrasi.php', 'pages' => ['import_migrasi.php']],
                ],
            ],
        ];
    }

    // anggota
    return [
        ['title' => 'Dashboard', 'icon' => 'ti ti-dashboard', 'href' => 'dashboard.php', 'pages' => ['dashboard.php']],
        ['title' => 'Simpanan Saya', 'icon' => 'ti ti-pig-money', 'href' => 'simpanan.php', 'pages' => ['simpanan.php', 'riwayat_simpanan.php']],
        ['title' => 'Pinjaman Saya', 'icon' => 'ti ti-cash', 'href' => 'pinjaman.php', 'pages' => ['pinjaman.php']],
        ['title' => 'Waserda Saya', 'icon' => 'ti ti-building-store', 'href' => 'waserda.php', 'pages' => ['waserda.php']],
        ['title' => 'SHU Saya', 'icon' => 'ti ti-calculator', 'href' => 'shu_saya.php', 'pages' => ['shu_saya.php']],
        ['title' => 'Profil Saya', 'icon' => 'ti ti-user', 'href' => 'profil.php', 'pages' => ['profil.php', 'ganti_password.php']],
    ];
}
