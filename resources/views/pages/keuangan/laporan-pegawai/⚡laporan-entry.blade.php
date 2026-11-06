<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\LaporanPegawai;
use App\Support\UnitKerja;

/**
 * Tab ENTRY Laporan Kepegawaian (anak pembungkus laporan-pegawai) — pengganti sheet "Lap Pegawai" workbook gaji lama.
 * Berdiri sendiri seperti Pembagian JM — tidak membaca modul absensi.
 *
 * Kolom sudah terisi bawaan (hari kerja = Hari Kerja Default master / slip bulan itu, cuti & lembur 0) → kepegawaian hanya
 * mengubah yang berbeda; setiap angka disimpan saat kotak ditinggalkan (blur), baris tabel baru dibuat saat pertama diubah.
 * PRIVASI GAJI: tab ini dibuka kepegawaian — tidak menampilkan rupiah / rumus apa pun.
 * Hak isi: laporanPegawai.isi (yang lain hanya melihat, kotak nonaktif). Terapkan ke slip ada di tab Buku.
 */
new class extends Component {
    use WithPagination;

    #[Session(key: 'laporan-pegawai-bulan')]
    public string $bulan = ''; // mm/yyyy

    #[Session(key: 'laporan-pegawai-searchKeyword')]
    public string $searchKeyword = '';

    #[Session(key: 'laporan-pegawai-filterUnit')]
    public string $filterUnit = '';

    #[Session(key: 'laporan-pegawai-itemsPerPage')]
    public int $itemsPerPage = 25;

    /** [emp_id => [hari_kerja, cuti, lembur_jam, keterangan]] — diikat ke input tabel. */
    public array $laporanInput = [];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('laporanPegawai.lihat'), 403);
    }

    public function mount(): void
    {
        if (!$this->bulanValid()) { // GUARD: nilai Session bertahan saat remount (ganti tab)
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
        unset($this->laporan, $this->karyawan, $this->rows);
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

    public function keBuku(): void
    {
        $this->dispatch('keuangan.laporanPegawai.keBuku');
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function unitList()
    {
        return UnitKerja::aktif();
    }

    /** Opsi LOV karyawan (x-combobox) — semua peserta bulan ini: id = emp_id, nama = nama, kelas = ID · unit. */
    #[Computed]
    public function karyawanOpsi(): array
    {
        return LaporanPegawai::karyawan($this->periode(), null)
            ->map(fn($row) => ['id' => $row->emp_id, 'nama' => $row->emp_name, 'kelas' => $row->emp_id . ($row->unit_name ? ' · ' . $row->unit_name : '')])
            ->all();
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
    public function rows(): LengthAwarePaginator
    {
        $semua = $this->karyawan;
        $halaman = $this->getPage();

        return new LengthAwarePaginator($semua->forPage($halaman, $this->itemsPerPage)->values(), $semua->count(), $this->itemsPerPage, $halaman,
            ['path' => request()->url(), 'pageName' => 'page']);
    }
};
?>

<div>
    @php
        $rows = $this->rows;
        $laporan = $this->laporan;
        $bolehIsi = auth()->user()?->can('laporanPegawai.isi');
        $jumlahDiubah = count(array_filter($laporan, fn($row) => $row['tersimpan']));
        $jam = fn($n) => LaporanPegawai::labelJam((float) $n);
    @endphp

    {{-- TOOLBAR --}}
    <div class="sticky z-30 px-4 pt-3 pb-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
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
            <div class="w-72" x-on:input.debounce.300ms="$wire.$refresh()">
                <x-input-label for="lap-cari" value="Karyawan" />
                <div class="mt-1">
                    <x-combobox wireModel="searchKeyword" :options="$this->karyawanOpsi" inputId="lap-cari" placeholder="Ketik nama / ID, atau pilih dari daftar…" judulDaftar="daftar karyawan" maxlength="100" />
                </div>
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
                <x-toolbar-refresh-reset :label="null" />
                <x-secondary-button type="button" wire:click="keBuku" wire:loading.attr="disabled" wire:target="keBuku">Lihat Buku Laporan →</x-secondary-button>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 mt-2" role="status" aria-live="polite">
            <x-badge variant="gray">{{ $rows->total() }} karyawan · {{ $jumlahDiubah }} diubah</x-badge>
            <span class="text-xs text-muted">Angka tersimpan saat kotak ditinggalkan. Nama bertanda ✎ = sudah diubah bulan ini. Penerapan ke slip dilakukan Keuangan di tab Buku.</span>
        </div>
    </div>

    {{-- TABEL ISIAN --}}
    <div class="mt-4 flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="px-4 pt-3">
            <h2 class="font-semibold text-ink dark:text-gray-100">Isian hari kerja, cuti &amp; lembur per karyawan</h2>
            <p class="text-xs text-muted">
                Hari kerja sudah terisi dari setup Master Karyawan (Hari Kerja Default; slip bulan ini mengikutinya); cuti &amp; lembur 0. Ubah hanya yang berbeda.
                Jam lembur boleh desimal (3,5), kosong = 0.
                @unless ($bolehIsi) <b>Hanya kepegawaian yang bisa mengubah angka.</b> @endunless
            </p>
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
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $empId = $row->emp_id; $lap = $laporan[$empId] ?? null; @endphp
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
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-muted">
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
                            <td></td>
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
