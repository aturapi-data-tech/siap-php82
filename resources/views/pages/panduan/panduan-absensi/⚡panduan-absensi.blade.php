<?php

use Livewire\Component;
use Illuminate\Support\Facades\DB;

/**
 * PANDUAN ABSENSI — cara pakai modul absensi SIAP (statis, tanpa mutasi).
 * Angka aturan (toleransi, lembur, jendela pulang) dibaca langsung dari kode/master,
 * supaya panduan tidak basi saat aturan diubah.
 */
new class extends Component {
    public function shifts()
    {
        return DB::table('siapmst_shifts')->where('active_status', '1')->orderBy('jam_masuk')->get();
    }
};
?>

@php
    $A = \App\Support\Absensi::class;
    $tautan = fn(string $route, string $label) => \Illuminate\Support\Facades\Route::has($route)
        ? '<a href="' . route($route) . '" wire:navigate class="font-semibold text-brand-green underline hover:no-underline dark:text-brand-lime">' . e($label) . '</a>'
        : '<b>' . e($label) . '</b>';
    $bagian = [
        'alur' => 'Gambaran alur',
        'persiapan' => '1. Persiapan (sekali)',
        'jadwal' => '2. Jadwal Dinas bulanan',
        'kiosk' => '3. Absen harian di Kiosk',
        'harian' => '4. Rekap Harian',
        'bulanan' => '5. Rekap Bulanan & lembur',
        'kode' => 'Arti kode & warna',
        'faq' => 'Tanya jawab',
    ];
@endphp

<div>
    <x-page-title title="Panduan Absensi" subtitle="Cara pakai: jadwal dinas → kiosk → rekap harian → rekap bulanan & lembur" />

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
                            ['Persiapan', 'Shift, karyawan, akun kiosk', 'sekali'],
                            ['Jadwal Dinas', 'P / S / I / C per tanggal', 'tiap bulan'],
                            ['Kiosk', 'Scan ID → masuk / pulang', 'tiap hari'],
                            ['Rekap Harian', 'Cek, isi izin, Generate', 'tiap hari'],
                            ['Rekap Bulanan', 'Nilai lembur, % hadir', 'akhir bulan'],
                        ] as $i => [$judul, $isi, $kapan])
                            <div class="p-3 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                                <div class="text-xs font-semibold text-brand-green dark:text-brand-lime">{{ $i + 1 }} · {{ $kapan }}</div>
                                <div class="font-semibold text-ink dark:text-gray-100">{{ $judul }}</div>
                                <div class="text-xs text-muted">{{ $isi }}</div>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-sm">Kunci utamanya: <b>Jadwal Dinas</b>. Kiosk mengambil shift dari jadwal, Generate Libur/Alpa membaca jadwal, dan % kehadiran dihitung dari jadwal.</p>
                </section>

                {{-- PERSIAPAN --}}
                <section id="persiapan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>1. Persiapan (cukup sekali)</h2>

                    <h3 class="mt-4">a. Master Shift — {!! $tautan('master.shift', 'buka') !!}</h3>
                    <p class="mt-1 text-sm">Shift yang berlaku sekarang:</p>
                    <table class="mt-2 ds-table">
                        <thead><tr><th>Kode</th><th>Shift</th><th>Jam</th><th>Toleransi terlambat</th></tr></thead>
                        <tbody>
                            @foreach ($this->shifts() as $sh)
                                <tr>
                                    <td><span class="inline-block w-7 py-0.5 text-xs font-bold text-center rounded {{ \App\Support\Jadwal::WARNA[$sh->shift_kode] ?? \App\Support\Jadwal::WARNA_LAIN }}">{{ $sh->shift_kode }}</span></td>
                                    <td>{{ $sh->shift_name }}</td>
                                    <td class="font-mono">{{ $sh->jam_masuk }} – {{ $sh->jam_pulang }}</td>
                                    <td>{{ (int) $sh->toleransi_menit }} menit</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <ul class="mt-2 text-sm">
                        <li><b>Kode Jadwal</b> (P, S, …) adalah huruf yang dipakai di grid jadwal. I dan C tidak boleh dipakai (khusus Izin & Cuti).</li>
                        <li>Jam pulang lebih kecil dari jam masuk = shift <b>lintas hari</b> (pulang esok hari) — otomatis.</li>
                    </ul>

                    <h3 class="mt-5">b. Master Karyawan — {!! $tautan('master.karyawan', 'buka') !!}</h3>
                    <ul class="mt-1 text-sm">
                        <li><b>ID Karyawan</b> (mis. <code>KMP-001</code>) dipakai untuk absen di kiosk — bisa diketik atau dicetak sebagai barcode di kartu karyawan.</li>
                        <li><b>Shift Default (P/S)</b> = kolom P/S di jadwal; dipakai tombol <i>Isi Sen–Sab</i>.</li>
                        <li>Karyawan keluar: <b>non-aktifkan</b> (toggle), jangan dihapus — riwayat absensinya tetap tersimpan.</li>
                        <li>Centang <b>Tenaga Kesehatan</b> bila wajib STR/SIP — tombol STR/SIP muncul di daftar.</li>
                    </ul>

                    <h3 class="mt-5">c. Akun komputer kiosk</h3>
                    <ol class="mt-1 text-sm">
                        <li>Buat role <code>kiosk</code> di {!! $tautan('sistem.role-control', 'Role Control') !!}.</li>
                        <li>Buat user khusus (mis. <i>kiosk@…</i>) di {!! $tautan('sistem.user-control', 'User Control') !!}, beri role <code>kiosk</code>.</li>
                        <li>Login dengan akun itu di komputer dekat pintu, buka menu <b>Kiosk Absensi</b>, biarkan layar terbuka.</li>
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
                    <h2>3. Absen harian di Kiosk — {!! $tautan('absensi.kiosk', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Karyawan cukup <b>ketik / scan ID lalu Enter</b> — sistem menentukan sendiri masuk atau pulang.</p>
                    <table class="mt-3 ds-table">
                        <thead><tr><th>Keadaan</th><th>Yang terjadi</th></tr></thead>
                        <tbody>
                            <tr><td>Terjadwal P/S hari ini, belum absen</td><td>Langsung tercatat <b>MASUK</b> dengan shift jadwal ("sesuai jadwal")</td></tr>
                            <tr><td>Sudah masuk, belum pulang</td><td>Tercatat <b>PULANG</b> (jam saat itu)</td></tr>
                            <tr><td>Scan ulang &lt; {{ $A::JEDA_MIN_PULANG_MENIT }} menit setelah masuk</td><td>Hanya info — dianggap scan dobel, <i>tidak</i> dicatat pulang</td></tr>
                            <tr><td>Tidak terjadwal (libur)</td><td>Diminta <b>pilih shift</b> (untuk tukar dinas) dengan catatan "tidak terjadwal"</td></tr>
                            <tr><td>Terjadwal I / C, atau sudah diisi Izin/Sakit/dll.</td><td>Hanya info — hubungi HRD bila ternyata masuk</td></tr>
                            <tr><td>Masuk & pulang sudah lengkap</td><td>Info "absen hari ini sudah lengkap"</td></tr>
                        </tbody>
                    </table>
                    <h3 class="mt-5">Aturan hitungan</h3>
                    <ul class="mt-1 text-sm">
                        <li><b>Terlambat</b>: masuk lewat jam masuk shift + toleransi → status <i>Terlambat</i>, menit terlambat dihitung dari jam masuk shift.</li>
                        <li><b>Lembur</b>: pulang ≥ <b>{{ $A::MIN_LEMBUR_MENIT }} menit</b> lewat jadwal pulang → lembur dicatat dengan status <i>Menunggu</i> (dinilai di Rekap Bulanan). Kurang dari itu tidak dihitung.</li>
                        <li><b>Pulang lewat tengah malam</b> (lembur panjang) tetap tercatat sebagai pulang shift tersebut, selama masih dalam {{ $A::BATAS_PULANG_JAM }} jam setelah jadwal pulang.</li>
                        <li><b>Lupa absen pulang</b> lebih dari {{ $A::BATAS_PULANG_JAM }} jam → scan berikutnya dianggap absen masuk hari baru. Jam pulang yang terlewat diisi HRD lewat Koreksi.</li>
                    </ul>
                </section>

                {{-- HARIAN --}}
                <section id="harian" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>4. Rekap Harian — {!! $tautan('absensi.rekap-harian', 'buka') !!}</h2>
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
                    <p class="mt-3 text-sm">Lembur di sini hanya <b>ditampilkan</b> (dengan statusnya) — penilaiannya di Rekap Bulanan.</p>
                </section>

                {{-- BULANAN --}}
                <section id="bulanan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>5. Rekap Bulanan & penilaian lembur — {!! $tautan('absensi.rekap-bulanan', 'buka') !!}</h2>
                    <ul class="mt-2 text-sm">
                        <li><b>Terjadwal</b> = hari berjadwal P/S s/d kemarin (bulan berjalan) atau sebulan penuh (bulan lalu).</li>
                        <li><b>% Kehadiran</b> = hadir (Hadir/Terlambat) di hari terjadwal ÷ hari terjadwal.
                            <span class="px-1 rounded bg-emerald-100 text-emerald-800">≥ 95%</span>
                            <span class="px-1 rounded bg-amber-100 text-amber-800">≥ 85%</span>
                            <span class="px-1 text-red-800 bg-red-100 rounded">&lt; 85%</span>.
                            Izin/cuti yang sudah dijadwalkan (I/C) tidak dihitung hari kerja; izin/sakit/alpa mendadak menurunkan persentase.</li>
                        <li><b>Lembur Dihitung</b> = hanya lembur yang disetujui; yang belum dinilai tampil kuning "menunggu".</li>
                    </ul>
                    <h3 class="mt-4">Menilai lembur</h3>
                    <ol class="mt-1 text-sm">
                        <li>Klik <b>nama karyawan</b> → rincian kehadiran sebulan per tanggal (jadwal vs kenyataan).</li>
                        <li>Di hari yang ada lembur, klik <b>✓ Hitung</b> atau <b>✗ Tidak</b>. Bisa diubah lagi lewat "ubah".</li>
                        <li>Atau <b>✓ Hitung semua yang menunggu</b> sekaligus.</li>
                    </ol>
                    <p class="mt-2 text-sm">Hari <span class="font-semibold text-red-600">terjadwal tapi tanpa catatan</span> ditandai merah di rincian — lengkapi lewat Rekap Harian.</p>
                </section>

                {{-- KODE --}}
                <section id="kode" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Arti kode & warna</h2>
                    <div class="grid grid-cols-1 gap-6 mt-3 md:grid-cols-2">
                        <div>
                            <h3>Status absensi</h3>
                            <div class="flex flex-wrap gap-2 mt-2">
                                @foreach ($A::STATUS as $k => $n)
                                    <x-badge :variant="$A::BADGE[$k]">{{ $k }} = {{ $n }}</x-badge>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <h3>Kode jadwal</h3>
                            <div class="flex flex-wrap gap-2 mt-2 text-sm">
                                @foreach ($this->shifts() as $sh)
                                    <span class="px-2 py-0.5 font-bold rounded {{ \App\Support\Jadwal::WARNA[$sh->shift_kode] ?? \App\Support\Jadwal::WARNA_LAIN }}">{{ $sh->shift_kode }}</span><span>{{ $sh->shift_name }}</span>
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
                            ['Lembur tidak muncul?', 'Lembur hanya tercatat bila pulang ≥ ' . $A::MIN_LEMBUR_MENIT . ' menit lewat jadwal pulang shift.'],
                            ['Karyawan sudah keluar?', 'Non-aktifkan di Master Karyawan — tidak muncul lagi di kiosk, jadwal, dan rekap, tetapi riwayatnya aman.'],
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
