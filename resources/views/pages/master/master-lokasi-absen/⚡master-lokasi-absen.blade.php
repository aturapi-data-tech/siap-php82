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
        $this->dispatch('master.lokasi-absen.openCreate');
    }

    public function openEdit(string $lokasiId): void
    {
        $this->dispatch('master.lokasi-absen.openEdit', lokasiId: $lokasiId);
    }

    public function requestDelete(string $lokasiId): void
    {
        $this->dispatch('master.lokasi-absen.requestDelete', lokasiId: $lokasiId);
    }

    // Toggle aktif langsung dari tabel — titik non-aktif tidak dipakai absen HP.
    public function toggleActive(string $lokasiId): void
    {
        $current  = (string) DB::table('siapmst_lokasi_absens')->where('lokasi_id', $lokasiId)->value('active_status');
        $newValue = $current === '1' ? '0' : '1';

        DB::table('siapmst_lokasi_absens')->where('lokasi_id', $lokasiId)->update(['active_status' => $newValue, 'updated_at' => now()]);

        $this->dispatch('toast', type: 'success', message: $newValue === '1' ? 'Lokasi diaktifkan.' : 'Lokasi dinon-aktifkan.');
    }

    #[On('master.lokasi-absen.saved')]
    public function refreshAfterSaved(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function rows()
    {
        $query = DB::table('siapmst_lokasi_absens as lk')
            ->select('lk.lokasi_id', 'lk.lokasi_name', 'lk.alamat', 'lk.latitude', 'lk.longitude', 'lk.radius_meter', 'lk.active_status',
                DB::raw('(SELECT COUNT(*) FROM siapmst_employers em WHERE em.emp_lokasi_id = lk.lokasi_id) AS jumlah_karyawan'))
            ->orderBy('lk.lokasi_name');

        if (trim($this->searchKeyword) !== '') {
            $keyword = mb_strtoupper(trim($this->searchKeyword));
            $query->where(fn($subQuery) => $subQuery->whereRaw('UPPER(lk.lokasi_id) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('UPPER(lk.lokasi_name) LIKE ?', ["%{$keyword}%"]));
        }

        return $query->paginate($this->itemsPerPage);
    }
};
?>

<div>

    @unless ($embedded)
    <x-page-title
        title="Lokasi Absen HP"
        subtitle="Titik RS / klinik — karyawan bisa absen lewat HP bila GPS-nya di dalam radius titik ini" />
    @endunless

    <div class="w-full {{ $embedded ? 'min-h-[32rem]' : 'h-[calc(100vh-5rem)]' }} flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="w-full lg:max-w-md">
                        <x-input-label for="searchKeyword" value="Cari Lokasi" class="sr-only" />
                        <x-text-input id="searchKeyword" type="text" wire:model.live.debounce.300ms="searchKeyword"
                            placeholder="Cari kode / nama lokasi..." class="block w-full" />
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
                            + Tambah Lokasi
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
                                <th>Nama Lokasi</th>
                                <th>Titik Koordinat</th>
                                <th class="ds-c">Radius</th>
                                <th class="ds-c">Karyawan</th>
                                <th>Status</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php $isActive = (string) $row->active_status === '1'; @endphp
                                <tr wire:key="lokasi-{{ $row->lokasi_id }}">
                                    <td class="ds-td-token">{{ $row->lokasi_id }}</td>
                                    <td>
                                        <div class="ds-td-strong">{{ $row->lokasi_name }}</div>
                                        @if ($row->alamat)
                                            <div class="text-xs text-muted">{{ $row->alamat }}</div>
                                        @endif
                                    </td>
                                    <td class="font-mono text-sm">
                                        <a href="https://www.google.com/maps?q={{ $row->latitude }},{{ $row->longitude }}" target="_blank" rel="noopener"
                                            class="underline text-brand-green">{{ $row->latitude }}, {{ $row->longitude }}</a>
                                    </td>
                                    <td class="ds-c">{{ \App\Support\LokasiAbsen::labelJarak((int) $row->radius_meter) }}</td>
                                    <td class="ds-c">{{ (int) $row->jumlah_karyawan }}</td>
                                    <td>
                                        <x-toggle wire:key="toggle-lokasi-{{ $row->lokasi_id }}-{{ $isActive ? 1 : 0 }}"
                                            :current="$isActive ? '1' : '0'" trueValue="1" falseValue="0"
                                            wireClick="toggleActive('{{ $row->lokasi_id }}')">
                                            {{ $isActive ? 'Aktif' : 'Non-aktif' }}
                                        </x-toggle>
                                    </td>
                                    <td class="ds-c">
                                        <div class="flex justify-center gap-2">
                                            <x-action-edit wire:click="openEdit('{{ $row->lokasi_id }}')" />
                                            <x-action-delete :action="'requestDelete(\'' . $row->lokasi_id . '\')'"
                                                title="Hapus Lokasi" message="Yakin hapus {{ $row->lokasi_name }}?" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-10 text-center text-muted dark:text-gray-400">Belum ada titik lokasi absen.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::master.master-lokasi-absen.master-lokasi-absen-actions wire:key="master-lokasi-absen-actions" />

        </div>
    </div>
</div>
