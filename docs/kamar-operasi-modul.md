# Modul Kamar Operasi (OK) — sirus-php82

Port penuh dari Oracle Forms **`rit006x.fmb`** (blok `TRANSAKSI_OK`) ke Laravel/Livewire.
Modul ini melayani **RJ, UGD, dan RI**. Cara kerja per layanan + seluruh titik hilir
(tagihan, kwitansi, jurnal) ada di §9.

Terkait: `docs/laborat-architecture.md` (pola satelit penunjang), skill `laborat`,
skill `administrasi-inline-edit`, skill `livewire-input-patterns`, skill `oracle-quirks`.

---

## 1. Konsep

Satelit penunjang yang menempel ke kunjungan induk — sama persis konsepnya dengan
Laboratorium: **ruangan mengirim → petugas OK memproses → biayanya kembali sebagai
baris tagihan di kunjungan induk.**

```
RAWAT INAP                 KAMAR OPERASI                    TAGIHAN RI
  order ─┐
         ▼
   [A] Proses Transaksi ──Trf Biaya-INAP──▶ [L] Selesai ──▶ rstxn_rioks (11 baris)
         │  (bebas edit)                        │
         │                                      └─ Batal Transaksi (L→A, hapus rioks)
         └─ Batalkan Pendaftaran (A→F, detail tetap ada)
```

| Status | Arti | Padanan lab |
|---|---|---|
| `A` | Proses Transaksi — tarif & detail bebas diubah | `C` |
| `L` | Transaksi Selesai — biaya sudah masuk tagihan, terkunci | `H` |
| `F` | Dibatalkan — transaksi tidak jadi diproses; detail tetap sebagai riwayat | `F` |

`NVL(ok_status,'A')` — baris ber-status NULL diperlakukan sebagai `A`, mengikuti form legacy.

---

## 2. Model data

Semua tabel **sudah ada** di Oracle dan aktif dipakai sejak 2010.

```
rstxn_rihdrs (kunjungan induk, PK rihdr_no)
      ▲ rihdr_no
      │
rstxn_oks  (PK ok_reg; ok_status, ok_date, dr_id, dr_id_ok, diag_id,
      │     sl_codefrom='01', 11 kolom pos tarif, 3 kolom jasa on call,
      │     4 kolom emp_id crew)
      ├── rstxn_okacts    (PK okact_id; accdoc_id → rsmst_accdocs, okact_price)
      ├── rstxn_okobats   (PK okobat_id; product_id → immst_products, qty, price)
      └── rstxn_okomlops  (PK omlop_dtl; emp_id → hrmst_employees, omlop_fee, oncallomlop_fee)

rstxn_rioks (PK ok_no GLOBAL, FK rihdr_no + ok_reg) ← hasil transfer, 11 pos
```

**PK-nya global dan tanpa sequence** (`ok_reg`, `ok_no`, `okact_id`, `okobat_id`, `omlop_dtl`).
Lihat §6 soal penanganan tabrakan.

---

## 3. Pos tarif — `App\Support\KamarOperasiTarif`

Satu kelas jadi sumber tunggal: daftar pos, tarif baku, pemetaan crew, dan **rumus hitung ulang**.

| Konstanta | Isi |
|---|---|
| `POS` | 11 kolom fee → keterangan yang ditulis ke `rstxn_rioks.ok_desc` |
| `LABEL` | label ringkas untuk layar |
| `POS_TURUNAN_DETAIL` | `oprdoc_fee` (Σ okacts), `equipment_fee` (Σ qty×harga okobats) — tak boleh diketik |
| `POS_GAJI_DOKTER` | `oprdoc_fee`→`dr_id`, `anesdoc_fee`→`dr_id_ok` |
| `POS_ONCALL` | 3 kolom on call — **TIDAK ditagihkan ke pasien** |
| `CREW` | 6 posisi → pos jasa & pos on call miliknya |
| `PERSEN_DARI_OPERATOR` | anestesi 50%, asisten opr/anes & instrument 10% |
| `TARIF_BAKU` | OM LOP 50rb, sewa OK 400rb, perawat 100rb, sewa alat 350rb, pengganti anestesi 0 |

> ⚠️ **Urutan dan teks `POS` tidak boleh diubah.** Laporan lama (kwitansi, pendapatan,
> piutang) mengelompokkan biaya dari string `ok_desc`, bukan dari kode.

### `hitungUlang($okReg, $row)` — satu-satunya tempat rumus ditulis

Dipanggil dari **6 pemicu**: tombol Hitung Tarif OK, tambah/hapus tindakan,
tambah/hapus bahan-alat, dan order dari EMR RI. Kalau rumusnya disalin ke masing-masing,
angka pasien akan berbeda tergantung lewat pintu mana petugas masuk.

Aturannya:
1. `oprdoc_fee` & `equipment_fee` **selalu** dijumlah ulang dari tabel detail.
2. Pos persentase **selalu disegarkan** dari `oprdoc_fee` terkini — persentase itu alat
   bantu hitung, bukan pengunci (keputusan user 2026-07-31).
3. Tarif baku flat **hanya mengisi yang masih NULL** — penyesuaian petugas (mis. OM LOP
   50rb diubah 75rb) tidak boleh kembali ke baku.
4. Total = dijumlah dari nilai yang **benar-benar tersimpan**, bukan dari persentase.

Wajib dipanggil di dalam `DB::transaction` dengan baris `rstxn_oks` sudah `lockForUpdate`.

---

## 3b. Susunan komponen — dipecah per bagian seperti Administrasi RJ

```
penunjang/kamar-operasi/
  ⚡daftar-kamar-operasi.blade.php          worklist + tombol Tambah Operasi
  ⚡daftar-kamar-operasi-actions.blade.php  SHELL modal (605 baris)
  ⚡daftar-kamar-operasi-tambah-actions     buat transaksi baru
  display-pasien-kamar-operasi/             identitas + info transaksi
  crew-jasa-kamar-operasi.blade.php         crew + pos tarif + jasa on call
  tindakan-kamar-operasi.blade.php          tab Tindakan Operasi
  bahan-alat-kamar-operasi.blade.php        tab Bahan dan Alat

app/Http/Traits/Txn/Penunjang/KamarOperasiTrait.php   guard bersama
app/Support/KamarOperasiTarif.php                     registry pos + mesin tarif
```

**Shell** hanya memegang identitas, total, status, dan aksi tingkat transaksi
(Hitung Tarif OK, Trf Biaya-INAP, Batal Transaksi). Semula satu file 1.999 baris;
dipecah supaya tiap bagian bisa dibuka & diubah sendiri.

**Kontrak induk–anak** (sama persis dengan Administrasi RJ):
1. Anak menerima `:okReg` dan **memuat datanya sendiri dari DB** — tidak mewarisi
   state induk yang bisa basi. Status kunci pun dibaca ulang tiap `findData()`.
2. Sesudah menulis, anak `dispatch('kamar-operasi.updated')`.
3. Shell & anak lain mendengar event itu untuk menyegarkan diri.
4. Shell meneruskannya ke `refresh-after-kamar-operasi.saved` supaya worklist di
   belakang modal ikut segar — kalau tidak, kolom Total Tarif di sana basi sampai
   modal ditutup.

**`KamarOperasiTrait`** memuat guard yang dipakai SEMUA anak: `isAllowedRoleOk`,
`isAllowedBatalOk`, `kunciBarisOk`, `catatLogOk`, `jalankanDenganRetryOk`,
`statusOk`, `sumberRefOk`, `kunciIndukOk`, `indukAktifOk`. Jangan disalin per komponen — satu anak bisa ketinggalan
saat aturannya berubah lalu diam-diam melewati penguncian atau audit log.

---

## 4. Dua pintu masuk (meniru lab)

OK **tidak punya tabel order terpisah** seperti `lbtxn_checkuphdrs`. Order langsung
membuat header `rstxn_oks` berstatus `A` — sama dengan yang dibuat petugas OK sendiri.
Pembedanya hanya audit log.

| Pintu | Komponen | Audit log |
|---|---|---|
| Petugas OK | `penunjang/kamar-operasi/⚡daftar-kamar-operasi-tambah-actions` | `Buat transaksi OK No.X` · kategori **ADMIN** |
| Ruangan mengirim | `ri/emr-ri/pemeriksaan-ri/penunjang/kamar-operasi/rm-kamar-operasi-ri-actions` | `Order Kamar Operasi No.X` · kategori **MR** |

Order dari ruangan boleh menyertakan **diagnosa pra-operasi** (`diag_id` — simpan `diag_id`,
BUKAN `icdx`; lihat skill `diagnosa-flow`) dan **rencana tindakan** yang langsung mengisi
`rstxn_okacts` lalu memanggil `hitungUlang()`.

Daftar read-only per kunjungan: `rm-daftar-kamar-operasi-ri`.

---

## 5. Guard pulang DUA ARAH — bagian terpenting

Ini yang membuat modul konsisten, dan **wajib ikut diport ke RJ/UGD**.

**MAJU** — `EmrRITrait::checkOkPendingRI()` dipakai di `kasir-ri.blade.php::postTransaksi()`:
pasien tidak bisa dipulangkan selama ada `rstxn_oks` ber-`NVL(ok_status,'A')='A'`.
Pesannya menyebut nomor OK-nya (`daftarOkPendingRI()`). Urutan guard: lab dulu, baru OK.

**MUNDUR** — begitu kunjungan induk tidak aktif lagi, Batal Transaksi ikut tertutup
(tombol `disabled` + banner). Menghapus biaya dari tagihan yang sudah ditutup membuat
total kwitansi tidak cocok. Ini menyamai aturan Batal Transfer UGD→RI.

> **Kenapa penting:** transfer mensyaratkan `ri_status='I'`. Tanpa guard MAJU, pasien bisa
> pulang duluan dan biaya operasinya terkunci selamanya di luar tagihan. Itulah asal
> **15 transaksi macet status `A`** sejak 2025-02-19 yang ditemukan saat modul ini dibuat.

Kalimat penguncian disusun **per aksi** lewat `pesanTerkunci('batal'|'transfer')` — jangan
satu string untuk dua tombol.

---

## 6. Beda dari legacy — sengaja, jangan dikembalikan

| Legacy `rit006x.fmb` | Di sini | Alasan |
|---|---|---|
| `COMMIT` di tengah proses transfer | seluruh INSERT + UPDATE status **satu** `DB::transaction` | legacy: gagal di pos ke-sekian meninggalkan biaya separuh yang tak bisa dibatalkan |
| `MAX(ok_no)+1..+11` sekali di awal | `MAX+1` per baris + **retry ORA-00001** | `ok_no` PK global; dua petugas transfer bersamaan bertabrakan |
| — | `lockForUpdate` + `lockRIRow` + audit log dalam transaksi yang sama | |
| Pos persentase hanya diisi bila NULL | selalu disegarkan | lihat §3 |
| `EXCEPTION WHEN OTHERS` menelan error | `RuntimeException` → toast, `QueryException` → retry/tampilkan | |

> **`SELECT MAX(...) FOR UPDATE` DITOLAK Oracle** (`ORA-01786`, tidak boleh pada query
> agregat). Mengunci baris induk juga tidak menolong karena PK-nya global lintas kunjungan.
> Solusinya retry, bukan lock.

---

## 7. Dampak ke luar modul

**`oprdoc_fee` dan `anesdoc_fee` menggerakkan pendapatan dokter.** View
`RSVIEW_NEWDOCSALARIES` membaca `RSTXN_OKS` langsung: `SUM(OPRDOC_FEE)` atas `dr_id`
(DESC_DOC `'OPERATOR'`) dan `SUM(ANESDOC_FEE)` atas `dr_id_ok` (`'ANASTESI'`), hanya untuk
kunjungan `ri_status='P'`.

Konsekuensinya: mengubah dua pos itu **atau mengganti dokternya** menggeser tagihan pasien
DAN Laporan Pendapatan Jasa Dokter. Karena itu `dr_id`/`dr_id_ok` tidak boleh dikosongkan,
dan setiap perubahan tarif/crew diaudit ke `userLog` kunjungan induk.

**Istilah di layar** (jangan tertukar):
- `JD Operator` / `JD Anestesi` = nama **pos tarif** (JD = Jasa Dokter)
- badge `Dokter` = penanda pos itu **juga** jadi pendapatan dokter
- "pendapatan dokter" untuk konsep penghasilan — jangan tulis "jasa dokter" di situ

---

## 8. Pola UI yang dipakai (ikut diport)

- **Display pasien** `display-pasien-kamar-operasi` — satu kartu, kiri identitas lengkap
  via `MasterPasienTrait`, kanan info transaksi ringkas. Tema sama dengan display RJ/UGD/RI
  dan `display-pasien-laborat`. Butuh listener `refresh-after-*.saved` karena prop `okReg`
  tidak berubah selama modal terbuka.
- **Susunan modal** meniru Administrasi RJ: header display + kartu total → body 1:1
  (Crew & Jasa | tab detail) → footer aksi.
- **Bingkai per KELOMPOK, bukan per sel**: satu bingkai hijau "Ditagihkan ke pasien"
  (6 crew + pos lainnya), satu bingkai putus-putus "Tidak ditagihkan ke pasien" (on call).
- **Nama crew dipasangkan dengan jasanya** (Dr. Operator ↔ JD Operator) — bukan dua daftar
  terpisah yang harus dicocokkan sendiri.
- **Panel info** "Arti penanda pada tarif" — gaya biru-info standar, default tertutup.
- **Warna** pakai token semantic (`bg-warning-tint`, `text-warning-deep`, `border-warning/30`),
  bukan `amber-*` mentah. Cek dulu kelasnya ada di CSS terbangun sebelum memakai varian
  opacity baru.
- **Tabel di tab** ikut standar Administrasi RJ: pembungkus `rounded-2xl` + `overflow-x-auto`,
  `thead` `text-sm text-gray-600`, `th` `px-4 py-3`, baris hover, `tfoot` Total.
- **Semua input angka** `x-text-input-number` tanpa override kelas. Simpan dipicu hook
  `updatedTarif/updatedOncall` karena komponen sinkron lewat `$wire.set` saat blur.
  > Jebakan: kalau nilai lama dibandingkan dari array yang ter-bind `wire:model`, isinya
  > sudah nilai BARU saat hook jalan → "tidak berubah" terus dan simpan tak pernah jalan.
  > Baca nilai lama dari DB.
- **Fokus otomatis saat modal dibuka** — `openActions()` mengirim `kamar-operasi-fokus`
  ke kotak pencarian tab pertama, **hanya bila `!$isFormLocked`** (mode entry). Pola sama
  dengan `administrasi-rj::openModal()` yang mengirim `focus-lov-jasa-karyawan`.
- **Penjaga anti-rebut fokus** — handler fokus mengabaikan permintaan kalau
  `document.activeElement` sudah berupa `input/select/textarea` (user sedang mengetik),
  dan mencoba 3× (0/150/400ms) karena elemen tujuan bisa belum ter-render. Persis pola
  `jasa-karyawan-rj`.
  > ⚠️ **`blur()` dan penjaga anti-rebut itu SEPASANG.** Saat berpindah tab/field,
  > `document.activeElement` masih kolom asal — juga sebuah `input` — sehingga penjaga
  > membatalkan permintaan fokus yang kita kirim sendiri. Karena itu listener tab
  > memanggil `document.activeElement?.blur()` DULU. Mengambil penjaganya tanpa
  > `blur()`-nya membuat "tab pindah tapi kursor tertinggal" — bug yang butuh tiga
  > putaran untuk ditemukan.
- **Rantai Enter** (skill `livewire-input-patterns` §7):
  - kartu tagihan: helper `enterBerikutnya()` — pindah ke input berikutnya menurut urutan
    DOM. Selektornya `input:not([disabled])` + saring `offsetParent`, **bukan**
    `inputmode=numeric`, supaya kotak pencarian LOV crew tidak terlewati.
  - pilih crew dari LOV → fokus turun ke kolom Jasa miliknya (`ok-jasa-<kolom>`).
  - form tambah: LOV → Enter → kolom angka → Enter → simpan → fokus balik ke LOV.
  - **kolom kosong + Enter = "selesai di sini"** → lompat ke tab berikutnya, dan di tab
    terakhir (Bahan dan Alat) → fokus kembali ke kotak pencarian LOV. Jadi seluruh entri
    bisa diselesaikan Enter-Enter tanpa mouse.
    Pola sama dengan Administrasi RJ (`if (!$event.target.value?.trim()) $dispatch(...)`).
  > Jebakan: pergantian tab **harus lewat server**. Enter di kolom angka memicu blur →
  > `$wire.set`, dan respons Livewire me-morph DOM sambil membawa `activeTab` lama sehingga
  > perubahan sisi Alpine ketimpa balik. `lanjutKeTab()` set properti + dispatch
  > `kamar-operasi-tab`; event browser dikirim SETELAH morph, jadi aman.
- Perpindahan fokus lintas komponen lewat satu event `kamar-operasi-fokus` + `ke`, target
  dicari dari `id` wrapper — supaya komponen LOV bersama tidak perlu diubah.
  `lanjutKeTab()` mengirim **dua** event sekaligus: `kamar-operasi-tab` (ganti panel) dan
  `kamar-operasi-fokus` (pindahkan kursor). Mengirim salah satunya saja = tab pindah tanpa
  kursor, atau sebaliknya.

### Beda dari Administrasi RJ (sadar, jangan "diseragamkan" tanpa membaca ini)

| Hal | Administrasi RJ | Kamar Operasi | Alasan |
|---|---|---|---|
| Ganti tab | murni Alpine (`$dispatch('administrasi-rj-goto-tab')`) | method server `lanjutKeTab()` | Enter di OK juga terjadi di `x-text-input-number` yang memicu `$wire.set`; morph-nya menimpa `tab` sisi Alpine |
| Handler fokus | per komponen tujuan (`x-on:focus-<nama>.window` + `$refs`) | satu handler di shell + `document.getElementById` | di RJ tiap tab komponen terpisah sejak awal; di OK shell yang memiliki kontainer tab |
| Nama event fokus | satu event per tujuan (~8) | satu event + payload `ke` | konsekuensi baris di atas |
| Rantai Enter dalam kartu | — | `enterBerikutnya()` urut DOM | tidak ada padanannya di RJ |
| Fokus sesudah pilih crew | — | ke kolom Jasa milik crew itu | tidak ada padanannya di RJ |

Untuk RJ/UGD nanti: baris **ganti tab** WAJIB ikut cara OK (masalahnya sama). Baris
lainnya boleh mengikuti RJ kalau tab-nya dipecah jadi komponen terpisah.

---

## 9. Layanan RJ / UGD / RI — status & cara kerja

Sejak 2026-07-31 modul ini melayani **tiga** layanan, mengikuti pola modul Laboratorium.

### Sumber kebenaran layanan
`rstxn_oks.status_rjri` (`'RJ' | 'UGD' | 'RI'`) + `ref_no` — persis padanan
`lbtxn_checkuphdrs.status_rjri` + `ref_no`. `ref_no` menunjuk `rj_no` untuk RJ/UGD dan
`rihdr_no` untuk RI.

`rihdr_no` **hanya diisi untuk RI** (kolomnya FK ke `rstxn_rihdrs`, jadi `rj_no` RJ/UGD
tidak boleh dititipkan ke sana). Kolom itu dipertahankan karena view
`RSVIEW_NEWDOCSALARIES` dan laporan lama masih membacanya.

Semua percabangan ada di **satu tempat** — `KamarOperasiTrait`:

| Helper | Gunanya |
|---|---|
| `sumberRefOk($okReg)` | `['sumber' => 'RJ\|UGD\|RI', 'refNo' => int]`, ber-NVL untuk baris cacat |
| `tabelBiayaOk($sumber)` | `rstxn_rjoks` / `rstxn_ugdoks` / `rstxn_rioks` |
| `kolomIndukBiayaOk($sumber)` | `rj_no` / `rj_no` / `rihdr_no` |
| `kunciIndukOk($sumber,$refNo)` | lock baris kunjungan + kembalikan statusnya |
| `indukAktifOk($sumber,$status)` | RI `'I'`, RJ/UGD `'A'` |
| `sebabIndukTerkunciOk()` | kalimat sebab, dirakit per aksi oleh pemanggil |
| `catatLogOk($sumber,$refNo,$teks)` | arahkan ke `appendAdminLog{RJ,UGD,RI}` |

Jangan menyalin ulang percabangan ini ke komponen anak — aturan "boleh transfer / boleh
batal" harus persis sama di semua pintu.

### Tabel biaya terpisah, bukan menumpang Lain-Lain
`rstxn_rjoks` & `rstxn_ugdoks` dibuat khusus (DDL
`database/sql/2026_07_31_alter_kamar_operasi_rj_ugd.sql`). **Keputusan user 2026-07-31:**
menitipkannya ke `rstxn_*others` membuat pendapatan operasi menyamar sebagai pendapatan
lain-lain di jurnal. Strukturnya identik `rstxn_rioks` (`ok_no` PK global, `ok_desc`,
`ok_price`, `ok_reg`) dengan FK kunjungan `rj_no`.

### Status kunjungan yang mengizinkan transfer / batal
RI `ri_status='I'`, RJ & UGD `rj_status='A'`. Jangan disamakan begitu saja — lihat
memory `feedback_ugd_rj_struktur_beda`.

### Guard supaya biaya tidak pernah tersangkut
| Titik | Guard |
|---|---|
| `kasir-ri::postTransaksi` | `checkOkPendingRI` — pasien tak bisa pulang |
| `kasir-rj::postTransaksi` | `checkOkPendingRJ` — kunjungan tak bisa dibayar |
| `kasir-ugd::postTransaksi` | `checkOkPendingUGD` — idem |
| `transfer-rj-ke-ugd` | `checkOkPendingRJ` **+** tolak bila `rstxn_rjoks` sudah terisi |
| `transfer-ugd-ke-ri` | `checkOkPendingUGD` **+** tolak bila `rstxn_ugdoks` sudah terisi |

Alasan guard kedua pada transfer antar-unit: `rstxn_*tempadmins` memetakan biaya ke
**kolom tetap** (`rj_admin, poli_price, acte_price, actp_price, actd_price, obat, lab,
rad, other, rs_admin`) dan **tidak punya kolom operasi**. Tanpa guard itu, biaya operasi
yang sudah masuk tagihan RJ/UGD akan hilang diam-diam saat pasien dipindah.

Biaya operasi **ikut berpindah** antar unit lewat kolom `ok` di `rstxn_ugdtempadmins` &
`rstxn_ritempadmins` (DDL `database/sql/2026_07_31_alter_tempadmins_kolom_ok.sql`).
Tanpa kolom itu nominalnya hilang senyap, karena kedua tabel memetakan biaya ke **kolom
tetap** — tidak ada tempat menitipkannya.

### Total tagihan — SATU daftar komponen
Biaya operasi jadi komponen `'kamarOperasi'` di `calculate{RJ,UGD}Costs()`, sejajar
`lab`/`rad`/`other`. Fungsi itu dipakai kasir **dan** transfer antar-unit, jadi jangan
menambahkan biaya operasi lagi di `hitungTotal()` — nanti dobel.

Tiap komponen transfer memetakan `'ok' => $costs['kamarOperasi']` saat insert, dan
`'ok' => $temp->ok` saat cascade UGD→RI.

> **Menambah komponen biaya baru?** Kolomnya harus ada di `rstxn_*tempadmins` DAN
> ditambahkan ke **semua** ekspresi yang menjumlah kolom tetap. Saat kolom `ok` dipasang
> ada **11** tempat: `calculateRICosts` (EmrRITrait), kasir RI/UGD, administrasi RI/UGD,
> `transfer-ugd`, `transfer-ugd-ke-ri`, `trf-ugd-rj-ri`, PendapatanRs (2), PiutangPasien.
> Cara mencarinya: grep baris yang menyebut `rs_admin` bersama `obat|lab|rad` — penulisan
> `nvl()`-nya tidak seragam (ada yang berspasi, ada yang rapat), jadi grep satu pola saja
> akan meleset. `calculateRICosts` sempat terlewat persis karena ini.

### Kwitansi
Rincian kwitansi RJ & UGD **tidak** dihitung di PHP — dibaca dari view `RSVIEW_RJSTRS` /
`RSVIEW_UGDSTRS` (`ORDER BY txn_no`). Pos operasi ditambahkan sebagai satu cabang
`UNION ALL` di masing-masing view: `database/sql/2026_08_01_view_kwitansi_rj_ugd_operasi.sql`
(`txn_id` = `KAMAR OPERASI`, txn_no 10 di RJ dan 11 di UGD), dikelompokkan per `ok_desc`
supaya tiap pos tarif jadi barisnya sendiri seperti LABORAT.

Nomor pos lama **tidak** dinomori ulang — view ini juga dibaca Oracle Dev 6i
(lihat [[project_dual_system_oradev_php82]]).

Tidak dobel hitung: cabang `TRF RJ` di view UGD menjumlah `total_biayarj` yang sudah
memuat operasi RJ; cabang baru hanya membaca `rstxn_ugdoks` (operasi kunjungan UGD sendiri).

Kwitansi RI memakai `calculateRICosts()` — pos `ok` (operasi RI sendiri) dan `trfUgdRj`
(termasuk operasi yang dibawa dari RJ/UGD).

### Jurnal & arus kas — `TKVIEW_ACCOUNTS`

**Saldo kas tidak terpengaruh** pos biaya mana pun. Halaman Cek Saldo Kas
(`transaksi/keuangan/saldo-kas`) memakai rumus form 6i (`App\Support\Keuangan\SaldoKas` di atas `Jurnal`/`JurnalCabang`: jurnal dibaca langsung dari tabel transaksi tanpa view, lihat `docs/jurnal-keuangan.md`; `txn_acc = akun, SUM(D − K)`, dipotong per shift), dan akun kas
hanya muncul di cabang **BAYAR** — yaitu uang yang benar-benar diterima.

Yang menuntut penyesuaian adalah sisi **pendapatan**. Tiap pos biaya punya sepasang cabang
di `TKVIEW_ACCOUNTS`: piutang unit (`RJ1` 4.1AA / `UGD1` 4.1BB / `RI1` 4.1CA) ↔ akun
pendapatan pos. Kamar Operasi sudah punya 11 akun (`OK1`–`OK11` → 4.1F01–4.1F11, dinamai
per PERAN: OPERATOR, ANASTESI, INSTRUMENT, …) — tapi 22 cabangnya membaca
`RSTXN_OKS ... where rihdr_no = a.rihdr_no FROM RSTXN_RIHDRS`, sehingga operasi RJ/UGD
(yang `rihdr_no`-nya NULL) tidak pernah terjaring.

Diukur pada kunjungan uji: tagihan Rp 1.115.000, terjurnal hanya Rp 179.000 — **selisih
Rp 936.000 persis nilai operasi**. Kasir menagih penuh & pembayaran mengkredit piutang
penuh, tapi pendapatannya tak pernah diakui → piutang unit melenceng.

Diperbaiki dengan 44 cabang baru (11 pos × 2 arah × 2 unit) di
`database/sql/2026_08_01_view_tkview_accounts_ok_rj_ugd.sql`, memakai pemetaan pos yang
sama persis dengan cabang RI. Akun pendapatan **dipakai bersama** dengan RI karena dinamai
per peran, bukan per unit; kalau nanti ingin dipisah, cukup ganti `conf_id` di cabang baru
+ tambah barisnya di `TKACC_CONFACCTXNS` — struktur view tak perlu disentuh.

> **`EXISTS` di tiap cabang baru JANGAN dihapus.** Cabang lama menerbitkan satu baris untuk
> SETIAP kunjungan walau nilainya nol. Meniru itu apa adanya membengkakkan view dari
> 26 juta jadi **44 juta baris**, karena RJ/UGD punya ratusan ribu kunjungan. Dengan
> `and exists (select 1 from RSTXN_OKS x where ... x.ok_status='L')` jumlah baris kembali
> persis 26.102.285.

**Laporan lain** — Pendapatan RS harian/bulanan/tahunan, Piutang Pasien, Pembayaran Piutang
semuanya lewat `PendapatanRsTrait` / `PiutangPasienTrait` yang sudah memuat pos operasi.
Bagian **Kas & Bank** di `monitoring-keuangan` masih placeholder "Dalam pengembangan".

Pendapatan Jasa Medis & Jasa Karyawan membaca `rstxn_*actparams` / `*actemps`; jasa crew OK
tidak pernah masuk situ — sama seperti perlakuan di RI selama ini, sengaja tidak diubah.

> **Jebakan saat menguji jurnal:** label baris jurnal hanya memuat nomor kunjungan
> (`'LAB ('||rj_no||'/'||reg_no||')'`), dan **nomor RJ lama beririsan dengan nomor UGD**
> (mis. 203858 ada di kedua tabel). Menyaring dengan `txn_name LIKE '%(no/%'` akan
> menjaring baris unit lain. Ukur berbasis **selisih sebelum vs sesudah**, jangan
> berbasis total.

### Titik yang sudah ikut menghitung biaya operasi RJ/UGD
- Tab **Kamar Operasi** (read-only) di Administrasi RJ & UGD — `kamar-operasi-{rj,ugd}.blade.php`,
  disisipkan sesudah Radiologi; ikut ke `sumTotalRJ` lewat `$sumKamarOperasi`.
- `kasir-rj` / `kasir-ugd` — `rjTotal`.
- `PendapatanRsTrait` (4 agregat RJ/UGD) & `PiutangPasienTrait` (2 agregat) — join
  `rstxn_{rj,ugd}oks` + komponen `NVL(ok.v,0)`.
- `RSVIEW_NEWDOCSALARIES` — 4 cabang baru seq 19-22 (`database/sql/2026_07_31_view_docsalaries_ok_rj_ugd.sql`),
  plus 4 `case` DESC_DOC di `pendapatan-jasa-dokter::fetchSourceJson()`.
- **Kwitansi RJ & UGD** — cabang `KAMAR OPERASI` di `RSVIEW_RJSTRS` / `RSVIEW_UGDSTRS`
  (`database/sql/2026_08_01_view_kwitansi_rj_ugd_operasi.sql`).
- **Rantai transfer** — kolom `ok` di `rstxn_{ugd,ri}tempadmins`
  (`database/sql/2026_07_31_alter_tempadmins_kolom_ok.sql`) + tampil sebagai kolom
  **Operasi** di `transfer-ugd` dan `trf-ugd-rj-ri`.

**Urutan menjalankan SQL** (tiga skrip, boleh sekaligus):
`2026_07_31_alter_kamar_operasi_rj_ugd.sql` → `2026_07_31_alter_tempadmins_kolom_ok.sql` →
`2026_07_31_view_docsalaries_ok_rj_ugd.sql` → `2026_08_01_view_kwitansi_rj_ugd_operasi.sql` →
`2026_08_01_view_tkview_accounts_ok_rj_ugd.sql`.

### Worklist & tampilan
`⚡daftar-kamar-operasi` memakai inline view `OK_DENGAN_KUNJUNGAN`: tiga scalar subquery
ber-PK meresolve `reg_no` / `status_induk` / `unit_name` sesuai `status_rjri`. Dipilih
karena `rstxn_oks` kecil (±5rb baris) sehingga jauh lebih murah daripada UNION tiga tabel
kunjungan ratusan ribu baris. Ada filter **Layanan**, dan nilainya dibawa ke modal Tambah.

> Urutan join **mengikat**: tabel kunjungan dulu, baru `rsmst_pasiens`. Menaruh pasiens
> lebih dahulu membuat alias `h` belum dikenal → `ORA-00904 "H"."REG_NO"`.
> Lihat memory `feedback_oracle_yajra_join_binding_order`.

Label kolom ikut layanan: RI "No Inap"/"Kamar", RJ "No Reg"/"Poli", UGD "No Reg"/"Cara Masuk".
Tombol transfer pun ikut: `Trf Biaya-RJ` / `Trf Biaya-UGD` / `Trf Biaya-INAP`.

### LOV tarif tindakan
RI memakai `lov-jasa-dokter-ri` (harga per kelas kamar, butuh `riHdrNo`). RJ/UGD tidak
punya kelas kamar → memakai `lov-jasa-dokter` biasa. Payload keduanya identik
(`accdoc_id` / `accdoc_desc` / `accdoc_price`), jadi handler `pilihTindakan()` tetap satu.

### Dua pintu masuk — lengkap di tiga layanan
| Pintu | Komponen |
|---|---|
| Petugas OK | `penunjang/kamar-operasi/⚡daftar-kamar-operasi-tambah-actions` (pilih RJ/UGD/RI) |
| Ruangan/poli mengirim | `{ri/emr-ri/pemeriksaan-ri, rj/emr-rj/pemeriksaan, ugd/emr-ugd/pemeriksaan}/penunjang/kamar-operasi/rm-kamar-operasi-{ri,rj,ugd}-actions` |

Ketiganya dipasang di tab **Pelayanan Penunjang** masing-masing, sesudah Radiologi,
berpasangan dengan daftar read-only `rm-daftar-kamar-operasi-{ri,rj,ugd}`.

Beda RJ/UGD dari RI:
- LOV tarif tindakan memakai `lov-jasa-dokter` (tarif dasar), **bukan** `-ri` — RJ/UGD
  tidak punya kelas kamar. Payload keduanya identik.
- Guard status kunjungan `rj_status='A'` (bukan `ri_status='I'`), lewat
  `check{RJ,UGD}Status` + `lock{RJ,UGD}Row` + `appendAdminLog{RJ,UGD}` kategori `MR`.
- `rihdr_no` diisi **NULL** — kolomnya FK ke `rstxn_rihdrs`.

### Yang BELUM ada
- Dari modul RI sendiri: `diag_id_ok`, `case_id`, `crew_id_crew*` (LOV `rsmst_okcrews`),
  parameter `omlop_jm`/`omlop_person`/`countomlop_crew`, cetak, viewer Rekam Medis,
  tautan ke dokumen klinis Laporan Operasi RI.

### Pembatalan (`ok_status='F'`)
- `A → F`: **Batalkan Pendaftaran** — tersedia di worklist dan footer modal untuk
  transaksi berstatus Proses Transaksi. Hanya `Admin` / `Supervisor Penunjang`.
  Kunjungan induk harus masih aktif; detail tindakan/bahan/crew tidak dihapus.
- `L → A`: **Batal Transaksi** — tetap di footer modal, menghapus baris biaya di
  kunjungan induk dan mengembalikan status ke Proses Transaksi.

---

## 10. Cara verifikasi (dipakai selama modul ini dibangun)

Tidak ada test otomatis. Yang dipakai:
1. `Blade::compileString()` → `php -l` untuk tiap file; pastikan `?>` tepat **1**.
2. `Livewire::test()` lalu hitung keseimbangan tag (`div`, `table`, `tr`, `td`, `span`, …) —
   tag timpang = layout geser.
3. Uji fungsional di dalam `DB::beginTransaction()` … `DB::rollBack()` terhadap data
   produksi nyata, lalu **buktikan data kembali utuh**.
4. Cek kelas Tailwind baru benar-benar ada di `public/build/assets/*.css` sebelum dipakai.
