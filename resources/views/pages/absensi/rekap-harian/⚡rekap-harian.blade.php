<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Support\Absensi;

/**
 * Rekap absensi harian: SEMUA karyawan aktif pada satu tanggal kerja (left join),
 * jadi yang belum absen ikut terlihat. Isi/koreksi manual lewat -actions.
 * Lembur hanya DITAMPILKAN di sini; penilaian (dihitung / tidak) di Rekap Bulanan.
 */
new class extends Component {
    use WithPagination;

    public string $tanggal       = '';  // dd/mm/yyyy
    public string $searchKeyword = '';
    public string $filterStatus  = '';  // '' | H T I S C A | BELUM | LEMBUR_P | kunci CATATAN_KHUSUS

    /** Catatan yang perlu perhatian HRD (filter & kartu ringkasan). */
    public const CATATAN_KHUSUS = [
        'LUPA_MASUK'   => 'Lupa absen masuk',
        'BELUM_PULANG' => 'Belum / tanpa absen pulang',
        'PULANG_CEPAT' => 'Pulang cepat',
    ];
    public string $filterUnit    = '';  // '' = semua unit
    public int    $itemsPerPage  = 25;

    public function mount(): void
    {
        $this->tanggal = now()->format('d/m/Y');
    }

    public function updatedTanggal(): void      { $this->resetPage(); }
    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedFilterStatus(): void  { $this->resetPage(); }
    public function updatedFilterUnit(): void    { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterStatus', 'filterUnit']);
        $this->tanggal = now()->format('d/m/Y');
        $this->itemsPerPage = 25;
        $this->resetPage();
    }

    public function geserTanggal(int $hari): void
    {
        $this->tanggal = $this->tanggalCarbon()->addDays($hari)->format('d/m/Y');
        $this->resetPage();
    }

    public function openInput(string $empId): void
    {
        $this->dispatch('absensi.rekap-harian.openInput', empId: $empId, tanggal: $this->tanggalCarbon()->format('d/m/Y'));
    }

    /** Generate Libur/Alpa massal untuk karyawan belum absen — mutasinya di -generate-actions. */
    public function openGenerate(): void
    {
        $this->dispatch('absensi.rekap-harian.openGenerate', tanggal: $this->tanggalCarbon()->format('d/m/Y'));
    }

    #[On('absensi.rekap-harian.saved')]
    public function refreshAfterSaved(): void {}

    public function tanggalCarbon(): Carbon
    {
        try {
            return Carbon::createFromFormat('!d/m/Y', $this->tanggal);
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }

    private function baseQuery()
    {
        $tanggalSql = Absensi::tanggalSql($this->tanggalCarbon());

        $query = DB::table('siapmst_employers as em')
            ->leftJoin('siaptxn_absensis as ab', function ($join) use ($tanggalSql) {
                $join->on('ab.emp_id', '=', 'em.emp_id')->where('ab.abs_date', '=', $tanggalSql);
            })
            ->leftJoin('siapmst_shifts as sh', 'sh.shift_id', '=', 'ab.shift_id')
            ->where('em.active_status', '1')
            ->when($this->filterUnit !== '', fn($subQuery) => $subQuery->where('em.emp_unit_id', $this->filterUnit));

        if (trim($this->searchKeyword) !== '') {
            $keyword = mb_strtoupper(trim($this->searchKeyword));
            $query->where(fn($subQuery) => $subQuery->whereRaw('UPPER(em.emp_id) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('UPPER(em.emp_name) LIKE ?', ["%{$keyword}%"]));
        }

        return $query;
    }

    /** Kondisi SQL satu kunci CATATAN_KHUSUS. */
    private function saringCatatanKhusus($query, string $kode)
    {
        return match ($kode) {
            'LUPA_MASUK'   => $query->whereNull('ab.jam_masuk')->whereNotNull('ab.jam_pulang')->whereIn('ab.sumber', ['KIOSK', 'MOBILE']),
            'BELUM_PULANG' => $query->whereNotNull('ab.jam_masuk')->whereNull('ab.jam_pulang'),
            'PULANG_CEPAT' => $query->where('ab.pulang_cepat_menit', '>', 0),
        };
    }

    #[Computed]
    public function rows()
    {
        $tanggalSql = Absensi::tanggalSql($this->tanggalCarbon());
        $query = $this->baseQuery()
            // Jadwal dinas tanggal ini (kosong = libur/tidak dijadwalkan)
            ->leftJoin('siaptxn_jadwals as jd', function ($join) use ($tanggalSql) {
                $join->on('jd.emp_id', '=', 'em.emp_id')->where('jd.jdw_tanggal', '=', $tanggalSql);
            })
            ->leftJoin('siapmst_shifts as sj', 'sj.shift_id', '=', 'jd.shift_id')
            ->leftJoin('siapmst_shifts as sd', 'sd.shift_id', '=', 'ab.dobel_shift_id')
            ->select('sd.shift_name as dobel_shift_name', 'jd.jdw_jenis', 'sj.shift_kode as jadwal_kode', 'sj.shift_warna as jadwal_warna', 'sj.shift_name as jadwal_shift','em.emp_id', 'em.emp_name', 'ab.abs_id', 'ab.abs_status', 'ab.shift_id', 'sh.shift_name',
                'ab.abs_date', 'sh.jam_pulang as shift_jam_pulang', 'sh.lintas_hari', 'ab.pulang_cepat_menit',
                'ab.jam_masuk', 'ab.jam_pulang', 'ab.terlambat_menit', 'ab.lembur_menit', 'ab.lembur_status', 'ab.lembur_dinilai_at', 'ab.keterangan', 'ab.sumber',
                'ab.masuk_foto', 'ab.masuk_jarak_meter', 'ab.pulang_foto', 'ab.pulang_jarak_meter',
                DB::raw('(SELECT us.name FROM users us WHERE us.id = ab.lembur_oleh) AS lembur_penilai'))
            ->orderBy('em.emp_name');

        if ($this->filterStatus === 'BELUM') {
            $query->whereNull('ab.abs_id');
        } elseif ($this->filterStatus === 'LEMBUR_P') {
            $query->where('ab.lembur_status', 'P');
        } elseif (isset(self::CATATAN_KHUSUS[$this->filterStatus])) {
            $this->saringCatatanKhusus($query, $this->filterStatus);
        } elseif ($this->filterStatus !== '') {
            $query->where('ab.abs_status', $this->filterStatus);
        }

        return $query->paginate($this->itemsPerPage);
    }

    /** Jumlah per status (+ BELUM) untuk kartu ringkasan — tidak terpengaruh filter status. */
    #[Computed]
    public function ringkasan(): array
    {
        $hitung = $this->baseQuery()
            ->groupBy('ab.abs_status')
            ->select('ab.abs_status', DB::raw('COUNT(*) AS jumlah'))
            ->pluck('jumlah', 'abs_status');

        $jumlahPerStatus = [
            'BELUM'    => (int) ($hitung[''] ?? 0),
            'LEMBUR_P' => (int) $this->baseQuery()->where('ab.lembur_status', 'P')->count(),
        ];
        foreach (array_keys(Absensi::STATUS) as $kode) {
            $jumlahPerStatus[$kode] = (int) ($hitung[$kode] ?? 0);
        }
        foreach (array_keys(self::CATATAN_KHUSUS) as $kode) {
            $jumlahPerStatus[$kode] = (int) $this->saringCatatanKhusus($this->baseQuery(), $kode)->count();
        }

        return $jumlahPerStatus;
    }
};
?>

<div>

    <x-page-title
        title="Rekap Absensi Harian"
        subtitle="Semua karyawan aktif pada tanggal kerja terpilih — isi Izin/Sakit/Cuti/Alpa/Libur, Generate Libur/Alpa & koreksi jam. Lembur dinilai di Rekap Bulanan." />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex flex-wrap items-end gap-2">
                        {{-- Tanggal --}}
                        <div class="flex items-center gap-1">
                            <x-secondary-button type="button" wire:click="geserTanggal(-1)" class="!px-2.5" title="Hari sebelumnya">‹</x-secondary-button>
                            <x-text-input wire:model.live.debounce.600ms="tanggal" x-mask="99/99/9999" placeholder="dd/mm/yyyy" class="w-32 text-center" />
                            <x-secondary-button type="button" wire:click="geserTanggal(1)" class="!px-2.5" title="Hari berikutnya">›</x-secondary-button>
                        </div>
                        <div class="w-full lg:w-64">
                            <x-input-label for="searchKeyword" value="Cari Karyawan" class="sr-only" />
                            <x-text-input id="searchKeyword" type="text" wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari ID / nama..." class="block w-full" />
                        </div>
                        <div class="w-44">
                            <x-select-input wire:model.live="filterUnit">
                                <option value="">Semua unit</option>
                                @foreach (\App\Support\UnitKerja::aktif() as $unit)
                                    <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div class="w-40">
                            <x-select-input wire:model.live="filterStatus">
                                <option value="">Semua status</option>
                                <option value="BELUM">Belum absen</option>
                                <option value="LEMBUR_P">Lembur menunggu penilaian</option>
                                @foreach ($this::CATATAN_KHUSUS as $kode => $nama)
                                    <option value="{{ $kode }}">{{ $nama }}</option>
                                @endforeach
                                @foreach (\App\Support\Absensi::STATUS as $kode => $nama)
                                    <option value="{{ $kode }}">{{ $nama }}</option>
                                @endforeach
                            </x-select-input>
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
                        @php $bisaGenerate = $this->tanggalCarbon()->lt(now()->startOfDay()); @endphp
                        <x-danger-button type="button" wire:click="openGenerate" :disabled="!$bisaGenerate"
                            title="{{ $bisaGenerate ? 'Catat Libur / Alpa sekaligus untuk karyawan yang belum absen' : 'Hanya untuk tanggal yang sudah lewat (sebelum hari ini)' }}">
                            Generate Libur / Alpa
                        </x-danger-button>
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>

                {{-- Ringkasan per status (klik = filter) --}}
                <div class="flex flex-wrap gap-2 mt-3">
                    @php $ringkasan = $this->ringkasan; @endphp
                    <button type="button" wire:click="$set('filterStatus', 'BELUM')"
                        class="px-3 py-1.5 text-xs font-semibold rounded-lg border {{ $filterStatus === 'BELUM' ? 'ring-2 ring-offset-1 ring-gray-400' : '' }} bg-canvas border-hairline text-body">
                        Belum absen: {{ $ringkasan['BELUM'] }}
                    </button>
                    @foreach (\App\Support\Absensi::STATUS as $kode => $nama)
                        <button type="button" wire:click="$set('filterStatus', '{{ $kode }}')"
                            class="rounded-lg {{ $filterStatus === $kode ? 'ring-2 ring-offset-1 ring-gray-400' : '' }}">
                            <x-badge :variant="\App\Support\Absensi::BADGE[$kode]">{{ $nama }}: {{ $ringkasan[$kode] }}</x-badge>
                        </button>
                    @endforeach
                    @if ($ringkasan['LEMBUR_P'] > 0 || $filterStatus === 'LEMBUR_P')
                        <button type="button" wire:click="$set('filterStatus', 'LEMBUR_P')"
                            class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-amber-300 bg-amber-50 text-amber-800 {{ $filterStatus === 'LEMBUR_P' ? 'ring-2 ring-offset-1 ring-amber-400' : '' }}">
                            ⏳ Lembur menunggu penilaian: {{ $ringkasan['LEMBUR_P'] }} <span class="font-normal">(dinilai di Rekap Bulanan)</span>
                        </button>
                    @endif
                    @foreach ($this::CATATAN_KHUSUS as $kode => $nama)
                        @if ($ringkasan[$kode] > 0 || $filterStatus === $kode)
                            <button type="button" wire:click="$set('filterStatus', '{{ $kode }}')"
                                class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-red-300 bg-red-50 text-red-800 {{ $filterStatus === $kode ? 'ring-2 ring-offset-1 ring-red-400' : '' }}">
                                {{ $nama }}: {{ $ringkasan[$kode] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- TABLE --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-t-2xl">
                    <table class="ds-table">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>ID</th>
                                <th>Nama</th>
                                <th class="ds-c">Jadwal</th>
                                <th>Shift</th>
                                <th>Masuk</th>
                                <th>Pulang</th>
                                <th>Status</th>
                                <th>Keterangan</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                <tr wire:key="rekap-{{ $row->emp_id }}">
                                    <td class="ds-td-token">{{ $row->emp_id }}</td>
                                    <td class="ds-td-strong">{{ $row->emp_name }}</td>
                                    <td class="ds-c">
                                        @php $kodeJadwal = $row->jdw_jenis === 'K' ? $row->jadwal_kode : $row->jdw_jenis; @endphp
                                        @if ($kodeJadwal)
                                            <span class="inline-block w-7 py-0.5 text-xs font-bold rounded" style="{{ \App\Support\Jadwal::gaya($kodeJadwal, $row->jdw_jenis === 'K' ? $row->jadwal_warna : null) }}"
                                                title="{{ $row->jadwal_shift ?? (\App\Support\Jadwal::KHUSUS[$kodeJadwal] ?? '') }}">{{ $kodeJadwal }}</span>
                                        @else
                                            <span class="text-[11px] text-muted-soft">libur</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $row->shift_name ?? '—' }}
                                        @if ($row->dobel_shift_name)
                                            <div class="text-xs">+ {{ $row->dobel_shift_name }} <x-badge variant="purple">dobel shift</x-badge></div>
                                        @endif
                                    </td>
                                    <td class="font-mono">
                                        {{ $row->jam_masuk ? \Carbon\Carbon::parse($row->jam_masuk)->format('H:i') : '—' }}
                                        @if ($row->abs_id && \App\Support\Absensi::lupaAbsenMasuk($row))
                                            <div class="mt-0.5 font-sans"><x-badge variant="danger">lupa absen masuk</x-badge></div>
                                        @endif
                                        @if ((int) $row->terlambat_menit > 0)
                                            <div class="text-[11px] text-amber-700">+{{ \App\Support\Absensi::labelTerlambat((int) $row->terlambat_menit) }}</div>
                                        @endif
                                        @if ($row->masuk_foto)
                                            <a href="{{ route('absensi.foto', [$row->abs_id, 'masuk']) }}" target="_blank" rel="noopener"
                                                class="block font-sans text-[11px] underline text-brand-green">📷 selfie · {{ \App\Support\LokasiAbsen::labelJarak((int) $row->masuk_jarak_meter) }}</a>
                                        @endif
                                    </td>
                                    <td class="font-mono">
                                        @if ($row->jam_pulang)
                                            {{ \Carbon\Carbon::parse($row->jam_pulang)->format('H:i') }}
                                            @unless (\Carbon\Carbon::parse($row->jam_pulang)->isSameDay(\Carbon\Carbon::parse($row->jam_masuk ?? $row->abs_date)))
                                                <span class="text-[10px] text-muted-soft">(+1 hari)</span>
                                            @endunless
                                            @if ((int) $row->pulang_cepat_menit > 0)
                                                <div class="font-sans text-[11px] text-red-700">pulang cepat {{ \App\Support\Absensi::labelMenit((int) $row->pulang_cepat_menit) }}</div>
                                            @endif
                                            @if ((int) $row->lembur_menit > 0)
                                                @php $statusLembur = $row->lembur_status ?: 'P'; @endphp
                                                <div class="mt-1 p-1.5 rounded-lg border {{ $statusLembur === 'P' ? 'border-amber-300 bg-amber-50' : 'border-hairline' }} font-sans">
                                                    <div class="flex items-center gap-1.5">
                                                        <span class="text-[11px] font-semibold text-indigo-700">Lembur {{ \App\Support\Absensi::labelMenit((int) $row->lembur_menit) }}</span>
                                                        <x-badge :variant="\App\Support\Absensi::LEMBUR_BADGE[$statusLembur]">{{ \App\Support\Absensi::LEMBUR_STATUS[$statusLembur] }}</x-badge>
                                                    </div>
                                                    @if ($statusLembur !== 'P' && $row->lembur_penilai)
                                                        <div class="mt-0.5 text-[10px] text-muted-soft">
                                                            oleh {{ $row->lembur_penilai }}{{ $row->lembur_dinilai_at ? ', ' . \App\Support\Format::waktu($row->lembur_dinilai_at) : '' }}
                                                        </div>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($row->pulang_foto)
                                                <a href="{{ route('absensi.foto', [$row->abs_id, 'pulang']) }}" target="_blank" rel="noopener"
                                                    class="block font-sans text-[11px] underline text-brand-green">📷 selfie · {{ \App\Support\LokasiAbsen::labelJarak((int) $row->pulang_jarak_meter) }}</a>
                                            @endif
                                        @elseif ($row->jam_masuk && \App\Support\Absensi::tanpaAbsenPulang($row))
                                            <span class="font-sans"><x-badge variant="danger">tanpa absen pulang</x-badge></span>
                                        @elseif ($row->jam_masuk)
                                            <span class="text-xs text-muted-soft">belum pulang</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row->abs_id)
                                            <x-badge :variant="\App\Support\Absensi::BADGE[$row->abs_status] ?? 'gray'">{{ \App\Support\Absensi::STATUS[$row->abs_status] ?? $row->abs_status }}</x-badge>
                                            @if ($row->sumber === 'MANUAL')
                                                <span class="ml-1 text-[10px] text-muted-soft">manual</span>
                                            @elseif ($row->sumber === 'MOBILE')
                                                <span class="ml-1 text-[10px] text-muted-soft">HP</span>
                                            @endif
                                        @else
                                            <span class="text-xs italic text-muted-soft">Belum absen</span>
                                        @endif
                                    </td>
                                    <td class="text-sm text-muted">{{ $row->keterangan }}</td>
                                    <td class="ds-c">
                                        <x-secondary-button type="button" wire:click="openInput('{{ $row->emp_id }}')" class="!px-2.5 !py-1 text-xs">
                                            {{ $row->abs_id ? 'Koreksi' : 'Isi' }}
                                        </x-secondary-button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-10 text-center text-muted dark:text-gray-400">
                                        Tidak ada data untuk filter ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::absensi.rekap-harian.rekap-harian-actions wire:key="rekap-harian-actions" />
            <livewire:pages::absensi.rekap-harian.rekap-harian-generate-actions wire:key="rekap-harian-generate-actions" />

        </div>
    </div>
</div>
