@props(['href' => '#', 'initials', 'name', 'memberCode', 'loanCode', 'plafond', 'remaining', 'schedule', 'completed', 'total'])
@php($percent = $total > 0 ? min(100, max(0, round($completed / $total * 100))) : 0)

<a href="{{ $href }}" class="ui-installment-card">
    <flux:avatar :initials="$initials" color="teal" circle />
    <div class="min-w-0 flex-1"><div class="ui-installment-card-top"><div class="min-w-0"><p class="truncate font-semibold text-slate-950">{{ $name }} <span>{{ $memberCode }}</span></p><p class="mt-1 font-mono text-xs font-semibold text-teal-700">{{ $loanCode }}</p></div><div class="flex gap-1"><span class="ui-code-badge">AGT</span><x-status-badge status="Aktif" /></div></div><div class="ui-installment-stats"><div><span>Plafon</span><strong>{{ $plafond }}</strong></div><div><span>Sisa pokok</span><strong class="text-teal-700">{{ $remaining }}</strong></div><div><span>Jadwal</span><strong class="font-medium">{{ $schedule }}</strong></div><div><p><span>Progress</span><strong>{{ $completed }}/{{ $total }}</strong></p><div class="ui-installment-progress"><span style="width: {{ $percent }}%"></span></div></div></div></div>
    <span class="ui-installment-go" aria-hidden="true">›</span>
</a>
