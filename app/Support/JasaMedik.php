<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pembagian Jasa Medik (JM) karyawan — pengganti sheet "JaMed" workbook gaji lama.
 *
 * Per bulan, per POS:
 *   bagian pegawai = nilai dasar × % JM × % pegawai          (sisanya bagian RS / BPI)
 *   nilai per poin = bagian pegawai ÷ total poin semua karyawan di pos itu
 *   JM karyawan    = Σ poin karyawan × nilai per poin
 *
 * Contoh Sep 2026 (Excel): Rawat Inap 238.509.413 × 50 % = 119.254.706 ÷ 172 poin = 693.341 / poin;
 * Laborat = 3,9 % × pendapatan lab 329.101.550 = 12.834.960 (100 % pegawai).
 *
 * "Terapkan ke slip" mengganti SEMUA baris JASA_MEDIK slip Draft karyawan yang punya poin bulan itu
 * (satu baris per pos, keterangan = nama pos). Karyawan tanpa poin & slip Final tidak disentuh.
 */
class JasaMedik
{
    /**
     * Pos JM. keterangan = keterangan baris slip (Rawat Inap & "Rawat Jalan / UGD" selaras kolom form JM
     * di GajiFormBagian). persen_jm / persen_pegawai = bawaan bulan pertama; bulan berikutnya menyalin bulan lalu.
     */
    public const POS = [
        'RI'    => ['nama' => 'Rawat Inap', 'singkat' => 'R. Inap', 'dasar' => 'Perolehan JM rawat inap (bangsal, transfer UGD, OK, rawat jalan)', 'persen_jm' => 100, 'persen_pegawai' => 50, 'keterangan' => 'Rawat Inap'],
        'UGD'   => ['nama' => 'UGD Rawat Jalan', 'singkat' => 'UGD', 'dasar' => 'Pendapatan UGD (jasa medis)', 'persen_jm' => 100, 'persen_pegawai' => 100, 'keterangan' => 'Rawat Jalan / UGD'],
        'POLI'  => ['nama' => 'Poli Rawat Jalan', 'singkat' => 'Poli', 'dasar' => 'Jasa karyawan rawat jalan + UGD rawat jalan', 'persen_jm' => 100, 'persen_pegawai' => 100, 'keterangan' => 'Poli Rawat Jalan'],
        'RESEP' => ['nama' => 'Poli Resep', 'singkat' => 'Resep', 'dasar' => 'JK resep + JK transfer', 'persen_jm' => 100, 'persen_pegawai' => 100, 'keterangan' => 'Poli Resep'],
        'LAB'   => ['nama' => 'Laborat', 'singkat' => 'Lab', 'dasar' => 'Total pendapatan laborat', 'persen_jm' => 3.9, 'persen_pegawai' => 100, 'keterangan' => 'Laborat'],
    ];

    /**
     * Pos bulan ini (dibuat otomatis bila belum ada: persen menyalin bulan sebelumnya, dasar 0).
     * @return array<string, array{nilai_dasar: float, persen_jm: float, persen_pegawai: float}>
     */
    public static function pos(string $periode, ?int $userId = null): array
    {
        $tersimpan = DB::table('siaptxn_jm_pos')->where('periode', $periode)->get()->keyBy('pos_kode');
        $kurang = array_diff(array_keys(self::POS), $tersimpan->keys()->all());
        if ($kurang !== []) {
            $bulanLalu = DB::table('siaptxn_jm_pos')->where('periode', '<', $periode)
                ->where('periode', DB::table('siaptxn_jm_pos')->where('periode', '<', $periode)->max('periode'))
                ->get()->keyBy('pos_kode');
            foreach ($kurang as $kode) {
                DB::table('siaptxn_jm_pos')->insert([
                    'periode' => $periode, 'pos_kode' => $kode, 'nilai_dasar' => 0,
                    'persen_jm' => (float) ($bulanLalu[$kode]->persen_jm ?? self::POS[$kode]['persen_jm']),
                    'persen_pegawai' => (float) ($bulanLalu[$kode]->persen_pegawai ?? self::POS[$kode]['persen_pegawai']),
                    'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $tersimpan = DB::table('siaptxn_jm_pos')->where('periode', $periode)->get()->keyBy('pos_kode');
        }

        $hasil = [];
        foreach (array_keys(self::POS) as $kode) {
            $row = $tersimpan[$kode];
            $hasil[$kode] = ['nilai_dasar' => (float) $row->nilai_dasar, 'persen_jm' => (float) $row->persen_jm, 'persen_pegawai' => (float) $row->persen_pegawai];
        }

        return $hasil;
    }

    public static function ubahPos(string $periode, string $kode, string $kolom, float $nilai, ?int $userId): bool
    {
        if (!isset(self::POS[$kode]) || !in_array($kolom, ['nilai_dasar', 'persen_jm', 'persen_pegawai'], true) || $nilai < 0
            || ($kolom !== 'nilai_dasar' && $nilai > 100) || $nilai > 99999999999999) {
            return false;
        }
        self::pos($periode, $userId);

        return DB::table('siaptxn_jm_pos')->where('periode', $periode)->where('pos_kode', $kode)
            ->update([$kolom => round($nilai, $kolom === 'nilai_dasar' ? 2 : 3), 'updated_by' => $userId, 'updated_at' => now()]) > 0;
    }

    /** Poin bulan ini. @return array<string, array<string, float>> emp_id => [pos => poin] */
    public static function poin(string $periode): array
    {
        $hasil = [];
        foreach (DB::table('siaptxn_jm_poins')->where('periode', $periode)->get() as $row) {
            $hasil[$row->emp_id][$row->pos_kode] = (float) $row->poin;
        }

        return $hasil;
    }

    public static function ubahPoin(string $periode, string $empId, string $kode, float $poin, ?int $userId): bool
    {
        if (!isset(self::POS[$kode]) || $poin < 0 || $poin > 999) {
            return false;
        }
        $kunci = ['periode' => $periode, 'emp_id' => $empId, 'pos_kode' => $kode];
        $ada = DB::table('siaptxn_jm_poins')->where($kunci)->exists();
        if ($ada) {
            DB::table('siaptxn_jm_poins')->where($kunci)->update(['poin' => round($poin, 2), 'updated_by' => $userId, 'updated_at' => now()]);
        } else {
            DB::table('siaptxn_jm_poins')->insert([...$kunci, 'poin' => round($poin, 2), 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }

        return true;
    }

    /** Bulan terakhir sebelum $periode yang punya poin (sumber "Salin poin"). */
    public static function periodePoinSebelumnya(string $periode): ?string
    {
        return DB::table('siaptxn_jm_poins')->where('periode', '<', $periode)->max('periode');
    }

    /** Salin poin bulan sebelumnya — hanya karyawan yang belum punya poin bulan ini. @return int jumlah karyawan disalin */
    public static function salinPoin(string $periode, ?int $userId): int
    {
        $sumber = self::periodePoinSebelumnya($periode);
        if (!$sumber) {
            return 0;
        }
        $sudahAda = DB::table('siaptxn_jm_poins')->where('periode', $periode)->distinct()->pluck('emp_id')->all();
        $aktif = DB::table('siapmst_employers')->where('active_status', '1')->pluck('emp_id')->all();
        $baris = DB::table('siaptxn_jm_poins')->where('periode', $sumber)
            // Oracle: '' = NULL → NOT IN ('') selalu kosong; saring hanya bila memang ada.
            ->when($sudahAda !== [], fn($query) => $query->whereNotIn('emp_id', $sudahAda))
            ->whereIn('emp_id', $aktif ?: [''])->get();

        DB::transaction(function () use ($baris, $periode, $userId) {
            foreach ($baris as $row) {
                DB::table('siaptxn_jm_poins')->insert([
                    'periode' => $periode, 'emp_id' => $row->emp_id, 'pos_kode' => $row->pos_kode, 'poin' => $row->poin,
                    'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return $baris->pluck('emp_id')->unique()->count();
    }

    /**
     * Ringkasan per pos: bagian pegawai, bagian RS, total poin, nilai per poin.
     * @return array<string, array{nilai_dasar: float, persen_jm: float, persen_pegawai: float, jm: float, pegawai: float, rs: float, total_poin: float, per_poin: float}>
     */
    public static function ringkasan(string $periode, ?array $pos = null, ?array $poin = null): array
    {
        $pos ??= self::pos($periode);
        $poin ??= self::poin($periode);
        $hasil = [];
        foreach ($pos as $kode => $data) {
            $jm = $data['nilai_dasar'] * $data['persen_jm'] / 100;
            $pegawai = $jm * $data['persen_pegawai'] / 100;
            $totalPoin = array_sum(array_map(fn($perPos) => $perPos[$kode] ?? 0, $poin));
            $hasil[$kode] = [...$data, 'jm' => $jm, 'pegawai' => $pegawai, 'rs' => $jm - $pegawai,
                'total_poin' => $totalPoin, 'per_poin' => $totalPoin > 0 ? $pegawai / $totalPoin : 0.0];
        }

        return $hasil;
    }

    /** JM per pos untuk satu karyawan. @return array<string, float> */
    public static function jmKaryawan(array $poinKaryawan, array $ringkasan): array
    {
        $hasil = [];
        foreach ($ringkasan as $kode => $data) {
            $hasil[$kode] = round(($poinKaryawan[$kode] ?? 0) * $data['per_poin'], 2);
        }

        return $hasil;
    }

    /**
     * Tulis JM ke slip Draft bulan ini.
     * @return array{diperbarui: int, final: array<int, string>, tanpaSlip: array<int, string>}
     */
    public static function terapkan(string $periode, ?int $userId): array
    {
        $poin = self::poin($periode);
        $ringkasan = self::ringkasan($periode, null, $poin);
        $hasil = ['diperbarui' => 0, 'final' => [], 'tanpaSlip' => []];

        $slipList = DB::table('siaptxn_gaji_hdrs')->where('periode', $periode)->whereIn('emp_id', array_map('strval', array_keys($poin)) ?: [''])
            ->get(['gaji_id', 'emp_id', 'gaji_status'])->keyBy('emp_id');
        $nama = DB::table('siapmst_employers')->whereIn('emp_id', array_map('strval', array_keys($poin)) ?: [''])->pluck('emp_name', 'emp_id');

        foreach ($poin as $empId => $poinKaryawan) {
            $slip = $slipList[$empId] ?? null;
            if (!$slip) {
                if (array_sum($poinKaryawan) > 0) {
                    $hasil['tanpaSlip'][] = $nama[$empId] ?? $empId;
                }
                continue;
            }
            if ($slip->gaji_status === GajiKaryawan::STATUS_FINAL) {
                $hasil['final'][] = $nama[$empId] ?? $empId;
                continue;
            }

            $gajiId = (int) $slip->gaji_id;
            DB::transaction(function () use ($gajiId, $poinKaryawan, $ringkasan) {
                DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->where('kode', 'JASA_MEDIK')->delete();
                foreach (self::jmKaryawan($poinKaryawan, $ringkasan) as $kode => $nilai) {
                    if ($nilai > 0) {
                        $poinPos = rtrim(rtrim(number_format($poinKaryawan[$kode], 2, ',', '.'), '0'), ',');
                        GajiKaryawan::simpanBaris($gajiId, ['jenis' => 'D', 'kode' => 'JASA_MEDIK', 'nilai' => $nilai,
                            'jumlah' => $poinKaryawan[$kode], 'tarif' => round($ringkasan[$kode]['per_poin'], 2),
                            'keterangan' => self::POS[$kode]['keterangan'] . " ({$poinPos} poin)"]);
                    }
                }
            });
            GajiKaryawan::hitungUlang($gajiId);
            $hasil['diperbarui']++;
        }

        $ada = DB::table('siaptxn_jm_hdrs')->where('periode', $periode)->exists();
        $data = ['diterapkan_at' => now(), 'diterapkan_oleh' => $userId, 'updated_at' => now()];
        $ada ? DB::table('siaptxn_jm_hdrs')->where('periode', $periode)->update($data)
            : DB::table('siaptxn_jm_hdrs')->insert([...$data, 'periode' => $periode, 'created_at' => now()]);

        return $hasil;
    }

    public static function terakhirDiterapkan(string $periode): ?object
    {
        return DB::table('siaptxn_jm_hdrs as h')->leftJoin('users as u', 'u.id', '=', 'h.diterapkan_oleh')
            ->where('h.periode', $periode)->first(['h.diterapkan_at', 'u.name']);
    }

    /** Karyawan peserta JM: aktif & punya parameter gaji, + yang sudah punya poin bulan ini. @return Collection<int, object> */
    public static function karyawan(string $periode, ?string $unitId, string $cari = ''): Collection
    {
        $berpoin = DB::table('siaptxn_jm_poins')->where('periode', $periode)->distinct()->pluck('emp_id')->all();

        return DB::table('siapmst_employers as em')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'em.emp_unit_id')
            ->leftJoin('siaptxn_gaji_hdrs as gh', fn($join) => $join->on('gh.emp_id', '=', 'em.emp_id')->where('gh.periode', '=', $periode))
            ->where(fn($query) => $query
                ->where(fn($aktif) => $aktif->where('em.active_status', '1')
                    ->whereExists(fn($sub) => $sub->select(DB::raw(1))->from('siapmst_gaji_karyawans as gk')->whereColumn('gk.emp_id', 'em.emp_id')))
                ->orWhereIn('em.emp_id', $berpoin ?: ['']))
            ->when($unitId, fn($query) => $query->where('em.emp_unit_id', $unitId))
            ->when(trim($cari) !== '', function ($query) use ($cari) {
                $kata = mb_strtoupper(trim($cari));
                $query->where(fn($sub) => $sub->whereRaw('UPPER(em.emp_id) LIKE ?', ["%{$kata}%"])->orWhereRaw('UPPER(em.emp_name) LIKE ?', ["%{$kata}%"]));
            })
            ->orderBy('un.unit_name')->orderBy('em.emp_name')
            ->select('em.emp_id', 'em.emp_name', 'un.unit_name', 'gh.gaji_status')
            ->get();
    }
}
