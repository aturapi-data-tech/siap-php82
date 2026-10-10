<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\Kalibrasi;

/**
 * Inventaris alat (medis & non medis) + status kalibrasi dari riwayat terakhir.
 * Kolom mengikuti formulir "Daftar Inventaris" RS (bukti akreditasi MFK 7).
 * Kalibrasi dikelola lewat modal aset.kalibrasi-alat (dipakai juga Jadwal Kalibrasi).
 */
new class extends Component {
    use WithPagination;

    public string $searchKeyword = '';
    public string $filterJenis   = '';
    public string $filterRuang   = '';
    public string $filterKondisi = '';
    public string $filterKalibrasi = ''; // '' | BELUM | TIDAK | LEWAT | SEGERA | OK | TIDAK_WAJIB
    public int    $itemsPerPage  = 25;

    public function updated($property): void
    {
        if (in_array($property, ['searchKeyword', 'filterJenis', 'filterRuang', 'filterKondisi', 'filterKalibrasi', 'itemsPerPage'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterJenis', 'filterRuang', 'filterKondisi', 'filterKalibrasi']);
        $this->itemsPerPage = 25;
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->dispatch('master.alat.openCreate');
    }

    public function openEdit(string $assetId): void
    {
        $this->dispatch('master.alat.openEdit', assetId: $assetId);
    }

    public function requestDelete(string $assetId): void
    {
        $this->dispatch('master.alat.requestDelete', assetId: $assetId);
    }

    public function openKalibrasi(string $assetId): void
    {
        $this->dispatch('aset.kalibrasi-alat.open', assetId: $assetId);
    }

    #[On('master.alat.saved')]
    public function refreshAfterSaved(): void
    {
        $this->resetPage();
    }

    // Kalibrasi berubah → cukup render ulang (badge), halaman tetap.
    #[On('aset.kalibrasi-alat.saved')]
    public function refreshAfterKalibrasi(): void {}

    #[Computed]
    public function ruangs()
    {
        return DB::table('siapmst_ruangs')->orderBy('ruang_id')->get(['ruang_id', 'ruang_name']);
    }

    #[Computed]
    public function rows()
    {
        $sd    = 'kl.kal_berlaku_sd';
        $hasil = 'kl.kal_hasil';
        $hariIni = 'TRUNC(SYSDATE)';

        // kl = kalibrasi terakhir per alat (inline view ROW_NUMBER — lihat Kalibrasi::sqlTerakhir)
        $q = DB::table('siapmst_assets as aa')
            ->leftJoin('siapmst_ruangs as ru', 'ru.ruang_id', '=', 'aa.ruang_id')
            ->leftJoin(DB::raw(Kalibrasi::sqlTerakhir() . ' kl'), 'kl.asset_id', '=', 'aa.asset_id')
            ->select('aa.*', 'ru.ruang_name')
            ->orderBy('aa.ruang_id')
            ->orderBy('aa.asset_name');

        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->where(fn($sub) => $sub->whereRaw('UPPER(aa.asset_id) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(aa.asset_name) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(aa.merk) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(aa.no_seri) LIKE ?', ["%{$kw}%"]));
        }
        if ($this->filterJenis !== '')   { $q->where('aa.asset_jenis', $this->filterJenis); }
        if ($this->filterRuang !== '')   { $q->where('aa.ruang_id', $this->filterRuang); }
        if ($this->filterKondisi !== '') { $q->where('aa.kondisi', $this->filterKondisi); }

        $batas = Kalibrasi::BATAS_SEGERA_HARI;
        match ($this->filterKalibrasi) {
            'TIDAK_WAJIB' => $q->where('aa.wajib_kalibrasi', '<>', '1'),
            'BELUM'  => $q->where('aa.wajib_kalibrasi', '1')->whereNull('kl.asset_id'),
            'TIDAK'  => $q->where('aa.wajib_kalibrasi', '1')->whereRaw("{$hasil} = 'T'"),
            'LEWAT'  => $q->where('aa.wajib_kalibrasi', '1')->whereRaw("{$hasil} = 'L' AND {$sd} < {$hariIni}"),
            'SEGERA' => $q->where('aa.wajib_kalibrasi', '1')->whereRaw("{$hasil} = 'L' AND {$sd} BETWEEN {$hariIni} AND {$hariIni} + {$batas}"),
            'OK'     => $q->where('aa.wajib_kalibrasi', '1')->whereRaw("{$hasil} = 'L' AND ({$sd} IS NULL OR {$sd} > {$hariIni} + {$batas})"),
            default  => null,
        };

        $rows = $q->paginate($this->itemsPerPage);

        $terakhir = Kalibrasi::terakhir($rows->pluck('asset_id')->all());
        foreach ($rows as $row) {
            $row->kal_terakhir = $terakhir[$row->asset_id] ?? null;
            $row->kal_status = Kalibrasi::status($row, $row->kal_terakhir);
        }

        return $rows;
    }
};
?>

<div>

    <x-page-title
        title="Inventaris Alat"
        subtitle="Daftar alat medis & non medis per ruang + status kalibrasi (Permenkes 54/2015: minimal 1x setahun)" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="w-full lg:w-64">
                            <x-input-label for="searchKeyword" value="Cari Alat" class="sr-only" />
                            <x-text-input id="searchKeyword" type="text" wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari kode / nama / merk / no. seri..." class="block w-full" />
                        </div>
                        <div class="w-36">
                            <x-select-input wire:model.live="filterJenis">
                                <option value="">Semua jenis</option>
                                @foreach (\App\Support\Kalibrasi::JENIS as $kode => $nama)
                                    <option value="{{ $kode }}">{{ $nama }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div class="w-44">
                            <x-select-input wire:model.live="filterRuang">
                                <option value="">Semua ruang</option>
                                @foreach ($this->ruangs as $ru)
                                    <option value="{{ $ru->ruang_id }}">{{ $ru->ruang_id }} — {{ $ru->ruang_name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div class="w-36">
                            <x-select-input wire:model.live="filterKondisi">
                                <option value="">Semua kondisi</option>
                                @foreach (\App\Support\Kalibrasi::KONDISI as $kode => $nama)
                                    <option value="{{ $kode }}">{{ $nama }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div class="w-44">
                            <x-select-input wire:model.live="filterKalibrasi">
                                <option value="">Semua status kalibrasi</option>
                                @foreach (\App\Support\Kalibrasi::STATUS as $kode => [$nama])
                                    <option value="{{ $kode }}">{{ $nama }}</option>
                                @endforeach
                                <option value="TIDAK_WAJIB">Tidak wajib kalibrasi</option>
                            </x-select-input>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-24">
                            <x-select-input wire:model.live="itemsPerPage">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </x-select-input>
                        </div>
                        <x-primary-button type="button" wire:click="openCreate">
                            + Tambah Alat
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
                                <th>Alat</th>
                                <th>Merk / Type</th>
                                <th>No. Seri</th>
                                <th>Ruang</th>
                                <th class="ds-c">Tahun</th>
                                <th class="ds-c">Jml</th>
                                <th>Kondisi</th>
                                <th>Kalibrasi</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                <tr wire:key="alat-{{ $row->asset_id }}" class="{{ (string) $row->active_status === '1' ? '' : 'opacity-50' }}">
                                    <td>
                                        <div class="ds-td-strong">{{ $row->asset_name }}</div>
                                        <div class="font-mono text-xs text-muted">{{ $row->asset_id }}</div>
                                        <div class="text-[11px] text-muted-soft">
                                            {{ \App\Support\Kalibrasi::JENIS[$row->asset_jenis] ?? '' }}
                                            @if (($row->pm_frekuensi ?? '-') !== '-')
                                                · perawatan {{ strtolower(\App\Support\Pemeliharaan::FREKUENSI[$row->pm_frekuensi]) }}
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <div>{{ $row->merk ?: '—' }}</div>
                                        <div class="text-xs text-muted">{{ $row->tipe_model }}</div>
                                    </td>
                                    <td class="font-mono text-sm">{{ $row->no_seri ?: '—' }}</td>
                                    <td>
                                        <div class="font-mono text-sm">{{ $row->ruang_id }}</div>
                                        <div class="text-xs text-muted">{{ $row->ruang_name }}</div>
                                    </td>
                                    <td class="ds-c">{{ $row->tahun_pengadaan }}</td>
                                    <td class="ds-c">{{ (int) $row->jumlah }}</td>
                                    <td>
                                        <x-badge :variant="\App\Support\Kalibrasi::KONDISI_BADGE[$row->kondisi] ?? 'gray'">
                                            {{ \App\Support\Kalibrasi::KONDISI[$row->kondisi] ?? $row->kondisi }}
                                        </x-badge>
                                    </td>
                                    <td>
                                        @if ($row->kal_status)
                                            <button type="button" wire:click="openKalibrasi(@js($row->asset_id))" title="Riwayat & input kalibrasi">
                                                <x-badge :variant="$row->kal_status[2]">{{ $row->kal_status[1] }}</x-badge>
                                            </button>
                                            @if ($row->kal_terakhir)
                                                <div class="mt-0.5 text-[11px] text-muted">
                                                    {{ \App\Support\Format::tanggal($row->kal_terakhir->kal_tanggal) }}
                                                    @if ($row->kal_terakhir->kal_berlaku_sd)
                                                        → s/d {{ \App\Support\Format::tanggal($row->kal_terakhir->kal_berlaku_sd) }}
                                                    @endif
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-xs text-muted-soft">Tidak wajib</span>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        <div class="flex justify-center gap-2">
                                            @if ((string) $row->wajib_kalibrasi === '1')
                                                <x-secondary-button type="button" wire:click="openKalibrasi({{ \Illuminate\Support\Js::from($row->asset_id) }})" class="!px-2.5 !py-1 text-xs">
                                                    Kalibrasi
                                                </x-secondary-button>
                                            @endif
                                            <x-action-edit wire:click="openEdit({{ \Illuminate\Support\Js::from($row->asset_id) }})" />
                                            <x-action-delete :action="'requestDelete(' . \Illuminate\Support\Js::from($row->asset_id) . ')'"
                                                title="Hapus Alat" message="Yakin hapus {{ $row->asset_name }} ({{ $row->asset_id }})?" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-10 text-center text-muted dark:text-gray-400">Data alat tidak ditemukan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::master.master-alat.master-alat-actions wire:key="master-alat-actions" />
            <livewire:pages::aset.kalibrasi-alat.kalibrasi-alat-actions wire:key="kalibrasi-alat-actions" />

        </div>
    </div>
</div>
