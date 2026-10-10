# TTD Pattern di PDF Print (DomPDF)

Pola standar render area Tanda Tangan (TTD) di semua `*-print.blade.php` — baik TTD digital (image dari `myuser_ttd_image`) maupun TTD manual (kolom kosong untuk tanda tangan basah).

Sebelumnya tiap file pakai pola berbeda: `display:flex` (UGD/RJ inform consent), `mx-auto object-contain` (eresep + lab + radiologi), `<br><br><br>` placeholder (cetak-rekam-medis EMR), `(.....................)` dots (rj-v1 awal). Pola-pola ini punya bug DomPDF-spesifik yang bikin TTD tidak ter-render dengan benar atau posisi tidak konsisten antar dokumen. Pola di doc ini sudah dipakai konsisten di **24+ file** dan sudah dites visual.

---

## 1. Anti-pattern (jangan dipakai)

### 1.1 `display:flex` untuk centering

```blade
{{-- ❌ JANGAN --}}
<div style="height:60px; display:flex; align-items:center; justify-content:center;">
    <img src="..." style="max-height:55px; max-width:160px; object-fit:contain;" alt="..." />
</div>
```

**Kenapa salah:** DomPDF **tidak mendukung** `display:flex`, `align-items`, `justify-content`, `object-fit`. Semua properti itu di-ignore. Akibatnya img render dengan natural size dan posisi default (inline-baseline) → numpuk dengan teks lain di sekitarnya.

### 1.2 `mx-auto` di `<img>`

```blade
{{-- ❌ JANGAN --}}
<img class="h-20 max-w-[200px] mx-auto object-contain" src="..." alt="">
```

**Kenapa salah:** `mx-auto` (margin-left:auto + margin-right:auto) cuma men-center elemen **block-level** dengan width tertentu. `<img>` default `inline` → margin-auto tidak punya efek. Img tetap kiri.

### 1.3 `<br><br><br>` sebagai placeholder kosong

```blade
{{-- ❌ JANGAN --}}
@if (!empty($ttd))
    <img class="h-16" src="..." alt="">
@else
    <br><br><br>
@endif
```

**Kenapa salah:** Tinggi `<br>` × 3 = ~36-42px tergantung font-size & line-height konteks. Tidak konsisten dengan tinggi img (64px). Cell kanan dengan img + cell kiri dengan br tidak akan punya tinggi sama → label di bawah tidak sejajar.

### 1.4 Empty div dengan height saja

```blade
{{-- ❌ JANGAN --}}
<div class="h-16"></div>
<div style="height:64px;"></div>
```

**Kenapa salah:** DomPDF sering meng-collapse block element yang **kosong total** ke 0 height, meskipun ada `height:` CSS. Bug ini juga muncul untuk Tailwind class `h-16`. Akibatnya: 64px space hilang → konten lain ketarik ke atas.

### 1.5 `(.....................)` dots

```blade
{{-- ❌ JANGAN --}}
<span class="border-t border-black">
    (.....................)
</span>
```

**Kenapa salah:** Dots adalah artefak typewriter, redundant ketika sudah ada underline (`border-t`). Mengganggu untuk TTD manual basah — area harus blank.

---

## 2. Pola standar — TTD digital (img signature)

```blade
<div class="text-center my-1">
    @if (!empty($ttd))
        <img class="h-16" src="@ttdSrc($ttd)" alt="TTD Dokter">
    @else
        <div class="h-16">&nbsp;</div>
    @endif
</div>
```

### Kunci-kuncinya

- **`text-align:center` di parent block** (`text-center`) — img inline akan ter-center sebagai inline content. Reliable di DomPDF.
- **`h-16` (= 64px native Tailwind)** — sudah ter-compile di build, exact 64px. Jangan pakai `h-[64px]` (bracket) — JIT cuma compile kalau class pernah dilihat di source pre-build.
- **`<div class="h-16">&nbsp;</div>` fallback** — `&nbsp;` (non-breaking space) memaksa div jadi "non-empty" sehingga DomPDF respect height:64px-nya.
- **Tidak ada `max-width`, `object-contain`, `mx-auto`** — tidak diperlukan kalau parent sudah text-center dan img dibatasi via `h-16`.

### Label dokter di bawah (dengan underline)

```blade
<div class="text-center">
    <span class="inline-block min-w-[150px] border-t border-black pt-0.5 font-bold">
        {{ $drPemeriksa ?: 'Dokter Pemeriksa' }}
    </span>
</div>
```

- **`inline-block`** wajib supaya `min-width` & `border-top` ter-apply (span default inline tidak respect width).
- **`border-t border-black`** untuk underline (garis tepat di atas teks).
- **`pt-0.5`** untuk jarak garis ke teks.
- **`min-w-[150px]`** supaya garis tidak terlalu pendek kalau nama dokter pendek (mis. "dr. A").

> **Catatan rebuild:** `min-w-[150px]` dan `pt-[3px]` adalah bracket class — kalau baru ditambah di file dan belum pernah ada di source lain, perlu `npm run build` agar masuk ke `public/build`. Tanpa rebuild, garis underline akan auto-fit ke lebar teks (tidak fatal, cuma visual).

---

## 3. Pola 3-stack — TTD dengan tanggal di atas

Untuk EMR / Resume / Form yang punya 3 baris: **tanggal → TTD area → underline + nama**. Pakai 3 div bertumpuk sehingga DomPDF render vertical stack yang reliable:

```blade
<td class="border border-black px-1.5 py-2 align-top text-center">
    {{-- Line 1: tanggal --}}
    <div class="text-center mb-0.5">
        Tulungagung, {{ $tglRj ?: '-' }}
    </div>

    {{-- Line 2: TTD image / fallback --}}
    <div class="text-center">
        @if (!empty($ttdDokter))
            <img class="h-16" src="@ttdSrc($ttdDokter)" alt="">
        @else
            <div class="h-16">&nbsp;</div>
        @endif
    </div>

    {{-- Line 3: underline + nama dokter --}}
    <div class="text-center">
        <span class="inline-block min-w-[150px] border-t border-black pt-0.5 font-bold">
            {{ $drPemeriksa ?: 'Dokter Pemeriksa' }}
        </span>
    </div>
</td>
```

### Kenapa 3 div terpisah?

DomPDF kadang menggabung elemen inline ke satu line walaupun sudah `inline-block`. Misal img + span border-top akan numpuk side-by-side di line yang sama, bukan vertical stack. Solusi: **bungkus tiap segmen dalam `<div>` block sendiri** — div block otomatis stack vertikal.

### Cell pasien kosong harus mirror struktur dokter

Kalau ada 2 kolom TTD (pasien + dokter) dan pasien tidak punya TTD digital, **kedua cell harus punya 3 line yang sama** supaya underline bottom sejajar. Pakai `&nbsp;` placeholder untuk line yang kosong:

```blade
<td class="border border-black px-1.5 py-2 align-top text-center">
    {{-- Line 1: placeholder (mirror tanggal di dokter) --}}
    <div class="text-center mb-0.5">&nbsp;</div>

    {{-- Line 2: TTD area kosong (untuk tanda tangan basah) --}}
    <div class="text-center">
        <div class="h-16">&nbsp;</div>
    </div>

    {{-- Line 3: underline + label --}}
    <div class="text-center">
        <span class="inline-block min-w-[150px] border-t border-black pt-0.5">
            Tanda tangan Pasien
        </span>
    </div>
</td>
```

Total tinggi tiap cell sekarang identik (~14px + 64px + 14px = 92px), label bottom sejajar antar kolom.

---

## 4. Pola compact (BPJS SEP / SKDP / PRB)

BPJS punya template official dengan area TTD lebih kecil (30-40px) supaya muat di kertas SEP/SKDP yang kompak. **Jangan ubah ukurannya** — pertahankan original heights, cuma tambah `&nbsp;` biar tidak collapse:

```blade
<div style="text-align: center; font-size: 10px;">
    <p style="margin: 0;">Mengetahui DPJP,</p>
    <div style="height: 40px;">&nbsp;</div>
    <p style="margin: 0; font-weight: bold;">{{ strtoupper($dpjpNama) }}</p>
</div>
```

Inline style dipertahankan karena layout BPJS pakai `<x-pdf.layout-sep>` (bukan Tailwind layout) dan height-nya non-standar (30/40px) — tidak ada native Tailwind class yang exact match.

---

## 5. Pola TTD manual full-page (Form A / B / Riwayat Pengobatan)

Form yang TTD-nya **selalu basah** (tidak ada signature image di DB). Sama dengan pola 3-stack, tapi tidak ada `@if` cabang — langsung pakai placeholder:

```blade
<td class="text-center px-2 py-1 align-top">
    <p class="font-bold mb-1">Manajer Pelayanan Pasien</p>
    <p class="text-[9px] text-gray-500 mb-2">{{ $dataFormA['tanggal'] ?? '-' }}</p>

    <div class="h-16">&nbsp;</div>

    <div class="border-t border-black pt-[3px] mt-1 min-w-[140px] inline-block">
        <p class="font-bold">{{ strtoupper($ttdNama) }}</p>
        <p class="text-[9px] text-gray-500">{{ $ttdJabatan }}</p>
    </div>
</td>
```

---

## 6. Directive `@ttdSrc()`

Blade directive yang dipakai di seluruh print untuk path img TTD:

```blade
<img src="@ttdSrc($ttd)" alt="">
```

Definisi di `app/Providers/AppServiceProvider.php`:

```php
Blade::directive('ttdSrc', function ($expression) {
    return "<?php echo (function (\$v) {
        return empty(\$v)
            ? ''
            : 'storage/' . (str_contains(\$v, '/') ? \$v : 'UserTtd/' . \$v);
    })($expression); ?>";
});
```

Behavior:
- Input `$ttd` boleh berisi nama file (e.g. `signature.png`) atau path relatif (e.g. `custom/sig.png`).
- Output: relative path `storage/UserTtd/...` atau `storage/...` yang di-resolve DomPDF ke `public/storage/...`.
- Output empty string kalau input kosong (img dengan src kosong → tidak render).

Jangan inline build path manual via `public_path('storage/' . $ttd)` — pakai `@ttdSrc()` (tampilan)
atau `App\Support\TtdUser` (path absolut untuk DomPDF) supaya konsisten.

### Lookup TTD dari User — WAJIB lewat `App\Support\TtdUser`

Kolom `users.myuser_ttd_image` menyimpan DUA format: nama file saja (standar baru Kelola User,
berkas di `storage/app/public/UserTtd/`) dan legacy path relatif `UserTtd/abc.jpg`. Menyusun
path sendiri (`public_path('storage/' . $nilai)`) benar untuk legacy tetapi GAGAL untuk format
baru → `file_exists` false → TTD dokter/petugas kosong di PDF (kasus 2026-09-08: ±80 titik cetak
modul dokumen RJ/UGD/RI terdampak, sementara cetak rekam medis RJ yang pakai `@ttdSrc` aman).

```php
use App\Support\TtdUser;

// Paling ringkas: dari kode user langsung jadi path absolut (null bila tak ada/berkas hilang)
$ttdPath = TtdUser::pathBerkasDariKode($entry['ttdCode'] ?? null);

// Bila nilai kolom sudah di tangan
$pathBerkas = TtdUser::pathBerkas($user->myuser_ttd_image); // public_path(...) — untuk DomPDF & file_exists()
$url        = TtdUser::url($user->myuser_ttd_image);        // asset(...) — untuk <img> di browser
$pathWeb    = TtdUser::pathWeb($user->myuser_ttd_image);    // 'storage/UserTtd/x.png' — dipakai @ttdSrc
```

Viewer rekam medis (`DokumenViewSupportTrait::dataDokumenRi/Txn`) sudah memanggil `TtdUser::pathBerkasDariKode`.
Lookup pakai `myuser_code` (kode user dari Oracle Dev 6i) yang biasanya sama dengan `dr_id` di transaksi.

---

## 7. Komponen layout PDF & Tailwind compile

Layout PDF yang dipakai: `resources/views/components/pdf/layout-a4-with-out-background.blade.php` (atau `layout-a4`, `layout-sep` untuk BPJS).

Layout inline build CSS Tailwind:
```php
$manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
$pdfCss = $manifest['resources/css/app.css']['file'] ?? null;
// ...
{!! $pdfCss ? file_get_contents(public_path('build/' . $pdfCss)) : '' !!}
```

Jadi **semua Tailwind class yang ada di build/manifest CSS otomatis tersedia di PDF**. Konsekuensi:

- **Class native** (`h-16`, `text-center`, `inline-block`, dst.) = aman, selalu di build.
- **Bracket class baru** (`h-[55px]`, `min-w-[140px]`, dst.) = perlu `npm run build` setelah ditambah di file baru, supaya JIT compile dan masuk ke build CSS. Kalau tidak rebuild, class-nya tidak ada di CSS PDF → tidak ada effect.

Untuk TTD area, **selalu pakai `h-16` (native)** — bukan `h-[64px]`. `h-16` = exact 64px = ukuran TTD standar.

---

## 8. File yang sudah pakai pola ini

24 file `-print.blade.php` (TTD digital `h-16`):

- 2 EMR Assessment Awal (`rekam-medis/rj/cetak-rekam-medis/cetak-rekam-medis-print`, `rekam-medis/ugd/cetak-rekam-medis/cetak-rekam-medis-print`)
- 2 Penunjang (`rekam-medis/penunjang/laboratorium-display/laboratorium-display-print`, `rekam-medis/penunjang/radiologi-display/radiologi-display-print`)
- 3 Eresep (`rekam-medis/{r-j,u-g-d,r-i}/cetak-eresep/cetak-eresep-print`)
- 4 Suket Sakit/Sehat (`modul-dokumen/{r-j,u-g-d}/suket-{sakit,sehat}/cetak-suket-*-print`)
- 3 General Consent (`modul-dokumen/{r-j,u-g-d,r-i}/general-consent/cetak-general-consent-*-print`)
- 3 Inform Consent (`modul-dokumen/{r-j,u-g-d,r-i}/inform-consent/cetak-inform-consent-*-print`)
- 1 Form Penjaminan (`modul-dokumen/ugd/form-penjaminan/cetak-form-penjaminan-print`)
- 1 Form Transfer UGD-RI (`modul-dokumen/ugd/form-trf-ugd-ri/cetak-form-trf-ugd-ri-print`)
- 2 Rekam Medis RJ baru (`rekam-medis/rj/cetak-rekam-medis/cetak-rekam-medis-rj-{v1,fisio}-print`)
- 1 Riwayat Pengobatan RI (`modul-dokumen/ri/riwayat-pengobatan/cetak-riwayat-pengobatan-ri-print`)
- 2 Form A & B MPP (`livewire/cetak/cetak-form-{a,b}-print`)

3 file BPJS pakai pola compact (`height: 30/40px` + `&nbsp;`):
- `modul-dokumen/bpjs/cetak-sep/cetak-sep-print`
- `modul-dokumen/bpjs/cetak-skdp/cetak-skdp-print`
- `modul-dokumen/bpjs/cetak-prb/cetak-prb-print`

---

## 9. Checklist saat menambah print baru dengan TTD

- [ ] Pakai layout `<x-pdf.layout-a4-with-out-background>` atau `<x-pdf.layout-a4>` (otomatis inline Tailwind CSS).
- [ ] Cell yang berisi TTD pakai `text-center align-top` di `<td>` (bukan `align-bottom`).
- [ ] Img signature pakai `class="h-16"` — JANGAN `h-[64px]`, `h-20`, `max-w-[200px] mx-auto object-contain`.
- [ ] Fallback empty selalu `<div class="h-16">&nbsp;</div>` — JANGAN `<br><br><br>` atau `<div class="h-16"></div>` (tanpa konten).
- [ ] Wrapper img/fallback di-bungkus `<div class="text-center">` block sendiri (bukan langsung di parent flex/grid).
- [ ] Label nama di bawah pakai struktur `<span class="inline-block min-w-[150px] border-t border-black pt-0.5">{{ $nama }}</span>`.
- [ ] Kalau punya 2+ kolom TTD, **setiap cell punya 3 line struktur sama** (tanggal/placeholder + TTD area + underline+label) supaya bottom sejajar.
- [ ] Lookup TTD lewat `App\Support\TtdUser::pathBerkasDariKode($code)` (DomPDF) atau `@ttdSrc()` (tampilan) — JANGAN `public_path('storage/' . $nilai)` sendiri.
- [ ] Setelah edit, kalau ada bracket class baru → `npm run build`.

---

## 10. Referensi

- Direktif: `app/Providers/AppServiceProvider.php` → `Blade::directive('ttdSrc', ...)`
- Layout: `resources/views/components/pdf/layout-a4-with-out-background.blade.php`
- Contoh canonical (3-stack): `resources/views/pages/components/rekam-medis/rj/cetak-rekam-medis/cetak-rekam-medis-rj-v1-print.blade.php`
- Contoh single TTD: `resources/views/pages/components/modul-dokumen/ugd/suket-sakit/cetak-suket-sakit-ugd-print.blade.php`
- Contoh 3-kolom TTD: `resources/views/pages/components/modul-dokumen/ugd/inform-consent/cetak-inform-consent-print.blade.php`
- Contoh compact BPJS: `resources/views/pages/components/modul-dokumen/bpjs/cetak-sep/cetak-sep-print.blade.php`

## 11. Urutan kolom TTD standar (dokumen multi-tanda-tangan)

Untuk dokumen consent dengan >1 penandatangan, **urutan kolom TTD di CETAK wajib SAMA dengan urutan blok di FORM (layar)**, dan **seragam antar modul RJ/UGD/RI**.

- **Inform Consent (3 kolom):** `Pasien / Wali → Saksi → Pemberi Informasi (PPA/Dokter)`.
  Form (semua modul) sudah pakai urutan ini; cetak RJ/UGD/RI diseragamkan ke sini
  (sebelumnya cetak keliru `Pasien → PPA → Saksi`).
- **General Consent (2 kolom):** `Pasien / Wali → Petugas Pemberi Penjelasan`.

Saat menambah/mengubah kolom TTD: **cek urutan blok di FORM dulu, lalu samakan di CETAK.**
JANGAN percaya komentar `{{-- TTD 3 kolom: ... --}}` — verifikasi label `<p class="font-bold">`
tiap kolom (komentar bisa tak sinkron dgn isi; kasus nyata: komentar RJ tertulis
`Saksi | Pemberi Informasi` padahal kolomnya `Pemberi Informasi | Saksi`).
