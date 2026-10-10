<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

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
        $this->dispatch('master.ruang.openCreate');
    }

    public function openEdit(string $ruangId): void
    {
        $this->dispatch('master.ruang.openEdit', ruangId: $ruangId);
    }

    public function requestDelete(string $ruangId): void
    {
        $this->dispatch('master.ruang.requestDelete', ruangId: $ruangId);
    }

    public function toggleActive(string $ruangId): void
    {
        $current  = (string) DB::table('siapmst_ruangs')->where('ruang_id', $ruangId)->value('active_status');
        $newValue = $current === '1' ? '0' : '1';

        DB::table('siapmst_ruangs')->where('ruang_id', $ruangId)->update(['active_status' => $newValue, 'updated_at' => now()]);

        $this->dispatch('toast', type: 'success', message: $newValue === '1' ? 'Ruang diaktifkan.' : 'Ruang dinon-aktifkan.');
    }

    #[On('master.ruang.saved')]
    public function refreshAfterSaved(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function rows()
    {
        $q = DB::table('siapmst_ruangs as ru')
            ->select('ru.ruang_id', 'ru.ruang_name', 'ru.active_status',
                DB::raw('(SELECT COUNT(*) FROM siapmst_assets aa WHERE aa.ruang_id = ru.ruang_id) AS jml_alat'))
            ->orderBy('ru.ruang_id');

        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->where(fn($sub) => $sub->whereRaw('UPPER(ru.ruang_id) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(ru.ruang_name) LIKE ?', ["%{$kw}%"]));
        }

        return $q->paginate($this->itemsPerPage);
    }
};
?>

<div>

    <x-page-title
        title="Master Ruang"
        subtitle="Ruang / unit — lokasi alat inventaris dan awalan kode alat" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="w-full lg:max-w-md">
                        <x-input-label for="searchKeyword" value="Cari Ruang" class="sr-only" />
                        <x-text-input id="searchKeyword" type="text" wire:model.live.debounce.300ms="searchKeyword"
                            placeholder="Cari kode / nama ruang..." class="block w-full" />
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-28">
                            <x-input-label for="itemsPerPage" value="Per halaman" class="sr-only" />
                            <x-select-input id="itemsPerPage" wire:model.live="itemsPerPage">
                                <option value="10">10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                            </x-select-input>
                        </div>
                        <x-primary-button type="button" wire:click="openCreate">
                            + Tambah Ruang
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
                                <th>Kode</th>
                                <th>Nama Ruang</th>
                                <th class="ds-c">Jml Alat</th>
                                <th>Status</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php $isActive = (string) $row->active_status === '1'; @endphp
                                <tr wire:key="ruang-{{ $row->ruang_id }}">
                                    <td class="ds-td-token">{{ $row->ruang_id }}</td>
                                    <td class="ds-td-strong">{{ $row->ruang_name }}</td>
                                    <td class="ds-c">{{ (int) $row->jml_alat }}</td>
                                    <td>
                                        <x-toggle wire:key="toggle-ruang-{{ $row->ruang_id }}-{{ $isActive ? 1 : 0 }}"
                                            :current="$isActive ? '1' : '0'" trueValue="1" falseValue="0"
                                            wireClick="toggleActive('{{ $row->ruang_id }}')">
                                            {{ $isActive ? 'Aktif' : 'Non-aktif' }}
                                        </x-toggle>
                                    </td>
                                    <td class="ds-c">
                                        <div class="flex justify-center gap-2">
                                            <x-action-edit wire:click="openEdit('{{ $row->ruang_id }}')" />
                                            <x-action-delete :action="'requestDelete(\'' . $row->ruang_id . '\')'"
                                                title="Hapus Ruang" message="Yakin hapus ruang {{ $row->ruang_name }}?" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-10 text-center text-muted dark:text-gray-400">Data ruang tidak ditemukan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::master.master-ruang.master-ruang-actions wire:key="master-ruang-actions" />

        </div>
    </div>
</div>
