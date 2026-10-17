# Migrasi Database Hosting

Selalu buat backup database sebelum melakukan perubahan.

Setelah `git pull`, cukup import satu file melalui phpMyAdmin:

`database/migrations/MIGRASI_UNIVERSAL.sql`

File universal dapat dipakai meskipun tidak diketahui migrasi lama mana yang sudah dijalankan. Struktur dan data referensi yang sudah ada akan dilewati sehingga tidak menimbulkan error duplikat.

Untuk instalasi benar-benar baru, import `simkeusi_koperasi_db.sql` terlebih dahulu. Sesudah itu import `MIGRASI_UNIVERSAL.sql`. File universal adalah peningkatan struktur, bukan pengganti database awal.

Setelah migrasi selesai, login Admin dan buka **Pengaturan → Surat Perjanjian & TTD Admin**. Lengkapi identitas Pihak Pertama, jabatan, template surat, dan TTD Admin sebelum Teller melakukan pencairan.
