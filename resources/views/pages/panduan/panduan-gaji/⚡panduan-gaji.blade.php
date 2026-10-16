<?php

use Livewire\Component;

/**
 * PANDUAN GAJI — cara pakai modul gaji karyawan SIAP (statis, tanpa mutasi).
 * Daftar komponen, masa berlaku link WA, dst. dibaca langsung dari App\Support\GajiKaryawan
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
    $tautan = fn(string $route, string $label, array $parameter = []) => \Illuminate\Support\Facades\Route::has($route)
        ? '<a href="' . route($route, $parameter) . '" wire:navigate class="font-semibold text-brand-green underline hover:no-underline dark:text-brand-lime">' . e($label) . '</a>'
        : '<b>' . e($label) . '</b>';
    $bagian = [
        'alur' => 'Gambaran alur',
        'parameter' => '1. Parameter gaji (sekali)',
        'generate' => '2. Generate slip bulanan',
        'rincian' => '3. Rincian slip',
        'hari-kerja' => '4. Mengubah hari kerja',
        'pph' => '5. PPh 21',
        'final' => '6. Final & buka kembali',
        'cetak' => '7. Cetak & kirim WhatsApp',
        'rumus' => 'Rumus hitungan',
        'faq' => 'Tanya jawab',
    ];
@endphp

<div>
    <x-page-title title="Panduan Gaji" subtitle="Cara pakai: parameter gaji → generate slip → rincian & hari kerja → final → cetak / kirim WhatsApp" />

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
                        Buka modul: {!! $tautan('keuangan.gaji-karyawan', 'Gaji Karyawan') !!}
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
                            ['Parameter Gaji', 'Master Karyawan › tombol Gaji', 'sekali / bila berubah'],
                            ['Generate', 'Slip Draft semua karyawan', 'tiap bulan'],
                            ['Rincian', 'Hari kerja, jasa medik, lembur, kasbon …', 'tiap bulan'],
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
                            <tr><td>Hari Kerja Default</td><td>Pengali tarif makan &amp; transport untuk slip <b>baru</b> (mis. 25). Per slip bisa diubah (bagian 4).</td></tr>
                            <tr><td>Potongan rutin</td><td>Asuransi, iuran organisasi (PPNI/IBI), arisan, koperasi, infaq, BPJS — nominal tetap per bulan.</td></tr>
                            <tr><td>PPh 21 (%)</td><td>Persen dari total pendapatan slip (bagian 5). 0 = tanpa PPh.</td></tr>
                            <tr><td>Bank &amp; No. Rekening</td><td>Tercetak di slip.</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-sm">Mengubah parameter <b>tidak mengubah slip yang sudah ada</b> — berlaku untuk slip yang di-generate berikutnya (atau slip Draft yang di-generate ulang).
                        Jasa medik, upah lembur, bonus, kasbon &amp; angsuran <b>tidak</b> di parameter karena berubah tiap bulan — diisi di rincian slip.</p>
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
                        <b>hari kerja</b>, baris yang diisi petugas (jasa medik, lembur, kasbon …) dan nilai yang <i>ditimpa</i> <b>tetap</b>.
                    </div>
                    <p class="mt-3 text-sm">Ringkasan di bawah toolbar: jumlah slip &amp; draft, total pendapatan, potongan, diterima, dan jumlah slip final yang belum dikirim WA.</p>
                </section>

                {{-- RINCIAN --}}
                <section id="rincian" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>3. Rincian slip</h2>
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
                    <h2>4. Mengubah hari kerja (cuti / izin / tambahan)</h2>
                    <ol class="mt-3 text-sm">
                        <li>Buka <b>Rincian</b> slip karyawan.</li>
                        <li>Di kotak <b>Hari Kerja</b> (kanan atas) ketik jumlah harinya, mis. 25 dikurangi 2 hari cuti → <b>23</b>.</li>
                        <li><b>Klik di luar kotak</b> (atau tekan Tab) — tersimpan. Tunjangan Makan &amp; Transport langsung dihitung ulang (23 × tarif), begitu juga total &amp; gaji diterima.</li>
                    </ol>
                    <ul class="mt-3 text-sm">
                        <li>Di bawah kotak tampil acuan <b>"Absensi bulan ini: N hari hadir"</b> (Hadir/Terlambat dari modul absensi). Bila beda, tersedia tombol <b>Pakai absensi (N hari)</b>.</li>
                        <li>Hari kerja tersimpan <b>per slip</b> — tidak ikut berubah saat Generate / Susun ulang. Hari kerja default di parameter hanya untuk slip baru.</li>
                        <li>Batas 0–31 hari. Hanya bisa diubah selama slip <b>Draft</b>.</li>
                    </ul>
                </section>

                {{-- PPH --}}
                <section id="pph" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>5. PPh 21</h2>
                    <ul class="mt-3 text-sm">
                        <li>Dihitung otomatis: <b>PPh 21 (%) × total pendapatan</b>, dibulatkan ke bawah. Baris PPh muncul bila persennya &gt; 0.</li>
                        <li>Untuk memakai nominal sendiri (mis. hasil hitungan TER), <b>ubah nilai baris PPh 21</b> di tabel — baris jadi <i>ditimpa</i> dan tidak dihitung ulang lagi. Panah balik mengembalikan ke hitungan persen.</li>
                    </ul>
                </section>

                {{-- FINAL --}}
                <section id="final" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>6. Final &amp; buka kembali</h2>
                    <ul class="mt-3 text-sm">
                        <li><b>Final</b> mengunci slip: rincian &amp; hari kerja tidak bisa diubah, generate ulang melewatinya. Tombol Final ada di kolom Aksi, footer Rincian, atau <b>Final Semua</b> (semua Draft yang tampil).</li>
                        <li>Salah? Klik <b>Buka</b> (kuning) / <b>Buka kembali ke Draft</b> — slip bisa diubah lagi. Link WhatsApp yang sudah dikirim ikut <b>mati</b> sampai slip difinalkan lagi.</li>
                        <li>Kirim WhatsApp hanya untuk slip Final, supaya karyawan menerima angka yang sudah pasti.</li>
                    </ul>
                </section>

                {{-- CETAK & WA --}}
                <section id="cetak" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>7. Cetak &amp; kirim WhatsApp</h2>
                    <h3 class="mt-3">Cetak</h3>
                    <ul class="mt-1 text-sm">
                        <li>Ikon printer biru di baris = slip satu karyawan. <b>Cetak Semua</b> = semua slip yang tampil (sesuai filter), satu karyawan satu halaman.</li>
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
                        <li>Pakai filter Status <b>"Final, belum dikirim WA"</b> untuk mengirim satu per satu sampai habis.</li>
                        <li>Tanda WA ✓ = tombol sudah diklik, bukan bukti pesan terkirim — pastikan menekan Kirim di WhatsApp.</li>
                    </ul>
                    <div class="p-3 mt-3 text-sm border rounded-xl border-amber-300 bg-amber-50 text-amber-900">
                        ⚠️ Alamat link mengikuti alamat SIAP yang sedang dibuka. Bila SIAP dibuka lewat alamat jaringan lokal (mis. <code>192.168.x.x</code>),
                        link hanya bisa dibuka dari wifi kantor. Kirim slip saat SIAP dibuka lewat alamat publik (domain / HTTPS).
                    </div>
                </section>

                {{-- RUMUS --}}
                <section id="rumus" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Rumus hitungan</h2>
                    <table class="mt-3 text-sm ds-table">
                        <tbody>
                            <tr><td class="font-semibold">Tunjangan makan / transport</td><td>hari kerja × tarif per hari</td></tr>
                            <tr><td class="font-semibold">Total pendapatan</td><td>gaji pokok + tunjangan + makan + transport + jasa medik + lembur + bonus + rapel + koreksi + lain</td></tr>
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
                            ['Hari kerja salah setelah Generate?', 'Ubah di kotak Hari Kerja pada Rincian (bagian 4). Hari kerja per slip tidak diubah lagi oleh Generate / Susun ulang.'],
                            ['Gaji pokok naik bulan ini?', 'Ubah di Master Karyawan › Gaji, lalu Generate ulang bulan itu (slip harus masih Draft). Slip bulan-bulan sebelumnya tidak berubah.'],
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
