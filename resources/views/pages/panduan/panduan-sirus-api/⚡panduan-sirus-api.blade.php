<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Support\GajiDokterSirus;

/**
 * PANDUAN BRIDGING SIRUS — SIAP sebagai CLIENT API gaji dokter (statis + tombol Uji sambungan, baca saja).
 * Versi server-nya ada di SIRUS: menu Sistem → Panduan API SIAP (docs/siap-api.md). Hanya role gaji.kelola.
 */
new class extends Component {
    public ?array $hasilUji = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function ujiSambungan(): void
    {
        $mulai = microtime(true);
        $this->hasilUji = GajiDokterSirus::ujiSambungan() + ['detik' => round(microtime(true) - $mulai, 2), 'waktu' => now()->format('d/m/Y H:i:s')];
    }

    #[Computed]
    public function ambilTerakhir(): ?object
    {
        return GajiDokterSirus::riwayatAmbil(1)->first();
    }
};
?>

@php
    $tautan = fn(string $route, string $label, array $parameter = []) => \Illuminate\Support\Facades\Route::has($route)
        ? '<a href="' . route($route, $parameter) . '" wire:navigate class="font-semibold text-brand-green underline hover:no-underline dark:text-brand-lime">' . e($label) . '</a>'
        : '<b>' . e($label) . '</b>';
    $bagian = [
        'alur' => 'Gambaran alur',
        'status' => 'Status sambungan',
        'setup' => '1. Setup (.env & akun)',
        'token' => '2. Token & pemanggilan',
        'endpoint' => '3. Endpoint yang dipakai',
        'snapshot' => '4. Snapshot & versi',
        'pemetaan' => '5. Pemetaan dokter',
        'rutin' => '6. Langkah rutin bulanan',
        'masalah' => '7. Masalah & penanganan',
        'kontrak' => '8. Kontrak data (untuk pengembang)',
    ];
    $aktif = GajiDokterSirus::aktif();
    $url = (string) config('services.sirus.url', '');
    $user = (string) config('services.sirus.user', '');
    $ambilTerakhir = $this->ambilTerakhir;
@endphp

<div>
    <x-page-title title="Panduan Bridging SIRUS" subtitle="SIAP sebagai client API gaji dokter SIRUS: setup .env & akun → token → tarik slip per bulan jasa → snapshot berversi → pemetaan dokter → penanganan masalah" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="grid grid-cols-1 gap-6 px-6 py-6 mx-auto max-w-7xl lg:grid-cols-4">

            {{-- DAFTAR ISI --}}
            <nav class="lg:sticky lg:top-24 lg:self-start">
                <div class="p-4 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <p class="mb-2 text-xs font-semibold tracking-wide uppercase text-muted">Daftar isi</p>
                    <ul class="space-y-1 text-sm">
                        @foreach ($bagian as $id => $judul)
                            <li><a href="#{{ $id }}" class="block px-2 py-1 rounded-lg text-body hover:bg-surface-soft hover:text-brand-green dark:text-gray-300">{{ $judul }}</a></li>
                        @endforeach
                    </ul>
                    <p class="pt-3 mt-3 text-xs border-t border-hairline text-muted">
                        Buka modul: {!! $tautan('keuangan.gaji-dokter', 'Gaji Dokter (SIRUS)') !!} · {!! $tautan('panduan.gaji', 'Panduan Gaji §12') !!}<br>
                        Versi server: SIRUS → Sistem → <b>Panduan API SIAP</b>
                    </p>
                </div>
            </nav>

            {{-- ISI --}}
            <article class="space-y-6 lg:col-span-3 text-body dark:text-gray-300 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-ink dark:[&_h2]:text-gray-100 [&_h3]:font-semibold [&_h3]:text-ink dark:[&_h3]:text-gray-100 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:space-y-1.5 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">

                {{-- ALUR --}}
                <section id="alur" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Gambaran alur</h2>
                    <p class="mt-2 text-sm">Gaji dokter spesialis dihitung di <b>SIRUS</b> (modul Gaji Dokter). SIAP adalah <b>client</b>: meminta token, menarik slip + lampiran satu bulan jasa, lalu menyimpannya sebagai snapshot. SIAP tidak pernah menulis ke SIRUS, dan SIRUS tidak pernah memanggil SIAP.</p>
                    <div class="grid grid-cols-1 gap-2 mt-4 text-sm sm:grid-cols-3 xl:grid-cols-5">
                        @foreach ([
                            ['Setup', '.env SIAP: URL, user, password akun API', 'sekali'],
                            ['Token', 'POST /api/siap/token → JWT 1 jam, di-cache 45 menit', 'otomatis'],
                            ['Tarik', 'master dokter + slip bulan jasa + lampiran', 'tiap Ambil'],
                            ['Snapshot', 'checksum per dokter → sama / versi baru', 'tiap Ambil'],
                            ['Pakai', 'Buku, Rincian & banding, Lampiran, Pemetaan', 'tiap bulan'],
                        ] as $urutan => [$judul, $isi, $kapan])
                            <div class="p-3 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                                <div class="text-xs font-semibold text-brand-green dark:text-brand-lime">{{ $urutan + 1 }} · {{ $kapan }}</div>
                                <div class="font-semibold text-ink dark:text-gray-100">{{ $judul }}</div>
                                <div class="text-xs text-muted">{{ $isi }}</div>
                            </div>
                        @endforeach
                    </div>
                    <pre class="p-4 mt-4 overflow-x-auto text-xs leading-relaxed border rounded-xl bg-surface-soft border-hairline text-ink dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700">SIAP                                                SIRUS
App\Support\GajiDokterSirus                         /api/siap  (prefix di host SIRUS biasa)
  token()      ──── POST /token {username,password} ──►  akun users ber-role "Api Siap" → JWT
  ambil(bulan) ──── GET /dokter                     ──►  rsmst_doctors
               ──── GET /gaji-dokter/{thn}/{bln}    ──►  rstxn_gajidoctorhdrs + dtls
               ──── GET /gaji-dokter/…/lampiran     ──►  GajiDokterLampiran::susun
  checksum per dokter → siaptxn_gdok_hdrs/dtls/lamps (versi), siaptxn_gdok_ambils (jejak)
  Bearer token di setiap GET · 401 → minta token baru sekali · timeout 120 detik</pre>
                </section>

                {{-- STATUS --}}
                <section id="status" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Status sambungan</h2>
                    <div class="grid grid-cols-1 gap-3 mt-3 text-sm sm:grid-cols-3">
                        <div class="p-3 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                            <div class="text-xs text-muted">SIAP_API_URL</div>
                            <div class="font-mono text-ink dark:text-gray-100">{{ $url !== '' ? $url : '— belum diisi —' }}</div>
                        </div>
                        <div class="p-3 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                            <div class="text-xs text-muted">SIAP_API_USER</div>
                            <div class="font-mono text-ink dark:text-gray-100">{{ $user !== '' ? $user : '— belum diisi —' }}</div>
                        </div>
                        <div class="p-3 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                            <div class="text-xs text-muted">Ambil terakhir</div>
                            <div class="text-ink dark:text-gray-100">{{ $ambilTerakhir ? \Carbon\Carbon::parse($ambilTerakhir->diambil_at)->format('d/m/Y H:i') . ' · bulan jasa ' . $ambilTerakhir->periode . ' · ' . $ambilTerakhir->jumlah_slip . ' slip' : 'belum pernah' }}</div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 mt-4">
                        @if ($aktif)
                            <x-primary-button type="button" wire:click="ujiSambungan" wire:loading.attr="disabled" wire:target="ujiSambungan">
                                <span wire:loading.remove wire:target="ujiSambungan">Uji sambungan</span>
                                <span wire:loading wire:target="ujiSambungan">Menghubungi SIRUS…</span>
                            </x-primary-button>
                            <span class="text-xs text-muted">Login + ambil master dokter (baca saja, tidak menyimpan apa pun).</span>
                        @else
                            <x-badge variant="danger">API belum diatur — isi SIAP_API_URL / USER / PASSWORD di .env lalu php artisan config:clear</x-badge>
                        @endif
                    </div>
                    @if ($hasilUji)
                        <div class="mt-3" role="status" aria-live="polite">
                            <x-badge :variant="$hasilUji['ok'] ? 'success' : 'danger'">{{ $hasilUji['ok'] ? 'Tersambung' : 'Gagal' }} · {{ $hasilUji['detik'] }} detik · {{ $hasilUji['waktu'] }}</x-badge>
                            <p class="mt-1 font-mono text-xs break-all">{{ $hasilUji['pesan'] }}</p>
                        </div>
                    @endif
                </section>

                {{-- SETUP --}}
                <section id="setup" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>1. Setup (.env &amp; akun)</h2>
                    <ol class="mt-3 text-sm">
                        <li><b>Di server SIRUS</b> (admin SIRUS): isi <code>SIAP_API_JWT_SECRET</code> (acak ≥ 32 karakter) di <code>.env</code>, <code>php artisan config:clear</code>, lalu <code>php artisan siap-api:akun siap-gaji</code>. Perintah mencetak password akun sekali — simpan.</li>
                        <li><b>Di server SIAP</b>: isi tiga baris di <code>.env</code> lalu <code>php artisan config:clear</code>:</li>
                    </ol>
                    <pre class="p-4 mt-2 overflow-x-auto text-xs leading-relaxed border rounded-xl bg-surface-soft border-hairline text-ink dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700"># .env SIAP
SIAP_API_URL=https://sirus.rsimadinah.local      # host SIRUS biasa (atau SIAP_API_HOST bila SIRUS mengaturnya), tanpa /api/siap
SIAP_API_USER=siap-gaji                          # akun users SIRUS ber-role "Api Siap"
SIAP_API_PASSWORD=&lt;password dari siap-api:akun&gt;</pre>
                    <ol class="mt-3 text-sm" start="3">
                        <li>Klik <b>Uji sambungan</b> di atas. Berhasil → buka {!! $tautan('keuangan.gaji-dokter', 'Gaji Dokter (SIRUS)') !!} dan <b>Ambil dari SIRUS</b> untuk satu bulan jasa.</li>
                        <li>Setelah terbukti, admin SIRUS mengisi <code>SIAP_API_IP_WHITELIST</code> dengan IP server SIAP supaya pintu API hanya terbuka untuk SIAP.</li>
                    </ol>
                    <h3 class="mt-4">Tentang akun API</h3>
                    <ul class="mt-1 text-sm">
                        <li>Akun <i>siap-gaji</i> adalah user SIRUS biasa ber-role <b>Api Siap</b>: admin SIRUS bisa mengganti password atau menonaktifkannya dari User Control. Setelah itu Ambil di SIAP gagal 401 sampai <code>.env</code> SIAP diperbarui — snapshot yang sudah ada tidak terpengaruh.</li>
                        <li>Akun ini tidak bisa dipakai masuk ke web SIRUS, dan tidak punya menu apa pun.</li>
                        <li>Password hanya ada di <code>.env</code> SIAP dan tidak pernah ditampilkan di layar SIAP.</li>
                    </ul>
                </section>

                {{-- TOKEN --}}
                <section id="token" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>2. Token &amp; pemanggilan</h2>
                    <ol class="mt-3 text-sm">
                        <li>SIAP mengirim <code>POST /api/siap/token</code> dengan username + password → SIRUS membalas token JWT berumur 1 jam (<code>expires_in</code>).</li>
                        <li>Token disimpan di cache SIAP (kunci <code>sirus-api-token</code>) selama 45 menit; selama itu tidak login ulang.</li>
                        <li>Setiap GET membawa <code>Authorization: Bearer &lt;token&gt;</code>. Bila SIRUS menjawab 401 (token kedaluwarsa / rahasia diganti), SIAP membuang cache, minta token baru, dan mengulang permintaan <b>sekali</b>.</li>
                        <li>Timeout: 20 detik untuk token, 120 detik untuk data (lampiran semua dokter ±1 MB / ±3 detik).</li>
                        <li>Galat apa pun menjadi toast di layar (pesan <code>error</code> dari SIRUS diteruskan apa adanya) dan tidak mengubah snapshot.</li>
                    </ol>
                    <p class="mt-3 text-sm">Semua ini ada di <code>App\Support\GajiDokterSirus</code> (method <code>token()</code>, <code>get()</code>). Tidak ada kode lain di SIAP yang memanggil SIRUS.</p>
                </section>

                {{-- ENDPOINT --}}
                <section id="endpoint" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>3. Endpoint yang dipakai</h2>
                    <table class="mt-3 text-sm ds-table">
                        <thead><tr><th>Endpoint SIRUS</th><th>Dipanggil saat</th><th>Disimpan ke</th></tr></thead>
                        <tbody>
                            <tr><td><code>POST /api/siap/token</code></td><td>token belum ada / kedaluwarsa</td><td>cache 45 menit</td></tr>
                            <tr><td><code>GET /api/siap/dokter</code></td><td>Uji sambungan · Segarkan (tab Pemetaan) · awal tiap Ambil</td><td><code>siapmst_dokter_sirus</code> (master + pemetaan)</td></tr>
                            <tr><td><code>GET /api/siap/gaji-dokter/{tahun}/{bulan}</code></td><td>Ambil dari SIRUS (bulan jasa)</td><td><code>siaptxn_gdok_hdrs</code> + <code>siaptxn_gdok_dtls</code></td></tr>
                            <tr><td><code>GET /api/siap/gaji-dokter/{tahun}/{bulan}/lampiran</code></td><td>Ambil dari SIRUS (semua dokter sekaligus)</td><td><code>siaptxn_gdok_lamps</code></td></tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-sm">Periode selalu <b>bulan jasa</b> (bulan pelayanan). Di SIRUS slip bulan jasa Juli dibayar sebagai bulan gaji Agustus; kolom bulan gaji ikut tersimpan. Angka datang mentah seperti di SIRUS: potongan negatif, status Draft / Final apa adanya.</p>
                </section>

                {{-- SNAPSHOT --}}
                <section id="snapshot" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>4. Snapshot &amp; versi</h2>
                    <ol class="mt-3 text-sm">
                        <li>Tiap Ambil, slip tiap dokter diberi <b>checksum</b> dari header (status, skema, persen, semua total), komponen (jenis, kode, nilai, pasien), dan lampiran (komponen, no. transaksi, nominal).</li>
                        <li>Checksum <b>sama</b> dengan versi terbaru → tidak ada yang ditulis selain "terakhir diambil". Dihitung sebagai <i>sama</i>.</li>
                        <li>Checksum <b>beda</b> → versi lama ditandai bukan terbaru, versi baru (versi+1) disimpan lengkap (header, komponen, lampiran). Versi lama <b>tidak pernah dihapus atau diubah</b>. Dihitung sebagai <i>berubah</i>.</li>
                        <li>Dokter yang belum punya snapshot bulan itu → versi 1, dihitung <i>baru</i>.</li>
                        <li>Tiap Ambil dicatat di tab <b>Riwayat Ambil</b>: siapa, kapan, bulan jasa, baru / berubah / sama, baris lampiran, dokter tanpa pemetaan.</li>
                    </ol>
                    <p class="mt-3 text-sm">Akibatnya angka yang sudah diproses Keuangan tidak berubah diam-diam. Perubahan di SIRUS terlihat sebagai badge <i>versi N</i> di Buku, saringan "Pernah berubah", dan modal Rincian → <b>Bandingkan dengan</b> versi sebelumnya atau bulan jasa sebelumnya. Snapshot tidak bisa disunting di SIAP: koreksi dilakukan di SIRUS lalu Ambil ulang.</p>
                </section>

                {{-- PEMETAAN --}}
                <section id="pemetaan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>5. Pemetaan dokter</h2>
                    <ul class="mt-3 text-sm">
                        <li>Dokter SIRUS dikenali lewat <code>dr_id</code>; karyawan SIAP lewat <code>emp_id</code>. Pemetaannya disimpan di <code>siapmst_dokter_sirus</code>.</li>
                        <li><b>Otomatis</b> saat Segarkan / Ambil: nama dokter dan nama karyawan disamakan (huruf saja, gelar diabaikan); cocok persis dan hanya satu karyawan → dipetakan (cara OTO). Pemetaan yang sudah ada tidak ditimpa.</li>
                        <li><b>Manual</b> di tab Pemetaan: Petakan → LOV karyawan (cara MANUAL). Lepas bila salah.</li>
                        <li>Dokter tanpa pemetaan (dokter jaga, tamu, "TIDAK ADA", nama kosong) tetap ditarik dan ditandai — tidak memblokir Ambil.</li>
                    </ul>
                </section>

                {{-- RUTIN --}}
                <section id="rutin" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>6. Langkah rutin bulanan</h2>
                    <ol class="mt-3 text-sm">
                        <li>Setelah SIRUS menutup gaji dokter bulan jasa N: {!! $tautan('keuangan.gaji-dokter', 'Gaji Dokter (SIRUS)') !!} → Buku → pilih bulan jasa N → <b>Ambil dari SIRUS</b>.</li>
                        <li>Baca pesan hasil (baru / berubah / sama) dan badge "dokter belum dipetakan" → lengkapi di tab Pemetaan bila ada dokter baru.</li>
                        <li>Periksa kolom "vs bulan lalu"; yang mencolok dibuka Rincian / Lampiran.</li>
                        <li>Menjelang pembayaran, Ambil ulang: bila ada "berubah", Rincian → Bandingkan dengan versi sebelumnya.</li>
                    </ol>
                    <p class="mt-3 text-sm">Ambil aman diulang kapan saja — tidak ada tulis ke SIRUS, dan snapshot yang sama tidak digandakan. Rincian kasus (SIRUS mengubah angka, dokter bukan karyawan, ejaan beda, slip belum Final, sambungan gagal) ada di {!! $tautan('panduan.gaji', 'Panduan Gaji bagian 12') !!}.</p>
                </section>

                {{-- MASALAH --}}
                <section id="masalah" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>7. Masalah &amp; penanganan</h2>
                    <table class="mt-3 text-sm ds-table">
                        <thead><tr><th>Pesan di SIAP</th><th>Artinya</th><th>Tindakan</th></tr></thead>
                        <tbody>
                            <tr><td>API SIRUS belum diatur (…di .env)</td><td><code>SIAP_API_URL</code> / <code>USER</code> / <code>PASSWORD</code> kosong</td><td>isi <code>.env</code> SIAP, <code>php artisan config:clear</code></td></tr>
                            <tr><td>Gagal login API SIRUS: Username / password API salah, akun nonaktif, atau bukan akun API SIAP.</td><td>SIRUS menolak akun</td><td>minta admin SIRUS cek akun <i>siap-gaji</i> (aktif, role Api Siap) atau jalankan ulang <code>siap-api:akun</code>, lalu perbarui password di <code>.env</code> SIAP</td></tr>
                            <tr><td>Gagal login API SIRUS: HTTP 503 / API SIAP tidak aktif</td><td><code>SIAP_API_JWT_SECRET</code> di SIRUS kosong</td><td>admin SIRUS mengisi rahasia + <code>config:clear</code></td></tr>
                            <tr><td>Gagal login API SIRUS: HTTP 404</td><td>URL salah (host Mobile JKN, atau SIRUS mengatur <code>SIAP_API_HOST</code> lain), atau route SIRUS tidak dimuat (rahasia &lt; 32 karakter)</td><td>cek <code>SIAP_API_URL</code> (host SIRUS / SIAP_API_HOST, tanpa <code>/api/siap</code>), cek rahasia di SIRUS</td></tr>
                            <tr><td>HTTP 403 / IP tidak diizinkan</td><td>IP server SIAP tidak ada di whitelist SIRUS</td><td>admin SIRUS menambah IP ke <code>SIAP_API_IP_WHITELIST</code></td></tr>
                            <tr><td>HTTP 429</td><td>terlalu banyak permintaan dalam semenit</td><td>tunggu semenit; jangan klik Ambil berulang-ulang</td></tr>
                            <tr><td>cURL error / timed out</td><td>SIRUS tidak terjangkau dari server SIAP (jaringan, DNS, sertifikat)</td><td><code>curl -I SIAP_API_URL</code> dari server SIAP; cek firewall / HTTPS</td></tr>
                            <tr><td>SIRUS belum punya slip gaji dokter untuk bulan jasa …</td><td>SIRUS belum menggaji bulan itu, atau salah pilih bulan (jasa ≠ gaji)</td><td>pilih bulan jasa yang benar; tunggu SIRUS memproses</td></tr>
                            <tr><td>N dokter belum dipetakan ke karyawan</td><td>nama tidak cocok otomatis</td><td>tab Pemetaan → Petakan manual, atau biarkan bila bukan karyawan</td></tr>
                            <tr><td>Badge "pernah berubah" / versi N</td><td>SIRUS mengubah angka setelah tarikan sebelumnya</td><td>Rincian → Bandingkan dengan versi sebelumnya; konfirmasi ke SIRUS bila perlu</td></tr>
                        </tbody>
                    </table>
                </section>

                {{-- KONTRAK --}}
                <section id="kontrak" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>8. Kontrak data (untuk pengembang)</h2>
                    <p class="mt-2 text-sm"><code>GajiDokterSirus::ambil()</code> membaca JSON SIRUS kolom per kolom. Field yang <b>wajib</b> ada:</p>
                    <ul class="mt-2 text-sm">
                        <li><b>dokter</b>: <code>dr_id, dr_name, dr_nik, kd_dr_bpjs, aktif</code>.</li>
                        <li><b>slip</b>: <code>gajidoctor_no, dr_id, dr_name, tahun_gaji, bulan_gaji, skema_gaji_pokok, nilai_gaji_pokok, potongan_rs_basis, potongan_rs_persen, pph21_persen, npwp_status, jasa_total, total_gaji, potongan_rs, pph21, potongan_lain_total, tambahan_total, gaji_diterima, gaji_status, tanggal_bayar, update_date, detail[]</code>.</li>
                        <li><b>detail</b>: <code>jenis, kode, label, keterangan, nilai, nilai_manual, jumlah_pasien, urutan</code>.</li>
                        <li><b>lampiran.baris</b>: <code>komponen, label, tgl, reg_no, nama, keterangan, klaim, klaim_status, no_sep, txn_no, nominal, pasien, masuk_slip</code>.</li>
                    </ul>
                    <p class="mt-3 text-sm">Menambah field di SIRUS aman (diabaikan). Mengganti nama / arti field harus bersamaan di dua repo: SIRUS <code>Api\Siap\GajiDokterApiController</code> ↔ SIAP <code>App\Support\GajiDokterSirus</code>. Checksum ikut berubah bila nilai atau pembulatan berubah, jadi perubahan format angka di SIRUS akan tampak sebagai "berubah" satu kali.</p>
                    <p class="mt-2 text-sm">Panjang kolom snapshot: nama dokter 100, kode komponen 20, label 100, keterangan lampiran 200, klaim 60, no. SEP 30, no. transaksi 30 — nilai lebih panjang dipotong saat disimpan.</p>
                </section>
            </article>
        </div>
    </div>
</div>
