<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas instansi (company profile) — SATU BARIS (id = 1), diatur di menu Sistem › Identitas.
 * Dipakai kop cetakan, topbar, halaman depan & login (App\Support\Identitas).
 * logo: path relatif — 'identitas/…' di disk public (hasil unggah) atau 'images/…' di public/.
 * Baris awal diisi dari config/identitas.php (.env IDENTITAS_*).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_identitas', function (Blueprint $table) {
            $table->integer('id');
            $table->primary('id', 'pk_siapmst_identitas');
            $table->string('nama', 150);
            $table->string('nama_singkat', 50)->nullable();
            $table->string('tagline', 200)->nullable();
            $table->string('deskripsi', 2000)->nullable();
            $table->string('pimpinan', 100)->nullable();
            $table->string('jabatan_pimpinan', 100)->nullable();
            $table->string('alamat', 300)->nullable();
            $table->string('kota', 100)->nullable();
            $table->string('kode_pos', 10)->nullable();
            $table->string('telp', 50)->nullable();
            $table->string('fax', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('website', 100)->nullable();
            $table->string('logo', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        DB::table('siapmst_identitas')->insert([
            'id'           => 1,
            'nama'         => config('identitas.nama') ?: 'Nama Instansi',
            'nama_singkat' => null,
            'alamat'       => config('identitas.alamat') ?: null,
            'kota'         => config('identitas.kota') ?: null,
            'telp'         => config('identitas.telp') ?: null,
            'fax'          => config('identitas.fax') ?: null,
            'website'      => config('identitas.website') ?: null,
            'logo'         => config('identitas.logo') ?: null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('siapmst_identitas');
    }
};
