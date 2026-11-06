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
 *   - form catat pinjaman baru / ubah pinjaman ($editId dari pembungkus) → angsuran / bln terhitung otomatis (pratinjau),
 *     pinjaman baru boleh menutup pinjaman lama yang masih berjalan (praktik koperasi);
 *   - tabel KETENTUAN per sumber (% sewa modal / bln, pembulatan, baris slip, aktif) + tambah sumber baru.
 * Simpan → event 'keuangan.pinjaman.saved' (pembungkus pindah ke tab Buku). Batal → 'keuangan.pinjaman.batal'.
 * Bulan kerja terakhir di tab Buku (Session) dipakai sebagai bawaan bulan awal angsuran.
 */
new class extends Component {
    public ?int $editId = null;

    /** Bulan kerja terakhir dipilih di tab Buku — kunci Session sama dengan pinjaman-buku. */
    #[Session(key: 'pinjaman-karyawan-bulan')]
    public string $bulan = '';

    public array $form = [];
    public int   $formVersi = 0;

    /** [sumber_kode => [persen_sewa, pembulatan]] — ketentuan, diikat ke input tabel. */
    public array $sumberInput = [];

    public array $sumberBaru = ['sumber_kode' => '', 'nama' => '', 'persen_sewa' => 0, 'pembulatan' => 1000, 'kode_potongan' => 'ANGS_KOP'];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(?int $editId = null): void
    {
        $this->editId = $editId;
        $this->muatSumber();
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

    private function muatSumber(): void
    {
        $this->sumberInput = PinjamanKaryawan::sumber()->map(fn($row) => ['persen_sewa' => (float) $row->persen_sewa, 'pembulatan' => (int) $row->pembulatan])->all();
        unset($this->sumber, $this->pratinjau);
    }

    // ─── Form pinjaman ────────────────────────────────────────────────────────
    private function resetForm(): void
    {
        $sumberPertama = PinjamanKaryawan::sumber(true)->first();
        $this->form = ['emp_id' => '', 'sumber_kode' => $sumberPertama?->sumber_kode ?? '', 'pokok' => 0,
            'persen_sewa' => (float) ($sumberPertama?->persen_sewa ?? 0), 'lama_bulan' => 12,
            'bulan_awal' => $this->bulanValid() ? $this->bulan : now()->format(Format::BULAN), 'lunas_periode' => '', 'keterangan' => '', 'tutup_lama' => true];
        $this->formVersi++;
        unset($this->pratinjau);
    }

    private function isiFormDari(int $id): bool
    {
        $row = PinjamanKaryawan::ambil($id);
        if (!$row) {
            return false;
        }
        $this->form = ['emp_id' => $row->emp_id, 'sumber_kode' => $row->sumber_kode, 'pokok' => (float) $row->pokok,
            'persen_sewa' => (float) $row->persen_sewa, 'lama_bulan' => (int) $row->lama_bulan,
            'bulan_awal' => $this->keTampilPeriode($row->bulan_awal), 'lunas_periode' => $this->keTampilPeriode($row->lunas_periode),
            'keterangan' => (string) ($row->keterangan ?? ''), 'tutup_lama' => false];
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
        $awal = $this->keDbPeriode($this->form['bulan_awal'] ?? null);
        $hitung['akhir'] = $awal ? $this->keTampilPeriode(Carbon::createFromFormat('!Y-m', $awal)->addMonthsNoOverflow($lama - 1)->format('Y-m')) : '–';

        return $hitung;
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

    #[Computed]
    public function sumber()
    {
        return PinjamanKaryawan::sumber();
    }

    #[Computed]
    public function karyawanPilihan()
    {
        return PinjamanKaryawan::karyawanPilihan();
    }

    #[Computed]
    public function rekap(): array
    {
        return PinjamanKaryawan::rekap($this->bulanValid() ? Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->format('Y-m') : now()->format('Y-m'));
    }
};
?>

<div>
    @php
        $sumberList = $this->sumber;
        $rekap = $this->rekap;
        $pratinjau = $this->pratinjau;
        $rp = fn($n) => GajiKaryawan::rupiah($n);
        $persen = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
    @endphp
    <div class="grid grid-cols-1 gap-4 mt-4 xl:grid-cols-5">
        {{-- FORM PINJAMAN --}}
        <div class="flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl xl:col-span-3 dark:border-gray-700 dark:bg-gray-900" wire:key="form-pinjaman-{{ $formVersi }}">
            <div class="flex flex-wrap items-start justify-between gap-2 px-4 pt-3">
                <div>
                    <h2 class="font-semibold text-ink dark:text-gray-100">{{ $editId ? 'Ubah pinjaman' : 'Catat pinjaman baru' }}</h2>
                    <p class="text-xs text-muted">Angsuran / bulan dihitung dari ketentuan sumber; % sewa modal boleh disesuaikan per pinjaman. Simpan → masuk Buku Pinjaman.</p>
                </div>
                <x-badge class="shrink-0" :variant="$editId ? 'warning' : 'success'">{{ $editId ? 'Mode: Ubah #' . $editId : 'Mode: Baru' }}</x-badge>
            </div>
            <div class="grid grid-cols-1 gap-4 px-4 py-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="pj-emp" value="Karyawan" />
                    <x-select-input id="pj-emp" wire:model="form.emp_id" :disabled="(bool) $editId" class="w-full mt-1">
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
                <div><span class="block text-xs text-muted">Angsuran / bln</span><span class="font-mono text-base font-bold text-brand-green dark:text-brand-lime">{{ $rp($pratinjau['angsuran']) }}</span></div>
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
</div>
