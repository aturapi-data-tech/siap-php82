---
name: livewire-render-versioning
description: Aturan wire:key berversi (WithRenderVersioningTrait / renderKey) saat ada komponen Livewire BERSARANG di dalamnya. Baca sebelum menambah renderKey/incrementVersion pada pembungkus, sebelum menyisipkan <livewire:...> di dalam modal berversi, atau saat console browser melempar "Snapshot missing on Livewire component with id" / "Cannot read properties of null (reading 'memo')".
---

# Render Versioning × Komponen Bersarang (Livewire 4)

## Aturan tunggal

> Kalau sebuah `<livewire:...>` berada **di dalam** elemen yang `wire:key`-nya memakai
> `$this->renderKey(...)`, maka `wire:key` komponen anak itu **WAJIB ikut versi yang sama**.

```blade
{{-- ❌ SALAH — key anak statis di dalam pembungkus berversi --}}
<div wire:key="{{ $this->renderKey('modal-screening-rj', [$rjNo ?? 'new']) }}">
    <livewire:pages::transaksi.rj.display-pasien-rj.display-pasien-rj :rjNo="$rjNo"
        wire:key="display-pasien-rj-screening-{{ $rjNo }}" />
</div>

{{-- ✅ BENAR — key anak diturunkan dari renderKey area yang sama --}}
<div wire:key="{{ $this->renderKey('modal-screening-rj', [$rjNo ?? 'new']) }}">
    <livewire:pages::transaksi.rj.display-pasien-rj.display-pasien-rj :rjNo="$rjNo"
        wire:key="{{ $this->renderKey('modal-screening-rj', ['display-pasien', $rjNo ?? 'new']) }}" />
</div>
```

Alternatif yang juga sah: **keluarkan** komponen anak dari pembungkus berversi (mis. versi cuma
dipasang di div BODY, komponen identitas pasien tinggal di HEADER). Pilih ini kalau anak itu mahal
(baca CLOB / master pasien) dan tak perlu di-refresh tiap simpan.

## Kenapa

`SupportNestingComponents` (vendor/livewire/livewire/src/Features/SupportNestingComponents/SupportNestingComponents.php)
hanya merender penuh anak yang **key-nya belum pernah dirender**. Kalau key anak sama seperti request
sebelumnya, server cuma mengirim **stub**:

```html
<div wire:id="…" wire:name="…" wire:key="…"></div>   ← TANPA wire:snapshot
```

Normalnya stub aman: morph berhenti di batas `wire:id` dan komponen lama di DOM dibiarkan.
Tapi begitu **key leluhur berubah** (karena `incrementVersion()`), morph membuang seluruh subtree dan
menyisipkan node baru. Node baru itu = stub tanpa snapshot → `Component` constructor
(`livewire.js` ~6401) melempar:

```
Uncaught Snapshot missing on Livewire component with id: <id>
Uncaught TypeError: Cannot read properties of null (reading 'memo')   ← lanjutannya, di get isLazy
```

Error kedua muncul karena instance setengah jadi terlanjur nempel di `el.__livewire`, lalu
`getDeepChildren()` membaca `child.isLazy` → `this.snapshot.memo` padahal `snapshot` null.

**Gejala di layar:** komponen anak (mis. kartu identitas pasien) hilang / jadi kotak kosong, dan
interaksi Livewire berikutnya di halaman itu ikut mati.

**Kapan meledak:** hanya saat versi naik dengan identitas lain tetap — yaitu di `persistScreening()`,
`saveDraft()`, `setPetugasScreening()`, `bukaKunci()`. Saat modal dibuka biasanya aman karena
`$rjNo` berubah `null → X` sehingga key anak ikut berubah. Jadi bug ini **lolos dari uji buka modal**;
harus diuji sampai Simpan.

## Cara membuktikan tanpa buka browser

```bash
php artisan tinker --execute '
$t = Livewire\Livewire::test("pages::transaksi.rj.emr-rj.screening.rm-screening-rj-actions");
$t->html();                                    // render pertama
$t->call("incrementVersion","modal-screening-rj");
$h = $t->html();                               // render setelah versi naik
preg_match_all("/<div[^>]*wire:id=\"[^\"]+\"[^>]*>/", $h, $m);
echo count($m[0])." node wire:id, ".substr_count(implode("",$m[0]),"wire:snapshot")." punya snapshot\n";'
```

Setiap node `wire:id` selain root komponen yang diuji **harus** punya `wire:snapshot`.
Kalau ada yang 0 → itu stub → di browser pasti "Snapshot missing".

## Audit pola yang sama

```bash
rg -l 'renderKey\(' resources/views | xargs rg -n '<livewire:' -A2 | rg -v 'renderKey|renderVersions'
```

Saring hasilnya: yang berbahaya hanya kalau tag `<livewire:>` benar-benar bersarang di elemen
ber-`renderKey` DAN komponen induknya memanggil `incrementVersion()` tanpa mengubah konteks key
lain (id transaksi, mode, dll.).

## Titik yang sudah dibetulkan (2026-08-15)

- `resources/views/pages/transaksi/rj/emr-rj/screening/⚡rm-screening-rj-actions.blade.php`
- `resources/views/pages/transaksi/ugd/emr-ugd/screening/⚡rm-screening-ugd-actions.blade.php`

Preseden pola benar yang sudah lama ada:
`resources/views/pages/transaksi/kasir/administrasi-kasir-ri/⚡administrasi-kasir-ri.blade.php:817`
(`wire:key="lov-obat-kasir-ri-{{ $slsNo }}-{{ $renderVersions['modal-administrasi-kasir-ri'] ?? 0 }}"`).

## Terkait

- `livewire-input-patterns` §8 — jangan pasang wire:key dinamis pada input search (kebalikan kasus ini).
- `administrasi-transaksi` — renderKey dipakai justru untuk memaksa remount input setelah simpan.
