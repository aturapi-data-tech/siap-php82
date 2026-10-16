<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Computed;
use App\Support\GajiKaryawan;

/**
 * Modal rincian satu slip: tambah baris (jasa medik, lembur, bonus, kasbon, angsuran …), ubah nilai
 * (baris otomatis jadi "ditimpa"), hapus / kembalikan, susun ulang, finalkan.
 * Setiap perubahan langsung disimpan & total dihitung ulang (GajiKaryawan::hitungUlang).
 */
new class extends Component {
    #[Locked]
    public ?int $gajiId = null;

    public array $baru = ['jenis' => 'D', 'kode' => 'JASA_MEDIK', 'keterangan' => '', 'nilai' => 0];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    #[On('keuangan.gaji-karyawan.openRincian')]
    public function openRincian(int $gajiId): void
    {
        $this->gajiId = $gajiId;
        unset($this->slip);
        if (!$this->slip) {
            $this->dispatch('toast', type: 'error', message: 'Slip tidak ditemukan.');
            return;
        }
        $this->resetBaru();
        $this->dispatch('open-modal', name: 'gaji-karyawan-rincian');
    }

    #[Computed]
    public function slip(): ?array
    {
        return $this->gajiId ? GajiKaryawan::slip($this->gajiId) : null;
    }

    private function bisaDiubah(): bool
    {
        return $this->gajiId !== null && GajiKaryawan::bisaDiubah($this->gajiId);
    }

    public function updatedBaruJenis(): void
    {
        $this->baru['kode'] = $this->baru['jenis'] === 'P' ? 'KASBON' : 'JASA_MEDIK';
    }

    public function tambahBaris(): void
    {
        $kodeBoleh = array_keys($this->baru['jenis'] === 'P' ? GajiKaryawan::POTONGAN : GajiKaryawan::PENDAPATAN);
        $this->validate([
            'baru.jenis'      => 'required|in:D,P',
            'baru.kode'       => ['required', 'in:' . implode(',', array_diff($kodeBoleh, ['PPH21']))],
            'baru.keterangan' => 'nullable|string|max:100',
            'baru.nilai'      => 'required|numeric|min:-999999999|max:999999999|not_in:0',
        ], [
            'baru.nilai.not_in' => 'Nilai tidak boleh 0.',
            'baru.kode.in'      => 'Komponen tidak valid.',
        ], ['baru.nilai' => 'Nilai', 'baru.kode' => 'Komponen']);

        if (!$this->bisaDiubah()) {
            $this->dispatch('toast', type: 'error', message: 'Slip sudah Final — buka kembali ke Draft untuk mengubah.');
            return;
        }

        GajiKaryawan::simpanBaris($this->gajiId, [
            'jenis' => $this->baru['jenis'], 'kode' => $this->baru['kode'],
            'keterangan' => $this->baru['keterangan'], 'nilai' => (float) $this->baru['nilai'],
        ]);
        GajiKaryawan::hitungUlang($this->gajiId);
        $this->resetBaru();
        $this->segarkan('Baris ditambahkan.');
    }

    public function ubahNilai(int $dtlId, $nilai): void
    {
        if (!is_numeric($nilai) || abs((float) $nilai) > 999999999 || !$this->milikSlip($dtlId)) {
            return;
        }
        if (!GajiKaryawan::ubahNilai($dtlId, (float) $nilai)) {
            $this->dispatch('toast', type: 'error', message: 'Slip sudah Final — buka kembali ke Draft untuk mengubah.');
            return;
        }
        $this->segarkan();
    }

    public function hapusBaris(int $dtlId): void
    {
        if (!$this->milikSlip($dtlId) || !GajiKaryawan::hapusBaris($dtlId, auth()->id())) {
            $this->dispatch('toast', type: 'error', message: 'Baris tidak bisa dihapus.');
            return;
        }
        $this->segarkan('Baris dihapus / dikembalikan ke parameter.');
    }

    /** Susun ulang baris otomatis slip ini (hari hadir terbaru, parameter terbaru). */
    public function susunUlang(): void
    {
        if (!$this->bisaDiubah()) {
            return;
        }
        GajiKaryawan::generate($this->slip['header']->periode, null, auth()->id(), $this->slip['header']->emp_id);
        $this->segarkan('Slip disusun ulang dari parameter & absensi terbaru.');
    }

    public function ubahStatus(string $status): void
    {
        if ($this->gajiId && GajiKaryawan::ubahStatus($this->gajiId, $status, auth()->id())) {
            $this->segarkan($status === GajiKaryawan::STATUS_FINAL ? 'Slip difinalkan (terkunci).' : 'Slip dibuka kembali ke Draft.');
        }
    }

    public function closeModal(): void
    {
        $this->gajiId = null;
        unset($this->slip);
        $this->dispatch('close-modal', name: 'gaji-karyawan-rincian');
    }

    /** Baris detail memang milik slip yang sedang dibuka (dtlId dari browser tidak dipercaya). */
    private function milikSlip(int $dtlId): bool
    {
        return $this->slip !== null && $this->slip['detail']->contains('dtl_id', $dtlId);
    }

    private function segarkan(?string $pesan = null): void
    {
        unset($this->slip);
        if ($pesan) {
            $this->dispatch('toast', type: 'success', message: $pesan);
        }
        $this->dispatch('keuangan.gaji-karyawan.saved');
    }

    private function resetBaru(): void
    {
        $this->baru = ['jenis' => 'D', 'kode' => 'JASA_MEDIK', 'keterangan' => '', 'nilai' => 0];
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="gaji-karyawan-rincian" size="4xl" focusable>
        @php $slip = $this->slip; @endphp
        @if ($slip)
            @php
                $header = $slip['header'];
                $draft = $header->gaji_status === \App\Support\GajiKaryawan::STATUS_DRAFT;
                $kelompok = ['D' => 'Pendapatan', 'P' => 'Potongan'];
                $bulanSlip = \Carbon\Carbon::createFromFormat('!Y-m', $header->periode);
            @endphp
            <div wire:key="rincian-{{ $header->gaji_id }}-{{ $header->gaji_status }}">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="ds-display-sm">{{ $header->emp_name }}</h2>
                        <p class="text-sm text-muted">
                            {{ $header->emp_id }}{{ $header->unit_name ? ' · ' . $header->unit_name : '' }} · Bulan kerja {{ \App\Support\Format::bulan($bulanSlip) }}
                            · <b>{{ (int) $header->hari_hadir }}</b> hari hadir
                        </p>
                    </div>
                    <x-badge :variant="$draft ? 'warning' : 'success'">{{ \App\Support\GajiKaryawan::STATUS[$header->gaji_status] }}</x-badge>
                </div>

                @unless ($draft)
                    <p class="p-3 mt-3 text-sm border rounded-xl border-emerald-400 bg-emerald-50 text-emerald-800">
                        Slip Final — terkunci{{ $header->difinalkan_at ? ' sejak ' . \App\Support\Format::waktu($header->difinalkan_at) : '' }}. Buka kembali ke Draft untuk mengubah.
                    </p>
                @endunless

                <table class="w-full mt-4 text-sm ds-table">
                    <thead>
                        <tr>
                            <th>Komponen</th>
                            <th>Keterangan</th>
                            <th class="text-right">Nilai (Rp)</th>
                            @if ($draft)<th class="ds-c">Aksi</th>@endif
                        </tr>
                    </thead>
                    @foreach ($kelompok as $jenis => $namaKelompok)
                        <tbody wire:key="kelompok-{{ $jenis }}">
                            <tr><td colspan="4" class="font-bold bg-surface-soft">{{ $namaKelompok }}</td></tr>
                            @forelse ($slip['detail']->where('jenis', $jenis) as $row)
                                <tr wire:key="dtl-{{ $row->dtl_id }}-{{ $row->nilai }}">
                                    <td>
                                        {{ \App\Support\GajiKaryawan::label($row->jenis, $row->kode) }}
                                        @if ($row->otomatis === '1')
                                            <span class="text-[10px] text-muted-soft">{{ $row->nilai_manual === '1' ? 'ditimpa' : 'otomatis' }}</span>
                                        @endif
                                    </td>
                                    <td class="text-xs text-muted">{{ $row->keterangan }}{{ (float) $row->tarif > 0 ? ' × Rp ' . \App\Support\GajiKaryawan::rupiah($row->tarif) : '' }}</td>
                                    <td class="text-right">
                                        @if ($draft)
                                            <input type="number" step="1" value="{{ (int) round($row->kode === 'KOREKSI' ? (float) $row->nilai : abs((float) $row->nilai)) }}"
                                                x-on:change="$wire.ubahNilai({{ $row->dtl_id }}, $event.target.value)"
                                                class="w-36 px-2 py-1 font-mono text-right border rounded-lg border-hairline {{ $jenis === 'P' ? 'text-red-700' : '' }}"
                                                title="{{ $jenis === 'P' ? 'Potongan — isi angka positif, otomatis dikurangkan' : 'Ubah nilai lalu klik di luar kotak' }}" />
                                        @else
                                            <span class="font-mono {{ $jenis === 'P' ? 'text-red-700' : '' }}">{{ \App\Support\GajiKaryawan::rupiah($row->nilai) }}</span>
                                        @endif
                                    </td>
                                    @if ($draft)
                                        <td class="ds-c">
                                            @if ($row->otomatis !== '1')
                                                <button type="button" wire:click="hapusBaris({{ $row->dtl_id }})" class="text-xs font-semibold text-red-700 hover:underline">Hapus</button>
                                            @elseif ($row->nilai_manual === '1')
                                                <button type="button" wire:click="hapusBaris({{ $row->dtl_id }})" class="text-xs font-semibold text-muted hover:underline" title="Kembalikan ke nilai dari parameter gaji">Kembalikan</button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-xs text-muted-soft">— tidak ada —</td></tr>
                            @endforelse
                        </tbody>
                    @endforeach
                    <tbody>
                        <tr><td colspan="2" class="font-semibold">Total pendapatan</td><td class="font-mono text-right">{{ \App\Support\GajiKaryawan::rupiah($header->total_pendapatan) }}</td>@if ($draft)<td></td>@endif</tr>
                        <tr><td colspan="2" class="font-semibold">Total potongan</td><td class="font-mono text-right text-red-700">{{ \App\Support\GajiKaryawan::rupiah($header->total_potongan) }}</td>@if ($draft)<td></td>@endif</tr>
                        <tr><td colspan="2" class="text-base font-bold">GAJI DITERIMA</td><td class="font-mono text-base font-bold text-right">{{ \App\Support\GajiKaryawan::rupiah($header->gaji_diterima) }}</td>@if ($draft)<td></td>@endif</tr>
                    </tbody>
                </table>

                @if ($draft)
                    <div class="p-3 mt-4 border rounded-xl border-hairline bg-surface-soft">
                        <p class="mb-2 text-sm font-semibold">Tambah baris</p>
                        <div class="grid grid-cols-1 gap-2 lg:grid-cols-4">
                            <x-select-input wire:model.live="baru.jenis">
                                <option value="D">Pendapatan</option>
                                <option value="P">Potongan</option>
                            </x-select-input>
                            <x-select-input wire:model="baru.kode">
                                @foreach ($baru['jenis'] === 'P' ? \App\Support\GajiKaryawan::POTONGAN : \App\Support\GajiKaryawan::PENDAPATAN as $kode => $namaKomponen)
                                    @continue($kode === 'PPH21')
                                    <option value="{{ $kode }}">{{ $namaKomponen }}</option>
                                @endforeach
                            </x-select-input>
                            <x-text-input wire:model="baru.keterangan" maxlength="100" placeholder="Keterangan (mis. 6 jam lembur)" />
                            <x-text-input-number wire:model="baru.nilai" :error="$errors->has('baru.nilai')" />
                        </div>
                        <x-input-error :messages="array_merge($errors->get('baru.nilai'), $errors->get('baru.kode'))" class="mt-1" />
                        <div class="flex items-center justify-between gap-2 mt-2">
                            <p class="text-xs text-muted">Potongan cukup diisi angka positif. PPh 21 dihitung otomatis — ubah nilainya di tabel untuk menimpa.</p>
                            <x-primary-button type="button" wire:click="tambahBaris" wire:loading.attr="disabled">Tambah</x-primary-button>
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-2 mt-4">
                    <div class="flex gap-2">
                        @if ($draft)
                            <x-secondary-button type="button" wire:click="susunUlang" title="Hitung ulang baris otomatis dari parameter gaji & hari hadir terbaru">Susun ulang</x-secondary-button>
                            <x-primary-button type="button" wire:click="ubahStatus('F')">Finalkan</x-primary-button>
                        @else
                            <x-secondary-button type="button" wire:click="ubahStatus('D')">Buka kembali ke Draft</x-secondary-button>
                        @endif
                    </div>
                    <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
