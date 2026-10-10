<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STR & SIP tenaga kesehatan — satu baris per surat, riwayat disimpan.
 * - STR berlaku seumur hidup (UU 17/2023) → lic_expired_date NULL.
 * - SIP berlaku 5 tahun & per tempat praktik → bisa lebih dari satu per karyawan.
 * active_status '1' = surat yang berlaku sekarang, '0' = riwayat.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_emp_licenses', function (Blueprint $table) {
            $table->bigIncrements('lic_id');
            $table->string('emp_id', 20);
            $table->string('lic_type', 10); // STR / SIP
            $table->string('lic_no', 100);
            $table->string('lic_profesi', 50)->nullable();
            $table->string('lic_issuer', 100)->nullable();
            $table->date('lic_issue_date')->nullable();
            $table->date('lic_expired_date')->nullable();
            $table->string('lic_file', 255)->nullable();
            $table->string('active_status', 1)->default('1');
            $table->timestamps();

            $table->foreign('emp_id', 'fk_siapmst_emp_licenses_emp')
                ->references('emp_id')->on('siapmst_employers');
        });

        Schema::table('siapmst_emp_licenses', function (Blueprint $table) {
            $table->index('emp_id', 'idx_siapmst_emp_lic_emp');
            $table->index('lic_expired_date', 'idx_siapmst_emp_lic_exp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siapmst_emp_licenses');
    }
};
