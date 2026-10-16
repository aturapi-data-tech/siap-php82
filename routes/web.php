<?php

use App\Http\Controllers\FotoAbsensiController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'welcome')->name('home');

// Absen HP — publik (tanpa login): karyawan ketik ID sendiri + GPS + selfie.
Route::livewire('/absensi/hp', 'pages::absensi.absen-hp.absen-hp')
    ->name('absensi.hp');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth'])->group(function () {
    // Daftarkan modul SIAP di sini (absensi, kepegawaian, aset), lalu tambahkan
    // entri menunya di App\Services\AppMenu::definitions().

    // ── MASTER ──
    Route::livewire('/master/karyawan', 'pages::master.master-karyawan.master-karyawan')
        ->name('master.karyawan');

    Route::livewire('/master/shift', 'pages::master.master-shift.master-shift')
        ->name('master.shift');

    Route::livewire('/master/unit-kerja', 'pages::master.master-unit-kerja.master-unit-kerja')
        ->name('master.unit-kerja');

    Route::livewire('/master/lokasi-absen', 'pages::master.master-lokasi-absen.master-lokasi-absen')
        ->name('master.lokasi-absen');

    Route::livewire('/master/ruang', 'pages::master.master-ruang.master-ruang')
        ->name('master.ruang');
    Route::livewire('/master/alat', 'pages::master.master-alat.master-alat')
        ->name('master.alat');

    // ── ASET ──
    Route::livewire('/aset/jadwal-kalibrasi', 'pages::aset.jadwal-kalibrasi.jadwal-kalibrasi')
        ->name('aset.jadwal-kalibrasi');
    Route::livewire('/aset/pemeliharaan-alat', 'pages::aset.pemeliharaan-alat.pemeliharaan-alat')
        ->name('aset.pemeliharaan-alat');
    Route::livewire('/aset/perbaikan-alat', 'pages::aset.perbaikan-alat.perbaikan-alat')
        ->name('aset.perbaikan-alat');

    // ── ABSENSI ──
    Route::livewire('/absensi/jadwal-dinas', 'pages::absensi.jadwal-dinas.jadwal-dinas')
        ->name('absensi.jadwal-dinas');
    Route::livewire('/absensi/kiosk', 'pages::absensi.kiosk-absensi.kiosk-absensi')
        ->name('absensi.kiosk');
    Route::get('/absensi/foto/{absId}/{jenis}', FotoAbsensiController::class)
        ->whereNumber('absId')->whereIn('jenis', ['masuk', 'pulang'])
        ->name('absensi.foto');
    Route::livewire('/absensi/pengaturan', 'pages::absensi.pengaturan-absensi.pengaturan-absensi')
        ->name('absensi.pengaturan');
    Route::livewire('/absensi/rekap-harian', 'pages::absensi.rekap-harian.rekap-harian')
        ->name('absensi.rekap-harian');
    Route::livewire('/absensi/rekap-bulanan', 'pages::absensi.rekap-bulanan.rekap-bulanan')
        ->name('absensi.rekap-bulanan');

    // Keuangan — hak akses dicek di komponen (Gate gaji.kelola, AksiRole::GAJI_KELOLA)
    Route::livewire('/keuangan/gaji-karyawan', 'pages::keuangan.gaji-karyawan.gaji-karyawan')
        ->name('keuangan.gaji-karyawan');

    // ── PANDUAN (cara pakai, semua user) ──
    Route::livewire('/panduan/absensi', 'pages::panduan.panduan-absensi.panduan-absensi')
        ->name('panduan.absensi');
    Route::livewire('/panduan/aset', 'pages::panduan.panduan-aset.panduan-aset')
        ->name('panduan.aset');

    // ── SISTEM ──
    Route::livewire('/sistem/identitas', 'pages::sistem.identitas-instansi.identitas-instansi')
        ->name('sistem.identitas');
    Route::livewire('/sistem/user-control', 'pages::sistem.user-control.user-control')
        ->name('sistem.user-control');
    Route::livewire('/sistem/role-control', 'pages::sistem.role-control.role-control')
        ->name('sistem.role-control');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
