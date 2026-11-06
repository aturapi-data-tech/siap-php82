<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiDokterSirus;
use App\Support\GajiKaryawan;

/**
 * Tab BUKU Gaji Dokter (anak pembungkus gaji-dokter): slip gaji dokter SIRUS per BULAN JASA — versi terbaru tiap dokter,
 * tombol "Ambil dari SIRUS" (snapshot berversi: sama → tidak ada perubahan; beda → versi baru, versi lama tetap),
 * pembanding dengan bulan lalu, dan modal Rincian (komponen slip + banding antar versi / bulan lalu).
 * Bulan jasa & saringan disimpan di Session (komponen di-unmount saat ganti tab — docs/tabs-pattern.md).
 * Tidak ada tulis ke SIRUS; snapshot tidak bisa disunting.
 */
new class extends Component {
    #[Session(key: 'gaji-dokter-bulan')]
    public string $bulan = ''; // mm/yyyy = bulan JASA

    #[Session(key: 'gaji-dokter-searchKeyword')]
    public string $searchKeyword = '';

    #[Session(key: 'gaji-dokter-saring')]
    public string $saring = 'semua'; // semua | berubah | tanpa_map | final

    /** Modal rincian: header yang dibuka + pembanding (gd_id versi lain / bulan lalu, atau null). */
    public ?int $rincianGdId = null;
    public string $bandingPilihan = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!$this->bulanValid()) {
            $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        }
        if (!in_array($this->saring, ['semua', 'berubah', 'tanpa_map', 'final'], true)) {
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

    public function updatedBulan(): void { $this->segarkan(); }
    public function updatedSearchKeyword(): void { $this->segarkan(); }
    public function updatedSaring(): void { $this->segarkan(); }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        $this->segarkan();
    }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword']);
        $this->saring = 'semua';
        $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        $this->segarkan();
    }

    private function segarkan(): void
    {
        unset($this->rows, $this->ambilTerakhir, $this->total);
    }

    public function ambil(): void
    {
        if (!$this->bulanValid()) {
            $this->dispatch('toast', type: 'error', message: 'Bulan jasa tidak valid.');
            return;
        }
        try {
            $hasil = GajiDokterSirus::ambil($this->periode(), auth()->id());
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
            return;
        }
        $pesan = "SIRUS {$this->bulan}: {$hasil['jumlah']} slip — {$hasil['baru']} baru, {$hasil['berubah']} berubah (versi baru), {$hasil['sama']} sama.";
        if ($hasil['tanpaMap'] !== []) {
            $pesan .= ' ' . count($hasil['tanpaMap']) . ' dokter belum dipetakan ke karyawan.';
        }
        $this->dispatch('toast', type: $hasil['jumlah'] === 0 ? 'warning' : 'success', message: $hasil['jumlah'] === 0 ? "SIRUS belum punya slip gaji dokter untuk bulan jasa {$this->bulan}." : $pesan);
        $this->segarkan();
    }

    public function openRincian(int $gdId): void
    {
        $this->rincianGdId = $gdId;
        $this->bandingPilihan = '';
        $pilihan = $this->bandingOpsi();
        $this->bandingPilihan = array_key_first($pilihan) ?? '';
        $this->dispatch('open-modal', name: 'gaji-dokter-rincian');
    }

    public function closeModal(): void
    {
        $this->rincianGdId = null;
        $this->bandingPilihan = '';
        $this->dispatch('close-modal', name: 'gaji-dokter-rincian');
    }

    public function openLampiran(int $gdId): void
    {
        $this->dispatch('keuangan.gaji-dokter.openLampiran', gdId: $gdId);
    }

    #[Computed]
    public function rows()
    {
        return GajiDokterSirus::daftar($this->periode(), $this->searchKeyword, $this->saring);
    }

    #[Computed]
    public function total(): array
    {
        $rows = GajiDokterSirus::daftar($this->periode());
        $total = ['jumlah' => $rows->count(), 'final' => $rows->where('gaji_status', 'F')->count(), 'tanpa_map' => $rows->whereNull('emp_id_map')->count(), 'berubah' => $rows->where('versi_sebelumnya', true)->count()];
        foreach (GajiDokterSirus::KOLOM_NOMINAL as $k) {
            $total[$k] = (float) $rows->sum($k);
        }

        return $total;
    }

    #[Computed]
    public function ambilTerakhir(): ?object
    {
        return GajiDokterSirus::riwayatAmbil(200)->firstWhere('periode', $this->periode());
    }

    // ─── Modal rincian ─────────────────────────────────────────────────────

    #[Computed]
    public function rincian(): ?object
    {
        return $this->rincianGdId ? \Illuminate\Support\Facades\DB::table('siaptxn_gdok_hdrs')->where('gd_id', $this->rincianGdId)->first() : null;
    }

    /** Pilihan pembanding: versi lain dokter yang sama bulan ini + versi terbaru bulan lalu. [kunci => label] */
    public function bandingOpsi(): array
    {
        $h = $this->rincian;
        if (!$h) {
            return [];
        }
        $opsi = [];
        foreach (GajiDokterSirus::versiList($h->periode, $h->dr_id) as $v) {
            if ((int) $v->gd_id !== (int) $h->gd_id) {
                $opsi[(string) $v->gd_id] = "Versi {$v->versi} (diambil " . Carbon::parse($v->diambil_at)->format('d/m/Y H:i') . ')';
            }
        }
        $bulanLalu = Carbon::createFromFormat('!Y-m', $h->periode)->subMonthNoOverflow()->format('Y-m');
        $lalu = \Illuminate\Support\Facades\DB::table('siaptxn_gdok_hdrs')->where('periode', $bulanLalu)->where('dr_id', $h->dr_id)->where('terbaru', '1')->first();
        if ($lalu) {
            $opsi[(string) $lalu->gd_id] = 'Bulan jasa ' . Carbon::createFromFormat('!Y-m', $bulanLalu)->translatedFormat('M Y') . " (versi {$lalu->versi})";
        }

        return $opsi;
    }

    #[Computed]
    public function pembanding(): ?object
    {
        return $this->bandingPilihan !== '' && isset($this->bandingOpsi()[$this->bandingPilihan])
            ? \Illuminate\Support\Facades\DB::table('siaptxn_gdok_hdrs')->where('gd_id', (int) $this->bandingPilihan)->first() : null;
    }

    public function updatedBandingPilihan(): void { unset($this->pembanding); }

    /** Baris rincian gabungan: tiap komponen versi ini + komponen pembanding yang hilang. */
    #[Computed]
    public function barisRincian(): array
    {
        $h = $this->rincian;
        if (!$h) {
            return [];
        }
        $ini = GajiDokterSirus::detail((int) $h->gd_id)->keyBy(fn($d) => $d->jenis . '|' . $d->kode);
        $lain = $this->pembanding ? GajiDokterSirus::detail((int) $this->pembanding->gd_id)->keyBy(fn($d) => $d->jenis . '|' . $d->kode) : collect();
        $baris = [];
        foreach ($ini->keys()->merge($lain->keys())->unique() as $kunci) {
            $x = $ini[$kunci] ?? null;
            $y = $lain[$kunci] ?? null;
            $baris[] = ['jenis' => ($x ?? $y)->jenis, 'label' => ($x ?? $y)->label ?: ($x ?? $y)->kode, 'kode' => ($x ?? $y)->kode,
                'pasien' => $x ? (int) $x->jumlah_pasien : null, 'nilai' => $x ? (float) $x->nilai : null, 'manual' => $x && $x->nilai_manual === '1',
                'pasien_lain' => $y ? (int) $y->jumlah_pasien : null, 'nilai_lain' => $y ? (float) $y->nilai : null,
                'urutan' => (int) (($x ?? $y)->urutan)];
        }
        usort($baris, fn($a, $b) => [$a['jenis'], $a['urutan']] <=> [$b['jenis'], $b['urutan']]);

        return $baris;
    }
};
?>

<div class="mt-4">
    @php
        $rp = fn($n) => GajiKaryawan::rupiah($n);
        $selisih = fn($n) => $n === null ? '–' : ($n > 0 ? '+' . $rp($n) : ($n < 0 ? '−' . $rp(abs($n)) : '0'));
        $total = $this->total;
        $ambilTerakhir = $this->ambilTerakhir;
        $labelBulan = \Carbon\Carbon::createFromFormat('!Y-m', \Carbon\Carbon::createFromFormat('!' . Format::BULAN, $bulan)->format('Y-m'))->translatedFormat('F Y');
    @endphp

    {{-- TOOLBAR --}}
    <div class="flex flex-wrap items-end gap-3 px-4 py-3 mb-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
        <div>
            <x-input-label value="Bulan Jasa" />
            <div class="flex items-center gap-1 mt-1">
                <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-3" title="Bulan sebelumnya" aria-label="Bulan sebelumnya">‹</x-secondary-button>
                <div class="w-28">
                    <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" class="w-full text-center font-mono" aria-label="Bulan jasa" />
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
                    <option value="berubah">Pernah berubah (versi &gt; 1)</option>
                    <option value="tanpa_map">Dokter belum dipetakan</option>
                    <option value="final">Final di SIRUS</option>
                </x-select-input>
            </div>
        </div>
        <div class="flex items-center gap-2 ml-auto">
            @if (GajiDokterSirus::aktif())
                <x-confirm-button variant="primary" action="ambil()" title="Ambil dari SIRUS"
                    message="Tarik slip gaji dokter bulan jasa {{ $bulan }} dari SIRUS? Dokter yang angkanya SAMA tidak berubah; yang BERBEDA mendapat versi baru (versi lama tetap tersimpan untuk pembanding). Tidak ada data SIRUS yang diubah." confirmText="Ya, ambil" cancelText="Batal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    Ambil dari SIRUS
                </x-confirm-button>
            @else
                <x-badge variant="danger">API SIRUS belum diatur di .env (SIAP_API_URL / USER / PASSWORD)</x-badge>
            @endif
            <x-toolbar-refresh-reset :label="null" />
        </div>
    </div>

    {{-- RINGKASAN --}}
    <div class="flex flex-wrap items-center gap-2 px-4 mb-3 text-xs text-muted">
        @if ($total['jumlah'] === 0)
            <x-badge variant="gray">Belum ada snapshot bulan jasa {{ $labelBulan }}</x-badge>
            <span>Klik <b>Ambil dari SIRUS</b> untuk menarik slip gaji dokter bulan ini.</span>
        @else
            <x-badge variant="info">{{ $total['jumlah'] }} slip · {{ $total['final'] }} Final di SIRUS</x-badge>
            @if ($total['berubah'] > 0)<x-badge variant="warning">{{ $total['berubah'] }} slip pernah berubah sejak pertama diambil</x-badge>@endif
            @if ($total['tanpa_map'] > 0)<x-badge variant="warning">{{ $total['tanpa_map'] }} dokter belum dipetakan ke karyawan</x-badge>@else<x-badge variant="success">Semua dokter terpetakan</x-badge>@endif
            @if ($ambilTerakhir)<span>Terakhir diambil {{ \Carbon\Carbon::parse($ambilTerakhir->diambil_at)->format('d/m/Y H:i') }} oleh {{ $ambilTerakhir->name ?? '–' }}.</span>@endif
            <span class="ml-auto">Bulan gaji di SIRUS = bulan jasa + 1. Angka adalah snapshot — ambil ulang untuk melihat apakah SIRUS berubah.</span>
        @endif
    </div>

    {{-- TABEL --}}
    <div class="overflow-x-auto bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <table class="ds-table">
            <thead>
                <tr>
                    <th>Dokter</th>
                    <th>Karyawan SIAP</th>
                    <th class="ds-c">Status</th>
                    <th class="text-right">Jasa</th>
                    <th class="text-right">Pot. RS</th>
                    <th class="text-right">PPh 21</th>
                    <th class="text-right">Pot. lain</th>
                    <th class="text-right">Tambahan</th>
                    <th class="text-right">Diterima</th>
                    <th class="text-right whitespace-nowrap">vs bulan lalu</th>
                    <th class="text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows as $row)
                    <tr wire:key="gd-{{ $row->gd_id }}">
                        <td>
                            <span class="font-semibold">{{ $row->dr_name }}</span>
                            <span class="block font-mono text-xs text-muted">dr_id {{ $row->dr_id }} · slip #{{ $row->gajidoctor_no }}
                                @if ($row->versi_sebelumnya)<x-badge variant="warning">versi {{ $row->versi }}</x-badge>@else · versi {{ $row->versi }}@endif
                            </span>
                        </td>
                        <td>
                            @if ($row->emp_id_map)
                                <span>{{ $row->emp_name }}</span><span class="block font-mono text-xs text-muted">{{ $row->emp_id_map }}</span>
                            @else
                                <x-badge variant="warning">Belum dipetakan</x-badge>
                            @endif
                        </td>
                        <td class="ds-c">@if ($row->gaji_status === 'F')<x-badge variant="success">Final</x-badge>@else<x-badge variant="gray">Draft</x-badge>@endif
                            <span class="block text-xs text-muted">{{ $row->npwp_status === 'Y' ? 'NPWP' : 'Tanpa NPWP' }} · PPh {{ rtrim(rtrim(number_format((float) $row->pph21_persen, 2, ',', '.'), '0'), ',') }}%</span></td>
                        <td class="font-mono text-right tabular-nums">{{ $rp($row->jasa_total) }}</td>
                        <td class="font-mono text-right tabular-nums">{{ $rp($row->potongan_rs) }}</td>
                        <td class="font-mono text-right tabular-nums">{{ $rp($row->pph21) }}</td>
                        <td class="font-mono text-right tabular-nums">{{ (float) $row->potongan_lain_total != 0 ? $rp($row->potongan_lain_total) : '–' }}</td>
                        <td class="font-mono text-right tabular-nums">{{ (float) $row->tambahan_total != 0 ? $rp($row->tambahan_total) : '–' }}</td>
                        <td class="font-mono font-semibold text-right tabular-nums">{{ $rp($row->gaji_diterima) }}</td>
                        <td class="font-mono text-xs text-right tabular-nums {{ $row->selisih_bulan_lalu === null ? 'text-muted' : ($row->selisih_bulan_lalu < 0 ? 'text-fg-danger-strong' : 'text-fg-success-strong') }}">{{ $selisih($row->selisih_bulan_lalu) }}</td>
                        <td class="text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <x-secondary-button type="button" wire:click="openRincian({{ $row->gd_id }})" wire:loading.attr="disabled" wire:target="openRincian" class="!px-3 !py-1.5 text-xs">Rincian</x-secondary-button>
                                <x-secondary-button type="button" wire:click="openLampiran({{ $row->gd_id }})" wire:loading.attr="disabled" wire:target="openLampiran" class="!px-3 !py-1.5 text-xs" title="{{ $row->jumlah_lampiran }} baris lampiran">Lampiran <span class="font-mono text-muted">{{ $row->jumlah_lampiran }}</span></x-secondary-button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="py-8 text-center text-muted">{{ $total['jumlah'] === 0 ? 'Belum ada snapshot bulan jasa ini.' : 'Tidak ada slip yang cocok dengan saringan.' }}</td></tr>
                @endforelse
            </tbody>
            @if ($total['jumlah'] > 0 && $saring === 'semua' && trim($searchKeyword) === '')
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="3">Total {{ $total['jumlah'] }} dokter</td>
                        <td class="font-mono text-right tabular-nums">{{ $rp($total['jasa_total']) }}</td>
                        <td class="font-mono text-right tabular-nums">{{ $rp($total['potongan_rs']) }}</td>
                        <td class="font-mono text-right tabular-nums">{{ $rp($total['pph21']) }}</td>
                        <td class="font-mono text-right tabular-nums">{{ $rp($total['potongan_lain_total']) }}</td>
                        <td class="font-mono text-right tabular-nums">{{ $rp($total['tambahan_total']) }}</td>
                        <td class="font-mono text-right tabular-nums">{{ $rp($total['gaji_diterima']) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    {{-- MODAL RINCIAN --}}
    <x-modal name="gaji-dokter-rincian" size="2xl">
        @php $h = $this->rincian; $p = $this->pembanding; @endphp
        <div class="flex flex-col min-h-full">
            <x-modul-dokumen.header judul="{{ $h ? $h->dr_name : 'Rincian slip' }}" tutup="closeModal"
                ikon="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                @if ($h)Bulan jasa {{ \Carbon\Carbon::createFromFormat('!Y-m', $h->periode)->translatedFormat('F Y') }} · versi {{ $h->versi }} · diambil {{ \Carbon\Carbon::parse($h->diambil_at)->format('d/m/Y H:i') }} · {{ $h->gaji_status === 'F' ? 'Final' : 'Draft' }} di SIRUS @if ($h->update_date_sirus)(diubah di SIRUS {{ $h->update_date_sirus }})@endif @endif
            </x-modul-dokumen.header>
            @if ($h)
                <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
                    <div class="flex flex-wrap items-end gap-3 mb-3">
                        <div class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <span class="text-muted">Gaji pokok (skema {{ $h->skema_gaji_pokok }})</span><span class="font-mono text-right">{{ $rp($h->nilai_gaji_pokok) }}</span>
                            <span class="text-muted">Potongan RS</span><span class="font-mono text-right">{{ rtrim(rtrim(number_format((float) $h->potongan_rs_persen, 2, ',', '.'), '0'), ',') }}% dari {{ $h->potongan_rs_basis === 'J' ? 'jasa' : 'total' }}</span>
                            <span class="text-muted">PPh 21</span><span class="font-mono text-right">{{ rtrim(rtrim(number_format((float) $h->pph21_persen, 2, ',', '.'), '0'), ',') }}% · {{ $h->npwp_status === 'Y' ? 'NPWP' : 'tanpa NPWP' }}</span>
                            <span class="text-muted">Dibayar</span><span class="font-mono text-right">{{ $h->tanggal_bayar ? \Carbon\Carbon::parse($h->tanggal_bayar)->format('d/m/Y') : '–' }}</span>
                        </div>
                        <div class="ml-auto w-72">
                            <x-input-label for="gd-banding" value="Bandingkan dengan" />
                            <div class="mt-1">
                                <x-select-input id="gd-banding" wire:model.live="bandingPilihan">
                                    <option value="">– tanpa pembanding –</option>
                                    @foreach ($this->bandingOpsi() as $kunci => $label)
                                        <option value="{{ $kunci }}">{{ $label }}</option>
                                    @endforeach
                                </x-select-input>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto border bg-canvas border-hairline rounded-xl dark:bg-gray-900 dark:border-gray-700">
                        <table class="ds-table">
                            <thead>
                                <tr>
                                    <th>Komponen</th>
                                    <th class="text-right">Pasien</th>
                                    <th class="text-right">Versi {{ $h->versi }}</th>
                                    @if ($p)
                                        <th class="text-right">{{ $p->periode === $h->periode ? 'Versi ' . $p->versi : \Carbon\Carbon::createFromFormat('!Y-m', $p->periode)->translatedFormat('M Y') }}</th>
                                        <th class="text-right">Selisih</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @php $jenisTerakhir = null; @endphp
                                @foreach ($this->barisRincian as $b)
                                    @if ($b['jenis'] !== $jenisTerakhir)
                                        @php $jenisTerakhir = $b['jenis']; @endphp
                                        <tr class="bg-surface-soft dark:bg-gray-800/60"><td colspan="{{ $p ? 5 : 3 }}" class="py-1 text-xs font-semibold uppercase text-muted">{{ GajiDokterSirus::LABEL_JENIS[$b['jenis']] ?? $b['jenis'] }}</td></tr>
                                    @endif
                                    @php $beda = $p && (($b['nilai'] ?? 0) != ($b['nilai_lain'] ?? 0) || ($b['pasien'] ?? 0) != ($b['pasien_lain'] ?? 0)); @endphp
                                    <tr wire:key="rinci-{{ $b['jenis'] }}-{{ $b['kode'] }}" class="{{ $beda ? 'bg-warning-soft/40' : '' }}">
                                        <td>{{ $b['label'] }} @if ($b['manual'])<x-badge variant="info">manual</x-badge>@endif @if ($b['nilai'] === null)<x-badge variant="gray">tidak ada di versi ini</x-badge>@endif</td>
                                        <td class="font-mono text-right tabular-nums">{{ $b['pasien'] ? $b['pasien'] : '' }}@if ($p && $beda && ($b['pasien_lain'] ?? null) !== ($b['pasien'] ?? null))<span class="text-xs text-muted"> ({{ $b['pasien_lain'] ?? 0 }})</span>@endif</td>
                                        <td class="font-mono text-right tabular-nums">{{ $b['nilai'] === null ? '–' : $rp($b['nilai']) }}</td>
                                        @if ($p)
                                            <td class="font-mono text-right tabular-nums text-muted">{{ $b['nilai_lain'] === null ? '–' : $rp($b['nilai_lain']) }}</td>
                                            <td class="font-mono text-xs text-right tabular-nums {{ !$beda ? 'text-muted' : '' }}">{{ $beda ? $selisih(($b['nilai'] ?? 0) - ($b['nilai_lain'] ?? 0)) : '=' }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                @foreach (GajiDokterSirus::KOLOM_NOMINAL as $k)
                                    <tr class="{{ $k === 'gaji_diterima' ? 'font-semibold' : '' }}">
                                        <td colspan="2">{{ GajiDokterSirus::LABEL_NOMINAL[$k] }}</td>
                                        <td class="font-mono text-right tabular-nums">{{ $rp($h->$k) }}</td>
                                        @if ($p)
                                            <td class="font-mono text-right tabular-nums text-muted">{{ $rp($p->$k) }}</td>
                                            <td class="font-mono text-xs text-right tabular-nums {{ abs((float) $h->$k - (float) $p->$k) < 0.5 ? 'text-muted' : '' }}">{{ abs((float) $h->$k - (float) $p->$k) < 0.5 ? '=' : $selisih((float) $h->$k - (float) $p->$k) }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tfoot>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-muted">Potongan tampil sebagai angka negatif, sama seperti di SIRUS. Angka ini snapshot SIAP — tidak bisa disunting; bila SIRUS berubah, Ambil ulang membuat versi baru dan versi ini tetap tersimpan.</p>
                </div>
                <div class="flex justify-end gap-2 px-4 py-3 border-t border-hairline dark:border-gray-700">
                    <x-secondary-button type="button" wire:click="openLampiran({{ $h->gd_id }})">Lihat lampiran</x-secondary-button>
                    <x-primary-button type="button" wire:click="closeModal">Tutup</x-primary-button>
                </div>
            @endif
        </div>
    </x-modal>
</div>
