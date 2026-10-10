<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Support\Format;
use App\Support\Pemeliharaan;

/**
 * PEMELIHARAAN PREVENTIF — riwayat pemeriksaan alat (bukti MFK 7). Entry / edit di -actions:
 * per ruang per tanggal semua alat sekaligus (harian mis. alat IGD, atau mingguan).
 * Temuan P/R → "Lapor" membuka aset.perbaikan-alat. Cetak = format "Checklist Perawatan Alat" RS
 * per ruang (atau semua ruang) per bulan per frekuensi.
 */
new class extends Component {
    use WithPagination;

    public string $searchKeyword   = '';
    public string $filterRuang     = '';
    public string $filterFrekuensi = ''; // '' | H | M | -
    public string $filterHasil     = ''; // '' | B | P | R
    public string $bulan           = ''; // mm/yyyy
    public int    $itemsPerPage    = 25;

    private string $printView = 'pages.aset.pemeliharaan-alat.pemeliharaan-alat-print';

    public function mount(): void
    {
        $this->bulan = now()->format(Format::BULAN);
    }

    public function updated($property): void
    {
        if (in_array($property, ['searchKeyword', 'filterRuang', 'filterFrekuensi', 'filterHasil', 'bulan', 'itemsPerPage'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterRuang', 'filterFrekuensi', 'filterHasil']);
        $this->bulan = now()->format(Format::BULAN);
        $this->itemsPerPage = 25;
        $this->resetPage();
    }

    public function geserBulan(int $n): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($n)->format(Format::BULAN);
        $this->resetPage();
    }

    public function awalBulan(): Carbon
    {
        try {
            return Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    // ─── Aksi ─────────────────────────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->dispatch('aset.pemeliharaan-alat.openCreate', ruangId: $this->filterRuang);
    }

    public function openEdit(int $pmId): void
    {
        $this->dispatch('aset.pemeliharaan-alat.openEdit', pmId: $pmId);
    }

    public function requestDelete(int $pmId): void
    {
        $this->dispatch('aset.pemeliharaan-alat.requestDelete', pmId: $pmId);
    }

    public function laporkanPerbaikan(int $pmId): void
    {
        $this->dispatch('aset.perbaikan-alat.openFromPemeliharaan', pmId: $pmId);
    }

    public function bukaPerbaikan(int $pbId): void
    {
        $this->dispatch('aset.perbaikan-alat.openEdit', pbId: $pbId);
    }

    #[On('aset.pemeliharaan-alat.saved')]
    #[On('aset.perbaikan-alat.saved')]
    public function refreshAfterSaved(): void {}

    // ─── Data ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function ruangs()
    {
        return DB::table('siapmst_ruangs as ru')
            ->whereExists(fn($s) => $s->select(DB::raw(1))->from('siapmst_assets as aa')
                ->whereColumn('aa.ruang_id', 'ru.ruang_id')->where('aa.active_status', '1'))
            ->orderBy('ru.ruang_id')
            ->get(['ru.ruang_id', 'ru.ruang_name']);
    }

    /** Progres hari ini (alat harian) & minggu ini (alat mingguan) untuk filter ruang. */
    #[Computed]
    public function progres(): array
    {
        $hasil = [];
        foreach (['H' => now(), 'M' => now()] as $frek => $tgl) {
            [$dari, $sampai] = Pemeliharaan::rentangSlot($tgl, $frek);
            $alat = DB::table('siapmst_assets')->where('active_status', '1')->where('pm_frekuensi', $frek)
                ->when($this->filterRuang !== '', fn($q) => $q->where('ruang_id', $this->filterRuang));
            $total = (clone $alat)->count();
            $sudah = DB::table('siaptxn_pemeliharaans')
                ->whereIn('asset_id', (clone $alat)->select('asset_id'))
                ->where('pm_tanggal', '>=', Absensi::tanggalSql($dari))
                ->where('pm_tanggal', '<=', Absensi::tanggalSql($sampai))
                ->distinct()->count('asset_id');
            $hasil[$frek] = ['total' => $total, 'sudah' => $sudah, 'dari' => $dari, 'sampai' => $sampai];
        }

        return $hasil;
    }

    #[Computed]
    public function rows()
    {
        $awal = $this->awalBulan();
        $q = DB::table('siaptxn_pemeliharaans as pm')
            ->join('siapmst_assets as aa', 'aa.asset_id', '=', 'pm.asset_id')
            ->leftJoin('siapmst_ruangs as ru', 'ru.ruang_id', '=', 'aa.ruang_id')
            ->leftJoin('users as us', 'us.id', '=', 'pm.petugas_id')
            ->where('pm.pm_tanggal', '>=', Absensi::tanggalSql($awal))
            ->where('pm.pm_tanggal', '<=', Absensi::tanggalSql($awal->copy()->endOfMonth()))
            ->when($this->filterRuang !== '', fn($q) => $q->where('aa.ruang_id', $this->filterRuang))
            ->when($this->filterFrekuensi !== '', fn($q) => $q->where('aa.pm_frekuensi', $this->filterFrekuensi))
            ->when($this->filterHasil !== '', fn($q) => $q->where('pm.pm_hasil', $this->filterHasil));

        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->where(fn($s) => $s->whereRaw('UPPER(aa.asset_name) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(aa.asset_id) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(pm.keterangan) LIKE ?', ["%{$kw}%"]));
        }

        $rows = $q->select('pm.*', 'aa.asset_name', 'aa.ruang_id', 'aa.pm_frekuensi', 'ru.ruang_name', 'us.name as petugas_name')
            ->orderByDesc('pm.pm_tanggal')->orderBy('aa.ruang_id')->orderBy('aa.asset_name')
            ->paginate($this->itemsPerPage);

        // Perbaikan yang sudah dibuat dari temuan P/R di halaman ini.
        $pmIds = collect($rows->items())->whereIn('pm_hasil', ['P', 'R'])->pluck('pm_id')->map(fn($v) => (int) $v)->all();
        $pb = DB::table('siaptxn_perbaikans')->whereIn('pm_id', $pmIds ?: [0])->get(['pm_id', 'pb_id', 'pb_no', 'pb_status'])->keyBy('pm_id');
        foreach ($rows->items() as $r) {
            $r->perbaikan = $pb[(int) $r->pm_id] ?? null;
        }

        return $rows;
    }

    // ─── Cetak checklist (format RS) ──────────────────────────────────────────
    public function cetak()
    {
        if (!in_array($this->filterFrekuensi, ['H', 'M'], true)) {
            $this->dispatch('toast', type: 'error', message: 'Pilih frekuensi Harian atau Mingguan dulu untuk cetak checklist.');
            return;
        }

        $awal = $this->awalBulan();
        $semua = $this->filterRuang === '';
        $pdf = Pdf::loadView($this->printView, [
            'ruang'     => $semua ? 'Semua Ruang'
                : (DB::table('siapmst_ruangs')->where('ruang_id', $this->filterRuang)->value('ruang_name') ?? $this->filterRuang),
            'semua'     => $semua,
            'awal'      => $awal,
            'frekuensi' => $this->filterFrekuensi,
            'slot'      => Pemeliharaan::jumlahSlot($awal, $this->filterFrekuensi),
            'checklist' => Pemeliharaan::checklist($semua ? Pemeliharaan::SEMUA_RUANG : $this->filterRuang, $awal, $this->filterFrekuensi),
            'dicetak'   => now(),
            'oleh'      => auth()->user()?->name,
        ])->setPaper('A4', 'landscape');

        return response()->streamDownload(fn() => print $pdf->output(),
            'checklist-perawatan-' . ($semua ? 'semua-ruang' : strtolower($this->filterRuang)) . '-'
            . strtolower(Pemeliharaan::FREKUENSI[$this->filterFrekuensi]) . '-' . $awal->format('Y-m') . '.pdf');
    }
};
?>

<div>

    <x-page-title
        title="Pemeliharaan Alat"
        subtitle="Entry & riwayat pemeriksaan alat harian / mingguan — B: Baik · P: Perlu Perbaikan · R: Rusak (MFK 7)" />

    @php
        $warnaHasil = ['B' => 'success', 'P' => 'warning', 'R' => 'danger'];
        $pg = $this->progres;
    @endphp

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="w-full sm:w-60">
                            <x-text-input type="text" wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari alat / keterangan..." class="block w-full" />
                        </div>
                        <div class="w-52">
                            <x-select-input wire:model.live="filterRuang">
                                <option value="">Semua ruang</option>
                                @foreach ($this->ruangs as $ru)
                                    <option value="{{ $ru->ruang_id }}">{{ $ru->ruang_id }} — {{ $ru->ruang_name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div class="w-36">
                            <x-select-input wire:model.live="filterFrekuensi">
                                <option value="">Semua frekuensi</option>
                                @foreach (\App\Support\Pemeliharaan::FREKUENSI as $kode => $nama)
                                    <option value="{{ $kode }}">{{ $nama }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div class="w-36">
                            <x-select-input wire:model.live="filterHasil">
                                <option value="">Semua hasil</option>
                                @foreach (\App\Support\Pemeliharaan::HASIL as $kode => $nama)
                                    <option value="{{ $kode }}">{{ $kode }} — {{ $nama }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div class="flex items-center gap-1">
                            <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-2.5" title="Bulan sebelumnya">‹</x-secondary-button>
                            <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" class="w-28 font-mono text-center" />
                            <x-secondary-button type="button" wire:click="geserBulan(1)" class="!px-2.5" title="Bulan berikutnya">›</x-secondary-button>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <x-secondary-button type="button" wire:click="cetak" wire:loading.attr="disabled" wire:target="cetak"
                            title="{{ in_array($filterFrekuensi, ['H', 'M'], true) ? 'Cetak format Checklist Perawatan Alat' : 'Pilih frekuensi Harian / Mingguan dulu' }}">
                            <span wire:loading.remove wire:target="cetak">Cetak Checklist</span>
                            <span wire:loading wire:target="cetak">Menyiapkan...</span>
                        </x-secondary-button>
                        <x-primary-button type="button" wire:click="openCreate">+ Entry Pemeliharaan</x-primary-button>
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>

                {{-- Progres pemeriksaan --}}
                <div class="flex flex-wrap gap-2 mt-3 text-xs">
                    @foreach (['H' => 'Alat harian — hari ini', 'M' => 'Alat mingguan — minggu ini'] as $frek => $label)
                        @php $p = $pg[$frek]; $lengkap = $p['total'] > 0 && $p['sudah'] >= $p['total']; @endphp
                        @if ($p['total'] > 0)
                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border
                                {{ $lengkap ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
                                <b>{{ $label }}</b>
                                @if ($frek === 'M')
                                    ({{ \App\Support\Format::tanggal($p['dari']) }} – {{ \App\Support\Format::tanggal($p['sampai']) }})
                                @endif
                                : {{ $p['sudah'] }} / {{ $p['total'] }} alat sudah dicek
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-t-2xl">
                    <table class="ds-table">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>Tanggal</th>
                                <th>Alat</th>
                                <th>Ruang</th>
                                <th>Frekuensi</th>
                                <th class="ds-c">Hasil</th>
                                <th>Keterangan / tindakan</th>
                                <th>Petugas</th>
                                <th>Perbaikan</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                <tr wire:key="pm-{{ $row->pm_id }}">
                                    <td class="whitespace-nowrap">
                                        <div class="font-mono">{{ \App\Support\Format::tanggal($row->pm_tanggal) }}</div>
                                        <div class="text-xs text-muted">{{ \App\Support\Format::hari($row->pm_tanggal) }}</div>
                                    </td>
                                    <td>
                                        <div class="ds-td-strong">{{ $row->asset_name }}</div>
                                        <div class="font-mono text-xs text-muted">{{ $row->asset_id }}</div>
                                    </td>
                                    <td class="text-sm">{{ $row->ruang_id }} <span class="text-muted">{{ $row->ruang_name }}</span></td>
                                    <td class="text-sm">
                                        {{ \App\Support\Pemeliharaan::FREKUENSI[$row->pm_frekuensi] ?? $row->pm_frekuensi }}
                                        @if ($row->pm_frekuensi === 'M')
                                            <div class="text-[11px] text-muted">minggu ke-{{ \App\Support\Pemeliharaan::mingguKe(\Carbon\Carbon::parse($row->pm_tanggal)) }}</div>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        <x-badge :variant="$warnaHasil[$row->pm_hasil] ?? 'gray'">{{ \App\Support\Pemeliharaan::HASIL[$row->pm_hasil] ?? $row->pm_hasil }}</x-badge>
                                    </td>
                                    <td class="max-w-[18rem] text-sm">{{ $row->keterangan ?: '—' }}</td>
                                    <td class="text-sm text-muted whitespace-nowrap">{{ $row->petugas_name ?: '—' }}</td>
                                    <td class="whitespace-nowrap">
                                        @if ($row->perbaikan)
                                            <button type="button" wire:click="bukaPerbaikan({{ $row->perbaikan->pb_id }})" class="text-left">
                                                <div class="font-mono text-xs text-brand-green hover:underline">{{ $row->perbaikan->pb_no }}</div>
                                                <x-badge :variant="\App\Support\Perbaikan::STATUS[$row->perbaikan->pb_status][1]">{{ \App\Support\Perbaikan::STATUS[$row->perbaikan->pb_status][0] }}</x-badge>
                                            </button>
                                        @elseif (in_array($row->pm_hasil, ['P', 'R'], true))
                                            <x-secondary-button type="button" wire:click="laporkanPerbaikan({{ $row->pm_id }})" class="!px-2.5 !py-1 text-xs">
                                                Lapor perbaikan
                                            </x-secondary-button>
                                        @else
                                            <span class="text-muted-soft">—</span>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        <div class="flex justify-center gap-2">
                                            <x-action-edit wire:click="openEdit({{ $row->pm_id }})" />
                                            <x-action-delete :action="'requestDelete(' . (int) $row->pm_id . ')'"
                                                title="Hapus Catatan Pemeliharaan"
                                                message="Hapus catatan {{ $row->asset_name }} tanggal {{ \App\Support\Format::tanggal($row->pm_tanggal) }}?" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-10 text-center text-muted dark:text-gray-400">
                                        Belum ada catatan pemeliharaan untuk filter ini. Klik <b>+ Entry Pemeliharaan</b> untuk mencatat.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::aset.pemeliharaan-alat.pemeliharaan-alat-actions wire:key="pemeliharaan-alat-actions" />
            <livewire:pages::aset.perbaikan-alat.perbaikan-alat-actions wire:key="perbaikan-alat-actions" />

        </div>
    </div>
</div>
