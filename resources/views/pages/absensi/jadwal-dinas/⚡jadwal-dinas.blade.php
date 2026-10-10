<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Support\Format;
use App\Support\Jadwal;

/**
 * JADWAL DINAS BULANAN — grid karyawan × tanggal seperti "Jadwal Masuk Kerja Bulan …" lapangan.
 * Pilih kuas (kode shift / I / C / Libur) lalu klik sel. Kosong = libur/off, Minggu ditandai merah.
 * Tanggal yang sudah lewat TERKUNCI (Jadwal::terkunci) — semua aksi hanya mengubah hari ini & seterusnya.
 * Jadwal dipakai kiosk (shift otomatis), Generate Libur/Alpa, dan Rekap Harian.
 */
new class extends Component {
    public string $bulan = '';      // mm/yyyy
    public string $kuas  = 'P';     // kode shift / I / C / '' (libur)
    public string $searchKeyword = '';

    private string $printView = 'pages.absensi.jadwal-dinas.jadwal-dinas-print';

    public function mount(): void
    {
        $this->bulan = now()->format(Format::BULAN);
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

    public function geserBulan(int $n): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($n)->format(Format::BULAN);
    }

    #[Computed]
    public function shifts()
    {
        return Jadwal::shiftBerkode();
    }

    #[Computed]
    public function karyawan()
    {
        // Urut ID → urutan baris bisa diatur lewat ID karyawan (sama dgn formulir jadwal lapangan).
        $q = DB::table('siapmst_employers')->where('siapmst_employers.active_status', '1')->orderBy('emp_id');
        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->where(fn($s) => $s->whereRaw('UPPER(emp_name) LIKE ?', ["%{$kw}%"])->orWhereRaw('UPPER(emp_id) LIKE ?', ["%{$kw}%"]));
        }

        return $q->leftJoin('siapmst_shifts as sd', 'sd.shift_id', '=', 'siapmst_employers.emp_shift_default')
            ->get(['emp_id', 'emp_name', 'sd.shift_kode as shift_default_kode']);
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
        $awal = $this->awalBulan();
        if (!$this->kuasValid() || $hari < 1 || $hari > $awal->daysInMonth || !$this->karyawan->contains('emp_id', $empId)) {
            return;
        }

        if (Jadwal::terkunci($awal->copy()->day($hari))) {
            $this->dispatch('toast', type: 'error', message: 'Jadwal tanggal yang sudah lewat terkunci — tidak bisa diubah.');
            return;
        }

        // Klik sel yang isinya sama dengan kuas = hapus (toggle), memudahkan koreksi.
        $lama = isset($this->jadwal[$empId][$hari]) ? Jadwal::kode($this->jadwal[$empId][$hari]) : '';
        $kode = $lama === $this->kuas ? '' : $this->kuas;

        Jadwal::isi($empId, $awal->copy()->day($hari), $kode, auth()->id(), $this->shifts);
        unset($this->jadwal);
    }

    /**
     * Isi Senin–Sabtu satu karyawan dengan SHIFT DEFAULT-nya (kolom P/S);
     * bila belum punya shift default → pakai kuas terpilih. Minggu dibiarkan libur.
     */
    public function isiHariKerja(string $empId): void
    {
        $karyawan = $this->karyawan->firstWhere('emp_id', $empId);
        if (!$karyawan) {
            return;
        }
        $kode = $karyawan->shift_default_kode ?: $this->kuas;
        if ($kode === '' || (!isset(Jadwal::KHUSUS[$kode]) && !$this->shifts->has($kode))) {
            return;
        }

        $awal = $this->awalBulan();
        $jumlah = 0;
        for ($d = $awal->copy(); $d->lte($awal->copy()->endOfMonth()); $d->addDay()) {
            if (!$d->isSunday() && !Jadwal::terkunci($d)) {
                Jadwal::isi($empId, $d->copy(), $kode, auth()->id(), $this->shifts);
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
        $awal = $this->awalBulan();
        $bulanLalu = $awal->copy()->subMonthNoOverflow();
        $lalu = Jadwal::bulan($this->karyawan->pluck('emp_id')->all(), $bulanLalu);
        $jumlah = 0;

        foreach ($this->karyawan as $k) {
            for ($tgl = $awal->copy(); $tgl->lte($awal->copy()->endOfMonth()); $tgl->addDay()) {
                if (isset($this->jadwal[$k->emp_id][$tgl->day]) || Jadwal::terkunci($tgl)) {
                    continue;
                }
                $sumber = $tgl->copy()->subWeeks(4);
                if ($sumber->isSameMonth($awal)) {
                    $sumber->subWeek();
                }
                $row = $lalu[$k->emp_id][$sumber->day] ?? null;
                if ($row && $sumber->isSameMonth($bulanLalu)
                    && Jadwal::isi($k->emp_id, $tgl->copy(), Jadwal::kode($row), auth()->id(), $this->shifts)) {
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
        $awal = $this->awalBulan();
        $dari = $awal->copy()->max(now()->startOfDay());
        if ($dari->gt($awal->copy()->endOfMonth())) {
            $this->dispatch('toast', type: 'error', message: 'Semua tanggal bulan ini sudah lewat — jadwal terkunci.');
            return;
        }

        $n = DB::table('siaptxn_jadwals')
            ->whereIn('emp_id', $this->karyawan->pluck('emp_id')->all() ?: [''])
            ->where('jdw_tanggal', '>=', Absensi::tanggalSql($dari))
            ->where('jdw_tanggal', '<=', Absensi::tanggalSql($awal->copy()->endOfMonth()))
            ->delete();

        $this->dispatch('toast', type: 'success', message: "Jadwal {$this->bulan} mulai " . Format::tanggal($dari) . " dikosongkan ({$n} sel).");
        unset($this->jadwal);
    }

    public function cetak()
    {
        $awal = $this->awalBulan();
        $pdf = Pdf::loadView($this->printView, [
            'awal'     => $awal,
            'karyawan' => $this->karyawan,
            'jadwal'   => $this->jadwal,
            'dicetak'  => now(),
            'oleh'     => auth()->user()?->name,
        ])->setPaper('A4', 'landscape');

        return response()->streamDownload(fn() => print $pdf->output(), 'jadwal-dinas-' . $awal->format('Y-m') . '.pdf');
    }
};
?>

<div>

    <x-page-title
        title="Jadwal Dinas"
        subtitle="Jadwal masuk kerja bulanan — dasar shift di kiosk, Generate Libur/Alpa, dan rekap" />

    @php
        $awal = $this->awalBulan();
        $jmlHari = $awal->daysInMonth;
        $jadwal = $this->jadwal;
        $warna = fn(string $k) => \App\Support\Jadwal::WARNA[$k] ?? \App\Support\Jadwal::WARNA_LAIN;
    @endphp

    <div class="w-full min-h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex items-center gap-1">
                            <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-2.5" title="Bulan sebelumnya">‹</x-secondary-button>
                            <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" class="w-28 font-mono text-center" />
                            <x-secondary-button type="button" wire:click="geserBulan(1)" class="!px-2.5" title="Bulan berikutnya">›</x-secondary-button>
                        </div>
                        <div class="w-56">
                            <x-text-input type="text" wire:model.live.debounce.300ms="searchKeyword" placeholder="Cari karyawan..." class="block w-full" />
                        </div>
                        {{-- KUAS --}}
                        <div class="flex flex-wrap items-center gap-1 px-2 py-1 border rounded-xl bg-canvas border-hairline dark:border-gray-700">
                            <span class="mr-1 text-xs font-semibold text-muted">Kuas:</span>
                            @foreach ($this->shifts as $kode => $sh)
                                <button type="button" wire:click="$set('kuas', '{{ $kode }}')" title="{{ $sh->shift_name }} {{ $sh->jam_masuk }}–{{ $sh->jam_pulang }}"
                                    class="w-9 h-8 text-sm font-bold rounded-lg {{ $warna($kode) }} {{ $kuas === $kode ? 'ring-4 ring-offset-1 ring-brand-green' : 'opacity-70 hover:opacity-100' }}">{{ $kode }}</button>
                            @endforeach
                            @foreach (\App\Support\Jadwal::KHUSUS as $kode => $nama)
                                <button type="button" wire:click="$set('kuas', '{{ $kode }}')" title="{{ $nama }}"
                                    class="w-9 h-8 text-sm font-bold rounded-lg {{ $warna($kode) }} {{ $kuas === $kode ? 'ring-4 ring-offset-1 ring-brand-green' : 'opacity-70 hover:opacity-100' }}">{{ $kode }}</button>
                            @endforeach
                            <button type="button" wire:click="$set('kuas', '')" title="Libur / hapus jadwal"
                                class="px-2 h-8 text-xs font-bold rounded-lg border border-hairline bg-white text-gray-700 {{ $kuas === '' ? 'ring-4 ring-offset-1 ring-brand-green' : 'opacity-70 hover:opacity-100' }}">Libur</button>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <x-secondary-button type="button" wire:click="salinBulanLalu" wire:loading.attr="disabled" wire:target="salinBulanLalu">Salin bulan lalu</x-secondary-button>
                        <x-confirm-button variant="danger-soft" action="kosongkanBulan()" title="Kosongkan Jadwal"
                            message="Hapus jadwal {{ $bulan }} mulai hari ini untuk karyawan yang tampil? Tanggal yang sudah lewat tetap (terkunci)." confirmText="Ya, kosongkan" cancelText="Batal">
                            Kosongkan
                        </x-confirm-button>
                        <x-primary-button type="button" wire:click="cetak" wire:loading.attr="disabled" wire:target="cetak">
                            <span wire:loading.remove wire:target="cetak">Cetak PDF</span>
                            <span wire:loading wire:target="cetak">Menyiapkan...</span>
                        </x-primary-button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-muted dark:text-gray-400">
                    Pilih kuas lalu klik sel. Klik sel yang isinya sama dengan kuas = hapus. Sel <b>kosong = libur/off</b>.
                    Tanggal yang sudah lewat 🔒 <b>terkunci</b> — tidak bisa diubah (menjaga keaslian data absensi).
                    @foreach ($this->shifts as $kode => $sh)
                        <b>{{ $kode }}</b> {{ $sh->shift_name }} ({{ $sh->jam_masuk }}–{{ $sh->jam_pulang }}){{ !$loop->last ? ' ·' : '' }}
                    @endforeach
                    · <b>I</b> Izin · <b>C</b> Cuti
                </p>
            </div>

            {{-- GRID --}}
            <div class="mt-4 overflow-x-auto border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <table class="text-sm border-collapse">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 px-3 py-2 text-left bg-pink-100 border border-hairline min-w-[14rem]">NAMA KARYAWAN</th>
                            <th class="px-1 py-2 text-xs text-center bg-pink-100 border border-hairline" title="Shift default">P/S</th>
                            @for ($h = 1; $h <= $jmlHari; $h++)
                                @php $tgl = $awal->copy()->day($h); @endphp
                                <th class="w-9 px-0 py-1 text-xs font-bold text-center border border-hairline {{ $tgl->isSunday() ? 'bg-[#ff0000] text-white' : 'bg-[#ff00ff] text-white' }} {{ $tgl->isToday() ? 'ring-2 ring-inset ring-sky-400' : '' }}">
                                    {{ $h }}
                                    <div class="text-[9px] font-normal opacity-90">{{ \App\Support\Jadwal::terkunci($tgl) ? '🔒' : mb_substr(\App\Support\Format::hari($tgl), 0, 3) }}</div>
                                </th>
                            @endfor
                            <th class="px-2 py-1 text-xs border bg-surface-card border-hairline">Hari kerja</th>
                            <th class="px-2 py-1 text-xs border bg-surface-card border-hairline">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->karyawan as $k)
                            @php $baris = $jadwal[$k->emp_id] ?? []; @endphp
                            <tr wire:key="jdw-{{ $k->emp_id }}">
                                <td class="sticky left-0 z-10 px-3 py-1 border bg-orange-50 border-hairline dark:bg-gray-900">
                                    <div class="font-semibold uppercase text-ink dark:text-gray-100">{{ $k->emp_name }}</div>
                                    <div class="font-mono text-[10px] text-muted">{{ $k->emp_id }}</div>
                                </td>
                                <td class="px-1 text-center border border-hairline bg-orange-50">
                                    @if ($k->shift_default_kode)
                                        <span class="inline-block w-7 py-0.5 text-xs font-bold rounded {{ $warna($k->shift_default_kode) }}">{{ $k->shift_default_kode }}</span>
                                    @endif
                                </td>
                                @for ($h = 1; $h <= $jmlHari; $h++)
                                    @php
                                        $sel = $baris[$h] ?? null;
                                        $kode = $sel ? \App\Support\Jadwal::kode($sel) : '';
                                        $minggu = $awal->copy()->day($h)->isSunday();
                                        $kunci = \App\Support\Jadwal::terkunci($awal->copy()->day($h));
                                    @endphp
                                    <td class="p-0 border border-hairline {{ !$sel && $minggu ? 'bg-[#ff0000]' : '' }}">
                                        @if ($kunci)
                                            {{-- Tanggal lewat: terkunci, hanya tampil --}}
                                            <div title="{{ \App\Support\Format::tanggal($awal->copy()->day($h)) }} · terkunci (sudah lewat)"
                                                class="flex items-center justify-center w-9 h-9 text-sm font-bold cursor-not-allowed {{ $kode ? $warna($kode) . ' opacity-75' : '' }}">
                                                {{ $kode }}
                                            </div>
                                        @else
                                            <button type="button" wire:click="isiSel(@js($k->emp_id), {{ $h }})"
                                                title="{{ \App\Support\Format::tanggal($awal->copy()->day($h)) }}{{ $sel && $sel->shift_name ? ' · ' . $sel->shift_name : ($kode ? ' · ' . (\App\Support\Jadwal::KHUSUS[$kode] ?? '') : ' · libur') }}"
                                                class="w-9 h-9 text-sm font-bold transition hover:ring-2 hover:ring-inset hover:ring-brand-green {{ $kode ? $warna($kode) : '' }}">
                                                {{ $kode }}
                                            </button>
                                        @endif
                                    </td>
                                @endfor
                                @php $kerja = collect($baris)->where('jdw_jenis', 'K')->count(); @endphp
                                <td class="px-2 text-center border border-hairline">
                                    <div class="font-mono font-semibold">{{ $kerja }}</div>
                                    <div class="text-[10px] text-muted whitespace-nowrap">
                                        {{ collect($baris)->map(fn($r) => \App\Support\Jadwal::kode($r))->countBy()->sortKeys()->map(fn($n, $kd) => "{$kd}:{$n}")->implode(' ') }}
                                    </div>
                                </td>
                                <td class="px-2 border border-hairline">
                                    <button type="button" wire:click="isiHariKerja(@js($k->emp_id))"
                                        class="text-xs font-semibold whitespace-nowrap text-brand-green hover:underline"
                                        title="Isi Senin–Sabtu bulan ini dengan shift default {{ $k->shift_default_kode ?: '(belum ada — pakai kuas)' }}">
                                        Isi Sen–Sab{{ $k->shift_default_kode ? ' (' . $k->shift_default_kode . ')' : '' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $jmlHari + 4 }}" class="px-6 py-10 text-center text-muted">Tidak ada karyawan aktif.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>
