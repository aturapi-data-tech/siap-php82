---
name: master-pasien
description: Field path & jebakan data pasien (rsmst_pasiens / MasterPasienTrait). Baca saat membaca/menyimpan data pasien, menampilkan jenis kelamin & umur, atau mengisi form Master Pasien — banyak bug mapping L/P, *Desc tidak sync, dan kolom yang salah nama.
---

# Master Pasien (rsmst_pasiens)

## Field paths via MasterPasienTrait (nested, BUKAN flat)
- Jenis kelamin: `jenisKelamin.jenisKelaminDesc` (bukan `sex`)
- Alamat: `identitas.alamat` + `identitas.rt` + `identitas.rw` + `identitas.desa` + `identitas.kec` (bukan flat)
- `tglLahir` / `tempatLahir` → flat. `regBirth` → `tglLahir`.

## Kolom tabel rsmst_pasiens (langsung DB)
- Prefix `reg_` HANYA di `reg_no`, `reg_name`, `reg_date`.
- Alamat = `address` (BUKAN `reg_address`).
- BPJS = `nokartu_bpjs` (BUKAN `no_bpjs`).
- Kolom `email` TIDAK ADA.
- Cek `MasterPasienTrait` dulu sebelum menebak nama kolom.

## Umur — SELALU compute dari birth_date
Kolom stored `thn` / `bln` / `hari` adalah snapshot saat registrasi dan TIDAK refresh. Hitung umur dari `birth_date` secara live. Untuk resume medis, anchor ke **Tgl Masuk**, bukan `now()`.

## Jenis kelamin — BUG mapping yang harus diwaspadai
1. **Binary fallback bug**: `jenisKelaminOptions` punya 5 nilai (0–4) tapi banyak kode save pakai `== 1 ? 'L' : 'P'` → semua non-Laki tersimpan `'P'`. Mapping harus eksplisit + validasi `in:1,2`.
2. **Desc tidak sync dengan Id**: JSON menyimpan `*Desc` terpisah dari `*Id`. Livewire kadang hanya update `*Id`, `*Desc` tetap default ("Laki-laki") → label salah tersimpan. Wajib sync `*Desc` via hook `updated()` + defensif di `save()`.

## Cetak identitas (print blade)
- Gender: nested `jenisKelamin.jenisKelaminDesc` (array trait tak punya flat `sex`) ATAU `$row->sex` untuk query DB-direct. Mapping 3-cabang: L / P / `-`.
- Umur: compute dari `birth_date`.
