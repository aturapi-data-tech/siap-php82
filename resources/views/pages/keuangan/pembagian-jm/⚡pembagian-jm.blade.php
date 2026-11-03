<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiKaryawan;
use App\Support\JasaMedik;
use App\Support\UnitKerja;

/**
 * PEMBAGIAN JASA MEDIK — pengganti sheet "JaMed" workbook gaji lama.
 *
 * Alur per bulan kerja: isi nilai dasar tiap pos (perolehan JM / pendapatan) & persen →
 *   isi / salin poin karyawan per pos → nilai per poin & JM per orang dihitung otomatis →
 *   "Terapkan ke Slip" menulis baris Jasa Medik ke slip Draft (Gaji Karyawan).
 * Setiap angka disimpan saat kotak ditinggalkan (blur). Hanya role gaji.kelola.
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'bulan')]
    public string $bulan = ''; // mm/yyyy

    public string $searchKeyword = '';
    public string $filterUnit    = '';
    public int    $itemsPerPage  = 25;

    /** [pos => [nilai_dasar, persen_jm, persen_pegawai]] — diikat ke x-text-input-number. */
    public array $posInput = [];

    /** [emp_id => [pos => poin]] — diikat ke x-text-input-number. */
    public array $poinInput = [];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
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
        $this->posInput = JasaMedik::pos($this->periode(), auth()->id());
        $this->poinInput = JasaMedik::poin($this->periode());
        $this->segarkan();
    }

    private function segarkan(): void
    {
        unset($this->ringkasan, $this->karyawan, $this->diterapkan, $this->rows, $this->sumberSalin);
    }

    // ─── Simpan saat blur ─────────────────────────────────────────────────────
    public function updatedPosInput($nilai, $kunci): void
    {
        [$kode, $kolom] = array_pad(explode('.', (string) $kunci, 2), 2, '');
        if (!is_numeric($nilai) || !JasaMedik::ubahPos($this->periode(), $kode, $kolom, (float) $nilai, auth()->id())) {
            $this->dispatch('toast', type: 'error', message: $kolom === 'nilai_dasar' ? 'Nilai dasar tidak valid.' : 'Persen harus 0–100.');
        }
        $this->posInput = JasaMedik::pos($this->periode());
        $this->segarkan();
    }

    public function updatedPoinInput($nilai, $kunci): void
    {
        [$empId, $kode] = array_pad(explode('.', (string) $kunci, 2), 2, '');
        $poin = $nilai === '' || $nilai === null ? 0 : $nilai;
        $lama = JasaMedik::poin($this->periode())[$empId][$kode] ?? null;
        if (is_numeric($poin) && $lama !== null && round((float) $poin, 2) === round($lama, 2)) {
            return;
        }
        if (!is_numeric($poin) || !$this->empIdDikenal($empId) || !JasaMedik::ubahPoin($this->periode(), $empId, $kode, (float) $poin, auth()->id())) {
            $this->dispatch('toast', type: 'error', message: 'Poin harus angka 0–999.');
        }
        $this->poinInput = JasaMedik::poin($this->periode());
        $this->segarkan();
    }

    /** emp_id dari browser tidak dipercaya — harus peserta yang tampil di daftar bulan ini. */
    private function empIdDikenal(string $empId): bool
    {
        return JasaMedik::karyawan($this->periode(), null)->contains('emp_id', $empId);
    }

    // ─── Aksi ─────────────────────────────────────────────────────────────────
    public function salinPoin(): void
    {
        $jumlah = JasaMedik::salinPoin($this->periode(), auth()->id());
        $sumber = JasaMedik::periodePoinSebelumnya($this->periode());
        $this->poinInput = JasaMedik::poin($this->periode());
        $this->segarkan();
        $this->dispatch('toast', type: $jumlah > 0 ? 'success' : 'info', message: $jumlah > 0
            ? "Poin {$jumlah} karyawan disalin dari " . Format::bulan(GajiKaryawan::awalPeriode($sumber)) . '.'
            : 'Tidak ada poin yang perlu disalin (semua karyawan sudah berpoin, atau belum ada bulan sebelumnya).');
    }

    public function terapkan(): void
    {
        $hasil = JasaMedik::terapkan($this->periode(), auth()->id());
        $pesan = "JM {$this->bulan} diterapkan ke {$hasil['diperbarui']} slip.";
        if ($hasil['final'] !== []) {
            $pesan .= ' ' . count($hasil['final']) . ' slip Final dilewati.';
        }
        if ($hasil['tanpaSlip'] !== []) {
            $pesan .= ' ' . count($hasil['tanpaSlip']) . ' karyawan berpoin belum punya slip (Generate dulu): ' . implode(', ', array_slice($hasil['tanpaSlip'], 0, 5)) . (count($hasil['tanpaSlip']) > 5 ? ', …' : '') . '.';
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
    public function ringkasan(): array
    {
        return JasaMedik::ringkasan($this->periode(), JasaMedik::pos($this->periode()), JasaMedik::poin($this->periode()));
    }

    #[Computed]
    public function karyawan()
    {
        return JasaMedik::karyawan($this->periode(), $this->filterUnit !== '' ? $this->filterUnit : null, $this->searchKeyword);
    }

    #[Computed]
    public function diterapkan(): ?object
    {
        return JasaMedik::terakhirDiterapkan($this->periode());
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
    public function sumberSalin(): ?string
    {
        return JasaMedik::periodePoinSebelumnya($this->periode());
    }
};
?>

<div>
    @php $rows = $this->rows; $sumberSalin = $this->sumberSalin; $adaPoin = $poinInput !== []; @endphp
    <x-page-title title="Pembagian Jasa Medik"
        subtitle="Hitung JM karyawan per bulan: nilai dasar per pos → poin per karyawan → JM per orang → terapkan ke slip gaji" />

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
                        <x-input-label for="jm-cari" value="Cari Karyawan" />
                        <x-text-input id="jm-cari" type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="ID / nama" class="block w-full mt-1" />
                    </div>
                    <div class="w-48">
                        <x-input-label for="jm-unit" value="Unit" />
                        <div class="mt-1">
                            <x-select-input id="jm-unit" wire:model.live="filterUnit">
                                <option value="">Semua unit</option>
                                @foreach ($this->unitList as $unit)
                                    <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-end gap-2 ml-auto">
                        @if ($sumberSalin)
                            <x-confirm-button variant="secondary" action="salinPoin()" title="Salin Poin"
                                message="Salin poin dari {{ \App\Support\Format::bulan(\App\Support\GajiKaryawan::awalPeriode($sumberSalin)) }} untuk karyawan yang belum punya poin di {{ $bulan }}? Poin yang sudah diisi tidak diubah." confirmText="Ya, salin" cancelText="Batal">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                Salin Poin Bulan Lalu
                            </x-confirm-button>
                        @endif
                        <x-confirm-button variant="primary" action="terapkan()" title="Terapkan JM ke Slip"
                            message="Tulis JM {{ $bulan }} ke slip Draft karyawan yang berpoin? Semua baris Jasa Medik di slip mereka DIGANTI hasil hitung ini (termasuk yang diisi lewat form Excel JM). Slip Final dilewati." confirmText="Ya, terapkan" cancelText="Batal">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                            Terapkan ke Slip
                        </x-confirm-button>
                        <x-toolbar-refresh-reset :label="null" />
                        <a href="{{ route('keuangan.gaji-karyawan') }}" wire:navigate
                            class="inline-flex items-center gap-1 px-3 py-2.5 text-sm font-medium rounded-lg text-brand-green hover:underline dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Ke Gaji Karyawan →</a>
                    </div>
                </div>
                @if ($this->diterapkan?->diterapkan_at)
                    <p class="mt-2 text-xs text-muted">Terakhir diterapkan ke slip {{ \App\Support\Format::waktu($this->diterapkan->diterapkan_at) }}{{ $this->diterapkan->name ? ' oleh ' . $this->diterapkan->name : '' }}. Ubah angka? Terapkan lagi.</p>
                @else
                    <p class="mt-2 text-xs text-muted">JM {{ $bulan }} belum diterapkan ke slip.</p>
                @endif
            </div>

            {{-- POS JM --}}
            @php $ringkasan = $this->ringkasan; $rp = fn($n) => \App\Support\GajiKaryawan::rupiah($n); @endphp
            <div class="mt-4 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="px-4 pt-3">
                    <h2 class="font-semibold text-ink dark:text-gray-100">1. Perolehan JM per pos</h2>
                    <p class="text-xs text-muted">Bagian pegawai = nilai dasar × % JM × % pegawai (sisanya bagian RS / BPI). Nilai per poin = bagian pegawai ÷ total poin.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="ds-table">
                        <thead>
                            <tr>
                                <th>Pos</th>
                                <th class="text-right">Nilai Dasar (Rp)</th>
                                <th class="text-right">% JM</th>
                                <th class="text-right">% Pegawai</th>
                                <th class="text-right">Bagian Pegawai</th>
                                <th class="text-right">Bagian RS</th>
                                <th class="text-right">Total Poin</th>
                                <th class="text-right">Nilai / Poin</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (\App\Support\JasaMedik::POS as $kode => $pos)
                                @php $data = $ringkasan[$kode]; @endphp
                                <tr wire:key="pos-{{ $kode }}-{{ $bulan }}">
                                    <td>
                                        <span class="font-semibold">{{ $pos['nama'] }}</span>
                                        <span class="block text-xs text-muted">{{ $pos['dasar'] }}</span>
                                    </td>
                                    <td class="min-w-[11rem]"><x-text-input-number wire:model="posInput.{{ $kode }}.nilai_dasar" :decimals="2" aria-label="Nilai dasar {{ $pos['nama'] }}" /></td>
                                    <td class="min-w-[7.5rem]"><x-text-input-number wire:model="posInput.{{ $kode }}.persen_jm" :decimals="3" aria-label="Persen JM {{ $pos['nama'] }}" /></td>
                                    <td class="min-w-[7.5rem]"><x-text-input-number wire:model="posInput.{{ $kode }}.persen_pegawai" :decimals="3" aria-label="Persen pegawai {{ $pos['nama'] }}" /></td>
                                    <td class="font-mono font-semibold text-right">{{ $rp($data['pegawai']) }}</td>
                                    <td class="font-mono text-right text-muted">{{ $rp($data['rs']) }}</td>
                                    <td class="font-mono text-right">{{ rtrim(rtrim(number_format($data['total_poin'], 2, ',', '.'), '0'), ',') }}</td>
                                    <td class="font-mono font-semibold text-right">{{ $rp($data['per_poin']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <td>Total</td>
                                <td class="font-mono text-right">{{ $rp(array_sum(array_column($ringkasan, 'nilai_dasar'))) }}</td>
                                <td></td><td></td>
                                <td class="font-mono text-right">{{ $rp(array_sum(array_column($ringkasan, 'pegawai'))) }}</td>
                                <td class="font-mono text-right">{{ $rp(array_sum(array_column($ringkasan, 'rs'))) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- POIN KARYAWAN --}}
            <div class="mt-4 flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-end justify-between gap-2 px-4 pt-3">
                    <div>
                        <h2 class="font-semibold text-ink dark:text-gray-100">2. Poin karyawan</h2>
                        <p class="text-xs text-muted">Isi poin tiap pos (boleh desimal, mis. 1,25). Kosong = 0. Angka kecil di bawah kotak = JM pos itu.</p>
                    </div>
                    <x-badge variant="gray">{{ $rows->total() }} karyawan</x-badge>
                </div>
                <div class="overflow-x-auto">
                    <table class="ds-table">
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                @foreach (\App\Support\JasaMedik::POS as $pos)
                                    <th class="ds-c">{{ $pos['singkat'] }}</th>
                                @endforeach
                                <th class="text-right">Total JM</th>
                                <th class="ds-c">Slip</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php $jm = \App\Support\JasaMedik::jmKaryawan($poinInput[$row->emp_id] ?? [], $ringkasan); @endphp
                                <tr wire:key="poin-{{ $row->emp_id }}-{{ $bulan }}">
                                    <td>
                                        <span class="font-semibold">{{ $row->emp_name }}</span>
                                        <span class="block font-mono text-xs text-muted">{{ $row->emp_id }}{{ $row->unit_name ? ' · ' . $row->unit_name : '' }}</span>
                                    </td>
                                    @foreach (\App\Support\JasaMedik::POS as $kode => $pos)
                                        <td class="w-24 ds-c">
                                            <x-text-input-number wire:model="poinInput.{{ $row->emp_id }}.{{ $kode }}" :decimals="2" class="w-20"
                                                aria-label="Poin {{ $pos['nama'] }} {{ $row->emp_name }}" />
                                            <span class="block mt-0.5 font-mono text-[11px] text-muted">{{ $jm[$kode] > 0 ? $rp($jm[$kode]) : '–' }}</span>
                                        </td>
                                    @endforeach
                                    <td class="font-mono font-bold text-right">{{ $rp(array_sum($jm)) }}</td>
                                    <td class="ds-c">
                                        @if ($row->gaji_status)
                                            <x-badge :variant="$row->gaji_status === 'F' ? 'success' : 'warning'">{{ \App\Support\GajiKaryawan::STATUS[$row->gaji_status] ?? $row->gaji_status }}</x-badge>
                                        @else
                                            <x-badge variant="gray">Belum ada</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-10 text-center text-muted">
                                        @if ($searchKeyword !== '' || $filterUnit !== '')
                                            Tidak ada karyawan yang cocok dengan saringan. <button type="button" wire:click="resetFilters" class="underline text-brand-green">Reset saringan</button>
                                        @else
                                            Belum ada karyawan peserta. Peserta JM = karyawan aktif yang punya parameter gaji (Master Karyawan → tombol Gaji).
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (!$adaPoin && $rows->total() > 0)
                    <p class="px-4 py-3 text-sm border-t text-body border-hairline dark:border-gray-700">
                        Belum ada poin di {{ $bulan }}. {{ $sumberSalin ? 'Klik "Salin Poin Bulan Lalu" untuk memakai poin bulan sebelumnya, atau isi' : 'Isi' }} poin langsung di tabel.
                    </p>
                @endif
                <div class="px-4 py-3 border-t border-hairline dark:border-gray-700">
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
