<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Support\Kalibrasi;

/**
 * JADWAL KALIBRASI TAHUNAN — matriks alat × Jan–Des (bukti MFK 7: "program dan jadwal
 * kalibrasi alat medis", SPO Kalibrasi RS langkah 2).
 *   J  = jatuh tempo (berlaku s/d kalibrasi sebelumnya)  ✓ = dikalibrasi (Laik)  ✗ = Tidak Laik
 * Klik nama alat → riwayat & catat kalibrasi (aset.kalibrasi-alat). Cetak PDF A4 melintang.
 */
new class extends Component {
    public string $tahun         = '';
    public string $filterRuang   = '';
    public string $searchKeyword = '';

    private string $printView = 'pages.aset.jadwal-kalibrasi.jadwal-kalibrasi-print';

    public function mount(): void
    {
        $this->tahun = (string) now()->year;
    }

    public function geserTahun(int $n): void
    {
        $this->tahun = (string) ($this->tahunInt() + $n);
    }

    public function resetFilters(): void
    {
        $this->reset(['filterRuang', 'searchKeyword']);
        $this->tahun = (string) now()->year;
    }

    public function openKalibrasi(string $assetId): void
    {
        $this->dispatch('aset.kalibrasi-alat.open', assetId: $assetId);
    }

    #[On('aset.kalibrasi-alat.saved')]
    public function refreshAfterKalibrasi(): void {}

    private function tahunInt(): int
    {
        $t = (int) $this->tahun;

        return $t >= 2000 && $t <= 2100 ? $t : now()->year;
    }

    #[Computed]
    public function ruangs()
    {
        return DB::table('siapmst_ruangs')->orderBy('ruang_id')->get(['ruang_id', 'ruang_name']);
    }

    #[Computed]
    public function jadwal(): array
    {
        return Kalibrasi::jadwalTahunan($this->tahunInt(), $this->filterRuang, $this->searchKeyword);
    }

    /** Ringkasan kartu atas. */
    #[Computed]
    public function ringkasan(): array
    {
        $j = collect($this->jadwal);
        $bulanIni = now()->month;
        $tahunIni = $this->tahunInt() === now()->year;

        return [
            'total'     => $j->count(),
            'dikal'     => $j->filter(fn($r) => collect($r['bulan'])->contains(fn($b) => $b['kal'] !== null))->count(),
            'bulan_ini' => $tahunIni ? $j->filter(fn($r) => $r['bulan'][$bulanIni]['jadwal'] === 'rencana')->count() : 0,
            'lewat'     => $j->filter(fn($r) => in_array($r['status'][0] ?? '', ['LEWAT', 'TIDAK'], true))->count(),
            'segera'    => $j->filter(fn($r) => ($r['status'][0] ?? '') === 'SEGERA')->count(),
            'belum'     => $j->filter(fn($r) => ($r['status'][0] ?? '') === 'BELUM')->count(),
        ];
    }

    public function cetak()
    {
        $data = [
            'tahun'   => $this->tahunInt(),
            'ruang'   => $this->filterRuang !== '' ? DB::table('siapmst_ruangs')->where('ruang_id', $this->filterRuang)->value('ruang_name') : 'SEMUA RUANG',
            'jadwal'  => $this->jadwal,
            'dicetak' => now(),
            'oleh'    => auth()->user()?->name,
        ];

        $pdf = Pdf::loadView($this->printView, $data)->setPaper('A4', 'landscape');

        return response()->streamDownload(fn() => print $pdf->output(), 'jadwal-kalibrasi-' . $data['tahun'] . '.pdf');
    }
};
?>

<div>

    <x-page-title
        title="Jadwal Kalibrasi Alat"
        subtitle="Program kalibrasi tahunan — Permenkes 54/2015: minimal 1x setahun oleh BPFK / lembaga terakreditasi KAN" />

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex items-center gap-1">
                            <x-secondary-button type="button" wire:click="geserTahun(-1)" class="!px-2.5" title="Tahun sebelumnya">‹</x-secondary-button>
                            <x-text-input wire:model.live.debounce.600ms="tahun" x-mask="9999" placeholder="yyyy" class="w-20 font-mono text-center" />
                            <x-secondary-button type="button" wire:click="geserTahun(1)" class="!px-2.5" title="Tahun berikutnya">›</x-secondary-button>
                        </div>
                        <div class="w-48">
                            <x-select-input wire:model.live="filterRuang">
                                <option value="">Semua ruang</option>
                                @foreach ($this->ruangs as $ru)
                                    <option value="{{ $ru->ruang_id }}">{{ $ru->ruang_id }} — {{ $ru->ruang_name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div class="w-full lg:w-64">
                            <x-text-input type="text" wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari kode / nama alat..." class="block w-full" />
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <x-primary-button type="button" wire:click="cetak" wire:loading.attr="disabled" wire:target="cetak">
                            <span wire:loading.remove wire:target="cetak">Cetak PDF</span>
                            <span wire:loading wire:target="cetak">Menyiapkan...</span>
                        </x-primary-button>
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>

                {{-- Ringkasan --}}
                @php $rk = $this->ringkasan; @endphp
                <div class="flex flex-wrap gap-2 mt-3 text-xs font-semibold">
                    <span class="px-3 py-1.5 rounded-lg border bg-canvas border-hairline">Wajib kalibrasi: {{ $rk['total'] }}</span>
                    <span class="px-3 py-1.5 rounded-lg border bg-emerald-50 border-emerald-200 text-emerald-800">Sudah dikalibrasi {{ $tahun }}: {{ $rk['dikal'] }}</span>
                    @if ($rk['bulan_ini'] > 0)
                        <span class="px-3 py-1.5 rounded-lg border bg-sky-50 border-sky-200 text-sky-800">Jatuh tempo bulan ini: {{ $rk['bulan_ini'] }}</span>
                    @endif
                    <span class="px-3 py-1.5 rounded-lg border bg-amber-50 border-amber-200 text-amber-800">Segera (≤ {{ \App\Support\Kalibrasi::BATAS_SEGERA_HARI }} hari): {{ $rk['segera'] }}</span>
                    <span class="px-3 py-1.5 rounded-lg border bg-red-50 border-red-200 text-red-800">Kedaluwarsa / tidak laik: {{ $rk['lewat'] }}</span>
                    <span class="px-3 py-1.5 rounded-lg border bg-gray-100 border-gray-200 text-gray-700">Belum pernah: {{ $rk['belum'] }}</span>
                </div>
            </div>

            {{-- MATRIKS --}}
            <div class="mt-4 overflow-x-auto border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <table class="ds-table">
                    <thead>
                        <tr>
                            <th>Alat</th>
                            <th>Ruang</th>
                            @foreach (['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'] as $i => $bln)
                                <th class="ds-c {{ (int) $tahun === now()->year && $i + 1 === now()->month ? 'bg-sky-50' : '' }}">{{ $bln }}</th>
                            @endforeach
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->jadwal as $r)
                            @php $a = $r['alat']; @endphp
                            <tr wire:key="jadwal-{{ $a->asset_id }}">
                                <td>
                                    <button type="button" wire:click="openKalibrasi(@js($a->asset_id))"
                                        class="font-semibold text-left text-brand-green hover:underline dark:text-brand-lime" wire:loading.attr="disabled" wire:target="openKalibrasi(@js($a->asset_id))">
                                        {{ $a->asset_name }}
                                    <x-loading wire:loading.delay wire:target="openKalibrasi(@js($a->asset_id))" class="ml-1 align-middle" /></button>
                                    <div class="font-mono text-xs text-muted">{{ $a->asset_id }}</div>
                                    <div class="text-[11px] text-muted-soft">{{ $a->merk }} {{ $a->tipe_model }}{{ $a->no_seri ? ' · SN ' . $a->no_seri : '' }}</div>
                                </td>
                                <td class="font-mono text-sm">{{ $a->ruang_id }}</td>
                                @foreach ($r['bulan'] as $m => $b)
                                    @php
                                        $warna = match ($b['jadwal']) {
                                            'terlewat'  => 'bg-red-100 text-red-800',
                                            'rencana'   => 'bg-sky-100 text-sky-800',
                                            'terpenuhi' => 'bg-gray-100 text-gray-500',
                                            default     => '',
                                        };
                                    @endphp
                                    <td class="ds-c !px-1 {{ (int) $tahun === now()->year && $m === now()->month ? 'bg-sky-50/50' : '' }}">
                                        @if ($b['jadwal'] || $b['kal'])
                                            <div class="inline-flex items-center justify-center gap-0.5 min-w-[2.2rem] px-1 py-0.5 rounded font-bold text-xs {{ $warna }}">
                                                {{-- Bentuk simbol ikut membedakan status (bukan warna saja): J rencana · J! terlewat · J✓ terpenuhi --}}
                                                @if ($b['jadwal'])<span title="{{ $labelJadwal = ['terlewat' => 'Jatuh tempo — terlewat', 'terpenuhi' => 'Jatuh tempo — sudah dikalibrasi ulang'][$b['jadwal']] ?? 'Jatuh tempo kalibrasi' }}"><span aria-hidden="true">{{ ['terlewat' => 'J!', 'terpenuhi' => 'J✓'][$b['jadwal']] ?? 'J' }}</span><span class="sr-only">{{ $labelJadwal }}</span></span>@endif
                                                @if ($b['kal'] === 'L')<span class="text-emerald-700" title="Dikalibrasi — Laik Pakai">✓</span>@endif
                                                @if ($b['kal'] === 'T')<span class="text-red-700" title="Dikalibrasi — Tidak Laik Pakai">✗</span>@endif
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                                <td>
                                    @if ($r['status'])
                                        <x-badge :variant="$r['status'][2]">{{ $r['status'][1] }}</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="15" class="px-6 py-10 text-center text-muted dark:text-gray-400">
                                    Belum ada alat aktif yang wajib kalibrasi untuk filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Legenda --}}
            <div class="flex flex-wrap gap-4 px-1 mt-3 text-xs text-muted dark:text-gray-400">
                <span><b class="px-1.5 rounded bg-sky-100 text-sky-800">J</b> jatuh tempo (rencana)</span>
                <span><b class="px-1.5 rounded bg-red-100 text-red-800">J!</b> jatuh tempo terlewat / belum pernah</span>
                <span><b class="px-1.5 rounded bg-gray-100 text-gray-500">J✓</b> jatuh tempo — sudah dikalibrasi ulang</span>
                <span><b class="text-emerald-700">✓</b> dikalibrasi, Laik Pakai</span>
                <span><b class="text-red-700">✗</b> dikalibrasi, Tidak Laik Pakai</span>
            </div>

            <livewire:pages::aset.kalibrasi-alat.kalibrasi-alat-actions wire:key="kalibrasi-alat-actions" />

        </div>
    </div>
</div>
