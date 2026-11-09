<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pembagian role: Admin, Manager Keuangan (nama baru role Keuangan — pemegangnya tetap), HRD, Supervisor Unit.
 * Hak tiap role: App\Services\AppMenu (halaman) & App\Support\AksiRole (aksi).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Role::where('name', 'Manager Keuangan')->exists()) {
            DB::table('roles')->whereRaw("TRIM(name) = 'Keuangan'")->update(['name' => 'Manager Keuangan', 'updated_at' => now()]);
        }
        foreach (['Admin', 'Manager Keuangan', 'HRD', 'Supervisor Unit'] as $nama) {
            Role::findOrCreate($nama, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'Manager Keuangan')->update(['name' => 'Keuangan', 'updated_at' => now()]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
