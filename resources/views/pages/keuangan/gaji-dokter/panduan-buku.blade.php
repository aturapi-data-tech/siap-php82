{{-- Panel Panduan tab Buku Gaji Dokter (SIRUS) — gaya biru-info, default tertutup (pola SIRUS; dipisah supaya file Buku ringkas). --}}
{{-- PANDUAN — gaya biru-info, default tertutup (pola SIRUS). --}}
<div x-data="{ buka: false }" class="overflow-hidden border border-blue-200 rounded-2xl bg-blue-50 dark:bg-blue-900/20 dark:border-blue-700">
    <button type="button" x-on:click="buka = !buka" :aria-expanded="buka"
        class="flex items-center justify-between w-full px-4 py-2.5 text-sm font-semibold text-blue-900 transition-colors hover:bg-blue-100 dark:text-blue-200 dark:hover:bg-blue-900/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-green dark:focus-visible:ring-brand-lime">
        <span class="flex items-center min-w-0 gap-2">
            <svg class="w-4 h-4 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span class="truncate">Panduan: snapshot, versi &amp; cara membaca slip</span>
        </span>
        <svg class="w-4 h-4 ml-2 text-blue-600 transition-transform shrink-0 motion-reduce:transition-none" x-bind:class="buka && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
    </button>
    <div x-show="buka" x-cloak class="px-4 pb-4 space-y-3 text-sm text-blue-900 dark:text-blue-100">
        <div>
            <div class="font-semibold">Dari mana angkanya</div>
            <p class="mt-0.5">Slip dihitung dan dikunci di <span class="font-semibold">SIRUS</span> (Slip Gaji Dokter). SIAP hanya <span class="font-semibold">menarik</span> hasilnya lewat API per bulan jasa dan menyimpannya sebagai snapshot &mdash; tidak ada yang bisa disunting di sini.</p>
        </div>
        <div>
            <div class="font-semibold">Versi snapshot</div>
            <p class="mt-0.5">Setiap <span class="font-semibold">Ambil dari SIRUS</span> membandingkan angka. Dokter yang sama tidak berubah; yang berbeda mendapat <span class="font-semibold">versi baru</span>, versi lama tetap tersimpan. Buka <span class="font-semibold">Rincian</span> lalu pilih pembanding (versi lain / bulan lalu) untuk melihat selisihnya per komponen.</p>
        </div>
        <div>
            <div class="font-semibold">Membaca slip</div>
            <table class="w-full text-sm text-left">
                <tbody class="align-top">
                    <tr><td class="py-0.5 pr-3 whitespace-nowrap font-semibold">Total Gaji</td><td class="py-0.5">jasa + gaji pokok (skema A aditif / G garanty fee / N tanpa pokok).</td></tr>
                    <tr><td class="py-0.5 pr-3 whitespace-nowrap font-semibold">Potongan</td><td class="py-0.5">Potongan RS (persen dari total gaji atau jasa) + PPh 21 (2,5% ber-NPWP, dinaikkan 20% bila tanpa NPWP) + potongan lain setelah pajak.</td></tr>
                    <tr><td class="py-0.5 pr-3 whitespace-nowrap font-semibold">Diterima</td><td class="py-0.5">total gaji &minus; potongan + tambahan (bonus, rapel).</td></tr>
                    <tr><td class="py-0.5 pr-3 whitespace-nowrap font-semibold">Lampiran</td><td class="py-0.5">rincian pasien/transaksi di balik tiap komponen jasa, berikut rekonsiliasi dengan slip.</td></tr>
                </tbody>
            </table>
        </div>
        <div>
            <div class="font-semibold">Terapkan ke slip gaji</div>
            <p class="mt-0.5">Menulis snapshot versi terbaru tiap dokter yang <span class="font-semibold">sudah dipetakan</span> ke slip <span class="font-semibold">Draft</span> Gaji Karyawan <span class="font-semibold">bulan kerja yang sama dengan bulan jasa</span> (slip bulan kerja Oktober dibayar awal November = "gaji November" di SIRUS): tiap komponen jasa (uang periksa, visite, operator, …), gaji pokok dokter, dan tambahan sebagai pendapatan; Potongan RS, PPh 21 SIRUS, dan potongan lain (IDI, BPJS, …) sebagai potongan, satu baris per komponen seperti di SIRUS &mdash; angkanya apa adanya dari SIRUS, dan PPh 21 SIAP tidak menghitung ulang bagian ini. Baris ini terkunci di Rincian slip karyawan (tanda <span class="font-semibold">dari SIRUS</span>); untuk mengubahnya, ubah di SIRUS lalu Ambil &amp; Terapkan ulang. Aman diulang: baris lama ditimpa. Slip Final dilewati. Dokter yang belum punya slip bulan itu (umumnya karena tidak punya parameter gaji, sehingga Generate melewatinya) dibuatkan slip Draft kosong yang hanya berisi baris SIRUS; Generate ulang tidak menghapus baris ini. Badge <span class="font-semibold">di slip gaji</span> / <span class="font-semibold">slip pakai v1</span> di daftar menunjukkan versi mana yang sudah ditulis.</p>
        </div>
        <div>
            <div class="font-semibold">Pemetaan dokter</div>
            <p class="mt-0.5">Dokter SIRUS perlu dipetakan ke karyawan SIAP (tab <span class="font-semibold">Pemetaan Dokter</span>) supaya slipnya bisa dihubungkan ke data kepegawaian. Yang belum dipetakan ditandai di daftar.</p>
        </div>
        @if (Route::has('panduan.gaji'))
            <a href="{{ route('panduan.gaji') }}" wire:navigate class="inline-flex items-center gap-1 font-semibold underline underline-offset-2 hover:text-blue-700 dark:hover:text-blue-300">Panduan lengkap penggajian &rarr;</a>
        @endif
    </div>
</div>
