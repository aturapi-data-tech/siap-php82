<?php

return [
    /*
    | Mode penjaga route grup 'menu' yang BELUM didefinisikan di berkas program (App\Http\Middleware\CekAksesMenu):
    |  'log'   = izinkan seperti sebelumnya, tapi catat ke storage/logs/akses-halaman-*.log (masa transisi)
    |  'tolak' = balas 403 (deny-by-default)
    | Halaman yang SUDAH didefinisikan selalu dijaga centang "Lihat"-nya (403) di kedua mode — sama seperti sebelumnya.
    */
    'mode' => env('AKSES_HALAMAN_MODE', 'log'),
];
