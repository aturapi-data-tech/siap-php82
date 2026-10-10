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
    public string $filterRole = '';
    public int $itemsPerPage = 10;

    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }
    public function updatedFilterRole(): void    { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterRole']);
        $this->itemsPerPage = 10;
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->dispatch('sistem.user-control.openCreate');
    }

    public function openEdit(int $userId): void
    {
        $this->dispatch('sistem.user-control.openEdit', userId: $userId);
    }

    public function requestDelete(int $userId): void
    {
        $this->dispatch('sistem.user-control.requestDelete', userId: $userId);
    }

    /* Toggle status aktif/nonaktif user langsung dari list. Nonaktif = tak bisa login.
       Guard: tidak boleh menonaktifkan akun sendiri (cegah lockout). */
    public function toggleActive(int $userId): void
    {
        if ($userId === (int) auth()->id()) {
            $this->dispatch('toast', type: 'error', message: 'Tidak bisa menonaktifkan akun sendiri.');
            return;
        }

        $user = DB::table('users')->select('active_status', 'name')->where('id', $userId)->first();
        if (!$user) {
            $this->dispatch('toast', type: 'error', message: 'User tidak ditemukan.');
            return;
        }

        $new = ((string) ($user->active_status ?? '1')) === '1' ? '0' : '1';
        DB::table('users')->where('id', $userId)->update([
            'active_status' => $new,
            'updated_at' => DB::raw('SYSDATE'),
        ]);

        $this->dispatch('toast', type: 'success', message: 'User ' . ($user->name ?? '') . ' → ' . ($new === '1' ? 'Aktif' : 'Nonaktif') . '.');
    }

    /* Beri / cabut role langsung dari list.
       Guard: Admin tidak boleh mencabut role Admin miliknya sendiri (cegah lockout). */
    public function toggleRole(int $userId, string $roleName): void
    {
        $user = User::find($userId);
        if (!$user) {
            $this->dispatch('toast', type: 'error', message: 'User tidak ditemukan.');
            return;
        }

        if ($user->hasRole($roleName)) {
            if ($userId === (int) auth()->id() && $roleName === 'Admin') {
                $this->dispatch('toast', type: 'error', message: 'Tidak bisa mencabut role Admin dari akun sendiri.');
                return;
            }
            $user->removeRole($roleName);
            $this->dispatch('toast', type: 'info', message: "Role {$roleName} dicabut.");
        } else {
            $user->assignRole($roleName);
            $this->dispatch('toast', type: 'success', message: "Role {$roleName} diberikan.");
        }
    }

    #[On('sistem.user-control.saved')]
    public function refreshAfterSaved(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function allRoles(): array
    {
        return DB::table('roles')->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();
    }

    #[Computed]
    public function rows()
    {
        $q = DB::table('users as us')
            ->leftJoin('siapmst_employers as em', 'us.emp_id', '=', 'em.emp_id')
            ->select('us.id', 'us.myuser_code', 'us.name', 'us.email', 'us.myuser_profesi', 'us.myuser_ttd_image',
                'us.emp_id', 'em.emp_name', 'us.active_status', 'us.last_seen_at',
                DB::raw("TO_CHAR(us.created_at, 'dd/mm/yyyy HH24:MI:SS') as created_at"))
            ->orderBy('us.name');

        if ($this->filterRole !== '') {
            $q->whereIn('us.id', function ($sub) {
                $sub->select('model_id')->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('model_has_roles.model_type', User::class)
                    ->where('roles.name', $this->filterRole);
            });
        }

        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->where(function ($sub) use ($kw) {
                $sub->whereRaw('UPPER(us.name) LIKE ?', ["%{$kw}%"])
                    ->orWhereRaw('UPPER(us.email) LIKE ?', ["%{$kw}%"])
                    ->orWhereRaw('UPPER(us.myuser_code) LIKE ?', ["%{$kw}%"])
                    ->orWhereRaw('UPPER(em.emp_name) LIKE ?', ["%{$kw}%"]);
            });
        }

        $users = $q->paginate($this->itemsPerPage);

        // Role semua user di halaman ini — satu query, bukan satu query per baris.
        $roleMap = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('model_has_roles.model_id', $users->pluck('id')->all() ?: [0])
            ->orderBy('roles.name')
            ->get(['model_has_roles.model_id', 'roles.name'])
            ->groupBy('model_id');

        foreach ($users as $user) {
            $user->role_list = ($roleMap[$user->id] ?? collect())->pluck('name')->all();
        }

        return $users;
    }
};
?>

<div>

    <x-page-title
        title="User Control"
        subtitle="Kelola user login, role, dan kaitannya dengan data karyawan" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">

                    {{-- Kiri: search + filter role --}}
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="w-full lg:w-72">
                            <x-input-label for="searchKeyword" value="Cari User" class="sr-only" />
                            <x-text-input id="searchKeyword" type="text"
                                wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari nama / email / kode / karyawan..."
                                class="block w-full" />
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" wire:click="$set('filterRole', '')"
                                class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium transition border
                                    {{ $filterRole === ''
                                        ? 'bg-gray-800 text-white border-gray-800 dark:bg-gray-200 dark:text-gray-900 dark:border-gray-200'
                                        : 'bg-canvas text-muted border-hairline hover:border-gray-400 dark:bg-gray-900 dark:text-gray-400 dark:border-gray-700 dark:hover:border-gray-500' }}">
                                Semua
                            </button>
                            @foreach ($this->allRoles as $role)
                                <button type="button" wire:click="$set('filterRole', @js($role))"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium transition border
                                        {{ $filterRole === $role
                                            ? \App\Support\RoleBadge::badge($role) . ' border-transparent ring-2 ring-offset-1 ring-current'
                                            : 'bg-canvas text-muted border-hairline hover:border-gray-400 dark:bg-gray-900 dark:text-gray-400 dark:border-gray-700 dark:hover:border-gray-500' }}">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ \App\Support\RoleBadge::dot($role) }}"></span>
                                    {{ $role }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Kanan: per halaman + tambah --}}
                    <div class="flex items-center justify-end gap-2 shrink-0">
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
                            + Tambah User
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
                                <th>User</th>
                                <th>Kode / Karyawan</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php
                                    $userRoles = $row->role_list;
                                    $isAktif = (string) ($row->active_status ?? '1') === '1';
                                @endphp
                                <tr wire:key="user-{{ $row->id }}" class="{{ $isAktif ? '' : 'opacity-60' }}">

                                    {{-- USER --}}
                                    <td>
                                        <div class="flex items-start gap-3">
                                            <div class="min-w-0">
                                                <div class="ds-td-strong">{{ $row->name ?? '-' }}</div>
                                                <div class="text-sm break-all text-muted dark:text-gray-400">{{ $row->email }}</div>
                                                <div class="mt-1 text-[11px] text-muted-soft">
                                                    Dibuat {{ $row->created_at ?? '-' }}
                                                    @if ($row->last_seen_at)
                                                        · Terakhir aktif {{ \App\Support\Format::waktu($row->last_seen_at) }}
                                                    @endif
                                                </div>
                                            </div>
                                            @if ($row->myuser_ttd_image)
                                                <img src="{{ \App\Support\TtdUser::url($row->myuser_ttd_image) }}"
                                                    class="w-auto h-12 bg-white border rounded-lg border-hairline shrink-0 dark:border-gray-600" alt="TTD">
                                            @endif
                                        </div>
                                    </td>

                                    {{-- KODE / KARYAWAN --}}
                                    <td>
                                        <div class="ds-td-token">{{ $row->myuser_code ?: '—' }}</div>
                                        <div class="mt-0.5 text-sm text-body dark:text-gray-300">
                                            {{ $row->emp_id ? ($row->emp_name ?? 'EMP ' . $row->emp_id) : '—' }}
                                        </div>
                                        @if (!empty($row->myuser_profesi))
                                            <span class="mt-1 {{ \App\Support\RoleBadge::badge($row->myuser_profesi) }}">{{ $row->myuser_profesi }}</span>
                                        @endif
                                    </td>

                                    {{-- ROLE — badge + dropdown beri/cabut --}}
                                    <td>
                                        <div class="relative" x-data="{ open: false }">
                                            <button type="button" @click="open = !open" @click.outside="open = false"
                                                class="flex items-center gap-1.5 group">
                                                <div class="flex flex-wrap gap-1">
                                                    @forelse ($userRoles as $r)
                                                        <span class="{{ \App\Support\RoleBadge::badge($r) }}">{{ $r }}</span>
                                                    @empty
                                                        <span class="text-xs italic text-muted-soft">Belum ada</span>
                                                    @endforelse
                                                </div>
                                                <svg class="w-3.5 h-3.5 text-gray-300 group-hover:text-brand-green dark:group-hover:text-brand-lime shrink-0 transition"
                                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>

                                            <div x-show="open" x-transition
                                                class="absolute left-0 z-50 w-48 mt-1 border shadow-lg bg-canvas border-hairline rounded-xl dark:bg-gray-900 dark:border-gray-700"
                                                style="display: none;">
                                                <div class="px-3 py-2 border-b border-hairline-soft dark:border-gray-800">
                                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-muted-soft">Kelola Role</p>
                                                </div>
                                                <ul class="py-1">
                                                    @forelse ($this->allRoles as $role)
                                                        @php $active = in_array($role, $userRoles, true); @endphp
                                                        <li>
                                                            <button type="button"
                                                                wire:click="toggleRole({{ $row->id }}, @js($role))"
                                                                @click="open = false"
                                                                class="flex items-center w-full gap-2 px-3 py-1.5 text-sm transition hover:bg-surface-soft dark:hover:bg-gray-800
                                                                    {{ $active ? 'font-semibold ' . \App\Support\RoleBadge::activeText($role) : 'text-muted dark:text-gray-400' }}">
                                                                @if ($active)
                                                                    <span class="{{ \App\Support\RoleBadge::badge($role) }} !px-1.5 !py-0 text-[10px]">✓</span>
                                                                @else
                                                                    <span class="flex items-center justify-center w-5 h-5">
                                                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                                                                    </span>
                                                                @endif
                                                                {{ $role }}
                                                            </button>
                                                        </li>
                                                    @empty
                                                        <li class="px-3 py-2 text-xs text-muted-soft">Belum ada role — buat di Role Control.</li>
                                                    @endforelse
                                                </ul>
                                            </div>
                                        </div>
                                    </td>

                                    {{-- STATUS --}}
                                    <td>
                                        <x-toggle wire:key="toggle-active-{{ $row->id }}-{{ $isAktif ? 1 : 0 }}"
                                            :current="$isAktif ? '1' : '0'" trueValue="1" falseValue="0"
                                            wireClick="toggleActive({{ $row->id }})">
                                            {{ $isAktif ? 'Aktif' : 'Nonaktif' }}
                                        </x-toggle>
                                    </td>

                                    {{-- AKSI --}}
                                    <td class="ds-c">
                                        <div class="flex justify-center gap-2">
                                            <x-action-edit wire:click="openEdit({{ $row->id }})" />
                                            <x-action-delete :action="'requestDelete(' . $row->id . ')'"
                                                title="Hapus User"
                                                message="Yakin hapus user {{ $row->name }}? Untuk user yang sudah tidak bekerja, sebaiknya dinonaktifkan saja." />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-10">
                                        <div class="flex flex-col items-center justify-center gap-3">
                                            <svg class="w-12 h-12 text-muted-soft" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" /></svg>
                                            <p class="text-base font-medium text-muted dark:text-gray-400">
                                                @if ($filterRole !== '')
                                                    Tidak ada user dengan role {{ $filterRole }}.
                                                @else
                                                    Data user tidak ditemukan.
                                                @endif
                                            </p>
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

            <livewire:pages::sistem.user-control.user-control-actions wire:key="user-control-actions" />

        </div>
    </div>
</div>
