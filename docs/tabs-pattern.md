# Standar Komponen Tab (`x-tabs` / `x-tab`)

> Bagian dari [Standar UI & Komponen](standar-ui-komponen.md) — lihat juga standar tombol, modal, form section, dan tabel.

Tab bar reusable sesuai **Standar UI siRUS v2**. Mengganti pola lama yang verbose (`<ul><li><button @class([...])>` atau `x-bind:class="..."` di-copy per tab).

File: `resources/views/components/tabs.blade.php` (track) + `resources/views/components/tab.blade.php` (item).

---

## Anatomi

- `<x-tabs>` — kontainer/track. Menyimpan prop `variant` dan diwariskan ke tiap `<x-tab>` di dalamnya via `@aware` (variant cukup ditulis sekali di induk).
- `<x-tab>` — satu item (di-render jadi `<button type="button">`). Atribut apa pun (`wire:click`, `x-on:click`, `title`, dst.) diteruskan ke `<button>`.

```blade
<x-tabs variant="underline">
    <x-tab :active="$activeTab === 'rj'"  color="emerald" wire:click="setTab('rj')">Rawat Jalan</x-tab>
    <x-tab :active="$activeTab === 'ugd'" color="rose"    wire:click="setTab('ugd')">UGD</x-tab>
    <x-tab :active="$activeTab === 'ri'"  color="blue"    wire:click="setTab('ri')">Rawat Inap</x-tab>
</x-tabs>
```

---

## Dua mode aktivasi

| Mode | Kapan | Cara |
|------|-------|------|
| **Server** | Aktif ditentukan Livewire/PHP (re-render saat klik) | `:active="$activeTab === 'rj'"` + `wire:click="..."` |
| **Alpine** | Aktif dihitung client-side, instan tanpa round-trip | `active-expr="tab === 'RiVisit'"` + `x-on:click="tab = 'RiVisit'"` |

**Mode Alpine** dipakai untuk tab yang dulunya `x-bind:class` + `@entangle`. Tetap switch seketika di browser:

```blade
<div x-data="{ tab: @entangle('activeTab') }">
    <x-tabs variant="underline" class="flex-wrap p-2">
        @foreach ($menus as $m)
            <x-tab active-expr="tab === '{{ $m['id'] }}'" x-on:click="tab = '{{ $m['id'] }}'">
                {{ $m['name'] }}
            </x-tab>
        @endforeach
    </x-tabs>
</div>
```

> Kalau `active-expr` diisi, komponen memakai `x-bind:class` reaktif dan **mengabaikan** `:active`. Jangan pasang `:class` sendiri di `<x-tab>` mode Alpine.

---

## Props

### `<x-tabs>`
| Prop | Default | Nilai |
|------|---------|-------|
| `variant` | `underline` | `pill` · `underline` · `card` · `chip` |

### `<x-tab>`
| Prop | Default | Catatan |
|------|---------|---------|
| `variant` | (warisan dari `<x-tabs>`) | Boleh dioverride per item |
| `active` | `false` | Mode server (boolean) |
| `active-expr` | `null` | Ekspresi JS Alpine; mengaktifkan mode Alpine |
| `color` | `brand` | `brand` · `emerald` · `rose` · `blue` · `purple` · `violet` — hanya berlaku di variant `underline` & `pill` |

---

## Varian

| Varian | Tampilan aktif | Pakai untuk |
|--------|----------------|-------------|
| `underline` | Garis bawah + teks berwarna | **Default** — tab section konten lebar (pengganti panel) |
| `pill` | Pill solid di track abu-abu | Filter/toggle ringkas |
| `card` | Tab kartu/folder naik menyatu panel bawah | — |
| `chip` | Chip `rounded-full` lepas, bisa wrap | — |

---

## Aturan & gotcha

- **Warna per modul dipertahankan.** Tab top-level (kasir/casemix/apotek) pakai `color=emerald` (RJ), `rose` (UGD), `blue` (RI), `violet` (rekap) sebagai penanda konteks. Tab EMR/administrasi tetap `brand` (hijau).
- **Ikon & badge** masuk ke slot `<x-tab>`. Untuk ikon + teks, tambahkan `class="inline-flex items-center gap-2"`.
- **Di dalam `<x-scrollable-tabs>`** (tab banyak, perlu scroll horizontal): JANGAN bungkus lagi dengan `<x-tabs>` (border-bottom dobel). Pakai `<x-tab variant="underline">` langsung di dalam `<div class="flex flex-nowrap gap-2 -mb-px">`; border-bottom ada di `<x-scrollable-tabs>`.
- **`color` hanya untuk `underline` & `pill`.** `card`/`chip` selalu brand.
- **Warna pakai map statis** (bukan `text-{$color}-700` dinamis) — Tailwind JIT tidak men-scan kelas yang disusun runtime. Kalau butuh warna baru, tambah entri di map `$palette` pada `tab.blade.php`.
- Padding baku `<x-tab>` = `px-4 py-2`, font `text-title-sm` (15px). Jangan override paksa `p-4` (konflik dengan `py-2`).

---

## Halaman Wrapper Hub (kompose komponen standalone via tab) — "model kasir"

Pola untuk **menggabungkan beberapa halaman/komponen yang SUDAH ada** ke satu layar lewat tab —
dipakai `/transaksi/apotek`, `/transaksi/kasir`, `/transaksi/casemix`. Wrapper hanya "rangka tab";
isi tiap tab = komponen Livewire penuh yang **juga punya route sendiri** (dipakai-ulang, bukan disalin).

**Ciri:**
- **Wrapper tipis** (± 60–120 baris): hanya `public string $activeTab` + `setTab()` (dengan whitelist),
  opsional pembuka modal berbagi. **NOL logika bisnis** — semua ada di komponen anak.
- **Anak = komponen standalone** (mis. `antrian-kasir-rj` juga di `/transaksi/rj/antrian-kasir-rj`;
  `daftar-rj-bulanan` juga di `/rawat-jalan/daftar-bulanan`). Wrapper tak menduplikasi query/aksi.
- **Modal berbagi** (mis. Cek Saldo Kas) di-mount **sekali** di level wrapper, **lazy** via flag boolean.

```php
new class extends Component {
    public string $activeTab = 'rj';
    public bool $showCekSaldo = false; // lazy: modal berat baru mount saat dibuka

    public function setTab(string $tab): void {
        if (!in_array($tab, ['rj', 'ugd', 'ri'], true)) return;  // whitelist wajib
        $this->activeTab = $tab;
    }
    public function openCekSaldo(): void { $this->showCekSaldo = true; $this->dispatch('open-modal', name: 'cek-saldo-kas'); }
};
```
```blade
<x-tabs variant="underline">
    <x-tab :active="$activeTab === 'rj'"  color="emerald" wire:click="setTab('rj')">Rawat Jalan</x-tab>
    <x-tab :active="$activeTab === 'ugd'" color="rose"    wire:click="setTab('ugd')">UGD</x-tab>
    <x-tab :active="$activeTab === 'ri'"  color="blue"    wire:click="setTab('ri')">Rawat Inap</x-tab>
</x-tabs>

@if ($activeTab === 'rj')
    <livewire:pages::transaksi.rj.antrian-kasir-rj.antrian-kasir-rj wire:key="antrian-kasir-rj-wrapper" />
@elseif ($activeTab === 'ugd')
    <livewire:pages::transaksi.ugd.antrian-kasir-ugd.antrian-kasir-ugd wire:key="antrian-kasir-ugd-wrapper" />
@endif
{{-- Modal berbagi, mount 1× --}}
<x-modal name="cek-saldo-kas">@if ($showCekSaldo)<livewire:... wire:key="cek-saldo-kas-inner" />@endif</x-modal>
```

### Alpine `x-show` vs Server `@if` — pilih sesuai berat komponen

| | **Alpine `x-show`** (semua anak mounted) | **Server `@if`** (lazy, 1 anak) |
|---|---|---|
| Mount | Semua tab mount sekaligus | Hanya tab aktif |
| Switch | Instan (client), **state anak terjaga** | Round-trip; anak lama unmount → **state reset** |
| Pakai untuk | Sub-komponen **ringan** (form dokumen) | Komponen **berat** (list + query + `wire:poll`) |
| Contoh | **modul-dokumen** (`modul-dokumen-ri`, sub-form di modal) | **model kasir** (apotek/kasir/casemix) |

Beda dari **modul-dokumen** (lihat `docs/modul-dokumen-ri-pattern.md`): di sana hub membuka **dokumen
bertanda tangan** (komponen ringan, khusus hub itu, di **modal**, tab **Alpine x-show** — semua mounted).
Model kasir mengkompose **halaman list standalone** (berat, ber-`wire:poll`) sebagai **halaman penuh**,
tab **server @if** (lazy) → konsekuensinya filter anak ter-reset saat ganti tab → wajib `#[Session]` (bawah).

## Persist state anak saat ganti tab (mode Server) — `#[Session]`

**Masalah.** Wrapper tab mode Server merender komponen anak lewat `@if ($activeTab === 'rj') <livewire:... /> @elseif ...`.
Ganti tab → `activeTab` berubah → anak tab lama **di-unmount, anak tab baru di-mount**.
Saat balik ke tab semula, komponennya **mount ulang** → `mount()` jalan lagi → filter (mis.
`filterTanggal = today`) **ter-reset**. Contoh: `/transaksi/apotek`, `/transaksi/kasir`, `/transaksi/casemix`.

**JANGAN** memperbaikinya dengan memindah tab ke Alpine `x-show` (semua anak tetap mounted) — komponen
antrian umumnya `wire:poll` → semua tab akan polling bersamaan (3–5× beban DB). Pertahankan `@if` (lazy).

**Solusi: persist tiap properti filter dengan `#[Session]`** + guard `mount()` supaya nilai tersimpan
tak tertimpa default saat remount. Lazy-load & polling per-tab tetap terjaga.

```php
use Livewire\Attributes\Session;

new class extends Component {
    // Key WAJIB di-namespace unik per komponen (hindari bentrok antar tab/komponen).
    #[Session(key: 'antrian-apotek-rj-searchKeyword')]
    public string $searchKeyword = '';
    #[Session(key: 'antrian-apotek-rj-filterTanggal')]
    public string $filterTanggal = '';
    #[Session(key: 'antrian-apotek-rj-filterKlaim')]
    public string $filterKlaim = '';
    // …semua properti filter/search/itemsPerPage. JANGAN Session-kan autoRefresh/renderVersions/data.

    public function mount(): void
    {
        // GUARD: hanya default bila kosong → nilai dari Session bertahan saat remount.
        $this->filterTanggal = $this->filterTanggal ?: Carbon::now()->format('d/m/Y');
        // (idem untuk filterBulan dll. yang di-set default di mount)
    }

    public function resetFilters(): void
    {
        // JANGAN diberi guard — tombol Reset MEMANG harus memaksa default.
        $this->reset([...]);
        $this->filterTanggal = Carbon::now()->format('d/m/Y');
    }
};
```

Aturan:
- **Key namespaced per komponen** (`<komponen>-<properti>`, mis. `daftar-rj-bulanan-filterBulan`) — kalau
  2 komponen memakai key sama, filter mereka saling bocor.
- Beri `#[Session]` ke **semua** properti filter/search/pagination; **jangan** ke `autoRefresh`,
  `renderVersions`, `renderAreas`, atau cache data (mis. `$claims`).
- **Guard `?:` hanya di `mount()`** untuk properti yang di-set default terhitung (`filterTanggal`/`filterBulan`);
  `resetFilters()` dibiarkan memaksa default.
- Bonus: filter juga bertahan lintas reload halaman (sampai logout) — biasanya UX yang diinginkan.
- Verifikasi: `Livewire::test($comp)->set('filterTanggal','X'); Livewire::test($comp)->get('filterTanggal')` = `X`.

Referensi: `transaksi/apotek` (wrapper) + `antrian-apotek-rj/ugd`, `antrian-ri-resep`; kasir & casemix idem.

## Migrasi dari pola lama

Acuan migrasi transaksi (merged main `1dee65ef`): 12 tab bar dikonversi, −161 baris. Validasi wajib: `php artisan view:cache` (compile semua Blade dengan resolver komponen asli) lalu `view:clear`.

Lihat juga skill `blade-safe-edit` (edit Blade presisi) dan `livewire-input-patterns`.
