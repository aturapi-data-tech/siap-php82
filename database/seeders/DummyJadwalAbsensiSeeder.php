<?php

namespace Database\Seeders;

use App\Support\Absensi;
use App\Support\Jadwal;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Jadwal dinas OKTOBER 2026 — disalin sel demi sel dari contoh lapangan
 * "Jadwal Masuk Kerja Bulan Oktober 2026" (P = Pagi 07–12, S = Sore 17–21, I = Izin, '.' = kosong/libur;
 * Minggu 4, 11, 18, 25 libur) + absensi tgl 1 s/d KEMARIN yang mengikuti jadwal:
 *   P/S → Hadir / Terlambat (±12%), sesekali lembur (Menunggu penilaian)
 *   I   → Izin
 *   kosong / Minggu → Libur
 * Hari ini dibiarkan kosong supaya kiosk bisa dicoba.
 *
 * Butuh DummyKaryawanSeeder & ShiftDefaultSeeder. Idempotent: sel/baris yang sudah ada dilewati.
 * Jalankan: php artisan db:seed --class=DummyJadwalAbsensiSeeder
 */
class DummyJadwalAbsensiSeeder extends Seeder
{
    /** 31 karakter per karyawan = tgl 1..31. Spasi pemisah minggu hanya untuk keterbacaan. */
    private const JADWAL_OKT_2026 = [
        'KMP-001' => 'PPP. PPPPPP. PPPPPP. PPPPPP. PPPPPP',
        'KMP-002' => 'PPP. SSSSPP. SSSSPP. SSSSPP. SSSSPP',
        'KMP-003' => 'ISS. SSSPPP. SSSPSP. SSSPPP. SSSPPP',
        'KMP-004' => 'SSP. PPPSSS. PPPSSS. PPPSSS. PPPSPS',
        'KMP-005' => '.... SSPSSS. ....P.. ....... .....P',
        'KMP-006' => 'PPP. PPPPPP. PPPPPP. PPPPPP. PPPPPP',
        'KMP-007' => 'SSP. PSPSSS. SSSSSS. PSSSSP. SSSPSP',
        'KMP-008' => 'PSP. SSSPPP. PSSSPS. SSPSSS. SSPSPS',
        'KMP-009' => 'SPS. SPSSSS. SPPPSP. SPSPSS. PPPSPS',
    ];

    public function run(): void
    {
        mt_srand(20261001);
        $awal = Carbon::create(2026, 10, 1);
        $shifts = Jadwal::shiftBerkode();
        $user = DB::table('users')->orderBy('id')->value('id');

        $jadwalBaru = 0;
        foreach (self::JADWAL_OKT_2026 as $empId => $pola) {
            if (!DB::table('siapmst_employers')->where('emp_id', $empId)->exists()) {
                $this->command?->warn("{$empId} belum ada — jalankan DummyKaryawanSeeder dulu.");
                continue;
            }
            $sel = str_split(str_replace(' ', '', $pola));
            foreach ($sel as $i => $kode) {
                $tgl = $awal->copy()->addDays($i);
                if ($kode === '.' || $tgl->month !== 10 || Jadwal::pada($empId, $tgl)) {
                    continue;
                }
                if (Jadwal::isi($empId, $tgl, $kode, $user, $shifts)) {
                    $jadwalBaru++;
                }
            }
        }

        // Absensi tgl 1 s/d kemarin (hanya bila masih di Oktober 2026 atau sesudahnya).
        $kemarin = now()->subDay()->startOfDay();
        $sampai = $kemarin->lt($awal->copy()->endOfMonth()) ? $kemarin : $awal->copy()->endOfMonth();
        $absenBaru = 0;
        $jadwal = Jadwal::bulan(array_keys(self::JADWAL_OKT_2026), $awal);

        for ($tgl = $awal->copy(); $tgl->lte($sampai); $tgl->addDay()) {
            foreach (array_keys(self::JADWAL_OKT_2026) as $empId) {
                if (!DB::table('siapmst_employers')->where('emp_id', $empId)->exists() || Absensi::absenTanggal($empId, $tgl)) {
                    continue;
                }
                $j = $jadwal[$empId][$tgl->day] ?? null;
                DB::table('siaptxn_absensis')->insert($this->baris($empId, $tgl->copy(), $j, $user));
                $absenBaru++;
            }
        }

        $this->command?->info("Jadwal Oktober 2026: {$jadwalBaru} sel · absensi: {$absenBaru} baris");
    }

    private function baris(string $empId, Carbon $tgl, ?object $j, ?int $user): array
    {
        $dasar = ['emp_id' => $empId, 'abs_date' => Absensi::tanggalSql($tgl), 'terlambat_menit' => 0, 'lembur_menit' => 0,
                  'created_at' => now(), 'updated_at' => now()];

        if (!$j) {
            return [...$dasar, 'abs_status' => 'L', 'keterangan' => 'Libur / off (sesuai jadwal)', 'sumber' => 'MANUAL', 'updated_by' => $user];
        }
        if ($j->jdw_jenis !== 'K') {
            return [...$dasar, 'abs_status' => $j->jdw_jenis, 'keterangan' => 'Sesuai jadwal dinas (' . Absensi::STATUS[$j->jdw_jenis] . ')', 'sumber' => 'MANUAL', 'updated_by' => $user];
        }

        $shift = DB::table('siapmst_shifts')->where('shift_id', $j->shift_id)->first();
        $masuk = Absensi::jadwalMasuk($shift, $tgl)->addMinutes(mt_rand(1, 100) <= 12 ? mt_rand(11, 40) : mt_rand(-20, 8))->addSeconds(mt_rand(0, 59));
        [$status, $terlambat] = Absensi::statusMasuk($shift, $tgl, $masuk);
        $pulang = Absensi::jadwalPulang($shift, $tgl)->addMinutes(mt_rand(1, 100) <= 10 ? mt_rand(45, 150) : mt_rand(0, 20))->addSeconds(mt_rand(0, 59));
        $lembur = Absensi::lemburMenit($shift, $tgl, $pulang);

        return [...$dasar,
            'shift_id' => $shift->shift_id, 'jam_masuk' => $masuk, 'jam_pulang' => $pulang,
            'abs_status' => $status, 'terlambat_menit' => $terlambat,
            'lembur_menit' => $lembur, 'lembur_status' => Absensi::statusLemburAwal($lembur),
            'sumber' => 'KIOSK',
        ];
    }
}
