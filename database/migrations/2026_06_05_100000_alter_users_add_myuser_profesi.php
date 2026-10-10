<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Profesi klinis tetap untuk user multi-role (mis. Perawat + Dokter /
        // Perawat + Manager). Dipakai sebagai identitas profesi saat menulis
        // CPPT/SBAR — sebelumnya diambil dari roles->first() yang urutannya
        // arbitrer sehingga user bisa tercatat dengan profesi yang salah.
        // NULL = otomatis ikut role pertama (perilaku lama).
        DB::statement('ALTER TABLE users ADD (myuser_profesi VARCHAR2(30))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN myuser_profesi');
    }
};
