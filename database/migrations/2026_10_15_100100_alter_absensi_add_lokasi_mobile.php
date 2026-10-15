<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Absen lewat HP (sumber MOBILE):
 * - siapmst_employers.emp_lokasi_id → titik kerja karyawan (kosong = boleh di titik aktif mana pun).
 * - siaptxn_absensis: bukti posisi GPS + selfie saat masuk & pulang.
 *   *_foto = path di disk 'local' (privat), dibuka lewat route absensi.foto.
 * Idempotent: kolom yang sudah ada dilewati.
 */
return new class extends Migration {
    private array $kolomAbsensi = [
        'LOKASI_ID'            => 'lokasi_id VARCHAR2(10)',
        'MASUK_LATITUDE'       => 'masuk_latitude NUMBER(10,7)',
        'MASUK_LONGITUDE'      => 'masuk_longitude NUMBER(10,7)',
        'MASUK_AKURASI_METER'  => 'masuk_akurasi_meter NUMBER(7)',
        'MASUK_JARAK_METER'    => 'masuk_jarak_meter NUMBER(7)',
        'MASUK_FOTO'           => 'masuk_foto VARCHAR2(255)',
        'PULANG_LATITUDE'      => 'pulang_latitude NUMBER(10,7)',
        'PULANG_LONGITUDE'     => 'pulang_longitude NUMBER(10,7)',
        'PULANG_AKURASI_METER' => 'pulang_akurasi_meter NUMBER(7)',
        'PULANG_JARAK_METER'   => 'pulang_jarak_meter NUMBER(7)',
        'PULANG_FOTO'          => 'pulang_foto VARCHAR2(255)',
    ];

    public function up(): void
    {
        if (!$this->kolomAda('SIAPMST_EMPLOYERS', 'EMP_LOKASI_ID')) {
            DB::statement('ALTER TABLE siapmst_employers ADD (emp_lokasi_id VARCHAR2(10))');
            DB::statement('ALTER TABLE siapmst_employers ADD CONSTRAINT fk_siapmst_emp_lokasi FOREIGN KEY (emp_lokasi_id) REFERENCES siapmst_lokasi_absens (lokasi_id)');
        }

        foreach ($this->kolomAbsensi as $nama => $definisi) {
            if (!$this->kolomAda('SIAPTXN_ABSENSIS', $nama)) {
                DB::statement("ALTER TABLE siaptxn_absensis ADD ({$definisi})");
            }
        }
        if (!$this->constraintAda('FK_SIAPTXN_ABSENSIS_LOKASI')) {
            DB::statement('ALTER TABLE siaptxn_absensis ADD CONSTRAINT fk_siaptxn_absensis_lokasi FOREIGN KEY (lokasi_id) REFERENCES siapmst_lokasi_absens (lokasi_id)');
        }
    }

    public function down(): void
    {
        if ($this->constraintAda('FK_SIAPTXN_ABSENSIS_LOKASI')) {
            DB::statement('ALTER TABLE siaptxn_absensis DROP CONSTRAINT fk_siaptxn_absensis_lokasi');
        }
        foreach (array_keys($this->kolomAbsensi) as $nama) {
            if ($this->kolomAda('SIAPTXN_ABSENSIS', $nama)) {
                DB::statement('ALTER TABLE siaptxn_absensis DROP COLUMN ' . strtolower($nama));
            }
        }

        if ($this->kolomAda('SIAPMST_EMPLOYERS', 'EMP_LOKASI_ID')) {
            DB::statement('ALTER TABLE siapmst_employers DROP CONSTRAINT fk_siapmst_emp_lokasi');
            DB::statement('ALTER TABLE siapmst_employers DROP COLUMN emp_lokasi_id');
        }
    }

    private function kolomAda(string $tabel, string $kolom): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            [$tabel, $kolom],
        )->c > 0;
    }

    private function constraintAda(string $nama): bool
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS c FROM user_constraints WHERE constraint_name = ?', [$nama])->c > 0;
    }
};
