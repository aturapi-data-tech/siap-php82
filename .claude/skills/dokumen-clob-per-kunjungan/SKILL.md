---
name: dokumen-clob-per-kunjungan
description: Pola modul dokumen yang datanya TIDAK menumpang JSON EMR, melainkan tabel sendiri berisi tiga kolom (PK, REG_NO, CLOB JSON) dengan SATU BARIS PER KUNJUNGAN, lalu formulir multi-baris dirakit saat tampil/cetak. Dipakai Pengkajian Medis PP 1.2 (RSTXN_PENGKAJIAN_REVIEWS) & PRMRJ RM.06 (RSTXN_PRMRJS). WAJIB dibaca sebelum membuat modul dokumen yang isinya lintas-kunjungan, perlu tabel sendiri, atau memuat snapshot isi EMR. Beda dari modul-dokumen (entri menumpang datadaftar*_json satu kunjungan) dan emr-multi-entry-document (CPPT/SBAR).
---

# Dokumen ber-tabel sendiri, satu baris per kunjungan

Untuk dokumen yang **tak muat** di JSON EMR satu kunjungan — karena isinya menyangkut
banyak kunjungan, atau perlu dicari lintas pasien. Dua modul memakainya:

| Modul | Tabel | Isi |
|---|---|---|
| Pengkajian Medis PP 1.2 (review & pakai-ulang) | `RSTXN_PENGKAJIAN_REVIEWS` | 1 baris = 1 peninjauan |
| PRMRJ / Profil Ringkas Medis RJ (RM.06) | `RSTXN_PRMRJS` | 1 baris = 1 kunjungan yang diringkas |

> **Pernah dicoba di luar EMR, lalu DITARIK SEMUA.** Tiga modul akreditasi area
> Sistem sempat memakai pola ini dengan `PERIODE` ('MM/YYYY') menggantikan
> `REG_NO` — Pemantauan Suhu & Akses Ruang Server dan Pelaporan Down Time DT-01.
> Ketiganya kini bertabel **DUA kolom** (PK + CLOB), satu baris = satu catatan:
> `RSTXN_SUHUSERVERS`, `RSTXN_AKSESSERVERS`, `RSTXN_DOWNTIMES`. Trait perantaranya
> (`LembarPeriodeTrait`, `PemantauanRuangServerTrait`) ikut dihapus.
>
> **Kenapa gagal cocok:** isinya catatan LEPAS, bukan dokumen berlembar. Satu
> pengukuran / kunjungan / kejadian berdiri sendiri — tak ada isi yang dipakai
> bersama banyak entri, jadi lock & read-modify-write cuma jadi mesin yang tak
> dibutuhkan, dan menghapus satu catatan salah tak boleh menyentuh yang lain.
>
> **Uji sebelum memakai pola ini:** adakah isi yang DIPAKAI BERSAMA banyak entri —
> kop lembar yang berubah tiap periode, TTD yang menutup seluruh lembar, status
> terkunci? Kalau tidak ada, jangan pakai. Identitas yang tetap sepanjang waktu
> bukan "isi bersama" — itu konstanta (`App\Support\Options\RuangServerOptions`),
> dan tanda tangan bisa diteken di kertas lewat garis kosong di cetakan.

Acuan kode: `App\Http\Traits\Txn\Pengkajian\PengkajianReviewTrait`,
`App\Http\Traits\Txn\Prmrj\PrmrjTrait`, `docs/ddl-prmrj.sql`,
`docs/ddl-pengkajian-medis-pp12.sql`.

## 1. Bentuk tabel — TIGA kolom, tidak lebih

```sql
CREATE TABLE RSTXN_<NAMA>S (
    <NAMA>_NO   NUMBER        NOT NULL,   -- PK, dari SEQ_<NAMA>S
    REG_NO      VARCHAR2(10)  NOT NULL,   -- SATU-SATUNYA penyaring lewat SQL
    <NAMA>_JSON CLOB,
    CONSTRAINT PK_<NAMA>S PRIMARY KEY (<NAMA>_NO)
);
CREATE INDEX IDX_<NAMA>_REG ON RSTXN_<NAMA>S (REG_NO);
CREATE SEQUENCE SEQ_<NAMA>S START WITH 1 INCREMENT BY 1 NOCACHE;
```

**Kenapa cuma REG_NO yang datar.** Oracle di sini TIDAK mendukung `JSON_VALUE`
(ORA-00904) — apa pun yang masuk CLOB tak bisa dipakai memfilter, mengurutkan,
maupun meng-indeks. REG_NO datar supaya "ambil dokumen milik pasien ini" cukup satu
query terindeks; sisanya (mencari baris milik KUNJUNGAN tertentu) diselesaikan dengan
men-decode JSON di PHP — aman karena per pasien barisnya sedikit.

**Yang hilang, dan ini keputusan sadar:** laporan lintas-pasien tak bisa lagi dihitung
lewat SQL. Kalau kelak diperlukan, penanda yang mau dihitung HARUS dinaikkan jadi kolom
datar. Tulis konsekuensi ini di komentar DDL-nya.

## 2. SATU BARIS PER KUNJUNGAN — bukan per pasien

Walau formulir kertasnya selembar berisi banyak baris (PRMRJ/RM.06), simpan tetap per
kunjungan; formulirnya DIRAKIT saat tampil/cetak dari semua baris milik `REG_NO`.

Alasannya bukan selera:
- satu baris per pasien = dua poli yang membuka pasien sama harus saling menunggu lock
  pada satu CLOB;
- satu kesalahan tulis merusak SELURUH riwayat pasien.

## 3. Method trait — tiru EmrRJTrait, jangan karang nama baru

```
findData<Nama>($regNo, $jenisKunjungan, $nomorKunjungan) : [?object, array]
lock<Nama>Row($no) : void            // lockForUpdate, di DALAM DB::transaction
updateJson<Nama>($regNo, $jenis, $nomor, array $payload) : void
readJson<Nama>(object $row) : array  // OracleLob::read + JSON_THROW_ON_ERROR
findRiwayat<Nama>($regNo, ?$jenis, ?$nomor) : [array, int]
```

- Bendera encode SAMA dengan `updateJsonRJ`: `JSON_UNESCAPED_UNICODE |
  JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`.
- JSON rusak **dilempar**, bukan jadi array kosong — pada read-modify-write, array
  kosong berarti menimpa isi lama dengan isian kosong.
- Node `dibuat` dipertahankan dari baris lama supaya pembuat pertama tak tertimpa tiap
  draft disimpan ulang.

**Guard tulis wajib** (semuanya pernah gagal sungguhan):
```php
if (blank($regNo))                              throw ...; // cegah ORA-01400
if (! in_array($jenis, ['RJ','RI'], true))      throw ...; // whitelist, bukan else
if ($nomor <= 0)                                throw ...;
```

**Urutan tak bisa dari SQL.** Tanggal ada di CLOB dan berformat `d/m/Y H:i:s` — tak
bisa diurut sebagai string. Ubah ke timestamp di PHP, dengan PK sebagai pemutus supaya
urutannya pasti; yang tak terbaca jatuh ke 0.

## 4. Isi JSON dipisah tiga

```
kriteria  — yang dicentang petugas
otomatis  — SNAPSHOT isi EMR saat disimpan
manual    — yang tak punya padanan di EMR
+ ttd { nama, kode, tanggal }, terkunci, dibuat
```

- **Snapshot, bukan baca ulang saat cetak.** Dokumen ini bertanda tangan: isinya harus
  sama dengan yang dilihat penanda tangan walau EMR-nya kelak dikoreksi. Ini pola
  SNAPSHOT (lihat skill `clause-versioning`), bukan versioning.
- **TTD simpan nama + kode + tanggal saja**, jangan gambarnya. Gambar diambil ulang
  dari `users.myuser_ttd_image` lewat kodenya saat cetak.
- **Daftar pilihan simpan KUNCI, bukan label** — redaksi label boleh diperbaiki tanpa
  merusak record lama. Kunci tak dikenal DIBUANG saat ditampilkan, jangan dicetak mentah.

## ⚠️ Jebakan yang sudah memakan korban

1. **`open()` menimpa snapshot tersimpan.** Kalau `open()` merakit `$otomatis` dari EMR
   lalu `muat<Nama>()` tidak menimpanya dengan isi tersimpan, tiap kali modal dibuka
   ulang suntingan petugas HILANG DIAM-DIAM dan penyimpanan berikutnya membuatnya
   permanen. Wajib: `$this->otomatis = array_replace($this->otomatis, $isi['otomatis'] ?? [])`
   — `array_replace`, supaya record lama yang belum punya kunci baru tetap terisi dari EMR.
2. **`x-toggle` tak bisa mengikat elemen array** seperti `x-check-box`. Kalau daftar
   pilihan dijadikan toggle, state layar harus peta `kunci => bool`; bentuk TERSIMPAN
   tetap daftar kunci, dikonversi bolak-balik di boundary. Penghitung ambang harus
   menghitung `array_filter($peta)`, bukan `count($peta)` — peta berisi 7 kunci mati
   akan lolos sebagai "7 terpilih".
3. **Ambang "N atau lebih" harus ditegakkan saat menyimpan**, bukan cuma tertulis di
   label — kalau tidak, toggle menyala dengan satu butir tercentang dan record lolos.
4. **Stempel TTD dipasang DI DALAM `simpan()` SESUDAH validasi**, jangan di
   `ttdPetugas()` sebelum memanggil simpan: validasi gagal meninggalkan tanda tangan
   menempel di layar padahal tak ada yang tersimpan.
5. **Audit log ditulis ke EMR kunjungan PEMILIK baris** (dari JSON), bukan kunjungan
   yang kebetulan sedang dibuka — kalau tidak, jejaknya nyasar ke rekam medis pasien lain.
6. **`COMMENT ON` jangan memuat titik koma** — pemecah statement per-`;` akan
   memotongnya jadi ORA-01756; em-dash juga tak selalu round-trip di klien Oracle.

## 5. Layar & cetak

- **Riwayat = kartu**, bukan tabel lebar. Belasan kolom memaksa layar menggulung ke
  samping dan tiap sel jadi sempit. Bahasa rupa ikut Pelayanan RJ: `ring-1 ring-hairline
  rounded-2xl shadow-sm`, aksen `border-l-4` untuk baris aktif.
- **Header modal: display pasien DULU, judul dokumen di bawahnya**
  (`display-pasien-rj`) — yang pertama dicari petugas adalah "ini pasien siapa".
- **Tombol ikut pola CPPT** (`rm-cppt-ri-actions`): `x-outline-button` ikon berwarna,
  `wire:confirm` inline (bukan `x-confirm-button`), `title` sebagai penjelas. Biru=lihat,
  amber=cetak, abu=buka kunci, merah=hapus dengan `ml-auto` supaya tak mudah terklik.
  Ukuran ikut baku komponen — jangan `!px-2 !py-1`.
- **Panel panduan = biru-info collapsible, default TERTUTUP** (memory
  `project_panduan_panel_blue_info_standard`).
- **Cetak boleh tetap tabel lebar** — di kertas landscape itu bentuk resmi formulirnya.
  Keterangan yang tak muat jadi kolom (mis. kriteria) taruh sebagai sub-baris `colspan`
  penuh di bawah barisnya, JANGAN catatan kaki bernomor yang memaksa bolak-balik.
- Buka Kunci & Hapus lewat Gate `dokumen.bukaKunci` / `dokumen.hapus`, dijaga DUA lapis
  (blade `@can` + guard server). Jangan role literal.

## 6. Verifikasi sebelum lapor selesai

- `php -l` tiap berkas, `php artisan view:cache` EXIT 0, lalu `view:clear`.
- `Livewire::test(...)->html()` + hitung saldo `<div>` = 0.
- Kelas Tailwind baru WAJIB dicek ada di `public/build/assets/app-*.css` — kelas tak
  terdaftar = CSS kosong, gagal tanpa gejala. (`border-blue-400`, `rounded-tl-2xl`,
  `sm:gap-8` termasuk yang TIDAK ada.)
- Uji bulak-balik simpan → buka ulang → nilai bertahan.

## 7. ⚠️ Membersihkan data uji

**JANGAN `DB::table(...)->delete()` polos.** Tabel ini dipakai user untuk mencoba modul;
delete tanpa syarat ikut menghapus data mereka. Catat PK yang dibuat lalu hapus per nomor
itu saja. Kalau telanjur, Oracle flashback masih menolong sebentar:
`SELECT ... FROM t AS OF TIMESTAMP (SYSTIMESTAMP - INTERVAL '5' MINUTE)` — jendelanya
sempit, di luar itu `ORA-01466`.
