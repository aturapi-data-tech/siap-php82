<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Support\GajiDokterSirus;

/**
 * Modal UJI HITUNG SIAP (tahap 2) — komponen terpisah sejajar tab, dibuka dari Buku lewat event
 * 'keuangan.gaji-dokter.openUjiHitung' (periode). Menghitung ulang setiap snapshot terbaru bulan jasa itu dengan mesin SIAP
 * (GajiDokterHitung: lampiran snapshot + struktur SIAP) dan membandingkannya dengan angka SIRUS. Selisih nol di semua dokter
 * selama beberapa bulan = syarat memindah mesin slip ke SIAP (tahap 3).
 */
new class extends Component {
    public string $periode = '';
    public array $hasilUji = [];
    public ?string $bukaDrId = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    #[On('keuangan.gaji-dokter.openUjiHitung')]
    public function openUjiHitung(string $periode): void
    {
        $this->periode = $periode;
        $this->bukaDrId = null;
        $this->hasilUji = GajiDokterSirus::ujiHitung($periode)->map(fn($row) => (array) $row)->values()->all();
        $this->dispatch('open-modal', name: 'gaji-dokter-uji-hitung');
    }

    public function closeModal(): void
    {
        $this->hasilUji = [];
        $this->bukaDrId = null;
        $this->dispatch('close-modal', name: 'gaji-dokter-uji-hitung');
    }

    public function toggleDetail(string $drId): void
    {
        $this->bukaDrId = $this->bukaDrId === $drId ? null : $drId;
    }
};
?>

<div>
    <x-modal name="gaji-dokter-uji-hitung" size="full" height="full">
        @php
            $rupiah = fn($nilai) => $nilai === null ? '–' : number_format((float) $nilai, 0, ',', '.');
            $cocok = collect($hasilUji)->where('cocok', true)->count();
            $labelKolom = ['jasa_total' => 'Jasa', 'total_gaji' => 'Total gaji', 'potongan_rs' => 'Potongan RS', 'pph21' => 'PPh 21', 'potongan_lain_total' => 'Potongan lain', 'tambahan_total' => 'Tambahan', 'gaji_diterima' => 'Diterima'];
        @endphp
        <div class="flex flex-col h-full">
            <x-modul-dokumen.header judul="Uji hitung SIAP" tutup="closeModal"
                ikon="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                Bulan jasa {{ GajiDokterSirus::namaPeriode($periode) }} · mesin SIAP dihitung dari lampiran snapshot + struktur SIAP, dibanding angka SIRUS
                <x-slot:badge><x-badge :variant="$cocok === count($hasilUji) ? 'success' : 'warning'">{{ $cocok }} / {{ count($hasilUji) }} cocok</x-badge></x-slot:badge>
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-3 overflow-y-auto bg-surface-soft dark:bg-gray-950/20">
                <p class="mb-3 text-xs text-muted dark:text-gray-400">Dokter bertanda "struktur dari snapshot" belum punya baris di tab Struktur Gaji — tarif kapita / aturan berjenjang tidak bisa direka dari snapshot, jadi tarik struktur dari SIRUS dulu sebelum menilai selisihnya. Selisih nol untuk semua dokter beberapa bulan berturut-turut = siap pindah ke mesin SIAP.</p>
                <div class="overflow-x-auto border bg-canvas border-hairline rounded-xl dark:bg-gray-900 dark:border-gray-700">
                    <table class="ds-table">
                        <thead><tr><th>Dokter</th><th>Struktur</th><th class="text-right">Diterima SIRUS</th><th class="text-right">Diterima SIAP</th><th>Hasil</th></tr></thead>
                        <tbody>
                            @forelse ($hasilUji as $uji)
                                <tr wire:key="uji-{{ $uji['dr_id'] }}" class="{{ $uji['cocok'] ? '' : 'bg-warning/10' }}">
                                    <td><span class="font-semibold">{{ $uji['dr_name'] }}</span><span class="block text-xs text-muted">v{{ $uji['versi'] }} · sumber {{ $uji['sumber'] }}</span></td>
                                    <td class="text-xs">{{ $uji['strukturAsal'] === 'struktur' ? 'tab Struktur Gaji' : 'dari snapshot' }}</td>
                                    <td class="font-mono text-right tabular-nums">{{ $rupiah(($uji['ringkasSiap']['gaji_diterima'] ?? 0) - ($uji['selisihHeader']['gaji_diterima']['selisih'] ?? 0)) }}</td>
                                    <td class="font-mono text-right tabular-nums">{{ $rupiah($uji['ringkasSiap']['gaji_diterima'] ?? null) }}</td>
                                    <td>
                                        @if ($uji['cocok'])
                                            <x-badge variant="success">cocok</x-badge>
                                        @else
                                            <button type="button" wire:click="toggleDetail('{{ $uji['dr_id'] }}')" class="text-sm font-semibold underline underline-offset-2 text-amber-800 dark:text-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green rounded">
                                                {{ count($uji['selisihHeader']) + count($uji['selisihDetail']) }} selisih {{ $bukaDrId === $uji['dr_id'] ? '▴' : '▾' }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                                @if (!$uji['cocok'] && $bukaDrId === $uji['dr_id'])
                                    <tr wire:key="uji-detail-{{ $uji['dr_id'] }}"><td colspan="5" class="bg-surface-soft dark:bg-gray-800/60">
                                        <table class="w-full text-sm">
                                            <thead><tr class="text-xs uppercase text-muted"><th class="py-1 text-left">Komponen</th><th class="py-1 text-right">SIRUS</th><th class="py-1 text-right">SIAP</th></tr></thead>
                                            <tbody>
                                                @foreach ($uji['selisihHeader'] as $kolom => $selisih)
                                                    <tr class="font-semibold"><td class="py-1">{{ $labelKolom[$kolom] ?? $kolom }}</td><td class="py-1 font-mono text-right tabular-nums">{{ $rupiah($selisih['sirus']) }}</td><td class="py-1 font-mono text-right tabular-nums">{{ $rupiah($selisih['siap']) }}</td></tr>
                                                @endforeach
                                                @foreach ($uji['selisihDetail'] as $selisih)
                                                    <tr><td class="py-1">{{ $selisih['label'] }} <span class="font-mono text-xs text-muted">{{ $selisih['jenis'] }}·{{ $selisih['kode'] }}</span></td><td class="py-1 font-mono text-right tabular-nums">{{ $rupiah($selisih['sirus']) }}</td><td class="py-1 font-mono text-right tabular-nums">{{ $rupiah($selisih['siap']) }}</td></tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </td></tr>
                                @endif
                            @empty
                                <tr><td colspan="5" class="py-8 text-center text-muted">Belum ada snapshot bulan jasa ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="flex justify-end px-4 py-3 border-t border-hairline dark:border-gray-700">
                <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
