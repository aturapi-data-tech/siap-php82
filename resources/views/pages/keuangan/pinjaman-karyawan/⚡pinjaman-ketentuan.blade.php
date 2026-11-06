<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\GajiKaryawan;
use App\Support\PinjamanKaryawan;

/**
 * Tab KETENTUAN Pinjaman Karyawan (anak pembungkus pinjaman-karyawan): master ketentuan per sumber pinjaman —
 * % sewa modal / bln, pembulatan angsuran, baris slip tujuan, aktif / nonaktif — plus tambah sumber baru.
 * Perubahan berlaku untuk pinjaman BARU; pinjaman lama tetap pada % saat pinjam (snapshot). Disimpan saat kotak ditinggalkan.
 * Bank yang belum dipakai dibiarkan nonaktif (tidak muncul di form Entry).
 */
new class extends Component {
    /** Bulan kerja terakhir di tab Buku — untuk ringkasan "N berjalan" per sumber. */
    #[Session(key: 'pinjaman-karyawan-bulan')]
    public string $bulan = '';

    /** [sumber_kode => [persen_sewa, pembulatan]] — diikat ke input tabel. */
    public array $sumberInput = [];

    public array $sumberBaru = ['sumber_kode' => '', 'nama' => '', 'persen_sewa' => 0, 'pembulatan' => 1000, 'kode_potongan' => 'ANGS_BANK'];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        $this->muatSumber();
    }

    private function periode(): string
    {
        return preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $this->bulan) ? Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->format('Y-m') : now()->subMonthNoOverflow()->format('Y-m');
    }

    private function muatSumber(): void
    {
        $this->sumberInput = PinjamanKaryawan::sumber()->map(fn($row) => ['persen_sewa' => (float) $row->persen_sewa, 'pembulatan' => (int) $row->pembulatan])->all();
        unset($this->sumber, $this->rekap);
    }

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
        $this->dispatch('toast', type: 'success', message: "Sumber pinjaman {$this->sumberBaru['nama']} ditambahkan (aktif).");
        $this->sumberBaru = ['sumber_kode' => '', 'nama' => '', 'persen_sewa' => 0, 'pembulatan' => 1000, 'kode_potongan' => 'ANGS_BANK'];
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
    public function rekap(): array
    {
        return PinjamanKaryawan::rekap($this->periode());
    }
};
?>

<div>
    @php
        $sumberList = $this->sumber;
        $rekap = $this->rekap;
        $rp = fn($n) => GajiKaryawan::rupiah($n);
    @endphp
    <div class="mt-4 max-w-5xl">
        {{-- KETENTUAN --}}
        <div class="flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
            <div class="px-4 pt-3">
                <h2 class="font-semibold text-ink dark:text-gray-100">Ketentuan sumber pinjaman</h2>
                <p class="text-xs text-muted">Angsuran / bulan = (pokok ÷ lama + pokok × % sewa modal) dibulatkan ke atas, kecuali pinjaman diberi <b>angsuran tetap</b> (jadwal bank). Berlaku untuk pinjaman <b>baru</b>; pinjaman lama tetap pada % saat pinjam. Bank yang belum dipakai dibiarkan nonaktif (tidak muncul di form). Disimpan saat kotak ditinggalkan.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="ds-table">
                    <thead>
                        <tr>
                            <th>Sumber</th>
                            <th class="text-right">% Sewa / bln</th>
                            <th class="ds-c">Pembulatan</th>
                            <th>Baris slip</th>
                            <th class="text-right">Angsuran bulan ini</th>
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
                                <td class="font-mono text-right">{{ ($rekap[$kode]['jumlah'] ?? 0) > 0 ? $rp($rekap[$kode]['angsuran']) : '–' }}</td>
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
