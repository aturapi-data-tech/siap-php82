<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use App\Support\GajiDokterSirus;

/**
 * Tab PEMETAAN DOKTER Gaji Dokter (anak pembungkus gaji-dokter): dokter SIRUS (dr_id) ↔ karyawan SIAP (emp_id).
 * Pemetaan otomatis (OTO) terjadi saat Segarkan / Ambil bila nama cocok persis (huruf saja, tanpa gelar) dan tunggal;
 * sisanya dipilih di sini lewat LOV karyawan (MANUAL). Satu baris disunting pada satu waktu (tombol Petakan) supaya daftar
 * karyawan tidak dimuat 100× di halaman. Dokter tanpa pemetaan tetap ditarik — hanya ditandai di Buku.
 */
new class extends Component {
    #[Session(key: 'gaji-dokter-pemetaan-cari')]
    public string $cari = '';

    #[Session(key: 'gaji-dokter-pemetaan-saring')]
    public string $saring = 'aktif'; // aktif | belum | semua

    /** dr_id yang sedang disunting (LOV tampil hanya di baris ini). */
    public ?string $editDrId = null;

    public string $empNama = '';
    public string $empId = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(): void
    {
        if (!in_array($this->saring, ['aktif', 'belum', 'semua'], true)) {
            $this->saring = 'aktif';
        }
    }

    public function updatedCari(): void { unset($this->rows); }
    public function updatedSaring(): void { unset($this->rows); }

    public function resetFilters(): void
    {
        $this->reset(['cari']);
        $this->saring = 'aktif';
        unset($this->rows);
    }

    public function segarkan(): void
    {
        try {
            $hasil = GajiDokterSirus::segarkanDokter(auth()->id());
            $this->dispatch('toast', type: 'success', message: "Master dokter disegarkan: {$hasil['baru']} dokter baru, {$hasil['oto']} terpetakan otomatis.");
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
        unset($this->rows);
    }

    public function mulaiEdit(string $drId): void
    {
        $this->editDrId = $drId;
        $this->empNama = '';
        $this->empId = '';
    }

    public function batalEdit(): void
    {
        $this->editDrId = null;
        $this->empNama = '';
        $this->empId = '';
    }

    /** Dipanggil LOV saat teks cocok dengan daftar → langsung simpan. */
    public function updatedEmpId(): void
    {
        if ($this->editDrId === null || $this->empId === '') {
            return;
        }
        if (!GajiDokterSirus::petakan($this->editDrId, $this->empId, auth()->id())) {
            $this->dispatch('toast', type: 'error', message: 'Karyawan tidak ditemukan.');
            return;
        }
        $this->dispatch('toast', type: 'success', message: "Dokter dipetakan ke {$this->empNama}.");
        $this->batalEdit();
        unset($this->rows);
    }

    public function lepas(string $drId): void
    {
        GajiDokterSirus::petakan($drId, null, auth()->id());
        $this->dispatch('toast', type: 'success', message: 'Pemetaan dilepas.');
        unset($this->rows);
    }

    #[Computed]
    public function rows()
    {
        $rows = GajiDokterSirus::dokterList($this->cari);

        return match ($this->saring) {
            'aktif' => $rows->where('aktif', '1')->values(),
            'belum' => $rows->filter(fn($r) => $r->aktif === '1' && $r->emp_id === null)->values(),
            default => $rows,
        };
    }

    #[Computed]
    public function karyawanOpsi(): array
    {
        return GajiDokterSirus::karyawanOpsi();
    }

    #[Computed]
    public function ringkas(): array
    {
        $semua = GajiDokterSirus::dokterList();
        $aktif = $semua->where('aktif', '1');

        return ['aktif' => $aktif->count(), 'terpetakan' => $aktif->whereNotNull('emp_id')->count(), 'semua' => $semua->count()];
    }
};
?>

<div class="mt-4">
    @php $ringkas = $this->ringkas; @endphp
    <div class="flex flex-wrap items-end gap-3 px-4 py-3 mb-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
        <div class="w-56">
            <x-input-label for="gdp-cari" value="Cari dokter" />
            <x-text-input id="gdp-cari" type="text" wire:model.live.debounce.300ms="cari" placeholder="nama dokter" class="block w-full mt-1" />
        </div>
        <div class="w-48">
            <x-input-label for="gdp-saring" value="Tampilkan" />
            <div class="mt-1">
                <x-select-input id="gdp-saring" wire:model.live="saring">
                    <option value="aktif">Dokter aktif</option>
                    <option value="belum">Aktif, belum dipetakan</option>
                    <option value="semua">Semua (termasuk nonaktif)</option>
                </x-select-input>
            </div>
        </div>
        <div class="flex items-center gap-2 ml-auto">
            <x-badge :variant="$ringkas['terpetakan'] === $ringkas['aktif'] ? 'success' : 'warning'">{{ $ringkas['terpetakan'] }} / {{ $ringkas['aktif'] }} dokter aktif terpetakan</x-badge>
            <x-secondary-button type="button" wire:click="segarkan" wire:loading.attr="disabled" wire:target="segarkan">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                <span wire:loading.remove wire:target="segarkan">Segarkan dari SIRUS</span>
                <span wire:loading wire:target="segarkan">Mengambil…</span>
            </x-secondary-button>
            <x-toolbar-refresh-reset :label="null" />
        </div>
    </div>

    <div class="flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="px-4 pt-3">
            <h2 class="font-semibold text-ink dark:text-gray-100">Dokter SIRUS → karyawan SIAP</h2>
            <p class="text-xs text-muted"><b>Otomatis</b> bila nama cocok persis (gelar diabaikan) dan hanya satu karyawan yang cocok. Nama yang berbeda ejaan (mis. salah ketik di master karyawan) dipetakan manual: klik <b>Petakan</b>, ketik nama / ID karyawan, pilih dari daftar. Dokter yang tidak digaji lewat SIAP (dokter jaga, tamu, "TIDAK ADA") biarkan kosong.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th>Dokter SIRUS</th>
                        <th>NIK / kode BPJS</th>
                        <th class="ds-c">Aktif</th>
                        <th class="min-w-[22rem]">Karyawan SIAP</th>
                        <th>Cara</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        <tr wire:key="dok-{{ $row->dr_id }}" class="{{ $row->aktif === '1' ? '' : 'opacity-60' }}">
                            <td><span class="font-semibold">{{ $row->dr_name }}</span><span class="block font-mono text-xs text-muted">dr_id {{ $row->dr_id }}</span></td>
                            <td class="font-mono text-xs">{{ $row->dr_nik ?: '–' }}<span class="block text-muted">{{ $row->kd_dr_bpjs ?: '' }}</span></td>
                            <td class="ds-c">@if ($row->aktif === '1')<x-badge variant="success">Aktif</x-badge>@else<x-badge variant="gray">Nonaktif</x-badge>@endif</td>
                            <td>
                                @if ($editDrId === $row->dr_id)
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1">
                                            <x-combobox wireModel="empNama" wireModelId="empId" :options="$this->karyawanOpsi" inputId="gdp-emp" placeholder="Ketik nama / ID karyawan, lalu pilih…" judulDaftar="daftar karyawan" maxlength="100" />
                                        </div>
                                        <x-secondary-button type="button" wire:click="batalEdit" class="!px-3">Batal</x-secondary-button>
                                    </div>
                                @elseif ($row->emp_id)
                                    <span class="font-semibold">{{ $row->emp_name }}</span><span class="block font-mono text-xs text-muted">{{ $row->emp_id }}{{ $row->emp_aktif === '1' ? '' : ' · karyawan nonaktif' }}</span>
                                @else
                                    <x-badge variant="warning">Belum dipetakan</x-badge>
                                @endif
                            </td>
                            <td class="text-xs">{{ ['OTO' => 'Otomatis (nama cocok)', 'MANUAL' => 'Manual'][$row->cara_map] ?? '–' }}</td>
                            <td class="text-right">
                                @if ($editDrId !== $row->dr_id)
                                    <div class="inline-flex items-center gap-1">
                                        <x-secondary-button type="button" wire:click="mulaiEdit('{{ $row->dr_id }}')" class="!px-3 !py-1.5 text-xs">{{ $row->emp_id ? 'Ganti' : 'Petakan' }}</x-secondary-button>
                                        @if ($row->emp_id)
                                            <x-confirm-button variant="danger-soft" action="lepas('{{ $row->dr_id }}')" title="Lepas pemetaan" message="Lepas pemetaan {{ $row->dr_name }} dari {{ $row->emp_name }}? Snapshot yang sudah tersimpan tidak berubah." confirmText="Ya, lepas" cancelText="Batal" class="!px-3 !py-1.5 text-xs">Lepas</x-confirm-button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-muted">{{ $ringkas['semua'] === 0 ? 'Master dokter belum ditarik — klik Segarkan dari SIRUS.' : 'Tidak ada dokter yang cocok dengan saringan.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
