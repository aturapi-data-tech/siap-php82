---
name: diagnosa-flow
description: Arsitektur & jebakan diagnosa ICD-10 (RSMST_MSTDIAGS, LOV diagnosa, EMR, SEP/VClaim, iDRG/INACBG). Baca sebelum mengubah/menambah apa pun yang memilih atau menyimpan diagnosa — ada 288 icdx kembar di master yang bikin lookup flag naive (value/first) salah baris, plus aturan icdx vs diag_id per konsumen.
---

# Diagnosa Flow (sirus-php82)

Dok lengkap: **`docs/diagnosa-architecture.md`** — baca itu untuk detail.
Ringkasan keputusan cepat:

## Peta komponen

| Lapisan | Lokasi |
|---|---|
| Master + 4 flag iDRG (`valid_code/accpdx/asterisk/im`) | `RSMST_MSTDIAGS`, UI `/master/diagnosa` |
| Picker standar (SATU-satunya) | `livewire/lov/diagnosa/⚡lov-diagnosa.blade.php` — guard `valid_code!==1`, `blockNonPrimary`, `blockIm` ada di `choose()`, berlaku utk SEMUA pemakai |
| Penanda kode IM (lintas komponen) | `App\Support\Diagnosa\KodeIm` — `adalah(array)` & `adalahKode(string)` |
| EMR diagnosis (RJ/UGD/RI) | `rm-diagnosa-*-actions.blade.php` — dual-write `rstxn_*dtls.diag_id` + JSON `diagnosis[]` (`diagId/icdX/kategoriDiagnosa`) |
| SEP / VClaim | `vclaim-*-actions.blade.php` — `SEPForm.diagAwal` = **icdx** |
| iDRG/INACBG coder | `kirim-diagnosa-{idrg,inacbg}.blade.php` — JSON `idrg.coderDiagnosa[]` (`code`=icdx), kirim `"PRI#SEC#..."`, validcode final dari `expanded[]` E-Klaim |

## Jebakan utama

1. **288 icdx kembar**: baris seed E-Klaim (`K20` vc=1) + baris legacy (`K20X`/`M47.80`
   dapat default `0/'N'`). JANGAN lookup flag via `value()`/`first()` by icdx — pakai:
   - cek boleh-primer: `->where(fn($q)=>$q->where('icdx',$code)->orWhere('diag_id',$code))->where('accpdx','Y')->exists()`
   - lookup massal + `keyBy('icdx')`: tambah `->orderBy('valid_code')->orderBy('accpdx')` (baris terbaik menang).
   - Lookup **by diag_id saja** (PK unik, mis. dari payload LOV) aman pakai `value()`.
2. **icdx vs diag_id**: ke sistem eksternal (BPJS SEP, E-Klaim) kirim `icdx`;
   utk join/simpan internal pakai `diag_id`.
3. **Jangan hapus baris master** — legacy `diag_id` direferensikan >130rb baris
   `rstxn_*dtls`. Nonaktifkan via `valid_code=0` (toggle di /master/diagnosa).
4. Field diagnosa **primer** → semua konsumen memakai guard exists-Y server-side, BUKAN
   `:blockNonPrimary` (0 dari 12 call site); jaga single-Primary invariant saat auto-kategori.
   Ketiga prop guard ditulis EKSPLISIT di 12 call site (tabel di docs §2). Status:
   `blockHeader` menutup di 6 (EMR + coder iDRG), dibuka di 6 (SEP/VClaim + coder
   INACBG); `blockIm` aktif di 3 coder iDRG; `blockNonPrimary` 0 pemakai. Pembagi
   keputusannya = siapa penilai akhir kode: ada sistem luar yang menolak & pesannya
   sampai ke pengguna (E-Klaim, BPJS) → guard dibuka; tidak ada penilai luar (EMR)
   atau penolakan mahal krn harus kirim klaim dulu (coder iDRG) → guard ditutup.
4b. **Setup guard LOV per konsumen** (`blockHeader` default true, `blockIm` default false):
   EMR/SEP/master pakai default; coder iDRG pakai default; **coder INACBG melepas
   keduanya** (`:blockHeader="false" :blockIm="false"`, tanpa `blockNonPrimary`) karena
   penentu akhirnya `validcode` dari respons E-Klaim + badge per baris. Keputusan
   sadar: kode kategori/IM yang lolos akan dibalas validcode=0.
   Kode IM: 1.413 dari 1.416 ber-`valid_code=1` & `accpdx='Y'`, jadi guard valid_code
   TIDAK menangkapnya. E-Klaim menolaknya di KEDUA bridging — komponen iDRG pun punya
   badge "Kode IM tidak diakui" (kirim-diagnosa-idrg:497,517) — karena itu coder iDRG
   memakai `:blockIm="true"`. Prop ini hanya menutup pemilihan manual; jalur sync tidak
   lewat add(). Penandanya `App\Support\Diagnosa\KodeIm` — cek DUA sumber:
   kolom `im=1` DAN deskripsi berakhiran `(IM)`.
4b2. **"Kode lengkap kok diblokir?"** Cek baris kembar `*X` dulu: 266 icdx punya baris
   seed (`I10`, vc=1) + baris legacy (`I10X`, vc=0). Suruh pilih baris yang tidak merah,
   JANGAN lepas `blockHeader`. Kode kategori asli (E11, K29) beda kasus — 210.311 baris
   EMR RJ lama memakai pola kategori ini.
4c. **Asterisk sudah tercakup guard primer**: 852/852 kode `asterisk=1` ber-`accpdx='N'`,
   jadi tidak perlu guard asterisk terpisah untuk aturan "asterisk tak boleh primer".
   Yang BELUM ada: validasi pasangan dagger–asterisk dalam satu list.
5. Fix data baris kembar: `database/sql/2026_06_04_sync_dup_icdx_validation_flags.sql`
   (cek dulu sudah dieksekusi atau belum sebelum menyimpulkan bug LOV).
