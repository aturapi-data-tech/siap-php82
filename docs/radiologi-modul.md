# Modul Radiologi (Penunjang)

Lokasi utama: `resources/views/pages/transaksi/penunjang/radiologi/`. Semua Volt class-based (`⚡`).

> **Baca dulu — beda arsitektur vs Lab.** Modul lab (`docs/laborat-modul.md`) adalah alur workflow
> status `P→C→H→F` dengan "Proses Administrasi" yang mem-post biaya. **Radiologi TIDAK begitu.**
> Biaya di-post **langsung saat order dibuat** (di EMR atau via tombol Tambah). Modul penunjang
> radiologi (`upload-radiologi`) fungsinya **Upload Hasil** (foto + PDF bacaan). "Status" di radiologi =
> **kelengkapan upload**, bukan status pemeriksaan. Tidak ada batal, tidak ada Mindray, tidak ada etiket.
>
> **PACS sudah berdiri** (Orthanc, PR #33): saat kirim SATUSEHAT, foto yang sudah diupload diangkat
> ke Orthanc lebih dulu supaya `ImagingStudy` punya StudyInstanceUID asli — lihat `docs/pacs-orthanc.md`
> (jalankan `docs/ddl-pacs-study-uid.sql` dulu di tiap environment). Sumber hasil tetap berkas unggahan,
> bukan modality DICOM langsung.
>
> **Tanggal di layar Upload.** Kolom "Order" = `waktu_entry`, yaitu **waktu ordernya dicatat**
> (dari EMR RJ/UGD/RI atau tombol Tambah Pemeriksaan) — **bukan** waktu upload hasil; waktu upload
> tidak disimpan di mana pun, unggahan hanya menulis nama berkas. Filter harian/bulanan memakai
> `NVL(waktu_entry, tgl_header)`; fallback ke tgl kunjungan itu untuk order legacy Oracle Dev 6i
> yang `waktu_entry`-nya NULL — tanpa itu `BETWEEN` membuangnya dan order lama tak pernah muncul.

## Struktur file

| File | Peran |
|---|---|
| `⚡upload-radiologi.blade.php` | **Halaman list/antrian** "Upload Hasil Radiologi". Tabel order per-sumber (toggle RJ/UGD/RI), filter harian/bulanan + status upload, edit inline tarif / dr. pengirim / keterangan, tombol Upload/Replace Foto & Hasil. Embed 3 sibling modal. |
| `⚡upload-radiologi-foto-actions.blade.php` | Modal **upload Foto** (`rad-upload-foto`). Listen `radiologi.foto.open`. |
| `⚡upload-radiologi-bacaan-actions.blade.php` | Modal **Hasil Bacaan** — upload PDF (`rad-upload-pdf`) + Generate PDF dari TinyMCE (`rad-generate`). Listen `radiologi.bacaan.upload.open` / `radiologi.bacaan.generate.open`. |
| `⚡upload-radiologi-tambah-actions.blade.php` | Modal **Tambah order** (`rad-tambah`) — pola **keranjang** seperti lab: toggle sumber in-modal + pilih pasien aktif + grid multi-item `rsmst_radiologis` (tarif editable per item). Listen `radiologi.tambah.open`. |
| `⚡upload-radiologi-view-actions.blade.php` | Modal **viewer file** (`view-radiologi-pdf`) — baca foto/hasil di iframe (pola `radiologi-display` RM), bukan tab baru. Generik: terima `file`+`title`. Foto & Hasil Bacaan dilihat **sendiri-sendiri** (tombol Lihat masing-masing). Listen `radiologi.view.open`. |

**Ordering pemeriksaan** dilakukan dari **EMR** (bukan folder penunjang):
- RJ: `transaksi/rj/emr-rj/pemeriksaan/penunjang/radiologi/⚡rm-radiologi-rj-actions.blade.php` (+ `rm-daftar-radiologi-rj.blade.php`)
- UGD: `transaksi/ugd/emr-ugd/pemeriksaan/penunjang/radiologi/⚡rm-daftar-radiologi-ugd.blade.php`
- RI: `transaksi/ri/emr-ri/pemeriksaan-ri/penunjang/radiologi/⚡rm-radiologi-ri-actions.blade.php` (+ `rm-daftar-radiologi-ri.blade.php`); tab administrasi RI: `transaksi/ri/administrasi-ri/⚡radiologi-ri.blade.php`

**Display/cetak (RM):** `resources/views/pages/components/rekam-medis/penunjang/radiologi-display/`
(`radiologi-display.blade.php` = display layar per pasien, `radiologi-display-print.blade.php` = cetak PDF hasil bacaan).

> Tidak ada file "modal actions per-pasien multi-tab", "display-pasien-radiologi" tersendiri, atau
> "layar antrian display" seperti di lab. Header pasien di list radiologi di-render **inline**.

## "Status" (kelengkapan upload — bukan status pemeriksaan)

Tidak ada kolom status pemeriksaan (P/C/H/F). Status dihitung di blade dari kelengkapan file
(`upload-radiologi.blade.php:452`):

```php
$isFotoOk  = !empty($row->rad_upload_pdf_foto);
$isHasilOk = !empty($row->rad_upload_pdf);
$isLengkap = $isFotoOk && $isHasilOk;
```

Filter list: `belum_foto` / `belum_pdf` / `belum` / `lengkap`. Baris belum lengkap di-highlight amber.
Status transaksi induk (`rj_status`/`ri_status`) hanya dipakai untuk **lock tarif** (bukan status pemeriksaan).

## Tabel DB

| Tabel | Peran | PK | Ref induk |
|---|---|---|---|
| `rstxn_rjrads` | Order radiologi RJ (**sekaligus baris biaya** ke induk RJ) | `rad_dtl` | `rj_no` → `rstxn_rjhdrs` |
| `rstxn_ugdrads` | Order radiologi UGD (idem) | `rad_dtl` | `rj_no` → `rstxn_ugdhdrs` |
| `rstxn_riradiologs` | Order radiologi RI (idem) | `rirad_no` | `rihdr_no` → `rstxn_rihdrs` |
| `rsmst_radiologis` | Master pemeriksaan | `rad_id` | — |
| `rsmst_doctors` | Master dokter (pengirim & radiolog TTD; radiolog difilter poli `LIKE '%RADIOLOG%'`) | `dr_id` | — |
| `rsview_rads` | **View gabungan** 3 tabel order untuk display RM per `reg_no` | `txn_no`/`txn_no_dtl` | — |

**Kolom penting di tabel order (`rstxn_*rads`):**

| Kolom | Isi |
|---|---|
| `rad_dtl` / `rirad_no` | PK detail (`NVL(MAX+1,1)`) |
| `rad_id` | FK master pemeriksaan |
| `rj_no` / `rihdr_no` | ref induk |
| `rad_price` / `rirad_price` | tarif (editable inline; terkunci bila induk sudah pulang) |
| `dr_pengirim` | **nama** dokter (bukan id) |
| `dr_radiologi` | radiolog penandatangan (Generate menyimpan `dr_id`) |
| `klinis_desc` | diagnosis/keterangan klinis (wajib saat order) |
| `keterangan` | catatan teknis (AP/lateral, dst) |
| `hasil_bacaan` | **CLOB** narasi HTML (output TinyMCE) — ini "expertise". Kesimpulan **menyatu** di sini. |
| `rad_upload_pdf` | nama file PDF hasil bacaan |
| `rad_upload_pdf_foto` | nama file foto |
| `waktu_entry` | timestamp order |

> Tidak ada `ref_no`, `emp_id`, `status_rjri`, atau kolom `kesimpulan` terpisah. Tidak ada tabel
> header/detail terpisah — **satu baris = satu pemeriksaan**.

## Alur SIMPAN

Tidak ada "Proses Administrasi" — **biaya di-post langsung saat INSERT order**.

### A. Order dari EMR (`kirimRadiologi()`, `rm-radiologi-rj-actions.blade.php`)

Guard: `checkRJStatus()` (pasien belum pulang) + `klinisDesc` wajib. Dibungkus `DB::transaction`.

```sql
-- RJ (UGD analog: rstxn_ugdrads + rj_no; RI: rstxn_riradiologs + rihdr_no + rirad_price + rirad_date)
INSERT INTO rstxn_rjrads (rad_dtl, rad_id, rj_no, rad_price, dr_pengirim, dr_radiologi, klinis_desc, waktu_entry)
VALUES ((SELECT NVL(MAX(TO_NUMBER(rad_dtl))+1,1) FROM rstxn_rjrads),
        :rad_id, :rj_no, :rad_price, :dr_pengirim_name, :dr_radiologi,
        :klinis_desc, TO_DATE(:now,'dd/mm/yyyy hh24:mi:ss'));
```

### B. Tambah dari modul radiologi (`insertRad()`, `upload-radiologi-tambah-actions.blade.php`)

Modal tambah **diseragamkan dengan modul lab** (pola keranjang): **toggle sumber RJ/UGD/RI di dalam modal**
(`setSource`), Step 1 pilih pasien aktif, Step 2 **grid `rsmst_radiologis` + keranjang multi-item**
(`items` paginated, `toggleItem`/`removeSelected`) dengan **tarif editable per item** (`selectedItems.{id}.price`
— khas radiologi; lab tarifnya fixed). `insertRad()` **loop** `selectedItems` → 1 baris `rstxn_*rads` per item
(`lockHeader` + `appendAdminLog{RJ,UGD,RI}` kategori MR). `dr_pengirim` disimpan **nama** dokter.

### C. Simpan Hasil Bacaan / Expertise (`upload-radiologi-bacaan-actions.blade.php`)

```sql
-- Generate PDF dari TinyMCE (generatePdf): simpan narasi + radiolog, render PDF, catat nama file
UPDATE rstxn_rjrads SET hasil_bacaan = :htmlNarasi, dr_radiologi = :drId WHERE rad_dtl = :dtl AND rj_no = :ref;
-- (render Pdf::loadView('...radiologi-display-print') → simpan ke disk 'local' upload/penunjang/radiologi)
UPDATE rstxn_rjrads SET rad_upload_pdf = :namaFilePdf WHERE rad_dtl = :dtl AND rj_no = :ref;

-- Upload PDF manual (uploadPdf): UPDATE rad_upload_pdf = :namaFile
-- Upload Foto (uploadFoto):        UPDATE rad_upload_pdf_foto = :namaFile
```

CLOB `hasil_bacaan` dibaca via `stream_get_contents` bila resource.

### D. Edit inline (`upload-radiologi.blade.php`)

`updateKeterangan` / `updateDrPengirim` / `saveTarif` (UPDATE kolom terkait). `saveTarif` dijaga
`isRefLocked()` (lihat bawah). **Ketiganya kini ter-audit**: dibungkus `DB::transaction` + `lock{RJ,UGD,RI}Row`
+ `appendAdminLog{RJ,UGD,RI}(..., 'MR')` via helper `logEditKeParent()` (log "Ubah Tarif/Dokter Pengirim/
Keterangan Radiologi #…"). Jadi semua mutasi radiologi (order/tambah/batal/edit) ter-audit di userLogs induk.

> Tidak ada perpindahan status `P→C→H` — konsep itu tidak ada di radiologi.

## HAPUS ORDER (batal) — di program penunjang (`upload-radiologi`), bukan Administrasi

> **Administrasi RJ/UGD/RI (tab Lab & Radiologi) kini READ-ONLY** — hanya tampil daftar + total, tanpa
> order/entry, edit tarif, maupun hapus. Semua mutasi radiologi ada di program penunjang.

| Aksi | Di mana |
|---|---|
| Order pemeriksaan | EMR (`kirimRadiologi`) |
| Edit tarif / dr. pengirim / keterangan | `upload-radiologi` (`saveTarif`, `updateDrPengirim`, `updateKeterangan`) |
| **Batalkan/hapus order** | tombol **Batalkan Order** per baris di `upload-radiologi` → `batalkanOrder(source, dtlNo, refNo)` |

`batalkanOrder` menghapus satu baris order **sekaligus baris biaya** sesuai source:

```sql
DELETE FROM rstxn_rjrads       WHERE rad_dtl  = :dtl AND rj_no    = :ref;  -- RJ
DELETE FROM rstxn_ugdrads      WHERE rad_dtl  = :dtl AND rj_no    = :ref;  -- UGD
DELETE FROM rstxn_riradiologs  WHERE rirad_no = :dtl AND rihdr_no = :ref;  -- RI
```

- **Role** `isAllowedBatal()` = **Admin + Supervisor Penunjang** (seragam lab; staff Radiologi TIDAK boleh
  batal — harus eskalasi ke atasan). Tombol pakai **`x-confirm-button`** (modal konfirmasi, sama gaya lab) +
  di-gate `@hasanyrole` sehingga hilang untuk non-supervisor.
- **Lock induk** `isRefLocked(source, refNo)`: pasien pulang (RJ/UGD `rj_status != 'A'`, RI `ri_status != 'I'`)
  → order terkunci, tak bisa dibatalkan (toast).
- **Audit log** `appendAdminLog{RJ,UGD,RI}($ref, 'Batal Order Radiologi #'.$dtl, 'MR')` di dalam
  `DB::transaction` + `lock{RJ,UGD,RI}Row` (upload-radiologi meng-`use` EmrRJ/UGD/RITrait).

Lock tarif memakai `isRefLocked()` yang sama (`upload-radiologi.blade.php`):

```php
// RI: ri_status harus 'I' (dirawat) agar bisa edit; RJ/UGD: rj_status harus 'A'
return $row && !empty($row->rj_status) && $row->rj_status !== 'A';
```

Bila pasien sudah pulang → tarif terkunci (toast "Pasien sudah pulang — tarif terkunci"), tapi upload
foto/hasil tetap bisa.

## Role / Akses

Tidak ada `isAllowedRole()`/`hasAnyRole` di blade radiologi (beda dari lab). Akses dijaga di **level menu**
(`app/Services/AppMenu.php:152`):

```php
'route' => 'transaksi.penunjang.radiologi.upload', 'title' => 'Upload Hasil Radiologi',
'roles' => ['admin', 'manager umum', 'supervisor penunjang', 'radiologi'],
```

Route (`routes/web.php`) hanya di bawah middleware `auth`, tanpa middleware role eksplisit. Order dari EMR
mengikuti akses EMR (dokter/perawat). Master radiologi (`master.radiologis`) pakai `$masterRoles`.

## Display pasien

Tidak ada komponen `display-pasien-radiologi` khusus (beda dari `display-pasien-laborat`).
- Di **list upload**: identitas pasien di-query **langsung** via join `rsmst_pasiens`, umur dihitung di
  Oracle (`months_between`). Menampilkan `reg_no`, `reg_name`, `sex`, `birth_date`, umur, `address` —
  **tanpa** no telp/NIK/BPJS. Tidak memakai `MasterPasienTrait`.
- Di **modal Order EMR**: reuse `<livewire:...display-pasien-rj>` (komponen RJ standar).

> Kalau nanti mau menyeragamkan identitas (telp/NIK/BPJS) seperti lab/RJ, ganti query inline dengan
> `MasterPasienTrait::findDataMasterPasien` (lihat pola di `display-pasien-laborat`).

## Cetak

`radiologi-display-print.blade.php` (dompdf `Pdf::loadView`, A4 portrait):
- Header identitas via `<x-pdf.identitas-pasien>` + Tgl Pemeriksaan + Dokter Pengirim.
- Judul pemeriksaan (`rad_desc`) + keterangan.
- Salam "Teman sejawat Yth." (meniru report Oracle Forms legacy).
- **Isi = narasi `hasil_bacaan`** (HTML TinyMCE, di-render `{!! !!}`, support tabel/heading/list).
- Footer TTD **Dokter Radiolog**: TTD image dari `User.myuser_ttd_image` (match `myuser_code = dr_id`);
  fallback dokter poli RADIOLOGI aktif pertama.

> Tidak ada bagian "Kesimpulan" terpisah — kesimpulan menyatu dalam `hasil_bacaan`.

## Integrasi alat / PACS — TIDAK ADA

Tidak ada integrasi PACS/DICOM/modality, tidak ada koneksi Oracle terpisah, tidak ada Http call ke alat,
tidak ada import hasil otomatis. Koneksi `oracle_mindray` khusus lab, **tidak** dipakai radiologi. Alur
radiologi 100% **manual upload** (foto dari modality diambil offline + tulis/upload hasil bacaan).

## Etiket — TIDAK ADA

Modul radiologi tidak punya tombol Etiket (fitur ini eksklusif modul lab).

## Ringkasan perbedaan kunci vs Lab

| Aspek | Lab | Radiologi |
|---|---|---|
| Alur status | `P→C→H→F` (`checkup_status`) | Tidak ada — hanya kelengkapan upload |
| Proses Administrasi (post biaya) | Ya (`P→C`) | Tidak — biaya di-post saat INSERT order |
| Batal/hapus | `batalkanPendaftaran`/`batalkanTransaksi` di modal lab (role-guarded, ada log) | `batalkanOrder` di `upload-radiologi` (penunjang) — hapus order+biaya, lock induk + log. Administrasi read-only |
| Header/detail | Terpisah (`checkuphdrs`/`checkupdtls`) | 1 baris = 1 pemeriksaan (`rstxn_*rads`) |
| Kesimpulan | Kolom `checkup_kesimpulan` | Menyatu di `hasil_bacaan` (CLOB HTML) |
| Import alat | Mindray (`oracle_mindray`) | Tidak ada (upload manual) |
| Etiket | Ada | Tidak ada |
| Role guard | `isAllowedRole`/`isAllowedBatal` di blade | Akses halaman via menu (`AppMenu.php`, role `radiologi`); **batal** dijaga `isAllowedBatal` = Admin + Supervisor Penunjang |
| display-pasien | `display-pasien-laborat` (MasterPasienTrait, telp/NIK/BPJS) | Query inline, tanpa telp/NIK/BPJS |
| Fitur unik | Mindray, etiket, kesimpulan | Upload Foto + Generate PDF bacaan (TinyMCE→dompdf), edit inline tarif, view `rsview_rads` |
