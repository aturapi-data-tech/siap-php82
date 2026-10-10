# Indeks Skill (`.claude/skills/`)

Katalog **skill** repo ini — instruksi terpaket yang dibaca AI (dan bisa dibaca manusia) sebelum
mengerjakan tipe tugas tertentu, supaya konsisten dengan pola & jebakan yang sudah dipetakan.
File tiap skill: `.claude/skills/<nama>/SKILL.md`. Panggil dengan `/<nama>`.

> Beda **skill** vs **docs/**: `docs/*.md` = referensi pola/arsitektur (dokumen). **Skill** =
> pembungkus "baca-dulu-sebelum-X" yang sering **menunjuk** ke docs terkait. Banyak skill = docs +
> aturan keras + kapan-wajib-dibaca.

Total: **16 skill**.

---

## 1. Keselamatan edit & konvensi kode

| Skill | Cakupan | Baca saat |
|---|---|---|
| **blade-safe-edit** | Aturan aman mengedit `*.blade.php` / Volt: dilarang regex multiline, verifikasi balance tag, jebakan compiler Volt (`?>`/`use`/`reuse`), escape ganda prop komponen. | Sebelum edit bulk / sed / regex pada Blade, atau edit banyak file sekaligus. |
| **naming-conventions** | Standar penamaan variable/method (camelCase Indonesia, hindari singkatan), singkatan modul yang "dipesan" (`rj`/`ri`/`ugd`/`rm`), aturan `use` vs FQCN di Volt. | Sebelum menulis kode PHP/Livewire/Volt baru, menamai variable domain, atau import class. |

## 2. Database & query

| Skill | Cakupan | Baca saat |
|---|---|---|
| **oracle-quirks** | Gotcha Oracle (Laravel + Oracle Dev 6i hybrid): `'' = NULL`, `JSON_VALUE` tak didukung, kolom mixed-case, `active_status` `'1'/'0'`, lookup shift, filter wilayah (`kab_id`). | Sebelum menulis/men-debug query DB, hasil query kosong tak terduga, ORA-00904. |

## 3. Livewire / UI

| Skill | Cakupan | Baca saat |
|---|---|---|
| **livewire-input-patterns** | Pola input Livewire/Alpine teruji: `wire:model.blur` auto-calc, `x-text-input-number`, Enter→`$wire` race, `x-now-button`, search input "mental", **persist filter antar tab (`#[Session]`)**. | Menambah/men-debug input numerik EMR, aksi Enter, filter list ber-tab. |
| **ui-pattern-docs** | Indeks pola UI di `docs/` (tombol, modal, tab, page-frame, cetak PDF/TTD, editor, list stabil, wrapper hub). | Sebelum membuat komponen UI baru — cek dulu polanya sudah ada. |

## 4. EMR — dokumen & teks legal

| Skill | Cakupan | Baca saat |
|---|---|---|
| **modul-dokumen** | Membuat/mem-port modul dokumen bertanda tangan (consent, surat keterangan, laporan, Akhir Hayat): kartu+tombol→modal, Draft→TTD→Kunci→Lihat/Cetak, role Gate terpusat, penanda tab, viewer Rekam Medis, porting RI⇄UGD⇄RJ, kode formulir RM (RM-KK.NN). | Membuat form dokumen baru, memasang di jalur lain, atau viewer rekam-medisnya. |
| **emr-multi-entry-document** | Dokumen multi-entri EMR RI (CPPT & SBAR): banyak entri per pasien, tab per-profesi, Edit=pemilik/Hapus=supervisor/Review=DPJP, copy-ke-form, cetak per-entri. | Membuat dokumen EMR RI mirip CPPT/SBAR atau fitur Edit/Review/Copy-nya. |
| **dokumen-clob-per-kunjungan** | Dokumen ber-tabel sendiri (3 kolom: PK, REG_NO, CLOB JSON), SATU BARIS PER KUNJUNGAN, formulir multi-baris dirakit saat tampil/cetak. Snapshot isi EMR, kriteria toggle, kartu riwayat, cetak. Dipakai Pengkajian Medis PP 1.2 & PRMRJ RM.06. | Membuat modul dokumen yang isinya lintas-kunjungan, perlu tabel sendiri, atau memuat snapshot EMR. |
| **clause-versioning** | Versioning teks klausul dokumen legal agar cetak ulang record lama tetap memakai redaksi SAAT DITANDATANGANI walau kebijakan berubah. | Sebelum mengubah teks klausul consent/pernyataan atau menambah versi klausul. |

## 5. EMR — domain data & modul

| Skill | Cakupan | Baca saat |
|---|---|---|
| **diagnosa-flow** | Arsitektur & jebakan diagnosa ICD-10 (`RSMST_MSTDIAGS`, LOV, EMR, SEP/VClaim, iDRG/INACBG); 288 icdx kembar → lookup flag naive salah baris; aturan `icdx` vs `diag_id` per konsumen. | Sebelum mengubah/menambah pemilihan atau penyimpanan diagnosa. |
| **master-pasien** | Field path & jebakan data pasien (`rsmst_pasiens`/`MasterPasienTrait`): mapping L/P, `*Desc` tak sync, kolom salah nama, umur dari `birth_date`. | Membaca/menyimpan data pasien, menampilkan gender & umur, mengisi Master Pasien. |
| **laborat** | Arsitektur & jebakan modul Laboratorium (`lbtxn_`/`lbmst_`, hasil, nilai rujukan & kritis per-gender, Mindray, status P/C/H/F, biaya ke induk RJ/UGD/RI). | Menambah/mengubah item master lab, input/tampilan/cetak hasil, ambang, laporan, batal. |
| **administrasi-inline-edit** | Sel tabel yang diedit langsung ke DB di modul Administrasi/transaksi (tarif, hari, tanggal Riwayat Kamar/Visit/Konsul); jebakan kolom turunan (hari/subtotal) + audit log. | Sebelum menambah/mengubah kolom editable pada tabel transaksi. |
| **satusehat-kirim** | Aturan mengirim resource ke SATUSEHAT (FHIR R4): sumber data JSON EMR yang benar, helper KFA/racikan (`ObatKfa`/`RacikanKfa`/`MedicationRequestItem`), jebakan validator Kemkes yang pernah menolak sungguhan, uji payload tanpa mengirim | Sebelum menambah/mengubah sender di `app/Http/Traits/SATUSEHAT` atau `*/satu-sehat/*`, atau saat kiriman ditolak `OperationOutcome`. Rujukan lengkap: `docs/satusehat-api.md` |
| **ews** | Modul EWS (Early Warning System): master parameter/rentang/respon per varian (`RSMST_EWS_*`, `/master/ews`), mesin skor `EwsSkor`, skor tersimpan di `tandaVital[].ews`, badge display pasien; jebakan charset Latin-1 & directive di atribut komponen. | Sebelum mengubah ambang, menambah parameter/varian, menyentuh form/tabel Observasi Lanjutan RI/UGD, atau membuat pemakai baru skor (RJ, cetak RM 93a, daftar EWS tinggi). Rujukan: `docs/ews-modul.md` |
| **bpjs-antrean-task-id** | Konsep `taskId` 1–7 & 99 (Antrean/Antrol BPJS) di node `taskIdPelayanan`: struktur RJ/UGD vs RI-Resep, format waktu ganda, siapa mengeset apa | Sebelum menyentuh titik yang mencatat waktu layanan (pendaftaran, poli, apotek, pembatalan), atau saat waktu antrean tak terkirim/dobel |

---

## Cara memakai
- AI: panggil `Skill` dengan nama (mis. `/modul-dokumen`) — instruksi termuat ke konteks.
- Manusia: buka `.claude/skills/<nama>/SKILL.md`.
- Menambah skill baru: buat folder `.claude/skills/<nama>/SKILL.md` (frontmatter `name` + `description`
  yang menyebut **kapan wajib dibaca**), lalu tambahkan barisnya ke tabel di atas.

> Referensi silang: banyak skill menunjuk ke `docs/` — lihat [ui-pattern-docs](../.claude/skills/ui-pattern-docs/SKILL.md)
> untuk indeks pola UI, dan [standar-ui-komponen.md](standar-ui-komponen.md) untuk standar komponen.
