<p align="center">
  <img src="public/images/logo-siap.svg" width="320" alt="SIAP Logo">
</p>

<p align="center">
  <strong>SIAP – Sistem Informasi Aset &amp; Pegawai</strong><br>
  Aplikasi web untuk absensi pegawai dan pengelolaan aset/alat rumah sakit.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12.x-red">
  <img src="https://img.shields.io/badge/PHP-8.2-blue">
  <img src="https://img.shields.io/badge/Livewire-4.x-purple">
  <img src="https://img.shields.io/badge/Database-Oracle-orange">
  <img src="https://img.shields.io/badge/TailwindCSS-3.x-cyan">
</p>

---

## Tentang SIAP

**SIAP (Sistem Informasi Aset & Pegawai)** membantu unit kepegawaian (HRD) dan IPSRS mengelola
kehadiran pegawai serta inventaris, kalibrasi, pemeliharaan, dan perbaikan alat medis & non medis.
Identitas instansi (logo, nama, alamat) dapat diatur dari aplikasi dan dipakai di kop cetakan.

Dibangun dengan **Laravel 12** dan **Livewire v4**, menggunakan **Oracle Database** sebagai backend,
serta **Tailwind CSS + Flowbite (UI only)** dan **Alpine.js** untuk tampilan.

---

## Fitur

### Absensi
- **Jadwal Dinas** — jadwal masuk kerja bulanan (P / S / M / I / C) + cetak
- **Kiosk Absensi** — layar absen masuk/pulang, ketik/scan ID karyawan
- **Rekap Harian** — kehadiran per tanggal + isi Izin/Sakit/Cuti/Alpa, termasuk lembur
- **Rekap Bulanan** — jumlah H/T/I/S/C/A per karyawan per bulan

### Aset
- **Inventaris Alat** — alat medis & non medis per ruang + status kalibrasi
- **Jadwal Kalibrasi** — program kalibrasi tahunan alat (MFK 7) + cetak PDF
- **Pemeliharaan Alat** — entry & riwayat pemeriksaan harian/mingguan (B / P / R) + cetak checklist
- **Perbaikan Alat** — lapor kerusakan & proses perbaikan (teknisi / vendor) + laporan

### Master
- Karyawan (penanda nakes, status aktif, shift default), Shift, Ruang/Unit

### Sistem
- Identitas Instansi, User Control, Role Control (Spatie Permission)
- Panduan penggunaan Absensi & Aset untuk semua user

---

## Role

Menu ditampilkan sesuai role (definisi tunggal di `app/Services/AppMenu.php`).

| Role | Akses |
|---|---|
| **admin** | Semua menu |
| **manager umum**, **manager medis**, **supervisor penunjang**, **supervisor tu** | Menu Master |
| **hrd** | Jadwal Dinas, Kiosk, Rekap Harian & Bulanan, Master Shift |
| **ipsrs** | Inventaris, Kalibrasi, Pemeliharaan, Perbaikan, Master Ruang |
| **kepala ruang** | Jadwal Dinas, Pemeliharaan & Perbaikan Alat |
| **kiosk** | Layar Kiosk Absensi saja |
| *semua user* | Panduan |

---

## Teknologi

- **Backend:** Laravel 12, PHP 8.2, Livewire v4, Oracle (yajra/laravel-oci8)
- **Frontend:** Tailwind CSS, Alpine.js, Flowbite (styling saja), TinyMCE, Toastr
- **Lainnya:** Laravel Breeze, DomPDF, Spatie Permission, milon/barcode

---

## Kebutuhan Sistem

- PHP >= 8.2 dengan ekstensi OCI8
- Oracle Instant Client 21.x+
- Composer
- Node.js & NPM
- Web server (Apache / Nginx)

---

## Instalasi

```bash
git clone https://github.com/aturapi-data-tech/siap-php82.git
cd siap-php82

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Isi koneksi Oracle dan identitas instansi di `.env` (`DB_HOST`, `DB_SERVICE_NAME`, `DB_USERNAME`,
`DB_PASSWORD`, `IDENTITAS_*`), lalu:

```bash
php artisan migrate
php artisan db:seed --class=ShiftDefaultSeeder
php artisan db:seed --class=RuangDefaultSeeder
php artisan storage:link

npm run build      # atau: npm run dev
php artisan serve
```

> Tabel aplikasi memakai prefix `SIAPMST_` (master) dan `SIAPTXN_` (transaksi).
> Seeder `Dummy*` hanya untuk data contoh di lingkungan pengembangan.

---

## Dokumentasi

Pola komponen & arsitektur ada di folder [`docs/`](docs/). Skill AI (Claude Code) ada di
`.claude/skills/` — indeksnya di [`docs/skills-index.md`](docs/skills-index.md).

---

## Lisensi

[MIT](LICENSE)
