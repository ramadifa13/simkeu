@props(['trends' => []])

@php
    $tabs = [
        'portfolio' => ['label' => 'Portofolio', 'title' => 'Pertumbuhan NOA', 'primary' => 'Anggota baru', 'secondary' => 'Kumulatif anggota', 'format' => 'number', 'icon' => 'users', 'tone' => 'teal'],
        'collectibility' => ['label' => 'Kolektibilitas', 'title' => 'Telat angsuran', 'primary' => 'Cicilan telat', 'secondary' => 'Nominal terlambat', 'format' => 'money', 'icon' => 'exclamation-triangle', 'tone' => 'amber'],
        'penalties' => ['label' => 'Denda', 'title' => 'Denda operasional', 'primary' => 'Denda terbentuk', 'secondary' => 'Denda tertagih', 'format' => 'money', 'icon' => 'receipt-percent', 'tone' => 'rose'],
        'profit' => ['label' => 'Laba', 'title' => 'Pertumbuhan laba', 'primary' => 'Bunga / angsuran', 'secondary' => 'Bunga diterima', 'format' => 'percent', 'icon' => 'chart-bar', 'tone' => 'sky'],
    ];
@endphp

<section {{ $attributes->merge(['class' => 'ui-analytics-panel']) }} x-data="{ tab: 'portfolio' }" aria-labelledby="analytics-title">
    <div class="ui-analytics-hero"><div><div class="ui-analytics-kicker"><flux:icon name="chart-bar" class="size-4" /> Analitik dashboard</div><h2 id="analytics-title">Tren operasional</h2><p>Bandingkan kesehatan portofolio dalam enam bulan terakhir.</p></div><span class="ui-analytics-period">6 bulan terakhir</span></div>
    <div class="ui-analytics-tabs" role="tablist" aria-label="Jenis analitik">
        @foreach ($tabs as $key => $tab)
            <button type="button" role="tab" x-on:click="tab = '{{ $key }}'" x-bind:aria-selected="tab === '{{ $key }}'" :class="tab === '{{ $key }}' ? 'is-active is-{{ $tab['tone'] }}' : ''" class="ui-analytics-tab">
                <flux:icon :name="$tab['icon']" class="size-4" /><span>{{ $tab['label'] }}</span>
            </button>
        @endforeach
    </div>
    @foreach ($tabs as $key => $tab)
        @php($rows = $trends[$key] ?? [])
        <div x-show="tab === '{{ $key }}'" x-cloak class="ui-analytics-content" role="tabpanel">
            @php($last = collect($rows)->last())
            <div class="ui-analytics-summary"><div><span>{{ $tab['primary'] }}</span><strong>{{ $tab['format'] === 'money' ? 'Rp '.number_format((float) data_get($last, 'value', 0), 0, ',', '.') : ($tab['format'] === 'percent' ? number_format((float) data_get($last, 'value', 0), 1, ',', '.').'%' : number_format((int) data_get($last, 'value', 0))) }}</strong><small>Bulan ini</small></div><div><span>{{ $tab['secondary'] }}</span><strong>{{ $tab['format'] === 'number' ? number_format((int) data_get($last, 'secondary', 0)) : 'Rp '.number_format((float) data_get($last, 'secondary', 0), 0, ',', '.') }}</strong><small>Nilai pendukung</small></div><div class="ui-analytics-insight"><flux:icon :name="$tab['icon']" class="size-5" /><span>Gunakan tab untuk membaca detail tanpa membuat dashboard terlalu padat.</span></div></div>
            <div class="ui-analytics-chart" aria-label="Grafik batang {{ $tab['title'] }}">
                @php($max = max(1, collect($rows)->max('value')))
                @foreach ($rows as $row)<div class="ui-analytics-bar-wrap"><div class="ui-analytics-bar-track"><span class="ui-analytics-bar is-{{ $tab['tone'] }}" style="height: {{ max(6, ((float) $row['value'] / $max) * 100) }}%" title="{{ $row['label'] }}: {{ $row['value'] }}"></span></div><span>{{ $row['label'] }}</span></div>@endforeach
            </div>
        </div>
    @endforeach
</section>
