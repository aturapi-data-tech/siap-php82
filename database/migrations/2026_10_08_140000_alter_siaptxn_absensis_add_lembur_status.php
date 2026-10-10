<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Penilaian lembur oleh admin di Rekap Harian.
 * lembur_status: NULL = tidak ada lembur, P = menunggu penilaian, Y = dihitung, N = tidak dihitung.
 * lembur_oleh / lembur_dinilai_at = siapa & kapan menilai.
 * Baris lama yang sudah punya lembur_menit > 0 diisi 'P' (menunggu).
 */
return new class extends Migration {
    private array $kolom = [
        'LEMBUR_STATUS'     => 'lembur_status VARCHAR2(1)',
        'LEMBUR_OLEH'       => 'lembur_oleh NUMBER(19)',
        'LEMBUR_DINILAI_AT' => 'lembur_dinilai_at TIMESTAMP',
    ];

    public function up(): void
    {
        foreach ($this->kolom as $nama => $definisi) {
            if (!$this->kolomAda($nama)) {
                DB::statement("ALTER TABLE siaptxn_absensis ADD ({$definisi})");
            }
        }

        DB::table('siaptxn_absensis')->where('lembur_menit', '>', 0)->whereNull('lembur_status')->update(['lembur_status' => 'P']);
    }

    public function down(): void
    {
        foreach (array_keys($this->kolom) as $nama) {
            if ($this->kolomAda($nama)) {
                DB::statement('ALTER TABLE siaptxn_absensis DROP COLUMN ' . strtolower($nama));
            }
        }
    }

    private function kolomAda(string $nama): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['SIAPTXN_ABSENSIS', $nama],
        )->c > 0;
    }
};
