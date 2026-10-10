<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kolom users khas SIRUS yang dipakai komponen inti:
 * - myuser_code / myuser_name / myuser_ttd_image / emp_id → komponen signature & PDF (App\Support\TtdUser)
 * - last_seen_at / last_seen_route                        → middleware TrackUserActivity
 *
 * Idempotent: kolom yang sudah ada dilewati (aman bila users disalin dari schema lama).
 */
return new class extends Migration {
    private array $columns = [
        'MYUSER_CODE' => 'myuser_code VARCHAR2(50)',
        'MYUSER_NAME' => 'myuser_name VARCHAR2(200)',
        'MYUSER_TTD_IMAGE' => 'myuser_ttd_image VARCHAR2(255)',
        'EMP_ID' => 'emp_id VARCHAR2(50)',
        'LAST_SEEN_AT' => 'last_seen_at TIMESTAMP',
        'LAST_SEEN_ROUTE' => 'last_seen_route VARCHAR2(255)',
    ];

    public function up(): void
    {
        foreach ($this->columns as $name => $definition) {
            if (!$this->columnExists($name)) {
                DB::statement("ALTER TABLE users ADD ({$definition})");
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->columns) as $name) {
            if ($this->columnExists($name)) {
                DB::statement('ALTER TABLE users DROP COLUMN ' . strtolower($name));
            }
        }
    }

    private function columnExists(string $name): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['USERS', $name],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
