<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\GajiDokterSirus;

/**
 * Modal LAMPIRAN rincian pasien gaji dokter (SIRUS) — tampil di LAYAR, komponen terpisah yang dipasang pembungkus
 * gaji-dokter SEJAJAR tab (pola modal gaji-dokter-lampiran di SIRUS). Dibuka lewat event:
 *   'keuangan.gaji-dokter.openLampiranPasienDokter'      (gdId)      → satu dokter (dari baris Buku / footer Rincian)
 *   'keuangan.gaji-dokter.openLampiranPasienSemuaDokter' (gdIdList)  → semua dokter yang sedang tampil di Buku
 * Isi per dokter (partial isi-lampiran-layar): identitas → catatan tanggal → tabel per komponen + subtotal (komponen
 * besar dilipat) → rekonsiliasi dengan slip. Data = snapshot SIAP (versi yang dipilih), jadi mode satu dokter punya
 * pemilih versi. Unduh Excel = CSV pipih, kolom sama dengan SIRUS.
 */
new class extends Component {
    /** Komponen dengan baris lebih dari ini dilipat sampai diminta (HTML awal tidak berisi ribuan baris). */
    public const BATAS_LIPAT = 300;

    /** Mode semua dokter dilipat lebih agresif: 35 dokter × ratusan baris = HTML 6 MB kalau semuanya dibuka. */
    public const BATAS_LIPAT_SEMUA = 100;

    public const NAMA_BULAN = ['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
        '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'];

    public const KOLOM_CSV = ['Dokter', 'Periode Jasa', 'Versi', 'Komponen', 'Tanggal', 'No. RM', 'Nama Pasien', 'Keterangan Jasa', 'Klaim', 'Jenis Klaim',
        'No. SEP', 'No. Transaksi', 'Nominal', 'Masuk Slip'];

    /** Snapshot (gd_id) yang ditampilkan — selalu larik walau isinya satu, supaya satu & banyak dokter memakai jalur yang sama. */
    public array $gdIdList = [];
    public bool $modeSemua = false;

    /** "gd_id|komponen" yang barisnya sengaja ditampilkan walau melebihi BATAS_LIPAT. */
    public array $komponenTerbuka = [];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    #[On('keuangan.gaji-dokter.openLampiranPasienDokter')]
    public function openLampiranPasienDokter(int $gdId): void
    {
        $this->siapkan([$gdId], false);
    }

    #[On('keuangan.gaji-dokter.openLampiranPasienSemuaDokter')]
    public function openLampiranPasienSemuaDokter(array $gdIdList): void
    {
        $this->siapkan($gdIdList, true);
    }

    private function siapkan(array $gdIdList, bool $modeSemua): void
    {
        $this->gdIdList = array_values(array_unique(array_filter(array_map('intval', $gdIdList))));
        $this->modeSemua = $modeSemua;
        $this->komponenTerbuka = [];
        unset($this->dataLampiranPerDokter);
        if ($this->dataLampiranPerDokter->isEmpty()) {
            $this->gdIdList = [];
            $this->dispatch('toast', type: 'warning', message: 'Tidak ada baris lampiran pada snapshot ini.');
            return;
        }
        $this->dispatch('open-modal', name: 'gaji-dokter-lampiran');
    }

    /** Tutup = kosongkan daftar, bukan sekadar menyembunyikan: request berikutnya tidak merender ulang ribuan baris. */
    public function closeModal(): void
    {
        $this->gdIdList = [];
        $this->komponenTerbuka = [];
        unset($this->dataLampiranPerDokter);
        $this->dispatch('close-modal', name: 'gaji-dokter-lampiran');
    }

    /** Mode satu dokter: ganti versi snapshot yang ditampilkan. */
    public function gantiVersiSnapshot(int $gdId): void
    {
        if ($this->modeSemua || $gdId <= 0) {
            return;
        }
        $this->gdIdList = [$gdId];
        $this->komponenTerbuka = [];
        unset($this->dataLampiranPerDokter);
    }

    public function toggleLipatKomponen(string $kunciKomponen): void
    {
        $this->komponenTerbuka = in_array($kunciKomponen, $this->komponenTerbuka, true)
            ? array_values(array_diff($this->komponenTerbuka, [$kunciKomponen]))
            : [...$this->komponenTerbuka, $kunciKomponen];
    }

    public static function namaPeriode(?string $periode): string
    {
        return $periode && preg_match('/^(\d{4})-(\d{2})$/', $periode, $bagianPeriode) ? (self::NAMA_BULAN[$bagianPeriode[2]] ?? $bagianPeriode[2]) . ' ' . $bagianPeriode[1] : (string) $periode;
    }

    /**
     * Satu entri per snapshot: header, baris lampiran, baris per komponen (masuk slip dulu, lalu grup kapita), angka
     * rekonsiliasi, dan daftar versi (mode satu dokter). Dokter tanpa baris lampiran dilewati (mode semua).
     */
    #[Computed]
    public function dataLampiranPerDokter()
    {
        if ($this->gdIdList === []) {
            return collect();
        }
        $headerList = DB::table('siaptxn_gdok_hdrs')->whereIn('gd_id', $this->gdIdList)->get()->keyBy('gd_id');

        return collect($this->gdIdList)->map(function ($gdId) use ($headerList) {
            $header = $headerList[$gdId] ?? null;
            if (!$header) {
                return null;
            }
            $lampiranRows = GajiDokterSirus::lampiran((int) $gdId);
            if ($lampiranRows->isEmpty()) {
                return null;
            }
            $perKomponen = $lampiranRows->groupBy('komponen')
                ->sortBy(fn($grupKomponen) => ($grupKomponen->first()->masuk_slip === '1' ? '0' : '1') . ($grupKomponen->first()->label ?: $grupKomponen->first()->komponen));

            return ['header' => $header, 'lampiran' => $lampiranRows, 'perKomponen' => $perKomponen,
                'angka' => self::rekonsiliasi($lampiranRows, GajiDokterSirus::detail((int) $gdId)),
                'versiOpsi' => $this->modeSemua ? collect() : GajiDokterSirus::versiList($header->periode, $header->dr_id)];
        })->filter()->sortBy(fn($komponen) => $komponen['header']->dr_name)->values();
    }

    /**
     * Rekonsiliasi — rumus sama dengan SIRUS (GajiDokterLampiran::rekonsiliasi): baris grup kapita TIDAK dijumlahkan
     * (slip memakai jumlah pasien × tarif kepala); jasa slip = jenis J tanpa "TUNJ "; kapita slip = jenis J berkode "KAPITA ".
     * Selisih ditampilkan apa adanya — angka tak cocok justru informasi.
     */
    public static function rekonsiliasi($lampiranRows, $detail): array
    {
        $totalTransaksi = (float) $lampiranRows->where('masuk_slip', '1')->sum('nominal');
        $totalDasarKapita = (float) $lampiranRows->where('masuk_slip', '!=', '1')->sum('nominal');
        $komponenJasa = $detail->where('jenis', 'J');
        $jasaSlip = (float) $komponenJasa->reject(fn($komponen) => str_starts_with((string) $komponen->kode, 'TUNJ '))->sum('nilai');
        $kapitaSlip = (float) $komponenJasa->filter(fn($komponen) => str_starts_with((string) $komponen->kode, 'KAPITA '))->sum('nilai');

        return ['totalTransaksi' => $totalTransaksi, 'totalDasarKapita' => $totalDasarKapita, 'jasaSlip' => $jasaSlip, 'kapitaSlip' => $kapitaSlip,
            'selisih' => round($jasaSlip - $totalTransaksi - $kapitaSlip, 2)];
    }

    /**
     * Unduh APA YANG SEDANG TAMPIL (satu dokter atau semua) sebagai CSV siap Excel — bentuk sama dengan SIRUS: PIPIH
     * (tanpa judul/subtotal di tengah data) supaya bisa dipivot, nominal angka mentah, BOM UTF-8, kolom Dokter & Periode di tiap baris.
     */
    public function unduhLampiranCsv(): mixed
    {
        $dataLampiran = $this->dataLampiranPerDokter;
        if ($dataLampiran->isEmpty()) {
            $this->dispatch('toast', type: 'warning', message: 'Tidak ada data untuk diunduh.');
            return null;
        }
        $headerPertama = $dataLampiran->first()['header'];
        $periode = str_replace('-', '', $headerPertama->periode);
        $namaBerkas = $this->modeSemua ? "lampiran-pasien-{$periode}-semua.csv" : "lampiran-pasien-{$headerPertama->dr_id}-{$periode}-v{$headerPertama->versi}.csv";

        return response()->streamDownload(function () use ($dataLampiran) {
            $berkasCsv = fopen('php://output', 'w');
            fwrite($berkasCsv, "\xEF\xBB\xBF");
            fputcsv($berkasCsv, self::KOLOM_CSV, ';', '"', '\\');
            foreach ($dataLampiran as $snapshot) {
                $header = $snapshot['header'];
                $periodeJasa = self::namaPeriode($header->periode);
                foreach ($snapshot['lampiran'] as $row) {
                    fputcsv($berkasCsv, [$header->dr_name, $periodeJasa, $header->versi, $row->label ?: $row->komponen, $row->tgl, $row->reg_no, $row->nama, $row->keterangan, $row->klaim, $row->klaim_status,
                        $row->no_sep, $row->txn_no, (int) round((float) $row->nominal), $row->masuk_slip === '1' ? 'Ya' : 'Tidak'], ';', '"', '\\');
                }
            }
            fclose($berkasCsv);
        }, $namaBerkas, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
};
?>

<div>
    <x-modal name="gaji-dokter-lampiran" size="full" height="full" focusable>
        <div class="flex flex-col h-full">

            {{-- HEADER MODAL --}}
            <x-modul-dokumen.header judul="Lampiran Rincian Pasien" tutup="closeModal"
                ikon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
                @if ($modeSemua){{ $this->dataLampiranPerDokter->count() }} dokter · snapshot SIAP @else{{ $this->dataLampiranPerDokter->first()['header']->dr_name ?? '' }} · snapshot SIAP @endif
            </x-modul-dokumen.header>

            {{-- BODY — tertutup = tidak merender apa pun (daftar dikosongkan di closeModal). --}}
            <div class="flex-1 px-4 pt-2 pb-4 overflow-y-auto bg-surface-soft dark:bg-gray-950/20">
                @if ($gdIdList)
                    @include('pages.keuangan.gaji-dokter.isi-lampiran-layar', [
                        'dataLampiran' => $this->dataLampiranPerDokter,
                        'komponenTerbuka' => $komponenTerbuka,
                        'batasLipat' => $modeSemua ? self::BATAS_LIPAT_SEMUA : self::BATAS_LIPAT,
                        'modeSemua' => $modeSemua,
                    ])
                @endif
            </div>

            {{-- FOOTER --}}
            <div class="px-6 py-3 border-t border-hairline bg-surface-soft dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-end gap-2">
                    <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
                    {{-- Unduh mengekspor APA YANG SEDANG TAMPIL — satu dokter atau semua — supaya isi berkas tidak pernah berbeda dari yang dilihat. --}}
                    <x-info-button type="button" wire:click="unduhLampiranCsv" wire:loading.attr="disabled" wire:target="unduhLampiranCsv" title="Unduh sebagai CSV — siap dibuka di Excel">
                        <span wire:loading.remove wire:target="unduhLampiranCsv" class="inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            Unduh Excel
                        </span>
                        <span wire:loading wire:target="unduhLampiranCsv" class="inline-flex items-center gap-2"><x-loading /> Menyiapkan...</span>
                    </x-info-button>
                </div>
            </div>
        </div>
    </x-modal>
</div>
