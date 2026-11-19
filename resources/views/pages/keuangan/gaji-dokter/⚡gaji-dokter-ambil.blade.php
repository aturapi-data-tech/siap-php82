<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\Format;
use App\Support\GajiDokterSirus;

/**
 * Tab 1 · AMBIL DATA Gaji Dokter (anak pembungkus gaji-dokter) — langkah pertama tiap bulan: pilih bulan jasa → Ambil dari
 * SIRUS (GajiDokterSirus::ambil: transaksi SIRUS dihitung dengan struktur gaji SIAP; sama → tetap, beda → versi baru; aman
 * diulang). Hasil ambil terakhir menetap di layar (bukan hanya toast) beserta tindak lanjutnya, lalu riwayat semua penarikan.
 * Bulan jasa berbagi Session dengan tab Buku & Bandingkan Versi. Pindah tab lewat event 'keuangan.gaji-dokter.pindahTab'.
 */
new class extends Component {
    #[Session(key: 'gaji-dokter-bulan')]
    public string $bulan = ''; // mm/yyyy = bulan JASA

    /** Hasil Ambil terakhir di layar ini (periode, jumlah, baru, berubah, sama, final[], tanpaStruktur[], tanpaMap[], catatan[], waktu). */
    public ?array $hasilAmbil = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!$this->bulanValid()) {
            $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
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

    public function updatedBulan(): void
    {
        unset($this->statusBulan, $this->periodeJasa);
    }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        unset($this->statusBulan, $this->periodeJasa);
    }

    public function ambil(): void
    {
        if (!$this->bulanValid()) {
            $this->dispatch('toast', type: 'error', message: 'Bulan jasa tidak valid (mm/yyyy).');
            return;
        }
        try {
            $hasil = GajiDokterSirus::ambil($this->periode(), auth()->id());
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
            return;
        }
        $this->hasilAmbil = [...$hasil, 'periode' => $this->periode(), 'waktu' => now()->format('d/m/Y H:i')];
        unset($this->statusBulan, $this->rows);
        $this->dispatch('toast', type: $hasil['jumlah'] === 0 ? 'warning' : 'success',
            message: $hasil['jumlah'] === 0 ? "SIRUS tidak punya transaksi dokter untuk bulan jasa {$this->bulan}." : "Ambil {$this->bulan} selesai: {$hasil['jumlah']} dokter.");
    }

    public function pindahTab(string $tab): void
    {
        $this->dispatch('keuangan.gaji-dokter.pindahTab', tab: $tab);
    }

    /** Keadaan bulan jasa terpilih: jumlah slip (versi terbaru), Final, yang pernah berubah, ambil terakhir. */
    #[Computed]
    public function statusBulan(): array
    {
        $periode = $this->periode();
        $terbaru = DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('terbaru', '1');

        return [
            'slip' => (clone $terbaru)->count(),
            'final' => (clone $terbaru)->where('gaji_status', 'F')->count(),
            'berversi' => (clone $terbaru)->where('versi', '>', 1)->count(),
            'ambil' => DB::table('siaptxn_gdok_ambils as a')->leftJoin('users as u', 'u.id', '=', 'a.diambil_oleh')
                ->where('a.periode', $periode)->orderByDesc('a.ambil_id')->first(['a.diambil_at', 'u.name']),
        ];
    }

    #[Computed]
    public function periodeJasa(): string
    {
        return $this->periode();
    }

    #[Computed]
    public function rows()
    {
        return GajiDokterSirus::riwayatAmbil(60);
    }
};
?>

<div class="mt-4 space-y-3">
    @php
        $periodeJasa = $this->periodeJasa;
        $labelBulanJasa = GajiDokterSirus::namaPeriode($periodeJasa);
        $labelBulanGaji = GajiDokterSirus::namaPeriode(Carbon::createFromFormat('!Y-m', $periodeJasa)->addMonthNoOverflow()->format('Y-m'));
        $status = $this->statusBulan;
        $hasil = $hasilAmbil && $hasilAmbil['periode'] === $periodeJasa ? $hasilAmbil : null;
        $daftarNama = fn(array $namaList) => implode(', ', array_slice($namaList, 0, 8)) . (count($namaList) > 8 ? ' dan ' . (count($namaList) - 8) . ' lainnya' : '');
        $tautan = 'font-semibold underline underline-offset-2 text-brand-green dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green rounded';
    @endphp

    {{-- AMBIL: bulan jasa + tombol --}}
    <div class="px-4 py-4 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
        <div class="flex flex-wrap items-end gap-4">
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
            <div>
                @if (GajiDokterSirus::aktif())
                    <x-confirm-button variant="primary" action="ambil()" title="Ambil dari SIRUS"
                        message="Ambil transaksi dokter bulan jasa {{ $bulan }} dari SIRUS dan hitung slipnya dengan struktur gaji di tab Struktur Gaji (+ baris manual)? Dokter yang angkanya SAMA tidak berubah; yang BERBEDA mendapat versi baru (versi lama tetap tersimpan). Slip yang sudah Final dilewati. Tidak ada data SIRUS yang diubah." confirmText="Ya, ambil" cancelText="Batal">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                        Ambil dari SIRUS
                    </x-confirm-button>
                @else
                    <x-badge variant="danger">API SIRUS belum diatur di .env (SIAP_API_URL / USER / PASSWORD)</x-badge>
                @endif
            </div>
            <div class="text-sm text-muted dark:text-gray-400 lg:ml-auto lg:text-right">
                <p>Jasa <span class="font-semibold text-body dark:text-gray-200">{{ $labelBulanJasa }}</span> dibayarkan pada gaji <span class="font-semibold text-body dark:text-gray-200">{{ $labelBulanGaji }}</span>.</p>
                <p>
                    @if ($status['slip'] > 0)
                        Sudah ada <span class="font-semibold text-body dark:text-gray-200">{{ $status['slip'] }} slip</span>
                        &middot; {{ $status['final'] }} Final @if ($status['berversi'] > 0) &middot; {{ $status['berversi'] }} pernah berubah @endif
                        @if ($status['ambil']) &middot; terakhir diambil {{ Carbon::parse($status['ambil']->diambil_at)->format('d/m/Y H:i') }} oleh {{ $status['ambil']->name ?? '–' }}@endif
                    @else
                        Belum pernah diambil.
                    @endif
                </p>
            </div>
        </div>
        <p class="mt-3 text-xs text-muted dark:text-gray-400">Aman diulang kapan saja: yang berubah (transaksi SIRUS, struktur gaji, atau baris manual) menjadi versi baru, yang sama dibiarkan. Setelah itu periksa di <b>2. Buku Gaji Dokter</b>.</p>
    </div>

    {{-- HASIL AMBIL TERAKHIR — menetap, dengan tindak lanjut --}}
    @if ($hasil)
        <div class="px-4 py-4 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700" role="status" aria-live="polite">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold text-ink dark:text-gray-100">Hasil ambil {{ $labelBulanJasa }} <span class="text-sm font-normal text-muted">· {{ $hasil['waktu'] }}</span></h2>
                <div class="flex flex-wrap gap-2">
                    @if ($hasil['berubah'] > 0)
                        <x-secondary-button type="button" wire:click="pindahTab('banding')">Lihat perubahan di 3. Bandingkan Versi</x-secondary-button>
                    @endif
                    @if ($hasil['jumlah'] > 0)
                        <x-primary-button type="button" wire:click="pindahTab('buku')">Lanjut ke 2. Buku Gaji Dokter →</x-primary-button>
                    @endif
                </div>
            </div>
            @if ($hasil['jumlah'] === 0)
                <p class="mt-2 text-sm text-body dark:text-gray-300">SIRUS tidak punya transaksi dokter untuk bulan jasa {{ $labelBulanJasa }}. Periksa bulan yang dipilih (bulan jasa, bukan bulan gaji).</p>
            @else
                <div class="grid grid-cols-2 gap-3 mt-3 sm:grid-cols-5">
                    @foreach ([['Dokter', $hasil['jumlah'], null], ['Baru', $hasil['baru'], 'success'], ['Berubah · versi baru', $hasil['berubah'], 'warning'], ['Sama', $hasil['sama'], null], ['Dilewati (Final)', count($hasil['final']), null]] as [$judul, $angka, $warna])
                        <div class="px-3 py-2 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                            <p class="text-xs font-semibold uppercase text-muted">{{ $judul }}</p>
                            <p @class(['text-xl font-semibold tabular-nums', 'text-ink dark:text-gray-100' => !$warna || $angka === 0, 'text-fg-success-strong' => $warna === 'success' && $angka > 0, 'text-fg-warning' => $warna === 'warning' && $angka > 0])>{{ $angka }}</p>
                        </div>
                    @endforeach
                </div>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @if ($hasil['tanpaStruktur'] !== [])
                        <li class="flex gap-2"><x-badge variant="warning" class="shrink-0">Perlu tindakan</x-badge>
                            <span><b>{{ count($hasil['tanpaStruktur']) }} dokter belum punya struktur gaji</b> — dihitung tanpa gaji pokok &amp; potongan: {{ $daftarNama($hasil['tanpaStruktur']) }}.
                                <button type="button" wire:click="pindahTab('struktur')" class="{{ $tautan }}">Isi di Struktur Gaji</button>, lalu Ambil ulang.</span></li>
                    @endif
                    @if ($hasil['tanpaMap'] !== [])
                        <li class="flex gap-2"><x-badge variant="gray" class="shrink-0">Catatan</x-badge>
                            <span><b>{{ count($hasil['tanpaMap']) }} dokter belum dipetakan ke karyawan</b> — belum bisa diterapkan ke slip gaji: {{ $daftarNama($hasil['tanpaMap']) }}.
                                <button type="button" wire:click="pindahTab('pemetaan')" class="{{ $tautan }}">Petakan</button> bila memang digaji lewat SIAP.</span></li>
                    @endif
                    @if ($hasil['final'] !== [])
                        <li class="flex gap-2"><x-badge variant="gray" class="shrink-0">Catatan</x-badge>
                            <span>Dilewati karena sudah Final: {{ $daftarNama($hasil['final']) }}. Buka Kunci di Buku bila perlu dihitung ulang.</span></li>
                    @endif
                    @foreach (array_slice($hasil['catatan'], 0, 10) as $catatan)
                        <li class="flex gap-2"><x-badge variant="gray" class="shrink-0">Catatan</x-badge><span>{{ $catatan }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- RIWAYAT --}}
    <div class="flex flex-col border bg-canvas border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="px-4 pt-3">
            <h2 class="font-semibold text-ink dark:text-gray-100">Riwayat ambil</h2>
            <p class="text-xs text-muted"><b>Baru</b> = dokter yang belum punya slip bulan itu. <b>Berubah</b> = hasil hitung berbeda dari versi terakhir → versi baru dibuat (versi lama tetap tersimpan, lihat 3. Bandingkan Versi). <b>Sama</b> = tidak ada perubahan, hanya tanggal ambil diperbarui.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Bulan jasa</th>
                        <th>Oleh</th>
                        <th class="text-right">Dokter</th>
                        <th class="text-right">Baru</th>
                        <th class="text-right">Berubah</th>
                        <th class="text-right">Sama</th>
                        <th class="text-right">Baris lampiran</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        <tr wire:key="ambil-{{ $row->ambil_id }}" @class(['bg-surface-strong/60 dark:bg-gray-800' => $row->periode === $periodeJasa])>
                            <td class="font-mono text-xs whitespace-nowrap">{{ Carbon::parse($row->diambil_at)->format('d/m/Y H:i') }}</td>
                            <td class="font-mono">{{ Carbon::createFromFormat('!Y-m', $row->periode)->translatedFormat('M Y') }}</td>
                            <td>{{ $row->name ?? '–' }}</td>
                            <td class="font-mono text-right">{{ $row->jumlah_slip }}</td>
                            <td class="font-mono text-right">{{ $row->baru > 0 ? $row->baru : '–' }}</td>
                            <td class="font-mono text-right">@if ($row->berubah > 0)<x-badge variant="warning">{{ $row->berubah }}</x-badge>@else – @endif</td>
                            <td class="font-mono text-right">{{ $row->sama > 0 ? $row->sama : '–' }}</td>
                            <td class="font-mono text-right">{{ $row->lampiran_baris > 0 ? number_format($row->lampiran_baris, 0, ',', '.') : '–' }}</td>
                            <td class="text-xs text-muted">{{ $row->keterangan ?? '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-8 text-center text-muted">Belum pernah mengambil data. Pilih bulan jasa di atas lalu klik <b>Ambil dari SIRUS</b>.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
