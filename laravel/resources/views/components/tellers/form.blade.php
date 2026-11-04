@props(['teller' => null, 'editing' => false])
@php($value = fn (string $field, mixed $default = null) => old($field, $teller?->{$field} ?? $default))

<x-required-hint class="mb-1" />

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">1</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">Identitas akun</h2>
            <p class="mt-1 text-sm text-slate-600">Username dipakai untuk masuk portal Teller.</p>
        </div>
    </div>
    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Username <x-required-mark /></flux:label>
            <flux:input name="username" :value="$value('username')" required autocomplete="username" />
            <flux:error name="username" />
        </flux:field>
        <flux:field>
            <flux:label>Nama lengkap <x-required-mark /></flux:label>
            <flux:input name="nama_lengkap" :value="$value('nama_lengkap')" required autocomplete="name" />
            <flux:error name="nama_lengkap" />
        </flux:field>
        <flux:field>
            <flux:label>Email</flux:label>
            <flux:input type="email" name="email" :value="$value('email')" autocomplete="email" />
            <flux:error name="email" />
        </flux:field>
        <flux:field>
            <flux:label>Telepon</flux:label>
            <flux:input name="telepon" :value="$value('telepon')" inputmode="tel" autocomplete="tel" />
            <flux:error name="telepon" />
        </flux:field>
    </div>
</section>

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-start gap-3">
        <span class="grid size-9 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">2</span>
        <div>
            <h2 class="text-base font-semibold text-slate-900">{{ $editing ? 'Status & kata sandi' : 'Keamanan akun' }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ $editing ? 'Kosongkan kata sandi bila tidak ingin mengubahnya. Atau gunakan reset sandi acak dari daftar.' : 'Kata sandi awal minimal 8 karakter. Status nonaktif menolak login.' }}</p>
        </div>
    </div>
    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <flux:field>
            <flux:label>Status akun <x-required-mark /></flux:label>
            <flux:select name="status" required>
                <option value="aktif" @selected($value('status', 'aktif') === 'aktif')>Aktif</option>
                <option value="nonaktif" @selected($value('status') === 'nonaktif')>Nonaktif</option>
            </flux:select>
            <flux:error name="status" />
        </flux:field>
        <div class="hidden md:block" aria-hidden="true"></div>
        <flux:field>
            <flux:label>{{ $editing ? 'Kata sandi baru' : 'Kata sandi awal' }} @unless($editing)<x-required-mark />@endunless</flux:label>
            <flux:input type="password" name="password" :required="! $editing" autocomplete="new-password" :placeholder="$editing ? 'Kosongkan bila tidak diubah' : null" />
            <flux:error name="password" />
        </flux:field>
        <flux:field>
            <flux:label>Konfirmasi kata sandi @unless($editing)<x-required-mark />@endunless</flux:label>
            <flux:input type="password" name="password_confirmation" :required="! $editing" autocomplete="new-password" />
            <flux:error name="password_confirmation" />
        </flux:field>
    </div>
</section>
