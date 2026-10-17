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

    /**
     * Warna latar bawaan per kode — disamakan dgn contoh lapangan (P kuning, S hijau, I abu).
     * Shift yang punya shift_warna memakai warnanya sendiri (diatur di Master Shift).
     */
    /**
     * Warna bawaan per kode — pastel cerah yang serasi dengan tema "Netral Klinis" (teks gelap, kontras ≥ 10:1).
     * P kuning (pagi), S hijau Madinah muda (sore), M biru (malam), N toska, I abu netral, C ungu.
     */
    public const WARNA_BAWAAN = [
        'P' => '#fde68a', 'S' => '#bbe5c8', 'M' => '#93c5fd', 'N' => '#99f6e4',
        'I' => '#e2e6e3', 'C' => '#ddd6fe',
    ];
    public const WARNA_LAIN = '#fed7aa';

    /**
     * Pilihan warna shift di Master Shift — pastel cerah, beda rona supaya mudah dibedakan di grid / cetak,
     * teks kode selalu gelap. Sengaja tanpa warna gelap / neon.
     */
    public const PALET = [
        '#fde68a' => 'Kuning', '#bbe5c8' => 'Hijau', '#bae6fd' => 'Biru muda', '#93c5fd' => 'Biru',
        '#99f6e4' => 'Toska', '#ddd6fe' => 'Ungu', '#fbcfe8' => 'Merah muda', '#fecaca' => 'Merah',
        '#fed7aa' => 'Oranye', '#d9f99d' => 'Hijau muda', '#fcd34d' => 'Emas', '#c7d2fe' => 'Lavender',
    ];

    /** Palet lama (neon, sebelum tema Netral Klinis) → padanan pastelnya. Data shift_warna lama tidak perlu diubah. */
    private const WARNA_LAMA = [
        '#ffff00' => '#fde68a', '#00ff00' => '#bbe5c8', '#7dd3fc' => '#bae6fd', '#2563eb' => '#93c5fd',
        '#5eead4' => '#99f6e4', '#c084fc' => '#ddd6fe', '#f9a8d4' => '#fbcfe8', '#f87171' => '#fecaca',
        '#fdba74' => '#fed7aa', '#bef264' => '#d9f99d', '#a5b4fc' => '#c7d2fe', '#999999' => '#e2e6e3',
    ];

    /** Warna tersimpan → warna palet sekarang (hex lama dipetakan; null/kosong tetap null). */
    public static function normalWarna(?string $warna): ?string
    {
        if (!$warna) {
            return null;
        }
        $warna = mb_strtolower($warna);

        return self::WARNA_LAMA[$warna] ?? $warna;
    }

    /**
     * [latar, teks] untuk satu kode jadwal — dipakai layar & cetak PDF.
     * Teks hitam / putih dipilih dari kecerahan latar supaya selalu terbaca.
     */
    public static function warna(string $kode, ?string $warnaShift = null): array
    {
        $latar = self::normalWarna($warnaShift) ?: (self::WARNA_BAWAAN[$kode] ?? self::WARNA_LAIN);
        [$merah, $hijau, $biru] = sscanf($latar, '#%02x%02x%02x');
        $kecerahan = ($merah * 299 + $hijau * 587 + $biru * 114) / 1000;

        return [$latar, $kecerahan > 150 ? '#13201a' : '#ffffff'];
    }

    /** Atribut style sel / badge kode jadwal: "background-color:…;color:…". */
    public static function gaya(string $kode, ?string $warnaShift = null): string
    {
        [$latar, $teks] = self::warna($kode, $warnaShift);

        return "background-color:{$latar};color:{$teks}";
    }

    /**
     * Jadwal tanggal yang sudah lewat (sebelum hari ini) TERKUNCI — menjaga keaslian data:
     * absensi, Generate Libur/Alpa, dan % kehadiran sudah dihitung dari jadwal tsb.
     * Hari ini & seterusnya masih boleh diubah.
     */
    public static function terkunci(Carbon $tanggal): bool
    {
        return $tanggal->copy()->startOfDay()->lt(now()->startOfDay());
    }

    /**
     * Shift aktif berkode yang berlaku untuk satu unit: kode => objek shift.
     * Shift tanpa unit berlaku untuk semua unit; $unitId null = hanya shift tanpa unit.
     * Kode jadwal unik dalam cakupan satu unit (dijaga validasi Master Shift).
     */
    public static function shiftBerkode(?string $unitId): Collection
    {
        $shifts = DB::table('siapmst_shifts')->where('active_status', '1')->whereNotNull('shift_kode')
            ->orderBy('jam_masuk')->get();

        return UnitKerja::saringShift($shifts, $unitId)->keyBy('shift_kode');
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
            ->get(['jd.*', 'sh.shift_kode', 'sh.shift_name', 'sh.shift_warna']);

        $jadwalPerKaryawan = [];
        foreach ($rows as $row) {
            $jadwalPerKaryawan[$row->emp_id][Carbon::parse($row->jdw_tanggal)->day] = $row;
        }

        return $jadwalPerKaryawan;
    }

    /** Jadwal satu karyawan pada satu tanggal (null = tidak dijadwalkan / libur). */
    public static function pada(string $empId, Carbon $tanggal): ?object
    {
        return DB::table('siaptxn_jadwals as jd')
            ->leftJoin('siapmst_shifts as sh', 'sh.shift_id', '=', 'jd.shift_id')
            ->where('jd.emp_id', $empId)
            ->where('jd.jdw_tanggal', Absensi::tanggalSql($tanggal))
            ->first(['jd.*', 'sh.shift_kode', 'sh.shift_name', 'sh.shift_warna', 'sh.jam_masuk', 'sh.jam_pulang', 'sh.toleransi_menit', 'sh.lintas_hari', 'sh.shift_fleksibel']);
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
     * $shifts = shift berkode untuk UNIT karyawan tsb (bawaan: dicari dari unitnya).
     * @return bool false bila kode tidak dikenal / bukan shift unit karyawan.
     */
    public static function isi(string $empId, Carbon $tanggal, string $kode, ?int $userId, ?Collection $shifts = null): bool
    {
        $shifts ??= self::shiftBerkode(UnitKerja::unitKaryawan($empId));
        $tanggalSql = Absensi::tanggalSql($tanggal);

        if ($kode === '') {
            DB::table('siaptxn_jadwals')->where('emp_id', $empId)->where('jdw_tanggal', $tanggalSql)->delete();
            return true;
        }

        if (isset(self::KHUSUS[$kode])) {
            $payload = ['jdw_jenis' => $kode, 'shift_id' => null];
        } elseif ($shift = $shifts->get($kode)) {
            $payload = ['jdw_jenis' => 'K', 'shift_id' => $shift->shift_id];
        } else {
            return false;
        }

        $payload += ['updated_by' => $userId, 'updated_at' => now()];
        $sudahAda = DB::table('siaptxn_jadwals')->where('emp_id', $empId)->where('jdw_tanggal', $tanggalSql)->exists();

        $sudahAda
            ? DB::table('siaptxn_jadwals')->where('emp_id', $empId)->where('jdw_tanggal', $tanggalSql)->update($payload)
            : DB::table('siaptxn_jadwals')->insert([...$payload, 'emp_id' => $empId, 'jdw_tanggal' => $tanggalSql, 'created_at' => now()]);

        return true;
    }
}
