# Urutan Migrasi Database Hosting

Selalu buat backup database sebelum import. Setiap migrasi cukup dijalankan satu kali melalui phpMyAdmin pada database aplikasi yang sama.

## A. Hosting yang sebelumnya sudah menjalankan migrasi sampai 10gh

Setelah `git pull`, cukup import:

1. `database/migrations/2026_10i_perjanjian_ttd_elektronik.sql`

File `10i` aman dijalankan ulang jika sebelumnya sudah sempat diimport, termasuk untuk menambahkan kolom identitas Pihak Pertama yang baru.

## B. Instalasi baru dari database awal

Import `simkeusi_koperasi_db.sql`, kemudian jalankan berurutan:

1. `database/migrations/2026_10_revisi_koperasi.sql`
2. `database/migrations/2026_10b_cara_bayar_angsuran.sql`
3. `database/migrations/2026_10_revisi_tahap2.sql`
4. `database/migrations/2026_10c_denda.sql`
5. `database/migrations/2026_10_revisi_tahap3.sql`
6. `database/migrations/2026_10d_input_mundur.sql`
7. `database/migrations/2026_10e_hari_angsuran.sql`
8. `database/migrations/2026_10f_gadai_tenor_6_bulan.sql`
9. `database/migrations/2026_10gh_kode_dan_foto_gadai.sql`
10. `database/migrations/2026_10i_perjanjian_ttd_elektronik.sql`

Jika memakai file gabungan `10gh`, jangan jalankan file `10g` dan `10h` secara terpisah.

## Setelah migrasi 10i

Login Admin, buka **Pengaturan → Surat Perjanjian & TTD Admin**, lalu lengkapi identitas Pihak Pertama, jabatan, template ringkas/lengkap, dan TTD Admin. Setelah disimpan, Teller dapat melakukan pencairan dan menghasilkan PDF perjanjian.
