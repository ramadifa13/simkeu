@props(['member', 'role'])

<div class="grid gap-4 sm:grid-cols-3">
    @foreach (['foto_ktp' => 'KTP', 'foto_kk' => 'Kartu Keluarga', 'foto_ktp_pasangan' => 'KTP Pasangan'] as $field => $label)
        <article @class(['ui-doc-card', 'is-'.$loop->index])><p class="font-semibold text-slate-900">{{ $label }}</p><p class="mt-2 text-sm {{ $member->{$field} ? 'text-emerald-700' : 'text-slate-500' }}">{{ $member->{$field} ? 'Dokumen tersedia' : 'Belum tersedia' }}</p>@if ($member->{$field})<a href="{{ route('portal.members.documents.show', ['role' => $role, 'member' => $member, 'document' => $field]) }}" target="_blank" rel="noopener" class="mt-4 inline-flex text-sm font-semibold text-teal-700 hover:text-sky-700">Buka dokumen</a>@endif</article>
    @endforeach
</div>
