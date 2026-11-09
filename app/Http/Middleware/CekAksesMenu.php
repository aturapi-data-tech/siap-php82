<?php

namespace App\Http\Middleware;

use App\Services\AppMenu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman yang terdaftar di AppMenu hanya boleh dibuka role yang tercantum di menunya,
 * supaya menu yang disembunyikan tidak bisa ditembus lewat URL. Route di luar menu tidak diperiksa di sini.
 */
class CekAksesMenu
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AppMenu::bolehBuka((string) $request->route()?->getName(), $request->user()), 403);

        return $next($request);
    }
}
