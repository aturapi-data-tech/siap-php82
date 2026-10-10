---
name: clause-versioning
description: Pola versioning teks klausul dokumen legal (general consent, pernyataan, consent lain) agar cetak ulang record lama tetap memakai redaksi SAAT DITANDATANGANI walau kebijakan/teks berubah (mis. INA-CBG→iDRG). WAJIB dibaca sebelum mengubah teks klausul consent/pernyataan, menambah versi klausul, atau membuat dokumen bertanda tangan baru yang teksnya bisa berubah.
---

# Clause Versioning (Dokumen Legal)

Dokumen bertanda tangan (general consent, surat pernyataan) harus bisa **dicetak ulang dengan
redaksi saat ditandatangani**, bukan redaksi terbaru — karena teks klausul bisa berubah karena
kebijakan baru (mis. transisi **INA-CBG → iDRG** mengubah ketentuan).

Detail lengkap: **`docs/clause-versioning.md`**. Ringkas di bawah.

## Aturan inti

1. **Teks klausul per-versi di class registry**, bukan hardcoded di komponen/cetak.
   Acuan: `app/Support/GeneralConsentClause.php` (const `CURRENT`, `registry()` per versi × context, `get($ctx,$ver)`).
2. **Record menstempel `clauseVersion`** = versi berlaku saat dibuat (di `defaultConsent()` form).
3. **Cetak & tampilan render versi TERSIMPAN** di record, bukan `CURRENT`.
4. Komponen Blade `x-consent.*` terima prop `version`; baca teks dari class via **`@use('App\Support\GeneralConsentClause')`** (bukan FQN inline).

## ⚠️ Gotcha yang mudah salah

- **Cetak fallback `?? 'v1'` (versi tertua), BUKAN `?? null`.** `null` → `CURRENT`. Record legacy (pra-versioning) tak punya stempel → harus render versi tertua, bukan yang terbaru. Contoh: `:version="$consent['clauseVersion'] ?? 'v1'"`.
- **Form/layar boleh `?? null`** (→ CURRENT) untuk entri baru; teruskan versi tersimpan bila ada.
- **Menambah versi baru = TAMBAH key `'v2'`, JANGAN ubah `'v1'`**, lalu naikkan `CURRENT`. Versi lama = arsip legal, tak boleh diedit.
- **Jangan FQN inline `\App\Support\...`.** Di **komponen Blade** pakai `@use('App\Support\X')` (bukan `use` di `@php` — tak sah, @php dikompilasi di dalam method render; Laravel ≥10.4 punya `@use`). Di **file Volt (form)** pakai `use App\Support\X;` biasa di blok `<?php>` atas.
- **Bagian dinamis** (nama wali, RS, pilihan SETUJU/TIDAK) TIDAK disimpan di registry — diinterpolasi komponen via placeholder `%WALI%/%HUB%/%RS%` + `strtr`/`e()`.
- **Teks form & cetak WAJIB dari satu sumber (class)** — jangan hardcode ulang di file cetak. Verifikasi identik: strip tags + normalisasi (buang nomor list & `:`) lalu banding string (nomor `<li>` CSS layar vs `1.` literal PDF = artefak format, bukan beda teks).

## Menambah versi (checklist)

1. `GeneralConsentClause::registry()` → tambah `'v2' => [ 'rj'=>..., 'ugd'=>..., 'ri'=>... ]`.
2. `const CURRENT = 'v2'`.
3. Selesai — record baru stempel v2; record lama tetap render v1.

## Versioning vs SNAPSHOT — jangan salah pilih

- **Versioning** (registry per-versi) → untuk **TEKS KLAUSUL** (jarang berubah): `GeneralConsentClause`, `PenjaminanClause`. Record simpan `clauseVersion`.
- **Snapshot** (salin nilai ke record) → untuk **DATA yang sering berubah & spesifik per record**, mis. **tarif/fasilitas kelas kamar**. Simpan salinan nilai (`nama`+`tarifLabel`+`fasilitas`) di entri saat `buildEntry()`; cetak prefer snapshot, fallback master (`KelasKamar::find`) utk legacy. Versioning tak praktis utk harga yang berubah tiap tahun.

## Yang sudah pakai pola ini

- `App\Support\GeneralConsentClause` — general consent RJ/UGD/RI (versioning).
- `App\Support\PenjaminanClause` — ketentuan BPJS & selisih biaya Form Penjaminan (versioning, section `bpjs`/`selisih`).
- `App\Support\AkhirHayatClause` — teks pernyataan Pengkajian Akhir Hayat RI (v1).
- `App\Support\SuratKematianClause` — klausul Surat Keterangan Kematian UGD (v1).
- `App\Support\KerohanianClause` — klausul Permintaan Pelayanan Kerohaniawan RI (v1).
  Sempat dihapus 2026-07-22 lalu DIPULIHKAN 2026-07-30 (`fe09b68b`) — permintaan
  rohaniwan tidak selalu terkait akhir hayat.
- Kelas kamar (orientasi Form Penjaminan) — **snapshot** (`kelasKamarSnapshot` di entri).

Lihat juga: `docs/clause-versioning.md`, skill `blade-safe-edit`.
