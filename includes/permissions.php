<?php
/**
 * Single Source of Truth — izin halaman & aksi per role.
 *
 * Path gate di auth.php memakai area folder (admin/teller/anggota).
 * Matriks ini untuk: (1) aksi bisnis, (2) assertion menu, (3) halaman
 * yang boleh dibuka lintas-folder lewat thin wrapper di area role.
 */

if (!function_exists('simkeu_permissions')) {
    /**
     * @return array{
     *   pages: array<string, list<string>>,
     *   actions: array<string, list<string>>
     * }
     */
    function simkeu_permissions(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $staff = ['admin', 'teller'];
        $admin = ['admin'];
        $anggota = ['anggota'];
        $all = ['admin', 'teller', 'anggota'];

        $map = [
            'pages' => [
                // Shared staff features (URL tetap di folder role masing-masing)
                'dashboard.php' => $all,
                'anggota.php' => $staff,
                'detail_anggota.php' => $staff,
                'setoran.php' => $staff,
                'penarikan.php' => $staff,
                'pembelian.php' => $staff,
                'koreksi.php' => $admin,
                'pengajuan.php' => $staff,
                'persetujuan.php' => $staff,
                'pencairan.php' => $staff,
                'angsuran.php' => $staff,
                'detail_angsuran.php' => $staff,
                'pelunasan.php' => $staff,
                'layanan.php' => $staff,
                'detail_layanan.php' => $staff,
                'denda.php' => $staff,
                'closing_angsuran.php' => $staff,
                'get_saldo.php' => $staff,
                'laporan_lunas.php' => $staff,
                'laporan_denda.php' => $staff,
                'laporan_simpanan.php' => $staff,
                'laporan_harian.php' => $staff,
                'laporan_pinjaman.php' => $admin,
                'laporan_angsuran.php' => $admin,
                'laporan_transaksi.php' => $admin,
                'laporan_kas.php' => $admin,
                'laporan_anggota.php' => $admin,
                'jenis_simpanan.php' => $admin,
                'jenis_pinjaman.php' => $admin,
                'teller.php' => $admin,
                'shu.php' => $admin,
                'shu_anggota.php' => $admin,
                'shu_laporan.php' => $admin,
                'pengaturan.php' => $admin,
                'pengaturan_pinjaman.php' => $admin,
                'backup.php' => $admin,
                'import_migrasi.php' => $admin,
                'profil.php' => ['teller', 'anggota'],
                'ubah_password.php' => ['teller'],
                'ganti_password.php' => $anggota,
                'simpanan.php' => $anggota,
                'riwayat_simpanan.php' => $anggota,
                'pinjaman.php' => $anggota,
                'waserda.php' => $anggota,
                'shu_saya.php' => $anggota,
            ],
            'actions' => [
                'closing.lock' => $admin,
                'angsuran.bayar' => $staff,
                'angsuran.batal_langsung' => $admin,
                'angsuran.ajukan_batal' => $staff,
                'angsuran.putuskan_batal' => $admin,
                'angsuran.pemutihan_denda' => $admin,
                'pengajuan.buat' => $staff,
                'pengajuan.setujui' => $admin,
                'pengajuan.tolak' => $admin,
                'pengajuan.hapus' => $admin,
                'pencairan.cairkan' => $staff,
                'pencairan.keputusan_tc' => $admin,
                'layanan.pengaturan' => $admin,
                'koreksi.mutate' => $admin,
                'master.mutate' => $admin,
                'shu.mutate' => $admin,
            ],
        ];

        return $map;
    }
}

if (!function_exists('simkeu_current_role')) {
    function simkeu_current_role(): string
    {
        return (string)($_SESSION['role'] ?? '');
    }
}

if (!function_exists('can')) {
    /**
     * Cek izin aksi bisnis (bukan path folder).
     */
    function can(string $action): bool
    {
        $role = simkeu_current_role();
        if ($role === '') {
            return false;
        }
        $allowed = simkeu_permissions()['actions'][$action] ?? null;
        if ($allowed === null) {
            // Aksi belum terdaftar: fail closed untuk mutate-ish names
            return false;
        }
        return in_array($role, $allowed, true);
    }
}

if (!function_exists('can_page')) {
    /**
     * Cek apakah role boleh membuka halaman (basename script).
     */
    function can_page(string $scriptBasename): bool
    {
        $role = simkeu_current_role();
        if ($role === '') {
            return false;
        }
        $pages = simkeu_permissions()['pages'];
        if (!isset($pages[$scriptBasename])) {
            // Halaman tidak terdaftar: izinkan lewat path-gate area saja
            return true;
        }
        return in_array($role, $pages[$scriptBasename], true);
    }
}

if (!function_exists('require_can')) {
    function require_can(string $action): void
    {
        if (!can($action)) {
            http_response_code(403);
            exit('Akses ditolak. Tindakan ini tidak tersedia untuk peran Anda.');
        }
    }
}

if (!function_exists('require_can_page')) {
    function require_can_page(?string $scriptBasename = null): void
    {
        $script = $scriptBasename ?? basename(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($script === '' || !can_page($script)) {
            http_response_code(403);
            exit('Akses ditolak. Halaman ini tidak tersedia untuk peran Anda.');
        }
    }
}
