<?php

use Livewire\Component;
use Illuminate\Support\Facades\DB;

/**
 * PANDUAN ABSENSI — cara pakai modul absensi SIAP (statis, tanpa mutasi).
 * Angka aturan (toleransi, lembur, jendela pulang, radius, dst.) dibaca langsung dari
 * master & Pengaturan Absensi (App\Support\PengaturanAbsensi), supaya panduan tidak basi
 * saat aturan diubah.
 */
new class extends Component {
    public function shifts()
    {
        return DB::table('siapmst_shifts')->where('active_status', '1')->orderBy('jam_masuk')->get();
    }
};
?>

@php
    $absensi = \App\Support\Absensi::class;
    $pengaturan = \App\Support\PengaturanAbsensi::class;
    $tautan = fn(string $route, string $label, array $parameter = []) => \Illuminate\Support\Facades\Route::has($route)
        ? '<a href="' . route($route, $parameter) . '" wire:navigate class="font-semibold text-brand-green underline hover:no-underline dark:text-brand-lime">' . e($label) . '</a>'
        : '<b>' . e($label) . '</b>';
    $bagian = [
        'alur' => 'Gambaran alur',
        'persiapan' => '1. Persiapan (sekali)',
        'jadwal' => '2. Jadwal Dinas bulanan',
        'kiosk' => '3. Absen di Kiosk',
        'hp' => '4. Absen lewat HP',
        'harian' => '5. Rekap Harian',
        'bulanan' => '6. Rekap Bulanan & lembur',
        'pengaturan' => '7. Pengaturan Absensi',
        'kode' => 'Arti kode & warna',
        'faq' => 'Tanya jawab',
    ];
@endphp

<div>
    <x-page-title title="Panduan Absensi" subtitle="Cara pakai: persiapan → jadwal dinas → absen kiosk / HP → rekap harian → rekap bulanan & lembur" />

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
                        Panduan aset: {!! $tautan('panduan.aset', 'Panduan Aset') !!}
                    </p>
                </div>
            </nav>

            {{-- ISI --}}
            <article class="space-y-6 lg:col-span-3 text-body dark:text-gray-300 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-ink dark:[&_h2]:text-gray-100 [&_h3]:font-semibold [&_h3]:text-ink dark:[&_h3]:text-gray-100 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:space-y-1.5 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">

                {{-- ALUR --}}
                <section id="alur" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Gambaran alur</h2>
                    <div class="grid grid-cols-1 gap-2 mt-4 text-sm sm:grid-cols-5">
                        @foreach ([
                            ['Persiapan', 'Pengaturan Absensi: shift, titik lokasi, akun kiosk', 'sekali'],
                            ['Jadwal Dinas', 'P / S / M / I / C per tanggal', 'tiap bulan'],
                            ['Kiosk / HP', 'Scan ID, atau GPS + selfie di HP', 'tiap hari'],
                            ['Rekap Harian', 'Cek, isi izin, Generate', 'tiap hari'],
                            ['Rekap Bulanan', 'Nilai lembur, % hadir', 'akhir bulan'],
                        ] as $urutan => [$judul, $isi, $kapan])
                            <div class="p-3 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                                <div class="text-xs font-semibold text-brand-green dark:text-brand-lime">{{ $urutan + 1 }} · {{ $kapan }}</div>
                                <div class="font-semibold text-ink dark:text-gray-100">{{ $judul }}</div>
                                <div class="text-xs text-muted">{{ $isi }}</div>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-sm">Kunci utamanya: <b>Jadwal Dinas</b>. Kiosk & absen HP mengambil shift dari jadwal, Generate Libur/Alpa membaca jadwal, dan % kehadiran dihitung dari jadwal.
                        Kiosk dan absen HP memakai <b>aturan hitungan yang sama</b> — bedanya hanya cara absennya.</p>
                </section>

                {{-- PERSIAPAN --}}
                <section id="persiapan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>1. Persiapan (cukup sekali)</h2>
                    <div class="p-3 mt-3 text-sm border rounded-xl border-brand-green/40 bg-brand-green/5">
                        💡 Semua setup absensi ada di satu tempat: {!! $tautan('absensi.pengaturan', 'Pengaturan Absensi') !!}
                        — tab <b>Aturan Absen</b>, <b>Shift &amp; Toleransi</b>, <b>Titik Lokasi HP</b>, dan <b>Lokasi Karyawan</b>.
                        Menu Master Shift & Master Karyawan yang lama tetap bisa dipakai.
                    </div>

                    <h3 class="mt-4">a. Shift — {!! $tautan('absensi.pengaturan', 'buka tab Shift', ['tab' => 'shift']) !!}</h3>
                    <p class="mt-1 text-sm">Shift yang berlaku sekarang:</p>
                    <table class="mt-2 ds-table">
                        <thead><tr><th>Kode</th><th>Shift</th><th>Jam</th><th>Toleransi terlambat</th></tr></thead>
                        <tbody>
                            @foreach ($this->shifts() as $shift)
                                <tr>
                                    <td><span class="inline-block w-7 py-0.5 text-xs font-bold text-center rounded {{ \App\Support\Jadwal::WARNA[$shift->shift_kode] ?? \App\Support\Jadwal::WARNA_LAIN }}">{{ $shift->shift_kode }}</span></td>
                                    <td>{{ $shift->shift_name }}</td>
                                    <td class="font-mono">{{ $shift->jam_masuk }} – {{ $shift->jam_pulang }}</td>
                                    <td>{{ (int) $shift->toleransi_menit }} menit</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <ul class="mt-2 text-sm">
                        <li><b>Kode Jadwal</b> (P, S, …) adalah huruf yang dipakai di grid jadwal. I dan C tidak boleh dipakai (khusus Izin & Cuti).</li>
                        <li>Jam pulang lebih kecil dari jam masuk = shift <b>lintas hari</b> (pulang esok hari) — otomatis.</li>
                        <li><b>Toleransi terlambat</b> diatur per shift. Contoh: shift 07:00 toleransi 10 menit → masuk 07:10 masih Hadir, 07:11 Terlambat.</li>
                    </ul>
                    <div class="p-3 mt-3 text-sm border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                        <b>Contoh membuat shift malam (shift 3)</b> — klik <i>+ Tambah Shift</i>, isi:
                        ID <code>MALAM</code> · Kode Jadwal <code>M</code> · Nama <i>Shift Malam</i> · Jam Masuk <code>21:00</code> · Jam Pulang <code>07:00</code> · Toleransi <code>10</code>.
                        Di bawah jam pulang muncul tulisan <i>"Lintas hari"</i>. Di Jadwal Dinas, isi <b>M pada tanggal mulai jaga</b>
                        (jaga Senin 21:00 – Selasa 07:00 → M di tanggal Senin). Absensinya tercatat di tanggal Senin, jam pulang ditandai "(+1 hari)".
                    </div>

                    <h3 class="mt-5">b. Master Karyawan — {!! $tautan('master.karyawan', 'buka') !!}</h3>
                    <ul class="mt-1 text-sm">
                        <li><b>ID Karyawan</b> (mis. <code>KMP-001</code>) dipakai untuk absen di kiosk — bisa diketik atau dicetak sebagai barcode di kartu karyawan.</li>
                        <li><b>Shift Default (P/S)</b> = kolom P/S di jadwal; dipakai tombol <i>Isi Sen–Sab</i>.</li>
                        <li>Karyawan keluar: <b>non-aktifkan</b> (toggle), jangan dihapus — riwayat absensinya tetap tersimpan.</li>
                        <li>Centang <b>Tenaga Kesehatan</b> bila wajib STR/SIP — tombol STR/SIP muncul di daftar.</li>
                        <li><b>Lokasi Absen HP</b> = RS / klinik tempat karyawan bekerja (lihat poin d).</li>
                    </ul>

                    <h3 class="mt-5">c. Akun komputer kiosk</h3>
                    <ol class="mt-1 text-sm">
                        <li>Buat role <code>kiosk</code> di {!! $tautan('sistem.role-control', 'Role Control') !!}.</li>
                        <li>Buat user khusus (mis. <i>kiosk@…</i>) di {!! $tautan('sistem.user-control', 'User Control') !!}, beri role <code>kiosk</code>.</li>
                        <li>Login dengan akun itu di komputer dekat pintu, buka menu <b>Kiosk Absensi</b>, biarkan layar terbuka.</li>
                    </ol>

                    <h3 class="mt-5">d. Titik lokasi absen HP — {!! $tautan('absensi.pengaturan', 'buka tab Titik Lokasi HP', ['tab' => 'lokasi']) !!}</h3>
                    <p class="mt-1 text-sm">Wajib bila karyawan absen lewat HP. Satu titik = satu gedung RS / klinik.</p>
                    <ol class="mt-1 text-sm">
                        <li>Klik <b>+ Tambah Lokasi</b>, isi Kode (mis. <code>RSU</code>) dan Nama (mis. <i>RS Utama</i>).</li>
                        <li><b>Titik Koordinat</b>, pilih salah satu:
                            <ul>
                                <li>Buka Google Maps → <b>klik kanan</b> tepat di gedung → klik angka koordinat paling atas (otomatis tersalin, mis. <code>-8.0654321, 111.9012345</code>) → tempel.</li>
                                <li>Atau berdiri di lokasi dengan HP/laptop, klik <b>Pakai lokasi saya sekarang</b> (butuh HTTPS).</li>
                            </ul>
                            Klik <b>cek di peta</b> untuk memastikan titiknya benar.</li>
                        <li><b>Radius</b> dalam meter — <code>2000</code> = 2 km. Karyawan hanya bisa absen HP bila jaraknya ke titik ini ≤ radius.</li>
                        <li>Atur <b>lokasi kerja tiap karyawan</b> di tab {!! $tautan('absensi.pengaturan', 'Lokasi Karyawan', ['tab' => 'lokasi-karyawan']) !!}
                            — bisa per orang, atau sekaligus (cari / filter "hanya yang belum diatur" → pilih lokasi → <i>Terapkan</i>).
                            Karyawan tanpa lokasi boleh absen di titik aktif <b>mana pun</b>.</li>
                    </ol>
                </section>

                {{-- JADWAL --}}
                <section id="jadwal" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>2. Jadwal Dinas bulanan — {!! $tautan('absensi.jadwal-dinas', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Buat sebelum bulan berjalan. Satu baris = satu karyawan, satu kolom = satu tanggal.</p>
                    <ol class="mt-3 text-sm">
                        <li>Pilih <b>bulan</b> (tombol ‹ ›).</li>
                        <li>Pilih <b>kuas</b>: P, S, I (izin), C (cuti), atau <b>Libur</b> (menghapus isi sel).</li>
                        <li><b>Klik sel</b> untuk mengisi. Klik sel yang isinya sama dengan kuas = menghapus.</li>
                        <li>Cara cepat:
                            <ul>
                                <li><b>Isi Sen–Sab (P)</b> di ujung baris → mengisi Senin–Sabtu sebulan dengan shift default karyawan itu.</li>
                                <li><b>Salin bulan lalu</b> → menyalin jadwal bulan lalu per <i>hari yang sama</i> (Senin→Senin), hanya ke sel kosong.</li>
                            </ul>
                        </li>
                        <li><b>Cetak PDF</b> untuk ditempel / dibagikan (format "Jadwal Masuk Kerja Bulan …").</li>
                    </ol>
                    <div class="p-3 mt-4 text-sm border rounded-xl border-amber-300 bg-amber-50 text-amber-900">
                        🔒 <b>Tanggal yang sudah lewat terkunci</b> — tidak bisa diubah, supaya absensi, Generate, dan % kehadiran yang sudah dihitung tetap asli.
                        Hari ini & seterusnya masih bisa diubah (mis. tukar dinas mendadak).
                    </div>
                    <p class="mt-3 text-sm">Sel <b>kosong = libur/off</b>. Minggu ditandai merah.</p>
                </section>

                {{-- KIOSK --}}
                <section id="kiosk" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>3. Absen di Kiosk — {!! $tautan('absensi.kiosk', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Karyawan cukup <b>ketik / scan ID lalu Enter</b> — sistem menentukan sendiri masuk atau pulang.</p>
                    <table class="mt-3 ds-table">
                        <thead><tr><th>Keadaan</th><th>Yang terjadi</th></tr></thead>
                        <tbody>
                            <tr><td>Terjadwal P/S hari ini, belum absen</td><td>Langsung tercatat <b>MASUK</b> dengan shift jadwal ("sesuai jadwal")</td></tr>
                            <tr><td>Sudah masuk, belum pulang</td><td>Tercatat <b>PULANG</b> (jam saat itu)</td></tr>
                            <tr><td>Scan ulang &lt; {{ $pengaturan::jedaMinPulangMenit() }} menit setelah masuk</td><td>Hanya info — dianggap scan dobel, <i>tidak</i> dicatat pulang</td></tr>
                            <tr><td>Tidak terjadwal (libur)</td><td>Diminta <b>pilih shift</b> (untuk tukar dinas) dengan catatan "tidak terjadwal"</td></tr>
                            <tr><td>Terjadwal I / C, atau sudah diisi Izin/Sakit/dll.</td><td>Hanya info — hubungi HRD bila ternyata masuk</td></tr>
                            <tr><td>Masuk & pulang sudah lengkap</td><td>Info "absen hari ini sudah lengkap" (+ saran lapor HRD bila dobel shift)</td></tr>
                        </tbody>
                    </table>
                    <h3 class="mt-5">Aturan hitungan <span class="text-sm font-normal text-muted">(berlaku juga untuk absen HP)</span></h3>
                    <ul class="mt-1 text-sm">
                        <li><b>Terlambat</b>: masuk lewat jam masuk shift + toleransi → status <i>Terlambat</i>, menit terlambat dihitung dari jam masuk shift.</li>
                        <li><b>Lembur</b>: pulang ≥ <b>{{ $pengaturan::minLemburMenit() }} menit</b> lewat jadwal pulang → lembur dicatat dengan status <i>Menunggu</i> (dinilai di Rekap Bulanan). Kurang dari itu tidak dihitung.</li>
                        <li><b>Pulang lewat tengah malam</b> (lembur panjang) tetap tercatat sebagai pulang shift tersebut, selama masih dalam {{ $pengaturan::batasPulangJam() }} jam setelah jadwal pulang (atau setelah jam masuk, bila baru masuk sesudah jadwal pulang lewat).</li>
                        <li><b>Dobel shift</b> (menggantikan jadwal rekan, mis. Pagi + Sore): sebaiknya <i>tidak</i> absen pulang di antara dua shift — cukup absen pulang setelah shift kedua. HRD lalu membuka <b>Koreksi</b> di Rekap Harian dan memilih <b>shift kedua</b>; lembur dihitung dari jam kerja shift kedua saja (jeda antar-shift tidak ikut) dan langsung berstatus Dihitung. Bila sudah terlanjur absen pulang, HRD cukup mengganti Jam Pulang dengan jam pulang shift kedua.</li>
                        <li><b>Shift malam, datang lewat tengah malam</b> (mis. jadwal M Senin, baru datang Selasa 00:30) → otomatis tercatat sebagai shift malam Senin (terlambat), tidak perlu pilih shift.</li>
                        <li><b>Lupa absen pulang</b> lebih dari {{ $pengaturan::batasPulangJam() }} jam → scan berikutnya dianggap absen masuk hari baru. Jam pulang yang terlewat diisi HRD lewat Koreksi.</li>
                    </ul>
                </section>

                {{-- HP --}}
                <section id="hp" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>4. Absen lewat HP — {!! $tautan('absensi.hp', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">
                        Karyawan absen dari HP sendiri <b>tanpa login</b>: ketik ID → GPS dicek → selfie → kirim.
                        Masuk / pulang ditentukan otomatis dengan aturan yang sama seperti kiosk.
                        Bisa dibuka lewat kartu <b>Absensi</b> di halaman depan, atau link langsung
                        <code class="break-all">{{ route('absensi.hp') }}</code>.
                    </p>
                    @unless ($pengaturan::absenHpAktif())
                        <p class="p-3 mt-3 text-sm border rounded-xl border-amber-300 bg-amber-50 text-amber-900">
                            ⏸️ Absen HP <b>sedang dinonaktifkan</b> — aktifkan di {!! $tautan('absensi.pengaturan', 'Pengaturan Absensi') !!}.
                        </p>
                    @endunless

                    <h3 class="mt-4">Langkah karyawan</h3>
                    <ol class="mt-1 text-sm">
                        <li><b>Nyalakan GPS</b> HP, buka halaman Absen HP.</li>
                        <li>Ketik <b>ID Karyawan</b> → <i>Lanjut</i>. Pastikan nama yang muncul benar (salah? klik <i>Bukan saya — ganti ID</i>). HP mengingat ID terakhir.</li>
                        <li>Klik <b>📍 Mulai Absen</b> → izinkan akses lokasi bila diminta.</li>
                        <li>Bila di dalam area, tampil "✓ Di area … (jarak)". Klik <b>📸 Ambil / Upload Selfie</b> → foto wajah.</li>
                        <li>Periksa foto (<i>Ganti Foto</i> bila kurang jelas) → <b>Kirim Absen</b>.</li>
                        <li>Muncul hasil: ✅ masuk, ⏰ terlambat, 👋 pulang, atau pilih shift bila tidak terjadwal — sama seperti kiosk.</li>
                    </ol>

                    <h3 class="mt-5">Arti pesan</h3>
                    <table class="mt-2 ds-table">
                        <thead><tr><th>Pesan</th><th>Artinya / yang dilakukan</th></tr></thead>
                        <tbody>
                            <tr><td>"Anda berada … dari … Absen hanya bisa dalam radius …"</td><td>Di luar area. Mendekat ke RS / klinik lalu <i>Coba Lagi</i>.</td></tr>
                            <tr><td>"Sinyal GPS kurang akurat (±… m)"</td><td>Akurasi lebih buruk dari {{ $pengaturan::maksAkurasiMeter() }} m (biasanya di dalam gedung). Aktifkan mode lokasi akurasi tinggi, dekati jendela / keluar sebentar, coba lagi.</td></tr>
                            <tr><td>"Izin lokasi ditolak"</td><td>Buka pengaturan browser → izin situs → Lokasi → <i>Izinkan</i>, lalu muat ulang.</td></tr>
                            <tr><td>"Halaman ini harus dibuka lewat HTTPS"</td><td>Link yang dibuka bukan HTTPS — hubungi admin untuk link yang benar.</td></tr>
                            <tr><td>"ID … tidak terdaftar"</td><td>Salah ketik ID, atau karyawan belum didaftarkan HRD.</td></tr>
                            <tr><td>"Terlalu banyak percobaan"</td><td>Tunggu 1 menit (pembatas keamanan).</td></tr>
                            <tr><td>"Absen HP sedang dinonaktifkan"</td><td>HRD mematikan sementara — absen di kiosk.</td></tr>
                        </tbody>
                    </table>

                    <h3 class="mt-5">Penting diketahui</h3>
                    <ul class="mt-1 text-sm">
                        <li>Jarak dihitung ulang di server saat kirim — posisi di luar radius tetap ditolak walau tampilan HP diakali.</li>
                        <li>Selfie & posisi disimpan untuk <b>masuk dan pulang</b>, dan bisa diperiksa HRD di Rekap Harian.</li>
                        <li>Halaman ini <b>tidak memakai login</b> — siapa pun yang tahu ID bisa mencoba absen. Selfie adalah buktinya; HRD sebaiknya rutin memeriksa foto (lihat bagian 5).</li>
                        <li>Untuk admin: GPS & kamera di browser HP <b>hanya jalan lewat HTTPS</b>. Alamat <code>http://192.168…</code> tidak bisa dipakai karyawan.</li>
                    </ul>
                </section>

                {{-- HARIAN --}}
                <section id="harian" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>5. Rekap Harian — {!! $tautan('absensi.rekap-harian', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Semua karyawan aktif pada satu tanggal, termasuk yang <b>belum absen</b>. Kolom <b>Jadwal</b> menampilkan rencana, kolom Shift/Masuk/Pulang menampilkan kenyataan.</p>
                    <h3 class="mt-4">Isi izin / sakit / koreksi jam (per karyawan)</h3>
                    <ol class="mt-1 text-sm">
                        <li>Pilih tanggal, cari karyawan, klik <b>Isi</b> (belum ada catatan) atau <b>Koreksi</b>.</li>
                        <li>Pilih status: <b>Hadir</b> (isi shift + jam masuk/pulang — terlambat & lembur dihitung otomatis), atau <b>Izin / Sakit / Cuti / Alpa / Libur</b> (keterangan wajib untuk Izin, Sakit, Cuti).</li>
                        <li>Simpan. Salah isi? Klik Koreksi lagi, atau <b>Hapus Absensi</b> agar kembali "Belum absen".</li>
                    </ol>
                    <h3 class="mt-4">Generate Libur / Alpa (sekaligus)</h3>
                    <ol class="mt-1 text-sm">
                        <li>Pilih tanggal yang <b>sudah lewat</b> (hari ini tidak bisa — shift sore mungkin belum datang).</li>
                        <li>Klik <b>Generate Libur / Alpa</b>. Muncul semua yang belum punya catatan dengan pilihan awal dari jadwal:
                            terjadwal kerja → <b>Alpa</b>, tidak terjadwal → <b>Libur</b>, terjadwal I/C → <b>Izin/Cuti</b>.</li>
                        <li>Ubah per orang bila perlu, lalu <b>Simpan</b>. Yang sudah punya catatan tidak disentuh.</li>
                    </ol>
                    <h3 class="mt-4">Menandai dobel shift</h3>
                    <ol class="mt-1 text-sm">
                        <li>Klik <b>Koreksi</b> pada karyawan yang menggantikan jadwal rekan (mis. Pagi + Sore).</li>
                        <li>Pastikan <b>Shift</b> = shift pertama, <b>Jam Pulang</b> = jam pulang <i>setelah shift kedua</i>.</li>
                        <li>Pilih <b>Dobel shift — shift kedua</b>. Lembur langsung terlihat: jam kerja di shift kedua saja (jeda antar-shift tidak dihitung).</li>
                        <li>Simpan → lembur berstatus <b>Dihitung</b>; kolom Shift menampilkan "+ shift kedua" dan badge <i>dobel shift</i>.</li>
                    </ol>

                    <h3 class="mt-4">Memeriksa absen HP</h3>
                    <ul class="mt-1 text-sm">
                        <li>Absen dari HP diberi label kecil <b>HP</b> di sebelah status.</li>
                        <li>Di kolom Masuk / Pulang ada link <b>📷 selfie · jarak</b> — klik untuk membuka foto di tab baru; jarak = jarak karyawan ke titik lokasi saat absen.</li>
                        <li>Foto hanya bisa dibuka <b>admin / HRD</b> dan karyawan pemiliknya. Absen yang mencurigakan (bukan orangnya, foto layar) → Koreksi / Hapus Absensi.</li>
                    </ul>
                    <p class="mt-3 text-sm">Lembur di sini hanya <b>ditampilkan</b> (dengan statusnya) — penilaiannya di Rekap Bulanan.</p>
                </section>

                {{-- BULANAN --}}
                <section id="bulanan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>6. Rekap Bulanan & penilaian lembur — {!! $tautan('absensi.rekap-bulanan', 'buka') !!}</h2>
                    <ul class="mt-2 text-sm">
                        <li><b>Terjadwal</b> = hari berjadwal P/S s/d kemarin (bulan berjalan) atau sebulan penuh (bulan lalu).</li>
                        <li><b>% Kehadiran</b> = hadir (Hadir/Terlambat) di hari terjadwal ÷ hari terjadwal.
                            <span class="px-1 rounded bg-emerald-100 text-emerald-800">≥ 95%</span>
                            <span class="px-1 rounded bg-amber-100 text-amber-800">≥ 85%</span>
                            <span class="px-1 text-red-800 bg-red-100 rounded">&lt; 85%</span>.
                            Izin/cuti yang sudah dijadwalkan (I/C) tidak dihitung hari kerja; izin/sakit/alpa mendadak menurunkan persentase.</li>
                        <li><b>Lembur Dihitung</b> = hanya lembur yang disetujui; yang belum dinilai tampil kuning "menunggu". Jumlah dobel shift tampil di bawahnya (mis. "2× dobel shift").</li>
                    </ul>
                    <h3 class="mt-4">Menilai lembur</h3>
                    <ol class="mt-1 text-sm">
                        <li>Klik <b>nama karyawan</b> → rincian kehadiran sebulan per tanggal (jadwal vs kenyataan).</li>
                        <li>Di hari yang ada lembur, klik <b>✓ Hitung</b> atau <b>✗ Tidak</b>. Bisa diubah lagi lewat "ubah".</li>
                        <li>Atau <b>✓ Hitung semua yang menunggu</b> sekaligus.</li>
                    </ol>
                    <p class="mt-2 text-sm">Hari <span class="font-semibold text-red-600">terjadwal tapi tanpa catatan</span> ditandai merah di rincian — lengkapi lewat Rekap Harian.</p>
                </section>

                {{-- PENGATURAN --}}
                <section id="pengaturan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>7. Pengaturan Absensi — {!! $tautan('absensi.pengaturan', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Satu dasbor untuk HRD. Kartu di atas menunjukkan status Absen HP, jumlah shift & titik aktif, dan karyawan yang belum punya lokasi — klik kartu untuk membuka tab-nya.</p>
                    <h3 class="mt-4">Tab Aturan Absen — nilai yang berlaku sekarang</h3>
                    <table class="mt-2 ds-table">
                        <thead><tr><th>Aturan</th><th class="ds-c">Sekarang</th><th>Artinya</th></tr></thead>
                        <tbody>
                            <tr><td>Absen HP</td><td class="ds-c">{{ $pengaturan::absenHpAktif() ? 'Aktif' : 'Nonaktif' }}</td><td>Matikan sementara bila ada masalah — karyawan diarahkan ke kiosk.</td></tr>
                            <tr><td>Akurasi GPS maksimal</td><td class="ds-c">{{ $pengaturan::maksAkurasiMeter() }} m</td><td>GPS yang kurang akurat dari ini diminta coba lagi.</td></tr>
                            <tr><td>Jeda minimal masuk → pulang</td><td class="ds-c">{{ $pengaturan::jedaMinPulangMenit() }} mnt</td><td>Absen ulang lebih cepat dianggap dobel, tidak dicatat pulang.</td></tr>
                            <tr><td>Batas absen pulang</td><td class="ds-c">{{ $pengaturan::batasPulangJam() }} jam</td><td>Setelah jadwal pulang (atau jam masuk, mana yang lebih akhir). Lewat dari itu = lupa absen pulang.</td></tr>
                            <tr><td>Batas jam shift malam</td><td class="ds-c">{{ sprintf('%02d:00', $pengaturan::batasJamLintasHari()) }}</td><td>Absen shift lintas hari sebelum jam ini dihitung milik tanggal kemarin.</td></tr>
                            <tr><td>Minimal lembur</td><td class="ds-c">{{ $pengaturan::minLemburMenit() }} mnt</td><td>Pulang lewat jadwal kurang dari ini tidak dihitung lembur.</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-sm">Ubah angka → <b>Simpan</b>; langsung berlaku di kiosk & HP. <i>Isi nilai bawaan</i> mengembalikan angka awal (perlu Simpan). Toleransi terlambat diatur per shift, radius per titik lokasi.</p>
                </section>

                {{-- KODE --}}
                <section id="kode" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Arti kode & warna</h2>
                    <div class="grid grid-cols-1 gap-6 mt-3 md:grid-cols-2">
                        <div>
                            <h3>Status absensi</h3>
                            <div class="flex flex-wrap gap-2 mt-2">
                                @foreach ($absensi::STATUS as $kodeStatus => $namaStatus)
                                    <x-badge :variant="$absensi::BADGE[$kodeStatus]">{{ $kodeStatus }} = {{ $namaStatus }}</x-badge>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <h3>Kode jadwal</h3>
                            <div class="flex flex-wrap gap-2 mt-2 text-sm">
                                @foreach ($this->shifts() as $shift)
                                    <span class="px-2 py-0.5 font-bold rounded {{ \App\Support\Jadwal::WARNA[$shift->shift_kode] ?? \App\Support\Jadwal::WARNA_LAIN }}">{{ $shift->shift_kode }}</span><span>{{ $shift->shift_name }}</span>
                                @endforeach
                                <span class="px-2 py-0.5 font-bold rounded {{ \App\Support\Jadwal::WARNA['I'] }}">I</span><span>Izin</span>
                                <span class="px-2 py-0.5 font-bold rounded {{ \App\Support\Jadwal::WARNA['C'] }}">C</span><span>Cuti</span>
                                <span class="px-2 py-0.5 rounded border border-hairline">kosong</span><span>Libur</span>
                            </div>
                        </div>
                    </div>
                    <p class="mt-4 text-sm">Format: tanggal <code>dd/mm/yyyy</code> · bulan <code>mm/yyyy</code> · waktu <code>dd/mm/yyyy hh:mi:ss</code>.</p>
                </section>

                {{-- FAQ --}}
                <section id="faq" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Tanya jawab</h2>
                    <div class="mt-3 space-y-2 text-sm">
                        @foreach ([
                            ['Karyawan lupa absen masuk / pulang?', 'HRD membuka Rekap Harian tanggal itu → Koreksi → isi jam masuk/pulang. Status terlambat & lembur dihitung otomatis.'],
                            ['Tukar dinas mendadak hari ini?', 'Ubah sel hari ini di Jadwal Dinas (masih bisa), atau biarkan karyawan memilih shift di kiosk saat tidak terjadwal.'],
                            ['Izin mendadak (tidak ada di jadwal)?', 'Isi di Rekap Harian → Izin + keterangan. Ini menurunkan % kehadiran; izin yang direncanakan sebaiknya dijadwalkan sebagai I.'],
                            ['Jadwal tanggal lalu salah?', 'Tanggal lalu terkunci. Kehadiran tetap bisa dikoreksi di Rekap Harian; bila jadwalnya sendiri harus diubah, hubungi Admin.'],
                            ['Lembur tidak muncul?', 'Lembur hanya tercatat bila pulang ≥ ' . $pengaturan::minLemburMenit() . ' menit lewat jadwal pulang shift.'],
                            ['Karyawan sudah keluar?', 'Non-aktifkan di Master Karyawan — tidak muncul lagi di kiosk, jadwal, dan rekap, tetapi riwayatnya aman.'],
                            ['Absen HP ditolak "di luar area" padahal sudah di RS?', 'Cek titik koordinat di Pengaturan Absensi › Titik Lokasi HP (klik koordinatnya untuk membuka peta), dan pastikan lokasi kerja karyawan di tab Lokasi Karyawan benar. Bila GPS di dalam gedung meleset, minta karyawan mendekati jendela, atau perbesar radius.'],
                            ['Karyawan lupa ID-nya?', 'ID ada di Master Karyawan (mis. KMP-001). HP mengingat ID terakhir yang berhasil dipakai, jadi biasanya cukup sekali ketik.'],
                            ['Bisakah absen HP untuk orang lain (titip absen)?', 'Secara teknis bisa bila tahu ID-nya dan berada di area — karena itu setiap absen HP wajib selfie. Periksa foto di Rekap Harian; absen yang tidak sesuai bisa dikoreksi atau dihapus.'],
                            ['Dobel shift tapi karyawan sudah terlanjur absen pulang?', 'Buka Koreksi di Rekap Harian: ganti Jam Pulang dengan jam pulang shift kedua dan pilih Dobel shift — shift kedua. Lembur dihitung otomatis.'],
                        ] as [$tanya, $jawab])
                            <details class="p-3 border rounded-xl border-hairline dark:border-gray-700">
                                <summary class="font-semibold cursor-pointer text-ink dark:text-gray-100">{{ $tanya }}</summary>
                                <p class="mt-2">{{ $jawab }}</p>
                            </details>
                        @endforeach
                    </div>
                </section>

            </article>
        </div>
    </div>
</div>
