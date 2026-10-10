---
name: satusehat-kirim
description: Aturan mengirim resource ke SATUSEHAT (FHIR R4) di repo ini — sumber data JSON EMR yang benar, helper KFA/racikan, jebakan validator Kemkes, dan cara menguji payload tanpa mengirim. WAJIB dibaca sebelum menambah/mengubah sender di app/Http/Traits/SATUSEHAT atau resources/views/pages/transaksi/*/satu-sehat, atau saat kiriman ditolak OperationOutcome.
---

# Kirim SATUSEHAT

Rujukan lengkap: **`docs/satusehat-api.md`** (arsitektur, payload per resource, backlog).
Skill ini isinya yang paling sering bikin salah.

## 1. Verifikasi sumber data ke DATA NYATA, jangan percaya kode lama

Empat kelompok sender pernah membaca key yang **tak pernah ada** di JSON EMR dan gagal
SENYAP — "berhasil dikirim (0 item)" atau "tidak ada data", tanpa error:

| Sender | Dibaca (salah) | Yang benar |
|---|---|---|
| Observation RJ | `pemeriksaanFisik`/`tandaVital` di akar, key `sistole`/`diastole`/`nadi`/`rr` | `pemeriksaan.tandaVital`, key `sistolik`/`distolik`/`frekuensiNadi`/`frekuensiNafas`/`spo2` |
| MedicationRequest & Dispense RJ/UGD | `kfaCode`/`product_id_satusehat` di item e-resep | lookup master obat lewat `productId` |
| Condition & ClinicalImpression RJ (+ `stepDiagnosa` bundel) | `diagnpinaList[]`/`diagnosaPinaUtama`, key `kodeIcdx`/`descIcdx` | `diagnosis[]` (ditulis rm-diagnosa-rj-actions), key `icdX ?? diagId` + `diagDesc` — sama dengan sender UGD |
| Procedure RJ & UGD (+ `stepTindakan` bundel) | `tindakanList`/`tindakan`, key `kodeIcd9`/`descIcd9` | `procedure[]` (ditulis rm-diagnosa-*-actions), key `procedureId` (= ICD-9, mis. `93.39`) + `procedureDesc` — sama dengan sender RI |

Sebelum menulis sender: `findDataRJ('...')` lalu `array_keys()` node yang dipakai. Sekali
saja, tapi wajib.

## 2. Pakai helper bersama, jangan menyalin logika

| Helper | Untuk |
|---|---|
| `App\Support\EresepJson` | bentuk e-resep RJ/UGD (datar) vs RI (berlembar) → `lembar()`, `jumlahRacikan()` |
| `App\Support\ObatKfa` | obat non-racikan + kode KFA dari `immst_products.product_id_satusehat` |
| `App\Support\RacikanKfa` | grup racikan → `ingredient[]` KFA; `grupList()`, `ringkas()`, `fhirIngredient()` |
| `App\Support\MedicationRequestItem` | peta obat → MedicationRequest untuk `authorizingPrescription` dispense |
| `App\Support\AlergiSnomed` | kode "tidak ada alergi" + `kategoriFhir()` |

Logika KFA yang disalin ke dua tempat itulah yang dulu bikin RJ/UGD berbeda diam-diam dari RI.

## 3. Jebakan validator Kemkes (semuanya pernah menolak sungguhan)

- **Elemen objek (0..1) jangan dikirim `[]`** → `invalid value (expected a DispenseRequest object): []`.
  Field opsional hanya disertakan bila ada isinya.
- **`quantity.system`**: `…/CodeSystem/kfa-satuan` DITOLAK (RuleNumber 10050). Pakai
  `http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm` kode `TAB`.
- **`AllergyIntolerance.category` WAJIB** (10075), termasuk untuk "tidak ada alergi".
  `type`/`criticality` boleh dihilangkan di situ, `category` tidak.
- **`Encounter.statusHistory`**: tiap entri wajib `start` DAN `end` (10122). Entri bawaan
  `createNewEncounter()`/`startRoomEncounter()` hanya punya `start` →
  `EncounterTrait::siapkanFinishEncounter()` yang merapikan.
- **`Encounter.diagnosis` wajib saat finish** (10457) → dari `conditionIds`, `use` = `DD`.
  Tolak lebih dulu bila diagnosa belum dikirim, jangan kirim lalu gagal.
- **Racikan**: campurannya tak punya KFA → `Medication.code` cukup `code.text`,
  `medicationType` = `SD`/Compound, kode KFA ada di `ingredient[]`.

## 4. Uji payload TANPA mengirim

Timpa `makeRequest()` di anonymous class yang me-`use` trait-nya:

```php
$dryRun = new class {
    use MedicationRequestTrait;
    public array $payloadList = [];
    public function makeRequest($method, $url, $payload = []) { $this->payloadList[] = $payload; return ['id' => 'dry']; }
};
$dryRun->createMedicationRequest([...]);
```

Jauh lebih cepat daripada trial-and-error ke API — dan **wajib**, karena `.env` menunjuk
**produksi** (`api-satusehat.kemkes.go.id`, tanpa `-stg`).

## 5. Yang tak terkirim WAJIB dilaporkan

Item tanpa kode (KFA/ICD/LOINC) boleh dilewati, **tidak boleh hilang diam-diam**: kartu
menampilkan hitungannya sebelum kirim, toast menyebutkannya sesudah kirim, dan untuk
racikan disebut **nama bahan** yang menghalangi supaya bisa langsung dilengkapi di master.

## 6. Waktu "selesai" beda tiap modul

`Encounter.period.end` = jam layanan berakhir, bukan `now()`:
RJ `taskId7` → `taskId5`; UGD `taskId7` → `perencanaan.pengkajianMedis.selesaiPemeriksaan`
(task5 tak pernah dipakai UGD); RI `exitDate`. Lihat skill `bpjs-antrean-task-id`.
