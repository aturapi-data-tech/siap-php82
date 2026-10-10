# Komponen TTD di Layar — `<x-signature.ttd-petugas>`

Komponen reusable untuk **tanda tangan petugas di form entry** (bukan cetak).
**Model tampilan = gaya EMR**: dua field readonly bersanding — *Petugas* (nama) &
*Waktu/Jam TTD* — plus tombol "TTD Saya" yang men-stamp nama user login + kode +
timestamp; setelah TTD tombol jadi *Ganti / Hapus* (bila `allowClear`). Kode tetap
disimpan (`:code`) untuk stempel cetak. Sejak 2026-09-08, bila `:code` terisi dan user punya
`myuser_ttd_image`, **gambar TTD-nya ditampilkan di atas field nama** (via
sub-komponen `x-signature.ttd-gambar :code :name`) — berlaku otomatis di 64 pemakai komponen.
Blok stempel bespoke (inform consent, penolakan obat/resusitasi, pulang APS, penundaan
pelayanan, second opinion, permintaan kerohanian, surat kematian; 16 modul) memakai
`<x-signature.ttd-gambar>` yang sama di atas nama petugas.

> Diseragamkan dari pola lama "kartu general-consent" (kotak dashed → kartu) ke
> gaya EMR (field berlabel). Semua pemakaian lama otomatis ikut gaya baru.

> Ini pola **layar/entry**. Untuk TTD di **cetakan PDF** (gambar `myuser_ttd_image`,
> underline, dll.) lihat `docs/ttd-pattern-pdf-print.md`.

File: `resources/views/components/signature/ttd-petugas.blade.php`

## Kapan dipakai
Setiap dokumen EMR yang butuh "stamp nama user login + tgl" sebagai penanda-tangan
(bukan gambar tanda tangan pasien via `signature-pad`). Sudah dipakai 11 dokumen VK
+ 8 form bedah/operasi (modul-dokumen RI). **Jangan tulis blok TTD inline lagi —
pakai komponen ini.**

## Prasyarat di komponen Livewire induk
Komponen hanya UI; logika stamp ada di induk. Sediakan:

```php
public array $newForm = [ /* ... */ 'ttd' => '', 'ttdCode' => '', 'ttdDate' => '' ];
public bool $isFormLocked = false;

public function ttdSaya(): void {
    if ($this->isFormLocked) return;                    // guard server-side (wajib)
    if (!empty($this->newForm['ttd'])) return;          // cegah double-sign
    $this->newForm['ttd']     = auth()->user()->myuser_name ?? '';
    $this->newForm['ttdCode'] = auth()->user()->myuser_code ?? '';   // utk gambar TTD di cetak
    $this->newForm['ttdDate'] = \Carbon\Carbon::now(config('app.timezone'))->format('d/m/Y H:i:s');
}
public function hapusTtd(): void {
    if ($this->isFormLocked) return;                    // guard server-side (wajib)
    $this->newForm['ttd'] = $this->newForm['ttdCode'] = $this->newForm['ttdDate'] = '';
}
```

`wire:click` di komponen menempel ke Livewire induk (anonymous component render inline),
jadi method cukup ada di induk.

## Props

| Prop | Default | Guna |
|---|---|---|
| `:ttd` | `''` | nilai nama TTD (lempar `$newForm['ttd']`) |
| `:date` | `''` | tgl/jam TTD (`$newForm['ttdDate']`) |
| `:code` | `''` | kode penanda-tangan; kalau diisi tampil "Kode: xxx" + gambar TTD user (bila ada) |
| `:locked` | `false` | sembunyikan tombol saat form terkunci (`$isFormLocked`) |
| `:canSign` | `true` | `false` → tombol TTD/Hapus disembunyikan walau form tak terkunci (mis. role tak berwenang). Field readonly tetap terlihat. Pasangkan dgn cek role: `:canSign="auth()->user()?->hasAnyRole(['Perawat','Admin'])"` |
| `sign` | `ttdSaya` | nama method Livewire stamp TTD |
| `clear` | `hapusTtd` | nama method Livewire hapus TTD |
| `:allowClear` | `true` | `false` → sekali TTD tak bisa diubah (tombol Ganti/Hapus disembunyikan); mis. form serah-terima / pengkajian |
| `:framed` | `true` | `true`=dibungkus border-form (kartu bertajuk, kolom di tengah); `false`=tanpa bingkai, rata kiri (utk grid-cell) |
| `title` | `Tanda Tangan` | judul border-form (hanya saat framed) |
| `label` | `''` (kosong) | judul kecil di atas baris field; kosongkan bila sudah ada judul kolom di luar |
| `nameLabel` | `Petugas` | label field nama (mis. `Petugas Pengkaji`, `Dokter Pengkaji`) |
| `dateLabel` | `Waktu TTD` | label field waktu (mis. `Jam Pengkajian`, `Jam TTD`) |
| `signLabel` | `TTD Saya` | teks tombol stamp |
| `clearLabel` | `Ganti / Hapus TTD` | teks tombol hapus |
| `emptyText` | `Belum ditandatangani.` | hint saat terkunci & belum TTD (mis. `Menunggu TTD Pengirim.`) |

**Adopsi di EMR-inti (gaya asli komponen):**
```blade
<x-signature.ttd-petugas :framed="false" :allowClear="false"
    :ttd="$dataDaftarRi['pengkajianDokter']['tandaTanganDokter']['dokterPengkaji'] ?? ''"
    :date="$dataDaftarRi['pengkajianDokter']['tandaTanganDokter']['jamDokterPengkaji'] ?? ''"
    :code="$dataDaftarRi['pengkajianDokter']['tandaTanganDokter']['dokterPengkajiCode'] ?? ''"
    :locked="$isFormLocked || $isReadOnlyByRole"
    :canSign="auth()->user()?->hasAnyRole(['Dokter', 'Admin'])"
    sign="setDokterPengkaji" nameLabel="Dokter Pengkaji" dateLabel="Jam TTD" signLabel="TTD Saya" />
```

## Contoh

**VK (framed, default subtitle):**
```blade
<x-signature.ttd-petugas :ttd="$newForm['ttd']" :date="$newForm['ttdDate'] ?? ''"
    :locked="$isFormLocked" />
```

**Bedah single-section (framed, judul spesifik, tanpa subtitle, simpan kode):**
```blade
<x-signature.ttd-petugas :ttd="$newForm['operatorTtd']" :date="$newForm['operatorTtdDate'] ?? ''"
    :code="$newForm['operatorTtdCode'] ?? ''" :locked="$isFormLocked"
    sign="setOperatorTtd" clear="clearOperatorTtd"
    title="Tanda Tangan Operator" label="" signLabel="TTD sebagai Operator" clearLabel="Hapus TTD" />
```

**Unframed dalam grid dua-kolom (mis. pra-anestesi kolom dokter):**
```blade
<x-signature.ttd-petugas :framed="false" :ttd="$newForm['ttd']" :date="$newForm['ttdDate'] ?? ''"
    :code="$newForm['ttdCode'] ?? ''" :locked="$isFormLocked" sign="setTtd" clear="clearTtd"
    label="Dokter Anestesi" signLabel="TTD Dokter Anestesi" clearLabel="Hapus TTD" />
```

## Standar tata letak kolom TTD (2026-09-15)
Pasien/wali, saksi, dan petugas berdampingan dalam satu grid — kotak gambar TTD harus
**sejajar di satu baris**. Urutan tiap kolom:

```
PASIEN / WALI                         PETUGAS PEMBERI PENJELASAN      ← judul kolom (uppercase, tengah)
┌──────────────────────────┐          ┌──────────────────────────┐
│  signature-result / pad  │          │  ttd-gambar (ttd-petugas) │   ← kotak LANGSUNG di bawah judul
└──────────────────────────┘          └──────────────────────────┘
Nama Pasien / Wali [.......]          Petugas [.......]                ← field nama
Hubungan [.......]                    Kode: 123X123
Waktu TTD: 15/09/2026 08:16:38        Waktu TTD: 15/09/2026 08:16:39   ← teks, bukan input
```

```blade
<div class="grid grid-cols-1 gap-6 md:grid-cols-2">
    <div class="flex flex-col">
        <div class="mb-2 text-sm font-semibold tracking-wide text-center uppercase text-muted dark:text-gray-400">Pasien / Wali</div>
        @if (!empty($signature))
            <x-signature.signature-result :signature="$signature" :disabled="$isFormLocked" wireMethod="clearSignature" />
        @elseif (!$isFormLocked)
            <x-signature.signature-pad wireMethod="setSignature" />
        @endif
        {{-- field nama / hubungan --}}
        @if (!empty($signature))
            <p class="mt-2 text-sm"><span class="text-muted">Waktu TTD:</span>
                <span class="font-semibold text-ink dark:text-gray-200">{{ $signatureDate }}</span></p>
        @endif
    </div>
    <div class="flex flex-col">
        <div class="mb-2 ...">Petugas Pemberi Penjelasan</div>
        <x-signature.ttd-petugas :framed="false" ... />
    </div>
</div>
```

- **Jangan ada teks di atas kotak.** `signature-result` kini menaruh prop `date` DI BAWAH kotak;
  lebih rapi lagi taruh baris "Waktu TTD" setelah field nama/hubungan (mirror kolom petugas).
- **Jangan bungkus kotak dengan `min-h-* flex justify-center`** — kotak jadi di-tengah-vertikal
  dan turun-naik beda antar kolom (bug General Consent sebelum 2026-09-15).
- Kotak `signature-result` & `ttd-gambar` identik: `w-full` + `max-h-40` + `aspect-ratio: 460 / 180`.
- **Jangan** jadikan kolom kartu berbingkai (`p-3 border rounded-lg`) dan **jangan** pakai
  `ttd-petugas :framed="true"` di dalam grid TTD — semua kolom tanpa bingkai.
- Judul kolom boleh ditulis di luar komponen **atau** lewat prop `label` ttd-petugas — keduanya
  bergaya sama (uppercase, rata tengah). Jangan pakai keduanya sekaligus.
- `ttd-petugas` mengirim `teksKosong` ke `ttd-gambar`: petugas yang tak punya berkas gambar TTD
  tetap mendapat kotak berukuran sama berisi "Gambar tanda tangan tidak tersedia", jadi field
  di bawahnya tidak naik. `ttd-gambar` tanpa `teksKosong` (modal lab) tetap tak render apa pun.

## Struktur internal
**Satu file** `ttd-petugas.blade.php` (tak ada sub-komponen). Bingkai saat `framed=true`
sengaja pakai `<div>` biasa (bukan `<x-border-form>`), dibuka/tutup per cabang `@if`,
sehingga isi ditulis **sekali** di tengah. Kelas bingkai menyalin gaya `<x-border-form>`
(`border-hairline rounded-2xl` + judul `ds-caption-up`).

## Gotcha
- **JANGAN pakai `<x-border-form>` yang tag-nya dibelah antar `@if`** (`@if($x)<x-border-form>@endif
  ...isi... @if($x)</x-border-form>@endif`). Saat cabang skip, **seluruh isi hilang** (output kosong)
  karena buffer `startComponent`/`renderComponent` tak seimbang. Itulah kenapa bingkai di sini
  memakai `<div>` biasa (boleh dibelah antar `@if`), bukan tag komponen.
- **JANGAN tulis `<x-...>` di komentar** file komponen (`@props`/`@php`). Blade tetap
  mengkompilasinya jadi tag → runtime `Undefined variable $component` (padahal `php -l` &
  `view:cache` lolos). Sebut tanpa angle-x.
- **Verifikasi via render, bukan HTTP status.** `curl` bisa dapat 302 (redirect login)
  sebelum komponen ter-render → bug render tak muncul. Uji tiap kombinasi prop:
  `php artisan tinker --execute="echo strlen(Blade::render('<x-signature.ttd-petugas :framed=\"false\" ... />'));"`
  (LEN=0 = bug). Lihat skill `blade-safe-edit`.
- Untuk render gambar TTD di cetakan, simpan `ttdCode` (myuser_code) dan resolve
  `myuser_ttd_image` di method `cetak()`; lihat `docs/ttd-pattern-pdf-print.md`.
