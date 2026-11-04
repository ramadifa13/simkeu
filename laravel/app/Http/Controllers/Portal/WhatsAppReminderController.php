<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WhatsAppReminderController extends Controller
{
    public function index(Request $request, string $role): View
    {
        $this->authorize('manage-cooperative-settings');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['terkirim', 'gagal', 'dilewati', 'antrian'])],
            'per_page' => ['nullable', Rule::in([5, 10, 25, 50])],
        ]);

        $logs = collect();
        $paginator = null;

        if (Schema::hasTable('wa_pengingat_log')) {
            $query = DB::table('wa_pengingat_log as w')
                ->leftJoin('angsuran as i', 'i.id', '=', 'w.angsuran_id')
                ->leftJoin('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')
                ->leftJoin('anggota as a', 'a.id', '=', 'p.anggota_id')
                ->select([
                    'w.id', 'w.angsuran_id', 'w.tanggal_jadwal', 'w.nomor', 'w.status',
                    'w.message_id', 'w.error_message', 'w.percobaan', 'w.sent_at', 'w.updated_at',
                    'p.kode_pinjaman', 'a.nama_lengkap', 'a.kode_anggota', 'i.angsuran_ke',
                ]);

            if (filled($filters['q'] ?? null)) {
                $term = '%'.$filters['q'].'%';
                $query->where(function ($builder) use ($term): void {
                    $builder->where('w.nomor', 'like', $term)
                        ->orWhere('a.nama_lengkap', 'like', $term)
                        ->orWhere('a.kode_anggota', 'like', $term)
                        ->orWhere('p.kode_pinjaman', 'like', $term);
                });
            }
            if (filled($filters['status'] ?? null)) {
                $query->where('w.status', $filters['status']);
            }

            $paginator = $query->orderByDesc('w.id')->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
            $logs = $paginator;
        }

        return view('portal.whatsapp.index', [
            'role' => $role,
            'logs' => $logs,
            'filters' => $filters,
            'available' => Schema::hasTable('wa_pengingat_log'),
        ]);
    }
}
