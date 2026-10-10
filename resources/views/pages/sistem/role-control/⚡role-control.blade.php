<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Models\User;

new class extends Component {
    use WithPagination;

    public string $searchKeyword = '';
    public int    $itemsPerPage  = 10;

    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword']);
        $this->itemsPerPage = 10;
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->dispatch('sistem.role-control.openCreate');
    }

    public function openEdit(int $roleId): void
    {
        $this->dispatch('sistem.role-control.openEdit', roleId: $roleId);
    }

    public function requestDelete(int $roleId): void
    {
        $this->dispatch('sistem.role-control.requestDelete', roleId: $roleId);
    }

    #[On('sistem.role-control.saved')]
    public function refreshAfterSaved(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function rows()
    {
        $q = DB::table('roles as ro')
            ->select('ro.id', 'ro.name', 'ro.guard_name', DB::raw("TO_CHAR(ro.created_at, 'dd/mm/yyyy HH24:MI:SS') as created_at"))
            ->orderBy('ro.name');

        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->whereRaw('UPPER(ro.name) LIKE ?', ["%{$kw}%"]);
        }

        $roles = $q->paginate($this->itemsPerPage);
        $roleIds = $roles->pluck('id')->all() ?: [0];

        // Permission & jumlah user untuk semua role di halaman ini — dua query, bukan per baris.
        $permissionMap = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereIn('role_has_permissions.role_id', $roleIds)
            ->orderBy('permissions.name')
            ->get(['role_has_permissions.role_id', 'permissions.name'])
            ->groupBy('role_id');

        $userCountMap = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereIn('role_id', $roleIds)
            ->groupBy('role_id')
            ->select('role_id', DB::raw('COUNT(*) AS jumlah'))
            ->pluck('jumlah', 'role_id');

        foreach ($roles as $role) {
            $role->permission_list = ($permissionMap[$role->id] ?? collect())->pluck('name')->all();
            $role->user_count = (int) ($userCountMap[$role->id] ?? 0);
        }

        return $roles;
    }
};
?>

<div>

    <x-page-title
        title="Role Control"
        subtitle="Kelola role & permission — role menentukan menu dan aksi yang boleh diakses user" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">

                    <div class="w-full lg:max-w-md">
                        <x-input-label for="searchKeyword" value="Cari Role" class="sr-only" />
                        <x-text-input id="searchKeyword" type="text"
                            wire:model.live.debounce.300ms="searchKeyword"
                            placeholder="Cari nama role..."
                            class="block w-full" />
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <div class="w-28">
                            <x-input-label for="itemsPerPage" value="Per halaman" class="sr-only" />
                            <x-select-input id="itemsPerPage" wire:model.live="itemsPerPage">
                                <option value="5">5</option>
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </x-select-input>
                        </div>
                        <x-primary-button type="button" wire:click="openCreate">
                            + Tambah Role
                        </x-primary-button>
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-t-2xl">
                    <table class="ds-table">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>ID</th>
                                <th>Nama Role</th>
                                <th>Guard</th>
                                <th>Permissions</th>
                                <th class="ds-c">Jml User</th>
                                <th>Dibuat</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                <tr wire:key="role-{{ $row->id }}">
                                    <td class="ds-td-token">{{ $row->id }}</td>
                                    <td>
                                        <span class="{{ \App\Support\RoleBadge::badge($row->name) }}">{{ $row->name }}</span>
                                    </td>
                                    <td><x-badge variant="alternative">{{ $row->guard_name }}</x-badge></td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            @forelse ($row->permission_list as $p)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $p }}</span>
                                            @empty
                                                <span class="text-xs italic text-muted-soft">Tanpa permission</span>
                                            @endforelse
                                        </div>
                                    </td>
                                    <td class="ds-c">
                                        @if ($row->user_count > 0)
                                            <x-badge variant="success">{{ $row->user_count }} user</x-badge>
                                        @else
                                            <span class="text-muted-soft">—</span>
                                        @endif
                                    </td>
                                    <td class="text-xs text-muted">{{ $row->created_at ?? '-' }}</td>
                                    <td class="ds-c">
                                        <div class="flex justify-center gap-2">
                                            <x-action-edit wire:click="openEdit({{ $row->id }})" />
                                            <x-action-delete :action="'requestDelete(' . $row->id . ')'"
                                                title="Hapus Role"
                                                message="Yakin hapus role {{ $row->name }}? Role yang masih dipakai user tidak bisa dihapus." />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-10">
                                        <div class="flex flex-col items-center justify-center gap-3">
                                            <svg class="w-12 h-12 text-muted-soft" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" /></svg>
                                            <p class="text-base font-medium text-muted dark:text-gray-400">Data role tidak ditemukan.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- PAGINATION --}}
                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::sistem.role-control.role-control-actions wire:key="role-control-actions" />

        </div>
    </div>
</div>
