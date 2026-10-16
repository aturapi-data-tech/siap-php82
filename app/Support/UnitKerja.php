<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unit kerja & hak akses per unit.
 * - Admin / HRD melihat semua unit.
 * - Role lain (mis. kepala ruang) hanya unit karyawan yang terhubung ke akunnya (users.emp_id).
 */
class UnitKerja
{
    /** Role yang boleh mengatur jadwal SEMUA unit. */
    public const ROLE_SEMUA_UNIT = ['admin', 'hrd'];

    /** @return Collection<int, object> unit aktif, urut nama */
    public static function aktif(): Collection
    {
        return DB::table('siapmst_unit_kerjas')->where('active_status', '1')->orderBy('unit_name')->get(['unit_id', 'unit_name']);
    }

    /**
     * Cakupan unit untuk user: ['semuaUnit' => bool, 'unitId' => ?string].
     * semuaUnit false + unitId null = akun tidak terhubung ke karyawan ber-unit (tidak boleh mengatur apa pun).
     */
    public static function cakupanUser(?object $user): array
    {
        if (!$user) {
            return ['semuaUnit' => false, 'unitId' => null];
        }

        $roles = $user->getRoleNames()->map(fn($role) => trim(strtolower($role)))->all();
        if (array_intersect(self::ROLE_SEMUA_UNIT, $roles) !== []) {
            return ['semuaUnit' => true, 'unitId' => null];
        }

        $unitId = $user->emp_id
            ? DB::table('siapmst_employers')->where('emp_id', $user->emp_id)->value('emp_unit_id')
            : null;

        return ['semuaUnit' => false, 'unitId' => $unitId];
    }

    /** Unit karyawan (null = belum diatur). */
    public static function unitKaryawan(string $empId): ?string
    {
        return DB::table('siapmst_employers')->where('emp_id', $empId)->value('emp_unit_id');
    }

    /**
     * Shift yang berlaku untuk satu unit: shift tanpa unit (semua unit) + shift khusus unit tsb.
     * $unitId null = hanya shift tanpa unit.
     *
     * @param  Collection<int, object>  $shifts
     * @return Collection<int, object>
     */
    public static function saringShift(Collection $shifts, ?string $unitId): Collection
    {
        return $shifts->filter(fn($shift) => $shift->unit_id === null || ($unitId !== null && $shift->unit_id === $unitId))->values();
    }
}
