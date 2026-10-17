---
name: siap-ui-ux
description: Aturan UI/UX khusus SIAP (Laravel 12 + Livewire 4 Volt + Alpine + Tailwind 3) — token warna "Netral Klinis", komponen yang wajib dipakai, pola Livewire/Alpine yang aman (loading, konfirmasi, modal, timer, galat jaringan), aksesibilitas, dan cara verifikasi. Baca saat membuat/merombak halaman, komponen, form, modal, atau tampilan; saat memilih warna; atau saat mengaudit UX.
---

# SIAP UI/UX untuk Livewire Laravel

Disusun dari audit UX berbasis UI UX Pro Max (aturan WCAG, Material, Apple HIG) lalu diikat ke SIAP:
identitas visual tetap (hijau Madinah + lime, komponen bersama SIRUS), pola Livewire/Alpine yang terbukti
aman, dan jebakan yang sudah pernah terjadi di repo ini. Gaya/palet/font generik **tidak dipakai** — brand
sudah tetap.

**Urutan prioritas:** permintaan user → skill ini & `docs/` → aturan UX umum (§4.15).

## 1. Alur kerja

1. **Cek pola yang sudah ada** — skill `ui-pattern-docs` (page-frame, master module, modal dirty, tab,
   cetak PDF). Jangan menulis ulang tombol/modal/tab/tabel.
2. **Pakai komponen** di §3 dan **token warna** di §2. Tidak ada hex mentah di halaman.
3. **Terapkan aturan Livewire/Alpine** di §4 untuk setiap aksi, modal, dan input.
4. **Kasus di luar aturan di atas** → pakai prinsip UX umum §4.15, diterjemahkan ke komponen & token SIAP.
5. **Checklist §5**, lalu **verifikasi §6** (minimal kompilasi view + buka halaman).

## 2. Token warna "Netral Klinis"

Sumber: `tailwind.config.cjs` (+ variabel `.dark` di `resources/css/app.css`). Latar netral, kartu putih,
hijau hanya untuk aksi & penanda aktif, lime sebagai aksen kecil.

| Peran | Kelas | Nilai terang |
|---|---|---|
| Latar halaman (wrapper page, band) | `bg-surface-soft` | `#f4f6f4` |
| Kartu, tabel, topbar, sidebar, modal isi | `bg-canvas` | `#ffffff` |
| Kartu sekunder | `bg-surface-card` | `#eef1ee` |
| Terpilih (menu/tab aktif, chip ikon) | `bg-surface-strong` | `#e3f0e6` |
| Garis | `border-hairline` | `#e2e6e3` |
| Judul / teks utama | `text-ink` | `#13201a` |
| Teks berjalan | `text-body` | `#3c463f` |
| Sub-judul / caption | `text-muted`, `text-muted-soft` | `#5f6961`, `#646d66` |
| Aksi utama | `bg-brand-green` (+ `hover:bg-brand-green-active`) | `#157547` |
| Aksen / aksi utama di mode gelap | `brand-lime` | `#a1cd3a` |
| Status | `x-badge` / token `success|warning|error|info` (`-tint` latar, `-deep` teks) | — |

Aturan:
- **Mode gelap**: skala `gray` Tailwind sudah diganti netral hijau hutan → cukup `dark:bg-gray-900`
  (latar), `dark:bg-gray-800` (kartu), `dark:border-gray-700`, `dark:text-gray-400` (redup).
  `text-muted` otomatis ikut token gelap (aturan di `app.css`).
- Aksi utama di mode gelap = lime dengan teks gelap (`dark:bg-brand-lime dark:text-ink`), sama seperti `x-primary-button`.
- **Jangan** `text-white` di atas kuning/lime (kontras < 2:1), **jangan** `text-brand-lime` di latar terang
  (1,9:1) — pakai `text-brand-green-active`.
- Kontras minimal 4,5:1 untuk teks, 3:1 untuk cincin fokus & ikon. Ragu → hitung (rumus luminans WCAG).
- Warna status **tidak boleh sendirian**: selalu ada kata/simbol (`Hadir`, `J!`, ikon).

## 3. Komponen wajib (jangan ditulis ulang)

| Kebutuhan | Komponen |
|---|---|
| Tombol | `x-primary-button`, `x-secondary-button`, `x-ghost-button`, `x-outline-button`, `x-danger-button`, `x-warning-button`, `x-icon-button` |
| Aksi baris tabel | `x-lihat-button`, `x-cetak-button`, `x-hapus-button`, `x-action-edit`, `x-action-delete` |
| Aksi berisiko (hapus, finalkan, generate, buka kunci) | `x-confirm-button` (punya spinner + `wire:target`) |
| Modal & form modal | `x-modal` + `x-dirty-modal-content` (peringatan "Data belum disimpan") |
| Sakelar | `x-toggle` (sudah `<button role="switch">`) |
| Tab | `x-tabs` / `x-tab` |
| Input | `x-text-input`, `x-text-input-number` (uang/angka), `x-select-input`, `x-textarea`, `x-combobox`, `x-input-label`, `x-input-error` |
| Toolbar list | `x-toolbar-refresh-reset` |
| Status | `x-badge` |
| Cetak PDF | `x-pdf.layout-a4-with-out-background` (A4 penuh), `x-pdf.layout-a4-bagi-4` (4 per lembar, mis. slip) |

Komponen bersama SIRUS: perbaikan aksesibilitas boleh, **ubah tampilan tidak** tanpa persetujuan user.

## 4. Aturan Livewire / Alpine

**4.1 Loading per aksi, bukan global.** Setiap tombol yang memanggil server: `wire:loading.attr="disabled"`
**dengan** `wire:target`. Tanpa target, tombol ikut mati setiap ketikan `wire:model.live`.
```blade
<x-primary-button wire:click="save" wire:loading.attr="disabled" wire:target="save">
    <span wire:loading.remove wire:target="save">Simpan</span>
    <span wire:loading wire:target="save">Menyimpan...</span>
</x-primary-button>
```
Input yang bisa dikirim dua kali (scan / Enter): `wire:loading.attr="readonly" wire:target="submitId"`.

**4.2 Aksi berisiko lewat `x-confirm-button`.** Hapus, finalkan, buka kembali dari Final, generate ulang,
"hitung semua" — jangan tombol polos. Pesan konfirmasi menyebut akibatnya.

**4.3 Modal.** `x-modal` sudah: `role="dialog"`, Tab terkunci, fokus kembali ke pemicu, Escape/klik latar
lewat `tryClose()` milik `x-dirty-modal-content` (elemen `data-penjaga-tutup`). Dialog yang muncul di
DALAM modal (konfirmasi, peringatan) beri `data-dialog-anak` supaya Escape tidak ikut menutup modal induk.
Tambah atribut `focusable` pada `x-modal` bila field pertama harus langsung terfokus.

**4.4 Satu elemen akar per komponen Livewire.** Modal halaman (`<x-modal>`) diletakkan DI DALAM div akar,
bukan setelahnya → kalau tidak: `MultipleRootElementsDetectedException`.

**4.5 Timer & listener Alpine dibersihkan.** `setInterval`/`addEventListener` di `x-init` bocor saat
Livewire mengganti langkah. Pakai `init()` + `destroy()` dan `wire:key` per langkah:
```blade
<div wire:key="kiosk-hasil" x-data="{ sisa: 5, t: null,
     init() { this.t = setInterval(() => { if (--this.sisa <= 0) { clearInterval(this.t); $wire.resetKiosk() } }, 1000) },
     destroy() { clearInterval(this.t) } }">
```
(Kasus nyata: timer kiosk lama me-reset layar orang berikutnya.)

**4.6 Panggilan `$wire` dari Alpine punya jalan pulang.** Jaringan HP bisa putus / sesi habis:
```js
try { await $wire.kirimAbsen(...) } catch (e) {} finally {
    if (this.fase === 'kirim') { this.fase = 'preview'; this.pesan = 'Gagal mengirim. Periksa sinyal, lalu coba lagi.' }
}
```
Pesan galat harus tampil di fase tempat user berada (bukan hanya di satu blok `x-show`), dengan `role="alert"`.

**4.7 Hasil aksi diumumkan.** Kartu hasil/galat: `role="status"` (atau `alert` untuk galat) + `aria-live`.
Toastr sudah punya `aria-live`.

**4.8 Fokus keyboard terlihat.** Elemen interaktif buatan sendiri:
`focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-gray-900`.
Jangan `focus:ring-*/15` (tak terlihat) dan jangan `focus:outline-none` tanpa pengganti.

**4.9 Elemen yang bisa diklik = `<button>`/`<a>`.** Bukan `<div wire:click>`. Sakelar → `x-toggle` atau
`<button role="switch" :aria-checked>`. Pilihan bertombol → `aria-pressed`. Jangan `<button>` di dalam `<a>`.

**4.10 Nama yang bisa dibaca.** Tombol ikon saja: `aria-label` (bukan `title` saja — tak terbaca di HP).
Label form: `<x-input-label for="x">` + `id="x"` pada input. Filter toolbar tanpa label terlihat:
`aria-label` atau label `sr-only`. Input file: `class="sr-only"` (bukan `hidden`) + `focus-within:ring` di label.

**4.11 Layar HP & kiosk** (Absen HP, Kiosk, halaman karyawan): target sentuh ≥44px (`py-3`/`min-h-[44px]`),
teks ≥ `text-xs` (12px), input `text-base` (iOS tidak zoom), tombol kirim terlihat (jangan andalkan Enter).

**4.12 Gerak.** Slider/animasi berhenti saat hover/fokus, ada tombol jeda, dan diam bila
`matchMedia('(prefers-reduced-motion: reduce)')`; tambahkan `motion-reduce:transition-none` pada transisi.

**4.13 Filter & periode yang penting disimpan** (`#[Url]` / `#[Session]`, lihat `livewire-input-patterns`)
supaya reload / kembali tidak mengulang dari bulan lalu.

**4.14 Empty state** jujur: bedakan "belum ada data" dengan "tidak cocok filter" + arahkan ke Reset.

**4.15 Prinsip UX umum** (urut prioritas, ringkasan UI UX Pro Max):
1. *Aksesibilitas* — kontras 4,5:1, fokus terlihat, alt teks, label, urutan Tab = urutan visual, judul berjenjang h1→h2.
2. *Sentuh & interaksi* — target ≥44px di HP (≥24px web), jarak ≥8px, umpan balik <100 ms, jangan andalkan hover.
3. *Performa* — gambar diberi ukuran/`loading="lazy"`, tidak ada lompatan tata letak, skeleton bila tunggu >1 detik.
4. *Gaya konsisten* — satu set ikon SVG (bukan emoji sebagai ikon), satu aksi utama per layar.
5. *Tata letak* — mobile-first, tanpa scroll horizontal, `dvh` bukan `vh` di HP, skala jarak 4/8px.
6. *Tipografi & warna* — badan teks ≥16px di HP, tinggi baris 1,5, angka kolom `tabular-nums`, warna lewat token.
7. *Animasi* — 150–300 ms, transform/opacity saja, hormati reduced-motion.
8. *Form* — label terlihat (bukan placeholder saja), galat di dekat field + cara memperbaiki, tanda wajib, konfirmasi sebelum menutup form yang berubah.
9. *Navigasi* — posisi aktif ditandai, kembali tidak mereset filter, aksi berbahaya terpisah dari navigasi.
10. *Data & grafik* — legenda & label sumbu, tidak hanya warna, ada empty/error state.

## 5. Checklist sebelum selesai

- [ ] Komponen §3 dipakai; tidak ada hex mentah / warna di luar token §2
- [ ] Mode terang **dan** gelap terbaca (≥4,5:1), status tidak hanya warna
- [ ] Setiap aksi server: loading + `wire:target`; aksi berisiko: `x-confirm-button`
- [ ] Modal: di dalam akar, form dibungkus `x-dirty-modal-content`
- [ ] Timer/listener Alpine punya `destroy()`; panggilan `$wire` punya jalur gagal
- [ ] Fokus keyboard terlihat, tombol ikon ber-`aria-label`, label terhubung ke input
- [ ] HP: tidak ada scroll horizontal halaman, target ≥44px
- [ ] Kelas Tailwind baru? → `npm run build` (dan beri tahu user: server juga perlu build).
      PDF (DomPDF) pakai CSS biasa di slot `gaya`, bukan kelas Tailwind baru.

## 6. Verifikasi

1. `php artisan view:clear && php artisan view:cache` — semua Blade/Volt terkompilasi.
2. Uji asap halaman yang disentuh sebagai admin (GET saja, tanpa menulis DB) — file PHPUnit sementara di
   scratchpad, dijalankan dengan `php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php <file>`:
   `$this->actingAs(User::where('email','admin@siap.com')->first())->get(route('...'))->assertOk()`.
3. Lihat hasilnya: halaman publik → `google-chrome --headless=new --window-size=1366,768 --screenshot=… http://127.0.0.1:8000/`
   (cek juga `390,844`). Halaman ber-login → simpan HTML dari uji asap, ganti URL `/build/` ke `file://…/public/build/`,
   buang `x-cloak`, lalu screenshot (Livewire/Alpine tidak jalan — hanya untuk warna & tata letak).
4. Perilaku interaktif (Tab di modal, Escape, timer) tidak teruji oleh screenshot → sebutkan ke user apa
   yang perlu dicoba manual.

Terkait: `ui-pattern-docs`, `livewire-input-patterns`, `livewire-render-versioning`, `blade-safe-edit`.
