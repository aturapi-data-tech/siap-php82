<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\Perbaikan;

/**
 * Daftar perbaikan / kerusakan alat (bukti MFK 7 EP d). Mutasi di -actions.
 * Tab status mengikuti alur Pedoman: Dilaporkan → Ditangani → Vendor → Selesai → Diserahkan.
 */
new class extends Component {
    use WithPagination;

    public string $filterStatus  = 'AKTIF'; // AKTIF | SEMUA | B P V S K X
    public string $filterRuang   = '';
    public string $searchKeyword = '';
    public int    $itemsPerPage  = 25;

    public function updated($property): void
    {
        if (in_array($property, ['filterStatus', 'filterRuang', 'searchKeyword', 'itemsPerPage'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['filterRuang', 'searchKeyword']);
        $this->filterStatus = 'AKTIF';
        $this->itemsPerPage = 25;
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->dispatch('aset.perbaikan-alat.openCreate');
    }

    public function openEdit(int $pbId): void
    {
        $this->dispatch('aset.perbaikan-alat.openEdit', pbId: $pbId);
    }

    #[On('aset.perbaikan-alat.saved')]
    public function refreshAfterSaved(): void {}

    #[Computed]
    public function ruangs()
    {
        return DB::table('siapmst_ruangs')->orderBy('ruang_id')->get(['ruang_id', 'ruang_name']);
    }

    private function baseQuery()
    {
        $q = DB::table('siaptxn_perbaikans as pb')
            ->join('siapmst_assets as aa', 'aa.asset_id', '=', 'pb.asset_id');

        if ($this->filterRuang !== '') {
            $q->where('pb.ruang_id', $this->filterRuang);
        }
        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->where(fn($s) => $s->whereRaw('UPPER(pb.pb_no) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(aa.asset_name) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(pb.asset_id) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(pb.kerusakan) LIKE ?', ["%{$kw}%"]));
        }

        return $q;
    }

    /** Jumlah per status (untuk tab) — tidak terpengaruh tab aktif. */
    #[Computed]
    public function jumlahStatus(): array
    {
        $hitung = $this->baseQuery()->groupBy('pb.pb_status')->select('pb.pb_status', DB::raw('COUNT(*) AS n'))->pluck('n', 'pb_status');
        $hasil = ['SEMUA' => (int) $hitung->sum(), 'AKTIF' => 0];
        foreach (array_keys(Perbaikan::STATUS) as $k) {
            $hasil[$k] = (int) ($hitung[$k] ?? 0);
            if (in_array($k, Perbaikan::STATUS_AKTIF, true)) {
                $hasil['AKTIF'] += $hasil[$k];
            }
        }

        return $hasil;
    }

    #[Computed]
    public function rows()
    {
        $q = $this->baseQuery()
            ->leftJoin('siapmst_ruangs as ru', 'ru.ruang_id', '=', 'pb.ruang_id')
            ->select('pb.*', 'aa.asset_name', 'aa.merk', 'aa.tipe_model', 'aa.no_seri', 'aa.wajib_kalibrasi', 'ru.ruang_name')
            ->orderByDesc('pb.tgl_permintaan');

        match ($this->filterStatus) {
            'SEMUA' => null,
            'AKTIF' => $q->whereIn('pb.pb_status', Perbaikan::STATUS_AKTIF),
            default => $q->where('pb.pb_status', $this->filterStatus),
        };

        return $q->paginate($this->itemsPerPage);
    }
};
?>

<div>

    <x-page-title
        title="Perbaikan Alat"
        subtitle="Laporan kerusakan & perbaikan alat — teknisi RS / vendor, sampai diserahkan kembali ke ruang (MFK 7)" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="w-full lg:w-72">
                            <x-text-input type="text" wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari no. / alat / kerusakan..." class="block w-full" />
                        </div>
                        <div class="w-48">
                            <x-select-input wire:model.live="filterRuang">
                                <option value="">Semua ruang</option>
                                @foreach ($this->ruangs as $ru)
                                    <option value="{{ $ru->ruang_id }}">{{ $ru->ruang_id }} — {{ $ru->ruang_name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <x-primary-button type="button" wire:click="openCreate">+ Lapor Kerusakan</x-primary-button>
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>

                {{-- Tab status --}}
                @php $js = $this->jumlahStatus; @endphp
                <div class="flex flex-wrap gap-1.5 mt-3">
                    @foreach (['AKTIF' => 'Belum tuntas', ...array_map(fn($s) => $s[0], \App\Support\Perbaikan::STATUS), 'SEMUA' => 'Semua'] as $kode => $nama)
                        <button type="button" wire:click="$set('filterStatus', '{{ $kode }}')"
                            class="px-3 py-1.5 text-xs font-semibold rounded-lg border transition
                                {{ $filterStatus === $kode ? 'bg-brand-green text-white border-brand-green' : 'bg-canvas text-muted border-hairline hover:border-gray-400' }}">
                            {{ $nama }} <span class="ml-0.5 opacity-80">({{ $js[$kode] ?? 0 }})</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-t-2xl">
                    <table class="ds-table">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>No. / Tgl Lapor</th>
                                <th>Alat</th>
                                <th>Kerusakan</th>
                                <th>Respon</th>
                                <th>Pelaksana</th>
                                <th class="ds-c">Lama</th>
                                <th class="text-right">Biaya</th>
                                <th>Status</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php
                                    $respon = \App\Support\Perbaikan::menitRespon($row);
                                    [$stLabel, $stVar] = \App\Support\Perbaikan::STATUS[$row->pb_status];
                                @endphp
                                <tr wire:key="pb-{{ $row->pb_id }}">
                                    <td class="whitespace-nowrap">
                                        <div class="ds-td-token">{{ $row->pb_no }}</div>
                                        <div class="text-xs text-muted">{{ \App\Support\Format::waktu($row->tgl_permintaan) }}</div>
                                        @if ($row->pm_id)
                                            <div class="text-[10px] text-muted-soft">dari checklist pemeliharaan</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="ds-td-strong">{{ $row->asset_name }}</div>
                                        <div class="font-mono text-xs text-muted">{{ $row->asset_id }}</div>
                                        <div class="text-[11px] text-muted-soft">{{ $row->ruang_id }} {{ $row->ruang_name }}</div>
                                    </td>
                                    <td class="max-w-[18rem]">
                                        <div class="text-sm">{{ $row->kerusakan }}</div>
                                        @if ($row->analisa)
                                            <div class="mt-0.5 text-xs text-muted">Analisa: {{ $row->analisa }}</div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @if ($respon === null)
                                            <span class="text-xs italic text-muted-soft">belum direspon</span>
                                        @else
                                            <span class="font-mono text-sm {{ $respon > \App\Support\Perbaikan::TARGET_RESPON_MENIT ? 'text-red-700 font-semibold' : 'text-emerald-700' }}">
                                                {{ \App\Support\Absensi::labelMenit($respon) ?: '0 mnt' }}
                                            </span>
                                            <div class="text-[11px] text-muted">{{ $row->teknisi }}</div>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        {{ $row->pb_pelaksana ? \App\Support\Perbaikan::PELAKSANA[$row->pb_pelaksana] : '—' }}
                                        @if ($row->vendor)
                                            <div class="text-xs text-muted">{{ $row->vendor }}</div>
                                        @endif
                                    </td>
                                    <td class="ds-c">{{ \App\Support\Perbaikan::lamaHari($row) }} hari</td>
                                    <td class="font-mono text-right">{{ (float) $row->biaya > 0 ? number_format((float) $row->biaya, 0, ',', '.') : '—' }}</td>
                                    <td><x-badge :variant="$stVar">{{ $stLabel }}</x-badge></td>
                                    <td class="ds-c">
                                        <div class="flex justify-center gap-2">
                                            <x-secondary-button type="button" wire:click="openEdit({{ $row->pb_id }})" class="!px-2.5 !py-1 text-xs">
                                                {{ in_array($row->pb_status, \App\Support\Perbaikan::STATUS_AKTIF, true) ? 'Proses' : 'Lihat' }}
                                            </x-secondary-button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-10 text-center text-muted dark:text-gray-400">Tidak ada data perbaikan untuk filter ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::aset.perbaikan-alat.perbaikan-alat-actions wire:key="perbaikan-alat-actions" />

        </div>
    </div>
</div>
