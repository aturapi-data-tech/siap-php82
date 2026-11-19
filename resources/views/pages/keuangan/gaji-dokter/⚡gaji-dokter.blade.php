<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * GAJI DOKTER (SIRUS) — pembungkus tab (pola docs/tabs-pattern.md, mode Server @if: hanya tab aktif yang di-mount).
 *
 * Tab bernomor = urutan kerja bulanan; Struktur & Pemetaan = setelan (dipisah garis di kanan):
 *   1 AMBIL    : pages::keuangan.gaji-dokter.gaji-dokter-ambil    — bulan jasa + Ambil dari SIRUS (transaksi SIRUS dihitung dengan struktur SIAP), hasil & riwayat ambil.
 *   2 BUKU     : pages::keuangan.gaji-dokter.gaji-dokter-buku     — slip per bulan jasa (versi DIPAKAI), keputusan versi baru, Rincian/Lampiran, Final, Terapkan ke slip gaji.
 *   3 BANDING  : pages::keuangan.gaji-dokter.gaji-dokter-banding  — bandingkan dua versi satu dokter sampai baris pasien, lalu Pakai versi ini (pola SIKEU).
 *   STRUKTUR   : pages::keuangan.gaji-dokter.gaji-dokter-struktur — struktur gaji per dokter (disetel di SIAP).
 *   PEMETAAN   : pages::keuangan.gaji-dokter.gaji-dokter-pemetaan — dokter SIRUS ↔ karyawan SIAP (LOV), segarkan master dokter.
 * Anak tab pindah tab lewat event 'keuangan.gaji-dokter.pindahTab' (tab).
 *
 * Modal Rincian & Lampiran = komponen terpisah yang dipasang di sini SEJAJAR tab (pola Slip Gaji Dokter SIRUS), dibuka lewat event:
 *   'keuangan.gaji-dokter.openRincianSlipDokter' (gdId)           → modal gaji-dokter-rincian
 *   'keuangan.gaji-dokter.openLampiranPasienDokter' (gdId)          → modal gaji-dokter-lampiran (satu dokter; dari baris Buku / footer Rincian)
 *   'keuangan.gaji-dokter.openLampiranPasienSemuaDokter' (gdIdList) → modal gaji-dokter-lampiran (semua dokter yang tampil di Buku)
 * SIAP hanya MEMBACA SIRUS (App\Support\GajiDokterSirus); angka yang sudah tersimpan tidak berubah tanpa versi baru.
 * Hanya role gaji.kelola.
 */
new class extends Component {
    #[Url(as: 'tab')]
    public string $tab = 'ambil'; // ambil | buku | banding | struktur | pemetaan

    private const TAB = ['ambil', 'buku', 'banding', 'struktur', 'pemetaan'];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if ($this->tab === 'riwayat') {
            $this->tab = 'ambil'; // tautan lama
        }
        if (!in_array($this->tab, self::TAB, true)) {
            $this->tab = 'ambil';
        }
    }

    #[On('keuangan.gaji-dokter.pindahTab')]
    public function setTab(string $tab): void
    {
        if (!in_array($tab, self::TAB, true)) {
            return;
        }
        $this->tab = $tab;
    }
};
?>

<div>
    <x-page-title title="Gaji Dokter"
        subtitle="Slip gaji dokter spesialis per bulan jasa dari transaksi SIRUS — struktur gaji disetel di sini, snapshot berversi (angka yang sudah diproses tidak berubah diam-diam), lalu diterapkan ke slip gaji karyawan" />

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">
            <div class="flex flex-wrap items-end justify-between gap-3 px-4">
                <x-tabs variant="underline" class="border-0">
                    <x-tab :active="$tab === 'ambil'" wire:click="setTab('ambil')" aria-label="Langkah 1: Ambil Data">
                        <span class="inline-flex items-center justify-center w-5 h-5 mr-1.5 text-xs font-semibold rounded-full bg-surface-strong text-brand-green-active dark:bg-gray-800 dark:text-brand-lime" aria-hidden="true">1</span>Ambil Data
                    </x-tab>
                    <x-tab :active="$tab === 'buku'" wire:click="setTab('buku')" aria-label="Langkah 2: Buku Gaji Dokter">
                        <span class="inline-flex items-center justify-center w-5 h-5 mr-1.5 text-xs font-semibold rounded-full bg-surface-strong text-brand-green-active dark:bg-gray-800 dark:text-brand-lime" aria-hidden="true">2</span>Buku Gaji Dokter
                    </x-tab>
                    <x-tab :active="$tab === 'banding'" wire:click="setTab('banding')" aria-label="Langkah 3: Bandingkan Versi">
                        <span class="inline-flex items-center justify-center w-5 h-5 mr-1.5 text-xs font-semibold rounded-full bg-surface-strong text-brand-green-active dark:bg-gray-800 dark:text-brand-lime" aria-hidden="true">3</span>Bandingkan Versi
                    </x-tab>
                    <span class="self-center h-5 mx-2 border-l border-hairline dark:border-gray-700" aria-hidden="true"></span>
                    <x-tab :active="$tab === 'struktur'" wire:click="setTab('struktur')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>Struktur Gaji
                    </x-tab>
                    <x-tab :active="$tab === 'pemetaan'" wire:click="setTab('pemetaan')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>Pemetaan Dokter
                    </x-tab>
                </x-tabs>
                <a href="{{ route('keuangan.gaji-karyawan') }}" wire:navigate
                    class="inline-flex items-center gap-1 px-3 py-2.5 text-sm font-medium rounded-lg text-brand-green hover:underline dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Ke Gaji Karyawan →</a>
            </div>
            <div class="border-b border-hairline dark:border-gray-700"></div>

            @if ($tab === 'buku')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-buku wire:key="gaji-dokter-buku" />
            @elseif ($tab === 'banding')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-banding wire:key="gaji-dokter-banding" />
            @elseif ($tab === 'struktur')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-struktur wire:key="gaji-dokter-struktur" />
            @elseif ($tab === 'pemetaan')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-pemetaan wire:key="gaji-dokter-pemetaan" />
            @else
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-ambil wire:key="gaji-dokter-ambil" />
            @endif
        </div>
    </div>

    {{-- Modal Rincian & Lampiran berdiri SEJAJAR dengan tab (bukan di dalam Buku, dan tidak bersarang satu sama lain):
         tidak ikut unmount saat ganti tab, dan tombol Lampiran ada di dua tempat (baris Buku & footer Rincian) — pola SIRUS. --}}
    <livewire:pages::keuangan.gaji-dokter.gaji-dokter-rincian wire:key="gaji-dokter-rincian" />
    <livewire:pages::keuangan.gaji-dokter.gaji-dokter-lampiran wire:key="gaji-dokter-lampiran" />
</div>
