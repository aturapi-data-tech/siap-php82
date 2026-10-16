<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    /** true = ditanam di tab Pengaturan Absensi (tanpa judul halaman, tinggi mengikuti isi). */
    public bool $embedded = false;

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
        $this->dispatch('master.unit-kerja.openCreate');
    }

    public function openEdit(string $unitId): void
    {
        $this->dispatch('master.unit-kerja.openEdit', unitId: $unitId);
    }

    public function requestDelete(string $unitId): void
    {
        $this->dispatch('master.unit-kerja.requestDelete', unitId: $unitId);
    }

    public function toggleActive(string $unitId): void
    {
        $current  = (string) DB::table('siapmst_unit_kerjas')->where('unit_id', $unitId)->value('active_status');
        $newValue = $current === '1' ? '0' : '1';

        DB::table('siapmst_unit_kerjas')->where('unit_id', $unitId)->update(['active_status' => $newValue, 'updated_at' => now()]);

        $this->dispatch('toast', type: 'success', message: $newValue === '1' ? 'Unit diaktifkan.' : 'Unit dinon-aktifkan.');
    }

    #[On('master.unit-kerja.saved')]
    public function refreshAfterSaved(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function rows()
    {
        $query = DB::table('siapmst_unit_kerjas as un')
            ->select('un.unit_id', 'un.unit_name', 'un.active_status',
                DB::raw("(SELECT COUNT(*) FROM siapmst_employers em WHERE em.emp_unit_id = un.unit_id AND em.active_status = '1') AS jumlah_karyawan"),
                DB::raw("(SELECT COUNT(*) FROM siapmst_shifts sh WHERE sh.unit_id = un.unit_id AND sh.active_status = '1') AS jumlah_shift"))
            ->orderBy('un.unit_name');

        if (trim($this->searchKeyword) !== '') {
            $keyword = mb_strtoupper(trim($this->searchKeyword));
            $query->where(fn($subQuery) => $subQuery->whereRaw('UPPER(un.unit_id) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('UPPER(un.unit_name) LIKE ?', ["%{$keyword}%"]));
        }

        return $query->paginate($this->itemsPerPage);
    }
};
?>

<div>

    @unless ($embedded)
    <x-page-title
        title="Master Unit Kerja"
        subtitle="Unit / ruang tempat karyawan bekerja — pengelompok jadwal dinas & shift per unit" />
    @endunless

    <div class="w-full {{ $embedded ? 'min-h-[32rem]' : 'h-[calc(100vh-5rem)]' }} flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="w-full lg:max-w-md">
                        <x-input-label for="searchKeyword" value="Cari Unit" class="sr-only" />
                        <x-text-input id="searchKeyword" type="text" wire:model.live.debounce.300ms="searchKeyword"
                            placeholder="Cari kode / nama unit..." class="block w-full" />
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-28">
                            <x-input-label for="itemsPerPage" value="Per halaman" class="sr-only" />
                            <x-select-input id="itemsPerPage" wire:model.live="itemsPerPage">
                                <option value="5">5</option>
                                <option value="10">10</option>
                                <option value="20">20</option>
                            </x-select-input>
                        </div>
                        <x-primary-button type="button" wire:click="openCreate">
                            + Tambah Unit
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
                                <th>Nama Unit</th>
                                <th class="ds-c">Karyawan Aktif</th>
                                <th class="ds-c">Shift Khusus</th>
                                <th>Status</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php $isActive = (string) $row->active_status === '1'; @endphp
                                <tr wire:key="unit-{{ $row->unit_id }}">
                                    <td class="ds-td-token">{{ $row->unit_id }}</td>
                                    <td class="ds-td-strong">{{ $row->unit_name }}</td>
                                    <td class="ds-c">{{ (int) $row->jumlah_karyawan }}</td>
                                    <td class="ds-c">{{ (int) $row->jumlah_shift }}</td>
                                    <td>
                                        <x-toggle wire:key="toggle-unit-{{ $row->unit_id }}-{{ $isActive ? 1 : 0 }}"
                                            :current="$isActive ? '1' : '0'" trueValue="1" falseValue="0"
                                            wireClick="toggleActive('{{ $row->unit_id }}')">
                                            {{ $isActive ? 'Aktif' : 'Non-aktif' }}
                                        </x-toggle>
                                    </td>
                                    <td class="ds-c">
                                        <div class="flex justify-center gap-2">
                                            <x-action-edit wire:click="openEdit('{{ $row->unit_id }}')" />
                                            <x-action-delete :action="'requestDelete(\'' . $row->unit_id . '\')'"
                                                title="Hapus Unit" message="Yakin hapus {{ $row->unit_name }}?" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-10 text-center text-muted dark:text-gray-400">Belum ada unit kerja.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::master.master-unit-kerja.master-unit-kerja-actions wire:key="master-unit-kerja-actions" />

        </div>
    </div>
</div>
