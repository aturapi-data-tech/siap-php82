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
    public string $filterUnit    = ''; // '' = semua · '-' = shift umum (tanpa unit) · unit_id
    public int    $itemsPerPage  = 10;

    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedFilterUnit(): void    { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterUnit']);
        $this->itemsPerPage = 10;
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->dispatch('master.shift.openCreate');
    }

    public function openEdit(string $shiftId): void
    {
        $this->dispatch('master.shift.openEdit', shiftId: $shiftId);
    }

    public function requestDelete(string $shiftId): void
    {
        $this->dispatch('master.shift.requestDelete', shiftId: $shiftId);
    }

    // Toggle aktif langsung dari tabel — shift non-aktif tidak muncul di kiosk.
    public function toggleActive(string $shiftId): void
    {
        $current  = (string) DB::table('siapmst_shifts')->where('shift_id', $shiftId)->value('active_status');
        $newValue = $current === '1' ? '0' : '1';

        DB::table('siapmst_shifts')->where('shift_id', $shiftId)->update(['active_status' => $newValue, 'updated_at' => now()]);

        $this->dispatch('toast', type: 'success', message: $newValue === '1' ? 'Shift diaktifkan.' : 'Shift dinon-aktifkan.');
    }

    #[On('master.shift.saved')]
    public function refreshAfterSaved(): void
    {
        $this->resetPage();
    }

    /** Unit yang punya shift khusus (+ jumlahnya) — pilihan filter. */
    #[Computed]
    public function unitList()
    {
        return DB::table('siapmst_unit_kerjas as un')
            ->orderBy('un.unit_name')
            ->get(['un.unit_id', 'un.unit_name', DB::raw('(SELECT COUNT(*) FROM siapmst_shifts sh WHERE sh.unit_id = un.unit_id) AS jumlah_shift')]);
    }

    #[Computed]
    public function rows()
    {
        $query = DB::table('siapmst_shifts as sh')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'sh.unit_id')
            ->select('sh.shift_id', 'sh.shift_name', 'sh.shift_kode', 'sh.shift_warna', 'sh.jam_masuk', 'sh.jam_pulang', 'sh.toleransi_menit',
                'sh.lintas_hari', 'sh.active_status', 'un.unit_name')
            ->when($this->filterUnit === '-', fn($subQuery) => $subQuery->whereNull('sh.unit_id'))
            ->when(!in_array($this->filterUnit, ['', '-'], true), fn($subQuery) => $subQuery->where('sh.unit_id', $this->filterUnit))
            ->orderByRaw('un.unit_name NULLS FIRST')
            ->orderBy('sh.jam_masuk');

        if (trim($this->searchKeyword) !== '') {
            $keyword = mb_strtoupper(trim($this->searchKeyword));
            $query->where(fn($subQuery) => $subQuery->whereRaw('UPPER(sh.shift_id) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('UPPER(sh.shift_name) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('UPPER(un.unit_name) LIKE ?', ["%{$keyword}%"]));
        }

        return $query->paginate($this->itemsPerPage);
    }
};
?>

<div>

    @unless ($embedded)
    <x-page-title
        title="Master Shift"
        subtitle="Jam kerja yang bisa dipilih karyawan saat absen di kiosk" />
    @endunless

    <div class="w-full {{ $embedded ? 'min-h-[32rem]' : 'h-[calc(100vh-5rem)]' }} flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-col gap-2 lg:flex-row">
                        <div class="w-full lg:w-72">
                            <x-input-label for="searchKeyword" value="Cari Shift" class="sr-only" />
                            <x-text-input id="searchKeyword" type="text" wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari kode / nama shift..." class="block w-full" />
                        </div>
                        <div class="w-full lg:w-64 shrink-0">
                            <x-input-label for="filterUnit" value="Unit Kerja" class="sr-only" />
                            <x-select-input id="filterUnit" wire:model.live="filterUnit">
                                <option value="">Semua unit</option>
                                <option value="-">Shift umum (tanpa unit)</option>
                                @foreach ($this->unitList as $unit)
                                    <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}{{ (int) $unit->jumlah_shift > 0 ? ' (' . (int) $unit->jumlah_shift . ')' : ' — pakai shift umum' }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-28">
                            <x-input-label for="itemsPerPage" value="Per halaman" class="sr-only" />
                            <x-select-input id="itemsPerPage" wire:model.live="itemsPerPage">
                                <option value="5">5</option>
                                <option value="10">10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                            </x-select-input>
                        </div>
                        <x-primary-button type="button" wire:click="openCreate">
                            + Tambah Shift
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
                                <th class="ds-c">Kode Jadwal</th>
                                <th>Nama Shift</th>
                                <th>Unit</th>
                                <th>Jam Kerja</th>
                                <th class="ds-c">Toleransi</th>
                                <th>Status</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php $isActive = (string) $row->active_status === '1'; @endphp
                                <tr wire:key="shift-{{ $row->shift_id }}">
                                    <td class="ds-td-token">{{ $row->shift_id }}</td>
                                    <td class="ds-c">
                                        @if ($row->shift_kode)
                                            <span class="inline-block w-7 py-0.5 text-xs font-bold rounded" style="{{ \App\Support\Jadwal::gaya($row->shift_kode, $row->shift_warna) }}">{{ $row->shift_kode }}</span>
                                        @endif
                                    </td>
                                    <td class="ds-td-strong">{{ $row->shift_name }}</td>
                                    <td>
                                        @if ($row->unit_name)
                                            {{ $row->unit_name }}
                                        @else
                                            <span class="text-xs text-muted">semua unit</span>
                                        @endif
                                    </td>
                                    <td class="font-mono">
                                        {{ $row->jam_masuk }} – {{ $row->jam_pulang }}
                                        @if ((string) $row->lintas_hari === '1')
                                            <x-badge variant="purple">lintas hari</x-badge>
                                        @endif
                                    </td>
                                    <td class="ds-c">{{ (int) $row->toleransi_menit }} mnt</td>
                                    <td>
                                        <x-toggle wire:key="toggle-shift-{{ $row->shift_id }}-{{ $isActive ? 1 : 0 }}"
                                            :current="$isActive ? '1' : '0'" trueValue="1" falseValue="0"
                                            wireClick="toggleActive('{{ $row->shift_id }}')">
                                            {{ $isActive ? 'Aktif' : 'Non-aktif' }}
                                        </x-toggle>
                                    </td>
                                    <td class="ds-c">
                                        <div class="flex justify-center gap-2">
                                            <x-action-edit wire:click="openEdit('{{ $row->shift_id }}')" />
                                            <x-action-delete :action="'requestDelete(\'' . $row->shift_id . '\')'"
                                                title="Hapus Shift" message="Yakin hapus {{ $row->shift_name }}?" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-10 text-center text-muted dark:text-gray-400">Data shift tidak ditemukan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::master.master-shift.master-shift-actions wire:key="master-shift-actions" />

        </div>
    </div>
</div>
