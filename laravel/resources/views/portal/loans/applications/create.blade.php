<x-layouts.portal :role="$role" title="Tambah pengajuan pinjaman">
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header
            icon="banknotes"
            eyebrow="Pinjaman"
            title="Buat pengajuan"
            description="Form mengikuti aturan native: tenor bertarif, gadai kelipatan Rp1 juta, jaminan HP/motor/emas, serta SHU/prioritas."
            :back-href="route('portal.loans.applications.index', ['role' => $role])"
        />
        <x-loans.workflow :role="$role" active="applications" />

        <form method="POST" action="{{ route('portal.loans.applications.store', ['role' => $role]) }}" enctype="multipart/form-data" class="space-y-5">
            @csrf
            <x-loans.application-form
                :role="$role"
                :members="$members"
                :products="$products"
                :group-leaders="$groupLeaders"
                :prefill-member-id="$prefillMemberId ?? null"
            />
            <x-form-actions :cancel-href="route('portal.loans.applications.index', ['role' => $role])" submit-label="Kirim pengajuan" />
        </form>
    </div>
</x-layouts.portal>
