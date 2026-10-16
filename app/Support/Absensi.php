<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aturan absensi SIAP — satu sumber untuk kiosk, rekap harian & rekap bulanan.
 *
 * Angka aturan (jeda, batas pulang, minimal lembur, jam lintas hari) diatur HRD di
 * Absensi › Pengaturan Absensi → App\Support\PengaturanAbsensi.
 *
 * - Tanggal kerja (abs_date) = tanggal MULAI shift. Shift lintas hari (malam) yang
 *   di-absen sebelum batas jam lintas hari (bawaan 12:00) dianggap shift kemarin.
 * - Terlambat dihitung dari jam masuk shift; status 'T' bila lewat toleransi.
 * - Karyawan yang datang lewat tengah malam untuk shift malam kemarin (belum diabsen, shift masih
 *   berjalan) otomatis dicatat ke shift malam kemarin, bukan diminta pilih shift.
 * - Absen pulang mencari catatan yang masih terbuka (sudah masuk, belum pulang) yang
 *   (jadwal pulang atau jam masuk, mana yang lebih akhir) + batas pulang (jam) belum lewat —
 *   jadi lembur lewat tengah malam tetap tercatat pulang, sedangkan lupa pulang kemarin
 *   tidak menahan absen masuk hari ini.
 * - Lembur = jam pulang aktual − jadwal pulang shift, dihitung bila ≥ minimal lembur (menit).
 * - Pulang cepat = jadwal pulang − jam pulang aktual, dicatat bila lewat toleransi shift.
 * - Lupa absen masuk: absen yang jatuh setelah separuh jam shift dikonfirmasi dulu di kiosk / HP —
 *   karyawan bisa memilih "mau pulang" → dicatat jam pulang saja (jam masuk dilengkapi HRD).
 * - Tanpa absen pulang: sudah masuk, belum pulang, dan jendela absen pulang sudah lewat.
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

    /** Keterangan otomatis catatan "lupa absen masuk" (ASCII: karakter "—" rusak di kolom Oracle) — dihapus Koreksi begitu jam masuk dilengkapi. */
    public const KETERANGAN_LUPA_MASUK = 'Lupa absen masuk - jam masuk dilengkapi HRD.';

    /** Datang sejauh ini sebelum jam masuk shift hari ini = datang untuk shift hari ini (bukan shift malam kemarin). */
    private const MENIT_DATANG_AWAL = 60;

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
        for ($mingguKe = 1; $mingguKe <= self::MINGGU_POLA_LIBUR; $mingguKe++) {
            $hariSama[] = self::tanggalSql($tanggal->copy()->subWeeks($mingguKe));
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

    /** @return Collection<int, object> shift aktif, urut jam masuk */
    public static function shiftAktif(): Collection
    {
        return DB::table('siapmst_shifts')->where('active_status', '1')->orderBy('jam_masuk')->get();
    }

    public static function tanggalKerja(object $shift, Carbon $waktu): Carbon
    {
        $tanggal = $waktu->copy()->startOfDay();

        if ((string) $shift->lintas_hari === '1' && $waktu->hour < PengaturanAbsensi::batasJamLintasHari()) {
            return $tanggal->subDay();
        }

        return $tanggal;
    }

    /**
     * Shift yang boleh DIPILIH karyawan saat tidak terjadwal (kiosk / HP): shift tanpa unit +
     * shift unit karyawan tsb. Shift dari jadwal tetap dipakai apa adanya (diatur HRD / kepala ruang).
     *
     * @param  Collection<int, object>  $shifts  shift aktif
     */
    public static function shiftPilihan(string $empId, Collection $shifts): Collection
    {
        return UnitKerja::saringShift($shifts, UnitKerja::unitKaryawan($empId));
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

    /** Menit lembur; 0 bila pulang kurang dari minimal lembur setelah jadwal pulang. */
    public static function lemburMenit(object $shift, Carbon $tanggalKerja, Carbon $waktuPulang): int
    {
        $menit = (int) floor(self::jadwalPulang($shift, $tanggalKerja)->diffInMinutes($waktuPulang, false));

        return $menit >= PengaturanAbsensi::minLemburMenit() ? $menit : 0;
    }

    /**
     * Lembur catatan DOBEL SHIFT (shift pertama + shift kedua dalam satu catatan, pulang setelah shift kedua).
     * = jam kerja di shift kedua (jadwal masuk shift kedua → pulang, maksimal sampai jadwal pulangnya)
     *   + kelebihan setelah jadwal pulang shift kedua (bila ≥ minimal lembur).
     * Jeda antara dua shift (mis. Pagi 12:00 → Sore 17:00) TIDAK dihitung.
     */
    public static function lemburDobelShift(object $shiftPertama, object $shiftKedua, Carbon $tanggalKerja, Carbon $waktuPulang): int
    {
        // Shift kedua = kemunculan jam masuknya yang pertama setelah jam masuk shift pertama (Malam → Pagi = esok hari).
        $masukKedua = self::jadwalMasuk($shiftKedua, $tanggalKerja);
        if ($masukKedua->lessThanOrEqualTo(self::jadwalMasuk($shiftPertama, $tanggalKerja))) {
            $masukKedua->addDay();
        }
        $pulangKedua = $masukKedua->copy()->addMinutes(
            self::jadwalMasuk($shiftKedua, $tanggalKerja)->diffInMinutes(self::jadwalPulang($shiftKedua, $tanggalKerja)));

        $kerjaShiftKedua = max(0, (int) floor($masukKedua->diffInMinutes($waktuPulang->copy()->min($pulangKedua), false)));
        $lewatShiftKedua = (int) floor($pulangKedua->diffInMinutes($waktuPulang, false));

        return $kerjaShiftKedua + ($lewatShiftKedua >= PengaturanAbsensi::minLemburMenit() ? $lewatShiftKedua : 0);
    }

    /** Menit pulang lebih cepat dari jadwal pulang; 0 bila tidak / masih dalam toleransi shift. */
    public static function pulangCepatMenit(object $shift, Carbon $tanggalKerja, Carbon $waktuPulang): int
    {
        $menit = (int) floor($waktuPulang->diffInMinutes(self::jadwalPulang($shift, $tanggalKerja), false));

        return $menit > (int) ($shift->toleransi_menit ?? 0) ? $menit : 0;
    }

    /**
     * Absen MASUK yang jatuh setelah separuh jam shift — kemungkinan karyawan lupa absen masuk
     * dan sebenarnya mau pulang. Kiosk / HP meminta konfirmasi dulu.
     */
    public static function mungkinLupaMasuk(object $shift, Carbon $sekarang): bool
    {
        $tanggalKerja = self::tanggalKerja($shift, $sekarang);
        $jadwalMasuk = self::jadwalMasuk($shift, $tanggalKerja);
        $separuhShift = intdiv((int) $jadwalMasuk->diffInMinutes(self::jadwalPulang($shift, $tanggalKerja)), 2);

        return $sekarang->greaterThanOrEqualTo($jadwalMasuk->copy()->addMinutes($separuhShift));
    }

    /**
     * Batas absen pulang satu catatan: (jadwal pulang atau jam masuk, mana yang lebih akhir) + batas pulang (jam).
     * $row: abs_date, jam_masuk, shift_jam_pulang, lintas_hari. Catatan tanpa shift → akhir tanggal kerjanya.
     */
    public static function batasAbsenPulang(object $row): Carbon
    {
        if (!$row->shift_jam_pulang) {
            return Carbon::parse($row->abs_date)->endOfDay();
        }

        $shift = (object) ['jam_pulang' => $row->shift_jam_pulang, 'lintas_hari' => $row->lintas_hari];
        $jadwalPulang = self::jadwalPulang($shift, Carbon::parse($row->abs_date)->startOfDay());

        return $jadwalPulang->max(Carbon::parse($row->jam_masuk))->addHours(PengaturanAbsensi::batasPulangJam());
    }

    /** Sudah masuk, belum pulang, dan jendela absen pulang sudah lewat (lupa absen pulang). */
    public static function tanpaAbsenPulang(object $row, ?Carbon $sekarang = null): bool
    {
        return $row->jam_masuk && !$row->jam_pulang && ($sekarang ?? now())->greaterThan(self::batasAbsenPulang($row));
    }

    /** Catatan dari kiosk / HP yang hanya punya jam pulang — karyawan lupa absen masuk. */
    public static function lupaAbsenMasuk(object $row): bool
    {
        return !$row->jam_masuk && $row->jam_pulang && in_array($row->sumber, ['KIOSK', 'MOBILE'], true);
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
            ->select('ab.*', 'sh.shift_name', 'sh.jam_masuk as shift_jam_masuk', 'sh.jam_pulang as shift_jam_pulang', 'sh.lintas_hari',
                'sh.toleransi_menit as shift_toleransi_menit')
            ->get();

        return $rows->first(function ($row) use ($waktu) {
            if (!$row->shift_jam_pulang) {
                return Carbon::parse($row->abs_date)->isSameDay($waktu);
            }

            // Masih dalam jendela pulang → lembur panjang (termasuk lewat tengah malam) tetap terhitung pulang.
            // Lewat jendela → dianggap lupa absen pulang; scan berikutnya jadi absen masuk baru.
            // Jendela dihitung dari yang LEBIH AKHIR: jadwal pulang atau jam masuk aktual — karyawan yang
            // baru absen masuk setelah jadwal pulang shift-nya lewat tetap bisa absen pulang.
            return $waktu->lessThanOrEqualTo(self::batasAbsenPulang($row));
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

    /**
     * Keputusan saat karyawan absen (kiosk / HP) — belum menulis apa pun.
     *
     * @param  Collection<int, object>  $shifts  shift aktif
     * @return array{aksi: 'pulang'|'masuk'|'pilih-shift'|'info'|'error', pesan?: string, terbuka?: object,
     *               shift?: object, mungkinLupaMasuk?: bool, catatan?: ?string, saranShiftId?: ?string}
     */
    public static function tentukanAksi(string $empId, Collection $shifts, Carbon $sekarang): array
    {
        // Sudah masuk & belum pulang → absen pulang.
        if ($terbuka = self::absenTerbuka($empId, $sekarang)) {
            $sejakMasuk = (int) Carbon::parse($terbuka->jam_masuk)->diffInMinutes($sekarang);
            if ($sejakMasuk < PengaturanAbsensi::jedaMinPulangMenit()) {
                return ['aksi' => 'info', 'pesan' => 'Sudah absen MASUK pukul ' . Carbon::parse($terbuka->jam_masuk)->format('H:i') . '. Absen pulang bisa dilakukan nanti.'];
            }

            return ['aksi' => 'pulang', 'terbuka' => $terbuka];
        }

        // Catatan hari ini sudah ada (lengkap / diisi admin) → info saja.
        if ($hariIni = self::absenTanggal($empId, $sekarang)) {
            if (in_array($hariIni->abs_status, self::STATUS_MANUAL, true) && !$hariIni->jam_masuk) {
                return ['aksi' => 'info', 'pesan' => 'Hari ini tercatat ' . self::STATUS[$hariIni->abs_status] . '. Hubungi HRD bila ternyata masuk kerja.'];
            }
            if ($hariIni->jam_pulang) {
                return ['aksi' => 'info', 'pesan' => 'Absen hari ini sudah lengkap (masuk '
                    . ($hariIni->jam_masuk ? Carbon::parse($hariIni->jam_masuk)->format('H:i') : '—')
                    . ', pulang ' . Carbon::parse($hariIni->jam_pulang)->format('H:i') . ').'
                    . ' Bila Anda dobel shift (menggantikan jadwal rekan), lapor HRD agar dicatat.'];
            }
        }

        if ($shifts->isEmpty()) {
            return ['aksi' => 'error', 'pesan' => 'Belum ada shift aktif. Hubungi HRD.'];
        }

        // Jadwal dinas hari ini menentukan shift.
        $jadwal = Jadwal::pada($empId, $sekarang);
        $shiftHariIni = $jadwal && $jadwal->jdw_jenis === 'K' ? $shifts->firstWhere('shift_id', $jadwal->shift_id) : null;

        // Datang lewat tengah malam untuk shift malam KEMARIN (mis. jadwal M Senin, datang Selasa 00:30).
        if ($shiftMalam = self::shiftMalamKemarin($empId, $shifts, $sekarang, $shiftHariIni)) {
            return ['aksi' => 'masuk', 'shift' => $shiftMalam, 'mungkinLupaMasuk' => self::mungkinLupaMasuk($shiftMalam, $sekarang)];
        }

        if ($shiftHariIni) {
            return ['aksi' => 'masuk', 'shift' => $shiftHariIni, 'mungkinLupaMasuk' => self::mungkinLupaMasuk($shiftHariIni, $sekarang)];
        }
        if ($jadwal && isset(Jadwal::KHUSUS[$jadwal->jdw_jenis])) {
            return ['aksi' => 'info', 'pesan' => 'Hari ini terjadwal ' . Jadwal::KHUSUS[$jadwal->jdw_jenis] . '. Hubungi HRD bila ternyata masuk kerja.'];
        }

        // Tidak ada jadwal → pilih shift (tukar dinas / tambahan).
        return [
            'aksi'         => 'pilih-shift',
            'catatan'      => Jadwal::adaJadwalBulan($sekarang)
                ? 'Anda tidak terjadwal masuk hari ini (libur). Pilih shift bila memang bertugas (tukar dinas).'
                : null,
            'saranShiftId' => self::sarankanShift(self::shiftPilihan($empId, $shifts), $sekarang)?->shift_id,
        ];
    }

    /**
     * Shift lintas hari dari jadwal KEMARIN yang masih berjalan dan belum diabsen — dipakai bila
     * karyawan baru datang lewat tengah malam. Syarat:
     *   - sebelum batas jam lintas hari (tanggal kerjanya memang jatuh ke kemarin),
     *   - shift kemarin belum lewat jadwal pulangnya & belum ada absensi tanggal kemarin,
     *   - karyawan tidak sedang datang untuk shift HARI INI (≤ MENIT_DATANG_AWAL sebelum jam masuknya).
     */
    private static function shiftMalamKemarin(string $empId, Collection $shifts, Carbon $sekarang, ?object $shiftHariIni): ?object
    {
        if ($sekarang->hour >= PengaturanAbsensi::batasJamLintasHari()) {
            return null;
        }

        $kemarin = $sekarang->copy()->subDay()->startOfDay();
        $jadwalKemarin = Jadwal::pada($empId, $kemarin);
        $shift = $jadwalKemarin && $jadwalKemarin->jdw_jenis === 'K' ? $shifts->firstWhere('shift_id', $jadwalKemarin->shift_id) : null;
        if (!$shift || (string) $shift->lintas_hari !== '1') {
            return null;
        }
        if ($sekarang->greaterThanOrEqualTo(self::jadwalPulang($shift, $kemarin)) || self::absenTanggal($empId, $kemarin)) {
            return null;
        }
        if ($shiftHariIni && $sekarang->greaterThanOrEqualTo(self::jadwalMasuk($shiftHariIni, $sekarang->copy()->startOfDay())->subMinutes(self::MENIT_DATANG_AWAL))) {
            return null;
        }

        return $shift;
    }

    /**
     * Catat absen PULANG pada catatan terbuka (hasil tentukanAksi 'pulang').
     * $kolomTambahan: kolom bukti absen HP (pulang_latitude, pulang_foto, …).
     * @return string pesan untuk layar
     */
    public static function catatPulang(object $terbuka, Carbon $sekarang, array $kolomTambahan = []): string
    {
        $lembur = $terbuka->shift_jam_pulang
            ? self::lemburMenit(
                (object) ['jam_pulang' => $terbuka->shift_jam_pulang, 'lintas_hari' => $terbuka->lintas_hari],
                Carbon::parse($terbuka->abs_date)->startOfDay(),
                $sekarang)
            : 0;

        $pulangCepat = $terbuka->shift_jam_pulang
            ? self::pulangCepatMenit(
                (object) ['jam_pulang' => $terbuka->shift_jam_pulang, 'lintas_hari' => $terbuka->lintas_hari, 'toleransi_menit' => $terbuka->shift_toleransi_menit],
                Carbon::parse($terbuka->abs_date)->startOfDay(),
                $sekarang)
            : 0;

        DB::table('siaptxn_absensis')->where('abs_id', $terbuka->abs_id)->update([
            'jam_pulang'        => $sekarang,
            'pulang_cepat_menit' => $pulangCepat,
            'lembur_menit'      => $lembur,
            'lembur_status'     => self::statusLemburAwal($lembur),
            'lembur_oleh'       => null,
            'lembur_dinilai_at' => null,
            'updated_at'        => $sekarang,
            ...$kolomTambahan,
        ]);

        $pesan = 'Absen PULANG pukul ' . $sekarang->format('H:i') . ' · ' . ($terbuka->shift_name ?? $terbuka->shift_id)
            . ' (masuk ' . Carbon::parse($terbuka->jam_masuk)->format('H:i') . ')';
        if ($lembur > 0) {
            $pesan .= ' — lembur ' . self::labelMenit($lembur) . ' (menunggu persetujuan)';
        }
        if ($pulangCepat > 0) {
            $pesan .= ' — pulang lebih cepat ' . self::labelMenit($pulangCepat) . ' dari jadwal';
        }

        return $pesan;
    }

    /**
     * Karyawan LUPA absen masuk dan sekarang mau pulang (dipilih di layar konfirmasi kiosk / HP):
     * dicatat jam pulang saja pada shift jadwalnya; jam masuk dilengkapi HRD lewat Koreksi.
     * @return array{tipe: 'pulang'|'info', pesan: string, status: ?string}
     */
    public static function catatPulangTanpaMasuk(string $empId, object $shift, Carbon $sekarang,
        string $sumber, ?int $userId, array $kolomTambahan = []): array
    {
        $tanggalKerja = self::tanggalKerja($shift, $sekarang);
        if (self::absenTanggal($empId, $tanggalKerja)) {
            return ['tipe' => 'info', 'pesan' => 'Sudah ada absensi untuk tanggal kerja ' . $tanggalKerja->format('d/m/Y') . '.', 'status' => null];
        }

        $lembur = self::lemburMenit($shift, $tanggalKerja, $sekarang);
        $pulangCepat = self::pulangCepatMenit($shift, $tanggalKerja, $sekarang);

        try {
            DB::table('siaptxn_absensis')->insert([
                'emp_id'             => $empId,
                'abs_date'           => self::tanggalSql($tanggalKerja),
                'shift_id'           => $shift->shift_id,
                'jam_pulang'         => $sekarang,
                'abs_status'         => 'H',
                'terlambat_menit'    => 0,
                'pulang_cepat_menit' => $pulangCepat,
                'lembur_menit'       => $lembur,
                'lembur_status'      => self::statusLemburAwal($lembur),
                'keterangan'         => self::KETERANGAN_LUPA_MASUK,
                'sumber'             => $sumber,
                'updated_by'         => $userId,
                'created_at'         => $sekarang,
                'updated_at'         => $sekarang,
                ...$kolomTambahan,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['tipe' => 'info', 'pesan' => 'Absensi tanggal ini sudah tercatat.', 'status' => null];
        }

        return [
            'tipe'   => 'pulang',
            'pesan'  => 'Absen PULANG pukul ' . $sekarang->format('H:i') . ' · ' . $shift->shift_name
                . '. Jam masuk belum tercatat — lapor HRD / kepala ruang untuk melengkapinya.',
            'status' => 'H',
        ];
    }

    /**
     * Catat absen MASUK dengan shift tertentu (dari jadwal atau pilihan karyawan).
     * $sumber: KIOSK | MOBILE. $kolomTambahan: kolom bukti absen HP (masuk_latitude, masuk_foto, …).
     * @return array{tipe: 'masuk'|'info', pesan: string, status: ?string}
     */
    public static function catatMasuk(string $empId, object $shift, bool $sesuaiJadwal, Carbon $sekarang,
        string $sumber, ?int $userId, array $kolomTambahan = []): array
    {
        $tanggalKerja = self::tanggalKerja($shift, $sekarang);
        [$status, $terlambat] = self::statusMasuk($shift, $tanggalKerja, $sekarang);

        if (self::absenTanggal($empId, $tanggalKerja)) {
            return ['tipe' => 'info', 'pesan' => 'Sudah ada absensi untuk tanggal kerja ' . $tanggalKerja->format('d/m/Y') . '.', 'status' => null];
        }

        try {
            DB::table('siaptxn_absensis')->insert([
                'emp_id'          => $empId,
                'abs_date'        => self::tanggalSql($tanggalKerja),
                'shift_id'        => $shift->shift_id,
                'jam_masuk'       => $sekarang,
                'abs_status'      => $status,
                'terlambat_menit' => $terlambat,
                'sumber'          => $sumber,
                'updated_by'      => $userId,
                'created_at'      => $sekarang,
                'updated_at'      => $sekarang,
                ...$kolomTambahan,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Scan dobel bersamaan → baris pertama sudah tercatat.
            return ['tipe' => 'info', 'pesan' => 'Absen masuk sudah tercatat.', 'status' => null];
        }

        $pesan = 'Absen MASUK pukul ' . $sekarang->format('H:i') . ' · ' . $shift->shift_name . ($sesuaiJadwal ? ' (sesuai jadwal)' : '');
        if ($status === 'T') {
            $pesan .= ' — terlambat ' . self::labelTerlambat($terlambat);
        }

        return ['tipe' => 'masuk', 'pesan' => $pesan, 'status' => $status];
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
