<?php

namespace App\Actions\Notifications;

use App\Models\Anggota;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Builds a small, role-scoped inbox from actionable loan records already migrated to Laravel. */
class GetPortalNotifications
{
    /**
     * @return Collection<int, array{title:string,description:string,href:string,icon:string,tone:string,group:string,actionable:bool}>
     */
    public function handle(User $user, string $role): Collection
    {
        return match ($role) {
            'admin' => $this->forAdmin(),
            'teller' => $this->forTeller($user),
            'anggota' => $this->forMember($user),
            default => collect(),
        };
    }

    /** Admin sees decisions waiting for Admin, not Teller collection reminders. */
    private function forAdmin(): Collection
    {
        $items = collect();

        if (Schema::hasTable('pengajuan_pinjaman')) {
            $pending = DB::table('pengajuan_pinjaman')->where('status', 'menunggu')->orderByDesc('tanggal_pengajuan')->limit(3)->get(['id', 'kode_pengajuan', 'tanggal_pengajuan']);
            foreach ($pending as $application) {
                $items->push($this->item('Pengajuan perlu ditinjau', $application->kode_pengajuan.' · '.Carbon::parse($application->tanggal_pengajuan)->translatedFormat('d M Y'), route('portal.loans.applications.show', ['role' => 'admin', 'application' => $application->id]), 'clipboard-document-check', 'amber', 'Perlu tindakan', true));
            }

            $ready = DB::table('pengajuan_pinjaman as pp')->where('pp.status', 'disetujui')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('pinjaman as p')->whereColumn('p.pengajuan_id', 'pp.id'))->orderByDesc('pp.tanggal_persetujuan')->limit(2)->get(['pp.id', 'pp.kode_pengajuan']);
            foreach ($ready as $application) {
                $items->push($this->item('Pengajuan siap dicairkan', $application->kode_pengajuan.' telah disetujui Admin.', route('portal.loans.applications.disburse.form', ['role' => 'admin', 'application' => $application->id]), 'banknotes', 'blue', 'Perlu tindakan', true));
            }
        }

        if (Schema::hasTable('pengajuan_pembatalan_angsuran')) {
            $requests = DB::table('pengajuan_pembatalan_angsuran as r')->join('angsuran as i', 'i.id', '=', 'r.angsuran_id')->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')->where('r.status', 'menunggu')->orderByDesc('r.tanggal_pengajuan')->limit(3)->get(['r.id', 'r.alasan', 'p.id as loan_id', 'p.kode_pinjaman', 'i.angsuran_ke']);
            foreach ($requests as $request) {
                $items->push($this->item('Pembatalan angsuran perlu diputuskan', $request->kode_pinjaman.' · angsuran ke-'.$request->angsuran_ke.' · '.str($request->alasan)->limit(52), route('portal.loans.show', ['role' => 'admin', 'loan' => $request->loan_id]), 'arrow-uturn-left', 'rose', 'Perlu tindakan', true));
            }
        }

        if (Schema::hasTable('layanan_pelunasan')) {
            $requests = DB::table('layanan_pelunasan as s')->join('pinjaman as p', 'p.id', '=', 's.pinjaman_id')->where('s.status', 'menunggu')->orderByDesc('s.created_at')->limit(3)->get(['p.id as loan_id', 'p.kode_pinjaman']);
            foreach ($requests as $request) {
                $items->push($this->item('Diskon pelunasan menunggu keputusan', $request->kode_pinjaman.' memerlukan keputusan Admin.', route('portal.loans.show', ['role' => 'admin', 'loan' => $request->loan_id, 'focus' => 'pelunasan']), 'check-badge', 'cyan', 'Perlu tindakan', true));
            }
        }

        if (Schema::hasTable('layanan_jaminan_kembali')) {
            $requests = DB::table('layanan_jaminan_kembali as k')->join('pinjaman as p', 'p.id', '=', 'k.pinjaman_id')->whereIn('k.status', ['menunggu', 'pengecekan'])->orderByDesc('k.id')->limit(3)->get(['p.id as loan_id', 'p.kode_pinjaman', 'k.status']);
            foreach ($requests as $request) {
                $items->push($this->item('Pengembalian jaminan perlu ditinjau', $request->kode_pinjaman.' · '.$request->status, route('portal.loans.show', ['role' => 'admin', 'loan' => $request->loan_id]), 'archive-box', 'amber', 'Perlu tindakan', true));
            }
        }

        if (Schema::hasTable('layanan_tunda_cair')) {
            $count = (int) DB::table('layanan_tunda_cair')->where('status', 'menunggu')->count();
            if ($count > 0) {
                $items->push($this->item('Tunda cair menunggu keputusan', $count.' pengajuan TC perlu diputuskan Admin.', route('portal.layanan.index', ['role' => 'admin', 'focus' => 'tunda_cair']), 'pause-circle', 'amber', 'Perlu tindakan', true));
            }
        }
        if (Schema::hasTable('layanan_pengajuan_hapus_denda')) {
            $requests = DB::table('layanan_pengajuan_hapus_denda as h')->join('pinjaman as p', 'p.id', '=', 'h.pinjaman_id')->where('h.status', 'menunggu')->orderByDesc('h.id')->limit(2)->get(['p.id as loan_id', 'p.kode_pinjaman']);
            foreach ($requests as $request) {
                $items->push($this->item('Pemutihan denda menunggu keputusan', $request->kode_pinjaman.' memerlukan keputusan Admin.', route('portal.layanan.show', ['role' => 'admin', 'loan' => $request->loan_id]), 'receipt-percent', 'rose', 'Perlu tindakan', true));
            }
        }
        if (Schema::hasTable('layanan_prioritas')) {
            $requests = DB::table('layanan_prioritas as pr')->join('pinjaman as p', 'p.id', '=', 'pr.pinjaman_id')->whereIn('pr.status', ['menunggu', 'pengecekan'])->orderByDesc('pr.id')->limit(2)->get(['p.id as loan_id', 'p.kode_pinjaman', 'pr.status']);
            foreach ($requests as $request) {
                $items->push($this->item('Prioritas menunggu tinjauan', $request->kode_pinjaman.' · '.$request->status, route('portal.layanan.show', ['role' => 'admin', 'loan' => $request->loan_id]), 'star', 'cyan', 'Perlu tindakan', true));
            }
        }
        if (Schema::hasTable('layanan_laporan_macet')) {
            $requests = DB::table('layanan_laporan_macet as m')->join('pinjaman as p', 'p.id', '=', 'm.pinjaman_id')->where('m.status', 'menunggu')->orderByDesc('m.id')->limit(2)->get(['p.id as loan_id', 'p.kode_pinjaman']);
            foreach ($requests as $request) {
                $items->push($this->item('Laporan macet menunggu keputusan', $request->kode_pinjaman.' memerlukan keputusan Admin.', route('portal.layanan.show', ['role' => 'admin', 'loan' => $request->loan_id]), 'exclamation-triangle', 'rose', 'Perlu tindakan', true));
            }
        }
        if (Schema::hasTable('layanan_bayar_denda') && Schema::hasColumn('layanan_bayar_denda', 'approved_by')) {
            $requests = DB::table('layanan_bayar_denda as d')->join('pinjaman as p', 'p.id', '=', 'd.pinjaman_id')->whereNull('d.approved_by')->orderByDesc('d.id')->limit(2)->get(['p.id as loan_id', 'p.kode_pinjaman']);
            foreach ($requests as $request) {
                $items->push($this->item('Pembayaran denda perlu ditinjau', $request->kode_pinjaman.' menunggu persetujuan Admin.', route('portal.layanan.show', ['role' => 'admin', 'loan' => $request->loan_id]), 'banknotes', 'amber', 'Perlu tindakan', true));
            }
        }

        return $this->prioritize($items)->take(8)->values();
    }

    private function forTeller(User $user): Collection
    {
        $items = collect();
        if (Schema::hasTable('pengajuan_pinjaman')) {
            $applications = DB::table('pengajuan_pinjaman')->where('user_id', $user->id)->whereIn('status', ['menunggu', 'disetujui', 'ditolak', 'dicairkan', 'batal_cair'])->orderByDesc('tanggal_persetujuan')->orderByDesc('tanggal_pengajuan')->limit(4)->get(['id', 'kode_pengajuan', 'status']);
            foreach ($applications as $application) {
                $pending = $application->status === 'menunggu';
                $ready = $application->status === 'disetujui';
                $label = $pending ? 'Pengajuan menunggu keputusan Admin' : ($ready ? 'Pengajuan siap dicairkan' : 'Status pengajuan diperbarui');
                $href = $ready
                    ? route('portal.loans.applications.disburse.form', ['role' => 'teller', 'application' => $application->id])
                    : route('portal.loans.applications.show', ['role' => 'teller', 'application' => $application->id]);
                $items->push($this->item($label, $application->kode_pengajuan.' · '.ucfirst(str_replace('_', ' ', $application->status)), $href, 'clipboard-document-check', $application->status === 'ditolak' || $application->status === 'batal_cair' ? 'rose' : ($pending || $ready ? 'amber' : 'emerald'), $pending || $ready ? 'Perlu dipantau' : 'Pembaruan', $pending || $ready));
            }
        }

        if (Schema::hasTable('pengajuan_pembatalan_angsuran')) {
            $hasSeenFlag = Schema::hasColumn('pengajuan_pembatalan_angsuran', 'dilihat_teller');
            $select = ['r.status', 'p.id as loan_id', 'p.kode_pinjaman', 'i.angsuran_ke'];
            if ($hasSeenFlag) {
                $select[] = 'r.dilihat_teller';
            }
            $requests = DB::table('pengajuan_pembatalan_angsuran as r')
                ->join('angsuran as i', 'i.id', '=', 'r.angsuran_id')
                ->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')
                ->where('r.teller_id', $user->id)
                ->whereIn('r.status', ['menunggu', 'disetujui', 'ditolak'])
                ->orderByDesc('r.tanggal_keputusan')
                ->orderByDesc('r.tanggal_pengajuan')
                ->limit(3)
                ->get($select);
            foreach ($requests as $request) {
                $unreadDecision = $hasSeenFlag && (int) ($request->dilihat_teller ?? 1) === 0 && $request->status !== 'menunggu';
                $title = match ($request->status) {
                    'menunggu' => 'Pengajuan pembatalan menunggu Admin',
                    'disetujui' => 'Pembatalan disetujui Admin',
                    default => 'Pembatalan ditolak Admin',
                };
                $items->push($this->item(
                    $title,
                    $request->kode_pinjaman.' · angsuran ke-'.$request->angsuran_ke,
                    route('portal.loans.show', ['role' => 'teller', 'loan' => $request->loan_id]),
                    'arrow-uturn-left',
                    $request->status === 'ditolak' ? 'rose' : ($request->status === 'menunggu' || $unreadDecision ? 'amber' : 'emerald'),
                    $request->status === 'menunggu' ? 'Perlu dipantau' : ($unreadDecision ? 'Belum dibaca' : 'Pembaruan'),
                    $request->status === 'menunggu' || $unreadDecision
                ));
            }
        }

        if (Schema::hasTable('layanan_pelunasan')) {
            $approved = DB::table('layanan_pelunasan as s')->join('pinjaman as p', 'p.id', '=', 's.pinjaman_id')->where('s.user_id', $user->id)->where('s.status', 'disetujui')->orderByDesc('s.diputuskan_at')->limit(2)->get(['p.id as loan_id', 'p.kode_pinjaman', 's.diskon_persen']);
            foreach ($approved as $request) {
                $items->push($this->item('Diskon pelunasan disetujui', $request->kode_pinjaman.' · diskon bunga '.number_format((float) $request->diskon_persen, 2, ',', '.').'%.', route('portal.loans.show', ['role' => 'teller', 'loan' => $request->loan_id, 'focus' => 'pelunasan']), 'check-badge', 'emerald', 'Pembaruan', true));
            }
        }

        if (Schema::hasTable('layanan_jaminan_kembali')) {
            $ready = DB::table('layanan_jaminan_kembali as k')->join('pinjaman as p', 'p.id', '=', 'k.pinjaman_id')->where('k.status', 'disetujui')->orderByDesc('k.diperiksa_at')->limit(2)->get(['p.id as loan_id', 'p.kode_pinjaman']);
            foreach ($ready as $request) {
                $items->push($this->item('Jaminan siap diserahkan', $request->kode_pinjaman.' telah disetujui Admin.', route('portal.loans.show', ['role' => 'teller', 'loan' => $request->loan_id]), 'archive-box-arrow-down', 'blue', 'Perlu tindakan', true));
            }
        }

        if (Schema::hasTable('layanan_tunda_cair')) {
            $rejected = DB::table('layanan_tunda_cair as tc')
                ->join('pengajuan_pinjaman as pp', 'pp.id', '=', 'tc.pengajuan_id')
                ->where('tc.user_id', $user->id)
                ->where('tc.status', 'ditolak')
                ->where('pp.status', 'disetujui')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pinjaman as p')->whereColumn('p.pengajuan_id', 'pp.id'))
                ->orderByDesc('tc.diputuskan_at')
                ->limit(2)
                ->get(['pp.kode_pengajuan']);
            foreach ($rejected as $row) {
                $items->push($this->item('TC ditolak Admin', $row->kode_pengajuan.' kembali dapat dicairkan.', route('portal.loans.applications.index', ['role' => 'teller']), 'pause-circle', 'amber', 'Perlu tindakan', true));
            }
        }
        if (Schema::hasTable('layanan_pengajuan_hapus_denda')) {
            $decisions = DB::table('layanan_pengajuan_hapus_denda as h')->join('pinjaman as p', 'p.id', '=', 'h.pinjaman_id')->where('h.user_id', $user->id)->whereIn('h.status', ['disetujui', 'ditolak'])->orderByDesc('h.diputuskan_at')->limit(2)->get(['p.id as loan_id', 'p.kode_pinjaman', 'h.status']);
            foreach ($decisions as $row) {
                $items->push($this->item('Keputusan hapus denda', $row->kode_pinjaman.' · '.$row->status, route('portal.layanan.show', ['role' => 'teller', 'loan' => $row->loan_id]), 'receipt-percent', $row->status === 'ditolak' ? 'rose' : 'emerald', 'Pembaruan', false));
            }
        }

        $items = $items->concat($this->dueNotices('teller'))->concat($this->penaltyNotices('teller'));
        $items = $items->concat($this->paymentNotices('teller', (int) $user->id));

        return $this->prioritize($items)->take(8)->values();
    }

    private function forMember(User $user): Collection
    {
        $memberId = (int) Anggota::query()->where('user_id', $user->id)->value('id');
        if (! $memberId) {
            return collect();
        }

        $items = collect();
        if (Schema::hasTable('pengajuan_pinjaman')) {
            $applications = DB::table('pengajuan_pinjaman')->where('anggota_id', $memberId)->whereIn('status', ['menunggu', 'disetujui', 'ditolak', 'dicairkan', 'batal_cair'])->orderByDesc('tanggal_persetujuan')->orderByDesc('tanggal_pengajuan')->limit(4)->get(['id', 'kode_pengajuan', 'status']);
            foreach ($applications as $application) {
                $label = $application->status === 'menunggu' ? 'Pengajuan pinjaman sedang diproses' : 'Status pengajuan pinjaman diperbarui';
                $items->push($this->item($label, $application->kode_pengajuan.' · '.ucfirst(str_replace('_', ' ', $application->status)), route('portal.loans.index', ['role' => 'anggota']), 'banknotes', $application->status === 'ditolak' || $application->status === 'batal_cair' ? 'rose' : ($application->status === 'menunggu' ? 'amber' : 'emerald'), $application->status === 'menunggu' ? 'Dalam proses' : 'Pembaruan', false));
            }
        }

        $items = $items->concat($this->dueNotices('anggota', $memberId))->concat($this->penaltyNotices('anggota', $memberId));
        if (Schema::hasTable('layanan_jaminan_kembali')) {
            $collateral = DB::table('layanan_jaminan_kembali as k')->join('pinjaman as p', 'p.id', '=', 'k.pinjaman_id')->where('p.anggota_id', $memberId)->whereIn('k.status', ['menunggu', 'pengecekan', 'disetujui', 'ditolak', 'diserahkan'])->orderByDesc('k.diperiksa_at')->orderByDesc('k.created_at')->limit(2)->get(['k.status', 'p.id as loan_id', 'p.kode_pinjaman']);
            foreach ($collateral as $request) {
                $items->push($this->item('Status pengembalian jaminan', $request->kode_pinjaman.' · '.ucfirst($request->status), route('portal.loans.show', ['role' => 'anggota', 'loan' => $request->loan_id]), 'archive-box', $request->status === 'ditolak' ? 'rose' : ($request->status === 'diserahkan' ? 'emerald' : 'blue'), 'Pembaruan', false));
            }
        }
        $items = $items->concat($this->paymentNotices('anggota', $memberId));

        return $this->prioritize($items)->take(8)->values();
    }

    /** Reminders match the native seven-day upcoming and overdue buckets. */
    private function dueNotices(string $role, ?int $memberId = null): Collection
    {
        if (! Schema::hasTable('angsuran') || ! Schema::hasTable('pinjaman')) {
            return collect();
        }

        $query = DB::table('angsuran as i')->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')->where('p.status', 'aktif')->whereIn('i.status', ['belum_bayar', 'telat'])->orderBy('i.tanggal_jatuh_tempo');
        if ($memberId !== null) {
            $query->where('p.anggota_id', $memberId);
        }

        return $query->whereDate('i.tanggal_jatuh_tempo', '<=', today()->addDays(7)->toDateString())->limit(4)->get(['i.angsuran_ke', 'i.tanggal_jatuh_tempo', 'p.id as loan_id', 'p.kode_pinjaman'])
            ->map(function (object $installment) use ($role): array {
                $overdue = $installment->tanggal_jatuh_tempo < today()->toDateString();
                $title = $overdue ? 'Angsuran melewati jatuh tempo' : 'Angsuran jatuh tempo dalam 7 hari';
                $date = Carbon::parse($installment->tanggal_jatuh_tempo)->translatedFormat('d M Y');

                return $this->item($title, $installment->kode_pinjaman.' · angsuran ke-'.$installment->angsuran_ke.' · '.$date, route('portal.loans.show', ['role' => $role, 'loan' => $installment->loan_id]), 'calendar-days', $overdue ? 'rose' : 'amber', 'Jadwal', true);
            });
    }

    private function penaltyNotices(string $role, ?int $memberId = null): Collection
    {
        if (! Schema::hasTable('layanan_bayar_denda')) {
            return collect();
        }

        $query = DB::table('angsuran as i')->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')->leftJoin('layanan_bayar_denda as d', 'd.angsuran_id', '=', 'i.id')->where('p.status', 'aktif')->where('i.status', '<>', 'lunas');
        if ($memberId !== null) {
            $query->where('p.anggota_id', $memberId);
        }

        return $query->groupBy('i.id', 'i.denda', 'p.id', 'p.kode_pinjaman', 'i.angsuran_ke')->havingRaw('i.denda - COALESCE(SUM(d.jumlah), 0) > 0.005')->orderByDesc('i.denda')->limit(2)->get(['i.angsuran_ke', 'p.id as loan_id', 'p.kode_pinjaman'])->map(fn (object $installment): array => $this->item('Denda belum lunas', $installment->kode_pinjaman.' · angsuran ke-'.$installment->angsuran_ke, route($role === 'anggota' ? 'portal.loans.show' : 'portal.loans.penalties', $role === 'anggota' ? ['role' => $role, 'loan' => $installment->loan_id] : ['role' => $role, 'q' => $installment->kode_pinjaman]), 'exclamation-triangle', 'rose', 'Perlu ditindaklanjuti', true));
    }

    private function paymentNotices(string $role, ?int $actorId = null): Collection
    {
        if (! Schema::hasTable('layanan_pembayaran')) {
            return collect();
        }

        $query = DB::table('layanan_pembayaran as lp')->join('pinjaman as p', 'p.id', '=', 'lp.pinjaman_id')->join('anggota as a', 'a.id', '=', 'p.anggota_id')->whereNull('lp.dibatalkan_at')->orderByDesc('lp.created_at')->limit(2);
        if ($role === 'teller') {
            $query->where('lp.user_id', $actorId);
        } elseif ($role === 'anggota') {
            $query->where('p.anggota_id', $actorId);
        }

        return $query->get(['lp.jenis', 'lp.jumlah', 'p.id as loan_id', 'p.kode_pinjaman', 'a.nama_lengkap'])->map(function (object $payment) use ($role): array {
            $title = match ($payment->jenis) {
                'denda' => 'Pembayaran denda tercatat',
                'pelunasan' => 'Pelunasan pinjaman tercatat',
                default => 'Pembayaran angsuran tercatat',
            };
            $description = $payment->kode_pinjaman.' · '.$payment->nama_lengkap.' · Rp '.number_format((float) $payment->jumlah, 0, ',', '.');

            return $this->item($title, $description, route('portal.loans.show', ['role' => $role, 'loan' => $payment->loan_id]), 'banknotes', 'emerald', 'Riwayat', false);
        });
    }

    /**
     * @param  Collection<int, array{title:string,description:string,href:string,icon:string,tone:string,group:string,actionable:bool}>  $items
     * @return Collection<int, array{title:string,description:string,href:string,icon:string,tone:string,group:string,actionable:bool}>
     */
    private function prioritize(Collection $items): Collection
    {
        return $items->sortByDesc(fn (array $item): int => $item['actionable'] ? 1 : 0)->values();
    }

    /** @return array{title:string,description:string,href:string,icon:string,tone:string,group:string,actionable:bool} */
    private function item(string $title, string $description, string $href, string $icon, string $tone, string $group, bool $actionable = false): array
    {
        return compact('title', 'description', 'href', 'icon', 'tone', 'group', 'actionable');
    }
}
