---
name: modul-dokumen
description: Pola membuat/mem-port modul dokumen bertanda tangan (consent, surat keterangan, laporan, Pengkajian Akhir Hayat) di EMR — kartu+tombol→modal, siklus Draft→TTD→Kunci→Lihat/Cetak, multi-entri, clause-versioning, PLUS wajib mendaftarkan viewer di display Rekam Medis dan (bila lintas jalur) porting RI⇄UGD⇄RJ, serta kode formulir RM (RM-KK.NN) untuk tiap cetakan baru. WAJIB dibaca sebelum membuat form dokumen baru, memasangnya di jalur lain, atau menambah viewer rekam-medisnya. Beda dari emr-multi-entry-document (CPPT/SBAR): di sini entri ditandatangani pasien/keluarga/saksi/petugas lalu terkunci & dicetak.
---

# Modul Dokumen (formulir bertanda tangan, multi-entri)

Acuan lengkap — **baca sebelum implementasi**:
- `docs/modul-dokumen-ri-pattern.md` — struktur file, siklus entri, validasi, TTD, buka-kunci, port jalur, viewer.
- `docs/dokumen-view-pattern.md` — viewer read-only (Lihat = render blade cetak ke iframe).
- `docs/clause-versioning.md` — teks legal WAJIB versioning (baca sebelum mengubah redaksi klausul).

Contoh kanonik: **Inform Consent RI** (paling lengkap) & **Pengkajian Akhir Hayat** (terbaru,
sudah di RI + UGD, contoh cetak payload bespoke). Beda dari skill `emr-multi-entry-document`
(CPPT/SBAR: banyak PPA + review DPJP) — di sini entri **di-TTD pasien/keluarga/saksi/petugas → terkunci → dicetak**.

> **Bukan pola ini** bila isi dokumen menyangkut BANYAK kunjungan atau perlu tabel
> sendiri (mis. Pengkajian Medis PP 1.2, PRMRJ RM.06) — itu skill
> `dokumen-clob-per-kunjungan`: tabel 3 kolom + CLOB JSON, satu baris per kunjungan.

## Titik sentuh saat menambah SATU dokumen di SATU jalur

1. `…/modul-dokumen/<dok>/rm-<dok>-actions.blade.php` — komponen Volt (form + siklus + cetak).
2. `…/components/modul-dokumen/<jalur>/<dok>/cetak-<dok>-print.blade.php` — cetak PDF.
3. Daftarkan **tab + panel** di `modul-dokumen-<jalur>.blade.php` (`<x-tab>` + `<div x-show>` berisi `<livewire:… :rjNo/riHdrNo :disabled wire:key>`). **WAJIB pasang penanda tab** (lihat aturan #7).
4. **Viewer rekam medis** — `…/rekam-medis/<jalur>/dokumen-view/<dok>-view-<jalur>.blade.php` **dan** daftarkan di `cetak-rekam-medis-open.blade.php`. **Belum selesai tanpa langkah ini.**
5. **Kode formulir RM** — pasang `kode="RM-KK.NN · Rev.N"` di tag layout cetaknya; formulir baru juga ditambahkan ke daftar `/panduan-dev/koding-formulir-rm` (aturan #13). **Belum selesai tanpa langkah ini.**

## Aturan keras (paling sering keliru)

1. **TTD petugas = aksi TERAKHIR yang sekaligus MENGUNCI** (`ttdPetugas()`/`setDokterPenjelas`). JANGAN sediakan tombol "Simpan & Kunci" terpisah — footer cukup **Simpan Draft**. TTD masuk `rules()` supaya error merah di kolomnya. **Stempel petugas yang ditulis ke form SEBELUM `validate()` WAJIB dicabut lagi saat `ValidationException`** (simpan nilai lama → `try { validate } catch (ValidationException) { kembalikan; throw }`), dan **Simpan Draft mengosongkan stempel**: kalau tidak, stempel tersisa di form, tombol TTD hilang (komponen menganggap sudah TTD), lalu draft tersimpan tampak bertanda tangan tapi berstatus Draft (kasus Edukasi Terintegrasi 2026-09-08). Varian **multi-TTD petugas** (Surgical Safety Checklist: Perawat Sirkuler+Dokter Anestesi+Operator, tanpa TTD pasien): `setTtdRole($role)` per peran + kunci OTOMATIS saat semua TTD terisi (`semuaTtdTerisi()`); buka kunci mencabut SEMUA TTD petugas.
2. **Role Buka Kunci & Hapus = SATU SUMBER** `App\Support\AksiRole` (konstanta `BUKA_KUNCI` & `HAPUS`; saat ini triad `Admin | Manager Umum | Manager Medis`). Dipakai via **Gate** `dokumen.bukaKunci` & `dokumen.hapus` (didaftarkan di `AppServiceProvider::boot()`). **JANGAN** tulis `@hasanyrole('Admin|Manager Umum|Manager Medis')`/`hasAnyRole([...])` literal — pakai `@can('dokumen.bukaKunci')`/`@can('dokumen.hapus')` (blade) & `auth()->user()?->can('dokumen.hapus')` (server, guard DUA lapis). Menambah/mengurangi role = ubah 1 file itu, otomatis berlaku di semua modul RI/UGD/RJ. Buka kunci mencabut `finalized` + **TTD petugas saja** (TTD pasien/keluarga & saksi DIPERTAHANKAN); wajib `appendAdminLog<Jalur>(…, 'MR')` menyebut pelakunya.
3. **Tulis data** SELALU via `DB::transaction` + `lock<Jalur>Row` + `updateJson<Jalur>` + audit MR. Muat entri lama dgn `array_replace_recursive(defaultForm(), $entri['form'])` (record legacy aman).
4. **Teks klausul = versioning** (`App\Support\*Clause`, snapshot versi saat TTD). **Pre-fill wajib di-sync di save()** — prop yang tak diedit user tak otomatis masuk array form (hilang di cetak).
5. **Peta label cetak** (skala/checklist/prognosis) → `App\Support\<Dok>Options::labels()` = satu sumber, dipakai form + semua viewer. Jangan diduplikasi.
6. **Baris aksi tabel entri seragam** — DUA BARIS: baris **atas** = aksi non-destruktif (Lanjut Isi/TTD, **Lihat** = `viewEntry(...)` `<x-secondary-button>`, **Cetak** = `cetak(...)` dgn spinner swap `<x-loading/> Mencetak...`); baris **bawah** = `@if (!$isFormLocked)` berisi **Buka Kunci** (`<x-confirm-button action="bukaKunci(...)">` di dalam `@can('dokumen.bukaKunci')`) + **Hapus** (`<x-outline-button wire:click.prevent="hapus(...)" wire:confirm>` di dalam `@can('dokumen.hapus')`) — server: guard `if (!auth()->user()?->can('dokumen.hapus')) { toast; return; }` sebagai statement pertama method hapus. Kontainer: `<div class="flex flex-col items-center gap-2">` membungkus dua `<div class="flex items-center justify-center gap-2">`. Varian fungsional Cetak (`cetakSemua`/`cetakLembar`/`cetakFormA-B`) boleh tetap. Doc: `docs/modul-dokumen-ri-pattern.md`.
7. **Penanda tab (badge "ada data")** — tiap `<x-tab>` di `modul-dokumen-<jalur>.blade.php` WAJIB tampil badge saat dokumennya ada isi, baca dari `$dataDaftar<Jalur>['<jsonKey>']`. Gaya seragam `<x-badge variant="success" class="text-[10px] px-1.5 py-0">…</x-badge>` sebelum `</x-tab>`. Isi badge per tipe: **multi** → `{{ count($key) }}` (guard `@if (count($key ?? []) > 0)`); **single** → `&#10003;` (guard `!empty($key['signature'])`/`['isFinal']`); **dual** (mis. `formMPP`) → `count(formA)+count(formB)`; **umbrella** (tab berisi banyak sub-dokumen, mis. Pelayanan Bedah/VK) → `&#10003;` bila `collect([...childKeys])->first(fn($k) => !empty($dataDaftar<Jalur>[$k]))`. Doc: `docs/modul-dokumen-ri-pattern.md`.

8. **Tampilan baku (BAKU sejak 2026-08-27, berlaku 69 modul)** — susunan modal
   `[ikon · judul · deskripsi · badge · ✕ sebaris]` → `[display pasien]` → `[isi flex-1]` →
   `[footer di LUAR area isi, sticky bottom-0]`; kartu di tab `[judul · badge · deskripsi
   1 baris]` + tabel pratinjau 3 entri terbaru yang **tetap tampil saat kosong**. Tombol
   tutup = anak terakhir baris flex judul dengan `ml-auto shrink-0` — **jangan** mengambang
   (`absolute`), **jangan** diberi baris sendiri, **jangan** di dalam kelompok judul.
   Nama tombol kalimat utuh ("Lanjutkan Pengisian", "Isi Formulir Baru"), judul tanpa
   singkatan, deskripsi > 90 karakter pakai `<x-deskripsi-ringkas>`. Rincian + tabel
   jebakan: `docs/modul-dokumen-ri-pattern.md §2a`.
   **Header modal WAJIB `<x-modul-dokumen.header judul ikon jalur :jumlah :readOnly>deskripsi</…>`**
   (slot `badge` untuk badge khusus) — BAKU 2026-09-15, 71 modal; `:jumlah` dievaluasi langsung, jadi
   ekspresi yang bisa null wajib `?? []`. **Sel Aksi tabel daftar WAJIB `<x-modul-dokumen.aksi-entri
   kunci :final :terkunci …>`** (Gate Buka Kunci/Hapus di dalamnya; method kosong `""` = tanpa tombol;
   rincian docs §2a "Tabel daftar"). **Footer modal dua layar WAJIB `<x-modul-dokumen.footer :formulir
   :terkunci :lihat :mengedit [:bisaSimpan]>petunjuk</…>`** (slot `tombol` untuk Batal Edit/Cetak;
   docs §2a "Modal"). **Kartu di tab WAJIB `<x-modul-dokumen.kartu judul :jumlah satuan :nonaktif>`**
   (slot `deskripsi`/`badge`/`ringkasan`/`tombolLain`, slot bawaan = pratinjau tanpa `mt-*`; docs §2a
   "Kartu di tab"). **Banner status WAJIB `<x-modul-dokumen.banner jenis="terkunci|lihat|lanjut" />`**
   (kondisi @if tetap di modul; teks khusus lewat slot). **Badge status & sel TTD WAJIB
   `<x-modul-dokumen.status-entri :final />` & `<x-modul-dokumen.status-ttd :nama="… ?? ''" [:sudah :waktu gaya]/>`**
   (docs §2a "Tabel daftar"). **Tabel layar daftar WAJIB `<x-modul-dokumen.tabel-daftar :kolom="[…]">` +
   `@empty <x-modul-dokumen.baris-kosong :kolom="N" />`**. Komponen khusus modul dokumen lainnya ditaruh di folder
   `resources/views/components/modul-dokumen/` (nested, `x-modul-dokumen.<nama>`).

9. **Dua layar + nama method baku (BAKU, berlaku SEMUA modul multi-entri)** — `$layar` +
   `diForm()`; `reset*()` ikut menyetel `$layar = 'daftar'` sehingga setiap jalur (simpan
   draft, TTD/kunci, batal, hapus) otomatis balik ke daftar. Method **wajib bernama
   `tambahEntri()` & `kembaliKeDaftar()`** — `periksa-tampilan.php` mencari persis
   `wire:click="tambahEntri"` (layar daftar) dan `wire:click="kembaliKeDaftar"` (layar
   formulir); modul dengan nama sendiri (Case Manager: `tambahFormA`/`cancelEditA`/
   `tutupFormB`) WAJIB menyediakan alias. **Letak `@if ($this->diForm())` = tepat sebelum
   `<fieldset>` formulir** — memasangnya di header modal membuat badge + **display pasien** +
   pembungkus isi ikut hilang di layar daftar (9 modul kena 2026-08-30, dan pemeriksa TETAP
   lolos). Buktikan lewat render: `str_contains($html, 'display-pasien')` di layar daftar.
   Awas positif palsu: `edukasi-terintegrasi-ri` memang sengaja mem-`@if(diForm)` badge "Mode:"
   di header. Doc: `docs/modul-dokumen-ri-pattern.md §2b`.
10. **Layar daftar polos, selebar modal, urut terbaru di atas** — TANPA judul
   `<h3>Daftar … Tersimpan</h3>` dan TANPA baris "Klik baris untuk lihat detail lengkap"
   (judul modul sudah di header modal). Kartu `p-6 … sm:p-8` hanya milik layar formulir:
   tempelkan di `<fieldset>` atau buat kelasnya bersyarat `$this->diForm()`; `max-w-5xl`
   dilarang (pakai `max-w-full`). Urutan tabel: `collect($daftar)->sortByDesc(fn($entri) =>
   strtotime(strtr(($entri['tanggal'] ?? '') ?: ($entri['createdAt'] ?? ''), '/', '-')))
   ->values()->all()` — **jangan** `array_reverse` (itu urutan simpan, bukan tanggal), jangan
   `Carbon::createFromFormat` (exception → modal 500 bila ada satu entri berformat menyimpang),
   jangan `Carbon::parse` (menebak m/d/Y). Doc: `docs/modul-dokumen-ri-pattern.md §2a & §2c`.

11. **Tabel daftar = bentuk Edukasi Terintegrasi (BAKU sejak 2026-09-08, 63 modul)** —
   tanpa kolom No; kolom pertama panah rincian (`<tbody x-data="{ open: false }">`,
   `<tr @click="open = !open">`, `<tr x-show="open">` berisi `<dl>` ringkasan); kolom
   `Tanggal · ringkasan khas · Petugas (TTD) · Status · Aksi`; Aksi dua baris: Lanjutkan
   Pengisian (primary) / Lihat = **`<x-lihat-button>` ikon saja** / Cetak = **`<x-cetak-button>` ikon saja** (bukan
   tombol berteks, bukan info/primary),
   lalu **garis pemisah** `│` Buka Kunci (`x-confirm-button variant="warning-soft"`, kuning) `│`
   **`<x-hapus-button>`** paling kanan — SATU baris rata kanan (`justify-end`), kelompok berisiko
   `pl-3 ml-1 border-l` hanya dirender bila user berhak; sel Aksi `whitespace-nowrap`; footer layar daftar berkalimat "Setiap entri berdiri
   sendiri — Isi Formulir Baru … Lanjutkan Pengisian …". Daftar kartu-baris `<div>` per
   entri dilarang. Salin dari `edukasi-terintegrasi-ri`, jangan bikin varian. Doc: §2a
   "Tabel daftar".

12. **Tanda tangan (keputusan 2026-09-08)** —
   (a) **Saksi WAJIB saat kunci** di SEMUA dokumen ber-saksi (`saksiNama`/`saksi` + `signatureSaksi`
   `required`): inform consent RJ/UGD/RI, penolakan obat & resusitasi, pulang APS, akhir hayat,
   form penjaminan; identifikasi bayi RI nama saksi perawat `required`. Jangan `nullable`.
   (b) **Stempel petugas di layar = `x-signature.ttd-petugas`** (`:framed="false"` + `:locked="true"`
   pada keadaan sudah TTD) — kotak putih gambar TTD rata atas + field nama readonly sejajar
   kolom pasien/saksi. **Kartu stempel bespoke** (div nama/Kode/tanggal rata tengah) DILARANG;
   14 modul sudah diganti. Gambar TTD user lewat `x-signature.ttd-gambar :code` /
   `:empId`, sumber `App\Support\TtdUser` (dua format kolom `myuser_ttd_image`).
   (b2) **Tata letak kolom TTD (standar 2026-09-15)** — grid `grid-cols-1 gap-6 md:grid-cols-2|3`,
   tiap kolom `flex flex-col`: judul kolom uppercase rata tengah → **kotak gambar TTD langsung
   di bawahnya** (`signature-result` / `signature-pad` / `ttd-petugas`) → field nama/hubungan →
   baris teks `Waktu TTD:` (gaya `text-sm`, label `text-muted`, nilai `font-semibold`).
   DILARANG: teks/tanggal di ATAS kotak (dulu `signature-result :date` → kotak turun, kini
   komponen menaruh tanggal di bawah) dan pembungkus `min-h-* flex justify-center` di sekitar
   kotak (kotak mengambang tak sejajar antar kolom), kolom berupa **kartu berbingkai**
   (`p-3 border rounded-lg`) atau `ttd-petugas :framed="true"` di dalam grid TTD, dan stempel
   petugas bespoke (div nama/kode/tanggal) — selalu `ttd-petugas :framed="false"`. Judul kolom boleh
   lewat prop `label` ttd-petugas (sudah bergaya judul standar, rata tengah). Petugas tanpa berkas
   gambar TTD tetap mendapat kotak berukuran sama berisi "Gambar tanda tangan tidak tersedia"
   (`ttd-gambar teksKosong`). Diseragamkan 2026-09-15 di 17 berkas: General Consent, Pra-Anestesi,
   Pre-Op, Surgical Safety Checklist (RJ/UGD/RI), Akhir Hayat RI/UGD, Edukasi Terintegrasi RI,
   Surat Kematian RI/UGD. Contoh rapi: General Consent RJ/UGD/RI.
   (c) **Cetak/viewer**: path gambar TTD WAJIB `TtdUser::pathBerkasDariKode($kode)` — jangan
   `public_path('storage/' . $nilai)` sendiri (format baru nama-file-saja gagal `file_exists`).
   (d) **Kolom "Petugas (TTD)" di tabel & viewer hanya menampilkan nama bila `entryIsFinal()`**;
   draft → badge "Belum TTD" walau data lama membawa stempel tertinggal.
   (e) **Model kunci tetap Model 1** (TTD petugas = aksi terakhir + kunci; pasien/saksi = isian
   pad). Model 2 (multi-PPA, tiap TTD tersimpan langsung, kunci otomatis saat lengkap:
   Surgical Safety Checklist, Pra-Anestesi) hanya untuk dokumen yang memang butuh urutan bebas.

13. **Kode formulir RM (BAKU sejak 2026-09-14, akreditasi MRMIK 3h & 6)** — tiap cetakan
   formulir rekam medis menulis kodenya **langsung & lengkap** di tag layout:
   `<x-pdf.layout-a4-with-out-background kode="RM-05.11 · Rev.0" title="…">` — literal, bukan
   `:kode="…"`, pemisah `" · "` (salin dari blade lain: `grep -rh 'kode="RM-' resources/views
   --include='*-print.blade.php' | head -1`). **Tanpa config** — prop `kode` di `layout-a4`,
   `layout-a4-with-out-background`, `layout-kwitansi` dicetak apa adanya, fixed di pojok
   kiri bawah SETIAP halaman (margin bawah, tak menimpa isi), berpasangan dengan
   "Dicetak: dd/mm/yyyy hh:mi:ss oleh <myuser_name>" di pojok kanan bawah (layout A4). **Itulah satu-satunya footer cetakan
   formulir RM** — JANGAN menulis footer "Dicetak • No. RM • nama/alamat RS" sendiri di blade cetak
   (RS sudah di kop, No. RM di identitas pasien; 32 footer bespoke dihapus 2026-09-15). Info khas
   entri (mis. "ID Form" MPP) boleh tetap di blade. Cetakan bukan formulir RM (kuitansi, etiket, SEP/PRB/SKDP,
   slip gaji, dokumen unit IT) tidak dikode.
   Daftar kelompok & nama formulir ada di `/panduan-dev/koding-formulir-rm` (method `formulir()`
   di SFC-nya); kolom View & Rev di halaman itu dibaca dari atribut blade.
   (a) **Port jalur lain = pakai kode yang SUDAH ADA** (dengan revisinya), bukan kode baru — satu
   formulir satu kode di RJ/UGD/RI. (b) Formulir baru = nomor berikutnya di kelompoknya + tambah
   baris di `formulir()` panduan. (c) **Revisi naik** hanya bila rancangan berubah (butir isian
   ditambah/diubah/dihapus), di SEMUA blade berkode itu, di commit yang sama; bug tampilan tidak.
   (d) **Kode tak pernah dihapus/dipakai ulang** — formulir dihentikan: nama di panduan diberi
   akhiran " (nonaktif)".

## Port ke jalur lain (RI ⇄ UGD ⇄ RJ)

Salin actions + cetak, ganti token **per-string** (bukan `RI→UGD` global). Tabel lengkap di
`docs/modul-dokumen-ri-pattern.md §9`. Ringkas: trait `EmrRITrait→EmrUGDTrait`; prop
`?string $riHdrNo → ?int $rjNo`; `dataDaftarRi → dataDaftarUGD` (RJ: `dataDaftarPoliRJ`, BUKAN `dataDaftarRj`); `findDataRI/checkEmrRIStatus/updateJsonRI/appendAdminLogRI/lockRIRow → …UGD`;
key JSON `pengkajian<Dok>RI→…UGD`; modal/area `-ri→-ugd`; tag display-pasien ganti **UTUH termasuk prefix namespace**:
`pages::transaksi.ri.display-pasien-ri.display-pasien-ri → pages::transaksi.ugd.display-pasien-ugd.display-pasien-ugd` (jebakan nyata: cuma sufiks yang terganti, sisa `transaksi.ri.` tetap kompilasi tapi salah jalur — sapu `grep -rn "pages::transaksi\.ri\." resources/views/pages/transaksi/{rj,ugd}/`).
Folder/file UGD/RJ **buang sufiks** `-ri`, tapi modal-name/renderArea/nama PDF **tetap** `-ugd`/`-rj`.

## Viewer rekam-medis: payload seragam vs bespoke

- **Seragam** (dataRi/form/ttd): pakai `DokumenViewSupportTrait::previewDokumenRi()/streamCetakDokumenRi()` langsung.
- **Bespoke** (cetak butuh `entry`+`opsiLabel`+`clause`, mis. Akhir Hayat): viewer **self-contained** — `pasienDokumen/identitasRsDokumen/renderDokumenPreview` + `TtdUser::pathBerkasDariKode` + `buildData()` yang meniru `cetak()`; `opsiLabel` dari `App\Support\<Dok>Options::labels()`.

## Verifikasi (WAJIB sebelum lapor selesai)

- **Tampilan**: `php .claude/skills/modul-dokumen/periksa-tampilan.php` → EXIT 0. Skrip ini
  membaca **HTML hasil render**, bukan isi berkas: keseimbangan tag di kedua layar, posisi
  tombol tutup lewat DOM, kelengkapan tombol footer, dan keterangan tabel saat kosong.
  Pelajaran mahal: `grep`/pemeriksa statis pernah meloloskan 10 berkas yang `@if`-nya
  membungkus header (tag "seimbang" tapi salah sarang), dan render satu komponen sendirian
  pernah meloloskan kelebihan `</div>` yang baru meledak saat bersarang di komponen payung.
- **`php artisan view:cache`** → EXIT 0 (pipeline Blade asli), lalu `php artisan view:clear`.
  Jangan andalkan `Blade::compileString` untuk file host rekam-medis — banyak yang tak
  standalone-compilable (bandingkan dgn `git HEAD`: gagal identik = pre-existing).
- **Kode formulir**: cetakan baru muncul di baris kodenya pada `/panduan-dev/koding-formulir-rm`
  (tanpa kotak "Perlu dibenahi"), tidak nyasar di "Cetakan Tanpa Kode". Tanpa DB:
  `grep -rL 'kode="RM-' resources/views --include='*-print.blade.php'`.
- `grep` tidak ada token jalur asal yang nyasar di file hasil port.
- Ikuti skill `blade-safe-edit` saat sed/regex pada `*.blade.php` (edit fresh copy, token presisi).
