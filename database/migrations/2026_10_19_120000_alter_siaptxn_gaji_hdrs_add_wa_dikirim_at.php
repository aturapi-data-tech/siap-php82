<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * wa_dikirim_at = terakhir kali petugas menekan tombol "Kirim WA" slip ini (membuka wa.me berisi
 * ringkasan + link PDF). Bukan bukti terkirim dari WhatsApp — penanda siapa yang belum dikirimi.
 * Idempotent: kolom yang sudah ada dilewati.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda()) {
            DB::statement('ALTER TABLE siaptxn_gaji_hdrs ADD (wa_dikirim_at TIMESTAMP)');
        }
    }

    public function down(): void
    {
        if ($this->kolomAda()) {
            DB::statement('ALTER TABLE siaptxn_gaji_hdrs DROP COLUMN wa_dikirim_at');
        }
    }

    private function kolomAda(): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['SIAPTXN_GAJI_HDRS', 'WA_DIKIRIM_AT'],
        )->c > 0;
    }
};
