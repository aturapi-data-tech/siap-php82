# Standar Modul Master (CRUD List + Form)

README standarisasi pengkodingan & UI/UX untuk semua modul di `resources/views/pages/master/`.
Tujuan: kode ringkas, seragam, dan mudah diaudit programmer lain — cukup hafal SATU pola.

**Acuan kanonik: `master-agama`** (`resources/views/pages/master/master-agama/`) — implementasi
terbersih generasi design-system `ds-*`. Halaman style guide hidup: route `/panduan-dev`.
Versi tutorial interaktif (per-submenu, gaya docs Livewire): route `/panduan-dev/koding-master`.

> Dokumen ini memakai token generasi baru (`ds-table`, `bg-surface-soft`, `border-hairline`,
> `x-action-edit/delete`). Contoh markup di `standar-ui-komponen.md` §3 dan `page-frame-pattern.md`
> yang masih menulis `bg-white border-gray-200` + Edit via `x-secondary-button` mentah adalah
> **gaya lama** — untuk modul master, ikuti dokumen ini.

---

## 1. Struktur File & Routing

Satu modul master = **satu folder, dua file Volt SFC**:

```
resources/views/pages/master/master-<nama>/
├── ⚡master-<nama>.blade.php           # LIST  : tabel + toolbar + pagination
└── ⚡master-<nama>-actions.blade.php   # FORM  : modal create/edit + delete handler
```

- Semua file = **Volt SFC anonymous class** (`new class extends Component {...}`), tanpa Controller.
- Routing eksplisit di `routes/web.php` (bukan Folio):

```php
Route::livewire('/master/<nama>', 'pages::master.master-<nama>.master-<nama>')
    ->name('master.<nama>');
```

- LIST me-mount FORM sebagai child di akhir markup:

```blade
<livewire:pages::master.master-<nama>.master-<nama>-actions wire:key="master-<nama>-actions" />
```

**Kapan boleh menyimpang** (varian resmi, lihat §8): master-detail hierarkis, form bertab
multi-partial, atau halaman integrasi eksternal.

---

## 2. Kontrak Penamaan

| Hal | Standar | Contoh |
|---|---|---|
| State pencarian | `searchKeyword` | — |
| State per halaman | `itemsPerPage` (default 10) | — |
| Reset filter | method `resetFilters()` | dipanggil `x-toolbar-refresh-reset` |
| Data list | `#[Computed] rows()` | `$this->rows` di markup |
| Event namespace | `master.<namafolder>.*` | `master.agama.openCreate` |
| Verb event | `openCreate` / `openEdit` / `requestDelete` / `saved` | — |
| Mode form | `$formMode` (`'create'`\|`'edit'`) + `$originalId` | — |
| State form | array `$form = [...]` (key = nama kolom DB) | `form.rel_desc` |
| wire:key baris | `<slug>-{{ $row->pk }}` | `agama-{{ $row->rel_id }}` |

Aturan tambahan:
- Event namespace **harus sama dengan nama folder** (`master-kelas-rawat` → `master.kelas-rawat.*`,
  BUKAN `master.class.*`). Deviasi lama tercatat di §9.
- LIST **tidak boleh berisi validasi/simpan** — ia hanya dispatch event ke FORM. Semua
  `validate()`/insert/update/delete hidup di file `-actions`.

---

## 3. Komponen LIST — anatomi

Kerangka lengkap: lihat `⚡master-agama.blade.php`. Ringkasan kelas PHP:

```php
new class extends Component {
    use WithPagination;

    public string $searchKeyword = '';
    public int    $itemsPerPage  = 10;

    public function updatedSearchKeyword(): void { $this->resetPage(); }
    public function updatedItemsPerPage(): void  { $this->resetPage(); }

    public function resetFilters(): void { /* reset + resetPage */ }

    public function openCreate(): void            { $this->dispatch('master.<x>.openCreate'); }
    public function openEdit(int $id): void       { $this->dispatch('master.<x>.openEdit', id: $id); }
    public function requestDelete(int $id): void  { $this->dispatch('master.<x>.requestDelete', id: $id); }

    #[On('master.<x>.saved')]
    public function refreshAfterSaved(): void { $this->resetPage(); }

    #[Computed]
    public function rows()
    {
        $q = DB::table('...')->select(...)->orderBy(...);
        if (trim($this->searchKeyword) !== '') {
            $kw = mb_strtoupper(trim($this->searchKeyword));
            $q->whereRaw('UPPER(kolom) LIKE ?', ["%{$kw}%"]);
        }
        return $q->paginate($this->itemsPerPage);
    }
};
```

- Query pakai **`DB::table()`** (bukan Eloquent) — konsisten dgn seluruh repo Oracle.
- Pencarian **case-insensitive Oracle**: `UPPER(kolom) LIKE` + `mb_strtoupper` (baca skill `oracle-quirks`).
- Query kompleks (join banyak / dipakai ulang utk export): pisahkan **`baseQuery()`** privat,
  `rows()` tinggal `->paginate()` — contoh `master-obat`.

Markup (urutan wajib):

1. `<x-page-title title="Master X" subtitle="..." />`
2. Frame flex-fill: `h-[calc(100vh-5rem)]` → `flex flex-col flex-1 min-h-0` (detail: `page-frame-pattern.md`)
3. **Toolbar sticky** `top-20 bg-surface-soft border-hairline`, isi: search
   (`wire:model.live.debounce.300ms="searchKeyword"`) · select `itemsPerPage` ·
   `<x-primary-button wire:click="openCreate">+ Tambah ...</x-primary-button>` ·
   `<x-toolbar-refresh-reset :label="null" />`
4. **Card tabel** `flex flex-col flex-1 min-h-0 bg-canvas border-hairline rounded-2xl`
5. **Tabel `class="ds-table"`** + `<thead class="sticky top-0 z-10">` — JANGAN tulis ulang
   kelas header manual; `.ds-table` sudah mengatur font/padding/uppercase.
   - Sel ID/kode: `ds-td-token` (mono) · sel nama utama: `ds-td-strong` · kolom rata tengah: `ds-c`
6. Baris: `wire:key` unik + kolom Aksi:

```blade
<td class="ds-c">
    <div class="flex justify-center gap-2">
        <x-action-edit wire:click="openEdit({{ $row->id }})" />
        <x-action-delete :action="'requestDelete(' . $row->id . ')'"
            title="Hapus X" message="Yakin hapus {{ $row->desc }}?" />
    </div>
</td>
```

7. Empty state: `@forelse/@empty` + `<td colspan="N">` ikon + teks (JANGAN panel `isEmpty()` sendiri)
8. Pagination sticky bottom: `{{ $this->rows->links() }}`

---

## 4. Komponen FORM (`-actions`) — anatomi

```php
new class extends Component {
    use WithRenderVersioningTrait;               // renderKey utk remount modal bersih

    public string $formMode   = 'create';
    public int    $originalId = 0;
    public array  $form = [ /* key = kolom DB, semua string '' */ ];

    public function mount(): void { $this->registerAreas(['modal']); }

    #[On('master.<x>.openCreate')] public function openCreate(): void { ... }
    #[On('master.<x>.openEdit')]   public function openEdit(int $id): void { ... }
    #[On('master.<x>.requestDelete')] public function delete<X>(int $id): void { ... }

    public function save(): void { ... }
    public function closeModal(): void { ... }
    private function resetForm(): void { ... }
};
```

Alur wajib tiap handler:

- **openCreate/openEdit**: `resetForm()` → set `formMode`/`originalId`/`form` → `incrementVersion('modal')`
  → `dispatch('open-modal', name: '...')` → dispatch event fokus field pertama.
- **save()**: `validate()` DULUAN (jangan early-return sebelum validate — field merah tak muncul)
  → insert/update → `dispatch('toast', type:'success', ...)` → `closeModal()` → `dispatch('master.<x>.saved')`.
- **closeModal()**: `resetForm()` → `dispatch('close-modal', ...)` → `resetVersion()`.

Markup modal (3 bagian — header/body/footer):

```blade
<x-modal name="master-<x>-actions" size="full" height="full" focusable>
    <x-dirty-modal-content name="master-<x>-actions" event="master.<x>.saved" label="X"
        :wireKey="$this->renderKey('modal', [$formMode, $originalId])">
        {{-- HEADER: logo + judul Tambah/Ubah + <x-badge> Mode + close X (x-icon-button tryClose()) --}}
        {{-- BODY  : bg-surface-soft + x-enter-chain + <x-border-form title="...">fields</x-border-form> --}}
        {{-- FOOTER: sticky bottom — hint kbd Enter · Batal (tryClose()) · Simpan (wire:loading) --}}
    </x-dirty-modal-content>
</x-modal>
```

- Field wajib pakai `:error="$errors->has(...)"` + `<x-input-error>` di bawahnya.
- Navigasi keyboard: `x-enter-chain` di body + `x-on:keydown.enter.prevent` per field
  (field terakhir → `$wire.save()`); fokus via `x-ref` + event window (baca skill `livewire-input-patterns`).
- Field nominal uang → `<x-text-input-number>` (jangan `type="number"` biasa).
- Form bertab: pakai `<x-tabbed-dirty-modal-content>` (bukan tabs manual di dalam
  `x-dirty-modal-content`) + partial per tab (lihat §8).
- **Field FK ke master lain → LOV** (`resources/views/livewire/lov/<entitas>/`, 34 tersedia —
  jangan bikin dropdown pencarian manual). Kontrak: mount `<livewire:lov...>` dgn `target` unik
  + `wire:key` ber-`renderVersions`; LOV dispatch `lov.selected.<target>`; parent tangkap via
  `#[On]` → isi `$form` + `resetValidation`; validasi `Rule::exists` tetap di parent.
  Mode edit: FK terkunci → field readonly (contoh `master-obat-kronis`), FK boleh ubah →
  prop `initial*Id`. Acuan bersih: `lov/product`.

---

## 5. Validasi

- Pesan **selalu Bahasa Indonesia** + `$attributes` nama field manusiawi.
- **Form kecil** (≤ ~5 field): tiga array inline di `save()` — `$this->validate($rules, $messages, $attributes)`
  (gaya baseline agama).
- **Form besar**: pisahkan method `rules()` / `messages()` / `validationAttributes()` supaya `save()`
  tetap pendek (gaya master-poli/diagnosa/karyawan — resmi, bukan deviasi).
- Rule unik hanya saat create: `formMode === 'create' ? 'required|...|unique:tabel,kolom' : 'required|...'`
  dan field PK `:disabled="$formMode === 'edit'"` di markup.

---

## 6. Delete — dua lapis pengaman (WAJIB)

1. **Konfirmasi UI**: selalu lewat `<x-action-delete>` (confirm-button danger) — JANGAN `wire:confirm`.
2. **Guard FK Oracle**: bungkus delete dgn catch `ORA-02292` → toast ramah:

```php
try {
    $deleted = DB::table('...')->where('pk', $id)->delete();
    if ($deleted === 0) { $this->dispatch('toast', type:'error', message:'Data tidak ditemukan.'); return; }
    $this->dispatch('toast', type:'success', message:'... berhasil dihapus.');
    $this->dispatch('master.<x>.saved');
} catch (QueryException $e) {
    if (str_contains($e->getMessage(), 'ORA-02292')) {
        $this->dispatch('toast', type:'error', message:'... tidak bisa dihapus karena masih dipakai di ....');
        return;
    }
    throw $e;
}
```

Opsional (lebih informatif): cek eksplisit tabel pemakai sebelum delete, seperti
`master-pasien-actions` (cek rstxn_rjhdrs/ugdhdrs/rihdrs) — dianjurkan utk master bervolume tinggi.

---

## 7. Batas ukuran & kapan pecah file

- LIST ideal ≤ ~300 baris; FORM ≤ ~400 baris.
- FORM > ~400 baris ATAU > 1 section logis → pecah **partial per section**
  (`master-<x>-actions-<section>.blade.php`, di-`@include`) — contoh `master-pasien` (10 partial).
- Partial = markup murni (tanpa kelas Volt); state tetap di file `-actions` induk.

---

## 8. Level kompleksitas & varian resmi

Tidak semua master sama beratnya — mulai SELALU dari Level 1, naik level hanya bila domain menuntut:

| Level | Ciri | Teknik tambahan | Contoh |
|---|---|---|---|
| **1 · Dasar** | satu tabel, CRUD murni | pola §1–§7 persis | agama, poli, kelas-rawat, stocklocations |
| **2 · Menengah** | + FK / status / query berat | LOV, `baseQuery()`, `toggleActive`, rules/messages method, form bertab | obat, obat-kronis, dokter, karyawan, pasien |
| **3 · Expert** | hierarki induk-anak / sub-list dlm form | verb event spesifik + payload konteks (`afterSaved(entity, roomId)`), panel detail, sub-form validasi bertahap, tarif per kelas via modal tersendiri | kamar, laborat, interaksi-obat, jasa-medis/jasa-dokter |

Pola Level 3 yang terstandar:
- **Hierarki (master-kamar)**: verb spesifik `openCreateKamar`/`openCreateBed(roomId)` dalam satu
  namespace `master.kamar.*`; child list dengarkan `bangsal.selected`; event `saved` bawa payload
  `entity` + `roomId` supaya refresh presisi.
- **Sub-list dalam form (master-jasa-medis)**: tiap sub-form (paket obat/lain-lain) punya LOV
  ber-target unik + `validate()` bertahap sendiri di `addPaket*()`; baris masuk array `$form`;
  `save()` validasi form utama lalu simpan header + loop detail.
- Level 3 BUKAN izin menaruh validate()/simpan di LIST (panel tarif master-dokter = backlog, bukan contoh).

### Varian struktur resmi (boleh menyimpang dari 2-file)

| Varian | Kapan | Contoh acuan |
|---|---|---|
| **Master-detail hierarkis** | Data induk-anak dikelola satu layar | keluarga `master-kamar` (bangsal→kamar→bed), `master-laborat` (clab→clabitem), `master-interaksi-obat` (hdr→dtl) |
| | Aturan: namespace event bersama + verb spesifik (`master.kamar.openCreateBangsal`), child list embedded tanpa `x-page-title`/frame penuh | |
| **Form bertab** | Field sangat banyak, multi-section | `master-pasien` (Alpine `activeTab` + partial) |
| **Single-file integrasi** | Bukan CRUD murni; sinkronisasi API eksternal | `setup-jadwal-bpjs`, `registrasi-aplicares-sirs` — trait API ikut `trait-template-api-eksternal.md` |

Di luar tiga varian ini, WAJIB ikut pola §1–§7.

---

## 9. Backlog penyeragaman (hasil audit 2026-07-09)

Deviasi nyata yang perlu diperbaiki bertahap (bukan varian resmi):

| Prioritas | Modul | Masalah |
|---|---|---|
| 🔴 | `master-obat-kronis`, `master-signa-catatan` | delete **tanpa catch ORA-02292** → user bisa kena error 500 |
| 🟡 | `master-kelas-rawat` (`master.class.*`), `master-diag-keperawatan` (`master.diagkep.*`) | namespace event ≠ nama folder |
| 🟡 | `master-dokter` | panel tarif-per-kelas + `validate()` di komponen LIST — mestinya modal/child terpisah |
| 🟢 | `master-interaksi-obat/dtl` | state `searchProduk`/`itemsPerPageProduk`/`pageProduk` ≠ kontrak §2 |
| 🟢 | `master-diag-keperawatan`, `master-obat`, `master-kamar` actions | belum pakai `x-border-form` utk section form |
| 🟢 | `master-pasien` | tabs manual → migrasi ke `x-tabbed-dirty-modal-content` |

Modul yang sudah 100% sesuai: `master-agama`, `master-stocklocations`, `master-akuntansi/*`
(3 modul). Sisanya sesuai dengan deviasi gaya kecil.

---

## 10. Checklist audit modul master baru

- [ ] Folder + 2 file `⚡` (list & actions), route `Route::livewire` + `->name('master.*')`
- [ ] Kontrak penamaan §2 (state, event `master.<folder>.*`, verb standar)
- [ ] LIST: page-title → frame flex-fill → toolbar sticky → `ds-table` → `x-action-edit/delete` → empty state → pagination sticky
- [ ] LIST tanpa validasi/DB-write; semua mutasi di `-actions`
- [ ] FORM: `WithRenderVersioningTrait` + `x-modal` + `x-dirty-modal-content` + header/body/footer standar
- [ ] `validate()` sebelum logika lain; pesan Indonesia + attributes
- [ ] Delete: `x-action-delete` + catch ORA-02292
- [ ] `x-enter-chain` + Enter di field terakhir = simpan; fokus otomatis saat modal buka
- [ ] Toast sukses/gagal via `dispatch('toast', ...)`; refresh list via event `saved`
- [ ] File tidak melebihi batas §7; pecah partial bila perlu

---

## Referensi

| Apa | Di mana |
|---|---|
| Template kanonik | `resources/views/pages/master/master-agama/` |
| Style guide hidup | route `/panduan-dev` |
| Token & kelas `ds-*` | `resources/css/app.css` (sumber warna: `tailwind.config.cjs`) |
| `x-action-edit` / `x-action-delete` | `resources/views/components/action-{edit,delete}.blade.php` |
| `x-toolbar-refresh-reset` | `resources/views/components/toolbar-refresh-reset.blade.php` |
| `x-border-form`, `x-dirty-modal-content`, `x-tabbed-dirty-modal-content`, `x-page-title` | `resources/views/components/` |
| `WithRenderVersioningTrait` | `app/Http/Traits/Concerns/WithRenderVersioningTrait.php` |
| Pola frame halaman | `docs/page-frame-pattern.md` |
| Modal dirty-guard | `docs/dirty-modal-pattern.md` |
| Tombol & UI umum | `docs/standar-komponen-tombol.md`, `docs/standar-ui-komponen.md` |
