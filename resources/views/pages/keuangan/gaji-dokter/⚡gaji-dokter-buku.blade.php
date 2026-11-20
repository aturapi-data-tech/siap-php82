<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiDokterSirus;
use App\Support\GajiDokterVersiPeriode;
use App\Support\GajiKaryawan;

/**
 * Tab BUKU Gaji Dokter (anak pembungkus gaji-dokter; Rincian & Lampiran = modal terpisah sejajar tab): slip gaji dokter per
 * BULAN JASA — slip tiap dokter menurut VERSI PERIODE yang dipakai (GajiDokterVersiPeriode). Setelah tab Ambil
 * Data: periksa, putuskan versi baru yang belum dipakai (Bandingkan / Pakai), Final per dokter, lalu "Terapkan ke slip gaji"
 * (slip dokter dipakai → slip Draft Gaji Karyawan, pola Jasa Medik). Rincian = komponen gaji-dokter-rincian; banding versi = tab Bandingkan Versi.
 * Bulan jasa & saringan disimpan di Session (komponen di-unmount saat ganti tab — docs/tabs-pattern.md).
 * Tidak ada tulis ke SIRUS; snapshot tidak bisa disunting.
 */
new class extends Component {
    #[Session(key: 'gaji-dokter-bulan')]
    public string $bulan = ''; // mm/yyyy = bulan JASA

    #[Session(key: 'gaji-dokter-searchKeyword')]
    public string $searchKeyword = '';

    #[Session(key: 'gaji-dokter-saring')]
    public string $saring = 'semua'; // semua | versi_baru | berubah | tanpa_map | final

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!$this->bulanValid()) {
            $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        }
        if (!in_array($this->saring, ['semua', 'versi_baru', 'berubah', 'tanpa_map', 'final'], true)) {
            $this->saring = 'semua';
        }
    }

    private function bulanValid(): bool
    {
        return (bool) preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $this->bulan);
    }

    private function awalBulan(): Carbon
    {
        return $this->bulanValid() ? Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->startOfMonth() : now()->startOfMonth();
    }

    private function periode(): string
    {
        return $this->awalBulan()->format('Y-m');
    }

    public function updatedBulan(): void { $this->segarkanTampilan(); }
    public function updatedSearchKeyword(): void { $this->segarkanTampilan(); }
    public function updatedSaring(): void { $this->segarkanTampilan(); }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        $this->segarkanTampilan();
    }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword']);
        $this->saring = 'semua';
        $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        $this->segarkanTampilan();
    }

    /** Modal Rincian membuat versi baru (hitung ulang slip SIAP) → daftar ikut segar. */
    #[On('keuangan.gaji-dokter.slipBerubah')]
    public function slipBerubah(): void
    {
        $this->segarkanTampilan();
    }

    private function segarkanTampilan(): void
    {
        unset($this->rows, $this->ambilTerakhirPeriodeIni, $this->ringkasanPeriode, $this->versiMenunggu, $this->versiDipakai);
    }

    /** Tulis versi dipakai dokter terpetakan ke slip Draft Gaji Karyawan bulan gaji (jasa + 1) — GajiDokterSirus::terapkan(). */
    public function terapkanGajiDokterKeSlip(): void
    {
        if (!$this->bulanValid()) {
            $this->dispatch('toast', type: 'error', message: 'Bulan jasa tidak valid.');
            return;
        }
        $hasilTerapkanKeSlipGaji = GajiDokterSirus::terapkan($this->periode(), auth()->id());
        $labelBulanKerja = GajiDokterSirus::namaPeriode($hasilTerapkanKeSlipGaji['periodeGaji']);
        // Daftar nama dipangkas — toast bukan tempat 30 nama; rinciannya terlihat di badge tiap baris.
        $ringkasNama = fn(array $namaList) => implode(', ', array_slice($namaList, 0, 4)) . (count($namaList) > 4 ? ' dan ' . (count($namaList) - 4) . ' lainnya' : '');
        $pesanToast = "Jasa dokter {$this->bulan} diterapkan ke {$hasilTerapkanKeSlipGaji['diperbarui']} slip gaji bulan kerja {$labelBulanKerja}.";
        if ($hasilTerapkanKeSlipGaji['dibuatkan'] > 0) {
            $pesanToast .= " {$hasilTerapkanKeSlipGaji['dibuatkan']} slip dibuatkan baru (dokter tanpa parameter gaji — hanya berisi jasa dokter).";
        }
        if ($hasilTerapkanKeSlipGaji['dibersihkan'] > 0) {
            $pesanToast .= " {$hasilTerapkanKeSlipGaji['dibersihkan']} slip dibersihkan dari bekas terapkan lama.";
        }
        if ($hasilTerapkanKeSlipGaji['final'] !== []) {
            $pesanToast .= ' Dilewati karena slip Final (' . count($hasilTerapkanKeSlipGaji['final']) . '): ' . $ringkasNama($hasilTerapkanKeSlipGaji['final']) . '.';
        }
        if ($hasilTerapkanKeSlipGaji['tanpaSlip'] !== []) {
            $pesanToast .= ' ' . count($hasilTerapkanKeSlipGaji['tanpaSlip']) . ' dokter dilewati karena karyawannya nonaktif: ' . $ringkasNama($hasilTerapkanKeSlipGaji['tanpaSlip']) . '.';
        }
        if ($hasilTerapkanKeSlipGaji['tanpaMap'] !== []) {
            $pesanToast .= ' ' . count($hasilTerapkanKeSlipGaji['tanpaMap']) . ' dokter belum dipetakan.';
        }
        $this->dispatch('toast', type: $hasilTerapkanKeSlipGaji['diperbarui'] > 0 ? 'success' : 'warning', message: $pesanToast);
        $this->segarkanTampilan();
    }

    /** Kunci / buka kunci slip. */
    public function finalkanSiap(int $gdId): void
    {
        $pesanGalat = GajiDokterSirus::ubahStatusSiap($gdId, 'F', auth()->id());
        $this->dispatch('toast', type: $pesanGalat ? 'error' : 'success', message: $pesanGalat ?? 'Slip dikunci Final.');
        $this->segarkanTampilan();
    }

    public function bukaKunciSiap(int $gdId): void
    {
        $pesanGalat = GajiDokterSirus::ubahStatusSiap($gdId, 'D', auth()->id());
        $this->dispatch('toast', type: $pesanGalat ? 'error' : 'success', message: $pesanGalat ?? 'Slip dibuka kembali ke Draft.');
        $this->segarkanTampilan();
    }

    /** Modal Rincian = komponen terpisah (gaji-dokter-rincian, dipasang pembungkus sejajar tab) — pola SIRUS. */
    public function openRincianSlipDokter(int $gdId): void
    {
        $this->dispatch('keuangan.gaji-dokter.openRincianSlipDokter', gdId: $gdId);
    }

    /** Modal Lampiran = komponen terpisah (gaji-dokter-lampiran, sejajar tab) — satu dokter atau semua yang sedang tampil. */
    public function openLampiranPasienDokter(int $gdId): void
    {
        $this->dispatch('keuangan.gaji-dokter.openLampiranPasienDokter', gdId: $gdId);
    }

    public function openLampiranPasienSemuaDokter(): void
    {
        $gdIdList = $this->rows->pluck('gd_id')->map(fn($gdId) => (int) $gdId)->values()->all();
        if ($gdIdList === []) {
            $this->dispatch('toast', type: 'warning', message: 'Belum ada slip yang bisa dilampirkan pada bulan jasa ini.');
            return;
        }
        $this->dispatch('keuangan.gaji-dokter.openLampiranPasienSemuaDokter', gdIdList: $gdIdList);
    }

    #[Computed]
    public function rows()
    {
        return GajiDokterSirus::daftar($this->periode(), $this->searchKeyword, $this->saring);
    }

    /** Pakai versi periode yang menunggu (terbaru) untuk gaji bulan jasa ini — GajiDokterVersiPeriode::pakai. */
    public function pakaiVersiMenunggu(): void
    {
        $menunggu = $this->versiMenunggu;
        if (!$menunggu) {
            return;
        }
        $pesanGalat = GajiDokterVersiPeriode::pakai((int) $menunggu->versi_id, auth()->id());
        $this->dispatch('toast', type: $pesanGalat ? 'error' : 'success',
            message: $pesanGalat ?? "Versi {$menunggu->versi} sekarang dipakai untuk gaji bulan jasa {$this->bulan}. Terapkan ke slip gaji ulang bila slip karyawan sudah terisi.");
        unset($this->versiMenunggu);
        $this->segarkanTampilan();
    }

    /** Buka tab Bandingkan Versi: A = versi periode dipakai, B = versi yang menunggu, dokter terpilih (opsional). */
    public function bandingkanVersiBaru(string $drId = ''): void
    {
        $dipakai = GajiDokterVersiPeriode::dipakai($this->periode());
        session(['gaji-dokter-banding-a' => $dipakai ? (int) $dipakai->versi_id : null, 'gaji-dokter-banding-b' => $this->versiMenunggu ? (int) $this->versiMenunggu->versi_id : null,
            'gaji-dokter-banding-dokter' => $drId, 'gaji-dokter-banding-tampilkan' => 'beda']);
        $this->dispatch('keuangan.gaji-dokter.pindahTab', tab: 'banding');
    }

    #[Computed]
    public function versiMenunggu(): ?object
    {
        return GajiDokterVersiPeriode::menunggu($this->periode());
    }

    #[Computed]
    public function versiDipakai(): ?object
    {
        return GajiDokterVersiPeriode::dipakai($this->periode());
    }

    #[Computed]
    public function ringkasanPeriode(): array
    {
        $rows = GajiDokterSirus::daftar($this->periode());
        $totalPeriode = ['jumlah' => $rows->count(), 'final' => $rows->where('gaji_status', 'F')->count(), 'tanpa_map' => $rows->whereNull('emp_id_map')->count(), 'berubah' => $rows->where('versi_sebelumnya', true)->count(),
            'versi_baru' => $rows->whereNotNull('versi_baru')->count(),
            'diterapkan' => $rows->filter(fn($row) => $row->diterapkan_versi === (int) $row->versi)->count(),
            'diterapkan_lama' => $rows->filter(fn($row) => $row->diterapkan_versi !== null && $row->diterapkan_versi !== (int) $row->versi)->count()];
        foreach (GajiDokterSirus::KOLOM_NOMINAL as $kolomNominal) {
            $totalPeriode[$kolomNominal] = (float) $rows->sum($kolomNominal);
        }

        return $totalPeriode;
    }

    public static function namaPeriode(?string $periode): string
    {
        return $periode && preg_match('/^(\d{4})-(\d{2})$/', $periode, $bagianPeriode)
            ? ((['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'][$bagianPeriode[2]] ?? $bagianPeriode[2]) . ' ' . $bagianPeriode[1])
            : (string) $periode;
    }

    #[Computed]
    public function ambilTerakhirPeriodeIni(): ?object
    {
        return GajiDokterSirus::riwayatAmbil(200)->firstWhere('periode', $this->periode());
    }
};
?>

<div class="mt-4">
    @php
        $rupiah = fn($nilai) => GajiKaryawan::rupiah($nilai);
        $formatPersen = fn($nilai) => rtrim(rtrim(number_format((float) $nilai, 2, ',', '.'), '0'), ',');
        $formatSelisih = fn($nilai) => $nilai === null ? '–' : ($nilai > 0 ? '+' . $rupiah($nilai) : ($nilai < 0 ? '−' . $rupiah(abs($nilai)) : '0'));
        $totalPeriode = $this->ringkasanPeriode;
        $ambilTerakhir = $this->ambilTerakhirPeriodeIni;
        $periodeJasa = \Carbon\Carbon::createFromFormat('!' . Format::BULAN, $bulan)->format('Y-m');
        $labelBulanJasa = $this::namaPeriode($periodeJasa);
        $labelBulanGaji = $this::namaPeriode(\Carbon\Carbon::createFromFormat('!Y-m', $periodeJasa)->addMonthNoOverflow()->format('Y-m'));
    @endphp

    {{-- TOOLBAR — saringan di kiri, aksi di kanan (pola Slip Gaji Dokter SIRUS). --}}
    <div class="flex flex-wrap items-end gap-3 px-4 py-3 mb-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
        <div>
            <x-input-label value="Bulan Jasa" />
            <div class="flex items-center gap-1 mt-1">
                <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-3" title="Bulan sebelumnya" aria-label="Bulan sebelumnya">‹</x-secondary-button>
                <div class="w-28">
                    <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" class="w-full font-mono text-center" aria-label="Bulan jasa" />
                </div>
                <x-secondary-button type="button" wire:click="geserBulan(1)" class="!px-3" title="Bulan berikutnya" aria-label="Bulan berikutnya">›</x-secondary-button>
            </div>
        </div>
        <div class="w-48">
            <x-input-label for="gd-cari" value="Cari Dokter" />
            <x-text-input id="gd-cari" type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="nama dokter" class="block w-full mt-1" />
        </div>
        <div class="w-52">
            <x-input-label for="gd-saring" value="Tampilkan" />
            <div class="mt-1">
                <x-select-input id="gd-saring" wire:model.live="saring">
                    <option value="semua">Semua slip</option>
                    <option value="versi_baru">Berbeda di versi baru (belum dipakai)</option>
                    <option value="berubah">Pernah berubah (versi &gt; 1)</option>
                    <option value="tanpa_map">Dokter belum dipetakan</option>
                    <option value="final">Final</option>
                </x-select-input>
            </div>
        </div>
        <div class="flex items-center gap-2 ml-auto">
            @if ($totalPeriode['jumlah'] > 0)
                {{-- Terapkan = pola "Terapkan ke Slip" Pembagian Jasa Medik: aman diulang (baris lama ditimpa), slip Final dilewati. --}}
                <x-confirm-button variant="secondary" action="terapkanGajiDokterKeSlip()" title="Terapkan ke slip gaji"
                    message="Tulis jasa dokter bulan jasa {{ $bulan }} (versi dipakai) ke slip Draft Gaji Karyawan bulan kerja {{ $labelBulanJasa }} (dibayar {{ $labelBulanGaji }}) untuk dokter yang sudah dipetakan? Dokter yang belum punya slip dibuatkan slip Draft baru. Jasa, gaji pokok dokter, tambahan, Potongan RS, dan PPh 21 ditulis apa adanya dari slip dokter; baris hasil terapkan sebelumnya ditimpa. Slip yang sudah Final dilewati." confirmText="Ya, terapkan" cancelText="Batal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
                    Terapkan ke slip gaji
                </x-confirm-button>
            @endif
            {{-- Lampiran Semua = outline (tampilan pendamping, bukan aksi utama) — mengikuti saringan yang sedang tampil. --}}
            <x-outline-button type="button" class="gap-2 whitespace-nowrap" wire:click="openLampiranPasienSemuaDokter" wire:loading.attr="disabled" wire:target="openLampiranPasienSemuaDokter" title="Lampiran rincian pasien semua dokter yang tampil">
                <span wire:loading.remove wire:target="openLampiranPasienSemuaDokter" class="inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" /></svg>
                    Lampiran Semua
                </span>
                <span wire:loading wire:target="openLampiranPasienSemuaDokter" class="inline-flex items-center gap-2"><x-loading /> Menyiapkan...</span>
            </x-outline-button>
            <x-toolbar-refresh-reset :label="null" />
        </div>
    </div>

    <p class="px-4 mb-3 text-sm text-muted dark:text-gray-400">
        Jasa <span class="font-semibold text-body dark:text-gray-200">{{ $labelBulanJasa }}</span> dibayarkan pada gaji
        <span class="font-semibold text-body dark:text-gray-200">{{ $labelBulanGaji }}</span>.
        Slip dihitung dari transaksi SIRUS + struktur gaji SIAP; baris manual (kasbon, bonus) lewat Rincian, lalu kunci dengan Final. Ambil ulang di tab Ambil Data kapan saja &mdash; hasil yang berbeda menjadi versi baru bulan ini dan <b>baru masuk gaji setelah dipilih</b>@if ($this->versiDipakai) (sekarang: <b>versi {{ $this->versiDipakai->versi }}</b>)@endif.
        @if ($ambilTerakhir)<span class="whitespace-nowrap">Terakhir diambil {{ \Carbon\Carbon::parse($ambilTerakhir->diambil_at)->format('d/m/Y H:i') }} oleh {{ $ambilTerakhir->name ?? '–' }}.</span>@endif
    </p>

    {{-- VERSI PERIODE BELUM DIPAKAI — menetap di atas, keputusan Keuangan (pola SIKEU "sahkan versi"). --}}
    @php $versiMenunggu = $this->versiMenunggu; $versiDipakaiPeriode = $this->versiDipakai; @endphp
    @if ($versiMenunggu)
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 mb-3 text-sm border rounded-2xl border-hairline bg-warning-soft text-fg-warning dark:border-gray-700" role="status">
            <span><b>Versi {{ $versiMenunggu->versi }}</b> ({{ \Carbon\Carbon::parse($versiMenunggu->created_at)->format('d/m/Y H:i') }}) belum dipakai &mdash; gaji masih memakai <b>versi {{ $versiDipakaiPeriode?->versi ?? '–' }}</b>.
                {{ $totalPeriode['versi_baru'] ?? 0 }} dokter berbeda.</span>
            <div class="flex flex-wrap gap-2 ml-auto">
                <x-secondary-button type="button" wire:click="bandingkanVersiBaru">Bandingkan</x-secondary-button>
                <x-confirm-button variant="primary" action="pakaiVersiMenunggu()" title="Pakai versi {{ $versiMenunggu->versi }}"
                    message="Pakai versi {{ $versiMenunggu->versi }} untuk gaji SELURUH dokter bulan jasa {{ $bulan }}? Sebaiknya bandingkan dulu. Versi lain tetap tersimpan; slip gaji karyawan baru berubah setelah Terapkan ulang." confirmText="Ya, pakai" cancelText="Batal">
                    Pakai versi {{ $versiMenunggu->versi }}
                </x-confirm-button>
            </div>
        </div>
    @endif

    {{-- RINGKASAN & PANDUAN sebaris: keduanya panel yang bisa ditutup (pola SIRUS). items-start supaya yang terbuka tidak menarik tinggi pasangannya. --}}
    <div class="grid items-start grid-cols-1 gap-3 mb-4 lg:grid-cols-2">
        {{-- RINGKASAN — bukan biru-info (biru dicadangkan untuk panduan). Default tertutup; angka pentingnya tetap terbaca di bilah judul. --}}
        <div x-data="{ buka: false }" class="overflow-hidden border rounded-2xl border-hairline bg-canvas dark:bg-gray-900 dark:border-gray-700">
            <button type="button" x-on:click="buka = !buka" :aria-expanded="buka"
                class="flex items-center justify-between w-full px-4 py-2.5 text-sm font-semibold transition-colors text-ink hover:bg-surface-soft dark:text-gray-100 dark:hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-green dark:focus-visible:ring-brand-lime">
                <span class="flex items-center min-w-0 gap-2">
                    <svg class="w-4 h-4 shrink-0 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m4 10V11m4 6V9M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                    <span class="shrink-0">Ringkasan periode</span>
                    <span class="text-sm font-normal truncate text-muted dark:text-gray-400">
                        @if ($totalPeriode['jumlah'] === 0)(belum ada snapshot {{ $labelBulanJasa }})@else({{ $totalPeriode['jumlah'] }} slip &middot; diterima {{ $rupiah($totalPeriode['gaji_diterima']) }})@endif
                    </span>
                </span>
                <svg class="w-4 h-4 ml-2 transition-transform shrink-0 text-muted motion-reduce:transition-none" x-bind:class="buka && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
            </button>

            <div x-show="buka" x-cloak>
                @if ($totalPeriode['jumlah'] === 0)
                    <p class="px-4 pb-4 text-sm text-muted dark:text-gray-400">Belum ada slip bulan jasa {{ $labelBulanJasa }} &mdash; ambil dulu di tab <button type="button" wire:click="$dispatch('keuangan.gaji-dokter.pindahTab', { tab: 'ambil' })" class="font-semibold underline underline-offset-2 text-brand-green dark:text-brand-lime">Ambil Data</button>.</p>
                @else
                    {{-- Lima angka dalam satu baris: label kecil di atas nilai, dipisah garis tipis. --}}
                    <div class="grid grid-cols-5 px-4 pb-4 divide-x divide-hairline dark:divide-gray-700">
                        <div class="pr-2">
                            <div class="text-sm text-muted dark:text-gray-400">Slip</div>
                            <div class="font-semibold t-num text-ink dark:text-gray-100">{{ $totalPeriode['jumlah'] }}</div>
                            <div class="text-sm t-num text-muted dark:text-gray-400 whitespace-nowrap">{{ $totalPeriode['jumlah'] - $totalPeriode['final'] }} draft &middot; {{ $totalPeriode['final'] }} final</div>
                        </div>
                        <div class="px-2">
                            <div class="text-sm text-muted dark:text-gray-400">Jasa</div>
                            <div class="font-semibold t-num text-ink dark:text-gray-100">{{ $rupiah($totalPeriode['jasa_total']) }}</div>
                        </div>
                        <div class="px-2">
                            <div class="text-sm text-muted dark:text-gray-400">Total Gaji</div>
                            <div class="font-semibold t-num text-ink dark:text-gray-100">{{ $rupiah($totalPeriode['total_gaji']) }}</div>
                        </div>
                        <div class="px-2">
                            <div class="text-sm text-muted dark:text-gray-400 whitespace-nowrap">Pot. RS + PPh</div>
                            <div class="font-semibold t-num text-rose-600 dark:text-rose-300">{{ $rupiah(abs($totalPeriode['potongan_rs']) + abs($totalPeriode['pph21'])) }}</div>
                        </div>
                        <div class="pl-2">
                            <div class="text-sm text-muted dark:text-gray-400">Diterima</div>
                            <div class="font-semibold t-num text-emerald-700 dark:text-emerald-300">{{ $rupiah($totalPeriode['gaji_diterima']) }}</div>
                        </div>
                    </div>
                    {{-- Penanda khas SIAP: pemetaan & versi. Menetap (bukan toast). --}}
                    <div class="flex flex-wrap items-center gap-2 px-4 pb-4 -mt-1 text-sm">
                        @if ($totalPeriode['tanpa_map'] > 0)
                            <x-badge variant="warning">{{ $totalPeriode['tanpa_map'] }} dokter belum dipetakan ke karyawan</x-badge>
                            <span class="text-muted dark:text-gray-400">&rarr; tab <span class="font-semibold">Pemetaan Dokter</span></span>
                        @else
                            <x-badge variant="success">Semua dokter terpetakan</x-badge>
                        @endif
                        @if ($totalPeriode['berubah'] > 0)<x-badge variant="warning">{{ $totalPeriode['berubah'] }} slip pernah berubah sejak pertama diambil</x-badge>@endif
                        @if ($totalPeriode['diterapkan'] > 0)<x-badge variant="success">{{ $totalPeriode['diterapkan'] }} sudah di slip gaji bulan kerja {{ $labelBulanJasa }}</x-badge>@endif
                        @if ($totalPeriode['diterapkan_lama'] > 0)<x-badge variant="warning">{{ $totalPeriode['diterapkan_lama'] }} slip gaji masih memakai versi lama — Terapkan ulang</x-badge>@endif
                        @if ($totalPeriode['diterapkan'] === 0 && $totalPeriode['diterapkan_lama'] === 0)<x-badge variant="gray">Belum diterapkan ke slip gaji</x-badge>@endif
                    </div>
                @endif
            </div>
        </div>

        @include('pages.keuangan.gaji-dokter.panduan-buku')
    </div>

    {{-- TABEL — baris = kartu ber-ring dengan jarak antar baris, penanda warna kiri per status (pola Slip Gaji Dokter SIRUS). --}}
    <div class="flex flex-col border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="overflow-x-auto rounded-2xl">
            <table class="w-full min-w-[64rem] text-base -mt-3 border-separate border-spacing-y-3 table-fixed">
                <thead class="[&_th]:bg-surface-card dark:[&_th]:bg-gray-800">
                    {{-- 11 kolom dirapatkan jadi 6: angka induk besar, penyusunnya baris kecil di bawahnya. --}}
                    <tr class="text-sm font-semibold tracking-wide text-left uppercase text-muted dark:text-gray-300">
                        <th class="px-3 py-3 w-[4%] text-right">No</th>
                        <th class="px-6 py-3 w-[28%]">Dokter</th>
                        <th class="px-6 py-3 w-[15%] text-right">Total Gaji</th>
                        <th class="px-6 py-3 w-[17%] text-right">Potongan</th>
                        <th class="px-6 py-3 w-[16%] text-right">Diterima</th>
                        <th class="px-6 py-3 w-[20%] text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        @php $potonganTotal = abs((float) $row->potongan_rs) + abs((float) $row->pph21) + abs((float) $row->potongan_lain_total); @endphp
                        <tr wire:key="gd-{{ $row->gd_id }}"
                            class="transition rounded-2xl shadow-sm ring-1 ring-hairline dark:ring-gray-700 {{ $row->gaji_status === 'F'
                                ? 'bg-success/10 dark:bg-gray-800 hover:shadow-lg hover:bg-success/20 dark:hover:bg-gray-700 border-l-4 border-success'
                                : 'bg-canvas dark:bg-gray-900 hover:shadow-lg hover:bg-surface-soft dark:hover:bg-gray-800 border-l-4 border-warning' }}">
                            <td class="px-3 py-3 text-sm text-right align-middle t-num text-muted dark:text-gray-400">{{ $loop->iteration }}</td>

                            {{-- DOKTER + sifat slip + pemetaan karyawan SIAP --}}
                            <td class="px-6 py-3 align-middle">
                                <div class="font-semibold truncate text-ink dark:text-gray-100" title="{{ $row->dr_name }}">{{ $row->dr_name }}</div>
                                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                    <x-badge :variant="$row->gaji_status === 'F' ? 'success' : 'warning'">{{ $row->gaji_status === 'F' ? 'Final' : 'Draft' }}</x-badge>
                                    @if (($row->sumber ?? 'SIRUS') === 'SIRUS')<x-badge variant="gray" title="Snapshot lama yang dihitung SIRUS (sebelum penggajian dokter pindah ke SIAP) — Ambil ulang untuk menghitungnya di SIAP">arsip SIRUS</x-badge>@endif
                                    @if ($row->versi_sebelumnya)
                                        <x-badge variant="info" title="Slip dokter ini pernah berubah sejak pertama diambil">slip v{{ $row->versi }}</x-badge>
                                    @endif
                                    @if ($row->versi_baru && $versiMenunggu)
                                        <button type="button" wire:click="bandingkanVersiBaru('{{ $row->dr_id }}')"
                                            class="inline-flex items-center rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green" title="Angka dokter ini berbeda di versi {{ $versiMenunggu->versi }} yang belum dipakai">
                                            <x-badge variant="warning">berbeda di versi {{ $versiMenunggu->versi }} · Bandingkan →</x-badge>
                                        </button>
                                    @endif
                                    <span class="text-sm text-muted dark:text-gray-400">
                                        {{ $row->dr_id }} &middot; <span class="font-mono">{{ $row->skema_gaji_pokok }}/{{ $row->potongan_rs_basis }}</span>
                                        @if (in_array($row->potongan_rs_basis, ['T', 'J'], true)) {{ $formatPersen($row->potongan_rs_persen) }}% @endif
                                        &middot; {{ $row->npwp_status === 'Y' ? 'NPWP' : 'tanpa NPWP' }}
                                    </span>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5 mt-1 text-sm">
                                    @if ($row->emp_id_map)
                                        <span class="truncate"><span class="text-muted dark:text-gray-400">Karyawan SIAP:</span> <span class="text-body dark:text-gray-200">{{ $row->emp_name }}</span> <span class="font-mono text-muted dark:text-gray-400">{{ $row->emp_id_map }}</span></span>
                                        @if ($row->diterapkan_versi === (int) $row->versi)
                                            <x-badge variant="success" title="Ditulis ke slip gaji bulan kerja {{ $labelBulanJasa }} pada {{ \Carbon\Carbon::parse($row->diterapkan_at_slip)->format('d/m/Y H:i') }}">di slip gaji</x-badge>
                                        @elseif ($row->diterapkan_versi !== null)
                                            <x-badge variant="warning" title="Slip gaji masih memakai versi {{ $row->diterapkan_versi }}; snapshot sudah versi {{ $row->versi }} — klik Terapkan ke slip gaji">slip pakai v{{ $row->diterapkan_versi }}</x-badge>
                                        @else
                                            <x-badge variant="gray">belum di slip gaji</x-badge>
                                        @endif
                                    @else
                                        <x-badge variant="warning">Belum dipetakan ke karyawan</x-badge>
                                    @endif
                                </div>
                            </td>

                            {{-- TOTAL GAJI = jasa + gaji pokok --}}
                            <td class="px-6 py-3 text-right align-middle">
                                <div class="font-semibold t-num text-ink dark:text-gray-100">{{ $rupiah($row->total_gaji) }}</div>
                                <div class="text-sm t-num text-muted dark:text-gray-400">
                                    jasa {{ $rupiah($row->jasa_total) }}
                                    @if ((float) $row->nilai_gaji_pokok > 0) + pokok {{ $rupiah($row->nilai_gaji_pokok) }} @endif
                                </div>
                            </td>

                            {{-- POTONGAN = RS + PPh + lain --}}
                            <td class="px-6 py-3 text-right align-middle">
                                <div class="font-semibold t-num text-rose-600 dark:text-rose-300">-{{ $rupiah($potonganTotal) }}</div>
                                <div class="text-sm t-num text-muted dark:text-gray-400">
                                    RS {{ $rupiah(abs((float) $row->potongan_rs)) }} &middot; PPh {{ $rupiah(abs((float) $row->pph21)) }}
                                    @if ((float) $row->potongan_lain_total != 0) &middot; lain {{ $rupiah(abs((float) $row->potongan_lain_total)) }} @endif
                                </div>
                            </td>

                            {{-- DITERIMA + pembanding bulan lalu (khas SIAP) --}}
                            <td class="px-6 py-3 text-right align-middle">
                                <div class="text-xl font-bold t-num text-emerald-700 dark:text-emerald-300">{{ $rupiah($row->gaji_diterima) }}</div>
                                <div class="text-sm t-num text-muted dark:text-gray-400">
                                    @if ((float) $row->tambahan_total > 0)<span class="block">termasuk tambahan {{ $rupiah($row->tambahan_total) }}</span>@endif
                                    <span class="{{ $row->selisih_bulan_lalu === null ? '' : ($row->selisih_bulan_lalu < 0 ? 'text-fg-danger-strong' : 'text-fg-success-strong') }}" title="Dibanding gaji diterima bulan jasa sebelumnya">vs bulan lalu {{ $formatSelisih($row->selisih_bulan_lalu) }}</span>
                                </div>
                            </td>

                            {{-- AKSI: Rincian (outline) · Lampiran (icon abu, ikon daftar). Target ber-argumen supaya hanya barisnya yang berputar. --}}
                            <td class="px-6 py-3 align-middle">
                                <div class="flex flex-nowrap items-center justify-center gap-1.5">
                                    <x-outline-button type="button" class="whitespace-nowrap shrink-0" wire:click="openRincianSlipDokter({{ $row->gd_id }})" wire:loading.attr="disabled" wire:target="openRincianSlipDokter({{ $row->gd_id }})">
                                        <span wire:loading.remove wire:target="openRincianSlipDokter({{ $row->gd_id }})" class="inline-flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                            Rincian
                                        </span>
                                        <span wire:loading wire:target="openRincianSlipDokter({{ $row->gd_id }})"><x-loading /> Membuka</span>
                                    </x-outline-button>
                                    @if (($row->sumber ?? 'SIRUS') === 'SIAP')
                                        @if ($row->gaji_status === 'F')
                                            <x-confirm-button variant="warning-soft" action="bukaKunciSiap({{ $row->gd_id }})" title="Buka Kunci" message="Buka kunci slip {{ $row->dr_name }}? Slip bisa diproses ulang dan baris manualnya diubah." confirmText="Ya, buka" cancelText="Batal" class="whitespace-nowrap shrink-0">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" /></svg>
                                                Buka Kunci
                                            </x-confirm-button>
                                        @else
                                            <x-confirm-button variant="primary" action="finalkanSiap({{ $row->gd_id }})" title="Final" message="Kunci slip {{ $row->dr_name }} (versi {{ $row->versi }}) sebagai Final? Versi dipakai tidak bisa diganti sampai dibuka lagi; Ambil berikutnya tetap mencatat versi baru untuk pembanding." confirmText="Ya, kunci" cancelText="Batal" class="whitespace-nowrap shrink-0">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                                Final
                                            </x-confirm-button>
                                        @endif
                                    @endif
                                    <x-icon-button color="gray" type="button" class="!p-2.5 shrink-0" wire:click="openLampiranPasienDokter({{ $row->gd_id }})" wire:loading.attr="disabled" wire:target="openLampiranPasienDokter({{ $row->gd_id }})"
                                        title="Lampiran rincian pasien {{ $row->dr_name }} ({{ $row->jumlah_lampiran }} baris)" aria-label="Lampiran rincian pasien {{ $row->dr_name }}">
                                        <span wire:loading.remove wire:target="openLampiranPasienDokter({{ $row->gd_id }})" class="inline-flex items-center gap-1">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" /></svg>
                                            <span class="text-xs t-num">{{ $row->jumlah_lampiran }}</span>
                                        </span>
                                        <span wire:loading wire:target="openLampiranPasienDokter({{ $row->gd_id }})"><x-loading size="md" /></span>
                                    </x-icon-button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-16 text-center text-muted dark:text-gray-400">
                                @if ($totalPeriode['jumlah'] === 0)
                                    Belum ada slip bulan jasa {{ $labelBulanJasa }}. Ambil dulu di tab <button type="button" wire:click="$dispatch('keuangan.gaji-dokter.pindahTab', { tab: 'ambil' })" class="font-semibold underline underline-offset-2 text-brand-green dark:text-brand-lime">Ambil Data</button>.
                                @else
                                    Tidak ada slip yang cocok dengan saringan. <button type="button" wire:click="resetFilters" class="font-semibold underline underline-offset-2 text-brand-green dark:text-brand-lime">Reset saringan</button>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
