<?php

use Livewire\Component;

/**
 * PANDUAN GAJI — cara pakai modul gaji karyawan SIAP (statis, tanpa mutasi).
 * Daftar komponen, masa berlaku link WA, form bagian, pos JM, dst. dibaca langsung dari App\Support\GajiKaryawan,
 * App\Support\GajiFormBagian, App\Support\JasaMedik, App\Support\LaporanPegawai & App\Support\PinjamanKaryawan
 * supaya panduan tidak basi saat aturan diubah. Hanya role gaji.kelola (sama dengan modulnya).
 */
new class extends Component {
    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }
};
?>

@php
    $gaji = \App\Support\GajiKaryawan::class;
    $formBagian = \App\Support\GajiFormBagian::class;
    $jm = \App\Support\JasaMedik::class;
    $lap = \App\Support\LaporanPegawai::class;
    $pinjaman = \App\Support\PinjamanKaryawan::class;
    $tautan = fn(string $route, string $label, array $parameter = []) => \Illuminate\Support\Facades\Route::has($route)
        ? '<a href="' . route($route, $parameter) . '" wire:navigate class="font-semibold text-brand-green underline hover:no-underline dark:text-brand-lime">' . e($label) . '</a>'
        : '<b>' . e($label) . '</b>';
    $bagian = [
        'alur' => 'Gambaran alur',
        'bulanan' => 'Urutan kerja tiap bulan',
        'parameter' => '1. Parameter gaji (sekali)',
        'generate' => '2. Generate slip bulanan',
        'jm' => '3. Pembagian Jasa Medik',
        'lap' => '4. Laporan Kepegawaian (hari kerja & lembur)',
        'pinjaman' => '5. Pinjaman Karyawan (angsuran)',
        'form-bagian' => '6. Form Bagian (Excel)',
        'rincian' => '7. Rincian slip',
        'hari-kerja' => '8. Mengubah hari kerja',
        'pph' => '9. PPh 21',
        'final' => '10. Final & buka kembali',
        'cetak' => '11. Cetak & kirim WhatsApp',
        'gaji-dokter' => '12. Gaji Dokter (SIRUS)',
        'rumus' => 'Rumus hitungan',
        'faq' => 'Tanya jawab',
    ];
@endphp

<div>
    <x-page-title title="Panduan Gaji" subtitle="Cara pakai: parameter gaji → generate slip → jasa medik → laporan kepegawaian → pinjaman karyawan → form bagian → rincian → final → cetak / kirim WhatsApp · gaji dokter spesialis ditarik dari SIRUS" />

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
                        Buka modul: {!! $tautan('keuangan.gaji-karyawan', 'Gaji Karyawan') !!} · {!! $tautan('keuangan.pembagian-jm', 'Pembagian Jasa Medik') !!} · {!! $tautan('keuangan.laporan-pegawai', 'Laporan Kepegawaian') !!} · {!! $tautan('keuangan.pinjaman-karyawan', 'Pinjaman Karyawan') !!} · {!! $tautan('keuangan.gaji-dokter', 'Gaji Dokter (SIRUS)') !!}
                    </p>
                </div>
            </nav>

            {{-- ISI --}}
            <article class="space-y-6 lg:col-span-3 text-body dark:text-gray-300 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-ink dark:[&_h2]:text-gray-100 [&_h3]:font-semibold [&_h3]:text-ink dark:[&_h3]:text-gray-100 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:space-y-1.5 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">

                {{-- ALUR --}}
                <section id="alur" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Gambaran alur</h2>
                    <div class="grid grid-cols-1 gap-2 mt-4 text-sm sm:grid-cols-3 xl:grid-cols-4">
                        @foreach ([
                            ['Parameter Gaji', 'Master Karyawan › tombol Gaji (+ poin JM)', 'sekali / bila berubah'],
                            ['Generate', 'Slip Draft semua karyawan', 'tiap bulan'],
                            ['Jasa Medik', 'Pembagian JM › Terapkan ke Slip', 'tiap bulan'],
                            ['Laporan Kepegawaian', 'Hari kerja & lembur isian kepegawaian › Terapkan ke Slip', 'tiap bulan'],
                            ['Pinjaman Karyawan', 'Buku pinjaman › angsuran bulan ini › Terapkan ke Slip', 'tiap bulan'],
                            ['Form Bagian', 'Excel: bon, kenaikan gaji, iuran koperasi, BPJS', 'tiap bulan'],
                            ['Final', 'Kunci slip yang sudah benar', 'tiap bulan'],
                            ['Cetak / WA', 'PDF slip atau kirim ke WhatsApp', 'tiap bulan'],
                        ] as $urutan => [$judul, $isi, $kapan])
                            <div class="p-3 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                                <div class="text-xs font-semibold text-brand-green dark:text-brand-lime">{{ $urutan + 1 }} · {{ $kapan }}</div>
                                <div class="font-semibold text-ink dark:text-gray-100">{{ $judul }}</div>
                                <div class="text-xs text-muted">{{ $isi }}</div>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-sm">
                        Modul gaji hanya bisa dibuka role <b>Admin</b> dan <b>Keuangan</b> — data gaji rahasia. Role Keuangan diberikan lewat
                        {!! $tautan('sistem.user-control', 'User Control') !!}. Slip dihitung per <b>bulan kerja</b> (mis. slip bulan kerja September dibayar awal Oktober).
                    </p>
                </section>

                {{-- URUTAN BULANAN --}}
                <section id="bulanan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Urutan kerja tiap bulan</h2>
                    <ol class="mt-3 text-sm">
                        <li><b>Generate</b> slip bulan kerja di {!! $tautan('keuangan.gaji-karyawan', 'Gaji Karyawan') !!} (bagian 2).</li>
                        <li><b>Jasa Medik</b>: di {!! $tautan('keuangan.pembagian-jm', 'Pembagian Jasa Medik') !!} isi nilai dasar per pos, <b>Generate Poin dari Master</b>, lalu <b>Terapkan ke Slip</b> (bagian 3).</li>
                        <li><b>Laporan Kepegawaian</b>: kolom sudah terisi dari master; kepegawaian mengubah hari kerja / cuti / jam lembur yang berbeda di {!! $tautan('keuangan.laporan-pegawai', 'Laporan Kepegawaian') !!}; Keuangan klik <b>Terapkan ke Slip</b> (bagian 4).
                            Lakukan <b>setelah JM diterapkan</b> — dasar upah lembur memuat JM.</li>
                        <li><b>Pinjaman Karyawan</b>: pinjaman baru dicatat di {!! $tautan('keuangan.pinjaman-karyawan', 'Pinjaman Karyawan') !!} saat terjadi; tiap bulan cukup klik <b>Terapkan ke Slip</b> (bagian 5).</li>
                        <li><b>Form Bagian</b>: ekspor form Excel tiap bagian, kirim ke bagian terkait, lalu impor hasilnya (bagian 6).</li>
                        <li>Periksa <b>Rincian</b> slip yang janggal (bagian 7), lalu <b>Final</b> (bagian 10).</li>
                        <li><b>Cetak</b> / <b>kirim WhatsApp</b> (bagian 11).</li>
                    </ol>
                    <p class="mt-3 text-sm">Langkah 2–6 boleh diulang selama slip masih <b>Draft</b>: Generate, Terapkan JM, Terapkan laporan kepegawaian, Terapkan angsuran, dan impor form semuanya <b>mengganti</b> angka (bukan menambah), jadi aman diproses ulang bila ada perubahan.</p>
                </section>

                {{-- PARAMETER --}}
                <section id="parameter" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>1. Parameter gaji (cukup sekali)</h2>
                    <ol class="mt-3 text-sm">
                        <li>Buka {!! $tautan('master.karyawan', 'Master Karyawan') !!}, klik tombol <b>Gaji</b> di kolom Aksi. Tombol bertitik oranye = parameter belum diatur.</li>
                        <li>Isi gaji pokok, tunjangan, tarif harian, potongan rutin, PPh 21 &amp; rekening, lalu <b>Simpan</b>.</li>
                        <li>Pakai filter <b>"Gaji belum diatur"</b> di toolbar untuk mencari yang belum diisi. Karyawan tanpa parameter <b>tidak dibuatkan slip</b>.</li>
                    </ol>
                    <table class="mt-3 text-sm ds-table">
                        <thead><tr><th>Isian</th><th>Keterangan</th></tr></thead>
                        <tbody>
                            <tr><td>Gaji Pokok, Tunjangan Struktural, Tunjangan Profesi</td><td>Nominal tetap per bulan.</td></tr>
                            <tr><td>Tarif Makan, Tarif Transport</td><td>Per <b>hari kerja</b> — dikali hari kerja slip.</td></tr>
                            <tr><td>Hari Kerja Default</td><td>Pengali tarif makan &amp; transport untuk slip <b>baru</b> (mis. 25). Per slip bisa diubah (bagian 8).</td></tr>
                            <tr><td>Potongan rutin</td><td>Asuransi lain, iuran organisasi (PPNI/IBI), arisan, koperasi, infaq — nominal tetap per bulan.</td></tr>
                            <tr><td class="font-semibold">BPJS</td><td>Kepesertaan JKN (dasar hitung &amp; keluarga tambahan) dan JP. Potongannya <b>dihitung otomatis</b> di slip dari UMK &amp; persen di Gaji Karyawan → <b>Ketentuan BPJS</b>; JP ikut berubah saat JM berubah. Bisa ditimpa per slip di rincian.</td></tr>
                            <tr><td>PPh 21 (%)</td><td>Persen dari total pendapatan slip (bagian 9). 0 = tanpa PPh.</td></tr>
                            <tr><td>Bank &amp; No. Rekening</td><td>Tercetak di slip.</td></tr>
                            <tr><td>Poin Jasa Medik (master)</td><td>Poin karyawan per pos ({{ collect($jm::POS)->pluck('nama')->implode(', ') }}), boleh desimal. Sumber <b>Generate Poin dari Master</b> di menu Pembagian Jasa Medik (bagian 3).</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-sm">Mengubah parameter <b>tidak mengubah slip yang sudah ada</b> — berlaku untuk slip yang di-generate berikutnya (atau slip Draft yang di-generate ulang).
                        Jasa medik dihitung di menu Pembagian Jasa Medik; hari kerja &amp; upah lembur di menu Laporan Kepegawaian; kasbon, angsuran dll. berubah tiap bulan — diisi lewat Form Bagian (Excel) atau rincian slip.</p>
                    <p class="mt-2 text-sm">No. HP karyawan (untuk kirim WhatsApp) diisi di form <b>Edit</b> Master Karyawan.</p>
                </section>

                {{-- GENERATE --}}
                <section id="generate" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>2. Generate slip bulanan — {!! $tautan('keuangan.gaji-karyawan', 'buka') !!}</h2>
                    <ol class="mt-3 text-sm">
                        <li>Pilih <b>Bulan Kerja</b> (tombol ‹ ›). Saat dibuka, otomatis bulan lalu.</li>
                        <li>Bila perlu, saring <b>Unit</b> — generate hanya untuk unit itu.</li>
                        <li>Klik <b>Generate</b> → konfirmasi. Slip <b>Draft</b> dibuat untuk semua karyawan aktif yang punya parameter gaji.</li>
                    </ol>
                    <div class="p-3 mt-3 text-sm border rounded-xl border-brand-green/40 bg-brand-green/5">
                        <b>Generate ulang aman.</b> Slip <b>Final</b> dilewati. Pada slip Draft, baris dari parameter disusun ulang, tetapi
                        <b>hari kerja</b>, baris yang diisi petugas / hasil impor / jasa medik dan nilai yang <i>ditimpa</i> <b>tetap</b>.
                    </div>
                    <p class="mt-3 text-sm">Ringkasan di bawah toolbar: jumlah slip &amp; draft, total pendapatan, potongan, diterima, dan jumlah slip final yang belum dikirim WA.</p>
                </section>

                {{-- PEMBAGIAN JM --}}
                <section id="jm" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>3. Pembagian Jasa Medik — {!! $tautan('keuangan.pembagian-jm', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Pengganti sheet <b>JaMed</b> Excel lama. JM tiap karyawan dihitung dari perolehan JM per pos dan poin karyawan, lalu ditulis ke slip.</p>
                    <h3 class="mt-4">Persiapan (sekali)</h3>
                    <ul class="mt-1 text-sm">
                        <li>Isi <b>Poin Jasa Medik</b> tiap karyawan di {!! $tautan('master.karyawan', 'Master Karyawan') !!} › tombol <b>Gaji</b>. Karyawan tanpa poin tidak ikut pembagian.</li>
                    </ul>
                    <h3 class="mt-4">Tiap bulan</h3>
                    <ol class="mt-1 text-sm">
                        <li>Pilih <b>Bulan Kerja</b> (sama dengan bulan slip).</li>
                        <li>Tabel <b>1. Perolehan JM per pos</b>: isi <b>Nilai Dasar</b> tiap pos, lalu klik di luar kotak. Persen bawaan sudah terisi dan menyalin bulan lalu — ubah bila kebijakan berubah.</li>
                        <li>Klik <b>Generate Poin dari Master</b> → poin bulan ini terisi dari master.</li>
                        <li>Bila bulan ini ada yang berbeda (mis. cuti panjang), ubah poinnya langsung di tabel <b>2. Poin karyawan</b>. Poin yang diubah bertanda <b>✎</b> dan <b>tidak ditimpa</b> saat Generate ulang.</li>
                        <li>Periksa angka kecil di bawah kotak poin (JM per pos) dan kolom <b>Total JM</b>.</li>
                        <li>Klik <b>Terapkan ke Slip</b> → baris Jasa Medik ditulis ke slip Draft, satu baris per pos, mis. "Rawat Inap (3 poin)".</li>
                    </ol>
                    <table class="mt-3 text-sm ds-table">
                        <thead><tr><th>Pos</th><th>Nilai dasar</th><th class="text-right">% JM</th><th class="text-right">% Pegawai (bawaan)</th></tr></thead>
                        <tbody>
                            @foreach ($jm::POS as $pos)
                                <tr><td class="font-semibold">{{ $pos['nama'] }}</td><td>{{ $pos['dasar'] }}</td>
                                    <td class="text-right">{{ rtrim(rtrim(number_format($pos['persen_jm'], 2, ',', '.'), '0'), ',') }}%</td>
                                    <td class="text-right">{{ rtrim(rtrim(number_format($pos['persen_pegawai'], 2, ',', '.'), '0'), ',') }}%</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="p-3 mt-3 text-sm border rounded-xl border-brand-green/40 bg-brand-green/5">
                        <b>Terapkan ke Slip aman diulang.</b> JM di slip <b>diganti</b> angka terbaru, bukan ditambah. Setelah nilai dasar, persen, atau poin berubah,
                        muncul tanda <b>"N slip Draft belum sesuai hitungan"</b> dan kolom Slip bertanda <b>Belum sesuai</b> — cukup klik Terapkan lagi.
                        Satu poin berubah membuat nilai per poin <b>semua</b> orang ikut berubah, jadi selalu terapkan ulang semuanya.
                    </div>
                    <ul class="mt-3 text-sm">
                        <li>Slip <b>Final</b> dilewati (tanda merah "Final, beda"). Buka slipnya ke Draft dulu bila memang perlu diubah.</li>
                        <li>Karyawan berpoin yang belum punya slip disebut di pesan — Generate slip dulu, lalu Terapkan lagi.</li>
                        <li>Karyawan yang dikeluarkan dari master: Generate Poin lalu Terapkan → JM lamanya ikut dihapus dari slip.</li>
                        <li>Jasa Medik <b>tidak</b> ada di Form Bagian (Excel) — supaya JM slip hanya punya satu sumber.</li>
                    </ul>
                </section>

                {{-- LAPORAN KEPEGAWAIAN --}}
                <section id="lap" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>4. Laporan Kepegawaian — {!! $tautan('keuangan.laporan-pegawai', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Pengganti sheet <i>Lap Pegawai</i> Excel lama (laporan Kasubbag Kepegawaian). Hari kerja, cuti, dan jam lembur tiap karyawan diisi kepegawaian dari catatan mereka (daftar lembur, catatan kehadiran), lalu ditulis ke slip oleh Keuangan.
                        Berdiri sendiri seperti Pembagian JM — <b>tidak terhubung</b> ke modul absensi program.
                        Role <b>HRD</b> mengisi; <b>Keuangan</b> hanya membaca dan menerapkan; <b>Admin</b> keduanya.</p>
                    <h3 class="mt-4">Tiap bulan</h3>
                    <ol class="mt-1 text-sm">
                        <li>Halaman punya dua tab: <b>Entry Laporan</b> (tabel isian kepegawaian) dan <b>Buku Laporan</b> (baca saja + Terapkan ke Slip). Pilih <b>Bulan Kerja</b> (sama dengan bulan slip), pastikan slip sudah di-<b>Generate</b> dan <b>JM sudah diterapkan</b> (dasar upah lembur memuat JM).</li>
                        <li>Tab Entry: kolom sudah <b>terisi bawaan</b>: hari kerja = hari kerja slip bulan ini (slip baru memakai <b>Hari Kerja Default</b> Master Karyawan → Gaji), cuti &amp; lembur 0. Tidak perlu mengisi satu per satu.</li>
                        <li>Ubah hanya yang berbeda: <b>Hari Kerja</b>, <b>Cuti</b>, <b>Jam Lembur</b> langsung di kotaknya; klik di luar kotak = tersimpan, nama karyawan bertanda ✎. Angka kecil di bawah kotak Hari Kerja = hari kerja di slip saat ini. Kolom <b>Keterangan</b> untuk catatan ke Keuangan (mis. "lembur laundry", "cuti panjang").</li>
                        <li>Kolom <b>Upah Lembur</b> = perkiraan dari dasar slip saat ini (pokok + JM + makan + transport).</li>
                        <li>Tab Buku: Keuangan memeriksa laporan (kolom Slip menunjukkan kecocokan) lalu klik <b>Terapkan ke Slip</b> → hari kerja slip disamakan (tunjangan makan &amp; transport dihitung ulang) dan baris <b>Upah Lembur</b> ditulis, mis. "6 jam".</li>
                    </ol>
                    <ul class="mt-3 text-sm">
                        <li><b>Terapkan ke Slip aman diulang.</b> Hari kerja &amp; lembur di slip diganti angka terbaru, bukan ditambah. Bila laporan, JM, atau hari kerja berubah setelah diterapkan, muncul tanda "N slip Draft belum sesuai laporan" dan kolom Slip bertanda <b>Belum sesuai</b> — cukup klik Terapkan lagi.</li>
                        <li>Slip <b>Final</b> dilewati (tanda merah "Final, beda"). Buka slipnya ke Draft dulu bila memang perlu diubah.</li>
                        <li>Karyawan yang belum punya slip disebut di pesan — Generate slip dulu, lalu Terapkan lagi.</li>
                        <li>Unit yang memang kerja 22 hari: ubah <b>Hari Kerja Default</b> karyawannya di Master Karyawan sekali saja, supaya tiap bulan tinggal koreksi cuti &amp; lembur.</li>
                        <li>Karyawan non-aktif keluar dari hitungan (sama seperti JM); barisnya tidak dihapus, jadi bila diaktifkan lagi angkanya kembali.</li>
                        <li>Cuti hanya informasi (tidak masuk slip) — kurangi sendiri hari kerjanya.</li>
                        <li>Upah lembur = jam ÷ {{ $lap::LEMBUR_JAM_PER_HARI }} ÷ {{ $lap::LEMBUR_HARI_ACUAN }} × dasar × {{ $lap::LEMBUR_FAKTOR * 100 }}%. Lembur tidak ada di Form Bagian (Excel) — supaya lembur slip hanya punya satu sumber.</li>
                    </ul>
                </section>

                {{-- PINJAMAN KARYAWAN --}}
                <section id="pinjaman" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>5. Pinjaman Karyawan — {!! $tautan('keuangan.pinjaman-karyawan', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Pengganti buku pinjaman koperasi (workbook <i>Angsuran Tiap Bulan Potong Gaji</i>, sheet 1% / 0,8% / LAPORAN 1) dan blok BRI / Koperasi / Rinjani di sheet <i>angsuran</i> workbook gaji lama. Empat tab: <b>Entry</b> (catat pinjaman), <b>Buku</b> (posisi &amp; Terapkan ke Slip), <b>Pembayaran</b> (bayar di luar gaji &amp; riwayat per orang), <b>Ketentuan</b> (aturan per sumber).</p>

                    <h3 class="mt-4">Cara kerja</h3>
                    <ol class="mt-1 text-sm">
                        <li><b>Ketentuan per sumber</b> (Koperasi, BRI, …): % sewa modal / bulan, pembulatan, dan baris slip tujuan. Dipakai saat pinjaman <b>baru</b> dicatat; pinjaman lama menyimpan % saat pinjam.</li>
                        <li><b>Pinjaman</b> = pokok, lama bulan, bulan kerja angsuran ke-1. Angsuran / bulan = (pokok ÷ lama + pokok × %) dibulatkan ke atas — atau <b>angsuran tetap</b> bila pemberi pinjaman (bank) sudah menetapkannya. Tiap angsuran dikenali lewat <b>bulan kerjanya</b>: ke-1 = bulan awal, ke-2 = bulan berikutnya, dst.</li>
                        <li><b>Buku pembayaran</b>: satu baris per angsuran yang terbayar, dengan sumbernya — <b>potong gaji</b> (dibuat otomatis saat Terapkan ke Slip) atau <b>tunai / transfer</b> (dicatat Keuangan di tab Pembayaran). Satu angsuran hanya bisa dibayar sekali, jadi tidak mungkin terpotong dobel.</li>
                        <li><b>Tiap bulan kerja, Terapkan ke Slip</b> menanyakan untuk tiap pinjaman berjalan: "kewajiban bulan ini sudah dibayar?" Belum → dipotong di slip Draft &amp; dicatat potong gaji. Sudah (tunai / di muka) → dilewati. Ditunda → dilewati, tetap belum terbayar. Aman diulang: baris di slip diganti, bukan ditambah; slip <b>Final</b> tidak disentuh.</li>
                        <li><b>Status</b> dihitung dari buku setiap kali ditampilkan, tidak disimpan: <i>Berjalan</i> (ke-n di antara awal–akhir, belum semua terbayar), <i>Lunas</i> (semua angsuran terbayar, atau total nominal ≥ total hutang, atau masa habis), <i>Dilunasi</i> (tanda Lunasi / ditutup pinjaman baru), <i>Belum mulai</i>. <b>Tunggakan</b> = angsuran yang bulannya sudah lewat tanpa pembayaran apa pun (termasuk yang ditunda) — tampil merah.</li>
                        <li><b>Kunci data</b>: pinjaman yang sudah punya satu pembayaran pun tidak bisa dihapus (salah input → hapus &amp; catat lagi sebelum ada pembayaran). Angsuran yang sudah terpotong di slip <b>Final</b> tidak bisa diubah, ditunda, atau dihapus dari buku.</li>
                    </ol>

                    <h3 class="mt-4">Langkah rutin</h3>
                    <ol class="mt-1 text-sm">
                        <li><b>Sekali / bila berubah</b> — tab Ketentuan: isi % sewa modal, pembulatan, baris slip; nyalakan bank yang dipakai (bank umum sudah disiapkan nonaktif), tambah sumber baru bila perlu.</li>
                        <li><b>Saat ada yang pinjam</b> — tab Entry: pilih karyawan (ketik nama / ID, pilih dari daftar), sumber, pokok, lama, bulan kerja angsuran ke-1, angsuran tetap bila dari bank. Centang "melunasi sisa pinjaman lama" bila pinjaman ini menggantikan pinjaman berjalan di sumber yang sama (praktik koperasi). Kotak pratinjau menampilkan angsuran / bulan dan bulan terakhir. <b>Simpan ke Buku</b>.</li>
                        <li><b>Tiap bulan</b> — tab Buku: pilih Bulan Kerja (= bulan slip), pastikan slip sudah di-Generate dan JM diterapkan, lalu <b>Terapkan ke Slip</b>. Kolom Slip menunjukkan kecocokan; tanda "N slip Draft belum sesuai buku pinjaman" = perlu Terapkan lagi.</li>
                        <li><b>Memeriksa seseorang</b> — tab Pembayaran: pilih karyawan → semua pinjamannya, tiap angsuran yang terbayar dan sumbernya, serta <b>kurang berapa</b>.</li>
                    </ol>

                    <h3 class="mt-4">Kasus per kasus</h3>
                    <dl class="mt-1 space-y-3 text-sm">
                        <div><dt class="font-semibold">A. Angsuran bulan ini sudah dibayar tunai / transfer sebelum gajian</dt>
                            <dd><ol class="mt-1">
                                <li>Tab Pembayaran → pilih karyawan → klik pinjamannya.</li>
                                <li>Isi angsuran ke-n bulan itu (bawaan = angsuran pertama yang belum terbayar), cara bayar, tanggal, jumlah → <b>Catat Pembayaran</b>.</li>
                                <li>Tab Buku → <b>Terapkan ke Slip</b> bila slip sudah berisi angsurannya → potongan dicabut; posisi menampilkan "bulan ini: Tunai".</li>
                            </ol></dd></div>
                        <div><dt class="font-semibold">B. Bayar dobel / di muka di luar gaji</dt>
                            <dd>Sama seperti A, isi "ke-n sampai ke-m" (mis. ke-5 sampai ke-7). Bulan-bulan itu dilewati saat tiba; bulan sesudahnya kembali dipotong gaji. Bila nominal yang dibayar sudah mencapai total hutang, status langsung Lunas.</dd></div>
                        <div><dt class="font-semibold">C. Bulan ini minta tidak dipotong dulu, dibayar nanti</dt>
                            <dd><ol class="mt-1">
                                <li>Tab Buku → nyalakan sakelar <b>Tunda</b> di sel posisi (baris "bulan ini").</li>
                                <li><b>Terapkan ke Slip</b> lagi → baris angsuran dicabut dari slip; posisi menampilkan "bulan ini: ditunda". Kewajiban tetap belum terbayar.</li>
                                <li>Bulan depan angsuran itu tampil merah sebagai tunggakan. Selesaikan lewat kasus A (bayar tunai), atau klik <b>Tagih tunggakan</b> → ikut dipotong di slip bulan depan bersama angsuran bulan depan (dobel), lalu Terapkan.</li>
                                <li>Salah tekan → matikan sakelar Tunda, Terapkan lagi → kembali dipotong.</li>
                            </ol>Selalu Terapkan ulang <b>sebelum</b> slip difinalkan; bila slip terlanjur Final, lihat kasus E.</dd></div>
                        <div><dt class="font-semibold">D. Slip gaji dibuka ke Draft, lalu potongan dinonaktifkan</dt>
                            <dd>Membuka slip ke Draft saja tidak mengubah buku. Setelah Tunda dinyalakan, catatan potong gaji bulan itu dicabut → angsuran kembali <b>belum terbayar</b> dan jadi tunggakan bulan depan. Terapkan sebelum Final lagi supaya slip dan buku selaras.</dd></div>
                        <div><dt class="font-semibold">E. Slip sudah Final</dt>
                            <dd>Semua yang menyangkut angsuran bulan itu terkunci: Tunda, bayar tunai untuk bulan itu, hapus catatan potong gaji, Lunasi mulai bulan itu — semua ditolak dengan pesan. Jalur resmi: Gaji Karyawan → buka slip ke Draft → lakukan perubahan → Terapkan → Final lagi. Bila gaji memang sudah dibayarkan ke karyawan, anggap angsuran bulan itu terbayar dan berlakukan Tunda / pelunasan untuk bulan berikutnya.</dd></div>
                        <div><dt class="font-semibold">F. Pelunasan dipercepat / sisa dihapuskan</dt>
                            <dd>Bila sisanya <b>dibayar</b>: kasus B (ke-n sampai angsuran terakhir) → Lunas. Bila sisanya <b>tidak ditagih</b> (dihapuskan, atau ditutup pinjaman baru): tombol <b>Lunasi</b> dengan Bulan Kerja = bulan pertama yang tidak dipotong (pelunasan sesudah potongan bulan ini dihitung → pilih bulan berikutnya) → status Dilunasi; riwayat per orang menulis "sisa N× tidak ditagih". Salah tandai → <b>Batal lunas</b>. Menghapus pembayaran tidak mengaktifkan pinjaman Dilunasi.</dd></div>
                        <div><dt class="font-semibold">G. Salah input pinjaman</dt>
                            <dd>Tidak ada tombol ubah. <b>Hapus</b> di Buku lalu catat lagi di Entry — hanya bisa selama belum ada pembayaran (termasuk belum pernah Terapkan). Sesudah itu pinjaman terkunci sebagai data valid.</dd></div>
                        <div><dt class="font-semibold">H. Satu orang punya dua pinjaman</dt>
                            <dd>Boleh. Sumber berbeda (koperasi + bank) berjalan paralel, masing-masing satu baris potongan di slip. Sumber sama: centang "melunasi sisa pinjaman lama" di Entry aktif secara bawaan; hilangkan centang bila keduanya memang berjalan bersama. Tab Pembayaran menampilkan semua pinjaman orang itu berdampingan dengan total kurangnya.</dd></div>
                        <div><dt class="font-semibold">I. Dokter / karyawan yang gajinya tidak dihitung di SIAP tapi punya pinjaman</dt>
                            <dd><ol class="mt-1">
                                <li>Master Karyawan → tombol Gaji → simpan parameter <b>nol</b> (hari kerja default 0), lalu Generate slipnya di Gaji Karyawan.</li>
                                <li>Terapkan ke Slip → slip <b>minus</b> sebesar angsuran. Angka minus ini <b>tagihan</b>, bukan bukti bayar.</li>
                                <li>Sebelum slip difinalkan, pastikan angka itu benar-benar dipotong di sistem honor dokter — begitu Final, buku mencatat angsuran terbayar lewat gaji. Bila honor tidak memotong, catat pembayarannya di tab Pembayaran (kasus A) dan jangan finalkan potongan di slip.</li>
                            </ol></dd></div>
                        <div><dt class="font-semibold">J. Pinjaman bank dari workbook lama</dt>
                            <dd>Workbook hanya menyimpan tanggal mulai, lama, dan angsuran. Dicatat sebagai pinjaman BRI dengan <b>angsuran tetap</b>; pokok diisi angsuran × lama (pokok asli tidak tercatat), jadi "sisa" = sisa angsuran × nominal, bukan sisa pokok menurut bank. Riwayat sampai bulan sebelum SIAP dipakai dicatat sebagai potong gaji "sebelum SIAP" supaya tidak tampak sebagai tunggakan.</dd></div>
                        <div><dt class="font-semibold">K. File koperasi dan workbook gaji tidak sama</dt>
                            <dd>Buku pinjaman mengikuti file koperasi (bendahara). Bandingkan tanda "Belum sesuai" / tunggakan di Buku dengan workbook, lalu putuskan bersama bendahara: Lunasi / Hapus bila memang tidak berjalan, atau biarkan ditagih.</dd></div>
                    </dl>
                </section>

                {{-- FORM BAGIAN --}}
                <section id="form-bagian" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>6. Form Bagian (Excel) — ekspor &amp; impor</h2>
                    <p class="mt-2 text-sm">Pengganti sheet-sheet Excel lama per bagian. Keuangan mengekspor form, bagian terkait mengisi, keuangan mengimpor kembali.</p>
                    <ol class="mt-3 text-sm">
                        <li>Di {!! $tautan('keuangan.gaji-karyawan', 'Gaji Karyawan') !!} pilih <b>Bulan Kerja</b> (dan Unit bila perlu), pastikan slip sudah di-<b>Generate</b>.</li>
                        <li>Klik <b>Form Bagian</b> → pada kartu bagian, klik <b>Ekspor Form</b> → berkas <code>.xlsx</code> terunduh (nama berkas memuat bagian &amp; bulan).</li>
                        <li>Kirim ke bagian terkait. Mereka mengisi <b>kolom kuning</b> saja — lihat baris <b>Cara isi</b> dan baris <b>CONTOH</b> (biru) di atas data.</li>
                        <li>Berkas kembali → buka <b>Form Bagian</b> → di kartu yang sama pilih berkas → <b>Impor</b> → konfirmasi. Hasilnya tampil di kartu: berapa diperbarui, tidak berubah, dilewati, dan baris yang salah.</li>
                    </ol>
                    <table class="mt-3 text-sm ds-table">
                        <thead><tr><th>Form</th><th>Diisi oleh</th><th>Kolom isian</th></tr></thead>
                        <tbody>
                            @foreach ($formBagian::daftar() as $form)
                                <tr><td class="font-semibold">{{ $form['judul'] }}</td><td>{{ $form['petugas'] }}</td>
                                    <td>{{ collect($form['kolom'])->where('isi', true)->pluck('label')->implode(', ') }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <h3 class="mt-4">Aturan impor</h3>
                    <ul class="mt-1 text-sm">
                        <li>Angka di form <b>mengganti</b> komponen itu di slip. Kosong = 0 (komponen dihapus), kecuali <b>Hari Kerja</b> &amp; <b>Kenaikan Gaji</b>: kosong = tidak diubah.</li>
                        <li>Form terikat <b>bulan &amp; bagian</b>: form bulan lain atau bagian lain ditolak. Ganti bulan di toolbar dulu bila perlu.</li>
                        <li>Baris dicocokkan lewat <b>ID Karyawan + nama</b>. ID yang diubah / ganda dilewati dan dilaporkan.</li>
                        <li>Slip <b>Final</b> dilewati. Karyawan yang angkanya sama dengan slip tidak disentuh.</li>
                        <li>Form dikunci bersandi: hanya kolom kuning yang bisa diisi, baris/kolom tidak bisa disisip atau dihapus.</li>
                        <li>Impor boleh diulang — berkas yang sama diimpor dua kali tidak menggandakan angka.</li>
                    </ul>
                    <h3 class="mt-4">Catatan per form</h3>
                    <ul class="mt-1 text-sm">
                        <li><b>Hari Kerja</b>: koreksi cepat per slip. Jalur utamanya tetap {!! $tautan('keuangan.laporan-pegawai', 'Laporan Kepegawaian') !!} (bagian 4); hari kerja yang diubah lewat form ini akan tampil "Belum sesuai" di sana sampai diterapkan ulang.</li>
                        <li><b>Kenaikan Gaji</b>: mengubah parameter gaji karyawan, dan slip Draft bulan itu langsung disusun ulang.</li>
                        <li><b>Iuran Koperasi</b>: menimpa potongan rutin parameter untuk bulan itu saja. Angsuran pinjaman tidak di sini — lihat {!! $tautan('keuangan.pinjaman-karyawan', 'Pinjaman Karyawan') !!} (bagian 5). BPJS juga tidak di sini — otomatis dari master (bagian 1).</li>
                    </ul>
                    <p class="mt-3 text-sm">Sandi proteksi form hanya diketahui keuangan (pengaturan <code>GAJI_FORM_SANDI</code> di server).</p>
                </section>

                {{-- RINCIAN --}}
                <section id="rincian" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>7. Rincian slip</h2>
                    <p class="mt-2 text-sm">Klik ikon mata (<b>Rincian</b>) atau nama karyawan. Kiri: tabel rincian. Kanan: Hari Kerja, Tambah Baris, Ringkasan.</p>
                    <h3 class="mt-4">Tambah baris (tiap bulan)</h3>
                    <ol class="mt-1 text-sm">
                        <li>Di kotak <b>Tambah Baris</b> pilih <b>Pendapatan</b> atau <b>Potongan</b>, lalu komponennya.</li>
                        <li>Isi keterangan (opsional, mis. "6 jam lembur") dan nilai, klik <b>Tambah</b>.</li>
                        <li><b>Potongan cukup diisi angka positif</b> — otomatis dikurangkan.</li>
                    </ol>
                    <div class="grid grid-cols-1 gap-3 mt-3 text-sm sm:grid-cols-2">
                        <div class="p-3 border rounded-xl border-hairline">
                            <p class="font-semibold text-ink dark:text-gray-100">Komponen pendapatan</p>
                            <p class="mt-1 text-muted">{{ collect($gaji::PENDAPATAN)->implode(' · ') }}</p>
                        </div>
                        <div class="p-3 border rounded-xl border-hairline">
                            <p class="font-semibold text-ink dark:text-gray-100">Komponen potongan</p>
                            <p class="mt-1 text-muted">{{ collect($gaji::POTONGAN)->implode(' · ') }}</p>
                        </div>
                    </div>
                    <h3 class="mt-4">Mengubah &amp; menghapus</h3>
                    <ul class="mt-1 text-sm">
                        <li><b>Ubah nilai</b>: ketik di kotak Nilai (Rp), lalu <b>klik di luar kotak</b> — tersimpan dan total dihitung ulang. Tidak ada tombol Simpan.</li>
                        <li>Label kecil di samping komponen: <i>otomatis</i> = dari parameter · <i>ditimpa</i> = nilai parameter diubah petugas (dipertahankan saat generate ulang) · <i>× hari kerja</i> = makan/transport.</li>
                        <li>Baris <i>ditimpa</i> punya tombol <b>panah balik</b> untuk mengembalikan ke nilai parameter.</li>
                        <li>Baris yang ditambah petugas bisa <b>dihapus</b> (ikon tong sampah). Baris otomatis tidak bisa dihapus — ubah nilainya ke 0 bila perlu.</li>
                        <li><b>Susun ulang</b> (footer) menyusun ulang baris otomatis dari parameter terbaru; hari kerja &amp; isian petugas tetap.</li>
                    </ul>
                </section>

                {{-- HARI KERJA --}}
                <section id="hari-kerja" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>8. Mengubah hari kerja (cuti / izin / tambahan)</h2>
                    <ol class="mt-3 text-sm">
                        <li>Buka <b>Rincian</b> slip karyawan.</li>
                        <li>Di kotak <b>Hari Kerja</b> (kanan atas) ketik jumlah harinya, mis. 25 dikurangi 2 hari cuti → <b>23</b>.</li>
                        <li><b>Klik di luar kotak</b> (atau tekan Tab) — tersimpan. Tunjangan Makan &amp; Transport langsung dihitung ulang (23 × tarif), begitu juga total &amp; gaji diterima.</li>
                    </ol>
                    <ul class="mt-3 text-sm">
                        <li>Di bawah kotak tampil acuan <b>"Absensi bulan ini: N hari hadir"</b> (Hadir/Terlambat dari modul absensi). Bila beda, tersedia tombol <b>Pakai absensi (N hari)</b>.</li>
                        <li>Hari kerja tersimpan <b>per slip</b> — tidak ikut berubah saat Generate / Susun ulang. Hari kerja default di parameter hanya untuk slip baru.</li>
                        <li>Batas 0–31 hari. Hanya bisa diubah selama slip <b>Draft</b>.</li>
                        <li>Untuk <b>semua karyawan sekaligus</b> pakai {!! $tautan('keuangan.laporan-pegawai', 'Laporan Kepegawaian') !!} (bagian 4) — hari kerja isian kepegawaian diterapkan ke seluruh slip Draft.</li>
                    </ul>
                </section>

                {{-- PPH --}}
                <section id="pph" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>9. PPh 21</h2>
                    <ul class="mt-3 text-sm">
                        <li>Dihitung otomatis: <b>PPh 21 (%) × total pendapatan</b>, dibulatkan ke bawah. Baris PPh muncul bila persennya &gt; 0.</li>
                        <li>Untuk memakai nominal sendiri (mis. hasil hitungan TER), <b>ubah nilai baris PPh 21</b> di tabel — baris jadi <i>ditimpa</i> dan tidak dihitung ulang lagi. Panah balik mengembalikan ke hitungan persen.</li>
                    </ul>
                </section>

                {{-- FINAL --}}
                <section id="final" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>10. Final &amp; buka kembali</h2>
                    <ul class="mt-3 text-sm">
                        <li><b>Final</b> mengunci slip: rincian &amp; hari kerja tidak bisa diubah, generate ulang melewatinya. Tombol Final ada di kolom Aksi, footer Rincian, atau <b>Final Semua</b> (semua Draft yang tampil).</li>
                        <li>Salah? Klik <b>Buka</b> (kuning) / <b>Buka kembali ke Draft</b> — slip bisa diubah lagi. Link WhatsApp yang sudah dikirim ikut <b>mati</b> sampai slip difinalkan lagi.</li>
                        <li>Kirim WhatsApp hanya untuk slip Final, supaya karyawan menerima angka yang sudah pasti.</li>
                    </ul>
                </section>

                {{-- CETAK & WA --}}
                <section id="cetak" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>11. Cetak &amp; kirim WhatsApp</h2>
                    <h3 class="mt-3">Cetak</h3>
                    <ul class="mt-1 text-sm">
                        <li>Ikon printer biru di baris = slip satu karyawan. <b>ZIP</b> di toolbar = semua slip yang tampil (sesuai filter) diunduh sekaligus, satu PDF per karyawan bernama <i>Slip Gaji - NAMA - Bulan Tahun.pdf</i>.</li>
                        <li>Slip memakai kop identitas instansi ({!! $tautan('sistem.identitas', 'Identitas Instansi') !!}); slip Draft bertanda <b>(DRAFT)</b>.</li>
                    </ul>
                    <h3 class="mt-4">Kirim WhatsApp</h3>
                    <ol class="mt-1 text-sm">
                        <li>Pastikan slip <b>Final</b> dan No. HP karyawan terisi. Ikon WA abu-abu = belum bisa (arahkan kursor untuk alasannya).</li>
                        <li>Klik ikon <b>WhatsApp hijau</b> → WhatsApp Web / aplikasi terbuka ke nomor karyawan dengan pesan sudah terisi (ringkasan + link PDF slip).</li>
                        <li>Tekan <b>Kirim</b> di WhatsApp. Di SIAP slip ditandai <b>WA ✓ tanggal</b>.</li>
                    </ol>
                    <ul class="mt-3 text-sm">
                        <li>Link PDF bisa dibuka karyawan <b>tanpa login</b>, hanya untuk slipnya sendiri, berlaku <b>{{ $gaji::MASA_LINK_SLIP_HARI }} hari</b>.</li>
                        <li>Badge kuning <b>"N slip final belum dikirim WA"</b> di bawah toolbar menunjukkan sisa yang belum dikirim; slip yang sudah ditandai <b>WA ✓</b> di kolom Status.</li>
                        <li>Tanda WA ✓ = tombol sudah diklik, bukan bukti pesan terkirim — pastikan menekan Kirim di WhatsApp.</li>
                    </ul>
                    <div class="p-3 mt-3 text-sm border rounded-xl border-amber-300 bg-amber-50 text-amber-900">
                        ⚠️ Alamat link mengikuti alamat SIAP yang sedang dibuka. Bila SIAP dibuka lewat alamat jaringan lokal (mis. <code>192.168.x.x</code>),
                        link hanya bisa dibuka dari wifi kantor. Kirim slip saat SIAP dibuka lewat alamat publik (domain / HTTPS).
                    </div>
                </section>

                {{-- GAJI DOKTER (SIRUS) --}}
                <section id="gaji-dokter" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>12. Gaji Dokter (SIRUS) — {!! $tautan('keuangan.gaji-dokter', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Gaji dokter spesialis (uang periksa, jasa tindakan, visite, operasi, kapita, potongan RS, PPh 21) <b>dihitung di SIRUS</b>, bukan di SIAP. SIAP menariknya lewat API baca-saja dan menyimpannya sebagai <b>snapshot berversi per bulan jasa</b>, supaya angka yang sudah diproses tidak berubah diam-diam dan perubahan di SIRUS selalu terlihat. Empat tab: <b>Buku</b> (slip per bulan jasa + Ambil dari SIRUS; tiap baris punya modal <b>Rincian</b> dan <b>Lampiran</b> rincian pasien, plus <b>Lampiran Semua</b> di toolbar — sama seperti Slip Gaji Dokter di SIRUS), <b>Pemetaan Dokter</b> (dokter SIRUS ↔ karyawan SIAP), <b>Struktur Gaji</b> (struktur gaji per dokter + mode pemindahan ke SIAP), <b>Riwayat Ambil</b>.</p>

                    <h3 class="mt-4">Cara kerja</h3>
                    <ol class="mt-1 text-sm">
                        <li><b>Sambungan</b>: SIAP login ke API SIRUS memakai <b>akun user SIRUS</b> ber-role <i>Api Siap</i> (dibuat admin SIRUS dengan <code>php artisan siap-api:akun siap-gaji</code>; bisa dinonaktifkan / diganti password dari User Control SIRUS, dan tidak bisa dipakai masuk ke web SIRUS). Username / password-nya diisi di <code>.env</code> SIAP (<code>SIAP_API_URL</code>, <code>SIAP_API_USER</code>, <code>SIAP_API_PASSWORD</code>); rahasia JWT di <code>.env</code> SIRUS (<code>SIAP_API_JWT_SECRET</code>, terpisah dari JWT Mobile JKN). SIRUS membalas token berumur 1 jam; permintaan berikutnya membawa token itu. API-nya <b>hanya membaca</b> — SIAP tidak pernah mengubah data SIRUS. Bila kredensial belum diisi, tombol Ambil tidak muncul.</li>
                        <li><b>Bulan jasa</b>: periode di SIAP = bulan pelayanan (jasa). Di SIRUS slip bulan jasa Juli dibayar sebagai bulan gaji Agustus — kolom "bulan gaji" ikut tersimpan di snapshot. Di Gaji Karyawan, padanannya adalah slip <b>bulan kerja Juli</b> (dibayar awal Agustus) — periode yang sama, beda sebutan.</li>
                        <li><b>Ambil dari SIRUS</b> menarik master dokter, semua slip bulan jasa itu (header + komponen J/P/T) dan lampirannya. Tiap dokter diberi <b>checksum</b> (angka header, komponen, lampiran). Sama dengan snapshot terakhir → hanya "terakhir diambil" diperbarui. Berbeda → <b>versi baru</b>; versi lama tetap tersimpan (tidak pernah ditimpa). Aman diulang kapan saja.</li>
                        <li><b>Versi &amp; pembanding</b>: di Buku, slip yang pernah berubah ditandai <i>versi N</i> (saringan "Pernah berubah"). Tombol <b>Rincian</b> membuka komponen slip dan pilihan <b>Bandingkan dengan</b>: versi sebelumnya (apa yang berubah sejak kita proses) atau bulan jasa sebelumnya (naik / turun dibanding bulan lalu). Baris yang berbeda disorot, selisih per komponen dan per total ditampilkan. Kolom "vs bulan lalu" di Buku = selisih gaji diterima.</li>
                        <li><b>Pemetaan dokter → karyawan</b>: saat Ambil / Segarkan, dokter yang namanya cocok persis dengan master karyawan (gelar diabaikan, hanya satu yang cocok) dipetakan otomatis. Sisanya dipetakan manual di tab Pemetaan lewat LOV karyawan. Dokter tanpa pemetaan tetap ditarik dan ditandai "Belum dipetakan" — tidak memblokir.</li>
                        <li><b>Lampiran</b>: tiap angka slip ditopang baris pasien / transaksi (tanggal, pasien, tindakan, klaim, no. SEP, nominal). Tabel per komponen berikut subtotal, komponen besar dilipat sampai diminta, dan di bawahnya rekonsiliasi total transaksi dengan jasa pada slip. Grup "dasar hitung per kapita" = grup kapita yang di SIRUS dibayar lewat skema lain, ditampilkan untuk pembanding.</li>
                        <li><b>Model penggajian tiap dokter</b> (gaji pokok &amp; skema A aditif / G garanty fee / N tanpa pokok, basis potongan RS T total / J jasa / N tidak / B berjenjang beserta persennya, persen PPh 21, status NPWP, tarif per kapita, tunjangan &amp; potongan rutin) <b>disetel di SIRUS</b>: Master Dokter → tombol <b>Struktur Gaji</b> (panel terpisah dari Ubah Data Dokter, milik bagian keuangan). Panel itu punya panduan sendiri "Cara menyetel struktur gaji". SIAP hanya membacanya — nilai yang berlaku saat slip diproses tersimpan di snapshot dan tampil di kartu identitas Rincian. Urutan bila setelannya berubah: ubah di Master Dokter SIRUS → <b>Proses Slip</b> ulang bulan itu di SIRUS (Buka Kunci dulu bila sudah Final) → di SIAP <b>Ambil dari SIRUS</b> (jadi versi baru) → <b>Terapkan ke slip gaji</b> ulang.</li>
                        <li><b>Tidak ada sunting</b>: snapshot tidak bisa diubah di SIAP. Koreksi dilakukan di SIRUS, lalu Ambil ulang → versi baru.</li>
                        <li><b>Terapkan ke slip gaji</b>: menulis snapshot versi terbaru tiap dokter yang <b>sudah dipetakan</b> ke slip Draft Gaji Karyawan bulan kerja yang sama dengan bulan jasa — <b>satu baris per komponen</b> persis seperti slip SIRUS: tiap jasa (uang periksa, visite, operator, …), gaji pokok dokter, dan tambahan sebagai pendapatan; Potongan RS, PPh 21 SIRUS, dan potongan lain (IDI, BPJS, …) sebagai potongan. Diterima di slip SIAP = diterima di SIRUS. PPh 21 otomatis SIAP <b>tidak</b> menghitung baris ini (sudah dipajaki SIRUS). Dokter yang belum punya slip (biasanya tanpa parameter gaji, jadi dilewati Generate) dibuatkan slip Draft kosong berisi baris SIRUS saja. Aman diulang (baris lama ditimpa), slip Final dilewati, Generate ulang tidak menghapusnya. Di Rincian slip karyawan baris ini <b>terkunci</b> (tanda "dari SIRUS"); ubah di SIRUS lalu Ambil &amp; Terapkan ulang. Badge di Buku: <i>di slip gaji</i>, <i>slip pakai v1</i> (snapshot sudah versi baru — Terapkan ulang), <i>belum di slip gaji</i>.</li>
                    </ol>

                    <h3 class="mt-4">Pemindahan penggajian dokter ke SIAP (bertahap)</h3>
                    <p class="mt-1 text-sm">Tujuan akhir: struktur gaji dan slip dokter dikelola di SIAP, SIRUS hanya memasok transaksi. Dijalankan dalam tiga tahap yang masing-masing bisa hidup lama di produksi; saklarnya di tab <b>Struktur Gaji</b> (mode) dan <code>.env</code> SIRUS (<code>SIAP_STRUKTUR_GAJI_DARI_SIAP</code>, <code>SIAP_GAJI_DOKTER_NONAKTIF</code>) — keduanya harus seirama.</p>
                    <ol class="mt-1 text-sm">
                        <li><b>Tahap 0 (sekarang)</b>: SIRUS sumber semuanya. Di tab Struktur Gaji klik <b>Tarik dari SIRUS</b> supaya SIAP punya salinan struktur tiap dokter (dibutuhkan tahap 2); tombol ini juga menyamakan master dokter (dokter baru, status aktif/nonaktif). Bila API struktur SIRUS belum terpasang, salinan yang sama bisa dibuat lewat database oleh admin: berkas <code>database/sql/2026_11_07_salin_struktur_gaji_dokter_dari_sirus.sql</code> dijalankan sebagai SYSTEM di Oracle (aman diulang, struktur yang sudah disetel di SIAP tidak ditimpa).</li>
                        <li><b>Tahap 1 — struktur di SIAP</b>: set mode "Struktur gaji disetel di: SIAP" dan di SIRUS <code>SIAP_STRUKTUR_GAJI_DARI_SIAP=true</code>. Sejak itu struktur diubah lewat tombol <b>Ubah</b> di tab Struktur Gaji (form sama dengan panel Struktur Gaji Master Dokter SIRUS, termasuk aturan berjenjang) dan otomatis dikirim ke SIRUS; panel SIRUS jadi baca-saja. Gagal kirim ditandai per baris → <b>Kirim ulang yang gagal</b>. SIRUS masih menghitung slip; alur Ambil &amp; Terapkan tidak berubah.</li>
                        <li><b>Tahap 2 — uji paralel</b>: tiap bulan setelah Ambil, klik <b>Uji hitung SIAP</b> di Buku: mesin SIAP menghitung ulang semua dokter dari lampiran snapshot + struktur SIAP dan membandingkannya dengan angka SIRUS (juga tersedia di Rincian sebagai pembanding "Hitungan mesin SIAP"). Dokter bertanda "struktur dari snapshot" belum punya baris struktur — tarik dulu. Lanjut ke tahap 3 hanya bila cocok semua beberapa bulan berturut-turut.</li>
                        <li><b>Tahap 3 — slip dihitung SIAP</b>: set mode "Slip dihitung oleh: SIAP" dan di SIRUS <code>SIAP_GAJI_DOKTER_NONAKTIF=true</code> (Proses/Final/Buka Kunci/Hapus di SIRUS ditutup, arsip tetap terbaca). Di Buku tombol utama jadi <b>Proses Slip</b>: menarik transaksi mentah bulan jasa dari SIRUS, menghitung dengan struktur SIAP + baris manual, dan menyimpannya sebagai snapshot ber-sumber SIAP (badge <i>SIAP</i>) dengan aturan versi yang sama. Baris manual (kasbon, IDI insidental, bonus, rapel, koreksi) diisi di <b>Rincian</b> → "Baris manual"; tiap perubahan menghitung ulang (versi baru). Kunci dengan <b>Final</b> di baris Buku (Proses Slip berikutnya melewatinya; <b>Buka Kunci</b> bila perlu koreksi). Terapkan ke slip gaji, Lampiran, dan banding versi bekerja sama seperti sebelumnya.</li>
                    </ol>

                    <h3 class="mt-4">Langkah rutin tiap bulan</h3>
                    <ol class="mt-1 text-sm">
                        <li>Setelah SIRUS menutup gaji dokter bulan jasa N: buka Buku, pilih bulan jasa N, klik <b>Ambil dari SIRUS</b>.</li>
                        <li>Periksa pesan hasil (baru / berubah / sama) dan badge "dokter belum dipetakan" → lengkapi di tab Pemetaan bila ada dokter baru.</li>
                        <li>Bandingkan kolom "vs bulan lalu"; yang mencolok dibuka Rincian / Lampiran.</li>
                        <li>Menjelang pembayaran, Ambil ulang sekali lagi: bila ada "berubah", buka Rincian → bandingkan dengan versi sebelumnya untuk melihat komponen mana yang diubah SIRUS setelah kita proses.</li>
                        <li>Klik <b>Terapkan ke slip gaji</b> → cek Gaji Karyawan bulan kerja itu: slip dokter berisi baris "dari SIRUS". Kalau setelah itu Ambil ulang menghasilkan versi baru, badge berubah jadi <i>slip pakai v1</i> → Terapkan ulang sebelum slip difinalkan.</li>
                    </ol>

                    <h3 class="mt-4">Kasus yang sudah diantisipasi</h3>
                    <dl class="mt-1 space-y-2 text-sm">
                        <div><dt class="font-semibold">A. SIRUS mengubah angka setelah kita proses</dt>
                            <dd>Ambil ulang → dokter itu mendapat versi 2, versi 1 tetap. Saringan "Pernah berubah" menampilkan siapa saja; Rincian → "Bandingkan dengan Versi 1" menunjukkan komponen yang berubah dan selisihnya. Riwayat Ambil mencatat kapan dan oleh siapa.</dd></div>
                        <div><dt class="font-semibold">B. Dokter ada di SIRUS tapi bukan karyawan SIAP (dokter jaga, tamu, "TIDAK ADA", nama kosong)</dt>
                            <dd>Tetap ditarik dan tampil "Belum dipetakan". Biarkan kosong bila memang tidak digaji lewat SIAP; snapshot-nya tetap berguna sebagai pembanding total jasa.</dd></div>
                        <div><dt class="font-semibold">C. Nama dokter beda ejaan dengan master karyawan</dt>
                            <dd>Pemetaan otomatis gagal (mis. salah ketik di master). Petakan manual di tab Pemetaan: Petakan → ketik nama / ID → pilih. Pemetaan tersimpan sekali untuk seterusnya.</dd></div>
                        <div><dt class="font-semibold">D. Jasa dokter tidak dihitung di SIRUS (anomali)</dt>
                            <dd>Buku menampilkan apa adanya (jasa 0 atau dokter tidak muncul). Pembanding bulan lalu dan lampiran membantu menemukannya; koreksinya di SIRUS, lalu Ambil ulang.</dd></div>
                        <div><dt class="font-semibold">E. Slip belum Final di SIRUS</dt>
                            <dd>Status Draft / Final SIRUS ikut tersimpan (saringan "Final di SIRUS"). Snapshot Draft boleh ditarik untuk pembanding awal; angka pastinya adalah versi yang diambil setelah Final.</dd></div>
                        <div><dt class="font-semibold">F. Dokter belum punya parameter gaji / slip belum di-Generate</dt>
                            <dd>Terapkan tetap jalan: slip Draft kosong dibuatkan untuk dokter itu, isinya hanya baris SIRUS. Bila kelak parameter gajinya diisi, Generate melengkapi slip tanpa menghapus baris SIRUS. Hanya karyawan <b>nonaktif</b> yang dilewati.</dd></div>
                        <div><dt class="font-semibold">G. Slip karyawan sudah Final saat Terapkan</dt>
                            <dd>Dilewati dan disebut di pesan hasil. Buka kembali ke Draft di Gaji Karyawan, Terapkan ulang, lalu finalkan lagi.</dd></div>
                        <div><dt class="font-semibold">H. Dokter juga karyawan dengan gaji pokok SIAP</dt>
                            <dd>Baris "Gaji Pokok Dokter (SIRUS)" ditulis terpisah dari "Gaji Pokok" parameter SIAP. Bila keduanya terisi, periksa jangan sampai dobel — matikan salah satunya (skema N di SIRUS, atau kosongkan gaji pokok di parameter SIAP).</dd></div>
                        <div><dt class="font-semibold">I. Sambungan gagal</dt>
                            <dd>Pesan galat tampil (server SIRUS mati, password beda, token ditolak). Snapshot yang sudah ada tidak terpengaruh. Cek <code>SIAP_API_URL</code>, pastikan akun <i>siap-gaji</i> di SIRUS masih aktif dan password di <code>.env</code> SIAP sama.</dd></div>
                    </dl>
                    <p class="mt-3 text-sm">Detail teknis sambungan (setup .env, akun, token, kontrak data, uji sambungan) ada di {!! $tautan('panduan.sirus-api', 'Panduan Bridging SIRUS') !!}.</p>
                    <p class="mt-3 text-xs text-muted">Snapshot tersimpan terpisah dan masuk ke slip karyawan hanya lewat Terapkan ke slip gaji. Potongan khas SIAP (angsuran, BPJS SIAP, kasbon) tetap diproses di Gaji Karyawan seperti biasa pada slip yang sama.</p>
                </section>

                {{-- RUMUS --}}
                <section id="rumus" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Rumus hitungan</h2>
                    <table class="mt-3 text-sm ds-table">
                        <tbody>
                            <tr><td class="font-semibold">Tunjangan makan / transport</td><td>hari kerja × tarif per hari</td></tr>
                            <tr><td class="font-semibold">Total pendapatan</td><td>gaji pokok + tunjangan + makan + transport + jasa medik + lembur + bonus + rapel + koreksi + lain</td></tr>
                            <tr><td class="font-semibold">Jasa medik per pos</td><td>poin karyawan × (nilai dasar × % JM × % pegawai ÷ total poin pos)</td></tr>
                            <tr><td class="font-semibold">Upah lembur</td><td>jam ÷ {{ $lap::LEMBUR_JAM_PER_HARI }} ÷ {{ $lap::LEMBUR_HARI_ACUAN }} × (gaji pokok + jasa medik + makan + transport) × {{ $lap::LEMBUR_FAKTOR * 100 }}%</td></tr>
                            <tr><td class="font-semibold">BPJS Kesehatan</td><td>% JKN karyawan × dasar (master, atau UMK) × (1 + keluarga tambahan) — otomatis bila peserta JKN</td></tr>
                            <tr><td class="font-semibold">BPJS Ketenagakerjaan</td><td>% JP karyawan × upah bulan itu (pokok + tunjangan + jasa medik, minimal UMK) — otomatis bila peserta JP; ikut berubah saat JM berubah</td></tr>
                            <tr><td class="font-semibold">PPh 21</td><td>PPh 21 (%) × total pendapatan (dibulatkan ke bawah), kecuali ditimpa</td></tr>
                            <tr><td class="font-semibold">Total potongan</td><td>potongan rutin + angsuran + kasbon + lain + PPh 21</td></tr>
                            <tr><td class="font-semibold">Gaji diterima</td><td>total pendapatan − total potongan</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-sm">Gaji diterima bisa <b>minus</b> bila potongan (mis. angsuran / kasbon) lebih besar dari pendapatan bulan itu — periksa slip seperti ini sebelum difinalkan.</p>
                </section>

                {{-- FAQ --}}
                <section id="faq" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Tanya jawab</h2>
                    <div class="mt-3 space-y-2 text-sm">
                        @foreach ([
                            ['Karyawan tidak muncul saat Generate?', 'Parameter gajinya belum diatur, atau karyawannya non-aktif. Cek Master Karyawan dengan filter "Gaji belum diatur".'],
                            ['Karyawan keluar / berhenti?', 'Non-aktifkan di Master Karyawan. Karyawan non-aktif keluar dari hitungan: Generate gaji tidak membuatkan slip dan menghapus slip Draft-nya bulan itu (slip Final tetap disimpan); poinnya tidak ikut Pembagian JM, jadi jatah JM terbagi ke karyawan aktif. Proses gaji bulan terakhirnya DULU, baru non-aktifkan. Bila diaktifkan lagi, poin & slipnya ikut kembali saat Generate.'],
                            ['Hari kerja salah setelah Generate?', 'Ubah di kotak Hari Kerja pada Rincian (bagian 8), atau impor form Hari Kerja (bagian 6). Hari kerja per slip tidak diubah lagi oleh Generate / Susun ulang.'],
                            ['Gaji pokok naik bulan ini?', 'Ubah di Master Karyawan › Gaji lalu Generate ulang bulan itu, atau untuk banyak karyawan sekaligus pakai form Kenaikan Gaji (Form Bagian) — slip Draft langsung disusun ulang. Slip bulan-bulan sebelumnya tidak berubah.'],
                            ['JM di slip beda dengan menu Pembagian JM?', 'Ada perubahan nilai dasar / persen / poin setelah diterapkan. Di Pembagian JM akan muncul tanda "belum sesuai" — klik Terapkan ke Slip lagi. Slip Final harus dibuka ke Draft dulu.'],
                            ['Impor form ditolak?', 'Pesannya menyebut sebabnya: form untuk bulan lain (ganti bulan di toolbar), form bagian lain (impor di kartu yang benar), atau susunan kolom berubah (ekspor ulang form, jangan sisip/hapus kolom).'],
                            ['Bagian salah isi lalu sudah diimpor?', 'Perbaiki di Excel lalu impor lagi — angka di slip diganti, tidak ditambah. Atau ubah langsung di rincian slip.'],
                            ['Perlu mengurangi gaji (koreksi minus)?', 'Tambahkan baris Potongan, mis. "Potongan Lain" dengan keterangannya. Kotak nilai hanya menerima angka positif.'],
                            ['Slip sudah Final tapi ada yang salah?', 'Klik Buka (kuning) → perbaiki → Final lagi → kirim ulang WA bila sudah terlanjur dikirim (link lama mati saat slip dibuka).'],
                            ['Link slip di WhatsApp tidak bisa dibuka karyawan?', 'Bisa karena: sudah lewat ' . $gaji::MASA_LINK_SLIP_HARI . ' hari, slip sempat dibuka kembali ke Draft, atau link berisi alamat jaringan lokal. Kirim ulang dari SIAP yang dibuka lewat alamat publik.'],
                            ['Tombol WA abu-abu?', 'Slip belum Final, atau No. HP karyawan kosong / tidak valid. Arahkan kursor ke tombol untuk melihat alasannya.'],
                            ['Siapa yang bisa melihat gaji?', 'Hanya role Admin & Keuangan. Role lain tidak melihat menu, tombol Gaji di Master Karyawan, maupun halaman ini.'],
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
