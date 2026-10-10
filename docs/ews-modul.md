# Modul EWS — Early Warning System

Skor peringatan dini dari tanda vital rutin. Dipakai di **Observasi Lanjutan** EMR
Rawat Inap dan UGD: tiap entri TTV diberi skor per parameter, dijumlah, lalu total
menentukan **frekuensi pantau ulang** dan **respon klinis** (siapa yang dihubungi).

Acuan isi: formulir manual RSUD dr. Iskak Tulungagung rev 2024 (RM 93a Dewasa/NEWS2,
93b Anak/PEWS, 93c MEOWS, 93d Neonatus). Bukan versi 5 warna 2018.

---

## 1. Peta berkas

| Lapisan | Berkas |
|---|---|
| DDL Oracle | `docs/ddl-ews.sql` — 3 tabel `RSMST_EWS_PARAMS`, `RSMST_EWS_RENTANGS`, `RSMST_EWS_RESPONS` (tanpa sequence, PK = MAX+1) |
| Isi bawaan | `app/Support/Ews/EwsDefault.php` — satu sumber untuk seed, unit test, dokumentasi |
| Seed | `app/Console/Commands/EwsSeed.php` → `php artisan ews:seed` |
| Pembaca master + cache | `app/Support/Ews/EwsMaster.php` (cache 10 mnt, `flush()` setelah master disimpan) |
| Mesin skor | `app/Support/Ews/EwsSkor.php` — murni input→output, diuji `tests/Unit/EwsSkorTest.php` |
| Master UI | `resources/views/pages/master/master-ews/` (list, actions param+rentang, respon-actions, simulasi) — route `/master/ews` |
| Pemakai | Observasi Lanjutan RI `emr-ri/observasi-ri/observasi-lanjutan-ri/` dan UGD `emr-ugd/observasi/` |
| Tampilan RM | kolom EWS di `rekam-medis/ri/cetak-rekam-medis/asesmen-ri-tab` dan `rekam-medis/ugd/cetak-rekam-medis` |

## 2. Pemasangan (tiap environment)

```bash
# 1. DDL — sebagai pemilik schema SIRUS. Periksa dulu tabel senama belum ada.
#    Jalankan isi docs/ddl-ews.sql (Bagian A boleh ORA-00942 di env bersih).
# 2. Isi awal
php artisan ews:seed              # hanya bila ketiga tabel masih kosong
php artisan ews:seed --force      # kosongkan + isi ulang (kustomisasi hilang)
php artisan ews:seed --dry-run    # ringkasan saja
# 3. Tailwind sudah di-build (kelas oranye/dark); kalau menyentuh kelas baru: npm run build
```

Tanpa DDL, aplikasi tetap jalan: `EwsMaster::muat()` menangkap `QueryException`
→ master kosong → baris EWS di Observasi Lanjutan **tidak muncul**, TTV tetap bisa
disimpan dengan `ews = null`.

## 3. Model data master

**Parameter** (`RSMST_EWS_PARAMS`): `VARIAN` (DEWASA / ANAK / NEONATUS / MEOWS),
`PARAM_KODE` = key JSON sumber nilai di entri (frekuensiNafas, spo2, kesadaran…),
`TIPE` ANGKA / PILIHAN / REFERENSI, `WAJIB`, `GANTIKAN_KODE` (spo2Skala2 → spo2),
`ACTIVE_STATUS` '1'/'0'.

**Rentang** (`RSMST_EWS_RENTANGS`): batas bawah/atas **inklusif** (NULL = tak
terbatas) atau `PILIHAN_KODE`+`PILIHAN_DESC`; `SYARAT` = kode pilihan param lain
yang harus terpilih (SpO2 skala 2 "95-96 on O2" → `O2`); `USIA_MIN_BLN`/`MAX` untuk
baris acuan per usia (PEWS nadi/nafas normal, tipe REFERENSI, tidak diskor).

**Respon** (`RSMST_EWS_RESPONS`): baris cocok bila `total ∈ [SKOR_MIN, SKOR_MAX]`
**atau** (`PARAM_MERAH='1'` dan ada parameter berskor 3). Bila >1 cocok, `URUTAN`
terbesar menang → urutkan ringan→berat. Memberi `KATEGORI`, `WARNA`
(PUTIH/HIJAU/KUNING/ORANYE/MERAH), `FREKUENSI` (teks) + `FREKUENSI_MENIT` (untuk
jatuh tempo pantau ulang), `RESPON`.

Contoh dewasa: 0 putih 12 jam · 1-4 hijau 6 jam · 1 parameter merah kuning 1 jam ·
5-6 oranye 1 jam · ≥7 merah 1 jam. MEOWS ≥7 = 15 menit. Anak/neonatus 0-2 / 3-4 / ≥5.

## 4. Pemilihan varian

`EwsSkor::varianUntukUmur(hari, tahun)`: ≤28 hari NEONATUS, <16 tahun ANAK, selebihnya
DEWASA. MEOWS **dipilih manual** petugas (dropdown di baris EWS). Saat form dibuka
ulang, varian entri terakhir diutamakan supaya pasien obstetri tetap MEOWS.
Umur dihitung dari `rsmst_pasiens.birth_date` (kolom umur di master hanya snapshot).

## 5. Bentuk data di JSON EMR

`observasi.observasiLanjutan.tandaVital[]` — entri lama tetap seperti dulu; entri baru
membawa kunci datar tambahan + hasil skor:

```json
{
  "waktuPemeriksaan": "06/09/2026 10:00:00", "sistolik": 130, "distolik": 80,
  "frekuensiNafas": 26, "frekuensiNadi": 100, "suhu": 36.5, "spo2": 98, "spo2Skala2": null,
  "kesadaran": "A", "oksigen": "ROOM_AIR", "alatOksigen": "",
  "keadaanUmum": "", "kardiovaskular": "", "respirasi": "",
  "nyeri": "", "perdarahan": "", "lochea": "", "produksiUrine": "", "proteinUrine": "", "djj": "",
  "ewsVarian": "DEWASA",
  "ews": {
    "tersedia": true, "varian": "DEWASA",
    "per": { "frekuensiNafas": { "nilai": 26, "skor": 3, "label": "≥ 25", "desc": "Pernafasan" }, "...": {} },
    "total": 4, "adaMerah": true, "lengkap": true, "kurang": [],
    "kategori": "Rendah - Sedang", "warna": "KUNING",
    "frekuensi": "Minimal tiap 1 jam", "frekuensiMenit": 60, "respon": "PJ Shift / Katim ...",
    "pantauUlang": "06/09/2026 11:00"
  }
}
```

Skor dihitung **sekali saat simpan** dan disimpan — cetak/viewer tidak menghitung
ulang, sehingga rekam medis tetap konsisten walau ambang master diubah kemudian.

## 6. Perilaku form Observasi Lanjutan (RI & UGD)

- Baris TTV lama tidak berubah. Di bawahnya baris **Skor EWS**: dropdown varian, badge
  umur, badge nadi/nafas normal per usia (ANAK), lalu field parameter tambahan yang
  **dibangun dari master** (`ewsParamTambahan()`): PILIHAN → select, ANGKA → number.
  Parameter yang sudah ada di baris TTV (nafas, SpO₂, sistolik, diastolik, nadi, suhu)
  tidak diulang.
- Validasi wajib ikut master (`aturanEws()`), bukan hard-code.
- Ganti varian → isian EWS varian sebelumnya dikosongkan (`updatedEwsVarian`).
- Enter: baris TTV berantai seperti semula → field EWS pertama (`id="ews-ri-first"` /
  `ews-ugd-first`) → berantai di dalam baris EWS → field terakhir = simpan.
- Toast sesudah simpan menyebut total, kategori, frekuensi; jadi `warning` bila ada
  parameter merah atau total ≥ 5.
- Tabel riwayat: kolom Kesadaran, O₂, EWS (badge warna + kategori; tooltip skor per
  parameter; tanda "belum lengkap"), Pantau Ulang (jatuh tempo + frekuensi).
- Panel biru "Keterangan skor" (tertutup) memuat tabel respon varian aktif dari master.
- Pratinjau skor di form (chip per parameter + total) dihitung tiap field selesai diisi (`hitungPratinjauEws`, input angka `wire:model.blur`); badge skor per sel di tabel riwayat; badge EWS terakhir di display pasien.

## 7. Master UI `/master/ews`

Tab varian → dua tampilan: **Parameter & Rentang** (form parameter + sub-baris rentang,
disimpan hapus-tulis-ulang per parameter, toggle aktif) dan **Respon Skor**. Tombol
**Simulasi Skor** membuka form nilai untuk varian terpilih dan menampilkan skor per
parameter, total, respon — memakai mesin & master yang sama dengan EMR. Setiap simpan
memanggil `EwsMaster::flush()`.

## 8. Jebakan yang sudah dibayar

- `@if (...) id="..." @endif` **di dalam tag komponen** tidak dikompilasi → ParseError.
  Pakai `:id="$loop->first ? 'x' : null"` (null tidak dirender).
- `@if ...{{ }}@endif` satu baris nempel di dalam `<span>` juga pecah → pindahkan ke
  helper (`EwsSkor::labelRespon`).
- Oracle WE8ISO8859P1: label master & teks log wajib ASCII/Latin-1 (`<= 8`, `SpO2`); ≤ ≥ ₂ ≈ — tersimpan "¿".
- Nilai suhu dibulatkan 1 desimal sebelum dicocokkan supaya 36.04 tidak jatuh ke celah
  36.0–36.1.
- Verifikasi tanpa Oracle: skrip tinker dengan `DB_CONNECTION=sqlite DB_DATABASE=:memory:`,
  tabel stub + `CREATE VIEW rsview_rihdrs AS SELECT * FROM rstxn_rihdrs` (view harus
  benar-benar view, karena `appendAdminLogRI` membaca ulang lewat view) dan
  `sqliteCreateFunction('to_char', ...)`.

## 9. Belum dikerjakan

- Daftar pasien EWS tinggi per ruangan (tim code blue). Badge EWS terakhir di display pasien RI/UGD SUDAH ada (`EwsSkor::terakhirDari`, tanda TERLAMBAT bila jatuh tempo pantau ulang lewat).
- Grafik skor EWS per waktu (grafik UGD masih suhu & nadi).
- RJ tidak punya observasi berseri; belum ada EWS di RJ.
- Cetak PDF Observasi Lanjutan tersendiri ala RM 93a (saat ini kolom EWS ikut di
  viewer rekam medis RI/UGD).
