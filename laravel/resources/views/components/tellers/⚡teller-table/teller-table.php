<?php

use App\Models\User;
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

    public int $perPage = 10;

    public function mount(string $role): void
    {
        abort_unless(auth()->user()?->role === 'admin', 403);
        $this->role = $role;
    }

    #[Computed]
    public function tellers(): LengthAwarePaginator
    {
        return User::query()
            ->select(['id', 'username', 'nama_lengkap', 'email', 'telepon', 'status', 'last_login'])
            ->where('role', 'teller')
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('nama_lengkap', 'like', "%{$this->search}%")
                        ->orWhere('username', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhere('telepon', 'like', "%{$this->search}%");
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function sort(string $column): void
    {
        $allowedColumns = ['username', 'nama_lengkap', 'telepon', 'status', 'last_login'];

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
};
