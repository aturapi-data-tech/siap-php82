# Arsitektur Diagnosa (ICD-10) — sirus-php82

Peta lengkap bagaimana diagnosa mengalir di aplikasi: master → LOV → konsumen
(EMR, SEP/VClaim, iDRG/INACBG). Baca ini SEBELUM mengubah apa pun yang
menyentuh pemilihan/penyimpanan diagnosa.

Terakhir diupdate: 2026-06-04.

---

## 1. Master: `RSMST_MSTDIAGS`

| Kolom | Tipe | Arti |
|---|---|---|
| `DIAG_ID` | VARCHAR2(10) PK | Kode internal (lihat konvensi di bawah) |
| `DIAG_DESC` | VARCHAR2(300) | Nama diagnosa (boleh translasi custom admin) |
| `ICDX` | VARCHAR2(15) | Kode ICD-10 tampilan (dotted, mis. `A00.1`, atau 3-char `K20`) |
| `VALID_CODE` | NUMBER | `1` = leaf/boleh dipakai koding; `0` = parent/category → **diblok di LOV** |
| `ACCPDX` | VARCHAR2(1) | `Y` = boleh jadi diagnosa **Primary**; `N` = hanya Secondary (`!PDX`) |
| `ASTERISK` | NUMBER | `1` = kode asterisk ★ (manifestasi, wajib pair dagger †) |
| `IM` | NUMBER | `1` = kode spesifik iDRG/INACBG Indonesian Modification |

4 kolom flag ditambahkan 2026-05-20 (`database/sql/2026_05_20_alter_idrg_add_validation_columns.sql`,
default `0/'N'`) dan diisi dari seed E-Klaim iDRG TSV 20260331
(`database/sql/seed_rsmst_mstdiags_idrg_20260331.sql`, MERGE **by diag_id**, 40.815 baris,
SAB=ICD10_2010_IM). Kelola via UI **/master/diagnosa** (list badge Status Koding + form 4 toggle).

### Konvensi `DIAG_ID` — PENTING, ada 3 generasi

| Bentuk | Contoh | Asal |
|---|---|---|
| No-dot | `A001` (=A00.1), `K20`, `M4780` | Seed E-Klaim 20260331 (format iDRG) |
| Padding-X | `K20X`, `A99X` | Legacy Oracle Dev 6i utk kode 3-char |
| Dotted | `M47.80`, `R65.9` | Legacy lain |

### ⚠️ 288 icdx kembar (gotcha terbesar)

Karena MERGE seed match by `diag_id`, baris legacy (`K20X`, `M47.80`) **tidak ter-match**
→ hanya dapat default DDL `0/'N'`, lalu seed menambah baris BARU (`K20`, `M4780`) dengan
flag asli E-Klaim. Hasil: **288 kode punya 2 baris dengan `icdx` sama tapi flag bertolak
belakang** (1 hijau valid + 1 merah invalid di dropdown LOV) → perilaku pilih kode terasa acak
(kasus nyata: K20 "terblok" di iDRG tapi lolos di SEP — padahal K20 memang VALID per E-Klaim).

- Baris legacy **TIDAK BOLEH dihapus**: direferensikan >130rb baris transaksi
  (`rstxn_rjdtls`/`ridtls`/`ugddtls`/`rjdtlks`/`oks` kolom `diag_id`).
- Fix data: `database/sql/2026_06_04_sync_dup_icdx_validation_flags.sql`
  (samakan flag antar baris kembar ke MAX grup = nilai seed; idempotent).
- Fix kode: semua guard accpdx pakai pola **exists-Y** (lihat §4), bukan `value()`
  yang ambil baris sembarang.

---

## 2. LOV: `livewire/lov/diagnosa/⚡lov-diagnosa.blade.php`

Satu-satunya picker diagnosa standar. **Selalu pakai ini** untuk field diagnosa baru —
jangan bikin autocomplete sendiri.

```blade
<livewire:lov.diagnosa.lov-diagnosa
    label="Diagnosa *"
    target="rjFormDiagnosaVclaim"      {{-- bedakan per form --}}
    :initialDiagnosaId="$diagnosaId"   {{-- mode edit (reactive; cari by diag_id lalu icdx) --}}
    :blockNonPrimary="true"                {{-- opsional: blok accpdx='N' (utk field DXP) --}}
    :blockIm="true"                    {{-- opsional: blok kode IM (default false) --}}
    :blockHeader="false"               {{-- opsional: izinkan kode kategori (default true = ditolak) --}}
    :disabled="$isFormLocked" />
```

Perilaku:
- Search min 2 char, limit 50, match `diag_id`/`icdx`/`diag_desc`.
- Dropdown menampilkan **SEMUA** baris termasuk invalid — baris invalid merah + badge
  (`!PDX` amber, `★` ungu, `iM` emerald; `iM ✕` merah bila `blockIm`), sama dengan
  badge di /master/diagnosa.
- Guard di `choose()` (berlaku di SEMUA pemakai LOV, bukan cuma iDRG):
  1. `blockHeader && valid_code !== 1` → toast error, batal pilih. `blockHeader`
     default **true**; kalau dilepas, baris kategori diberi badge `KATEGORI` amber
     (tidak merah) supaya tetap kelihatan bukan kode leaf.
  2. `blockNonPrimary && accpdx !== 'Y'` → toast error, batal pilih.
  3. `blockIm && App\Support\Diagnosa\KodeIm::adalah($opt)` → toast error, batal pilih.

**Kalau kode LENGKAP terasa terblokir, cek baris kembar dulu.** 266 icdx punya DUA
baris: baris seed Kemenkes (mis. `diag_id` = `I10`, `valid_code`=1) dan baris legacy
(`diag_id` = `I10X`, `valid_code`=0 karena tidak ada di berkas referensi; 261 dari 266
ber-akhiran `X`). Keduanya muncul di dropdown dengan `icdx` sama — satu merah, satu
tidak. Jawabannya memilih baris yang tidak merah, BUKAN melepas `blockHeader`.

Angka untuk menakar dampaknya (data dev): dari 293.870 baris diagnosa EMR RJ lama yang
memakai kode `valid_code`=0, **83.559** sebenarnya punya baris valid (kasus kembar di
atas) dan **210.311** memakai kode kategori asli seperti `E11` / `K29` — yang benar
adalah kode anaknya (`E11.9`, `K29.7`).

**Setup di SELURUH pemakai LOV.** 13 call site, hasil audit langsung ke berkas
(semua call site MENULIS ketiga prop eksplisit; cetak tebal = menutup / aktif):

| # | Konsumen | File : baris | `target` | `blockHeader` | `blockIm` | `blockNonPrimary` |
|---|---|---|---|---|---|---|
| 1 | SEP / VClaim | `transaksi/rj/daftar-rj/⚡vclaim-rj-actions.blade.php` : 1264 | `rjFormDiagnosaVclaim` | false | false | false |
| 2 | SEP / VClaim | `transaksi/ugd/daftar-ugd/⚡vclaim-ugd-actions.blade.php` : 671 | `ugdFormDiagnosaVclaim` | false | false | false |
| 3 | SEP / VClaim | `transaksi/ri/daftar-ri/⚡vclaim-ri-actions.blade.php` : 1380 | `riFormDiagnosaVclaim` | false | false | false |
| 4 | EMR Diagnosis | `transaksi/rj/emr-rj/diagnosa/⚡rm-diagnosa-rj-actions.blade.php` : 485 | `rjFormDiagnosaRm` | false | false | false |
| 5 | EMR Diagnosis | `transaksi/ugd/emr-ugd/diagnosa/⚡rm-diagnosa-ugd-actions.blade.php` : 422 | `ugdFormDiagnosaRm` | false | false | false |
| 6 | EMR Diagnosis | `transaksi/ri/emr-ri/diagnosa-ri/⚡rm-diagnosa-ri-actions.blade.php` : 441 | `riFormDiagnosaRm` | false | false | false |
| 7 | Coder iDRG | `transaksi/rj/idrg/⚡kirim-diagnosa-idrg.blade.php` : 441 | `rjFormDiagnosaIdrgCoder` | **true** | **true** | false |
| 8 | Coder iDRG | `transaksi/ugd/idrg/⚡kirim-diagnosa-idrg.blade.php` : 436 | `ugdFormDiagnosaIdrgCoder` | **true** | **true** | false |
| 9 | Coder iDRG | `transaksi/ri/idrg/⚡kirim-diagnosa-idrg.blade.php` : 434 | `riFormDiagnosaIdrgCoder` | **true** | **true** | false |
| 10 | Coder INACBG | `transaksi/rj/idrg/⚡kirim-diagnosa-inacbg.blade.php` : 473 | `rjFormDiagnosaInacbgCoder` | false | false | false |
| 11 | Coder INACBG | `transaksi/ugd/idrg/⚡kirim-diagnosa-inacbg.blade.php` : 473 | `ugdFormDiagnosaInacbgCoder` | false | false | false |
| 12 | Coder INACBG | `transaksi/ri/idrg/⚡kirim-diagnosa-inacbg.blade.php` : 473 | `riFormDiagnosaInacbgCoder` | false | false | false |
| 13 | Laporan Diagnosa (hitung jumlah, baca saja) | `manajemen/laporan-diagnosa/hitung-diagnosa/⚡hitung-diagnosa.blade.php` : 106 | `laporanDiagnosaHitung` | false | false | false |

Rekap: `blockHeader` menutup HANYA di 3 coder iDRG; DIBUKA di 10 lainnya (SEP/VClaim +
coder INACBG + EMR diagnosis + Laporan Diagnosa — dibuka 2026-09-11 atas keputusan user supaya dokter bisa
memilih kode induk seperti N40/E11). `blockIm` aktif hanya di 3 coder iDRG.
`blockNonPrimary` tidak aktif di mana pun — aturan primer ditegakkan server-side di tiap
konsumen (§4).

**Pola keputusannya: siapa penilai akhir kode.**

| Konsumen | Guard LOV | Penilai akhir |
|---|---|---|
| Coder iDRG | ketat (`blockHeader` + `blockIm`) | langkah koding utama — ditolak sejak pemilihan supaya tidak bolak-balik kirim klaim |
| Coder INACBG | dibuka | `validcode` respons E-Klaim, tampil per baris di kolom Keterangan |
| SEP / VClaim | dibuka | BPJS VClaim saat SEP dibuat; pesan penolakan muncul sebagai toast dari metadata respons. **Tidak ada** badge kevalidan per kode seperti di coder |
| EMR diagnosis | dibuka (sejak 2026-09-11) | tidak dikirim ke luar; dokter bebas memilih kode induk, LOV tetap memberi badge KATEGORI sebagai peringatan |

Cara memeriksa ulang kalau nanti ada call site baru:

```bash
grep -rn "lov.diagnosa.lov-diagnosa" resources/views --include=*.blade.php -A3
```

Coder INACBG sengaja melepas semua guard: penentu akhir di sana adalah `validcode` dari
respons `expanded[]` E-Klaim, dan tiap baris coder sudah menampilkan badge
Valid / Tidak Valid / IM tidak berlaku beserta alasannya. Jadi koder boleh memasukkan
kode apa adanya (kategori, IM, atau kode yang tak boleh primer → otomatis Secondary),
lalu memperbaikinya setelah tahu jawaban E-Klaim. Konsekuensinya diterima sadar:
kode kategori/IM yang lolos ke klaim akan dibalas `validcode=0`.

**Kenapa `blockIm` perlu terpisah dari guard lain.** **1.413 dari 1.416** kode IM di
master ber-`valid_code=1` DAN `accpdx='Y'`, jadi lolos guard 1 & 2 — tanpa flag ini
kode IM baru ketahuan salah setelah klaim dikirim dan dibalas `validcode=0`.

E-Klaim menolak kode IM di **kedua** bridging, bukan hanya INACBG. Buktinya komponen
iDRG sendiri menampilkan badge "Kode IM tidak diakui" + pesan "Kode IM tidak dikenali
e-klaim. Coba kode ICD-10 standar tanpa suffix IM." (`kirim-diagnosa-idrg.blade.php`
baris 497 & 517). Karena itu `blockIm="true"` dipasang di 3 call site coder iDRG:
penolakannya maju ke saat memilih, koder tidak perlu bolak-balik mengirim klaim dulu.

Prop ini hanya menutup **pemilihan manual**. Jalur sync (`syncFromEmr` di iDRG,
`syncFromIdrg` di INACBG) tidak melewati `add()`, jadi kode IM yang sudah ada di EMR
tetap bisa masuk daftar coder — disengaja, supaya kelihatan lalu diganti; penandanya
badge merah di kolom Keterangan.

Penanda IM ada di `App\Support\Diagnosa\KodeIm` (helper statis, lintas komponen):
- `KodeIm::adalah(array $baris)` — dari payload LOV / baris coder yang sudah di tangan.
- `KodeIm::adalahKode(string $kode, string $deskripsi = '')` — lookup master untuk guard
  server-side. Sengaja `exists()` atas SEMUA baris berkode sama: baris legacy sering
  ber-`im=0` default, jadi cukup satu baris menandai IM untuk memblokir.

Dua sumber penanda dan **keduanya** harus dicek: kolom master `im=1` dan deskripsi
berakhiran `(IM)` — sebagian baris seed hanya membawa penanda di salah satunya.
- Sukses → dispatch `lov.selected.{target}` dengan payload:
  ```php
  ['diag_id' => ..., 'diag_desc' => ..., 'icdx' => ...,
   'valid_code' => int, 'accpdx' => 'Y|N', 'asterisk' => int, 'im' => int]
  ```

Handler di parent:
```php
#[On('lov.selected.rjFormDiagnosaVclaim')]
public function onDiagnosa(string $target, array $payload): void { ... }
```

---

## 3. Konsumen & format simpan

### a. EMR Diagnosis (RJ/UGD/RI)
File: `pages/transaksi/{rj,ugd}/emr-*/diagnosa/rm-diagnosa-*-actions.blade.php`,
`ri/emr-ri/diagnosa-ri/rm-diagnosa-ri-actions.blade.php`.

**Dual-write**:
1. Tabel transaksi legacy: `rstxn_rjdtls` / `rstxn_ugddtls` / `rstxn_ridtls`
   (`diag_id` + nomor detail) + update flag header (mis. `rstxn_rjhdrs.rj_diagnosa='D'`).
2. JSON EMR `diagnosis[]`:
   ```php
   ['diagId' => ..., 'diagDesc' => ..., 'icdX' => ..., 'ketdiagnosa' => ...,
    'kategoriDiagnosa' => 'Primary|Secondary', 'rjDtlDtl' => ..., 'rjNo' => ...]
   ```
   Auto-kategori: Primary pertama hanya jika `accpdx='Y'` (lookup by `diag_id` payload —
   aman, `diag_id` unik). Single-Primary invariant dijaga `setKategoriDiagnosa()`.

### b. SEP / VClaim (pembuatan SEP BPJS)
File: `pages/transaksi/{rj,ugd,ri}/daftar-*/vclaim-*-actions.blade.php`.

- LOV target `*FormDiagnosaVclaim` → handler set `SEPForm['diagAwal'] = payload['icdx']`.
- **Yang dikirim ke BPJS = `icdx`** (kode ICD-10 dotted), BUKAN `diag_id`.
- Validitas master tetap berlaku (guard LOV) — kode `valid_code=0` tak bisa dipilih utk SEP.

### c. iDRG / INACBG coder (klaim E-Klaim Kemenkes)
File: `pages/transaksi/{rj,ugd,ri}/idrg/kirim-diagnosa-{idrg,inacbg}.blade.php`.
Dok detail: `docs/idrg-bridging.md`.

- Editor coder terpisah dari EMR: JSON `idrg.coderDiagnosa[]` / `idrg.coderInacbgDiagnosa[]`
  `['code' => icdx, 'desc' => ..., 'kategori' => 'Primary|Secondary', 'validcode' => ?, 'validInfo' => ?]`.
- Sumber: LOV (guard penuh) atau **Sync dari EMR** (`diagnosis[]` → tanpa guard LOV;
  validcode diisi dari master, lihat pola keyBy §4).
- Kirim ke E-Klaim: `buildString()` → `"PRIMARY#SECONDARY#..."` (kode = icdx).
- Respons `expanded[]` E-Klaim meng-update `validcode` per baris → badge Valid/Tidak Valid
  di tabel coder (kebenaran final = E-Klaim, master hanya pre-filter).

---

## 4. Pola wajib saat menulis guard/lookup flag diagnosa

Karena ada icdx kembar (§1), **JANGAN** lookup flag dengan `value()`:

```php
// ❌ SALAH — baris yang kena bisa baris legacy ber-flag default
$accpdx = DB::table('rsmst_mstdiags')
    ->where('icdx', $code)->orWhere('diag_id', $code)->value('accpdx');

// ✅ BENAR (cek "boleh primer") — true jika ADA baris kode tsb dengan accpdx='Y'
$isAllowedAsPrimary = DB::table('rsmst_mstdiags')
    ->where(fn($q) => $q->where('icdx', $code)->orWhere('diag_id', $code))
    ->where('accpdx', 'Y')
    ->exists();

// ✅ BENAR (lookup banyak kode sekaligus utk keyBy) — baris terbaik menang
$masters = DB::table('rsmst_mstdiags')
    ->whereIn('icdx', $codes)->orWhereIn('diag_id', $codes)
    ->select('diag_id', 'icdx', 'valid_code', 'accpdx', 'im')
    ->orderBy('valid_code')->orderBy('accpdx')   // keyBy keep TERAKHIR → asc = terbaik menang
    ->get();
$byIcdx = $masters->keyBy('icdx');
```

Pengecualian: lookup **by `diag_id` saja** (PK, unik — mis. dari payload LOV yang membawa
`diag_id` baris yang benar-benar diklik) boleh pakai `value()`.

## 5. Checklist menambah field/fitur diagnosa baru

1. Pakai `<livewire:lov.diagnosa.lov-diagnosa>` dengan `target` unik per form.
2. Butuh kode utk sistem eksternal? Kirim **`icdx`** (BPJS/E-Klaim), bukan `diag_id`.
3. Butuh referensi internal/join transaksi? Simpan **`diag_id`**.
4. Field diagnosa primer? Semua konsumen saat ini memakai guard exists-Y (§4) di
   server, BUKAN `:blockNonPrimary="true"` — field diagnosa di aplikasi ini satu kotak
   untuk primer + sekunder, kode `accpdx='N'` tetap masuk lalu dipaksa Secondary.
   Prop `blockNonPrimary` baru relevan kalau ada field KHUSUS primer.
5. Field coder INACBG? Lepas semua guard (`:blockHeader="false" :blockIm="false"
   :blockNonPrimary="false"`) dan JANGAN tambah guard IM di `add()` — penentu akhirnya
   `validcode` E-Klaim + badge per baris. Aturan primer tetap ditegakkan `add()` /
   `setKategori()` lewat pola exists-Y.
5. Auto-kategori Primary/Secondary: cek dulu sudah ada Primary, lalu guard accpdx.
6. Jangan hapus baris master yang direferensikan transaksi — nonaktifkan via `valid_code=0`.
