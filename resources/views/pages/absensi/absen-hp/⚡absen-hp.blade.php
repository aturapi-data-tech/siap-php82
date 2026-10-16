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

<div class="min-h-screen px-4 py-6 bg-surface-soft"
    x-data="{
        jam: '', tanggal: '',
        tick() {
            const d = new Date();
            const dua = (n) => String(n).padStart(2, '0');
            this.jam = dua(d.getHours()) + ':' + dua(d.getMinutes());
            this.tanggal = d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
        },
    }"
    x-init="tick(); setInterval(() => tick(), 1000)"
    x-on:absen-hp-ingat-id.window="try { localStorage.setItem('absenHpEmpId', $event.detail.empId) } catch (galat) {}">

    <div class="w-full max-w-md mx-auto">

        {{-- Kepala --}}
        <div class="flex items-center gap-2 mb-5">
            <img src="{{ asset('images/Logogram black solid.png') }}" alt="Logo" class="w-8 h-8" />
            <span class="text-lg font-bold tracking-tight text-brand-green">SIAP · Absen HP</span>
        </div>
        <div class="mb-5 text-center">
            <div class="font-mono text-5xl font-bold tracking-tight text-ink tabular-nums" x-text="jam"></div>
            <div class="mt-1 text-muted" x-text="tanggal"></div>
        </div>

        @if (!\App\Support\PengaturanAbsensi::absenHpAktif())
            <div class="p-6 text-center border-2 border-amber-300 bg-amber-50 rounded-3xl">
                <div class="mb-2 text-4xl">⏸️</div>
                <p class="font-semibold text-ink">Absen HP sedang dinonaktifkan</p>
                <p class="mt-1 text-sm text-body">Silakan absen di kiosk, atau hubungi HRD.</p>
            </div>
        @endif

        {{-- STEP: INPUT ID --}}
        @if ($step === 'input-id' && \App\Support\PengaturanAbsensi::absenHpAktif())
            <form wire:key="absen-hp-input-id" wire:submit="pilihKaryawan"
                class="p-5 border shadow-sm bg-canvas border-hairline rounded-3xl"
                x-data
                x-init="try { const tersimpan = localStorage.getItem('absenHpEmpId'); if (tersimpan && !$wire.empIdInput) $wire.empIdInput = tersimpan; } catch (galat) {}">
                <label for="empIdInput" class="block mb-3 font-semibold text-center text-ink">ID Karyawan</label>
                <input id="empIdInput" type="text" autocomplete="off" autocapitalize="characters"
                    wire:model="empIdInput"
                    class="w-full px-4 py-4 font-mono text-2xl text-center uppercase border-2 rounded-2xl border-hairline focus:border-brand-green focus:ring-brand-green"
                    placeholder="Contoh: PRW-001" />
                @error('empIdInput')
                    <p class="mt-2 text-sm text-center text-red-600">{{ $message }}</p>
                @enderror
                <button type="submit" wire:loading.attr="disabled"
                    class="w-full py-4 mt-4 text-lg font-semibold text-white rounded-2xl bg-brand-green active:scale-[.98] transition">
                    <span wire:loading.remove wire:target="pilihKaryawan">Lanjut</span>
                    <span wire:loading wire:target="pilihKaryawan">Memeriksa...</span>
                </button>
            </form>
        @endif

        {{-- STEP: MULAI (GPS → foto → kirim, dikendalikan Alpine) --}}
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
                        const cek = await $wire.cekLokasi(this.posisi.latitude, this.posisi.longitude, this.posisi.accuracy);
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
                            this.fase = 'preview';
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
                        await $wire.kirimAbsen(this.posisi.latitude, this.posisi.longitude, this.posisi.accuracy, this.foto);
                    },
                }">

                <p class="text-sm text-center text-muted">Absen sebagai</p>
                <p class="text-2xl font-bold text-center text-ink">{{ $this->karyawan->emp_name }}</p>
                <p class="font-mono text-sm text-center text-muted">{{ $this->karyawan->emp_id }}</p>
                <p class="mt-1 text-xs text-center text-muted">
                    Lokasi kerja: <b>{{ $this->karyawan->lokasi_name ?? 'semua titik RS/klinik aktif' }}</b>
                </p>
                <p class="p-2 mt-3 text-sm text-center border rounded-xl border-hairline bg-surface-soft text-body">{{ $this->statusHariIni }}</p>

                {{-- Bukan HTTPS --}}
                <template x-if="!aman">
                    <p class="p-3 mt-4 text-sm border rounded-xl border-red-300 bg-red-50 text-red-900">
                        Halaman ini harus dibuka lewat <b>HTTPS</b> agar GPS bisa dipakai. Hubungi admin.
                    </p>
                </template>

                {{-- Siap / gagal --}}
                <div x-show="aman && (fase === 'siap' || fase === 'luar')" class="mt-5">
                    <div x-show="fase === 'luar'" class="p-3 mb-3 text-sm border rounded-xl border-amber-300 bg-amber-50 text-amber-900">
                        <p x-text="pesan"></p>
                    </div>
                    <button type="button" x-on:click="mulai()"
                        class="w-full py-4 text-lg font-semibold text-white rounded-2xl bg-brand-green active:scale-[.98] transition">
                        <span x-text="fase === 'luar' ? '↻ Coba Lagi' : '📍 Mulai Absen'"></span>
                    </button>
                    <p class="mt-2 text-xs text-center text-muted">Nyalakan GPS. Anda akan diminta izin lokasi.</p>
                </div>

                {{-- Mengambil lokasi --}}
                <div x-show="fase === 'gps'" class="py-8 text-center text-muted">
                    <div class="mb-2 text-3xl animate-pulse">📍</div>
                    Mencari lokasi Anda...
                </div>

                {{-- Foto --}}
                <div x-show="fase === 'foto' || fase === 'preview' || fase === 'kirim'" class="mt-4">
                    <p class="mb-3 text-sm text-center text-emerald-700">
                        ✓ Di area <b x-text="lokasiName"></b> (<span x-text="jarakLabel"></span>)
                    </p>

                    <input x-ref="inputFoto" type="file" accept="image/*" capture="user" class="hidden" x-on:change="fotoDipilih($event)" />

                    <div x-show="fase !== 'foto'" class="overflow-hidden bg-black aspect-square rounded-2xl">
                        <img :src="foto" alt="Selfie" class="object-cover w-full h-full" />
                    </div>

                    <button type="button" x-show="fase === 'foto'" x-on:click="$refs.inputFoto.click()" :disabled="prosesFoto"
                        class="w-full py-4 text-lg font-semibold text-white rounded-2xl bg-brand-green">
                        <span x-text="prosesFoto ? 'Memproses foto...' : '📸 Ambil / Upload Selfie'"></span>
                    </button>

                    <div x-show="fase === 'preview'" class="grid grid-cols-2 gap-3 mt-4">
                        <button type="button" x-on:click="$refs.inputFoto.click()"
                            class="py-4 font-semibold border-2 rounded-2xl border-hairline text-ink">Ganti Foto</button>
                        <button type="button" x-on:click="kirim()"
                            class="py-4 font-semibold text-white rounded-2xl bg-brand-green">Kirim Absen</button>
                    </div>

                    <p x-show="fase === 'kirim'" class="py-4 text-center text-muted">Mengirim absen...</p>
                </div>

                <button type="button" wire:click="gantiId" x-show="fase !== 'kirim'"
                    class="w-full mt-5 text-sm font-semibold underline text-muted">Bukan saya — ganti ID</button>
            </div>
        @endif

        {{-- STEP: PILIH SHIFT --}}
        @if ($step === 'pilih-shift' && $this->karyawan)
            <div wire:key="absen-hp-pilih-shift" class="p-5 border shadow-sm bg-canvas border-hairline rounded-3xl">
                <p class="text-sm text-center text-muted">Absen masuk untuk</p>
                <p class="mb-4 text-2xl font-bold text-center text-ink">{{ $this->karyawan->emp_name }}</p>
                @if ($catatanJadwal)
                    <p class="p-3 mb-4 text-sm text-center border rounded-xl border-amber-300 bg-amber-50 text-amber-900">{{ $catatanJadwal }}</p>
                @endif
                <p class="mb-3 font-semibold text-center text-ink">Pilih shift Anda:</p>
                <div class="grid grid-cols-1 gap-3">
                    @foreach ($this->shiftPilihan as $shift)
                        @php $disarankan = $shift->shift_id === $saranShiftId; @endphp
                        <button type="button" wire:click="pilihShift('{{ $shift->shift_id }}')" wire:loading.attr="disabled"
                            class="relative px-5 py-4 text-left border-2 rounded-2xl
                                {{ $disarankan ? 'border-brand-green bg-brand-green/10' : 'border-hairline' }}">
                            @if ($disarankan)
                                <span class="absolute px-2 py-0.5 text-[11px] font-semibold text-white rounded-full -top-2.5 right-3 bg-brand-green">Disarankan</span>
                            @endif
                            <div class="text-lg font-semibold text-ink">{{ $shift->shift_name }}</div>
                            <div class="font-mono text-muted">{{ $shift->jam_masuk }} – {{ $shift->jam_pulang }}</div>
                        </button>
                    @endforeach
                </div>
                <button type="button" wire:click="batalPilihShift" class="w-full mt-5 text-sm font-semibold underline text-muted">Batal</button>
            </div>
        @endif

        {{-- STEP: KONFIRMASI (absen setelah separuh shift — mungkin lupa absen masuk) --}}
        @if ($step === 'konfirmasi-masuk' && $this->karyawan && $this->shiftKonfirmasi)
            @php $shift = $this->shiftKonfirmasi; @endphp
            <div wire:key="absen-hp-konfirmasi" class="p-5 border-4 shadow-sm border-amber-400 bg-amber-50 rounded-3xl">
                <p class="text-2xl font-bold text-center text-ink">{{ $this->karyawan->emp_name }}</p>
                <p class="mt-2 text-center text-body">
                    Anda <b>belum absen masuk</b> untuk {{ $shift->shift_name }}
                    <span class="font-mono">({{ $shift->jam_masuk }}–{{ $shift->jam_pulang }})</span>. Absen ini untuk apa?
                </p>
                <div class="grid grid-cols-1 gap-3 mt-4">
                    <button type="button" wire:click="konfirmasiMasuk('masuk')" wire:loading.attr="disabled"
                        class="px-5 py-4 text-left border-2 rounded-2xl border-hairline bg-canvas">
                        <div class="text-lg font-semibold text-ink">✅ Saya baru datang</div>
                        <div class="text-sm text-muted">Catat MASUK sekarang (terlambat)</div>
                    </button>
                    <button type="button" wire:click="konfirmasiMasuk('pulang')" wire:loading.attr="disabled"
                        class="px-5 py-4 text-left border-2 rounded-2xl border-hairline bg-canvas">
                        <div class="text-lg font-semibold text-ink">👋 Saya lupa absen masuk, mau pulang</div>
                        <div class="text-sm text-muted">Catat PULANG — jam masuk dilengkapi HRD</div>
                    </button>
                </div>
                <button type="button" wire:click="batalPilihShift" class="w-full mt-5 text-sm font-semibold underline text-muted">Batal</button>
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
            <div wire:key="absen-hp-hasil" class="p-6 text-center border-4 shadow-sm rounded-3xl {{ $warna }}">
                <div class="mb-2 text-5xl">{{ $ikon }}</div>
                <p class="text-2xl font-bold text-ink">{{ $hasil['judul'] }}</p>
                <p class="mt-2 text-lg text-body">{{ $hasil['pesan'] }}</p>
                <button type="button" wire:click="selesai"
                    class="w-full py-3 mt-6 font-semibold border-2 rounded-2xl border-hairline bg-canvas text-ink">Selesai</button>
            </div>
        @endif

        @if (in_array($step, ['input-id', 'mulai'], true) && \App\Support\PengaturanAbsensi::absenHpAktif())
            <x-absensi.cara-absen mode="hp" class="mt-4" />
        @endif
    </div>
</div>
