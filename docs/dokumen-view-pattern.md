# Pola "Dokumen Viewer" (Lihat = Preview Cetak) — Rekam Medis

Pola untuk menampilkan dokumen read-only di layar yang **isinya persis sama dengan hasil Cetak PDF**, plus navigasi antar-record tanpa buka-tutup modal. Diadopsi di display Rekam Medis RI/RJ/UGD (`resources/views/pages/components/rekam-medis/*/dokumen-view/`).

Gunakan pola ini kalau: sudah ada blade cetak (DomPDF) untuk suatu dokumen, dan ingin user bisa **melihat** isinya di modal tanpa harus download PDF dulu — dengan jaminan tampilan = cetakan.

## Inti pola

1. **Lihat = render blade cetak ke iframe.** Jangan bikin daftar field kurasi manual (rawan beda dgn cetakan & rawan Array-to-string). Render blade print yang sudah ada → HTML self-contained → tampilkan di `<iframe srcdoc>`.
2. **Satu sumber payload** (`buatData()`), dipakai bersama oleh `lihat()` (preview) dan `cetak()` (PDF) → dijamin identik.
3. **Per-modul, komponen kecil.** Tiap jenis dokumen = satu komponen Volt viewer. Helper bersama ditaruh di trait + komponen Blade shared.

## Komponen shared

### Trait `App\Http\Traits\Dokumen\DokumenViewSupportTrait`
- `pasienDokumen($regNo)` — data pasien + umur (`thn`).
- `App\Support\TtdUser::pathBerkasDariKode($code)` — path TTD dari `myuser_code` (null jika hilang); bukan method trait.
- `identitasRsDokumen()` — kop RS.
- `renderDokumenPreview($view, $data)` — **kunci**: `view($view,['data'=>$data])->render()` lalu:
  - `str_replace(public_path(), '', $html)` → ubah path filesystem absolut (logo/TTD `public_path(...)` yg dibutuhkan DomPDF) jadi path web-relatif (`/images/...`, `/storage/...`) supaya **muncul di iframe browser** (kalau tidak → 404).
  - inject `<style>body{zoom:1.4}</style>` sebelum `</head>` → perbesar preview **tanpa** menyentuh blade cetak. Cetak PDF tetap pakai HTML asli.
- (khusus RI, dokumen PAB berpayload seragam) `dataDokumenRi()` / `streamCetakDokumenRi()` / `previewDokumenRi()`.

### Blade `resources/views/components/rm/`
- `dokumen-view-modal` — shell modal `size=full height=full`; body pakai iframe kalau `:previewHtml` terisi (else slot). Footer: nav antar-record + Tutup + Cetak PDF (`wire:click="cetak(id)"`).
- `doc-list-row` — baris entri (Lihat + Cetak) → `wire:click="lihat(id)"`/`cetak(id)` terikat komponen pembungkus.
- `doc-empty` — empty-state.
- `record-nav` — Prev/Next antar-record (di footer, dibahas di bawah).

## Anatomi komponen viewer

```php
new class extends Component {
    use EmrRITrait, MasterPasienTrait, DokumenViewSupportTrait;
    public ?string $riHdrNo = null;
    public array $list = [];         // entri dioper via prop dari parent (bukan baca CLOB ulang)
    public ?array $selected = null;
    public string $previewHtml = '';
    private string $printView = 'pages.components.modul-dokumen.ri.<doc>.cetak-<doc>-print';

    public function lihat(string $id): void {
        $this->selected = collect($this->list)->firstWhere('id', $id) ?: null;
        $data = $this->buatData($id); if (!$data) return;
        $this->previewHtml = $this->renderDokumenPreview($this->printView, $data);
        $this->dispatch('open-modal', name: "view-<doc>-{$this->riHdrNo}");
    }
    public function cetak(string $id): mixed {
        $data = $this->buatData($id); if (!$data) return null;
        $pdf = Pdf::loadView($this->printView, ['data'=>$data])->setPaper('A4');
        return response()->streamDownload(fn()=>print $pdf->output(), '<doc>-'.($data['regNo'] ?? $this->riHdrNo).'.pdf');
    }
    private function buatData(string $id): ?array { /* baca fresh findDataRI + pasien + identitasRs + ttd + tglCetak */ }
};
```

Template: daftar `<x-rm.doc-list-row>` (dari `$list`) + satu `<x-rm.dokumen-view-modal ... :previewHtml="$previewHtml">`.

## Jebakan penting

- **Path gambar cetak.** Blade cetak pakai `public_path('images/...')` / `public_path('storage/...')` (absolut, untuk DomPDF). Di iframe browser itu jadi URL 404. Wajib di-rewrite di preview (lihat `renderDokumenPreview`). **Cetak PDF jangan di-rewrite.**
- **Array-to-string.** Kalau tetap render field manual, hindari `implode` array bersarang & `(string)$array`. (Pola iframe menghilangkan risiko ini.)
- **Blade full-doc di iframe.** Blade cetak = `<!DOCTYPE html>` + CSS inline (`layout-a4-*` inline via `file_get_contents`). Aman di `srcdoc` (self-contained). Jangan embed sebagai `<div>` (style bocor).
- **`@disabled(...)` di dalam `<x-...>`.** JANGAN taruh `@disabled($cond)` sebagai atribut komponen Blade — merusak kompilasi (`unexpected endif`). Pakai `:disabled="$cond"`.
- **previewHtml besar.** Hasil render bisa ratusan KB; disimpan di public property → ikut payload Livewire. OK untuk 1 modal aktif; jangan set banyak sekaligus.
- **Efisiensi.** Oper `$list` sebagai prop dari parent (data yg sudah dimuat), jangan tiap komponen baca CLOB (`findDataRI`) sendiri saat mount. `cetak`/`lihat` baru baca fresh saat aksi.

## Navigasi antar-record (2 level)

**Level dokumen** (dalam modal Lihat, antar-entri sejenis): trait menyediakan `navField` + `navIdList()` + `navTotal()` + `navPos()` + `prevRecord()`/`nextRecord()` (jalan di atas `$list`+`$selected`, memanggil `lihat()`). Komponen cukup set `$this->navField = 'createdAt'` (atau field id lain) di mount + oper `:navTotal`/`:navPos` ke modal.

**Level record/kunjungan** (footer modal RM utama, antar-kunjungan 1 pasien): state di **`rekam-medis-display`** (daftar yg terfilter `regNo`). `openRekamMedis($txnNo,$status)` hitung pos/total dari halaman aktif + dispatch open modul yg benar (RJ/UGD/RI). Tombol modal dispatch `rm-display-nav(dir)`; display `#[On('rm-display-nav')]` cari tetangga → buka. **Urutan DESC** → `next` = `pos-1` (lebih baru/atas), `prev` = `pos+1` (lebih lama/bawah). Lintas-modul → tutup modal modul lain dulu. Komponen `x-rm.record-nav` (tombol ghost-brand).

## Warna/UI
Tombol nav pakai `x-ghost-button` (ghost-brand: tint hijau brand + border brand) supaya "aware" tanpa se-dominan primary. Lihat `docs/standar-komponen-tombol.md`.
