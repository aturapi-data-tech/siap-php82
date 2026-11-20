<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiDokterBandingVersi;
use App\Support\GajiDokterSirus;
use App\Support\GajiDokterVersiPeriode;

/**
 * Tab 3 · BANDINGKAN VERSI Gaji Dokter — tingkat PERIODE (pola SIKEU "Bandingkan Versi" + "sahkan versi").
 * Satu versi periode = keadaan seluruh dokter satu bulan jasa (GajiDokterVersiPeriode; dibuat tiap Ambil yang hasilnya berbeda,
 * atau koreksi manual). Pilih bulan → versi A & B → ringkasan total, daftar dokter baru / hilang / berubah → klik dokter untuk
 * detailnya sampai per transaksi (GajiDokterBandingVersi::bandingkan) → "Pakai versi … untuk gaji bulan ini".
 * Bawaan: B = versi terbaru, A = versi dipakai (bila berbeda), selain itu versi sebelumnya. Bulan jasa berbagi Session dengan tab lain.
 */
new class extends Component {
    #[Session(key: 'gaji-dokter-bulan')]
    public string $bulan = ''; // mm/yyyy = bulan JASA

    #[Session(key: 'gaji-dokter-banding-cari')]
    public string $searchKeyword = '';

    #[Session(key: 'gaji-dokter-banding-tampilkan')]
    public string $tampilkan = 'beda'; // beda | semua

    /** versi_id versi periode A & B. */
    #[Session(key: 'gaji-dokter-banding-a')]
    public ?int $versiA = null;

    #[Session(key: 'gaji-dokter-banding-b')]
    public ?int $versiB = null;

    #[Session(key: 'gaji-dokter-banding-dokter')]
    public string $drId = '';

    /** semua | baru | hilang | berubah — saring tabel transaksi dokter terpilih */
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
        if (!in_array($this->tampilkan, ['beda', 'semua'], true)) {
            $this->tampilkan = 'beda';
        }
        $this->pilihVersiBawaan();
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

    private function segarkan(): void
    {
        unset($this->versiPeriodeList, $this->perbandingan, $this->dokterList, $this->dokterTerpilih, $this->hasil);
    }

    public function updatedBulan(): void { $this->gantiBulan(); }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        $this->gantiBulan();
    }

    private function gantiBulan(): void
    {
        $this->versiA = null;
        $this->versiB = null;
        $this->drId = '';
        $this->segarkan();
        $this->pilihVersiBawaan();
        $this->pilihDokterBawaan();
    }

    /** B = versi periode terbaru; A = versi dipakai bila berbeda dari B, selain itu versi tepat sebelum B. */
    private function pilihVersiBawaan(): void
    {
        $this->segarkan();
        $idList = $this->versiPeriodeList->pluck('versi_id')->all();
        if (!in_array($this->versiB, $idList, true)) {
            $this->versiB = $idList[0] ?? null;
        }
        if (!in_array($this->versiA, $idList, true) || $this->versiA === $this->versiB) {
            $idDipakai = $this->versiPeriodeList->firstWhere('dipakai', '1')?->versi_id;
            $this->versiA = $idDipakai && $idDipakai !== $this->versiB ? $idDipakai : collect($idList)->first(fn($versiId) => $versiId !== $this->versiB);
        }
        $this->segarkan();
    }

    private function pilihDokterBawaan(): void
    {
        if (!$this->dokterList->contains('dr_id', $this->drId)) {
            $this->drId = (string) ($this->dokterList->first()?->dr_id ?? '');
        }
        unset($this->dokterTerpilih, $this->hasil);
        $this->filterJenis = 'semua';
    }

    public function updatedVersiA(): void { $this->segarkan(); $this->pilihDokterBawaan(); }
    public function updatedVersiB(): void { $this->segarkan(); $this->pilihDokterBawaan(); }
    public function updatedTampilkan(): void { unset($this->dokterList); $this->pilihDokterBawaan(); }
    public function updatedSearchKeyword(): void { unset($this->dokterList); }

    public function tukar(): void
    {
        [$this->versiA, $this->versiB] = [$this->versiB, $this->versiA];
        $this->segarkan();
    }

    public function pilihDokter(string $drId): void
    {
        $this->drId = $drId;
        unset($this->dokterTerpilih, $this->hasil);
        $this->filterJenis = 'semua';
    }

    public function setFilterJenis(string $jenis): void
    {
        $this->filterJenis = in_array($jenis, ['semua', 'baru', 'hilang', 'berubah'], true) ? $jenis : 'semua';
    }

    /** Pakai versi periode A atau B untuk gaji bulan ini — GajiDokterVersiPeriode::pakai (semua dokter sekaligus). */
    public function pakaiVersi(int $versiId): void
    {
        if (!in_array($versiId, [(int) $this->versiA, (int) $this->versiB], true)) {
            return;
        }
        $pesanGalat = GajiDokterVersiPeriode::pakai($versiId, auth()->id());
        $versi = $this->versiPeriodeList->firstWhere('versi_id', $versiId);
        $this->dispatch('toast', type: $pesanGalat ? 'error' : 'success',
            message: $pesanGalat ?? "Versi {$versi?->versi} sekarang dipakai untuk gaji bulan jasa {$this->bulan}. Terapkan ke slip gaji ulang di Buku bila slip karyawan sudah terisi.");
        $this->segarkan();
    }

    public function bukaRincian(): void
    {
        $dokter = $this->dokterTerpilih;
        $gdId = $dokter?->gd_b ?? $dokter?->gd_a;
        if ($gdId) {
            $this->dispatch('keuangan.gaji-dokter.openRincianSlipDokter', gdId: (int) $gdId);
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword']);
        $this->tampilkan = 'beda';
        unset($this->dokterList);
        $this->pilihDokterBawaan();
    }

    #[Computed]
    public function versiPeriodeList()
    {
        return GajiDokterVersiPeriode::daftar($this->periode());
    }

    #[Computed]
    public function perbandingan(): ?array
    {
        if (!$this->versiA || !$this->versiB || $this->versiA === $this->versiB) {
            return null;
        }

        return GajiDokterVersiPeriode::bandingkan((int) $this->versiA, (int) $this->versiB);
    }

    #[Computed]
    public function dokterList()
    {
        $kataCari = mb_strtolower(trim($this->searchKeyword));

        return collect($this->perbandingan['dokter'] ?? [])
            ->filter(fn($dokter) => ($this->tampilkan === 'semua' || !in_array($dokter->jenis, ['sama', 'setara'], true))
                && ($kataCari === '' || str_contains(mb_strtolower($dokter->dr_name), $kataCari) || str_contains($dokter->dr_id, $kataCari)))
            ->values();
    }

    #[Computed]
    public function dokterTerpilih(): ?object
    {
        return collect($this->perbandingan['dokter'] ?? [])->firstWhere('dr_id', $this->drId);
    }

    #[Computed]
    public function hasil(): ?array
    {
        $dokter = $this->dokterTerpilih;

        return $dokter && in_array($dokter->jenis, ['berubah', 'setara'], true) ? GajiDokterBandingVersi::bandingkan((int) $dokter->gd_a, (int) $dokter->gd_b) : null;
    }
};
?>
<div class="mt-4 space-y-3">
    @php
        $rupiah = fn($nilai) => $nilai === null ? '—' : \App\Support\GajiKaryawan::rupiah($nilai);
        $formatSelisih = fn($nilai) => $nilai > 0 ? '+' . $rupiah($nilai) : ($nilai < 0 ? '−' . $rupiah(abs($nilai)) : '0');
        $nilaiPengaturan = fn($kolom, $nilai) => match (true) {
            $nilai === null || $nilai === '' => '—',
            in_array($kolom, ['nilai_gaji_pokok'], true) => $rupiah($nilai),
            in_array($kolom, ['potongan_rs_persen', 'pph21_persen'], true) => rtrim(rtrim(number_format((float) $nilai, 2, ',', '.'), '0'), ',') . '%',
            $kolom === 'gaji_status' => ['D' => 'Draft', 'F' => 'Final'][$nilai] ?? $nilai,
            $kolom === 'npwp_status' => ['Y' => 'Ada', 'T' => 'Tidak ada'][$nilai] ?? $nilai,
            default => (string) $nilai,
        };
        $versiList = $this->versiPeriodeList;
        $perbandingan = $this->perbandingan;
        $objekA = $versiList->firstWhere('versi_id', $versiA);
        $objekB = $versiList->firstWhere('versi_id', $versiB);
        $versiDipakai = $versiList->firstWhere('dipakai', '1');
        $labelVersi = fn($versi) => 'v' . $versi->versi . ' · ' . Carbon::parse($versi->created_at)->format('d/m/Y H:i') . ' · ' . (GajiDokterVersiPeriode::LABEL_SUMBER[$versi->sumber] ?? $versi->sumber)
            . ' · ' . $versi->jumlah_dokter . ' dokter' . ($versi->dipakai === '1' ? ' · DIPAKAI' : '');
        $badgeJenis = ['baru' => 'success', 'hilang' => 'danger', 'berubah' => 'warning', 'setara' => 'gray', 'sama' => 'gray'];
        $labelJenis = GajiDokterVersiPeriode::LABEL_JENIS;
    @endphp

    {{-- TOOLBAR: bulan + versi periode A / B --}}
    <div class="flex flex-wrap items-end gap-3 px-4 py-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
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
        @if ($versiList->count() > 1)
            <div>
                <x-input-label for="gdb-a" value="Versi A" />
                <div class="mt-1 w-[22rem] max-w-full">
                    <x-select-input id="gdb-a" wire:model.live="versiA">
                        @foreach ($versiList as $versi)
                            <option value="{{ $versi->versi_id }}">{{ $labelVersi($versi) }}</option>
                        @endforeach
                    </x-select-input>
                </div>
            </div>
            <x-secondary-button type="button" wire:click="tukar" wire:loading.attr="disabled" wire:target="tukar" title="Tukar versi A & B" aria-label="Tukar versi A dan B">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
            </x-secondary-button>
            <div>
                <x-input-label for="gdb-b" value="Versi B" />
                <div class="mt-1 w-[22rem] max-w-full">
                    <x-select-input id="gdb-b" wire:model.live="versiB">
                        @foreach ($versiList as $versi)
                            <option value="{{ $versi->versi_id }}">{{ $labelVersi($versi) }}</option>
                        @endforeach
                    </x-select-input>
                </div>
            </div>
        @endif
        <div wire:loading.flex wire:target="bulan, geserBulan, versiA, versiB, tukar, pilihDokter, pakaiVersi" class="items-center gap-2 pb-2.5 text-sm text-muted" role="status">Membandingkan…</div>
    </div>

    @if ($versiList->isEmpty())
        <div class="p-4 text-sm border rounded-2xl border-hairline bg-canvas text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">
            Belum ada data bulan jasa {{ $bulan }}. Ambil dulu di tab
            <button type="button" wire:click="$dispatch('keuangan.gaji-dokter.pindahTab', { tab: 'ambil' })" class="font-semibold underline text-brand-green dark:text-brand-lime">Ambil Data</button>.
        </div>
    @elseif ($versiList->count() === 1)
        <div class="p-4 text-sm border rounded-2xl border-hairline bg-canvas text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">
            Bulan jasa {{ $bulan }} baru punya satu versi ({{ $labelVersi($versiList->first()) }}) dan itu yang dipakai untuk gaji. Ambil ulang di tab
            <button type="button" wire:click="$dispatch('keuangan.gaji-dokter.pindahTab', { tab: 'ambil' })" class="font-semibold underline text-brand-green dark:text-brand-lime">Ambil Data</button>
            &mdash; bila hasilnya berbeda, versi 2 terbentuk dan bisa dibandingkan di sini.
        </div>
    @elseif ($perbandingan === null)
        <div class="p-4 text-sm border rounded-2xl border-hairline bg-canvas text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">Pilih dua versi yang berbeda.</div>
    @else
        @php $total = $perbandingan['total']; $perJenisDokter = $total['per_jenis']; @endphp

        {{-- KEPUTUSAN: versi periode yang dipakai untuk gaji bulan ini --}}
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm border rounded-2xl border-hairline bg-canvas dark:bg-gray-900 dark:border-gray-700" role="status">
            <span class="text-body dark:text-gray-300">
                Dipakai untuk gaji {{ $bulan }}: <span class="font-semibold text-ink dark:text-gray-100">{{ $versiDipakai ? 'v' . $versiDipakai->versi : 'belum ada' }}</span>
                @if ($versiDipakai && $versiDipakai->dipakai_at)<span class="text-muted">· dipilih {{ Carbon::parse($versiDipakai->dipakai_at)->format('d/m/Y H:i') }}{{ $versiDipakai->dipakai_nama ? ' oleh ' . $versiDipakai->dipakai_nama : '' }}</span>@endif
            </span>
            <div class="flex flex-wrap items-center gap-2 ml-auto">
                @foreach ([['A', $objekA], ['B', $objekB]] as [$hurufVersi, $versiPilihan])
                    @if ($versiPilihan && $versiPilihan->dipakai !== '1')
                        <x-confirm-button :variant="$hurufVersi === 'B' ? 'primary' : 'secondary'" action="pakaiVersi({{ $versiPilihan->versi_id }})" title="Pakai versi {{ $hurufVersi }}"
                            message="Pakai versi {{ $versiPilihan->versi }} untuk gaji SELURUH dokter bulan jasa {{ $bulan }}? Buku, Final, dan Terapkan ke slip gaji akan memakai versi ini. Versi lain tetap tersimpan dan bisa dipakai kembali. Slip gaji karyawan yang sudah terisi baru berubah setelah Terapkan ulang." confirmText="Ya, pakai" cancelText="Batal">
                            Pakai versi {{ $hurufVersi }} (v{{ $versiPilihan->versi }}) untuk gaji bulan ini
                        </x-confirm-button>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- RINGKASAN PERIODE --}}
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([['Dokter', $total['dokter_a'], $total['dokter_b'], false], ['Jasa', $total['jasa_a'], $total['jasa_b'], true], ['Diterima', $total['diterima_a'], $total['diterima_b'], true]] as [$judul, $nilaiA, $nilaiB, $uang])
                @php $selisih = $nilaiB - $nilaiA; @endphp
                <div class="p-4 border rounded-2xl border-hairline bg-canvas dark:bg-gray-900 dark:border-gray-700">
                    <p class="text-xs font-semibold uppercase text-muted">{{ $judul }} · semua dokter</p>
                    <p class="mt-1 font-mono text-sm tabular-nums text-body dark:text-gray-300">A · v{{ $objekA->versi }}: {{ $uang ? $rupiah($nilaiA) : $nilaiA }}</p>
                    <p class="font-mono text-sm tabular-nums text-body dark:text-gray-300">B · v{{ $objekB->versi }}: {{ $uang ? $rupiah($nilaiB) : $nilaiB }}</p>
                    <p @class(['mt-1 text-sm font-semibold tabular-nums', 'text-fg-success-strong' => abs($selisih) < 0.5, 'text-fg-danger-strong' => abs($selisih) >= 0.5])>
                        Selisih {{ $uang ? $formatSelisih($selisih) : ($selisih > 0 ? '+' : '') . $selisih }}
                    </p>
                </div>
            @endforeach
            <div class="p-4 border rounded-2xl border-hairline bg-canvas dark:bg-gray-900 dark:border-gray-700">
                <p class="text-xs font-semibold uppercase text-muted">Dokter berbeda</p>
                <p class="mt-1 text-sm text-body dark:text-gray-300">
                    <span class="font-semibold text-fg-warning">{{ $perJenisDokter['berubah'] ?? 0 }} berubah</span> ·
                    <span class="font-semibold text-fg-success-strong">{{ $perJenisDokter['baru'] ?? 0 }} baru</span> ·
                    <span class="font-semibold text-fg-danger-strong">{{ $perJenisDokter['hilang'] ?? 0 }} hilang</span>
                </p>
                <p class="text-xs text-muted">{{ ($perJenisDokter['sama'] ?? 0) + ($perJenisDokter['setara'] ?? 0) }} dokter angkanya sama {{ ($perJenisDokter['setara'] ?? 0) > 0 ? '(' . $perJenisDokter['setara'] . ' hanya beda sumber hitungan)' : '' }}.</p>
            </div>
        </div>

        <div class="grid gap-3 lg:grid-cols-[19rem_minmax(0,1fr)]">
            {{-- DAFTAR DOKTER --}}
            <div class="flex flex-col overflow-hidden border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700 lg:max-h-[calc(100dvh-12rem)]">
                <div class="px-3 py-2 space-y-2 border-b border-hairline dark:border-gray-700">
                    <div class="flex gap-2">
                        <x-text-input type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="cari dokter" class="w-full" aria-label="Cari dokter" />
                        <div class="w-32 shrink-0">
                            <x-select-input wire:model.live="tampilkan" aria-label="Tampilkan dokter">
                                <option value="beda">Yang berbeda</option>
                                <option value="semua">Semua</option>
                            </x-select-input>
                        </div>
                    </div>
                    <p class="text-xs text-muted">Selisih = diterima B − A.</p>
                </div>
                <ul class="overflow-y-auto divide-y divide-hairline dark:divide-gray-700">
                    @forelse ($this->dokterList as $dokter)
                        <li wire:key="gdb-dokter-{{ $dokter->dr_id }}">
                            <button type="button" wire:click="pilihDokter('{{ $dokter->dr_id }}')" aria-pressed="{{ $dokter->dr_id === $drId ? 'true' : 'false' }}"
                                @class(['w-full px-4 py-2.5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-green dark:focus-visible:ring-brand-lime',
                                    'bg-surface-strong dark:bg-gray-800' => $dokter->dr_id === $drId, 'hover:bg-surface-soft dark:hover:bg-gray-800/60' => $dokter->dr_id !== $drId])>
                                <span class="flex items-start justify-between gap-2">
                                    <span class="text-sm font-medium text-ink dark:text-gray-100">{{ $dokter->dr_name }}</span>
                                    <x-badge :variant="$badgeJenis[$dokter->jenis]" class="shrink-0">{{ $labelJenis[$dokter->jenis] }}</x-badge>
                                </span>
                                <span class="flex justify-between gap-2 mt-0.5 text-xs text-muted">
                                    <span class="font-mono">{{ $dokter->dr_id }}</span>
                                    @if (!in_array($dokter->jenis, ['sama', 'setara'], true))
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
                            @else
                                Semua dokter sama persis di versi A dan B.
                                <button type="button" wire:click="$set('tampilkan', 'semua')" class="font-semibold underline text-brand-green dark:text-brand-lime">Tampilkan semua</button>
                            @endif
                        </li>
                    @endforelse
                </ul>
            </div>

            {{-- DETAIL DOKTER TERPILIH --}}
            <div class="space-y-3 min-w-0">
                @php $dokter = $this->dokterTerpilih; @endphp
                @if (!$dokter)
                    <div class="p-4 text-sm border rounded-2xl border-hairline bg-canvas text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">Pilih dokter di sebelah kiri untuk melihat rinciannya sampai per transaksi.</div>
                @else
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                        <div>
                            <p class="font-semibold text-ink dark:text-gray-100">{{ $dokter->dr_name }} <x-badge :variant="$badgeJenis[$dokter->jenis]">{{ $labelJenis[$dokter->jenis] }}</x-badge></p>
                            <p class="text-xs text-muted">Slip dokter: A = {{ $dokter->versi_a ? 'versi ' . $dokter->versi_a : 'tidak ada' }} · B = {{ $dokter->versi_b ? 'versi ' . $dokter->versi_b : 'tidak ada' }}</p>
                        </div>
                        <x-secondary-button type="button" wire:click="bukaRincian" wire:loading.attr="disabled" wire:target="bukaRincian" class="ml-auto">Buka Rincian</x-secondary-button>
                    </div>
                    @php $hasil = $this->hasil; @endphp
                    @if ($dokter->jenis === 'sama')
                        <div class="p-4 text-sm border rounded-2xl border-hairline bg-success-soft text-fg-success-strong" role="status">Slip dokter ini sama persis di versi A dan B (diterima {{ $rupiah($dokter->diterima_b) }}).</div>
                    @elseif ($dokter->jenis === 'baru')
                        <div class="p-4 text-sm border rounded-2xl border-hairline bg-canvas text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">
                            Dokter ini <b>hanya ada di versi B</b> (diterima {{ $rupiah($dokter->diterima_b) }}, jasa {{ $rupiah($dokter->jasa_b) }}) &mdash; di versi A belum ada transaksinya. Buka Rincian / Lampiran untuk melihat transaksinya.
                        </div>
                    @elseif ($dokter->jenis === 'hilang')
                        <div class="p-4 text-sm border rounded-2xl border-hairline bg-canvas text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">
                            Dokter ini <b>hanya ada di versi A</b> (diterima {{ $rupiah($dokter->diterima_a) }}) &mdash; di versi B SIRUS tidak lagi mengirim transaksinya (dibatalkan / dipindah bulan). Bila versi B dipakai, dokter ini tidak digaji bulan ini.
                        </div>
                    @elseif ($dokter->jenis === 'setara')
                        <div class="p-4 text-sm border rounded-2xl border-hairline bg-success-soft text-fg-success-strong" role="status">Angka slip &amp; jumlah transaksi dokter ini sama di versi A dan B (diterima {{ $rupiah($dokter->diterima_b) }}) &mdash; hanya snapshot / sumber hitungannya yang berbeda. Rincian di bawah.</div>
                    @endif
                    @if ($hasil && in_array($dokter->jenis, ['berubah', 'setara'], true))
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
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($kartu as [$judul, $nilaiLama, $nilaiBaru, $uang])
                    @php $selisih = $nilaiBaru - $nilaiLama; @endphp
                    <div class="p-4 border rounded-2xl border-hairline bg-canvas dark:bg-gray-900 dark:border-gray-700">
                        <p class="text-xs font-semibold uppercase text-muted">{{ $judul }}</p>
                        <p class="mt-1 font-mono text-sm tabular-nums text-body dark:text-gray-300">A: {{ $uang ? $rupiah($nilaiLama) : number_format($nilaiLama, 0, ',', '.') }}</p>
                        <p class="font-mono text-sm tabular-nums text-body dark:text-gray-300">B: {{ $uang ? $rupiah($nilaiBaru) : number_format($nilaiBaru, 0, ',', '.') }}</p>
                        <p @class(['mt-1 text-sm font-semibold tabular-nums', 'text-fg-success-strong' => abs($selisih) < 0.5, 'text-fg-danger-strong' => abs($selisih) >= 0.5])>
                            Selisih {{ $uang ? $formatSelisih($selisih) : ($selisih > 0 ? '+' : ($selisih < 0 ? '−' : '')) . number_format(abs($selisih), 0, ',', '.') }}
                        </p>
                    </div>
                @endforeach
                {{-- Kartu 4: transaksi yang berbeda (tingkat baris) — tautan ke tabel transaksi di bawah. --}}
                @php $perJenis = $lampiran['jumlah_per_jenis'] ?? []; @endphp
                <div class="p-4 border rounded-2xl border-hairline bg-canvas dark:bg-gray-900 dark:border-gray-700">
                    <p class="text-xs font-semibold uppercase text-muted">Transaksi berbeda</p>
                    @if ($lampiran['jumlah_selisih'] > 0)
                        <p class="mt-1 text-sm text-body dark:text-gray-300">
                            <span class="font-semibold text-fg-success-strong">{{ $perJenis['baru'] ?? 0 }} baru</span> ·
                            <span class="font-semibold text-fg-danger-strong">{{ $perJenis['hilang'] ?? 0 }} hilang</span> ·
                            <span class="font-semibold text-fg-warning">{{ $perJenis['berubah'] ?? 0 }} berubah</span>
                        </p>
                        <a href="#gdb-transaksi" class="inline-block mt-1 text-sm font-semibold underline underline-offset-2 text-brand-green dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green rounded">Lihat per transaksi ↓</a>
                    @else
                        <p class="mt-1 text-sm font-semibold text-fg-success-strong">Tidak ada</p>
                        <p class="text-xs text-muted">Semua transaksi sama — selisih angka berasal dari cara hitung slip / struktur.</p>
                    @endif
                </div>
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
                            <thead><tr><th>Angka & pengaturan slip</th><th class="text-right">A</th><th class="text-right">B</th><th class="text-right">Selisih</th></tr></thead>
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
                            <thead><tr><th>Komponen yang berbeda</th><th class="text-right">Pasien A → B</th><th class="text-right">A</th><th class="text-right">B</th><th class="text-right">Selisih</th></tr></thead>
                            <tbody>
                                @foreach ($hasil['detail'] as $komponen)
                                    <tr wire:key="gdb-komp-{{ $komponen['jenis'] }}-{{ $komponen['kode'] }}">
                                        <td>
                                            <span class="font-medium text-ink dark:text-gray-100">{{ $komponen['label'] }}</span>
                                            <span class="block text-xs text-muted">{{ GajiDokterSirus::LABEL_JENIS[$komponen['jenis']] ?? $komponen['jenis'] }}
                                                @if ($komponen['lama'] === null) · <b>baru</b> @elseif ($komponen['baru'] === null) · <b>hilang</b> @endif</span>
                                            @if (!empty($komponen['keterangan_asal']))
                                                <span class="flex items-start gap-1.5 mt-1 text-xs">
                                                    <x-badge :variant="match ($komponen['asal']) { 'transaksi' => 'warning', 'hitung' => 'info', default => 'gray' }" class="shrink-0">{{ match ($komponen['asal']) { 'transaksi' => 'dari transaksi', 'hitung' => 'dari cara hitung', default => 'dari struktur' } }}</x-badge>
                                                    <span class="text-body dark:text-gray-300">{{ $komponen['keterangan_asal'] }}</span>
                                                </span>
                                            @endif
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

                {{-- LAMPIRAN PASIEN (per transaksi) --}}
                @if ($lampiran['jumlah_selisih'] > 0)
                    <div id="gdb-transaksi" class="scroll-mt-24 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                        <div class="flex flex-wrap items-end justify-between gap-2 px-4 pt-3">
                            <div>
                                <h3 class="text-sm font-semibold text-ink dark:text-gray-100">Transaksi (lampiran pasien) yang berbeda</h3>
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
    @endif
</div>
