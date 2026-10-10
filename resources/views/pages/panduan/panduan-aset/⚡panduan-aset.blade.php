<?php

use Livewire\Component;

/**
 * PANDUAN ASET — cara pakai modul aset (inventaris, kalibrasi, pemeliharaan, perbaikan) +
 * kaitannya dengan bukti akreditasi MFK 7. Statis; angka aturan dibaca dari App\Support\*.
 */
new class extends Component {
};
?>

@php
    $K = \App\Support\Kalibrasi::class;
    $PM = \App\Support\Pemeliharaan::class;
    $PB = \App\Support\Perbaikan::class;
    $tautan = fn(string $route, string $label) => \Illuminate\Support\Facades\Route::has($route)
        ? '<a href="' . route($route) . '" wire:navigate class="font-semibold text-brand-green underline hover:no-underline dark:text-brand-lime">' . e($label) . '</a>'
        : '<b>' . e($label) . '</b>';
    $bagian = [
        'alur' => 'Gambaran alur',
        'ruang' => '1. Master Ruang',
        'inventaris' => '2. Inventaris Alat',
        'kalibrasi' => '3. Kalibrasi',
        'jadwal' => '4. Jadwal Kalibrasi',
        'pemeliharaan' => '5. Pemeliharaan (checklist)',
        'perbaikan' => '6. Perbaikan alat',
        'akreditasi' => 'Bukti akreditasi MFK 7',
        'faq' => 'Tanya jawab',
    ];
@endphp

<div>
    <x-page-title title="Panduan Aset" subtitle="Cara pakai: inventaris → kalibrasi → pemeliharaan → perbaikan (bukti MFK 7)" />

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
                        Panduan absensi: {!! $tautan('panduan.absensi', 'Panduan Absensi') !!}
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
                            ['Master Ruang', 'Lokasi alat', 'sekali'],
                            ['Inventaris', 'Data alat + kondisi', 'saat ada alat baru'],
                            ['Kalibrasi', 'Catat sertifikat', 'min. 1x setahun'],
                            ['Pemeliharaan', 'Checklist B / P / R', 'harian / mingguan'],
                            ['Perbaikan', 'Lapor → selesai', 'saat rusak'],
                        ] as $i => [$judul, $isi, $kapan])
                            <div class="p-3 border rounded-xl border-hairline bg-surface-soft dark:bg-gray-800 dark:border-gray-700">
                                <div class="text-xs font-semibold text-brand-green dark:text-brand-lime">{{ $i + 1 }} · {{ $kapan }}</div>
                                <div class="font-semibold text-ink dark:text-gray-100">{{ $judul }}</div>
                                <div class="text-xs text-muted">{{ $isi }}</div>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-sm">Semua saling terhubung: temuan <b>P/R</b> di checklist bisa langsung dilaporkan sebagai <b>perbaikan</b>, dan kondisi alat di inventaris ikut berubah otomatis.</p>
                </section>

                {{-- RUANG --}}
                <section id="ruang" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>1. Master Ruang — {!! $tautan('master.ruang', 'buka') !!}</h2>
                    <ul class="mt-2 text-sm">
                        <li>Kode ruang singkat (mis. <code>IGD</code>, <code>APT</code>) — dipakai sebagai awalan kode alat.</li>
                        <li>Ruang yang masih punya alat tidak bisa dihapus — non-aktifkan saja.</li>
                    </ul>
                </section>

                {{-- INVENTARIS --}}
                <section id="inventaris" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>2. Inventaris Alat — {!! $tautan('master.alat', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Kolom mengikuti formulir <b>Daftar Inventaris</b> RS.</p>
                    <ol class="mt-3 text-sm">
                        <li>Klik <b>+ Tambah Alat</b>.</li>
                        <li><b>Kode Alat</b> dengan pola <code>RUANG.JENIS.NO/TAHUN</code>, mis. <code>IGD.SP.01/2023</code> (Syringe Pump IGD no. 1, pengadaan 2023).</li>
                        <li>Isi nama, jenis (Medis / Non Medis), merk, type/model, no. seri, ruang, tahun pengadaan, jumlah, kondisi.</li>
                        <li><b>Wajib kalibrasi</b> untuk alat yang punya parameter ukur/keluaran (tensimeter, timbangan, monitor, pompa infus, dll.). Interval default {{ $K::INTERVAL_DEFAULT }} bulan.</li>
                        <li><b>Pemeliharaan rutin</b>: Harian / Mingguan / Tidak rutin — menentukan alat masuk checklist mana.</li>
                    </ol>
                    <div class="flex flex-wrap gap-2 mt-3 text-sm">
                        <span>Kondisi:</span>
                        @foreach ($K::KONDISI as $k => $n)
                            <x-badge :variant="$K::KONDISI_BADGE[$k]">{{ $k }} = {{ $n }}</x-badge>
                        @endforeach
                    </div>
                    <p class="mt-3 text-sm">Alat yang sudah punya riwayat kalibrasi/perbaikan tidak bisa dihapus (bukti akreditasi) — <b>non-aktifkan</b> bila sudah tidak dipakai.</p>
                </section>

                {{-- KALIBRASI --}}
                <section id="kalibrasi" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>3. Kalibrasi</h2>
                    <p class="mt-2 text-sm">Permenkes 54/2015 Pasal 8: kalibrasi alat kesehatan <b>paling sedikit 1 kali setahun</b>, oleh BPFK / institusi penguji terakreditasi KAN.</p>
                    <ol class="mt-3 text-sm">
                        <li>Di Inventaris Alat, klik tombol <b>Kalibrasi</b> (atau badge status) pada alat.</li>
                        <li>Klik <b>+ Catat Kalibrasi</b> setelah kalibrasi dilaksanakan, isi sesuai <b>Sertifikat Kalibrasi</b>: tanggal, lembaga pelaksana, nomor sertifikat, hasil.</li>
                        <li><b>Berlaku s/d</b> terisi otomatis (tanggal + interval) — sesuaikan dengan "disarankan kalibrasi ulang" di sertifikat.</li>
                        <li>Hasil <b>Tidak Laik Pakai</b> → alat jangan dipakai; laporkan perbaikan, lalu kalibrasi ulang.</li>
                    </ol>
                    <div class="flex flex-wrap gap-2 mt-3 text-sm">
                        <span>Status:</span>
                        @foreach ($K::STATUS as $kode => [$nama, $var])
                            <x-badge :variant="$var">{{ $nama }}</x-badge>
                        @endforeach
                    </div>
                    <p class="mt-2 text-sm"><i>Segera kalibrasi</i> muncul {{ $K::BATAS_SEGERA_HARI }} hari sebelum masa berlaku habis.</p>
                </section>

                {{-- JADWAL KALIBRASI --}}
                <section id="jadwal" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>4. Jadwal Kalibrasi — {!! $tautan('aset.jadwal-kalibrasi', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Program kalibrasi tahunan (SPO Kalibrasi langkah 2: "menyusun program dan jadwal kalibrasi"). Matriks alat × Januari–Desember:</p>
                    <ul class="mt-2 text-sm">
                        <li><span class="px-1 font-bold rounded bg-sky-100 text-sky-800">J</span> jatuh tempo kalibrasi (rencana)</li>
                        <li><span class="px-1 font-bold text-red-800 bg-red-100 rounded">J</span> jatuh tempo terlewat / belum pernah dikalibrasi</li>
                        <li><span class="px-1 font-bold text-gray-500 bg-gray-100 rounded">J</span> sudah dikalibrasi ulang</li>
                        <li><b class="text-emerald-700">✓</b> dikalibrasi, laik pakai · <b class="text-red-700">✗</b> dikalibrasi, tidak laik</li>
                    </ul>
                    <p class="mt-2 text-sm">Klik nama alat untuk mencatat kalibrasi. <b>Cetak PDF</b> → lampiran bukti akreditasi.</p>
                </section>

                {{-- PEMELIHARAAN --}}
                <section id="pemeliharaan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>5. Pemeliharaan (entry & riwayat) — {!! $tautan('aset.pemeliharaan-alat', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Setiap pemeriksaan alat dicatat lalu tersimpan sebagai riwayat. Alat <b>harian</b> (mis. alat IGD) dicek tiap hari, alat <b>mingguan</b> sekali per minggu. Frekuensi tiap alat diatur di Inventaris Alat.</p>
                    <ol class="mt-3 text-sm">
                        <li>Klik <b>+ Entry Pemeliharaan</b> → pilih <b>ruang</b> dan <b>tanggal</b> (default hari ini). Semua alat rutin di ruang itu tampil, harian lebih dulu.</li>
                        <li>Isi hasil tiap alat: <b>B</b>, <b>P</b>, atau <b>R</b>. Alat yang tidak dicek biarkan kosong. <b>✓ Semua yang kosong = Baik</b> mempercepat bila semua alat baik.</li>
                        <li>Hasil <b>P / R</b> wajib diberi keterangan / tindakan. Simpan.</li>
                        <li>Alat yang sudah dicek di tanggal itu (mingguan: di minggu itu) ditandai "Sudah dicek" — mengubahnya memperbarui catatan yang sama, tidak dobel.</li>
                        <li>Daftar riwayat bisa difilter ruang, frekuensi, hasil, dan bulan. Baris P/R punya tombol <b>Lapor perbaikan</b>. Edit / Hapus per catatan.</li>
                        <li>Pantau kotak <b>"hari ini / minggu ini: x / y alat sudah dicek"</b> di atas daftar.</li>
                        <li><b>Cetak Checklist</b> (pilih frekuensi Harian / Mingguan dulu) → format Checklist Perawatan Alat per ruang atau semua ruang, untuk ditandatangani petugas & kepala ruangan.</li>
                    </ol>
                    <div class="flex flex-wrap gap-2 mt-3 text-sm">
                        @foreach ($PM::HASIL as $k => $n)
                            <span class="px-2 py-0.5 rounded font-bold {{ ['B' => 'bg-emerald-100 text-emerald-800', 'P' => 'bg-amber-100 text-amber-800', 'R' => 'bg-red-100 text-red-800'][$k] }}">{{ $k }}</span><span>{{ $n }}</span>
                        @endforeach
                    </div>
                    <p class="mt-2 text-sm">Tanggal di masa depan tidak bisa diisi. Catatan yang sudah dilaporkan ke perbaikan tidak bisa dihapus.</p>
                </section>

                {{-- PERBAIKAN --}}
                <section id="perbaikan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>6. Perbaikan alat — {!! $tautan('aset.perbaikan-alat', 'buka') !!}</h2>
                    <p class="mt-2 text-sm">Format "Laporan Perbaikan Alat Medis" + alur Pedoman Pengelolaan Alat Medis.</p>
                    <ol class="mt-3 text-sm">
                        <li><b>+ Lapor Kerusakan</b> → pilih ruang, alat, tulis kerusakan (kapan, gejala, penyebab), kondisi RR/RB. Nomor otomatis <code>PB/yyyy/mm/nnnn</code>.</li>
                        <li>Teknisi membuka laporan → status <b>Ditangani teknisi</b>: isi waktu respon (tombol <i>Kini</i>) & nama teknisi.
                            Target respon <b>±{{ $PB::TARGET_RESPON_MENIT }} menit</b> — lebih dari itu ditandai merah.</li>
                        <li>Tidak bisa ditangani internal → <b>Di vendor</b>: isi vendor & no. service report.</li>
                        <li>Selesai → <b>Selesai dikerjakan</b> (analisa, suku cadang, biaya, tgl selesai) → <b>Diserahkan kembali</b> (tgl & penerima).</li>
                        <li>Tidak bisa diperbaiki → <b>Tidak dapat diperbaiki</b> + alasan & rekomendasi (mis. usul penggantian).</li>
                        <li><b>Cetak Laporan</b> untuk ditandatangani user, teknisi, penerima.</li>
                    </ol>
                    <div class="flex flex-wrap gap-2 mt-3 text-sm">
                        @foreach ($PB::STATUS as $kode => [$nama, $var])
                            <x-badge :variant="$var">{{ $nama }}</x-badge>
                        @endforeach
                    </div>
                    <ul class="mt-3 text-sm">
                        <li>Kondisi alat di inventaris otomatis: lapor → RR/RB · diserahkan kembali → <b>B</b> · tidak dapat diperbaiki → <b>RB</b>.</li>
                        <li>Alat wajib kalibrasi yang selesai diperbaiki → lakukan <b>uji fungsi / kalibrasi ulang</b>.</li>
                        <li>Alat yang masih dalam perbaikan tidak bisa dilaporkan dobel — laporan aktifnya yang terbuka.</li>
                        <li>Hapus hanya untuk laporan berstatus <i>Dilaporkan</i> (salah input).</li>
                    </ul>
                </section>

                {{-- AKREDITASI --}}
                <section id="akreditasi" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Bukti akreditasi MFK 7 (Pengelolaan Peralatan Medis)</h2>
                    <table class="mt-3 ds-table">
                        <thead><tr><th>Elemen</th><th>Bukti yang diminta</th><th>Ambil dari</th></tr></thead>
                        <tbody>
                            <tr><td>EP a</td><td>Inventarisasi</td><td>Inventaris Alat (daftar per ruang)</td></tr>
                            <tr><td>EP a</td><td>Pemeliharaan preventif</td><td>Pemeliharaan → riwayat entry + Cetak Checklist per ruang per bulan</td></tr>
                            <tr><td>EP a</td><td>Kalibrasi</td><td>Jadwal Kalibrasi → Cetak PDF + sertifikat dari lembaga</td></tr>
                            <tr><td>EP d</td><td>Perbaikan oleh pihak kompeten</td><td>Perbaikan Alat → Cetak Laporan (teknisi / vendor + service report)</td></tr>
                        </tbody>
                    </table>
                </section>

                {{-- FAQ --}}
                <section id="faq" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Tanya jawab</h2>
                    <div class="mt-3 space-y-2 text-sm">
                        @foreach ([
                            ['Alat pindah ruang?', 'Edit alat di Inventaris → ganti Ruang. Riwayat kalibrasi/perbaikan tetap menempel pada alat.'],
                            ['Sertifikat menyarankan kalibrasi ulang bukan 12 bulan?', 'Ubah "Berlaku s/d" sesuai sertifikat saat mencatat, atau ubah interval kalibrasi alat di Inventaris.'],
                            ['Checklist salah klik?', 'Klik lagi sel yang sama sampai kembali ke hasil yang benar (B → P → R → kosong).'],
                            ['Alat rusak ditemukan bukan dari checklist?', 'Langsung + Lapor Kerusakan di menu Perbaikan Alat.'],
                            ['Alat sudah tidak dipakai / afkir?', 'Non-aktifkan di Inventaris (status aktif) — data & riwayatnya tetap ada untuk audit.'],
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
