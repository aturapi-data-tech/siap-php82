---
name: ews
description: Arsitektur & jebakan modul EWS (Early Warning System) — master parameter/rentang/respon per varian (DEWASA/ANAK/NEONATUS/MEOWS) di RSMST_EWS_*, mesin skor App\Support\Ews\EwsSkor, integrasi ke Observasi Lanjutan RI & UGD (JSON tandaVital[].ews), badge di display pasien. WAJIB dibaca sebelum mengubah ambang skor, menambah parameter/varian, menyentuh form/tabel Observasi Lanjutan, atau membuat pemakai baru skor EWS (RJ, cetak RM 93a, daftar pasien EWS tinggi). Jebakan: charset Oracle Latin-1, directive di atribut komponen, skor disimpan bukan dihitung ulang.
---

# Modul EWS (sirus-php82)

Dok utama: **`docs/ews-modul.md`** (peta berkas, pemasangan, model data, bentuk JSON,
perilaku form, jebakan). DDL: `docs/ddl-ews.sql`. Branch pengembangan: `feat/ews-observasi`.

## 1. Sumber kebenaran — jangan hard-code ambang

| Hal | Di mana |
|---|---|
| Ambang skor, pilihan, respon | tabel `RSMST_EWS_PARAMS` / `RENTANGS` / `RESPONS`, diedit di `/master/ews` |
| Isi bawaan (seed, unit test, dokumentasi) | `App\Support\Ews\EwsDefault` → `php artisan ews:seed [--force|--dry-run]` |
| Pembaca master + cache 10 mnt | `App\Support\Ews\EwsMaster::muat()`; **`flush()` wajib** setelah master berubah |
| Mesin skor (murni, diuji) | `App\Support\Ews\EwsSkor::hitung(varian, nilai, master, umurBulan)` — `tests/Unit/EwsSkorTest.php` |
| Skor terakhir utk badge | `EwsSkor::terakhirDari(tandaVital[])` — dipakai display pasien RI & UGD |

Form Observasi Lanjutan **membangun field & aturan validasi dari master** (`ewsParamTambahan()`,
`aturanEws()`): menambah parameter di master = otomatis muncul di form. Kalau butuh perilaku
khusus per parameter (mis. SpO2 skala 2 menggantikan skala 1, syarat "on O2"), pakai kolom
master `GANTIKAN_KODE` / `SYARAT`, bukan `if` di komponen.

## 2. Varian & umur

`EwsSkor::varianUntukUmur(hari, tahun)`: ≤28 hari NEONATUS, <16 th ANAK, sisanya DEWASA
(konstanta `BATAS_*`). MEOWS **manual**. Teks "untuk siapa" di dropdown = `EwsDefault::VARIAN_UNTUK`
— ubah keduanya bersamaan. Umur dari `rsmst_pasiens.birth_date` (kolom umur master = snapshot).
Saat form dibuka ulang, varian entri terakhir diutamakan (pasien obstetri tetap MEOWS).

## 3. Skor DISIMPAN, bukan dihitung ulang

Entri `observasi.observasiLanjutan.tandaVital[]` membawa kunci datar EWS (kesadaran, oksigen,
alatOksigen, spo2Skala2, keadaanUmum, kardiovaskular, respirasi, nyeri, perdarahan, lochea,
produksiUrine, proteinUrine, djj), `ewsVarian`, dan `ews` = keluaran `hitung()` + `pantauUlang`.
Viewer/cetak/badge membaca `ews` apa adanya → rekam medis konsisten walau ambang master diubah.
Entri lama tanpa `ews` = sah, tampil "-" (guard `is_array($x['ews'] ?? null) && !empty(...['tersedia'])`).

## 4. Jebakan yang sudah dibayar

- **Oracle WE8ISO8859P1**: teks yang masuk DB (label master, seed, log JSON) wajib ASCII/Latin-1
  — `≤ ≥ ₂ ≈ —` tersimpan `¿`. Tulis `<= 8`, `>= 25`, `SpO2`, tanda hubung biasa. Unicode hanya
  di blade. Cek: `INSTR(kolom, '¿') > 0`.
- **`@if (...) id="x" @endif` di dalam tag komponen** → ParseError. Pakai `:id="$cond ? 'x' : null"`.
- **`@if…{{ }}@endif` nempel satu baris** di dalam `<span>` → pecah. Pindahkan ke helper
  (`EwsSkor::labelRespon`).
- Input angka form: `wire:model.blur` (pratinjau skor dihitung di `updated*`), bukan `.live`.
- Grid baris EWS: kelas `grid-cols-*` ditulis literal lewat `match` (bukan dirangkai) agar tidak
  dipangkas Tailwind; jumlah kolom dihitung dari lebar isi varian.
- PK master = MAX+1 dalam transaksi (`EwsMaster::idBaru`), tanpa sequence — netral driver.
- Verifikasi tanpa Oracle: tinker `DB_CONNECTION=sqlite DB_DATABASE=:memory:` + stub tabel;
  `rsview_*` harus **VIEW** sungguhan atas `rstxn_*` (log admin membaca ulang lewat view);
  `sqliteCreateFunction('to_char', ...)`.

## 5. Pemakai skor saat ini

Observasi Lanjutan RI (`emr-ri/observasi-ri/observasi-lanjutan-ri/`) & UGD (`emr-ugd/observasi/`):
baris EWS di atas TTV, pratinjau skor, tabel 6 kolom (sel Tanda Vital dua baris + badge skor per
angka, EWS/Pantau Ulang). Display pasien RI & UGD: badge EWS terakhir + TERLAMBAT. Viewer RM
RI (`asesmen-ri-tab`) & UGD (`cetak-rekam-medis-open`): kolom EWS. Master `/master/ews` +
Simulasi Skor.

Belum ada: RJ, cetak PDF RM 93a, grafik skor, daftar pasien EWS tinggi per ruangan.
