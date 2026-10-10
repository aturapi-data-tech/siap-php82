<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Jadwal dinas bulanan (format "Jadwal Masuk Kerja Bulan …" lapangan):
 * grid karyawan × tanggal; isi = kode shift (P/S/M/N…) · I izin · C cuti · kosong = libur/off.
 * Dipakai kiosk (shift otomatis), Generate Libur/Alpa, dan Rekap Harian.
 */
class Jadwal
{
    /** Kode khusus (bukan shift). */
    public const KHUSUS = ['I' => 'Izin', 'C' => 'Cuti'];

    /** Warna sel per kode — disamakan dgn contoh lapangan: P #FFFF00, S #00FF00, I abu (#999999). */
    public const WARNA = [
        'P' => 'bg-[#ffff00] text-gray-900',
        'S' => 'bg-[#00ff00] text-gray-900',
        'M' => 'bg-blue-600 text-white',
        'N' => 'bg-teal-300 text-gray-900',
        'I' => 'bg-[#999999] text-white',
        'C' => 'bg-purple-400 text-white',
    ];
    public const WARNA_LAIN = 'bg-orange-300 text-gray-900';

    /** Warna cetak PDF (hex) — padanan WARNA. */
    public const WARNA_PDF = [
        'P' => ['#ffff00', '#111'], 'S' => ['#00ff00', '#111'], 'M' => ['#2563eb', '#fff'],
        'N' => ['#5eead4', '#111'], 'I' => ['#999999', '#fff'], 'C' => ['#c084fc', '#fff'],
    ];

    /**
     * Jadwal tanggal yang sudah lewat (sebelum hari ini) TERKUNCI — menjaga keaslian data:
     * absensi, Generate Libur/Alpa, dan % kehadiran sudah dihitung dari jadwal tsb.
     * Hari ini & seterusnya masih boleh diubah.
     */
    public static function terkunci(Carbon $tanggal): bool
    {
        return $tanggal->copy()->startOfDay()->lt(now()->startOfDay());
    }

    /** Shift aktif berkode: kode => objek shift. */
    public static function shiftBerkode(): Collection
    {
        return DB::table('siapmst_shifts')->where('active_status', '1')->whereNotNull('shift_kode')
            ->orderBy('jam_masuk')->get()->keyBy('shift_kode');
    }

    /** Kode tampilan satu baris jadwal (P / S / I / C …). */
    public static function kode(object $jadwal): string
    {
        return $jadwal->jdw_jenis === 'K' ? (string) ($jadwal->shift_kode ?? '?') : (string) $jadwal->jdw_jenis;
    }

    /**
     * Jadwal sebulan: [emp_id][hari] = baris (+ shift_kode, shift_name).
     * @param  array<int, string>  $empIds
     */
    public static function bulan(array $empIds, Carbon $awalBulan): array
    {
        if ($empIds === []) {
            return [];
        }

        $rows = DB::table('siaptxn_jadwals as jd')
            ->leftJoin('siapmst_shifts as sh', 'sh.shift_id', '=', 'jd.shift_id')
            ->whereIn('jd.emp_id', $empIds)
            ->where('jd.jdw_tanggal', '>=', Absensi::tanggalSql($awalBulan))
            ->where('jd.jdw_tanggal', '<=', Absensi::tanggalSql($awalBulan->copy()->endOfMonth()))
            ->get(['jd.*', 'sh.shift_kode', 'sh.shift_name']);

        $hasil = [];
        foreach ($rows as $r) {
            $hasil[$r->emp_id][Carbon::parse($r->jdw_tanggal)->day] = $r;
        }

        return $hasil;
    }

    /** Jadwal satu karyawan pada satu tanggal (null = tidak dijadwalkan / libur). */
    public static function pada(string $empId, Carbon $tanggal): ?object
    {
        return DB::table('siaptxn_jadwals as jd')
            ->leftJoin('siapmst_shifts as sh', 'sh.shift_id', '=', 'jd.shift_id')
            ->where('jd.emp_id', $empId)
            ->where('jd.jdw_tanggal', Absensi::tanggalSql($tanggal))
            ->first(['jd.*', 'sh.shift_kode', 'sh.shift_name', 'sh.jam_masuk', 'sh.jam_pulang', 'sh.toleransi_menit', 'sh.lintas_hari']);
    }

    /** Apakah bulan ini sudah punya jadwal (minimal satu baris) — penentu dipakai/tidaknya jadwal. */
    public static function adaJadwalBulan(Carbon $tanggal): bool
    {
        return DB::table('siaptxn_jadwals')
            ->where('jdw_tanggal', '>=', Absensi::tanggalSql($tanggal->copy()->startOfMonth()))
            ->where('jdw_tanggal', '<=', Absensi::tanggalSql($tanggal->copy()->endOfMonth()))
            ->exists();
    }

    /**
     * Isi / hapus satu sel. $kode: kode shift (P/S/…) · I · C · '' (hapus = libur).
     * @return bool false bila kode tidak dikenal.
     */
    public static function isi(string $empId, Carbon $tanggal, string $kode, ?int $userId, ?Collection $shifts = null): bool
    {
        $shifts ??= self::shiftBerkode();
        $tgl = Absensi::tanggalSql($tanggal);

        if ($kode === '') {
            DB::table('siaptxn_jadwals')->where('emp_id', $empId)->where('jdw_tanggal', $tgl)->delete();
            return true;
        }

        if (isset(self::KHUSUS[$kode])) {
            $data = ['jdw_jenis' => $kode, 'shift_id' => null];
        } elseif ($shift = $shifts->get($kode)) {
            $data = ['jdw_jenis' => 'K', 'shift_id' => $shift->shift_id];
        } else {
            return false;
        }

        $data += ['updated_by' => $userId, 'updated_at' => now()];
        $ada = DB::table('siaptxn_jadwals')->where('emp_id', $empId)->where('jdw_tanggal', $tgl)->exists();

        $ada
            ? DB::table('siaptxn_jadwals')->where('emp_id', $empId)->where('jdw_tanggal', $tgl)->update($data)
            : DB::table('siaptxn_jadwals')->insert([...$data, 'emp_id' => $empId, 'jdw_tanggal' => $tgl, 'created_at' => now()]);

        return true;
    }
}
