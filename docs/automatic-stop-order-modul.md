# Modul Automatic Stop Order

Order obat golongan tertentu **berhenti otomatis** setelah batas hari kecuali dokter
mengkaji ulang dan menulis order baru (kebijakan PKPO; tujuan: keamanan pasien dan
pengendalian antimikroba KPRA/KFT). Modul ini meniru arsitektur EWS (`docs/ews-modul.md`):
master = ATURAN, hasil per pasien dihitung aplikasi.

Status 2026-09-07: master + mesin hitung + **tab read-only di EMR RI** (kanan Observasi).
Badge di PTO / display pasien RI dan keputusan kaji ulang belum (lihat bagian 5).

## 1. Peta berkas

| Hal | Berkas |
|---|---|
| DDL (2 tabel) | `docs/ddl-automatic-stop-order.sql` |
| Isi bawaan 12 golongan | `App\Support\AutomaticStopOrder\AutomaticStopOrderDefault` → `php artisan automatic-stop-order:seed [--force|--dry-run]` (`app/Console/Commands/AutomaticStopOrderSeed.php`) |
| Pembaca master + cache 10 mnt | `App\Support\AutomaticStopOrder\AutomaticStopOrderMaster::muat()`; `flush()` wajib setelah master berubah; `golonganUntukProduk()` = satu-satunya pintu mesin bertanya "obat ini kena Automatic Stop Order?" |
| Halaman `/master/automatic-stop-order` | `resources/views/pages/master/master-automatic-stop-order/` — list (2 tab: Golongan, Pemetaan Obat), `-actions` (golongan), `-produk-actions` (pemetaan, LOV product) |
| Mesin hitung (murni) | `App\Support\AutomaticStopOrder\AutomaticStopOrderHitung::nilai(eresepHdr, master, sekarang)` — `tests/Unit/AutomaticStopOrderHitungTest.php` |
| Tab EMR RI | `resources/views/pages/transaksi/ri/emr-ri/automatic-stop-order-ri/` — `#[On('open-rm-automatic-stop-order-ri')]`, dipanggil dari `emr-ri.blade.php` bersama tab lain; hitung ulang saat `refresh-after-ri.saved` |
| Rute & menu | `routes/web.php` `master.automatic-stop-order`; `App\Services\AppMenu` grup Master Pelayanan, badge Apotek |

## 2. Pemasangan (tiap environment)

```bash
# 1. DDL — sebagai pemilik schema SIRUS. Periksa dulu tabel senama belum ada:
#    SELECT table_name FROM user_tables WHERE table_name LIKE 'RSMST_STOP_ORDER%';
#    Jalankan isi docs/ddl-automatic-stop-order.sql (Bagian A boleh ORA-00942 di env bersih).
# 2. Isi awal golongan
php artisan automatic-stop-order:seed
# 3. Apoteker memetakan obat lewat /master/automatic-stop-order → tab Pemetaan Obat (tidak ada seed).
```

## 3. Model data

**Golongan** (`RSMST_STOP_ORDER_GOLONGANS`): `GOLONGAN_KODE` unik huruf besar, `GOLONGAN_NAMA`,
`BATAS_HARI` (>0, batas stop), `BATAS_MINIMAL_HARI` (opsional, <= BATAS_HARI: lama pemberian
minimal sebelum boleh dikaji/dihentikan), `KETERANGAN` (kebijakan/syarat lanjut, ASCII saja), `URUTAN`,
`ACTIVE_STATUS` '1'/'0'. Nonaktif = seluruh obat di golongan itu tidak dipantau.

**Pemetaan obat** (`RSMST_STOP_ORDER_PRODUCTS`): PK `PRODUCT_ID` (= `immst_products`), `GOLONGAN_ID`
FK, `CATATAN` per obat (mis. "IV maksimal 120 mg/hari"), `ACTIVE_STATUS`. **Satu obat =
satu golongan.** Tanpa FK ke `immst_products` (milik Oracle Dev 6i); aplikasi memvalidasi
saat simpan. Obat yang tidak dipetakan **diabaikan** Automatic Stop Order — keputusan user: pemetaan per
`product_id`, bukan tebakan dari nama obat.

Bentuk `AutomaticStopOrderMaster::muat()`:
```php
['golongan' => [golongan_id => [...baris]], 'produk' => [product_id => ['golongan_id','catatan','active_status']], 'tersedia' => bool]
```

## 4. Isi bawaan (AutomaticStopOrderDefault)

| Kode | Batas | Catatan |
|---|---|---|
| VASODILATOR_TOPIKAL | 3 | |
| PETHIDIN | 2 | akumulasi norpetidin |
| KETOROLAK | 5 | IV maks 120 mg/hari |
| ANTIKOAGULAN | 7 | LMWH, heparin, fondaparinux |
| WARFARIN | 14 | |
| ANTIINFEKSI_SISTEMIK | 7 | lanjut bila kultur / respon baik / KPRA+KFT; kaji switch IV→oral |
| ANTIVIRAL | 7 | kecuali amantadin & oseltamivir (protokol) |
| ANTIINFEKSI_TOPIKAL | 10 | |
| ANTIFUNGI | 10 | |
| NARKOTIK | 7 | **angka dari salinan rusak — cocokkan ke pedoman RS** |
| KORTIKOSTEROID | 7 | **idem** |
| PENYAKIT_KRONIK | 30 | |

## 5. Belum dikerjakan (rancangan yang sudah disepakati)

- ~~Mesin hitung~~ SUDAH: `AutomaticStopOrderHitung::nilai()` — resep aktif = TTD atau slsNo
  (definisi PTO); hari berjalan dari resep PERTAMA rantai tak terputus, rantai putus bila
  jeda > `JEDA_MAKSIMAL_HARI` (2); status AMAN / MENDEKATI (H-1) / LEWAT (>= batas);
  `belumMinimal` bila < batas minimal; racikan dilewati; obat tak dipetakan dikembalikan
  terpisah (`tidakDipetakan`). Hasil tidak disimpan.
- ~~Tab EMR RI~~ SUDAH (read-only): ringkasan per status, tabel obat terpantau, daftar
  obat aktif yang tidak dipantau, tombol Hitung ulang.
- Keputusan kaji ulang disimpan di JSON RI node `automaticStopOrder[]` per obat (tgl, LANJUT/STOP,
  petugas, catatan); LANJUT mengulang jam mulai. Boleh DPJP & Apoteker (Gate).
- Badge per obat di PTO + ringkasan di display pasien RI. UGD/cetak menyusul.

## 6. Jebakan

- Charset Oracle Latin-1: teks master ASCII (`<= 120 mg`, tanda hubung biasa).
- `AutomaticStopOrderMaster::flush()` setelah setiap tulis master, sama seperti EWS.
- Verifikasi tanpa Oracle: boot `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`,
  buat 3 tabel stub (2 Automatic Stop Order + `immst_products`), `Artisan::call('automatic-stop-order:seed')`, lalu
  `Livewire::test` ketiga komponen — skrip contoh ada di riwayat sesi 2026-09-07.
