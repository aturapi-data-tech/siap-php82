<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master karyawan SIAP. Prefix kolom emp_ disamakan dengan immst_employers (SIRUS)
 * dan users.emp_id, supaya akun login & data karyawan bisa langsung dihubungkan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_employers', function (Blueprint $table) {
            $table->string('emp_id', 20);
            $table->primary('emp_id', 'pk_siapmst_employers');
            $table->string('emp_name', 100);
            $table->string('emp_nik_ktp', 16)->nullable();
            $table->string('emp_sex', 1)->nullable(); // L / P
            $table->string('emp_birth_place', 50)->nullable();
            $table->date('emp_birth_date')->nullable();
            $table->string('emp_address', 200)->nullable();
            $table->string('emp_phone', 20)->nullable();
            $table->date('emp_join_date')->nullable();
            $table->string('emp_is_nakes', 1)->default('0'); // '1' = wajib STR/SIP
            $table->string('active_status', 1)->default('1');
            $table->timestamps();
        });

        Schema::table('siapmst_employers', function (Blueprint $table) {
            $table->index('emp_name', 'idx_siapmst_employers_name');
            $table->index('active_status', 'idx_siapmst_employers_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siapmst_employers');
    }
};
