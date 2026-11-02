# Audit Fondasi UI/UX SIMKEU

**Tujuan:** menyatukan keputusan tampilan sebelum setiap modul bisnis dibangun. Halaman validasi sementara `portal/admin/ui-preview` telah dihapus pada 2026-11-03 setelah semua pola yang disetujui diekstrak ke komponen Blade reusable. Dokumen ini adalah peta sumber pola, bukan menu operasional.

## Cakupan yang sudah diaudit

| Kelompok | Fondasi yang harus sama di semua modul | Implementasi reusable |
| --- | --- | --- |
| Arah visual | canvas slate, surface putih, aksen teal, teks navy/slate berkontras, radius, shadow, wave header | token, hero, status semantik |
| Shell | sidebar sticky/collapsible, header akun/notifikasi, avatar fallback, tooltip ikon, mobile drawer/bottom sheet, skip link | layout portal responsif |
| Hierarki halaman | eyebrow, judul, deskripsi, aksi utama | header wave dan tombol primer |
| Aksi | primer, sekunder, ghost, bahaya; tiga titik hanya pada baris tabel | tombol dan action menu |
| Form | teks, email, sandi, telepon, angka/mata uang, tanggal/waktu, textarea, select, radio, checkbox, autocomplete, upload, label, bantuan, error, disabled | katalog input lengkap, form pendek, dan form panjang |
| Data | heading tabel, toolbar filter, search, sort, date range, chip filter, badge, menu baris, selection/bulk action, pagination, kolom prioritas mobile | tabel contoh, skeleton, kosong |
| List angsuran | filter status pembatalan dan hari minggu ini, pencarian, kartu pinjaman nasabah, plafon/sisa pokok/jadwal/progress, detail jadwal, aksi baris, keranjang pembayaran | pola list angsuran statis dari native; logika finansial tetap Fase 7 |
| Detail | kartu ringkasan, tab, timeline audit, dokumen privat | ringkasan/detail contoh |
| Feedback | toast sukses/peringatan/gagal/info, callout, validasi, loading/retry, empty, offline | notifikasi interaktif dan state |
| Aksesibilitas | fokus terlihat, label, keyboard, target nyaman, kontras, reduced motion | checklist dan kontrol fokus |
| Responsif | mobile-first, tablet grid, desktop density, tabel horizontal yang terkendali | panduan breakpoint |
| Kegagalan sistem | 403, 404, 419, 500, koneksi terputus, perubahan belum tersimpan | kartu state dan modal konfirmasi |

## Keputusan yang tidak boleh berubah per modul

1. Semua input memakai Flux UI; pilihan sedikit memakai select dan pilihan panjang memakai autocomplete.
2. Form singkat ada di modal; form panjang, unggahan, dan transaksi finansial memakai halaman tersendiri.
3. Tombol aksi utama selalu memakai `variant="primary"` dengan aksen teal dan teks putih; tombol sekunder memakai `outline` dengan teks slate. Menu tiga titik hanya untuk aksi kontekstual baris tabel.
4. Tabel selalu menyediakan state memuat, kosong, gagal/retry, search/filter/sort, pagination, serta kolom prioritas di mobile.
5. Warna tidak membawa makna sendiri tanpa teks; status memakai label dan kontras minimum yang terbaca.
6. Animasi hanya micro-interaction singkat dan wajib mematuhi `prefers-reduced-motion`.
7. Semua jenis kontrol yang tersedia di UI Preview harus memakai primitive ini; jangan membuat input, modal, toast, atau status baru per modul tanpa terlebih dahulu menambahkannya ke fondasi.

## Peta komponen reusable

- **Data:** `data-table`, `table-filters`, `filter-chips`, `bulk-action-bar`, `pagination`, `action-menu`, `empty-state`, `loading-state`, dan `tabs`.
- **Form dan feedback:** `form-section`, `form-actions`, `autocomplete`, `file-upload`, `image-upload`, `signature-field`, `upload-progress`, `short-form-modal`, `confirm-action-modal`, `bottom-sheet`, `side-drawer`, `unsaved-changes-modal`, `retry-state`, `offline-banner`, serta toast/callout Flux.
- **Ringkasan dan sistem:** `page-header`, `stat-card`, `status-badge`, `system-state-card`, `audit-timeline`, dan `document-preview`.
- **Angsuran:** `installments/filter-chips`, `installments/loan-card`, `installments/schedule-table`, dan `installments/payment-cart`.

Semua komponen berada pada `resources/views/components/`; tidak ada salinan markup fondasi pada suatu halaman demo. Perubahan pada satu komponen otomatis tersedia untuk semua modul yang memakainya.

## Aturan penggunaan dan review

- Kepadatan teks, padding baris tabel, ukuran kontrol, dan bentuk tombol.
- Nada warna teal, gelap, sukses, peringatan, dan bahaya pada layar nyata.
- Bentuk hero/wave, kartu, tab, modal, dan empty/loading state.
- Perilaku pada mobile, tablet, dan desktop sebelum diterapkan ke Fase 4 dan seterusnya.

Primitive di `resources/css/app.css` dan `resources/views/components/` menjadi satu-satunya sumber pola. Setiap pola baru wajib dibuat sebagai komponen atau primitive di sana terlebih dahulu, bukan ditulis ulang di halaman modul.
