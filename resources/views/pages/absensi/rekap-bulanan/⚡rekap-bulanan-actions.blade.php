<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Support\Format;
use App\Support\Jadwal;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Rincian kehadiran SATU karyawan selama satu bulan + PENILAIAN LEMBUR.
 *
 * Semua tanggal dalam bulan ditampilkan (hari tanpa baris = "tanpa catatan").
 * Lembur di sini dinilai Hitung (Y) / Tidak (N); hanya Y yang masuk total Rekap Bulanan.
 * Rekap Harian hanya menampilkan lembur — keputusan ada di layar ini.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $empId   = '';
    public string $empName = '';
    public string $bulan   = ''; // mm/yyyy
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
    }

    #[On('absensi.rekap-bulanan.openDetail')]
    public function openDetail(string $empId, string $bulan): void
    {
        $dataKaryawan = DB::table('siapmst_employers')->where('emp_id', $empId)->first(['emp_id', 'emp_name']);
        if (!$dataKaryawan) {
            $this->dispatch('toast', type: 'error', message: 'Data karyawan tidak ditemukan.');
            return;
        }

        $this->empId   = (string) $dataKaryawan->emp_id;
        $this->empName = (string) $dataKaryawan->emp_name;
        $this->bulan   = $bulan;
        unset($this->hari);

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'rekap-bulanan-actions');
    }

    private function awalBulan(): Carbon
    {
        try {
            return Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    /** Satu entri per tanggal dalam bulan: ['tanggal' => Carbon, 'abs' => ?object]. */
    #[Computed]
    public function hari(): array
    {
        if ($this->empId === '') {
            return [];
        }

        $awal = $this->awalBulan();
        $rows = DB::table('siaptxn_absensis as ab')
            ->leftJoin('siapmst_shifts as sh', 'sh.shift_id', '=', 'ab.shift_id')
            ->where('ab.emp_id', $this->empId)
            ->where('ab.abs_date', '>=', Absensi::tanggalSql($awal))
            ->where('ab.abs_date', '<=', Absensi::tanggalSql($awal->copy()->endOfMonth()))
            ->select('ab.*', 'sh.shift_name', 'sh.jam_masuk as shift_jam_masuk', 'sh.jam_pulang as shift_jam_pulang', 'sh.lintas_hari',
                DB::raw('(SELECT us.name FROM users us WHERE us.id = ab.lembur_oleh) AS lembur_penilai'))
            ->get()
            ->keyBy(fn($row) => substr($row->abs_date, 0, 10));

        $jadwal = Jadwal::bulan([$this->empId], $awal)[$this->empId] ?? [];

        $daftarHari = [];
        for ($tanggal = $awal->copy(); $tanggal->lte($awal->copy()->endOfMonth()); $tanggal->addDay()) {
            $daftarHari[] = ['tanggal' => $tanggal->copy(), 'abs' => $rows[$tanggal->format('Y-m-d')] ?? null, 'jadwal' => $jadwal[$tanggal->day] ?? null];
        }

        return $daftarHari;
    }

    /** Ringkasan bulan ini untuk header modal. */
    #[Computed]
    public function ringkasan(): array
    {
        $abs = collect($this->hari)->pluck('abs')->filter();

        $ringkasan = [];
        foreach (array_keys(Absensi::STATUS) as $kode) {
            $ringkasan[$kode] = $abs->where('abs_status', $kode)->count();
        }
        $ringkasan['lembur_y'] = (int) $abs->where('lembur_status', 'Y')->sum('lembur_menit');
        $ringkasan['lembur_p'] = (int) $abs->where('lembur_status', 'P')->sum('lembur_menit');
        $ringkasan['jml_p']    = $abs->where('lembur_status', 'P')->count();
        $ringkasan['terlambat'] = (int) $abs->sum('terlambat_menit');

        // Kehadiran vs jadwal — s/d kemarin (bulan berjalan) / akhir bulan (bulan lalu).
        $batas = $this->awalBulan()->isSameMonth(now()) ? now()->subDay()->startOfDay() : $this->awalBulan()->endOfMonth()->startOfDay();
        $terjadwal = collect($this->hari)->filter(fn($hari) => $hari['jadwal'] && $hari['jadwal']->jdw_jenis === 'K' && $hari['tanggal']->lte($batas));
        $ringkasan['terjadwal'] = $terjadwal->count();
        $ringkasan['hadir_terjadwal'] = $terjadwal->filter(fn($hari) => $hari['abs'] && in_array($hari['abs']->abs_status, ['H', 'T'], true))->count();
        $ringkasan['persen'] = $ringkasan['terjadwal'] > 0 ? (int) round($ringkasan['hadir_terjadwal'] / $ringkasan['terjadwal'] * 100) : null;

        return $ringkasan;
    }

    // ─── Penilaian lembur ─────────────────────────────────────────────────────
    public function nilaiLembur(int $absId, string $status): void
    {
        $milikKaryawan = DB::table('siaptxn_absensis')->where('abs_id', $absId)->where('emp_id', $this->empId)->exists();
        if (!$milikKaryawan || !Absensi::nilaiLembur($absId, $status, auth()->id())) {
            $this->dispatch('toast', type: 'error', message: 'Lembur tidak bisa dinilai.');
            return;
        }

        $this->setelahDinilai();
    }

    /** Hitung semua lembur yang masih menunggu pada bulan ini. */
    public function hitungSemuaMenunggu(): void
    {
        $ids = collect($this->hari)->pluck('abs')->filter()->where('lembur_status', 'P')->pluck('abs_id');

        foreach ($ids as $absId) {
            Absensi::nilaiLembur((int) $absId, 'Y', auth()->id());
        }

        $this->dispatch('toast', type: 'success', message: $ids->count() . ' lembur ' . $this->empName . ' dihitung.');
        $this->setelahDinilai();
    }

    private function setelahDinilai(): void
    {
        unset($this->hari, $this->ringkasan);
        $this->dispatch('absensi.rekap-bulanan.saved');
    }

    public function closeModal(): void
    {
        $this->empId = '';
        $this->empName = '';
        unset($this->hari, $this->ringkasan);
        $this->dispatch('close-modal', name: 'rekap-bulanan-actions');
        $this->resetVersion();
    }
};
?>

<div>
    <x-modal name="rekap-bulanan-actions" size="full" height="full" focusable>
        <div class="flex flex-col min-h-full" wire:key="{{ $this->renderKey('modal', [$empId, $bulan]) }}">

            {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
            <x-modul-dokumen.header :judul="'Kehadiran ' . ($empName)"
                ikon="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z">
                <span class="font-mono">{{ $empId }}</span> · {{ $bulan }} · nilai lembur: <b>Hitung</b> masuk total, <b>Tidak</b> diabaikan.
            </x-modul-dokumen.header>
            <div class="px-6 py-3 border-b shrink-0 bg-surface-soft border-hairline dark:border-gray-700">
                {{-- Ringkasan --}}
                @if ($empId !== '')
                    @php $ringkasan = $this->ringkasan; @endphp
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach (\App\Support\Absensi::STATUS as $kode => $nama)
                            <x-badge :variant="\App\Support\Absensi::BADGE[$kode]">{{ $nama }}: {{ $ringkasan[$kode] }}</x-badge>
                        @endforeach
                        <span class="mx-1 text-hairline">|</span>
                        <span class="text-sm">Terjadwal <b class="font-mono">{{ $ringkasan['terjadwal'] }}</b> hari ·
                            Kehadiran <b class="font-mono {{ $ringkasan['persen'] === null ? '' : ($ringkasan['persen'] >= 95 ? 'text-emerald-700' : ($ringkasan['persen'] >= 85 ? 'text-amber-700' : 'text-red-700')) }}">{{ $ringkasan['persen'] === null ? '—' : $ringkasan['persen'] . '%' }}</b>
                            <span class="text-xs text-muted">({{ $ringkasan['hadir_terjadwal'] }}/{{ $ringkasan['terjadwal'] }})</span></span>
                        <span class="text-sm">Terlambat <b class="font-mono">{{ \App\Support\Absensi::labelMenit($ringkasan['terlambat']) ?: '0' }}</b></span>
                        <span class="text-sm text-indigo-700">Lembur dihitung <b class="font-mono">{{ \App\Support\Absensi::labelMenit($ringkasan['lembur_y']) ?: '0' }}</b></span>
                        @if ($ringkasan['jml_p'] > 0)
                            <span class="text-sm text-amber-700">⏳ {{ $ringkasan['jml_p'] }} lembur menunggu ({{ \App\Support\Absensi::labelMenit($ringkasan['lembur_p']) }})</span>
                            <x-primary-button type="button" wire:click="hitungSemuaMenunggu" wire:loading.attr="disabled" class="!px-3 !py-1.5 text-xs">
                                ✓ Hitung semua yang menunggu
                            </x-primary-button>
                        @endif
                    </div>
                @endif
            </div>

            {{-- BODY: semua tanggal dalam bulan --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
                <div class="overflow-hidden border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    <table class="ds-table">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th class="ds-c">Jadwal</th>
                                <th>Shift</th>
                                <th>Masuk</th>
                                <th>Pulang</th>
                                <th>Status</th>
                                <th>Lembur</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->hari as $hari)
                                @php
                                    $tanggal = $hari['tanggal'];
                                    $absensi = $hari['abs'];
                                    $libur = $tanggal->isSunday();
                                @endphp
                                <tr wire:key="hari-{{ $tanggal->format('Ymd') }}" class="{{ $absensi ? '' : 'opacity-50' }} {{ $absensi && $absensi->lembur_status === 'P' ? 'bg-amber-50/60' : '' }}">
                                    <td class="whitespace-nowrap">
                                        <span class="font-mono">{{ \App\Support\Format::tanggal($tanggal) }}</span>
                                        <span class="text-xs {{ $libur ? 'text-red-600' : 'text-muted' }}">{{ \App\Support\Format::hari($tanggal) }}</span>
                                    </td>
                                    @php $jadwalHari = $hari['jadwal']; $kodeJadwal = $jadwalHari ? \App\Support\Jadwal::kode($jadwalHari) : ''; @endphp
                                    <td class="ds-c">
                                        @if ($kodeJadwal)
                                            <span class="inline-block w-7 py-0.5 text-xs font-bold rounded" style="{{ \App\Support\Jadwal::gaya($kodeJadwal, $jadwalHari->shift_warna ?? null) }}">{{ $kodeJadwal }}</span>
                                        @else
                                            <span class="text-[10px] text-muted-soft">libur</span>
                                        @endif
                                    </td>
                                    @if (!$absensi)
                                        <td colspan="6" class="text-xs italic {{ $jadwalHari && $jadwalHari->jdw_jenis === 'K' && $tanggal->lt(now()->startOfDay()) ? 'text-red-600 font-semibold not-italic' : 'text-muted-soft' }}">
                                            {{ $jadwalHari && $jadwalHari->jdw_jenis === 'K' && $tanggal->lt(now()->startOfDay()) ? 'terjadwal tapi tanpa catatan — isi/Generate di Rekap Harian' : 'tanpa catatan' }}
                                        </td>
                                    @else
                                        <td>{{ $absensi->shift_name ?? '—' }}</td>
                                        <td class="font-mono">
                                            {{ $absensi->jam_masuk ? \Carbon\Carbon::parse($absensi->jam_masuk)->format('H:i') : '—' }}
                                            @if ((int) $absensi->terlambat_menit > 0)
                                                <div class="text-[11px] text-amber-700">+{{ \App\Support\Absensi::labelMenit((int) $absensi->terlambat_menit) }}</div>
                                            @endif
                                            @if (\App\Support\Absensi::lupaAbsenMasuk($absensi))
                                                <div class="font-sans"><x-badge variant="danger">lupa absen masuk</x-badge></div>
                                            @endif
                                        </td>
                                        <td class="font-mono">
                                            @if ($absensi->jam_pulang)
                                                {{ \Carbon\Carbon::parse($absensi->jam_pulang)->format('H:i') }}
                                                @unless (\Carbon\Carbon::parse($absensi->jam_pulang)->isSameDay(\Carbon\Carbon::parse($absensi->jam_masuk ?? $absensi->abs_date)))
                                                    <span class="text-[10px] text-muted-soft">(+1)</span>
                                                @endunless
                                                @if ($absensi->shift_jam_pulang)
                                                    <div class="text-[10px] text-muted-soft">jadwal {{ $absensi->shift_jam_pulang }}</div>
                                                @endif
                                                @if ((int) $absensi->pulang_cepat_menit > 0)
                                                    <div class="font-sans text-[11px] text-red-700">pulang cepat {{ \App\Support\Absensi::labelMenit((int) $absensi->pulang_cepat_menit) }}</div>
                                                @endif
                                            @elseif ($absensi->jam_masuk && \App\Support\Absensi::tanpaAbsenPulang($absensi))
                                                <span class="font-sans"><x-badge variant="danger">tanpa absen pulang</x-badge></span>
                                            @elseif ($absensi->jam_masuk)
                                                <span class="text-xs text-muted-soft">belum pulang</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            <x-badge :variant="\App\Support\Absensi::BADGE[$absensi->abs_status] ?? 'gray'">{{ \App\Support\Absensi::STATUS[$absensi->abs_status] ?? $absensi->abs_status }}</x-badge>
                                        </td>
                                        <td>
                                            @if ((int) $absensi->lembur_menit > 0)
                                                @php $statusLembur = $absensi->lembur_status ?: 'P'; @endphp
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <span class="font-mono text-sm font-semibold {{ $statusLembur === 'N' ? 'line-through text-muted-soft' : 'text-indigo-700' }}">
                                                        {{ \App\Support\Absensi::labelMenit((int) $absensi->lembur_menit) }}
                                                    </span>
                                                    @if ($statusLembur === 'P')
                                                        {{-- Belum dinilai → pilih; setelah dipilih label "Menunggu" hilang --}}
                                                        <button type="button" wire:click="nilaiLembur({{ $absensi->abs_id }}, 'Y')"
                                                            class="px-2 py-0.5 text-[11px] font-semibold rounded bg-emerald-600 text-white hover:bg-emerald-700">✓ Hitung</button>
                                                        <button type="button" wire:click="nilaiLembur({{ $absensi->abs_id }}, 'N')"
                                                            class="px-2 py-0.5 text-[11px] font-semibold rounded border border-gray-300 text-gray-700 hover:bg-gray-100">✗ Tidak</button>
                                                    @else
                                                        <x-badge :variant="\App\Support\Absensi::LEMBUR_BADGE[$statusLembur]">{{ \App\Support\Absensi::LEMBUR_STATUS[$statusLembur] }}</x-badge>
                                                        <button type="button" wire:click="nilaiLembur({{ $absensi->abs_id }}, 'P')"
                                                            class="text-[11px] text-muted-soft hover:underline" title="Batalkan penilaian">ubah</button>
                                                    @endif
                                                </div>
                                                @if ($statusLembur !== 'P' && $absensi->lembur_penilai)
                                                    <div class="mt-0.5 text-[10px] text-muted-soft">
                                                        oleh {{ $absensi->lembur_penilai }}{{ $absensi->lembur_dinilai_at ? ', ' . \App\Support\Format::waktu($absensi->lembur_dinilai_at) : '' }}
                                                    </div>
                                                @endif
                                            @else
                                                <span class="text-muted-soft">—</span>
                                            @endif
                                        </td>
                                        <td class="text-sm text-muted">{{ $absensi->keterangan }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs text-muted dark:text-gray-400">
                        Koreksi jam / isi Izin-Sakit-Cuti-Alpa dilakukan di Rekap Harian.
                    </p>
                    <x-secondary-button type="button" wire:click="closeModal">Tutup</x-secondary-button>
                </div>
            </div>

        </div>
    </x-modal>
</div>
