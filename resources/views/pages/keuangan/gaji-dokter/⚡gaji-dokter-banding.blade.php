<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiDokterBandingVersi;
use App\Support\GajiDokterSirus;

/**
 * Tab BANDINGKAN VERSI Gaji Dokter (anak pembungkus gaji-dokter; pola SIKEU "Bandingkan Versi").
 * Pilih bulan jasa → dokter (berversi > 1 dulu) → versi lama & versi baru → yang berbeda: pengaturan slip, angka slip,
 * komponen, dan baris lampiran pasien (baru / hilang / berubah). Logika di App\Support\GajiDokterBandingVersi.
 * Hanya baca; bulan jasa berbagi Session dengan tab Buku. Pilihan dokter/versi di Session (bukan URL) supaya tidak tertinggal di alamat tab lain.
 */
new class extends Component {
    #[Session(key: 'gaji-dokter-bulan')]
    public string $bulan = ''; // mm/yyyy = bulan JASA

    #[Session(key: 'gaji-dokter-banding-cari')]
    public string $searchKeyword = '';

    #[Session(key: 'gaji-dokter-banding-tampilkan')]
    public string $tampilkan = 'berversi'; // berversi | semua

    #[Session(key: 'gaji-dokter-banding-dokter')]
    public string $drId = '';

    #[Session(key: 'gaji-dokter-banding-lama')]
    public ?int $versiLama = null;

    #[Session(key: 'gaji-dokter-banding-baru')]
    public ?int $versiBaru = null;

    /** semua | baru | hilang | berubah */
    public string $filterJenis = 'semua';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!$this->bulanValid()) {
            $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        }
        if (!in_array($this->tampilkan, ['berversi', 'semua'], true)) {
            $this->tampilkan = 'berversi';
        }
        $this->pilihDokterBawaan();
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

    public function updatedBulan(): void { $this->gantiDaftar(); }
    public function updatedSearchKeyword(): void { unset($this->dokterList); }
    public function updatedTampilkan(): void { $this->gantiDaftar(); }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        $this->gantiDaftar();
    }

    private function gantiDaftar(): void
    {
        unset($this->dokterList, $this->versiList, $this->hasil);
        $this->pilihDokterBawaan();
    }

    /** Dokter terpilih tetap bila masih ada di bulan ini; kalau tidak, dokter pertama (berversi > 1 didahulukan). */
    private function pilihDokterBawaan(): void
    {
        $daftar = GajiDokterBandingVersi::dokterList($this->periode(), '', $this->tampilkan !== 'semua');
        if (!$daftar->contains('dr_id', $this->drId)) {
            $this->drId = (string) ($daftar->first()?->dr_id ?? '');
            $this->versiLama = null;
            $this->versiBaru = null;
        }
        $this->pilihVersiBawaan();
    }

    /** Bawaan: baru = versi terbaru, lama = versi tepat sebelumnya. */
    private function pilihVersiBawaan(): void
    {
        unset($this->versiList, $this->hasil);
        $idList = $this->versiList->pluck('gd_id')->map(fn($gdId) => (int) $gdId)->all();
        if (!in_array($this->versiBaru, $idList, true)) {
            $this->versiBaru = $idList[0] ?? null;
        }
        if (!in_array($this->versiLama, $idList, true) || $this->versiLama === $this->versiBaru) {
            $this->versiLama = collect($idList)->first(fn($gdId) => $gdId !== $this->versiBaru);
        }
        $this->filterJenis = 'semua';
    }

    public function pilihDokter(string $drId): void
    {
        $this->drId = $drId;
        $this->versiLama = null;
        $this->versiBaru = null;
        $this->pilihVersiBawaan();
    }

    public function updatedVersiLama(): void { unset($this->hasil); $this->filterJenis = 'semua'; }
    public function updatedVersiBaru(): void { unset($this->hasil); $this->filterJenis = 'semua'; }

    public function tukar(): void
    {
        [$this->versiLama, $this->versiBaru] = [$this->versiBaru, $this->versiLama];
        unset($this->hasil);
    }

    public function setFilterJenis(string $jenis): void
    {
        $this->filterJenis = in_array($jenis, ['semua', 'baru', 'hilang', 'berubah'], true) ? $jenis : 'semua';
    }

    public function bukaRincian(): void
    {
        if ($this->versiBaru) {
            $this->dispatch('keuangan.gaji-dokter.openRincianSlipDokter', gdId: (int) $this->versiBaru);
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword']);
        $this->tampilkan = 'berversi';
        $this->gantiDaftar();
    }

    #[Computed]
    public function dokterList()
    {
        return GajiDokterBandingVersi::dokterList($this->periode(), $this->searchKeyword, $this->tampilkan !== 'semua');
    }

    #[Computed]
    public function versiList()
    {
        return $this->drId === '' ? collect() : GajiDokterSirus::versiList($this->periode(), $this->drId);
    }

    #[Computed]
    public function hasil(): ?array
    {
        if (!$this->versiLama || !$this->versiBaru || $this->versiLama === $this->versiBaru) {
            return null;
        }

        return GajiDokterBandingVersi::bandingkan((int) $this->versiLama, (int) $this->versiBaru);
    }
};
?>

<div class="mt-4">
    @php
        $rupiah = fn($nilai) => $nilai === null ? '—' : \App\Support\GajiKaryawan::rupiah($nilai);
        $formatSelisih = fn($nilai) => $nilai > 0 ? '+' . $rupiah($nilai) : ($nilai < 0 ? '−' . $rupiah(abs($nilai)) : '0');
        $labelVersi = fn($versi) => 'v' . $versi->versi . ' · ' . ($versi->created_at ? Carbon::parse($versi->created_at)->format('d/m/Y H:i') : '–') . ' · ' . ($versi->sumber ?? 'SIRUS')
            . ($versi->terbaru === '1' ? ' · terbaru' : '');
        $nilaiPengaturan = fn($kolom, $nilai) => match (true) {
            $nilai === null || $nilai === '' => '—',
            in_array($kolom, ['nilai_gaji_pokok'], true) => $rupiah($nilai),
            in_array($kolom, ['potongan_rs_persen', 'pph21_persen'], true) => rtrim(rtrim(number_format((float) $nilai, 2, ',', '.'), '0'), ',') . '%',
            $kolom === 'gaji_status' => ['D' => 'Draft', 'F' => 'Final'][$nilai] ?? $nilai,
            $kolom === 'npwp_status' => ['Y' => 'Ada', 'T' => 'Tidak ada'][$nilai] ?? $nilai,
            default => (string) $nilai,
        };
        $dokterList = $this->dokterList;
    @endphp

    {{-- TOOLBAR --}}
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
            <x-input-label for="gdb-cari" value="Cari Dokter" />
            <x-text-input id="gdb-cari" type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="nama dokter" class="block w-full mt-1" />
        </div>
        <div class="w-44">
            <x-input-label for="gdb-saring" value="Tampilkan" />
            <div class="mt-1">
                <x-select-input id="gdb-saring" wire:model.live="tampilkan">
                    <option value="berversi">Berversi &gt; 1</option>
                    <option value="semua">Semua dokter</option>
                </x-select-input>
            </div>
        </div>
        <div wire:loading.flex wire:target="bulan, geserBulan, tampilkan, pilihDokter, versiLama, versiBaru, tukar" class="items-center gap-2 pb-2.5 text-sm text-muted" role="status">
            Membandingkan…
        </div>
    </div>

    <div class="grid gap-3 lg:grid-cols-[18rem_minmax(0,1fr)]">
        {{-- DAFTAR DOKTER --}}
        <div class="flex flex-col overflow-hidden border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700 lg:max-h-[calc(100dvh-14rem)]">
            <div class="px-4 py-2 border-b border-hairline dark:border-gray-700">
                <h2 class="text-sm font-semibold text-ink dark:text-gray-100">Dokter · {{ $dokterList->count() }}</h2>
                <p class="text-xs text-muted">Selisih = diterima versi terbaru − versi pertama.</p>
            </div>
            <ul class="overflow-y-auto divide-y divide-hairline dark:divide-gray-700">
                @forelse ($dokterList as $dokter)
                    <li wire:key="gdb-dokter-{{ $dokter->dr_id }}">
                        <button type="button" wire:click="pilihDokter('{{ $dokter->dr_id }}')" aria-pressed="{{ $dokter->dr_id === $drId ? 'true' : 'false' }}"
                            @class(['w-full px-4 py-2.5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-green dark:focus-visible:ring-brand-lime',
                                'bg-surface-strong dark:bg-gray-800' => $dokter->dr_id === $drId, 'hover:bg-surface-soft dark:hover:bg-gray-800/60' => $dokter->dr_id !== $drId])>
                            <span class="flex items-start justify-between gap-2">
                                <span class="text-sm font-medium text-ink dark:text-gray-100">{{ $dokter->dr_name }}</span>
                                <x-badge :variant="$dokter->jumlah_versi > 1 ? 'warning' : 'gray'" class="shrink-0">{{ $dokter->jumlah_versi }} versi</x-badge>
                            </span>
                            <span class="flex justify-between gap-2 mt-0.5 text-xs text-muted">
                                <span class="font-mono">{{ $dokter->dr_id }}</span>
                                @if ($dokter->jumlah_versi > 1)
                                    <span @class(['font-mono tabular-nums', 'text-fg-danger-strong' => abs($dokter->selisih) >= 0.5])>{{ $formatSelisih($dokter->selisih) }}</span>
                                @endif
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-8 text-sm text-center text-muted">
                        @if ($searchKeyword !== '')
                            Tidak ada dokter yang cocok dengan "{{ $searchKeyword }}".
                            <button type="button" wire:click="resetFilters" class="font-semibold underline text-brand-green dark:text-brand-lime">Reset</button>
                        @elseif ($tampilkan !== 'semua')
                            Belum ada dokter bulan jasa {{ $bulan }} yang angkanya berubah (semua masih versi 1).
                            <button type="button" wire:click="$set('tampilkan', 'semua')" class="font-semibold underline text-brand-green dark:text-brand-lime">Tampilkan semua dokter</button>
                        @else
                            Belum ada slip bulan jasa {{ $bulan }}. Ambil dulu di tab <button type="button" wire:click="$dispatch('keuangan.gaji-dokter.pindahTab', { tab: 'ambil' })" class="font-semibold underline text-brand-green dark:text-brand-lime">1. Ambil Data</button>.
                        @endif
                    </li>
                @endforelse
            </ul>
        </div>

        {{-- PANEL BANDING --}}
        <div class="space-y-3 min-w-0">
            @if ($drId === '')
                <div class="p-4 text-sm border rounded-2xl border-hairline bg-canvas text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">Pilih dokter di sebelah kiri.</div>
            @else
                <div class="flex flex-wrap items-end gap-3 px-4 py-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <div>
                        <x-input-label for="gdb-lama" value="Versi lama (A)" />
                        <div class="mt-1 w-80 max-w-full">
                            <x-select-input id="gdb-lama" wire:model.live="versiLama">
                                @foreach ($this->versiList as $versi)
                                    <option value="{{ $versi->gd_id }}">{{ $labelVersi($versi) }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </div>
                    <x-secondary-button type="button" wire:click="tukar" wire:loading.attr="disabled" wire:target="tukar" title="Tukar versi lama & baru" aria-label="Tukar versi lama dan baru">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
                    </x-secondary-button>
                    <div>
                        <x-input-label for="gdb-baru" value="Versi baru (B)" />
                        <div class="mt-1 w-80 max-w-full">
                            <x-select-input id="gdb-baru" wire:model.live="versiBaru">
                                @foreach ($this->versiList as $versi)
                                    <option value="{{ $versi->gd_id }}">{{ $labelVersi($versi) }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </div>
                    @if ($versiBaru)
                        <x-secondary-button type="button" wire:click="bukaRincian" wire:loading.attr="disabled" wire:target="bukaRincian" class="ml-auto">Buka Rincian versi B</x-secondary-button>
                    @endif
                </div>

                @php $hasil = $this->hasil; @endphp
                @if ($this->versiList->count() < 2)
                    <div class="p-4 text-sm border rounded-2xl border-hairline bg-canvas text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">
                        Dokter ini baru punya satu versi untuk bulan jasa {{ $bulan }} — angka SIRUS belum pernah berubah sejak diambil, jadi belum ada yang dibandingkan.
                    </div>
                @elseif ($hasil === null)
                    <div class="p-4 text-sm border rounded-2xl border-hairline bg-canvas text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">Pilih dua versi yang berbeda.</div>
                @else
                    @php
                        $lampiran = $hasil['lampiran'];
                        $barisTampil = $filterJenis === 'semua' ? $lampiran['baris'] : array_values(array_filter($lampiran['baris'], fn($baris) => $baris['jenis'] === $filterJenis));
                        $kartu = [
                            ['Diterima', (float) $hasil['lama']->gaji_diterima, (float) $hasil['baru']->gaji_diterima, true],
                            ['Jasa', (float) $hasil['lama']->jasa_total, (float) $hasil['baru']->jasa_total, true],
                            ['Baris lampiran', (float) $lampiran['total']['lama']['baris'], (float) $lampiran['total']['baru']['baris'], false],
                        ];
                    @endphp

                    {{-- RINGKASAN --}}
                    <div class="grid gap-3 sm:grid-cols-3">
                        @foreach ($kartu as [$judul, $nilaiLama, $nilaiBaru, $uang])
                            @php $selisih = $nilaiBaru - $nilaiLama; @endphp
                            <div class="p-4 border rounded-2xl border-hairline bg-canvas dark:bg-gray-900 dark:border-gray-700">
                                <p class="text-xs font-semibold uppercase text-muted">{{ $judul }}</p>
                                <p class="mt-1 font-mono text-sm tabular-nums text-body dark:text-gray-300">A · v{{ $hasil['lama']->versi }}: {{ $uang ? $rupiah($nilaiLama) : number_format($nilaiLama, 0, ',', '.') }}</p>
                                <p class="font-mono text-sm tabular-nums text-body dark:text-gray-300">B · v{{ $hasil['baru']->versi }}: {{ $uang ? $rupiah($nilaiBaru) : number_format($nilaiBaru, 0, ',', '.') }}</p>
                                <p @class(['mt-1 text-sm font-semibold tabular-nums', 'text-fg-success-strong' => abs($selisih) < 0.5, 'text-fg-danger-strong' => abs($selisih) >= 0.5])>
                                    Selisih {{ $uang ? $formatSelisih($selisih) : ($selisih > 0 ? '+' : ($selisih < 0 ? '−' : '')) . number_format(abs($selisih), 0, ',', '.') }}
                                </p>
                            </div>
                        @endforeach
                    </div>

                    @if ($hasil['identik'])
                        <div class="p-4 text-sm font-semibold border rounded-2xl border-hairline bg-success-soft text-fg-success-strong" role="status">
                            Tidak ada perbedaan — kedua versi identik sampai tingkat baris lampiran pasien.
                        </div>
                    @else
                        {{-- PENGATURAN & ANGKA SLIP --}}
                        @if ($hasil['pengaturan'] !== [] || $hasil['angka'] !== [])
                            <div class="overflow-x-auto border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                                <table class="ds-table">
                                    <thead><tr><th>Angka & pengaturan slip</th><th class="text-right">A · v{{ $hasil['lama']->versi }}</th><th class="text-right">B · v{{ $hasil['baru']->versi }}</th><th class="text-right">Selisih</th></tr></thead>
                                    <tbody>
                                        @foreach ($hasil['pengaturan'] as $baris)
                                            <tr wire:key="gdb-atur-{{ $baris['kolom'] }}">
                                                <td>{{ $baris['label'] }}</td>
                                                <td class="font-mono text-right line-through text-muted">{{ $nilaiPengaturan($baris['kolom'], $baris['lama']) }}</td>
                                                <td class="font-mono text-right">{{ $nilaiPengaturan($baris['kolom'], $baris['baru']) }}</td>
                                                <td class="text-right text-muted">—</td>
                                            </tr>
                                        @endforeach
                                        @foreach ($hasil['angka'] as $kolom => $baris)
                                            <tr wire:key="gdb-angka-{{ $kolom }}">
                                                <td class="font-medium text-ink dark:text-gray-100">{{ GajiDokterSirus::LABEL_NOMINAL[$kolom] ?? $kolom }}</td>
                                                <td class="font-mono text-right tabular-nums">{{ $rupiah($baris['lama']) }}</td>
                                                <td class="font-mono text-right tabular-nums">{{ $rupiah($baris['baru']) }}</td>
                                                <td class="font-mono font-semibold text-right tabular-nums text-fg-danger-strong">{{ $formatSelisih($baris['selisih']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        {{-- KOMPONEN --}}
                        @if ($hasil['detail'] !== [])
                            <div class="overflow-x-auto border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                                <table class="ds-table">
                                    <thead><tr><th>Komponen yang berbeda</th><th class="text-right">Pasien A → B</th><th class="text-right">A · v{{ $hasil['lama']->versi }}</th><th class="text-right">B · v{{ $hasil['baru']->versi }}</th><th class="text-right">Selisih</th></tr></thead>
                                    <tbody>
                                        @foreach ($hasil['detail'] as $komponen)
                                            <tr wire:key="gdb-komp-{{ $komponen['jenis'] }}-{{ $komponen['kode'] }}">
                                                <td>
                                                    <span class="font-medium text-ink dark:text-gray-100">{{ $komponen['label'] }}</span>
                                                    <span class="block text-xs text-muted">{{ GajiDokterSirus::LABEL_JENIS[$komponen['jenis']] ?? $komponen['jenis'] }}
                                                        @if ($komponen['lama'] === null) · <b>baru</b> @elseif ($komponen['baru'] === null) · <b>hilang</b> @endif</span>
                                                </td>
                                                <td class="font-mono text-right tabular-nums whitespace-nowrap">{{ $komponen['pasien_lama'] ?? '—' }} → {{ $komponen['pasien_baru'] ?? '—' }}</td>
                                                <td class="font-mono text-right tabular-nums">{{ $rupiah($komponen['lama']) }}</td>
                                                <td class="font-mono text-right tabular-nums">{{ $rupiah($komponen['baru']) }}</td>
                                                <td class="font-mono font-semibold text-right tabular-nums">{{ $formatSelisih((float) ($komponen['baru'] ?? 0) - (float) ($komponen['lama'] ?? 0)) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        {{-- LAMPIRAN PASIEN --}}
                        @if ($lampiran['jumlah_selisih'] > 0)
                            <div class="border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                                <div class="flex flex-wrap items-end justify-between gap-2 px-4 pt-3">
                                    <div>
                                        <h3 class="text-sm font-semibold text-ink dark:text-gray-100">Lampiran pasien yang berbeda</h3>
                                        <p class="text-xs text-muted">Baris dicocokkan per komponen + no. transaksi; nominal berbeda = <b>berubah</b>, bukan hilang + baru.</p>
                                    </div>
                                    <x-tabs variant="pill" aria-label="Saring jenis perbedaan">
                                        @foreach (['semua' => 'Semua', 'baru' => 'Baru', 'hilang' => 'Hilang', 'berubah' => 'Berubah'] as $jenis => $labelJenis)
                                            <x-tab :active="$filterJenis === $jenis" wire:click="setFilterJenis('{{ $jenis }}')">
                                                {{ $labelJenis }} ({{ $jenis === 'semua' ? $lampiran['jumlah_selisih'] : ($lampiran['jumlah_per_jenis'][$jenis] ?? 0) }})
                                            </x-tab>
                                        @endforeach
                                    </x-tabs>
                                </div>
                                @if ($lampiran['terpotong'])
                                    <p class="px-4 mt-2 text-sm text-fg-warning">Perbedaan lebih dari {{ number_format(GajiDokterBandingVersi::BATAS_BARIS_SELISIH, 0, ',', '.') }} baris — yang tampil hanya sebagian pertama.</p>
                                @endif
                                <div class="mt-2 overflow-x-auto">
                                    <table class="ds-table">
                                        <thead>
                                            <tr><th>Jenis</th><th>Komponen</th><th>Tanggal</th><th>Pasien</th><th>Klaim</th><th class="text-right">Pasien</th><th class="text-right">Nominal</th></tr>
                                        </thead>
                                        <tbody>
                                            @forelse ($barisTampil as $nomorBaris => $baris)
                                                @php
                                                    $acuan = $baris['baru'] ?? $baris['lama'];
                                                    $berubah = fn($kolom) => in_array($kolom, $baris['berubah'], true);
                                                    $sel = function ($kolom, $format = null) use ($baris, $berubah) {
                                                        $tampil = fn($nilai) => $format ? $format($nilai) : (($nilai === null || $nilai === '') ? '—' : e($nilai));
                                                        if ($baris['jenis'] === 'berubah' && $berubah($kolom)) {
                                                            return '<span class="line-through text-muted">' . $tampil($baris['lama'][$kolom]) . '</span><br>' . $tampil($baris['baru'][$kolom]);
                                                        }
                                                        if ($baris['jenis'] === 'hilang') {
                                                            return '<span class="line-through">' . $tampil($baris['lama'][$kolom]) . '</span>';
                                                        }
                                                        return $tampil(($baris['baru'] ?? $baris['lama'])[$kolom]);
                                                    };
                                                @endphp
                                                <tr wire:key="gdb-lamp-{{ $filterJenis }}-{{ $nomorBaris }}">
                                                    <td>
                                                        <x-badge :variant="match ($baris['jenis']) { 'baru' => 'success', 'hilang' => 'danger', default => 'warning' }">{{ ucfirst($baris['jenis']) }}</x-badge>
                                                        @if ($baris['jenis'] === 'berubah')
                                                            <p class="mt-1 text-xs text-muted">{{ implode(', ', GajiDokterBandingVersi::labelPerubahan($baris)) }}</p>
                                                        @endif
                                                    </td>
                                                    <td class="text-sm">{{ $acuan['label'] }}
                                                        @if ($acuan['txn_no'])<span class="block font-mono text-xs text-muted">{{ $acuan['txn_no'] }}</span>@endif
                                                        @if ($berubah('masuk_slip') || $acuan['masuk_slip'] !== '1')
                                                            <span class="block text-xs text-muted">{!! $sel('masuk_slip', fn($nilai) => $nilai === '1' ? 'masuk slip' : 'tidak masuk slip') !!}</span>
                                                        @endif
                                                    </td>
                                                    <td class="font-mono text-xs whitespace-nowrap">{!! $sel('tgl') !!}</td>
                                                    <td class="text-sm">
                                                        <span class="text-ink dark:text-gray-100">{!! $sel('nama') !!}</span>
                                                        <span class="block font-mono text-xs text-muted">{!! $sel('reg_no') !!}</span>
                                                        @if ($acuan['keterangan'] || $berubah('keterangan'))<span class="block text-xs text-muted">{!! $sel('keterangan') !!}</span>@endif
                                                    </td>
                                                    <td class="text-xs">{!! $sel('klaim') !!}
                                                        @if ($acuan['klaim_status'] || $berubah('klaim_status'))<span class="block text-muted">{!! $sel('klaim_status') !!}</span>@endif
                                                    </td>
                                                    <td class="font-mono text-right tabular-nums">{!! $sel('pasien') !!}
                                                        @if ($berubah('jumlah_baris') || $acuan['jumlah_baris'] > 1)<span class="block text-xs text-muted">{!! $sel('jumlah_baris', fn($nilai) => $nilai === null ? '—' : $nilai . ' baris') !!}</span>@endif
                                                    </td>
                                                    <td class="font-mono text-right tabular-nums whitespace-nowrap">{!! $sel('nominal', fn($nilai) => $rupiah($nilai)) !!}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="7" class="py-6 text-center text-muted">Tidak ada baris berjenis ini.
                                                    <button type="button" wire:click="setFilterJenis('semua')" class="font-semibold underline text-brand-green dark:text-brand-lime">Tampilkan semua</button></td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @elseif ($lampiran['total']['lama']['baris'] > 0 || $lampiran['total']['baru']['baris'] > 0)
                            <p class="px-1 text-sm text-muted">Lampiran pasien kedua versi sama persis — perbedaannya hanya di angka/pengaturan slip di atas.</p>
                        @endif
                    @endif
                @endif
            @endif
        </div>
    </div>
</div>
