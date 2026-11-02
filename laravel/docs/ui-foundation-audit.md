# Audit Fondasi UI/UX SIMKEU

**Tujuan:** menyatukan keputusan tampilan sebelum setiap modul bisnis dibangun. Halaman `portal/admin/ui-preview` adalah *living preview* internal, bukan menu operasional dan tidak memakai/mengubah data produksi.

## Cakupan yang sudah diaudit

| Kelompok | Fondasi yang harus sama di semua modul | Validasi di preview |
| --- | --- | --- |
| Arah visual | canvas slate, surface putih, aksen teal, teks navy/slate berkontras, radius, shadow, wave header | token, hero, status semantik |
| Shell | sidebar sticky/collapsible, header akun/notifikasi, mobile drawer, skip link | layout portal responsif |
| Hierarki halaman | eyebrow, judul, deskripsi, aksi utama | header wave dan tombol primer |
| Aksi | primer, sekunder, ghost, bahaya; tiga titik hanya pada baris tabel | tombol dan action menu |
| Form | label, bantuan, error, disabled, select pendek, autocomplete panjang, textarea, upload | form pendek dan form panjang |
| Data | heading tabel, toolbar filter, search, sort, badge, menu baris, pagination, kolom prioritas mobile | tabel contoh, skeleton, kosong |
| Detail | kartu ringkasan, tab, timeline audit, dokumen privat | ringkasan/detail contoh |
| Feedback | sukses, peringatan, error, loading/retry, empty | notifikasi dan state |
| Aksesibilitas | fokus terlihat, label, keyboard, target nyaman, kontras, reduced motion | checklist dan kontrol fokus |
| Responsif | mobile-first, tablet grid, desktop density, tabel horizontal yang terkendali | panduan breakpoint |

## Keputusan yang tidak boleh berubah per modul

1. Semua input memakai Flux UI; pilihan sedikit memakai select dan pilihan panjang memakai autocomplete.
2. Form singkat ada di modal; form panjang, unggahan, dan transaksi finansial memakai halaman tersendiri.
3. Tombol aksi utama selalu memakai `variant="primary"` dengan aksen teal dan teks putih; tombol sekunder memakai `outline` dengan teks slate. Menu tiga titik hanya untuk aksi kontekstual baris tabel.
4. Tabel selalu menyediakan state memuat, kosong, gagal/retry, search/filter/sort, pagination, serta kolom prioritas di mobile.
5. Warna tidak membawa makna sendiri tanpa teks; status memakai label dan kontras minimum yang terbaca.
6. Animasi hanya micro-interaction singkat dan wajib mematuhi `prefers-reduced-motion`.

## Yang perlu disetujui melalui preview

- Kepadatan teks, padding baris tabel, ukuran kontrol, dan bentuk tombol.
- Nada warna teal, gelap, sukses, peringatan, dan bahaya pada layar nyata.
- Bentuk hero/wave, kartu, tab, modal, dan empty/loading state.
- Perilaku pada mobile, tablet, dan desktop sebelum diterapkan ke Fase 4 dan seterusnya.

Setelah disetujui, primitive di `resources/css/app.css` dan `resources/views/components/` menjadi satu-satunya sumber pola. Halaman preview dapat dihapus tanpa memengaruhi fitur bisnis.
