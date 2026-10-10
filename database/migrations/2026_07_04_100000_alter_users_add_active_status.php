<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Status aktif user: '1' = aktif (boleh login), '0' = nonaktif (diblokir login).
     * Dipakai untuk menonaktifkan user (mis. dokter yang sudah tidak bekerja) tanpa
     * menghapus datanya. Konvensi '1'/'0' (VARCHAR2(1)) selaras master lain di repo.
     *
     * Idempotent: kolom mungkin sudah ditambahkan manual di Oracle → skip bila ada.
     */
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = 'USERS' AND column_name = 'ACTIVE_STATUS'",
        );

        if ((int) ($exists->c ?? 0) === 0) {
            DB::statement("ALTER TABLE users ADD (active_status VARCHAR2(1) DEFAULT '1' NOT NULL)");
        }

        // Backfill: pastikan user lama bernilai '1' (aktif).
        DB::statement("UPDATE users SET active_status = '1' WHERE active_status IS NULL");
    }

    public function down(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = 'USERS' AND column_name = 'ACTIVE_STATUS'",
        );

        if ((int) ($exists->c ?? 0) > 0) {
            DB::statement('ALTER TABLE users DROP COLUMN active_status');
        }
    }
};
