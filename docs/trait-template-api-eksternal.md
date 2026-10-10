# Template Trait untuk API Eksternal

Konvensi & template untuk membuat trait baru di Sirus yang berinteraksi dengan API eksternal (BPJS V-Claim, Antrean, Aplicares, iCare, SIRS, iDRG/INACBG, atau API non-BPJS).

Pola ini sudah dipakai di:

| Trait | File | Karakteristik |
|-------|------|---------------|
| `VclaimTrait`     | `app/Http/Traits/BPJS/VclaimTrait.php`     | BPJS standar (HMAC + AES + LZString) |
| `AntrianTrait`    | `app/Http/Traits/BPJS/AntrianTrait.php`    | Sama dengan Vclaim, berbeda env prefix |
| `AplicaresTrait`  | `app/Http/Traits/BPJS/AplicaresTrait.php`  | Sama, tambah `Content-Type: application/json` |
| `iCareTrait`      | `app/Http/Traits/BPJS/iCareTrait.php`      | Sama dengan Aplicares |
| `SirsTrait`       | `app/Http/Traits/SIRS/SirsTrait.php`       | REST polos tanpa enkripsi (Kemenkes RS Online) |
| `iDrgTrait`       | `app/Http/Traits/IDRG/iDrgTrait.php`       | AES-256-CBC + HMAC 10-byte signature (E-Klaim INACBG) |
| `SatuSehatTrait`  | `app/Http/Traits/SATUSEHAT/SatuSehatTrait.php` | OAuth2 Bearer token (cache 3500s) + REST FHIR (SatuSehat Kemenkes) |

Ikuti pola yang sama supaya halaman monitoring `/database-monitor/log-bpjs` otomatis menampilkan log-nya.

---

## 1. Struktur kanonik trait

Tiap trait API eksternal punya **3 grup method**:

```
┌──── Response Helpers ────┐  ┌──── Auth & Crypto ────┐  ┌──── API Methods ────┐
│ sendResponse($..., $payload) │  signature()              │  method1($params)
│ sendError($..., $payload)    │  stringEncrypt($key, $s)  │  method2($params)
│ response_decrypt($r, ...)    │  stringDecrypt($key, $s)  │  ...
└──────────────────────────┘  └───────────────────────┘  └─────────────────────┘
```

### Visualisasi alur

```
Caller (controller / Livewire)
    │
    │ Trait::method1($params)
    ▼
[Trait method]
    │
    ├─► validate($params)  →  sendError($msg, $errs, 201, null, null)  → return JSON
    │
    ├─► signature() = HMAC headers
    │
    ├─► Http::post($url, $body) ───────────► EXTERNAL API
    │                                              │
    │   ◄──────── $response ───────────────────────┘
    │
    └─► response_decrypt($response, $signature, $url, $rtt)
            │
            ├─► sniff payload via $response->transferStats
            ├─► decrypt body kalau success
            │
            └─► sendResponse($msg, $data, $code, $url, $rtt, $payload)
                ↓
                INSERT web_log_status (code, response, http_req, http_payload, requestTransferTime)
                ↓
                return JSON ke caller
```

---

## 2. Template file

Buat file di `app/Http/Traits/{Namespace}/{Service}Trait.php`. Ganti `XYZ` dengan prefix env service (mis. `VCLAIM`, `ICARE`, `KEMKES`).

```php
<?php

namespace App\Http\Traits\{Namespace};

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Exception;

trait {Service}Trait
{
    // ====================================================================
    // 1. RESPONSE HELPERS
    // ====================================================================

    /**
     * Helper sukses — insert log + return JSON.
     *
     * @param string      $message              Pesan ke client
     * @param mixed       $data                 Data response
     * @param int         $code                 HTTP code (default 200)
     * @param string|null $url                  URL endpoint eksternal (untuk log)
     * @param float|null  $requestTransferTime  Durasi request (detik)
     * @param string|null $payload              Request body yg dikirim (auto dari transferStats)
     */
    public static function sendResponse($message, $data, $code = 200, $url = null, $requestTransferTime = null, $payload = null)
    {
        $response = [
            'response' => $data,
            'metadata' => [
                'message' => $message,
                'code' => $code,
            ],
        ];

        DB::table('web_log_status')->insert([
            'code'                => $code,
            'date_ref'            => Carbon::now(env('APP_TIMEZONE')),
            'response'            => json_encode($response, true),
            'http_req'            => $url,
            'http_payload'        => $payload,
            'requestTransferTime' => $requestTransferTime,
        ]);

        return response()->json($response, $code);
    }

    /**
     * Helper error — sama signature dengan sendResponse, dipakai untuk validation/HTTP error.
     */
    public static function sendError($error, $errorMessages = [], $code = 404, $url = null, $requestTransferTime = null, $payload = null)
    {
        $response = [
            'metadata' => [
                'message' => $error,
                'code' => $code,
            ],
        ];
        if (!empty($errorMessages)) {
            $response['response'] = $errorMessages;
        }

        DB::table('web_log_status')->insert([
            'code'                => $code,
            'date_ref'            => Carbon::now(env('APP_TIMEZONE')),
            'response'            => json_encode($response, true),
            'http_req'            => $url,
            'http_payload'        => $payload,
            'requestTransferTime' => $requestTransferTime,
        ]);

        return response()->json($response, $code);
    }

    // ====================================================================
    // 2. AUTH & CRYPTO (sesuaikan dengan spec API masing-masing)
    // ====================================================================

    public static function signature()
    {
        $cons_id   = env('XYZ_CONS_ID');
        $secretKey = env('XYZ_SECRET_KEY');
        $userkey   = env('XYZ_USER_KEY');

        date_default_timezone_set('UTC');
        $tStamp = strval(time() - strtotime('1970-01-01 00:00:00'));
        $signature = hash_hmac('sha256', $cons_id . '&' . $tStamp, $secretKey, true);
        $encodedSignature = base64_encode($signature);

        return [
            'user_key'      => $userkey,
            'x-cons-id'     => $cons_id,
            'x-timestamp'   => $tStamp,
            'x-signature'   => $encodedSignature,
            'decrypt_key'   => $cons_id . $secretKey . $tStamp,
            'Content-Type'  => 'application/json',
        ];
    }

    /** Decrypt response BPJS (AES-256-CBC + LZString). */
    public static function stringDecrypt($key, $string)
    {
        $encrypt_method = 'AES-256-CBC';
        $key_hash = hex2bin(hash('sha256', $key));
        $iv = substr(hex2bin(hash('sha256', $key)), 0, 16);
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
        $output = \LZCompressor\LZString::decompressFromEncodedURIComponent($output);
        return $output;
    }

    // ====================================================================
    // 3. RESPONSE HANDLER (universal)
    // ====================================================================

    public static function response_decrypt($response, $signature, $url, $requestTransferTime)
    {
        // ★ KEY POINT: sniff request body dari Guzzle.
        // Laravel HTTP client otomatis set $response->transferStats via Guzzle on_stats callback.
        // null-safe untuk catch block tanpa $response (payload null saat HTTP gagal sebelum kirim).
        $payload = $response->transferStats?->getRequest()?->getBody()?->__toString();

        if ($response->failed()) {
            return self::sendError(
                $response->reason(),
                $response->json('response'),
                $response->status(),
                $url,
                $requestTransferTime,
                $payload
            );
        }

        // metaData (D besar) vs metadata (d kecil) — cek spec API masing-masing
        $code = $response->json('metaData.code');

        if ($code == 200) {
            $decrypt = self::stringDecrypt($signature['decrypt_key'], $response->json('response'));
            $data = json_decode($decrypt, true);
        } else {
            $data = json_decode($response, true);
        }

        return self::sendResponse(
            $response->json('metaData.message'),
            $data,
            $code,
            $url,
            $requestTransferTime,
            $payload
        );
    }

    // ====================================================================
    // 4. API METHODS (per endpoint)
    // ====================================================================

    public static function get_something($param1, $param2)
    {
        // 1. Validation rules
        $r = [
            'param1' => $param1,
            'param2' => $param2,
        ];
        $rules = [
            'param1' => 'required|string',
            'param2' => 'required|numeric',
        ];
        $validator = Validator::make($r, $rules);
        if ($validator->fails()) {
            // tanpa $response → payload null
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }

        // 2. HTTP call
        try {
            $url = env('XYZ_URL') . 'endpoint/path';
            $signature = self::signature();

            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->post($url, [
                    'param1' => $param1,
                    'param2' => $param2,
                ]);

            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), null, 408, $url ?? null, null);
        }
    }
}
```

---

## 3. Tabel `web_log_status` (referensi)

DDL Oracle (sudah dieksekusi):

```sql
CREATE TABLE WEB_LOG_STATUS (
    id                   NUMBER GENERATED BY DEFAULT AS IDENTITY,
    code                 NUMBER,
    date_ref             TIMESTAMP,
    http_req             VARCHAR2(4000),  -- URL endpoint
    response             CLOB,             -- response body (sudah di-decrypt kalau encrypted)
    requesttransfertime  NUMBER,           -- durasi (detik) — case sensitive di Oracle
    http_payload         CLOB              -- request body yg dikirim (ADDED 2026-05-20)
);
```

**Catatan akses Laravel:**
- Kolom `requesttransfertime` di-fold uppercase oleh Oracle (DDL tanpa quote) — akses **tanpa double-quote** di Laravel:
  ```php
  DB::raw('requesttransfertime as request_transfer_time')
  ```
- Insert pakai key lowercase: `'http_payload' => $payload, 'requestTransferTime' => $rtt`.

Lihat memo `feedback_oracle_mixed_case_column.md` di personal memory untuk konteks selengkapnya.

---

## 4. Checklist saat membuat trait baru

- [ ] **Namespace** `App\Http\Traits\{Namespace}` (mis. `BPJS`, `SIRS`, `Kemenkes`, `Pcare`).
- [ ] **3 method standar**: `sendResponse`, `sendError`, `response_decrypt` — semua punya parameter `$payload = null` di akhir.
- [ ] **Helper auth** — `signature()` atau sesuai spec.
- [ ] **Helper decrypt** — kalau API enkripsi (BPJS pakai AES-256-CBC + LZString; iDRG pakai AES-256-CBC + HMAC signature 10-byte).
- [ ] **Per-method**: 5 langkah → validate → try → `Http::post` → `response_decrypt` → catch.
- [ ] **Sniff payload** di `response_decrypt` via `$response->transferStats?->getRequest()?->getBody()?->__toString()`.
- [ ] **Insert ke `web_log_status`** WAJIB termasuk `http_payload` (kolom CLOB).
- [ ] **ENV** — semua secret/URL via `env('PREFIX_*')`, tidak hardcode.
- [ ] **Carbon timezone** — pakai `Carbon::now(env('APP_TIMEZONE'))` untuk timestamp lokal.

---

## 5. Anti-pattern

❌ **JANGAN** hardcode URL/secret:
```php
$response = Http::post('https://apijkn.bpjs-kesehatan.go.id/...', ...);  // BAD
```

❌ **JANGAN** lewatkan logging:
```php
$response = Http::post($url, ...);
return $response->json();  // BAD — gak ada log, gak ada audit trail
```

❌ **JANGAN** sniff payload manual & teruskan dari semua caller (rentang ratusan caller):
```php
// BAD — ribet, semua caller harus diubah
$payload = json_encode($body);
return self::response_decrypt($response, $sig, $url, $rtt, $payload);
```

✅ **DO** sniff di `response_decrypt` saja (caller tidak perlu ubah):
```php
public static function response_decrypt($response, $signature, $url, $requestTransferTime)
{
    $payload = $response->transferStats?->getRequest()?->getBody()?->__toString();
    // ...
}
```

❌ **JANGAN** silently swallow exception tanpa log:
```php
try { ... } catch (Exception $e) { return null; }  // BAD
```

✅ **DO** kembalikan via `sendError`:
```php
catch (Exception $e) {
    return self::sendError($e->getMessage(), null, 408, $url ?? null, null);
}
```

❌ **JANGAN** quote kolom yg sebenarnya uppercase di Oracle:
```php
DB::raw('"requesttransfertime" as ...')  // BAD — ORA-00904 invalid identifier
```

✅ **DO** unquoted (Oracle fold ke uppercase otomatis):
```php
DB::raw('requesttransfertime as request_transfer_time')
```

---

## 6. Kasus khusus: API enkripsi non-BPJS (iDRG)

iDRG/INACBG pakai **AES-256-CBC** + **HMAC 10-byte signature** di awal payload (bukan LZString). Tetap ikuti pola yang sama, tapi:

- `signature()` tidak perlu — pakai `IDRG_KEY` (hex 64 char) langsung.
- `stringEncrypt` / `stringDecrypt` diganti `inacbgEncrypt` / `inacbgDecrypt`.
- `response_decrypt` punya parameter ekstra `$key` + `$debug`.
- **Auto-decrypt request body** di `response_decrypt` supaya log baca plaintext JSON, bukan ciphertext:

```php
$wireBody = $response->transferStats?->getRequest()?->getBody()?->__toString();
$payload = $wireBody;
if (!$debug && is_string($wireBody) && $wireBody !== '') {
    try {
        $decrypted = self::inacbgDecrypt($wireBody, $key);
        if ($decrypted !== 'SIGNATURE_NOT_MATCH' && $decrypted !== false) {
            $payload = $decrypted;
        }
    } catch (Exception $e) {
        // biarkan $payload = ciphertext kalau decrypt gagal
    }
}
```

Lihat `app/Http/Traits/IDRG/iDrgTrait.php` untuk implementasi lengkap.

---

## 7. Kasus khusus: API tanpa enkripsi (REST JSON polos)

Contoh **SIRS Online Kemenkes** — tanpa HMAC, tanpa enkripsi response. Struktur trait-nya lebih simple:

```php
private static function sirsResponse($response, string $url): \Illuminate\Http\JsonResponse
{
    $status = $response->status();
    $body = $response->json() ?? $response->body();
    $payload = $response->transferStats?->getRequest()?->getBody()?->__toString();

    DB::table('web_log_status')->insert([
        'code'                => $status,
        'date_ref'            => Carbon::now(env('APP_TIMEZONE')),
        'response'            => json_encode($body),
        'http_req'            => $url,
        'http_payload'        => $payload,
        'requestTransferTime' => $response->transferStats?->getTransferTime(),
    ]);

    return response()->json($body, $status);
}

private static function sirsError(string $message, int $code, string $url = null): \Illuminate\Http\JsonResponse
{
    // payload null karena tidak ada $response saat error pre-flight
    DB::table('web_log_status')->insert([
        'code'                => $code,
        'date_ref'            => Carbon::now(env('APP_TIMEZONE')),
        'response'            => json_encode(['message' => $message, 'code' => $code]),
        'http_req'            => $url,
        'http_payload'        => null,
        'requestTransferTime' => null,
    ]);

    return response()->json(['message' => $message, 'code' => $code], $code);
}
```

Lihat `app/Http/Traits/SIRS/SirsTrait.php` untuk implementasi lengkap.

---

## 7b. Kasus khusus: OAuth2 Bearer token (SatuSehat)

SatuSehat Kemenkes pakai **OAuth2 client_credentials** — bukan HMAC. Token di-cache 3500 detik. Trait-nya pakai pattern berbeda: 1 method universal `makeRequest($method, $endpoint, $data)` yang dipakai semua sub-trait FHIR (`PatientTrait`, `MedicationRequestTrait`, dll).

Pola logging di `makeRequest()`:

```php
protected function makeRequest($method, $endpoint, $data = [])
{
    $token = $this->getAccessToken();
    $url = $this->baseUrl . $endpoint;

    $client = Http::timeout(10)
        ->withToken($token)
        ->withHeaders(['Organization-Id' => $this->organizationId]);

    // Bungkus try/catch — koneksi gagal pun tetap ke-log
    try {
        if (strtolower($method) === 'get') {
            $response = $client->get($url);
        } else {
            $response = $client
                ->withHeaders(['Content-Type' => 'application/json'])
                ->{$method}($url, $data);
        }
    } catch (\Exception $e) {
        // Network/timeout — payload fallback ke $data yang akan dikirim
        $this->logSatuSehat($url, 0, null, $e->getMessage(),
            strtolower($method) === 'get' ? null : json_encode($data));
        throw $e;
    }

    // Sniff + log sukses maupun error 4xx/5xx
    $payload = $response->transferStats?->getRequest()?->getBody()?->__toString();
    $this->logSatuSehat($url, $response->status(),
        $response->transferStats?->getTransferTime(),
        $response->body(), $payload);

    if ($response->successful()) {
        return $response->json();
    }
    throw new \Exception('API request failed: ' . $response->body());
}

private function logSatuSehat(string $url, ?int $code, ?float $rtt, ?string $responseBody, ?string $payload): void
{
    DB::table('web_log_status')->insert([
        'code'                => $code,
        'date_ref'            => Carbon::now(env('APP_TIMEZONE')),
        'response'            => $responseBody,
        'http_req'            => $url,
        'http_payload'        => $payload,
        'requestTransferTime' => $rtt,
    ]);
}
```

Karakteristik:
- Method universal `makeRequest()` → semua sub-trait FHIR (`PatientTrait`, dll) otomatis dapat logging tanpa diubah.
- Token request (`getAccessToken`) **tidak di-log** karena di-cache 3500 detik → jarang sekali fire.
- Tidak ada `sendResponse`/`sendError` — return raw `$response->json()` atau throw Exception.

Lihat `app/Http/Traits/SATUSEHAT/SatuSehatTrait.php` untuk implementasi lengkap.

---

## 8. Halaman monitoring `/database-monitor/log-bpjs`

Setelah trait baru dipakai, log otomatis muncul di halaman monitoring. Filter berdasar pattern URL via fungsi `detectService()` di `resources/views/pages/database-monitor/log-bpjs/⚡log-bpjs.blade.php`:

```php
public static function detectService(?string $url): string
{
    if (empty($url)) return 'other';
    $u = strtolower($url);
    return match (true) {
        str_contains($u, 'vclaim-rest')   => 'vclaim',
        str_contains($u, 'antreanrs')     => 'antrian',
        str_contains($u, 'aplicares')     => 'aplicares',
        str_contains($u, 'icare')         => 'icare',
        str_contains($u, 'sirsonline')    => 'sirs',
        str_contains($u, 'inacbg')        => 'idrg',
        str_contains($u, 'satusehat')     => 'satusehat',
        // ↓ tambah pattern service baru di sini
        // str_contains($u, 'pcare')      => 'pcare',
        default => 'other',
    };
}
```

Juga tambah label & styling badge di `$serviceOptions`, `$svcShort`, `$svcStyle`.

---

## 9. Referensi

| Trait | Karakteristik | Pelajari kalau bikin... |
|-------|---------------|-----------------------|
| `VclaimTrait`     | Standard BPJS (HMAC + AES + LZString)             | API BPJS sejenis (V-Claim, Antrean, Aplicares, iCare) |
| `iDrgTrait`       | AES + HMAC 10-byte + auto-decrypt log              | API Kemenkes ber-enkripsi (INACBG, IHS) |
| `SirsTrait`       | REST polos tanpa enkripsi                         | API REST simple (RS Online, Kemenkes lain) |
| `SatuSehatTrait`  | OAuth2 + makeRequest() universal                   | API OAuth2 / REST FHIR / banyak sub-trait |
| `AntrianTrait`    | Sama dengan Vclaim, contoh Cache::lock di caller  | API yg butuh idempotency / race-condition guard |

Lihat juga `docs/idrg-bridging.md` untuk konteks bridging E-Klaim yang lebih dalam (multi-step orchestrator + per-step SFC).
