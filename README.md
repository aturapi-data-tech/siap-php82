<p align="center">
  <img src="public/images/logo-sirus.png" width="200" alt="siRUS Logo">
</p>

<p align="center">
  <strong>siRUS – Sistem Informasi Rumah Sakit</strong><br>
  Sistem informasi rumah sakit berbasis web menggunakan Laravel.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12.x-red">
  <img src="https://img.shields.io/badge/PHP-8.2-blue">
  <img src="https://img.shields.io/badge/Livewire-4.x-purple">
  <img src="https://img.shields.io/badge/Database-Oracle-orange">
  <img src="https://img.shields.io/badge/TailwindCSS-3.x-cyan">
</p>

---

## Hierarki Role

`Admin` adalah super user dan dapat melihat seluruh program. Di bawahnya terdapat dua jalur manajerial (**Umum** & **Medis**), ditambah `Casemix` sebagai role **lintas administrasi–medis**.

```
Admin  (super user — akses seluruh program)
│
├── Manager Umum
│   │
│   ├── Supervisor Penunjang
│   │   ├── Gizi
│   │   ├── Apoteker
│   │   ├── Laboratorium
│   │   └── Radiologi
│   │
│   └── Supervisor Tu
│       ├── Tu
│       ├── Gudang Obat
│       └── Gudang Non Medis
│
├── Manager Medis
│   │
│   └── Mr  (Rekam Medis — kelengkapan data medis;
│       │  titik eskalasi koreksi dari Dokter / Perawat)
│       ├── Dokter
│       └── Perawat
│
└── Casemix  ↔  lintas administrasi + medis
    (verifikasi klaim & koding diagnosis — berkoordinasi
     ke Manager Umum maupun Manager Medis)
```

**Peran khusus:**

- **Mr (Rekam Medis)** — bertanggung jawab atas kelengkapan data medis. Bila Dokter atau Perawat melakukan kesalahan entry/dokumentasi, eskalasi koreksi dilakukan melalui Mr.
- **Casemix** — bekerja lintas jalur: sisi administrasi (klaim BPJS, billing) dan sisi medis (verifikasi koding diagnosis / iDRG). Karena itu Casemix tidak ditempatkan eksklusif di bawah Manager Umum atau Manager Medis, melainkan berkoordinasi ke keduanya.

| Level | Role |
|---|---|
| **4 — Super User** | Admin |
| **3 — Manager** | Manager Umum, Manager Medis |
| **2 — Supervisor** | Supervisor Penunjang, Supervisor Tu, Mr, Casemix *(lintas administrasi–medis)* |
| **1 — Fungsional** | Gizi, Apoteker, Laboratorium, Radiologi, Tu, Gudang Obat, Gudang Non Medis, Dokter, Perawat |

> Akses default: setiap role melihat data milik unitnya sendiri. Atasan dapat melihat data seluruh role yang berada di bawah cabangnya. Casemix — meski berada di level supervisor — bekerja lintas jalur sehingga punya akses ke data dari sisi administrasi & medis sesuai kebutuhan klaim dan koding.

---

## Tentang siRUS

**siRUS (Sistem Informasi Rumah Sakit)** adalah aplikasi berbasis web untuk membantu pengelolaan operasional rumah sakit secara terintegrasi, efisien, dan aman.

Aplikasi ini dibangun dengan **Laravel 12** dan **Livewire v4**, menggunakan **Oracle Database** sebagai backend utama, serta **Tailwind CSS + Flowbite (UI only)** untuk tampilan modern.

---

## Fitur Utama

- Manajemen data pasien
- Manajemen pengguna, role & permission
- Autentikasi & otorisasi (Laravel Breeze)
- Dashboard interaktif berbasis Livewire
- Export & import data (Excel)
- Generate laporan PDF
- Integrasi Oracle Database (OCI8)
- UI modern berbasis Tailwind + Flowbite

---

## Teknologi yang Digunakan

### Backend
- Laravel 12
- PHP 8.2
- Livewire v4
- Oracle Database (OCI8)

### Frontend
- Tailwind CSS
- Alpine.js
- Flowbite (UI styling only, tanpa JS Flowbite)
- TinyMCE 8 GPL (rich-text editor + table support — lihat `docs/tinymce-editor-pattern.md`)

### Lainnya
- Laravel Breeze (Blade + Alpine)
- DomPDF (PDF)
- Maatwebsite Excel
- Spatie Permission

---

## Kebutuhan Sistem

- PHP >= 8.2
- Composer
- Node.js & NPM
- Oracle Instant Client 21.x+
- Web Server (Apache / Nginx)

---

## Instalasi

### 1. Clone Repository
```bash
git clone https://github.com/username/sirus.git
cd sirus
