# Arsitektur Laboratorium End-to-End (sirus-php82)

Peta **siklus penuh** lab: dari dokter **order lab di EMR** → **petugas lab** proses & input hasil →
**administrasi/kasir** (biaya) → **dokter lihat hasil** → **master** (rentang normal & kritis) → **laporan**.

> Deep-dive modul petugas (status, batal, Mindray, kesimpulan, etiket, SQL): **`docs/laborat-modul.md`**.
> Fitur ambang kritis: `docs/laborat-modul.md` bagian "Nilai Kritis" (+ skill `laborat`).

---

## 1. Siklus & status (`lbtxn_checkuphdrs.checkup_status`)

```
DOKTER (EMR)          PETUGAS LAB                         ADMIN/KASIR         DOKTER
 order lab   ─┐                                                                 ▲
             ▼                                                                  │
        [P] Terdaftar ──proses administrasi──▶ [C] Input hasil ──simpan──▶ [H] Selesai ──lihat/cetak hasil
             │  (P→C: POST biaya ke rstxn_*labs)      │                          │
             │                                        │                          └─ laboratorium-display
             └─batal pendaftaran─▶ [F] Dibatalkan     └─ batal transaksi (H/C→P: HAPUS biaya)
```

| Kode | Arti | Transisi kunci |
|---|---|---|
| `P` | Terdaftar (baru di-order dari EMR) | `P→C` post biaya · `P→F` batal pendaftaran |
| `C` | Proses / input hasil | `C→H` simpan hasil · `C→P` batal transaksi |
| `H` | Selesai (hasil final) | `H→P` batal transaksi (hapus biaya) |
| `F` | Dibatalkan | terminal |

---

## 2. Model Data (tabel & relasi)

Prefix: `lbtxn_` (transaksi lab), `lbmst_` (master lab), `rstxn_*labs` (baris biaya di transaksi induk).

```
rstxn_rjhdrs / ugdhdrs / rihdrs   (kunjungan induk: rj_no / rihdr_no)
        ▲ ref_no + status_rjri
        │
lbtxn_checkuphdrs  (PK checkup_no; checkup_status, status_rjri, ref_no, reg_no, dr_id, emp_id, klinis_desc, checkup_kesimpulan, checkup_date, waktu_*)
        │ 1:N (checkup_no)
        ├── lbtxn_checkupdtls     (checkup_dtl, clabitem_id, lab_item_code, lab_result, lab_result_status, price)
        ├── lbtxn_checkupoutdtls  (pemeriksaan LUAR: labout_result, labout_normal, labout_price)
        └── lbtxn_checkupobats    (obat/bahan: price, qty)

lbmst_clabs (PK clab_id)  1:N  lbmst_clabitems (PK clabitem_id+clab_id+product_id)
        (master item: rentang normal/kritis, unit, mapping alat/LOINC, paket)

Biaya di-POST saat P→C ke SATU baris agregat sesuai status_rjri:
  RJ  → rstxn_rjlabs   (lab_dtl, lab_desc, lab_price, rj_no,    checkup_no)
  UGD → rstxn_ugdlabs  (lab_dtl, lab_desc, lab_price, rj_no,    checkup_no)   -- UGD pakai rj_no
  RI  → rstxn_rilabs   (lab_dtl, lab_desc, lab_price, rihdr_no, checkup_no, lab_date)  -- RI +lab_date
```

`checkup_no` = benang merah utama (order→hasil→biaya). `ref_no` + `status_rjri` = benang ke kunjungan induk.

---

## 3. STAGE 1 — Order lab dari EMR (RJ/UGD/RI)

Dokter order via tab **"Pelayanan Penunjang"** di EMR. Komponen order (Volt SFC) per layanan:

| Layanan | File order |
|---|---|
| RJ | `pages/transaksi/rj/emr-rj/pemeriksaan/penunjang/laborat/⚡rm-laborat-rj-actions.blade.php` |
| UGD | `pages/transaksi/ugd/emr-ugd/pemeriksaan/penunjang/laborat/⚡rm-laborat-ugd-actions.blade.php` |
| RI | `pages/transaksi/ri/emr-ri/pemeriksaan-ri/penunjang/laborat/⚡rm-laborat-ri-actions.blade.php` |

Daftar "sudah di-order" (read-only, per kunjungan): `rm-daftar-laborat-{rj,ugd,ri}.blade.php` (+ varian `-luar-`).

**Alur `kirimLaboratorium()`** (RJ ±122, UGD ±118, RI ±194), semua dalam `DB::transaction`:
1. INSERT `lbtxn_checkuphdrs`: `checkup_no = NVL(MAX(TO_NUMBER(checkup_no))+1,1)`, **`checkup_status='P'`**,
   `status_rjri` = `'RJ'|'UGD'|'RI'`, `ref_no` = `rj_no` (RJ/UGD) / `rihdr_no` (RI), `reg_no`,
   `checkup_date = TO_DATE(now)`, `klinis_desc = trim(klinisDesc)`.
2. `dr_id`: **RJ/UGD otomatis** dari `dr_id` kunjungan; **RI dari picker** `$drId` (LOV `relatedDoctors()` =
   DPJP ∪ visite ∪ jasa dokter ∪ leveling dokter dari EMR JSON — RI wajib `'drId'=>'required'`).
3. INSERT detail via `insertItemAndChildren()`: parent + **auto-expand paket** (child = item dengan
   `clabitem_group = parent.clabitem_id`, urut `item_seq`), tiap baris simpan `clabitem_id`, `lab_item_code`
   (=`item_code`), `price` (snapshot dari master saat pilih).

**Picker item**: computed `items()` dari `lbmst_clabitems` (`clabitem_id, clabitem_desc, price,
clabitem_group, item_code`), **hanya parent** (`whereNull('clabitem_group')`), search + `paginate(15)`.

**Aturan wajib sebelum order:**
- **`klinis_desc` (Diagnosis/Ket. Klinis) REQUIRED** (`rules() 'klinisDesc'=>'required'` + `validateWithToast`)
  — aturan "klinis_desc order penunjang".
- Minimal 1 item; guard pasien belum pulang (`checkRJStatus/checkUGDStatus/checkRIStatus`).
- RI wajib pilih dokter pengirim.
- Trait: `EmrRJTrait/EmrUGDTrait/EmrRITrait` (status guard + `appendAdminLog{RJ,UGD,RI}` audit `'MR'` +
  `checkLabPending*`). **Tak ada LabTrait khusus** — insert inline di komponen.

Order (status `P`) langsung muncul di worklist petugas (`⚡daftar-laborat.blade.php` via view `rsview_checkups`,
link `checkup_no`) sebagai **"Terdaftar"**.

---

## 4. STAGE 2 — Petugas lab (proses, input hasil, Mindray)

Modul berdiri sendiri: `pages/transaksi/penunjang/laborat/`. Detail lengkap → **`docs/laborat-modul.md`**.
Ringkas:
- `⚡daftar-laborat.blade.php` = worklist; `⚡daftar-laborat-actions.blade.php` = modal per-pasien (proses,
  simpan hasil, batal, cetak, etiket). Tab input di `pemeriksaan-laborat.blade.php` (+ luar, obat).
- **Proses Administrasi (`P→C`)** = `updateCheckupStatus('C')` (`⚡daftar-laborat-actions.blade.php:213`):
  hitung total (pemeriksaan+luar+obat) → **POST 1 baris** ke `rstxn_*labs` → set `C` + `emp_id` + `waktu_masuk`.
- **Input hasil (status `C`)**: tiap hasil (blur) → hitung `lab_result_status` (lihat §7) → UPDATE
  `lbtxn_checkupdtls`. **Import Mindray** (`importMindray`, koneksi Oracle `oracle_mindray`, `SpecimenID=checkup_no`,
  match `lab_item_code=ItemCode`) lalu `recalculateAllResultStatus()`.
- **Simpan Hasil (`C→H`)** + **Kesimpulan** (`checkup_kesimpulan`, auto-save on blur status C).
- **Batal** (role Admin · Supervisor Penunjang; petugas TIDAK boleh): `batalkanTransaksi` (H/C→P, DELETE
  `rstxn_*labs`), `batalkanPendaftaran` (P→F). Guard status induk + `DB::transaction`+`lockForUpdate`+audit.

---

## 5. STAGE 3 — Administrasi & Kasir (hubungan biaya)

**Tab "Laboratorium" di Administrasi = READ-ONLY** (hanya daftar + total, tak ada entry/edit/hapus):

| Layanan | File tab | Sumber angka |
|---|---|---|
| RJ | `pages/transaksi/rj/administrasi-rj/⚡laboratorium-rj.blade.php` | `rstxn_rjlabs` |
| UGD | `pages/transaksi/ugd/administrasi-ugd/⚡laboratorium-ugd.blade.php` | `rstxn_ugdlabs` |
| RI | `pages/transaksi/ri/administrasi-ri/⚡laboratorium-ri.blade.php` | `rstxn_rilabs` |

- Angka **berasal dari `rstxn_*labs`** yang di-POST petugas saat P→C (bukan dihitung ulang di Administrasi).
  Refresh via `#[On('administrasi-lab-*.updated')]`.
- **Masuk total tagihan**: tiap Administrasi `sum('lab_price')` → `$sumLaboratorium` masuk `$sumTotalRJ`
  (`administrasi-rj.blade.php:209,213`), idem UGD (`:225`), RI `$sumRiLab` (`administrasi-ri.blade.php:158`,
  `kasir-ri.blade.php:220`).
- **Kasir**: cek keberadaan biaya lab (mis. `kasir-rj.blade.php:581` untuk lab UGD hasil transfer RJ→UGD;
  `kasir-ugd.blade.php:441` lab RI). RI juga menyerap lab transfer via `rstxn_ritempadmins.lab`.
- **Batal biaya**: hanya lewat modul petugas (`batalkanTransaksi` DELETE `rstxn_*labs`, guarded) — bukan dari
  Administrasi/Kasir.

> Prinsip: Order (EMR) dan pengelolaan biaya (petugas lab) yang menulis; Administrasi/Kasir **hanya membaca**.

---

## 6. STAGE 4 — Dokter lihat hasil

Komponen reusable **`laboratorium-display`** (scoped `regNo`), di-embed di banyak tempat:

| Tempat dokter mengakses | File host |
|---|---|
| EMR RJ → tab "Hasil Penunjang" → sub "Laboratorium" | `.../rj/emr-rj/pemeriksaan/tabs/hasil-penunjang-tab.blade.php:42` |
| EMR UGD → "Hasil Penunjang" → "Laboratorium" | `.../ugd/emr-ugd/pemeriksaan/tabs/hasil-penunjang-tab.blade.php:43` |
| EMR RI → sub-tab `laboratorium` | `.../ri/emr-ri/pemeriksaan-ri/rm-pemeriksaan-ri-actions.blade.php:432` |
| Cetak Rekam Medis RJ/UGD/RI | `pages/components/rekam-medis/{r-j,u-g-d,r-i}/cetak-rekam-medis/cetak-rekam-medis-open.blade.php` |
| Berkas BPJS RJ/UGD/RI | `.../daftar-{rj,ugd,ri}-bulanan/⚡berkas-bpjs-*-actions.blade.php` |

File: `pages/components/rekam-medis/penunjang/laboratorium-display/⚡laboratorium-display.blade.php`
(layar) + `-print.blade.php` (PDF).

- **List checkup** (`baseQuery`): view `rsview_checkups` WHERE `reg_no=regNo` AND `checkup_status!='F'` AND
  ada detail internal; paginate (3/hal), filter tahun/status/keyword. Hanya status `H` yang punya tombol
  "Hasil Laboratorium" (`openDetail`) & "Cetak". Role gate `@role(['Dokter','Admin','Perawat','Laboratorium'])`.
- **Detail** (`openDetail`): join `lbtxn_checkuphdrs → lbtxn_checkupdtls → rsmst_pasiens → lbmst_clabitems →
  lbmst_clabs → rsmst_doctors → immst_employers` WHERE `checkup_no=:cno AND nvl(hidden_status,'N')='N'`,
  urut `app_seq, item_seq`. Kolom: `lab_result`, `lab_result_status`, `lowhigh_status`, normal `_m/_f`,
  limit `_m/_f`, **`critical_*_m/f`**, `nilai_kritis`, `unit_desc`, `unit_convert`, `sex`.
- **Status** H/HH/HIGH=Tinggi(merah), L/LL/LOW=Rendah(biru), R=Abnormal(oranye).
- **Nilai Rujukan** per gender (`sex==='P'`→`_f`, else `_m`; `×unit_convert` hanya bila `lowhigh_status='Y'`;
  fallback teks `normal_*`).
- **Badge NILAI KRITIS** = **berbasis ambang** `critical_low/high` per gender + fallback flag (lihat §8).
- **Kesimpulan** tampil di modal + PDF. **Cetak** = `cetakLaborat(checkupNo)` → `Pdf::loadView(...-print)`
  streamDownload `laborat-<no>.pdf`.

**Beda `display-pasien-laborat`** (`pages/transaksi/penunjang/laborat/display-pasien-laborat/`): scoped
`checkupNo` (bukan `regNo`), **hanya kartu identitas** 1 checkup (via `MasterPasienTrait`), dipakai di modal
**petugas** (banner pasien) — bukan viewer hasil dokter.

---

## 7. MASTER — model rentang normal & perhitungan status

Route `/master/laborat` → `master-laborat/clab` (kategori) + `master-laborat/clabitem` (item).

**`lbmst_clabs`**: `clab_id` (PK), `clab_desc`, `app_seq`.

**`lbmst_clabitems`** (PK `clabitem_id`+`clab_id`+`product_id`) — kolom kunci:

| Grup | Kolom |
|---|---|
| Identitas | `clabitem_id`, `clabitem_desc`, `clab_id`, `product_id`, `item_seq`, `price`, `dosage`, `unit_desc`, `status`, `hidden_status` |
| Paket | `is_group` (`'1'`=paket), `clabitem_group` (id induk bila child) |
| Mode rujukan | `lowhigh_status` (`'Y'`=Rentang Angka, null=Teks Deskriptif) |
| Normal TEKS | `normal_m`, `normal_f` |
| Normal ANGKA | `low_limit_m/high_limit_m` (Pria), `low_limit_f/high_limit_f` (Wanita), `low_limit_k/high_limit_k` (Anak) |
| **KRITIS ANGKA** | `critical_low_m/high_m`, `critical_low_f/high_f`, `critical_low_k/high_k` |
| Alert | `nilai_kritis` (`'Y'/'N'`) |
| Konversi/Alat | `unit_convert`, `item_code` (Mindray), `loinc_code`/`loinc_display` (Satu Sehat) |

**Form** (`⚡master-clabitem-actions.blade.php`): 3 section (Pemeriksaan · Nilai Rujukan · Tarif&Konfigurasi).
Blok angka P/W/A → `_m/_f/_k` muncul bila `lowhigh_status=Y`; blok **"Ambang Nilai Kritis"** nested di
dalamnya + gate tambahan `nilai_kritis=Y`. Mode teks → `normal_m/normal_f`.

### 7.1 Perhitungan `lab_result_status` (H/L/N/R) — **BUKAN di master**

Ada di `pages/transaksi/penunjang/laborat/⚡pemeriksaan-laborat.blade.php`. `sex==='P'` = Wanita.

```
mode ANGKA (lowhigh_status='Y' & hasil numerik):
    unitConvert = unit_convert ?: 1
    numValue    = hasil / unitConvert          # entri interaktif: DIBAGI convert
    low  = sex==='P' ? low_limit_f  : low_limit_m
    high = sex==='P' ? high_limit_f : high_limit_m
    numValue < low  → 'L' (Rendah) ;  numValue > high → 'H' (Tinggi) ;  else Normal (null)
mode TEKS (lowhigh_status≠'Y'):
    normal = sex==='P' ? normal_f : normal_m
    hasil != normal → 'R' (Abnormal)
```

- **`recalculateAllResultStatus()`** (dipanggil setelah Mindray) logika sama, tapi `numValue=(float)hasil`
  **tanpa** bagi `unit_convert` (import sudah menyimpan nilai terbagi).
- **`_k` (Anak) TIDAK dipakai runtime** — hanya `_m/_f`. Konsisten di semua konsumen (display, cetak,
  laporan, status). `_k` cuma tersimpan/tertampil di master.
- **Status disimpan `'H'/'L'/'R'/null`** (Normal = null, bukan literal `'N'`).

---

## 8. Nilai Kritis (ambang `critical_*`) — ringkas

Definisi & aturan lengkap: `docs/laborat-modul.md` bagian "Nilai Kritis". Inti:
`nilai_kritis='Y'` DAN (`hasil ≤ critical_low` ATAU `hasil ≥ critical_high`) per gender (`_m/_f`);
**fallback** ke flag `lab_result_status` H/L bila ambang belum diisi / hasil non-numerik. Disimpan unit RAW.

**Konsumen (harus konsisten):**

| Tempat | Basis kritis | Status |
|---|---|---|
| Master `/master/laborat` (input + list) | — (definisi ambang) | ✅ |
| Dokter `laboratorium-display` (layar) | **ambang + fallback** | ✅ |
| Cetak `laboratorium-display-print` | **ambang + fallback** | ✅ |
| Laporan Nilai Kritis (`NilaiKritisLabTrait`) | **ambang SAJA (tanpa fallback)** — item tanpa ambang tak muncul | ✅ |
| **Petugas input `pemeriksaan-laborat`** | **flag lama** (`nilai_kritis=Y` + status H/L) | ⚠️ BELUM diselaraskan |

> **Catatan kebijakan**: laporan sengaja **threshold-only** (beda dari display/cetak yang fallback) — laporan
> manajemen hanya menghitung hasil yang melewati ambang yang SUDAH dikonfigurasi, agar tidak tercemar item
> yang ambangnya belum diisi (mis. PCT rendah yang secara klinis bukan kritis).

---

## 9. Gap & catatan (per 2026-07-13)

1. ⚠️ **`pemeriksaan-laborat.blade.php:869`** (highlight KRITIS di layar input petugas) masih flag-based
   (`nilai_kritis==='Y' && status ∈ {H,L}`), **belum** pakai `critical_*`. Untuk konsisten penuh perlu
   diselaraskan ke pola ambang+fallback seperti display/cetak/laporan.
2. **`lab_result_status` (H/L/N/R) tak memakai `critical_*`** — status Tinggi/Rendah tetap dari rentang
   **normal**. Ambang kritis hanya dipakai untuk BADGE/penandaan kritis, bukan mengubah H/L. (Desain benar:
   kritis = lapisan di atas normal.)
3. **`_k` (Anak) tak dipakai runtime** di mana pun (normal & kritis) — tak ada deteksi umur. Jika ingin
   dipakai, butuh hitung umur dari `birth_date` di SEMUA konsumen sekaligus (normal + kritis).
4. **DDL `critical_*` baru ada di DEV**, prod belum (SELECT sebut kolom eksplisit → `ORA-00904` bila belum).
5. **Isi nilai kritis di master masih kosong** — semua item ber-`nilai_kritis='Y'` jalur **fallback**
   sampai ambang diisi (perlu validasi Patologi Klinik).
