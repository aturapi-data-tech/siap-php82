# TinyMCE Editor Pattern

Pola standar untuk rich-text editor di EMR/Resume/Form modal yang butuh **table editing** (insert row/col, merge cells), formatting Word-style, dan output HTML yang reliable di-render via DomPDF.

Sebelumnya project pakai Quill v2 — tapi Quill **tidak punya plugin table native** (cuma tabel dari module komunitas pihak ketiga, tidak stabil). Untuk dokumen seperti Resume Medis RM 41 yang struktur dasarnya `<table>` 2-kolom (Label | Value), TinyMCE jauh lebih cocok. TinyMCE GPL community = self-hosted, tidak butuh API key Tiny Cloud.

**Status saat ini:** Quill **sudah di-replace seluruhnya** oleh TinyMCE — package `quill` di-uninstall, komponen `<x-quill-editor>` dihapus. Lihat §10 untuk catatan migrasi & backward compat data legacy.

---

## 1. Quick usage

```blade
<x-tinymce-editor
    name="resumeMedis"
    placeholder="Ketik isi resume medis..."
    height="600"
    modal-event="resume-medis-ri"
    flush-event="resume-medis-ri.flush"
    reload-event="resume-medis-ri.reload"
    class="mt-1" />
```

Pasang dalam Livewire/Volt component yang punya property:

```php
public string $resumeMedis = '';
```

Editor akan otomatis sync dua arah dengan `$resumeMedis`:
- **Editor → wire**: setiap `input`/`change`/`keyup`/`blur`/`SetContent` event di TinyMCE, isi HTML di-push via `$wire.set('resumeMedis', html, false)` (tanpa server roundtrip).
- **Wire → editor**: pre-fill saat editor init (`ed.setContent(initial)`), atau on-demand via `reloadEvent`.

---

## 2. Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `name` *(required)* | string | — | Nama Livewire property tempat HTML disimpan. |
| `placeholder` | string | `"Tulis di sini…"` | Placeholder saat editor kosong. |
| `height` | int | `480` | Tinggi editor area dalam px (excluding toolbar). |
| `modal-event` | string\|null | `null` | Modal name. Saat `open-modal`/`close-modal` event dengan `detail.name === modalEvent` fires, editor boot/cleanup otomatis. Wajib kalau editor di dalam `<x-modal>`. |
| `flush-event` | string\|null | `null` | Custom window event untuk **paksa flush** isi editor ke `$wire` sebelum action submit. Penting untuk tombol Simpan/Cetak. |
| `reload-event` | string\|null | `null` | Custom event untuk **reload** isi dari `$wire` ke editor (mis. setelah server-side reset/template rebuild). |

---

## 3. Modal lifecycle

```blade
<x-modal name="resume-medis-ri" size="full" height="full">
    <div class="flex flex-col h-full">

        <!-- Header static -->
        <div class="px-6 py-4 border-b">...</div>

        <!-- Body scrollable -->
        <div class="flex-1 px-6 py-5 overflow-y-auto">
            <x-tinymce-editor
                name="resumeMedis"
                modal-event="resume-medis-ri"
                flush-event="resume-medis-ri.flush"
                reload-event="resume-medis-ri.reload"
                height="600" />
        </div>

        <!-- Footer sticky bottom -->
        <div class="sticky bottom-0 z-10 ... bg-white border-t shrink-0">
            <x-secondary-button wire:click="closeEditor">Batal</x-secondary-button>
            <x-secondary-button
                x-on:click="window.dispatchEvent(new Event('resume-medis-ri.flush'));
                            $nextTick(() => $wire.cetakPdf())">
                Cetak PDF
            </x-secondary-button>
            <x-primary-button
                x-on:click="window.dispatchEvent(new Event('resume-medis-ri.flush'));
                            $nextTick(() => $wire.save())">
                Simpan
            </x-primary-button>
        </div>

    </div>
</x-modal>
```

Flow open → save → close → re-open:

1. **Open** — server `dispatch('open-modal', name: 'resume-medis-ri')` → factory listener fire `setTimeout(120ms) → bootEditor()` → TinyMCE init dengan content dari `$wire.get('resumeMedis')`.
2. **Simpan** — user klik Simpan → Alpine dispatch `'resume-medis-ri.flush'` window event → factory `flush()` push HTML editor ke `$wire` → `$wire.save()` jalan server-side → tetap di modal.
3. **Close** — server `dispatch('close-modal', name: 'resume-medis-ri')` → factory `cleanupEditor()` → `tinymce.remove()` instance, filter null entries.
4. **Re-open** — sama dengan step 1, tapi `bootEditor()` reset textarea state + pakai unique ID baru supaya tidak collide dengan instance lama.

---

## 4. Required: flush sebelum action submit

**Anti-pattern:**

```blade
<!-- ❌ JANGAN — content TinyMCE belum sync ke $wire saat action submit -->
<x-primary-button wire:click="save">Simpan</x-primary-button>
```

TinyMCE flush ke `$wire` via debounced events (`blur`, `change`). Kalau user klik Simpan tanpa blur dulu (mis. langsung klik dari keyboard di toolbar), isi terakhir bisa hilang.

**Pattern:**

```blade
<!-- ✅ Force flush via window event sebelum action -->
<x-primary-button
    x-on:click="window.dispatchEvent(new Event('resume-medis-ri.flush'));
                $nextTick(() => $wire.save())">
    Simpan
</x-primary-button>
```

`$nextTick` memastikan flush selesai (set wire property) sebelum action server-side dipanggil.

---

## 5. Reload dari server-side

Pakai `reload-event` untuk kasus seperti "Reset ke Default" — server rebuild template, lalu push ke editor:

```php
public function resetToDefault(): void
{
    // ... validation ...
    $dataRI = $this->findDataRI($this->riHdrNo);
    $this->resumeMedis = $this->buildPreFilledTemplate($dataRI);

    // Trigger reload di TinyMCE — factory listener catch event ini
    $this->dispatch('resume-medis-ri.reload');

    $this->dispatch('toast', type: 'success', message: 'Template di-reset.');
}
```

Factory `reload()`:
```js
reload() {
    if (!this.editor) return;
    const fresh = this.$wire.get(propName) || "";
    this.editor.setContent(fresh);
}
```

Livewire 3 `$this->dispatch()` fires CustomEvent yang bubble ke `window` → `window.addEventListener(reloadEvent, ...)` di factory pick it up.

---

## 6. Render output di PDF (DomPDF)

TinyMCE output HTML clean dengan `<table>`, `<ol>`, `<ul>`, `<strong>`, `<em>`, dst. Render di blade print:

```blade
<style>
    .resume-medis-content {
        font-size: 11px;
        line-height: 1.4;
        color: #1f2937;
    }
    .resume-medis-content p { margin: 0 0 4px 0; }
    .resume-medis-content ol { padding-left: 22px; margin: 0 0 4px 0; }
    .resume-medis-content table {
        width: 100%;
        border-collapse: collapse;
        margin: 4px 0;
    }
    .resume-medis-content table td,
    .resume-medis-content table th {
        border: 1px solid #9ca3af;
        padding: 3px 6px;
        vertical-align: top;
    }
    .resume-medis-content table th {
        background: #f3f4f6;
        font-weight: bold;
        text-align: left;
    }
</style>
<div class="resume-medis-content">
    {!! !empty($resumeMedis) ? $resumeMedis : '<p>-</p>' !!}
</div>
```

### Kenapa CSS inline `<style>` di print blade, bukan Tailwind class?

TinyMCE output `<table>` dengan inline `border-collapse: collapse` di style attribute, tapi cell `<td>` tidak punya class. DomPDF perlu CSS rule `border: 1px solid ...` di `.resume-medis-content table td` supaya gridline visible. Tailwind class tidak bisa target child dari raw HTML user-typed.

### Anti-patterns saat render

```blade
{{-- ❌ JANGAN — pakai Tailwind prose / typography plugin --}}
<div class="prose">{!! $resumeMedis !!}</div>
```

`prose` plugin punya banyak rule yang clash dengan DomPDF (`overflow-wrap`, `text-decoration-thickness`, dll yang tidak supported). Hasilnya margin/padding aneh.

```blade
{{-- ❌ JANGAN — render via {{ }} (escape HTML jadi text) --}}
<div>{{ $resumeMedis }}</div>
```

Pakai `{!! !!}` (unescaped) karena content sudah HTML. Risk XSS? Editor cuma boleh dipakai authenticated user (dokter/perawat), dan output langsung ke PDF user yang sama — bukan ke browser publik. Acceptable trade-off.

---

## 7. Bug fixes & workarounds

### 7.1 `purgeDestroyedEditor` crash saat re-open

**Symptom:** Modal pertama kali open OK, editor render normal. Save → close → buka lagi → editor **tidak render**, cuma textarea raw dengan HTML mentah visible. Console error:

```
Uncaught TypeError: null has no properties
    purgeDestroyedEditor tinymce.js
    initEditors tinymce.js
    init tinymce.js
    bootEditor app.js
```

**Cause:** TinyMCE 8 bug. Saat init kedua, internal `purgeDestroyedEditor` iterate `tinymce.editors` array global. Kalau ada `null` entry (sisa dari `destroy()`/`remove()` yang tidak bersih), function crash di property access → `null has no properties`. Init aborted, textarea tidak ter-replace.

**Fix di `bootEditor()`** (sudah include di app.js factory):

```js
// Filter null entries sebelum init
if (Array.isArray(tinymce.editors)) {
    tinymce.editors = tinymce.editors.filter((e) => e != null);
}

// Reset textarea state — sapu artifact destroy sebelumnya
host.style.cssText = "";
host.className = "";
host.value = "";

// Unique ID per init + pakai `selector` (bukan `target`)
host.id = "tinymce-host-" + Math.random().toString(36).slice(2);

tinymce.init({
    selector: "#" + host.id,
    license_key: "gpl",
    ...
});
```

### 7.2 License key wajib

TinyMCE 6.8+ / 7+ / 8+ **mandatory** explicit `license_key`. Untuk versi GPL self-hosted:

```js
tinymce.init({
    license_key: "gpl",  // ← wajib
    ...
})
```

Tanpa ini, init throw error "License key not provided".

### 7.3 Modal di dalam `wire:ignore`

Wrap editor host di `<div wire:ignore>` supaya Livewire morph tidak ganggu DOM TinyMCE saat re-render:

```blade
<div wire:ignore x-data="tinymceEditor({...})">
    <textarea x-ref="host"></textarea>
</div>
```

Tanpa `wire:ignore`, setiap Livewire re-render (mis. dari toast event, error update) bisa hapus iframe TinyMCE → editor blank tiba-tiba.

### 7.4 `target` vs `selector`

```js
// ❌ Kurang reliable saat re-init
tinymce.init({ target: host, ... })

// ✅ Pakai selector dengan unique ID
host.id = "tinymce-host-" + Math.random().toString(36).slice(2);
tinymce.init({ selector: "#" + host.id, ... })
```

`target` (DOM node) sering trigger `purgeDestroyedEditor` path. `selector` membuat tiap init dilihat sebagai **instance fresh** oleh TinyMCE internal.

---

## 8. Toolbar & plugins yang dipakai

```js
plugins: "lists link table autolink code charmap",
toolbar:
    "undo redo | blocks | bold italic underline strikethrough | forecolor backcolor | " +
    "alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | " +
    "table | link charmap | removeformat code",
```

| Plugin | Fungsi |
|--------|--------|
| `lists` | Bullet & numbered list |
| `link` | Insert/edit hyperlink |
| `table` | Insert/edit table (row, col, merge cells, properties) |
| `autolink` | Auto-detect URL saat ketik |
| `code` | View/edit raw HTML source |
| `charmap` | Insert special character |

**Jangan tambah plugin** kecuali ada use-case spesifik. Tiap plugin = bundle size lebih besar.

---

## 9. Self-hosted bundle via Vite

`resources/js/app.js` import dari npm package (tidak butuh CDN):

```js
import tinymce from "tinymce";
import "tinymce/icons/default";
import "tinymce/themes/silver";
import "tinymce/models/dom";
import "tinymce/plugins/lists";
import "tinymce/plugins/link";
import "tinymce/plugins/table";
import "tinymce/plugins/autolink";
import "tinymce/plugins/code";
import "tinymce/plugins/charmap";
import "tinymce/skins/ui/oxide/skin.min.css";
import contentCss from "tinymce/skins/content/default/content.min.css?inline";
import contentUiCss from "tinymce/skins/ui/oxide/content.min.css?inline";

window.tinymce = tinymce;
```

Init pakai `skin: false` + `content_css: false` supaya tidak coba fetch dari CDN/public path. Content style di-inject inline via `content_style`:

```js
tinymce.init({
    skin: false,
    content_css: false,
    content_style: contentCss + "\n" + contentUiCss + "\nbody { font-size: 14px; }",
    ...
})
```

**Bundle size impact:** ~800KB minified untuk TinyMCE core + 6 plugin di atas. Bersama Quill (yang juga dipakai), `public/build/assets/app-*.js` total ~1.6MB. Bisa di-code-split via dynamic import di future kalau jadi concern.

---

## 10. Migrasi dari Quill — catatan historis

Sebelumnya project pakai **Quill v2** untuk rich-text editor (radiologi `hasil_bacaan`, lab narasi, dst.). Quill di-replace ke TinyMCE penuh karena:

1. **Tidak ada table support native** — Quill cuma punya plugin table dari modul komunitas pihak ketiga, tidak stabil. Resume Medis RM 41 butuh struktur `<table>` 2-kolom (Label | Value), tidak bisa pakai Quill.
2. **Inkonsistensi style output** — Quill pakai class `.ql-align-center/-right/-justify` untuk alignment, TinyMCE pakai inline style atau class HTML standar. Lebih simpel kalau seluruh repo pakai 1 editor.
3. **Maintenance** — 1 editor library = 1 set of issues to debug, 1 bundle to maintain.

Sejak migrasi (lihat commit history), **`<x-quill-editor>` + Alpine factory `quillEditor` + package `quill` sudah dihapus** dari project. Komponen Blade ada di history saja.

### Backward compat — data legacy dari era Quill

Output HTML Quill yang sudah tersimpan di DB (terutama `hasil_bacaan` di radiologi) masih bisa di-render oleh DomPDF — class `.ql-align-*` tetap valid di CSS print template (`radiologi-display-print.blade.php`). TinyMCE bisa load HTML Quill sebagai initial content tanpa masalah (HTML standar). Tidak perlu migrasi data.

---

## 11. Contoh implementasi referensi

- **Resume Medis RM 41** — `resources/views/pages/components/rekam-medis/ri/resume-medis-ri/⚡resume-medis-ri-actions.blade.php`
  - Full TinyMCE dgn table support, auto pre-fill template dari EMR data, reload-event, EMR status lock, sticky footer, separate Simpan & Cetak PDF.

---

## 12. Komponen & file terkait

- **`resources/views/components/tinymce-editor.blade.php`** — Blade anonymous component, prop forwarding.
- **`resources/js/app.js`** — Alpine factory `tinymceEditor({...})`, import TinyMCE core + plugins, register `Alpine.data`.

Build & cache:
- Edit factory app.js / tambah plugin → wajib `npm run build`.
- Edit blade component / tambah usage → `php artisan view:clear` cukup, tidak perlu rebuild Vite.
