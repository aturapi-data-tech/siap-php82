<?php

namespace App\Services;

use App\Support\AksesHalaman;
use App\Support\ProgramAkses;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * AppMenu — sumber tunggal menu aplikasi (dashboard + sidebar).
 *
 * Definisi menu ada di berkas program app/Akses/Program/<Kelompok>/*.php (App\Support\ProgramAkses),
 * bukan di sini. Setiap entri: ['group','groupOrder','order','route','title','desc','roles','badge'];
 * 'roles' = centang awal hak Lihat halaman (HakAkses::SEMUA_ROLE = terbuka untuk semua user login).
 *
 * - `all()`            semua entri (route yang tidak terdaftar dilewati)
 * - `forUser($user)`   entri yang boleh dilihat user — keputusan App\Support\AksesHalaman, SAMA dengan
 *                      penjaga URL CekAksesMenu: menu yang tampil = halaman yang boleh dibuka
 * - `grouped($user)`   forUser + urut + kelompokkan per 'group' untuk render UI
 */
class AppMenu
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $menu = [];
        foreach (ProgramAkses::menu() as $m) {
            if (!Route::has($m['route'])) {
                continue;
            }
            $m['href'] = route($m['route'], $m['params'] ?? []);
            $menu[] = $m;
        }

        return $menu;
    }

    /** @return array<int, array<string, mixed>> */
    public static function forUser(?object $user): array
    {
        if (!$user) {
            return [];
        }

        return array_values(array_filter(self::all(), fn ($m) => AksesHalaman::boleh($user, $m['route'])));
    }

    /** Sorted + grouped by 'group'. Cocok untuk render sidebar/dashboard. */
    public static function grouped(?object $user): Collection
    {
        return collect(self::forUser($user))
            ->sortBy([['groupOrder', 'asc'], ['order', 'asc']])
            ->groupBy('group');
    }
}
