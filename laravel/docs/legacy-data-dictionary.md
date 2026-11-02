# Kamus Data Legacy dan Klasifikasi Migrasi

Sumber: struktur clone `simkeu_laravel` diverifikasi 2026-11-02. Setiap daftar **Field lengkap** mencakup seluruh kolom tabel pada saat pemeriksaan. Kategori: **Dipertahankan** (dipakai aktif), **Historis baca-saja** (ditampilkan/diimpor tetapi tidak diedit bebas), **Digabung** (menjadi bagian domain/aggregate lain), **Dipindah** (ke storage privat/log Laravel), atau **Tinjau** (keputusan bisnis sebelum mutasi baru).

## Identitas dan konfigurasi

| Tabel | Field lengkap | Klasifikasi / pemilik |
| --- | --- | --- |
| `users` | `id`, `username`, `password`, `nama_lengkap`, `email`, `telepon`, `foto_profil`, `role`, `status`, `last_login`, `created_at` | Dipertahankan sebagai akun; hash dan histori login baca-saja. Rinciannya di Field & Rule Catalogue. |
| `user_permissions` | `id`, `user_id`, `permission`, `enabled`, `created_at` | Dipertahankan; policy/Gate per izin. Tinjau daftar permission aktif sebelum menggantikan role-only policy. |
| `anggota` | `id`, `kode_anggota`, `kode_anggota_jaminan`, `kategori_shu`, `ketua_kelompok_id`, `nama_lengkap`, `alamat`, `telepon`, `email`, `tempat_lahir`, `tanggal_lahir`, `jenis_kelamin`, `pekerjaan`, `ktp`, `foto_ktp`, `foto_kk`, `foto_ktp_pasangan`, `rekening_bank`, `user_id`, `status`, `nonaktif_jadwal`, `tanggal_daftar`, `created_at` | Dipertahankan; tiga foto dipindah ke storage privat, KTP/rekening sensitif, `created_at` historis baca-saja. |
| `koperasi` | `id`, `nama`, `alamat`, `logo`, `telepon`, `email`, `rekening_bank`, `created_at` | Dipertahankan sebagai konfigurasi tunggal; logo dipindah ke storage privat/public terkontrol, `created_at` historis. |
| `settings` | `id`, `setting_key`, `setting_value`, `created_at`, `updated_at` | Dipertahankan sebagai key-value terkontrol Admin; kunci dikenal harus divalidasi, bukan input bebas. |
| `periode` | `id`, `tahun`, `bulan`, `status`, `tanggal_buka`, `tanggal_tutup`, `created_at` | Dipertahankan sebagai periode operasional; status dan tanggal menjadi lock rule. |
| `pengaturan_perjanjian` | `id`, `nama_pihak_pertama`, `nik_pihak_pertama`, `alamat_pihak_pertama`, `telepon_pihak_pertama`, `jabatan_admin`, `template_default`, `isi_ringkas`, `isi_lengkap`, `ttd_admin`, `updated_by`, `updated_at` | Dipertahankan; NIK/tanda tangan sensitif, tanda tangan dipindah ke storage privat atau terenkripsi sesuai hasil review. |

## Simpanan dan kas

| Tabel | Field lengkap | Klasifikasi / pemilik |
| --- | --- | --- |
| `jenis_simpanan` | `id`, `kode_jenis`, `nama_jenis`, `deskripsi`, `minimum`, `maksimum`, `bagi_hasil`, `status`, `created_at` | Dipertahankan sebagai master; `created_at` historis. |
| `simpanan` | `id`, `anggota_id`, `jenis_simpanan_id`, `saldo`, `created_at`, `updated_at` | Dipertahankan sebagai saldo aggregate; `saldo` hanya diubah Action ledger transaksional. |
| `transaksi` | `id`, `kode_transaksi`, `anggota_id`, `jenis_simpanan_id`, `jenis_transaksi`, `jumlah`, `saldo_sebelum`, `saldo_sesudah`, `keterangan`, `user_id`, `tanggal_transaksi`, `created_at` | Dipertahankan sebagai ledger historis baca-saja; koreksi/reversal membuat jejak baru, tidak edit bebas. |
| `kas` | `id`, `transaksi_id`, `jenis`, `jumlah`, `saldo_sebelum`, `saldo_sesudah`, `keterangan`, `tanggal` | Dipertahankan sebagai ledger historis baca-saja; saldo berantai dikelola service khusus. |
| `log_koreksi` | `id`, `transaksi_id`, `jumlah_lama`, `jumlah_baru`, `selisih`, `keterangan`, `user_id`, `created_at` | Dipertahankan sebagai audit historis; digabung dengan tampilan activity log tanpa menghapus detail finansial. |
| `waserda_transaksi` | `id`, `kode_transaksi`, `anggota_id`, `items`, `total_bayar`, `metode_bayar`, `user_id`, `created_at` | Dipertahankan; `items` historis JSON/text baca-saja sampai format item disepakati; transaksi/kas relasi harus diverifikasi. |
| `pembelian_listrik` | `id`, `kode_transaksi`, `anggota_id`, `nomor_token`, `jumlah_kwh`, `harga_per_kwh`, `total_bayar`, `metode_bayar`, `user_id`, `created_at` | Dipertahankan sebagai produk layanan; token adalah data sensitif operasional, histori baca-saja. |
| `pembelian_indihome` | `id`, `kode_transaksi`, `anggota_id`, `no_pelanggan`, `paket_layanan`, `bulan_tagihan`, `total_bayar`, `metode_bayar`, `user_id`, `created_at` | Dipertahankan sebagai produk layanan; nomor pelanggan sensitif, histori baca-saja. |

## Master dan siklus pinjaman

| Tabel | Field lengkap | Klasifikasi / pemilik |
| --- | --- | --- |
| `jenis_pinjaman` | `id`, `kode_jenis`, `nama_jenis`, `maksimal_pinjaman`, `tenor_max`, `bunga`, `frekuensi_diizinkan`, `memerlukan_jaminan`, `deskripsi`, `status`, `created_at` | Dipertahankan sebagai master produk; perubahan hanya Admin dan tidak mengubah snapshot pinjaman lama. |
| `jenis_pinjaman_tarif` | `id`, `jenis_pinjaman_id`, `tenor_bulan`, `bunga`, `created_at` | Dipertahankan sebagai tarif master; perlu unique business rule produk+tenor. |
| `denda_tarif` | `id`, `jumlah_pinjaman`, `denda_per_hari`, `created_at` | Dipertahankan sebagai master denda; hanya Admin, dipakai calculator server-side. |
| `pengajuan_pinjaman` | `id`, `kode_pengajuan`, `anggota_id`, `jenis_pinjaman_id`, `jumlah_pinjaman`, `tenor`, `bunga`, `skema_bunga`, `frekuensi`, `tujuan_pinjaman`, `status`, `catatan_admin`, `admin_id`, `user_id`, `tanggal_pengajuan`, `tanggal_persetujuan`, `created_at`, `hari_angsuran`, `fasilitas_prioritas`, `kategori_shu`, `ketua_kelompok_id` | Dipertahankan sebagai state machine; keputusan/waktu/pelaku historis baca-saja setelah transisi. |
| `pinjaman` | `id`, `pengajuan_id`, `kode_pinjaman`, `anggota_id`, `jenis_pinjaman_id`, `jumlah_pinjaman`, `sisa_pokok`, `tenor`, `bunga`, `skema_bunga`, `frekuensi`, `jumlah_cicilan`, `total_pinjaman`, `angsuran_per_bulan`, `tanggal_pencairan`, `jatuh_tempo`, `foto_pencairan`, `dicairkan_oleh`, `tanggal_lunas`, `status`, `created_at`, `input_mundur`, `cicilan_dimuka`, `kategori_shu`, `ketua_kelompok_id` | Dipertahankan; foto dipindah privat, nilai/jadwal adalah snapshot historis, status hanya Action transaksional. |
| `angsuran` | `id`, `pinjaman_id`, `angsuran_ke`, `jumlah_angsuran`, `pokok`, `bunga`, `denda`, `denda_manual`, `tanggal_jatuh_tempo`, `tanggal_bayar`, `status`, `cara_bayar`, `keterlambatan_hari`, `created_at` | Dipertahankan sebagai jadwal/ledger; denda otomatis/manual serta status hanya calculator/payment Action. |
| `jaminan_pinjaman` | `id`, `pengajuan_id`, `pinjaman_id`, `jenis_barang`, `identitas_barang`, `deskripsi_barang`, `nilai_taksiran`, `foto_barang`, `foto_nasabah_barang`, `status_jaminan`, `tanggal_dikembalikan`, `created_at` | Dipertahankan; identitas/foto jaminan privat dan state `dititipkan`/`dikembalikan`/`dilelang` terkunci setelah tindakan. |
| `pelunasan_dini` | `id`, `pinjaman_id`, `jumlah_pelunasan`, `keterangan`, `user_id`, `cek_jaminan_nasabah`, `ttd_jaminan_nasabah`, `created_at` | Dipertahankan sebagai histori pelunasan; tanda tangan dipindah privat/terenkripsi. |
| `pengajuan_pembatalan_angsuran` | `id`, `angsuran_id`, `teller_id`, `alasan`, `tanggal_pengajuan`, `status`, `aktif`, `admin_id`, `catatan_admin`, `tanggal_keputusan`, `dilihat_teller` | Dipertahankan sebagai approval state machine; tidak boleh dihapus setelah keputusan. |
| `closing_angsuran_bulanan` | `id`, `bulan`, `status`, `ditutup_at`, `ditutup_oleh`, `dibuka_at`, `dibuka_oleh`, `catatan`, `total_pembayaran`, `jumlah_pembayaran` | Dipertahankan sebagai lock/snapshot periode; status dan audit hanya Admin. |
| `closing_angsuran_detail` | `id`, `closing_id`, `angsuran_id`, `nominal`, `tanggal_bayar` | Dipertahankan sebagai detail snapshot historis baca-saja. |

## Layanan lanjutan dan pendanaan

| Tabel | Field lengkap | Klasifikasi / pemilik |
| --- | --- | --- |
| `layanan_pengaturan` | `id`, `diskon_pelunasan`, `pemutihan`, `wa_denda`, `wa_lunas`, `denda_jaminan_per_juta`, `surat_lunas`, `bast_jaminan`, `bonus_persen` | Dipertahankan sebagai konfigurasi sensitif Admin; template pesan/dokumen perlu sanitasi dan audit. |
| `layanan_audit` | `id`, `pinjaman_id`, `user_id`, `aksi`, `detail`, `created_at` | Dipertahankan historis; digabung pada timeline audit tanpa memutasi data asli. |
| `layanan_pembayaran` | `id`, `angsuran_id`, `pinjaman_id`, `user_id`, `jenis`, `jumlah`, `metode`, `bukti`, `created_at`, `dibatalkan_at`, `bunga_diskon` | Dipertahankan sebagai payment ledger; bukti dipindah privat dan pembatalan soft reversal. |
| `layanan_bayar_denda` | `id`, `angsuran_id`, `pinjaman_id`, `user_id`, `jumlah`, `metode`, `bukti`, `transaksi_id`, `approved_by`, `created_at`, `hari_telat`, `tarif`, `denda_awal`, `denda_akhir` | Dipertahankan sebagai histori pelunasan denda/snapshot kalkulasi; bukti privat. |
| `layanan_pelunasan` | `id`, `pinjaman_id`, `user_id`, `status`, `diskon_persen`, `admin_id`, `diputuskan_at`, `catatan`, `created_at` | Dipertahankan sebagai approval pelunasan; transition policy per peran. |
| `layanan_pengajuan_hapus_denda` | `id`, `pinjaman_id`, `angsuran_id`, `user_id`, `alasan`, `status`, `admin_id`, `created_at`, `diputuskan_at`, `aktif` | Dipertahankan sebagai approval pemutihan; tidak menghapus denda tanpa jejak. |
| `layanan_laporan_macet` | `id`, `pinjaman_id`, `user_id`, `hari_telat`, `sisa_hutang`, `catatan`, `status`, `admin_id`, `keputusan`, `created_at`, `diputuskan_at` | Dipertahankan sebagai workflow kolektibilitas/keputusan. |
| `layanan_jaminan_kembali` | `id`, `pinjaman_id`, `user_id`, `status`, `admin_id`, `foto`, `ttd`, `diserahkan_at`, `alasan`, `bukti_jaminan`, `bukti_lunas`, `catatan_admin`, `diperiksa_at`, `ttd_teller` | Dipertahankan; seluruh media/tanda tangan dipindah privat, penyerahan wajib workflow ber-audit. |
| `layanan_lelang` | `id`, `pinjaman_id`, `user_id`, `harga_jual`, `terpakai`, `kredit`, `kelebihan`, `dikembalikan`, `metode`, `bukti`, `transaksi_id`, `catatan`, `created_at` | Dipertahankan sebagai penyelesaian jaminan; nilai dan bukti histori baca-saja. |
| `layanan_alokasi_lelang` | `id`, `lelang_id`, `angsuran_id`, `jumlah`, `created_at` | Dipertahankan sebagai alokasi historis ke angsuran. |
| `layanan_bonus` | `id`, `pinjaman_id`, `anggota_id`, `tahun`, `bunga_dasar`, `persen`, `jumlah`, `metode`, `bukti`, `user_id`, `transaksi_id`, `created_at` | Dipertahankan sebagai pembayaran bonus; bukti privat dan nilai snapshot. |
| `layanan_prioritas` | `id`, `pinjaman_id`, `anggota_id`, `user_id`, `status`, `admin_id`, `catatan`, `koreksi`, `koreksi_admin_id`, `koreksi_at` | Dipertahankan sebagai workflow prioritas dan koreksi Admin. |
| `layanan_repeat_order` | `pinjaman_id`, `user_id`, `catatan`, `created_at` | Dipertahankan sebagai histori/flag repeat order; primary key/unique database perlu diverifikasi sebelum model. |
| `layanan_tunda_cair` | `id`, `pengajuan_id`, `user_id`, `status`, `alasan`, `admin_id`, `catatan_admin`, `diputuskan_at`, `created_at` | Dipertahankan sebagai workflow tunda pencairan. |
| `pendanaan_dapin` | `id`, `kode_dapin`, `nama_pendana`, `tanggal_diterima`, `modal_diterima`, `tenor`, `total_wajib_kembali`, `tanggal_jatuh_tempo`, `status`, `catatan`, `created_at` | Dipertahankan sebagai pendanaan; nilai keuangan historis dan status terkontrol. |
| `alokasi_dapin` | `id`, `source_key`, `dapin_id`, `pinjaman_id`, `modal_dialokasikan`, `kewajiban_dapin`, `tanggal_alokasi`, `catatan`, `created_at` | Dipertahankan sebagai alokasi pendanaan historis; `source_key` untuk idempotensi import. |

## Perjanjian, SHU, migrasi, dan notifikasi

| Tabel | Field lengkap | Klasifikasi / pemilik |
| --- | --- | --- |
| `perjanjian_pinjaman` | `id`, `pinjaman_id`, `nomor_perjanjian`, `versi`, `snapshot_json`, `nama_saksi_1`, `nama_saksi_2`, `ttd_nasabah`, `ttd_teller`, `ttd_admin`, `ttd_saksi_1`, `ttd_saksi_2`, `hash_dokumen`, `ditandatangani_at` | Dipertahankan sebagai artefak legal historis; snapshot/hash/tanda tangan immutable dan privat. |
| `lampiran_perjanjian` | `id`, `perjanjian_id`, `file_path`, `urutan` | Dipertahankan; file dipindah/ditautkan ke storage privat dan urutan dipertahankan. |
| `shu_periode` | `id`, `tahun`, `nama_periode`, `tanggal_mulai`, `tanggal_selesai`, `status`, `total_shu`, `kebijakan_json`, `alokasi_json`, `snapshot_hash`, `referensi_rat`, `dibuat_oleh`, `dihitung_at`, `disahkan_oleh`, `disahkan_at`, `created_at` | Dipertahankan sebagai lifecycle snapshot; final immutable kecuali proses pembalikan resmi. |
| `shu_anggota` | `id`, `shu_periode_id`, `anggota_id`, `nama_snapshot`, `kode_snapshot`, `total_simpanan`, `total_pinjaman`, `total_angsuran`, `total_transaksi`, `jasa_simpanan`, `jasa_pinjaman`, `jasa_transaksi`, `total_shu`, `status_bayar`, `tanggal_bayar`, `pembayaran_id` | Dipertahankan sebagai alokasi historis baca-saja; snapshot identitas tidak diganti saat profil berubah. |
| `shu_hak_pinjaman` | `id`, `pinjaman_id`, `anggota_id`, `sumber_anggota_id`, `jenis`, `kategori_snapshot`, `dasar_pinjaman`, `persen`, `jumlah`, `pembayaran_lancar`, `status_bayar`, `metode`, `bukti`, `referensi`, `dibayar_oleh`, `dibayar_at`, `transaksi_id`, `created_at` | Dipertahankan sebagai hak/pembayaran historis; bukti privat dan status terkunci setelah bayar. |
| `shu_pembayaran` | `id`, `shu_anggota_id`, `jumlah`, `metode`, `bukti`, `referensi`, `nonce`, `user_id`, `transaksi_id`, `created_at`, `dibatalkan_at`, `dibatalkan_oleh`, `alasan_batal`, `transaksi_balik_id` | Dipertahankan; nonce untuk idempotensi, bukti privat, pembatalan adalah reversal. |
| `shu_audit` | `id`, `periode_id`, `user_id`, `aksi`, `detail`, `created_at` | Dipertahankan historis; digabung tampilan audit, bukan diubah. |
| `shu_hak_audit` | `id`, `hak_id`, `user_id`, `aksi`, `detail`, `created_at` | Dipertahankan historis; digabung tampilan audit, bukan diubah. |
| `migrasi_import_item` | `id`, `jenis`, `source_key`, `target_id`, `created_at` | Dipertahankan sebagai registry idempotensi import; tidak untuk UI umum. |
| `migrasi_pembayaran` | `id`, `source_key`, `pinjaman_id`, `transaksi_id`, `tanggal_bayar`, `angsuran_ke`, `jenis_pembayaran`, `pokok`, `bunga`, `denda`, `total_diterima`, `status`, `keterangan`, `created_at` | Historis baca-saja, dipertahankan untuk traceability pembayaran import. |
| `migrasi_anggota_dokumen` | `anggota_id`, `foto`, `ttd`, `updated_at` | Dipindah/digabung ke layanan dokumen privat; histori asal dipertahankan. |
| `migrasi_dokumen_cadangan` | `id`, `jenis`, `source_key`, `kode_anggota`, `foto_ktp`, `foto_kk`, `foto_ktp_pasangan`, `ttd_anggota`, `foto_pencairan`, `foto_barang`, `foto_nasabah_barang`, `perjanjian_json`, `updated_at` | Historis baca-saja dan sangat sensitif; hanya command recovery Admin, bukan tampilan reguler. |
| `wa_pengingat_log` | `id`, `angsuran_id`, `tanggal_jadwal`, `nomor`, `status`, `message_id`, `error_message`, `percobaan`, `sent_at`, `updated_at` | Dipertahankan sebagai log job/notifikasi; nomor/error PII, akses Admin terbatas. |

## Tabel baru Laravel

`migrations` dan `activity_logs` bukan field legacy. `migrations` adalah internal Laravel; `activity_logs` menjadi audit lintas modul server-side. Keduanya tidak menjadi objek import UI.
