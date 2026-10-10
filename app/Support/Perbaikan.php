<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Perbaikan alat — alur Pedoman Pengelolaan Alat Medis RSUI Madinah (bukti MFK 7 EP d):
 * lapor → teknisi respon (target ±15 menit) & analisa → (bila perlu) vendor + service report
 * → selesai dikerjakan → diserahkan kembali ke ruang; biaya dicatat untuk evaluasi.
 */
class Perbaikan
{
    /** kode => [label, varian badge] — urutan = urutan alur. */
    public const STATUS = [
        'B' => ['Dilaporkan', 'warning'],
        'P' => ['Ditangani teknisi', 'info'],
        'V' => ['Di vendor', 'purple'],
        'S' => ['Selesai dikerjakan', 'success'],
        'K' => ['Diserahkan kembali', 'gray'],
        'X' => ['Tidak dapat diperbaiki', 'danger'],
    ];

    /** Status yang masih berjalan (belum tuntas). */
    public const STATUS_AKTIF = ['B', 'P', 'V', 'S'];

    public const PELAKSANA = ['I' => 'Teknisi internal RS', 'V' => 'Vendor'];

    /** Target respon teknisi (Pedoman: ±15 menit). */
    public const TARGET_RESPON_MENIT = 15;

    /** Nomor berikutnya: PB/YYYY/MM/NNNN (urut per bulan). */
    public static function nomorBaru(Carbon $tanggal): string
    {
        $awalan = 'PB/' . $tanggal->format('Y/m') . '/';
        $terakhir = DB::table('siaptxn_perbaikans')->where('pb_no', 'like', $awalan . '%')->max('pb_no');
        $urut = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;

        return $awalan . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    /** Menit dari permintaan sampai respon teknisi (null bila belum direspon). */
    public static function menitRespon(object $pb): ?int
    {
        if (!$pb->tgl_respon) {
            return null;
        }

        return max(0, (int) floor(Carbon::parse($pb->tgl_permintaan)->diffInMinutes(Carbon::parse($pb->tgl_respon), false)));
    }

    /** Lama perbaikan dalam hari (permintaan → selesai / hari ini bila belum selesai). */
    public static function lamaHari(object $pb): int
    {
        $akhir = $pb->tgl_selesai ? Carbon::parse($pb->tgl_selesai) : now();

        return (int) Carbon::parse($pb->tgl_permintaan)->startOfDay()->diffInDays($akhir->startOfDay());
    }

    /** Perbaikan yang masih berjalan untuk sebuah alat (cegah laporan dobel). */
    public static function aktifUntuk(string $assetId): ?object
    {
        return DB::table('siaptxn_perbaikans')->where('asset_id', $assetId)->whereIn('pb_status', self::STATUS_AKTIF)
            ->orderByDesc('tgl_permintaan')->first();
    }
}
