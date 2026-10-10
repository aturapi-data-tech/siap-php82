<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Support\Jadwal;

/**
 * KIOSK ABSENSI — layar penuh di dekat pintu masuk.
 *
 * Alur:  ketik/scan ID karyawan + Enter
 *   ├─ ada absen terbuka (sudah masuk, belum pulang) → catat PULANG + menit lembur
 *   ├─ belum absen & ada JADWAL kerja hari ini        → langsung catat MASUK dgn shift jadwal (H/T)
 *   ├─ jadwal hari ini Izin / Cuti                    → info saja
 *   ├─ belum absen & tidak dijadwalkan (libur)        → pilih shift (tukar dinas / lembur) → MASUK
 *   └─ sudah lengkap / tercatat Izin-Sakit-Cuti-Alpa → info saja
 * Layar hasil tampil beberapa detik lalu kembali ke input (Alpine timer → resetKiosk()).
 */
new #[Layout('layouts.app-fullscreen')] class extends Component {
    public string $empIdInput = '';

    /** input | pilih-shift | hasil */
    public string $step = 'input';

    public ?array $karyawan = null;
    public ?string $saranShiftId = null;

    /** Catatan di layar pilih shift (mis. "tidak terjadwal hari ini"). */
    public ?string $catatanJadwal = null;

    /** ['tipe' => masuk|pulang|info|error, 'judul', 'pesan', 'status'?] */
    public ?array $hasil = null;

    #[Computed]
    public function shifts()
    {
        return Absensi::shiftAktif();
    }

    #[Computed]
    public function absenTerakhir()
    {
        return DB::table('siaptxn_absensis as ab')
            ->join('siapmst_employers as em', 'em.emp_id', '=', 'ab.emp_id')
            ->whereNotNull('ab.jam_masuk')
            ->where('ab.updated_at', '>=', now()->subDay())
            ->orderByDesc('ab.updated_at')
            ->limit(8)
            ->get(['ab.emp_id', 'em.emp_name', 'ab.jam_masuk', 'ab.jam_pulang', 'ab.abs_status', 'ab.shift_id', 'ab.lembur_menit']);
    }

    // ─── Langkah 1: ID karyawan ───────────────────────────────────────────────
    public function submitId(): void
    {
        $empId = mb_strtoupper(trim($this->empIdInput));
        $this->empIdInput = '';

        if ($empId === '') {
            return;
        }

        $emp = DB::table('siapmst_employers')->select('emp_id', 'emp_name', 'active_status')->where('emp_id', $empId)->first();

        if (!$emp) {
            $this->tampilkanHasil('error', 'ID tidak dikenal', "ID \"{$empId}\" tidak terdaftar sebagai karyawan.");
            return;
        }
        if ((string) $emp->active_status !== '1') {
            $this->tampilkanHasil('error', $emp->emp_name, 'Karyawan berstatus non-aktif. Hubungi HRD.');
            return;
        }

        $this->karyawan = ['emp_id' => $emp->emp_id, 'emp_name' => $emp->emp_name];
        $sekarang = now();

        // Sudah masuk & belum pulang → absen pulang.
        if ($terbuka = Absensi::absenTerbuka($emp->emp_id, $sekarang)) {
            $sejakMasuk = (int) Carbon::parse($terbuka->jam_masuk)->diffInMinutes($sekarang);
            if ($sejakMasuk < Absensi::JEDA_MIN_PULANG_MENIT) {
                $this->tampilkanHasil('info', $emp->emp_name,
                    'Sudah absen MASUK pukul ' . Carbon::parse($terbuka->jam_masuk)->format('H:i') . '. Absen pulang bisa dilakukan nanti.');
                return;
            }

            $lembur = $terbuka->shift_jam_pulang
                ? Absensi::lemburMenit(
                    (object) ['jam_pulang' => $terbuka->shift_jam_pulang, 'lintas_hari' => $terbuka->lintas_hari],
                    Carbon::parse($terbuka->abs_date)->startOfDay(),
                    $sekarang)
                : 0;

            DB::table('siaptxn_absensis')->where('abs_id', $terbuka->abs_id)->update([
                'jam_pulang'        => $sekarang,
                'lembur_menit'      => $lembur,
                'lembur_status'     => Absensi::statusLemburAwal($lembur),
                'lembur_oleh'       => null,
                'lembur_dinilai_at' => null,
                'updated_at'        => $sekarang,
            ]);

            $pesan = 'Absen PULANG pukul ' . $sekarang->format('H:i') . ' · ' . ($terbuka->shift_name ?? $terbuka->shift_id)
                . ' (masuk ' . Carbon::parse($terbuka->jam_masuk)->format('H:i') . ')';
            if ($lembur > 0) {
                $pesan .= ' — lembur ' . Absensi::labelMenit($lembur) . ' (menunggu persetujuan)';
            }
            $this->tampilkanHasil('pulang', $emp->emp_name, $pesan, $terbuka->abs_status);
            return;
        }

        // Catatan hari ini sudah ada (lengkap / diisi admin) → info saja.
        if ($hariIni = Absensi::absenTanggal($emp->emp_id, $sekarang)) {
            if (in_array($hariIni->abs_status, Absensi::STATUS_MANUAL, true) && !$hariIni->jam_masuk) {
                $this->tampilkanHasil('info', $emp->emp_name,
                    'Hari ini tercatat ' . Absensi::STATUS[$hariIni->abs_status] . '. Hubungi HRD bila ternyata masuk kerja.');
                return;
            }
            if ($hariIni->jam_pulang) {
                $this->tampilkanHasil('info', $emp->emp_name,
                    'Absen hari ini sudah lengkap (masuk ' . Carbon::parse($hariIni->jam_masuk)->format('H:i')
                    . ', pulang ' . Carbon::parse($hariIni->jam_pulang)->format('H:i') . ').');
                return;
            }
        }

        if ($this->shifts->isEmpty()) {
            $this->tampilkanHasil('error', $emp->emp_name, 'Belum ada shift aktif. Hubungi HRD.');
            return;
        }

        // Jadwal dinas hari ini menentukan shift.
        $jadwal = Jadwal::pada($emp->emp_id, $sekarang);
        if ($jadwal && $jadwal->jdw_jenis === 'K' && ($shift = $this->shifts->firstWhere('shift_id', $jadwal->shift_id))) {
            $this->catatMasuk($shift, true);
            return;
        }
        if ($jadwal && isset(Jadwal::KHUSUS[$jadwal->jdw_jenis])) {
            $this->tampilkanHasil('info', $emp->emp_name,
                'Hari ini terjadwal ' . Jadwal::KHUSUS[$jadwal->jdw_jenis] . '. Hubungi HRD bila ternyata masuk kerja.');
            return;
        }

        // Tidak ada jadwal → pilih shift (tukar dinas / tambahan).
        $this->catatanJadwal = Jadwal::adaJadwalBulan($sekarang)
            ? 'Anda tidak terjadwal masuk hari ini (libur). Pilih shift bila memang bertugas (tukar dinas).'
            : null;
        $this->saranShiftId = Absensi::sarankanShift($this->shifts, $sekarang)?->shift_id;
        $this->step = 'pilih-shift';
    }

    // ─── Langkah 2: pilih shift → absen masuk ─────────────────────────────────
    public function pilihShift(string $shiftId): void
    {
        if ($this->step !== 'pilih-shift' || !$this->karyawan) {
            return;
        }

        $shift = $this->shifts->firstWhere('shift_id', $shiftId);
        if (!$shift) {
            return;
        }

        $this->catatMasuk($shift, false);
    }

    /** Catat absen MASUK dengan shift tertentu (dari jadwal atau pilihan karyawan). */
    private function catatMasuk(object $shift, bool $sesuaiJadwal): void
    {
        $sekarang = now();
        $tanggalKerja = Absensi::tanggalKerja($shift, $sekarang);
        [$status, $terlambat] = Absensi::statusMasuk($shift, $tanggalKerja, $sekarang);
        $empId = $this->karyawan['emp_id'];

        if (Absensi::absenTanggal($empId, $tanggalKerja)) {
            $this->tampilkanHasil('info', $this->karyawan['emp_name'],
                'Sudah ada absensi untuk tanggal kerja ' . $tanggalKerja->format('d/m/Y') . '.');
            return;
        }

        try {
            DB::table('siaptxn_absensis')->insert([
                'emp_id'          => $empId,
                'abs_date'        => Absensi::tanggalSql($tanggalKerja),
                'shift_id'        => $shift->shift_id,
                'jam_masuk'       => $sekarang,
                'abs_status'      => $status,
                'terlambat_menit' => $terlambat,
                'sumber'          => 'KIOSK',
                'updated_by'      => auth()->id(),
                'created_at'      => $sekarang,
                'updated_at'      => $sekarang,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Scan dobel bersamaan → baris pertama sudah tercatat.
            $this->tampilkanHasil('info', $this->karyawan['emp_name'], 'Absen masuk sudah tercatat.');
            return;
        }

        $pesan = 'Absen MASUK pukul ' . $sekarang->format('H:i') . ' · ' . $shift->shift_name . ($sesuaiJadwal ? ' (sesuai jadwal)' : '');
        if ($status === 'T') {
            $pesan .= ' — terlambat ' . Absensi::labelTerlambat($terlambat);
        }
        $this->tampilkanHasil('masuk', $this->karyawan['emp_name'], $pesan, $status);
    }

    public function resetKiosk(): void
    {
        $this->reset(['empIdInput', 'karyawan', 'saranShiftId', 'catatanJadwal', 'hasil']);
        $this->step = 'input';
        unset($this->absenTerakhir);
    }

    private function tampilkanHasil(string $tipe, string $judul, string $pesan, ?string $status = null): void
    {
        $this->hasil = compact('tipe', 'judul', 'pesan', 'status');
        $this->step = 'hasil';
        unset($this->absenTerakhir);
    }
};
?>

<div class="min-h-screen bg-surface-soft dark:bg-gray-900"
    x-data="{
        jam: '', tanggal: '',
        tick() {
            const d = new Date();
            this.jam = [d.getHours(), d.getMinutes(), d.getSeconds()].map((n) => String(n).padStart(2, '0')).join(':');
            const dua = (n) => String(n).padStart(2, '0');
            this.tanggal = d.toLocaleDateString('id-ID', { weekday: 'long' }) + ', ' + dua(d.getDate()) + '/' + dua(d.getMonth() + 1) + '/' + d.getFullYear();
        },
    }"
    x-init="tick(); setInterval(() => tick(), 1000)">

    <div class="grid min-h-screen grid-cols-1 lg:grid-cols-3">

        {{-- PANEL UTAMA --}}
        <div class="flex flex-col items-center justify-center px-6 py-10 lg:col-span-2">

            {{-- Jam --}}
            <div class="mb-10 text-center">
                <div class="flex items-center justify-center gap-3 mb-4">
                    <img src="{{ asset('images/Logogram black solid.png') }}" alt="Logo" class="w-10 h-10" />
                    <span class="text-2xl font-bold tracking-tight text-brand-green">SIAP · Absensi</span>
                </div>
                <div class="font-mono font-bold tracking-tight text-7xl text-ink tabular-nums" x-text="jam"></div>
                <div class="mt-2 text-xl text-muted" x-text="tanggal"></div>
            </div>

            <div class="w-full max-w-xl">

                {{-- STEP: INPUT ID --}}
                @if ($step === 'input')
                    <div class="p-8 border shadow-sm bg-canvas border-hairline rounded-3xl"
                        x-data
                        x-init="$nextTick(() => $refs.inputId.focus())">
                        <label for="empIdInput" class="block mb-3 text-lg font-semibold text-center text-ink">
                            Ketik atau scan ID Karyawan, lalu tekan Enter
                        </label>
                        <input id="empIdInput" x-ref="inputId" type="text" autocomplete="off"
                            wire:model="empIdInput" wire:keydown.enter.prevent="submitId"
                            x-on:blur="setTimeout(() => $refs.inputId?.focus(), 300)"
                            class="w-full px-6 py-5 font-mono text-3xl text-center uppercase border-2 rounded-2xl border-hairline focus:border-brand-green focus:ring-brand-green"
                            placeholder="Contoh: PRW-001" />
                        <div wire:loading wire:target="submitId" class="mt-3 text-center text-muted">Memeriksa...</div>
                    </div>
                @endif

                {{-- STEP: PILIH SHIFT --}}
                @if ($step === 'pilih-shift' && $karyawan)
                    <div class="p-8 border shadow-sm bg-canvas border-hairline rounded-3xl"
                        x-data="{ sisa: 30 }"
                        x-init="const t = setInterval(() => { if (--sisa <= 0) { clearInterval(t); $wire.resetKiosk(); } }, 1000)">
                        <p class="text-center text-muted">Absen masuk untuk</p>
                        <p class="mb-1 text-3xl font-bold text-center text-ink">{{ $karyawan['emp_name'] }}</p>
                        <p class="mb-6 font-mono text-center text-muted">{{ $karyawan['emp_id'] }}</p>

                        @if ($catatanJadwal)
                            <p class="p-3 mb-4 text-sm text-center border rounded-xl border-amber-300 bg-amber-50 text-amber-900">{{ $catatanJadwal }}</p>
                        @endif
                        <p class="mb-3 font-semibold text-center text-ink">Pilih shift Anda:</p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @foreach ($this->shifts as $shift)
                                @php $disarankan = $shift->shift_id === $saranShiftId; @endphp
                                <button type="button" wire:click="pilihShift('{{ $shift->shift_id }}')"
                                    wire:loading.attr="disabled"
                                    @if ($disarankan) x-init="$nextTick(() => $el.focus())" @endif
                                    class="relative px-5 py-4 text-left transition border-2 rounded-2xl focus:outline-none focus:ring-4 focus:ring-brand-green/30
                                        {{ $disarankan ? 'border-brand-green bg-brand-green/10' : 'border-hairline hover:border-brand-green/60' }}">
                                    @if ($disarankan)
                                        <span class="absolute px-2 py-0.5 text-[11px] font-semibold text-white rounded-full -top-2.5 right-3 bg-brand-green">Disarankan</span>
                                    @endif
                                    <div class="text-lg font-semibold text-ink">{{ $shift->shift_name }}</div>
                                    <div class="font-mono text-muted">{{ $shift->jam_masuk }} – {{ $shift->jam_pulang }}</div>
                                </button>
                            @endforeach
                        </div>

                        <div class="flex items-center justify-between mt-6 text-sm text-muted">
                            <span>Kembali otomatis dalam <b x-text="sisa"></b> detik</span>
                            <button type="button" wire:click="resetKiosk" class="font-semibold underline">Batal</button>
                        </div>
                    </div>
                @endif

                {{-- STEP: HASIL --}}
                @if ($step === 'hasil' && $hasil)
                    @php
                        $warna = match ($hasil['tipe']) {
                            'masuk'  => $hasil['status'] === 'T' ? 'border-amber-400 bg-amber-50' : 'border-emerald-400 bg-emerald-50',
                            'pulang' => 'border-sky-400 bg-sky-50',
                            'info'   => 'border-slate-300 bg-white',
                            default  => 'border-red-400 bg-red-50',
                        };
                        $ikon = match ($hasil['tipe']) {
                            'masuk'  => $hasil['status'] === 'T' ? '⏰' : '✅',
                            'pulang' => '👋',
                            'info'   => 'ℹ️',
                            default  => '⛔',
                        };
                    @endphp
                    <div class="p-10 text-center border-4 shadow-sm rounded-3xl {{ $warna }}"
                        x-data="{ sisa: 5 }"
                        x-init="const t = setInterval(() => { if (--sisa <= 0) { clearInterval(t); $wire.resetKiosk(); } }, 1000)"
                        x-on:keydown.window.enter.prevent="$wire.resetKiosk()">
                        <div class="mb-3 text-6xl">{{ $ikon }}</div>
                        <p class="text-3xl font-bold text-ink">{{ $hasil['judul'] }}</p>
                        <p class="mt-3 text-xl text-body">{{ $hasil['pesan'] }}</p>
                        <p class="mt-6 text-sm text-muted">Kembali dalam <b x-text="sisa"></b> detik · tekan Enter untuk lanjut</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- PANEL KANAN: absen terakhir --}}
        <aside class="px-6 py-10 border-t lg:border-t-0 lg:border-l border-hairline bg-canvas">
            <h2 class="mb-4 text-lg font-semibold text-ink">Absen terakhir</h2>
            <ul class="space-y-3">
                @forelse ($this->absenTerakhir as $row)
                    <li wire:key="terakhir-{{ $row->emp_id }}-{{ $row->jam_masuk }}" class="flex items-center justify-between gap-3 p-3 border rounded-xl border-hairline">
                        <div class="min-w-0">
                            <div class="font-semibold truncate text-ink">{{ $row->emp_name }}</div>
                            <div class="font-mono text-xs text-muted">{{ $row->emp_id }} · {{ $row->shift_id }}</div>
                        </div>
                        <div class="text-right shrink-0">
                            @if ($row->jam_pulang)
                                <div class="text-sm font-semibold text-sky-700">Pulang {{ \Carbon\Carbon::parse($row->jam_pulang)->format('H:i') }}</div>
                                @if ((int) $row->lembur_menit > 0)
                                    <div class="text-[11px] text-indigo-700">lembur {{ \App\Support\Absensi::labelMenit((int) $row->lembur_menit) }}</div>
                                @endif
                            @else
                                <div class="text-sm font-semibold {{ $row->abs_status === 'T' ? 'text-amber-700' : 'text-emerald-700' }}">
                                    Masuk {{ \Carbon\Carbon::parse($row->jam_masuk)->format('H:i') }}
                                </div>
                            @endif
                            <x-badge :variant="\App\Support\Absensi::BADGE[$row->abs_status] ?? 'gray'">{{ \App\Support\Absensi::STATUS[$row->abs_status] ?? $row->abs_status }}</x-badge>
                        </div>
                    </li>
                @empty
                    <li class="text-sm text-muted">Belum ada absen.</li>
                @endforelse
            </ul>
            <p class="mt-8 text-xs text-muted-soft">
                Layar kiosk — dibuka di komputer dekat pintu masuk dengan akun petugas.
                <a href="{{ route('dashboard') }}" class="underline">Keluar ke dashboard</a>
            </p>
        </aside>
    </div>
</div>
