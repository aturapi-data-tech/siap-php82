<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Support\Format;
use App\Support\GajiFormBagian;
use App\Support\GajiKaryawan;
use App\Support\LaporanUpahBpjsTk;

/**
 * Modal "Form Bagian": ekspor form Excel per bagian (hari kerja, bon, kenaikan gaji, iuran koperasi)
 * untuk diisi bagian terkait, lalu impor kembali → slip Draft diperbarui.
 * Jasa Medik lewat menu Pembagian Jasa Medik, Lembur lewat Laporan Kepegawaian, angsuran lewat Pinjaman Karyawan (kartu penunjuk di akhir grid).
 * Logika ekspor/impor di App\Support\GajiFormBagian. Bulan & unit mengikuti toolbar halaman gaji.
 * Kartu "Upah BPJS TK" = ekspor saja: template upah SIPP Online bulan lapor (App\Support\LaporanUpahBpjsTk), tidak diimpor balik.
 */
new class extends Component {
    use WithFileUploads;

    #[Locked]
    public string $periode = '';

    #[Locked]
    public ?string $unitId = null;

    /** Berkas impor per bagian (key = bagian). */
    public array $berkas = [];

    /** Hasil impor terakhir per bagian — ditampilkan di kartunya. */
    public array $hasil = [];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    #[On('keuangan.gaji-karyawan.openFormBagian')]
    public function buka(string $periode, ?string $unitId = null): void
    {
        if (!GajiKaryawan::periodeValid($periode)) {
            return;
        }
        $this->periode = $periode;
        $this->unitId = $unitId !== '' ? $unitId : null;
        $this->reset(['berkas', 'hasil']);
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'gaji-karyawan-form-bagian');
    }

    public function ekspor(string $bagian)
    {
        if (!GajiFormBagian::ada($bagian) || $this->periode === '') {
            return null;
        }

        $book = GajiFormBagian::ekspor($bagian, $this->periode, $this->unitId);
        if (!$book) {
            $this->dispatch('toast', type: 'error', message: $bagian === 'kenaikan_gaji'
                ? 'Belum ada karyawan aktif yang punya parameter gaji.'
                : 'Belum ada slip bulan ini. Klik Generate dulu, baru ekspor form.');
            return null;
        }

        return response()->streamDownload(
            fn() => IOFactory::createWriter($book, 'Xlsx')->save('php://output'),
            GajiFormBagian::namaBerkas($bagian, $this->periode, $this->unitId),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** Template upah BPJS Ketenagakerjaan (SIPP Online) untuk bulan lapor = bulan kerja + 1 — ekspor saja. */
    public function eksporUpahBpjsTk()
    {
        if ($this->periode === '') {
            return null;
        }
        $book = LaporanUpahBpjsTk::ekspor($this->periode);
        if (!$book) {
            $this->dispatch('toast', type: 'error', message: 'Belum ada peserta BPJS TK: isi nomor KPJ / kode TK di Master Karyawan → Gaji, atau jalankan php artisan gaji:impor-upah-bpjs-tk.');
            return null;
        }

        return response()->streamDownload(
            fn() => IOFactory::createWriter($book, 'Xlsx')->save('php://output'),
            LaporanUpahBpjsTk::namaBerkas($this->periode),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function impor(string $bagian): void
    {
        if (!GajiFormBagian::ada($bagian) || $this->periode === '') {
            return;
        }

        $this->validate(
            ["berkas.{$bagian}" => 'required|file|mimes:xlsx|max:5120'],
            ["berkas.{$bagian}.required" => 'Pilih berkas form (.xlsx) dulu.', "berkas.{$bagian}.mimes" => 'Harus berkas .xlsx hasil Ekspor.'],
            ["berkas.{$bagian}" => 'Berkas'],
        );

        $hasil = GajiFormBagian::impor($bagian, $this->berkas[$bagian]->getRealPath(), $this->periode, auth()->id());
        $this->berkas[$bagian]->delete();
        unset($this->berkas[$bagian]);
        $this->hasil[$bagian] = $hasil;

        $this->dispatch('toast', type: $hasil['ok'] ? 'success' : 'error',
            message: GajiFormBagian::daftar()[$bagian]['judul'] . ': ' . $hasil['pesan']);
        if ($hasil['ok'] && $hasil['diperbarui'] > 0) {
            $this->dispatch('keuangan.gaji-karyawan.saved');
        }
    }

    public function closeModal(): void
    {
        $this->reset(['berkas', 'hasil']);
        $this->dispatch('close-modal', name: 'gaji-karyawan-form-bagian');
    }
};
?>

<div>
    <x-modal name="gaji-karyawan-form-bagian" size="full" height="full">
        <div class="flex flex-col min-h-full" data-penjaga-tutup x-data="{ tryClose() { $wire.closeModal() } }">
            <x-modul-dokumen.header judul="Form Bagian — Ekspor / Impor Excel"
                ikon="M3 10h18M3 14h18M10 3v18M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z">
                Bulan kerja <b>{{ $periode !== '' ? Format::bulan(GajiKaryawan::awalPeriode($periode)) : '-' }}</b>{{ $unitId ? ' · unit ' . $unitId : ' · semua unit' }}
                · Ekspor form → kirim ke bagian → bagian mengisi kolom kuning → impor di sini.
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
                <p class="p-3 mb-4 text-sm border rounded-xl border-hairline bg-canvas text-body dark:bg-gray-800 dark:border-gray-700">
                    Form berisi slip bulan ini (Generate dulu). Nilai di kolom kuning <b>menggantikan</b> komponen itu di slip;
                    kosong = 0 (kecuali Hari Kerja &amp; Kenaikan Gaji: kosong = tidak diubah). Baris dicocokkan lewat ID Karyawan,
                    slip <b>Final dilewati</b>, dan form bulan / bagian lain ditolak.
                </p>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach (GajiFormBagian::daftar() as $key => $bagian)
                        @php $hasilBagian = $hasil[$key] ?? null; @endphp
                        <div wire:key="form-bagian-{{ $key }}" class="flex flex-col p-4 border shadow-sm bg-canvas border-hairline rounded-2xl dark:bg-gray-800 dark:border-gray-700">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="font-semibold text-ink dark:text-gray-100">{{ $bagian['judul'] }}</h3>
                                <x-badge variant="gray" class="shrink-0">{{ $bagian['petugas'] }}</x-badge>
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ $bagian['keterangan'] }}</p>
                            <p class="mt-1 text-xs text-muted">Kolom isian:
                                {{ collect($bagian['kolom'])->where('isi', true)->pluck('label')->implode(', ') }}</p>

                            <div class="mt-3">
                                <x-secondary-button type="button" wire:click="ekspor('{{ $key }}')" wire:loading.attr="disabled" wire:target="ekspor('{{ $key }}')" class="w-full justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                    <span wire:loading.remove wire:target="ekspor('{{ $key }}')">Ekspor Form</span>
                                    <span wire:loading wire:target="ekspor('{{ $key }}')">Menyiapkan…</span>
                                </x-secondary-button>
                            </div>

                            <div class="pt-3 mt-3 border-t border-hairline dark:border-gray-700">
                                <x-file-upload name="berkas.{{ $key }}" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                    loadingText="Mengunggah…" aria-label="Berkas form {{ $bagian['judul'] }}" />
                                <x-confirm-button variant="primary" action="impor('{{ $key }}')" class="w-full justify-center mt-2"
                                    title="Impor Form {{ $bagian['judul'] }}"
                                    message="Perbarui slip Draft bulan ini dari form {{ $bagian['judul'] }}? Nilai komponen di slip diganti nilai di form; slip Final dilewati."
                                    confirmText="Ya, impor" cancelText="Batal" :disabled="empty($berkas[$key])">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                                    Impor
                                </x-confirm-button>
                            </div>

                            @if ($hasilBagian)
                                <div role="status" aria-live="polite" class="p-2 mt-3 text-xs rounded-lg {{ $hasilBagian['ok'] ? 'bg-success-tint text-success-deep' : 'bg-error-tint text-error-deep' }}">
                                    <p class="font-semibold">{{ $hasilBagian['pesan'] }}</p>
                                    @foreach ([...$hasilBagian['galat'], ...$hasilBagian['tidakDikenal']] as $catatan)
                                        <p class="mt-0.5">• {{ $catatan }}</p>
                                    @endforeach
                                    @if ($hasilBagian['final'] !== [])
                                        <p class="mt-0.5">• Final dilewati: {{ implode(', ', $hasilBagian['final']) }}</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach

                    {{-- Jasa Medik bukan form Excel: dihitung dari poin di menu Pembagian Jasa Medik (satu sumber JM slip). --}}
                    <div class="flex flex-col p-4 border border-dashed shadow-sm bg-canvas border-hairline rounded-2xl dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-ink dark:text-gray-100">Jasa Medik</h3>
                            <x-badge variant="info" class="shrink-0">Menu sendiri</x-badge>
                        </div>
                        <p class="mt-1 text-xs text-muted">Tidak lewat Excel. JM dihitung dari nilai dasar per pos &amp; poin karyawan (master), lalu diterapkan ke slip di menu Pembagian Jasa Medik.</p>
                        <a href="{{ route('keuangan.pembagian-jm', ['bulan' => $periode !== '' ? \App\Support\GajiKaryawan::awalPeriode($periode)->format('m/Y') : null]) }}" wire:navigate
                            class="inline-flex items-center justify-center w-full gap-2 px-5 py-2.5 mt-auto text-sm font-medium border rounded-lg text-brand-green border-brand-green/30 bg-brand-green/10 hover:bg-brand-green hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:text-brand-lime dark:border-brand-lime/30 dark:bg-brand-lime/10 dark:hover:bg-brand-lime dark:hover:text-gray-900">
                            Buka Pembagian Jasa Medik →
                        </a>
                    </div>

                    {{-- Lembur bukan form Excel: jam lembur & hari kerja diisi kepegawaian di menu Laporan Kepegawaian (satu sumber lembur slip). --}}
                    <div class="flex flex-col p-4 border border-dashed shadow-sm bg-canvas border-hairline rounded-2xl dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-ink dark:text-gray-100">Lembur &amp; Hari Kerja</h3>
                            <x-badge variant="info" class="shrink-0">Menu sendiri</x-badge>
                        </div>
                        <p class="mt-1 text-xs text-muted">Tidak lewat Excel. Jam lembur &amp; hari kerja diisi kepegawaian langsung di menu Laporan Kepegawaian, lalu diterapkan ke slip. Form Hari Kerja di atas tetap tersedia untuk koreksi cepat.</p>
                        <a href="{{ route('keuangan.laporan-pegawai', ['bulan' => $periode !== '' ? \App\Support\GajiKaryawan::awalPeriode($periode)->format('m/Y') : null]) }}" wire:navigate
                            class="inline-flex items-center justify-center w-full gap-2 px-5 py-2.5 mt-auto text-sm font-medium border rounded-lg text-brand-green border-brand-green/30 bg-brand-green/10 hover:bg-brand-green hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:text-brand-lime dark:border-brand-lime/30 dark:bg-brand-lime/10 dark:hover:bg-brand-lime dark:hover:text-gray-900">
                            Buka Laporan Kepegawaian →
                        </a>
                    </div>

                    {{-- Angsuran bukan form Excel: dihitung dari buku pinjaman di menu Pinjaman Karyawan (satu sumber angsuran slip). --}}
                    <div class="flex flex-col p-4 border border-dashed shadow-sm bg-canvas border-hairline rounded-2xl dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-ink dark:text-gray-100">Angsuran Koperasi &amp; Bank</h3>
                            <x-badge variant="info" class="shrink-0">Menu sendiri</x-badge>
                        </div>
                        <p class="mt-1 text-xs text-muted">Tidak lewat Excel. Pinjaman dicatat sekali di buku pinjaman (pokok, sewa modal, lama, bulan awal); angsuran tiap bulan terhitung sendiri, berhenti saat lunas, lalu diterapkan ke slip di menu Pinjaman Karyawan.</p>
                        <a href="{{ route('keuangan.pinjaman-karyawan', ['bulan' => $periode !== '' ? \App\Support\GajiKaryawan::awalPeriode($periode)->format('m/Y') : null]) }}" wire:navigate
                            class="inline-flex items-center justify-center w-full gap-2 px-5 py-2.5 mt-auto text-sm font-medium border rounded-lg text-brand-green border-brand-green/30 bg-brand-green/10 hover:bg-brand-green hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:text-brand-lime dark:border-brand-lime/30 dark:bg-brand-lime/10 dark:hover:bg-brand-lime dark:hover:text-gray-900">
                            Buka Pinjaman Karyawan →
                        </a>
                    </div>

                    {{-- BPJS bukan form Excel: dihitung otomatis di slip dari master karyawan + Ketentuan BPJS (satu sumber). --}}
                    <div class="flex flex-col p-4 border border-dashed shadow-sm bg-canvas border-hairline rounded-2xl dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-ink dark:text-gray-100">BPJS</h3>
                            <x-badge variant="info" class="shrink-0">Otomatis</x-badge>
                        </div>
                        <p class="mt-1 text-xs text-muted">Tidak lewat Excel. BPJS Kesehatan (JKN × dasar × jiwa) dan Ketenagakerjaan (JP × upah bulan itu) dihitung otomatis di slip dari kepesertaan di Master Karyawan → Gaji dan UMK / persen di <b>Ketentuan BPJS</b>. Bisa ditimpa per slip di rincian.</p>
                    </div>

                    {{-- Laporan upah BPJS TK: template SIPP Online, ekspor saja (diunggah ke SIPP, tidak diimpor balik). --}}
                    <div class="flex flex-col p-4 border shadow-sm bg-canvas border-hairline rounded-2xl dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-ink dark:text-gray-100">Upah BPJS TK (SIPP)</h3>
                            <x-badge variant="gray" class="shrink-0">Ekspor saja</x-badge>
                        </div>
                        <p class="mt-1 text-xs text-muted">Template upah BPJS Ketenagakerjaan bulan lapor <b>{{ $periode !== '' ? Format::bulan(LaporanUpahBpjsTk::bulanLapor($periode)) : '-' }}</b> (bulan gaji dibayar) untuk diunggah ke SIPP Online: NIK, KPJ, kode TK, nama, tanggal lahir, UPAH dari Master Karyawan → Gaji. RAPEL 0 — isi di berkas bila ada.</p>
                        <div class="mt-auto pt-3">
                            <x-secondary-button type="button" wire:click="eksporUpahBpjsTk" wire:loading.attr="disabled" wire:target="eksporUpahBpjsTk" class="w-full justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                <span wire:loading.remove wire:target="eksporUpahBpjsTk">Ekspor template SIPP</span>
                                <span wire:loading wire:target="eksporUpahBpjsTk">Menyiapkan…</span>
                            </x-secondary-button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex justify-end">
                    <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
                </div>
            </div>
        </div>
    </x-modal>
</div>
