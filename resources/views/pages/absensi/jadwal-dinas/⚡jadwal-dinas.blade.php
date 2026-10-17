<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Support\Format;
use App\Support\Jadwal;
use App\Support\UnitKerja;

/**
 * JADWAL DINAS BULANAN — grid karyawan × tanggal seperti "Jadwal Masuk Kerja Bulan …" lapangan.
 * Pilih kuas (kode shift / I / C / Libur) lalu klik sel. Kosong = libur/off, Minggu ditandai merah.
 * Tanggal yang sudah lewat TERKUNCI (Jadwal::terkunci) — semua aksi hanya mengubah hari ini & seterusnya.
 * Jadwal dipakai kiosk (shift otomatis), Generate Libur/Alpa, dan Rekap Harian.
 *
 * Unit kerja (UnitKerja::cakupanUser):
 *   - admin / HRD   → pilih unit; kuas = shift unit terpilih. "Semua unit" = HANYA LIHAT & CETAK
 *                     (kuas tiap unit berbeda — P Gizi 05:00 ≠ P IGD 07:00, M hanya ada di unit tertentu).
 *   - role lain     → DIKUNCI ke unit karyawan yang terhubung ke akunnya (mis. kepala ruang).
 * Semua aksi hanya menyentuh karyawan di $this->karyawan (sudah tersaring unit), dan kode yang
 * diisikan divalidasi terhadap shift unit karyawan masing-masing.
 */
new class extends Component {
    public string $bulan = '';      // mm/yyyy
    public string $kuas  = 'P';     // kode shift / I / C / '' (libur)
    public string $searchKeyword = '';
    #[Url(as: 'unit')]
    public string $filterUnit = ''; // admin/HRD: '' = semua unit (lihat & cetak saja)

    private string $printView = 'pages.absensi.jadwal-dinas.jadwal-dinas-print';

    /** Cache shift berkode per unit dalam satu request. */
    private array $shiftPerUnit = [];

    public function mount(): void
    {
        $this->bulan = now()->format(Format::BULAN);
        // Admin / HRD langsung masuk ke satu unit (mengisi jadwal wajib per unit).
        if ($this->cakupan['semuaUnit'] && $this->filterUnit === '') {
            $this->filterUnit = (string) ($this->unitList->first()->unit_id ?? '');
        }
        $this->kuas = (string) ($this->shifts->keys()->first() ?? 'I');
    }

    public function updatedFilterUnit(): void
    {
        unset($this->shifts, $this->karyawan, $this->jadwal);
        $this->kuas = (string) ($this->shifts->keys()->first() ?? 'I');
    }

    public function awalBulan(): Carbon
    {
        try {
            return Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
    }

    // ─── Cakupan unit ─────────────────────────────────────────────────────────
    #[Computed]
    public function cakupan(): array
    {
        return UnitKerja::cakupanUser(auth()->user());
    }

    /** Unit yang sedang ditampilkan (null = semua unit). Role terbatas selalu dikunci ke unitnya. */
    public function unitAktif(): ?string
    {
        if (!$this->cakupan['semuaUnit']) {
            return $this->cakupan['unitId'];
        }

        return $this->filterUnit !== '' ? $this->filterUnit : null;
    }

    /** Jadwal hanya bisa DIISI per unit — mode semua unit = lihat & cetak. */
    public function bisaIsi(): bool
    {
        return $this->unitAktif() !== null;
    }

    private function tolakSemuaUnit(): bool
    {
        if ($this->bisaIsi()) {
            return false;
        }
        $this->dispatch('toast', type: 'error', message: 'Pilih unit dulu — jadwal diisi per unit (kuas tiap unit berbeda).');

        return true;
    }

    public function bolehAkses(): bool
    {
        return $this->cakupan['semuaUnit'] || $this->cakupan['unitId'] !== null;
    }

    #[Computed]
    public function unitList()
    {
        return UnitKerja::aktif();
    }

    #[Computed]
    public function namaUnitAktif(): ?string
    {
        return $this->unitAktif() ? DB::table('siapmst_unit_kerjas')->where('unit_id', $this->unitAktif())->value('unit_name') : null;
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    /** Kuas = shift khusus unit aktif, atau shift umum bila unit belum punya shift khusus / semua unit. */
    #[Computed]
    public function shifts()
    {
        return Jadwal::shiftBerkode($this->unitAktif());
    }

    /** Shift berkode untuk unit karyawan tertentu — validasi kode per karyawan. */
    private function shiftUnit(?string $unitId)
    {
        return $this->shiftPerUnit[$unitId ?? ''] ??= Jadwal::shiftBerkode($unitId);
    }

    #[Computed]
    public function karyawan()
    {
        if (!$this->bolehAkses()) {
            return collect();
        }

        // Dikelompokkan per unit (tanpa unit paling bawah), lalu urut ID → urutan baris
        // dalam unit bisa diatur lewat ID karyawan (sama dgn formulir jadwal lapangan).
        $query = DB::table('siapmst_employers as em')
            ->leftJoin('siapmst_shifts as sd', 'sd.shift_id', '=', 'em.emp_shift_default')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'em.emp_unit_id')
            ->where('em.active_status', '1')
            ->when($this->unitAktif(), fn($subQuery, $unitId) => $subQuery->where('em.emp_unit_id', $unitId))
            ->orderByRaw('un.unit_name NULLS LAST')
            ->orderBy('em.emp_id');

        if (trim($this->searchKeyword) !== '') {
            $keyword = mb_strtoupper(trim($this->searchKeyword));
            $query->where(fn($subQuery) => $subQuery->whereRaw('UPPER(em.emp_name) LIKE ?', ["%{$keyword}%"])->orWhereRaw('UPPER(em.emp_id) LIKE ?', ["%{$keyword}%"]));
        }

        return $query->get(['em.emp_id', 'em.emp_name', 'em.emp_unit_id', 'un.unit_name', 'sd.shift_kode as shift_default_kode', 'sd.shift_warna as shift_default_warna']);
    }

    #[Computed]
    public function jadwal(): array
    {
        return Jadwal::bulan($this->karyawan->pluck('emp_id')->all(), $this->awalBulan());
    }

    private function kuasValid(): bool
    {
        return $this->kuas === '' || isset(Jadwal::KHUSUS[$this->kuas]) || $this->shifts->has($this->kuas);
    }

    // ─── Isi sel ──────────────────────────────────────────────────────────────
    public function isiSel(string $empId, int $hari): void
    {
        if ($this->tolakSemuaUnit()) {
            return;
        }
        $awal = $this->awalBulan();
        $karyawan = $this->karyawan->firstWhere('emp_id', $empId);
        if (!$this->kuasValid() || $hari < 1 || $hari > $awal->daysInMonth || !$karyawan) {
            return;
        }

        if (Jadwal::terkunci($awal->copy()->day($hari))) {
            $this->dispatch('toast', type: 'error', message: 'Jadwal tanggal yang sudah lewat terkunci — tidak bisa diubah.');
            return;
        }

        // Klik sel yang isinya sama dengan kuas = hapus (toggle), memudahkan koreksi.
        $kodeLama = isset($this->jadwal[$empId][$hari]) ? Jadwal::kode($this->jadwal[$empId][$hari]) : '';
        $kode = $kodeLama === $this->kuas ? '' : $this->kuas;

        if (!Jadwal::isi($empId, $awal->copy()->day($hari), $kode, auth()->id(), $this->shiftUnit($karyawan->emp_unit_id))) {
            $this->dispatch('toast', type: 'error', message: "Kode {$kode} bukan shift unit {$karyawan->emp_name}.");
            return;
        }
        unset($this->jadwal);
    }

    /**
     * Isi Senin–Sabtu satu karyawan dengan SHIFT DEFAULT-nya (kolom P/S);
     * bila belum punya shift default → pakai kuas terpilih. Minggu dibiarkan libur.
     */
    public function isiHariKerja(string $empId): void
    {
        if ($this->tolakSemuaUnit()) {
            return;
        }
        $karyawan = $this->karyawan->firstWhere('emp_id', $empId);
        if (!$karyawan) {
            return;
        }
        $shiftKaryawan = $this->shiftUnit($karyawan->emp_unit_id);
        $kode = $karyawan->shift_default_kode ?: $this->kuas;
        if ($kode === '' || (!isset(Jadwal::KHUSUS[$kode]) && !$shiftKaryawan->has($kode))) {
            $this->dispatch('toast', type: 'error', message: "Kode {$kode} bukan shift unit {$karyawan->emp_name}.");
            return;
        }

        $awal = $this->awalBulan();
        $jumlah = 0;
        for ($tanggal = $awal->copy(); $tanggal->lte($awal->copy()->endOfMonth()); $tanggal->addDay()) {
            if (!$tanggal->isSunday() && !Jadwal::terkunci($tanggal)) {
                Jadwal::isi($empId, $tanggal->copy(), $kode, auth()->id(), $shiftKaryawan);
                $jumlah++;
            }
        }
        if ($jumlah === 0) {
            $this->dispatch('toast', type: 'error', message: 'Semua tanggal bulan ini sudah lewat — jadwal terkunci.');
        }
        unset($this->jadwal);
    }

    /**
     * Salin dari bulan lalu dengan HARI YANG SAMA (Senin→Senin, Minggu→Minggu):
     * sumber = tanggal tujuan − 4 minggu (atau − 5 minggu bila masih di bulan yang sama).
     * Hanya mengisi sel yang masih kosong.
     */
    public function salinBulanLalu(): void
    {
        if ($this->tolakSemuaUnit()) {
            return;
        }
        $awal = $this->awalBulan();
        $bulanLalu = $awal->copy()->subMonthNoOverflow();
        $jadwalLalu = Jadwal::bulan($this->karyawan->pluck('emp_id')->all(), $bulanLalu);
        $jumlah = 0;

        foreach ($this->karyawan as $karyawan) {
            for ($tanggal = $awal->copy(); $tanggal->lte($awal->copy()->endOfMonth()); $tanggal->addDay()) {
                if (isset($this->jadwal[$karyawan->emp_id][$tanggal->day]) || Jadwal::terkunci($tanggal)) {
                    continue;
                }
                $sumber = $tanggal->copy()->subWeeks(4);
                if ($sumber->isSameMonth($awal)) {
                    $sumber->subWeek();
                }
                $row = $jadwalLalu[$karyawan->emp_id][$sumber->day] ?? null;
                if ($row && $sumber->isSameMonth($bulanLalu)
                    && Jadwal::isi($karyawan->emp_id, $tanggal->copy(), Jadwal::kode($row), auth()->id(), $this->shiftUnit($karyawan->emp_unit_id))) {
                    $jumlah++;
                }
            }
        }

        $this->dispatch('toast', type: $jumlah ? 'success' : 'info',
            message: $jumlah ? "{$jumlah} sel disalin dari bulan lalu (hari yang sama; sel yang sudah terisi tidak diubah)." : 'Tidak ada yang disalin — bulan lalu kosong atau semua sel sudah terisi.');
        unset($this->jadwal);
    }

    /** Kosongkan jadwal bulan ini mulai HARI INI (tanggal yang sudah lewat terkunci). */
    public function kosongkanBulan(): void
    {
        if ($this->tolakSemuaUnit()) {
            return;
        }
        $awal = $this->awalBulan();
        $dari = $awal->copy()->max(now()->startOfDay());
        if ($dari->gt($awal->copy()->endOfMonth())) {
            $this->dispatch('toast', type: 'error', message: 'Semua tanggal bulan ini sudah lewat — jadwal terkunci.');
            return;
        }

        $jumlahSel = DB::table('siaptxn_jadwals')
            ->whereIn('emp_id', $this->karyawan->pluck('emp_id')->all() ?: [''])
            ->where('jdw_tanggal', '>=', Absensi::tanggalSql($dari))
            ->where('jdw_tanggal', '<=', Absensi::tanggalSql($awal->copy()->endOfMonth()))
            ->delete();

        $this->dispatch('toast', type: 'success', message: "Jadwal {$this->bulan} mulai " . Format::tanggal($dari) . " dikosongkan ({$jumlahSel} sel).");
        unset($this->jadwal);
    }

    public function cetak()
    {
        $awal = $this->awalBulan();
        $pdf = Pdf::loadView($this->printView, [
            'awal'      => $awal,
            'karyawan'  => $this->karyawan,
            'jadwal'    => $this->jadwal,
            'shifts'    => $this->shifts,
            'namaUnit'  => $this->namaUnitAktif,
            'dicetak'   => now(),
            'oleh'      => auth()->user()?->name,
        ])->setPaper('A4', 'landscape');

        $namaBerkas = 'jadwal-dinas-' . ($this->unitAktif() ? mb_strtolower($this->unitAktif()) . '-' : '') . $awal->format('Y-m') . '.pdf';

        return response()->streamDownload(fn() => print $pdf->output(), $namaBerkas);
    }
};
?>

<div>

    <x-page-title
        title="Jadwal Dinas"
        subtitle="Jadwal masuk kerja bulanan per unit — dasar shift di kiosk, Generate Libur/Alpa, dan rekap" />

    @php
        $awal = $this->awalBulan();
        $jumlahHari = $awal->daysInMonth;
        $jadwal = $this->jadwal;
    @endphp

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">

            @if (!$this->bolehAkses())
                <div class="max-w-2xl p-6 mt-6 border rounded-2xl border-warning-subtle bg-warning-soft text-fg-warning" role="alert">
                    <p class="font-semibold">Akun Anda belum terhubung ke unit kerja.</p>
                    <p class="mt-1 text-sm">Jadwal hanya bisa diatur untuk unit Anda sendiri. Minta admin menghubungkan akun ini ke data karyawan
                        (Sistem › User Control) dan mengisi <b>Unit Kerja</b> karyawan tersebut (Master Karyawan).</p>
                </div>
            @else

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex items-center gap-1">
                            <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-2.5" title="Bulan sebelumnya" aria-label="Bulan sebelumnya">‹</x-secondary-button>
                            <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" aria-label="Bulan (mm/yyyy)" class="w-28 font-mono text-center" />
                            <x-secondary-button type="button" wire:click="geserBulan(1)" class="!px-2.5" title="Bulan berikutnya" aria-label="Bulan berikutnya">›</x-secondary-button>
                        </div>
                        {{-- UNIT --}}
                        @if ($this->cakupan['semuaUnit'])
                            <div class="w-72">
                                <x-select-input wire:model.live="filterUnit" aria-label="Filter unit">
                                    <option value="">Semua unit (lihat &amp; cetak)</option>
                                    @foreach ($this->unitList as $unit)
                                        <option value="{{ $unit->unit_id }}">{{ $unit->unit_name }}</option>
                                    @endforeach
                                </x-select-input>
                            </div>
                        @else
                            <div class="px-3 py-2 text-sm font-semibold border rounded-lg bg-canvas border-hairline text-ink">
                                Unit: {{ $this->namaUnitAktif }}
                            </div>
                        @endif
                        <div class="w-56">
                            <x-text-input type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="Cari karyawan..." aria-label="Cari karyawan" class="block w-full" />
                        </div>
                        {{-- KUAS — hanya saat satu unit dipilih --}}
                        @if ($this->bisaIsi())
                        <div class="flex flex-wrap items-center gap-1 px-2 py-1 border rounded-xl bg-canvas border-hairline dark:bg-gray-800 dark:border-gray-700" role="group" aria-label="Kuas jadwal">
                            <span class="mr-1 text-xs font-semibold text-muted">Kuas:</span>
                            @foreach ($this->shifts as $kode => $shift)
                                <button type="button" wire:click="$set('kuas', '{{ $kode }}')" title="{{ $shift->shift_name }} {{ $shift->jam_masuk }}–{{ $shift->jam_pulang }}"
                                    style="{{ \App\Support\Jadwal::gaya($kode, $shift->shift_warna) }}"
                                    aria-pressed="{{ $kuas === $kode ? 'true' : 'false' }}"
                                    class="w-9 h-9 text-sm font-bold rounded-lg border border-black/10 {{ $kuas === $kode ? 'ring-4 ring-offset-1 ring-brand dark:ring-offset-gray-900' : 'opacity-70 hover:opacity-100' }}">{{ $kode }}</button>
                            @endforeach
                            @foreach (\App\Support\Jadwal::KHUSUS as $kode => $nama)
                                <button type="button" wire:click="$set('kuas', '{{ $kode }}')" title="{{ $nama }}"
                                    style="{{ \App\Support\Jadwal::gaya($kode) }}"
                                    aria-pressed="{{ $kuas === $kode ? 'true' : 'false' }}"
                                    class="w-9 h-9 text-sm font-bold rounded-lg border border-black/10 {{ $kuas === $kode ? 'ring-4 ring-offset-1 ring-brand dark:ring-offset-gray-900' : 'opacity-70 hover:opacity-100' }}">{{ $kode }}</button>
                            @endforeach
                            <button type="button" wire:click="$set('kuas', '')" title="Libur / hapus jadwal"
                                aria-pressed="{{ $kuas === '' ? 'true' : 'false' }}"
                                class="px-2 h-9 text-xs font-bold rounded-lg border border-default-medium bg-canvas text-heading dark:bg-gray-800 {{ $kuas === '' ? 'ring-4 ring-offset-1 ring-brand dark:ring-offset-gray-900' : 'opacity-70 hover:opacity-100' }}">Libur</button>
                        </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        @if ($this->bisaIsi())
                            <x-secondary-button type="button" wire:click="salinBulanLalu" wire:loading.attr="disabled" wire:target="salinBulanLalu">Salin bulan lalu</x-secondary-button>
                            <x-confirm-button variant="danger-soft" action="kosongkanBulan()" title="Kosongkan Jadwal"
                                message="Hapus jadwal {{ $bulan }} mulai hari ini untuk karyawan yang tampil? Tanggal yang sudah lewat tetap (terkunci)." confirmText="Ya, kosongkan" cancelText="Batal">
                                Kosongkan
                            </x-confirm-button>
                        @endif
                        <x-primary-button type="button" wire:click="cetak" wire:loading.attr="disabled" wire:target="cetak">
                            <span wire:loading.remove wire:target="cetak">Cetak PDF</span>
                            <span wire:loading wire:target="cetak">Menyiapkan...</span>
                        </x-primary-button>
                    </div>
                </div>
                @if ($this->bisaIsi())
                <p class="mt-2 text-xs text-muted dark:text-gray-400">
                    Pilih kuas lalu klik sel. Klik sel yang isinya sama dengan kuas = hapus. Sel <b>kosong = libur/off</b>.
                    Tanggal yang sudah lewat (ikon gembok) <b>terkunci</b> — tidak bisa diubah (menjaga keaslian data absensi).
                    @foreach ($this->shifts as $kode => $shift)
                        <b>{{ $kode }}</b> {{ $shift->shift_name }} ({{ $shift->jam_masuk }}–{{ $shift->jam_pulang }}){{ !$loop->last ? ' ·' : '' }}
                    @endforeach
                    · <b>I</b> Izin · <b>C</b> Cuti
                </p>
                @else
                    <p class="px-3 py-2 mt-2 text-xs border rounded-xl border-warning-subtle bg-warning-soft text-fg-warning">
                        Mode <b>semua unit</b> — hanya <b>lihat &amp; cetak</b>. Untuk mengisi jadwal, <b>pilih unit</b> dulu:
                        jam shift tiap unit berbeda (mis. P Gizi 05:00, P IGD 07:00) dan shift Malam hanya ada di unit tertentu.
                    </p>
                @endif
            </div>

            {{-- GRID --}}
            <div class="mt-4 overflow-x-auto border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <table class="text-sm border-collapse">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 px-3 py-2 text-xs font-semibold tracking-wide text-left uppercase border bg-surface-card text-heading border-hairline min-w-[14rem] dark:bg-gray-800 dark:border-gray-700">Nama Karyawan</th>
                            <th class="px-1 py-2 text-xs font-semibold text-center border bg-surface-card text-heading border-hairline dark:bg-gray-800 dark:border-gray-700" title="Shift default">P/S</th>
                            @for ($hari = 1; $hari <= $jumlahHari; $hari++)
                                @php $tanggal = $awal->copy()->day($hari); @endphp
                                {{-- Hari biasa: tint hijau brand · Minggu: tint merah + teks merah tua (tetap tertulis "Min") · hari ini: cincin brand --}}
                                <th class="w-9 px-0 py-1 text-xs font-bold text-center border border-hairline dark:border-gray-700 {{ $tanggal->isSunday() ? 'bg-danger-soft text-fg-danger-strong' : 'bg-surface-strong text-fg-brand-strong dark:bg-brand-softer' }} {{ $tanggal->isToday() ? 'ring-2 ring-inset ring-brand dark:ring-brand-lime' : '' }}"
                                    @if ($tanggal->isToday()) aria-current="date" @endif>
                                    {{ $hari }}
                                    <div class="text-[10px] font-medium">@if (\App\Support\Jadwal::terkunci($tanggal)){!! '<svg class="w-3 h-3 mx-auto" viewBox="0 0 20 20" fill="currentColor" aria-label="terkunci"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" /></svg>' !!}@else{{ mb_substr(\App\Support\Format::hari($tanggal), 0, 3) }}@endif</div>
                                </th>
                            @endfor
                            <th class="px-2 py-1 text-xs font-semibold border bg-surface-card text-heading border-hairline dark:bg-gray-800 dark:border-gray-700">Hari kerja</th>
                            <th class="px-2 py-1 text-xs font-semibold border bg-surface-card text-heading border-hairline dark:bg-gray-800 dark:border-gray-700">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->karyawan->groupBy(fn($row) => $row->unit_name ?? 'Belum ada unit') as $namaUnitKelompok => $karyawanUnit)
                            @unless ($this->unitAktif())
                                <tr wire:key="jdw-unit-{{ $loop->index }}">
                                    <td colspan="{{ $jumlahHari + 4 }}" class="p-0 border border-hairline bg-surface-card dark:bg-gray-800">
                                        <div class="sticky left-0 inline-flex items-center gap-2 px-3 py-1.5 text-xs font-bold tracking-wide uppercase text-ink dark:text-gray-100">
                                            {{ $namaUnitKelompok }}
                                            <span class="font-normal normal-case text-muted">{{ $karyawanUnit->count() }} karyawan</span>
                                        </div>
                                    </td>
                                </tr>
                            @endunless
                            @foreach ($karyawanUnit as $karyawan)
                            @php $jadwalKaryawan = $jadwal[$karyawan->emp_id] ?? []; @endphp
                            <tr wire:key="jdw-{{ $karyawan->emp_id }}">
                                <td class="sticky left-0 z-10 px-3 py-1 border bg-canvas border-hairline dark:bg-gray-900 dark:border-gray-700">
                                    <div class="font-semibold uppercase text-ink dark:text-gray-100">{{ $karyawan->emp_name }}</div>
                                    <div class="font-mono text-xs text-muted">{{ $karyawan->emp_id }}</div>
                                </td>
                                <td class="px-1 text-center border bg-canvas border-hairline dark:bg-gray-900 dark:border-gray-700">
                                    @if ($karyawan->shift_default_kode)
                                        <span class="inline-block w-7 py-0.5 text-xs font-bold rounded" style="{{ \App\Support\Jadwal::gaya($karyawan->shift_default_kode, $karyawan->shift_default_warna) }}">{{ $karyawan->shift_default_kode }}</span>
                                    @endif
                                </td>
                                @for ($hari = 1; $hari <= $jumlahHari; $hari++)
                                    @php
                                        $sel = $jadwalKaryawan[$hari] ?? null;
                                        $kode = $sel ? \App\Support\Jadwal::kode($sel) : '';
                                        $gayaSel = $kode ? \App\Support\Jadwal::gaya($kode, $sel->shift_warna ?? null) : '';
                                        $minggu = $awal->copy()->day($hari)->isSunday();
                                        $terkunci = \App\Support\Jadwal::terkunci($awal->copy()->day($hari));
                                    @endphp
                                    <td class="p-0 border border-hairline dark:border-gray-700 {{ !$sel && $minggu ? 'bg-danger-soft' : '' }}">
                                        @if ($terkunci || !$this->bisaIsi())
                                            {{-- Tanggal lewat (terkunci) / mode semua unit: hanya tampil --}}
                                            <div title="{{ \App\Support\Format::tanggal($awal->copy()->day($hari)) }}{{ $sel && $sel->shift_name ? ' · ' . $sel->shift_name : '' }}{{ $terkunci ? ' · terkunci (sudah lewat)' : ' · pilih unit untuk mengubah' }}" style="{{ $gayaSel }}"
                                                class="flex items-center justify-center w-9 h-9 text-sm font-bold {{ $terkunci ? 'cursor-not-allowed' : '' }} {{ $kode && $terkunci ? 'opacity-75' : '' }}">
                                                {{ $kode }}
                                            </div>
                                        @else
                                            <button type="button" wire:click="isiSel(@js($karyawan->emp_id), {{ $hari }})" style="{{ $gayaSel }}"
                                                title="{{ \App\Support\Format::tanggal($awal->copy()->day($hari)) }}{{ $sel && $sel->shift_name ? ' · ' . $sel->shift_name : ($kode ? ' · ' . (\App\Support\Jadwal::KHUSUS[$kode] ?? '') : ' · libur') }}"
                                                class="w-9 h-9 text-sm font-bold transition hover:ring-2 hover:ring-inset hover:ring-brand-green">
                                                {{ $kode }}
                                            </button>
                                        @endif
                                    </td>
                                @endfor
                                @php $hariKerja = collect($jadwalKaryawan)->where('jdw_jenis', 'K')->count(); @endphp
                                <td class="px-2 text-center border border-hairline dark:border-gray-700">
                                    <div class="font-mono font-semibold text-ink dark:text-gray-100">{{ $hariKerja }}</div>
                                    <div class="text-xs text-muted whitespace-nowrap">
                                        {{ collect($jadwalKaryawan)->map(fn($row) => \App\Support\Jadwal::kode($row))->countBy()->sortKeys()->map(fn($jumlah, $kodeJadwal) => "{$kodeJadwal}:{$jumlah}")->implode(' ') }}
                                    </div>
                                </td>
                                <td class="px-2 border border-hairline dark:border-gray-700">
                                    @if ($this->bisaIsi())
                                    <button type="button" wire:click="isiHariKerja(@js($karyawan->emp_id))"
                                        class="text-xs font-semibold whitespace-nowrap text-fg-brand hover:underline"
                                        title="Isi Senin–Sabtu bulan ini dengan shift default {{ $karyawan->shift_default_kode ?: '(belum ada — pakai kuas)' }}">
                                        Isi Sen–Sab{{ $karyawan->shift_default_kode ? ' (' . $karyawan->shift_default_kode . ')' : '' }}
                                    </button>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="{{ $jumlahHari + 4 }}" class="px-6 py-10 text-center text-muted">
                                    {{ $this->unitAktif() ? 'Belum ada karyawan aktif di unit ini — atur Unit Kerja karyawan di Master Karyawan / Pengaturan Absensi.' : 'Tidak ada karyawan aktif.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @endif

        </div>
    </div>
</div>
