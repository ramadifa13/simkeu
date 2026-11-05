<?php

use App\Actions\Members\ResolveMemberDisbursementPhoto;
use App\Models\Anggota;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    #[Locked]
    public string $role = '';

    public string $status = '';

    public string $sortBy = 'nama_lengkap';

    public string $sortDirection = 'asc';

    public int $perPage = 10;

    public function mount(string $role): void
    {
        $this->authorizeStaff();
        $this->role = $role;
    }

    #[Computed]
    public function members(): LengthAwarePaginator
    {
        $this->authorizeStaff();

        $paginator = Anggota::query()
            ->select(['id', 'kode_anggota', 'kode_anggota_jaminan', 'nama_lengkap', 'telepon', 'kategori_shu', 'status', 'tanggal_daftar'])
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('nama_lengkap', 'like', "%{$this->search}%")
                        ->orWhere('kode_anggota', 'like', "%{$this->search}%")
                        ->orWhere('telepon', 'like', "%{$this->search}%");
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate($this->perPage);

        $photos = app(ResolveMemberDisbursementPhoto::class)->map(
            $paginator->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
        $paginator->getCollection()->transform(function (Anggota $member) use ($photos): Anggota {
            $path = $photos[(int) $member->id] ?? null;
            $member->setAttribute(
                'avatar_url',
                ($path && Storage::disk('local')->exists($path))
                    ? route('portal.members.avatar', ['role' => $this->role, 'member' => $member->id]).'?v='.md5($path)
                    : null
            );

            return $member;
        });

        return $paginator;
    }

    public function sort(string $column): void
    {
        $allowedColumns = ['kode_anggota', 'nama_lengkap', 'telepon', 'status', 'tanggal_daftar'];

        if (! in_array($column, $allowedColumns, true)) {
            return;
        }

        $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortBy = $column;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(mixed $value): void
    {
        $this->perPage = in_array((int) $value, [5, 10, 25, 50], true) ? (int) $value : 10;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    private function authorizeStaff(): void
    {
        abort_unless(in_array(auth()->user()?->role, ['admin', 'teller'], true), 403);
    }
};
