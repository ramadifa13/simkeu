@props(['name' => 'unsaved-changes'])

<x-confirm-action-modal :name="$name" title="Perubahan belum disimpan" description="Keluar dari halaman ini dapat menghapus perubahan lokal yang belum disimpan." confirm-label="Keluar tanpa menyimpan" variant="danger"><p class="text-sm text-slate-600">Simpan perubahan terlebih dahulu bila data masih diperlukan.</p></x-confirm-action-modal>
