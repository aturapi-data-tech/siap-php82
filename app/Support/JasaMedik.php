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
 * Poin: MASTER per karyawan (Master Karyawan → Gaji) → "Generate Poin" menyalin ke bulan berjalan;
 * poin yang disesuaikan di layar bulan itu dipertahankan saat generate ulang.
 *
 * "Terapkan ke slip" mengganti SEMUA baris JASA_MEDIK slip Draft karyawan yang punya poin bulan itu
 * (satu baris per pos, keterangan = nama pos). Karyawan tanpa poin & slip Final tidak disentuh.
 * Satu-satunya sumber JM slip — Form Bagian (Excel) sengaja tidak punya form Jasa Medik.
 */
class JasaMedik
{
    /**
     * Pos JM. keterangan = keterangan baris slip. persen_jm / persen_pegawai = bawaan bulan pertama; bulan berikutnya menyalin bulan lalu.
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

    /** Poin bulan ini diubah di layar → manual = 1 (tidak ditimpa Generate ulang). */
    public static function ubahPoin(string $periode, string $empId, string $kode, float $poin, ?int $userId): bool
    {
        if (!isset(self::POS[$kode]) || $poin < 0 || $poin > 999) {
            return false;
        }
        $kunci = ['periode' => $periode, 'emp_id' => $empId, 'pos_kode' => $kode];
        $ada = DB::table('siaptxn_jm_poins')->where($kunci)->exists();
        if ($ada) {
            DB::table('siaptxn_jm_poins')->where($kunci)->update(['poin' => round($poin, 2), 'manual' => '1', 'updated_by' => $userId, 'updated_at' => now()]);
        } else {
            DB::table('siaptxn_jm_poins')->insert([...$kunci, 'poin' => round($poin, 2), 'manual' => '1', 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }

        return true;
    }

    // ─── Master poin (Master Karyawan → Gaji) ─────────────────────────────────

    /** @return array<string, float> pos => poin master satu karyawan (semua pos, 0 bila belum diisi). */
    public static function masterPoin(string $empId): array
    {
        $tersimpan = DB::table('siapmst_jm_poins')->where('emp_id', $empId)->pluck('poin', 'pos_kode');

        return array_map(fn($kode) => (float) ($tersimpan[$kode] ?? 0), array_combine(array_keys(self::POS), array_keys(self::POS)));
    }

    /** Simpan master poin satu karyawan; poin 0 = baris dihapus. */
    public static function simpanMasterPoin(string $empId, array $poin, ?int $userId): void
    {
        DB::transaction(function () use ($empId, $poin, $userId) {
            DB::table('siapmst_jm_poins')->where('emp_id', $empId)->delete();
            foreach (array_keys(self::POS) as $kode) {
                $nilai = round(max(0, min(999, (float) ($poin[$kode] ?? 0))), 2);
                if ($nilai > 0) {
                    DB::table('siapmst_jm_poins')->insert(['emp_id' => $empId, 'pos_kode' => $kode, 'poin' => $nilai,
                        'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    /**
     * Generate poin bulan ini dari master (pola generate slip gaji):
     *  - karyawan aktif yang punya master poin → poin bulan = poin master;
     *  - poin yang DIUBAH di layar bulan itu (manual = 1) dipertahankan;
     *  - poin hasil generate lama yang tidak ada lagi di master (karyawan keluar / poin dihapus) dibuang.
     * @return array{karyawan: int, dipertahankan: int, dibuang: int}
     */
    public static function generatePoin(string $periode, ?int $userId): array
    {
        $master = DB::table('siapmst_jm_poins as mp')->join('siapmst_employers as em', 'em.emp_id', '=', 'mp.emp_id')
            ->where('em.active_status', '1')->where('mp.poin', '>', 0)->get(['mp.emp_id', 'mp.pos_kode', 'mp.poin']);
        $lama = DB::table('siaptxn_jm_poins')->where('periode', $periode)->get()
            ->keyBy(fn($row) => $row->emp_id . '|' . $row->pos_kode);
        $hasil = ['karyawan' => $master->pluck('emp_id')->unique()->count(), 'dipertahankan' => 0, 'dibuang' => 0];

        DB::transaction(function () use ($periode, $userId, $master, $lama, &$hasil) {
            $kunciMaster = [];
            foreach ($master as $row) {
                $kunci = $row->emp_id . '|' . $row->pos_kode;
                $kunciMaster[$kunci] = true;
                $ada = $lama[$kunci] ?? null;
                if ($ada?->manual === '1') {
                    $hasil['dipertahankan']++;
                    continue;
                }
                $data = ['poin' => $row->poin, 'manual' => '0', 'updated_by' => $userId, 'updated_at' => now()];
                $ada
                    ? DB::table('siaptxn_jm_poins')->where(['periode' => $periode, 'emp_id' => $row->emp_id, 'pos_kode' => $row->pos_kode])->update($data)
                    : DB::table('siaptxn_jm_poins')->insert([...$data, 'periode' => $periode, 'emp_id' => $row->emp_id, 'pos_kode' => $row->pos_kode, 'created_at' => now()]);
            }
            foreach ($lama as $kunci => $row) {
                if (!isset($kunciMaster[$kunci]) && $row->manual !== '1') {
                    DB::table('siaptxn_jm_poins')->where(['periode' => $periode, 'emp_id' => $row->emp_id, 'pos_kode' => $row->pos_kode])->delete();
                    $hasil['dibuang']++;
                }
            }
        });

        return $hasil;
    }

    /** @return array<string, array<string, true>> emp_id => [pos => true] poin yang diubah manual bulan ini. */
    public static function poinManual(string $periode): array
    {
        $hasil = [];
        foreach (DB::table('siaptxn_jm_poins')->where('periode', $periode)->where('manual', '1')->get(['emp_id', 'pos_kode']) as $row) {
            $hasil[$row->emp_id][$row->pos_kode] = true;
        }

        return $hasil;
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
     * Tulis JM ke slip Draft bulan ini. AMAN DIULANG kapan saja: setiap kali, baris Jasa Medik slip
     * karyawan berpoin dihapus lalu ditulis ulang dari angka terbaru (bukan ditambah).
     * Karyawan yang dulu diterapkan tapi kini tidak berpoin lagi → baris JM hasil terapkan lama
     * (tarif > 0 = nilai per poin; hanya menu ini yang mengisinya) dibersihkan.
     * @return array{diperbarui: int, dibersihkan: int, final: array<int, string>, tanpaSlip: array<int, string>}
     */
    public static function terapkan(string $periode, ?int $userId): array
    {
        $poin = self::poin($periode);
        $ringkasan = self::ringkasan($periode, null, $poin);
        $hasil = ['diperbarui' => 0, 'dibersihkan' => 0, 'final' => [], 'tanpaSlip' => []];

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

        // Bekas terapkan sebelumnya pada karyawan yang kini tanpa poin (master dihapus / keluar).
        $sisaLama = DB::table('siaptxn_gaji_dtls as d')->join('siaptxn_gaji_hdrs as h', 'h.gaji_id', '=', 'd.gaji_id')
            ->where('h.periode', $periode)->where('h.gaji_status', GajiKaryawan::STATUS_DRAFT)
            ->where('d.kode', 'JASA_MEDIK')->where('d.tarif', '>', 0)
            ->when($poin !== [], fn($query) => $query->whereNotIn('h.emp_id', array_map('strval', array_keys($poin))))
            ->distinct()->pluck('h.gaji_id');
        foreach ($sisaLama as $gajiId) {
            DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->where('kode', 'JASA_MEDIK')->where('tarif', '>', 0)->delete();
            GajiKaryawan::hitungUlang((int) $gajiId);
            $hasil['dibersihkan']++;
        }

        $ada = DB::table('siaptxn_jm_hdrs')->where('periode', $periode)->exists();
        $data = ['diterapkan_at' => now(), 'diterapkan_oleh' => $userId, 'updated_at' => now()];
        $ada ? DB::table('siaptxn_jm_hdrs')->where('periode', $periode)->update($data)
            : DB::table('siaptxn_jm_hdrs')->insert([...$data, 'periode' => $periode, 'created_at' => now()]);

        return $hasil;
    }

    /**
     * Cocokkan JM hitungan dengan yang ada di slip — penanda "perlu diterapkan ulang".
     * @return array<string, array{status: string, hitung: float, slip: float}> emp_id => status
     *   status: sesuai | beda (slip Draft, angka beda → Terapkan lagi) | final | tanpa_slip
     */
    public static function cocokSlip(string $periode): array
    {
        $poin = self::poin($periode);
        $ringkasan = self::ringkasan($periode, null, $poin);
        $slip = DB::table('siaptxn_gaji_hdrs as h')
            ->leftJoin('siaptxn_gaji_dtls as d', fn($join) => $join->on('d.gaji_id', '=', 'h.gaji_id')->where('d.kode', '=', 'JASA_MEDIK'))
            ->where('h.periode', $periode)
            ->groupBy('h.emp_id', 'h.gaji_status')
            ->select('h.emp_id', 'h.gaji_status', DB::raw('NVL(SUM(d.nilai), 0) AS jm'),
                DB::raw('SUM(CASE WHEN d.tarif > 0 THEN 1 ELSE 0 END) AS baris_terapkan'))
            ->get()->keyBy('emp_id');

        $hasil = [];
        foreach ($slip->keys()->merge(array_keys($poin))->unique() as $empId) {
            $hitung = array_sum(self::jmKaryawan($poin[$empId] ?? [], $ringkasan));
            $row = $slip[$empId] ?? null;
            if (!isset($poin[$empId]) && (int) ($row->baris_terapkan ?? 0) === 0) {
                continue; // bukan peserta JM & tidak pernah diterapkan — JM-nya urusan rincian / form
            }
            $diSlip = (float) ($row->jm ?? 0);
            $hasil[$empId] = ['hitung' => $hitung, 'slip' => $diSlip, 'status' => match (true) {
                !$row => $hitung > 0 ? 'tanpa_slip' : 'sesuai',
                abs($hitung - $diSlip) < 0.5 => 'sesuai',
                $row->gaji_status === GajiKaryawan::STATUS_FINAL => 'final',
                default => 'beda',
            }];
        }

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
