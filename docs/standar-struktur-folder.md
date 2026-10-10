# Standar Struktur Folder & Penempatan Berkas

Aturan **di mana sebuah berkas harus tinggal** dan **bagaimana ia dinamai** — untuk `app/` dan
`resources/views/`. Tujuannya satu: programmer baru bisa menebak lokasi berkas tanpa `grep`, dan
programmer lama tidak perlu memutuskan ulang tiap kali menambah modul.

Dokumen ini **melengkapi**, bukan menggantikan:
- `docs/standar-master-module.md` — isi & anatomi modul master (2 file `⚡list` + `⚡actions`)
- `docs/modul-dokumen-ri-pattern.md` — isi modul dokumen bertanda tangan
- `docs/page-frame-pattern.md`, `docs/standar-ui-komponen.md` — markup di dalam berkas

Di sini kita hanya bicara **pohon direktori dan nama berkas**.

> **Versi web (tutorial interaktif, 11 bab):** route `/panduan-dev/koding-struktur`.
> Isinya sama dengan dokumen ini; buka yang mana saja. Kalau salah satu diubah, ubah keduanya.

> Hasil audit 2026-08-13 (dasar dokumen ini): 1.021 berkas Blade — **691 SFC Volt** (berkelas
> `new class extends Component`) + **330 partial** markup murni; `app/` 128 berkas PHP
> (68 trait, 38 support); `routes/web.php` 146 `Route::livewire`.

---

## 1. Prinsip

| # | Prinsip | Konsekuensi praktis |
|---|---|---|
| P1 | **Satu modul = satu folder.** | Semua berkas milik satu layar hidup berdampingan; tidak ada berkas modul yang nyempil di folder induk. |
| P2 | **Nama folder = nama modul = nama berkas utama = namespace event.** | `master-agama/` → `⚡master-agama.blade.php` → event `master.agama.*`. Tiga-tiganya harus sinkron. |
| P3 | **Lokasi mengikuti PEMILIK, bukan pemakai.** | Cetakan dokumen RI tinggal di area rekam-medis/dokumen (dipakai banyak layar), bukan di dalam `emr-ri/` (satu pemakai). |
| P4 | **`⚡` menandai komponen, ketiadaannya menandai partial.** | Terlihat dari `ls` mana yang punya state dan mana yang cuma markup. |
| P5 | **Kebab-case, Bahasa Indonesia, tanpa singkatan baru.** | Akronim domain yang sudah baku (`ri`, `rj`, `ugd`, `emr`, `lov`, `sep`, `idrg`) tetap dipakai apa adanya. |
| P6 | **Kedalaman maksimum 4 level di bawah `pages/`.** | `pages/transaksi/ri/emr-ri/modul-dokumen/<modul>/` sudah 5 — lihat §4.3 untuk pengecualian resmi. |

---

## 2. Peta direktori resmi — `resources/views/`

```
resources/views/
├── components/                  # KOMPONEN BLADE ANONIM  <x-...>  — TANPA state, TANPA kelas Volt
│   ├── <nama>.blade.php         #   umum lintas modul: x-modal, x-text-input, x-now-button
│   └── <namespace>/             #   berkelompok: x-list.*, x-pdf.*, x-signature.*, x-lov.*
│
├── layouts/                     # layouts::app, layouts::guest, layouts::app-fullscreen
│
├── livewire/                    # KOMPONEN LIVEWIRE LINTAS-MODUL (dipanggil <livewire:...>)
│   └── lov/<entitas>/lov-<entitas>.blade.php     # 55 LOV — acuan konsistensi terbaik di repo
│
└── pages/                       # HALAMAN & KOMPONEN MILIK HALAMAN (namespace `pages::`)
    ├── components/              #   komponen pages LINTAS-JALUR (dipakai >1 area) — §3.3
    │   ├── modul-dokumen/       #     cetakan + modal dokumen  (bpjs/, ri/, rj/, ugd/)
    │   ├── rekam-medis/         #     viewer & cetakan rekam medis (ri/, rj/, ugd/, penunjang/)
    │   └── manajemen/           #     cetakan laporan manajemen
    ├── <area>/                  #   area: master, transaksi, manajemen, keuangan…
    │   └── <modul>/             #     satu layar = satu folder
    │       ├── ⚡<modul>.blade.php
    │       ├── ⚡<modul>-actions.blade.php
    │       └── <modul>-<bagian>.blade.php
```

**Aturan tegas — tiga tempat komponen, tiga tujuan berbeda:**

| Folder | Isinya | Cara dipanggil | Boleh punya kelas Volt? |
|---|---|---|---|
| `components/` | komponen presentasi anonim | `<x-nama>` / `<x-ns.nama>` | ❌ tidak — kalau butuh state, ia bukan penghuni sini |
| `livewire/` | komponen ber-state **lintas modul** (LOV, dialog global) | `<livewire:lov.dokter>` | ✅ wajib |
| `pages/` | halaman + komponen ber-state **milik halaman** | `Route::livewire` / `<livewire:pages::…>` | ✅ (kecuali partial `@include`) |

Uji cepat sebelum menaruh: *“Apakah ia punya state/`wire:model`?”* → tidak = `components/`.
*“Apakah dipakai lebih dari satu area?”* → ya = `livewire/`, tidak = `pages/<area>/<modul>/`.

---

## 3. Kontrak penamaan berkas

### 3.1 Prefix `⚡` — wajib, dan artinya tunggal

> **`⚡` = berkas ini SFC Volt berkelas** (`new [#[Layout]] class extends Component`).
> **Tanpa `⚡` = partial markup murni** yang di-`@include` (tanpa blok `<?php new class`).

Ini konvensi native Livewire 4 dan sudah disetel di `config/livewire.php`
(`make_command.emoji => true`), jadi setiap `php artisan make:livewire` sudah patuh otomatis.

Kenapa aturan ini yang dipilih (bukan “`⚡` hanya untuk halaman ber-route”):
- Saat audit, invariannya **sudah benar satu arah**: dari 142 berkas ber-`⚡`, **0** yang bukan SFC.
  Tidak ada yang perlu dibatalkan — hanya perlu dilengkapi (dikerjakan 2026-08-13, §8 item 3).
- Ia menjawab pertanyaan yang paling sering muncul saat maintenance: *“ini komponen atau include?”*
  — sekarang jawabannya terlihat dari `ls`, tanpa membuka berkas.
- `⚡` **tidak** ikut dalam string resolusi Livewire (`pages::master.master-obat.master-obat` cocok
  dengan `⚡master-obat.blade.php`), jadi menambah/melepas prefix **tidak memutus referensi** —
  selama referensinya lewat resolver Livewire, bukan view finder (lihat pengecualian di bawah).

Keadaan sekarang: **691 SFC ber-`⚡`** (semua), **0 SFC tanpa `⚡`**, **0 partial** ber-`⚡`.

**Tidak ada pengecualian.** Sempat ada 3: `lov-poli`, `lov-diag-kep`, `lov-asuhan-keperawatan`
punya `render()` eksplisit yang memanggil view-nya sendiri **by name**
(`return view('livewire.lov.poli.lov-poli')`) — jalur itu memakai **view finder**, bukan resolver
Livewire, sehingga `⚡` akan memutusnya. `render()` itu mubazir (SFC merender template-nya sendiri;
32 LOV lain tidak punya, ketiganya sisa porting dari komponen berkelas), jadi dihapus lebih dulu
lalu berkasnya di-rename — 2026-08-13, §8 item 3b.

> Pelajaran polanya: kalau sebuah SFC memanggil `view('<nama-dirinya>')`, ia mengikat nama berkas
> ke view finder dan membuat berkasnya tidak bisa di-rename. Jangan tulis `render()` di SFC kecuali
> ia mengembalikan view yang **berbeda** dari dirinya.

Cek kepatuhan (dua-duanya harus kosong):

```bash
# Harus kosong: SFC Volt yang belum ber-⚡
cd resources/views && for f in $(find . -name '*.blade.php' ! -name '⚡*'); do
  grep -qE '^new .*class extends' "$f" && echo "KURANG ⚡: $f"; done

# Harus kosong: berkas ber-⚡ yang ternyata partial
for f in $(find . -name '⚡*.blade.php'); do
  grep -qE '^new .*class extends' "$f" || echo "SALAH ⚡: $f"; done
```

### 3.2 Suffix peran berkas

| Suffix | Peran | SFC (`⚡`) atau partial? | Sebaran nyata |
|---|---|---|---|
| *(tanpa suffix)* = nama modul | LIST / layar utama modul | **SFC** | — |
| `-actions` | modal create/edit + semua `validate()`/insert/update/delete | **SFC** | 200 SFC, 0 partial |
| `-tab` | isi satu tab dari layar bertab | **partial** | 0 SFC, 56 partial |
| `-view` | penampil read-only (viewer rekam medis) | **partial** | 0 SFC, 8 partial |
| `-print` | badan cetak dompdf (dirender via layout `x-pdf.layout-*`) | **partial** | 0 SFC, 88 partial |
| `-<bagian>` | pecahan section dari berkas yang kebesaran (§5) | **partial** | — |

Perhatikan: **hanya layar utama dan `-actions` yang jadi komponen.** Semua yang lain partial —
konsisten dengan §5: memecah berkas dilakukan dengan `@include`, bukan dengan menambah komponen
Livewire anak (tiap komponen anak = satu round-trip + satu titik race Alpine/morph). Jadi `-tab`
BUKAN komponen per tab; ia markup tab yang state-nya tetap di induk.

Tab yang jumlahnya banyak dikumpulkan di subfolder `tabs/`:
`pages/transaksi/<jalur>/emr-<jalur>/<section>/tabs/<nama>-tab.blade.php` (8 folder memakai pola ini).

Suffix di luar tabel ini **tidak dibuat baru**. Prefix `_` untuk menandai partial **tidak dipakai**
(peran partial sudah dinyatakan oleh absennya `⚡`).

### 3.3 Prefix area khusus

| Prefix | Arti | Lokasi |
|---|---|---|
| `rm-` | kartu + modal satu dokumen rekam medis di dalam EMR | `pages/transaksi/<jalur>/emr-<jalur>/modul-dokumen/<modul>/` |
| `cetak-` | pembungkus cetak (tombol/modal) berpasangan dengan `-print` | `pages/components/modul-dokumen/<jalur>/<modul>/` |
| `lov-` | list-of-value | `livewire/lov/<entitas>/` |

### 3.4 Suffix jalur — wajib untuk SEMUA modul-dokumen, di folder DAN nama berkas

**Setiap** folder & berkas di bawah `emr-<jalur>/modul-dokumen/` menyandang jalurnya — bukan hanya
yang kebetulan ada di lebih dari satu jalur. Alasannya: RI sudah begitu di 38/38 folder, dan patokan
“hanya kalau multi-jalur” akan memaksa 23 folder RI **dilepas** suffix-nya — arah yang salah,
sekaligus menghilangkan petunjuk “ini EMR yang mana” saat berkas dibuka sendirian di editor.

**Satu pengecualian: jangan stutter.** Kalau nama dokumen sudah memuat jalurnya, tidak ditambah lagi
— `form-trf-ugd-ri/` tetap apa adanya, bukan `form-trf-ugd-ri-ugd/`, dan berkasnya tetap
`⚡rm-form-trf-ugd-ri-actions.blade.php`.

Contoh:

```
pages/transaksi/ri/emr-ri/modul-dokumen/pengkajian-pre-op-ri/⚡rm-pengkajian-pre-op-ri-actions.blade.php
pages/transaksi/rj/emr-rj/modul-dokumen/pengkajian-pre-op-rj/⚡rm-pengkajian-pre-op-rj-actions.blade.php
pages/transaksi/ugd/emr-ugd/modul-dokumen/pengkajian-pre-op-ugd/⚡rm-pengkajian-pre-op-ugd-actions.blade.php
```

Alasannya bukan estetika: ketiga berkas ini **berbeda isi** (tabel sumber, kolom, guard), sering
dibuka bersamaan, dan tanpa suffix ketiga tab editor bernama identik. Ketiga jalur sudah patuh
(RI 38/38, RJ 13/13, UGD 18/18 — §8 item 4 selesai 2026-08-13).

Berlaku juga untuk partial di dalamnya: `suket-rj/tabs/suket-sehat-rj-tab.blade.php` — jalur
ditulis **sebelum** suffix peran (`-rj-tab`, bukan `-tab-rj`), dan kumpulan tab memakai folder
`tabs/` seperti §3.2.

### 3.5 Akronim di nama folder

Akronim ditulis **utuh dan huruf kecil**: `ri`, `rj`, `ugd`, `bpjs`, `ok`, `emr`.
**JANGAN** memecahnya per huruf — pola `r-i/`, `u-g-d/`, `b-p-j-s/`, `o-k-ri.blade.php` adalah
artefak konversi otomatis dari PascalCase (`RI` → `r-i`), bukan keputusan desain.

### 3.6 Nama berkas ≠ kunci data

Aturan kebab-case berlaku untuk **nama berkas**, bukan untuk nilai yang sudah tersimpan di DB.
Bila sebuah pengenal dipakai ganda — sebagai nama berkas DAN sebagai nilai yang dipersistensi —
yang boleh diubah hanya nama berkasnya; nilai tersimpan tetap apa adanya, dan penurunan
nama berkas dilakukan eksplisit di kode.

Contoh nyata: `<x-site-marking-diagram>`. `id` panel (`priaFront`) tersimpan sebagai
`marks[].view` di record pengkajian pre-op. Berkas figurnya sudah dikebab-kan menjadi
`pria-front.blade.php`, sementara `id`-nya **tetap** camelCase; komponen menurunkan nama berkas
lewat `\Illuminate\Support\Str::kebab($p['id'])`. Mengubah `id` = menghilangkan tanda operasi pada
record lama. Cek pertanyaan ini sebelum me-rename apa pun: *“apakah nama ini pernah ditulis ke DB?”*

> Status data per 2026-08-13: belum ada satu pun record pengkajian pre-op tersimpan
> (RI 8.976 baris ber-JSON, UGD 37.604, RJ 167.003 — nol yang memuat `pengkajianPreOp`/`marks`,
> modulnya memang baru). Jadi risikonya masih **laten**, bukan kerusakan yang sudah terjadi.
> Aturannya tetap berlaku: begitu petugas mulai mengisi, `id` tidak boleh lagi disentuh.

---

## 4. Aturan penempatan per area

### 4.1 `pages/master/` — sudah baku

Ikuti `docs/standar-master-module.md` apa adanya: `master-<nama>/` + 2 berkas `⚡`.
Acuan kanonik `master-agama`.

### 4.2 `pages/transaksi/<jalur>/` — jalur pelayanan

```
pages/transaksi/{rj,ugd,ri,ri-resep}/
├── daftar-<jalur>/          # pendaftaran (LIST + *-actions per integrasi: vclaim, idrg, satu-sehat)
├── daftar-<jalur>-bulanan/
├── pelayanan-<jalur>/
├── administrasi-<jalur>/    # 1 berkas per pos biaya: room-ri, visit-ri, konsul-ri, …
├── eresep-<jalur>/
├── emr-<jalur>/
│   ├── emr-<jalur>.blade.php      # shell EMR + tab
│   ├── <tab>-<jalur>/             # pengkajian-awal-ri, penilaian-ri, observasi-ri, cppt-ri, …
│   └── modul-dokumen/<modul>-<jalur>/
├── idrg/  satu-sehat/  task-id-pelayanan/     # integrasi eksternal
└── display-pasien-<jalur>/
```

Satu berkas per pos biaya di `administrasi-*` adalah pola yang benar dan dipertahankan — pos biaya
tumbuh terus, dan tiap pos punya tarif + audit log sendiri.

### 4.3 `pages/manajemen/` — laporan

Dua pola hidup berdampingan dan **keduanya sah**, dengan batas tegas:

| Pola | Kapan | Contoh |
|---|---|---|
| `manajemen/<modul>/` | laporan lintas unit / hub dashboard | `indikator-pelayanan`, `mutasi-obat`, `laporan-diagnosa` |
| `manajemen/<sumber>/<unit>/<modul>/` | laporan yang **format & regulatornya** menentukan bentuk | `manajemen/sirs/ri/laporan-rl-3-2-rawat-inap`, `manajemen/rs/penunjang/lab/laporan-nilai-kritis` |

Level `<sumber>` hanya boleh salah satu dari daftar tertutup: `rs`, `sirs`, `vclaim`.
`<unit>`: `rj`, `ugd`, `ri`, `penunjang`, `tu`. Ini satu-satunya tempat kedalaman 5 diizinkan
(pengecualian resmi atas P6), karena penamaan RL sudah ditentukan Kemkes.

### 4.4 `pages/components/` — komponen pages lintas-jalur

Isi: cetakan (`-print` + pembungkus `cetak-*`), viewer dokumen (`*-view-<jalur>`), dan modal yang
dipanggil dari beberapa layar sekaligus. Struktur:

```
pages/components/<domain>/<jalur>/<modul>/<berkas>.blade.php
       domain: modul-dokumen | rekam-medis | manajemen
       jalur : bpjs | ri | rj | ugd   (atau kelompok fungsi: etiket, penunjang)
```

Sebuah berkas naik ke sini **hanya** kalau pemakainya >1 area. Kalau cuma dipakai satu layar, ia
tinggal di folder modulnya (P3).

---

## 5. Batas ukuran berkas

Melanjutkan `standar-master-module.md` §7, digeneralisasi ke semua area:

| Jenis | Ideal | Wajib pecah di |
|---|---|---|
| LIST / layar utama | ≤ 300 baris | > 600 |
| `-actions` (form/modal) | ≤ 400 baris | > 800 |
| `-print` | ≤ 500 baris | > 900 |
| Trait / class `Support` | ≤ 400 baris | > 700 |

Cara pecah: **partial per section logis** (`<modul>-<bagian>.blade.php`, di-`@include`) — markup
murni, state tetap di induk. Bukan dengan menambah komponen Livewire anak, karena tiap komponen
anak menambah satu round-trip dan satu titik race Alpine/morph.

**Dua syarat batas partial**, dua-duanya wajib dicek sebelum memecah:

1. **Imbang tag** — `<div>`, komponen `<x-*>`, `@if`, `@foreach`, dan penanda komentar Blade harus
   imbang DI DALAM partial. Batas yang enak dibaca belum tentu imbang. (Awas menghitung komponen
   self-closing multi-baris: `<x-text-input\n … />` — lookahead `/>` tidak melewati newline, jadi
   regex naif mengiranya tag pembuka.)
2. **Rekonstruksi byte-eksak** — induk dengan tiap `@include` diganti kembali oleh isi partial-nya
   harus sama byte-per-byte dengan berkas asli. Ini invarian **tekstual**, jadi ia tidak bergantung
   pada apakah suatu cabang `@if` kebetulan ikut dirender saat diuji — kelemahan yang dimiliki
   verifikasi berbasis render.

3. **Impor kelas ikut dipindah** — dan ini yang paling mudah terlewat.
   **Partial `@include` dikompilasi ke berkas TERPISAH, jadi ia TIDAK mewarisi `use` dari blok
   kelas induk.** Ia mewarisi **variabel**, bukan **import**. Begitu blok yang diekstrak memanggil
   nama kelas pendek (`PraAnestesiOptions::…`), partial-nya meledak `Class not found`.

   Perbaikannya: tambah directive `@use('App\Support\Options\PraAnestesiOptions')` di kolom 0 pada
   partial (pola yang dipakai 12 berkas repo, mis. `components/consent/*`) — **bukan**
   `@php use …; @endphp` yang dilarang di berkas komponen.

> **Syarat 2 tidak menjamin syarat 3.** Teks identik ≠ scope import identik — dan render komponen
> `-actions` sendirian pun bisa lolos, karena cabang yang memanggil kelas itu hanya hidup kalau
> induk mengirim props. Yang menangkapnya: pemeriksa #8 (sapu halaman utuh) dan pemeriksa nama
> kelas pendek di partial. Ini kejadian nyata di `rm-pra-anestesi-*-sistem-organ` (commit `94ba1652`).

Yang berubah setelah pecah hanyalah **baris kosong**: `@include` menelan newline di sekitarnya.
Tidak berpengaruh pada tampilan karena letaknya antar-blok.

**Batas ini hanya berlaku untuk MARKUP.** Berkas yang besar karena blok kelas Volt-nya (mis.
`⚡daftar-rj-actions` — 1.330 dari 1.679 baris adalah kelas) tidak terbantu oleh pemecahan partial;
yang perlu dikurangi kelasnya (pisah ke trait/Support), dan itu keputusan desain per modul.

Sebaran saat ini: 22 berkas 1.501–3.000 baris, 106 berkas 801–1.500. Kandidat pecah terbesar:
`rm-pengkajian-pre-op-*-actions` (1.781 × 3 jalur), `rm-akhir-hayat*-actions` (1.714 × 2),
`⚡daftar-rj-actions` (1.679), `vclaim-ri-actions` (1.661).

---

## 6. Peta direktori resmi — `app/`

```
app/
├── Console/Commands/          # perkakas: MakeLov, BersihkanCacheSnomed
├── Http/
│   ├── Controllers/           # HANYA sisa Breeze (auth, profile). Fitur baru = Volt SFC, bukan controller
│   ├── Middleware/  Requests/
│   └── Traits/                # mixin untuk komponen Livewire — §6.1
├── Models/                    # Eloquent hanya untuk tabel milik Laravel (users, cache SNOMED).
│                              # Tabel Oracle warisan diakses via Query Builder — JANGAN bikin model baru untuk RSMST_*/RSTXN_*
├── Providers/  Services/
├── Support/                   # class stateless, dipanggil statis — §6.2
└── View/Components/           # AppLayout, GuestLayout
```

### 6.1 `app/Http/Traits/<Grup>/<Nama>Trait.php`

Trait = **mixin ber-state** yang di-`use` oleh kelas Volt (boleh menyentuh `$this`, `dispatch()`,
properti komponen). Grup level-1 adalah **daftar tertutup**:

| Grup | Isi | Contoh |
|---|---|---|
| `Concerns/` | lintas-komponen, bukan domain | `WithRenderVersioningTrait`, `WithValidationToastTrait` |
| `BPJS/` `SATUSEHAT/` `SIRS/` `IDRG/` | klien API eksternal, satu trait per resource/layanan | `VclaimTrait`, `EncounterTrait` |
| `Txn/<Jalur>/` | logika transaksi per jalur | `Txn/Ri/EmrRITrait` |
| `Manajemen/<Sumber>/<Unit>/` | query laporan, cermin §4.3 | `Manajemen/Sirs/Ri/RL32Trait` |
| `Master/<Modul>/` | logika master berat | `Master/MasterPasien/MasterPasienTrait` |
| `<Area>/` | area domain lain | `Dokumen/`, `Keuangan/`, `Stock/` |

Bukan daftar tertutup — grup baru boleh lahir untuk area domain baru. Yang menentukan penghuni
`Traits/` bukan nama grupnya, melainkan **satu uji**: trait menyentuh `$this` (properti komponen,
`dispatch()`, `validate()`). Kalau tidak, ia bukan mixin dan tempatnya di `Support/` (§6.2).

Nama grup ditulis PascalCase atau akronim huruf besar utuh (`IDRG`, bukan `iDRG`). Folder yang
namanya sama dengan satu-satunya berkas di dalamnya (`WithRenderVersioning/WithRenderVersioningTrait.php`)
adalah nesting mubazir — isinya masuk `Concerns/`.

### 6.2 `app/Support/<SubNamespace>/<Nama>.php`

Support = **class stateless**, semua method `static`, tidak tahu-menahu soal Livewire. Saat ini 35
dari 38 berkas menumpuk rata di akar folder sehingga kategorinya tak terlihat. Sub-namespace resmi:

**Aturan pembentukan: sub-namespace dibuat HANYA bila anggotanya ≥ 2.** Folder berisi satu berkas
menambah kedalaman tanpa memberi informasi — biarkan ia di akar `App\Support`. Karena itu yang
dibentuk cuma 4 kelompok (+ `Downtime/` yang sudah ada), bukan satu folder per domain.

| Sub-namespace | Isi | Penghuni |
|---|---|---|
| `Clause/` (6) | teks legal berversi (lihat `docs/clause-versioning.md`) | `GeneralConsentClause`, `AkhirHayatClause`, `KerohanianClause`, `PenjaminanClause`, `PenolakanObatClause`, `SuratKematianClause` |
| `Options/` (9) | daftar opsi & skala formulir EMR | `NyeriOptions`, `GiziOptions`, `AkhirHayatOptions`, `PraAnestesiOptions`, `SafetyPlanOptions`, `PermintaanDarahOptions`, `DischargePlanningOptions`, `EdukasiTerintegrasiOptions`, `SurveilansHaisOptions` |
| `Terminologi/` (8) | pemetaan kode standar (SNOMED/KFA/LOINC/ICD/FHIR) | `AlergiSnomed`, `ObatKfa`, `RacikanKfa`, `MedicationRequestItem`, `PenilaianObservationMap`, `ObservasiLanjutanMap`, `DischargeDisposition`, `KodeIm` |
| `GajiDokter/` (2) | modul slip gaji dokter | `GajiDokter`, `GajiDokterLampiran` |
| `Downtime/` (2) | formulir & tarif waktu henti | `FormulirDowntime`, `TarifDowntime` |
| *(akar)* (11) | pembantu tunggal per domain — nama sudah menjelaskan dirinya | `OracleLob`, `Terbilang`, `LogText`, `EresepJson`, `AdmisiPulangRI`, `DpjpUtamaRI`, `KelasKamar`, `KamarOperasiTarif`, `AksiRole`, `NomorSuratKematian`, `SatuSehatMonitor` |

> **Jangan mengandalkan resolusi satu-namespace antar kelas Support.** Sebelum penataan ini ada 7
> tempat yang memanggil `Foo::` tanpa `use` maupun FQCN, mengandalkan keduanya kebetulan berada di
> `App\Support`. Tiga di antaranya langsung putus saat salah satunya pindah — dan putusnya **senyap**,
> baru meledak ketika jalur kode itu dijalankan. Tulis `use` eksplisit, selalu.

**Batas Trait vs Support** (pertanyaan yang paling sering salah dijawab): butuh `$this` /
`dispatch()` / properti komponen → Trait. Murni input→output → Support. Kalau sebuah trait tidak
pernah menyentuh `$this`, ia salah tempat.

---

## 7. Routing & URL

```php
Route::livewire('/<area>/<modul>', 'pages::<area>.<modul>.<modul>')->name('<area>.<modul>');
```

Tiga hal harus sejalan: **segmen URL pertama = folder area = prefix nama route.**

Kondisi sekarang tidak sejalan — semua layar transaksi tinggal di `pages/transaksi/`, tapi URL-nya
tersebar ke 15 prefix berbeda: `/transaksi/*` (14), `/keuangan/*` (13), `/gudang/*` (7), `/ri/*` (5),
`/rawat-jalan/*` (4), `/ugd/*` (3), `/operasi/*`, `/jadwal-kontrol`. RJ memakai `/rawat-jalan/` padahal
RI dan UGD memakai akronim. Standar untuk route **baru**:

| Area view | Prefix URL | Prefix nama route |
|---|---|---|
| `pages/master/` | `/master/` | `master.` |
| `pages/transaksi/<jalur>/` | `/<jalur>/` (`rj`, `ugd`, `ri`, `ri-resep`) | `<jalur>.` |
| `pages/transaksi/<fungsi>/` | `/<fungsi>/` (`keuangan`, `gudang`, `apotek`, `kasir`, `casemix`, `penunjang`) | `<fungsi>.` |
| `pages/manajemen/` | `/manajemen/` | `manajemen.` |
| `pages/database-monitor/` | `/database-monitor/` | `database-monitor.` |

Nama modul **tidak mengulang jalurnya di URL**: `/ri/update-tt`, bukan `/ri/update-tt-ri`
(nama *berkas* tetap `update-tt-ri` sesuai §3.4 — yang diringkas hanya URL).

URL yang sudah live **tidak diubah tanpa alasan** — ia ada di bookmark & pintasan petugas. Perubahan
prefix hanya lewat langkah §8, disertai redirect lama→baru.

---

## 8. Backlog penyeragaman (audit 2026-08-13)

Semua item di bawah **hanya pindah/rename berkas**, tanpa perubahan logika. Urut dari yang paling
aman. Tiap langkah: `git mv` → jalankan pemeriksa §3.1 → `Livewire::test()` render tiap komponen
tersentuh (**bukan** `view:cache` — ia tidak menangkap galat kelas Volt, lihat skill
`blade-safe-edit`) → commit terpisah per langkah.

| # | Item | Volume | Risiko | Catatan |
|---|---|---|---|---|
| ✅ 1 | `erm-<jalur>.blade.php` → `emr-<jalur>.blade.php` | 3 berkas | 🟢 | **SELESAI 2026-08-13** — 3 rename + 3 tag `<livewire:>` + 28 penyebutan di komentar/docs |
| ✅ 2 | Hapus prefix `_` : `_patient-detail`, `_breakdown-dokter` | 2 | 🟢 | **SELESAI 2026-08-13** — jadi `penunjang-detail-pasien` & `pendapatan-rs-rincian-dokter` (pola `<modul>-<bagian>`), 6 `@include` disesuaikan |
| ✅ 3 | `⚡` untuk SFC Volt yang belum punya | 546 | 🟢 | **SELESAI 2026-08-13** — 546 rename |
| ✅ 3b | 3 LOV: hapus `render()` mubazir lalu rename | 3 | 🟡 | **SELESAI 2026-08-13** — output render dibandingkan byte-per-byte dgn baseline (identik sesudah `wire:id`/`snapshot`/id Alpine acak dinormalkan). Kini 691/691 patuh, tanpa pengecualian |
| ✅ 4 | Suffix jalur folder+berkas modul-dokumen RJ & UGD | 32 folder | 🟡 | **SELESAI 2026-08-13** — 32 folder + 11 berkas + 34 referensi. Termasuk `suket/tab/` → `suket-<jalur>/tabs/` dan 4 partial tab yang tadinya bernama identik di dua jalur. `form-trf-ugd-ri` dikecualikan (anti-stutter, §3.4) |
| ✅ 5 | 7 `rm-*-actions` UGD tanpa suffix `-ugd` | 7 | 🟡 | **SELESAI** — bagian dari item 4 |
| ✅ 6 | Folder akronim `r-i/ r-j/ u-g-d/ b-p-j-s/` → `ri/ rj/ ugd/ bpjs/` | 7 folder, 184 berkas | 🟡 | **SELESAI 2026-08-13** — 363 referensi dalam **tiga** bentuk penulisan: dotted (`components.modul-dokumen.r-i.`), path (`components/modul-dokumen/r-i/`), dan path singkat tanpa prefix `components/` di docs |
| ✅ 7 | `app/Support` → sub-namespace §6.2 | 25 berkas | 🟡 | **SELESAI 2026-08-13** — 25 pindah ke 4 kelompok, 11 tetap di akar (aturan ≥2 anggota), `Diagnosa/KodeIm` → `Terminologi/KodeIm`, 217 FQCN + 3 `use` eksplisit yang tadinya implisit |
| ✅ 8 | `Traits/WithRenderVersioning`, `Traits/WithValidationToast` → `Traits/Concerns/` | 2 | 🟡 | **SELESAI 2026-08-13** — 347 rujukan |
| ✅ 9 | `Traits/iDRG` → `Traits/IDRG` | 1 folder | 🟡 | **SELESAI 2026-08-13** — 59 rujukan. Nama trait `iDrgTrait` sendiri TIDAK diubah (di luar lingkup pemfolderan; kalau mau PSR-1 penuh, itu pekerjaan terpisah) |
| 🟡 10 | Pecah berkas > 1.500 baris (§5) | 22 → **11 sisa** | 🔴 | **SEBAGIAN 2026-08-13** — 11 berkas dipecah jadi 53 partial: 5 halaman panduan-dev (per kelompok bab), pengkajian-pre-op ×3 & pra-anestesi ×3 (per section formulir). **Sisa 11 belum**: bulk-nya blok kelas Volt, bukan markup (mis. `daftar-rj-actions` 1.330 dari 1.679 baris = kelas). Memecah markup saja tidak menurunkannya ke bawah ambang — yang perlu dikurangi kelasnya, dan itu keputusan desain per modul |
| ✅ 11 | Seragamkan prefix URL (§7) + redirect lama | 20 route | 🔴 | **SELESAI 2026-08-13** — 20 route + 20 `Route::redirect` (302, bukan 301) supaya bookmark petugas tetap jalan. Termasuk anti-stutter `/ri/update-tt-ri` → `/ri/update-tt`. Nama route ikut berubah; `transaksi.rj.` sengaja TIDAK disapu buta karena 167 kemunculannya adalah nama KOMPONEN |
| ✅ 12 | `site-marking/figs/*.blade.php` camelCase → kebab-case | 16 | 🟢 | **SELESAI 2026-08-13** — `footDorsumKanan` → `foot-dorsum-kanan`. Istilah anatomi (dorsum/palm) DIPERTAHANKAN; `id` panel tidak ikut diubah, lihat §3.6 |

Item 1–3 dan 12 sudah dikerjakan 2026-08-13. Item 10–11 bukan pekerjaan pemfolderan dan tidak perlu
dipaksakan sekarang.

### Hasil verifikasi render (2026-08-13, Oracle hidup)

Sesudah keempat item di atas, dengan DB hidup: **0 gagal**.

- `Livewire::test()` mount 12 komponen terdampak (host EMR RI/RJ/UGD, induk daftar-ri &
  pelayanan-rj/ugd yang tag `<livewire:>`-nya diedit, modal tambah lab & upload radiologi,
  pendapatan-rs, pengkajian pre-op ×3) — semuanya render, tanpa penanda komentar bocor.
- `View::exists()` untuk 2 `@include` yang di-rename → ada; 2 nama lamanya → **tidak** ada.
- Resolusi 690 referensi komponen lewat `Livewire\Finder\Finder` asli
  (`app('livewire.finder')`, **bukan** `app(Finder::class)` — yang terakhir memberi instance baru
  tanpa namespace sehingga semuanya tampak gagal): 683 ter-resolve, 7 sisanya prosa komentar,
  placeholder tutorial `koding-master`, template generator `MakeLov`, dan route ter-komentar.
- `<x-site-marking-diagram>` diuji per-panel di jalur layar **dan** PDF dengan `id` camelCase,
  plus kontrol negatif (`view` tak dikenal → panel tidak digambar).

Uji dilakukan mount-only tanpa memanggil aksi, dan dipastikan tidak menulis apa pun
(0 baris baru di `api_log_status`/`web_log_status` selama pengujian).

### Cara memverifikasi tanpa DB

Saat Oracle mati, aplikasi tidak bisa boot (`AppServiceProvider::boot()` → `Gate::define` menarik
permission dari DB), jadi `Livewire::test()` tidak tersedia. Dua pemeriksa berikut tetap jalan dan
sudah dipakai untuk memvalidasi item 1–3 & 12:

1. **Kompilasi Blade standalone** — bootstrap Laravel hanya sampai tahap *register*
   (`LoadConfiguration` + `RegisterFacades` + `RegisterProviders`, **lewati `BootProviders`**), ambil
   `blade.compiler` dari container, `compileString()` tiap berkas lalu `php -l` hasilnya.
   Wajib memisahkan blok kelas SFC (sampai `\n?>`) sebelum mengompilasi templatnya — kalau tidak,
   `<x-...>` yang cuma disebut di komentar `//` blok kelas akan disulih jadi kode komponen dan
   memunculkan parse error palsu (terjadi di 5 berkas saat baseline pertama).
2. **Resolusi referensi komponen** — kumpulkan semua `<livewire:NAMA` dan argumen kedua
   `Route::livewire(...)`, lalu petakan ke berkas via `component_namespaces` +
   `component_locations` dengan `⚡` opsional di segmen terakhir. Harus 0 yang gagal.
   Catatan: 5 “kegagalan” yang wajar & permanen = 3 placeholder tutorial di `koding-master`
   (`master-<nama>`, `master-pekerjaan`), 1 penyebutan dalam komentar di `⚡upload-radiologi`,
   dan 1 route yang di-komentar di `web.php` (`pages::master.poli.index`).

Sesudah rename massal, hapus isi `storage/framework/views/` secara manual (`view:clear` butuh boot).

### Pemeriksa #8 — sapu halaman utuh lewat HTTP kernel (yang paling menentukan)

Butuh DB hidup, dan **inilah pemeriksa yang paling dekat dengan kenyataan**: middleware + layout +
seluruh komponen anak ikut dirender, termasuk cabang yang hanya hidup kalau induk mengirim props.

```php
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
auth()->loginUsingId(1);
foreach ($routeGetTanpaParameter as $uri) {
    $res = $kernel->handle(Illuminate\Http\Request::create('/'.$uri, 'GET'));
    if ($res->getStatusCode() >= 500) { /* laporkan */ }
}
```

Dua hal praktis:
- **Potong per-batch** (±20 halaman/proses). 164 halaman berat (2–4 MB HTML masing-masing)
  menghabiskan memori kalau dirender dalam satu proses.
- **Pisahkan yang bukan salahmu.** Buktikan lewat git per berkas
  (`git log <base>..HEAD -- <berkas>`), jangan diasumsikan. Saat sapuan pertama, 5 halaman 500 dan
  **nol** disebabkan 18 commit penyeragaman: 4× `ORA-00904` karena kolom DDL memang belum ada di
  env ini (`RSTXN_RJRADS.TGL_BACAAN`, `LBMST_CLABITEMS.CRITICAL_LOW_M`), 1× PHP 8.2 melarang akses
  konstanta trait langsung (`RL41Trait::AGE_GROUPS_RL41`).

### Pemeriksa #9 — nama kelas pendek di dalam partial

Menangkap pelanggaran syarat 3 di §5. Untuk tiap berkas **tanpa** blok kelas Volt, cari rujukan
`NamaKelas::` bernama pendek yang tidak punya import lokal dan tidak ada di namespace global —
kalau basename-nya cocok dengan sebuah kelas `App\*`, itu akan meledak saat dirender.

Tiga jebakan yang membuat pemeriksa ini melaporkan berkas sehat sebagai rusak (semuanya sudah
kejadian, dan bikin daftar temuan turun 27 → 16 → 10):
- Lupa mengenali directive **`@use('App\Foo')`** — 12 berkas repo memakainya.
- Regex `use` yang menuntut kolom 0, padahal `use` sering **berindentasi di dalam blok `@php`**.
- Nama kelas yang muncul sebagai **prosa** — di komentar Blade, atau sebagai teks contoh di halaman
  tutorial `/panduan-dev`. Dari 10 temuan terakhir, 7 adalah prosa.

---

## 9. Checklist saat menambah modul baru

- [ ] Satu folder baru di `pages/<area>/`, nama kebab-case Indonesia, = nama berkas utama
- [ ] Berkas utama + `-actions` ber-`⚡`; partial tanpa `⚡`
- [ ] Ada suffix jalur bila modul hidup di >1 jalur (§3.4)
- [ ] Route: segmen URL pertama = folder area = prefix nama route (§7)
- [ ] Namespace event = nama folder (`standar-master-module.md` §2)
- [ ] Cetakan/viewer yang dipakai >1 layar naik ke `pages/components/<domain>/<jalur>/`
- [ ] Logika stateless → `app/Support/<SubNamespace>/`; mixin komponen → `app/Http/Traits/<Grup>/`
- [ ] Tidak ada berkas > batas §5 sejak lahir
- [ ] Tidak menambah model Eloquent untuk tabel Oracle warisan
- [ ] Dua pemeriksa §3.1 kembali kosong

---

## 10. Referensi

| Apa | Di mana |
|---|---|
| Anatomi isi modul master | `docs/standar-master-module.md` |
| Anatomi modul dokumen bertanda tangan | `docs/modul-dokumen-ri-pattern.md`, skill `modul-dokumen` |
| Frame halaman & tabel full-height | `docs/page-frame-pattern.md` |
| Komponen UI & tombol | `docs/standar-ui-komponen.md`, `docs/standar-komponen-tombol.md` |
| Konvensi penamaan variable/method & `use` | skill `naming-conventions` |
| Keselamatan rename/edit massal Blade | skill `blade-safe-edit` |
| Generator LOV | `app/Console/Commands/MakeLov.php` |
| Konfigurasi lokasi komponen & `⚡` | `config/livewire.php` (`component_locations`, `component_namespaces`, `make_command.emoji`) |
