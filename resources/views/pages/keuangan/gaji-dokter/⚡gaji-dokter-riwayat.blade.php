<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Support\GajiDokterSirus;

/**
 * Tab RIWAYAT AMBIL Gaji Dokter (anak pembungkus gaji-dokter): jejak tiap penarikan dari SIRUS —
 * siapa, kapan, bulan jasa apa, berapa slip baru / berubah (versi baru) / sama, berapa baris lampiran, dokter tanpa pemetaan.
 */
new class extends Component {
    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    #[Computed]
    public function rows()
    {
        return GajiDokterSirus::riwayatAmbil(60);
    }
};
?>

<div class="mt-4">
    <div class="flex flex-col bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
        <div class="px-4 pt-3">
            <h2 class="font-semibold text-ink dark:text-gray-100">Riwayat penarikan dari SIRUS</h2>
            <p class="text-xs text-muted"><b>Baru</b> = dokter yang belum punya snapshot bulan itu. <b>Berubah</b> = angka SIRUS berbeda dari snapshot terakhir → versi baru dibuat (versi lama tetap tersimpan). <b>Sama</b> = tidak ada perubahan, hanya tanggal ambil diperbarui.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Bulan jasa</th>
                        <th>Oleh</th>
                        <th class="text-right">Slip</th>
                        <th class="text-right">Baru</th>
                        <th class="text-right">Berubah</th>
                        <th class="text-right">Sama</th>
                        <th class="text-right">Baris lampiran</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        <tr wire:key="ambil-{{ $row->ambil_id }}">
                            <td class="font-mono text-xs whitespace-nowrap">{{ \Carbon\Carbon::parse($row->diambil_at)->format('d/m/Y H:i') }}</td>
                            <td class="font-mono">{{ \Carbon\Carbon::createFromFormat('!Y-m', $row->periode)->translatedFormat('M Y') }}</td>
                            <td>{{ $row->name ?? '–' }}</td>
                            <td class="font-mono text-right">{{ $row->jumlah_slip }}</td>
                            <td class="font-mono text-right">{{ $row->baru > 0 ? $row->baru : '–' }}</td>
                            <td class="font-mono text-right">@if ($row->berubah > 0)<x-badge variant="warning">{{ $row->berubah }}</x-badge>@else – @endif</td>
                            <td class="font-mono text-right">{{ $row->sama > 0 ? $row->sama : '–' }}</td>
                            <td class="font-mono text-right">{{ $row->lampiran_baris > 0 ? number_format($row->lampiran_baris, 0, ',', '.') : '–' }}</td>
                            <td class="text-xs text-muted">{{ $row->keterangan ?? '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-8 text-center text-muted">Belum pernah menarik data dari SIRUS. Buka tab Buku Gaji Dokter → <b>Ambil dari SIRUS</b>.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
