@props([
    'role',
    'members',
    'products',
    'groupLeaders',
    'prefillMemberId' => null,
])

@php
    $selectedMemberId = (string) old('anggota_id', $prefillMemberId ?? '');
    $productPayload = $products->map(fn ($product) => [
        'id' => $product->id,
        'collateral' => (bool) $product->memerlukan_jaminan,
        'frequencies' => $product->memerlukan_jaminan
            ? ['bulanan', 'mingguan', 'dua_mingguan']
            : array_values(array_filter(array_map('trim', explode(',', (string) $product->frekuensi_diizinkan)))),
        'tariffs' => $product->tariffs ?? ($product->memerlukan_jaminan ? [1 => 15, 2 => 15, 3 => 15, 4 => 15, 5 => 15, 6 => 15] : []),
        'max' => (float) $product->maksimal_pinjaman,
    ])->values();
    $memberPayload = $members->mapWithKeys(fn ($member) => [$member->id => [
        'kode' => $member->kode_anggota,
        'agTJ' => str_starts_with((string) $member->kode_anggota, 'AGTJ'),
        'needKk' => blank($member->foto_kk ?? null),
        'needSpouse' => blank($member->foto_ktp_pasangan ?? null),
        'shu' => $member->kategori_shu ?? 'anggota',
        'ketua' => $member->ketua_kelompok_id ? (string) $member->ketua_kelompok_id : '',
    ]]);
@endphp

<div
    class="space-y-5"
    x-on:change="if ($event.target?.name === 'anggota_id') selectMember($event.target.value)"
    x-data="{
        products: @js($productPayload),
        members: @js($memberPayload),
        collateral: false,
        frequencies: [],
        tariffs: {},
        kategori: '{{ old('kategori_jaminan', 'hp') }}',
        shu: '{{ old('kategori_shu', 'anggota') }}',
        ketuaId: '{{ old('ketua_kelompok_id', '') }}',
        amount: {{ (float) old('jumlah_pinjaman', 0) }},
        tenor: {{ (int) old('tenor', 0) }},
        frequency: '{{ old('frekuensi', '') }}',
        memberId: @js($selectedMemberId),
        productId: '{{ old('jenis_pinjaman_id', '') }}',
        init() {
            if (this.productId) this.applyProduct(this.productId, false);
            if (this.memberId) this.syncMemberProfile(false);
        },
        selectMember(id) {
            this.memberId = String(id || '');
            this.syncMemberProfile(true);
        },
        syncMemberProfile(overwrite = true) {
            if (! this.memberMeta) return;
            if (this.collateral) {
                this.shu = 'anggota';
                this.ketuaId = '';
                return;
            }
            if (overwrite || ! this.shu) {
                this.shu = this.memberMeta.shu || 'anggota';
            }
            if (overwrite || this.ketuaId === '') {
                this.ketuaId = this.shu === 'anggota' ? (this.memberMeta.ketua || '') : '';
            }
            if (this.shu === 'ketua_kelompok') {
                this.ketuaId = '';
            }
        },
        selectProduct(event) {
            this.applyProduct(event.target.value, true);
        },
        applyProduct(productId, resetMissing = true) {
            const product = this.products.find((row) => String(row.id) === String(productId));
            this.productId = product ? String(product.id) : '';
            this.collateral = !!(product && product.collateral);
            this.frequencies = product ? product.frequencies : [];
            this.tariffs = product ? (this.collateral ? {1:15,2:15,3:15,4:15,5:15,6:15} : product.tariffs) : {};
            const keys = Object.keys(this.tariffs).map(Number).sort((a,b)=>a-b);
            if (resetMissing || !keys.includes(Number(this.tenor))) {
                if (!keys.includes(Number(this.tenor))) this.tenor = keys[0] || '';
            }
            if (resetMissing || !this.frequencies.includes(this.frequency)) {
                if (!this.frequencies.includes(this.frequency)) this.frequency = this.frequencies[0] || '';
            }
            if (resetMissing && product && (!this.amount || Number(this.amount) <= 0)) {
                this.amount = Number(product.max || 0);
            }
            if (this.collateral) {
                this.shu = 'anggota';
                this.ketuaId = '';
            } else {
                this.syncMemberProfile(false);
            }
        },
        get memberMeta() { return this.members[this.memberId] || null; },
        get needAgtjDocs() {
            return !this.collateral
                && this.memberMeta
                && this.memberMeta.agTJ
                && (this.memberMeta.needKk || this.memberMeta.needSpouse);
        },
        get rate() {
            if (!this.tenor) return 0;
            if (this.collateral) return 15;
            if (this.frequency === 'dua_mingguan') return 45;
            return Number(this.tariffs[this.tenor] || 0);
        },
        get preview() {
            const pokok = Number(this.amount || 0);
            const tenor = Number(this.tenor || 0);
            if (pokok <= 0 || tenor <= 0) return null;
            let bungaPersen = this.rate;
            if (this.collateral) {
                if (pokok < 1000000 || pokok % 1000000 !== 0) return { error: 'Nominal gadai harus kelipatan Rp 1 juta.' };
                const fee = 5000 + ((pokok / 1000000) * 5000);
                const tambahan = fee * tenor * 4;
                const dasar = Math.round(pokok * 15 / 100);
                bungaPersen = ((dasar + tambahan) / pokok) * 100;
            }
            const totalBunga = Math.round(pokok * bungaPersen / 100);
            const total = pokok + totalBunga;
            const cicilanCount = this.frequency === 'mingguan' ? tenor * 4 : (this.frequency === 'dua_mingguan' ? tenor * 2 : tenor);
            const angsuran = Math.round(total / Math.max(cicilanCount, 1));
            return { bungaPersen, totalBunga, total, angsuran, cicilanCount };
        }
    }"
>
    <x-form-section number="1" title="Data pengajuan" description="Produk, plafon, tenor bertarif, dan frekuensi dicek ulang di server.">
        <div class="grid gap-4 md:grid-cols-2">
            <x-member-picker :members="$members" :value="$selectedMemberId" required />
            <flux:field>
                <flux:label>Jenis pinjaman <x-required-mark /></flux:label>
                <flux:select name="jenis_pinjaman_id" required x-on:change="selectProduct($event)">
                    <option value="">Pilih produk</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected((string) old('jenis_pinjaman_id') === (string) $product->id)>
                            {{ $product->nama_jenis }}{{ $product->memerlukan_jaminan ? ' · Gadai' : '' }} · maks Rp {{ number_format($product->maksimal_pinjaman, 0, ',', '.') }}
                        </option>
                    @endforeach
                </flux:select>
            </flux:field>
            <flux:field>
                <flux:label>Nominal <x-required-mark /></flux:label>
                <flux:input name="jumlah_pinjaman" type="number" min="1" step="1" x-model.number="amount" :value="old('jumlah_pinjaman')" required />
                <p class="mt-1 text-xs text-slate-500" x-show="collateral" x-cloak>Gadai wajib kelipatan Rp 1.000.000.</p>
            </flux:field>
            <flux:field>
                <flux:label>Tenor (bulan) <x-required-mark /></flux:label>
                <flux:select name="tenor" x-model="tenor" required>
                    <option value="">Pilih tenor</option>
                    <template x-for="month in Object.keys(tariffs).map(Number).sort((a,b)=>a-b)" :key="month">
                        <option :value="month" x-text="month + ' bulan · ' + Number(tariffs[month]).toFixed(2).replace('.', ',') + '%'"></option>
                    </template>
                </flux:select>
            </flux:field>
            <flux:field>
                <flux:label>Frekuensi <x-required-mark /></flux:label>
                <flux:select name="frekuensi" x-model="frequency" required>
                    <option value="">Pilih frekuensi</option>
                    <option value="bulanan" x-bind:disabled="frequencies.length && !frequencies.includes('bulanan')">Bulanan</option>
                    <option value="mingguan" x-bind:disabled="frequencies.length && !frequencies.includes('mingguan')">Mingguan</option>
                    <option value="dua_mingguan" x-bind:disabled="frequencies.length && !frequencies.includes('dua_mingguan')">2 minggu sekali</option>
                </flux:select>
            </flux:field>
            <flux:field x-show="!collateral" x-cloak>
                <flux:label>Hari angsuran <x-required-mark /></flux:label>
                <flux:select name="hari_angsuran" x-bind:required="!collateral" x-bind:disabled="collateral">
                    <option value="">Pilih hari</option>
                    @foreach (['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'] as $index => $day)
                        <option value="{{ $index + 1 }}" @selected((string) old('hari_angsuran') === (string) ($index + 1))>{{ $day }}</option>
                    @endforeach
                </flux:select>
                <flux:description>Dipakai untuk filter daftar angsuran.</flux:description>
            </flux:field>
            <x-loans.payment-date-field
                name="tanggal_pengajuan"
                label="Tanggal pengajuan"
                :value="old('tanggal_pengajuan', today()->toDateString())"
            />
            <p class="md:col-span-2 text-xs text-slate-500">Tanggal boleh lampau untuk data lama, tidak boleh masa depan.</p>
            <flux:field class="md:col-span-2">
                <flux:label>Tujuan pinjaman <x-required-mark /></flux:label>
                <flux:textarea name="tujuan_pinjaman" rows="3" required placeholder="Jelaskan tujuan penggunaan pinjaman…">{{ old('tujuan_pinjaman') }}</flux:textarea>
            </flux:field>
        </div>

        <div class="mt-4 rounded-2xl border border-teal-100 bg-teal-50/70 p-4" x-show="preview" x-cloak>
            <template x-if="preview && preview.error">
                <p class="text-sm font-medium text-rose-700" x-text="preview.error"></p>
            </template>
            <template x-if="preview && !preview.error">
                <dl class="grid gap-3 sm:grid-cols-4 text-sm">
                    <div><dt class="text-xs text-teal-800">Bunga efektif</dt><dd class="mt-1 font-semibold text-slate-900" x-text="preview.bungaPersen.toFixed(2).replace('.', ',') + '%'"></dd></div>
                    <div><dt class="text-xs text-teal-800">Total bunga</dt><dd class="mt-1 font-semibold text-slate-900" x-text="'Rp ' + Number(preview.totalBunga).toLocaleString('id-ID')"></dd></div>
                    <div><dt class="text-xs text-teal-800">Total tagihan</dt><dd class="mt-1 font-semibold text-slate-900" x-text="'Rp ' + Number(preview.total).toLocaleString('id-ID')"></dd></div>
                    <div><dt class="text-xs text-teal-800">Angsuran / cicilan</dt><dd class="mt-1 font-semibold text-slate-900" x-text="'Rp ' + Number(preview.angsuran).toLocaleString('id-ID') + ' · ' + preview.cicilanCount + 'x'"></dd></div>
                </dl>
            </template>
        </div>
    </x-form-section>

    <x-form-section number="2" title="SHU & prioritas" description="Hanya untuk pinjaman tanpa jaminan. Peran dan ketua mengikuti profil anggota, bisa diubah bila perlu." x-show="!collateral" x-cloak>
        <div class="grid gap-4 md:grid-cols-2">
            <flux:field>
                <flux:label>Peran pada pengajuan <x-required-mark /></flux:label>
                <flux:select
                    name="kategori_shu"
                    x-model="shu"
                    x-bind:required="!collateral"
                    x-bind:disabled="collateral"
                    x-on:change="if (shu === 'ketua_kelompok') ketuaId = ''"
                >
                    <option value="anggota">Anggota</option>
                    <option value="ketua_kelompok">Ketua kelompok</option>
                </flux:select>
            </flux:field>
            <flux:field x-show="shu === 'anggota'" x-cloak>
                <flux:label>Ketua yang mengajak</flux:label>
                <flux:select name="ketua_kelompok_id" x-model="ketuaId" x-bind:disabled="collateral || shu !== 'anggota'">
                    <option value="">Tidak tergabung kelompok</option>
                    @foreach ($groupLeaders as $leader)
                        <option value="{{ $leader->id }}">{{ $leader->kode_anggota }} — {{ $leader->nama_lengkap }}</option>
                    @endforeach
                </flux:select>
            </flux:field>
            <label class="md:col-span-2 flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700">
                <input type="checkbox" name="fasilitas_prioritas" value="1" class="mt-1 rounded border-slate-300 text-teal-700 focus:ring-teal-600" @checked(old('fasilitas_prioritas'))>
                <span>
                    <span class="font-semibold text-slate-900">Ajukan fasilitas prioritas</span>
                    <span class="mt-1 block text-slate-600">Hanya tanpa jaminan, nominal maksimal Rp 1.000.000, dan harus sudah disetujui Admin dengan riwayat 2 pelunasan lancar.</span>
                </span>
            </label>
        </div>
    </x-form-section>

    <x-form-section number="3" title="Jaminan gadai" description="Pilih kategori barang dan lengkapi detail sesuai jenis jaminan." x-show="collateral" x-cloak>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach (['hp' => 'HP', 'sepeda_motor' => 'Sepeda motor', 'emas' => 'Emas'] as $value => $label)
                <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700 has-[:checked]:border-teal-500 has-[:checked]:bg-teal-50 has-[:checked]:text-teal-900">
                    <input
                        type="radio"
                        name="kategori_jaminan"
                        value="{{ $value }}"
                        class="text-teal-700 focus:ring-teal-600"
                        x-model="kategori"
                        x-bind:disabled="!collateral"
                        @checked(old('kategori_jaminan', 'hp') === $value)
                    >
                    {{ $label }}
                </label>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2" x-show="kategori === 'hp'" x-cloak>
            @foreach ([
                'hp_jenis' => 'Jenis HP',
                'hp_merk_tipe' => 'Merk / tipe',
                'hp_warna' => 'Warna',
                'hp_imei' => 'IMEI',
                'hp_nomor_seri' => 'Nomor seri',
                'hp_nomor_model' => 'Nomor model',
                'hp_kelengkapan' => 'Kelengkapan',
                'hp_kondisi' => 'Kondisi',
            ] as $name => $label)
                <flux:field>
                    <flux:label>{{ $label }} <x-required-mark /></flux:label>
                    <flux:input :name="$name" :value="old($name)" x-bind:required="collateral && kategori === 'hp'" x-bind:disabled="!collateral || kategori !== 'hp'" />
                </flux:field>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2" x-show="kategori === 'sepeda_motor'" x-cloak>
            @foreach ([
                'motor_jenis_kendaraan' => 'Jenis kendaraan',
                'motor_merk_tipe' => 'Merk / tipe',
                'motor_tahun' => 'Tahun',
                'motor_nopol' => 'Nomor polisi',
                'motor_no_rangka' => 'No rangka',
                'motor_no_mesin' => 'No mesin',
                'motor_warna' => 'Warna',
                'motor_atas_nama_bpkb' => 'Atas nama BPKB',
                'motor_no_bpkb' => 'No BPKB',
                'motor_kondisi' => 'Kondisi',
            ] as $name => $label)
                <flux:field @class(['md:col-span-2' => $name === 'motor_kondisi'])>
                    <flux:label>{{ $label }} <x-required-mark /></flux:label>
                    <flux:input
                        :name="$name"
                        :type="$name === 'motor_tahun' ? 'number' : 'text'"
                        :value="old($name)"
                        x-bind:required="collateral && kategori === 'sepeda_motor'"
                        x-bind:disabled="!collateral || kategori !== 'sepeda_motor'"
                    />
                </flux:field>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2" x-show="kategori === 'emas'" x-cloak>
            @foreach ([
                'emas_jenis_barang' => ['Jenis barang', null],
                'emas_berat' => ['Berat', 'Contoh: 10 gram'],
                'emas_kadar' => ['Kadar', 'Contoh: 22 karat'],
                'emas_bentuk_ciri' => ['Bentuk / ciri', null],
                'emas_surat_nota' => ['Surat / nota', null],
            ] as $name => [$label, $placeholder])
                <flux:field @class(['md:col-span-2' => $name === 'emas_surat_nota'])>
                    <flux:label>{{ $label }} <x-required-mark /></flux:label>
                    <flux:input
                        :name="$name"
                        :value="old($name)"
                        :placeholder="$placeholder"
                        x-bind:required="collateral && kategori === 'emas'"
                        x-bind:disabled="!collateral || kategori !== 'emas'"
                    />
                </flux:field>
            @endforeach
        </div>
    </x-form-section>

    <x-form-section
        number="4"
        title="Dokumen AGTJ"
        description="Wajib diunggah jika kode anggota berawalan AGTJ dan dokumen belum tersedia di profil."
        x-show="needAgtjDocs"
        x-cloak
    >
        <div class="grid gap-4 md:grid-cols-2">
            <div x-show="memberMeta && memberMeta.needKk" x-cloak>
                <x-image-upload
                    label="Foto Kartu Keluarga (KK)"
                    name="foto_kk"
                    description="JPG, PNG, atau WebP. Otomatis dikompres maksimal 1 MB."
                    x-bind:required="needAgtjDocs && memberMeta && memberMeta.needKk"
                />
            </div>
            <div x-show="memberMeta && memberMeta.needSpouse" x-cloak>
                <x-image-upload
                    label="Foto KTP Suami/Istri"
                    name="foto_ktp_pasangan"
                    description="JPG, PNG, atau WebP. Otomatis dikompres maksimal 1 MB."
                    x-bind:required="needAgtjDocs && memberMeta && memberMeta.needSpouse"
                />
            </div>
        </div>
    </x-form-section>
</div>
