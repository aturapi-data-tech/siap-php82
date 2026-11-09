<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Kepegawaian bulanan — pengganti sheet "Lap Pegawai" workbook gaji lama
 * (laporan Kasubbag Kepegawaian: hari kerja, cuti, lembur per karyawan → dipakai bagian gaji).
 * Angka diisi kepegawaian dari catatan mereka, ATAU — bila sakelar "Ambil dari Rekap Absensi" bulan itu menyala — dibaca dari absensi:
 *   hari kerja = hari Hadir + Terlambat, cuti = hari Cuti, jam lembur = lembur yang dinilai "Dihitung" (lihat dariAbsensi()).
 *   Karyawan tanpa catatan absensi sebulan itu tetap memakai isian / bawaan. Sakelar tidak menimpa isian tersimpan:
 *   saat dimatikan, angka kepegawaian kembali utuh. Bulan yang belum memilih mengikuti pilihan terakhir bulan sebelumnya.
 *
 * Per bulan kerja, per karyawan:
 *   hari kerja  = pengali tunjangan makan & transport di slip
 *   cuti        = informasi (tidak masuk slip)
 *   jam lembur  = total jam lembur sebulan
 *
 * Tanpa langkah "siapkan": laporan() selalu mengembalikan SEMUA karyawan peserta — yang belum punya baris tersimpan
 * memakai nilai awal (hari kerja = slip bulan itu / default parameter, cuti & lembur 0). Baris baru ditulis ke tabel
 * hanya saat petugas mengubah angka, jadi petugas cukup mengedit yang berbeda dari bawaan master.
 *
 * "Terapkan ke Slip" (role gaji.kelola) menulis ke slip Draft bulan itu:
 *   - hari kerja slip (GajiKaryawan::ubahHariKerja → makan & transport dihitung ulang),
 *   - baris Upah Lembur = jam ÷ 7 ÷ 22 × dasar × 150 % (dasar = pokok + jasa medik + makan + transport slip saat itu).
 * Satu-satunya sumber lembur slip — Form Bagian (Excel) sengaja tidak punya form Lembur.
 * Karena dasar memuat JM & tunjangan harian, terapkan laporan ini SETELAH Pembagian JM diterapkan.
 */
class LaporanPegawai
{
    /** Upah lembur ala workbook lama: jam ÷ 7 jam/hari ÷ 22 hari × dasar upah × 150 %. */
    public const LEMBUR_JAM_PER_HARI = 7;
    public const LEMBUR_HARI_ACUAN   = 22;
    public const LEMBUR_FAKTOR       = 1.5;

    /** Komponen slip yang menjadi dasar upah lembur. */
    public const KODE_DASAR_LEMBUR = ['POKOK', 'JASA_MEDIK', 'MAKAN', 'TRANSPORT'];

    public static function hitungUpahLembur(float $jam, float $dasar): float
    {
        return $jam <= 0 ? 0.0 : round($jam / self::LEMBUR_JAM_PER_HARI / self::LEMBUR_HARI_ACUAN * $dasar * self::LEMBUR_FAKTOR);
    }

    public static function labelJam(float $jam): string
    {
        return rtrim(rtrim(number_format($jam, 2, ',', '.'), '0'), ',');
    }

    // ─── Laporan bulan ini ────────────────────────────────────────────────────

    /**
     * Laporan bulan ini untuk SEMUA karyawan peserta aktif (lihat karyawan()) — non-aktif keluar dari hitungan;
     * barisnya tidak dihapus, jadi bila diaktifkan lagi angkanya kembali terhitung (pola yang sama dengan poin JM).
     * tersimpan = false → belum ada baris di tabel; nilainya bawaan: hari kerja slip bulan itu / default parameter, cuti & lembur 0.
     * dari_absensi = true → hari kerja, cuti & jam lembur dibaca dari absensi (sakelar menyala & karyawan punya catatan);
     * keterangan tetap isian kepegawaian, dan tersimpan false karena angka yang tampil bukan isian mereka.
     * @return array<string, array{hari_kerja: int, cuti: int, lembur_jam: float, keterangan: string, tersimpan: bool, dari_absensi: bool}>
     */
    public static function laporan(string $periode): array
    {
        $isian = self::isian($periode);
        if (!self::memakaiAbsensi($periode)) {
            return $isian;
        }

        $absensi = self::dariAbsensi($periode);
        foreach ($isian as $empId => $data) {
            if (isset($absensi[$empId])) {
                $isian[$empId] = ['hari_kerja' => $absensi[$empId]['hari_kerja'], 'cuti' => $absensi[$empId]['cuti'],
                    'lembur_jam' => $absensi[$empId]['lembur_jam'], 'keterangan' => $data['keterangan'], 'tersimpan' => false, 'dari_absensi' => true];
            }
        }

        return $isian;
    }

    /** Angka isian kepegawaian / bawaan master, tanpa memandang sakelar absensi. */
    private static function isian(string $periode): array
    {
        $tersimpan = DB::table('siaptxn_lap_pegawais')->where('periode', $periode)->get()->keyBy('emp_id');

        $hasil = [];
        foreach (self::karyawan($periode, null) as $row) {
            $empId = (string) $row->emp_id;
            $ada = $tersimpan[$empId] ?? null;
            $hasil[$empId] = $ada
                ? ['hari_kerja' => (int) $ada->hari_kerja, 'cuti' => (int) $ada->cuti, 'lembur_jam' => (float) $ada->lembur_jam,
                    'keterangan' => (string) ($ada->keterangan ?? ''), 'tersimpan' => true, 'dari_absensi' => false]
                : ['hari_kerja' => (int) ($row->hari_hadir ?? $row->hari_kerja_default ?? 0), 'cuti' => 0, 'lembur_jam' => 0.0,
                    'keterangan' => '', 'tersimpan' => false, 'dari_absensi' => false];
        }

        return $hasil;
    }

    // ─── Sumber dari Rekap Absensi ────────────────────────────────────────────

    /**
     * Pilihan sumber yang berlaku: pilihan bulan itu sendiri, atau pilihan terakhir sebelumnya bila bulan itu belum memilih.
     * sumber_absensi_at terisi = pernah dipilih (sumber_absensi NULL + at terisi = sengaja dimatikan).
     */
    private static function pilihanSumber(string $periode): ?object
    {
        return DB::table('siaptxn_lap_pegawai_hdrs')->where('periode', '<=', $periode)->whereNotNull('sumber_absensi_at')
            ->orderByDesc('periode')->first(['periode', 'sumber_absensi']);
    }

    public static function memakaiAbsensi(string $periode): bool
    {
        return self::pilihanSumber($periode)?->sumber_absensi === '1';
    }

    /** Bulan asal bila sakelar menyala karena mengikuti bulan sebelumnya ('Y-m'); null bila dipilih di bulan ini atau mati. */
    public static function asalSumberAbsensi(string $periode): ?string
    {
        $pilihan = self::pilihanSumber($periode);

        return $pilihan && $pilihan->sumber_absensi === '1' && $pilihan->periode !== $periode ? $pilihan->periode : null;
    }

    public static function aturSumberAbsensi(string $periode, bool $nyala, ?int $userId): void
    {
        $data = ['sumber_absensi' => $nyala ? '1' : null, 'sumber_absensi_at' => now(), 'sumber_absensi_oleh' => $userId, 'updated_at' => now()];
        DB::table('siaptxn_lap_pegawai_hdrs')->where('periode', $periode)->exists()
            ? DB::table('siaptxn_lap_pegawai_hdrs')->where('periode', $periode)->update($data)
            : DB::table('siaptxn_lap_pegawai_hdrs')->insert([...$data, 'periode' => $periode, 'created_at' => now()]);
    }

    /**
     * Angka sebulan dari absensi, hanya karyawan Wajib Absen yang punya catatan (angka sama dengan Rekap Absensi Bulanan).
     * Yang tidak wajib selalu memakai isian, walau punya catatan (scan kiosk sesekali tidak boleh mengubah gajinya).
     * Dihitung per tanggal (DISTINCT) supaya baris ganda tidak melebihi jumlah hari sebulan.
     * @return array<string, array{hari_kerja: int, cuti: int, lembur_jam: float, lembur_menunggu: int}>
     */
    public static function dariAbsensi(string $periode): array
    {
        $awal = Carbon::createFromFormat('!Y-m', $periode)->startOfMonth();

        return DB::table('siaptxn_absensis')
            ->where('abs_date', '>=', Absensi::tanggalSql($awal))
            ->where('abs_date', '<=', Absensi::tanggalSql($awal->copy()->endOfMonth()))
            ->whereIn('emp_id', fn($sub) => $sub->select('emp_id')->from('siapmst_employers')->where('emp_wajib_absen', '1'))
            ->groupBy('emp_id')
            ->select('emp_id',
                DB::raw("COUNT(DISTINCT CASE WHEN abs_status IN ('H', 'T') THEN abs_date END) AS hari_kerja"),
                DB::raw("COUNT(DISTINCT CASE WHEN abs_status = 'C' THEN abs_date END) AS cuti"),
                DB::raw("NVL(SUM(CASE WHEN lembur_status = 'Y' THEN lembur_menit END), 0) AS lembur_menit"),
                DB::raw("SUM(CASE WHEN lembur_status = 'P' THEN 1 ELSE 0 END) AS lembur_menunggu"))
            ->get()
            ->mapWithKeys(fn($row) => [(string) $row->emp_id => [
                'hari_kerja' => (int) $row->hari_kerja,
                'cuti' => (int) $row->cuti,
                'lembur_jam' => round((int) $row->lembur_menit / 60, 2),
                'lembur_menunggu' => (int) $row->lembur_menunggu,
            ]])
            ->all();
    }

    /**
     * Akibat menyalakan sakelar absensi — untuk konfirmasi sebelum angka bulan itu berubah.
     * @return array{peserta_tercatat: int, tanpa_catatan: int, isian_tertimpa: int, angka_berubah: int, lembur_menunggu: int, sudah_diterapkan: bool}
     */
    public static function pratinjauAbsensi(string $periode): array
    {
        $isian = self::isian($periode);
        $absensi = array_intersect_key(self::dariAbsensi($periode), $isian);

        $isianTertimpa = 0;
        $angkaBerubah = 0;
        foreach ($absensi as $empId => $data) {
            $isianTertimpa += $isian[$empId]['tersimpan'] ? 1 : 0;
            $angkaBerubah += $data['hari_kerja'] !== $isian[$empId]['hari_kerja'] || $data['cuti'] !== $isian[$empId]['cuti']
                || abs($data['lembur_jam'] - $isian[$empId]['lembur_jam']) >= 0.005 ? 1 : 0;
        }

        return [
            'peserta_tercatat' => count($absensi),
            'tanpa_catatan' => count($isian) - count($absensi),
            'isian_tertimpa' => $isianTertimpa,
            'angka_berubah' => $angkaBerubah,
            'lembur_menunggu' => count(array_filter($absensi, fn($data) => $data['lembur_menunggu'] > 0)),
            'sudah_diterapkan' => DB::table('siaptxn_lap_pegawai_hdrs')->where('periode', $periode)->whereNotNull('diterapkan_at')->exists(),
        ];
    }

    /**
     * Ubah satu kolom laporan di layar. Baris dibuat bila belum ada, kolom lain memakai nilai bawaan (lihat laporan()).
     * hari_kerja & cuti bilangan bulat 0–31; lembur_jam 0–744 (2 desimal); keterangan teks ≤ 200.
     */
    public static function ubah(string $periode, string $empId, string $kolom, mixed $nilai, ?int $userId): bool
    {
        $data = match ($kolom) {
            'hari_kerja', 'cuti' => is_numeric($nilai) && (float) $nilai == (int) $nilai && (int) $nilai >= 0 && (int) $nilai <= 31 ? [$kolom => (int) $nilai] : null,
            'lembur_jam'         => is_numeric($nilai) && (float) $nilai >= 0 && (float) $nilai <= 744 ? [$kolom => round((float) $nilai, 2)] : null,
            'keterangan'         => is_string($nilai) || $nilai === null ? [$kolom => mb_substr(trim((string) $nilai), 0, 200) ?: null] : null,
            default              => null,
        };
        if ($data === null) {
            return false;
        }
        $data += ['updated_by' => $userId, 'updated_at' => now()];

        $kunci = ['periode' => $periode, 'emp_id' => $empId];
        if (DB::table('siaptxn_lap_pegawais')->where($kunci)->exists()) {
            DB::table('siaptxn_lap_pegawais')->where($kunci)->update($data);
        } else {
            // Isian, bukan laporan(): angka absensi tidak boleh ikut tersimpan saat keterangan diisi selagi sakelar menyala.
            $bawaan = self::isian($periode)[$empId] ?? null;
            if ($bawaan === null) {
                return false; // bukan peserta bulan ini
            }
            DB::table('siaptxn_lap_pegawais')->insert([...$kunci, 'hari_kerja' => $bawaan['hari_kerja'], 'cuti' => $bawaan['cuti'],
                'lembur_jam' => $bawaan['lembur_jam'], ...$data, 'created_at' => now()]);
        }

        return true;
    }

    // ─── Slip ─────────────────────────────────────────────────────────────────

    /**
     * Tulis hari kerja & upah lembur ke slip Draft bulan ini. AMAN DIULANG: hari kerja slip disamakan,
     * baris Upah Lembur dihapus lalu ditulis ulang dari jam × dasar slip saat itu (bukan ditambah).
     * Semua karyawan peserta ikut (yang belum diubah petugas memakai nilai bawaan = hari kerja slip, lembur 0). Slip Final dilewati.
     * @return array{diperbarui: int, final: array<int, string>, tanpaSlip: array<int, string>}
     */
    public static function terapkan(string $periode, ?int $userId): array
    {
        // Bulan yang sudah diterapkan memegang pilihannya sendiri: mengubah sakelar bulan sebelumnya tidak boleh menggeser angkanya.
        if (self::pilihanSumber($periode)?->periode !== $periode) {
            self::aturSumberAbsensi($periode, self::memakaiAbsensi($periode), $userId);
        }
        $laporan = self::laporan($periode);
        $hasil = ['diperbarui' => 0, 'final' => [], 'tanpaSlip' => []];
        $empIds = array_map('strval', array_keys($laporan)) ?: [''];
        $slipList = DB::table('siaptxn_gaji_hdrs')->where('periode', $periode)->whereIn('emp_id', $empIds)
            ->get(['gaji_id', 'emp_id', 'gaji_status', 'hari_hadir'])->keyBy('emp_id');
        $nama = DB::table('siapmst_employers')->whereIn('emp_id', $empIds)->pluck('emp_name', 'emp_id');

        foreach ($laporan as $empId => $data) {
            $slip = $slipList[$empId] ?? null;
            if (!$slip) {
                if ($data['hari_kerja'] > 0 || $data['lembur_jam'] > 0) {
                    $hasil['tanpaSlip'][] = $nama[$empId] ?? $empId;
                }
                continue;
            }
            if ($slip->gaji_status === GajiKaryawan::STATUS_FINAL) {
                $hasil['final'][] = $nama[$empId] ?? $empId;
                continue;
            }

            $gajiId = (int) $slip->gaji_id;
            DB::transaction(function () use ($gajiId, $slip, $data) {
                if ((int) $slip->hari_hadir !== $data['hari_kerja']) {
                    GajiKaryawan::ubahHariKerja($gajiId, $data['hari_kerja']);
                }
                self::tulisLembur($gajiId, $data['lembur_jam']);
            });
            GajiKaryawan::hitungUlang($gajiId);
            $hasil['diperbarui']++;
        }

        $data = ['diterapkan_at' => now(), 'diterapkan_oleh' => $userId, 'updated_at' => now()];
        DB::table('siaptxn_lap_pegawai_hdrs')->where('periode', $periode)->exists()
            ? DB::table('siaptxn_lap_pegawai_hdrs')->where('periode', $periode)->update($data)
            : DB::table('siaptxn_lap_pegawai_hdrs')->insert([...$data, 'periode' => $periode, 'created_at' => now()]);

        return $hasil;
    }

    /** Ganti baris Upah Lembur slip: jam × (dasar slip saat ini). jam 0 → tanpa baris lembur. */
    private static function tulisLembur(int $gajiId, float $jam): void
    {
        $dasar = (float) DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->whereIn('kode', self::KODE_DASAR_LEMBUR)->sum('nilai');
        $upah = self::hitungUpahLembur($jam, $dasar);
        DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->where('kode', 'LEMBUR')->delete();
        if ($upah > 0) {
            GajiKaryawan::simpanBaris($gajiId, ['jenis' => 'D', 'kode' => 'LEMBUR', 'nilai' => $upah, 'jumlah' => $jam,
                'tarif' => round($upah / $jam, 2), 'keterangan' => self::labelJam($jam) . ' jam']);
        }
    }

    /**
     * Cocokkan laporan dengan slip — penanda "perlu diterapkan ulang".
     * Upah lembur dibandingkan dengan hitungan dari dasar slip SAAT INI, jadi JM / hari kerja yang berubah
     * setelah lembur diterapkan juga terdeteksi.
     * @return array<string, array{status: string, hari_slip: int, jam_slip: float, upah_slip: float, upah_hitung: float, dasar: float}>
     *   status: sesuai | beda (slip Draft → Terapkan lagi) | final | tanpa_slip
     */
    public static function cocokSlip(string $periode, ?array $laporan = null): array
    {
        $laporan ??= self::laporan($periode);
        $dasarSql = "'" . implode("','", self::KODE_DASAR_LEMBUR) . "'";
        $slip = DB::table('siaptxn_gaji_hdrs as h')
            ->leftJoin('siaptxn_gaji_dtls as d', 'd.gaji_id', '=', 'h.gaji_id')
            ->where('h.periode', $periode)
            ->groupBy('h.emp_id', 'h.gaji_status', 'h.hari_hadir')
            ->select('h.emp_id', 'h.gaji_status', 'h.hari_hadir',
                DB::raw("NVL(SUM(CASE WHEN d.kode = 'LEMBUR' THEN d.jumlah END), 0) AS jam"),
                DB::raw("NVL(SUM(CASE WHEN d.kode = 'LEMBUR' THEN d.nilai END), 0) AS upah"),
                DB::raw("NVL(SUM(CASE WHEN d.kode IN ({$dasarSql}) THEN d.nilai END), 0) AS dasar"))
            ->get()->keyBy('emp_id');

        $hasil = [];
        foreach ($laporan as $empId => $data) {
            $row = $slip[$empId] ?? null;
            $dasar = (float) ($row->dasar ?? 0);
            $upahHitung = self::hitungUpahLembur($data['lembur_jam'], $dasar);
            $hariSlip = (int) ($row->hari_hadir ?? 0);
            $jamSlip = (float) ($row->jam ?? 0);
            $upahSlip = (float) ($row->upah ?? 0);
            // Jam hanya dibandingkan bila ada upah yang ditulis / dihitung — dasar 0 (parameter kosong) tidak menghasilkan baris lembur.
            $jamSesuai = abs($jamSlip - $data['lembur_jam']) < 0.01 || ($upahSlip < 1 && $upahHitung < 1);
            $sesuai = $row && $hariSlip === $data['hari_kerja'] && $jamSesuai && abs($upahSlip - $upahHitung) < 1;
            $hasil[$empId] = ['hari_slip' => $hariSlip, 'jam_slip' => $jamSlip, 'upah_slip' => $upahSlip, 'upah_hitung' => $upahHitung, 'dasar' => $dasar,
                'status' => match (true) {
                    !$row   => $data['hari_kerja'] > 0 || $data['lembur_jam'] > 0 ? 'tanpa_slip' : 'sesuai',
                    $sesuai => 'sesuai',
                    $row->gaji_status === GajiKaryawan::STATUS_FINAL => 'final',
                    default => 'beda',
                }];
        }

        return $hasil;
    }

    public static function terakhirDiterapkan(string $periode): ?object
    {
        return DB::table('siaptxn_lap_pegawai_hdrs as h')->leftJoin('users as u', 'u.id', '=', 'h.diterapkan_oleh')
            ->where('h.periode', $periode)->first(['h.diterapkan_at', 'u.name']);
    }

    /**
     * Karyawan peserta laporan: aktif, bukan dokter / manajemen (UnitKerja::UNIT_DOKTER_MANAJEMEN — slip mereka tidak disentuh Terapkan),
     * & (punya parameter gaji, slip bulan ini, atau baris laporan bulan ini).
     * @return Collection<int, object> kolom: emp_id, emp_name, unit_name, gaji_status, hari_hadir (slip), hari_kerja_default (master, null bila belum ada parameter)
     */
    public static function karyawan(string $periode, ?string $unitId, string $cari = ''): Collection
    {
        $berlaporan = DB::table('siaptxn_lap_pegawais')->where('periode', $periode)->pluck('emp_id')->all();

        return DB::table('siapmst_employers as em')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'em.emp_unit_id')
            ->leftJoin('siaptxn_gaji_hdrs as gh', fn($join) => $join->on('gh.emp_id', '=', 'em.emp_id')->where('gh.periode', '=', $periode))
            ->leftJoin('siapmst_gaji_karyawans as gk', 'gk.emp_id', '=', 'em.emp_id')
            ->where('em.active_status', '1')
            ->tap(fn($query) => UnitKerja::tanpaDokterManajemen($query, 'em.emp_unit_id'))
            ->where(fn($query) => $query
                ->whereNotNull('gk.emp_id')
                ->orWhereNotNull('gh.gaji_id')
                ->orWhereIn('em.emp_id', $berlaporan ?: ['']))
            ->when($unitId, fn($query) => $query->where('em.emp_unit_id', $unitId))
            ->when(trim($cari) !== '', function ($query) use ($cari) {
                $kata = mb_strtoupper(trim($cari));
                $query->where(fn($sub) => $sub->whereRaw('UPPER(em.emp_id) LIKE ?', ["%{$kata}%"])->orWhereRaw('UPPER(em.emp_name) LIKE ?', ["%{$kata}%"]));
            })
            ->orderBy('un.unit_name')->orderBy('em.emp_name')
            ->select('em.emp_id', 'em.emp_name', 'em.emp_wajib_absen', 'un.unit_name', 'gh.gaji_status', 'gh.hari_hadir', 'gk.hari_kerja_default')
            ->get();
    }
}
