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

    #[Computed]
    public function rows()
    {
        $q = DB::table('siapmst_shifts')
            ->select('shift_id', 'shift_name', 'shift_kode', 'jam_masuk', 'jam_pulang', 'toleransi_menit', 'lintas_hari', 'active_status')
            ->orderBy('jam_masuk');

        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->where(fn($sub) => $sub->whereRaw('UPPER(shift_id) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(shift_name) LIKE ?', ["%{$kw}%"]));
        }

        return $q->paginate($this->itemsPerPage);
    }
};
?>

<div>

    <x-page-title
        title="Master Shift"
        subtitle="Jam kerja yang bisa dipilih karyawan saat absen di kiosk" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="w-full lg:max-w-md">
                        <x-input-label for="searchKeyword" value="Cari Shift" class="sr-only" />
                        <x-text-input id="searchKeyword" type="text" wire:model.live.debounce.300ms="searchKeyword"
                            placeholder="Cari kode / nama shift..." class="block w-full" />
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
                                            <span class="inline-block w-7 py-0.5 text-xs font-bold rounded {{ \App\Support\Jadwal::WARNA[$row->shift_kode] ?? \App\Support\Jadwal::WARNA_LAIN }}">{{ $row->shift_kode }}</span>
                                        @endif
                                    </td>
                                    <td class="ds-td-strong">{{ $row->shift_name }}</td>
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
                                    <td colspan="7" class="px-6 py-10 text-center text-muted dark:text-gray-400">Data shift tidak ditemukan.</td>
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
