<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiKaryawan;
use App\Support\PinjamanKaryawan;

/**
 * Tab ENTRY Pinjaman Karyawan (anak pembungkus pinjaman-karyawan):
 *   form catat pinjaman baru / ubah pinjaman ($editId dari pembungkus) → angsuran / bln terhitung otomatis (pratinjau),
 *   pinjaman baru boleh menutup pinjaman lama yang masih berjalan (praktik koperasi). Ketentuan per sumber ada di tab Ketentuan.
 * Simpan → event 'keuangan.pinjaman.saved' (pembungkus pindah ke tab Buku). Batal → 'keuangan.pinjaman.batal'.
 * Bulan kerja terakhir di tab Buku (Session) dipakai sebagai bawaan bulan awal angsuran.
 */
new class extends Component {
    public ?int $editId = null;

    /** Mode ubah pada pinjaman yang sudah punya pembayaran: data pokok terkunci, hanya keterangan & pelunasan. */
    public bool $terkunci = false;

    /** Bulan kerja terakhir dipilih di tab Buku — kunci Session sama dengan pinjaman-buku. */
    #[Session(key: 'pinjaman-karyawan-bulan')]
    public string $bulan = '';

    public array $form = [];
    public int   $formVersi = 0;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(?int $editId = null): void
    {
        $this->editId = $editId;
        $this->terkunci = $editId !== null && PinjamanKaryawan::punyaRiwayat($editId);
        $this->resetForm();
        if ($editId !== null && !$this->isiFormDari($editId)) {
            $this->editId = null;
            $this->dispatch('toast', type: 'error', message: 'Pinjaman tidak ditemukan.');
        }
    }

    private function bulanValid(): bool
    {
        return (bool) preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $this->bulan);
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

    // ─── Form pinjaman ────────────────────────────────────────────────────────
    private function resetForm(): void
    {
        $sumberPertama = PinjamanKaryawan::sumber(true)->first();
        $this->form = ['emp_id' => '', 'emp_nama' => '', 'sumber_kode' => $sumberPertama?->sumber_kode ?? '', 'pokok' => 0,
            'persen_sewa' => (float) ($sumberPertama?->persen_sewa ?? 0), 'lama_bulan' => 12,
            'bulan_awal' => $this->bulanValid() ? $this->bulan : now()->format(Format::BULAN), 'lunas_periode' => '', 'keterangan' => '', 'tutup_lama' => true, 'angsuran_tetap' => 0];
        $this->formVersi++;
        unset($this->pratinjau);
    }

    private function isiFormDari(int $id): bool
    {
        $row = PinjamanKaryawan::ambil($id);
        if (!$row) {
            return false;
        }
        $this->form = ['emp_id' => $row->emp_id, 'emp_nama' => (string) $row->emp_name, 'sumber_kode' => $row->sumber_kode, 'pokok' => (float) $row->pokok,
            'persen_sewa' => (float) $row->persen_sewa, 'lama_bulan' => (int) $row->lama_bulan,
            'bulan_awal' => $this->keTampilPeriode($row->bulan_awal), 'lunas_periode' => $this->keTampilPeriode($row->lunas_periode),
            'keterangan' => (string) ($row->keterangan ?? ''), 'tutup_lama' => false, 'angsuran_tetap' => (float) ($row->angsuran_tetap ?? 0)];
        $this->formVersi++;
        unset($this->pratinjau);

        return true;
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
        $hasil = PinjamanKaryawan::simpan($this->editId, [
            ...$this->form,
            'bulan_awal' => $this->keDbPeriode($this->form['bulan_awal'] ?? null) ?? '',
            'lunas_periode' => $this->form['lunas_periode'] === '' ? null : ($this->keDbPeriode($this->form['lunas_periode']) ?? 'x'),
        ], auth()->id());
        if (!$hasil['ok']) {
            $this->dispatch('toast', type: 'error', message: $hasil['pesan']);
            return;
        }
        $pesan = $hasil['pesan'];
        if (!$this->editId && !empty($this->form['tutup_lama'])) {
            $ditutup = PinjamanKaryawan::tutupPinjamanLama($this->form['emp_id'], $this->form['sumber_kode'], $this->keDbPeriode($this->form['bulan_awal']), $hasil['id'], auth()->id());
            if ($ditutup > 0) {
                $pesan .= " {$ditutup} pinjaman lama ditandai dilunasi oleh pinjaman ini.";
            }
        }
        $this->dispatch('toast', type: 'success', message: $pesan . ' Klik Terapkan ke Slip di tab Buku supaya angsuran bulan ini masuk slip.');
        $this->dispatch('keuangan.pinjaman.saved');
    }

    public function batal(): void
    {
        if ($this->editId) {
            $this->dispatch('keuangan.pinjaman.batal');
            return;
        }
        $this->resetForm();
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
        $tetap = is_numeric($this->form['angsuran_tetap'] ?? null) ? (float) $this->form['angsuran_tetap'] : 0;
        $hitung['ditetapkan'] = $tetap > 0;
        if ($tetap > 0) {
            $hitung['angsuran'] = $tetap;
            $hitung['total'] = $tetap * $lama;
        }
        $awal = $this->keDbPeriode($this->form['bulan_awal'] ?? null);
        $hitung['akhir'] = $awal ? $this->keTampilPeriode(Carbon::createFromFormat('!Y-m', $awal)->addMonthsNoOverflow($lama - 1)->format('Y-m')) : '–';

        return $hitung;
    }

    #[Computed]
    public function sumber()
    {
        return PinjamanKaryawan::sumber();
    }

    /** Opsi LOV karyawan (x-combobox): id = emp_id, nama = nama karyawan, kelas = ID · unit (ikut dicari saat mengetik). */
    #[Computed]
    public function karyawanPilihan(): array
    {
        return PinjamanKaryawan::karyawanPilihan()
            ->map(fn($row) => ['id' => $row->emp_id, 'nama' => $row->emp_name, 'kelas' => $row->emp_id . ($row->unit_name ? ' · ' . $row->unit_name : '')])
            ->all();
    }

};
?>

<div>
    @php
        $sumberList = $this->sumber;
        $pratinjau = $this->pratinjau;
        $rp = fn($n) => GajiKaryawan::rupiah($n);
        $persen = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
    @endphp
    <div class="mt-4 max-w-4xl">
        {{-- FORM PINJAMAN --}}
        <div class="flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900" wire:key="form-pinjaman-{{ $formVersi }}">
            <div class="flex flex-wrap items-start justify-between gap-2 px-4 pt-3">
                <div>
                    <h2 class="font-semibold text-ink dark:text-gray-100">{{ $editId ? 'Ubah pinjaman' : 'Catat pinjaman baru' }}</h2>
                    <p class="text-xs text-muted">Angsuran / bulan dihitung dari ketentuan sumber (tab Ketentuan); % sewa modal boleh disesuaikan per pinjaman. Simpan → masuk Buku Pinjaman.</p>
                </div>
                <x-badge class="shrink-0" :variant="$editId ? 'warning' : 'success'">{{ $editId ? 'Mode: Ubah #' . $editId : 'Mode: Baru' }}</x-badge>
            </div>
            @if ($terkunci)
                <p class="px-4 pt-2 text-xs font-semibold text-warning-deep dark:text-warning" role="status">Pinjaman ini sudah punya pembayaran tercatat — pokok, sumber, lama, bulan awal, dan % terkunci (data valid). Yang bisa diubah: keterangan dan bulan pelunasan.</p>
            @endif
            <div class="grid grid-cols-1 gap-4 px-4 py-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="pj-emp" value="Karyawan" />
                    <div class="mt-1">
                        <x-combobox wireModel="form.emp_nama" wireModelId="form.emp_id" :options="$this->karyawanPilihan" :disabled="(bool) $editId"
                            inputId="pj-emp" placeholder="Ketik nama / ID karyawan, lalu pilih dari daftar…" judulDaftar="daftar karyawan" maxlength="100"
                            enterAction="document.getElementById('pj-pokok')?.focus()" />
                    </div>
                    <p class="mt-1 text-xs {{ $form['emp_nama'] !== '' && $form['emp_id'] === '' ? 'text-warning-deep dark:text-warning' : 'text-muted' }}">
                        @if ($form['emp_nama'] !== '' && $form['emp_id'] === '')
                            Nama belum cocok dengan daftar — pilih dari daftar (panah bawah / klik) supaya karyawannya terkunci.
                        @elseif ($form['emp_id'] !== '')
                            Terpilih: <b class="font-mono">{{ $form['emp_id'] }}</b>
                        @else
                            Hanya karyawan aktif yang punya parameter gaji (supaya ada slip untuk dipotong). Bisa cari lewat nama, ID, atau unit.
                        @endif
                    </p>
                </div>
                <div>
                    <x-input-label for="pj-sumber-form" value="Sumber pinjaman" />
                    <x-select-input id="pj-sumber-form" wire:model.live="form.sumber_kode" :disabled="$terkunci" class="w-full mt-1">
                        @foreach ($sumberList->where('active_status', '1') as $kode => $sumber)
                            <option value="{{ $kode }}">{{ $sumber->nama }} ({{ $persen($sumber->persen_sewa) }}% / bln)</option>
                        @endforeach
                    </x-select-input>
                </div>
                <div>
                    <x-input-label for="pj-pokok" value="Pokok pinjaman (Rp)" />
                    <x-text-input-number id="pj-pokok" wire:model="form.pokok" :disabled="$terkunci" class="w-full mt-1" />
                </div>
                <div>
                    <x-input-label for="pj-persen" value="% Sewa modal / bulan" />
                    <x-text-input-number id="pj-persen" wire:model="form.persen_sewa" :decimals="3" :disabled="$terkunci" class="w-full mt-1" />
                    <p class="mt-1 text-xs text-muted">Terisi dari ketentuan sumber; ubah bila pinjaman ini beda (mis. 0 = tanpa sewa modal).</p>
                </div>
                <div>
                    <x-input-label for="pj-lama" value="Lama angsuran (bulan)" />
                    <x-text-input-number id="pj-lama" wire:model="form.lama_bulan" :disabled="$terkunci" class="w-full mt-1" />
                </div>
                <div>
                    <x-input-label for="pj-awal" value="Bulan kerja angsuran ke-1" />
                    <x-text-input id="pj-awal" wire:model.blur="form.bulan_awal" x-mask="99/9999" placeholder="mm/yyyy" :disabled="$terkunci" class="w-full mt-1 font-mono" />
                </div>
                <div>
                    <x-input-label for="pj-tetap" value="Angsuran tetap / bulan (opsional)" />
                    <x-text-input-number id="pj-tetap" wire:model="form.angsuran_tetap" :disabled="$terkunci" class="w-full mt-1" />
                    <p class="mt-1 text-xs text-muted">Isi bila pemberi pinjaman (bank) sudah menetapkan angsurannya — menimpa rumus. 0 = pakai rumus.</p>
                </div>
                @if ($editId)
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
                <div><span class="block text-xs text-muted">Angsuran / bln{{ $pratinjau['ditetapkan'] ? ' (ditetapkan)' : '' }}</span><span class="font-mono text-base font-bold text-brand-green dark:text-brand-lime">{{ $rp($pratinjau['angsuran']) }}</span></div>
                <div><span class="block text-xs text-muted">Total s/d {{ $pratinjau['akhir'] }}</span><span class="font-mono font-semibold">{{ $rp($pratinjau['total']) }}</span></div>
            </div>

            <div class="flex justify-end gap-2 px-4 py-4 mt-auto">
                <x-secondary-button type="button" wire:click="batal" wire:loading.attr="disabled" wire:target="batal">{{ $editId ? 'Batal ubah' : 'Kosongkan' }}</x-secondary-button>
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editId ? 'Simpan perubahan' : 'Simpan ke Buku' }}</span>
                    <span wire:loading wire:target="save">Menyimpan...</span>
                </x-primary-button>
            </div>
        </div>

    </div>
</div>
