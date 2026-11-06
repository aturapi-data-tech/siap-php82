<?php

use Livewire\Component;
use Livewire\Attributes\Url;

/**
 * GAJI DOKTER (SIRUS) — pembungkus tab (pola docs/tabs-pattern.md, mode Server @if: hanya tab aktif yang di-mount).
 *
 *   BUKU     : pages::keuangan.gaji-dokter.gaji-dokter-buku     — slip gaji dokter per bulan jasa (snapshot versi terbaru), Ambil dari SIRUS, rincian & banding versi / bulan lalu.
 *   PEMETAAN : pages::keuangan.gaji-dokter.gaji-dokter-pemetaan — dokter SIRUS ↔ karyawan SIAP (LOV), segarkan master dokter.
 *   STRUKTUR : pages::keuangan.gaji-dokter.gaji-dokter-struktur — struktur gaji per dokter + mode pemindahan ke SIAP (tahap 1–3).
 *   RIWAYAT  : pages::keuangan.gaji-dokter.gaji-dokter-riwayat  — jejak tiap penarikan (baru / berubah / sama).
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
    public string $tab = 'buku'; // buku | pemetaan | struktur | riwayat

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!in_array($this->tab, ['buku', 'pemetaan', 'struktur', 'riwayat'], true)) {
            $this->tab = 'buku';
        }
    }

    public function setTab(string $tab): void
    {
        if (!in_array($tab, ['buku', 'pemetaan', 'struktur', 'riwayat'], true)) {
            return;
        }
        $this->tab = $tab;
    }
};
?>

<div>
    <x-page-title title="Gaji Dokter (SIRUS)"
        subtitle="Slip gaji dokter spesialis dihitung di SIRUS; SIAP menariknya per bulan jasa sebagai snapshot berversi — angka yang sudah diproses tidak berubah, perubahan di SIRUS tampil sebagai perbandingan" />

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">
            <div class="flex flex-wrap items-end justify-between gap-3 px-4">
                <x-tabs variant="underline" class="border-0">
                    <x-tab :active="$tab === 'buku'" wire:click="setTab('buku')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>Buku Gaji Dokter
                    </x-tab>
                    <x-tab :active="$tab === 'pemetaan'" wire:click="setTab('pemetaan')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>Pemetaan Dokter
                    </x-tab>
                    <x-tab :active="$tab === 'struktur'" wire:click="setTab('struktur')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>Struktur Gaji
                    </x-tab>
                    <x-tab :active="$tab === 'riwayat'" wire:click="setTab('riwayat')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>Riwayat Ambil
                    </x-tab>
                </x-tabs>
                <a href="{{ route('keuangan.gaji-karyawan') }}" wire:navigate
                    class="inline-flex items-center gap-1 px-3 py-2.5 text-sm font-medium rounded-lg text-brand-green hover:underline dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Ke Gaji Karyawan →</a>
            </div>
            <div class="border-b border-hairline dark:border-gray-700"></div>

            @if ($tab === 'pemetaan')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-pemetaan wire:key="gaji-dokter-pemetaan" />
            @elseif ($tab === 'struktur')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-struktur wire:key="gaji-dokter-struktur" />
            @elseif ($tab === 'riwayat')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-riwayat wire:key="gaji-dokter-riwayat" />
            @else
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-buku wire:key="gaji-dokter-buku" />
            @endif
        </div>
    </div>

    {{-- Modal Rincian & Lampiran berdiri SEJAJAR dengan tab (bukan di dalam Buku, dan tidak bersarang satu sama lain):
         tidak ikut unmount saat ganti tab, dan tombol Lampiran ada di dua tempat (baris Buku & footer Rincian) — pola SIRUS. --}}
    <livewire:pages::keuangan.gaji-dokter.gaji-dokter-rincian wire:key="gaji-dokter-rincian" />
    <livewire:pages::keuangan.gaji-dokter.gaji-dokter-lampiran wire:key="gaji-dokter-lampiran" />
    <livewire:pages::keuangan.gaji-dokter.gaji-dokter-uji-hitung wire:key="gaji-dokter-uji-hitung" />
</div>
