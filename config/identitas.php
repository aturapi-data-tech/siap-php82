<?php

/**
 * Identitas instansi untuk kop cetakan (<x-logo.identitas>).
 * Ganti lewat .env — tanpa menyentuh kode. Baris yang kosong tidak dicetak.
 * Logo = path relatif terhadap public/ (DomPDF membaca dari disk).
 */
return [
    'nama'    => env('IDENTITAS_NAMA', 'Rumah Sakit Islam Madinah'),
    'alamat'  => env('IDENTITAS_ALAMAT', 'Jl. Jati Wayang, Lk. 2, Ngunut, Kec. Ngunut, Kabupaten Tulungagung, Jawa Timur 66292'),
    'telp'    => env('IDENTITAS_TELP', '(0355) 396055'),
    'fax'     => env('IDENTITAS_FAX', '(0355) 396824'),
    'website' => env('IDENTITAS_WEBSITE', 'www.rsimadinah.com'),
    'logo'    => env('IDENTITAS_LOGO', 'images/Logo Persegi.png'),
    'kota'    => env('IDENTITAS_KOTA', 'Tulungagung'),
];
