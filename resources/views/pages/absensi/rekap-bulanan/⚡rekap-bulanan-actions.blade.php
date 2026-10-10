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
        $emp = DB::table('siapmst_employers')->where('emp_id', $empId)->first(['emp_id', 'emp_name']);
        if (!$emp) {
            $this->dispatch('toast', type: 'error', message: 'Data karyawan tidak ditemukan.');
            return;
        }

        $this->empId   = (string) $emp->emp_id;
        $this->empName = (string) $emp->emp_name;
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
            ->select('ab.*', 'sh.shift_name', 'sh.jam_masuk as shift_jam_masuk', 'sh.jam_pulang as shift_jam_pulang',
                DB::raw('(SELECT us.name FROM users us WHERE us.id = ab.lembur_oleh) AS lembur_penilai'))
            ->get()
            ->keyBy(fn($r) => substr($r->abs_date, 0, 10));

        $jadwal = Jadwal::bulan([$this->empId], $awal)[$this->empId] ?? [];

        $hasil = [];
        for ($tgl = $awal->copy(); $tgl->lte($awal->copy()->endOfMonth()); $tgl->addDay()) {
            $hasil[] = ['tanggal' => $tgl->copy(), 'abs' => $rows[$tgl->format('Y-m-d')] ?? null, 'jadwal' => $jadwal[$tgl->day] ?? null];
        }

        return $hasil;
    }

    /** Ringkasan bulan ini untuk header modal. */
    #[Computed]
    public function ringkasan(): array
    {
        $abs = collect($this->hari)->pluck('abs')->filter();

        $hasil = [];
        foreach (array_keys(Absensi::STATUS) as $kode) {
            $hasil[$kode] = $abs->where('abs_status', $kode)->count();
        }
        $hasil['lembur_y'] = (int) $abs->where('lembur_status', 'Y')->sum('lembur_menit');
        $hasil['lembur_p'] = (int) $abs->where('lembur_status', 'P')->sum('lembur_menit');
        $hasil['jml_p']    = $abs->where('lembur_status', 'P')->count();
        $hasil['terlambat'] = (int) $abs->sum('terlambat_menit');

        // Kehadiran vs jadwal — s/d kemarin (bulan berjalan) / akhir bulan (bulan lalu).
        $batas = $this->awalBulan()->isSameMonth(now()) ? now()->subDay()->startOfDay() : $this->awalBulan()->endOfMonth()->startOfDay();
        $terjadwal = collect($this->hari)->filter(fn($h) => $h['jadwal'] && $h['jadwal']->jdw_jenis === 'K' && $h['tanggal']->lte($batas));
        $hasil['terjadwal'] = $terjadwal->count();
        $hasil['hadir_terjadwal'] = $terjadwal->filter(fn($h) => $h['abs'] && in_array($h['abs']->abs_status, ['H', 'T'], true))->count();
        $hasil['persen'] = $hasil['terjadwal'] > 0 ? (int) round($hasil['hadir_terjadwal'] / $hasil['terjadwal'] * 100) : null;

        return $hasil;
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
        <div class="flex flex-col min-h-[calc(100vh-8rem)]" wire:key="{{ $this->renderKey('modal', [$empId, $bulan]) }}">

            {{-- HEADER --}}
            <div class="relative px-6 py-5 bg-surface-soft">
                <div class="relative flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-10 h-10 rounded-xl bg-brand-green/10 dark:bg-brand-lime/15">
                            <img src="{{ asset('images/Logogram black solid.png') }}" alt="Logo" class="block w-6 h-6 dark:hidden" />
                            <img src="{{ asset('images/Logogram white solid.png') }}" alt="Logo" class="hidden w-6 h-6 dark:block" />
                        </div>
                        <div>
                            <h2 class="ds-display-sm dark:text-gray-100">Kehadiran {{ $empName }}</h2>
                            <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                <span class="font-mono">{{ $empId }}</span> ·
                                {{ $bulan }}
                                · nilai lembur: <b>Hitung</b> masuk total, <b>Tidak</b> diabaikan.
                            </p>
                        </div>
                    </div>
                    <x-icon-button color="gray" type="button" wire:click="closeModal">
                        <span class="sr-only">Close</span>
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </x-icon-button>
                </div>

                {{-- Ringkasan --}}
                @if ($empId !== '')
                    @php $rk = $this->ringkasan; @endphp
                    <div class="flex flex-wrap items-center gap-2 mt-4">
                        @foreach (\App\Support\Absensi::STATUS as $kode => $nama)
                            <x-badge :variant="\App\Support\Absensi::BADGE[$kode]">{{ $nama }}: {{ $rk[$kode] }}</x-badge>
                        @endforeach
                        <span class="mx-1 text-hairline">|</span>
                        <span class="text-sm">Terjadwal <b class="font-mono">{{ $rk['terjadwal'] }}</b> hari ·
                            Kehadiran <b class="font-mono {{ $rk['persen'] === null ? '' : ($rk['persen'] >= 95 ? 'text-emerald-700' : ($rk['persen'] >= 85 ? 'text-amber-700' : 'text-red-700')) }}">{{ $rk['persen'] === null ? '—' : $rk['persen'] . '%' }}</b>
                            <span class="text-xs text-muted">({{ $rk['hadir_terjadwal'] }}/{{ $rk['terjadwal'] }})</span></span>
                        <span class="text-sm">Terlambat <b class="font-mono">{{ \App\Support\Absensi::labelMenit($rk['terlambat']) ?: '0' }}</b></span>
                        <span class="text-sm text-indigo-700">Lembur dihitung <b class="font-mono">{{ \App\Support\Absensi::labelMenit($rk['lembur_y']) ?: '0' }}</b></span>
                        @if ($rk['jml_p'] > 0)
                            <span class="text-sm text-amber-700">⏳ {{ $rk['jml_p'] }} lembur menunggu ({{ \App\Support\Absensi::labelMenit($rk['lembur_p']) }})</span>
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
                            @foreach ($this->hari as $h)
                                @php
                                    $tgl = $h['tanggal'];
                                    $ab = $h['abs'];
                                    $libur = $tgl->isSunday();
                                @endphp
                                <tr wire:key="hari-{{ $tgl->format('Ymd') }}" class="{{ $ab ? '' : 'opacity-50' }} {{ $ab && $ab->lembur_status === 'P' ? 'bg-amber-50/60' : '' }}">
                                    <td class="whitespace-nowrap">
                                        <span class="font-mono">{{ \App\Support\Format::tanggal($tgl) }}</span>
                                        <span class="text-xs {{ $libur ? 'text-red-600' : 'text-muted' }}">{{ \App\Support\Format::hari($tgl) }}</span>
                                    </td>
                                    @php $jd = $h['jadwal']; $kj = $jd ? \App\Support\Jadwal::kode($jd) : ''; @endphp
                                    <td class="ds-c">
                                        @if ($kj)
                                            <span class="inline-block w-7 py-0.5 text-xs font-bold rounded {{ \App\Support\Jadwal::WARNA[$kj] ?? \App\Support\Jadwal::WARNA_LAIN }}">{{ $kj }}</span>
                                        @else
                                            <span class="text-[10px] text-muted-soft">libur</span>
                                        @endif
                                    </td>
                                    @if (!$ab)
                                        <td colspan="6" class="text-xs italic {{ $jd && $jd->jdw_jenis === 'K' && $tgl->lt(now()->startOfDay()) ? 'text-red-600 font-semibold not-italic' : 'text-muted-soft' }}">
                                            {{ $jd && $jd->jdw_jenis === 'K' && $tgl->lt(now()->startOfDay()) ? 'terjadwal tapi tanpa catatan — isi/Generate di Rekap Harian' : 'tanpa catatan' }}
                                        </td>
                                    @else
                                        <td>{{ $ab->shift_name ?? '—' }}</td>
                                        <td class="font-mono">
                                            {{ $ab->jam_masuk ? \Carbon\Carbon::parse($ab->jam_masuk)->format('H:i') : '—' }}
                                            @if ((int) $ab->terlambat_menit > 0)
                                                <div class="text-[11px] text-amber-700">+{{ \App\Support\Absensi::labelMenit((int) $ab->terlambat_menit) }}</div>
                                            @endif
                                        </td>
                                        <td class="font-mono">
                                            @if ($ab->jam_pulang)
                                                {{ \Carbon\Carbon::parse($ab->jam_pulang)->format('H:i') }}
                                                @unless (\Carbon\Carbon::parse($ab->jam_pulang)->isSameDay(\Carbon\Carbon::parse($ab->jam_masuk)))
                                                    <span class="text-[10px] text-muted-soft">(+1)</span>
                                                @endunless
                                                @if ($ab->shift_jam_pulang)
                                                    <div class="text-[10px] text-muted-soft">jadwal {{ $ab->shift_jam_pulang }}</div>
                                                @endif
                                            @elseif ($ab->jam_masuk)
                                                <span class="text-xs text-muted-soft">belum pulang</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            <x-badge :variant="\App\Support\Absensi::BADGE[$ab->abs_status] ?? 'gray'">{{ \App\Support\Absensi::STATUS[$ab->abs_status] ?? $ab->abs_status }}</x-badge>
                                        </td>
                                        <td>
                                            @if ((int) $ab->lembur_menit > 0)
                                                @php $st = $ab->lembur_status ?: 'P'; @endphp
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <span class="font-mono text-sm font-semibold {{ $st === 'N' ? 'line-through text-muted-soft' : 'text-indigo-700' }}">
                                                        {{ \App\Support\Absensi::labelMenit((int) $ab->lembur_menit) }}
                                                    </span>
                                                    @if ($st === 'P')
                                                        {{-- Belum dinilai → pilih; setelah dipilih label "Menunggu" hilang --}}
                                                        <button type="button" wire:click="nilaiLembur({{ $ab->abs_id }}, 'Y')"
                                                            class="px-2 py-0.5 text-[11px] font-semibold rounded bg-emerald-600 text-white hover:bg-emerald-700">✓ Hitung</button>
                                                        <button type="button" wire:click="nilaiLembur({{ $ab->abs_id }}, 'N')"
                                                            class="px-2 py-0.5 text-[11px] font-semibold rounded border border-gray-300 text-gray-700 hover:bg-gray-100">✗ Tidak</button>
                                                    @else
                                                        <x-badge :variant="\App\Support\Absensi::LEMBUR_BADGE[$st]">{{ \App\Support\Absensi::LEMBUR_STATUS[$st] }}</x-badge>
                                                        <button type="button" wire:click="nilaiLembur({{ $ab->abs_id }}, 'P')"
                                                            class="text-[11px] text-muted-soft hover:underline" title="Batalkan penilaian">ubah</button>
                                                    @endif
                                                </div>
                                                @if ($st !== 'P' && $ab->lembur_penilai)
                                                    <div class="mt-0.5 text-[10px] text-muted-soft">
                                                        oleh {{ $ab->lembur_penilai }}{{ $ab->lembur_dinilai_at ? ', ' . \App\Support\Format::waktu($ab->lembur_dinilai_at) : '' }}
                                                    </div>
                                                @endif
                                            @else
                                                <span class="text-muted-soft">—</span>
                                            @endif
                                        </td>
                                        <td class="text-sm text-muted">{{ $ab->keterangan }}</td>
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
