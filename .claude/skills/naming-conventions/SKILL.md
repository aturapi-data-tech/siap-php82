---
name: naming-conventions
description: Standar penamaan variable/method & aturan import (use vs FQCN) di repo ini. Baca sebelum menulis kode PHP/Livewire/Volt baru — terutama saat menamai variable untuk konsep domain (risiko jatuh, dll.) atau menambahkan pemakaian class seperti Carbon di file Volt.
---

# Naming Conventions & Imports

## 1. Singkatan modul sudah "dipesan" — jangan dipakai untuk makna lain

Di repo ini singkatan berikut SELALU berarti modul/unit, bukan yang lain:

| Singkatan | Artinya | BUKAN |
|---|---|---|
| `rj` / `$rj` | Rawat Jalan | risiko jatuh |
| `ri` / `$ri` | Rawat Inap | — |
| `ugd` | Unit Gawat Darurat | — |
| `rm` | Rekam Medis / No. RM | — |

Contoh kasus nyata: variable risiko jatuh sempat dinamai `$rjList`, `$rjKategori` — di file RJ, `$rj` adalah data rawat jalan → tabrakan makna, ditolak user.
Terulang 2026-08-03 di Rekam Medis: `$rjRec` (maksudnya daftar entri resiko jatuh) berada
di berkas RJ → dibenahi jadi `$resikoJatuhList`, sekalian `$txn`→`$dataDaftarTxn`,
`$idn`→`$identitas`, `$tv`→`$tandaVital`, item loop `$x`/`$n`/`$g`→`$entri`/`$entriNyeri`/`$entriGizi`.

**Aturan:** konsep domain ditulis LENGKAP, camelCase bahasa Indonesia, ikut idiom field JSON-nya:
`$resikoJatuhTerakhir`, `hitungResikoJatuhTerakhir()`, `$kategoriResiko`, `$tglPenilaian`.

Variable lokal juga ditulis LENGKAP — jangan singkatan walau scope-nya pendek
(keputusan user 2026-06-06, jadwal-kontrol):
`$src`→`$sumber`, `$kw`→`$keyword`, `$w` (closure where)→`$subQuery`,
`$b`/`$r` (item loop/sort)→nama itemnya (`$kunjungan`, `$jadwal`, `$entri`).
Nama generik untuk collection hasil juga dihindari: `$hasil`→`$jadwalList`/`$riwayatList`.
**Item baris = `$row` / koleksinya `$rows`** (STANDAR, keputusan user 2026-09-15): item loop hasil query,
`$this->rows`, `$results`, atau baris tabel ditulis `$row` — BUKAN `$r`, `$baris`, `$item`, `$data`.
Bila dalam satu scope ada dua lapis baris, beri sufiks kata penuh (`$rowHeader`, `$rowDetail`).
Item yang punya makna domain jelas tetap memakai nama domainnya (`$pasien`, `$dokterLeveling`, `$entri`).
Catatan sebaran saat keputusan: `$baris` masih ada di 136 berkas / 1.257 kemunculan (terbanyak satu-sehat
RJ/UGD/RI & downtime/cetak/form), 17 berkas di antaranya juga memakai `$row` → sapuan tersendiri, cek bentrok per scope.

Akronim juga dieja penuh, termasuk yang sudah terlanjur jadi idiom lintas file
(keputusan user 2026-07-27, alasan: auditor kode bingung membacanya):
`$formRO`→**`$formReadOnly`** (= `$isFormLocked || $viewOnly`, flag read-only modul dokumen;
di-rename serentak 516 kemunculan / 37 file RI+UGD+RJ, termasuk prop `:formRO` pada
`x-surveilans.kultur-list` & `x-surveilans.antibiotik-list`).
Varian per-form di Case Manager: `$formRO_A`/`$formRO_B`→`$formReadOnlyA`/`$formReadOnlyB`
(menyelaraskan dgn `$viewOnlyA`/`$viewOnlyB`).
Nama Inggris dipertahankan di sini — tetangganya (`$isFormLocked`, `$viewOnly`) memang Inggris,
dan "read only" itu status UI, bukan istilah domain klinis.

Prefix singkatan pada method trait juga ditolak (keputusan user 2026-09-08): `dvPasien`/
`dvTtdPath`/`dvIdentitasRs` ("dv" = dokumen view) di `DokumenViewSupportTrait` → `pasienDokumen`/
`identitasRsDokumen`; `dvTtdPath` dibuang karena hanya membungkus `App\Support\TtdUser::pathBerkasDariKode`.
Bila butuh pembeda anti-tabrakan antar trait, pakai KATA penuh sebagai sufiks (`…Dokumen`), bukan prefix 2 huruf.

## 2. `use` import vs FQCN di file Volt

File Volt SFC punya 2 zona PHP yang **dikompilasi terpisah**:

1. **Blok `<?php ... ?>` atas** (class component) → import normal berlaku.
   Tulis `use Carbon\Carbon;` di atas dan pakai `Carbon::` — JANGAN `\Carbon\Carbon::` inline di zona ini.
2. **`@php ... @endphp` di template** → import dari blok atas TIDAK menjangkau sini;
   FQCN `\Carbon\Carbon::` memang diperlukan kalau terpaksa.

**Aturan:** logika non-trivial (loop, parsing tanggal, agregasi) JANGAN ditaruh di `@php`
template — pindahkan ke method class (private + public property hasil). Template `@php`
hanya untuk mapping display ringan. Dengan begitu FQCN nyaris tidak pernah dibutuhkan.

## 3. Konsistensi gaya yang sudah jalan

- Property/method Livewire: camelCase bahasa Indonesia sesuai domain (`$dataDaftarRi`, `openDisplay`, `hitungResikoJatuhTerakhir`).
- Key JSON EMR: ikuti key yang sudah ada di `datadaftar*_json` (`resikoJatuh`, `kategoriResiko`) — jangan menerjemahkan/menyingkat ulang.
- Kolom Oracle: snake_case lowercase di query (`bed_no`, `room_name`) — lihat skill `oracle-quirks` untuk jebakan mixed-case.
- Komentar di blok `<?php` Volt: hindari substring `reuse`/`re-use` (lihat skill `blade-safe-edit` §3).

## 4. Branching data sensitif lintas tabel — JANGAN if/else atau ternary default

Operasi tulis yang cabangnya menentukan TABEL tujuan (mis. sumber RJ vs RI):
nilai di luar dugaan tidak boleh diam-diam jatuh ke cabang `else`.

```php
// ❌ SALAH — sumber 'XX' ikut masuk cabang RI
$data = $sumber === 'RJ' ? $this->findDataRJ($no) : $this->findDataRI($no);
if ($sumber === 'RJ') { ...updateJsonRJ... } else { ...updateJsonRI... }

// ✅ BENAR — guard whitelist + if eksplisit per nilai
if (!in_array($sumber, ['RJ', 'RI'], true)) { toast error; return; }
if ($sumber === 'RJ') { ...updateJsonRJ... }
if ($sumber === 'RI') { ...updateJsonRI... }
```
Acuan: riwayat-kontrol-pasien (geser tgl kontrol RJ/RI).
