<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aturan kalibrasi alat (Permenkes 54/2015 Pasal 8: paling sedikit 1x setahun, oleh BPFK /
 * institusi penguji terakreditasi KAN; hasil Laik / Tidak Laik Pakai).
 *
 * Status alat diturunkan dari kalibrasi TERAKHIR (tanggal paling baru):
 *   BELUM   wajib kalibrasi tapi belum ada riwayat
 *   TIDAK   kalibrasi terakhir hasilnya Tidak Laik Pakai
 *   LEWAT   berlaku s/d sudah lewat (kedaluwarsa)
 *   SEGERA  berlaku s/d ≤ BATAS_SEGERA_HARI lagi
 *   OK      masih berlaku
 *   -       alat tidak wajib kalibrasi
 */
class Kalibrasi
{
    public const HASIL = ['L' => 'Laik Pakai', 'T' => 'Tidak Laik Pakai'];

    public const JENIS = ['M' => 'Alat Medis', 'N' => 'Alat Non Medis'];

    public const KONDISI = ['B' => 'Baik', 'RR' => 'Rusak Ringan', 'RB' => 'Rusak Berat'];
    public const KONDISI_BADGE = ['B' => 'success', 'RR' => 'warning', 'RB' => 'danger'];

    /** Peringatan "segera kalibrasi" mulai N hari sebelum berlaku s/d. */
    public const BATAS_SEGERA_HARI = 60;

    /** Default interval kalibrasi (bulan) — Permenkes 54/2015: minimal 1x setahun. */
    public const INTERVAL_DEFAULT = 12;

    public const STATUS = [
        'BELUM'  => ['Belum pernah', 'gray'],
        'TIDAK'  => ['Tidak laik pakai', 'danger'],
        'LEWAT'  => ['Kedaluwarsa', 'danger'],
        'SEGERA' => ['Segera kalibrasi', 'warning'],
        'OK'     => ['Berlaku', 'success'],
    ];

    /**
     * Inline view SQL "kalibrasi TERAKHIR per alat" (ROW_NUMBER analitik) — untuk di-join:
     *   ->leftJoin(DB::raw(Kalibrasi::sqlTerakhir() . ' kl'), 'kl.asset_id', '=', 'aa.asset_id')
     * Sengaja bukan subquery berkorelasi + ROWNUM: pola itu memutus sesi Oracle 10g
     * (ORA-03113) saat dibungkus paginasi.
     */
    public static function sqlTerakhir(): string
    {
        return '(SELECT asset_id, kal_id, kal_tanggal, kal_pelaksana, kal_no_sertifikat, kal_hasil, kal_berlaku_sd
                   FROM (SELECT k.*, ROW_NUMBER() OVER (PARTITION BY k.asset_id ORDER BY k.kal_tanggal DESC, k.kal_id DESC) AS rn_terakhir
                           FROM siaptxn_kalibrasis k)
                  WHERE rn_terakhir = 1)';
    }

    /**
     * Kalibrasi terakhir per alat untuk sekumpulan asset_id (satu query).
     * @param  array<int, string>  $assetIds
     * @return Collection<string, object> keyBy asset_id
     */
    public static function terakhir(array $assetIds): Collection
    {
        if ($assetIds === []) {
            return collect();
        }

        return DB::table(DB::raw(self::sqlTerakhir() . ' kl'))
            ->whereIn('kl.asset_id', $assetIds)
            ->get()
            ->keyBy('asset_id');
    }

    /** @return array{0: string, 1: string, 2: string}|null [kode, label, variant badge] */
    public static function status(object $alat, ?object $terakhir): ?array
    {
        if ((string) $alat->wajib_kalibrasi !== '1') {
            return null;
        }

        $kode = match (true) {
            !$terakhir                     => 'BELUM',
            $terakhir->kal_hasil === 'T'   => 'TIDAK',
            !$terakhir->kal_berlaku_sd     => 'OK',
            default                        => self::kodeBerlaku(Carbon::parse($terakhir->kal_berlaku_sd)),
        };

        $label = self::STATUS[$kode][0];
        if ($kode === 'SEGERA') {
            $label .= ' (' . self::sisaHari(Carbon::parse($terakhir->kal_berlaku_sd)) . ' hari)';
        }

        return [$kode, $label, self::STATUS[$kode][1]];
    }

    private static function kodeBerlaku(Carbon $berlakuSd): string
    {
        $sisa = self::sisaHari($berlakuSd);

        return match (true) {
            $sisa < 0                       => 'LEWAT',
            $sisa <= self::BATAS_SEGERA_HARI => 'SEGERA',
            default                         => 'OK',
        };
    }

    public static function sisaHari(Carbon $berlakuSd): int
    {
        return (int) now()->startOfDay()->diffInDays($berlakuSd->copy()->startOfDay(), false);
    }

    /**
     * Matriks jadwal kalibrasi satu tahun untuk alat AKTIF yang wajib kalibrasi.
     *
     * Per alat per bulan (1–12):
     *   'kal'    => 'L' | 'T' | null   — dikalibrasi bulan itu (hasil Laik / Tidak Laik)
     *   'jadwal' => 'rencana' | 'terpenuhi' | 'terlewat' | null
     *       jatuh tempo (berlaku s/d dari kalibrasi sebelumnya) jatuh di bulan itu;
     *       terpenuhi = sudah ada kalibrasi yang lebih baru; terlewat = lewat & belum.
     * Alat wajib yang BELUM PERNAH dikalibrasi: jatuh tempo dianggap bulan berjalan
     * (tahun berjalan) → 'terlewat'.
     *
     * @return array<int, array{alat: object, status: ?array, bulan: array<int, array{kal: ?string, jadwal: ?string}>}>
     */
    public static function jadwalTahunan(int $tahun, string $ruangId = '', string $kata = ''): array
    {
        $q = DB::table('siapmst_assets as aa')
            ->leftJoin('siapmst_ruangs as ru', 'ru.ruang_id', '=', 'aa.ruang_id')
            ->where('aa.wajib_kalibrasi', '1')
            ->where('aa.active_status', '1')
            ->select('aa.*', 'ru.ruang_name')
            ->orderBy('aa.ruang_id')
            ->orderBy('aa.asset_name');

        if ($ruangId !== '') {
            $q->where('aa.ruang_id', $ruangId);
        }
        if (trim($kata) !== '') {
            $kw = mb_strtoupper(trim($kata));
            $q->where(fn($s) => $s->whereRaw('UPPER(aa.asset_id) LIKE ?', ["%{$kw}%"])
                ->orWhereRaw('UPPER(aa.asset_name) LIKE ?', ["%{$kw}%"]));
        }

        $alat = $q->get();
        if ($alat->isEmpty()) {
            return [];
        }

        $semuaKal = DB::table('siaptxn_kalibrasis')
            ->whereIn('asset_id', $alat->pluck('asset_id')->all())
            ->orderBy('kal_tanggal')
            ->get()
            ->groupBy('asset_id');

        $hariIni = now()->startOfDay();
        $hasil = [];

        foreach ($alat as $a) {
            $riwayat = $semuaKal[$a->asset_id] ?? collect();
            $bulan = array_fill(1, 12, ['kal' => null, 'jadwal' => null]);

            foreach ($riwayat as $k) {
                $tgl = Carbon::parse($k->kal_tanggal);
                if ($tgl->year === $tahun) {
                    // Laik menimpa Tidak Laik bila dua kali dalam sebulan (ulang setelah perbaikan).
                    $lama = $bulan[$tgl->month]['kal'];
                    $bulan[$tgl->month]['kal'] = $lama === 'L' ? 'L' : $k->kal_hasil;
                }

                if ($k->kal_berlaku_sd) {
                    $jatuhTempo = Carbon::parse($k->kal_berlaku_sd)->startOfDay();
                    if ($jatuhTempo->year === $tahun) {
                        $sudahUlang = $riwayat->contains(fn($x) => Carbon::parse($x->kal_tanggal)->gt(Carbon::parse($k->kal_tanggal)));
                        $bulan[$jatuhTempo->month]['jadwal'] = match (true) {
                            $sudahUlang                 => 'terpenuhi',
                            $jatuhTempo->lt($hariIni)   => 'terlewat',
                            default                     => 'rencana',
                        };
                    }
                }
            }

            if ($riwayat->isEmpty() && $tahun === $hariIni->year) {
                $bulan[$hariIni->month]['jadwal'] = 'terlewat';
            }

            $terakhir = $riwayat->sortByDesc('kal_tanggal')->first();
            $hasil[] = ['alat' => $a, 'status' => self::status($a, $terakhir), 'bulan' => $bulan];
        }

        return $hasil;
    }

    /** Default "berlaku s/d" = tanggal kalibrasi + interval alat (bulan). */
    public static function berlakuSdDefault(Carbon $tanggalKalibrasi, int $intervalBulan): Carbon
    {
        return $tanggalKalibrasi->copy()->addMonthsNoOverflow(max(1, $intervalBulan));
    }
}
