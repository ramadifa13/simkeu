# Kontrak Data Dashboard Native

Dokumen ini menjadi sumber Fase 4. Dashboard tidak boleh menghitung ulang KPI dengan definisi berbeda dari native tanpa keputusan bisnis tertulis.

| KPI / daftar | Sumber dan definisi native | Target role |
| --- | --- | --- |
| Anggota / NOA | jumlah anggota aktif/nonaktif, pendaftaran hari ini; tren anggota baru dan kumulatif berdasarkan `tanggal_daftar` | Admin, Teller sesuai scope |
| Simpanan | saldo per jenis dari transaksi setoran dikurangi penarikan; total saldo aggregate | Admin, Teller, Anggota miliknya |
| Kas | kas masuk/keluar, saldo dari baris kas terakhir; setoran, angsuran, Waserda, penarikan, pencairan dipisahkan menurut keterangan/jenis | Admin, Teller terbatas operasional |
| Pinjaman | total, aktif, lunas, macet, pokok tersisa, bunga tersisa, jadwal/jatuh tempo, status pengajuan dan siap cair | Admin, Teller; Anggota miliknya |
| Pertumbuhan laba | bunga ÷ total angsuran. Prioritas memakai closing terkunci terbaru; jika belum ada memakai angsuran lunas yang tanggal bayar dan jatuh temponya berada di bulan yang sama | Admin |
| Tren telat | angsuran `telat`, keterlambatan >0, atau lunas setelah jatuh tempo; dikelompokkan bulan jatuh tempo | Admin, Teller |
| Tren denda | denda terbentuk per jatuh tempo; denda tertagih dari `layanan_bayar_denda`, lalu fallback denda angsuran lunas yang belum memiliki ledger denda | Admin, Teller |
| Kolektibilitas/macet | pinjaman dengan tunggakan minimal 7 hari, termasuk rekonstruksi point-in-time dari jadwal untuk tren historis | Admin, Teller |
| Waserda | count/nominal keseluruhan, hari ini, dan transaksi terbaru | Admin, Teller, Anggota miliknya |
| Aktivitas terbaru | transaksi, angsuran, dan Waserda terbaru dengan anggota, produk, dan petugas | role-scoped |

## Aturan implementasi

- Query dashboard diletakkan pada Action/query object per role; tidak di Blade atau Livewire component.
- Kartu dan alert harus menautkan ke daftar berfilter yang memakai policy sama.
- Untuk KPI historical yang mengandalkan rekonstruksi, tampilkan label periode dan sumber (closing/preview) agar pengguna tidak mengira nilai final.
- Semua query memakai timezone Asia/Jakarta dan batas waktu eksplisit.
- Anggota tidak boleh menerima count, nominal, atau aktivitas milik anggota lain; Teller tidak boleh menerima aksi Admin meskipun kartu terlihat.
