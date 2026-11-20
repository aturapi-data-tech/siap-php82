<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\Format;
use App\Support\GajiDokterSirus;
use App\Support\GajiDokterVersiPeriode;

/**
 * Tab 1 · AMBIL DATA Gaji Dokter (anak pembungkus gaji-dokter) — langkah pertama tiap bulan: pilih bulan jasa → Ambil dari
 * SIRUS (GajiDokterSirus::ambil: transaksi SIRUS dihitung dengan struktur gaji SIAP; sama → tetap, beda → versi baru; aman
 * diulang). Hasil ambil terakhir menetap di layar (bukan hanya toast) beserta tindak lanjutnya, lalu DAFTAR VERSI bulan itu
 * (GajiDokterVersiPeriode, pola SIKEU "Tarik Data SIRUS"): pilih versi yang dipakai untuk gaji (Pakai) atau bandingkan dulu
 * (tab Bandingkan Versi), lalu riwayat semua penarikan.
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
        unset($this->statusBulan, $this->periodeJasa, $this->versiPeriodeList);
    }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        unset($this->statusBulan, $this->periodeJasa, $this->versiPeriodeList);
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
        unset($this->statusBulan, $this->rows, $this->versiPeriodeList, $this->versiPerAmbil);
        $this->dispatch('toast', type: $hasil['jumlah'] === 0 ? 'warning' : 'success',
            message: $hasil['jumlah'] === 0 ? "SIRUS tidak punya transaksi dokter untuk bulan jasa {$this->bulan}." : "Ambil {$this->bulan} selesai: {$hasil['jumlah']} dokter.");
    }

    /** Pakai satu versi periode untuk gaji bulan jasa ini (semua dokter sekaligus) — GajiDokterVersiPeriode::pakai. */
    public function pakaiVersi(int $versiId): void
    {
        $versi = $this->versiPeriodeList->firstWhere('versi_id', $versiId);
        if (!$versi) {
            return;
        }
        $pesanGalat = GajiDokterVersiPeriode::pakai($versiId, auth()->id());
        $this->dispatch('toast', type: $pesanGalat ? 'error' : 'success',
            message: $pesanGalat ?? "Versi {$versi->versi} sekarang dipakai untuk gaji bulan jasa {$this->bulan}. Terapkan ke slip gaji ulang di Buku bila slip karyawan sudah terisi.");
        unset($this->versiPeriodeList, $this->statusBulan);
    }

    /** Buka tab Bandingkan Versi dengan A = versi dipakai, B = versi ini. */
    public function bandingkanVersi(int $versiId): void
    {
        $dipakai = $this->versiPeriodeList->firstWhere('dipakai', '1');
        session(['gaji-dokter-banding-a' => $dipakai && (int) $dipakai->versi_id !== $versiId ? (int) $dipakai->versi_id : null,
            'gaji-dokter-banding-b' => $versiId, 'gaji-dokter-banding-dokter' => '']);
        $this->pindahTab('banding');
    }

    public function pindahTab(string $tab): void
    {
        $this->dispatch('keuangan.gaji-dokter.pindahTab', tab: $tab);
    }

    /** Keadaan bulan jasa terpilih: jumlah slip (versi dipakai), Final, yang pernah berubah, versi baru belum dipakai, ambil terakhir. */
    #[Computed]
    public function statusBulan(): array
    {
        $periode = $this->periode();
        $dipakai = DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dipakai', '1');

        return [
            'slip' => (clone $dipakai)->count(),
            'final' => (clone $dipakai)->where('gaji_status', 'F')->count(),
            'berversi' => DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('versi', '>', 1)->distinct()->count('dr_id'),
            'dipakai' => GajiDokterVersiPeriode::dipakai($periode),
            'menunggu' => GajiDokterVersiPeriode::menunggu($periode),
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
    public function versiPeriodeList()
    {
        return GajiDokterVersiPeriode::daftar($this->periode());
    }

    /** ambil_id → nomor versi periode yang dibentuknya (Ambil yang hasilnya sama tidak membentuk versi). */
    #[Computed]
    public function versiPerAmbil(): array
    {
        return DB::table('siaptxn_gdok_versis')->whereNotNull('ambil_id')->pluck('versi', 'ambil_id')->map(fn($versi) => (int) $versi)->all();
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
        {{-- VERSI: keterangan menonjol versi berapa yang dipakai untuk gaji bulan ini --}}
        @if ($status['dipakai'])
            <div class="flex flex-wrap items-center gap-2 px-3 py-2 mt-3 text-sm rounded-xl bg-surface-soft dark:bg-gray-800" role="status">
                <span class="text-body dark:text-gray-300">Dipakai untuk gaji {{ $labelBulanJasa }}:</span>
                <x-badge variant="success" class="!text-sm">Versi {{ $status['dipakai']->versi }}</x-badge>
                <span class="text-xs text-muted">{{ GajiDokterVersiPeriode::LABEL_SUMBER[$status['dipakai']->sumber] ?? $status['dipakai']->sumber }} {{ Carbon::parse($status['dipakai']->created_at)->format('d/m/Y H:i') }}</span>
                @if ($status['menunggu'])
                    <span class="mx-1 text-muted" aria-hidden="true">·</span>
                    <x-badge variant="warning" class="!text-sm">Versi {{ $status['menunggu']->versi }} belum dipakai</x-badge>
                    <span class="text-xs text-muted">{{ Carbon::parse($status['menunggu']->created_at)->format('d/m/Y H:i') }} &mdash; pilih di daftar Versi di bawah</span>
                @endif
            </div>
        @endif
        <p class="mt-3 text-xs text-muted dark:text-gray-400">Aman diulang kapan saja. Ambil pertama menjadi <b>versi 1</b> dan langsung dipakai untuk gaji. Ambil ulang yang hasilnya berbeda menjadi <b>versi baru</b> untuk seluruh dokter bulan itu, tetapi <b>belum dipakai</b> &mdash; pilih versinya di daftar <b>Versi</b> di bawah (Bandingkan dulu bila perlu).</p>
    </div>

    {{-- HASIL AMBIL TERAKHIR — menetap, dengan tindak lanjut --}}
    @if ($hasil)
        <div class="px-4 py-4 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700" role="status" aria-live="polite">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold text-ink dark:text-gray-100">Hasil ambil {{ $labelBulanJasa }} <span class="text-sm font-normal text-muted">· {{ $hasil['waktu'] }}</span></h2>
                <div class="flex flex-wrap gap-2">
                    @if ($hasil['jumlah'] > 0)
                        <x-primary-button type="button" wire:click="pindahTab('buku')">Lanjut ke Buku Gaji Dokter →</x-primary-button>
                    @endif
                </div>
            </div>
            @if ($hasil['jumlah'] === 0)
                <p class="mt-2 text-sm text-body dark:text-gray-300">SIRUS tidak punya transaksi dokter untuk bulan jasa {{ $labelBulanJasa }}. Periksa bulan yang dipilih (bulan jasa, bukan bulan gaji).</p>
            @else
                <div class="grid grid-cols-2 gap-3 mt-3 sm:grid-cols-4">
                    @foreach ([['Dokter', $hasil['jumlah'], null], ['Dokter baru', $hasil['baru'], 'success'], ['Dokter berubah', $hasil['berubah'], 'warning'], ['Sama', $hasil['sama'], null]] as [$judul, $angka, $warna])
                        <div class="px-3 py-2 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                            <p class="text-xs font-semibold uppercase text-muted">{{ $judul }}</p>
                            <p @class(['text-xl font-semibold tabular-nums', 'text-ink dark:text-gray-100' => !$warna || $angka === 0, 'text-fg-success-strong' => $warna === 'success' && $angka > 0, 'text-fg-warning' => $warna === 'warning' && $angka > 0])>{{ $angka }}</p>
                        </div>
                    @endforeach
                </div>
                {{-- Versi periode yang dibentuk Ambil ini --}}
                <div @class(['flex flex-wrap items-center gap-3 px-3 py-2 mt-3 text-sm rounded-xl', 'bg-warning-soft text-fg-warning' => $hasil['versiDibuat'] && !$hasil['versiLangsungDipakai'], 'bg-surface-soft text-body dark:bg-gray-800 dark:text-gray-300' => !$hasil['versiDibuat'] || $hasil['versiLangsungDipakai']])>
                    @if ($hasil['versiDibuat'] && $hasil['versiLangsungDipakai'])
                        <span><b>Versi {{ $hasil['versiPeriode'] }}</b> dibuat dan langsung dipakai untuk gaji (versi pertama bulan ini).</span>
                    @elseif ($hasil['versiDibuat'])
                        <span><b>Versi {{ $hasil['versiPeriode'] }}</b> dibuat &mdash; <b>belum dipakai</b>; gaji masih memakai versi {{ $hasil['versiDipakai'] }}. Pilih di daftar Versi di bawah.</span>
                    @else
                        <span>Tidak ada perubahan dibanding versi terakhir (versi {{ $hasil['versiPeriode'] }}) &mdash; tidak ada versi baru.</span>
                    @endif
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
                            <span>Slip dokter ini sudah Final dan angkanya berbeda di versi baru: {{ $daftarNama($hasil['final']) }}. Versi baru tidak bisa dipakai sebelum Buka Kunci di Buku.</span></li>
                    @endif
                    @if (($hasil['kapitaTanpaTransaksi'] ?? []) !== [])
                        <li class="flex gap-2"><x-badge variant="info" class="shrink-0">Info</x-badge>
                            <span>Ikut dihitung walau tanpa transaksi bulan ini (dokter per kapita &mdash; gaji pokok &amp; kapita dari Struktur Gaji): {{ $daftarNama($hasil['kapitaTanpaTransaksi']) }}.</span></li>
                    @endif
                    @foreach (array_slice($hasil['catatan'], 0, 10) as $catatan)
                        <li class="flex gap-2"><x-badge variant="gray" class="shrink-0">Catatan</x-badge><span>{{ $catatan }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- VERSI BULAN JASA — pilih yang dipakai untuk gaji (pola SIKEU) --}}
    @php $versiList = $this->versiPeriodeList; $versiDipakai = $versiList->firstWhere('dipakai', '1'); @endphp
    @if ($versiList->isNotEmpty())
        <div class="border bg-canvas border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
            <div class="px-4 pt-3">
                <h2 class="font-semibold text-ink dark:text-gray-100">Versi bulan jasa {{ $labelBulanJasa }}</h2>
                <p class="text-xs text-muted">Satu versi = keadaan seluruh dokter bulan ini. Tepat satu versi <b>dipakai</b> untuk gaji (Buku, Final, Terapkan ke slip gaji). Versi lain tetap tersimpan dan bisa dipakai kembali.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="ds-table">
                    <thead>
                        <tr><th>Versi</th><th>Dibuat</th><th>Asal</th><th class="text-right">Dokter</th><th class="text-right">Jasa</th><th class="text-right">Diterima</th><th class="text-right">vs dipakai</th><th>Status</th><th class="text-right">Aksi</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($versiList as $versi)
                            @php $selisihDipakai = $versiDipakai ? $versi->gaji_diterima - $versiDipakai->gaji_diterima : 0; @endphp
                            <tr wire:key="versi-{{ $versi->versi_id }}" @class(['bg-surface-strong/60 dark:bg-gray-800' => $versi->dipakai === '1'])>
                                <td class="font-mono font-semibold">v{{ $versi->versi }}</td>
                                <td class="font-mono text-xs whitespace-nowrap">{{ Carbon::parse($versi->created_at)->format('d/m/Y H:i') }}<span class="block font-sans text-muted">{{ $versi->dibuat_nama ?? '' }}</span></td>
                                <td class="text-sm">{{ GajiDokterVersiPeriode::LABEL_SUMBER[$versi->sumber] ?? $versi->sumber }}@if ($versi->keterangan)<span class="block text-xs text-muted">{{ $versi->keterangan }}</span>@endif</td>
                                <td class="font-mono text-right">{{ $versi->jumlah_dokter }}</td>
                                <td class="font-mono text-right tabular-nums">{{ \App\Support\GajiKaryawan::rupiah($versi->jasa_total) }}</td>
                                <td class="font-mono text-right tabular-nums">{{ \App\Support\GajiKaryawan::rupiah($versi->gaji_diterima) }}</td>
                                <td @class(['font-mono text-right tabular-nums', 'text-muted' => abs($selisihDipakai) < 0.5, 'text-fg-danger-strong font-semibold' => abs($selisihDipakai) >= 0.5])>
                                    {{ $versi->dipakai === '1' ? '—' : (abs($selisihDipakai) < 0.5 ? '0' : ($selisihDipakai > 0 ? '+' : '−') . \App\Support\GajiKaryawan::rupiah(abs($selisihDipakai))) }}
                                </td>
                                <td>
                                    @if ($versi->dipakai === '1')
                                        <x-badge variant="success">Dipakai untuk gaji</x-badge>
                                        @if ($versi->dipakai_at)<span class="block text-xs text-muted">sejak {{ Carbon::parse($versi->dipakai_at)->format('d/m/Y H:i') }}{{ $versi->dipakai_nama ? ' · ' . $versi->dipakai_nama : '' }}</span>@endif
                                    @elseif ($loop->first)
                                        <x-badge variant="warning">Terbaru · belum dipakai</x-badge>
                                    @else
                                        <span class="text-xs text-muted">tersimpan</span>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($versi->dipakai !== '1')
                                        <div class="inline-flex gap-2">
                                            <x-secondary-button type="button" wire:click="bandingkanVersi({{ $versi->versi_id }})" title="Bandingkan versi {{ $versi->versi }} dengan versi yang dipakai">Bandingkan</x-secondary-button>
                                            <x-confirm-button variant="primary" action="pakaiVersi({{ $versi->versi_id }})" title="Pakai versi {{ $versi->versi }}"
                                                message="Pakai versi {{ $versi->versi }} untuk gaji SELURUH dokter bulan jasa {{ $bulan }}? Buku, Final, dan Terapkan ke slip gaji akan memakai versi ini. Versi lain tetap tersimpan. Slip gaji karyawan yang sudah terisi baru berubah setelah Terapkan ulang." confirmText="Ya, pakai" cancelText="Batal">
                                                Pakai
                                            </x-confirm-button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- RIWAYAT --}}
    <div class="flex flex-col border bg-canvas border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="px-4 pt-3">
            <h2 class="font-semibold text-ink dark:text-gray-100">Riwayat ambil</h2>
            <p class="text-xs text-muted"><b>Baru</b> = dokter yang belum punya slip bulan itu. <b>Berubah</b> = slip dokter berbeda dari sebelumnya. <b>Versi</b> = versi bulan yang dibentuk Ambil itu (<i>sama</i> = tidak ada perubahan, tidak membentuk versi). <b>Sama</b> = tidak ada perubahan, hanya tanggal ambil diperbarui.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Bulan jasa</th>
                        <th>Versi</th>
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
                            <td class="font-mono">{{ isset($this->versiPerAmbil[$row->ambil_id]) ? 'v' . $this->versiPerAmbil[$row->ambil_id] : 'sama' }}</td>
                            <td>{{ $row->name ?? '–' }}</td>
                            <td class="font-mono text-right">{{ $row->jumlah_slip }}</td>
                            <td class="font-mono text-right">{{ $row->baru > 0 ? $row->baru : '–' }}</td>
                            <td class="font-mono text-right">@if ($row->berubah > 0)<x-badge variant="warning">{{ $row->berubah }}</x-badge>@else – @endif</td>
                            <td class="font-mono text-right">{{ $row->sama > 0 ? $row->sama : '–' }}</td>
                            <td class="font-mono text-right">{{ $row->lampiran_baris > 0 ? number_format($row->lampiran_baris, 0, ',', '.') : '–' }}</td>
                            <td class="text-xs text-muted">{{ $row->keterangan ?? '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-8 text-center text-muted">Belum pernah mengambil data. Pilih bulan jasa di atas lalu klik <b>Ambil dari SIRUS</b>.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
