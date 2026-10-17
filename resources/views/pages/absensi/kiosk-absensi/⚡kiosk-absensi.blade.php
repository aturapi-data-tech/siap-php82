<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Illuminate\Support\Facades\DB;
use App\Support\Absensi;

/**
 * KIOSK ABSENSI — layar penuh di dekat pintu masuk.
 *
 * Alur:  ketik/scan ID karyawan + Enter
 *   ├─ ada absen terbuka (sudah masuk, belum pulang) → catat PULANG + menit lembur
 *   ├─ belum absen & ada JADWAL kerja hari ini        → langsung catat MASUK dgn shift jadwal (H/T)
 *   │     └─ sudah lewat separuh shift                 → konfirmasi: MASUK, atau lupa absen masuk → PULANG
 *   ├─ jadwal hari ini Izin / Cuti                    → info saja
 *   ├─ belum absen & tidak dijadwalkan (libur)        → pilih shift (tukar dinas / lembur) → MASUK
 *   └─ sudah lengkap / tercatat Izin-Sakit-Cuti-Alpa → info saja
 * Layar hasil tampil beberapa detik lalu kembali ke input (Alpine timer → resetKiosk()).
 */
new #[Layout('layouts.app-fullscreen')] class extends Component {
    public string $empIdInput = '';

    /** input | pilih-shift | konfirmasi-masuk | hasil */
    public string $step = 'input';

    public ?array $karyawan = null;
    public ?string $saranShiftId = null;

    /** Shift jadwal yang menunggu konfirmasi "masuk atau lupa absen masuk". */
    #[Locked]
    public ?string $shiftKonfirmasiId = null;

    /** Catatan di layar pilih shift (mis. "tidak terjadwal hari ini"). */
    public ?string $catatanJadwal = null;

    /** ['tipe' => masuk|pulang|info|error, 'judul', 'pesan', 'status'?] */
    public ?array $hasil = null;

    #[Computed]
    public function shifts()
    {
        return Absensi::shiftAktif();
    }

    /** Shift yang bisa dipilih karyawan ini (tanpa unit + unitnya) — layar pilih shift. */
    #[Computed]
    public function shiftPilihan()
    {
        return $this->karyawan['emp_id'] ? Absensi::shiftPilihan($this->karyawan['emp_id'], $this->shifts) : collect();
    }

    #[Computed]
    public function absenTerakhir()
    {
        return DB::table('siaptxn_absensis as ab')
            ->join('siapmst_employers as em', 'em.emp_id', '=', 'ab.emp_id')
            ->where(fn($subQuery) => $subQuery->whereNotNull('ab.jam_masuk')->orWhereNotNull('ab.jam_pulang'))
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

        $dataKaryawan = DB::table('siapmst_employers')->select('emp_id', 'emp_name', 'active_status')->where('emp_id', $empId)->first();

        if (!$dataKaryawan) {
            $this->tampilkanHasil('error', 'ID tidak dikenal', "ID \"{$empId}\" tidak terdaftar sebagai karyawan.");
            return;
        }
        if ((string) $dataKaryawan->active_status !== '1') {
            $this->tampilkanHasil('error', $dataKaryawan->emp_name, 'Karyawan berstatus non-aktif. Hubungi HRD.');
            return;
        }

        $this->karyawan = ['emp_id' => $dataKaryawan->emp_id, 'emp_name' => $dataKaryawan->emp_name];
        $sekarang = now();
        $keputusan = Absensi::tentukanAksi($dataKaryawan->emp_id, $this->shifts, $sekarang);

        if ($keputusan['aksi'] === 'pulang') {
            $this->tampilkanHasil('pulang', $dataKaryawan->emp_name, Absensi::catatPulang($keputusan['terbuka'], $sekarang), $keputusan['terbuka']->abs_status);
            return;
        }
        if ($keputusan['aksi'] === 'masuk' && $keputusan['mungkinLupaMasuk']) {
            $this->shiftKonfirmasiId = $keputusan['shift']->shift_id;
            $this->step = 'konfirmasi-masuk';
            return;
        }
        if ($keputusan['aksi'] === 'masuk') {
            $this->catatMasuk($keputusan['shift'], true);
            return;
        }
        if ($keputusan['aksi'] === 'pilih-shift') {
            $this->catatanJadwal = $keputusan['catatan'];
            $this->saranShiftId = $keputusan['saranShiftId'];
            $this->step = 'pilih-shift';
            return;
        }

        $this->tampilkanHasil($keputusan['aksi'], $dataKaryawan->emp_name, $keputusan['pesan']);
    }

    // ─── Langkah 2: pilih shift → absen masuk ─────────────────────────────────
    public function pilihShift(string $shiftId): void
    {
        if ($this->step !== 'pilih-shift' || !$this->karyawan) {
            return;
        }

        $shift = $this->shiftPilihan->firstWhere('shift_id', $shiftId);
        if (!$shift) {
            return;
        }

        $this->catatMasuk($shift, false);
    }

    // ─── Konfirmasi: absen setelah separuh shift ──────────────────────────────
    #[Computed]
    public function shiftKonfirmasi(): ?object
    {
        return $this->shiftKonfirmasiId ? $this->shifts->firstWhere('shift_id', $this->shiftKonfirmasiId) : null;
    }

    /** $pilihan: masuk = memang baru datang (terlambat) | pulang = lupa absen masuk, sekarang mau pulang. */
    public function konfirmasiMasuk(string $pilihan): void
    {
        $shift = $this->shiftKonfirmasi;
        if ($this->step !== 'konfirmasi-masuk' || !$this->karyawan || !$shift || !in_array($pilihan, ['masuk', 'pulang'], true)) {
            return;
        }

        if ($pilihan === 'masuk') {
            $this->catatMasuk($shift, true);
            return;
        }

        $hasilPulang = Absensi::catatPulangTanpaMasuk($this->karyawan['emp_id'], $shift, now(), 'KIOSK', auth()->id());
        $this->tampilkanHasil($hasilPulang['tipe'], $this->karyawan['emp_name'], $hasilPulang['pesan'], $hasilPulang['status']);
    }

    /** Catat absen MASUK dengan shift tertentu (dari jadwal atau pilihan karyawan). */
    private function catatMasuk(object $shift, bool $sesuaiJadwal): void
    {
        $hasilMasuk = Absensi::catatMasuk($this->karyawan['emp_id'], $shift, $sesuaiJadwal, now(), 'KIOSK', auth()->id());
        $this->tampilkanHasil($hasilMasuk['tipe'], $this->karyawan['emp_name'], $hasilMasuk['pesan'], $hasilMasuk['status']);
    }

    public function resetKiosk(): void
    {
        $this->reset(['empIdInput', 'karyawan', 'saranShiftId', 'catatanJadwal', 'shiftKonfirmasiId', 'hasil']);
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
        jam: '', tanggal: '', t: null,
        tick() {
            const d = new Date();
            this.jam = [d.getHours(), d.getMinutes(), d.getSeconds()].map((n) => String(n).padStart(2, '0')).join(':');
            const dua = (n) => String(n).padStart(2, '0');
            this.tanggal = d.toLocaleDateString('id-ID', { weekday: 'long' }) + ', ' + dua(d.getDate()) + '/' + dua(d.getMonth() + 1) + '/' + d.getFullYear();
        },
        init() { this.tick(); this.t = setInterval(() => this.tick(), 1000) },
        destroy() { clearInterval(this.t) },
    }">

    <div class="grid min-h-screen grid-cols-1 lg:grid-cols-3">

        {{-- PANEL UTAMA --}}
        <div class="flex flex-col items-center justify-center px-6 py-10 lg:col-span-2">

            {{-- Kepala: logo RS + label, lalu jam besar (sama dengan Absen HP) --}}
            @php $logoRs = \App\Support\Identitas::logoUrl(); @endphp
            <div class="w-full max-w-xl mb-6">
                <div class="flex items-center justify-between gap-3 mb-5">
                    @if ($logoRs)
                        <img src="{{ $logoRs }}" alt="{{ \App\Support\Identitas::get()->nama }}" class="object-contain w-auto h-14">
                    @else
                        <span class="text-2xl font-bold text-brand-green">{{ \App\Support\Identitas::namaPendek() }}</span>
                    @endif
                    <span class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold tracking-wide uppercase rounded-full bg-brand-softer text-fg-brand-strong">
                        <x-absensi.ikon nama="jam" class="w-5 h-5" /> Kiosk Absensi
                    </span>
                </div>
                <div class="px-6 py-6 text-center border shadow-sm bg-canvas border-hairline rounded-3xl">
                    <div class="font-mono font-bold tracking-tight text-7xl text-ink tabular-nums" x-text="jam"></div>
                    <div class="mt-2 text-xl text-muted" x-text="tanggal"></div>
                </div>
            </div>

            <div class="w-full max-w-xl">

                {{-- STEP: INPUT ID --}}
                @if ($step === 'input')
                    <div wire:key="kiosk-input" class="p-8 border shadow-sm bg-canvas border-hairline rounded-3xl"
                        x-data
                        x-init="$nextTick(() => $refs.inputId.focus())">
                        <label for="empIdInput" class="block mb-3 text-lg font-semibold text-center text-ink">
                            Ketik atau scan ID Karyawan, lalu tekan Enter
                        </label>
                        <input id="empIdInput" x-ref="inputId" type="text" autocomplete="off"
                            wire:model="empIdInput" wire:keydown.enter.prevent="submitId"
                            {{-- terkunci selama diperiksa: Enter ganda / scan kedua tidak mengirim permintaan dobel --}}
                            wire:loading.attr="readonly" wire:target="submitId"
                            x-on:blur="setTimeout(() => $refs.inputId?.focus(), 300)"
                            class="w-full px-6 py-5 font-mono text-3xl text-center uppercase border-2 rounded-2xl bg-neutral-secondary-medium border-default-medium text-heading placeholder:normal-case placeholder:text-xl placeholder:font-sans placeholder:text-gray-500 focus:border-brand focus:ring-brand"
                            placeholder="Contoh: PRW-001" />
                        {{-- Layar sentuh tanpa scanner: tombol besar, tidak perlu mencari Enter di keyboard layar --}}
                        <button type="button" wire:click="submitId" wire:loading.attr="disabled" wire:target="submitId"
                            class="inline-flex items-center justify-center w-full gap-2 py-4 mt-4 text-lg font-semibold text-white transition rounded-2xl bg-brand hover:bg-brand-strong focus:outline-none focus:ring-4 focus:ring-brand-medium disabled:opacity-60 disabled:cursor-wait">
                            <span wire:loading.remove wire:target="submitId" class="inline-flex items-center gap-2">Absen <x-absensi.ikon nama="panah" class="w-5 h-5" /></span>
                            <span wire:loading wire:target="submitId">Memeriksa...</span>
                        </button>
                    </div>
                @endif

                {{-- STEP: PILIH SHIFT --}}
                @if ($step === 'pilih-shift' && $karyawan)
                    {{-- Timer dibersihkan di destroy(): langkah yang ditinggalkan lewat Batal / pilih shift tidak boleh
                         me-reset layar orang berikutnya. wire:key → tiap langkah komponen Alpine baru. --}}
                    <div wire:key="kiosk-shift" class="p-8 border shadow-sm bg-canvas border-hairline rounded-3xl"
                        x-data="{ sisa: 30, t: null,
                            init() { this.t = setInterval(() => { if (--this.sisa <= 0) { clearInterval(this.t); $wire.resetKiosk(); } }, 1000) },
                            destroy() { clearInterval(this.t) } }">
                        <p class="text-center text-muted">Absen masuk untuk</p>
                        <p class="mb-1 text-3xl font-bold text-center text-ink">{{ $karyawan['emp_name'] }}</p>
                        <p class="mb-6 font-mono text-center text-muted">{{ $karyawan['emp_id'] }}</p>

                        @if ($catatanJadwal)
                            <p class="p-3 mb-4 text-sm text-center border rounded-xl border-amber-300 bg-amber-50 text-amber-900">{{ $catatanJadwal }}</p>
                        @endif
                        <p class="mb-3 font-semibold text-center text-ink">Pilih shift Anda:</p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @foreach ($this->shiftPilihan as $shift)
                                @php $disarankan = $shift->shift_id === $saranShiftId; @endphp
                                <button type="button" wire:click="pilihShift('{{ $shift->shift_id }}')"
                                    wire:loading.attr="disabled"
                                    @if ($disarankan) x-init="$nextTick(() => $el.focus())" @endif
                                    class="relative px-5 py-4 text-left transition border-2 rounded-2xl focus:outline-none focus:ring-4 focus:ring-brand-medium
                                        {{ $disarankan ? 'border-brand bg-brand-softer' : 'border-default-medium hover:border-brand' }}" wire:target="pilihShift('{{ $shift->shift_id }}')">
                                    @if ($disarankan)
                                        <span class="absolute px-2 py-0.5 text-xs font-semibold text-white rounded-full -top-3 right-3 bg-brand">Disarankan</span>
                                    @endif
                                    <div class="text-lg font-semibold text-ink">{{ $shift->shift_name }}</div>
                                    <div class="font-mono text-muted">{{ $shift->jam_masuk }} – {{ $shift->jam_pulang }}</div>
                                <x-loading wire:loading.delay wire:target="pilihShift('{{ $shift->shift_id }}')" class="absolute right-3 bottom-3" /></button>
                            @endforeach
                        </div>

                        <div class="flex items-center justify-between mt-6 text-sm text-muted">
                            <span>Kembali otomatis dalam <b x-text="sisa"></b> detik</span>
                            <button type="button" wire:click="resetKiosk" class="inline-flex items-center px-4 font-semibold rounded-xl min-h-[44px] text-heading hover:bg-surface-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Batal</button>
                        </div>
                    </div>
                @endif

                {{-- STEP: KONFIRMASI (absen setelah separuh shift — mungkin lupa absen masuk) --}}
                @if ($step === 'konfirmasi-masuk' && $karyawan && $this->shiftKonfirmasi)
                    @php $shift = $this->shiftKonfirmasi; @endphp
                    <div wire:key="kiosk-konfirmasi" class="p-8 border border-t-4 shadow-sm bg-canvas border-hairline border-t-warning rounded-3xl"
                        x-data="{ sisa: 30, t: null,
                            init() { this.t = setInterval(() => { if (--this.sisa <= 0) { clearInterval(this.t); $wire.resetKiosk(); } }, 1000) },
                            destroy() { clearInterval(this.t) } }">
                        <p class="text-3xl font-bold text-center text-ink">{{ $karyawan['emp_name'] }}</p>
                        <p class="mt-3 text-lg text-center text-body">
                            Anda <b>belum absen masuk</b> untuk {{ $shift->shift_name }}
                            <span class="font-mono">({{ $shift->jam_masuk }}–{{ $shift->jam_pulang }})</span>.
                        </p>
                        <p class="mb-6 text-center text-body">Absen ini untuk apa?</p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <button type="button" wire:click="konfirmasiMasuk('masuk')" wire:loading.attr="disabled"
                                class="px-5 py-4 text-left transition border-2 rounded-2xl border-default-medium bg-canvas hover:border-brand focus:outline-none focus:ring-4 focus:ring-brand-medium" wire:target="konfirmasiMasuk('masuk')">
                                <div class="flex items-center gap-2 text-lg font-semibold text-ink"><x-absensi.ikon nama="masuk" class="w-6 h-6 text-fg-brand" /> Saya baru datang</div>
                                <div class="text-sm text-muted">Catat MASUK sekarang (terlambat)</div>
                            <x-loading wire:loading.delay wire:target="konfirmasiMasuk('masuk')" class="ml-1 align-middle" /></button>
                            <button type="button" wire:click="konfirmasiMasuk('pulang')" wire:loading.attr="disabled"
                                class="px-5 py-4 text-left transition border-2 rounded-2xl border-default-medium bg-canvas hover:border-brand focus:outline-none focus:ring-4 focus:ring-brand-medium" wire:target="konfirmasiMasuk('pulang')">
                                <div class="flex items-center gap-2 text-lg font-semibold text-ink"><x-absensi.ikon nama="pulang" class="w-6 h-6 text-fg-brand" /> Saya lupa absen masuk, mau pulang</div>
                                <div class="text-sm text-muted">Catat PULANG — jam masuk dilengkapi HRD</div>
                            <x-loading wire:loading.delay wire:target="konfirmasiMasuk('pulang')" class="ml-1 align-middle" /></button>
                        </div>
                        <div class="flex items-center justify-between mt-6 text-sm text-muted">
                            <span>Kembali otomatis dalam <b x-text="sisa"></b> detik</span>
                            <button type="button" wire:click="resetKiosk" class="inline-flex items-center px-4 font-semibold rounded-xl min-h-[44px] text-heading hover:bg-surface-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Batal</button>
                        </div>
                    </div>
                @endif

                {{-- STEP: HASIL --}}
                @if ($step === 'hasil' && $hasil)
                    {{-- Kartu hasil bersama (sama dengan Absen HP); timer 5 detik dibersihkan di destroy() --}}
                    <x-absensi.kartu-hasil :hasil="$hasil" wire:key="kiosk-hasil" class="p-10"
                        x-data="{ sisa: 5, t: null,
                            init() { this.t = setInterval(() => { if (--this.sisa <= 0) { clearInterval(this.t); $wire.resetKiosk(); } }, 1000) },
                            destroy() { clearInterval(this.t) } }"
                        x-on:keydown.window.enter.prevent="$wire.resetKiosk()">
                        <p class="mt-6 text-sm text-muted">Kembali dalam <b x-text="sisa"></b> detik · tekan Enter untuk lanjut</p>
                    </x-absensi.kartu-hasil>
                @endif

                @if ($step === 'input')
                    <x-absensi.cara-absen mode="kiosk" class="mt-6" />
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
                                    <div class="text-xs text-indigo-700">lembur {{ \App\Support\Absensi::labelMenit((int) $row->lembur_menit) }}</div>
                                @endif
                            @else
                                <div class="text-sm font-semibold {{ $row->abs_status === 'T' ? 'text-amber-700' : 'text-emerald-700' }}">
                                    Masuk {{ $row->jam_masuk ? \Carbon\Carbon::parse($row->jam_masuk)->format('H:i') : '—' }}
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
