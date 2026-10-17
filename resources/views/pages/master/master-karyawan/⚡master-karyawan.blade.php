<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    public string $searchKeyword = '';
    public string $filterUnit    = ''; // '' = semua unit, '-' = belum ada unit
    public string $filterGaji    = ''; // '' | ATUR | BELUM — hanya untuk role gaji.kelola
    public int    $itemsPerPage  = 10;

    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedFilterUnit(): void    { $this->resetPage(); }
    public function updatedFilterGaji(): void    { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset(['searchKeyword', 'filterUnit', 'filterGaji']);
        $this->itemsPerPage = 10;
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->dispatch('master.karyawan.openCreate');
    }

    public function openEdit(string $empId): void
    {
        $this->dispatch('master.karyawan.openEdit', empId: $empId);
    }

    public function requestDelete(string $empId): void
    {
        $this->dispatch('master.karyawan.requestDelete', empId: $empId);
    }

    // Toggle aktif/non-aktif langsung dari tabel (pola master Level 2).
    public function toggleActive(string $empId): void
    {
        $current  = (string) DB::table('siapmst_employers')->where('emp_id', $empId)->value('active_status');
        $newValue = $current === '1' ? '0' : '1';

        DB::table('siapmst_employers')
            ->where('emp_id', $empId)
            ->update(['active_status' => $newValue, 'updated_at' => now()]);

        $this->dispatch(
            'toast',
            type: 'success',
            message: $newValue === '1' ? 'Karyawan diaktifkan.' : 'Karyawan dinon-aktifkan.',
        );
    }

    /** Parameter gaji (rahasia) — hanya role gaji.kelola; tombol & modalnya juga hanya dirender untuk role itu. */
    public function openGaji(string $empId): void
    {
        abort_unless($this->bolehGaji, 403);
        $this->dispatch('keuangan.parameter-gaji.openEdit', empId: $empId);
    }

    #[On('keuangan.parameter-gaji.saved')]
    public function refreshAfterGajiSaved(): void {}

    #[Computed]
    public function bolehGaji(): bool
    {
        return (bool) auth()->user()?->can('gaji.kelola');
    }

    public function openStrSip(string $empId): void
    {
        $this->dispatch('master.karyawan.openStrSip', empId: $empId);
    }

    #[On('master.karyawan.saved')]
    public function refreshAfterSaved(): void
    {
        $this->resetPage();
    }

    // STR/SIP berubah → cukup render ulang (status badge), halaman tetap.
    #[On('master.karyawan.strSipSaved')]
    public function refreshAfterStrSipSaved(): void {}

    #[Computed]
    public function unitList()
    {
        return DB::table('siapmst_unit_kerjas')->orderBy('unit_name')->get(['unit_id', 'unit_name', 'active_status']);
    }

    #[Computed]
    public function rows()
    {
        $query = DB::table('siapmst_employers')
            ->leftJoin('siapmst_shifts as sd', 'sd.shift_id', '=', 'siapmst_employers.emp_shift_default')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'siapmst_employers.emp_unit_id')
            ->select('emp_id', 'emp_name', 'emp_sex', 'emp_phone', 'emp_join_date', 'emp_is_nakes', 'siapmst_employers.active_status',
                'sd.shift_kode as shift_default_kode', 'sd.shift_name as shift_default_name', 'sd.shift_warna as shift_default_warna', 'un.unit_name')
            ->when($this->filterUnit === '-', fn($subQuery) => $subQuery->whereNull('siapmst_employers.emp_unit_id'))
            ->when(!in_array($this->filterUnit, ['', '-'], true), fn($subQuery) => $subQuery->where('siapmst_employers.emp_unit_id', $this->filterUnit))
            ->orderBy('emp_id');

        if ($this->bolehGaji) {
            $punyaGaji = fn($subQuery) => $subQuery->select('emp_id')->from('siapmst_gaji_karyawans');
            $query->addSelect(DB::raw('(SELECT COUNT(*) FROM siapmst_gaji_karyawans gk WHERE gk.emp_id = siapmst_employers.emp_id) AS punya_gaji'))
                ->when($this->filterGaji === 'ATUR', fn($subQuery) => $subQuery->whereIn('emp_id', $punyaGaji))
                ->when($this->filterGaji === 'BELUM', fn($subQuery) => $subQuery->whereNotIn('emp_id', $punyaGaji));
        }

        if (trim($this->searchKeyword) !== '') {
            $keyword = mb_strtoupper(trim($this->searchKeyword));
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->whereRaw('UPPER(emp_id) LIKE ?', ["%{$keyword}%"])
                    ->orWhereRaw('UPPER(emp_name) LIKE ?', ["%{$keyword}%"])
                    ->orWhereRaw('UPPER(emp_phone) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('UPPER(un.unit_name) LIKE ?', ["%{$keyword}%"]);
            });
        }

        $rows = $query->paginate($this->itemsPerPage);

        // Ringkasan STR/SIP yang berlaku untuk karyawan di halaman ini — satu query.
        $lisensi = DB::table('siapmst_emp_licenses')
            ->whereIn('emp_id', $rows->pluck('emp_id')->all() ?: [''])
            ->where('active_status', '1')
            ->groupBy('emp_id')
            ->select(
                'emp_id',
                DB::raw("SUM(CASE WHEN lic_type = 'STR' THEN 1 ELSE 0 END) AS jml_str"),
                DB::raw("SUM(CASE WHEN lic_type = 'SIP' THEN 1 ELSE 0 END) AS jml_sip"),
                DB::raw("MIN(CASE WHEN lic_type = 'SIP' THEN lic_expired_date END) AS sip_berakhir"),
            )
            ->get()
            ->keyBy('emp_id');

        foreach ($rows as $row) {
            $row->str_sip = $this->statusStrSip($row, $lisensi[$row->emp_id] ?? null);
        }

        return $rows;
    }

    /**
     * Status STR/SIP untuk badge daftar: null (bukan nakes) atau [label, variant].
     * SIP yang dipantau = yang paling cepat berakhir di antara SIP berlaku.
     */
    private function statusStrSip(object $row, ?object $lisensi): ?array
    {
        if ((string) $row->emp_is_nakes !== '1') {
            return null;
        }
        if (!$lisensi || (int) $lisensi->jml_sip === 0) {
            return ['Belum ada SIP', 'gray'];
        }

        $sisaHari = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($lisensi->sip_berakhir)->startOfDay(), false);

        return match (true) {
            $sisaHari < 0   => ['SIP kedaluwarsa', 'danger'],
            $sisaHari <= 90 => ["SIP habis {$sisaHari} hari", 'warning'],
            (int) $lisensi->jml_str === 0 => ['STR belum ada', 'gray'],
            default         => ['STR/SIP aman', 'success'],
        };
    }
};
?>

<div>

    <x-page-title
        title="Master Karyawan"
        subtitle="Kelola data karyawan SIAP — dasar absensi, kepegawaian & STR/SIP nakes" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">

                    {{-- SEARCH + FILTER UNIT (satu kelompok di kiri) --}}
                    <div class="flex flex-col gap-2 lg:flex-row">
                        <div class="w-full lg:w-72">
                            <x-input-label for="searchKeyword" value="Cari Karyawan" class="sr-only" />
                            <x-text-input id="searchKeyword" type="text"
                                wire:model.live.debounce.300ms="searchKeyword"
                                placeholder="Cari ID / nama / no. HP..."
                                class="block w-full" />
                        </div>
                        {{-- Lebar muat nama unit terpanjang ("Pendaftaran & Rekam Medis") --}}
                        <div class="w-full lg:w-64 shrink-0">
                            <x-input-label for="filterUnit" value="Unit Kerja" class="sr-only" />
                            <x-select-input id="filterUnit" wire:model.live="filterUnit">
                                <option value="">Semua unit</option>
                                @foreach ($this->unitList as $unit)
                                    <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}{{ (string) $unit->active_status === '1' ? '' : ' (non-aktif)' }}</option>
                                @endforeach
                                <option value="-">— Belum ada unit —</option>
                            </x-select-input>
                        </div>
                        @if ($this->bolehGaji)
                            <div class="w-full lg:w-64 shrink-0">
                                <x-select-input wire:model.live="filterGaji">
                                    <option value="">Parameter gaji: semua</option>
                                    <option value="ATUR">Gaji sudah diatur</option>
                                    <option value="BELUM">Gaji belum diatur</option>
                                </x-select-input>
                            </div>
                        @endif
                    </div>

                    {{-- RIGHT ACTIONS --}}
                    <div class="flex items-center justify-end gap-2">
                        <div class="w-28">
                            <x-input-label for="itemsPerPage" value="Per halaman" class="sr-only" />
                            <x-select-input id="itemsPerPage" wire:model.live="itemsPerPage">
                                <option value="5">5</option>
                                <option value="10">10</option>
                                <option value="15">15</option>
                                <option value="20">20</option>
                                <option value="100">100</option>
                            </x-select-input>
                        </div>
                        <x-primary-button type="button" wire:click="openCreate">
                            + Tambah Karyawan Baru
                        </x-primary-button>
                        <x-toolbar-refresh-reset :label="null" />
                    </div>
                </div>
            </div>

            {{-- TABLE WRAPPER --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">

                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-t-2xl">
                    <table class="ds-table">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th>Karyawan</th>
                                <th>Kontak</th>
                                <th title="Status aktif & STR/SIP">Status</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->rows as $row)
                                @php $isActive = (string) $row->active_status === '1'; @endphp
                                <tr wire:key="karyawan-{{ $row->emp_id }}">
                                    {{-- Karyawan = nama (+ shift default) / ID · L/P · unit --}}
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <span class="ds-td-strong">{{ $row->emp_name }}</span>
                                            @if ($row->shift_default_kode)
                                                <span class="inline-block w-7 py-0.5 text-xs font-bold text-center rounded" style="{{ \App\Support\Jadwal::gaya($row->shift_default_kode, $row->shift_default_warna) }}" title="Shift default: {{ $row->shift_default_name }}">{{ $row->shift_default_kode }}</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-muted">
                                            <span class="font-mono">{{ $row->emp_id }}</span>{{ $row->emp_sex ? ' · ' . $row->emp_sex : '' }}{{ $row->unit_name ? ' · ' . $row->unit_name : '' }}
                                        </div>
                                    </td>
                                    {{-- Kontak = No. HP / tanggal masuk --}}
                                    <td>
                                        <div class="font-mono">{{ $row->emp_phone ?: '—' }}</div>
                                        @if ($row->emp_join_date)
                                            <div class="text-xs text-muted">Masuk {{ \App\Support\Format::tanggal($row->emp_join_date) }}</div>
                                        @endif
                                    </td>
                                    {{-- Status = toggle aktif / STR-SIP --}}
                                    <td>
                                        {{-- wire:key sertakan nilai aktif supaya Alpine re-init saat status berubah --}}
                                        <x-toggle wire:key="toggle-active-{{ $row->emp_id }}-{{ $isActive ? 1 : 0 }}"
                                            :current="$isActive ? '1' : '0'" trueValue="1" falseValue="0"
                                            wireClick="toggleActive('{{ $row->emp_id }}')">
                                            {{ $isActive ? 'Aktif' : 'Non-aktif' }}
                                        </x-toggle>
                                        <div class="mt-1">
                                        @if ($row->str_sip)
                                            {{-- Klik badge = buka STR/SIP karyawan ini --}}
                                            <button type="button" wire:click="openStrSip('{{ $row->emp_id }}')" title="Kelola STR/SIP" wire:loading.attr="disabled" wire:target="openStrSip('{{ $row->emp_id }}')">
                                                <x-badge :variant="$row->str_sip[1]">{{ $row->str_sip[0] }}</x-badge>
                                            <x-loading wire:loading.delay wire:target="openStrSip('{{ $row->emp_id }}')" class="ml-1 align-middle" /></button>
                                        @else
                                            <span class="text-xs text-muted-soft">Bukan nakes</span>
                                        @endif
                                        </div>
                                    </td>
                                    <td class="ds-c">
                                        {{-- Urutan tetap: STR/SIP · Gaji · Edit · Hapus. Ukuran sama dengan x-action-edit;
                                             baris non-nakes memakai slot kosong selebar STR/SIP supaya kolom tombol tetap lurus. --}}
                                        <div class="flex items-center justify-end gap-2 flex-nowrap whitespace-nowrap">
                                            @if ((string) $row->emp_is_nakes === '1')
                                                <x-secondary-button type="button" wire:click="openStrSip('{{ $row->emp_id }}')" class="px-2.5 py-1.5 text-sm" title="Kelola STR / SIP">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                    </svg>
                                                    STR/SIP
                                                </x-secondary-button>
                                            @else
                                                <span aria-hidden="true" style="visibility:hidden">
                                                    <x-secondary-button type="button" class="px-2.5 py-1.5 text-sm" tabindex="-1">
                                                        <svg class="w-4 h-4" viewBox="0 0 24 24"></svg>
                                                        STR/SIP
                                                    </x-secondary-button>
                                                </span>
                                            @endif
                                            @if ($this->bolehGaji)
                                                @php $punyaGaji = (int) $row->punya_gaji > 0; @endphp
                                                <x-secondary-button type="button" wire:click="openGaji('{{ $row->emp_id }}')" class="px-2.5 py-1.5 text-sm"
                                                    title="{{ $punyaGaji ? 'Parameter gaji' : 'Parameter gaji belum diatur — tidak ikut generate slip' }}">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                                    </svg>
                                                    Gaji
                                                    @unless ($punyaGaji)
                                                        <span class="w-2 h-2 rounded-full bg-amber-500" aria-label="belum diatur"></span>
                                                    @endunless
                                                </x-secondary-button>
                                            @endif
                                            <x-action-edit wire:click="openEdit('{{ $row->emp_id }}')" />
                                            <x-action-delete :action="'requestDelete(\'' . $row->emp_id . '\')'"
                                                title="Hapus Karyawan"
                                                message="Yakin hapus karyawan {{ $row->emp_name }} ({{ $row->emp_id }})?" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10">
                                        <div class="flex flex-col items-center justify-center gap-3">
                                            <svg class="w-12 h-12 text-muted-soft" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" /></svg>
                                            <p class="text-base font-medium text-muted dark:text-gray-400">Data karyawan tidak ditemukan.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- PAGINATION --}}
                <div class="sticky bottom-0 z-10 px-4 py-3 bg-canvas border-t border-hairline rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

            <livewire:pages::master.master-karyawan.master-karyawan-actions wire:key="master-karyawan-actions" />
            <livewire:pages::master.master-karyawan.str-sip.master-str-sip-actions wire:key="master-str-sip-actions" />
            @if ($this->bolehGaji)
                <livewire:pages::keuangan.parameter-gaji.parameter-gaji-actions wire:key="parameter-gaji-actions" />
            @endif

        </div>
    </div>
</div>
