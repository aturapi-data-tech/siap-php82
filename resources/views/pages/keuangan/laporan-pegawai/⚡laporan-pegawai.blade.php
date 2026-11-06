<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * LAPORAN KEPEGAWAIAN — pembungkus tab (pola docs/tabs-pattern.md, mode Server @if: hanya tab aktif yang di-mount).
 *
 *   ENTRY : pages::keuangan.laporan-pegawai.laporan-entry — tabel isian kepegawaian (hari kerja, cuti, jam lembur, keterangan),
 *           kolom terisi bawaan dari setup master, disimpan saat kotak ditinggalkan. Role laporanPegawai.isi.
 *   BUKU  : pages::keuangan.laporan-pegawai.laporan-buku  — laporan bulan kerja (baca saja) + kecocokan slip + Terapkan ke Slip (gaji.kelola).
 *
 * Tab bawaan: Entry bagi yang hanya boleh mengisi (HRD), Buku bagi Keuangan / Admin. Tab tersimpan di URL (?tab=).
 * Hak buka halaman: laporanPegawai.lihat.
 */
new class extends Component {
    #[Url(as: 'tab')]
    public string $tab = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('laporanPegawai.lihat'), 403);
    }

    public function mount(): void
    {
        if (!in_array($this->tab, ['entry', 'buku'], true)) {
            $user = auth()->user();
            $this->tab = $user?->can('laporanPegawai.isi') && !$user?->can('gaji.kelola') ? 'entry' : 'buku';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['entry', 'buku'], true)) {
            $this->tab = $tab;
        }
    }

    #[On('keuangan.laporanPegawai.keEntry')]
    public function keEntry(): void
    {
        $this->tab = 'entry';
    }

    #[On('keuangan.laporanPegawai.keBuku')]
    public function keBuku(): void
    {
        $this->tab = 'buku';
    }
};
?>

<div>
    <x-page-title title="Laporan Kepegawaian"
        subtitle="Hari kerja, cuti & jam lembur tiap karyawan per bulan kerja — terisi dari master, kepegawaian mengubah yang berbeda (Entry), Keuangan menerapkan ke slip gaji (Buku)" />

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">
            <div class="flex flex-wrap items-end justify-between gap-3 px-4">
                <x-tabs variant="underline" class="border-0">
                    <x-tab :active="$tab === 'entry'" wire:click="setTab('entry')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>Entry Laporan
                    </x-tab>
                    <x-tab :active="$tab === 'buku'" wire:click="setTab('buku')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>Buku Laporan
                    </x-tab>
                </x-tabs>
                @can('gaji.kelola')
                    <a href="{{ route('keuangan.gaji-karyawan') }}" wire:navigate
                        class="inline-flex items-center gap-1 px-3 py-2.5 text-sm font-medium rounded-lg text-brand-green hover:underline dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Ke Gaji Karyawan →</a>
                @endcan
            </div>
            <div class="border-b border-hairline dark:border-gray-700"></div>

            @if ($tab === 'entry')
                <livewire:pages::keuangan.laporan-pegawai.laporan-entry wire:key="laporan-entry" />
            @else
                <livewire:pages::keuangan.laporan-pegawai.laporan-buku wire:key="laporan-buku" />
            @endif
        </div>
    </div>
</div>
