# Audit kesetaraan dashboard native → Laravel

Status audit: **parsial tervalidasi** (2026-11-03). Dokumen ini menjadi kontrak sebelum Fase 4 ditutup.

| Area | Sumber PHP native | Implementasi Laravel | Status |
|---|---|---|---|
| Admin: anggota | `dashboardLoadStaffData()` → `anggota` | `GetDashboardData::staffData()` | Tervalidasi query dan role |
| Admin: simpanan, setoran, kas | `transaksi`, `kas`, `jenis_simpanan` | `staffData()` + detail `savings`, `deposits-today`, `cash` | Tervalidasi query dan drill-down |
| Admin: pinjaman, tagihan, antrean | `pinjaman`, `angsuran`, `pengajuan_pinjaman` | `staffData()` + `loans`, `receivables`, `approvals`, `disbursements` | Tervalidasi query dan drill-down |
| Admin: Waserda | `waserda_transaksi` | `staffData()` + detail `waserda` | Tervalidasi bila tabel tersedia |
| Admin: tren enam bulan | NOA, telat, denda, laba native | `trendSeries()` | Tervalidasi struktur; angka perlu UAT per periode |
| Teller: transaksi pribadi | transaksi/kas teller | `staffData($user->id)` | Tervalidasi scope user |
| Anggota: data pribadi | `anggota.user_id`, simpanan, pinjaman, angsuran | `member()` + detail `*-mine` | Tervalidasi scope anggota |

## Kriteria penutupan

- Bandingkan setiap KPI pada tiga tanggal uji: tanpa transaksi, transaksi normal, dan transaksi terlambat/lunas.
- Bandingkan nominal dashboard dengan hasil laporan modul Laravel setelah Fase 5–8 selesai.
- Pastikan satu akun anggota tidak dapat membuka report anggota lain dan Teller tidak dapat membuka dashboard Admin.
- Catat selisih, alasan bisnis, serta keputusan pemilik produk sebelum Fase 4 dinyatakan selesai.

Dashboard tidak melakukan mutasi data. Detail dashboard juga read-only; pencatatan transaksi tetap berada pada modul berotorisasi.
