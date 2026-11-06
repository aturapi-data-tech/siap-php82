<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\GajiDokterSirus;
use App\Support\GajiKaryawan;

/**
 * Modal RINCIAN slip gaji dokter (SIRUS) — komponen terpisah dari daftar (pola gaji-dokter-rincian di SIRUS), dipasang
 * pembungkus gaji-dokter SEJAJAR tab supaya tidak ikut unmount saat ganti tab. Dibuka lewat event
 * 'keuangan.gaji-dokter.openRincian' (gd_id) dari Buku. Isi: kartu identitas, strip snapshot + pembanding
 * (versi lain / bulan lalu), blok Jasa & Tunjangan | Potongan | Tambahan dengan selisih, footer angka inti. Hanya baca.
 * Tombol Lampiran di footer → tutup modal lalu event 'keuangan.gaji-dokter.openLampiran' (ditangkap pembungkus).
 */
new class extends Component {
    /** Header snapshot yang dibuka + pembanding (gd_id versi lain / bulan lalu, atau ''). */
    public ?int $rincianGdId = null;
    public string $bandingPilihan = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    #[On('keuangan.gaji-dokter.openRincian')]
    public function openRincian(int $gdId): void
    {
        $this->rincianGdId = $gdId;
        $this->bandingPilihan = '';
        unset($this->rincian, $this->pembanding, $this->barisRincian);
        $this->bandingPilihan = array_key_first($this->bandingOpsi()) ?? '';
        $this->dispatch('open-modal', name: 'gaji-dokter-rincian');
    }

    public function closeModal(): void
    {
        $this->rincianGdId = null;
        $this->bandingPilihan = '';
        unset($this->rincian, $this->pembanding, $this->barisRincian);
        $this->dispatch('close-modal', name: 'gaji-dokter-rincian');
    }

    public function openLampiran(int $gdId): void
    {
        $this->closeModal();
        $this->dispatch('keuangan.gaji-dokter.openLampiran', gdId: $gdId);
    }

    public static function namaPeriode(?string $ym): string
    {
        return $ym && preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)
            ? ((['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'][$m[2]] ?? $m[2]) . ' ' . $m[1])
            : (string) $ym;
    }

    #[Computed]
    public function rincian(): ?object
    {
        return $this->rincianGdId ? DB::table('siaptxn_gdok_hdrs')->where('gd_id', $this->rincianGdId)->first() : null;
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
        $lalu = DB::table('siaptxn_gdok_hdrs')->where('periode', $bulanLalu)->where('dr_id', $h->dr_id)->where('terbaru', '1')->first();
        if ($lalu) {
            $opsi[(string) $lalu->gd_id] = 'Bulan jasa ' . Carbon::createFromFormat('!Y-m', $bulanLalu)->translatedFormat('M Y') . " (versi {$lalu->versi})";
        }

        return $opsi;
    }

    #[Computed]
    public function pembanding(): ?object
    {
        return $this->bandingPilihan !== '' && isset($this->bandingOpsi()[$this->bandingPilihan])
            ? DB::table('siaptxn_gdok_hdrs')->where('gd_id', (int) $this->bandingPilihan)->first() : null;
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

<div>
    {{-- Pola Rincian Slip Gaji SIRUS: kartu identitas 5 kolom → strip snapshot & pembanding →
         blok Jasa & Tunjangan | Potongan | Tambahan (tabel kartu + subtotal) → footer angka inti. Hanya baca. --}}
    <x-modal name="gaji-dokter-rincian" size="full" height="full">
        @php
            $rp = fn($n) => GajiKaryawan::rupiah($n);
            $persen = fn($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
            $selisih = fn($n) => $n === null ? '–' : ($n > 0 ? '+' . $rp($n) : ($n < 0 ? '−' . $rp(abs($n)) : '0'));
            $h = $this->rincian;
            $p = $this->pembanding;
        @endphp
        <div class="flex flex-col min-h-[calc(100vh-8rem)]">
            <x-modul-dokumen.header judul="Rincian Slip Gaji" tutup="closeModal"
                ikon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
                @if ($h){{ $h->dr_name }} · snapshot versi {{ $h->versi }} dari SIRUS, hanya baca @endif
            </x-modul-dokumen.header>

            @if ($h)
                @php
                    $labelP = $p ? ($p->periode === $h->periode ? 'Versi ' . $p->versi : $this::namaPeriode($p->periode)) : null;
                    $blok = [
                        'J' => ['judul' => 'Jasa & Tunjangan', 'catatan' => 'Membentuk total gaji — kena PPh.'],
                        'P' => ['judul' => 'Potongan', 'catatan' => 'Dua baris teratas (Potongan RS & PPh 21) berasal dari rumus dan ikut menentukan pajak; sisanya di bawah garis dipotong setelah pajak.'],
                        'T' => ['judul' => 'Tambahan', 'catatan' => 'Diberikan setelah pajak (bonus, rapel, koreksi bulan lalu).'],
                    ];
                    $perJenis = collect($this->barisRincian)->groupBy('jenis');
                @endphp
                <div class="flex-1 px-4 pt-2 pb-4 bg-surface-soft dark:bg-gray-950/20">
                    <div class="space-y-4">

                        {{-- KARTU IDENTITAS DOKTER: kiri (3) identitas & parameter, kanan (2) periode & angka, dipisah garis vertikal. --}}
                        <div class="px-4 py-2.5 text-base border rounded-lg border-hairline bg-canvas dark:bg-gray-900 dark:border-gray-700">
                            <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-5">
                                <div class="space-y-1.5 sm:col-span-3 sm:border-r sm:border-hairline dark:sm:border-gray-700 sm:pr-4">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-xl font-bold truncate text-ink dark:text-white">{{ $h->dr_name }}</span>
                                        <span class="font-mono text-base text-muted dark:text-gray-400 shrink-0">{{ $h->dr_id }}</span>
                                    </div>
                                    <div class="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                                        <div class="space-y-1">
                                            <div><span class="text-muted">Karyawan SIAP:</span>
                                                <span class="ml-1 text-body dark:text-gray-300">
                                                    @php $map = GajiDokterSirus::daftar($h->periode)->firstWhere('dr_id', $h->dr_id); @endphp
                                                    @if ($map && $map->emp_id_map){{ $map->emp_name }} <span class="font-mono text-sm text-muted">{{ $map->emp_id_map }}</span>@else<x-badge variant="warning">belum dipetakan</x-badge>@endif
                                                </span>
                                            </div>
                                            <div><span class="text-muted">Skema:</span>
                                                <span class="ml-1 text-body dark:text-gray-300"><span class="font-mono">{{ $h->skema_gaji_pokok }}</span> &mdash; {{ match ($h->skema_gaji_pokok) { 'G' => 'garanty fee', 'N' => 'tanpa gaji pokok', default => 'aditif' } }}</span>
                                            </div>
                                            <div><span class="text-muted">Gaji Pokok:</span> <span class="ml-1 t-num text-body dark:text-gray-300">{{ $rp($h->nilai_gaji_pokok) }}</span></div>
                                        </div>
                                        <div class="space-y-1">
                                            <div><span class="text-muted">Potongan RS:</span>
                                                <span class="ml-1 text-body dark:text-gray-300"><span class="font-mono">{{ $h->potongan_rs_basis }}</span>
                                                    @if (in_array($h->potongan_rs_basis, ['T', 'J'], true)) &middot; <span class="t-num">{{ $persen($h->potongan_rs_persen) }}%</span> dari {{ $h->potongan_rs_basis === 'J' ? 'jasa' : 'total gaji' }}
                                                    @elseif ($h->potongan_rs_basis === 'B') &middot; berjenjang per komponen
                                                    @else &middot; tidak dipotong @endif
                                                </span>
                                            </div>
                                            <div><span class="text-muted">PPh 21:</span> <span class="ml-1 t-num text-body dark:text-gray-300">{{ $persen($h->pph21_persen) }}% &middot; {{ $h->npwp_status === 'Y' ? 'NPWP' : 'tanpa NPWP' }}</span></div>
                                            <div class="font-mono text-sm text-muted dark:text-gray-400">Slip #{{ $h->gajidoctor_no }}@if ($h->tanggal_bayar) &middot; dibayar {{ \Carbon\Carbon::parse($h->tanggal_bayar)->format('d/m/Y') }}@endif</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-1.5 sm:col-span-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-muted">Status di SIRUS:</span>
                                        <x-badge :variant="$h->gaji_status === 'F' ? 'success' : 'warning'">{{ $h->gaji_status === 'F' ? 'Final' : 'Draft' }}</x-badge>
                                    </div>
                                    <div class="flex items-start justify-between gap-2 text-lg">
                                        <span class="font-semibold text-ink dark:text-white">Jasa {{ \Carbon\Carbon::createFromFormat('!Y-m', $h->periode)->format('m/Y') }}</span>
                                        <span class="font-semibold text-right text-brand-green dark:text-brand-lime">Gaji {{ $h->periode_gaji ? \Carbon\Carbon::createFromFormat('!Y-m', $h->periode_gaji)->format('m/Y') : '–' }}</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-2"><span class="text-muted">Total Gaji:</span> <span class="font-semibold t-num text-ink dark:text-gray-100">{{ $rp($h->total_gaji) }}</span></div>
                                    <div class="flex items-center justify-between gap-2"><span class="text-muted">Gaji Diterima:</span> <span class="text-xl font-bold t-num text-emerald-700 dark:text-emerald-300">{{ $rp($h->gaji_diterima) }}</span></div>
                                </div>
                            </div>
                        </div>

                        {{-- STRIP SNAPSHOT & PEMBANDING (khas SIAP; menggantikan strip "terkunci" di SIRUS) --}}
                        <div class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm border rounded-xl bg-emerald-50 border-emerald-200 text-emerald-900 dark:bg-emerald-900/20 dark:border-emerald-800 dark:text-emerald-200">
                            <span>Snapshot <span class="font-semibold">versi {{ $h->versi }}</span>{{ $h->terbaru === '1' ? ' (terbaru)' : '' }} diambil {{ \Carbon\Carbon::parse($h->diambil_at)->format('d/m/Y H:i') }}@if ($h->update_date_sirus) &middot; terakhir diubah di SIRUS {{ $h->update_date_sirus }}@endif. Angka tidak bisa disunting di SIAP; bila SIRUS berubah, Ambil ulang membuat versi baru.</span>
                            <label class="flex items-center gap-2 ml-auto shrink-0">
                                <span class="font-semibold whitespace-nowrap">Bandingkan dengan</span>
                                <div class="w-64">
                                    <x-select-input id="gd-banding" wire:model.live="bandingPilihan" aria-label="Bandingkan dengan">
                                        <option value="">– tanpa pembanding –</option>
                                        @foreach ($this->bandingOpsi() as $kunci => $label)
                                            <option value="{{ $kunci }}">{{ $label }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                            </label>
                        </div>

                        {{-- BLOK PER JENIS: J | P berdampingan, T merentang dua kolom. --}}
                        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                            @foreach ($blok as $jenis => $meta)
                                @php $baris = $perJenis->get($jenis, collect()); $subtotal = (float) $baris->sum('nilai'); $subtotalP = $p ? (float) $baris->sum('nilai_lain') : null; $sudahDipisah = false; @endphp
                                <div class="{{ $jenis === 'T' ? 'xl:col-span-2' : '' }}">
                                    <x-border-form :title="$meta['judul']">
                                        <div class="space-y-3">
                                            <p class="text-sm text-muted dark:text-gray-400">{{ $meta['catatan'] }}</p>
                                            <div class="overflow-x-auto overflow-y-auto max-h-[26rem] rounded-2xl">
                                                <table class="w-full min-w-full -mt-2 text-sm border-separate border-spacing-y-2 table-fixed">
                                                    <thead class="sticky top-0 z-10">
                                                        <tr class="text-sm font-semibold tracking-wide text-left uppercase text-muted dark:text-gray-300">
                                                            <th class="px-4 py-3 {{ $p ? 'w-[36%]' : 'w-[52%]' }} bg-surface-card dark:bg-gray-800">Keterangan</th>
                                                            <th class="px-3 py-3 w-[16%] text-right bg-surface-card dark:bg-gray-800">Jml Pasien</th>
                                                            <th class="px-3 py-3 {{ $p ? 'w-[18%]' : 'w-[32%]' }} text-right bg-surface-card dark:bg-gray-800">Nilai</th>
                                                            @if ($p)
                                                                <th class="px-3 py-3 w-[16%] text-right bg-surface-card dark:bg-gray-800">{{ $labelP }}</th>
                                                                <th class="px-3 py-3 w-[14%] text-right bg-surface-card dark:bg-gray-800">Selisih</th>
                                                            @endif
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @forelse ($baris as $b)
                                                            @php
                                                                $terhitung = $jenis === 'P' && in_array($b['kode'], ['RS', 'PPH21'], true);
                                                                $beda = $p && (($b['nilai'] ?? 0) != ($b['nilai_lain'] ?? 0) || ($b['pasien'] ?? 0) != ($b['pasien_lain'] ?? 0));
                                                            @endphp
                                                            @if ($jenis === 'P' && !$terhitung && !$sudahDipisah)
                                                                @php $sudahDipisah = true; @endphp
                                                                <tr><td colspan="{{ $p ? 5 : 3 }}" class="px-4 pt-3 pb-1 text-sm text-muted dark:text-gray-400">&darr; dipotong setelah pajak &mdash; tidak mengurangi dasar PPh</td></tr>
                                                            @endif
                                                            <tr wire:key="rinci-{{ $jenis }}-{{ $b['kode'] }}"
                                                                class="transition rounded-2xl shadow-sm ring-1 ring-hairline dark:ring-gray-700 {{ $beda ? 'bg-warning/10 dark:bg-amber-900/15' : ($terhitung ? 'bg-surface-soft dark:bg-gray-800' : 'bg-canvas dark:bg-gray-900 hover:bg-surface-soft dark:hover:bg-gray-800') }}">
                                                                <td class="px-4 py-2 align-middle rounded-l-2xl" title="Kode: {{ $b['kode'] }}">
                                                                    {{ $b['label'] }}
                                                                    @if ($b['manual'])<x-badge variant="info">manual</x-badge>@elseif ($terhitung)<span class="ml-1 text-sm text-muted dark:text-gray-400">(dari rumus)</span>@endif
                                                                    @if ($b['nilai'] === null)<x-badge variant="gray">tidak ada di versi ini</x-badge>@endif
                                                                </td>
                                                                <td class="px-3 py-2 text-right align-middle tabular-nums">{{ $b['pasien'] ?: '' }}@if ($beda && ($b['pasien_lain'] ?? null) !== ($b['pasien'] ?? null))<span class="text-xs text-muted"> ({{ $b['pasien_lain'] ?? 0 }})</span>@endif</td>
                                                                <td class="px-3 py-2 text-right align-middle tabular-nums {{ ($b['nilai'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-300' : '' }} {{ $p ? '' : 'rounded-r-2xl' }}">{{ $b['nilai'] === null ? '–' : $rp($b['nilai']) }}</td>
                                                                @if ($p)
                                                                    <td class="px-3 py-2 text-right align-middle tabular-nums text-muted dark:text-gray-400">{{ $b['nilai_lain'] === null ? '–' : $rp($b['nilai_lain']) }}</td>
                                                                    <td class="px-3 py-2 text-xs text-right align-middle tabular-nums rounded-r-2xl {{ $beda ? 'font-semibold text-amber-800 dark:text-amber-300' : 'text-muted' }}">{{ $beda ? $selisih(($b['nilai'] ?? 0) - ($b['nilai_lain'] ?? 0)) : '=' }}</td>
                                                                @endif
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="{{ $p ? 5 : 3 }}" class="px-4 py-6 text-center text-muted dark:text-gray-400">Tidak ada baris.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="rounded-2xl ring-1 ring-hairline bg-surface-card dark:bg-gray-800 dark:ring-gray-700">
                                                            <td class="px-4 py-3 text-sm font-semibold tracking-wide uppercase rounded-l-2xl text-muted dark:text-gray-300" colspan="2">Subtotal</td>
                                                            <td class="px-3 py-3 text-lg font-bold text-right tabular-nums {{ $p ? '' : 'rounded-r-2xl' }} {{ $subtotal < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-ink dark:text-gray-100' }}">{{ $rp($subtotal) }}</td>
                                                            @if ($p)
                                                                <td class="px-3 py-3 text-right tabular-nums text-muted dark:text-gray-400">{{ $rp($subtotalP) }}</td>
                                                                <td class="px-3 py-3 text-xs text-right tabular-nums rounded-r-2xl {{ abs($subtotal - $subtotalP) < 0.5 ? 'text-muted' : 'font-semibold text-amber-800 dark:text-amber-300' }}">{{ abs($subtotal - $subtotalP) < 0.5 ? '=' : $selisih($subtotal - $subtotalP) }}</td>
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

                {{-- FOOTER: angka inti + aksi. Gaji Diterima paling besar — itu yang benar-benar dibayarkan. --}}
                <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="grid flex-1 grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-4">
                            @foreach (['total_gaji' => ['Total Gaji', 'text-ink dark:text-gray-100'], 'potongan_rs' => ['Potongan RS', 'text-rose-600 dark:text-rose-300'], 'pph21' => ['PPh 21', 'text-rose-600 dark:text-rose-300']] as $k => [$label, $warna])
                                <div>
                                    <div class="text-sm tracking-wide uppercase text-muted dark:text-gray-400">{{ $label }}</div>
                                    <div class="text-lg font-semibold tabular-nums {{ $warna }}">{{ $rp($h->$k) }}@if ($p && abs((float) $h->$k - (float) $p->$k) >= 0.5)<span class="ml-1 text-xs font-normal text-amber-800 dark:text-amber-300">{{ $selisih((float) $h->$k - (float) $p->$k) }}</span>@endif</div>
                                </div>
                            @endforeach
                            <div>
                                <div class="text-sm tracking-wide uppercase text-muted dark:text-gray-400">Gaji Diterima</div>
                                <div class="text-2xl font-bold leading-tight tabular-nums text-emerald-700 dark:text-emerald-300">{{ $rp($h->gaji_diterima) }}@if ($p && abs((float) $h->gaji_diterima - (float) $p->gaji_diterima) >= 0.5)<span class="ml-1 text-xs font-normal text-amber-800 dark:text-amber-300">{{ $selisih((float) $h->gaji_diterima - (float) $p->gaji_diterima) }} vs {{ $labelP }}</span>@endif</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
                            <x-outline-button type="button" class="gap-2" wire:click="openLampiran({{ $h->gd_id }})" wire:loading.attr="disabled" wire:target="openLampiran" title="Lihat lampiran rincian pasien (tanggal layanan, no. RM, nama, nominal)">
                                <span wire:loading.remove wire:target="openLampiran" class="inline-flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" /></svg>
                                    Lampiran
                                </span>
                                <span wire:loading wire:target="openLampiran" class="inline-flex items-center gap-2"><x-loading /> Menyiapkan...</span>
                            </x-outline-button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </x-modal>
</div>
