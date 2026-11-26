<?php

namespace App\Console\Commands;

use App\Support\AksesHalaman;
use App\Support\HakAkses;
use App\Support\ProgramAkses;
use App\Support\SinkronAksesOtomatis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Membuat permission dari berkas program (app/Akses/Program/<Kelompok>/*.php, App\Support\ProgramAkses):
 *  - `{route}.lihat` (dan aksi lain di kunci 'aksi' entri menu) untuk setiap menu yang tidak terbuka untuk semua;
 *  - aksi khusus ('aksiKhusus', mis. gaji.kelola).
 *
 * Centang awal ('roles' / 'rolesAksi') disalin HANYA untuk permission yang baru dibuat pada run ini. Permission yang
 * sudah ada tidak disentuh — menjalankan ulang aman: centang yang dicabut admin di Role Control tidak kembali.
 *
 * Biasanya TIDAK perlu dijalankan manual: App\Support\SinkronAksesOtomatis menjalankannya sendiri pada request
 * pertama sesudah definisi berubah (habis deploy). Manual tetap dipakai untuk --dry-run dan --ulang.
 *
 * `--ulang=keuangan.gaji-karyawan,gaji.kelola` : isi ulang centang route / aksi itu dari definisi (menimpa Role Control
 * untuk itu saja) — dipakai saat centang awal sebuah hak dikoreksi.
 *
 * BERSIH-BERSIH: permission yang tidak lagi lahir dari definisi (menu/aksi dihapus, buatan manual) DIHAPUS beserta
 * centangnya — tak ada kode yang memeriksanya, jadi hak siapa pun tidak berubah. Dicatat ke log channel 'akses'.
 *
 * PENGAMAN BENTROK (pelajaran insiden SIRUS prod 2026-11-26, ORA-00001 CACHE_KEY_PK):
 *  - Memakai kunci yang sama dengan sinkron otomatis (SinkronAksesOtomatis::bukaKunci) — perintah manual menunggu
 *    bila sinkron otomatis sedang jalan, dan sebaliknya sinkron otomatis melewatinya.
 *  - Selama sinkron, cache permission Spatie dialihkan ke store 'array' (memori proses ini). Tanpa ini tiap
 *    Permission::create / givePermissionTo membangun ulang cache di tabel CACHE lewat MERGE, yang bentrok dengan
 *    request web yang membangunnya bersamaan. Cache asli cukup dibuang SEKALI di akhir.
 */
class SinkronAksesHalaman extends Command
{
    protected $signature = 'akses:sinkron
        {--dry-run : Tampilkan rencana tanpa menulis ke DB}
        {--ulang= : Route menu / nama aksi khusus (pisah koma) yang centangnya DIISI ULANG dari definisi — menimpa centang Role Control untuk itu saja}';

    protected $description = 'Buat permission dari berkas program hak akses (app/Akses/Program) dan salin centang awalnya';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $kunci = null;
        if (!SinkronAksesOtomatis::$sedangMenyinkron) {
            $kunci = SinkronAksesOtomatis::bukaKunci();
            if ($kunci === false) {
                $this->error('Berkas kunci storage/framework/akses-sinkron.tanda.lock tidak bisa dibuka — periksa izin folder storage/framework.');
                return self::FAILURE;
            }
            if (!flock($kunci, LOCK_EX | LOCK_NB)) {
                $this->line('Sinkron otomatis sedang berjalan — menunggu sampai selesai…');
                flock($kunci, LOCK_EX);
            }
        }

        $registrar = app(PermissionRegistrar::class);
        $storeAsli = config('permission.cache.store');
        config(['permission.cache.store' => 'array']);
        $registrar->initializeCache();

        try {
            return $this->jalankan($dryRun);
        } finally {
            config(['permission.cache.store' => $storeAsli]);
            $registrar->initializeCache();
            if (!$dryRun) {
                $registrar->forgetCachedPermissions(); // cache asli (DB) dibuang sekali, tanpa ditulis ulang di sini
            }
            if ($kunci) {
                flock($kunci, LOCK_UN);
                fclose($kunci);
            }
        }
    }

    private function jalankan(bool $dryRun): int
    {
        $roleDb = Role::where('guard_name', 'web')->get()->keyBy(fn ($r) => trim(strtolower($r->name)));
        $ada = Permission::where('guard_name', 'web')->pluck('name')->flip();

        $ulang = array_filter(array_map('trim', explode(',', (string) $this->option('ulang'))));
        foreach ($ulang as $r) {
            if (!AksesHalaman::dikenal($r) && !isset(ProgramAkses::aksiKhusus()[$r])) {
                $this->error("--ulang: '{$r}' bukan route menu maupun aksi khusus berkas program");
                return self::FAILURE;
            }
        }

        // Rencana: nama permission => [kunci --ulang, centang awal]
        $rencana = [];
        foreach (AksesHalaman::menuPerRoute() as $route => $menu) {
            if (in_array(HakAkses::SEMUA_ROLE, $menu['roles'], true)) {
                continue; // terbuka untuk semua, tak perlu permission
            }
            foreach (AksesHalaman::aksiBerlaku($route) as $aksi) {
                $rencana[AksesHalaman::namaPermission($route, $aksi)] = [$route, AksesHalaman::rolesAwal($route, $aksi)];
            }
        }
        foreach (ProgramAkses::aksiKhusus() as $nama => $aksi) {
            $rencana[$nama] = [$nama, array_map(fn ($r) => trim(strtolower($r)), $aksi['roles'])];
        }

        $baru = 0;
        $centang = 0;
        $diulang = 0;
        $roleTakDikenal = [];
        foreach ($rencana as $nama => [$kunciUlang, $rolesAwal]) {
            $targetRole = [];
            foreach ($rolesAwal as $r) {
                if (isset($roleDb[$r])) {
                    $targetRole[] = $roleDb[$r];
                } else {
                    $roleTakDikenal[$r] = true;
                }
            }

            if (isset($ada[$nama])) {
                // Sudah ada: centang admin dihormati, KECUALI diminta --ulang.
                if (in_array($kunciUlang, $ulang, true)) {
                    $diulang++;
                    $this->line("  ulang {$nama}: " . collect($targetRole)->pluck('name')->implode(', '));
                    if (!$dryRun) {
                        Permission::findByName($nama, 'web')->syncRoles($targetRole);
                    }
                }
                continue;
            }

            $baru++;
            $centang += count($targetRole);
            $this->line("  baru {$nama}: " . collect($targetRole)->pluck('name')->implode(', '));
            if ($dryRun) {
                continue;
            }
            $permission = Permission::create(['name' => $nama, 'guard_name' => 'web']);
            foreach ($targetRole as $role) {
                $role->givePermissionTo($permission);
            }
        }

        $dibersihkan = $this->bersihkan($rencana, $dryRun);

        if (!$dryRun) {
            AksesHalaman::lupakanCache();
            SinkronAksesOtomatis::tulisTanda(); // sinkron otomatis tak perlu mengulang
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Permission baru: {$baru}, centang role: {$centang}"
            . ($ulang ? ", diisi ulang: {$diulang}" : '') . ($dibersihkan ? ", sisa lama dihapus: {$dibersihkan}" : ''));
        if ($roleTakDikenal) {
            $this->warn('Role di definisi tapi tidak ada di tabel roles: ' . implode(', ', array_keys($roleTakDikenal)));
        }

        return self::SUCCESS;
    }

    /**
     * Hapus permission di luar definisi beserta centangnya.
     *
     * @param array<string, mixed> $rencana
     */
    private function bersihkan(array $rencana, bool $dryRun): int
    {
        $sisa = Permission::where('guard_name', 'web')->with('roles')->orderBy('name')->get()
            ->reject(fn ($p) => isset($rencana[$p->name]));
        if ($sisa->isEmpty()) {
            return 0;
        }

        $catatan = $sisa->mapWithKeys(fn ($p) => [$p->name => $p->roles->pluck('name')->implode(', ')])->all();
        foreach ($catatan as $nama => $roles) {
            $this->line("  hapus {$nama}" . ($roles !== '' ? " (centang: {$roles})" : ''));
        }
        if ($dryRun) {
            return $sisa->count();
        }

        DB::transaction(function () use ($sisa) {
            foreach ($sisa as $permission) {
                $permission->delete(); // Spatie ikut melepas kaitan role & user
            }
        });
        Log::channel('akses')->warning('akses:sinkron menghapus permission di luar definisi', ['permission' => $catatan]);

        return $sisa->count();
    }
}
