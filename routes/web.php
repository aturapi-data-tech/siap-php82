<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'welcome')->name('home');

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
    Route::livewire('/absensi/rekap-harian', 'pages::absensi.rekap-harian.rekap-harian')
        ->name('absensi.rekap-harian');
    Route::livewire('/absensi/rekap-bulanan', 'pages::absensi.rekap-bulanan.rekap-bulanan')
        ->name('absensi.rekap-bulanan');

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
