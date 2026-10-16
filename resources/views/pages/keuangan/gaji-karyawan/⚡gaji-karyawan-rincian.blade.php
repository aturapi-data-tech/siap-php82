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

    /** Nilai tampil per baris (dtl_id => rupiah positif; koreksi boleh minus) — diikat ke x-text-input-number. */
    public array $nilaiBaris = [];

    /** Hari kerja slip (pengali tarif makan & transport) — diikat ke x-text-input-number. */
    public int $hariKerja = 0;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    #[On('keuangan.gaji-karyawan.openRincian')]
    public function openRincian(int $gajiId): void
    {
        $this->gajiId = $gajiId;
        unset($this->slip, $this->hadirAbsensi);
        if (!$this->slip) {
            $this->dispatch('toast', type: 'error', message: 'Slip tidak ditemukan.');
            return;
        }
        $this->resetBaru();
        $this->isiNilaiBaris();
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

    /**
     * x-text-input-number mengirim nilai setiap blur — disimpan HANYA bila berbeda dari yang tersimpan,
     * supaya sekadar mengklik kotak tidak menandai baris otomatis sebagai "ditimpa".
     */
    public function updatedNilaiBaris($nilai, $kunci): void
    {
        $dtlId = (int) $kunci;
        $row = $this->slip ? $this->slip['detail']->firstWhere('dtl_id', $dtlId) : null;
        if (!$row || !is_numeric($nilai) || abs((float) $nilai) > 999999999) {
            $this->isiNilaiBaris();
            return;
        }
        if ((int) round((float) $nilai) === $this->nilaiTampil($row)) {
            return;
        }
        if (!GajiKaryawan::ubahNilai($dtlId, (float) $nilai)) {
            $this->dispatch('toast', type: 'error', message: 'Slip sudah Final — buka kembali ke Draft untuk mengubah.');
        }
        $this->segarkan();
    }

    private function nilaiTampil(object $row): int
    {
        return (int) round($row->kode === 'KOREKSI' ? (float) $row->nilai : abs((float) $row->nilai));
    }

    /** Hari kerja diedit (cuti / izin / tambahan) → makan & transport dihitung ulang. Disimpan hanya bila berubah. */
    public function updatedHariKerja($nilai): void
    {
        $hari = (int) $nilai;
        if (!$this->slip || $hari === (int) $this->slip['header']->hari_hadir) {
            return;
        }
        if (!GajiKaryawan::ubahHariKerja($this->gajiId, $hari)) {
            $this->dispatch('toast', type: 'error', message: 'Hari kerja harus 0–31 dan slip masih Draft.');
        }
        $this->segarkan();
    }

    /** Pakai jumlah hadir (H/T) menurut absensi bulan itu sebagai hari kerja. */
    public function pakaiAbsensi(): void
    {
        $this->updatedHariKerja($this->hadirAbsensi);
    }

    #[Computed]
    public function hadirAbsensi(): int
    {
        if (!$this->slip) {
            return 0;
        }
        $header = $this->slip['header'];

        return GajiKaryawan::hariHadir([$header->emp_id], $header->periode)[$header->emp_id] ?? 0;
    }

    private function isiNilaiBaris(): void
    {
        $this->hariKerja = $this->slip ? (int) $this->slip['header']->hari_hadir : 0;
        $this->nilaiBaris = $this->slip
            ? $this->slip['detail']->mapWithKeys(fn($row) => [(string) $row->dtl_id => $this->nilaiTampil($row)])->all()
            : [];
    }

    public function hapusBaris(int $dtlId): void
    {
        if (!$this->milikSlip($dtlId) || !GajiKaryawan::hapusBaris($dtlId, auth()->id())) {
            $this->dispatch('toast', type: 'error', message: 'Baris tidak bisa dihapus.');
            return;
        }
        $this->segarkan('Baris dihapus / dikembalikan ke parameter.');
    }

    /** Susun ulang baris otomatis slip ini dari parameter terbaru (hari kerja slip tetap). */
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

    /** Tombol Kirim WA diklik (wa.me dibuka di browser) — catat waktu kirim. */
    public function tandaiWa(int $gajiId): void
    {
        if ($gajiId === $this->gajiId) {
            GajiKaryawan::tandaiWa($gajiId);
            $this->segarkan();
        }
    }

    public function closeModal(): void
    {
        $this->gajiId = null;
        unset($this->slip, $this->hadirAbsensi);
        $this->dispatch('close-modal', name: 'gaji-karyawan-rincian');
    }

    /** Baris detail memang milik slip yang sedang dibuka (dtlId dari browser tidak dipercaya). */
    private function milikSlip(int $dtlId): bool
    {
        return $this->slip !== null && $this->slip['detail']->contains('dtl_id', $dtlId);
    }

    private function segarkan(?string $pesan = null): void
    {
        unset($this->slip, $this->hadirAbsensi);
        $this->isiNilaiBaris();
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
    <x-modal name="gaji-karyawan-rincian" size="full" height="full" focusable>
        @php $slip = $this->slip; @endphp
        @if ($slip)
            @php
                $header = $slip['header'];
                $draft = $header->gaji_status === \App\Support\GajiKaryawan::STATUS_DRAFT;
                $kelompok = ['D' => 'Pendapatan', 'P' => 'Potongan'];
                $bulanSlip = \Carbon\Carbon::createFromFormat('!Y-m', $header->periode);
            @endphp
            <div class="flex flex-col min-h-full" wire:key="rincian-{{ $header->gaji_id }}-{{ $header->gaji_status }}">

                {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
                <x-modul-dokumen.header :judul="'Rincian Slip — ' . ($header->emp_name)"
                    ikon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                    {{ $header->emp_id }}{{ $header->unit_name ? ' · ' . $header->unit_name : '' }} · Bulan kerja {{ \App\Support\Format::bulan($bulanSlip) }} · <b>{{ (int) $header->hari_hadir }}</b> hari kerja
                    <x-slot:badge><x-badge class="shrink-0 whitespace-nowrap" :variant="$draft ? 'warning' : 'success'">{{ \App\Support\GajiKaryawan::STATUS[$header->gaji_status] }}</x-badge></x-slot:badge>
                </x-modul-dokumen.header>

                {{-- BODY: rincian (kiri) · tambah baris + ringkasan (kanan) --}}
                <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
                    @unless ($draft)
                        <p class="p-3 mb-4 text-sm border rounded-xl border-emerald-400 bg-emerald-50 text-emerald-800">
                            Slip Final — terkunci{{ $header->difinalkan_at ? ' sejak ' . \App\Support\Format::waktu($header->difinalkan_at) : '' }}. Buka kembali ke Draft untuk mengubah.
                        </p>
                    @endunless

                    <div class="grid items-start grid-cols-1 gap-4 lg:grid-cols-3">
                        <div class="lg:col-span-2">
                            <x-border-form title="Rincian">
                                <table class="w-full text-sm ds-table">
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
                                                        @if (in_array($row->kode, \App\Support\GajiKaryawan::PENDAPATAN_HARIAN, true))
                                                            <span class="text-[10px] text-muted-soft">× hari kerja</span>
                                                        @elseif ($row->otomatis === '1')
                                                            <span class="text-[10px] text-muted-soft">{{ $row->nilai_manual === '1' ? 'ditimpa' : 'otomatis' }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="text-xs text-muted">{{ $row->keterangan }}{{ (float) $row->tarif > 0 ? ' × Rp ' . \App\Support\GajiKaryawan::rupiah($row->tarif) : '' }}</td>
                                                    @php $harian = in_array($row->kode, \App\Support\GajiKaryawan::PENDAPATAN_HARIAN, true); @endphp
                                                    <td class="text-right">
                                                        @if ($harian)
                                                            {{-- Baris harian = hari kerja × tarif — diubah lewat kotak Hari Kerja, bukan diketik di sini --}}
                                                            <span class="font-mono" title="Ubah lewat kotak Hari Kerja">{{ \App\Support\GajiKaryawan::rupiah($row->nilai) }}</span>
                                                        @elseif ($draft)
                                                            <div class="w-40 ml-auto">
                                                                <x-text-input-number wire:model="nilaiBaris.{{ $row->dtl_id }}"
                                                                    class="{{ $jenis === 'P' ? 'text-red-700' : '' }}"
                                                                    title="{{ $jenis === 'P' ? 'Potongan — isi angka positif, otomatis dikurangkan' : 'Ubah nilai lalu klik di luar kotak' }}" />
                                                            </div>
                                                        @else
                                                            <span class="font-mono {{ $jenis === 'P' ? 'text-red-700' : '' }}">{{ \App\Support\GajiKaryawan::rupiah($row->nilai) }}</span>
                                                        @endif
                                                    </td>
                                                    @if ($draft)
                                                        <td class="ds-c">
                                                            @if ($row->otomatis !== '1')
                                                                <x-hapus-button action="hapusBaris({{ $row->dtl_id }})" title="Hapus Baris"
                                                                    message="Hapus baris {{ \App\Support\GajiKaryawan::label($row->jenis, $row->kode) }} dari slip?" />
                                                            @elseif ($row->nilai_manual === '1' && !in_array($row->kode, \App\Support\GajiKaryawan::PENDAPATAN_HARIAN, true))
                                                                <x-icon-button color="gray" type="button" class="!p-2.5 shrink-0" wire:click="hapusBaris({{ $row->dtl_id }})"
                                                                    title="Kembalikan ke nilai dari parameter gaji">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
                                                                </x-icon-button>
                                                            @endif
                                                        </td>
                                                    @endif
                                                </tr>
                                            @empty
                                                <tr><td colspan="4" class="text-xs text-muted-soft">— tidak ada —</td></tr>
                                            @endforelse
                                        </tbody>
                                    @endforeach
                                </table>
                            </x-border-form>
                        </div>

                        <div class="space-y-4">
                            <x-border-form title="Hari Kerja">
                                @if ($draft)
                                    <div class="flex items-end gap-2">
                                        <div class="w-28">
                                            <x-text-input-number wire:model="hariKerja" title="Hari kerja dibayar — pengali tarif makan & transport" />
                                        </div>
                                        <span class="pb-2 text-sm text-muted">hari</span>
                                    </div>
                                @else
                                    <p class="text-base font-bold">{{ (int) $header->hari_hadir }} hari</p>
                                @endif
                                <p class="mt-2 text-xs text-muted">
                                    Pengali tarif makan &amp; transport. Kurangi bila ada cuti / izin, tambah bila ada hari ekstra.
                                    Absensi bulan ini: <b>{{ $this->hadirAbsensi }}</b> hari hadir (Hadir/Terlambat).
                                </p>
                                @if ($draft && $this->hadirAbsensi > 0 && $this->hadirAbsensi !== (int) $header->hari_hadir)
                                    <div class="mt-2">
                                        <x-secondary-button type="button" wire:click="pakaiAbsensi">Pakai absensi ({{ $this->hadirAbsensi }} hari)</x-secondary-button>
                                    </div>
                                @endif
                            </x-border-form>
                            @if ($draft)
                                <x-border-form title="Tambah Baris">
                                <div class="grid grid-cols-1 gap-2">
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
                                <div class="flex justify-end mt-3">
                                    <x-primary-button type="button" wire:click="tambahBaris" wire:loading.attr="disabled">Tambah</x-primary-button>
                                </div>
                                <p class="mt-3 text-xs text-muted">Potongan cukup diisi angka positif. PPh 21 dihitung otomatis — ubah nilainya di tabel untuk menimpa.
                                    Baris <i>ditimpa</i> dipertahankan saat generate ulang; panah balik mengembalikannya ke nilai parameter.</p>
                                </x-border-form>
                            @endif
                            <x-border-form title="Ringkasan">
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between"><span>Total pendapatan</span><span class="font-mono">{{ \App\Support\GajiKaryawan::rupiah($header->total_pendapatan) }}</span></div>
                                    <div class="flex justify-between"><span>Total potongan</span><span class="font-mono text-red-700">{{ \App\Support\GajiKaryawan::rupiah($header->total_potongan) }}</span></div>
                                    <div class="flex justify-between pt-2 text-base font-bold border-t border-hairline"><span>Gaji diterima</span><span class="font-mono">Rp {{ \App\Support\GajiKaryawan::rupiah($header->gaji_diterima) }}</span></div>
                                </div>
                            </x-border-form>
                        </div>
                    </div>
                </div>

                {{-- FOOTER --}}
                <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex gap-2">
                            @if ($draft)
                                <x-secondary-button type="button" wire:click="susunUlang" title="Susun ulang baris otomatis dari parameter gaji terbaru (hari kerja slip tetap)">Susun ulang</x-secondary-button>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @include('pages.keuangan.gaji-karyawan.tombol-kirim-wa', ['header' => $header])
                            <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
                            @if ($draft)
                                <x-primary-button type="button" wire:click="ubahStatus('F')">Finalkan</x-primary-button>
                            @else
                                <x-warning-button type="button" wire:click="ubahStatus('D')">Buka kembali ke Draft</x-warning-button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-modal>
</div>
