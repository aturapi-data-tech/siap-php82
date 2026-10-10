<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aturan absensi SIAP — satu sumber untuk kiosk, rekap harian & rekap bulanan.
 *
 * - Tanggal kerja (abs_date) = tanggal MULAI shift. Shift lintas hari (malam) yang
 *   di-absen sebelum jam 12:00 dianggap shift kemarin.
 * - Terlambat dihitung dari jam masuk shift; status 'T' bila lewat toleransi.
 * - Absen pulang mencari catatan yang masih terbuka (sudah masuk, belum pulang) yang
 *   jadwal pulangnya + BATAS_PULANG_JAM belum lewat — jadi lembur lewat tengah malam
 *   tetap tercatat pulang, sedangkan lupa pulang kemarin tidak menahan absen masuk hari ini.
 * - Lembur = jam pulang aktual − jadwal pulang shift, dihitung bila ≥ MIN_LEMBUR_MENIT.
 */
class Absensi
{
    public const STATUS = [
        'H' => 'Hadir',
        'T' => 'Terlambat',
        'I' => 'Izin',
        'S' => 'Sakit',
        'C' => 'Cuti',
        'A' => 'Alpa',
        'L' => 'Libur',
    ];

    /** Status yang diisi admin (bukan dari kiosk). */
    public const STATUS_MANUAL = ['I', 'S', 'C', 'A', 'L'];

    /** Varian x-badge per status. */
    public const BADGE = [
        'H' => 'success',
        'T' => 'warning',
        'I' => 'info',
        'S' => 'purple',
        'C' => 'alternative',
        'A' => 'danger',
        'L' => 'gray',
    ];

    /** Jeda minimal masuk → pulang; scan ulang lebih cepat dianggap dobel. */
    public const JEDA_MIN_PULANG_MENIT = 10;

    /** Lewat jadwal pulang kurang dari ini (mis. beres-beres) tidak dihitung lembur. */
    public const MIN_LEMBUR_MENIT = 30;

    /** Status penilaian lembur oleh admin (kolom lembur_status). */
    public const LEMBUR_STATUS = [
        'P' => 'Menunggu',
        'Y' => 'Dihitung',
        'N' => 'Tidak dihitung',
    ];

    public const LEMBUR_BADGE = [
        'P' => 'warning',
        'Y' => 'success',
        'N' => 'gray',
    ];

    /** Status lembur awal saat jam pulang dicatat: ada lembur → menunggu penilaian admin. */
    public static function statusLemburAwal(int $lemburMenit): ?string
    {
        return $lemburMenit > 0 ? 'P' : null;
    }

    /**
     * Admin menilai lembur satu baris absensi (dipakai Rekap Bulanan).
     * Y = dihitung, N = tidak dihitung, P = kembalikan ke menunggu.
     * @return bool false bila status tidak valid / baris tidak punya lembur.
     */
    public static function nilaiLembur(int $absId, string $status, ?int $userId): bool
    {
        if (!array_key_exists($status, self::LEMBUR_STATUS)) {
            return false;
        }

        return DB::table('siaptxn_absensis')
            ->where('abs_id', $absId)
            ->where('lembur_menit', '>', 0)
            ->update([
                'lembur_status'     => $status,
                'lembur_oleh'       => $status === 'P' ? null : $userId,
                'lembur_dinilai_at' => $status === 'P' ? null : now(),
                'updated_at'        => now(),
            ]) > 0;
    }

    /** Jumlah minggu riwayat untuk menebak hari libur rutin (dipakai Generate). */
    public const MINGGU_POLA_LIBUR = 4;

    /**
     * emp_id yang dalam N minggu terakhir TIDAK PERNAH masuk (H/T) pada hari yang sama
     * dengan $tanggal — mis. tidak pernah masuk hari Minggu → kemungkinan libur rutin.
     * Karyawan tanpa riwayat sama sekali tidak dianggap libur rutin.
     *
     * @param  array<int, string>  $empIds
     * @return array<int, string>
     */
    public static function liburRutin(array $empIds, Carbon $tanggal): array
    {
        if ($empIds === []) {
            return [];
        }

        $hariSama = [];
        for ($i = 1; $i <= self::MINGGU_POLA_LIBUR; $i++) {
            $hariSama[] = self::tanggalSql($tanggal->copy()->subWeeks($i));
        }

        $pernahMasuk = DB::table('siaptxn_absensis')
            ->whereIn('emp_id', $empIds)
            ->whereIn('abs_date', $hariSama)
            ->whereIn('abs_status', ['H', 'T'])
            ->distinct()
            ->pluck('emp_id')
            ->all();

        $punyaRiwayat = DB::table('siaptxn_absensis')
            ->whereIn('emp_id', $empIds)
            ->where('abs_date', '>=', self::tanggalSql($tanggal->copy()->subWeeks(self::MINGGU_POLA_LIBUR)))
            ->where('abs_date', '<', self::tanggalSql($tanggal))
            ->distinct()
            ->pluck('emp_id')
            ->all();

        return array_values(array_diff(array_intersect($empIds, $punyaRiwayat), $pernahMasuk));
    }

    /** Jendela absen pulang setelah jadwal pulang (menampung lembur panjang). */
    public const BATAS_PULANG_JAM = 6;

    /** Batas jam: absen shift lintas hari sebelum jam ini masih milik tanggal kemarin. */
    private const BATAS_JAM_LINTAS_HARI = 12;

    /** @return Collection<int, object> shift aktif, urut jam masuk */
    public static function shiftAktif(): Collection
    {
        return DB::table('siapmst_shifts')->where('active_status', '1')->orderBy('jam_masuk')->get();
    }

    public static function tanggalKerja(object $shift, Carbon $waktu): Carbon
    {
        $tanggal = $waktu->copy()->startOfDay();

        if ((string) $shift->lintas_hari === '1' && $waktu->hour < self::BATAS_JAM_LINTAS_HARI) {
            return $tanggal->subDay();
        }

        return $tanggal;
    }

    /** Shift yang jam masuknya paling dekat dengan $waktu (jarak melingkar 24 jam). */
    public static function sarankanShift(Collection $shifts, Carbon $waktu): ?object
    {
        $menitSekarang = $waktu->hour * 60 + $waktu->minute;

        return $shifts->sortBy(function ($shift) use ($menitSekarang) {
            [$jam, $menit] = array_map('intval', explode(':', $shift->jam_masuk));
            $selisih = abs($jam * 60 + $menit - $menitSekarang);

            return min($selisih, 1440 - $selisih);
        })->first();
    }

    /** Jadwal masuk shift pada tanggal kerja tertentu. */
    public static function jadwalMasuk(object $shift, Carbon $tanggalKerja): Carbon
    {
        [$jam, $menit] = array_map('intval', explode(':', $shift->jam_masuk));

        return $tanggalKerja->copy()->setTime($jam, $menit);
    }

    /** Jadwal pulang shift pada tanggal kerja (shift lintas hari → hari berikutnya). */
    public static function jadwalPulang(object $shift, Carbon $tanggalKerja): Carbon
    {
        [$jam, $menit] = array_map('intval', explode(':', $shift->jam_pulang));
        $pulang = $tanggalKerja->copy()->setTime($jam, $menit);

        return (string) $shift->lintas_hari === '1' ? $pulang->addDay() : $pulang;
    }

    /** Menit lembur; 0 bila pulang kurang dari MIN_LEMBUR_MENIT setelah jadwal pulang. */
    public static function lemburMenit(object $shift, Carbon $tanggalKerja, Carbon $waktuPulang): int
    {
        $menit = (int) floor(self::jadwalPulang($shift, $tanggalKerja)->diffInMinutes($waktuPulang, false));

        return $menit >= self::MIN_LEMBUR_MENIT ? $menit : 0;
    }

    /** @return array{0: string, 1: int} [status H|T, menit terlambat] */
    public static function statusMasuk(object $shift, Carbon $tanggalKerja, Carbon $waktuMasuk): array
    {
        $menit = (int) floor(self::jadwalMasuk($shift, $tanggalKerja)->diffInMinutes($waktuMasuk, false));

        return $menit > (int) $shift->toleransi_menit ? ['T', $menit] : ['H', 0];
    }

    /** Catatan absensi yang sudah masuk tapi belum pulang (untuk absen pulang). */
    public static function absenTerbuka(string $empId, Carbon $waktu): ?object
    {
        $rows = DB::table('siaptxn_absensis as ab')
            ->leftJoin('siapmst_shifts as sh', 'sh.shift_id', '=', 'ab.shift_id')
            ->where('ab.emp_id', $empId)
            ->whereNotNull('ab.jam_masuk')
            ->whereNull('ab.jam_pulang')
            ->whereIn('ab.abs_date', [self::tanggalSql($waktu), self::tanggalSql($waktu->copy()->subDay())])
            ->orderByDesc('ab.abs_date')
            ->select('ab.*', 'sh.shift_name', 'sh.jam_masuk as shift_jam_masuk', 'sh.jam_pulang as shift_jam_pulang', 'sh.lintas_hari')
            ->get();

        return $rows->first(function ($row) use ($waktu) {
            if (!$row->shift_jam_pulang) {
                return Carbon::parse($row->abs_date)->isSameDay($waktu);
            }

            // Masih dalam jendela pulang → lembur panjang (termasuk lewat tengah malam) tetap terhitung pulang.
            // Lewat jendela → dianggap lupa absen pulang; scan berikutnya jadi absen masuk baru.
            $shift = (object) ['jam_pulang' => $row->shift_jam_pulang, 'lintas_hari' => $row->lintas_hari];
            $batas = self::jadwalPulang($shift, Carbon::parse($row->abs_date)->startOfDay())->addHours(self::BATAS_PULANG_JAM);

            return $waktu->lessThanOrEqualTo($batas);
        });
    }

    /** Catatan absensi karyawan pada tanggal kerja tertentu. */
    public static function absenTanggal(string $empId, Carbon $tanggalKerja): ?object
    {
        return DB::table('siaptxn_absensis')
            ->where('emp_id', $empId)
            ->where('abs_date', self::tanggalSql($tanggalKerja))
            ->first();
    }

    /** Ekspresi tanggal Oracle (tanpa jam) untuk kolom DATE. */
    public static function tanggalSql(Carbon $tanggal)
    {
        return DB::raw("to_date('" . $tanggal->format('Y-m-d') . "', 'YYYY-MM-DD')");
    }

    /** Label durasi menit → "2 jam 10 mnt" (dipakai terlambat & lembur). */
    public static function labelMenit(int $menit): string
    {
        return self::labelTerlambat($menit);
    }

    public static function labelTerlambat(int $menit): string
    {
        if ($menit <= 0) {
            return '';
        }

        return $menit >= 60
            ? intdiv($menit, 60) . ' jam ' . ($menit % 60) . ' mnt'
            : $menit . ' mnt';
    }
}
