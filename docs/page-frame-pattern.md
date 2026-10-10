# Page Frame Pattern

Pola standar struktur halaman SIRUS — judul di topbar + tabel yang otomatis isi tinggi viewport.

Sebelumnya tiap halaman pakai `<header>` lokal di atas konten dan `max-h-[calc(100dvh-320px)]` di scroll area. Pola itu fragile: offset 320px harus dihitung manual sesuai tinggi toolbar di atasnya, dan kalau salah hitung muncul empty space di bawah card. Pola baru memindahkan judul ke topbar global dan pakai flex column biar card otomatis fill remaining vertical space tanpa hard-coded offset.

---

## 1. Page Title — `<x-page-title>`

Komponen Blade yang nge-set Alpine store `pageTitle`. Layout (`resources/views/layouts/app.blade.php`) render store ini sebagai chip kecil di kanan logo RS pada topbar.

### Pemakaian

```blade
<div>
    <x-page-title
        title="Judul Halaman"
        subtitle="Deskripsi singkat halaman." />

    {{-- konten halaman --}}
</div>
```

### Aturan props

- **`title`** (string) — judul singkat, plain text.
- **`subtitle`** (string) — deskripsi singkat, plain text.
  - **Tidak boleh mengandung HTML** (mis. `<strong>`, `<span>`) — semua styling diatur di layout.
  - Karakter `&`, `<`, `>`, `"` ditulis apa adanya (atau pakai entity HTML; Blade akan handle escape attribute).
  - Hindari subtitle yang sangat panjang — topbar punya ruang terbatas.

### Behavior

- Store di-reset otomatis saat `livewire:navigating` (sebelum DOM swap), jadi title page lama tidak nyangkut di topbar saat user pindah halaman.
- Halaman yang tidak set title (mis. dashboard, login) akan otomatis menampilkan topbar tanpa chip judul (karena store kosong dan `x-show="$store.pageTitle?.title"` jadi false).

### Implementasi referensi

- Komponen: `resources/views/components/page-title.blade.php`
- Store + reset: `resources/views/layouts/app.blade.php` (script `alpine:init` + `livewire:navigating`)
- Render slot: `resources/views/layouts/app.blade.php` (block di sebelah logo, `hidden lg:flex`)

---

## 2. Flex-Fill Table Frame

Pola wrapper untuk halaman bertabel: card tabel mepet ke bawah viewport, pagination nempel di card bottom, scroll terjadi internal di scroll area tabel.

### Struktur 4 lapis

```blade
<div>
    <x-page-title title="..." subtitle="..." />

    {{-- LAPIS 1: Outer wrapper — height = sisa viewport (di bawah topbar 5rem) --}}
    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-white dark:bg-gray-800">

        {{-- LAPIS 2: Inner padding wrapper — flex column biar anak bisa flex-fill --}}
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR (sticky di atas table card) --}}
            <div class="sticky z-30 px-4 py-3 bg-white border-b border-gray-200 top-20 dark:bg-gray-900 dark:border-gray-700">
                {{-- filter, search, action buttons --}}
            </div>

            {{-- LAPIS 3: Card tabel — flex-1 isi sisa tinggi --}}
            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-white border border-gray-200 shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">

                {{-- LAPIS 4: Scroll area — flex-1 di dalam card, ini yang scroll --}}
                <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto rounded-t-2xl">
                    <table class="min-w-full text-sm">
                        <thead class="sticky top-0 z-10 ...">...</thead>
                        <tbody>...</tbody>
                    </table>
                </div>

                {{-- PAGINATION sticky di bawah card --}}
                <div class="sticky bottom-0 z-10 px-4 py-3 bg-white border-t border-gray-200 rounded-b-2xl dark:bg-gray-900 dark:border-gray-700">
                    {{ $this->rows->links() }}
                </div>
            </div>

        </div>
    </div>
</div>
```

### Kelas kunci

| Kelas | Lapis | Fungsi |
|---|---|---|
| `h-[calc(100vh-5rem)]` | Outer | Tinggi total = viewport - topbar 5rem (80px). |
| `flex flex-col` | Outer, Card | Children jadi flex item vertikal. |
| `flex-1 min-h-0` | Inner, Card, Scroll area | Anak yang growable. `min-h-0` penting biar overflow di dalam flex child beneran scroll (default `min-height: auto` di flex item bikin overflow lolos). |
| `overflow-x-auto overflow-y-auto` | Scroll area | Tempat scroll terjadi (bukan di body). |
| `sticky top-0` | thead | Header table tetap kelihatan saat scroll. |
| `sticky bottom-0` | Pagination | Nempel di bawah card. |

> **Gotcha — wrapper perantara (mis. `wire:poll`) memutus rantai flex.** Rantai `flex-1` dari Outer → Inner → Card tidak boleh putus. Kalau table card (Lapis 3) dibungkus div lain di antaranya — misalnya wrapper auto-refresh `<div wire:poll.20s class="mt-4">` — div perantara itu **wajib ikut `flex flex-col flex-1 min-h-0`**. Kalau hanya `class="mt-4"`, `flex-1` di card kehilangan acuan tinggi → card menciut setinggi konten (tabel kosong tampak pendek, sisa layar putih). Ini beda gejala dengan card-level yang benar (lihat `pelayanan-rj` yang card-nya langsung anak Inner tanpa wrapper).

### Empty state (tabel kosong / sedikit baris)

Yang bikin tabel "isi penuh layar" walau kosong adalah **card-level `flex-1 min-h-0`** (Lapis 3) — bukan baris empty-nya. Card putih otomatis fill sisa tinggi viewport dan pagination tetap nempel di bawah, jadi empty state cukup **satu baris sederhana di dalam `<tbody>`**. Referensi kanonik: `daftar-rj` (route `rawat-jalan/daftar`).

Pola standar — `@forelse ... @empty` dengan satu `<tr><td colspan="N">` (N = jumlah kolom `<thead>`), `text-center` + `py-16`:

```blade
<tbody>
    @forelse ($this->rows as $row)
        <tr wire:key="...">...</tr>
    @empty
        <tr>
            <td colspan="5" class="px-6 py-16 text-center text-gray-500 dark:text-gray-400">
                {{-- teks polos "Belum ada data", atau ikon + teks: --}}
                <div class="flex flex-col items-center gap-2">
                    <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">...</svg>
                    <span>Tidak ada data</span>
                </div>
            </td>
        </tr>
    @endforelse
</tbody>
```

Aturan:

- **Pakai `@forelse`/`@empty`** di dalam `<tbody>`. JANGAN `@foreach` + `@if ($this->rows->isEmpty())` dengan panel `<div class="flex-1 ...">` di luar `<table>` — itu over-engineer dan tidak konsisten dengan pola repo.
- **`colspan` = jumlah kolom** `<thead>` supaya sel kosong membentang penuh.
- **`py-16 text-center` sudah cukup.** Jangan tambah `h-full`/`flex-1` di `<tr>`/`<td>` — tinggi `<table>` tidak terdefinisi sehingga `h-full` tak berefek; card-level flex (Lapis 3) yang sudah urus fill.
- Jangan tambah `flex flex-col` di scroll area (Lapis 4) demi empty state — tidak perlu.

### Kelas header tabel (`<thead>` → `<tr>`)

Header kolom tabel list pakai kelas baku berikut — ukuran **`text-sm`**, bobot **`font-semibold`**, rata kiri, abu-abu, uppercase:

```blade
<thead class="sticky top-0 z-10 bg-gray-50 dark:bg-gray-800">
    <tr class="text-sm font-semibold tracking-wide text-left text-gray-600 uppercase dark:text-gray-300">
        <th class="px-6 py-3">...</th>
    </tr>
</thead>
```

Aturan:

- **Jangan** pakai `text-base` (tampak terlalu tebal) atau `text-xs` (terlalu tipis) untuk header list — selalu **`text-sm`**.
- Bobot selalu **`font-semibold`** (bukan `font-medium` / `font-bold`).
- Variasi yang dibolehkan karena beda konteks: header rata-tengah `text-center text-gray-500`, header branded `text-brand`, header modal/sub-tabel kecil `text-xs font-medium`. Untuk **header list standar**, ikuti kelas baku di atas.

### Halaman tanpa tabel (form, dashboard, dll.)

Form / dashboard panjang tidak perlu pakai pola flex-fill. Cukup pakai outer wrapper biasa:

```blade
<div>
    <x-page-title title="..." subtitle="..." />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-white dark:bg-gray-800">
        <div class="px-6 pt-2 pb-6">
            {{-- konten form / dashboard --}}
        </div>
    </div>
</div>
```

Bedanya: `min-h` (bukan `h`) supaya konten bisa scroll body kalau panjang, dan tanpa `flex flex-col flex-1 min-h-0` di dalamnya.

---

## 3. Halaman dengan Action Button di Header

Untuk halaman yang dulu punya button/link di `<header>` (mis. tombol Refresh, Kembali, Mount All), pindahkan elemen aksinya ke **action bar** di awal content area, di bawah `<x-page-title>`:

```blade
<div>
    <x-page-title title="..." subtitle="..." />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-white dark:bg-gray-800">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- ACTION BAR --}}
            <div class="flex flex-wrap items-center justify-end gap-2 mb-4">
                <x-secondary-button wire:click="...">Refresh</x-secondary-button>
                <a href="..." wire:navigate>← Kembali</a>
            </div>

            {{-- toolbar + table card seperti biasa --}}
        </div>
    </div>
</div>
```

Pertahankan semua attribute (`wire:click`, `href`, `class`, `x-on:click`) dari elemen asli — hanya posisinya yang dipindah.

---

## 4. Halaman dengan Multi-Card / Nested Grid

Untuk layout 2-card side-by-side (mis. `master-clab` = list di kiri + child di kanan, atau `kartu-stock` = produk di kiri + history di kanan), propagasi flex context ke setiap cell grid:

```blade
{{-- Parent grid harus flex-1 min-h-0 supaya isi grid bisa pakai full height --}}
<div class="grid grid-cols-3 gap-2 flex-1 min-h-0">

    {{-- Cell kiri: flex column biar card di dalamnya bisa flex-fill --}}
    <div class="flex flex-col min-h-0">
        <div class="flex flex-col flex-1 min-h-0 bg-white border ... rounded-2xl">
            {{-- toolbar --}}
            <div class="flex-1 min-h-0 overflow-y-auto">...</div>
            {{-- pagination --}}
        </div>
    </div>

    {{-- Cell kanan: child Livewire component --}}
    <div class="col-span-2 flex flex-col min-h-0">
        <livewire:pages::... wire:key="..." />
    </div>
</div>
```

Untuk child Livewire component (yang di-embed di grid cell), buat root-nya juga flex:

```blade
{{-- resources/views/pages/.../child-component.blade.php --}}
<div class="flex flex-col h-full min-h-0">
    @if (...)
        <div class="flex flex-col flex-1 min-h-0">
            {{-- toolbar + card flex-fill --}}
        </div>
    @endif
</div>
```

---

## 5. Checklist Saat Bikin Page Baru

- [ ] Root `<div>` lalu `<x-page-title title="..." subtitle="..." />` di atas konten.
- [ ] Subtitle plain text, tanpa HTML markup.
- [ ] Kalau ada tabel + pagination: pakai pola 4-lapis flex-fill (lihat §2).
- [ ] Empty state tabel: `@forelse`/`@empty` + `<td colspan py-16 text-center>` (lihat §2). Jangan bikin panel `flex-1` / `isEmpty()` sendiri — card-level flex sudah bikin layar terisi penuh.
- [ ] Kalau ada action button (Refresh, Kembali, dll.): masuk ke action bar di bawah `<x-page-title>` (lihat §3), bukan di dalam `<header>`.
- [ ] Jangan pakai `<header class="bg-white shadow ...">` lagi — itu pola lama.
- [ ] Jangan pakai `max-h-[calc(100dvh-XXXpx)]` di scroll area — itu pola lama yang fragile.
- [ ] Test di viewport pendek (mis. laptop 13" 768px tinggi) untuk pastikan card tidak ke-cut.

---

## 6. Referensi Implementasi

| Use case | File contoh |
|---|---|
| Tabel sederhana | `resources/views/pages/master/master-poli/⚡master-poli.blade.php` |
| Tabel dengan toolbar kompleks | `resources/views/pages/transaksi/rj/pelayanan-rj/⚡pelayanan-rj.blade.php` |
| Empty state tabel standar (`@forelse`/`@empty`) | `resources/views/pages/transaksi/rj/daftar-rj/⚡daftar-rj.blade.php` |
| Multi-card grid (2-cell) | `resources/views/pages/master/master-laborat/clab/⚡master-clab.blade.php` |
| Child component dalam grid cell | `resources/views/pages/master/master-laborat/clabitem/⚡master-clabitem.blade.php` |
| Action bar di atas | `resources/views/pages/manajemen/rs/tu/pendapatan-rs/⚡pendapatan-rs.blade.php` |
| Form / dashboard (tanpa flex-fill) | `resources/views/livewire/⚡dashboard.blade.php` |
