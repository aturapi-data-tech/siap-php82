{{-- Tombol Kirim WA slip gaji — dipakai daftar slip & modal rincian (keduanya punya method tandaiWa()).
     Butuh $header: gaji_id, gaji_status, emp_name, emp_phone, periode, total_pendapatan, total_potongan, gaji_diterima.
     wa.me dibuka LANGSUNG dari klik (bukan setelah round-trip Livewire) supaya tidak diblokir popup blocker;
     tandaiWa() menyusul untuk mencatat waktu kirim. --}}
@php
    $tautanWa = \App\Support\GajiKaryawan::tautanWa($header);
    $alasanNonaktif = match (true) {
        $header->gaji_status !== \App\Support\GajiKaryawan::STATUS_FINAL => 'Finalkan slip dulu sebelum dikirim',
        \App\Support\GajiKaryawan::nomorWa($header->emp_phone ?? null) === null => 'No. HP karyawan belum diisi / tidak valid (Master Karyawan)',
        default => null,
    };
@endphp

@if ($tautanWa)
    <x-icon-button color="green" type="button" class="!p-2.5 shrink-0"
        title="Kirim slip ke WhatsApp {{ $header->emp_phone }}{{ $header->wa_dikirim_at ?? null ? ' (sudah pernah dikirim ' . \App\Support\Format::tanggal($header->wa_dikirim_at) . ')' : '' }}"
        x-on:click="window.open(@js($tautanWa), '_blank'); $wire.tandaiWa({{ $header->gaji_id }})">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.47-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.69.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35zM12.05 21.5h-.01a9.4 9.4 0 01-4.8-1.31l-.34-.2-3.57.93.95-3.48-.22-.36a9.43 9.43 0 01-1.45-5.03c0-5.2 4.24-9.44 9.45-9.44 2.52 0 4.89.99 6.67 2.77a9.37 9.37 0 012.76 6.68c0 5.2-4.24 9.44-9.44 9.44zm8.03-17.47A11.3 11.3 0 0012.05.7C5.8.7.7 5.79.7 12.05c0 2 .52 3.95 1.52 5.67L.6 23.6l6.02-1.58a11.3 11.3 0 005.42 1.38h.01c6.26 0 11.35-5.09 11.35-11.35 0-3.03-1.18-5.88-3.33-8.02z"/>
        </svg>
    </x-icon-button>
@else
    <x-icon-button color="gray" type="button" class="!p-2.5 shrink-0 opacity-50 cursor-not-allowed" disabled title="{{ $alasanNonaktif }}">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12.05 21.5h-.01a9.4 9.4 0 01-4.8-1.31l-.34-.2-3.57.93.95-3.48-.22-.36a9.43 9.43 0 01-1.45-5.03c0-5.2 4.24-9.44 9.45-9.44 2.52 0 4.89.99 6.67 2.77a9.37 9.37 0 012.76 6.68c0 5.2-4.24 9.44-9.44 9.44zm8.03-17.47A11.3 11.3 0 0012.05.7C5.8.7.7 5.79.7 12.05c0 2 .52 3.95 1.52 5.67L.6 23.6l6.02-1.58a11.3 11.3 0 005.42 1.38h.01c6.26 0 11.35-5.09 11.35-11.35 0-3.03-1.18-5.88-3.33-8.02z"/>
        </svg>
    </x-icon-button>
@endif
