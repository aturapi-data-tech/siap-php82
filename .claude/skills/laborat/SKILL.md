---
name: laborat
description: Arsitektur & jebakan modul Laboratorium (lbtxn_/lbmst_, hasil lab, nilai rujukan & nilai kritis, Mindray, status P/C/H/F, biaya ke induk RJ/UGD/RI). Baca sebelum menambah/mengubah apa pun soal lab — item master, input/tampilan/cetak hasil, ambang normal & kritis, laporan penunjang, atau batal. Banyak jebakan: kolom per-gender m/f/k, ambang kritis + fallback, unit_convert cuma untuk tampilan, DDL kolom baru wajib jalan tiap env.
---

# Modul Laboratorium (sirus-php82)

Dok:
- **`docs/laborat-architecture.md`** — siklus PENUH end-to-end (EMR order → petugas → admin/kasir → dokter
  lihat hasil → master → laporan), model data, gap/inkonsistensi. Baca ini dulu untuk gambaran besar.
- **`docs/laborat-modul.md`** — deep-dive modul petugas (status, batal, Mindray, SQL, **Nilai Kritis**).

Ringkasan keputusan cepat di bawah.

## Peta komponen

| Lapisan | Lokasi |
|---|---|
| Master item lab (rentang normal + ambang kritis) | `lbmst_clabitems`, UI `/master/laborat` → `master-laborat/clabitem/⚡master-clabitem{,-actions}` |
| Master kelompok lab | `lbmst_clabs` |
| Modul lab petugas (antrian, proses, input hasil, cetak, etiket, batal) | `pages/transaksi/penunjang/laborat/⚡daftar-laborat*` |
| Display hasil (layar) & cetak PDF | `pages/components/rekam-medis/penunjang/laboratorium-display/laboratorium-display{,-print}.blade.php` |
| Laporan Nilai Kritis (manajemen) | `App\Http\Traits\Manajemen\Rs\Penunjang\Lab\NilaiKritisLabTrait` + `pages/manajemen/rs/penunjang/lab/laporan-nilai-kritis` |
| Laporan penunjang detail (lab internal/rujukan) | lihat memory `project_laporan_penunjang_detail` |

Prefix tabel: `lbtxn_` (transaksi) & `lbmst_` (master). Header `lbtxn_checkuphdrs` (PK `checkup_no`,
`checkup_status` P/C/H/F, `status_rjri`, `ref_no`), detail `lbtxn_checkupdtls` (`lab_result`,
`lab_result_status` H/L/N/R), luar `lbtxn_checkupoutdtls`, obat `lbtxn_checkupobats`.

## Jebakan utama

1. **Nilai rujukan & kritis per gender — hanya m/f di runtime.** Master punya 3 kelompok: Pria `_m`,
   Wanita `_f`, Anak `_k` — untuk `low/high_limit_*` **dan** `critical_low/high_*`. Tapi display/cetak/laporan
   **hanya** pilih `_m/_f` by `sex` (`'P'→_f`, selain itu `_m`); `_k` tersimpan tapi tak dipakai (tak ada
   deteksi umur). Jangan tambah `_k` ke satu konsumen saja tanpa menambah deteksi umur di semua (termasuk
   normal range). Query menyebut kolom **eksplisit** — tambah kolom di SELECT saat butuh.

2. **Nilai kritis = lewat AMBANG KRITIS, dengan FALLBACK.** Definisi: `nilai_kritis='Y'` DAN
   (`hasil <= critical_low` ATAU `hasil >= critical_high`) per gender. Bila ambang belum diisi ATAU hasil
   non-numerik → **fallback** ke flag lama `lab_result_status` (H/L). Pola ini WAJIB sama di konsumen:
   display (`laboratorium-display`), cetak (`-print`), trait laporan (`NilaiKritisLabTrait`). ⚠️ **GAP
   diketahui**: layar input petugas `pemeriksaan-laborat.blade.php:869` MASIH flag-based (belum ambang) —
   selaraskan bila menyentuh area itu. `lab_result_status` (H/L/N/R) sendiri TETAP dari rentang normal,
   BUKAN critical (kritis = lapisan badge di atas normal). Detail: `docs/laborat-architecture.md` §8-9.

3. **`unit_convert` cuma untuk TAMPILAN.** `lab_result`, `low/high_limit`, `critical_*` semua disimpan unit
   **RAW** (nilai alat). Perbandingan Tinggi/Rendah/Kritis pakai RAW. `× unit_convert` hanya saat render
   (aktif bila `lowhigh_status='Y'` & `unit_convert` numerik >0). Jangan bandingkan nilai yang sudah dikonversi.

4. **DDL kolom baru wajib jalan tiap environment.** Karena SELECT menyebut kolom eksplisit, kolom yang
   belum ada di DB → **ORA-00904** (halaman rusak, bukan cuma fitur). Cek `user_tab_columns` dulu; jalankan
   ALTER di dev **dan** ingatkan prod sebelum deploy.

5. **Administrasi RJ/UGD/RI (tab Laboratorium) READ-ONLY.** Cuma daftar biaya + total. Order lewat EMR,
   kelola/batal lewat modul penunjang lab (modal `daftar-laborat`). Jangan tambah entry/edit/hapus di Administrasi.

6. **Batal = eskalasi ke atasan.** `isAllowedBatal()` = Admin · Supervisor Penunjang (petugas lab TIDAK
   boleh batal sendiri). Dua jenis: `batalkanPendaftaran` (P→F), `batalkanTransaksi` (H/C→P, hapus biaya
   induk). WAJIB guard status induk (RJ/UGD `L/F/I`, RI `P`) + `DB::transaction`+`lockForUpdate`+audit log.

7. **Mindray = koneksi Oracle terpisah** (`oracle_mindray`). `SpecimenID` = `checkup_no`, match item pakai
   `lab_item_code`=`ItemCode`. Ada konversi khusus "alat baru" (HGB/MCHC /10, RDW-CV /100, PCT ×10). Semua
   dalam satu transaksi; kegagalan koneksi ditoast tanpa ubah data lokal.

8. **Oracle quirks berlaku penuh** (lihat skill `oracle-quirks`): `'' = NULL`, tak ada `JSON_VALUE`,
   numerik-guard string hasil pakai `REGEXP_LIKE(...,'^-?[0-9]+(\.[0-9]+)?$')` sebelum `TO_NUMBER`
   (CASE short-circuit). Laporan kritis butuh `sex` → `baseKritis` left-join `rsmst_pasiens`.

## Verifikasi wajib sebelum selesai (Volt/Blade)

Compile 2 file blade via `Blade::compileString` → `php -l` (lihat skill `blade-safe-edit`), jalankan query
nyata ke Oracle (rentang lebar) untuk pastikan tak ada ORA-00904, dan uji logika kritis (jalur ambang +
fallback) — idealnya set ambang dalam `DB::transaction` lalu `rollBack`.
