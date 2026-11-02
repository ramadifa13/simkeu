<x-layouts.portal :role="$role" :title="$definition['title']">
    <div class="mx-auto max-w-7xl space-y-5 lg:space-y-6">
        <x-page-header icon="chart-bar" eyebrow="Detail dashboard" :title="$definition['title']" :description="$definition['description']">
            <x-slot:actions><flux:button :href="route('portal.dashboard', ['role' => $role])" variant="outline" icon="arrow-left">Kembali ke dashboard</flux:button></x-slot:actions>
        </x-page-header>
        <section class="ui-panel overflow-hidden"><div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="funnel" class="size-4" /></span><h2>Filter dashboard diterapkan</h2></div><span class="ui-table-count">{{ $definition['rows']->count() }} baris</span></div><div class="overflow-x-auto"><table class="w-full min-w-[42rem] text-sm"><thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr>@foreach ($definition['headers'] as $header)<th class="px-5 py-3">{{ $header }}</th>@endforeach</tr></thead><tbody class="divide-y divide-slate-100">@forelse ($definition['rows'] as $row)<tr>@foreach ((array) $row as $value)<td class="px-5 py-3 text-slate-700">{{ is_numeric($value) ? number_format((float) $value, 0, ',', '.') : ($value ?: '—') }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($definition['headers']) }}"><x-empty-state class="border-0 bg-transparent py-12" :title="$definition['empty']" icon="inbox" /></td></tr>@endforelse</tbody></table></div></section>
    </div>
</x-layouts.portal>
