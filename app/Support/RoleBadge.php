<?php

namespace App\Support;

/**
 * Warna badge role — satu sumber untuk User Control & Role Control.
 *
 * Role yang dikenal punya warna tetap; role baru otomatis dapat warna dari
 * hash namanya (stabil, tidak berubah antar request).
 */
class RoleBadge
{
    private const FIXED = [
        'Admin' => 'red',
        'Hrd' => 'blue',
        'Manager' => 'orange',
        'Supervisor' => 'indigo',
        'Karyawan' => 'emerald',
        'Aset' => 'amber',
    ];

    private const HASHED = ['orange', 'yellow', 'lime', 'green', 'cyan', 'sky', 'indigo', 'purple', 'fuchsia', 'rose', 'teal', 'violet', 'pink'];

    private const BADGE = [
        'red' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200',
        'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200',
        'orange' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-200',
        'indigo' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-200',
        'emerald' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200',
        'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200',
        'yellow' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-200',
        'lime' => 'bg-lime-100 text-lime-800 dark:bg-lime-900/30 dark:text-lime-200',
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200',
        'cyan' => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-200',
        'sky' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-200',
        'purple' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-200',
        'fuchsia' => 'bg-fuchsia-100 text-fuchsia-800 dark:bg-fuchsia-900/30 dark:text-fuchsia-200',
        'rose' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-200',
        'teal' => 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-200',
        'violet' => 'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-200',
        'pink' => 'bg-pink-100 text-pink-800 dark:bg-pink-900/30 dark:text-pink-200',
    ];

    private const TEXT = [
        'red' => 'text-red-700 dark:text-red-400',
        'blue' => 'text-blue-700 dark:text-blue-400',
        'orange' => 'text-orange-700 dark:text-orange-400',
        'indigo' => 'text-indigo-700 dark:text-indigo-400',
        'emerald' => 'text-emerald-700 dark:text-emerald-400',
        'amber' => 'text-amber-700 dark:text-amber-400',
        'yellow' => 'text-yellow-700 dark:text-yellow-400',
        'lime' => 'text-lime-700 dark:text-lime-400',
        'green' => 'text-green-700 dark:text-green-400',
        'cyan' => 'text-cyan-700 dark:text-cyan-400',
        'sky' => 'text-sky-700 dark:text-sky-400',
        'purple' => 'text-purple-700 dark:text-purple-400',
        'fuchsia' => 'text-fuchsia-700 dark:text-fuchsia-400',
        'rose' => 'text-rose-700 dark:text-rose-400',
        'teal' => 'text-teal-700 dark:text-teal-400',
        'violet' => 'text-violet-700 dark:text-violet-400',
        'pink' => 'text-pink-700 dark:text-pink-400',
    ];

    private const DOT = [
        'red' => 'bg-red-500', 'blue' => 'bg-blue-500', 'orange' => 'bg-orange-500', 'indigo' => 'bg-indigo-500',
        'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-500', 'yellow' => 'bg-yellow-500', 'lime' => 'bg-lime-500',
        'green' => 'bg-green-500', 'cyan' => 'bg-cyan-500', 'sky' => 'bg-sky-500', 'purple' => 'bg-purple-500',
        'fuchsia' => 'bg-fuchsia-500', 'rose' => 'bg-rose-500', 'teal' => 'bg-teal-500', 'violet' => 'bg-violet-500',
        'pink' => 'bg-pink-500',
    ];

    public static function palette(string $role): string
    {
        return self::FIXED[$role] ?? self::HASHED[abs(crc32($role)) % count(self::HASHED)];
    }

    /** Kelas badge lengkap (pill berwarna). */
    public static function badge(string $role): string
    {
        return 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ' . self::BADGE[self::palette($role)];
    }

    /** Warna teks untuk item dropdown role yang aktif. */
    public static function activeText(string $role): string
    {
        return self::TEXT[self::palette($role)];
    }

    /** Titik warna untuk pill filter role. */
    public static function dot(string $role): string
    {
        return self::DOT[self::palette($role)];
    }
}
