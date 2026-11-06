<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pinjaman Karyawan:
 * - angsuran_tetap: angsuran / bulan yang DITETAPKAN pemberi pinjaman (jadwal bank) — bila diisi, menimpa hitungan
 *   rumus koperasi (pokok ÷ lama + sewa modal). NULL / 0 = pakai rumus.
 * - ketentuan sumber dilengkapi bank-bank umum (nonaktif; nyalakan di tabel Ketentuan saat dipakai).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE siaptxn_pinjamans ADD (angsuran_tetap NUMBER(14,2) NULL)');

        foreach (\App\Support\PinjamanKaryawan::BANK_TAMBAHAN as $sumber) {
            if (!DB::table('siapmst_pinjaman_sumbers')->where('sumber_kode', $sumber['sumber_kode'])->exists()) {
                DB::table('siapmst_pinjaman_sumbers')->insert([...$sumber, 'active_status' => '0', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('siapmst_pinjaman_sumbers')->whereIn('sumber_kode', array_column(\App\Support\PinjamanKaryawan::BANK_TAMBAHAN, 'sumber_kode'))->delete();
        DB::statement('ALTER TABLE siaptxn_pinjamans DROP COLUMN angsuran_tetap');
    }
};
