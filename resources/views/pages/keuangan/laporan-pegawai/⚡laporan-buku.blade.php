<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiKaryawan;
use App\Support\LaporanPegawai;
use App\Support\UnitKerja;

/**
 * Tab BUKU Laporan Kepegawaian (anak pembungkus laporan-pegawai): laporan bulan kerja BACA SAJA
 * (hari kerja, cuti, jam lembur, keterangan per karyawan + kecocokan dengan slip) dan "Terapkan ke Slip" (gaji.kelola):
 * hari kerja slip + baris Upah Lembur ditulis ke slip Draft, aman diulang. Tanpa rupiah — upah lembur dihitung saat terapkan.
 * Isian diubah di tab Entry. Bulan kerja & saringan di Session (kunci sama dengan laporan-entry) supaya bertahan saat ganti tab.
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

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('laporanPegawai.lihat'), 403);
    }

    public function mount(): void
    {
        if (!$this->bulanValid()) { // GUARD: nilai Session bertahan saat remount (ganti tab)
            $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        }
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
            $this->segarkan();
        }
    }

    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedFilterUnit(): void    { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        $this->resetPage();
        $this->segarkan();
    }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterUnit']);
        $this->itemsPerPage = 25;
        $this->resetPage();
    }

    private function segarkan(): void
    {
        unset($this->laporan, $this->karyawan, $this->rows, $this->cocokSlip, $this->diterapkan);
    }

    public function keEntry(): void
    {
        $this->dispatch('keuangan.laporanPegawai.keEntry');
    }

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
        $bolehTerapkan = auth()->user()?->can('gaji.kelola');
        $bolehIsi = auth()->user()?->can('laporanPegawai.isi');
        $jumlahDiubah = count(array_filter($laporan, fn($row) => $row['tersimpan']));
        $jam = fn($n) => LaporanPegawai::labelJam((float) $n);
        $cocok = collect($cocokSemua);
        $jumlahBeda = $cocok->where('status', 'beda')->count();
        $jumlahFinal = $cocok->where('status', 'final')->count();
        $jumlahTanpaSlip = $cocok->where('status', 'tanpa_slip')->count();
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
            <div class="w-44">
                <x-input-label for="lapb-cari" value="Cari Karyawan" />
                <x-text-input id="lapb-cari" type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="ID / nama" class="block w-full mt-1" />
            </div>
            <div class="w-48">
                <x-input-label for="lapb-unit" value="Unit" />
                <div class="mt-1">
                    <x-select-input id="lapb-unit" wire:model.live="filterUnit">
                        <option value="">Semua unit</option>
                        @foreach ($this->unitList as $unit)
                            <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}</option>
                        @endforeach
                    </x-select-input>
                </div>
            </div>
            <div class="flex flex-wrap items-end gap-2 ml-auto">
                @if ($bolehIsi)
                    <x-secondary-button type="button" wire:click="keEntry" wire:loading.attr="disabled" wire:target="keEntry">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                        Ubah di Entry
                    </x-secondary-button>
                @endif
                @if ($bolehTerapkan)
                    <x-confirm-button variant="primary" action="terapkan()" title="Terapkan ke Slip Gaji"
                        message="Tulis hari kerja & jam lembur {{ $bulan }} ke slip Draft? Hari kerja slip disamakan dengan laporan dan semua baris Upah Lembur DIGANTI hasil hitung dari jam lembur. Terapkan Pembagian JM dulu — dasar lembur memuat JM. Slip Final dilewati." confirmText="Ya, terapkan" cancelText="Batal">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                        Terapkan ke Slip
                    </x-confirm-button>
                @endif
                <x-toolbar-refresh-reset :label="null" />
            </div>
        </div>
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
            <x-badge variant="gray">{{ $rows->total() }} karyawan · {{ $jumlahDiubah }} diubah kepegawaian</x-badge>
            <span class="text-xs text-muted">
                @if ($this->diterapkan?->diterapkan_at)
                    Terakhir diterapkan ke slip {{ Format::waktu($this->diterapkan->diterapkan_at) }}{{ $this->diterapkan->name ? ' oleh ' . $this->diterapkan->name : '' }}. Aman diulang: hari kerja & lembur slip diganti angka terbaru, bukan ditambah.
                @else
                    Laporan {{ $bulan }} belum diterapkan ke slip.
                @endif
            </span>
        </div>
    </div>

    {{-- BUKU LAPORAN --}}
    <div class="mt-4 flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="px-4 pt-3">
            <h2 class="font-semibold text-ink dark:text-gray-100">Buku laporan {{ $bulan }}</h2>
            <p class="text-xs text-muted">Baca saja — isian diubah kepegawaian di tab Entry. Nama bertanda ✎ = sudah diubah dari bawaan master; sisanya memakai Hari Kerja Default master, cuti &amp; lembur 0. Kolom Slip = kecocokan hari kerja &amp; jam lembur dengan slip bulan ini.</p>
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
                        <tr wire:key="lapb-{{ $empId }}-{{ $bulan }}">
                            <td>
                                <span class="font-semibold">{{ $row->emp_name }}</span>@if ($lap && $lap['tersimpan'])<span class="ml-1 text-warning-deep dark:text-warning" title="Sudah diubah kepegawaian bulan ini">✎<span class="sr-only"> diubah</span></span>@endif
                                <span class="block font-mono text-xs text-muted">{{ $empId }}{{ $row->unit_name ? ' · ' . $row->unit_name : '' }}</span>
                            </td>
                            <td class="font-mono ds-c">{{ $lap['hari_kerja'] ?? '–' }}<span class="block text-[11px] font-normal text-muted">{{ $row->gaji_status ? 'slip ' . (int) $row->hari_hadir : 'belum ada slip' }}</span></td>
                            <td class="font-mono ds-c">{{ $lap['cuti'] ?? 0 }}</td>
                            <td class="font-mono ds-c">{{ $lap ? $jam($lap['lembur_jam']) : '0' }}@if ($cocokRow && $cocokRow['jam_slip'] > 0)<span class="block text-[11px] font-normal text-muted">slip {{ $jam($cocokRow['jam_slip']) }}</span>@endif</td>
                            <td class="text-sm">{{ $lap['keterangan'] ?? '' }}</td>
                            <td class="ds-c">
                                @if ($statusCocok === 'beda' || $statusCocok === 'final')
                                    <x-badge :variant="$statusCocok === 'final' ? 'danger' : 'warning'"
                                        title="Di slip: {{ $cocokRow['hari_slip'] }} hari, lembur {{ $jam($cocokRow['jam_slip']) }} jam. Laporan: {{ $lap['hari_kerja'] }} hari, {{ $jam($lap['lembur_jam']) }} jam">{{ $statusCocok === 'final' ? 'Final, beda' : 'Belum sesuai' }}</x-badge>
                                @elseif ($statusCocok === 'sesuai' && $row->gaji_status)
                                    <x-badge :variant="$row->gaji_status === 'F' ? 'success' : 'success'">{{ $row->gaji_status === 'F' ? 'Final, sesuai' : 'Sesuai' }}</x-badge>
                                @elseif ($row->gaji_status)
                                    <x-badge :variant="$row->gaji_status === 'F' ? 'success' : 'warning'">{{ GajiKaryawan::STATUS[$row->gaji_status] ?? $row->gaji_status }}</x-badge>
                                @else
                                    <x-badge variant="gray">Belum ada slip</x-badge>
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
