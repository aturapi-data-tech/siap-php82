<?php

namespace App\Support;

/**
 * SUMBER TUNGGAL daftar role untuk aksi yang dibatasi — DIKLASTER per area.
 *
 * Menggantikan penulisan literal berulang di tiap berkas:
 *
 *   SEBELUM  @hasanyrole('Admin|Manager Umum|Manager Medis')      (6 tempat, mudah melenceng)
 *   SESUDAH  @can('emr.logAktivitas')                             (1 sumber)
 *
 * Konstanta dikonsumsi lewat Gate yang didaftarkan di
 * App\Providers\AppServiceProvider::boot(). Pemakaian:
 *   - Blade  : @can('dokumen.hapus') ... @endcan
 *   - Server : auth()->user()?->can('dokumen.hapus')
 *
 * ATURAN PENTING — SATU KONSTANTA PER MAKSUD, BUKAN PER DAFTAR ROLE.
 * Dua aksi yang kebetulan punya daftar sama TETAP dipisah konstantanya, supaya
 * kelak bisa diberi role berbeda tanpa menyentuh kode pemanggil. Contoh nyata:
 * DOKUMEN_HAPUS dan EMR_LOG_AKTIVITAS sekarang isinya sama, tapi yang pertama
 * aksi destruktif dan yang kedua hanya melihat audit log — menggabungkannya
 * berarti menaikkan hak lihat log setiap kali kebijakan hapus dilonggarkan.
 *
 * MENAMBAH KLASTER BARU:
 *   1. tambah konstanta di sini (beri prefix area),
 *   2. daftarkan Gate-nya di AppServiceProvider,
 *   3. ganti @hasanyrole literal di blade dengan @can, DAN
 *   4. pastikan guard SERVER di method-nya ikut memakai ->can() — guard blade
 *      saja bisa ditembus karena wire:click memanggil method publik.
 *
 */
class AksiRole
{
    /* ─────────────── KEUANGAN ─────────────── */

    /** Role yang boleh membuka & mengelola gaji karyawan (parameter, slip, cetak). Data gaji = rahasia. */
    public const GAJI_KELOLA = ['Admin', 'Manager Keuangan'];

    /* ─────────────── KEPEGAWAIAN ─────────────── */

    /** Role yang boleh MEMBUKA Laporan Kepegawaian (hari kerja, cuti, lembur per bulan). Keuangan ikut membaca & menerapkan ke slip. */
    public const LAPORAN_PEGAWAI_LIHAT = ['Admin', 'HRD', 'Manager Keuangan'];

    /** Role yang boleh MENGISI Laporan Kepegawaian (siapkan baris, isi hari kerja / cuti / lembur). Terapkan ke slip tetap GAJI_KELOLA. */
    public const LAPORAN_PEGAWAI_ISI = ['Admin', 'HRD'];

    /** Role yang boleh mengubah data Master Karyawan (termasuk Wajib Absen & STR/SIP). Manager Keuangan hanya melihat + parameter gaji. */
    public const KARYAWAN_KELOLA = ['Admin', 'HRD'];

    /* ─────────────── ABSENSI ─────────────── */

    /** Role yang melihat absensi SEMUA unit. Selain ini (Supervisor Unit) dikunci ke unit karyawan yang terhubung ke akunnya. */
    public const ABSENSI_LIHAT = ['Admin', 'HRD', 'Manager Keuangan'];

    /** Role yang boleh mengubah Rekap Harian: izin, sakit, cuti, koreksi jam, Generate Libur/Alpa. */
    public const ABSENSI_KELOLA = ['Admin', 'HRD'];

    /** Role yang boleh menilai lembur dihitung / tidak. Supervisor Unit hanya untuk unitnya. */
    public const LEMBUR_NILAI = ['Admin', 'HRD', 'Supervisor Unit'];

    /* ─────────────── MUTU ─────────────── */

    /** Role yang boleh mengunci / membuka kunci kertas kerja indikator mutu satu bulan (validasi Komite Mutu). */
    public const MUTU_KUNCI = ['Admin', 'Komite Mutu'];

    /* ─────────────── SISTEM ─────────────── */

    /** Role yang boleh membuka Panduan Struktur Database & mengunduh SQL struktur (detail teknis skema). */
    public const STRUKTUR_DB = ['Admin'];

    /* ─────────────── CONTOH ─────────────── */

    // /** Role yang boleh MENGHAPUS entri dokumen. */
    // public const DOKUMEN_HAPUS = ['Admin', 'Manager Umum', 'Manager Medis'];
}
