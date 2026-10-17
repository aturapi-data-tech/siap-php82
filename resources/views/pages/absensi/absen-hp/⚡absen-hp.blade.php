<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Support\LokasiAbsen;
use App\Support\PengaturanAbsensi;

/**
 * ABSEN HP — halaman PUBLIK (tanpa login), karyawan absen sendiri dari HP: ID + GPS + selfie.
 *
 * Alur:
 *   ketik ID karyawan → pilihKaryawan() → tampil nama, lokasi kerja & status hari ini
 *   → Mulai: ambil GPS → cekLokasi() (jarak dihitung server)
 *       ├─ di luar radius / GPS tidak akurat → pesan, coba lagi
 *       └─ dalam radius → ambil / upload selfie (dikecilkan ±640px di HP) → kirimAbsen()
 *            ├─ lokasi divalidasi ULANG di server, lalu alur sama dengan kiosk (Absensi::tentukanAksi)
 *            ├─ tidak terjadwal → pilih shift (selfie & posisi ditahan di $buktiTertunda)
 *            └─ lewat separuh shift → konfirmasi: MASUK, atau lupa absen masuk → PULANG (bukti juga ditahan)
 * Karena publik: percobaan per IP dibatasi (RateLimiter) dan $empId dikunci (#[Locked]).
 * Butuh HTTPS: browser hanya mengizinkan GPS di secure context.
 */
new #[Layout('layouts.app-fullscreen')] class extends Component {
    /** Batas percobaan per IP per menit — menahan tebak-tebak ID / kirim beruntun. */
    private const BATAS_CARI_ID_PER_MENIT = 20;
    private const BATAS_KIRIM_PER_MENIT = 10;

    /** input-id | mulai | pilih-shift | konfirmasi-masuk | hasil */
    public string $step = 'input-id';

    public string $empIdInput = '';

    /** Karyawan yang sedang absen — dikunci agar tidak bisa diganti dari browser setelah dicek. */
    #[Locked]
    public ?string $empId = null;

    public ?string $catatanJadwal = null;
    public ?string $saranShiftId = null;

    /** Bukti absen yang menunggu pilihan shift — dikunci agar tidak bisa diubah dari browser. */
    #[Locked]
    public ?array $buktiTertunda = null;

    /** Shift jadwal yang menunggu konfirmasi "masuk atau lupa absen masuk". */
    #[Locked]
    public ?string $shiftKonfirmasiId = null;

    /** ['tipe' => masuk|pulang|info|error, 'judul', 'pesan', 'status'?] */
    public ?array $hasil = null;

    #[Computed]
    public function karyawan(): ?object
    {
        if (!$this->empId) {
            return null;
        }

        return DB::table('siapmst_employers as em')
            ->leftJoin('siapmst_lokasi_absens as lk', 'lk.lokasi_id', '=', 'em.emp_lokasi_id')
            ->where('em.emp_id', $this->empId)
            ->first(['em.emp_id', 'em.emp_name', 'em.active_status', 'em.emp_lokasi_id', 'lk.lokasi_name']);
    }

    #[Computed]
    public function shifts()
    {
        return Absensi::shiftAktif();
    }

    /** Shift yang bisa dipilih karyawan ini (tanpa unit + unitnya) — layar pilih shift. */
    #[Computed]
    public function shiftPilihan()
    {
        return $this->empId ? Absensi::shiftPilihan($this->empId, $this->shifts) : collect();
    }

    /** Ringkasan absen hari ini di layar awal. */
    #[Computed]
    public function statusHariIni(): string
    {
        $empId = $this->karyawan->emp_id;
        $sekarang = now();

        if ($terbuka = Absensi::absenTerbuka($empId, $sekarang)) {
            return 'Sudah MASUK pukul ' . Carbon::parse($terbuka->jam_masuk)->format('H:i') . ' — absen berikutnya: PULANG.';
        }
        $hariIni = Absensi::absenTanggal($empId, $sekarang);
        if ($hariIni?->jam_pulang) {
            return 'Absen hari ini sudah lengkap.';
        }
        if ($hariIni && !$hariIni->jam_masuk) {
            return 'Hari ini tercatat ' . (Absensi::STATUS[$hariIni->abs_status] ?? $hariIni->abs_status) . '.';
        }

        return 'Belum absen masuk hari ini.';
    }

    // ─── Langkah 1: ID karyawan ───────────────────────────────────────────────
    public function pilihKaryawan(): void
    {
        $empId = mb_strtoupper(trim($this->empIdInput));
        if ($empId === '') {
            return;
        }
        $this->resetErrorBag();
        if ($this->terlaluSering('cari-id', self::BATAS_CARI_ID_PER_MENIT)) {
            $this->addError('empIdInput', 'Terlalu banyak percobaan. Tunggu 1 menit lalu coba lagi.');
            return;
        }

        $this->empId = $empId;
        unset($this->karyawan, $this->statusHariIni);

        $penolakan = $this->tolakKaryawan();
        if ($penolakan) {
            $this->empId = null;
            unset($this->karyawan);
            $this->addError('empIdInput', $penolakan);
            return;
        }

        $this->dispatch('absen-hp-ingat-id', empId: $empId);
        $this->step = 'mulai';
    }

    public function gantiId(): void
    {
        $this->batalkanBuktiTertunda();
        $this->reset(['empId', 'catatanJadwal', 'saranShiftId', 'buktiTertunda', 'shiftKonfirmasiId', 'hasil']);
        $this->step = 'input-id';
        unset($this->karyawan, $this->statusHariIni);
    }

    // ─── Langkah 2: GPS ───────────────────────────────────────────────────────
    /** Dicek sebelum foto diambil, supaya karyawan tidak selfie sia-sia. */
    public function cekLokasi(float $latitude, float $longitude, float $akurasiMeter): array
    {
        $penolakan = $this->tolakKaryawan();
        if ($penolakan) {
            return ['dalamRadius' => false, 'pesan' => $penolakan];
        }

        $periksa = LokasiAbsen::periksa($this->karyawan->emp_lokasi_id, $latitude, $longitude, $akurasiMeter);

        return [
            'dalamRadius' => $periksa['dalamRadius'],
            'pesan'       => $periksa['pesan'],
            'lokasiName'  => $periksa['lokasi']?->lokasi_name,
            'jarakLabel'  => $periksa['jarakMeter'] !== null ? LokasiAbsen::labelJarak($periksa['jarakMeter']) : null,
        ];
    }

    // ─── Langkah 3: kirim (GPS + selfie) ─────────────────────────────────────
    public function kirimAbsen(float $latitude, float $longitude, float $akurasiMeter, string $fotoDataUrl): void
    {
        if ($this->step !== 'mulai') {
            return;
        }
        if ($this->terlaluSering('kirim', self::BATAS_KIRIM_PER_MENIT)) {
            $this->tampilkanHasil('error', 'Terlalu sering', 'Terlalu banyak percobaan absen. Tunggu 1 menit lalu coba lagi.');
            return;
        }

        $penolakan = $this->tolakKaryawan();
        if ($penolakan) {
            $this->tampilkanHasil('error', 'Tidak bisa absen', $penolakan);
            return;
        }
        $karyawan = $this->karyawan;

        // Posisi divalidasi ulang — hasil cekLokasi() di browser tidak dipercaya.
        $periksa = LokasiAbsen::periksa($karyawan->emp_lokasi_id, $latitude, $longitude, $akurasiMeter);
        if (!$periksa['dalamRadius']) {
            $this->tampilkanHasil('error', 'Di luar area absen', $periksa['pesan']);
            return;
        }

        $sekarang = now();
        $keputusan = Absensi::tentukanAksi($karyawan->emp_id, $this->shifts, $sekarang);
        if (in_array($keputusan['aksi'], ['info', 'error'], true)) {
            $this->tampilkanHasil($keputusan['aksi'], $karyawan->emp_name, $keputusan['pesan']);
            return;
        }

        $jenis = $keputusan['aksi'] === 'pulang' ? 'pulang' : 'masuk';
        $pathFoto = LokasiAbsen::simpanFoto($fotoDataUrl, $karyawan->emp_id, $jenis);
        if (!$pathFoto) {
            $this->tampilkanHasil('error', 'Foto tidak valid', 'Foto gagal dibaca. Ulangi ambil foto.');
            return;
        }

        $bukti = [
            'lokasi_id'     => $periksa['lokasi']->lokasi_id,
            'latitude'      => round($latitude, 7),
            'longitude'     => round($longitude, 7),
            'akurasi_meter' => (int) round($akurasiMeter),
            'jarak_meter'   => $periksa['jarakMeter'],
            'foto'          => $pathFoto,
        ];

        if ($keputusan['aksi'] === 'pulang') {
            $pesan = Absensi::catatPulang($keputusan['terbuka'], $sekarang, $this->kolomBukti('pulang', $bukti));
            $this->tampilkanHasil('pulang', $karyawan->emp_name, $pesan, $keputusan['terbuka']->abs_status);
            return;
        }
        if ($keputusan['aksi'] === 'masuk' && $keputusan['mungkinLupaMasuk']) {
            $this->buktiTertunda = $bukti;
            $this->shiftKonfirmasiId = $keputusan['shift']->shift_id;
            $this->step = 'konfirmasi-masuk';
            return;
        }
        if ($keputusan['aksi'] === 'masuk') {
            $this->catatMasuk($keputusan['shift'], true, $bukti);
            return;
        }

        // pilih-shift: tahan bukti sampai karyawan memilih shift.
        $this->buktiTertunda = $bukti;
        $this->catatanJadwal = $keputusan['catatan'];
        $this->saranShiftId = $keputusan['saranShiftId'];
        $this->step = 'pilih-shift';
    }

    public function pilihShift(string $shiftId): void
    {
        if ($this->step !== 'pilih-shift' || !$this->buktiTertunda) {
            return;
        }

        $shift = $this->shiftPilihan->firstWhere('shift_id', $shiftId);
        if (!$shift) {
            return;
        }

        $bukti = $this->buktiTertunda;
        $this->buktiTertunda = null;
        $this->catatMasuk($shift, false, $bukti);
    }

    #[Computed]
    public function shiftKonfirmasi(): ?object
    {
        return $this->shiftKonfirmasiId ? $this->shifts->firstWhere('shift_id', $this->shiftKonfirmasiId) : null;
    }

    /** $pilihan: masuk = memang baru datang (terlambat) | pulang = lupa absen masuk, sekarang mau pulang. */
    public function konfirmasiMasuk(string $pilihan): void
    {
        $shift = $this->shiftKonfirmasi;
        if ($this->step !== 'konfirmasi-masuk' || !$this->buktiTertunda || !$shift || !in_array($pilihan, ['masuk', 'pulang'], true)) {
            return;
        }

        $bukti = $this->buktiTertunda;
        $this->buktiTertunda = null;
        if ($pilihan === 'masuk') {
            $this->catatMasuk($shift, true, $bukti);
            return;
        }

        $hasilPulang = Absensi::catatPulangTanpaMasuk($this->karyawan->emp_id, $shift, now(), 'MOBILE', auth()->id(),
            ['lokasi_id' => $bukti['lokasi_id'], ...$this->kolomBukti('pulang', $bukti)]);
        if ($hasilPulang['tipe'] !== 'pulang') {
            Storage::disk('local')->delete($bukti['foto']);
        }
        $this->tampilkanHasil($hasilPulang['tipe'], $this->karyawan->emp_name, $hasilPulang['pesan'], $hasilPulang['status']);
    }

    public function batalPilihShift(): void
    {
        $this->batalkanBuktiTertunda();
        $this->selesai();
    }

    /** Kembali ke layar awal karyawan yang sama (mis. coba lagi setelah di luar radius). */
    public function selesai(): void
    {
        $this->reset(['catatanJadwal', 'saranShiftId', 'buktiTertunda', 'shiftKonfirmasiId', 'hasil']);
        $this->step = $this->empId ? 'mulai' : 'input-id';
        unset($this->statusHariIni);
    }

    private function catatMasuk(object $shift, bool $sesuaiJadwal, array $bukti): void
    {
        $hasilMasuk = Absensi::catatMasuk($this->karyawan->emp_id, $shift, $sesuaiJadwal, now(), 'MOBILE', auth()->id(),
            ['lokasi_id' => $bukti['lokasi_id'], ...$this->kolomBukti('masuk', $bukti)]);

        if ($hasilMasuk['tipe'] !== 'masuk') {
            Storage::disk('local')->delete($bukti['foto']);
        }
        $this->tampilkanHasil($hasilMasuk['tipe'], $this->karyawan->emp_name, $hasilMasuk['pesan'], $hasilMasuk['status']);
    }

    /** Bukti → kolom masuk_* / pulang_* siaptxn_absensis. */
    private function kolomBukti(string $jenis, array $bukti): array
    {
        return [
            "{$jenis}_latitude"      => $bukti['latitude'],
            "{$jenis}_longitude"     => $bukti['longitude'],
            "{$jenis}_akurasi_meter" => $bukti['akurasi_meter'],
            "{$jenis}_jarak_meter"   => $bukti['jarak_meter'],
            "{$jenis}_foto"          => $bukti['foto'],
        ];
    }

    private function batalkanBuktiTertunda(): void
    {
        if ($this->buktiTertunda) {
            Storage::disk('local')->delete($this->buktiTertunda['foto']);
        }
    }

    /** Alasan karyawan tidak boleh absen, null bila boleh. */
    private function tolakKaryawan(): ?string
    {
        if (!PengaturanAbsensi::absenHpAktif()) {
            return 'Absen HP sedang dinonaktifkan HRD. Silakan absen di kiosk.';
        }
        if (!$this->karyawan) {
            return $this->empId ? "ID \"{$this->empId}\" tidak terdaftar sebagai karyawan." : 'Isi ID karyawan dulu.';
        }
        if ((string) $this->karyawan->active_status !== '1') {
            return 'Karyawan berstatus non-aktif. Hubungi HRD.';
        }

        return null;
    }

    private function terlaluSering(string $aksi, int $batasPerMenit): bool
    {
        $kunci = 'absen-hp:' . $aksi . ':' . request()->ip();
        if (RateLimiter::tooManyAttempts($kunci, $batasPerMenit)) {
            return true;
        }
        RateLimiter::hit($kunci, 60);

        return false;
    }

    private function tampilkanHasil(string $tipe, string $judul, string $pesan, ?string $status = null): void
    {
        $this->hasil = compact('tipe', 'judul', 'pesan', 'status');
        $this->step = 'hasil';
        unset($this->statusHariIni);
    }
};
?>

{{-- Absen HP — tampilan cerah & sederhana: kepala logo RS, jam, kartu langkah (ID → Lokasi → Selfie), kartu hasil.
     Logika GPS / selfie / kirim tetap di Alpine blok "mulai" (tidak berubah). --}}
<div class="min-h-dvh px-4 pt-5 pb-8 bg-surface-soft"
    x-data="{
        jam: '', tanggal: '', t: null,
        tick() {
            const d = new Date();
            const dua = (n) => String(n).padStart(2, '0');
            this.jam = dua(d.getHours()) + ':' + dua(d.getMinutes());
            this.tanggal = d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
        },
        init() { this.tick(); this.t = setInterval(() => this.tick(), 1000) },
        destroy() { clearInterval(this.t) },
    }"
    x-on:absen-hp-ingat-id.window="try { localStorage.setItem('absenHpEmpId', $event.detail.empId) } catch (galat) {}">

    <div class="w-full max-w-md mx-auto space-y-4">

        {{-- Kepala: logo RS + nama layar --}}
        @php $logoRs = \App\Support\Identitas::logoUrl(); @endphp
        <header class="flex items-center justify-between gap-3">
            @if ($logoRs)
                <img src="{{ $logoRs }}" alt="{{ \App\Support\Identitas::get()->nama }}" class="object-contain w-auto h-11 max-w-[60%]">
            @else
                <span class="text-lg font-bold text-brand-green">{{ \App\Support\Identitas::namaPendek() }}</span>
            @endif
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide rounded-full bg-brand-softer text-fg-brand-strong">
                <x-absensi.ikon nama="lokasi" class="w-4 h-4" /> Absen HP
            </span>
        </header>

        {{-- Jam (waktu HP — yang dicatat tetap jam server) --}}
        <div class="px-5 py-4 text-center border shadow-sm bg-canvas border-hairline rounded-3xl">
            <div class="font-mono text-5xl font-bold tracking-tight text-ink tabular-nums" x-text="jam"></div>
            <div class="mt-1 text-muted" x-text="tanggal"></div>
        </div>

        @if (!\App\Support\PengaturanAbsensi::absenHpAktif())
            <div class="p-6 text-center border border-t-4 shadow-sm bg-canvas border-hairline border-t-warning rounded-3xl" role="status">
                <span class="grid mx-auto mb-3 rounded-full w-14 h-14 place-items-center bg-warning-soft text-fg-warning">
                    <x-absensi.ikon nama="jeda" class="w-7 h-7" />
                </span>
                <p class="font-semibold text-ink">Absen HP sedang dinonaktifkan</p>
                <p class="mt-1 text-sm text-body">Silakan absen di kiosk, atau hubungi HRD.</p>
            </div>
        @endif

        {{-- STEP 1: INPUT ID --}}
        @if ($step === 'input-id' && \App\Support\PengaturanAbsensi::absenHpAktif())
            <form wire:key="absen-hp-input-id" wire:submit="pilihKaryawan"
                class="p-5 border shadow-sm bg-canvas border-hairline rounded-3xl"
                x-data
                x-init="try { const tersimpan = localStorage.getItem('absenHpEmpId'); if (tersimpan && !$wire.empIdInput) $wire.empIdInput = tersimpan; } catch (galat) {}">
                <x-absensi.langkah :aktif="1" />
                <label for="empIdInput" class="block mt-5 mb-2 text-sm font-semibold text-heading">ID Karyawan</label>
                <input id="empIdInput" type="text" autocomplete="off" autocapitalize="characters" enterkeyhint="go" spellcheck="false"
                    wire:model="empIdInput" wire:loading.attr="readonly" wire:target="pilihKaryawan"
                    class="w-full px-4 py-4 font-mono text-2xl text-center uppercase border-2 rounded-2xl bg-neutral-secondary-medium border-default-medium text-heading placeholder:normal-case placeholder:text-base placeholder:font-sans placeholder:text-gray-500 focus:border-brand focus:ring-brand"
                    placeholder="Contoh: PRW-001" />
                @error('empIdInput')
                    <p class="mt-2 text-sm text-center text-fg-danger-strong" role="alert">{{ $message }}</p>
                @enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="pilihKaryawan" class="mt-4 w-full inline-flex items-center justify-center gap-2 py-4 text-lg font-semibold text-white rounded-2xl bg-brand hover:bg-brand-strong focus:outline-none focus:ring-4 focus:ring-brand-medium active:scale-[.98] transition motion-reduce:transition-none disabled:opacity-60 disabled:cursor-wait">
                    <span wire:loading.remove wire:target="pilihKaryawan" class="inline-flex items-center gap-2">Lanjut <x-absensi.ikon nama="panah" class="w-5 h-5" /></span>
                    <span wire:loading wire:target="pilihKaryawan">Memeriksa...</span>
                </button>
            </form>
        @endif

        {{-- STEP 2–3: LOKASI → SELFIE → KIRIM (dikendalikan Alpine) --}}
        @if ($step === 'mulai' && $this->karyawan)
            <div wire:key="absen-hp-mulai-{{ $empId }}" class="p-5 border shadow-sm bg-canvas border-hairline rounded-3xl"
                x-data="{
                    fase: 'siap',            // siap | gps | luar | foto | preview | kirim
                    pesan: '', lokasiName: '', jarakLabel: '',
                    posisi: null, posisiWaktu: 0, foto: null, prosesFoto: false,
                    aman: window.isSecureContext,

                    async mulai() {
                        if (!this.aman) return;
                        this.fase = 'gps'; this.pesan = '';
                        try {
                            this.posisi = await this.ambilPosisi();
                            this.posisiWaktu = Date.now();
                        } catch (galat) {
                            this.fase = 'luar';
                            this.pesan = galat.code === 1
                                ? 'Izin lokasi ditolak. Izinkan akses lokasi untuk situs ini di pengaturan browser, lalu coba lagi.'
                                : 'Lokasi tidak didapat. Pastikan GPS menyala, lalu coba lagi.';
                            return;
                        }
                        let cek;
                        try {
                            cek = await $wire.cekLokasi(this.posisi.latitude, this.posisi.longitude, this.posisi.accuracy);
                        } catch (galat) { cek = null; }
                        // Sinyal putus / sesi habis → jangan tertahan di layar mencari lokasi, beri jalan Coba Lagi.
                        if (!cek) { this.fase = 'luar'; this.pesan = 'Gagal menghubungi server. Periksa sinyal internet, lalu coba lagi.'; return; }
                        this.lokasiName = cek.lokasiName ?? ''; this.jarakLabel = cek.jarakLabel ?? '';
                        if (!cek.dalamRadius) { this.fase = 'luar'; this.pesan = cek.pesan; return; }
                        this.fase = 'foto';
                    },

                    ambilPosisi() {
                        return new Promise((selesai, gagal) => navigator.geolocation.getCurrentPosition(
                            (hasil) => selesai(hasil.coords), gagal,
                            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }));
                    },

                    // Foto dari kamera / galeri dikecilkan ke ±640px JPEG supaya kirimnya ringan.
                    async fotoDipilih(event) {
                        const berkas = event.target.files[0];
                        event.target.value = '';
                        if (!berkas) return;
                        this.prosesFoto = true;
                        try {
                            const gambar = new Image();
                            const url = URL.createObjectURL(berkas);
                            await new Promise((selesai, gagal) => { gambar.onload = selesai; gambar.onerror = gagal; gambar.src = url; });
                            const skala = Math.min(1, 640 / Math.max(gambar.naturalWidth, gambar.naturalHeight));
                            const kanvas = document.createElement('canvas');
                            kanvas.width = Math.round(gambar.naturalWidth * skala);
                            kanvas.height = Math.round(gambar.naturalHeight * skala);
                            kanvas.getContext('2d').drawImage(gambar, 0, 0, kanvas.width, kanvas.height);
                            URL.revokeObjectURL(url);
                            this.foto = kanvas.toDataURL('image/jpeg', 0.75);
                            this.fase = 'preview'; this.pesan = '';
                        } catch (galat) {
                            this.pesan = 'Foto tidak bisa dibaca. Coba ambil ulang.';
                        } finally {
                            this.prosesFoto = false;
                        }
                    },

                    async kirim() {
                        this.fase = 'kirim';
                        // Posisi diambil ulang bila sudah lebih dari 2 menit.
                        try {
                            if (Date.now() - this.posisiWaktu > 120000) this.posisi = await this.ambilPosisi();
                        } catch (galat) {} // gagal → pakai posisi awal; server tetap memvalidasi jaraknya
                        try {
                            await $wire.kirimAbsen(this.posisi.latitude, this.posisi.longitude, this.posisi.accuracy, this.foto);
                        } catch (galat) {} finally {
                            // Berhasil → server pindah langkah & blok ini hilang. Masih di 'kirim' = gagal kirim:
                            // kembali ke pratinjau supaya bisa tekan Kirim lagi (foto tidak perlu diambil ulang).
                            if (this.fase === 'kirim') { this.fase = 'preview'; this.pesan = 'Gagal mengirim. Periksa sinyal internet, lalu tekan Kirim Absen lagi.'; }
                        }
                    },
                }">

                <x-absensi.langkah aktif-expr="['foto', 'preview', 'kirim'].includes(fase) ? 3 : 2" />

                {{-- Identitas karyawan --}}
                <div class="flex items-center gap-3 p-3 mt-5 rounded-2xl bg-surface-soft">
                    <span class="grid w-12 h-12 rounded-full place-items-center bg-brand-softer text-fg-brand-strong shrink-0">
                        <x-absensi.ikon nama="orang" class="w-6 h-6" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-lg font-bold leading-tight truncate text-ink">{{ $this->karyawan->emp_name }}</p>
                        <p class="font-mono text-sm text-muted">{{ $this->karyawan->emp_id }}</p>
                        <p class="text-xs text-muted">Lokasi kerja: <b>{{ $this->karyawan->lokasi_name ?? 'semua titik RS/klinik aktif' }}</b></p>
                    </div>
                </div>
                <p class="mt-3 text-sm text-center text-body">{{ $this->statusHariIni }}</p>

                {{-- Bukan HTTPS --}}
                <template x-if="!aman">
                    <p class="p-3 mt-4 text-sm border rounded-xl border-danger-subtle bg-danger-soft text-fg-danger-strong" role="alert">
                        Halaman ini harus dibuka lewat <b>HTTPS</b> agar GPS bisa dipakai. Hubungi admin.
                    </p>
                </template>

                {{-- Siap / gagal --}}
                <div x-show="aman && (fase === 'siap' || fase === 'luar')" class="mt-5">
                    <div x-show="fase === 'luar'" role="alert" class="flex gap-2 p-3 mb-3 text-sm border rounded-xl border-warning-subtle bg-warning-soft text-fg-warning">
                        <x-absensi.ikon nama="peringatan" class="w-5 h-5" />
                        <p x-text="pesan"></p>
                    </div>
                    <button type="button" x-on:click="mulai()" class="w-full inline-flex items-center justify-center gap-2 py-4 text-lg font-semibold text-white rounded-2xl bg-brand hover:bg-brand-strong focus:outline-none focus:ring-4 focus:ring-brand-medium active:scale-[.98] transition motion-reduce:transition-none disabled:opacity-60 disabled:cursor-wait">
                        <span x-show="fase !== 'luar'" class="inline-flex items-center gap-2"><x-absensi.ikon nama="lokasi" class="w-5 h-5" /> Mulai Absen</span>
                        <span x-show="fase === 'luar'" x-cloak class="inline-flex items-center gap-2"><x-absensi.ikon nama="ulang" class="w-5 h-5" /> Coba Lagi</span>
                    </button>
                    <p class="mt-2 text-xs text-center text-muted">Nyalakan GPS. Anda akan diminta izin lokasi.</p>
                </div>

                {{-- Mengambil lokasi --}}
                <div x-show="fase === 'gps'" x-cloak class="flex flex-col items-center gap-3 py-8 text-center text-muted" role="status">
                    <span class="grid w-14 h-14 rounded-full place-items-center bg-brand-softer text-fg-brand-strong animate-pulse motion-reduce:animate-none">
                        <x-absensi.ikon nama="lokasi" class="w-7 h-7" />
                    </span>
                    Mencari lokasi Anda...
                </div>

                {{-- Foto --}}
                <div x-show="fase === 'foto' || fase === 'preview' || fase === 'kirim'" x-cloak class="mt-5">
                    <p class="flex items-center justify-center gap-1.5 mb-3 text-sm text-center text-fg-success-strong">
                        <x-absensi.ikon nama="cek" class="w-5 h-5" />
                        <span>Di area <b x-text="lokasiName"></b> (<span x-text="jarakLabel"></span>)</span>
                    </p>

                    {{-- Pesan galat foto / kirim --}}
                    <p x-show="pesan && (fase === 'foto' || fase === 'preview')" x-text="pesan" role="alert"
                        class="p-3 mb-3 text-sm border rounded-xl border-danger-subtle bg-danger-soft text-fg-danger-strong"></p>

                    <input x-ref="inputFoto" type="file" accept="image/*" capture="user" class="hidden" x-on:change="fotoDipilih($event)" />

                    <div x-show="fase !== 'foto'" class="overflow-hidden bg-gray-900 aspect-square rounded-2xl">
                        <img :src="foto" alt="Pratinjau selfie" class="object-cover w-full h-full" />
                    </div>

                    <button type="button" x-show="fase === 'foto'" x-on:click="$refs.inputFoto.click()" :disabled="prosesFoto" class="w-full inline-flex items-center justify-center gap-2 py-4 text-lg font-semibold text-white rounded-2xl bg-brand hover:bg-brand-strong focus:outline-none focus:ring-4 focus:ring-brand-medium active:scale-[.98] transition motion-reduce:transition-none disabled:opacity-60 disabled:cursor-wait">
                        <x-absensi.ikon nama="kamera" class="w-5 h-5" />
                        <span x-text="prosesFoto ? 'Memproses foto...' : 'Ambil Selfie'"></span>
                    </button>

                    <div x-show="fase === 'preview'" class="grid grid-cols-2 gap-3 mt-4">
                        <button type="button" x-on:click="$refs.inputFoto.click()"
                            class="inline-flex items-center justify-center gap-2 py-4 font-semibold border-2 rounded-2xl border-default-medium text-heading hover:bg-surface-card focus:outline-none focus:ring-4 focus:ring-brand-medium">
                            <x-absensi.ikon nama="ulang" class="w-5 h-5" /> Ulang Foto
                        </button>
                        <button type="button" x-on:click="kirim()"
                            class="inline-flex items-center justify-center gap-2 py-4 font-semibold text-white rounded-2xl bg-brand hover:bg-brand-strong focus:outline-none focus:ring-4 focus:ring-brand-medium">
                            Kirim <x-absensi.ikon nama="panah" class="w-5 h-5" />
                        </button>
                    </div>

                    <p x-show="fase === 'kirim'" class="py-4 text-center text-muted" role="status">Mengirim absen...</p>
                </div>

                <button type="button" wire:click="gantiId" x-show="fase !== 'kirim'" class="w-full inline-flex items-center justify-center min-h-[44px] mt-3 text-sm font-semibold rounded-xl text-muted hover:text-ink hover:bg-surface-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Bukan saya — ganti ID</button>
            </div>
        @endif

        {{-- STEP: PILIH SHIFT --}}
        @if ($step === 'pilih-shift' && $this->karyawan)
            <div wire:key="absen-hp-pilih-shift" class="p-5 border shadow-sm bg-canvas border-hairline rounded-3xl">
                <p class="text-sm text-center text-muted">Absen masuk untuk</p>
                <p class="mb-4 text-2xl font-bold text-center text-ink">{{ $this->karyawan->emp_name }}</p>
                @if ($catatanJadwal)
                    <p class="p-3 mb-4 text-sm text-center border rounded-xl border-warning-subtle bg-warning-soft text-fg-warning">{{ $catatanJadwal }}</p>
                @endif
                <p class="mb-3 font-semibold text-center text-ink">Pilih shift Anda:</p>
                <div class="grid grid-cols-1 gap-3">
                    @foreach ($this->shiftPilihan as $shift)
                        @php $disarankan = $shift->shift_id === $saranShiftId; @endphp
                        <button type="button" wire:click="pilihShift('{{ $shift->shift_id }}')" wire:loading.attr="disabled" wire:target="pilihShift"
                            class="w-full flex items-start gap-3 px-4 py-4 text-left border-2 rounded-2xl transition focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-medium {{ $disarankan ? 'border-brand bg-brand-softer' : 'border-default-medium hover:border-brand' }}">
                            <x-absensi.ikon nama="jam" class="w-6 h-6 mt-0.5 text-fg-brand" />
                            <span class="flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="text-lg font-semibold text-ink">{{ $shift->shift_name }}</span>
                                    @if ($disarankan)
                                        <span class="px-2 py-0.5 text-xs font-semibold text-white rounded-full bg-brand">Disarankan</span>
                                    @endif
                                </span>
                                <span class="block font-mono text-muted">{{ $shift->jam_masuk }} – {{ $shift->jam_pulang }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>
                <button type="button" wire:click="batalPilihShift" class="w-full inline-flex items-center justify-center min-h-[44px] mt-3 text-sm font-semibold rounded-xl text-muted hover:text-ink hover:bg-surface-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Batal</button>
            </div>
        @endif

        {{-- STEP: KONFIRMASI (absen setelah separuh shift — mungkin lupa absen masuk) --}}
        @if ($step === 'konfirmasi-masuk' && $this->karyawan && $this->shiftKonfirmasi)
            @php $shift = $this->shiftKonfirmasi; @endphp
            <div wire:key="absen-hp-konfirmasi" class="p-5 border border-t-4 shadow-sm bg-canvas border-hairline border-t-warning rounded-3xl">
                <p class="text-2xl font-bold text-center text-ink">{{ $this->karyawan->emp_name }}</p>
                <p class="mt-2 text-center text-body">
                    Anda <b>belum absen masuk</b> untuk {{ $shift->shift_name }}
                    <span class="font-mono">({{ $shift->jam_masuk }}–{{ $shift->jam_pulang }})</span>. Absen ini untuk apa?
                </p>
                <div class="grid grid-cols-1 gap-3 mt-4">
                    <button type="button" wire:click="konfirmasiMasuk('masuk')" wire:loading.attr="disabled" wire:target="konfirmasiMasuk"
                        class="w-full flex items-start gap-3 px-4 py-4 text-left border-2 rounded-2xl transition focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-medium border-default-medium hover:border-brand">
                        <x-absensi.ikon nama="masuk" class="w-6 h-6 mt-0.5 text-fg-brand" />
                        <span><span class="block text-lg font-semibold text-ink">Saya baru datang</span>
                            <span class="block text-sm text-muted">Catat MASUK sekarang (terlambat)</span></span>
                    </button>
                    <button type="button" wire:click="konfirmasiMasuk('pulang')" wire:loading.attr="disabled" wire:target="konfirmasiMasuk"
                        class="w-full flex items-start gap-3 px-4 py-4 text-left border-2 rounded-2xl transition focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-medium border-default-medium hover:border-brand">
                        <x-absensi.ikon nama="pulang" class="w-6 h-6 mt-0.5 text-fg-brand" />
                        <span><span class="block text-lg font-semibold text-ink">Saya lupa absen masuk, mau pulang</span>
                            <span class="block text-sm text-muted">Catat PULANG — jam masuk dilengkapi HRD</span></span>
                    </button>
                </div>
                <button type="button" wire:click="batalPilihShift" class="w-full inline-flex items-center justify-center min-h-[44px] mt-3 text-sm font-semibold rounded-xl text-muted hover:text-ink hover:bg-surface-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Batal</button>
            </div>
        @endif

        {{-- STEP: HASIL --}}
        @if ($step === 'hasil' && $hasil)
            <x-absensi.kartu-hasil wire:key="absen-hp-hasil" :hasil="$hasil">
                <button type="button" wire:click="selesai"
                    class="w-full py-3 mt-6 font-semibold border-2 rounded-2xl border-default-medium text-heading hover:bg-surface-card focus:outline-none focus:ring-4 focus:ring-brand-medium">Selesai</button>
            </x-absensi.kartu-hasil>
        @endif

        @if (in_array($step, ['input-id', 'mulai'], true) && \App\Support\PengaturanAbsensi::absenHpAktif())
            <x-absensi.cara-absen mode="hp" />
        @endif
    </div>
</div>
