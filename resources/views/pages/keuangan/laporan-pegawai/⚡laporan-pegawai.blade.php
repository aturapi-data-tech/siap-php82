<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiKaryawan;
use App\Support\LaporanPegawai;
use App\Support\UnitKerja;

/**
 * LAPORAN KEPEGAWAIAN — pengganti sheet "Lap Pegawai" workbook gaji lama (laporan Kasubbag Kepegawaian).
 * Berdiri sendiri seperti Pembagian JM — tidak membaca modul absensi.
 *
 * Alur per bulan kerja: kolom sudah terisi bawaan (hari kerja = slip bulan itu / default master, cuti & lembur 0) →
 *   kepegawaian hanya mengubah yang berbeda (hari kerja, cuti, jam lembur, keterangan) → Keuangan klik
 *   "Terapkan ke Slip": hari kerja slip + baris Upah Lembur ditulis ke slip Draft (Gaji Karyawan).
 * Setiap angka disimpan saat kotak ditinggalkan (blur); baris tabel baru dibuat saat pertama kali diubah.
 * PRIVASI GAJI: halaman ini dibuka kepegawaian — TIDAK menampilkan rupiah / rumus apa pun (upah lembur, dasar upah).
 *   Semua perhitungan hanya terjadi saat Terapkan ke Slip; hasilnya dilihat Keuangan di Gaji Karyawan.
 * Hak akses: buka = laporanPegawai.lihat; isi = laporanPegawai.isi; terapkan = gaji.kelola.
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'bulan')]
    public string $bulan = ''; // mm/yyyy

    public string $searchKeyword = '';
    public string $filterUnit    = '';
    public int    $itemsPerPage  = 25;

    /** [emp_id => [hari_kerja, cuti, lembur_jam, keterangan]] — diikat ke input tabel. */
    public array $laporanInput = [];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('laporanPegawai.lihat'), 403);
    }

    public function mount(): void
    {
        if (!$this->bulanValid()) {
            $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        }
        $this->muatInput();
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

    public function updatedBulan(): void
    {
        if ($this->bulanValid()) {
            $this->resetPage();
            $this->muatInput();
        }
    }

    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedFilterUnit(): void    { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        $this->resetPage();
        $this->muatInput();
    }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterUnit']);
        $this->itemsPerPage = 25;
        $this->resetPage();
    }

    private function muatInput(): void
    {
        $this->laporanInput = array_map(
            fn($row) => ['hari_kerja' => $row['hari_kerja'], 'cuti' => $row['cuti'], 'lembur_jam' => $row['lembur_jam'], 'keterangan' => $row['keterangan']],
            LaporanPegawai::laporan($this->periode()),
        );
        $this->segarkan();
    }

    private function segarkan(): void
    {
        unset($this->laporan, $this->karyawan, $this->rows, $this->cocokSlip, $this->diterapkan);
    }

    // ─── Simpan saat blur ─────────────────────────────────────────────────────
    public function updatedLaporanInput($nilai, $kunci): void
    {
        if (!auth()->user()?->can('laporanPegawai.isi')) {
            $this->dispatch('toast', type: 'error', message: 'Hanya petugas kepegawaian yang boleh mengubah laporan.');
            $this->muatInput();
            return;
        }
        [$empId, $kolom] = array_pad(explode('.', (string) $kunci, 2), 2, '');
        $lama = LaporanPegawai::laporan($this->periode())[$empId] ?? null;
        $baru = $kolom === 'keterangan' ? trim((string) $nilai) : ($nilai === '' || $nilai === null ? 0 : $nilai);
        $sama = $lama !== null && match ($kolom) {
            'keterangan' => $baru === $lama['keterangan'],
            'lembur_jam' => is_numeric($baru) && abs((float) $baru - $lama['lembur_jam']) < 0.005,
            'hari_kerja', 'cuti' => is_numeric($baru) && (float) $baru == $lama[$kolom],
            default      => false,
        };
        if ($sama) {
            return;
        }
        if (!$this->empIdDikenal($empId) || !LaporanPegawai::ubah($this->periode(), $empId, $kolom, $baru, auth()->id())) {
            $this->dispatch('toast', type: 'error', message: match ($kolom) {
                'hari_kerja', 'cuti' => 'Hari harus bilangan bulat 0–31.',
                'lembur_jam'         => 'Jam lembur harus angka 0–744 (boleh desimal, mis. 3,5).',
                default              => 'Nilai tidak valid.',
            });
        }
        $this->muatInput();
    }

    /** emp_id dari browser tidak dipercaya — harus peserta yang tampil di daftar bulan ini. */
    private function empIdDikenal(string $empId): bool
    {
        return LaporanPegawai::karyawan($this->periode(), null)->contains('emp_id', $empId);
    }

    // ─── Aksi ─────────────────────────────────────────────────────────────────
    public function terapkan(): void
    {
        if (!auth()->user()?->can('gaji.kelola')) {
            $this->dispatch('toast', type: 'error', message: 'Hanya Keuangan yang boleh menerapkan ke slip gaji.');
            return;
        }
        $hasil = LaporanPegawai::terapkan($this->periode(), auth()->id());
        $pesan = "Hari kerja & upah lembur {$this->bulan} diterapkan ke {$hasil['diperbarui']} slip.";
        if ($hasil['final'] !== []) {
            $pesan .= ' ' . count($hasil['final']) . ' slip Final dilewati.';
        }
        if ($hasil['tanpaSlip'] !== []) {
            $pesan .= ' ' . count($hasil['tanpaSlip']) . ' karyawan belum punya slip (Generate dulu di Gaji Karyawan): '
                . implode(', ', array_slice($hasil['tanpaSlip'], 0, 5)) . (count($hasil['tanpaSlip']) > 5 ? ', …' : '') . '.';
        }
        $this->dispatch('toast', type: $hasil['diperbarui'] > 0 ? 'success' : 'warning', message: $pesan);
        $this->segarkan();
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function unitList()
    {
        return UnitKerja::aktif();
    }

    #[Computed]
    public function laporan(): array
    {
        return LaporanPegawai::laporan($this->periode());
    }

    #[Computed]
    public function karyawan()
    {
        return LaporanPegawai::karyawan($this->periode(), $this->filterUnit !== '' ? $this->filterUnit : null, $this->searchKeyword);
    }

    #[Computed]
    public function diterapkan(): ?object
    {
        return LaporanPegawai::terakhirDiterapkan($this->periode());
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $semua = $this->karyawan;
        $halaman = $this->getPage();

        return new LengthAwarePaginator($semua->forPage($halaman, $this->itemsPerPage)->values(), $semua->count(), $this->itemsPerPage, $halaman,
            ['path' => request()->url(), 'pageName' => 'page']);
    }

    #[Computed]
    public function cocokSlip(): array
    {
        return LaporanPegawai::cocokSlip($this->periode(), $this->laporan);
    }
};
?>

<div>
    @php
        $rows = $this->rows;
        $laporan = $this->laporan;
        $cocokSemua = $this->cocokSlip;
        $bolehIsi = auth()->user()?->can('laporanPegawai.isi');
        $bolehTerapkan = auth()->user()?->can('gaji.kelola');
        $jumlahDiubah = count(array_filter($laporan, fn($row) => $row['tersimpan']));
        $jam = fn($n) => LaporanPegawai::labelJam((float) $n);
    @endphp
    <x-page-title title="Laporan Kepegawaian"
        subtitle="Hari kerja, cuti & jam lembur tiap karyawan per bulan kerja — terisi dari master, kepegawaian mengubah yang berbeda, Keuangan menerapkan ke slip gaji" />

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 pt-1 pb-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label value="Bulan Kerja" />
                        <div class="flex items-center gap-1 mt-1">
                            <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-3" title="Bulan sebelumnya" aria-label="Bulan sebelumnya">‹</x-secondary-button>
                            <div class="w-28">
                                <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" class="w-full text-center font-mono" aria-label="Bulan kerja" />
                            </div>
                            <x-secondary-button type="button" wire:click="geserBulan(1)" class="!px-3" title="Bulan berikutnya" aria-label="Bulan berikutnya">›</x-secondary-button>
                        </div>
                    </div>
                    <div class="w-44">
                        <x-input-label for="lap-cari" value="Cari Karyawan" />
                        <x-text-input id="lap-cari" type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="ID / nama" class="block w-full mt-1" />
                    </div>
                    <div class="w-48">
                        <x-input-label for="lap-unit" value="Unit" />
                        <div class="mt-1">
                            <x-select-input id="lap-unit" wire:model.live="filterUnit">
                                <option value="">Semua unit</option>
                                @foreach ($this->unitList as $unit)
                                    <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-end gap-2 ml-auto">
                        @if ($bolehTerapkan)
                            <x-confirm-button variant="primary" action="terapkan()" title="Terapkan ke Slip Gaji"
                                message="Tulis hari kerja & jam lembur {{ $bulan }} ke slip Draft? Hari kerja slip disamakan dengan laporan dan semua baris Upah Lembur DIGANTI hasil hitung dari jam lembur. Terapkan Pembagian JM dulu — dasar lembur memuat JM. Slip Final dilewati." confirmText="Ya, terapkan" cancelText="Batal">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                Terapkan ke Slip
                            </x-confirm-button>
                        @endif
                        <x-toolbar-refresh-reset :label="null" />
                        @if ($bolehTerapkan)
                            <a href="{{ route('keuangan.gaji-karyawan') }}" wire:navigate
                                class="inline-flex items-center gap-1 px-3 py-2.5 text-sm font-medium rounded-lg text-brand-green hover:underline dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Ke Gaji Karyawan →</a>
                        @endif
                    </div>
                </div>
                @php
                    $cocok = collect($cocokSemua);
                    $jumlahBeda = $cocok->where('status', 'beda')->count();
                    $jumlahFinal = $cocok->where('status', 'final')->count();
                    $jumlahTanpaSlip = $cocok->where('status', 'tanpa_slip')->count();
                @endphp
                <div class="flex flex-wrap items-center gap-2 mt-2" role="status" aria-live="polite">
                    @if ($jumlahBeda > 0)
                        <x-badge variant="warning">{{ $jumlahBeda }} slip Draft belum sesuai laporan — klik Terapkan ke Slip</x-badge>
                    @elseif ($cocok->isNotEmpty() && $jumlahTanpaSlip === 0)
                        <x-badge variant="success">Semua slip Draft sudah sesuai laporan</x-badge>
                    @endif
                    @if ($jumlahTanpaSlip > 0)
                        <x-badge variant="gray">{{ $jumlahTanpaSlip }} karyawan belum punya slip — Generate di Gaji Karyawan</x-badge>
                    @endif
                    @if ($jumlahFinal > 0)
                        <x-badge variant="danger">{{ $jumlahFinal }} slip Final beda — buka ke Draft dulu bila perlu diubah</x-badge>
                    @endif
                    <span class="text-xs text-muted">
                        @if ($this->diterapkan?->diterapkan_at)
                            Terakhir diterapkan ke slip {{ Format::waktu($this->diterapkan->diterapkan_at) }}{{ $this->diterapkan->name ? ' oleh ' . $this->diterapkan->name : '' }}. Aman diulang: hari kerja & lembur slip diganti angka terbaru, bukan ditambah.
                        @else
                            Laporan {{ $bulan }} belum diterapkan ke slip.
                        @endif
                    </span>
                </div>
            </div>

            {{-- TABEL LAPORAN --}}
            <div class="mt-4 flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-end justify-between gap-2 px-4 pt-3">
                    <div>
                        <h2 class="font-semibold text-ink dark:text-gray-100">Hari kerja, cuti & lembur per karyawan</h2>
                        <p class="text-xs text-muted">
                            Hari kerja sudah terisi dari setup Master Karyawan (Hari Kerja Default; slip bulan ini mengikutinya); cuti &amp; lembur 0. Ubah hanya yang berbeda — nama bertanda <b>✎</b> = sudah diubah kepegawaian.
                            Jam lembur boleh desimal (3,5), kosong = 0. Upah lembur dihitung Keuangan saat Terapkan ke Slip — tidak ditampilkan di sini.
                            @unless ($bolehIsi) <b>Hanya kepegawaian yang bisa mengubah angka.</b> @endunless
                        </p>
                    </div>
                    <x-badge variant="gray">{{ $rows->total() }} karyawan · {{ $jumlahDiubah }} diubah</x-badge>
                </div>
                <div class="overflow-x-auto">
                    <table class="ds-table">
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th class="ds-c">Hari Kerja</th>
                                <th class="ds-c">Cuti</th>
                                <th class="ds-c">Jam Lembur</th>
                                <th>Keterangan</th>
                                <th class="ds-c">Slip</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php
                                    $empId = $row->emp_id;
                                    $lap = $laporan[$empId] ?? null;
                                    $cocokRow = $cocokSemua[$empId] ?? null;
                                    $statusCocok = $cocokRow['status'] ?? null;
                                @endphp
                                <tr wire:key="lap-{{ $empId }}-{{ $bulan }}">
                                    <td>
                                        <span class="font-semibold">{{ $row->emp_name }}</span>@if ($lap && $lap['tersimpan'])<span class="ml-1 text-warning-deep dark:text-warning" title="Sudah diubah kepegawaian bulan ini">✎<span class="sr-only"> diubah</span></span>@endif
                                        <span class="block font-mono text-xs text-muted">{{ $empId }}{{ $row->unit_name ? ' · ' . $row->unit_name : '' }}</span>
                                        <span class="block text-[11px] text-muted" title="Hari Kerja Default yang di-setup di Master Karyawan → tombol Gaji. Ubah di sana bila unit ini memang beda (mis. 22 hari).">
                                            @if ($row->hari_kerja_default !== null)
                                                Setup master: <b>{{ (int) $row->hari_kerja_default }}</b> hari kerja
                                            @else
                                                Belum ada parameter gaji di master
                                            @endif
                                        </span>
                                    </td>
                                    <td class="w-24 ds-c">
                                        <x-text-input-number wire:model="laporanInput.{{ $empId }}.hari_kerja" class="w-20" :disabled="!$bolehIsi" aria-label="Hari kerja {{ $row->emp_name }}" />
                                    </td>
                                    <td class="w-20 ds-c">
                                        <x-text-input-number wire:model="laporanInput.{{ $empId }}.cuti" class="w-16" :disabled="!$bolehIsi" aria-label="Cuti {{ $row->emp_name }}" />
                                    </td>
                                    <td class="w-28 ds-c">
                                        <x-text-input-number wire:model="laporanInput.{{ $empId }}.lembur_jam" :decimals="2" class="w-24" :disabled="!$bolehIsi" aria-label="Jam lembur {{ $row->emp_name }}" />
                                    </td>
                                    <td class="min-w-[12rem]">
                                        <x-text-input wire:model.blur="laporanInput.{{ $empId }}.keterangan" maxlength="200" :disabled="!$bolehIsi" placeholder="mis. cuti panjang, lembur laundry" class="w-full text-sm" aria-label="Keterangan {{ $row->emp_name }}" />
                                    </td>
                                    <td class="ds-c">
                                        @if ($statusCocok === 'beda' || $statusCocok === 'final')
                                            <x-badge :variant="$statusCocok === 'final' ? 'danger' : 'warning'"
                                                title="Di slip: {{ $cocokRow['hari_slip'] }} hari, lembur {{ $jam($cocokRow['jam_slip']) }} jam. Laporan: {{ $lap['hari_kerja'] }} hari, {{ $jam($lap['lembur_jam']) }} jam">{{ $statusCocok === 'final' ? 'Final, beda' : 'Belum sesuai' }}</x-badge>
                                        @elseif ($row->gaji_status)
                                            <x-badge :variant="$row->gaji_status === 'F' ? 'success' : 'warning'">{{ GajiKaryawan::STATUS[$row->gaji_status] ?? $row->gaji_status }}</x-badge>
                                        @else
                                            <x-badge variant="gray">Belum ada</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-10 text-center text-muted">
                                        @if ($searchKeyword !== '' || $filterUnit !== '')
                                            Tidak ada karyawan yang cocok dengan saringan. <button type="button" wire:click="resetFilters" class="underline text-brand-green">Reset saringan</button>
                                        @else
                                            Belum ada karyawan peserta. Peserta = karyawan aktif yang punya parameter gaji (Master Karyawan → tombol Gaji) atau slip bulan ini.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($laporan !== [])
                            <tfoot>
                                <tr class="font-semibold">
                                    <td>Total {{ count($laporan) }} karyawan</td>
                                    <td class="font-mono ds-c">{{ array_sum(array_column($laporan, 'hari_kerja')) }}</td>
                                    <td class="font-mono ds-c">{{ array_sum(array_column($laporan, 'cuti')) }}</td>
                                    <td class="font-mono ds-c">{{ $jam(array_sum(array_column($laporan, 'lembur_jam'))) }} jam</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-hairline dark:border-gray-700">
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
