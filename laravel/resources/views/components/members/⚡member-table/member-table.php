<?php

use App\Models\Anggota;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $role = '';

    public string $status = '';

    public string $sortBy = 'nama_lengkap';

    public string $sortDirection = 'asc';

    public function mount(string $role): void
    {
        $this->role = $role;
    }

    #[Computed]
    public function members(): LengthAwarePaginator
    {
        return Anggota::query()
            ->select(['id', 'kode_anggota', 'nama_lengkap', 'telepon', 'status', 'tanggal_daftar'])
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('nama_lengkap', 'like', "%{$this->search}%")
                        ->orWhere('kode_anggota', 'like', "%{$this->search}%")
                        ->orWhere('telepon', 'like', "%{$this->search}%");
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(10);
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

    public function resetFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }
};
