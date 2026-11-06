<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiKaryawan;
use App\Support\PinjamanKaryawan;

/**
 * PINJAMAN KARYAWAN — buku pinjaman yang dipotong dari gaji (pengganti workbook koperasi & blok angsuran workbook gaji lama).
 *
 * Dua tab:
 *   ENTRY : form catat / ubah pinjaman (karyawan, sumber, pokok, lama, bulan awal → angsuran / bln terhitung otomatis,
 *           pinjaman baru menutup pinjaman lama) + tabel KETENTUAN per sumber (% sewa modal / bln, pembulatan, baris slip).
 *   BUKU  : daftar pinjaman dengan posisi "ke-n / lama" & sisa pada bulan kerja terpilih, Lunasi / Batal lunas / Hapus,
 *           dan "Terapkan ke Slip" (potongan angsuran bulan kerja → slip Draft, aman diulang).
 * Hanya role gaji.kelola (data gaji & pinjaman = rahasia).
 */
new class extends Component {
    use WithPagination;

    #[Url(as: 'tab')]
    public string $tab = 'buku'; // entry | buku

    #[Url(as: 'bulan')]
    public string $bulan = ''; // mm/yyyy

    public string $searchKeyword = '';
    public string $filterSumber  = '';
    public string $filterStatus  = 'berjalan'; // berjalan | selesai | semua
    public int    $itemsPerPage  = 25;

    /** [sumber_kode => [persen_sewa, pembulatan]] — ketentuan, diikat ke input tabel. */
    public array $sumberInput = [];

    /** Form tambah sumber baru. */
    public array $sumberBaru = ['sumber_kode' => '', 'nama' => '', 'persen_sewa' => 0, 'pembulatan' => 1000, 'kode_potongan' => 'ANGS_KOP'];

    /** Form pinjaman (tab Entry). formId null = pinjaman baru. */
    public ?int  $formId = null;
    public array $form = [];
    public int   $formVersi = 0;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!$this->bulanValid()) {
            $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        }
        if (!in_array($this->tab, ['entry', 'buku'], true)) {
            $this->tab = 'buku';
        }
        $this->resetForm();
        $this->muatSumber();
    }

    public function pilihTab(string $tab): void
    {
        if (in_array($tab, ['entry', 'buku'], true)) {
            $this->tab = $tab;
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

    /** mm/yyyy → YYYY-MM (null bila tidak valid). */
    private function keDbPeriode(?string $mmYyyy): ?string
    {
        return is_string($mmYyyy) && preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $mmYyyy, $m) ? "{$m[2]}-{$m[1]}" : null;
    }

    private function keTampilPeriode(?string $yyyyMm): string
    {
        return PinjamanKaryawan::periodeValid($yyyyMm) ? substr($yyyyMm, 5, 2) . '/' . substr($yyyyMm, 0, 4) : '';
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
        $this->resetPage();
    }

    private function muatSumber(): void
    {
        $this->sumberInput = PinjamanKaryawan::sumber()->map(fn($row) => ['persen_sewa' => (float) $row->persen_sewa, 'pembulatan' => (int) $row->pembulatan])->all();
        $this->segarkan();
    }

    private function segarkan(): void
    {
        unset($this->sumber, $this->rows, $this->cocokSlip, $this->diterapkan, $this->rekap, $this->karyawanPilihan, $this->pratinjau);
    }

    // ─── Ketentuan ────────────────────────────────────────────────────────────
    public function updatedSumberInput($nilai, $kunci): void
    {
        [$kode, $kolom] = array_pad(explode('.', (string) $kunci, 2), 2, '');
        if (!PinjamanKaryawan::ubahSumber($kode, $kolom, $nilai, auth()->id())) {
            $this->dispatch('toast', type: 'error', message: $kolom === 'persen_sewa' ? 'Persen sewa modal harus 0–10.' : 'Pembulatan tidak valid.');
        }
        $this->muatSumber();
    }

    public function tambahSumber(): void
    {
        $galat = PinjamanKaryawan::simpanSumber($this->sumberBaru, auth()->id());
        if ($galat !== null) {
            $this->dispatch('toast', type: 'error', message: $galat);
            return;
        }
        $this->dispatch('toast', type: 'success', message: "Sumber pinjaman {$this->sumberBaru['nama']} ditambahkan.");
        $this->sumberBaru = ['sumber_kode' => '', 'nama' => '', 'persen_sewa' => 0, 'pembulatan' => 1000, 'kode_potongan' => 'ANGS_KOP'];
        $this->muatSumber();
    }

    public function toggleSumber(string $kode): void
    {
        $sumber = PinjamanKaryawan::sumber()[$kode] ?? null;
        if ($sumber) {
            PinjamanKaryawan::ubahSumber($kode, 'active_status', $sumber->active_status === '1' ? '0' : '1', auth()->id());
        }
        $this->muatSumber();
    }

    // ─── Form pinjaman (tab Entry) ────────────────────────────────────────────
    private function resetForm(): void
    {
        $sumberPertama = PinjamanKaryawan::sumber(true)->first();
        $this->formId = null;
        $this->form = ['emp_id' => '', 'sumber_kode' => $sumberPertama?->sumber_kode ?? '', 'pokok' => 0,
            'persen_sewa' => (float) ($sumberPertama?->persen_sewa ?? 0), 'lama_bulan' => 12,
            'bulan_awal' => $this->bulanValid() ? $this->bulan : now()->format(Format::BULAN), 'lunas_periode' => '', 'keterangan' => '', 'tutup_lama' => true];
        $this->formVersi++;
        unset($this->pratinjau);
    }

    public function openTambah(): void
    {
        $this->resetForm();
        $this->tab = 'entry';
    }

    public function openEdit(int $id): void
    {
        $row = PinjamanKaryawan::ambil($id);
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Pinjaman tidak ditemukan.');
            return;
        }
        $this->resetForm();
        $this->formId = $id;
        $this->form = ['emp_id' => $row->emp_id, 'sumber_kode' => $row->sumber_kode, 'pokok' => (float) $row->pokok,
            'persen_sewa' => (float) $row->persen_sewa, 'lama_bulan' => (int) $row->lama_bulan,
            'bulan_awal' => $this->keTampilPeriode($row->bulan_awal), 'lunas_periode' => $this->keTampilPeriode($row->lunas_periode),
            'keterangan' => (string) ($row->keterangan ?? ''), 'tutup_lama' => false];
        $this->tab = 'entry';
    }

    /** Ganti sumber di form → % sewa ikut ketentuan sumber itu. */
    public function updatedFormSumberKode(): void
    {
        $sumber = PinjamanKaryawan::sumber()[$this->form['sumber_kode']] ?? null;
        if ($sumber) {
            $this->form['persen_sewa'] = (float) $sumber->persen_sewa;
        }
        unset($this->pratinjau);
    }

    public function updatedForm(): void
    {
        unset($this->pratinjau);
    }

    public function save(): void
    {
        $hasil = PinjamanKaryawan::simpan($this->formId, [
            ...$this->form,
            'bulan_awal' => $this->keDbPeriode($this->form['bulan_awal'] ?? null) ?? '',
            'lunas_periode' => $this->form['lunas_periode'] === '' ? null : ($this->keDbPeriode($this->form['lunas_periode']) ?? 'x'),
        ], auth()->id());
        if (!$hasil['ok']) {
            $this->dispatch('toast', type: 'error', message: $hasil['pesan']);
            return;
        }
        $pesan = $hasil['pesan'];
        if (!$this->formId && !empty($this->form['tutup_lama'])) {
            $ditutup = PinjamanKaryawan::tutupPinjamanLama($this->form['emp_id'], $this->form['sumber_kode'], $this->keDbPeriode($this->form['bulan_awal']), $hasil['id'], auth()->id());
            if ($ditutup > 0) {
                $pesan .= " {$ditutup} pinjaman lama ditandai dilunasi oleh pinjaman ini.";
            }
        }
        $this->dispatch('toast', type: 'success', message: $pesan . ' Klik Terapkan ke Slip supaya angsuran bulan ini masuk slip.');
        $this->searchKeyword = '';
        $this->filterStatus = 'semua';
        $this->resetForm();
        $this->segarkan();
        $this->tab = 'buku';
    }

    public function batalForm(): void
    {
        $this->resetForm();
        $this->tab = 'buku';
    }

    /** Pratinjau hitungan dari isian form (tanpa menyimpan). */
    #[Computed]
    public function pratinjau(): array
    {
        $sumber = PinjamanKaryawan::sumber()[$this->form['sumber_kode'] ?? ''] ?? null;
        $pokok = is_numeric($this->form['pokok'] ?? null) ? (float) $this->form['pokok'] : 0;
        $persen = is_numeric($this->form['persen_sewa'] ?? null) ? (float) $this->form['persen_sewa'] : 0;
        $lama = is_numeric($this->form['lama_bulan'] ?? null) ? max(1, (int) $this->form['lama_bulan']) : 1;
        $hitung = PinjamanKaryawan::hitung($pokok, $persen, $lama, (int) ($sumber?->pembulatan ?? 1000));
        $awal = $this->keDbPeriode($this->form['bulan_awal'] ?? null);
        $hitung['akhir'] = $awal ? $this->keTampilPeriode(Carbon::createFromFormat('!Y-m', $awal)->addMonthsNoOverflow($lama - 1)->format('Y-m')) : '–';

        return $hitung;
    }

    // ─── Aksi baris (tab Buku) ────────────────────────────────────────────────
    public function lunaskan(int $id): void
    {
        if (!PinjamanKaryawan::lunaskan($id, $this->periode(), auth()->id())) {
            $this->dispatch('toast', type: 'error', message: 'Pelunasan harus setelah bulan awal angsuran.');
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
        if (!PinjamanKaryawan::hapus($id)) {
            $this->dispatch('toast', type: 'error', message: 'Pinjaman tidak ditemukan.');
            return;
        }
        if ($this->formId === $id) {
            $this->resetForm();
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

    #[Computed]
    public function karyawanPilihan()
    {
        return PinjamanKaryawan::karyawanPilihan();
    }
};
?>

<div>
    @php
        $sumberList = $this->sumber;
        $rekap = $this->rekap;
        $rp = fn($n) => GajiKaryawan::rupiah($n);
        $persen = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
        $labelStatus = ['belum' => ['gray', 'Belum mulai'], 'berjalan' => ['success', 'Berjalan'], 'lunas' => ['info', 'Lunas'], 'dilunasi' => ['info', 'Dilunasi']];
    @endphp
    <x-page-title title="Pinjaman Karyawan"
        subtitle="Buku pinjaman koperasi / bank yang dipotong dari gaji: ketentuan per sumber → catat pinjaman → angsuran per bulan terhitung sendiri → terapkan ke slip gaji" />

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">

            {{-- TAB + BULAN KERJA --}}
            <div class="sticky z-30 pt-1 bg-surface-soft top-20 dark:bg-gray-900">
                <div class="flex flex-wrap items-end justify-between gap-3 px-4">
                    <x-tabs variant="underline" class="border-0">
                        <x-tab :active="$tab === 'entry'" wire:click="pilihTab('entry')">
                            <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>Entry Pinjaman{{ $formId ? ' (ubah)' : '' }}
                        </x-tab>
                        <x-tab :active="$tab === 'buku'" wire:click="pilihTab('buku')">
                            <svg class="w-4 h-4 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>Buku Pinjaman
                        </x-tab>
                    </x-tabs>
                    <div class="flex items-end gap-3 pb-1">
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
                        <a href="{{ route('keuangan.gaji-karyawan') }}" wire:navigate
                            class="inline-flex items-center gap-1 px-3 py-2.5 text-sm font-medium rounded-lg text-brand-green hover:underline dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Ke Gaji Karyawan →</a>
                    </div>
                </div>
                <div class="border-b border-hairline dark:border-gray-700"></div>
            </div>

            @if ($tab === 'entry')
                {{-- ════════ TAB ENTRY ════════ --}}
                @php $pratinjau = $this->pratinjau; @endphp
                <div class="grid grid-cols-1 gap-4 mt-4 xl:grid-cols-5">
                    {{-- FORM PINJAMAN --}}
                    <div class="flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl xl:col-span-3 dark:border-gray-700 dark:bg-gray-900" wire:key="form-pinjaman-{{ $formVersi }}">
                        <div class="flex flex-wrap items-start justify-between gap-2 px-4 pt-3">
                            <div>
                                <h2 class="font-semibold text-ink dark:text-gray-100">{{ $formId ? 'Ubah pinjaman' : 'Catat pinjaman baru' }}</h2>
                                <p class="text-xs text-muted">Angsuran / bulan dihitung dari ketentuan sumber; % sewa modal boleh disesuaikan per pinjaman. Simpan → masuk Buku Pinjaman.</p>
                            </div>
                            <x-badge class="shrink-0" :variant="$formId ? 'warning' : 'success'">{{ $formId ? 'Mode: Ubah #' . $formId : 'Mode: Baru' }}</x-badge>
                        </div>
                        <div class="grid grid-cols-1 gap-4 px-4 py-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <x-input-label for="pj-emp" value="Karyawan" />
                                <x-select-input id="pj-emp" wire:model="form.emp_id" :disabled="(bool) $formId" class="w-full mt-1">
                                    <option value="">— pilih karyawan —</option>
                                    @foreach ($this->karyawanPilihan as $karyawan)
                                        <option value="{{ $karyawan->emp_id }}">{{ $karyawan->emp_name }} · {{ $karyawan->emp_id }}{{ $karyawan->unit_name ? ' · ' . $karyawan->unit_name : '' }}</option>
                                    @endforeach
                                </x-select-input>
                                <p class="mt-1 text-xs text-muted">Hanya karyawan aktif yang punya parameter gaji (supaya ada slip untuk dipotong).</p>
                            </div>
                            <div>
                                <x-input-label for="pj-sumber-form" value="Sumber pinjaman" />
                                <x-select-input id="pj-sumber-form" wire:model.live="form.sumber_kode" class="w-full mt-1">
                                    @foreach ($sumberList->where('active_status', '1') as $kode => $sumber)
                                        <option value="{{ $kode }}">{{ $sumber->nama }} ({{ $persen($sumber->persen_sewa) }}% / bln)</option>
                                    @endforeach
                                </x-select-input>
                            </div>
                            <div>
                                <x-input-label for="pj-pokok" value="Pokok pinjaman (Rp)" />
                                <x-text-input-number id="pj-pokok" wire:model="form.pokok" class="w-full mt-1" />
                            </div>
                            <div>
                                <x-input-label for="pj-persen" value="% Sewa modal / bulan" />
                                <x-text-input-number id="pj-persen" wire:model="form.persen_sewa" :decimals="3" class="w-full mt-1" />
                                <p class="mt-1 text-xs text-muted">Terisi dari ketentuan sumber; ubah bila pinjaman ini beda (mis. 0 = tanpa sewa modal).</p>
                            </div>
                            <div>
                                <x-input-label for="pj-lama" value="Lama angsuran (bulan)" />
                                <x-text-input-number id="pj-lama" wire:model="form.lama_bulan" class="w-full mt-1" />
                            </div>
                            <div>
                                <x-input-label for="pj-awal" value="Bulan kerja angsuran ke-1" />
                                <x-text-input id="pj-awal" wire:model.blur="form.bulan_awal" x-mask="99/9999" placeholder="mm/yyyy" class="w-full mt-1 font-mono" />
                            </div>
                            @if ($formId)
                                <div>
                                    <x-input-label for="pj-lunas" value="Dilunasi mulai bulan (opsional)" />
                                    <x-text-input id="pj-lunas" wire:model.blur="form.lunas_periode" x-mask="99/9999" placeholder="mm/yyyy" class="w-full mt-1 font-mono" />
                                    <p class="mt-1 text-xs text-muted">Pelunasan dipercepat: mulai bulan ini tidak dipotong. Kosongkan bila berjalan normal.</p>
                                </div>
                            @else
                                <div class="sm:col-span-2">
                                    <label class="inline-flex items-start gap-2 text-sm cursor-pointer">
                                        <input type="checkbox" wire:model="form.tutup_lama" class="mt-0.5 rounded border-gray-300 text-brand-green focus:ring-brand-green dark:border-gray-600 dark:bg-gray-800">
                                        <span>Pinjaman ini <b>melunasi sisa pinjaman lama</b> (sumber sama) yang masih berjalan — praktik koperasi: satu angsuran per orang. Pinjaman lama ditandai dilunasi mulai bulan awal pinjaman ini.</span>
                                    </label>
                                </div>
                            @endif
                            <div class="sm:col-span-2">
                                <x-input-label for="pj-ket" value="Keterangan" />
                                <x-text-input id="pj-ket" wire:model="form.keterangan" maxlength="200" placeholder="mis. pinjaman lama tarif 1%, TF melunasi bulan 8" class="w-full mt-1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3 p-4 mx-4 text-sm border rounded-xl border-hairline bg-surface-soft sm:grid-cols-4 dark:bg-gray-800 dark:border-gray-700" role="status" aria-live="polite">
                            <div><span class="block text-xs text-muted">Sewa modal / bln</span><span class="font-mono font-semibold">{{ $rp($pratinjau['sewa']) }}</span></div>
                            <div><span class="block text-xs text-muted">Pokok / bln (dibulatkan)</span><span class="font-mono font-semibold">{{ $rp($pratinjau['pokok_bulan']) }}</span></div>
                            <div><span class="block text-xs text-muted">Angsuran / bln</span><span class="font-mono text-base font-bold text-brand-green dark:text-brand-lime">{{ $rp($pratinjau['angsuran']) }}</span></div>
                            <div><span class="block text-xs text-muted">Total s/d {{ $pratinjau['akhir'] }}</span><span class="font-mono font-semibold">{{ $rp($pratinjau['total']) }}</span></div>
                        </div>

                        <div class="flex justify-end gap-2 px-4 py-4 mt-auto">
                            <x-secondary-button type="button" wire:click="batalForm" wire:loading.attr="disabled" wire:target="batalForm">{{ $formId ? 'Batal ubah' : 'Kosongkan' }}</x-secondary-button>
                            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save">{{ $formId ? 'Simpan perubahan' : 'Simpan ke Buku' }}</span>
                                <span wire:loading wire:target="save">Menyimpan...</span>
                            </x-primary-button>
                        </div>
                    </div>

                    {{-- KETENTUAN --}}
                    <div class="flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl xl:col-span-2 dark:border-gray-700 dark:bg-gray-900">
                        <div class="px-4 pt-3">
                            <h2 class="font-semibold text-ink dark:text-gray-100">Ketentuan sumber pinjaman</h2>
                            <p class="text-xs text-muted">Angsuran / bulan = (pokok ÷ lama + pokok × % sewa modal) dibulatkan ke atas. Berlaku untuk pinjaman <b>baru</b>; pinjaman lama tetap pada % saat pinjam. Disimpan saat kotak ditinggalkan.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="ds-table">
                                <thead>
                                    <tr>
                                        <th>Sumber</th>
                                        <th class="text-right">% Sewa / bln</th>
                                        <th class="ds-c">Pembulatan</th>
                                        <th>Baris slip</th>
                                        <th class="ds-c">Aktif</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sumberList as $kode => $sumber)
                                        <tr wire:key="sumber-{{ $kode }}" class="{{ $sumber->active_status === '1' ? '' : 'opacity-60' }}">
                                            <td><span class="font-semibold">{{ $sumber->nama }}</span><span class="block font-mono text-xs text-muted">{{ $kode }} · {{ $rekap[$kode]['jumlah'] ?? 0 }} berjalan</span></td>
                                            <td class="min-w-[7rem]"><x-text-input-number wire:model="sumberInput.{{ $kode }}.persen_sewa" :decimals="3" aria-label="Persen sewa modal {{ $sumber->nama }}" /></td>
                                            <td class="ds-c">
                                                <x-select-input wire:model.live="sumberInput.{{ $kode }}.pembulatan" aria-label="Pembulatan {{ $sumber->nama }}" class="w-24">
                                                    @foreach (PinjamanKaryawan::PEMBULATAN_PILIHAN as $pilihan)
                                                        <option value="{{ $pilihan }}">{{ number_format($pilihan, 0, ',', '.') }}</option>
                                                    @endforeach
                                                </x-select-input>
                                            </td>
                                            <td class="text-xs">{{ PinjamanKaryawan::KODE_POTONGAN[$sumber->kode_potongan] ?? $sumber->kode_potongan }}</td>
                                            <td class="ds-c"><x-toggle :current="$sumber->active_status" trueValue="1" falseValue="0" wireClick="toggleSumber('{{ $kode }}')" aria-label="Aktifkan {{ $sumber->nama }}" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="px-4 py-3 mt-auto border-t border-hairline dark:border-gray-700">
                            <p class="mb-2 text-xs font-semibold text-muted">Tambah sumber baru</p>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                                <x-text-input wire:model="sumberBaru.sumber_kode" maxlength="10" placeholder="KODE" class="font-mono uppercase text-sm" aria-label="Kode sumber baru" />
                                <x-text-input wire:model="sumberBaru.nama" maxlength="40" placeholder="Nama sumber" class="text-sm sm:col-span-2" aria-label="Nama sumber baru" />
                                <x-text-input-number wire:model="sumberBaru.persen_sewa" :decimals="3" aria-label="Persen sewa modal sumber baru" />
                                <x-select-input wire:model="sumberBaru.kode_potongan" aria-label="Baris slip sumber baru" class="text-sm">
                                    @foreach (PinjamanKaryawan::KODE_POTONGAN as $kodePotongan => $labelPotongan)
                                        <option value="{{ $kodePotongan }}">{{ $labelPotongan }}</option>
                                    @endforeach
                                </x-select-input>
                            </div>
                            <div class="flex items-center justify-between gap-2 mt-2">
                                <span class="text-xs text-muted">Pembulatan sumber baru: 1.000 (ubah di tabel setelah ditambah).</span>
                                <x-secondary-button type="button" wire:click="tambahSumber" wire:loading.attr="disabled" wire:target="tambahSumber">Tambah Sumber</x-secondary-button>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                {{-- ════════ TAB BUKU ════════ --}}
                @php
                    $rows = $this->rows;
                    $cocokSemua = $this->cocokSlip;
                    $cocok = collect($cocokSemua);
                    $jumlahBeda = $cocok->where('status', 'beda')->count();
                    $jumlahFinal = $cocok->where('status', 'final')->count();
                    $jumlahTanpaSlip = $cocok->where('status', 'tanpa_slip')->count();
                @endphp
                <div class="px-4 pt-3 pb-3 border-b bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                    <div class="flex flex-wrap items-end gap-3">
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

                <div class="mt-4 flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-wrap items-end justify-between gap-2 px-4 pt-3">
                        <div>
                            <h2 class="font-semibold text-ink dark:text-gray-100">Buku pinjaman</h2>
                            <p class="text-xs text-muted">Posisi <b>ke-n / lama</b> dan sisa dihitung dari bulan kerja di atas. Pinjaman berhenti sendiri setelah angsuran terakhir; pelunasan dipercepat lewat tombol <b>Lunasi</b>; ubah lewat ikon pensil (membuka tab Entry).</p>
                        </div>
                        <x-badge variant="gray">{{ $rows->total() }} pinjaman</x-badge>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="ds-table">
                            <thead>
                                <tr>
                                    <th>Karyawan</th>
                                    <th>Sumber</th>
                                    <th class="text-right">Pokok</th>
                                    <th class="text-right">% Sewa</th>
                                    <th class="ds-c">Lama</th>
                                    <th class="ds-c">Mulai – Akhir</th>
                                    <th class="text-right">Angsuran / bln</th>
                                    <th class="ds-c">Posisi {{ $bulan }}</th>
                                    <th class="ds-c">Slip</th>
                                    <th class="ds-c">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    @php [$warnaStatus, $teksStatus] = $labelStatus[$row->status] ?? ['gray', $row->status]; $statusCocok = $cocokSemua[$row->emp_id]['status'] ?? null; @endphp
                                    <tr wire:key="pinjaman-{{ $row->pinjaman_id }}-{{ $bulan }}">
                                        <td>
                                            <span class="font-semibold">{{ $row->emp_name }}</span>@if ($row->emp_aktif !== '1')<x-badge variant="gray" class="ml-1">non-aktif</x-badge>@endif
                                            <span class="block font-mono text-xs text-muted">{{ $row->emp_id }}{{ $row->unit_name ? ' · ' . $row->unit_name : '' }}</span>
                                            @if ($row->keterangan)<span class="block text-xs text-muted">{{ $row->keterangan }}</span>@endif
                                        </td>
                                        <td>{{ $row->sumber_nama }}</td>
                                        <td class="font-mono text-right">{{ $rp($row->pokok) }}</td>
                                        <td class="font-mono text-right">{{ $persen($row->persen_sewa) }}%<span class="block text-[11px] text-muted">{{ $rp($row->sewa) }}/bln</span></td>
                                        <td class="font-mono ds-c">{{ $row->lama_bulan }} bln</td>
                                        <td class="font-mono ds-c text-xs whitespace-nowrap">{{ substr($row->bulan_awal, 5, 2) }}/{{ substr($row->bulan_awal, 0, 4) }} – {{ substr($row->bulan_akhir, 5, 2) }}/{{ substr($row->bulan_akhir, 0, 4) }}@if ($row->lunas_periode)<span class="block text-info-deep dark:text-info">dilunasi {{ substr($row->lunas_periode, 5, 2) }}/{{ substr($row->lunas_periode, 0, 4) }}</span>@endif</td>
                                        <td class="font-mono font-semibold text-right">{{ $rp($row->angsuran) }}<span class="block text-[11px] font-normal text-muted">total {{ $rp($row->total) }}</span></td>
                                        <td class="ds-c">
                                            <x-badge :variant="$warnaStatus">{{ $teksStatus }}</x-badge>
                                            @if ($row->status === 'berjalan')<span class="block mt-0.5 font-mono text-xs">ke-{{ $row->ke }}/{{ $row->lama_bulan }} · sisa {{ $row->sisa }}</span>@endif
                                        </td>
                                        <td class="ds-c">
                                            @if ($statusCocok === 'beda' || $statusCocok === 'final')
                                                <x-badge :variant="$statusCocok === 'final' ? 'danger' : 'warning'" title="Di slip Rp {{ $rp($cocokSemua[$row->emp_id]['slip']) }}, buku pinjaman Rp {{ $rp($cocokSemua[$row->emp_id]['hitung']) }}">{{ $statusCocok === 'final' ? 'Final, beda' : 'Belum sesuai' }}</x-badge>
                                            @elseif ($statusCocok === 'tanpa_slip')
                                                <x-badge variant="gray">Belum ada slip</x-badge>
                                            @elseif ($statusCocok === 'sesuai')
                                                <x-badge variant="success">Sesuai</x-badge>
                                            @else
                                                <span class="text-muted">–</span>
                                            @endif
                                        </td>
                                        <td class="ds-c whitespace-nowrap">
                                            <x-action-edit wire:click="openEdit({{ $row->pinjaman_id }})" title="Ubah pinjaman (tab Entry)" />
                                            @if ($row->status === 'berjalan')
                                                <x-confirm-button variant="warning-soft" action="lunaskan({{ $row->pinjaman_id }})" title="Lunasi dipercepat" class="!px-2 !py-1 text-xs"
                                                    message="Tandai pinjaman {{ $row->emp_name }} ({{ $row->sumber_nama }}) lunas mulai bulan kerja {{ $bulan }}? Mulai bulan itu tidak dipotong lagi. Pinjaman tetap tercatat sebagai riwayat." confirmText="Ya, lunas" cancelText="Batal">Lunasi</x-confirm-button>
                                            @elseif ($row->status === 'dilunasi')
                                                <x-confirm-button variant="secondary" action="batalLunas({{ $row->pinjaman_id }})" title="Batalkan tanda lunas" class="!px-2 !py-1 text-xs"
                                                    message="Batalkan tanda lunas {{ $row->emp_name }}? Angsuran berjalan lagi sesuai jadwal." confirmText="Ya, batalkan" cancelText="Batal">Batal lunas</x-confirm-button>
                                            @endif
                                            <x-confirm-button variant="danger-soft" action="hapus({{ $row->pinjaman_id }})" title="Hapus pinjaman" class="!px-2 !py-1 text-xs"
                                                message="Hapus pinjaman {{ $row->emp_name }} ({{ $row->sumber_nama }}, {{ $rp($row->pokok) }})? Hanya untuk salah input — riwayatnya hilang. Untuk pinjaman selesai pakai Lunasi." confirmText="Ya, hapus" cancelText="Batal">Hapus</x-confirm-button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="px-6 py-10 text-center text-muted">
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
            @endif
        </div>
    </div>
</div>
