{{-- Info singkat cara absen (lipat/buka) untuk karyawan — dipakai kiosk & absen HP.
     Props: mode = kiosk | hp. Angka diambil dari Pengaturan Absensi supaya selalu sesuai. --}}
@props(['mode' => 'kiosk'])

@php
    $jedaPulang  = \App\Support\PengaturanAbsensi::jedaMinPulangMenit();
    $batasPulang = \App\Support\PengaturanAbsensi::batasPulangJam();
@endphp

<details {{ $attributes->merge(['class' => 'group border shadow-sm bg-canvas border-hairline rounded-3xl']) }}>
    <summary class="flex items-center justify-between gap-3 px-5 py-4 font-semibold list-none cursor-pointer select-none rounded-3xl text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">
        <span class="inline-flex items-center gap-2"><x-absensi.ikon nama="info" class="w-5 h-5 text-fg-brand" /> Cara absen, dobel shift &amp; Poli</span>
        <svg class="w-4 h-4 transition-transform text-muted group-open:rotate-180 motion-reduce:transition-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
        </svg>
    </summary>

    <div class="px-5 pb-5 space-y-4 text-sm leading-relaxed text-body">
        <div>
            <p class="font-semibold text-ink">Cara pakai</p>
            <ol class="mt-1 ml-5 space-y-0.5 list-decimal">
                @if ($mode === 'hp')
                    <li>Ketik <b>ID Karyawan</b> → <b>Lanjut</b>. ID diingat di HP ini, besok tidak perlu ketik lagi.</li>
                    <li>Nyalakan <b>GPS</b> dan izinkan akses lokasi. Absen hanya bisa di dalam radius tempat kerja Anda.</li>
                    <li>Ambil <b>foto selfie</b> (wajah jelas), lalu <b>Kirim</b>.</li>
                @else
                    <li>Ketik atau scan <b>ID Karyawan</b>, lalu tekan <b>Enter</b>.</li>
                    <li>Sistem otomatis mencatat <b>masuk</b> atau <b>pulang</b> — tidak perlu memilih.</li>
                    <li>Bila hari ini tidak terjadwal (tukar dinas), pilih shift yang Anda jalani.</li>
                @endif
            </ol>
        </div>

        <div>
            <p class="font-semibold text-ink">Masuk</p>
            <ul class="mt-1 ml-5 space-y-0.5 list-disc">
                <li>Absen pertama hari itu = <b>masuk</b>, sesuai shift di jadwal dinas Anda.</li>
                <li>Lewat batas toleransi shift = <b>terlambat</b>.</li>
                <li><b>Lupa absen masuk?</b> Bila Anda absen setelah separuh jam shift, layar bertanya — pilih <i>"Saya lupa absen masuk, mau pulang"</i>, lalu lapor HRD untuk melengkapi jam masuk.</li>
                <li><b>Tukar jadwal</b> dengan rekan? Minta kepala ruang mengubah jadwal dinas <i>sebelum</i> absen, supaya tidak tercatat di shift yang salah.</li>
            </ul>
        </div>

        <div>
            <p class="font-semibold text-ink">Pulang</p>
            <ul class="mt-1 ml-5 space-y-0.5 list-disc">
                <li>Absen berikutnya = <b>pulang</b>. Absen ulang kurang dari {{ $jedaPulang }} menit setelah masuk tidak dihitung.</li>
                <li>Pulang sebelum jadwal (lewat toleransi shift) tercatat <b>pulang cepat</b>.</li>
                <li><b>Shift malam</b> (mis. 21:00–07:00): absen pulang besok paginya — tetap tercatat di tanggal mulai jaga.</li>
                <li>Lupa absen pulang lebih dari {{ $batasPulang }} jam setelah jadwal pulang → tercatat <b>tanpa absen pulang</b>, lapor HRD.</li>
            </ul>
        </div>

        <div class="p-3 border rounded-2xl border-amber-300 bg-amber-50">
            <p class="font-semibold text-amber-900">Dobel shift (menggantikan jadwal rekan, mis. Pagi + Sore)</p>
            <ol class="mt-1 ml-5 space-y-0.5 list-decimal text-amber-900">
                <li><b>Masuk</b>: absen sekali di awal shift pertama.</li>
                <li>Di antara dua shift <b>JANGAN absen pulang</b>.</li>
                <li><b>Pulang</b>: absen setelah shift kedua selesai.</li>
                <li>Lapor HRD / kepala ruang agar ditandai dobel shift — lembur dihitung dari jam shift kedua.</li>
            </ol>
            <p class="mt-1 text-xs text-amber-800">Terlanjur absen pulang setelah shift pertama? Tidak perlu absen lagi — cukup lapor HRD supaya jam pulangnya dikoreksi.</p>
        </div>

        <div class="p-3 border rounded-2xl border-sky-300 bg-sky-50">
            <p class="font-semibold text-sky-900">Poli (shift fleksibel — mendampingi dokter)</p>
            <ol class="mt-1 ml-5 space-y-0.5 list-decimal text-sky-900">
                <li><b>Masuk</b>: absen saat mulai mendampingi dokter — walau tidak terjadwal, tanpa pilih shift, tidak dihitung terlambat.</li>
                <li><b>Pulang</b>: absen setelah dokter selesai praktik — tidak dihitung pulang cepat.</li>
                <li>Praktik pagi &amp; sore di hari yang sama: <b>jangan absen pulang di antara sesi</b>, cukup absen pulang setelah sesi terakhir.</li>
            </ol>
        </div>
    </div>
</details>
