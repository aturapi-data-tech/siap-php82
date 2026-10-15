<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Support\Format;

/**
 * Rekap absensi bulanan per karyawan: jumlah H/T/I/S/C/A + total menit terlambat.
 * Total Lembur = hanya lembur yang DINILAI "Dihitung" (Y) di Rekap Harian; yang masih
 * menunggu ditampilkan terpisah, yang "Tidak dihitung" diabaikan.
 * Klik nama karyawan → rincian kehadiran sebulan + penilaian lembur (-actions).
 * Hari Terjadwal & % Kehadiran dari JADWAL DINAS: hari berjadwal kerja (K, bukan I/C) s/d
 * kemarin (bulan berjalan) / akhir bulan (bulan lalu); % = hadir (H/T) di hari terjadwal ÷ terjadwal.
 * "Tanpa catatan" = hari dalam periode (s/d hari ini) yang belum punya baris absensi sama
 * sekali (belum dicatat Libur/Alpa) — isi lewat Generate Libur/Alpa di Rekap Harian.
 */
new class extends Component {
    use WithPagination;

    public string $bulan         = ''; // mm/yyyy
    public string $searchKeyword = '';
    public int    $itemsPerPage  = 25;

    public function mount(): void
    {
        $this->bulan = now()->format(Format::BULAN);
    }

    public function updatedBulan(): void         { $this->resetPage(); }
    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword']);
        $this->bulan = now()->format(Format::BULAN);
        $this->itemsPerPage = 25;
        $this->resetPage();
    }

    public function geserBulan(int $n): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($n)->format(Format::BULAN);
        $this->resetPage();
    }

    public function openDetail(string $empId): void
    {
        $this->dispatch('absensi.rekap-bulanan.openDetail', empId: $empId, bulan: $this->awalBulan()->format(Format::BULAN));
    }

    // Lembur dinilai di modal → cukup render ulang, halaman tetap.
    #[On('absensi.rekap-bulanan.saved')]
    public function refreshAfterSaved(): void {}

    private function awalBulan(): Carbon
    {
        try {
            return Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    /** Jumlah hari periode yang sudah berjalan (bulan berjalan dihitung s/d hari ini). */
    #[Computed]
    public function hariBerjalan(): int
    {
        $awal = $this->awalBulan();
        if ($awal->isFuture()) {
            return 0;
        }
        $akhir = $awal->isSameMonth(now()) ? now()->startOfDay() : $awal->copy()->endOfMonth()->startOfDay();

        return (int) $awal->diffInDays($akhir) + 1;
    }

    /**
     * Batas perhitungan % kehadiran: bulan lalu → akhir bulan; bulan berjalan → KEMARIN
     * (hari ini belum selesai, shift sore belum datang); bulan depan → null.
     */
    #[Computed]
    public function batasKehadiran(): ?Carbon
    {
        $awal = $this->awalBulan();
        $batas = $awal->isSameMonth(now()) ? now()->subDay()->startOfDay() : $awal->copy()->endOfMonth()->startOfDay();

        return $batas->lt($awal) || $awal->isFuture() ? null : $batas;
    }

    #[Computed]
    public function rows()
    {
        $awal  = $this->awalBulan();
        $dari  = Absensi::tanggalSql($awal);
        $sampai = Absensi::tanggalSql($awal->copy()->endOfMonth());

        $hitung = fn(string $kode) => DB::raw("SUM(CASE WHEN ab.abs_status = '{$kode}' THEN 1 ELSE 0 END) AS jml_" . strtolower($kode));

        // Tanggal literal untuk subquery jadwal (nilai dari Carbon, bukan input user).
        $lit = fn(Carbon $t) => "to_date('" . $t->format('Y-m-d') . "', 'YYYY-MM-DD')";
        $dariL   = $lit($awal);
        $sampaiL = $lit($awal->copy()->endOfMonth());
        $batasL  = $this->batasKehadiran ? $lit($this->batasKehadiran) : $lit($awal->copy()->subDay()); // kosong → 0
        $terjadwal = fn(string $sd, string $alias) => DB::raw(
            "(SELECT COUNT(*) FROM siaptxn_jadwals jd WHERE jd.emp_id = em.emp_id AND jd.jdw_jenis = 'K'
               AND jd.jdw_tanggal BETWEEN {$dariL} AND {$sd}) AS {$alias}");

        $q = DB::table('siapmst_employers as em')
            ->leftJoin('siaptxn_absensis as ab', function ($join) use ($dari, $sampai) {
                $join->on('ab.emp_id', '=', 'em.emp_id')
                    ->where('ab.abs_date', '>=', $dari)
                    ->where('ab.abs_date', '<=', $sampai);
            })
            ->where('em.active_status', '1')
            ->groupBy('em.emp_id', 'em.emp_name')
            ->select('em.emp_id', 'em.emp_name',
                $hitung('H'), $hitung('T'), $hitung('I'), $hitung('S'), $hitung('C'), $hitung('A'), $hitung('L'),
                DB::raw('COUNT(ab.abs_id) AS jml_catatan'),
                DB::raw('NVL(SUM(ab.terlambat_menit), 0) AS total_terlambat'),
                DB::raw("NVL(SUM(CASE WHEN ab.lembur_status = 'Y' THEN ab.lembur_menit END), 0) AS total_lembur"),
                DB::raw("SUM(CASE WHEN ab.lembur_status = 'Y' THEN 1 ELSE 0 END) AS hari_lembur"),
                DB::raw("NVL(SUM(CASE WHEN ab.lembur_status = 'P' THEN ab.lembur_menit END), 0) AS lembur_menunggu"),
                DB::raw('SUM(CASE WHEN ab.dobel_shift_id IS NOT NULL THEN 1 ELSE 0 END) AS jml_dobel_shift'),
                $terjadwal($batasL, 'hari_terjadwal'),
                $terjadwal($sampaiL, 'terjadwal_sebulan'),
                DB::raw("(SELECT COUNT(*) FROM siaptxn_absensis a2
                           JOIN siaptxn_jadwals j2 ON j2.emp_id = a2.emp_id AND j2.jdw_tanggal = a2.abs_date AND j2.jdw_jenis = 'K'
                          WHERE a2.emp_id = em.emp_id AND a2.abs_status IN ('H', 'T')
                            AND a2.abs_date BETWEEN {$dariL} AND {$batasL}) AS hadir_terjadwal"))
            ->orderBy('em.emp_id');

        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->where(fn($sub) => $sub->whereRaw('UPPER(em.emp_id) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(em.emp_name) LIKE ?', ["%{$kw}%"]));
        }

        return $q->paginate($this->itemsPerPage);
    }
};
?>

<div>

    <x-page-title
        title="Rekap Absensi Bulanan"
        subtitle="Jumlah kehadiran per karyawan — klik nama untuk melihat rincian harian & menilai lembur" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex items-center gap-1">
                            <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-2.5" title="Bulan sebelumnya">‹</x-secondary-button>
                            <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" class="w-28 text-center font-mono" />
                            <x-secondary-button type="button" wire:click="geserBulan(1)" class="!px-2.5" title="Bulan berikutnya">›</x-secondary-button>
                        </div>
                        <div class="w-full lg:w-64">
                            <x-input-label for="searchKeyword" value="Cari Karyawan" class="sr-only" />
                            <x-text-input id="searchKeyword" type="text" wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari ID / nama..." class="block w-full" />
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-24">
                            <x-select-input wire:model.live="itemsPerPage">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </x-select-input>
                        </div>
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>
                <p class="mt-2 text-xs text-muted dark:text-gray-400">
                    Periode berjalan: <b>{{ $this->hariBerjalan }}</b> hari.
                    % Kehadiran dihitung dari Jadwal Dinas s/d <b>{{ $this->batasKehadiran ? \App\Support\Format::tanggal($this->batasKehadiran) : '—' }}</b>
                    (hari ini belum dihitung) — Izin/Cuti yang sudah dijadwalkan (I/C) tidak termasuk hari kerja.
                    "Tanpa catatan" = hari yang belum dicatat sama sekali — lengkapi lewat <b>Generate Libur / Alpa</b> di Rekap Harian.
                </p>
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-t-2xl">
                    <table class="ds-table">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>Karyawan</th>
                                <th class="ds-c" title="Hari berjadwal kerja (P/S) s/d batas perhitungan">Terjadwal</th>
                                <th class="ds-c" title="Hadir (H/T) pada hari terjadwal ÷ hari terjadwal">% Kehadiran</th>
                                @foreach (\App\Support\Absensi::STATUS as $kode => $nama)
                                    <th class="ds-c" title="{{ $nama }}">{{ $nama }}</th>
                                @endforeach
                                <th class="ds-c">Total Terlambat</th>
                                <th class="ds-c">Lembur Dihitung</th>
                                <th class="ds-c">Tanpa Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                <tr wire:key="bulanan-{{ $row->emp_id }}">
                                    <td>
                                        <button type="button" wire:click="openDetail('{{ $row->emp_id }}')"
                                            class="block font-semibold text-left text-brand-green hover:underline dark:text-brand-lime">
                                            {{ $row->emp_name }}
                                        </button>
                                        <span class="font-mono text-xs text-muted dark:text-gray-400">{{ $row->emp_id }}</span>
                                    </td>
                                    @php
                                        $terj = (int) $row->hari_terjadwal;
                                        $persen = $terj > 0 ? round((int) $row->hadir_terjadwal / $terj * 100) : null;
                                        $warnaPersen = $persen === null ? 'text-muted-soft' : ($persen >= 95 ? 'bg-emerald-100 text-emerald-800' : ($persen >= 85 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800'));
                                    @endphp
                                    <td class="ds-c">
                                        <div class="font-mono font-semibold">{{ $terj }}</div>
                                        @if ((int) $row->terjadwal_sebulan !== $terj)
                                            <div class="text-[10px] text-muted-soft">dari {{ (int) $row->terjadwal_sebulan }} sebulan</div>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        @if ($persen !== null)
                                            <span class="inline-block px-2 py-0.5 rounded font-mono font-bold text-sm {{ $warnaPersen }}">{{ $persen }}%</span>
                                            <div class="text-[10px] text-muted-soft">{{ (int) $row->hadir_terjadwal }}/{{ $terj }} hari</div>
                                        @else
                                            <span class="text-muted-soft" title="Belum ada hari terjadwal yang lewat">—</span>
                                        @endif
                                    </td>
                                    @foreach (array_keys(\App\Support\Absensi::STATUS) as $kode)
                                        @php $jml = (int) $row->{'jml_' . strtolower($kode)}; @endphp
                                        <td class="ds-c">
                                            @if ($jml > 0)
                                                <x-badge :variant="\App\Support\Absensi::BADGE[$kode]">{{ $jml }}</x-badge>
                                            @else
                                                <span class="text-muted-soft">0</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="font-mono ds-c">
                                        {{ \App\Support\Absensi::labelTerlambat((int) $row->total_terlambat) ?: '—' }}
                                    </td>
                                    <td class="font-mono ds-c">
                                        @if ((int) $row->total_lembur > 0)
                                            <span class="text-indigo-700">{{ \App\Support\Absensi::labelMenit((int) $row->total_lembur) }}</span>
                                            <div class="text-[11px] text-muted-soft">
                                                {{ (int) $row->hari_lembur }} hari{{ (int) $row->jml_dobel_shift > 0 ? ' · ' . (int) $row->jml_dobel_shift . '× dobel shift' : '' }}
                                            </div>
                                        @else
                                            —
                                        @endif
                                        @if ((int) $row->lembur_menunggu > 0)
                                            <button type="button" wire:click="openDetail('{{ $row->emp_id }}')"
                                                class="text-[11px] text-amber-700 hover:underline">
                                                ⏳ + {{ \App\Support\Absensi::labelMenit((int) $row->lembur_menunggu) }} menunggu — nilai
                                            </button>
                                        @endif
                                    </td>
                                    <td class="ds-c text-muted">{{ max(0, $this->hariBerjalan - (int) $row->jml_catatan) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="13" class="px-6 py-10 text-center text-muted dark:text-gray-400">Tidak ada data.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::absensi.rekap-bulanan.rekap-bulanan-actions wire:key="rekap-bulanan-actions" />

        </div>
    </div>
</div>
