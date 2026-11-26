<?php

namespace App\Http\Middleware;

use App\Support\AksesHalaman;
use App\Support\SinkronAksesOtomatis;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga halaman grup route 'menu': user hanya boleh membuka route yang centang "Lihat"-nya dimiliki salah satu
 * role-nya (keputusan App\Support\AksesHalaman) — halaman di menu selalu 403 bagi yang tak berhak.
 *
 * Route di grup ini yang TIDAK didefinisikan di berkas program (app/Akses/Program/<Kelompok>/) mengikuti
 * config akses.mode: 'log' = diizinkan seperti sebelumnya tapi dicatat ke log channel 'akses' (masa transisi);
 * 'tolak' = 403 (deny-by-default). Halaman baru wajib didaftarkan di berkas program.
 *
 * Didaftarkan juga sebagai persistent middleware Livewire (AppServiceProvider) — ikut memeriksa aksi Livewire,
 * jadi hak yang dicabut di Role Control langsung berlaku untuk tab yang masih terbuka.
 */
class CekAksesMenu
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sesudah deploy yang mengubah definisi hak akses, permission baru dibuat otomatis (sekali, request pertama).
        SinkronAksesOtomatis::pastikan();

        $route = (string) $request->route()?->getName();
        if (AksesHalaman::boleh($request->user(), $route)) {
            return $next($request);
        }

        if ($route === '' || !AksesHalaman::dikenal($route)) {
            Log::channel('akses')->warning('Route grup menu tanpa definisi hak akses — daftarkan di app/Akses/Program', [
                'mode' => config('akses.mode'),
                'route' => $route,
                'user_id' => $request->user()?->id,
                'url' => $request->fullUrl(),
            ]);
            if (config('akses.mode') !== 'tolak') {
                return $next($request);
            }
        }

        abort(403, 'Anda tidak memiliki akses ke halaman ini.');
    }
}
