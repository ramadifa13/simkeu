@props(['member', 'disbursementPhoto' => null, 'role' => null])
@php
    $role = $role ?? request()->route('role');
    $avatarUrl = ($disbursementPhoto && \Illuminate\Support\Facades\Storage::disk('local')->exists($disbursementPhoto))
        ? route('portal.members.avatar', ['role' => $role, 'member' => $member->id]).'?v='.md5($disbursementPhoto)
        : null;
    $details = ['Kode anggota' => $member->kode_anggota, 'Kode anggota jaminan' => $member->kode_anggota_jaminan, 'Nama lengkap' => $member->nama_lengkap, 'Status' => ucfirst($member->status), 'Telepon' => $member->telepon, 'Email' => $member->email, 'Alamat' => $member->alamat, 'Tempat, tanggal lahir' => trim(($member->tempat_lahir ? $member->tempat_lahir.', ' : '').($member->tanggal_lahir?->translatedFormat('d F Y') ?? '')), 'Jenis kelamin' => $member->jenis_kelamin === 'L' ? 'Laki-laki' : ($member->jenis_kelamin === 'P' ? 'Perempuan' : null), 'Pekerjaan' => $member->pekerjaan, 'NIK / KTP' => $member->ktp, 'Rekening bank' => $member->rekening_bank, 'Kategori SHU' => $member->kategori_shu === 'ketua_kelompok' ? 'Ketua kelompok' : 'Anggota', 'Ketua kelompok' => $member->ketuaKelompok?->nama_lengkap, 'Tanggal daftar' => $member->tanggal_daftar?->translatedFormat('d F Y'), 'Nonaktif terjadwal' => $member->nonaktif_jadwal?->translatedFormat('d F Y'), 'ID anggota' => $member->id, 'ID akun' => $member->user_id, 'Dibuat pada' => $member->created_at?->translatedFormat('d F Y H:i')];
@endphp
<div class="mb-6 flex flex-wrap items-center gap-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
    @if ($avatarUrl)
        <img src="{{ $avatarUrl }}" alt="Foto pencairan {{ $member->nama_lengkap }}" class="size-20 rounded-2xl object-cover ring-2 ring-white" />
    @else
        <span class="grid size-20 place-items-center rounded-2xl bg-teal-100 text-2xl font-bold text-teal-800">{{ mb_substr($member->nama_lengkap, 0, 1) }}</span>
    @endif
    <div>
        <p class="text-sm font-semibold text-slate-900">Foto profil anggota</p>
        <p class="mt-1 text-sm text-slate-600">Menggunakan foto nasabah saat menerima uang (foto pencairan), sama seperti PHP native.</p>
    </div>
</div>
<dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">@foreach ($details as $label => $value)<div class="border-b border-slate-100 pb-4"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</dt><dd class="mt-1 whitespace-pre-line text-sm font-medium text-slate-900">{{ filled($value) ? $value : '—' }}</dd></div>@endforeach</dl>
