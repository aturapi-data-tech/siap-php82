<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiKaryawan;
use App\Support\UnitKerja;

/**
 * GAJI KARYAWAN — slip per karyawan per bulan (pola gaji dokter sirus-php82).
 *
 * Alur: atur parameter gaji (Master Karyawan → tombol Gaji) → Generate (Draft, dari parameter + hari hadir absensi)
 *   → Rincian: sesuaikan hari kerja (cuti / izin), isi jasa medik, upah lembur, bonus, kasbon, angsuran … → Final (terkunci) → Cetak slip.
 * Generate ulang aman: slip Final dilewati, baris yang diisi / ditimpa petugas dipertahankan.
 * Hanya role gaji.kelola (AksiRole::GAJI_KELOLA) — dicek di boot(), berlaku untuk setiap aksi.
 */
new class extends Component {
    use WithPagination;

    public string $bulan         = ''; // mm/yyyy (bulan kerja)
    public string $searchKeyword = '';
    public string $filterUnit    = '';
    public string $filterStatus  = ''; // '' | D | F
    public int    $itemsPerPage  = 20;

    private string $printView = 'pages.keuangan.gaji-karyawan.gaji-karyawan-slip-print';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
    }

    public function updatedBulan(): void         { $this->resetPage(); }
    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedFilterUnit(): void    { $this->resetPage(); }
    public function updatedFilterStatus(): void  { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterUnit', 'filterStatus']);
        $this->itemsPerPage = 20;
        $this->resetPage();
    }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        $this->resetPage();
    }

    private function awalBulan(): Carbon
    {
        try {
            return Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    private function periode(): string
    {
        return $this->awalBulan()->format('Y-m');
    }

    // ─── Aksi ─────────────────────────────────────────────────────────────────
    public function generate(): void
    {
        $hasil = GajiKaryawan::generate($this->periode(), $this->filterUnit !== '' ? $this->filterUnit : null, auth()->id());

        $pesan = "Generate {$this->bulan}: {$hasil['dibuat']} slip baru, {$hasil['diperbarui']} diperbarui.";
        if ($hasil['dilewatiFinal'] !== []) {
            $pesan .= ' ' . count($hasil['dilewatiFinal']) . ' slip Final dilewati.';
        }
        if ($hasil['tanpaParameter'] > 0) {
            $pesan .= " {$hasil['tanpaParameter']} karyawan belum punya parameter gaji.";
        }
        $this->dispatch('toast', type: 'success', message: $pesan);
        unset($this->ringkasan);
    }

    public function ubahStatus(int $gajiId, string $status): void
    {
        if (!GajiKaryawan::ubahStatus($gajiId, $status, auth()->id())) {
            return;
        }
        $this->dispatch('toast', type: 'success', message: $status === GajiKaryawan::STATUS_FINAL ? 'Slip difinalkan (terkunci).' : 'Slip dibuka kembali ke Draft.');
        unset($this->ringkasan);
    }

    /** Finalkan semua slip Draft yang tampil (periode + filter unit / cari). */
    public function finalkanSemua(): void
    {
        $gajiIds = $this->querySlip()->where('gh.gaji_status', GajiKaryawan::STATUS_DRAFT)->pluck('gh.gaji_id');
        foreach ($gajiIds as $gajiId) {
            GajiKaryawan::ubahStatus((int) $gajiId, GajiKaryawan::STATUS_FINAL, auth()->id());
        }
        $this->dispatch('toast', type: 'success', message: count($gajiIds) . ' slip difinalkan.');
        unset($this->ringkasan);
    }

    public function openRincian(int $gajiId): void
    {
        $this->dispatch('keuangan.gaji-karyawan.openRincian', gajiId: $gajiId);
    }

    #[On('keuangan.gaji-karyawan.saved')]
    public function refreshAfterSaved(): void
    {
        unset($this->ringkasan);
    }

    public function cetakSlip(int $gajiId)
    {
        $slip = GajiKaryawan::slip($gajiId);
        if (!$slip) {
            return null;
        }

        return $this->unduhPdf([$slip], 'slip-gaji-' . mb_strtolower($slip['header']->emp_id) . '-' . $slip['header']->periode . '.pdf');
    }

    /** Cetak semua slip yang tampil (periode + filter) — satu karyawan satu halaman. */
    public function cetakMassal()
    {
        $slipList = $this->querySlip()->orderBy('gh.emp_id')->pluck('gh.gaji_id')
            ->map(fn($gajiId) => GajiKaryawan::slip((int) $gajiId))->filter()->values()->all();
        if ($slipList === []) {
            $this->dispatch('toast', type: 'error', message: 'Belum ada slip untuk dicetak.');
            return null;
        }

        return $this->unduhPdf($slipList, 'slip-gaji-' . ($this->filterUnit !== '' ? mb_strtolower($this->filterUnit) . '-' : '') . $this->periode() . '.pdf');
    }

    private function unduhPdf(array $slipList, string $namaBerkas)
    {
        $pdf = Pdf::loadView($this->printView, [
            'slipList' => $slipList,
            'dicetak'  => now(),
            'oleh'     => auth()->user()?->name,
        ])->setPaper('A5', 'portrait');

        return response()->streamDownload(fn() => print $pdf->output(), $namaBerkas);
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function unitList()
    {
        return UnitKerja::aktif();
    }

    /** Slip periode terpilih + filter unit / cari (tanpa filter status). */
    private function querySlip()
    {
        $query = DB::table('siaptxn_gaji_hdrs as gh')
            ->join('siapmst_employers as em', 'em.emp_id', '=', 'gh.emp_id')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'gh.unit_id')
            ->where('gh.periode', $this->periode())
            ->when($this->filterUnit !== '', fn($subQuery) => $subQuery->where('gh.unit_id', $this->filterUnit));

        if (trim($this->searchKeyword) !== '') {
            $keyword = mb_strtoupper(trim($this->searchKeyword));
            $query->where(fn($subQuery) => $subQuery->whereRaw('UPPER(em.emp_id) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('UPPER(em.emp_name) LIKE ?', ["%{$keyword}%"]));
        }

        return $query;
    }

    #[Computed]
    public function ringkasan(): array
    {
        $baris = $this->querySlip()
            ->select(DB::raw('COUNT(*) AS jumlah'),
                DB::raw("SUM(CASE WHEN gh.gaji_status = 'D' THEN 1 ELSE 0 END) AS draft"),
                DB::raw('NVL(SUM(gh.total_pendapatan), 0) AS pendapatan'),
                DB::raw('NVL(SUM(gh.total_potongan), 0) AS potongan'),
                DB::raw('NVL(SUM(gh.gaji_diterima), 0) AS diterima'))
            ->first();

        return [
            'jumlah'     => (int) $baris->jumlah,
            'draft'      => (int) $baris->draft,
            'pendapatan' => (float) $baris->pendapatan,
            'potongan'   => (float) $baris->potongan,
            'diterima'   => (float) $baris->diterima,
        ];
    }

    #[Computed]
    public function rows()
    {
        return $this->querySlip()
            ->when($this->filterStatus !== '', fn($subQuery) => $subQuery->where('gh.gaji_status', $this->filterStatus))
            ->select('gh.gaji_id', 'gh.emp_id', 'em.emp_name', 'un.unit_name', 'gh.hari_hadir', 'gh.total_pendapatan',
                'gh.total_potongan', 'gh.gaji_diterima', 'gh.gaji_status', 'gh.difinalkan_at')
            ->orderBy('gh.emp_id')
            ->paginate($this->itemsPerPage);
    }
};
?>

<div>
    <x-page-title
        title="Gaji Karyawan"
        subtitle="Slip gaji bulanan — generate dari parameter gaji, sesuaikan hari kerja & rincian, finalkan, cetak" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR — satu baris (pola gaji dokter sirus): saringan berlabel di kiri, aksi di kanan (ml-auto). --}}
            <div class="sticky z-30 px-4 pt-1 pb-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label value="Bulan Kerja" />
                        <div class="flex items-center gap-1 mt-1">
                            <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-3" title="Bulan sebelumnya">‹</x-secondary-button>
                            <div class="w-28">
                                <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" class="w-full text-center font-mono" />
                            </div>
                            <x-secondary-button type="button" wire:click="geserBulan(1)" class="!px-3" title="Bulan berikutnya">›</x-secondary-button>
                        </div>
                    </div>
                    <div class="w-48">
                        <x-input-label value="Cari Karyawan" />
                        <x-text-input type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="ID / nama" class="block w-full mt-1" />
                    </div>
                    <div class="w-56">
                        <x-input-label value="Unit" />
                        <div class="mt-1">
                            <x-select-input wire:model.live="filterUnit">
                                <option value="">Semua unit</option>
                                @foreach ($this->unitList as $unit)
                                    <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </div>
                    <div class="w-36">
                        <x-input-label value="Status" />
                        <div class="mt-1">
                            <x-select-input wire:model.live="filterStatus">
                                <option value="">Semua</option>
                                <option value="D">Draft</option>
                                <option value="F">Final</option>
                            </x-select-input>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-end gap-2 ml-auto">
                        <x-confirm-button variant="primary" action="generate()" title="Generate Slip Gaji"
                            message="Susun slip {{ $bulan }}{{ $filterUnit !== '' ? ' untuk unit terpilih' : '' }} dari parameter gaji? Slip baru memakai hari kerja default; slip Final dilewati; hari kerja & rincian yang sudah diisi petugas tetap." confirmText="Ya, generate" cancelText="Batal">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            Generate
                        </x-confirm-button>
                        <x-confirm-button variant="secondary" action="finalkanSemua()" title="Final Semua"
                            message="Kunci {{ $this->ringkasan['draft'] }} slip Draft {{ $bulan }} yang tampil? Slip Final tidak bisa diubah sampai dibuka kembali." confirmText="Ya, finalkan" cancelText="Batal"
                            :disabled="$this->ringkasan['draft'] === 0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                            Final Semua
                        </x-confirm-button>
                        <x-cetak-button wire:click="cetakMassal" label="Cetak Semua" title="Cetak semua slip yang tampil — satu karyawan satu halaman" />
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>

                @php $ringkasan = $this->ringkasan; @endphp
                <div class="flex flex-wrap gap-2 mt-3">
                    <x-badge variant="gray">{{ $ringkasan['jumlah'] }} slip · {{ $ringkasan['draft'] }} draft</x-badge>
                    <x-badge variant="info">Pendapatan Rp {{ \App\Support\GajiKaryawan::rupiah($ringkasan['pendapatan']) }}</x-badge>
                    <x-badge variant="danger">Potongan Rp {{ \App\Support\GajiKaryawan::rupiah(abs($ringkasan['potongan'])) }}</x-badge>
                    <x-badge variant="success">Diterima Rp {{ \App\Support\GajiKaryawan::rupiah($ringkasan['diterima']) }}</x-badge>
                </div>
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-t-2xl">
                    <table class="ds-table">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>Karyawan</th>
                                <th class="ds-c">Hari Kerja</th>
                                <th class="text-right">Pendapatan</th>
                                <th class="text-right">Potongan</th>
                                <th class="text-right">Diterima</th>
                                <th class="ds-c">Status</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php $final = $row->gaji_status === \App\Support\GajiKaryawan::STATUS_FINAL; @endphp
                                <tr wire:key="slip-{{ $row->gaji_id }}">
                                    <td>
                                        <button type="button" wire:click="openRincian({{ $row->gaji_id }})" class="block font-semibold text-left text-brand-green hover:underline">{{ $row->emp_name }}</button>
                                        <span class="font-mono text-xs text-muted">{{ $row->emp_id }}{{ $row->unit_name ? ' · ' . $row->unit_name : '' }}</span>
                                    </td>
                                    <td class="font-mono ds-c">{{ (int) $row->hari_hadir }}</td>
                                    <td class="font-mono text-right">{{ \App\Support\GajiKaryawan::rupiah($row->total_pendapatan) }}</td>
                                    <td class="font-mono text-right text-red-700">{{ \App\Support\GajiKaryawan::rupiah($row->total_potongan) }}</td>
                                    <td class="font-mono font-bold text-right">{{ \App\Support\GajiKaryawan::rupiah($row->gaji_diterima) }}</td>
                                    <td class="ds-c">
                                        <x-badge :variant="$final ? 'success' : 'warning'">{{ \App\Support\GajiKaryawan::STATUS[$row->gaji_status] ?? $row->gaji_status }}</x-badge>
                                    </td>
                                    <td class="ds-c">
                                        {{-- Urutan tetap: Rincian · Final/Buka · Cetak --}}
                                        <div class="flex items-center justify-center gap-2 whitespace-nowrap">
                                            <x-lihat-button wire:click="openRincian({{ $row->gaji_id }})" title="Rincian slip {{ $row->emp_name }}" />
                                            @if ($final)
                                                <x-warning-button type="button" wire:click="ubahStatus({{ $row->gaji_id }}, 'D')" class="!px-3 shrink-0" title="Buka kembali ke Draft">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" /></svg>
                                                    Buka
                                                </x-warning-button>
                                            @else
                                                <x-primary-button type="button" wire:click="ubahStatus({{ $row->gaji_id }}, 'F')" class="!px-3 shrink-0" title="Kunci slip sebagai Final">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                                    Final
                                                </x-primary-button>
                                            @endif
                                            <x-cetak-button wire:click="cetakSlip({{ $row->gaji_id }})" title="Cetak slip {{ $row->emp_name }}" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-10 text-center text-muted">
                                        Belum ada slip {{ $bulan }}. Atur gaji karyawan di <a href="{{ route('master.karyawan') }}" class="underline text-brand-green">Master Karyawan</a> (tombol <b>Gaji</b>), lalu klik <b>Generate</b>.
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

            <livewire:pages::keuangan.gaji-karyawan.gaji-karyawan-rincian wire:key="gaji-karyawan-rincian" />
        </div>
    </div>
</div>
