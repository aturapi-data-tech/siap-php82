<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pemeliharaan preventif alat — format "Checklist Perawatan Alat" RSUI Madinah
 * (bukti MFK 7 EP a/e): per ruang per bulan, harian (tgl 1–31) atau mingguan (Minggu 1–5).
 * Hasil: B = Baik, R = Rusak, P = Perlu Perbaikan.
 * Entry lewat CRUD (aset.pemeliharaan-alat): satu catatan per alat per slot —
 * harian = per tanggal, mingguan = per minggu ke-n bulan itu.
 */
class Pemeliharaan
{
    public const HASIL = ['B' => 'Baik', 'P' => 'Perlu Perbaikan', 'R' => 'Rusak'];

    public const FREKUENSI = ['H' => 'Harian', 'M' => 'Mingguan', '-' => 'Tidak rutin'];

    /** Pilihan ruang "Semua Ruang" di cetak checklist. */
    public const SEMUA_RUANG = '*';

    /** Minggu ke-n dalam bulan (1–5): tgl 1–7 → 1, 8–14 → 2, …, 29–31 → 5. */
    public static function mingguKe(Carbon $tanggal): int
    {
        return (int) ceil($tanggal->day / 7);
    }

    /** Rentang tanggal minggu ke-n dalam bulan, mis. [8, 14]. */
    public static function rentangMinggu(Carbon $awalBulan, int $minggu): array
    {
        $dari = ($minggu - 1) * 7 + 1;

        return [$dari, min($minggu * 7, $awalBulan->daysInMonth)];
    }

    /** Jumlah slot kolom: harian = jumlah hari, mingguan = 5 (minggu 5 = tgl 29–31, bila ada). */
    public static function jumlahSlot(Carbon $awalBulan, string $frekuensi): int
    {
        return $frekuensi === 'H' ? $awalBulan->daysInMonth : ($awalBulan->daysInMonth > 28 ? 5 : 4);
    }

    /** Slot (tanggal / minggu ke-) dari sebuah tanggal pemeriksaan. */
    public static function slot(Carbon $tanggal, string $frekuensi): int
    {
        return $frekuensi === 'H' ? $tanggal->day : self::mingguKe($tanggal);
    }

    /**
     * Rentang tanggal "satu pemeriksaan" untuk alat berfrekuensi tsb pada tanggal itu:
     * harian / tidak rutin = tanggal itu saja; mingguan = minggu ke-n bulan itu (1–7, 8–14, …).
     * @return Carbon[] [dari, sampai]
     */
    public static function rentangSlot(Carbon $tanggal, string $frekuensi): array
    {
        if ($frekuensi !== 'M') {
            return [$tanggal->copy()->startOfDay(), $tanggal->copy()->startOfDay()];
        }
        [$dari, $sampai] = self::rentangMinggu($tanggal->copy()->startOfMonth(), self::mingguKe($tanggal));

        return [$tanggal->copy()->day($dari)->startOfDay(), $tanggal->copy()->day($sampai)->startOfDay()];
    }

    /** Catatan yang sudah ada untuk alat di slot yang sama (harian: tanggal itu; mingguan: minggu itu). */
    public static function catatanSlot(string $assetId, string $frekuensi, Carbon $tanggal, ?int $kecualiPmId = null): ?object
    {
        [$dari, $sampai] = self::rentangSlot($tanggal, $frekuensi);

        return DB::table('siaptxn_pemeliharaans')
            ->where('asset_id', $assetId)
            ->where('pm_tanggal', '>=', Absensi::tanggalSql($dari))
            ->where('pm_tanggal', '<=', Absensi::tanggalSql($sampai))
            ->when($kecualiPmId, fn($q) => $q->where('pm_id', '<>', $kecualiPmId))
            ->orderByDesc('pm_tanggal')
            ->first();
    }

    /**
     * Simpan satu hasil pemeriksaan. $pmId null → tambah, tapi bila slot (tanggal / minggu) alat itu
     * sudah terisi, catatan itu yang diperbarui (satu catatan per slot, sesuai format checklist).
     * Hasil B mengosongkan keterangan bila tidak diisi. @return int pm_id
     */
    public static function simpan(string $assetId, string $frekuensi, Carbon $tanggal, string $hasil, ?string $keterangan, ?int $userId, ?int $pmId = null): int
    {
        $data = [
            'pm_tanggal' => Absensi::tanggalSql($tanggal),
            'pm_hasil'   => $hasil,
            'keterangan' => filled($keterangan) ? trim($keterangan) : null,
            'petugas_id' => $userId,
            'updated_at' => now(),
        ];

        $pmId ??= self::catatanSlot($assetId, $frekuensi, $tanggal)?->pm_id;
        if ($pmId) {
            DB::table('siaptxn_pemeliharaans')->where('pm_id', $pmId)->update($data);

            return (int) $pmId;
        }

        DB::table('siaptxn_pemeliharaans')->insert([...$data, 'asset_id' => $assetId, 'created_at' => now()]);

        return (int) DB::table('siaptxn_pemeliharaans')->where('asset_id', $assetId)
            ->where('pm_tanggal', Absensi::tanggalSql($tanggal))->value('pm_id');
    }

    /**
     * Alat aktif di ruang dengan frekuensi tsb + catatan pemeliharaan bulan itu.
     * @return array{alat: Collection, catatan: array<string, array<int, object>>}
     *         catatan[asset_id][slot] = baris terakhir di slot itu
     */
    public static function checklist(string $ruangId, Carbon $awalBulan, string $frekuensi): array
    {
        // SEMUA_RUANG → semua ruang, urut per ruang (dikelompokkan di tampilan & cetakan).
        $alat = DB::table('siapmst_assets as aa')
            ->leftJoin('siapmst_ruangs as ru', 'ru.ruang_id', '=', 'aa.ruang_id')
            ->when($ruangId !== self::SEMUA_RUANG, fn($q) => $q->where('aa.ruang_id', $ruangId))
            ->where('aa.active_status', '1')
            ->where('aa.pm_frekuensi', $frekuensi)
            ->orderBy('aa.ruang_id')
            ->orderBy('aa.asset_name')
            ->orderBy('aa.asset_id')
            ->get(['aa.*', 'ru.ruang_name']);

        $catatan = [];
        if ($alat->isNotEmpty()) {
            $rows = DB::table('siaptxn_pemeliharaans as pm')
                ->leftJoin('users as us', 'us.id', '=', 'pm.petugas_id')
                ->whereIn('pm.asset_id', $alat->pluck('asset_id')->all())
                ->where('pm.pm_tanggal', '>=', Absensi::tanggalSql($awalBulan))
                ->where('pm.pm_tanggal', '<=', Absensi::tanggalSql($awalBulan->copy()->endOfMonth()))
                ->orderBy('pm.pm_tanggal')
                ->get(['pm.*', 'us.name as petugas_name']);

            foreach ($rows as $r) {
                $catatan[$r->asset_id][self::slot(Carbon::parse($r->pm_tanggal), $frekuensi)] = $r;
            }
        }

        return ['alat' => $alat, 'catatan' => $catatan];
    }
}
