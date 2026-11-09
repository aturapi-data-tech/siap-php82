<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\UnitKerja;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Akun Supervisor Unit untuk tiap unit yang punya karyawan wajib absen: <unit>@siap.com (mis. farmasi@siap.com).
 * Unit supervisor dibaca dari karyawan yang terhubung ke akun, jadi akun dihubungkan ke karyawan pertama unit itu;
 * ganti ke supervisor sebenarnya di User Control. Akun yang sudah ada tidak diubah, jadi aman diulang.
 */
class BuatAkunSupervisor extends Command
{
    protected $signature = 'siap:akun-supervisor {--password= : Password semua akun baru (bila kosong, ditanyakan)}';

    protected $description = 'Buat akun Supervisor Unit untuk tiap unit wajib absen';

    public function handle(): int
    {
        $sandi = (string) ($this->option('password') ?: $this->secret('Password akun baru'));
        if (mb_strlen($sandi) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        $baris = [];
        foreach (UnitKerja::aktifWajibAbsen() as $unit) {
            $email = self::emailUnit($unit->unit_name);
            if (User::where('email', $email)->exists()) {
                $baris[] = [$unit->unit_name, $email, '-', 'sudah ada, tidak diubah'];
                continue;
            }
            $karyawan = DB::table('siapmst_employers')->where('emp_unit_id', $unit->unit_id)
                ->where('active_status', '1')->where('emp_wajib_absen', '1')
                ->orderBy('emp_id')->first(['emp_id', 'emp_name']);

            $nama = 'Supervisor ' . $unit->unit_name;
            $user = User::forceCreate(['name' => $nama, 'myuser_name' => $nama, 'email' => $email, 'password' => Hash::make($sandi),
                'active_status' => '1', 'emp_id' => $karyawan->emp_id, 'email_verified_at' => now()]);
            $user->assignRole('Supervisor Unit');
            $baris[] = [$unit->unit_name, $email, "{$karyawan->emp_id} {$karyawan->emp_name}", 'dibuat'];
        }

        $this->table(['Unit', 'Email', 'Terhubung ke', 'Hasil'], $baris);

        return self::SUCCESS;
    }

    /** "Ruang Jafar" → jafar@siap.com, "Laundry & CSSD" → laundry@siap.com, "Kamar Operasi" → kamaroperasi@siap.com. */
    private static function emailUnit(string $namaUnit): string
    {
        $inti = trim(Str::before(preg_replace('/^Ruang\s+/i', '', $namaUnit), '&'));

        return Str::lower(preg_replace('/[^a-z0-9]/i', '', $inti)) . '@siap.com';
    }
}
