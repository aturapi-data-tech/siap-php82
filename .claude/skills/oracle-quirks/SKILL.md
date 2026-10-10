---
name: oracle-quirks
description: Gotcha & pola query Oracle untuk repo ini (Laravel + Oracle Dev 6i hybrid). WAJIB dibaca sebelum menulis/men-debug query DB — terutama saat hasil query kosong tak terduga, error ORA-00904, kolom mixed-case, filter active_status, lookup shift, atau filter wilayah (kab_id).
---

# Oracle Quirks (sirus-php82)

DB ini dipakai bareng oleh sistem legacy **Oracle Dev 6i** dan aplikasi baru **sirus-php82**. Banyak perilaku menyimpang dari MySQL/Postgres. Cek daftar ini sebelum menulis query.

## 1. Empty string `''` = NULL
Oracle memperlakukan `''` sebagai `NULL`. Jangan pernah pakai `<> ''` atau `= ''`.

```php
// SALAH — selalu false di Oracle
->whereRaw("nama <> ''")
// BENAR
->whereRaw("nama IS NOT NULL")
->whereRaw("LENGTH(TRIM(nama)) > 0")
```

## 2. Tidak support JSON_VALUE / JSON_QUERY / JSON_TABLE
Versi Oracle di sini melempar **ORA-00904** untuk fungsi JSON. Jangan dipakai di SQL.

- Pakai `INSTR(...)` / pattern-match string di SQL, **atau**
- Ambil kolom mentah lalu `json_decode()` di PHP.

## 3. Kolom mixed-case harus di-quote via DB::raw
Oracle melipat identifier ke UPPERCASE kecuali di-quote. Kolom mixed-case (mis. `requestTransferTime`) wajib:

```php
->select(DB::raw('"requestTransferTime" as request_transfer_time'))
```

## 4. active_status = string '1' / '0' (BUKAN 'Y'/'N')
Master dari Oracle Dev 6i menyimpan `active_status` sebagai string `'1'` (aktif) / `'0'` (nonaktif).

```php
->where('active_status', '1')
```

## 5. Tabel shift = `rstxn_shiftctls` (BUKAN rsmst_shifts)
Lookup shift berjalan berdasar jam sekarang:

```php
->whereRaw("to_char(sysdate,'HH24:MI:SS') between shift_start and shift_end")
```

## 6. kab_id legacy Tulungagung punya 2 kode
`rsmst_pasiens.kab_id` untuk Tulungagung bisa `'3504'` (BPS) **atau** `'1'` (legacy). Filter "Dalam Kota" harus menyertakan keduanya.

```php
->whereIn('kab_id', ['3504', '1'])
```

## 7. Carbon 3 diffInSeconds sign terbalik
Repo pakai Carbon 3.11.0. `diffInSeconds($other, false)` tandanya kebalik dari Carbon 2. Untuk durasi pakai timestamp mentah:

```php
$durasi = $end->getTimestamp() - $start->getTimestamp();
```

## 8. Cache JSON sirus-php82 vs Oracle Dev 6i
Entry yang dibuat lewat Oracle Dev 6i bisa bypass cache JSON sirus-php82 → tak kelihatan di UI baru walau ada di DB. Saat data "hilang" di UI tapi ada di tabel, curigai jalur cache ini.
