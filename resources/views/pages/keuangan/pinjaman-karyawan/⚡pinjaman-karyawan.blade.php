<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * PINJAMAN KARYAWAN — pembungkus tab (pola docs/tabs-pattern.md, mode Server @if: hanya tab aktif yang di-mount).
 *
 *   ENTRY : pages::keuangan.pinjaman-karyawan.pinjaman-entry — form catat / ubah pinjaman + ketentuan per sumber.
 *   BUKU  : pages::keuangan.pinjaman-karyawan.pinjaman-buku  — daftar pinjaman per bulan kerja + Terapkan ke Slip.
 *
 * Komunikasi antar tab lewat event:
 *   buku  → 'keuangan.pinjaman.openEdit' (id) / 'keuangan.pinjaman.openTambah'  → pembungkus pindah ke Entry
 *   entry → 'keuangan.pinjaman.saved' / 'keuangan.pinjaman.batal'               → pembungkus pindah ke Buku
 * Hanya role gaji.kelola (data gaji & pinjaman = rahasia).
 */
new class extends Component {
    #[Url(as: 'tab')]
    public string $tab = 'buku'; // entry | buku

    /** Pinjaman yang dibuka untuk diubah di tab Entry (null = pinjaman baru). */
    public ?int $editId = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!in_array($this->tab, ['entry', 'buku'], true)) {
            $this->tab = 'buku';
        }
    }

    public function setTab(string $tab): void
    {
        if (!in_array($tab, ['entry', 'buku'], true)) {
            return;
        }
        if ($tab === 'entry' && $this->tab !== 'entry') {
            $this->editId = null;
        }
        $this->tab = $tab;
    }

    #[On('keuangan.pinjaman.openTambah')]
    public function openTambah(): void
    {
        $this->editId = null;
        $this->tab = 'entry';
    }

    #[On('keuangan.pinjaman.openEdit')]
    public function openEdit(int $id): void
    {
        $this->editId = $id;
        $this->tab = 'entry';
    }

    #[On('keuangan.pinjaman.saved')]
    #[On('keuangan.pinjaman.batal')]
    public function keBuku(): void
    {
        $this->editId = null;
        $this->tab = 'buku';
    }
};
?>

<div>
    <x-page-title title="Pinjaman Karyawan"
        subtitle="Buku pinjaman koperasi / bank yang dipotong dari gaji: ketentuan per sumber → catat pinjaman → angsuran per bulan terhitung sendiri → terapkan ke slip gaji" />

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">
            <div class="flex flex-wrap items-end justify-between gap-3 px-4">
                <x-tabs variant="underline" class="border-0">
                    <x-tab :active="$tab === 'entry'" wire:click="setTab('entry')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>Entry Pinjaman{{ $editId ? ' (ubah #' . $editId . ')' : '' }}
                    </x-tab>
                    <x-tab :active="$tab === 'buku'" wire:click="setTab('buku')">
                        <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>Buku Pinjaman
                    </x-tab>
                </x-tabs>
                <a href="{{ route('keuangan.gaji-karyawan') }}" wire:navigate
                    class="inline-flex items-center gap-1 px-3 py-2.5 text-sm font-medium rounded-lg text-brand-green hover:underline dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Ke Gaji Karyawan →</a>
            </div>
            <div class="border-b border-hairline dark:border-gray-700"></div>

            @if ($tab === 'entry')
                <livewire:pages::keuangan.pinjaman-karyawan.pinjaman-entry :edit-id="$editId" wire:key="pinjaman-entry-{{ $editId ?? 'baru' }}" />
            @else
                <livewire:pages::keuangan.pinjaman-karyawan.pinjaman-buku wire:key="pinjaman-buku" />
            @endif
        </div>
    </div>
</div>
