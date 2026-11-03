<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Support\IndikatorMutu;
use App\Support\RunChart;

/**
 * REKAP CAPAIAN INDIKATOR MUTU — per bulan, triwulan & tahun + run chart + cetak PDF.
 * Angka dari kertas kerja (siaptxn_indikator_obs). Triwulan/tahun = jumlah pembilang ÷ jumlah penyebut,
 * BUKAN rata-rata persen bulanan.
 *
 * Cakupan: admin / Komite Mutu → seluruh RS atau satu unit; kepala ruang → unitnya sendiri saja.
 */
new class extends Component {
    #[Url(as: 'tahun')]
    public string $tahun = '';
    #[Url(as: 'unit')]
    public string $filterUnit = ''; // '' = seluruh RS (admin / Komite Mutu)
    #[Url(as: 'kelompok')]
    public string $kelompok = 'INM';
    #[Url(as: 'ind')]
    public string $indKode = '';     // indikator yang ditampilkan run chart-nya

    private string $printView = 'pages.mutu.rekap-capaian.rekap-capaian-print';

    public function mount(): void
    {
        if (!preg_match('/^\d{4}$/', $this->tahun)) {
            $this->tahun = now()->format('Y');
        }
        if (!isset(IndikatorMutu::KELOMPOK[$this->kelompok])) {
            $this->kelompok = 'INM';
        }
    }

    public function updatedKelompok(): void
    {
        $this->indKode = '';
    }

    public function geserTahun(int $jumlahTahun): void
    {
        $this->tahun = (string) ($this->tahunAngka() + $jumlahTahun);
    }

    private function tahunAngka(): int
    {
        return preg_match('/^\d{4}$/', $this->tahun) ? (int) $this->tahun : (int) now()->format('Y');
    }

    // ─── Cakupan ──────────────────────────────────────────────────────────────
    #[Computed]
    public function cakupan(): array
    {
        return IndikatorMutu::cakupanUser(auth()->user());
    }

    /** null = seluruh RS; kode unit / TINGKAT_RS = satu unit. Kepala ruang selalu unitnya. */
    public function unitAktif(): ?string
    {
        if (!$this->cakupan['semuaUnit']) {
            return $this->cakupan['unitId'];
        }

        return $this->filterUnit !== '' ? $this->filterUnit : null;
    }

    #[Computed]
    public function unitPilihan(): array
    {
        $pilihan = [IndikatorMutu::TINGKAT_RS => 'Tingkat RS (Komite Mutu)'];
        foreach (DB::table('siapmst_unit_kerjas')->where('active_status', '1')->orderBy('unit_name')->get(['unit_id', 'unit_name']) as $unit) {
            if (IndikatorMutu::untukUnit($unit->unit_id) !== []) {
                $pilihan[$unit->unit_id] = $unit->unit_name;
            }
        }

        return $pilihan;
    }

    #[Computed]
    public function namaCakupan(): string
    {
        $unitId = $this->unitAktif();

        return match (true) {
            $unitId === null => 'Seluruh Rumah Sakit',
            $unitId === IndikatorMutu::TINGKAT_RS => 'Tingkat RS (Komite Mutu)',
            default => 'Unit ' . DB::table('siapmst_unit_kerjas')->where('unit_id', $unitId)->value('unit_name'),
        };
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    /** Indikator yang tampil untuk cakupan ini (unit → hanya indikator unit tsb). */
    private function indikatorCakupan(?string $kelompok = null): array
    {
        $unitId = $this->unitAktif();
        $daftar = $unitId === null ? IndikatorMutu::definisi() : IndikatorMutu::untukUnit($unitId);

        return $kelompok === null ? $daftar : array_filter($daftar, fn($definisi) => in_array($kelompok, $definisi['kelompok'], true));
    }

    #[Computed]
    public function rekap(): array
    {
        return IndikatorMutu::rekapTahun($this->tahunAngka(), $this->unitAktif());
    }

    /**
     * Tabel satu kelompok: per indikator → bulan 1..12, TW 1..4, tahun → [capaian, tercapai, pembilang, penyebut].
     */
    private function susunTabel(string $kelompok): array
    {
        $tabel = [];
        foreach ($this->indikatorCakupan($kelompok) as $kode => $definisi) {
            $perBulan = $this->rekap[$kode] ?? [];
            $sel = fn(array $data) => [
                'capaian'   => $nilai = IndikatorMutu::capaian($data['pembilang'], $data['penyebut'], $definisi['pengali']),
                'tercapai'  => IndikatorMutu::tercapai($nilai, $definisi),
                'pembilang' => $data['pembilang'],
                'penyebut'  => $data['penyebut'],
            ];
            $baris = ['definisi' => $definisi, 'bulan' => [], 'triwulan' => []];
            foreach (range(1, 12) as $bulan) {
                $baris['bulan'][$bulan] = $sel($perBulan[$bulan] ?? ['pembilang' => 0.0, 'penyebut' => 0.0]);
            }
            foreach (range(1, 4) as $triwulan) {
                $baris['triwulan'][$triwulan] = $sel(IndikatorMutu::gabung($perBulan, range($triwulan * 3 - 2, $triwulan * 3)));
            }
            $baris['tahun'] = $sel(IndikatorMutu::gabung($perBulan, range(1, 12)));
            $tabel[$kode] = $baris;
        }

        return $tabel;
    }

    #[Computed]
    public function tabel(): array
    {
        return $this->susunTabel($this->kelompok);
    }

    /** Indikator run chart: pilihan user, atau yang pertama ber-data di tab ini (lalu yang pertama). */
    #[Computed]
    public function grafik(): ?array
    {
        $adaCapaian = fn(array $baris) => collect($baris['bulan'])->contains(fn($sel) => $sel['capaian'] !== null);
        $baris = $this->tabel[$this->indKode]
            ?? collect($this->tabel)->first($adaCapaian)
            ?? (array_values($this->tabel)[0] ?? null);
        if (!$baris) {
            return null;
        }

        return [
            'definisi' => $baris['definisi'],
            'svg'      => RunChart::svg(array_map(fn($sel) => $sel['capaian'], $baris['bulan']), $baris['definisi']),
            'adaData'  => $adaCapaian($baris),
        ];
    }

    public function pilihGrafik(string $kode): void
    {
        $this->indKode = $kode;
    }

    // ─── Cetak ────────────────────────────────────────────────────────────────
    public function cetak()
    {
        $tabelPerKelompok = [];
        foreach (array_keys(IndikatorMutu::KELOMPOK) as $kelompok) {
            $tabel = $this->susunTabel($kelompok);
            if ($tabel !== []) {
                $tabelPerKelompok[$kelompok] = $tabel;
            }
        }
        $grafikPdf = [];
        foreach ($tabelPerKelompok as $tabel) {
            foreach ($tabel as $kode => $baris) {
                $nilai = array_map(fn($sel) => $sel['capaian'], $baris['bulan']);
                if (!isset($grafikPdf[$kode]) && array_filter($nilai, fn($capaian) => $capaian !== null) !== []) {
                    $grafikPdf[$kode] = ['definisi' => $baris['definisi'], 'gambar' => RunChart::dataUri($nilai, $baris['definisi'])];
                }
            }
        }

        $pdf = Pdf::loadView($this->printView, [
            'tahun'            => $this->tahunAngka(),
            'cakupan'          => $this->namaCakupan,
            'tabelPerKelompok' => $tabelPerKelompok,
            'grafikPdf'        => $grafikPdf,
        ])->setPaper('A4', 'landscape');

        $unitId = $this->unitAktif();
        $namaBerkas = 'capaian-indikator-mutu-' . $this->tahunAngka()
            . ($unitId ? '-' . mb_strtolower(trim($unitId, '#')) : '') . '.pdf';

        return response()->streamDownload(fn() => print $pdf->output(), $namaBerkas);
    }
};
?>

<div>
    <x-page-title
        title="Rekap Capaian Indikator Mutu"
        subtitle="Capaian bulanan, triwulan & tahunan terhadap target + run chart — dari kertas kerja unit" />

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">

            @if (!$this->cakupan['semuaUnit'] && !$this->cakupan['unitId'])
                <div class="max-w-2xl p-6 mt-6 border rounded-2xl border-warning-subtle bg-warning-soft text-fg-warning" role="alert">
                    <p class="font-semibold">Akun Anda belum terhubung ke unit kerja.</p>
                    <p class="mt-1 text-sm">Minta Admin menghubungkan akun ke karyawan (Sistem › User Control) dan mengisi <b>Unit Kerja</b> karyawan tersebut.</p>
                </div>
            @else
                {{-- TOOLBAR --}}
                <div class="sticky z-30 px-4 py-3 border-b bg-surface-soft border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="flex items-center gap-1">
                                <x-secondary-button type="button" wire:click="geserTahun(-1)" class="!px-2.5" aria-label="Tahun sebelumnya">‹</x-secondary-button>
                                <x-text-input wire:model.live.debounce.600ms="tahun" x-mask="9999" placeholder="yyyy" aria-label="Tahun" class="w-20 font-mono text-center" />
                                <x-secondary-button type="button" wire:click="geserTahun(1)" class="!px-2.5" aria-label="Tahun berikutnya">›</x-secondary-button>
                            </div>
                            @if ($this->cakupan['semuaUnit'])
                                <div class="w-64">
                                    <x-select-input wire:model.live="filterUnit" aria-label="Cakupan">
                                        <option value="">Seluruh Rumah Sakit</option>
                                        @foreach ($this->unitPilihan as $unitId => $namaUnit)
                                            <option value="{{ $unitId }}">{{ $namaUnit }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                            @else
                                <div class="px-3 py-2 text-sm font-semibold border rounded-lg bg-canvas border-hairline text-ink dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100">
                                    {{ $this->namaCakupan }}
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center justify-end gap-2">
                            <x-cetak-button wire:click="cetak" label="Cetak PDF" />
                        </div>
                    </div>

                    <div class="mt-3">
                        <x-tabs>
                            @foreach (\App\Support\IndikatorMutu::KELOMPOK as $kode => $nama)
                                <x-tab :active="$kelompok === $kode" wire:click="$set('kelompok', '{{ $kode }}')">{{ $nama }}</x-tab>
                            @endforeach
                        </x-tabs>
                    </div>
                </div>

                {{-- TABEL REKAP --}}
                <div class="mt-4 border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    <div class="overflow-x-auto rounded-2xl">
                        <table class="ds-table">
                            <thead>
                                <tr>
                                    <th>Indikator</th>
                                    <th class="ds-c">Target</th>
                                    @foreach (\App\Support\IndikatorMutu::NAMA_BULAN as $namaBulan)
                                        <th class="ds-c">{{ $namaBulan }}</th>
                                    @endforeach
                                    @foreach (['I', 'II', 'III', 'IV'] as $romawi)
                                        <th class="ds-c">TW {{ $romawi }}</th>
                                    @endforeach
                                    <th class="ds-c">{{ $tahun }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->tabel as $kode => $baris)
                                    @php $definisi = $baris['definisi']; @endphp
                                    <tr wire:key="rekap-{{ $kode }}" @class(['bg-surface-strong dark:bg-gray-800' => $this->grafik && $this->grafik['definisi']['kode'] === $kode])>
                                        <td class="min-w-[16rem]">
                                            <button type="button" wire:click="pilihGrafik('{{ $kode }}')"
                                                class="text-left rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-gray-900"
                                                aria-label="Tampilkan run chart {{ $definisi['judul'] }}">
                                                <span class="ds-td-token">{{ $kode }}</span>
                                                <span class="block text-sm font-semibold text-ink dark:text-gray-100 hover:underline">{{ $definisi['judul'] }}</span>
                                                @if ($kelompok === 'IMP-RS' && $definisi['kategoriRs'])
                                                    <span class="block text-xs text-muted dark:text-gray-400">{{ $definisi['kategoriRs'] }}</span>
                                                @endif
                                            </button>
                                        </td>
                                        <td class="ds-c whitespace-nowrap">{{ \App\Support\IndikatorMutu::labelTarget($definisi) }}</td>
                                        @foreach ([...array_values($baris['bulan']), ...array_values($baris['triwulan']), $baris['tahun']] as $indeks => $sel)
                                            <td @class(['ds-c whitespace-nowrap tabular-nums', 'border-l border-hairline dark:border-gray-700' => $indeks === 12 || $indeks === 16])
                                                title="{{ \App\Support\IndikatorMutu::angka($sel['pembilang'], 0) }} / {{ \App\Support\IndikatorMutu::angka($sel['penyebut'], 0) }}">
                                                @if ($sel['capaian'] === null)
                                                    <span class="text-muted-soft dark:text-gray-500">—</span>
                                                @else
                                                    <span @class([
                                                        'font-semibold',
                                                        'text-success-deep dark:text-success' => $sel['tercapai'],
                                                        'text-error-deep dark:text-red-400' => !$sel['tercapai'],
                                                    ])>{{ $sel['tercapai'] ? '✓' : '✗' }} {{ \App\Support\IndikatorMutu::angka($sel['capaian'], 1) }}</span>
                                                    <span class="block text-[11px] text-muted dark:text-gray-400">{{ \App\Support\IndikatorMutu::angka($sel['pembilang'], 0) }}/{{ \App\Support\IndikatorMutu::angka($sel['penyebut'], 0) }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="19" class="px-6 py-16 text-center text-muted dark:text-gray-400">
                                            Tidak ada indikator kelompok ini untuk {{ $this->namaCakupan }}.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="px-4 py-2 text-xs border-t border-hairline text-muted dark:border-gray-700 dark:text-gray-400">
                        ✓ tercapai · ✗ tidak tercapai · — tidak ada kasus. Angka kecil = pembilang/penyebut. Triwulan & tahun = jumlah pembilang ÷ jumlah penyebut.
                        Klik nama indikator untuk melihat run chart-nya.
                    </div>
                </div>

                {{-- RUN CHART --}}
                @if ($this->grafik)
                    @php $grafik = $this->grafik; @endphp
                    <div class="p-4 mt-4 border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="text-base font-semibold text-ink dark:text-gray-100">
                                Run chart {{ $grafik['definisi']['kode'] }} — {{ $grafik['definisi']['judul'] }}
                            </h2>
                            <span class="text-xs text-muted dark:text-gray-400">{{ $this->namaCakupan }} · {{ $tahun }}</span>
                        </div>
                        @if ($grafik['adaData'])
                            <div class="mt-3">{!! $grafik['svg'] !!}</div>
                            <p class="mt-2 text-xs text-muted dark:text-gray-400">
                                Garis putus-putus = target. Titik bertanda ✗ = tidak mencapai target. Garis terputus = bulan tanpa kasus.
                            </p>
                        @else
                            <p class="py-10 text-sm text-center text-muted dark:text-gray-400">Belum ada data kertas kerja untuk indikator ini di tahun {{ $tahun }}.</p>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
