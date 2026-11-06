<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiKaryawan;
use App\Support\PinjamanKaryawan;

/**
 * Tab BUKU Pinjaman Karyawan (anak pembungkus pinjaman-karyawan):
 *   daftar pinjaman dengan posisi "ke-n / lama" & sisa pada bulan kerja terpilih, Lunasi / Batal lunas / Hapus,
 *   dan "Terapkan ke Slip" (potongan angsuran bulan kerja → slip Draft, aman diulang).
 * Ubah pinjaman → event 'keuangan.pinjaman.openEdit' (pembungkus pindah ke tab Entry); Pinjaman Baru → 'keuangan.pinjaman.openTambah'.
 * Saringan & bulan kerja disimpan di Session (komponen di-unmount saat ganti tab — docs/tabs-pattern.md).
 */
new class extends Component {
    use WithPagination;

    #[Session(key: 'pinjaman-karyawan-bulan')]
    public string $bulan = ''; // mm/yyyy

    #[Session(key: 'pinjaman-karyawan-searchKeyword')]
    public string $searchKeyword = '';

    #[Session(key: 'pinjaman-karyawan-filterSumber')]
    public string $filterSumber = '';

    #[Session(key: 'pinjaman-karyawan-filterStatus')]
    public string $filterStatus = 'berjalan'; // berjalan | selesai | semua

    #[Session(key: 'pinjaman-karyawan-itemsPerPage')]
    public int $itemsPerPage = 25;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        // GUARD: hanya default bila kosong → nilai Session bertahan saat remount (ganti tab).
        if (!$this->bulanValid()) {
            $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        }
        if (!in_array($this->filterStatus, ['berjalan', 'selesai', 'semua'], true)) {
            $this->filterStatus = 'berjalan';
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
    public function updatedFilterSumber(): void  { $this->resetPage(); }
    public function updatedFilterStatus(): void  { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        $this->resetPage();
        $this->segarkan();
    }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterSumber']);
        $this->filterStatus = 'berjalan';
        $this->itemsPerPage = 25;
        $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        $this->resetPage();
        $this->segarkan();
    }

    private function segarkan(): void
    {
        unset($this->sumber, $this->rows, $this->cocokSlip, $this->diterapkan, $this->rekap);
    }

    // ─── Aksi ─────────────────────────────────────────────────────────────────
    public function openTambah(): void
    {
        $this->dispatch('keuangan.pinjaman.openTambah');
    }

    public function openEdit(int $id): void
    {
        $this->dispatch('keuangan.pinjaman.openEdit', id: $id);
    }

    public function openBayar(int $id): void
    {
        $this->dispatch('keuangan.pinjaman.openBayar', id: $id);
    }

    /** Tagih semua tunggakan pinjaman ini di slip bulan kerja terpilih. */
    public function tagihTunggakan(int $id): void
    {
        $row = PinjamanKaryawan::ambil($id, $this->periode());
        if (!$row || $row->tunggakan === []) {
            $this->dispatch('toast', type: 'warning', message: 'Tidak ada tunggakan.');
            return;
        }
        $hasil = PinjamanKaryawan::tagihDiSlip($id, $row->tunggakan, $this->periode(), auth()->id());
        $this->dispatch('toast', type: $hasil['dibuat'] > 0 ? 'success' : 'error', message: ($hasil['dibuat'] > 0 ? "{$hasil['dibuat']} tunggakan ditagih di slip {$this->bulan} — klik Terapkan ke Slip. " : '') . implode(' ', $hasil['dilewati']));
        $this->segarkan();
    }


    public function lunaskan(int $id): void
    {
        $galat = PinjamanKaryawan::lunaskan($id, $this->periode(), auth()->id());
        if ($galat !== null) {
            $this->dispatch('toast', type: 'error', message: $galat);
            return;
        }
        $this->dispatch('toast', type: 'success', message: "Ditandai lunas: mulai bulan kerja {$this->bulan} tidak dipotong lagi. Klik Terapkan ke Slip bila slip bulan ini sudah berisi angsurannya.");
        $this->segarkan();
    }

    public function batalLunas(int $id): void
    {
        PinjamanKaryawan::batalLunas($id, auth()->id());
        $this->dispatch('toast', type: 'success', message: 'Tanda lunas dibatalkan — angsuran berjalan lagi sesuai jadwal.');
        $this->segarkan();
    }

    public function hapus(int $id): void
    {
        $galat = PinjamanKaryawan::hapus($id);
        if ($galat !== null) {
            $this->dispatch('toast', type: 'error', message: $galat);
            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Pinjaman dihapus. Angsuran yang sudah ada di slip Draft dibersihkan saat Terapkan berikutnya.');
        $this->segarkan();
    }

    public function terapkan(): void
    {
        $hasil = PinjamanKaryawan::terapkan($this->periode(), auth()->id());
        $pesan = "Angsuran {$this->bulan} diterapkan ke {$hasil['diperbarui']} slip.";
        if ($hasil['dibersihkan'] > 0) {
            $pesan .= " Angsuran lama dihapus dari {$hasil['dibersihkan']} slip yang kini tanpa tagihan (lunas / dihapus).";
        }
        if ($hasil['final'] !== []) {
            $pesan .= ' ' . count($hasil['final']) . ' slip Final dilewati.';
        }
        if ($hasil['tanpaSlip'] !== []) {
            $pesan .= ' ' . count($hasil['tanpaSlip']) . ' peminjam belum punya slip (Generate dulu): ' . implode(', ', array_slice($hasil['tanpaSlip'], 0, 5)) . (count($hasil['tanpaSlip']) > 5 ? ', …' : '') . '.';
        }
        $this->dispatch('toast', type: $hasil['diperbarui'] > 0 || $hasil['dibersihkan'] > 0 ? 'success' : 'warning', message: $pesan);
        $this->segarkan();
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function sumber()
    {
        return PinjamanKaryawan::sumber();
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $semua = PinjamanKaryawan::daftar($this->periode(), $this->filterSumber !== '' ? $this->filterSumber : null, $this->filterStatus, $this->searchKeyword);
        $halaman = $this->getPage();

        return new LengthAwarePaginator($semua->forPage($halaman, $this->itemsPerPage)->values(), $semua->count(), $this->itemsPerPage, $halaman,
            ['path' => request()->url(), 'pageName' => 'page']);
    }

    #[Computed]
    public function cocokSlip(): array
    {
        return PinjamanKaryawan::cocokSlip($this->periode());
    }

    #[Computed]
    public function diterapkan(): ?object
    {
        return PinjamanKaryawan::terakhirDiterapkan($this->periode());
    }

    #[Computed]
    public function rekap(): array
    {
        return PinjamanKaryawan::rekap($this->periode());
    }
};
?>

<div>
    @php
        $rows = $this->rows;
        $sumberList = $this->sumber;
        $rekap = $this->rekap;
        $cocokSemua = $this->cocokSlip;
        $cocok = collect($cocokSemua);
        $jumlahBeda = $cocok->where('status', 'beda')->count();
        $jumlahFinal = $cocok->where('status', 'final')->count();
        $jumlahTanpaSlip = $cocok->where('status', 'tanpa_slip')->count();
        $rp = fn($n) => GajiKaryawan::rupiah($n);
        $persen = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
        $labelStatus = ['belum' => ['gray', 'Belum mulai'], 'berjalan' => ['success', 'Berjalan'], 'lunas' => ['info', 'Lunas'], 'dilunasi' => ['info', 'Dilunasi']];
        $periodeKini = preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $bulan, $m) ? "{$m[2]}-{$m[1]}" : '';
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
                <x-input-label for="pj-cari" value="Cari Karyawan" />
                <x-text-input id="pj-cari" type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="ID / nama" class="block w-full mt-1" />
            </div>
            <div class="w-44">
                <x-input-label for="pj-sumber" value="Sumber" />
                <div class="mt-1">
                    <x-select-input id="pj-sumber" wire:model.live="filterSumber">
                        <option value="">Semua sumber</option>
                        @foreach ($sumberList as $kode => $sumber)
                            <option value="{{ $kode }}">{{ $sumber->nama }}</option>
                        @endforeach
                    </x-select-input>
                </div>
            </div>
            <div class="w-44">
                <x-input-label for="pj-status" value="Status" />
                <div class="mt-1">
                    <x-select-input id="pj-status" wire:model.live="filterStatus">
                        <option value="berjalan">Berjalan / belum mulai</option>
                        <option value="selesai">Lunas</option>
                        <option value="semua">Semua</option>
                    </x-select-input>
                </div>
            </div>
            <div class="flex flex-wrap items-end gap-2 ml-auto">
                <x-secondary-button type="button" wire:click="openTambah" wire:loading.attr="disabled" wire:target="openTambah">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Pinjaman Baru
                </x-secondary-button>
                <x-confirm-button variant="primary" action="terapkan()" title="Terapkan Angsuran ke Slip"
                    message="Tulis angsuran bulan kerja {{ $bulan }} ke slip Draft tiap peminjam? Baris angsuran koperasi / bank di slip mereka DIGANTI hasil hitung buku pinjaman; angsuran pinjaman yang sudah lunas dihapus. Slip Final dilewati." confirmText="Ya, terapkan" cancelText="Batal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                    Terapkan ke Slip
                </x-confirm-button>
                <x-toolbar-refresh-reset :label="null" />
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 mt-2" role="status" aria-live="polite">
            @if ($jumlahBeda > 0)
                <x-badge variant="warning">{{ $jumlahBeda }} slip Draft belum sesuai buku pinjaman — klik Terapkan ke Slip</x-badge>
            @elseif ($cocok->isNotEmpty() && $jumlahTanpaSlip === 0)
                <x-badge variant="success">Semua slip Draft sudah sesuai buku pinjaman</x-badge>
            @endif
            @if ($jumlahTanpaSlip > 0)
                <x-badge variant="gray">{{ $jumlahTanpaSlip }} peminjam belum punya slip — Generate di Gaji Karyawan</x-badge>
            @endif
            @if ($jumlahFinal > 0)
                <x-badge variant="danger">{{ $jumlahFinal }} slip Final beda — buka ke Draft dulu bila perlu diubah</x-badge>
            @endif
            @foreach ($sumberList->where('active_status', '1') as $kode => $sumber)
                @if (($rekap[$kode]['jumlah'] ?? 0) > 0)
                    <x-badge variant="info">{{ $sumber->nama }}: {{ $rekap[$kode]['jumlah'] }} berjalan · {{ $rp($rekap[$kode]['angsuran']) }}</x-badge>
                @endif
            @endforeach
            <span class="text-xs text-muted">
                @if ($this->diterapkan?->diterapkan_at)
                    Terakhir diterapkan {{ Format::waktu($this->diterapkan->diterapkan_at) }}{{ $this->diterapkan->name ? ' oleh ' . $this->diterapkan->name : '' }}. Aman diulang: angsuran di slip diganti angka terbaru, bukan ditambah.
                @else
                    Angsuran {{ $bulan }} belum diterapkan ke slip.
                @endif
            </span>
        </div>
    </div>

    {{-- BUKU --}}
    <div class="mt-4 flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-end justify-between gap-2 px-4 pt-3">
            <div>
                <h2 class="font-semibold text-ink dark:text-gray-100">Buku pinjaman</h2>
                <p class="text-xs text-muted">Posisi <b>ke-n / lama</b> dan sisa dihitung dari bulan kerja di atas. Pinjaman berhenti sendiri setelah angsuran terakhir; pelunasan dipercepat lewat tombol <b>Lunasi</b>. Pinjaman yang sudah punya pembayaran <b>terkunci</b>: tidak bisa dihapus, pensil hanya untuk keterangan &amp; pelunasan. Riwayat per orang ada di tab Pembayaran.</p>
            </div>
            <x-badge variant="gray">{{ $rows->total() }} pinjaman</x-badge>
        </div>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th>Karyawan</th>
                        <th>Pinjaman</th>
                        <th class="text-right">Angsuran / bln</th>
                        <th>Posisi {{ $bulan }} &amp; pembayaran</th>
                        <th class="ds-c">Slip</th>
                        <th class="ds-c">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php [$warnaStatus, $teksStatus] = $labelStatus[$row->status] ?? ['gray', $row->status]; $statusCocok = $cocokSemua[$row->emp_id]['status'] ?? null; @endphp
                        <tr wire:key="pinjaman-{{ $row->pinjaman_id }}-{{ $bulan }}">
                            <td class="whitespace-nowrap">
                                <span class="font-semibold">{{ $row->emp_name }}</span>@if ($row->emp_aktif !== '1')<x-badge variant="gray" class="ml-1">non-aktif</x-badge>@endif
                                <span class="block font-mono text-xs text-muted">{{ $row->emp_id }}{{ $row->unit_name ? ' · ' . $row->unit_name : '' }}</span>
                                @php $catatan = $row->keterangan && !str_starts_with($row->keterangan, 'Impor Excel') ? $row->keterangan : ''; @endphp
                                @if ($catatan !== '')<span class="block max-w-[14rem] text-xs truncate text-muted" title="{{ $row->keterangan }}">{{ $catatan }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="font-semibold">{{ $row->sumber_nama }}</span> <span class="font-mono">{{ $rp($row->pokok) }}</span>
                                <span class="block font-mono text-xs text-muted">{{ $persen($row->persen_sewa) }}% ({{ $rp($row->sewa) }}/bln) · {{ substr($row->bulan_awal, 5, 2) }}/{{ substr($row->bulan_awal, 0, 4) }} – {{ substr($row->bulan_akhir, 5, 2) }}/{{ substr($row->bulan_akhir, 0, 4) }}</span>
                                @if ($row->lunas_periode)<span class="block text-xs text-info-deep dark:text-info">dilunasi {{ substr($row->lunas_periode, 5, 2) }}/{{ substr($row->lunas_periode, 0, 4) }}</span>@endif
                            </td>
                            <td class="font-mono font-semibold text-right whitespace-nowrap">{{ $rp($row->angsuran) }}<span class="block text-[11px] font-normal text-muted">{{ $row->angsuran_ditetapkan ? 'tetap · ' : '' }}total {{ $rp($row->total) }}</span></td>
                            <td class="text-xs whitespace-nowrap">
                                @php $selesai = in_array($row->status, ['lunas', 'dilunasi'], true); $sisaKali = $selesai ? 0 : max(0, (int) $row->lama_bulan - $row->terbayar); $sisaRp = $selesai ? 0 : max(0, $row->total_hutang - $row->terbayar_nominal); @endphp
                                <x-badge :variant="$warnaStatus">{{ $teksStatus }}</x-badge>
                                @if ($row->status === 'berjalan')<span class="font-mono">angsuran ke-<b>{{ $row->ke }}</b>/{{ $row->lama_bulan }}</span>@endif
                                <span class="block mt-0.5 font-mono">dibayar {{ $row->terbayar }}× = <b>{{ $rp($row->terbayar_nominal) }}</b></span>
                                <span class="block font-mono {{ $sisaRp > 0 ? 'text-ink dark:text-gray-100' : 'text-success-deep dark:text-success' }}">sisa {{ $sisaKali }}× = <b>{{ $rp($sisaRp) }}</b></span>
                                @if ($row->tunggakan !== [])
                                    <span class="block font-semibold text-danger-deep dark:text-danger" title="{{ implode(', ', array_map(fn($p) => PinjamanKaryawan::labelPeriode($p), $row->tunggakan)) }}">tunggakan {{ count($row->tunggakan) }} bln</span>
                                @endif
                                @if ($row->luar_gaji_bulan_ini)
                                    <x-badge variant="success" class="mt-0.5">bulan ini: {{ PinjamanKaryawan::SUMBER_BAYAR[$row->bayar_bulan_ini->sumber] ?? $row->bayar_bulan_ini->sumber }}</x-badge>
                                @elseif ($row->bayar_bulan_ini && $row->bayar_bulan_ini->dipotong_periode !== $periodeKini)
                                    <x-badge variant="info" class="mt-0.5">bulan ini: dipotong slip {{ PinjamanKaryawan::labelPeriode($row->bayar_bulan_ini->dipotong_periode) }}</x-badge>
                                @elseif ($row->status === 'berjalan')
                                    <span class="block text-muted">bulan ini: potong gaji</span>
                                @endif
                            </td>
                            <td class="ds-c">
                                @if ($statusCocok === 'beda' || $statusCocok === 'final')
                                    <x-badge :variant="$statusCocok === 'final' ? 'danger' : 'warning'" class="whitespace-nowrap" title="Di slip Rp {{ $rp($cocokSemua[$row->emp_id]['slip']) }}, buku pinjaman Rp {{ $rp($cocokSemua[$row->emp_id]['hitung']) }}">{{ $statusCocok === 'final' ? 'Final, beda' : 'Belum sesuai' }}</x-badge>
                                @elseif ($statusCocok === 'tanpa_slip')
                                    <x-badge variant="gray">Belum ada slip</x-badge>
                                @elseif ($statusCocok === 'sesuai')
                                    <x-badge variant="success">Sesuai</x-badge>
                                @else
                                    <span class="text-muted">–</span>
                                @endif
                            </td>
                            <td class="ds-c">
                                <div class="flex flex-wrap items-center justify-end gap-1 max-w-[13rem] ml-auto">
                                    <x-icon-button color="green" class="!p-1.5" wire:click="openEdit({{ $row->pinjaman_id }})" title="{{ $row->terbayar > 0 ? 'Ubah keterangan / pelunasan (data pokok terkunci)' : 'Ubah pinjaman' }}" aria-label="Ubah pinjaman {{ $row->emp_name }}">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    </x-icon-button>
                                    @if ($row->status !== 'lunas' && $row->status !== 'dilunasi')
                                        <x-icon-button color="blue" class="!p-1.5" wire:click="openBayar({{ $row->pinjaman_id }})" title="Catat pembayaran di luar gaji" aria-label="Bayar pinjaman {{ $row->emp_name }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                                        </x-icon-button>
                                    @endif
                                    @if ($row->tunggakan !== [])
                                        <x-confirm-button variant="warning-soft" action="tagihTunggakan({{ $row->pinjaman_id }})" title="Tagih tunggakan di slip" class="!px-2 !py-1 text-xs"
                                            message="Tagih {{ count($row->tunggakan) }} tunggakan {{ $row->emp_name }} ({{ implode(', ', array_map(fn($p) => PinjamanKaryawan::labelPeriode($p), $row->tunggakan)) }}) di slip bulan kerja {{ $bulan }}, selain angsuran bulan ini? Potongan bulan ini jadi dobel." confirmText="Ya, tagih" cancelText="Batal">Tagih tunggakan</x-confirm-button>
                                    @endif
                                    @if ($row->status === 'berjalan')
                                        <x-confirm-button variant="warning-soft" action="lunaskan({{ $row->pinjaman_id }})" title="Lunasi dipercepat" class="!px-2 !py-1 text-xs"
                                            message="Tandai pinjaman {{ $row->emp_name }} ({{ $row->sumber_nama }}) lunas mulai bulan kerja {{ $bulan }}? Mulai bulan itu tidak dipotong lagi. Pinjaman tetap tercatat sebagai riwayat." confirmText="Ya, lunas" cancelText="Batal">Lunasi</x-confirm-button>
                                    @elseif ($row->status === 'dilunasi')
                                        <x-confirm-button variant="secondary" action="batalLunas({{ $row->pinjaman_id }})" title="Batalkan tanda lunas" class="!px-2 !py-1 text-xs"
                                            message="Batalkan tanda lunas {{ $row->emp_name }}? Angsuran berjalan lagi sesuai jadwal." confirmText="Ya, batalkan" cancelText="Batal">Batal lunas</x-confirm-button>
                                    @endif
                                    @if ($row->terbayar === 0)
                                        <x-confirm-button variant="danger-soft" action="hapus({{ $row->pinjaman_id }})" title="Hapus pinjaman (belum ada pembayaran)" class="!px-2 !py-1 text-xs"
                                            message="Hapus pinjaman {{ $row->emp_name }} ({{ $row->sumber_nama }}, {{ $rp($row->pokok) }})? Hanya untuk salah input sebelum ada pembayaran." confirmText="Ya, hapus" cancelText="Batal">Hapus</x-confirm-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-10 text-center text-muted">
                                @if ($searchKeyword !== '' || $filterSumber !== '' || $filterStatus !== 'berjalan')
                                    Tidak ada pinjaman yang cocok dengan saringan. <button type="button" wire:click="resetFilters" class="underline text-brand-green">Reset saringan</button>
                                @else
                                    Belum ada pinjaman berjalan. Klik <b>Pinjaman Baru</b> untuk mencatat di tab Entry.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-hairline dark:border-gray-700">
            {{ $rows->links() }}
        </div>
    </div>
</div>
