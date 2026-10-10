<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivity
{
    /**
     * Update users.last_seen_at + last_seen_route untuk user yang sedang login.
     * Throttle via cache 1-menit per user supaya tidak nge-write tiap request
     * (di sistem dengan banyak request seperti Livewire poll, write tiap request
     * akan jadi storm ke Oracle).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            // Enforce user NONAKTIF: kalau active_status jadi '0' saat user sedang login,
            // tendang keluar di request berikutnya. Fail-safe: kolom belum ada / null → aktif.
            if ((string) (Auth::user()->active_status ?? '1') === '0') {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors([
                    'email' => 'Akun Anda dinonaktifkan. Hubungi administrator.',
                ]);
            }

            $userId = Auth::id();
            $cacheKey = "user_activity:{$userId}";
            if (!Cache::has($cacheKey)) {
                $routeName = $this->resolveCurrentRouteName($request);
                DB::table('users')->where('id', $userId)->update([
                    'last_seen_at' => Carbon::now(),
                    'last_seen_route' => $routeName,
                ]);
                // Livewire mengirim beberapa request paralel; saat cache kedaluwarsa,
                // dua request bisa lolos Cache::has() bersamaan lalu sama-sama MERGE
                // ke tabel CACHE → yang kalah kena ORA-00001 (CACHE_KEY_PK). Key-nya
                // sudah ditulis request pemenang, jadi aman diabaikan.
                try {
                    Cache::put($cacheKey, 1, now()->addMinute());
                } catch (UniqueConstraintViolationException) {
                    // no-op
                }
            }
        }
        return $next($request);
    }

    /**
     * Resolve route name yang sedang dilihat user.
     * Untuk request Livewire endpoint (path mulai `livewire/`), route name-nya
     * adalah route Livewire internal (mis. `default-livewire.update`) — tidak
     * berguna untuk monitoring. Ambil dari Referer header (URL halaman yang user
     * lihat di browser) dan match ke route name aplikasi.
     */
    private function resolveCurrentRouteName(Request $request): ?string
    {
        $isLivewireEndpoint = str_starts_with($request->path(), 'livewire/');

        // Non-Livewire request → langsung pakai route name request ini.
        if (!$isLivewireEndpoint) {
            return $request->route()?->getName();
        }

        // Livewire endpoint → coba match Referer URL ke route name.
        $referer = $request->headers->get('referer');
        if (empty($referer)) {
            return null;
        }

        try {
            $matched = Route::getRoutes()->match(Request::create($referer, 'GET'));
            return $matched->getName();
        } catch (\Throwable) {
            return null;
        }
    }
}
