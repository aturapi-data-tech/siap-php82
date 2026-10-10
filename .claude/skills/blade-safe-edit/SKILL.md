---
name: blade-safe-edit
description: Aturan keselamatan saat mengedit file Blade / Volt di repo ini. Baca sebelum melakukan edit bulk atau pakai sed/perl/regex pada *.blade.php — mencegah match melebar yang merusak banyak file. Juga mencakup jebakan compiler Volt.
---

# Blade Safe Edit

File Blade di repo ini besar dan banyak nested tag. Edit ceroboh gampang merusak banyak file sekaligus.

## 1. JANGAN regex multiline untuk Blade
`perl -0` / `sed` multiline rawan match melebar dan merusak banyak file diam-diam.

- Pakai tool **Edit** dengan `old_string` presisi (sertakan konteks unik).
- Untuk perubahan berulang yang identik, pakai `replace_all: true` pada Edit — bukan sed.

## 2. Verifikasi sesudah edit — `php -l` TIDAK cukup
`php -l` lolos walau struktur tag Blade kacau. Selalu cek:

```bash
git diff --stat                 # pastikan jumlah file/baris berubah masuk akal
# hitung balance tag yang diedit, mis. modal/div pembuka vs penutup
grep -c '@if' file.blade.php; grep -c '@endif' file.blade.php
grep -c '<x-modal' file.blade.php; grep -c '</x-modal' file.blade.php
```
Diff-stat yang membengkak = tanda match melebar → batalkan.

## 2b. Komentar Blade WAJIB seimbang — teks bocor ke layar

Mengedit ISI komentar `{{-- --}}` yang panjang gampang menyisipkan `--}}` di tengah.
Sisa komentarnya lalu jadi **teks biasa yang tercetak ke layar**, lengkap dengan `--}}`.
`php -l` lolos (hasil kompilasinya PHP yang sah), dan uji render yang cuma memeriksa
"apakah teks X ada" juga lolos — yang bocor justru teks yang tidak dicari siapa pun.

Kejadian nyata 2026-08-02: komentar lebar kolom di `master-dokter-penggajian-actions`
diganti isinya, `--}}` ikut tertulis di akhir teks baru, sementara paragraf lama masih
menyusul sesudahnya. Panel "Gaji Pokok & Skema" menampilkan kalimat tentang
`x-text-input-number` ke pengguna.

Cek setelah menyentuh komentar mana pun:

```bash
# jumlahnya harus sama persis
grep -c '{{--' file.blade.php; grep -c -- '--}}' file.blade.php
```

Lebih tuntas — pastikan hasil render tidak memuat penanda komentar sama sekali:

```php
$html = Livewire::test('...')->call('open', $id)->html();
assert(!str_contains($html, '--}}') && !str_contains($html, '{{--'));
```

Saat mengganti isi komentar panjang, sertakan `--}}` penutup lama di dalam
`old_string` supaya tidak mungkin tertinggal dua penutup.

## 3. Volt: hindari kata "use" di komentar PHP
Compiler Volt salah-strip komentar `//` bila ada substring `re-use` / `reuse` — sisanya terbaca sebagai statement `use` → **ParseError**.

```php
// SALAH di blok <?php Volt:  // re-use komponen ini
// BENAR: tulis ulang tanpa "use", mis. "pakai ulang komponen ini"
```

## 3b. JANGAN menambah `@php use App\...; @endphp` di berkas blade

Merapikan FQCN jadi impor terlihat sepele, tapi gagal di DUA tempat berbeda
(kejadian nyata 2026-08-03, RM cetak & display nyeri):

| Jenis berkas | Yang terjadi |
|---|---|
| Berkas cetak PDF (isinya di dalam `<x-pdf.layout-a4...>`) | impor ditaruh di atas tag komponen → **tag PEMBUKA komponen tidak ikut terkompilasi**, `@endif` bawaan komponen jadi yatim → `ParseError: unexpected token "endif", expecting end of file` |
| Komponen file-tunggal (`<?php ... ?>` + template) | berkas kompilasi di `storage/framework/views/livewire/views/` **tetap memuat blok `use` milik bagian kelas** → `Cannot use App\Support\X as X because the name is already in use` |

Aturannya:

- **Komponen file-tunggal:** bagian template SUDAH otomatis mengenal nama pendek dari
  `use` di blok kelas. Tulis `NyeriOptions::...` langsung — jangan impor ulang.
- **Berkas cetak / blade biasa:** pakai nama kelas lengkap (`\App\Support\NyeriOptions::`).
  Blok `@php` di dalam tag komponen bukan scope berkas, jadi `use` di situ pun ditolak.

## 4. Pola UI sudah terdokumentasi — jangan reinvent
Sebelum bikin komponen, cek `docs/` (lihat skill `ui-pattern-docs`): tombol standar, now-button, print PDF/TTD, page-frame, dirty-modal, stable-lookup, tinymce. Ikuti pola yang ada agar konsisten.

## 5. Keseimbangan `<div>` harus dihitung PER CABANG, bukan per berkas

`grep -c '<div'` vs `grep -c '</div>'` bisa **0/0 tapi tetap rusak**: tag pembuka ada di
dalam `@if`, penutupnya di luar. Cabang yang tidak dirender membuat `</div>` kelebihan.

```blade
{{-- SALAH — saat $isFormLocked true, </div> terakhir jadi kelebihan --}}
@if (!$isFormLocked)
    <div class="grid ...">
        <div>form entri</div>
@endif
    <div>tabel data</div>
    </div>

{{-- BENAR — pembungkus di luar @if, isinya saja yang dikondisikan --}}
<div class="grid ...">
    @if (!$isFormLocked)
        <div>form entri</div>
    @endif
    <div>tabel data</div>
</div>
```

**Gejala khasnya:** "tab/blok pertama normal, yang lain turun jauh ke bawah". `</div>`
liar menutup container induk lebih awal sehingga blok-blok berikutnya terlempar KELUAR
container; container yang menyisakan panel tersembunyi tetap memakan `min-h-*` dan
tampak sebagai pita kosong. Terjadi 2026-08-03 di `visit-ri.blade.php` (Administrasi RI):
1 dari 14 panel tab yang masih di tempat, pita kosong ±310px di semua tab selain Visit.

Cek sebelum lapor selesai — simulasikan cabangnya, lalu sapu SEMUA berkas yang memakai
flag yang sama sekaligus (`$isFormLocked` dipakai ~221 berkas):

```bash
# buang isi @if (!$flag) untuk mensimulasikan $flag = true, lalu hitung <div> vs </div>
# (bandingkan hasilnya dengan cabang normal — dua-duanya harus 0)
```

Perkecualian sah: `components/signature/ttd-petugas.blade.php` sengaja membelah wrapper
dengan `@if ($framed)` berpasangan di dua ujung — alasannya ditulis di berkasnya.
Diagnosa lanjutannya (render + parse DOM) ada di §2 dan skill `ui-pattern-docs`.

## Escape ganda pada prop komponen (tampil `&amp;` di layar)

Prop yang di-echo ulang komponen dengan `{{ }}` (`value` pada `x-input-label`, `title` pada
`x-border-form`, `nameLabel`/`signLabel`/`emptyText` pada `x-signature.ttd-petugas`, dll.)
akan ter-escape DUA kali:

```blade
{{-- SALAH — layar menampilkan "Mata &amp; Telinga" --}}
<x-input-label value="Mata &amp; Telinga" />
<x-input-label value="{{ $organ['label'] }}" />   {{-- label ber-& juga kena --}}

{{-- BENAR --}}
<x-input-label value="Mata & Telinga" />
<x-input-label :value="$organ['label']" />
```

`&amp;` tetap benar untuk teks HTML biasa (paragraf, isi tombol). Yang dilarang hanya di
ATRIBUT prop komponen. Cek cepat sebelum lapor:

```bash
grep -n '(value|title|nameLabel|signLabel)="[^"]*&amp;' file.blade.php   # harus kosong
grep -c '&amp;amp;' file.blade.php                                        # harus 0
```
