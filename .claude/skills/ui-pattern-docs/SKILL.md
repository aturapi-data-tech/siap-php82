---
name: ui-pattern-docs
description: Indeks pola UI/komponen terdokumentasi di folder docs/. Baca sebelum membuat komponen baru (tombol, modal, halaman, cetak PDF, editor, list) agar konsisten dengan pola repo dan tidak reinvent. Mengarahkan ke file docs/ yang relevan.
---

# Indeks Pola UI/Komponen (docs/)

Sebelum membuat komponen baru, cek apakah polanya sudah ada di `docs/`. Ikuti pola yang ada agar konsisten. Baca file docs terkait sebelum implementasi.

> Katalog SEMUA skill repo (12) ada di `docs/skills-index.md` — daftar skill + "baca saat".

| Kebutuhan | Baca file |
|---|---|
| **Di mana file baru harus ditaruh & dinamai** (pohon `app/` + `resources/views/`, aturan `⚡`, suffix peran/jalur, batas `components/` vs `livewire/` vs `pages/`, batas Trait vs Support, prefix URL, 7 pemeriksa rename) | `docs/standar-struktur-folder.md` — versi web: route `/panduan-dev/koding-struktur` |
| **Modul master baru / CRUD list+form** (struktur file, event, `ds-table`, `x-action-*`, ORA-02292) | `docs/standar-master-module.md` (acuan kanonik: `master-agama`) |
| Standar tombol (varian, ukuran, warna, ikon) | `docs/standar-komponen-tombol.md` |
| **Kasir, transfer & model batal** (alur kasir→pulang, transfer RJ→UGD→RI, Batal Transaksi/Transfer/Inap, matriks & guard) | halaman `/panduan-dev/koding-administrasi` |
| Tab bar (`x-tabs`/`x-tab` — varian, mode server/Alpine, warna modul) | `docs/tabs-pattern.md` |
| Standar UI komponen umum | `docs/standar-ui-komponen.md` |
| Halaman bertabel full-height (frame, toolbar sticky, pagination, empty state) | `docs/page-frame-pattern.md` |
| Modal dengan deteksi perubahan (konfirmasi keluar bila dirty) | `docs/dirty-modal-pattern.md` |
| Cetak PDF + tanda tangan (TTD) | `docs/ttd-pattern-pdf-print.md` |
| TTD petugas di layar (form entry, stamp nama+tgl user login) | `docs/ttd-petugas-component.md` (komponen `<x-signature.ttd-petugas>`) |
| Editor rich text | `docs/tinymce-editor-pattern.md` |
| List/lookup stabil (decouple dari filter) | `docs/stable-lookup-list-pattern.md` |
| Trait untuk integrasi API eksternal (BPJS/iDRG/Sisrute dll.) | `docs/trait-template-api-eksternal.md` |
| **SATUSEHAT** (FHIR R4 — model pengiriman, auth OAuth2, resolusi IHS, standarisasi per-resource, pemetaan kolom dashboard, coverage & backlog) | `docs/satusehat-api.md` |
| Bridging iDRG (grouper, Stage 1/2, topup) | `docs/idrg-bridging.md` |
| Diagnosa ICD-10 (master, LOV, EMR, SEP, iDRG) | `docs/diagnosa-architecture.md` (+ skill `diagnosa-flow`) |
| Versioning teks klausul dokumen legal (consent/pernyataan; cetak ulang sesuai redaksi saat TTD) | `docs/clause-versioning.md` (+ skill `clause-versioning`) |
| Laboratorium — siklus PENUH (EMR order → petugas → admin/kasir → dokter lihat hasil → master → laporan) | `docs/laborat-architecture.md` (+ skill `laborat`) |
| Laboratorium — deep-dive modul petugas (item master, hasil, rentang normal & nilai kritis, Mindray, batal) | `docs/laborat-modul.md` (+ skill `laborat`) |
| **Kamar Operasi (OK)** — port Oracle Forms rit006x.fmb: 2 pintu masuk (petugas OK & order EMR RI), mesin tarif 11 pos, guard pulang dua arah, rantai Enter. Memuat **tahapan porting ke RJ/UGD**. | `docs/kamar-operasi-modul.md` |
| **Modul-dokumen RI** (formulir bertanda tangan: consent, kerohanian, edukasi, akhir hayat — draft → TTD keluarga/saksi → TTD petugas = kunci → buka kunci Admin/Manager, cetak) | `docs/modul-dokumen-ri-pattern.md` |
| **Dokumen multi-entri EMR RI** (CPPT/SBAR — banyak entri per pasien, tab per-profesi, Edit=pemilik/Hapus=supervisor/Review=DPJP Utama, copy-ke-form, cetak per-entri) | `docs/emr-multi-entry-document-pattern.md` (+ skill `emr-multi-entry-document`) |

## Catatan kunci per pola
- **Page frame / tabel full-height**: yang bikin tabel isi penuh layar = card-level `flex flex-col flex-1 min-h-0` (bukan empty row-nya). Empty state cukup `@forelse`/`@empty` + `<td colspan py-16 text-center>`. JANGAN bikin panel `flex-1` / `@if($this->rows->isEmpty())` sendiri. Acuan: `daftar-rj`. **Gotcha:** wrapper perantara `wire:poll` (`<div ... class="mt-4">`) di atas card WAJIB ikut `flex flex-col flex-1 min-h-0`, kalau tidak card menciut & tabel kosong tampak pendek. **Header tabel list baku:** `text-sm font-semibold tracking-wide text-left text-gray-600 uppercase` (jangan `text-base`/`text-xs`; `font-semibold`, bukan medium/bold).
- **TTD print**: pola `h-16` + `text-center` + `&nbsp;` fallback. HINDARI `display:flex` / `mx-auto` / `<br>` / bracket yang belum di-rebuild.
- **TTD petugas di layar**: pakai komponen `<x-signature.ttd-petugas>` (jangan tulis blok TTD-Saya inline lagi). Induk sediakan `ttdSaya()`/`hapusTtd()` (guard `$isFormLocked` server-side + simpan `ttdCode`). `:framed=false` utk grid-cell. **Gotcha:** jangan taruh `<x-...>` di komentar file komponen → runtime `Undefined variable $component` (lolos lint, meledak saat render).
- **Modul-dokumen RI**: TTD petugas = aksi TERAKHIR yang sekaligus MENGUNCI entri (jangan bikin tombol "Simpan & Kunci" terpisah). Buka kunci hanya cabut TTD petugas, TTD pasien/saksi tetap, wajib audit + gate `@hasanyrole` DAN cek role di server.
- **Escape ganda prop komponen**: `value="A &amp; B"` / `title=` / `nameLabel=` / `signLabel=` ter-escape DUA kali (komponen meng-echo `{{ }}` lagi) → layar menampilkan `&amp;`. Tulis `&` polos di atribut komponen; untuk nilai dinamis pakai `:value="$x"`, bukan `value="{{ $x }}"`.
- **Stable lookup list**: list HANYA depend tanggal; decouple dari filterStatus/filterKlaim.
- **Trait API eksternal**: ikuti pola `VclaimTrait` — event split (mis. `idrg-state-updated` vs `idrg-section-changed`), suffix per-modul.
- **Tab bar**: pakai `x-tabs`/`x-tab`, JANGAN tulis ulang `<ul><li><button @class([...])>`. `variant` di `<x-tabs>` diwarisi via `@aware`. Mode server (`:active` + `wire:click`) vs Alpine (`active-expr` + `x-on:click`, untuk `@entangle`). Di dalam `<x-scrollable-tabs>` jangan bungkus lagi dengan `<x-tabs>` (border dobel). Warna baru → tambah di map `$palette` (statis, bukan kelas dinamis).

Lihat juga skill terkait: `blade-safe-edit`, `livewire-input-patterns`.
