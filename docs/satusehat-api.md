# Dokumentasi API SATUSEHAT — Model Pengiriman & Standarisasi Data

Dokumen ini menjelaskan **cara sistem mengirim data ke SATUSEHAT** (platform interoperabilitas Kemenkes, FHIR R4) dan **standarisasi data** tiap resource. Berbasis implementasi nyata di repo, bukan teori.

- Lapisan trait: `app/Http/Traits/SATUSEHAT/*.php` (20 file, ~3.200 baris)
- Lapisan UI (aktif): `resources/views/pages/transaksi/{rj,ugd,ri}/satu-sehat/*.blade.php` + `daftar-*/satu-sehat-*-actions.blade.php`
- Helper data (dipakai bersama): `App\Support\{EresepJson, ObatKfa, RacikanKfa, MedicationRequestItem, AlergiSnomed}`
- Orkestrator batch (referensi): `app/Http/Traits/SATUSEHAT/KirimRawatJalanTrait.php`

> **Ruang lingkup aktif = RJ, UGD, dan RI.** Masing-masing punya modal kirim sendiri
> (`resources/views/pages/transaksi/{rj,ugd,ri}/satu-sehat/*.blade.php`, 12–16 kartu per modul).
> Diperbarui 2026-08-03 sesudah uji kirim pertama ke server — lihat §11.

---

## 1. Arsitektur singkat

```
                    ┌─────────────────────── SatuSehatTrait (core/transport) ───────────────────────┐
                    │  initializeSatuSehat() · getAccessToken() · makeRequest() · logSatuSehat()     │
                    └───────────────────────────────────────────────────────────────────────────────┘
                                   ▲ di-`use` oleh semua resource trait
  Resource traits (bangun payload FHIR + POST/PUT):
   Encounter · Condition · Observation · Procedure · AllergyIntolerance ·
   MedicationRequest · MedicationDispense · ServiceRequest · Specimen · DiagnosticReport ·
   Patient · Practitioner · Organization · Location · (Loinc/Snomed = lookup terminologi)

  UI RJ (Livewire/Volt, satu tombol per-resource):
   satu-sehat-rj-actions  ──buka modal──▶  kirim-encounter │ kirim-condition │ kirim-observation │
                                            kirim-procedure │ kirim-medication-request
```

Dua "jalur" kirim yang perlu dibedakan:
1. **Jalur UI aktif (yang benar-benar dipakai):** 5 komponen Livewire per-langkah, masing-masing tombol "Kirim" sendiri. Menyimpan hasil ke node JSON `satusehat` pada record RJ.
2. **Jalur orkestrator batch `KirimRawatJalanTrait` (11 langkah sekali jalan):** lengkap (termasuk alergi, dispense, lab), tapi **belum di-`use` komponen/route manapun** — anggap sebagai blueprint/cadangan, bukan jalur produksi.

---

## 2. Autentikasi & environment

OAuth2 **client_credentials** — `SatuSehatTrait.php:38-53`.

| Hal | Nilai / Cara |
|---|---|
| Token endpoint | `SATUSEHAT_AUTH_URL . "accesstoken?grant_type=client_credentials"` (POST `asForm`) |
| Kredensial | env `SATUSEHAT_CLIENT_ID`, **`SATUSEHAT_SECRET_ID`** (catat: `_SECRET_ID`, bukan `_CLIENT_SECRET`) |
| Cache token | `Cache::remember('satusehat_access_token', 3500, …)` — TTL hardcoded ~58 mnt, `expires_in` diabaikan |
| Header API | `Authorization: Bearer {token}` + `Organization-Id: {SATUSEHAT_ORGANIZATION_ID}` |
| Base URL FHIR | `SATUSEHAT_BASE_URL` → `https://api-satusehat.kemkes.go.id/fhir-r4/v1/` (**PRODUCTION**) |
| Versi | FHIR **R4**; profil resource `https://fhir.kemkes.go.id/r4/StructureDefinition/*` |

**Environment switch = ganti nilai env** (tak ada toggle di kode). Sandbox Kemkes biasanya `api-satusehat-stg.kemkes.go.id`.

⚠️ **Semua kredensial dibaca `env()` langsung, tanpa wrapper `config/*.php`.** Kalau `php artisan config:cache` dijalankan di production, `env()` runtime → `null` → integrasi mati senyap. (Lihat backlog §8.)

---

## 3. Transport & logging

`makeRequest($method, $endpoint, $data = [])` — `SatuSehatTrait.php:61-104`. Laravel `Http`.

- **Bukan FHIR Bundle.** Tiap resource = satu HTTP call terpisah (`POST Encounter`, `POST Condition`, …).
- `Http::timeout(10)` untuk token & API. **Tanpa `connectTimeout()` / `retry()`** → rawan gagal saat server lambat.
- Sukses (`2xx`) → `$response->json()` (array). Gagal → `throw \Exception('API request failed: '.body)`; caller (blade) tangkap `\Throwable` → toast.
- **Logging:** tiap call di-insert ke tabel **`web_log_status`** via `logSatuSehat()` (`:109-119`): `code, date_ref, response, http_req, http_payload, requestTransferTime`.

---

## 4. Resolusi IHS Code

IHS = identitas resource di SATUSEHAT. Sumbernya kolom master (di-set sekali), bukan dilookup tiap kirim:

| Entitas | IHS disimpan di | Cara isi |
|---|---|---|
| **Pasien** | `rsmst_pasiens.patient_uuid` (+ JSON `pasien.identitas.patientUuid`) | `searchPatient(['nik'=>…])` → `/Patient?identifier=…/nik\|{nik}`; kalau kosong `createPatient()` (Master Pasien) |
| **Dokter** | `rsmst_doctors.dr_uuid` | manual (trait `searchPractitioner` by NIK/IBP/SIPP tersedia tapi tak dipakai runtime) |
| **Poli / Location** | `rsmst_polis.poli_uuid` | manual (trait `searchLocation`/`createLocation` tersedia) |
| **Organization** | env `SATUSEHAT_ORGANIZATION_ID` | tetap (`100027469`) |

⚠️ Kalau `dr_uuid` / `poli_uuid` kosong → kirim Encounter berhenti dengan toast error (`kirim-encounter.blade.php:92-99`). NIK harus 16 digit; kalau tidak, identifier di-skip diam-diam (`PatientTrait.php:47-61`).

---

## 5. Model pengiriman (urutan & aturan)

Urutan kanonik (dari orkestrator `KirimRawatJalanTrait::kirimRawatJalan()`, `:74-118`). Di UI aktif langkah 1-4 + 7 yang tersedia sebagai tombol; sisanya baru ada di trait.

| # | Langkah | Resource FHIR | Sistem kode | Gate |
|---|---|---|---|---|
| 1 | Kunjungan | **Encounter** | class `AMB` (v3-ActCode) | **ROOT — wajib sukses, kalau gagal semua berhenti** (`:76-78`) |
| 2 | Diagnosa | **Condition** (`encounter-diagnosis`) | ICD-10 | fail-soft |
| 3 | Tanda vital | **Observation** (`vital-signs`) | LOINC | fail-soft |
| 4 | Tindakan | **Procedure** | ICD-9-CM | fail-soft |
| 5 | Keluhan utama | **Condition** (`problem-list-item`) | SNOMED | fail-soft |
| 6 | Alergi | **AllergyIntolerance** | SNOMED | fail-soft |
| 7 | Peresepan obat | **MedicationRequest** | KFA | fail-soft |
| 8 | Obat dibawa pulang | **MedicationDispense** | KFA | fail-soft |
| 9-11 | Penunjang lab | **ServiceRequest → Observation(`laboratory`) → DiagnosticReport** | LOINC | fail-soft |

**Aturan penting:**
- **Encounter adalah akar.** Semua resource lain mereferensikan `Encounter/{id}`, `Patient/{id}`, `Practitioner/{id}`. Encounter punya siklus status 3 tahap: `arrived` (POST) → `in-progress` (PUT, `startRoomEncounter`) → `finished` (PUT, hanya bila `txnStatus=CLOSED` atau `rjStatus=2`).
- **Idempotensi** = guard in-memory pada state `$ss` (`if empty($ss['...Ids'])`) + node JSON `satusehat` di record RJ. Setiap `kirim()` cek "sudah pernah?" → toast info & berhenti. Hanya **Encounter** & **ServiceRequest** yang punya `identifier` bisnis (natural key) di sisi server; resource lain andalkan guard lokal → **hati-hati kirim dobel bila state JSON hilang**.
- **Item tanpa kode kunci di-skip diam-diam** (`continue`): diagnosa tanpa `kodeIcdx`, tindakan tanpa `kodeIcd9`, obat tanpa `kfaCode`, lab tanpa `loincCode`. Bisa "berhasil (0 item)" tanpa peringatan.
- **Penyimpanan hasil:** node `satusehat` di JSON RJ → `encounterId`, `conditionIds[]`, `observationIds[]`, `procedureIds[]`, `medicationRequestIds[]`, flag `encounterInProgress`/`encounterFinished`. Ditulis via `DB::transaction` + `lockRJRow` + `updateJsonRJ`.

---

## 6. Standarisasi data per resource

| Resource | Trait | resourceType / status | Sistem kode (system URI) | Sumber data (JSON EMR / master) |
|---|---|---|---|---|
| Encounter | EncounterTrait | `Encounter` / arrived→in-progress→finished | class `http://terminology.hl7.org/CodeSystem/v3-ActCode` = `AMB` | `rjNo`, `dr_uuid`, `poli_uuid`, `rjDate`, `regName` |
| Condition (diagnosa) | ConditionTrait `createFinalDiagnosis` | `Condition` / active·confirmed, `encounter-diagnosis` | **ICD-10** `http://hl7.org/fhir/sid/icd-10` (SNOMED opsional dual-coding, tak diisi di RJ) | `diagnosis[]` { `icdX`/`diagId`, `diagDesc` } — ditulis rm-diagnosa-*-actions; key lama `diagnpinaList`/`kodeIcdx` tidak pernah ditulis (bug "Tidak ada data diagnosa" 2026-08-05) |
| Condition (keluhan utama) | ConditionTrait `createChiefComplaint` | `Condition` / `problem-list-item` | **SNOMED** `http://snomed.info/sct` | `keluhanUtama` + `keluhanUtamaSnomedCode` |
| Observation (vital) | ObservationTrait | `Observation` / final, `vital-signs` | **LOINC** `http://loinc.org`, unit UCUM `http://unitsofmeasure.org` | `pemeriksaanFisik`/`tandaVital`: sistole/diastole/nadi/suhu/rr |
| Observation (penilaian) | ObservationTrait + `App\Support\PenilaianObservationMap` | `Observation` / final, **`survey`** (risiko jatuh) · `vital-signs` (antropometri) | **LOINC** — Morse `59460-6` (skor) & `59461-4` (level, answer list **LL905-1**); BB `29463-7`, TB `8302-2`, IMT `39156-5` | `penilaian.resikoJatuh[]` & `penilaian.gizi[]` (**RJ = UGD = RI identik**) |
| MedicationAdministration (RI) | MedicationAdministrationTrait + `App\Support\ObservasiLanjutanMap` | `MedicationAdministration` / completed + contained `Medication` | **KFA**; route **SNOMED** (`route-codes`) | `observasi.obatDanCairan.pemberianObatDanCairan[]` — obat/cairan yang benar-benar **diberikan** |
| Observation (oksigen & cairan, RI) | ObservationTrait + `App\Support\ObservasiLanjutanMap` | `Observation` / final | **LOINC** — alat oksigen `107117-4`, laju `3151-8` (**valueRange**), urine output `9187-6` | `observasi.pemakaianOksigen.pemakaianOksigenData[]`, `observasi.pengeluaranCairan.pengeluaranCairan[]` |
| Procedure | ProcedureTrait | `Procedure` / completed | **ICD-9-CM** `http://hl7.org/fhir/sid/icd-9-cm` (category SNOMED `71388002`) | `procedure[]` { `procedureId` = ICD-9, `procedureDesc` } — ditulis rm-diagnosa-*-actions; key lama `tindakanList`/`kodeIcd9` tidak pernah ditulis (bug "tidak ada data tindakan" RJ/UGD 2026-08-05) |
| AllergyIntolerance | AllergyIntoleranceTrait + `App\Support\AlergiSnomed` | `AllergyIntolerance` / active·confirmed | **SNOMED** — `category` WAJIB selalu ada (RuleNumber 10075) | `kirim-allergy` RJ/UGD/RI. Kode `716186003` ("no known allergy") DITOLAK sebagai substance-code |
| MedicationRequest | MedicationRequestTrait | `MedicationRequest` + contained `Medication` | **KFA** `http://sys-ids.kemkes.go.id/kfa` | `eresep`/`resepObat`; KFA dari master obat `product_id_satusehat` |
| MedicationDispense | MedicationDispenseTrait + `App\Support\MedicationRequestItem` | `MedicationDispense` (encounter via `context`) | **KFA**; `quantity.system` = `…/v3-orderableDrugForm` (RuleNumber 10050) | `kirim-medication-dispense` RJ/UGD/RI. `authorizingPrescription` dari peta eksplisit, bukan urutan — §11.5 |
| ServiceRequest | ServiceRequestTrait | `ServiceRequest` / active·original-order | **LOINC** — lab panel `26436-6`; radiologi dari `rsmst_radiologis.loinc_code`, generik `18748-4` bila master kosong | order lab (`lbtxn_checkuphdrs`) & radiologi (`rstxn_*rads`) — dipakai sender Lab & Radiologi RJ/UGD/RI |
| Specimen | SpecimenTrait | `Specimen` / available | **SNOMED** — `119297000` Blood specimen, metode `129300006` | satu per paket checkup — dipakai sender Lab RJ/UGD/RI |
| DiagnosticReport | DiagnosticReportTrait | `DiagnosticReport` / final, category `LAB` / `RAD` | **LOINC** | dipakai sender Lab & Radiologi RJ/UGD/RI. **`identifier.system` bercabang**: `…/diagnostic/{org}/lab` atau `/rad` (RuleNumber 10432) — record yang terkirim SEBELUM aturan itu memakai bentuk lama tanpa akhiran, lihat §12 |

**Kode LOINC vital di-hardcode di blade** (`kirim-observation.blade.php:86-99`): TD panel `85354-9` (komponen `8480-6` sistole / `8462-4` diastole), Nadi `8867-4`, Suhu `8310-5`, RR `9279-1`. `LoincTrait`/`SnomedTrait` (lookup live ke `tx.fhir.org`) **tidak dipakai** di alur RJ.

**KFA obat** diambil dari master obat kolom `product_id_satusehat` / `product_name_satusehat` (di-set manual di `/master/master-obat`). Kalau kosong → item resep di-skip.

**Kode LOINC Penilaian diverifikasi lewat terminology server** (`tx.fhir.org`, pakai `LoincTrait::lookupLoincCode()` / `ValueSet/$expand`) — **jangan isi dari hafalan**: tebakan awal `59460-2`/`59461-0` ternyata tidak ada. Kategori repo dipetakan ke answer list resmi LL905-1 (Rendah→`LA13038-7`, Sedang→`LA13039-5`, Tinggi→`LA13040-3`) sehingga terkirim sebagai `valueCodeableConcept`, bukan teks bebas. **Humpty Dumpty tidak punya padanan LOINC** → kode generik `73830-2` + `valueString`. **Skor/kategori skrining gizi sengaja tidak dikirim**: skalanya custom 3-item (bukan MST/MUST/Strong-Kids).

**Model e-resep — `App\Support\EresepJson`.** Polanya sama di 3 modul; bedanya hanya rawat inap bisa memberi **lebih dari satu resep dalam satu periode perawatan** (>1 hari):
- **RJ/UGD (datar, di akar):** `eresep[]` + `eresepRacikan[]`. Tidak ada `eresepHdr` (terbukti 0 record).
- **RI (berlembar):** `eresepHdr[]` = `{resepNo, resepDate, eresep[], eresepRacikan[], slsNo?, tandaTanganDokter?}`; tiap lembar punya TTD & nomor jual sendiri. Hdr lama bisa tanpa key `eresepRacikan` → wajib `?? []`.
- `EresepJson::lembar($data)` menormalkan keduanya jadi `[{resepNo, resepDate, nonRacikan[], racikan[noRacikan => bahan[]]}]`. Racikan dikelompokkan per `noRacikan` — **1 grup = 1 obat racikan**, anggotanya = bahan. Ada juga `jumlahRacikan()` (per grup, bukan per baris) & `kesiapanRacikan()`.

---

## 7. Pemetaan kolom Dashboard SATUSEHAT → status implementasi

Kolom di dashboard platform SATUSEHAT (jumlah resource per bulan) vs kondisi di sistem ini.

> ⚠️ **Tabel di bawah sudah usang** (ditulis saat alur baru ada di RJ). Kondisi nyata per 2026-07-15 — dibaca dari mount di `satu-sehat-{rj,ri,ugd}-actions`:
> - **RJ (12 kartu):** Encounter · Condition · Observation · Procedure · MedicationRequest · ChiefComplaint · Allergy · MedicationDispense · Lab · Radiologi · ClinicalImpression · **Penilaian**
> - **UGD (12 kartu):** sama seperti RJ (urutan beda; Encounter class **EMER**, Location IGD hardcode)
> - **RI (12 aktif + 2 digating):** Encounter (**IMP**) · **EpisodeOfCare** · Condition · Procedure · Observation · MedicationRequest · MedicationDispense · Lab · Radiologi · ClinicalImpression (CPPT) · **NutritionOrder** · **Penilaian** — ChiefComplaint & Allergy masih `@if(false)` (SNOMED)
>
> Jadi baris "❌ / ⚠️ trait saja" untuk MedicationDispense, ServiceRequest, Specimen, DiagnosticReport, Allergy, ClinicalImpression, EpisodeOfCare, NutritionOrder **sudah tidak berlaku**. Yang benar-benar belum ada: **Composition** (ringkasan pulang), **Immunization** (belum ada modul). **ImagingStudy** sudah di-wire ke UI (PR #33): dikirim dari sender Radiologi RJ/UGD/RI bila fotonya sudah diupload — berkasnya diangkat ke Orthanc lebih dulu supaya StudyInstanceUID-nya asli (lihat §9.5 & `docs/pacs-orthanc.md`).
>
> **Badge kartu = urutan tampil di modul itu** (tidak disamakan lintas modul; set resource memang beda). Dirapikan 2026-07-15.

| Kolom Dashboard (Resource FHIR) | Trait ada? | Ter-wire di UI RJ? | Sistem kode |
|---|---|---|---|
| Jumlah Kunjungan (**Encounter**) | ✅ | ✅ tombol | class AMB |
| Jumlah Diagnosis (**Condition**) | ✅ | ✅ tombol | ICD-10 (+SNOMED keluhan) |
| Jumlah Observasi (**Observation**) | ✅ | ✅ tombol | LOINC |
| Jumlah Tindakan (**Procedure**) | ✅ | ✅ tombol | ICD-9-CM |
| Jumlah Peresepan Obat (**MedicationRequest**) | ✅ | ✅ tombol | KFA |
| Jumlah Obat Dibawa Pulang (**MedicationDispense**) | ✅ | ⚠️ trait saja | KFA |
| Jumlah Layanan Penunjang (**ServiceRequest**) | ✅ | ⚠️ trait saja | LOINC |
| Jumlah Laboratorium (**Specimen**) | ✅ | ⚠️ trait saja | — |
| Jumlah Pelaporan Diagnostik (**DiagnosticReport**) | ✅ | ⚠️ trait saja | LOINC |
| Jumlah Intoleransi Alergi (**AllergyIntolerance**) | ✅ | ⚠️ trait saja | SNOMED |
| Jumlah Diet (**Composition**) | ⚠️ dibangun, belum diuji live | ❌ | LOINC 88645-7 — label dashboard "Diet" menyesatkan, resource-nya Resume Medis (§9.4) |
| Jumlah Impresi Klinik (**ClinicalImpression**) | ❌ | ❌ | — |
| Jumlah Radiologi (**ImagingStudy**) | ✅ | ⚠️ trait saja | LOINC + DICOM UID (via Orthanc) |
| Jumlah Imunisasi (**Immunization**) | ❌ | ❌ | — |
| Jumlah Episode Perawatan (**EpisodeOfCare**) | ❌ | ❌ | — |
| Jumlah Instruksi Gizi (**NutritionOrder**) | ❌ | ❌ | — |

**Ringkas coverage:** 5 resource sudah terkirim penuh (Encounter, Condition, Observation, Procedure, MedicationRequest). ServiceRequest, Specimen & DiagnosticReport sudah di-wire lewat sender Lab & Radiologi; sisa yang perlu di-wire: MedicationDispense, AllergyIntolerance. 5 resource belum dibuat sama sekali (Composition/Diet, ClinicalImpression, Immunization, EpisodeOfCare, NutritionOrder). ImagingStudy kini juga sudah di-wire (§9.5) — **payload lengkap & metode kirim di §9**.

---

## 8. Backlog & gotcha (verifikasi lapangan)

1. **`env()` tanpa config wrapper** → mati senyap bila `config:cache`. **Rekomendasi:** buat `config/satusehat.php` dan baca via `config('satusehat.*')`.
2. **5 resource belum di-wire** (Dispense/ServiceRequest/Specimen/DiagnosticReport/Allergy) → dashboard SATUSEHAT untuk kolom itu akan 0 walau trait tersedia. Orkestrator `KirimRawatJalanTrait` sudah memuat semuanya tapi belum dipanggil UI.
3. **Timeout 10s tanpa retry/connectTimeout** — samakan pola dengan integrasi lain (BPJS `timeout(8)->connectTimeout(3)`), lihat memori "BPJS sync call = freeze".
4. **KFA/kode di-skip diam-diam** — SUDAH ditutup untuk obat: kartu MedicationRequest menampilkan jumlah siap kirim, racikan yang bahannya belum ber-KFA, dan obat tanpa KFA; toast menyebut hal yang sama sesudah kirim. Diagnosa tanpa `kodeIcdx`, tindakan tanpa `kodeIcd9`, dan lab tanpa `loincCode` **masih** di-skip diam-diam.
5. **`registrationId == medicationCode == kfaCode`** untuk obat non-racikan — perlu ditinjau apakah field registrasi obat harus beda dari KFA. Racikan memakai `RACIKAN-{noKunjungan}-{n}`.
6. **DiagnosticReport default kategori `MB`/Microbiology** — set eksplisit `LAB`/`RAD` saat mengaktifkan lab/radiologi.
7. **Diagnosa tidak menandai primer/sekunder** (`Encounter.diagnosis.rank` tidak diisi) — semua Condition setara.
8. **Token TTL hardcoded 3500** mengabaikan `expires_in`, tak ada invalidasi cache saat 401.
9. **Tidak ada sandbox — dan `.env` menunjuk PRODUKSI.** `SATUSEHAT_BASE_URL` = `api-satusehat.kemkes.go.id` (**tanpa `-stg`**), sementara `CLIENT_ID`/`SECRET_ID`/`ORGANIZATION_ID` **kosong**. Artinya: begitu kredensial diisi di file itu, kiriman uji pertama **langsung menembak produksi**. **Siapkan kredensial `-stg` dulu sebelum uji apa pun.** Konsekuensi: seluruh resource RI/UGD (termasuk Penilaian) **benar secara konstruksi tapi belum pernah divalidasi server** — yang perlu dibuktikan lebih dulu: `category=survey` dan unit UCUM anotasi `{score}`.
10. **Racikan obat — TERPECAHKAN 2026-08-03** *(sebelumnya buntu spek + data)*.
    - *Spek:* dikirim sebagai compound — `Medication.contained` dengan `ingredient[]` ber-KFA per bahan, `medicationType` **SD/Compound**, dan `Medication.code` cukup `code.text` karena campurannya memang tak punya KFA tunggal. `ingredient[]` di `MedicationRequestTrait` & `MedicationDispenseTrait` sudah diaktifkan.
    - *Data:* kuncinya bukan `productId` melainkan **nama** — probe 200 kunjungan RJ: 38 nama bahan tanpa `productId` (393 baris) semuanya cocok **persis satu** produk ber-KFA di master. `App\Support\RacikanKfa` memetakan `productId` → KFA, dan bila kosong mencocokkan `productName` **hanya bila kandidatnya tepat satu** (nama kembar ditolak — menebak berarti salah obat).
    - *Hasil:* RJ **203/205 grup (99%)**, UGD **198/214 (93%)**, RI 2/2 — dari sebelumnya 13%/11%. Grup yang gagal dilaporkan **beserta nama bahannya** (mis. `VITAMIN B KOMPLEKS`, `SIRPLUS TABLET`), tinggal dilengkapi KFA-nya di Master Obat.
    - *Sisa:* `ingredient.strength` tidak diisi (dosis di JSON teks bebas: "1/2", "sesuai bb"), dan bentuk sediaan racikan masih default Tablet.

11. **⚠️ Satuan dosis `gr` = GRAM, bukan GRAIN.** Di EMR, `"1 gr"` (669 baris) berarti 1 gram; di UCUM `gr` adalah **grain** (~0,065 g) → kalau dikirim apa adanya, dosis salah ~15×. `ObservasiLanjutanMap::SATUAN_UCUM` memaksa `gr/gram/g → 'g'`. Satuan non-UCUM (`amp`, `tab`, `unit`, `flash`) pakai anotasi UCUM `{ampul}` dsb. (dimensionless, jujur). Satuan tak dikenal → dosis `null` → **seluruh `dosage` dibuang** karena constraint FHIR **mad-1** (`dosage` wajib punya `dose` atau `rate`); route ikut dibuang, jangan kirim setengah.
12. **`rute` pemberian obat = 63 varian teks bebas** (`iv` 6.449, `IV` 684, `Iv` 27, `inheler` 35 — salah ketik). Dipetakan ke SNOMED atas teks ternormalkan; yang tak dikenal **tidak dipetakan** (lebih baik tanpa route daripada salah kode). Perbaikan hulu: jadikan rute picklist, bukan teks bebas.
13. **Hanya ~31% baris pemberian obat punya `productId`** (2.497 dari 8.078) — cairan (RL/NaCl) tampaknya diketik bebas tanpa memilih master obat. Baris tanpa productId/KFA dilewati tapi **dihitung & dilaporkan** di kartu 13. Perbaikan hulu: wajibkan pilih dari master obat untuk cairan.
14. **`product_id` tidak ditulis ke tabel racikan.** Kolom `PRODUCT_ID` **ADA** di `rstxn_rjobatracikans` (51.035 baris) & `rstxn_ugdobatracikans` (8.394) tapi **0% terisi** — INSERT di `eresep-{rj,ugd}-racikan.blade.php` tak menyertakannya, padahal nilainya tersedia (dipakai lookup `takar` dua baris di atas). Tabel non-racikan `rstxn_rjobats` menulisnya dengan benar. Akibat: `productId` racikan lama **tak bisa di-backfill lewat join**; satu-satunya jalan tersisa = pencocokan nama (berisiko, ada nama kembar). **Perbaikan termurah: sertakan `product_id` di INSERT racikan.** Dampak non-SATUSEHAT: `hitungSaldoPerObat` melewati baris racikan tanpa `productId` → cek saldo stok apotek diam-diam skip.

---

## 9. Resource belum ada — payload lengkap & metode kirim

Enam kolom dashboard SATUSEHAT **belum punya trait sama sekali** (lihat §7). Bagian ini
adalah referensi kanonik cara mengirimnya: endpoint, contoh payload **FHIR R4**, metode
`createX()` (idiom repo: `resourceType` → `subject`/`encounter` reference → `makeRequest('post', '/X', $payload)`),
pemetaan sumber data SIRUS, dan gap yang harus ditutup dulu.

> ⚠️ **Semua di bagian ini = cetak-biru, BELUM diuji ke sandbox Kemkes.** Uji di `-stg`
> dulu, verifikasi via `web_log_status`, baru arahkan ke production.

### 9.0 Prasyarat & urutan

- **Prasyarat semua resource:** `Encounter` pasien harus sudah terkirim — keenam resource
  ini mereferensikan `Encounter/{id}` **dan** `Patient/{id}` (IHS pasien, §4).
- **Idempotensi:** resource ini tak punya natural key di server → **wajib guard lokal**
  (cek node JSON `satusehat` sebelum kirim), sama seperti Procedure/Observation (§5).
- **Urutan implementasi disarankan** (dari data paling siap → paling butuh modul baru):

| # | Resource FHIR | Endpoint | Sistem kode | Sumber data SIRUS | Kesiapan |
|---|---|---|---|---|---|
| 1 | **EpisodeOfCare** | `POST /EpisodeOfCare` | episodeofcare-type | `rstxn_rihdrs` (RI) | ✅ data ada |
| 2 | **ClinicalImpression** | `POST /ClinicalImpression` | SNOMED (finding) | asesmen "A" SOAP EMR | ✅ data ada |
| 3 | **NutritionOrder** | `POST /NutritionOrder` | SNOMED (oralDiet) | order diet EMR (role Gizi) | ◑ perlu petakan kode |
| 4 | **Composition** | `POST /Composition` | LOINC 88645-7 + LP173421-1 | indeks ID resource kunjungan → 13 section | ⚠️ RJ dibangun (`CompositionTrait` + kartu 13), belum diuji live |
| 5 | **ImagingStudy** | `POST /ImagingStudy` | DICOM DCM + ICD-9-CM | modul Radiologi | ⚠️ gap: UID DICOM |
| 6 | **Immunization** | `POST /Immunization` | KFA (vaksin) | — | ⚠️ gap: belum ada modul |

---

### 9.1 EpisodeOfCare — Episode Perawatan (utamanya Rawat Inap)

Mengelompokkan **banyak Encounter** dalam satu episode perawatan. Setiap Encounter di
episode itu menambahkan `Encounter.episodeOfCare[] = { reference: 'EpisodeOfCare/{id}' }`.
Paling relevan untuk **RI** (satu rawat inap = satu episode); RJ umumnya single-encounter.

**Payload FHIR R4:**

```json
{
  "resourceType": "EpisodeOfCare",
  "identifier": [{
    "system": "http://sys-ids.kemkes.go.id/episodeofcare/{organizationId}",
    "value": "{rihdr_no}"
  }],
  "status": "active",
  "type": [{
    "coding": [{
      "system": "http://terminology.hl7.org/CodeSystem/episodeofcare-type",
      "code": "hacc",
      "display": "Home and Community Care"
    }]
  }],
  "patient": { "reference": "Patient/{ihsPatient}" },
  "managingOrganization": { "reference": "Organization/{organizationId}" },
  "period": { "start": "2026-07-14T08:00:00+07:00", "end": null },
  "careManager": { "reference": "Practitioner/{ihsDpjp}" }
}
```

**Metode:**

```php
public function createEpisodeOfCare(array $data): array
{
    $payload = [
        'resourceType' => 'EpisodeOfCare',
        'identifier'   => [[
            'system' => 'http://sys-ids.kemkes.go.id/episodeofcare/' . $this->organizationId,
            'value'  => $data['episodeNo'],            // rihdr_no
        ]],
        'status' => $data['status'] ?? 'active',       // active | finished | cancelled
        'type'   => [[ 'coding' => [[
            'system'  => 'http://terminology.hl7.org/CodeSystem/episodeofcare-type',
            'code'    => 'hacc',
            'display' => 'Home and Community Care',
        ]]]],
        'patient'              => ['reference' => 'Patient/' . $data['patientId']],
        'managingOrganization' => ['reference' => 'Organization/' . $this->organizationId],
        'period' => array_filter([
            'start' => $data['start'] ?? now()->toIso8601String(),
            'end'   => $data['end'] ?? null,           // diisi saat pasien pulang → PUT status 'finished'
        ]),
        'careManager' => ['reference' => 'Practitioner/' . $data['careManagerId']],
    ];
    return $this->makeRequest('post', '/EpisodeOfCare', $payload);
}
```

- **Pemetaan SIRUS:** `episodeNo` = `rihdr_no`; `start` = tgl masuk RI; `end` = tgl pulang
  (kosong selama dirawat, di-`PUT` `status: finished` + `period.end` saat pulang);
  `careManagerId` = `dr_uuid` DPJP.
- **PR:** karena RI belum punya alur kirim SATUSEHAT sama sekali, EpisodeOfCare mengharuskan
  Encounter RI dikirim lebih dulu — implementasikan jalur RI (Encounter) bersamaan.

---

### 9.2 ClinicalImpression — Impresi Klinik

Asesmen klinis dokter (huruf **"A"** di SOAP) — kesimpulan/impresi terhadap kondisi pasien.

**Payload FHIR R4:**

```json
{
  "resourceType": "ClinicalImpression",
  "status": "completed",
  "description": "Asesmen kunjungan rawat jalan",
  "subject": { "reference": "Patient/{ihsPatient}" },
  "encounter": { "reference": "Encounter/{ihsEncounter}" },
  "effectiveDateTime": "2026-07-14T09:15:00+07:00",
  "date": "2026-07-14T09:20:00+07:00",
  "assessor": { "reference": "Practitioner/{ihsDpjp}" },
  "summary": "Suspek ISPA viral, perbaikan klinis, rawat jalan.",
  "finding": [{
    "itemCodeableConcept": {
      "coding": [{ "system": "http://snomed.info/sct", "code": "54150009", "display": "Upper respiratory infection" }]
    }
  }]
}
```

**Metode:**

```php
public function createClinicalImpression(array $data): array
{
    $payload = [
        'resourceType' => 'ClinicalImpression',
        'status'       => $data['status'] ?? 'completed',
        'description'  => $data['description'] ?? null,
        'subject'      => ['reference' => 'Patient/'   . $data['patientId']],
        'encounter'    => ['reference' => 'Encounter/' . $data['encounterId']],
        'effectiveDateTime' => $data['effective'] ?? now()->toIso8601String(),
        'date'         => now()->toIso8601String(),
        'assessor'     => ['reference' => 'Practitioner/' . $data['assessorId']],
        'summary'      => $data['summary'],
        'finding'      => array_map(fn ($f) => [
            'itemCodeableConcept' => ['coding' => [[
                'system'  => 'http://snomed.info/sct',
                'code'    => $f['code'],
                'display' => $f['display'],
            ]]],
        ], $data['findings'] ?? []),
    ];
    return $this->makeRequest('post', '/ClinicalImpression', $payload);
}
```

- **Pemetaan SIRUS:** `summary` = teks section Penilaian/Assessment EMR; `assessorId` = DPJP;
  `finding` opsional (isi bila asesmen dipetakan ke SNOMED).
- **PR:** SNOMED untuk `finding` opsional — kirim tanpa `finding` (hanya `summary`) sudah valid.

---

### 9.3 NutritionOrder — Instruksi Gizi

Order diet pasien. Role **Gizi** sudah punya akses Daftar RI/EMR (lihat modul terkait).

**Payload FHIR R4:**

```json
{
  "resourceType": "NutritionOrder",
  "status": "active",
  "intent": "order",
  "patient": { "reference": "Patient/{ihsPatient}" },
  "encounter": { "reference": "Encounter/{ihsEncounter}" },
  "dateTime": "2026-07-14T10:00:00+07:00",
  "orderer": { "reference": "Practitioner/{ihsDokter}" },
  "oralDiet": {
    "type": [{
      "coding": [{ "system": "http://snomed.info/sct", "code": "435801000124108", "display": "Low sodium diet" }],
      "text": "Diet rendah garam"
    }]
  }
}
```

**Metode:**

```php
public function createNutritionOrder(array $data): array
{
    $payload = [
        'resourceType' => 'NutritionOrder',
        'status'       => $data['status'] ?? 'active',
        'intent'       => 'order',
        'patient'      => ['reference' => 'Patient/'   . $data['patientId']],
        'encounter'    => ['reference' => 'Encounter/' . $data['encounterId']],
        'dateTime'     => $data['dateTime'] ?? now()->toIso8601String(),
        'orderer'      => ['reference' => 'Practitioner/' . $data['ordererId']],
        'oralDiet'     => [ 'type' => [[
            'coding' => [[
                'system'  => 'http://snomed.info/sct',
                'code'    => $data['dietCode'],
                'display' => $data['dietDisplay'],
            ]],
            'text' => $data['dietText'],               // "Diet rendah garam", dst.
        ]]],
    ];
    return $this->makeRequest('post', '/NutritionOrder', $payload);
}
```

- **Pemetaan SIRUS:** `dietText` = teks diet dari EMR (role Gizi); `ordererId` = DPJP/dokter gizi.
- **PR:** butuh tabel/mapping teks-diet → **kode SNOMED diet** (`oralDiet.type.coding`).
  Bisa kirim minimal dengan `text` saja bila kode belum tersedia (sebagian server menerima).

---

### 9.4 Composition — Resume Medis (Bab 28 playbook Rawat Jalan)

Composition **bukan** dokumen naratif baru: ia **indeks** dari resource yang sudah dikirim
selama kunjungan, dirangkai lewat `section[].entry`. Satu kunjungan = satu payload, dikirim
saat kunjungan selesai (setelah `PUT Encounter` status `finished`).

> Sumber: playbook *Resume Medis - Rawat Jalan* Bab 28, disunting 2 Desember 2025.
> **Koreksi penting:** dokumen lama repo ini menulis `type` = `18842-5` *Discharge summary*
> dan menyebut label dashboard "Diet". Keduanya SALAH — changelog playbook v6.1 (24/10/2024)
> mencatat "perubahan kode tipe resume medis". Nilai yang benar ada di tabel bawah.

**Elemen wajib** (bertanda `*` di playbook): `status`, `type`, `subject`, `date`, `author[]`,
`title`, `attester.mode`, `relatesTo[i].code`, `relatesTo[i].target`.
Opsional yang relevan: `identifier`, `category`, `encounter`, `custodian`, `event[]`, `section[]`.

- `identifier.system` = `http://sys-ids.kemkes.go.id/composition/{organizationId}`
- `date` UTC+00 (`YYYY-MM-DDThh:mm:ss+00:00`), tidak boleh < 3 Juni 2014
- `subject` = `Patient/{ihs}`, `encounter` = `Encounter/{id}`, `author[]` = `Practitioner/{ihs}`

| Elemen | Nilai |
|---|---|
| `type.coding` | `http://loinc.org` · **`88645-7`** · *Outpatient hospital Discharge summary* |
| `category.coding` | `http://loinc.org` · **`LP173421-1`** · *Report* |

**Tiga belas section** (kode `TK*` bersistem `http://terminology.kemkes.go.id`, sisanya LOINC).
Sub-section ditulis di `section[i].section[j]`:

| # | Section | Kode | Sub-section | Kode sub | `entry` → resource |
|---|---|---|---|---|---|
| 1 | Anamnesis | TK000003 | Keluhan Utama | 10154-3 | Condition (keluhan utama) |
| | | | Keluhan Penyerta | 11450-4 | Condition |
| | | | Riwayat Alergi | 48765-2 | AllergyIntolerance |
| | | | Riw. Penyakit Pribadi Terdahulu | 11348-0 | Condition (`inactive`) |
| | | | Riw. Penyakit Pribadi Sekarang | 10164-2 | Condition (`active`) |
| | | | Riwayat Penyakit Keluarga | 10157-6 | FamilyMemberHistory |
| | | | Riwayat Pengobatan | 10160-0 | MedicationStatement |
| 2 | Pemeriksaan Fisik | TK000007 | Tanda Vital | 8716-3 | Observation (TTV, kesadaran, antropometri) |
| | | | Head to Toe | 10187-3 | Observation |
| 3 | Pemeriksaan Fungsional | 47420-5 | — | — | Observation (status psikologis) |
| 4 | Perencanaan Perawatan | 18776-5 | — | — | ClinicalImpression, Goal, CarePlan |
| 5 | Pemeriksaan Penunjang | TK000009 | Hasil Lab | 11502-2 | ServiceRequest, Procedure, Specimen, Observation, DiagnosticReport |
| | | | Hasil Radiologi | 18782-3 | ServiceRequest, Observation, Procedure, AllergyIntolerance, DiagnosticReport |
| 6 | Diagnosis | TK000004 | Diagnosis Awal | 42347-5 | Condition (diagnosis masuk) |
| | | | Diagnosis Akhir | 78375-3 | ClinicalImpression, Condition, RiskAssessment |
| 7 | Tindakan/Prosedur Medis | TK000005 | — | — | ServiceRequest, Procedure, Observation |
| 8 | Farmasi (*display* "Obat") | TK000013 | Obat Saat Kunjungan | 42346-7 | MedicationRequest, MedicationDispense, MedicationAdministration |
| | | | Obat Pulang | 75311-1 | MedicationRequest, MedicationDispense |
| 9 | Diet | — | Rekomendasi Diet | 42344-2 | NutritionOrder (`intent: proposal`) |
| | | | Diet yang Diberikan | 61144-2 | NutritionOrder (`intent: order`) |
| 10 | Edukasi | 34895-3 | — | — | Procedure (edukasi) |
| 11 | Kondisi Saat Meninggalkan RS | 10184-0 | — | — | ClinicalImpression, Condition |
| 12 | Rencana Tindak Lanjut | 8653-8 | — | — | Observation, CarePlan, ServiceRequest |
| 13 | Perjalanan Kunjungan Pasien | 8648-8 | — | — | **narasi** `section.text.div` (bukan `entry`) |

**Dua hal yang belum jelas dari playbook** — tanyakan sebelum kirim produksi:
1. Section 9 (Diet) tidak diberi kode di level section-nya; playbook menulis
   `Composition.section.code` padahal judulnya `section.section.title`. Kemungkinan salah tulis.
2. `relatesTo[i].code` + `.target` ditandai **wajib**, padahal resume pertama tidak
   menggantikan/merujuk dokumen mana pun. Perlu konfirmasi nilai yang diterima validator.

**Pemetaan ke node `satuSehat` (RJ) — apa yang SUDAH bisa diisi hari ini:**

| Section | Sumber di node |
|---|---|
| 1a Keluhan Utama | `chiefComplaintId` |
| 1c Riwayat Alergi | `allergyId` |
| 2a Tanda Vital | `observationIds` |
| 3 Pemeriksaan Fungsional | `penilaianObservationIds` (perlu dipilah: playbook memaksudkan status psikologis) |
| 4 Perencanaan Perawatan | `clinicalImpressionId` |
| 5a Lab | `labServiceRequestIds`, `labSpecimenIds`, `labObservationIds`, `labDiagnosticReportIds` |
| 5b Radiologi | `radServiceRequestIds`, `radDiagnosticReportIds` |
| 6a/6b Diagnosis | `conditionIds` (+ `clinicalImpressionId` di 6b) |
| 7 Tindakan | `procedureIds` |
| 8a Obat Saat Kunjungan | `medicationRequestIds`, `medicationDispenseIds` |
| 13 Perjalanan Kunjungan | narasi dirakit dari EMR |

Belum punya sumbernya: 1b, 1d, 1e, 1f (FamilyMemberHistory), 1g (MedicationStatement),
2b head-to-toe (kalau tak dikirim), 8b obat pulang (RJ tidak membedakan), 9 Diet,
10 Edukasi, 11 Kondisi pulang, 12 Rencana tindak lanjut.
**Section yang sumbernya kosong tidak dibuat** — jangan kirim `entry: []`
(validator menolak elemen objek kosong, lihat §3 skill `satusehat-kirim`).

#### Jalur IGD — susunannya BEDA, jangan disamakan

Playbook *Pelayanan Instalasi Gawat Darurat* memakai `type` **`97663-9`**
*Emergency medicine Emergency department Discharge summary* (bukan 88645-7), dan susunan
section-nya berbeda dari rawat jalan:

- **Dua section tambahan di depan:** `Asesmen Awal IGD` (LOINC `97667-0`, entry → Observation
  triase & asesmen awal kecuali pemeriksaan fisik) dan `Skrining` (kemkes `TK000129`,
  entry → Observation + QuestionnaireResponse).
- **Tidak punya section Diet maupun Edukasi.**
- Sisanya (Anamnesis 7 sub, Pemeriksaan Fisik 2 sub, Fungsional, Perencanaan Perawatan,
  Penunjang 2 sub, Diagnosis 2 sub, Tindakan, Farmasi 2 sub, Kondisi Pulang, Rencana Tindak
  Lanjut, Perjalanan Kunjungan) identik kodenya dengan RJ.

Total slot yang bisa diisi: **RJ 24, IGD 23**. `ResumeMedisSection::daftar($jalur)` yang
mengurus perbedaan ini; jalur yang playbook-nya belum dibaca (mis. `ri`) sengaja melempar.

**Implementasi (RJ & UGD):** `App\Support\Terminologi\ResumeMedisSection` (peta 13 section +
`tipeDokumen()` per jalur — jalur selain `rj` sengaja melempar sampai playbooknya dibaca),
`App\Http\Traits\SATUSEHAT\CompositionTrait` (`buildComposition()` terpisah dari
`createComposition()` supaya payload bisa diuji tanpa API), dan kartu ke-13
`⚡kirim-resume-medis` di `pages/transaksi/rj/satu-sehat/` **dan**
`pages/transaksi/ugd/satu-sehat/` — dipasang paling bawah (RJ: di bawah kartu
"Selesaikan Encounter") dan menolak jalan sebelum `encounterFinished`.

---

### 9.5 ImagingStudy — Radiologi

**Status: ✅ Sudah di-wire ke UI** (sender Radiologi RJ/UGD/RI, PR #33) — sebelumnya trait saja.

Implementasi ada di dua trait:

| Trait | Fungsi |
|---|---|
| `ImagingStudyTrait` | Rakit payload FHIR R4 + `POST /ImagingStudy` ke SATUSEHAT |
| `OrthancTrait` | Koneksi ke PACS Orthanc — ambil `StudyInstanceUID` asli via REST |

**Sumber UID:**

| Kondisi | Sumber UID | Keterangan |
|---|---|---|
| `STUDY_UID` terisi di tabel order | UID asli dari PACS Orthanc | Bisa ditelusuri ke gambar DICOM |
| `STUDY_UID` kosong | `uidStudi()` — UID turunan arc `2.25` | Sah bentuknya, tidak bisa ditelusuri |

**Alur end-to-end:**

```
Order Rad (RADNUM_NO)
  → Orthanc /tools/find (AccessionNumber = RADNUM_NO)
    → StudyInstanceUID asli → simpan ke STUDY_UID
      → ImagingStudyTrait::postImagingStudy() → SATUSEHAT
```

Detail alur, DDL, dan setup Orthanc → lihat **`docs/pacs-orthanc.md`** §5 & §5b.

**Trait `ImagingStudyTrait::postImagingStudy()`:**

```php
$data = [
    'kunci'            => "rad-{$rjNo}-{$radDtl}",
    'patientId'        => $ihsPatient,
    'encounterId'      => $ihsEncounter,
    'started'          => $waktuIso,
    'modalityCode'     => 'DX',              // dari modalitasDariDeskripsi()
    'modalityDisplay'  => 'Digital Radiography',
    'procedureCode'    => '36643-5',         // LOINC
    'procedureDisplay' => 'THORAX PA/AP',
    'referrerId'       => $ihsDokter,        // opsional
];
$response = $this->postImagingStudy($data);
```

**Trait `OrthancTrait` — koneksi SIRUS → PACS:**

```php
use App\Http\Traits\SATUSEHAT\OrthancTrait;

// Cari UID dari Orthanc:
$uid = $this->cariStudyUid($radnumNo);

// Sinkron satu row:
$this->sinkronStudyUid('rstxn_rjrads', ['rj_no' => $rjNo, 'rad_dtl' => $dtl], $radnumNo);

// Batch sinkron semua yang belum:
$count = $this->sinkronStudyUidBatch('rstxn_rjrads', 'rj_no', 'rad_dtl');
```

**Env config (`.env`):**

```env
ORTHANC_URL=http://localhost:8042
ORTHANC_USER=sirus
ORTHANC_PASSWORD=<password>
```

**Uji staging:** `ImagingStudy/16744a38-6141-43cc-ad1a-4c0280625374` — diterima
(Encounter UGD 203859, pasien `P02478375538`, THORAX PA/AP LOINC `36643-5`, modalitas DX).

**Yang masih perlu:**

1. Generate `RADNUM_NO` otomatis saat order radiologi dibuat (saat ini kosong semua)
2. Wire `ImagingStudyTrait` ke komponen kirim radiologi SATUSEHAT (saat ini kirim SR + DR saja, ImagingStudy dilewati)
3. Pastikan alat radiologi support DICOM supaya Orthanc terisi gambar asli

---

### 9.6 Immunization — Imunisasi

Belum ada modul imunisasi di sistem — **perlu form capture dulu**.

**Payload FHIR R4:**

```json
{
  "resourceType": "Immunization",
  "status": "completed",
  "vaccineCode": {
    "coding": [{ "system": "http://sys-ids.kemkes.go.id/kfa", "code": "{kfaVaksin}", "display": "Vaksin ..." }]
  },
  "patient": { "reference": "Patient/{ihsPatient}" },
  "encounter": { "reference": "Encounter/{ihsEncounter}" },
  "occurrenceDateTime": "2026-07-14T10:30:00+07:00",
  "primarySource": true,
  "location": { "reference": "Location/{ihsPoli}" },
  "lotNumber": "L123",
  "route": {
    "coding": [{ "system": "http://terminology.hl7.org/CodeSystem/v3-RouteOfAdministration", "code": "IM", "display": "Injection, intramuscular" }]
  },
  "doseQuantity": { "value": 0.5, "system": "http://unitsofmeasure.org", "code": "mL" },
  "performer": [{ "actor": { "reference": "Practitioner/{ihsPetugas}" } }]
}
```

**Metode:**

```php
public function createImmunization(array $data): array
{
    $payload = [
        'resourceType' => 'Immunization',
        'status'       => $data['status'] ?? 'completed',
        'vaccineCode'  => ['coding' => [[
            'system'  => 'http://sys-ids.kemkes.go.id/kfa',    // KFA vaksin
            'code'    => $data['kfaCode'],
            'display' => $data['kfaDisplay'],
        ]]],
        'patient'            => ['reference' => 'Patient/'   . $data['patientId']],
        'encounter'          => ['reference' => 'Encounter/' . $data['encounterId']],
        'occurrenceDateTime' => $data['occurrence'] ?? now()->toIso8601String(),
        'primarySource'      => true,
        'location'           => ['reference' => 'Location/' . $data['locationId']],
        'lotNumber'          => $data['lotNumber'] ?? null,
        'route'              => ['coding' => [[
            'system'  => 'http://terminology.hl7.org/CodeSystem/v3-RouteOfAdministration',
            'code'    => $data['routeCode'] ?? 'IM',
            'display' => $data['routeDisplay'] ?? 'Injection, intramuscular',
        ]]],
        'doseQuantity' => [
            'value'  => $data['doseValue'] ?? 0.5,
            'system' => 'http://unitsofmeasure.org',
            'code'   => 'mL',
        ],
        'performer' => [['actor' => ['reference' => 'Practitioner/' . $data['performerId']]]],
    ];
    return $this->makeRequest('post', '/Immunization', $payload);
}
```

- **Pemetaan SIRUS:** butuh **modul/riwayat imunisasi baru** yang menangkap jenis vaksin
  (ber-KFA, ambil dari master obat `product_id_satusehat`), lot, rute, dosis, petugas.
- **Gap yang harus ditutup:** data belum ada sama sekali → prioritas paling akhir; dahului
  dengan form capture (paling relevan imunisasi anak / vaksin di RJ).

---

## 10. Cara menambah / mengaktifkan resource baru

1. **Sudah ada trait, tinggal wire ke UI:** buat komponen Livewire `kirim-<resource>.blade.php` meniru `kirim-procedure.blade.php` (state, tombol `kirimForCurrent`, `saveResult()` ke node `satusehat`, gate `:disabled="!$hasEncounter"`), lalu render di `satu-sehat-rj-actions.blade.php` (baris ~105-114).
2. **Belum ada trait (EpisodeOfCare/ClinicalImpression/NutritionOrder/Composition/ImagingStudy/Immunization):** buat `App\Http\Traits\SATUSEHAT\<Resource>Trait` meniru pola `ProcedureTrait` (bangun payload FHIR R4 + `POST /<Resource>` via `makeRequest`), pastikan referensi `Encounter/{id}` + `subject Patient/{id}`. **Payload lengkap & metode `createX()` untuk keenam resource ini sudah disiapkan di §9** — tinggal salin.
3. Simpan id hasil ke node JSON `satusehat`. Uji di **sandbox** dulu (ganti env AUTH/BASE URL ke `-stg`).
4. Verifikasi via tabel `web_log_status` (http_req/http_payload/response).

> Lihat juga: `docs/trait-template-api-eksternal.md` (pola trait API eksternal), memori "BPJS sync call = freeze" (timeout), `docs/diagnosa-architecture.md` (kode ICD-10/diagnosa).

---

## 11. Pelajaran uji kirim pertama (2026-08-03)

Semua yang di bawah ini **ditemukan dari respons server**, bukan dari membaca spek. Pola
errornya seragam: `OperationOutcome` dengan `expression` menunjuk elemen yang salah dan
`RuleNumber` Kemkes.

### 11.1 Aturan validator yang menolak

| Error dari server | Sebab | Perbaikan |
|---|---|---|
| `invalid value (expected a DispenseRequest object): []` — `MedicationRequest.dispenseRequest` | elemen ber-kardinalitas 0..1 (objek) dikirim sebagai array kosong | field opsional hanya disertakan bila ada isinya (`MedicationRequestTrait`, `MedicationDispenseTrait`) |
| `Invalid coding system: …/CodeSystem/kfa-satuan (RuleNumber 10050)` — `MedicationDispense.quantity.system` | CodeSystem `kfa-satuan` tidak dikenal | pakai `http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm` kode `TAB` (pola yang sudah dipakai `KirimRawatJalanTrait`) |
| `Element not found: AllergyIntolerance.category (RuleNumber 10075)` | `category` dihilangkan untuk pernyataan "tidak ada alergi" | `category` WAJIB selalu ada → `AlergiSnomed::kategoriFhir()`; `type`/`criticality` tetap boleh dihilangkan |
| `every statusHistory period start and end must be filled (Rule 10122)` — `Encounter.statusHistory` | entri dari `createNewEncounter()`/`startRoomEncounter()` hanya punya `start` | `EncounterTrait::siapkanFinishEncounter()` mengisi `end` tiap entri dari `start` entri berikutnya |
| `Element not found: Encounter.diagnosis (RuleNumber 10457)` | finish dikirim tanpa diagnosis | `Encounter.diagnosis` diisi dari `conditionIds` (`use` = `DD`, `rank` berurutan); tombol Finish menolak lebih dulu bila diagnosa belum dikirim |
| `Code not found: '1306548008' in system: http://snomed.info/sct (RuleNumber 10003)` — `Condition.code[0].code` (Chief Complaint, 2026-08-04) | edisi SNOMED server SATUSEHAT lebih tua dari tx.fhir.org — LOV keluhan bisa memilih konsep baru (contoh ini effective 2024-04-01) yang belum dikenal Kemkes | pin edisi via `config/txfhir.php` `snomed_version` (default International Edition `20240201`): `SnomedTrait` kirim `system-version` di `$expand` & `version` di `$lookup`; cache lama dibersihkan `php artisan snomed:bersihkan-cache` (`--dry-run` dulu); EMR yang terlanjur menyimpan kode baru → pilih ulang kode induk yang mapan (kasus ini `254837009` Malignant neoplasm of breast) |
| `RuleNumber 10432` — `DiagnosticReport.identifier[0].system` (2026-08-24) | `…/diagnostic/{org}` tanpa akhiran layanan ditolak | pecah per layanan: `…/diagnostic/{org}/lab` dan `…/diagnostic/{org}/rad`. **Record yang sudah terkirim memakai bentuk lama** — pemulihan indeks wajib mencari dengan KEDUA bentuk (§12), kalau tidak DR lama tak ketemu dan dibuatkan DR kedua |
| `RuleNumber 10385` — `DiagnosticReport.result` (2026-08-24) | `result` jadi wajib; sender radiologi dulu mengirim DR tanpa Observation sama sekali | tiap order radiologi kini membuat satu Observation ringkas (`category` `imaging`, `valueString` "Lihat hasil pada lampiran radiologi") lalu dirujuk dari `DiagnosticReport.result`. Hasil bacaan sesungguhnya tetap PDF/foto terlampir, bukan nilai terstruktur |

### 11.2 Uji payload TANPA mengirim

Trik yang jauh lebih cepat daripada trial-and-error ke API: pakai anonymous class yang
me-`use` trait-nya lalu **menimpa `makeRequest()`** supaya payload ditangkap, bukan dikirim.

```php
$dryRun = new class {
    use MedicationRequestTrait;
    public array $payloadList = [];
    public function makeRequest($method, $url, $payload = []) { $this->payloadList[] = $payload; return ['id' => 'dry']; }
};
$dryRun->createMedicationRequest([...]);   // periksa $dryRun->payloadList[0]
```

### 11.3 Sumber data yang ternyata salah alamat

Dua sender membaca key yang **tak pernah ada** di JSON EMR, dan keduanya gagal senyap
("berhasil, 0 item") — pola yang wajib dicurigai saat menambah sender baru:

| Sender | Dibaca (salah) | Yang benar |
|---|---|---|
| Observation RJ | `pemeriksaanFisik` / `tandaVital` di akar; key `sistole`, `diastole`, `nadi`, `rr` | `pemeriksaan.tandaVital`; key `sistolik`, `distolik`, `frekuensiNadi`, `frekuensiNafas`, `spo2` |
| MedicationRequest & Dispense RJ/UGD | `kfaCode` / `product_id_satusehat` di item e-resep | lookup `immst_products.product_id_satusehat` lewat `productId` (`App\Support\ObatKfa`) |

Pelajarannya: **verifikasi key ke data nyata** (`findDataRJ()` lalu `array_keys()`), jangan
percaya nama field di kode lama.

### 11.4 Waktu "selesai" berbeda tiap modul

`Encounter.period.end` harus jam layanan berakhir, bukan `now()` (jam petugas mengklik):

| Modul | Urutan sumber | Alasan |
|---|---|---|
| RJ | `taskId7` → `taskId5` → `now()` | probe 150 kunjungan: task5 terisi 125× |
| UGD | `taskId7` → `perencanaan.pengkajianMedis.selesaiPemeriksaan` → `now()` | task5 terisi **0×**, "Selesai Pemeriksaan" 91× |
| RI | `exitDate` (tgl pulang) → `now()` | rawat inap tak memakai task antrean |

Lihat skill `bpjs-antrean-task-id` untuk arti taskId 1–7 & 99.

### 11.5 Pasangan resep → penyerahan

`MedicationDispense.authorizingPrescription` dulu ditebak dari **urutan** daftar
`medicationRequestIds`. Sejak racikan ikut dikirim, urutan itu makin rawan. Sekarang resep
mencatat peta eksplisit `satusehat.medicationRequestItems` (`id`, `jenis`, `kunci`, `kode`,
`display`, `qty`), dan `App\Support\MedicationRequestItem::ambil()` menyusun ulang peta itu
untuk kunjungan lama — **ditolak bila jumlahnya tak cocok**, bukan menebak.


## 12. Indeks kiriman penunjang per-order (`radKirim` / `labKirim`)

Ditambahkan 2026-08-24. Berlaku untuk **enam sender**: lab & radiologi × RJ/UGD/RI.
Kode bersamanya di `app/Http/Traits/SATUSEHAT/PenunjangKirimTrait.php`.

### 12.1 Masalah yang diperbaiki

Sender penunjang menyimpan hasil kiriman sebagai **array datar** — `radServiceRequestIds`,
`labDiagnosticReportIds`, dan seterusnya: daftar UUID tanpa keterangan order mana punya siapa.
Begitu satu order gagal di tengah (ServiceRequest terbentuk, DiagnosticReport belum), tak ada
cara tahu order mana yang bolong. Guard cuma bisa dua sikap, dua-duanya salah:

- **lolos** → SR di-POST ulang dengan identifier yang sama → ditolak duplikat
  (`RuleNumber 20002`) dan macet selamanya;
- **tolak semua** → DR tak pernah tersusul, data di SATUSEHAT tinggal separuh.

### 12.2 Bentuk node

Tiap order sudah punya identifier stabil yang dipakai saat POST. Identifier itu jadi kuncinya:

```jsonc
satusehat: {
  // TETAP ADA — jangan diubah bentuknya
  "radServiceRequestIds":   ["…"],
  "radDiagnosticReportIds": ["…"],
  "radObservationIds":      ["…"],
  "radImagingStudyIds":     ["…"],

  // penanda kelengkapan yang sesungguhnya
  "radKirim": { "rad-673349-11408": { "sr": "…", "obs": "…", "dr": "…", "is": "…" } },
  "labKirim": { "673349-9912":      { "sr": "…", "sp": "…", "obs": ["…"], "dr": "…" } }
}
```

Awalan kunci mengikuti identifier yang dipakai sender: radiologi `rad-` (RJ), `ugd-rad-`,
`ri-rad-`; lab tanpa awalan (RJ), `ugd-`, `ri-`.

> **Array datar wajib dipertahankan.** `App\Support\SatuSehatMonitor` mencocokkan **string
> mentah** `"radServiceRequestIds":["` ke CLOB, dan indikator status di `daftar-rj`/`daftar-ugd`
> serta `kirim-resume-medis` juga membacanya. Mengubah bentuknya memutus mereka diam-diam.

### 12.3 Aturan

1. Guard bukan lagi "ada isinya → tolak", melainkan **"kumpulkan bagian yang belum ada, kirim
   itu saja"**. Order yang semua bagian wajibnya sudah punya id dilewati tanpa memanggil API.
2. **Record lama** belum punya indeks; id-nya dipulihkan sekali lewat pencarian identifier
   (`cariIdLewatIdentifier()`), lalu indeksnya disimpan. **Gagal atau tak ketemu = order
   DILEWATI, bukan dikirim ulang** — array datar sudah membuktikan ia pernah terkirim, jadi
   POST ulang hanya akan kena 20002 lagi. Sikap ini sama dengan guard lama, jadi record lama
   tak pernah lebih buruk dari sebelumnya.
3. **DiagnosticReport punya dua bentuk `identifier.system`** sejak RuleNumber 10432 (§11.1).
   Pemulihan wajib mencoba yang baru dulu (`…/diagnostic/{org}/rad|lab`) lalu jatuh ke yang
   lama (tanpa akhiran). Kalau tidak, DR lama tak ketemu dan dibuatkan **DR kedua** untuk
   order yang sama — SATUSEHAT tak menolaknya karena system-nya memang beda.
4. **Observation bukan penanda kelengkapan.** Ia tak punya identifier: tak bisa dipulihkan,
   dan tak akan ditolak duplikat. Di radiologi, Observation dibuat **di dalam** cabang
   pembuatan DR — kalau di luar, laporan yang sudah ada ditinggali observasi yatim tiap
   tombol kirim ditekan. Karena itu `obs` **tidak** masuk daftar bagian wajib.
5. **Batas di lab:** paket lama yang DR-nya bolong sengaja **dilewati**, bukan dikirim ulang —
   daftar Observation-nya tak bisa dipulihkan, dan mengirim ulang berarti menggandakan hasil
   lab di SATUSEHAT. Jumlah yang dilewati disebut di toast, tidak hilang diam-diam.

### 12.4 Efek sampingan yang menguntungkan

Foto radiologi yang diupload **sesudah** SATUSEHAT dikirim kini bisa disusulkan
ImagingStudy-nya — order itu jadi "belum lengkap" di sisi `is`. Sebelumnya tak pernah bisa,
karena guard lama memblokir seluruh kiriman begitu DR-nya ada.

### 12.5 Menambah resource baru ke sender penunjang

Tambahkan bagiannya ke daftar bagian wajib dan ke `catatKirim()`. **Jangan** membuat array
datar baru sebagai penanda "sudah dikirim" — itu justru masalah yang bab ini perbaiki.
Kalau resource-nya tak punya identifier (seperti Observation), perlakukan seperti aturan 4.

> **Belum diuji kirim live.** Yang khususnya belum terbukti: apakah SATUSEHAT menerima
> `?identifier=` yang ter-`rawurlencode` penuh (seluruh `system|value`). Kalau ditolak,
> efeknya hanya pemulihan record lama gagal → order dilewati (aman), bukan kiriman ganda.
