<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\GajiDokterSirus;
use App\Support\GajiDokterHitung;
use App\Support\GajiDokterStruktur;
use App\Support\GajiKaryawan;

/**
 * Modal RINCIAN slip gaji dokter (SIRUS) — komponen terpisah dari daftar (pola gaji-dokter-rincian di SIRUS), dipasang
 * pembungkus gaji-dokter SEJAJAR tab supaya tidak ikut unmount saat ganti tab. Dibuka lewat event
 * 'keuangan.gaji-dokter.openRincianSlipDokter' (gd_id) dari Buku. Isi: kartu identitas, strip snapshot + pembanding
 * (versi lain / bulan lalu / hitungan mesin SIAP), blok Jasa & Tunjangan | Potongan | Tambahan dengan selisih, footer angka inti.
 * Snapshot SIRUS hanya baca; snapshot ber-sumber SIAP (tahap 3) punya baris manual + Hitung ulang.
 * Tombol Lampiran di footer → tutup modal ini lalu event 'keuangan.gaji-dokter.openLampiranPasienDokter' → modal gaji-dokter-lampiran (sejajar).
 */
new class extends Component {
    /** Header snapshot yang dibuka + pembanding (gd_id versi lain / bulan lalu / 'SIAP' = hitungan mesin SIAP, atau ''). */
    public ?int $rincianGdId = null;
    public string $bandingPilihan = '';

    /** Tahap 3 — entri baris manual (kasbon, bonus, …) untuk slip ber-sumber SIAP. */
    public array $manualBaru = ['jenis' => 'P', 'kode' => '', 'keterangan' => '', 'nilai' => ''];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    #[On('keuangan.gaji-dokter.openRincianSlipDokter')]
    public function openRincianSlipDokter(int $gdId): void
    {
        $this->rincianGdId = $gdId;
        $this->bandingPilihan = '';
        unset($this->headerSlipDokter, $this->pembanding, $this->barisRincianSlip);
        $this->bandingPilihan = array_key_first($this->pilihanPembanding()) ?? '';
        $this->dispatch('open-modal', name: 'gaji-dokter-rincian');
    }

    public function closeModal(): void
    {
        $this->rincianGdId = null;
        $this->bandingPilihan = '';
        unset($this->headerSlipDokter, $this->pembanding, $this->barisRincianSlip);
        $this->dispatch('close-modal', name: 'gaji-dokter-rincian');
    }

    public function openLampiranPasienDokter(int $gdId): void
    {
        $this->closeModal();
        $this->dispatch('keuangan.gaji-dokter.openLampiranPasienDokter', gdId: $gdId);
    }

    public static function namaPeriode(?string $periode): string
    {
        return $periode && preg_match('/^(\d{4})-(\d{2})$/', $periode, $bagianPeriode)
            ? ((['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'][$bagianPeriode[2]] ?? $bagianPeriode[2]) . ' ' . $bagianPeriode[1])
            : (string) $periode;
    }

    #[Computed]
    public function headerSlipDokter(): ?object
    {
        return $this->rincianGdId ? DB::table('siaptxn_gdok_hdrs')->where('gd_id', $this->rincianGdId)->first() : null;
    }

    /** Pilihan pembanding: versi lain dokter yang sama bulan ini + versi terbaru bulan lalu. [kunci => label] */
    public function pilihanPembanding(): array
    {
        $header = $this->headerSlipDokter;
        if (!$header) {
            return [];
        }
        $pilihanPembanding = [];
        foreach (GajiDokterSirus::versiList($header->periode, $header->dr_id) as $versi) {
            if ((int) $versi->gd_id !== (int) $header->gd_id) {
                $pilihanPembanding[(string) $versi->gd_id] = "Versi {$versi->versi} (diambil " . Carbon::parse($versi->diambil_at)->format('d/m/Y H:i') . ')';
            }
        }
        $bulanLalu = Carbon::createFromFormat('!Y-m', $header->periode)->subMonthNoOverflow()->format('Y-m');
        $headerBulanLalu = DB::table('siaptxn_gdok_hdrs')->where('periode', $bulanLalu)->where('dr_id', $header->dr_id)->where('terbaru', '1')->first();
        if ($headerBulanLalu) {
            $pilihanPembanding[(string) $headerBulanLalu->gd_id] = 'Bulan jasa ' . Carbon::createFromFormat('!Y-m', $bulanLalu)->translatedFormat('M Y') . " (versi {$headerBulanLalu->versi})";
        }
        // Tahap 2: pembanding maya = hitungan mesin SIAP atas lampiran snapshot ini + struktur SIAP (GajiDokterSirus::ujiHitungSnapshot).
        if (($header->sumber ?? 'SIRUS') === 'SIRUS') {
            $pilihanPembanding['SIAP'] = 'Hitungan mesin SIAP (lampiran snapshot ini + struktur SIAP)';
        }

        return $pilihanPembanding;
    }

    #[Computed]
    public function pembanding(): ?object
    {
        if ($this->bandingPilihan === '' || !isset($this->pilihanPembanding()[$this->bandingPilihan])) {
            return null;
        }
        if ($this->bandingPilihan === 'SIAP') {
            $uji = GajiDokterSirus::ujiHitungSnapshot((int) $this->rincianGdId);

            return $uji ? (object) [...$uji['hasil']['ringkas'], 'gd_id' => 0, 'periode' => $this->headerSlipDokter->periode, 'versi' => 'SIAP', 'label_pembanding' => 'hitungan SIAP',
                'struktur_asal' => $uji['strukturAsal'], 'cocok' => $uji['banding']['cocok']] : null;
        }

        return DB::table('siaptxn_gdok_hdrs')->where('gd_id', (int) $this->bandingPilihan)->first();
    }

    /** Detail pembanding: dari snapshot (gd_id) atau dari hasil hitung mesin SIAP (pembanding maya). */
    private function detailPembanding()
    {
        if (!$this->pembanding) {
            return collect();
        }
        if ($this->bandingPilihan === 'SIAP') {
            $uji = GajiDokterSirus::ujiHitungSnapshot((int) $this->rincianGdId);

            return collect($uji['hasil']['detail'] ?? [])->map(fn($komponen) => (object) $komponen);
        }

        return GajiDokterSirus::detail((int) $this->pembanding->gd_id);
    }

    public function updatedBandingPilihan(): void { unset($this->pembanding, $this->barisRincianSlip); }

    // ─── Tahap 3: baris manual & hitung ulang untuk slip ber-sumber SIAP ──────

    #[Computed]
    public function manualList()
    {
        $header = $this->headerSlipDokter;

        return $header ? GajiDokterSirus::manualList($header->periode, $header->dr_id) : collect();
    }

    public function tambahManual(): void
    {
        $header = $this->headerSlipDokter;
        if (!$header || $header->sumber !== 'SIAP' || $header->gaji_status === 'F') {
            $this->dispatch('toast', type: 'error', message: 'Baris manual hanya untuk slip Draft yang dihitung SIAP.');
            return;
        }
        $jenis = $this->manualBaru['jenis'] === 'T' ? 'T' : 'P';
        $kodeBoleh = $jenis === 'T' ? GajiDokterStruktur::KODE_TAMBAHAN : GajiDokterStruktur::KODE_POTONGAN;
        $nilai = (float) str_replace(['.', ','], ['', '.'], (string) $this->manualBaru['nilai']);
        if (!in_array($this->manualBaru['kode'], $kodeBoleh, true)) {
            $this->dispatch('toast', type: 'error', message: 'Pilih kode baris dulu.');
            return;
        }
        if ($nilai <= 0) {
            $this->dispatch('toast', type: 'error', message: 'Nilai harus angka positif (tanda minus diurus otomatis).');
            return;
        }
        DB::table('siaptxn_gdok_manuals')->insert(['periode' => $header->periode, 'dr_id' => $header->dr_id, 'jenis' => $jenis, 'kode' => $this->manualBaru['kode'],
            'keterangan' => mb_substr(trim((string) $this->manualBaru['keterangan']), 0, 100) ?: null, 'nilai' => $nilai, 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $this->manualBaru = ['jenis' => $jenis, 'kode' => '', 'keterangan' => '', 'nilai' => ''];
        $this->hitungUlangSiap();
    }

    public function hapusManual(int $manualId): void
    {
        $header = $this->headerSlipDokter;
        if (!$header || $header->gaji_status === 'F') {
            $this->dispatch('toast', type: 'error', message: 'Slip sudah Final — buka kunci dulu.');
            return;
        }
        DB::table('siaptxn_gdok_manuals')->where('manual_id', $manualId)->where('periode', $header->periode)->where('dr_id', $header->dr_id)->delete();
        $this->hitungUlangSiap();
    }

    /** Hitung ulang slip SIAP ini dari lampiran snapshot + struktur + baris manual; bila berubah, modal berpindah ke versi baru. */
    public function hitungUlangSiap(): void
    {
        $header = $this->headerSlipDokter;
        if (!$header || $header->sumber !== 'SIAP') {
            return;
        }
        $hasilHitungUlang = GajiDokterSirus::hitungUlangSiap((int) $header->gd_id, auth()->id());
        if ($hasilHitungUlang['pesan'] !== null) {
            $this->dispatch('toast', type: 'error', message: $hasilHitungUlang['pesan']);
            return;
        }
        $this->rincianGdId = $hasilHitungUlang['gd_id'];
        $this->bandingPilihan = '';
        unset($this->headerSlipDokter, $this->pembanding, $this->barisRincianSlip, $this->manualList);
        $this->dispatch('toast', type: 'success', message: $hasilHitungUlang['berubah'] ? 'Slip dihitung ulang — versi baru dibuat.' : 'Angka tidak berubah.');
        $this->dispatch('keuangan.gaji-dokter.slipBerubah');
    }

    /** Baris rincian gabungan: tiap komponen versi ini + komponen pembanding yang hilang. */
    #[Computed]
    public function barisRincianSlip(): array
    {
        $header = $this->headerSlipDokter;
        if (!$header) {
            return [];
        }
        $detailIni = GajiDokterSirus::detail((int) $header->gd_id)->keyBy(fn($komponen) => $komponen->jenis . '|' . $komponen->kode);
        $detailLain = $this->detailPembanding()->keyBy(fn($komponen) => $komponen->jenis . '|' . $komponen->kode);
        $barisRincian = [];
        foreach ($detailIni->keys()->merge($detailLain->keys())->unique() as $kunciKomponen) {
            $komponenIni = $detailIni[$kunciKomponen] ?? null;
            $komponenLain = $detailLain[$kunciKomponen] ?? null;
            $barisRincian[] = ['jenis' => ($komponenIni ?? $komponenLain)->jenis, 'label' => ($komponenIni ?? $komponenLain)->label ?: ($komponenIni ?? $komponenLain)->kode, 'kode' => ($komponenIni ?? $komponenLain)->kode,
                'pasien' => $komponenIni ? (int) $komponenIni->jumlah_pasien : null, 'nilai' => $komponenIni ? (float) $komponenIni->nilai : null, 'manual' => $komponenIni && $komponenIni->nilai_manual === '1',
                'pasien_lain' => $komponenLain ? (int) $komponenLain->jumlah_pasien : null, 'nilai_lain' => $komponenLain ? (float) $komponenLain->nilai : null,
                'urutan' => (int) (($komponenIni ?? $komponenLain)->urutan)];
        }
        usort($barisRincian, fn($kiri, $kanan) => [$kiri['jenis'], $kiri['urutan']] <=> [$kanan['jenis'], $kanan['urutan']]);

        return $barisRincian;
    }
};
?>

<div>
    {{-- Pola Rincian Slip Gaji SIRUS: kartu identitas 5 kolom → strip snapshot & pembanding →
         blok Jasa & Tunjangan | Potongan | Tambahan (tabel kartu + subtotal) → footer angka inti. Hanya baca. --}}
    <x-modal name="gaji-dokter-rincian" size="full" height="full">
        @php
            $rupiah = fn($nilai) => GajiKaryawan::rupiah($nilai);
            $formatPersen = fn($nilai) => rtrim(rtrim(number_format((float) $nilai, 2, ',', '.'), '0'), ',');
            $formatSelisih = fn($nilai) => $nilai === null ? '–' : ($nilai > 0 ? '+' . $rupiah($nilai) : ($nilai < 0 ? '−' . $rupiah(abs($nilai)) : '0'));
            $header = $this->headerSlipDokter;
            $pembanding = $this->pembanding;
        @endphp
        <div class="flex flex-col min-h-[calc(100vh-8rem)]">
            <x-modul-dokumen.header judul="Rincian Slip Gaji" tutup="closeModal"
                ikon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
                @if ($header){{ $header->dr_name }} · {{ ($header->sumber ?? 'SIRUS') === 'SIAP' ? 'dihitung SIAP, versi ' . $header->versi : 'snapshot versi ' . $header->versi . ' dari SIRUS, hanya baca' }} @endif
            </x-modul-dokumen.header>

            @if ($header)
                @php
                    $labelPembanding = $pembanding ? ($pembanding->label_pembanding ?? ($pembanding->periode === $header->periode ? 'Versi ' . $pembanding->versi : $this::namaPeriode($pembanding->periode))) : null;
                    $blokPerJenis = [
                        'J' => ['judul' => 'Jasa & Tunjangan', 'catatan' => 'Membentuk total gaji — kena PPh.'],
                        'P' => ['judul' => 'Potongan', 'catatan' => 'Dua baris teratas (Potongan RS & PPh 21) berasal dari rumus dan ikut menentukan pajak; sisanya di bawah garis dipotong setelah pajak.'],
                        'T' => ['judul' => 'Tambahan', 'catatan' => 'Diberikan setelah pajak (bonus, rapel, koreksi bulan lalu).'],
                    ];
                    $perJenis = collect($this->barisRincianSlip)->groupBy('jenis');
                @endphp
                <div class="flex-1 px-4 pt-2 pb-4 bg-surface-soft dark:bg-gray-950/20">
                    <div class="space-y-4">

                        {{-- KARTU IDENTITAS DOKTER: kiri (3) identitas & parameter, kanan (2) periode & angka, dipisah garis vertikal. --}}
                        <div class="px-4 py-2.5 text-base border rounded-lg border-hairline bg-canvas dark:bg-gray-900 dark:border-gray-700">
                            <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-5">
                                <div class="space-y-1.5 sm:col-span-3 sm:border-r sm:border-hairline dark:sm:border-gray-700 sm:pr-4">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-xl font-bold truncate text-ink dark:text-white">{{ $header->dr_name }}</span>
                                        <span class="font-mono text-base text-muted dark:text-gray-400 shrink-0">{{ $header->dr_id }}</span>
                                    </div>
                                    <div class="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                                        <div class="space-y-1">
                                            <div><span class="text-muted">Karyawan SIAP:</span>
                                                <span class="ml-1 text-body dark:text-gray-300">
                                                    @php $pemetaanDokter = GajiDokterSirus::daftar($header->periode)->firstWhere('dr_id', $header->dr_id); @endphp
                                                    @if ($pemetaanDokter && $pemetaanDokter->emp_id_map){{ $pemetaanDokter->emp_name }} <span class="font-mono text-sm text-muted">{{ $pemetaanDokter->emp_id_map }}</span>@else<x-badge variant="warning">belum dipetakan</x-badge>@endif
                                                </span>
                                            </div>
                                            <div><span class="text-muted">Skema:</span>
                                                <span class="ml-1 text-body dark:text-gray-300"><span class="font-mono">{{ $header->skema_gaji_pokok }}</span> &mdash; {{ match ($header->skema_gaji_pokok) { 'G' => 'garanty fee', 'N' => 'tanpa gaji pokok', default => 'aditif' } }}</span>
                                            </div>
                                            <div><span class="text-muted">Gaji Pokok:</span> <span class="ml-1 t-num text-body dark:text-gray-300">{{ $rupiah($header->nilai_gaji_pokok) }}</span></div>
                                        </div>
                                        <div class="space-y-1">
                                            <div><span class="text-muted">Potongan RS:</span>
                                                <span class="ml-1 text-body dark:text-gray-300"><span class="font-mono">{{ $header->potongan_rs_basis }}</span>
                                                    @if (in_array($header->potongan_rs_basis, ['T', 'J'], true)) &middot; <span class="t-num">{{ $formatPersen($header->potongan_rs_persen) }}%</span> dari {{ $header->potongan_rs_basis === 'J' ? 'jasa' : 'total gaji' }}
                                                    @elseif ($header->potongan_rs_basis === 'B') &middot; berjenjang per komponen
                                                    @else &middot; tidak dipotong @endif
                                                </span>
                                            </div>
                                            <div><span class="text-muted">PPh 21:</span> <span class="ml-1 t-num text-body dark:text-gray-300">{{ $formatPersen($header->pph21_persen) }}% &middot; {{ $header->npwp_status === 'Y' ? 'NPWP' : 'tanpa NPWP' }}</span></div>
                                            <div class="font-mono text-sm text-muted dark:text-gray-400">{{ (int) $header->gajidoctor_no > 0 ? 'Slip #' . $header->gajidoctor_no : 'Slip SIAP' }}@if ($header->tanggal_bayar) &middot; dibayar {{ \Carbon\Carbon::parse($header->tanggal_bayar)->format('d/m/Y') }}@endif</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-1.5 sm:col-span-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-muted">{{ ($header->sumber ?? 'SIRUS') === 'SIAP' ? 'Status:' : 'Status di SIRUS:' }}</span>
                                        <x-badge :variant="$header->gaji_status === 'F' ? 'success' : 'warning'">{{ $header->gaji_status === 'F' ? 'Final' : 'Draft' }}</x-badge>
                                    </div>
                                    <div class="flex items-start justify-between gap-2 text-lg">
                                        <span class="font-semibold text-ink dark:text-white">Jasa {{ \Carbon\Carbon::createFromFormat('!Y-m', $header->periode)->format('m/Y') }}</span>
                                        <span class="font-semibold text-right text-brand-green dark:text-brand-lime">Gaji {{ $header->periode_gaji ? \Carbon\Carbon::createFromFormat('!Y-m', $header->periode_gaji)->format('m/Y') : '–' }}</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-2"><span class="text-muted">Total Gaji:</span> <span class="font-semibold t-num text-ink dark:text-gray-100">{{ $rupiah($header->total_gaji) }}</span></div>
                                    <div class="flex items-center justify-between gap-2"><span class="text-muted">Gaji Diterima:</span> <span class="text-xl font-bold t-num text-emerald-700 dark:text-emerald-300">{{ $rupiah($header->gaji_diterima) }}</span></div>
                                </div>
                            </div>
                        </div>

                        {{-- STRIP SNAPSHOT & PEMBANDING (khas SIAP; menggantikan strip "terkunci" di SIRUS) --}}
                        <div class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm border rounded-xl bg-emerald-50 border-emerald-200 text-emerald-900 dark:bg-emerald-900/20 dark:border-emerald-800 dark:text-emerald-200">
                            <span>
                                @if (($header->sumber ?? 'SIRUS') === 'SIAP')
                                    <span class="font-semibold">Dihitung SIAP</span> · versi {{ $header->versi }}{{ $header->terbaru === '1' ? ' (terbaru)' : '' }} · diproses {{ \Carbon\Carbon::parse($header->diambil_at)->format('d/m/Y H:i') }}. Komponen jasa dari transaksi SIRUS; potongan RS, PPh 21, tunjangan &amp; potongan rutin dari tab Struktur Gaji; baris manual di bawah.
                                @else
                                    Snapshot <span class="font-semibold">versi {{ $header->versi }}</span>{{ $header->terbaru === '1' ? ' (terbaru)' : '' }} diambil {{ \Carbon\Carbon::parse($header->diambil_at)->format('d/m/Y H:i') }}@if ($header->update_date_sirus) &middot; terakhir diubah di SIRUS {{ $header->update_date_sirus }}@endif. Angka tidak bisa disunting di SIAP; bila SIRUS berubah, Ambil ulang membuat versi baru.
                                @endif
                                @if ($pembanding && isset($pembanding->cocok))
                                    <x-badge :variant="$pembanding->cocok ? 'success' : 'warning'">mesin SIAP {{ $pembanding->cocok ? 'cocok' : 'ada selisih' }} · struktur {{ $pembanding->struktur_asal === 'struktur' ? 'tab Struktur Gaji' : 'direka dari snapshot' }}</x-badge>
                                @endif
                            </span>
                            <label class="flex items-center gap-2 ml-auto shrink-0">
                                <span class="font-semibold whitespace-nowrap">Bandingkan dengan</span>
                                <div class="w-64">
                                    <x-select-input id="gd-banding" wire:model.live="bandingPilihan" aria-label="Bandingkan dengan">
                                        <option value="">– tanpa pembanding –</option>
                                        @foreach ($this->pilihanPembanding() as $kunciPilihan => $labelPilihan)
                                            <option value="{{ $kunciPilihan }}">{{ $labelPilihan }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                            </label>
                        </div>

                        {{-- BLOK PER JENIS: J | P berdampingan, T merentang dua kolom. --}}
                        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                            @foreach ($blokPerJenis as $jenis => $infoBlok)
                                @php $rowsJenis = $perJenis->get($jenis, collect()); $subtotal = (float) $rowsJenis->sum('nilai'); $subtotalPembanding = $pembanding ? (float) $rowsJenis->sum('nilai_lain') : null; $sudahDipisah = false; @endphp
                                <div class="{{ $jenis === 'T' ? 'xl:col-span-2' : '' }}">
                                    <x-border-form :title="$infoBlok['judul']">
                                        <div class="space-y-3">
                                            <p class="text-sm text-muted dark:text-gray-400">{{ $infoBlok['catatan'] }}</p>
                                            <div class="overflow-x-auto overflow-y-auto max-h-[26rem] rounded-2xl">
                                                <table class="w-full min-w-full -mt-2 text-sm border-separate border-spacing-y-2 table-fixed">
                                                    <thead class="sticky top-0 z-10">
                                                        <tr class="text-sm font-semibold tracking-wide text-left uppercase text-muted dark:text-gray-300">
                                                            <th class="px-4 py-3 {{ $pembanding ? 'w-[36%]' : 'w-[52%]' }} bg-surface-card dark:bg-gray-800">Keterangan</th>
                                                            <th class="px-3 py-3 w-[16%] text-right bg-surface-card dark:bg-gray-800">Jml Pasien</th>
                                                            <th class="px-3 py-3 {{ $pembanding ? 'w-[18%]' : 'w-[32%]' }} text-right bg-surface-card dark:bg-gray-800">Nilai</th>
                                                            @if ($pembanding)
                                                                <th class="px-3 py-3 w-[16%] text-right bg-surface-card dark:bg-gray-800">{{ $labelPembanding }}</th>
                                                                <th class="px-3 py-3 w-[14%] text-right bg-surface-card dark:bg-gray-800">Selisih</th>
                                                            @endif
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @forelse ($rowsJenis as $row)
                                                            @php
                                                                $dariRumus = $jenis === 'P' && in_array($row['kode'], ['RS', 'PPH21'], true);
                                                                $bedaDenganPembanding = $pembanding && (($row['nilai'] ?? 0) != ($row['nilai_lain'] ?? 0) || ($row['pasien'] ?? 0) != ($row['pasien_lain'] ?? 0));
                                                            @endphp
                                                            @if ($jenis === 'P' && !$dariRumus && !$sudahDipisah)
                                                                @php $sudahDipisah = true; @endphp
                                                                <tr><td colspan="{{ $pembanding ? 5 : 3 }}" class="px-4 pt-3 pb-1 text-sm text-muted dark:text-gray-400">&darr; dipotong setelah pajak &mdash; tidak mengurangi dasar PPh</td></tr>
                                                            @endif
                                                            <tr wire:key="rinci-{{ $jenis }}-{{ $row['kode'] }}"
                                                                class="transition rounded-2xl shadow-sm ring-1 ring-hairline dark:ring-gray-700 {{ $bedaDenganPembanding ? 'bg-warning/10 dark:bg-amber-900/15' : ($dariRumus ? 'bg-surface-soft dark:bg-gray-800' : 'bg-canvas dark:bg-gray-900 hover:bg-surface-soft dark:hover:bg-gray-800') }}">
                                                                <td class="px-4 py-2 align-middle rounded-l-2xl" title="Kode: {{ $row['kode'] }}">
                                                                    {{ $row['label'] }}
                                                                    @if ($row['manual'])<x-badge variant="info">manual</x-badge>@elseif ($dariRumus)<span class="ml-1 text-sm text-muted dark:text-gray-400">(dari rumus)</span>@endif
                                                                    @if ($row['nilai'] === null)<x-badge variant="gray">tidak ada di versi ini</x-badge>@endif
                                                                </td>
                                                                <td class="px-3 py-2 text-right align-middle tabular-nums">{{ $row['pasien'] ?: '' }}@if ($bedaDenganPembanding && ($row['pasien_lain'] ?? null) !== ($row['pasien'] ?? null))<span class="text-xs text-muted"> ({{ $row['pasien_lain'] ?? 0 }})</span>@endif</td>
                                                                <td class="px-3 py-2 text-right align-middle tabular-nums {{ ($row['nilai'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-300' : '' }} {{ $pembanding ? '' : 'rounded-r-2xl' }}">{{ $row['nilai'] === null ? '–' : $rupiah($row['nilai']) }}</td>
                                                                @if ($pembanding)
                                                                    <td class="px-3 py-2 text-right align-middle tabular-nums text-muted dark:text-gray-400">{{ $row['nilai_lain'] === null ? '–' : $rupiah($row['nilai_lain']) }}</td>
                                                                    <td class="px-3 py-2 text-xs text-right align-middle tabular-nums rounded-r-2xl {{ $bedaDenganPembanding ? 'font-semibold text-amber-800 dark:text-amber-300' : 'text-muted' }}">{{ $bedaDenganPembanding ? $formatSelisih(($row['nilai'] ?? 0) - ($row['nilai_lain'] ?? 0)) : '=' }}</td>
                                                                @endif
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="{{ $pembanding ? 5 : 3 }}" class="px-4 py-6 text-center text-muted dark:text-gray-400">Tidak ada baris.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="rounded-2xl ring-1 ring-hairline bg-surface-card dark:bg-gray-800 dark:ring-gray-700">
                                                            <td class="px-4 py-3 text-sm font-semibold tracking-wide uppercase rounded-l-2xl text-muted dark:text-gray-300" colspan="2">Subtotal</td>
                                                            <td class="px-3 py-3 text-lg font-bold text-right tabular-nums {{ $pembanding ? '' : 'rounded-r-2xl' }} {{ $subtotal < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-ink dark:text-gray-100' }}">{{ $rupiah($subtotal) }}</td>
                                                            @if ($pembanding)
                                                                <td class="px-3 py-3 text-right tabular-nums text-muted dark:text-gray-400">{{ $rupiah($subtotalPembanding) }}</td>
                                                                <td class="px-3 py-3 text-xs text-right tabular-nums rounded-r-2xl {{ abs($subtotal - $subtotalPembanding) < 0.5 ? 'text-muted' : 'font-semibold text-amber-800 dark:text-amber-300' }}">{{ abs($subtotal - $subtotalPembanding) < 0.5 ? '=' : $formatSelisih($subtotal - $subtotalPembanding) }}</td>
                                                            @endif
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </x-border-form>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- TAHAP 3: BARIS MANUAL (slip ber-sumber SIAP) — kasbon, bonus, rapel, koreksi; ikut dihitung ulang. --}}
                @if (($header->sumber ?? 'SIRUS') === 'SIAP')
                    <div class="px-4 pb-4 bg-surface-soft dark:bg-gray-950/20">
                        <x-border-form title="Baris manual (potongan &amp; tambahan)">
                            <p class="mb-2 text-sm text-muted dark:text-gray-400">Kasbon, iuran insidental, bonus, rapel, koreksi bulan lalu. Ketik nilai positif — potongan otomatis minus. Tiap perubahan menghitung ulang slip (versi baru).{{ $header->gaji_status === 'F' ? ' Slip sudah Final: buka kunci di Buku untuk mengubah.' : '' }}</p>
                            <table class="w-full text-sm">
                                <tbody>
                                    @forelse ($this->manualList as $manual)
                                        <tr wire:key="manual-{{ $manual->manual_id }}" class="border-t border-hairline dark:border-gray-700">
                                            <td class="py-1.5"><x-badge :variant="$manual->jenis === 'T' ? 'success' : 'warning'">{{ $manual->jenis === 'T' ? 'Tambahan' : 'Potongan' }}</x-badge> {{ GajiDokterStruktur::labelKomponen($manual->kode) }}@if ($manual->keterangan) <span class="text-muted">— {{ $manual->keterangan }}</span>@endif</td>
                                            <td class="py-1.5 font-mono text-right tabular-nums {{ $manual->jenis === 'T' ? '' : 'text-rose-600 dark:text-rose-300' }}">{{ $manual->jenis === 'T' ? '' : '-' }}{{ $rupiah($manual->nilai) }}</td>
                                            <td class="py-1.5 text-right">@if ($header->gaji_status !== 'F')<x-hapus-button action="hapusManual({{ $manual->manual_id }})" title="Hapus baris" message="Hapus baris {{ GajiDokterStruktur::labelKomponen($manual->kode) }}? Slip dihitung ulang." />@endif</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="py-2 text-xs italic text-muted">Belum ada baris manual.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                            @if ($header->gaji_status !== 'F')
                                <div class="grid items-end grid-cols-12 gap-2 pt-3 mt-2 border-t border-hairline dark:border-gray-700">
                                    <div class="col-span-2"><x-input-label for="gdm-jenis" value="Jenis" /><div class="mt-1"><x-select-input id="gdm-jenis" wire:model.live="manualBaru.jenis"><option value="P">Potongan</option><option value="T">Tambahan</option></x-select-input></div></div>
                                    <div class="col-span-3"><x-input-label for="gdm-kode" value="Kode" /><div class="mt-1"><x-select-input id="gdm-kode" wire:model="manualBaru.kode"><option value="">— pilih —</option>@foreach (($manualBaru['jenis'] ?? 'P') === 'T' ? GajiDokterStruktur::KODE_TAMBAHAN : GajiDokterStruktur::KODE_POTONGAN as $kodeManual)<option value="{{ $kodeManual }}">{{ GajiDokterStruktur::labelKomponen($kodeManual) }}</option>@endforeach</x-select-input></div></div>
                                    <div class="col-span-4"><x-input-label for="gdm-ket" value="Keterangan" /><x-text-input id="gdm-ket" type="text" wire:model="manualBaru.keterangan" maxlength="100" placeholder="mis. Kasbon 12 Juli" class="block w-full mt-1" /></div>
                                    <div class="col-span-2"><x-input-label for="gdm-nilai" value="Nilai (Rp)" /><div class="mt-1"><x-text-input-number id="gdm-nilai" wire:model="manualBaru.nilai" /></div></div>
                                    <div class="col-span-1"><x-primary-button type="button" wire:click="tambahManual" wire:loading.attr="disabled" wire:target="tambahManual" class="!px-3" title="Tambah baris & hitung ulang" aria-label="Tambah baris">+</x-primary-button></div>
                                </div>
                            @endif
                        </x-border-form>
                    </div>
                @endif

                {{-- FOOTER: angka inti + aksi. Gaji Diterima paling besar — itu yang benar-benar dibayarkan. --}}
                <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="grid flex-1 grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-4">
                            @foreach (['total_gaji' => ['Total Gaji', 'text-ink dark:text-gray-100'], 'potongan_rs' => ['Potongan RS', 'text-rose-600 dark:text-rose-300'], 'pph21' => ['PPh 21', 'text-rose-600 dark:text-rose-300']] as $kolomNominal => [$labelPilihan, $warnaNilai])
                                <div>
                                    <div class="text-sm tracking-wide uppercase text-muted dark:text-gray-400">{{ $labelPilihan }}</div>
                                    <div class="text-lg font-semibold tabular-nums {{ $warnaNilai }}">{{ $rupiah($header->$kolomNominal) }}@if ($pembanding && abs((float) $header->$kolomNominal - (float) $pembanding->$kolomNominal) >= 0.5)<span class="ml-1 text-xs font-normal text-amber-800 dark:text-amber-300">{{ $formatSelisih((float) $header->$kolomNominal - (float) $pembanding->$kolomNominal) }}</span>@endif</div>
                                </div>
                            @endforeach
                            <div>
                                <div class="text-sm tracking-wide uppercase text-muted dark:text-gray-400">Gaji Diterima</div>
                                <div class="text-2xl font-bold leading-tight tabular-nums text-emerald-700 dark:text-emerald-300">{{ $rupiah($header->gaji_diterima) }}@if ($pembanding && abs((float) $header->gaji_diterima - (float) $pembanding->gaji_diterima) >= 0.5)<span class="ml-1 text-xs font-normal text-amber-800 dark:text-amber-300">{{ $formatSelisih((float) $header->gaji_diterima - (float) $pembanding->gaji_diterima) }} vs {{ $labelPembanding }}</span>@endif</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
                            @if (($header->sumber ?? 'SIRUS') === 'SIAP' && $header->gaji_status !== 'F' && $header->terbaru === '1')
                                <x-secondary-button type="button" class="gap-2" wire:click="hitungUlangSiap" wire:loading.attr="disabled" wire:target="hitungUlangSiap" title="Hitung ulang dari lampiran snapshot ini + struktur gaji terbaru + baris manual">
                                    <span wire:loading.remove wire:target="hitungUlangSiap">Hitung ulang</span>
                                    <span wire:loading wire:target="hitungUlangSiap"><x-loading /> Menghitung…</span>
                                </x-secondary-button>
                            @endif
                            <x-outline-button type="button" class="gap-2" wire:click="openLampiranPasienDokter({{ $header->gd_id }})" wire:loading.attr="disabled" wire:target="openLampiranPasienDokter" title="Lihat lampiran rincian pasien (tanggal layanan, no. RM, nama, nominal)">
                                <span wire:loading.remove wire:target="openLampiranPasienDokter" class="inline-flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" /></svg>
                                    Lampiran
                                </span>
                                <span wire:loading wire:target="openLampiranPasienDokter" class="inline-flex items-center gap-2"><x-loading /> Menyiapkan...</span>
                            </x-outline-button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </x-modal>
</div>
