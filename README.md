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

## Hak akses (Role)

Pola sama dengan SIRUS & SIKEU: **siapa boleh apa diatur di Sistem › Role Control** dengan mencentang hak per role.

- **Definisi hak** ada di `app/Akses/Program/<Kelompok>/` — satu berkas per program (menu + hak *Lihat halaman*
  `{route}.lihat` + aksi khusus seperti `gaji.kelola`, `absensi.kelola`), urutan program di `_kelompok.php`.
- **Keputusan** satu pintu: `App\Support\AksesHalaman` — dipakai menu (sidebar & dashboard), penjaga URL + aksi
  Livewire (`CekAksesMenu`) dan Gate aksi (`@can` / `->can()`). Role **Admin** selalu boleh semua.
- **Permission dibuat otomatis** pada request pertama sesudah deploy (`App\Support\SinkronAksesOtomatis` →
  `php artisan akses:sinkron`); manual: `php artisan akses:sinkron --dry-run`. Centang awal = nama role di berkas
  program, dipakai **sekali** saat hak dibuat; sesudahnya yang berlaku centang Role Control. Role baru yang dibuat
  belakangan (mis. "IPSRS") **tidak** otomatis dapat akses dari namanya — centang hak-nya dulu.
- Route grup `menu` yang belum didefinisikan: `AKSES_HALAMAN_MODE=log` (bawaan) diizinkan + dicatat di
  `storage/logs/akses-halaman-*.log`; `tolak` = 403.

Centang awal (sebelum diubah di Role Control):

| Role | Akses |
|---|---|
| **admin** | Semua menu & aksi |
| **manager umum**, **manager medis**, **supervisor penunjang**, **supervisor tu** | Menu Master |
| **hrd** | Master Karyawan/Unit/Shift/Lokasi, Jadwal Dinas, Kiosk, Rekap Harian & Bulanan, Pengaturan Absensi, Laporan Kepegawaian |
| **manager keuangan** | Penggajian, Rekap Harian & Bulanan, Master Karyawan (lihat) |
| **supervisor unit** | Jadwal Dinas, Rekap Bulanan + nilai lembur (unitnya) |
| **ipsrs** | Inventaris, Kalibrasi, Pemeliharaan, Perbaikan, Master Ruang |
| **kepala ruang** | Jadwal Dinas, Pemeliharaan & Perbaikan Alat, Indikator Mutu |
| **komite mutu** | Indikator Mutu + kunci kertas kerja |
| **kiosk** | Layar Kiosk Absensi saja |
| *semua user* | Absen HP, Panduan Absensi & Aset |

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
