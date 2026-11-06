<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * GAJI DOKTER (SIRUS) — pembungkus tab (pola docs/tabs-pattern.md, mode Server @if: hanya tab aktif yang di-mount).
 *
 *   BUKU     : pages::keuangan.gaji-dokter.gaji-dokter-buku     — slip gaji dokter per bulan jasa (snapshot versi terbaru), Ambil dari SIRUS, rincian & banding versi / bulan lalu.
 *   LAMPIRAN : pages::keuangan.gaji-dokter.gaji-dokter-lampiran — rincian pasien / transaksi per dokter per komponen (dasar angka slip).
 *   PEMETAAN : pages::keuangan.gaji-dokter.gaji-dokter-pemetaan — dokter SIRUS ↔ karyawan SIAP (LOV), segarkan master dokter.
 *   RIWAYAT  : pages::keuangan.gaji-dokter.gaji-dokter-riwayat  — jejak tiap penarikan (baru / berubah / sama).
 *
 * Komunikasi antar tab lewat event: buku → 'keuangan.gaji-dokter.openLampiran' (gdId) → pembungkus pindah ke Lampiran;
 *   buku → 'keuangan.gaji-dokter.openRincian' (gdId) → modal gaji-dokter-rincian (dipasang di pembungkus, sejajar tab).
 * SIAP hanya MEMBACA SIRUS (App\Support\GajiDokterSirus); angka yang sudah tersimpan tidak berubah tanpa versi baru.
 * Hanya role gaji.kelola.
 */
new class extends Component {
    #[Url(as: 'tab')]
    public string $tab = 'buku'; // buku | lampiran | pemetaan | riwayat

    /** Header snapshot yang dibuka di tab Lampiran (tombol Lampiran di Buku). */
    public ?int $lampiranGdId = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!in_array($this->tab, ['buku', 'lampiran', 'pemetaan', 'riwayat'], true)) {
            $this->tab = 'buku';
        }
    }

    public function setTab(string $tab): void
    {
        if (!in_array($tab, ['buku', 'lampiran', 'pemetaan', 'riwayat'], true)) {
            return;
        }
        $this->tab = $tab;
    }

    #[On('keuangan.gaji-dokter.openLampiran')]
    public function openLampiran(int $gdId): void
    {
        $this->lampiranGdId = $gdId;
        $this->tab = 'lampiran';
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
                    <x-tab :active="$tab === 'lampiran'" wire:click="setTab('lampiran')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>Lampiran
                    </x-tab>
                    <x-tab :active="$tab === 'pemetaan'" wire:click="setTab('pemetaan')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>Pemetaan Dokter
                    </x-tab>
                    <x-tab :active="$tab === 'riwayat'" wire:click="setTab('riwayat')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>Riwayat Ambil
                    </x-tab>
                </x-tabs>
                <a href="{{ route('keuangan.gaji-karyawan') }}" wire:navigate
                    class="inline-flex items-center gap-1 px-3 py-2.5 text-sm font-medium rounded-lg text-brand-green hover:underline dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Ke Gaji Karyawan →</a>
            </div>
            <div class="border-b border-hairline dark:border-gray-700"></div>

            @if ($tab === 'lampiran')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-lampiran :gd-id="$lampiranGdId" wire:key="gaji-dokter-lampiran-{{ $lampiranGdId ?? 'kosong' }}" />
            @elseif ($tab === 'pemetaan')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-pemetaan wire:key="gaji-dokter-pemetaan" />
            @elseif ($tab === 'riwayat')
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-riwayat wire:key="gaji-dokter-riwayat" />
            @else
                <livewire:pages::keuangan.gaji-dokter.gaji-dokter-buku wire:key="gaji-dokter-buku" />
            @endif
        </div>
    </div>

    {{-- Modal Rincian berdiri SEJAJAR dengan tab (bukan di dalam Buku): tidak ikut unmount saat ganti tab, dan tombol
         Lampiran di footernya mengirim event ke pembungkus ini (pola SIRUS). --}}
    <livewire:pages::keuangan.gaji-dokter.gaji-dokter-rincian wire:key="gaji-dokter-rincian" />
</div>
