# Bridging iDRG / INACBG (E-Klaim Kemenkes) di Sirus

Dokumen ini menjelaskan arsitektur, alur, dan konvensi modul **Bridging iDRG / INACBG** di Sirus untuk tiga modul transaksi: **RI** (Rawat Inap), **RJ** (Rawat Jalan), **UGD** (IGD).

Referensi spec: **Manual WS E-Klaim 5.10.x** (changelog 20260403).

---

## 1. Arsitektur

### 1.1 Struktur file

```
resources/views/pages/transaksi/{modul}/
├── daftar-{modul}/
│   └── idrg-{modul}-actions.blade.php       ← ORCHESTRATOR (modal + cara pakai + render SFC per step)
└── idrg/
    ├── kirim-generate-number.blade.php       ← Step 1
    ├── kirim-new-claim.blade.php             ← Step 2
    ├── kirim-set-data.blade.php              ← Step 3
    ├── kirim-diagnosa-idrg.blade.php         ← Step 4
    ├── kirim-prosedur-idrg.blade.php         ← Step 5
    ├── kirim-group-idrg.blade.php            ← Step 6 (Grouping iDRG Stage 1)
    ├── kirim-group-idrg-2.blade.php          ← Step 7 (Grouping iDRG Stage 2 — Topup)
    ├── kirim-final-idrg.blade.php            ← Step 8
    ├── kirim-import-inacbg.blade.php         ← Step 9
    ├── kirim-diagnosa-inacbg.blade.php       ← Step 10
    ├── kirim-prosedur-inacbg.blade.php       ← Step 11
    ├── kirim-group-inacbg-1.blade.php        ← Step 12 (Grouping INACBG Stage 1)
    ├── kirim-group-inacbg-2.blade.php        ← Step 13 (Grouping INACBG Stage 2 — Special CMG)
    ├── kirim-final-inacbg.blade.php          ← Step 14
    ├── kirim-final-klaim.blade.php           ← Step 15
    ├── kirim-send-klaim.blade.php            ← Step 16
    └── kirim-print-klaim.blade.php           ← Step 17
```

### 1.2 Lapisan kode

| Lapisan | File | Tanggung jawab |
|---|---|---|
| **Trait API** | `app/Http/Traits/IDRG/iDrgTrait.php` | Pemanggilan API e-klaim (encrypt/decrypt, all 17+ method) |
| **Trait EMR** | `app/Http/Traits/Txn/{Ri,Rj,Ugd}/Emr{RI,RJ,UGD}Trait.php` | Akses `findDataXX()`, `updateJsonXX()`, `lockXXRow()` ke JSON header transaksi |
| **Orchestrator** | `idrg-{modul}-actions.blade.php` | Modal full-screen, sidebar "Cara Pakai", load SFC per step, gating section B/C/D |
| **SFC per-step** | `kirim-*.blade.php` (Volt SFC) | Self-contained: state, API call, view |

### 1.3 Pola sinkronisasi state (event split)

Setiap SFC saat melakukan mutate state mengirim event ke parent:

```php
$this->dispatch('idrg-section-changed-{suffix}', riHdrNo: (string) $this->riHdrNo);
```

| Modul | Event suffix |
|---|---|
| RI | `idrg-section-changed-ri` |
| RJ | `idrg-section-changed` (tanpa suffix, legacy — jangan diubah) |
| UGD | `idrg-section-changed-ugd` |

Parent orchestrator listener:

```php
#[On('idrg-section-changed-ri')]
public function onIdrgSectionChanged(string $riHdrNo): void
{
    if ((string) $this->riHdrNo !== $riHdrNo) return;
    $this->loadData();
    $this->incrementVersion('modal');   // bump wire:key → semua SFC remount
}
```

Mekanisme: parent bump version → render key SFC berubah → SFC remount → fresh state via `mount()` → `reloadState()` baca JSON terbaru. **Tidak ada cross-sibling broadcast** untuk hindari race "A request already contains one of the messages" (Livewire 3 bundle interceptor).

### 1.4 Gating section

Orchestrator menampilkan section secara bertahap berdasarkan state:

| Section | Kondisi |
|---|---|
| A. Setup Klaim | Selalu tampil |
| B. Coding iDRG | `$hasClaim` (Step 2 sukses) |
| C. Coding INACBG | `$idrgFinal` (Step 8 sukses) |
| D. Finalisasi Klaim | `$inacbgFinal` (Step 14 sukses) |

---

## 2. Konvensi Modul-Specific

| | RI | RJ | UGD |
|---|---|---|---|
| Parameter primary | `$riHdrNo` | `$rjNo` | `$rjNo` (legacy — UGD juga pakai `$rjNo`) |
| Trait EMR | `EmrRITrait` | `EmrRJTrait` | `EmrUGDTrait` |
| Lookup | `findDataRI()` | `findDataRJ()` | `findDataUGD()` |
| Update JSON | `updateJsonRI()` | `updateJsonRJ()` | `updateJsonUGD()` |
| Lock row | `lockRIRow()` | `lockRJRow()` | `lockUGDRow()` |
| Modal event | `daftar-ri.idrg.open` | `daftar-rj.idrg.open` | `daftar-ugd.idrg.open` |
| Section event | `idrg-section-changed-ri` | `idrg-section-changed` | `idrg-section-changed-ugd` |
| Step event sample | `idrg-prosedur-ri.set` | `idrg-prosedur-rj.set` | `idrg-prosedur-ugd.set` |
| Jenis Rawat | `1` | `2` | `3` (IGD) |

---

## 3. Schema State (`rstxn_*.datadaftar*_json.idrg`)

Semua state iDRG/INACBG disimpan di field JSON `idrg` di header transaksi (`rstxn_rjhdrs.datadaftarpolirj_json`, `rstxn_rihdrs.datadaftarri_json`, `rstxn_ugdhdrs.datadaftarugd_json`).

Schema key di `idrg`:

```
{
    // === A. Setup Klaim ===
    "nomorSep": "...",
    "claimData": { jenis_rawat, tarif_rs, tgl_masuk, tgl_pulang, ... },
    "claimDataSavedAt": "...",

    // === B. Coding iDRG ===
    "coderDiagnosa":     [ { code, desc, kategori, validcode, validInfo } ],
    "coderDiagnosaSyncedAt": "...",
    "coderProsedur":     [ { code, desc, multiplicity, settingGroup, validcode, validInfo } ],
    "coderProsedurSyncedAt": "...",
    "idrgDiagnosaString": "I10.0+R51",    // string terkirim ke API
    "idrgProsedurString": "81.53#86.28",
    "idrgDiagnosa":       { ...response API set_idrg_diagnosa },
    "idrgProsedur":       { ...response API set_idrg_prosedur },
    "idrgDiagnosaExpanded": [...],         // simpan expanded[] untuk debug + valid badge detail
    "idrgProsedurExpanded": [...],
    "idrgGroup":          { ...response API grouper_idrg (stage 1) },
    "idrgUngroupable":    false,
    "idrgStage2":         { ...response API grouper_idrg (stage 2) },
    "idrgTopupCodesInput": "1A001#2B003",  // hash-separated codes Stage 2 yang dikirim
    "idrgFinal":          true,
    "idrgFinalAt":        "2026-05-20T...",

    // === C. Coding INACBG ===
    "inacbgImport":       { ...response API import_idrg_to_inacbg },
    "inacbgImportedAt":   "...",
    "coderInacbgDiagnosa":[ ... ],     // sama bentuk dengan coderDiagnosa
    "coderInacbgDiagnosaSyncedAt": "...",
    "coderInacbgProsedur":[ ... ],
    "coderInacbgProsedurSyncedAt": "...",
    "inacbgDiagnosaString":"...",
    "inacbgProsedurString":"...",
    "inacbgDiagnosa":     { ...response API set_inacbg_diagnosa },
    "inacbgProsedur":     { ...response API set_inacbg_prosedur },
    "inacbgDiagnosaExpanded": [...],
    "inacbgProsedurExpanded": [...],
    "inacbgStage1":       { ...response API grouper_inacbg (stage 1) },
    "inacbgUngroupable":  false,
    "inacbgStage2":       { ...response API grouper_inacbg (stage 2) },
    "inacbgSpecialCmgInput": "SP-001#SD-002",
    "inacbgFinal":        true,
    "inacbgFinalAt":      "...",

    // === D. Finalisasi Klaim ===
    "klaimFinal":         true,
    "klaimFinalAt":       "...",
    "coderNik":           "3201234567890001",   // emp_id user yang final
    "klaimPdfPath":       "...",
    "klaimPdfData":       "..."                  // base64 pdf
}
```

---

## 4. Detail Per-Step

### Section A — Setup Klaim

| # | File | Method API | Output state | Catatan |
|---|---|---|---|---|
| 1 | `kirim-generate-number.blade.php` | `generateNomorKlaim($noKartu)` | `idrg.nomorKlaim` | **Hanya untuk pasien khusus** (COVID-19, KIPI, Bayi Baru Lahir, Co-Insidens). Pasien BPJS biasa pakai SEP yang sudah ada — skip. |
| 2 | `kirim-new-claim.blade.php` | `newClaim($nomorSep, $nomorKartu, $nik, $namaPasien, $tglLahir, $sex, $jenisRawat, $kelasRawat)` | `idrg.nomorSep` | Registrasi SEP ke E-Klaim. Setelah ini Section B muncul. |
| 3 | `kirim-set-data.blade.php` | `setClaimData($nomorSep, $tarifRs, $tglMasuk, $tglPulang, ...)` | `idrg.claimData` | Hanya muncul kalau `hasClaim`. Tarif & tanggal masuk/pulang auto dari rincian kasir. |

### Section B — Coding iDRG

| # | File | Method API | Catatan |
|---|---|---|---|
| 4 | `kirim-diagnosa-idrg.blade.php` | `setDiagnosaIdrg($nomorSep, $diagnosa)` | Coder Editor: auto-sync dari EMR `diagnosis[]` pertama kali (primary di depan). Coder casemix bisa edit tanpa ubah EMR. Format string: `"PRI#SEC#SEC#..."`. |
| 5 | `kirim-prosedur-idrg.blade.php` | `setProsedurIdrg($nomorSep, $procedure)` | Auto-sync dari EMR `procedure[]`. Field **Mult** (`multiplicity`) untuk kode yang diulang (`+N`). Setting group sudah disembunyikan dari UI (semua default `1`). String API: `"86.22+3#81.53"`. Format `#` kirim untuk hapus semua. |
| 6 | `kirim-group-idrg.blade.php` | `grouperIdrgStage1($nomorSep)` | Output: `idrgGroup` dengan `drg_code`, `mdc_number`, `topup_options`, dll. Kalau `mdc_number == 36` → ungroupable. Card hasil **otomatis merge data Stage 2** kalau tersedia (Total CW = DRG CW + Σ topup CW, Total Klaim = Total CW × NBR). |
| 7 | `kirim-group-idrg-2.blade.php` | `grouperIdrgStage2($nomorSep, $topupCodes)` | Tampil hanya jika `topup_options` ada. **UI: x-select-input per kategori** (groupBy `type` field). Single-select per kategori. Codes yang dipilih di-join `#`. |
| 8 | `kirim-final-idrg.blade.php` | `finalIdrg($nomorSep)`, `reeditIdrg($nomorSep)` | Final → `idrgFinal=true`. **Edit Ulang** → clear `idrgGroup`, `idrgUngroupable`, `idrgStage2`, `idrgTopupCodesInput`. Card grouping auto-hidden sampai re-group. |

### Section C — Coding INACBG (setelah iDRG final)

| # | File | Method API | Catatan |
|---|---|---|---|
| 9 | `kirim-import-inacbg.blade.php` | `importIdrgToInacbg($nomorSep)` | **Replace mode**: setelah API sukses, reset `coderInacbgDiagnosa/Prosedur` + `coderInacbgDiagnosa/ProsedurSyncedAt` + `inacbgDiagnosa/ProsedurString`. Saat parent re-render, step 10-11 auto-resync dari `idrg.coderDiagnosa/Prosedur`. |
| 10 | `kirim-diagnosa-inacbg.blade.php` | `setDiagnosaInacbg($nomorSep, $diagnosa)` | Sama dengan step 4, tapi untuk INACBG. Override kode IM-only di sini. |
| 11 | `kirim-prosedur-inacbg.blade.php` | `setProsedurInacbg($nomorSep, $procedure)` | Sama dengan step 5, **tapi tanpa kolom Mult/Setting** di UI (INACBG vanilla tidak pakai notasi `+N`). |
| 12 | `kirim-group-inacbg-1.blade.php` | `grouperInacbgStage1($nomorSep)` | Output: `inacbgStage1` dengan `cbg.code`, `cost_weight`, `special_cmg_option`. Kalau kode CBG diawali `X` → ungroupable. |
| 13 | `kirim-group-inacbg-2.blade.php` | `grouperInacbgStage2($nomorSep, $specialCmg)` | Hanya jika `special_cmg_option` ada (implant/prosthesis/investigation/drug). **UI: x-select-input per kategori** (Special Procedure / Special Prosthesis / Special Investigation / Special Drug — group by `type`). |
| 14 | `kirim-final-inacbg.blade.php` | `finalInacbg($nomorSep)`, `reeditInacbg($nomorSep)` | Final → `inacbgFinal=true`. **Edit Ulang** → clear `inacbgStage1`, `inacbgStage2`, `inacbgUngroupable`. |

### Section D — Finalisasi Klaim (setelah INACBG final)

| # | File | Method API | Catatan |
|---|---|---|---|
| 15 | `kirim-final-klaim.blade.php` | `finalKlaim($nomorSep, $coderNik, $payorId)` | `coderNik` otomatis dari `emp_id` user login (Karyawan). |
| 16 | `kirim-send-klaim.blade.php` | `sendClaimIndividual($nomorSep)` | Kirim ke data center BPJS. |
| 17 | `kirim-print-klaim.blade.php` | `printKlaim($nomorSep)` | PDF base64 → tampil di SFC step 17 + tombol download. |

---

## 5. Pola UX Penting

### 5.1 Coder Editor (Diagnosa / Prosedur)

Tabel inline-edit dengan:
- **wire:model (tanpa modifier)** untuk input mult / kategori → sync state lokal browser saja (tidak fire request per blur)
- **wire:click di action button** → request bawa state terbaru otomatis
- Pola sama dengan modul administrasi (`lain-lain-rj.blade.php`)
- **Mencegah race condition** "A request already contains one of the messages" akibat 2 method action dari komponen yang sama di batch yang sama (Livewire 3 bundle interceptor bertabrakan dengan reactive child component seperti LOV).

Implementasi di `kirim-prosedur-idrg.blade.php`:

```php
private function persistCoder(): void
{
    DB::transaction(function () {
        $this->lockRIRow($this->riHdrNo);
        $data = $this->findDataRI($this->riHdrNo);
        $sanitized = [];
        foreach ($this->coderProsedur as $c) {
            $code = trim((string) ($c['code'] ?? ''));
            if ($code === '') continue;
            $sanitized[] = [
                'code' => $code,
                'desc' => (string) ($c['desc'] ?? ''),
                'multiplicity' => max(1, (int) ($c['multiplicity'] ?? 1)),
                'settingGroup' => max(1, (int) ($c['settingGroup'] ?? 1)),
                'validcode' => $c['validcode'] ?? null,
            ];
        }
        $data['idrg']['coderProsedur'] = $sanitized;
        $this->updateJsonRI($this->riHdrNo, $data);
    });
}

public function setForCurrent(): void
{
    $this->persistCoder();   // commit wire:model state → DB
    $this->set($this->riHdrNo);  // baru hit API
}
```

### 5.2 Valid IM badge (kolom "Valid IM")

Setelah `setDiagnosa` / `setProsedur` API sukses, response `expanded[]` di-parse:

```php
foreach ($idrg['coderProsedur'] as &$c) {
    $item = $byCode[$c['code']];
    $c['validcode'] = (string) ($item['validcode'] ?? '');
    $extra = $item;
    unset($extra['code'], $extra['validcode']);
    $c['validInfo'] = $extra;  // simpan semua field tambahan (description, im_only, dst.)
}
$idrg['idrgProsedurExpanded'] = $expanded;  // simpan raw untuk debug accordion
```

View badge:
- `validcode='1'` → badge **Valid** (success)
- `validcode='0'` → badge:
  - **"Kode IM tidak diakui"** kalau deteksi suffix `(IM)` di deskripsi master atau flag API (`im_only`/`imOnly`/`im`/`is_im`)
  - **"IM tidak berlaku"** untuk INACBG step 10-11 yang spesifik soal IM
  - **"Tidak Valid"** untuk kasus lain
- Reason text di bawah badge (prioritas: API `description`/`message`/`validcode_message`/`reason` > heuristic)
- Tooltip hover JSON penuh
- Accordion `[debug] raw expanded[] response` di bawah tabel untuk inspect raw

### 5.3 Stage 2 Topup — x-select-input per kategori

`topup_options` (iDRG Stage 2) dan `special_cmg_option` (INACBG Stage 2) di-group berdasarkan field `type` di server-side:

```php
$byType = [];
foreach ($specialCmgOptions as $opt) {
    $typeLabel = (string) ($opt['type'] ?? 'Special CMG');
    $slug = self::slugType($typeLabel);  // "Special Procedure" → "special_procedure"
    $byType[$slug] ??= ['label' => $typeLabel, 'options' => []];
    $byType[$slug]['options'][] = $opt;
}
```

Render satu `x-select-input` per kategori dengan opsi `— None —` + opsi-opsinya. State `$selectedCmg` jadi assoc `[type_slug => code]`. Saat klik "Jalankan/Group Ulang", non-empty codes di-join `#` → kirim ke API.

UI mirror tampilan e-klaim (Special Procedure, Special Prosthesis, Special Investigation, Special Drug).

### 5.4 Hasil Grouping — merge Stage 2

`kirim-group-idrg.blade.php` (Stage 1 card) otomatis merge data Stage 2 untuk display:

- Loop topup rows antara DRG dan NBR
- Total CW recomputed: **`DRG CW + Σ topup CW`**
- Total Klaim recomputed: **`Total CW × NBR`**
- Badge berubah dari "Perlu Stage 2 (N opsi)" → "Stage 2 selesai (N topup applied)"

Contoh tampilan setelah Stage 2:

```
DRG     Hip Revision Proc.    1807119     DRG CW: 5.14
Top-up  Hip Implant           13031       Top Up CW: 1.9702
NBR     Rp 8.037.060                      Total CW: 7.1102
Total Klaim                               Rp 57.145.099
```

### 5.5 Edit Ulang → clear grouping

`reedit()` di step 8 (Final iDRG) dan step 14 (Final INACBG) **clear hasil grouping yang stale** supaya user wajib re-group setelah edit coding:

```php
public function reedit(): void
{
    // ... call API reedit
    $idrg['idrgFinal'] = false;
    $idrg['idrgFinalAt'] = null;
    // Clear stale grouping — wajib re-group setelah edit coding
    $idrg['idrgGroup'] = [];
    $idrg['idrgUngroupable'] = false;
    $idrg['idrgStage2'] = [];
    $idrg['idrgTopupCodesInput'] = '';
    $this->saveResult($idrg);
}
```

Card step 6, 7 auto-hidden karena `@if (!empty($idrgGroup))` / `@if (!empty($stage2))`.

### 5.6 Import iDRG → INACBG — Replace mode

`importInacbg()` di step 9 tidak hanya call API e-klaim — juga **reset coder INACBG state** supaya step 10-11 auto-resync dari `idrg.coderDiagnosa/Prosedur`:

```php
$idrg['inacbgImport'] = $res['response'] ?? [];
$idrg['inacbgImportedAt'] = now()->toIso8601String();
// REPLACE coder INACBG
$idrg['coderInacbgDiagnosa'] = [];
$idrg['coderInacbgDiagnosaSyncedAt'] = null;
$idrg['coderInacbgProsedur'] = [];
$idrg['coderInacbgProsedurSyncedAt'] = null;
$idrg['inacbgDiagnosaString'] = null;
$idrg['inacbgProsedurString'] = null;
```

---

## 6. Trait API — `iDrgTrait`

`app/Http/Traits/IDRG/iDrgTrait.php`

### 6.1 Method list

| Method | Endpoint metadata |
|---|---|
| `generateNomorKlaim($noKartu)` | `{method: 'new_claim', stage: 1}` |
| `newClaim($nomorSep, ...)` | `{method: 'new_claim', stage: 2}` |
| `setClaimData($nomorSep, ...)` | `{method: 'set_claim_data'}` |
| `setDiagnosaIdrg($nomorSep, $diagnosa)` | `{method: 'set_idrg_diagnosa'}` |
| `setProsedurIdrg($nomorSep, $procedure)` | `{method: 'set_idrg_prosedur'}` |
| `getDiagnosaIdrg($nomorSep)` | `{method: 'get_idrg_diagnosa'}` |
| `getProsedurIdrg($nomorSep)` | `{method: 'get_idrg_prosedur'}` |
| `searchDiagnosaIdrg($keyword)` | `{method: 'search_idrg_diagnosa'}` |
| `searchProsedurIdrg($keyword)` | `{method: 'search_idrg_prosedur'}` |
| `grouperIdrgStage1($nomorSep)` | `{method: 'grouper', grouper: 'idrg', stage: 1}` |
| `grouperIdrgStage2($nomorSep, $topupCodes)` | `{method: 'grouper', grouper: 'idrg', stage: 2}` |
| `finalIdrg($nomorSep)` | `{method: 'idrg_grouper_final'}` |
| `reeditIdrg($nomorSep)` | `{method: 'idrg_reedit'}` |
| `importIdrgToInacbg($nomorSep)` | `{method: 'import_idrg_inacbg'}` |
| `setDiagnosaInacbg($nomorSep, $diagnosa)` | `{method: 'set_inacbg_diagnosa'}` |
| `setProsedurInacbg($nomorSep, $procedure)` | `{method: 'set_inacbg_prosedur'}` |
| `grouperInacbgStage1($nomorSep)` | `{method: 'grouper', stage: 1}` |
| `grouperInacbgStage2($nomorSep, $specialCmg)` | `{method: 'grouper', stage: 2}` |
| `finalInacbg($nomorSep)` | `{method: 'final_klaim_inacbg'}` |
| `reeditInacbg($nomorSep)` | `{method: 'reedit_klaim'}` |
| `finalKlaim($nomorSep, $coderNik, $payorId)` | `{method: 'final_klaim'}` |
| `sendClaimIndividual($nomorSep)` | `{method: 'send_claim_individual'}` |
| `printKlaim($nomorSep)` | `{method: 'print_klaim'}` |

### 6.2 Helper

- `eklaim_url($debug)` — pilih URL test/prod berdasarkan flag `IDRG_DEBUG` di `.env`
- `inacbgEncrypt($body, $key)` / `response_decrypt($response, $key, ...)` — AES encryption layer (kecuali debug mode)
- `EKLAIM_ERROR_MAP` (`E20xx`) + `describeEklaimError($metadata, $context)` — translate kode error API ke pesan Indonesia
- `EKLAIM_UNGROUPABLE_MAP` (`3611199`, `3635929`, dst.) + `describeUngroupable($groupResult)` — translate kode ungroupable + saran kode yang harus ditambah

### 6.3 ENV

```dotenv
IDRG_DEBUG=false      # true = bypass encrypt (untuk testing local), false = prod
IDRG_KEY=...          # secret key untuk AES encrypt/decrypt
```

URL endpoint hardcoded di `eklaim_url()` — test vs prod.

---

## 7. Known Limitations & Future Work

### 7.1 Tampilan reason "Tidak Valid" — masih sebagian heuristik

API e-klaim **kemungkinan** tidak selalu mengirim field `description`/`message` di `expanded[]` untuk kode invalid. Saat ini kita pakai:

1. **Prioritas**: field API jika ada (`description`/`message`/`validcode_message`/`reason`)
2. **Fallback heuristic**: deteksi suffix `(IM)` di deskripsi master lokal → label "Kode IM tidak diakui"

Untuk dapat kepastian schema response: buka **accordion `[debug] raw expanded[] response`** di bawah tabel coder, copy JSON-nya. Kalau API ternyata kirim field reason, kita bisa drop heuristic.

### 7.2 Kategori topup — depend on API `type` field

Stage 2 dropdown grouping mengandalkan field `type` per option dari API. Kalau API tidak kasih `type`, semua opsi masuk grup `'default'` → satu dropdown gabungan (still functional, just no per-category split).

### 7.3 RJ event suffix legacy

Modul RJ pakai event `idrg-section-changed` (tanpa suffix `-rj`). Ini legacy — listener subscribe ke nama ini. Kalau mau di-rename ke `idrg-section-changed-rj` untuk konsistensi, harus update listener juga (`idrg-rj-actions.blade.php`).

### 7.4 UGD pakai `$rjNo`

Modul UGD masih pakai property name `$rjNo` (bukan `$ugdNo`). Ini legacy nomenclature — tidak diubah untuk minimize refactor risk.

---

## 8. Troubleshooting

### "A request already contains one of the messages in this array"

Race condition Livewire 3 saat 2 method action dari komponen yang sama masuk di batch yang sama, dan komponen tersebut punya **reactive child** (LOV procedure dengan `#[Reactive]` property).

**Fix**: refactor input inline ke `wire:model` (tanpa modifier) supaya tidak ada method call per blur. State lokal sync ikut click button → 1 action saja per batch.

Reference: `kirim-prosedur-idrg.blade.php` `persistCoder()` pattern.

### "iDRG harus final terlebih dahulu" saat klik Import INACBG

Step 9 (Import) butuh `idrgFinal=true`. Lengkap dulu step 8 (Final iDRG) — kalau ungroupable atau `topup_options` ada tapi Stage 2 belum dijalankan, Final iDRG tetap terkunci.

### Hasil Grouping muncul "** Rp ..." dengan tanda `**`

Tanda `**` = nilai **belum final**, masih bisa berubah. Hilang setelah `idrgFinal=true` atau `inacbgFinal=true`.

### "MDC 36" / "Ungroupable" di Stage 1

Kombinasi diagnosa + prosedur tidak match aturan grouper. Lihat field `error_code` di response untuk kode spesifik (`3611199` = gender mismatch, `3635929` = butuh kode konsultasi rehab medis, dll.). `describeUngroupable()` kasih saran kode yang harus ditambah.

---

## 9. Riwayat Refactor Besar

| Tanggal | Commit | Ringkasan |
|---|---|---|
| 2026-04-22 | (RJ/UGD/RI merged) | Bridging iDRG dasar untuk 3 modul — `daftar-{modul}-actions.blade.php` orchestrator + SFC per step |
| 2026-04-27 | `9081ec6` | iDRG Coder Editor (RJ+UGD+RI) — full refactor, per-step SFC, modal sibling |
| 2026-04-27 | `02ae8c0` | EMR validation toast + x-input-error (trait WithValidationToast) |
| 2026-05-14 | `7931cb9` | iDRG Stage 2 bridging (RJ+UGD+RI) merged develop — `grouperIdrgStage2` + topup_options/topup_codes |
| 2026-05-20 | (sesi ini) | Refactor 22 file: wire:model commit-on-save (race fix), valid IM badge + debug accordion, Stage 2 dropdown per kategori, Stage 1 merge Stage 2 display, edit-ulang clear grouping, import replace mode |
