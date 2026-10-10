# Dirty Modal Pattern — Single vs Tabbed

Dua komponen wrapper modal yang track perubahan form ("dirty state") dan kasih warning "Tutup dan Simpan" kalau user mau close dengan data belum disimpan.

| Komponen | Cocok untuk | File |
|---|---|---|
| `<x-dirty-modal-content>` | Modal isinya **satu form** (single section) | `resources/views/components/dirty-modal-content.blade.php` |
| `<x-tabbed-dirty-modal-content>` | Modal **multi-tab**, tiap tab Livewire independen | `resources/views/components/tabbed-dirty-modal-content.blade.php` |

---

## 1. `<x-dirty-modal-content>` — Single Dirty (PATTERN LAMA)

### Konteks

Modal yang isinya satu form / satu Livewire component (atau beberapa yang dianggap satu kesatuan). Sekali ada user ketik di mana saja di slot, **seluruh modal dianggap dirty**.

Dipakai luas di repo ini: EMR RJ, EMR UGD, modal master (kamar/bed/akun/karyawan), modal sub-page seperti suket / inform-consent, dst.

### Props

```blade
<x-dirty-modal-content
    name="modal-name"                          {{-- Sama dengan name di <x-modal> --}}
    event="refresh-after-xxx.saved"            {{-- Event dispatch sub-component setelah save sukses untuk reset dirty --}}
    label="Nama Form"                          {{-- Teks di warning ("Ada perubahan di form {{ label }} ...") --}}
    :wireKey="$this->renderKey('modal-xxx', [...])"
    :save-events="['save-event-1', 'save-event-2']"   {{-- Livewire event yang di-broadcast saat "Tutup dan Simpan" --}}
    wrapperClass="flex flex-col min-h-..."     {{-- Optional --}}>

    {{-- Isi form di sini --}}

</x-dirty-modal-content>
```

### Cara Kerja

1. **Wrapper Alpine** punya state `dirty: false`.
2. **Wrapper catch SEMUA `input` / `change`** event yang bubble dari slot via `x-on:input` `x-on:change` di root div.
3. Sekali fire (dengan guard ~300ms dari `openedAt` untuk skip hydration), set `dirty = true`.
4. **Tombol Tutup** (di header / footer modal) panggil `tryClose()` — kalau dirty buka warning dialog, else langsung `$wire.closeModal()`.
5. **"Tutup dan Simpan"** di dialog panggil `saveAndClose()` — dispatch SEMUA event di `saveEvents`, tunggu sampai semua child confirm via `event` prop, lalu close.
6. **Setelah save sukses**, sub-component dispatch `event` (mis. `refresh-after-rj.saved`) → wrapper listen di `window` → reset `dirty=false` + `openedAt`.

### Yang Disediakan ke Slot

| Nama Alpine | Tipe | Keterangan |
|---|---|---|
| `dirty` | bool | Global dirty flag. |
| `setDirty()` | function | Set dirty=true. Otomatis dipanggil via `x-on:input/change` di wrapper. |
| `tryClose()` | function | Tombol Tutup panggil ini. |
| `saveAndClose()` | function | "Tutup dan Simpan" di warning. |

### Contoh Pemakaian

`resources/views/pages/transaksi/rj/emr-rj/⚡emr-rj.blade.php`:

```blade
<x-modal name="rm-perawat-actions" size="full" height="full" focusable>
    <x-dirty-modal-content name="rm-perawat-actions"
        event="refresh-after-rj.saved"
        label="EMR Rawat Jalan"
        :save-events="[
            'save-rm-anamnesa-rj',
            'save-rm-pemeriksaan-rj',
            'save-rm-diagnosa-rj',
            'save-rm-perencanaan-rj',
        ]"
        :wireKey="$this->renderKey('modal-emr-rj', [$rjNo ?? 'new'])">

        {{-- Tombol Tutup di header --}}
        <x-icon-button x-on:click="tryClose()">...</x-icon-button>

        {{-- ... isi form ... --}}

        {{-- Tombol Simpan global di footer --}}
        <x-primary-button wire:click="save()">Simpan</x-primary-button>
    </x-dirty-modal-content>
</x-modal>
```

### Kontrak Sub-Component

Tidak ada — sub-component Livewire **tidak perlu pasang Alpine apapun**. Cukup:

1. Punya `#[On('save-xxx')]` handler untuk setiap event di `saveEvents`.
2. Setelah save sukses, dispatch event yang sama dengan `event` prop (mis. `$this->dispatch('refresh-after-rj.saved')`) supaya wrapper reset dirty.

### Catatan

- **Kalau tab di-switch**, dirty TIDAK reset (karena tracking global). User edit di tab A → buka tab B → balik tab A: dirty tetap.
- **Tutup-dan-Simpan** dispatch SEMUA save event sekaligus, walau cuma 1 section yang berubah. Untuk multi-tab modal ini biasanya tidak efisien (multi-lock JSON yang sama berurutan).

---

## 2. `<x-tabbed-dirty-modal-content>` — Dirty Per-Tab (PATTERN BARU)

### Konteks

Modal multi-tab di mana **tiap tab adalah Livewire sub-component independen** dengan save handler-nya sendiri. Track dirty **per-tab** supaya:
- Indicator dot per tab nav (user tahu tab mana yang dirty)
- "Tutup dan Simpan" cuma dispatch save event untuk tab yang dirty saja
- Warning dialog list nama tab yang dirty

Saat ini dipakai khusus oleh **EMR Rawat Inap** (`pages/transaksi/ri/emr-ri/⚡emr-ri.blade.php`).

### Props

```blade
<x-tabbed-dirty-modal-content
    name="modal-name"                          {{-- Sama dengan name di <x-modal> --}}
    savedEvent="refresh-after-xxx.saved"       {{-- Event dispatch dari sub-component setelah save sukses --}}
    :wireKey="$this->renderKey('modal-xxx', [...])"
    :tabs="[
        ['key' => 'tab-a', 'label' => 'Tab A', 'saveEvent' => 'save-section-a'],
        ['key' => 'tab-b', 'label' => 'Tab B', 'saveEvent' => 'save-section-b'],
    ]"
    initialTab="tab-a"                         {{-- Optional; default ambil dari tabs[0] --}}
    wrapperClass="flex flex-col min-h-..."     {{-- Optional --}}>

    {{-- Header, tab nav, tab panels, footer --}}

</x-tabbed-dirty-modal-content>
```

Hanya tab yang punya save handler perlu masuk `:tabs`. Tab read-only (mis. Riwayat Kunjungan) skip — `tabDirty[key]` undefined, dot indicator otomatis tidak muncul.

### Cara Kerja

Berbeda dengan single — **source of truth ada di sub-component, bukan wrapper**.

1. **Sub-component Alpine** punya `sectionDirty: false`, listen `x-on:input/change`.
2. Saat user edit, sub-component dispatch event `section-dirty` dengan payload `{tab}`.
3. **Wrapper catch event** via `x-on:section-dirty` → set `tabDirty[tab] = true`.
4. **Tab nav** pakai `x-show="tabDirty[key]"` untuk indicator dot.
5. **Tombol Simpan footer** panggil `saveActive()` — dispatch save event untuk activeTab saja.
6. **Setelah save sukses**, sub-component listen `savedEvent` → reset `sectionDirty=false` → dispatch `section-clean` → wrapper `markClean(tab)`.
7. **Tutup-dan-Simpan**: dispatch save event hanya untuk dirty tab.

### Yang Disediakan ke Slot

| Nama Alpine | Tipe | Keterangan |
|---|---|---|
| `activeTab` | string | Tab key aktif. |
| `tabDirty` | object | `{tabKey: bool}` map per tab. |
| `saveMap` | object | `{tabKey: {label, saveEvent}}` dari prop tabs. |
| `markDirty(tab)` | function | Set `tabDirty[tab]=true`. Dipanggil internal lewat event. |
| `markClean(tab)` | function | Set `tabDirty[tab]=false`. |
| `isAnyDirty()` | function | Boolean. |
| `dirtyTabLabels()` | function | Array label dirty tabs untuk warning. |
| `saveActive()` | function | Dispatch save event untuk activeTab. |
| `tryClose()` | function | Tombol Tutup panggil ini. |
| `saveAndClose()` | function | "Tutup dan Simpan" di warning. |

### Contoh Pemakaian

`resources/views/pages/transaksi/ri/emr-ri/⚡emr-ri.blade.php`:

```blade
<x-modal name="rm-ri-actions" size="full" height="full" focusable>
    <x-tabbed-dirty-modal-content
        name="rm-ri-actions"
        savedEvent="refresh-after-ri.saved"
        :wireKey="$this->renderKey('modal-emr-ri', [$riHdrNo ?? 'new'])"
        :tabs="[
            ['key' => 'pengkajian-perawat', 'label' => 'Pengkajian Perawat', 'saveEvent' => 'save-rm-pengkajian-awal-ri'],
            ['key' => 'pengkajian-dokter', 'label' => 'Pengkajian Dokter', 'saveEvent' => 'save-rm-pengkajian-dokter-ri'],
            ['key' => 'diagnosa', 'label' => 'Diagnosis', 'saveEvent' => 'save-rm-diagnosa-ri'],
            ['key' => 'perencanaan', 'label' => 'Perencanaan', 'saveEvent' => 'save-rm-perencanaan-ri'],
        ]">

        {{-- Tab nav --}}
        @foreach ($tabs as $tab)
            <button @click="activeTab = '{{ $tab['key'] }}'">
                {{ $tab['label'] }}
                <span x-show="tabDirty['{{ $tab['key'] }}']" x-cloak
                    class="inline-block w-2 h-2 rounded-full bg-amber-500" title="Belum disimpan"></span>
            </button>
        @endforeach

        {{-- Tab panels — tiap panel berisi sub-component Livewire --}}
        <div x-show="activeTab === 'pengkajian-perawat'">
            <livewire:pages::transaksi.ri.emr-ri.pengkajian-awal-ri.rm-pengkajian-awal-ri-actions :riHdrNo="$riHdrNo" />
        </div>
        {{-- dst ... --}}

        {{-- Footer --}}
        <x-secondary-button x-on:click="tryClose()">Tutup</x-secondary-button>
        <template x-if="saveMap[activeTab]">
            <x-primary-button x-on:click="saveActive()">
                <span>Simpan <span x-text="saveMap[activeTab].label"></span></span>
            </x-primary-button>
        </template>
    </x-tabbed-dirty-modal-content>
</x-modal>
```

### Kontrak Sub-Component

Tiap sub-component WAJIB pasang Alpine x-data di root div untuk track sectionDirty + dispatch event ke wrapper:

```blade
<div class="space-y-4"
    wire:key="..."
    x-data="{
        sectionDirty: false,
        openedAt: 0,
        tab: 'tab-key-of-this-section',
        markDirty() {
            if (!this.sectionDirty && Date.now() - this.openedAt > 300) {
                this.sectionDirty = true;
                this.$dispatch('section-dirty', { tab: this.tab });
            }
        },
    }"
    x-init="
        openedAt = Date.now();
        window.addEventListener('refresh-after-xxx.saved', () => {
            sectionDirty = false;
            openedAt = Date.now();
            $dispatch('section-clean', { tab: tab });
        });
    "
    x-on:input="markDirty()"
    x-on:change="markDirty()">

    {{-- Form content --}}

</div>
```

**Saveable handler harus pakai `#[On(...)]`** yang match dengan `saveEvent` di props:

```php
#[On('save-rm-perencanaan-ri')]
public function store(): void { ... }
```

Setelah save sukses, dispatch event reset:
```php
private function afterSave(): void
{
    $this->dispatch('refresh-after-ri.saved');
    $this->dispatch('toast', ...);
}
```

---

## Mana yang Dipakai?

| Skenario | Pakai |
|---|---|
| Modal CRUD master (master kamar, akun, karyawan) | `<x-dirty-modal-content>` |
| Modal single sub-page (suket, inform-consent) | `<x-dirty-modal-content>` |
| EMR RJ / UGD (multi-section tapi global save flow) | `<x-dirty-modal-content>` |
| EMR RI (multi-tab, tiap tab independent component dengan save handler beda) | `<x-tabbed-dirty-modal-content>` |

Kalau ragu, default pakai single (`<x-dirty-modal-content>`) — switch ke tabbed kalau:
- Modal punya 4+ tab dengan save handler independen
- User butuh tahu tab mana yang dirty (indicator per tab)
- Save per tab lebih efisien dari save semua sekaligus

## Gotchas

### Untuk Keduanya
- **`event` / `savedEvent` HARUS match** dengan `$this->dispatch('xxx')` di PHP handler — kalau tidak, dirty tidak pernah reset.
- **Guard 300ms** mencegah false-positive dirty saat Livewire hydration. Jangan diturunkan terlalu rendah.

### Untuk Tabbed
- **Source of truth di sub-component, bukan wrapper**. Wrapper cuma aggregator dari event `section-dirty`/`section-clean`.
- **Tab read-only/display-only tidak perlu Alpine dirty** — cukup jangan masuk `:tabs` props.
- **Event `section-dirty`/`section-clean` generic** — bukan namespaced, tapi karena Alpine `$dispatch` bubble lewat DOM, hanya wrapper ancestor terdekat yang catch. Jangan campur 2 wrapper bersarang.
- **`saveActive()` cuma dispatch event Livewire** — tidak menunggu konfirmasi save sukses. Reset dirty terjadi saat sub-component dispatch `savedEvent` setelah handler PHP selesai.

---

## 3. Sub-Tab Dirty (Nested) — Child di Dalam Child

### Konteks

Top-tab (di `<x-tabbed-dirty-modal-content>`) yang **isinya punya sub-tab sendiri**, masing-masing sub-tab adalah `<livewire:>` child terpisah dengan save handler & form-entry sendiri (multi-entry append pattern).

Contoh di repo: **penilaian-ri** (sub-tab: nyeri / resikoJatuh / dekubitus / gizi) dan **observasi-ri** (sub-tab: obat-cairan / pengeluaran / oksigen / ttv). Tiap child punya `formEntryX` + save handler `addAssessmentX()`.

### Masalah yang Dipecahkan

Sebelum pattern ini ada, kalau user:
1. Edit sub-tab Nyeri (dirty)
2. Switch ke sub-tab Risiko Jatuh, edit juga
3. Klik Simpan di modal footer → server cuma dispatch save event untuk sub-tab AKTIF (Risiko Jatuh) → **data Nyeri silently hilang** karena tidak ke-save

### Cara Kerja

Decision-nya: **switch sub-tab BEBAS tanpa warning** (UX mulus, data tetap di state child SFC). Tapi saat klik Simpan, server **iterate semua sub-tab yang dirty** dan dispatch save event masing-masing.

```
User edit Nyeri      → subDirty.nyeri = true
User switch ke RJ    → no warning, data Nyeri preserved
User edit RJ         → subDirty.resikoJatuh = true
User klik Simpan     → server iterate subDirty → dispatch save Nyeri + save RJ
                       (Dekubitus & Gizi yang tidak dirty TIDAK di-dispatch)
```

### Implementasi

#### Parent SFC (e.g. `rm-penilaian-ri-actions.blade.php`)

**1. Tambah Livewire property `$subDirty`:**

```php
public string $subTab = 'nyeri';   // sub-tab aktif (sync dgn Alpine)

public array $subDirty = [
    'nyeri' => false,
    'resikoJatuh' => false,
    'dekubitus' => false,
    'gizi' => false,
];
```

**2. Bridge handler — iterate dirty sub-tabs dispatch save event masing-masing:**

```php
#[On('save-active-rm-penilaian-ri')]   // ← saveEvent dari :tabs di emr-ri
public function dispatchActiveSubTabSave(): void
{
    $eventMap = [
        'nyeri' => 'save-rm-penilaian-nyeri-ri',
        'resikoJatuh' => 'save-rm-penilaian-resiko-jatuh-ri',
        'dekubitus' => 'save-rm-penilaian-dekubitus-ri',
        'gizi' => 'save-rm-penilaian-gizi-ri',
    ];

    $targets = array_keys(array_filter($this->subDirty));   // sub-tab yg dirty
    if (empty($targets)) {
        $targets = [$this->subTab];   // fallback: user belum edit apa-apa
    }

    foreach ($targets as $key) {
        if (isset($eventMap[$key])) {
            $this->dispatch($eventMap[$key]);   // ← dispatch ke child masing-masing
        }
    }
}
```

**3. Reset `$subDirty` di resetForm()** (supaya tidak persist saat modal close & reopen):

```php
protected function resetForm(): void
{
    $this->resetVersion();
    // ...
    $this->subDirty = ['nyeri' => false, 'resikoJatuh' => false, 'dekubitus' => false, 'gizi' => false];
}
```

**4. Blade root x-data — Alpine state + markDirty + clean listener:**

```blade
<div class="space-y-4"
    wire:key="..."
    x-data="{
        sectionDirty: false,
        openedAt: 0,
        tab: 'penilaian',            // top-tab key (untuk section-dirty/clean)
        subTab: @entangle('subTab').live,
        subDirty: @entangle('subDirty').live,
        markDirty() {
            if (Date.now() - this.openedAt <= 300) return;
            if (!this.subDirty[this.subTab]) {
                this.subDirty[this.subTab] = true;    // mark sub-tab aktif dirty
            }
            if (!this.sectionDirty) {
                this.sectionDirty = true;
                this.$dispatch('section-dirty', { tab: this.tab });   // top-level dirty
            }
        },
    }"
    x-init="
        openedAt = Date.now();
        window.addEventListener('refresh-after-ri.saved', (e) => {
            const savedSub = e.detail?.subTab;
            if (savedSub && subDirty.hasOwnProperty(savedSub)) {
                subDirty[savedSub] = false;   // clean sub-tab spesifik
            } else {
                subDirty[subTab] = false;
            }
            if (!Object.values(subDirty).some(v => v)) {
                sectionDirty = false;
                openedAt = Date.now();
                $dispatch('section-clean', { tab: tab });   // top-level clean
            }
        });
    "
    x-on:input="markDirty()"
    x-on:change="markDirty()">

    {{-- tab nav buttons: @click="subTab = '...'" --}}
    {{-- <livewire:child-1>, <livewire:child-2>, ... --}}

</div>
```

#### Child SFC (e.g. `rm-penilaian-nyeri-ri-actions.blade.php`)

**1. Listen save event dari parent bridge:**

```php
#[On('save-rm-penilaian-nyeri-ri')]
public function addAssessmentNyeri(): void
{
    // ... validate + lock + transaction + append entry ke array
    $this->afterSave('Penilaian Nyeri berhasil disimpan.');
}
```

**2. afterSave dispatch `refresh-after-ri.saved` dgn payload subTab:**

```php
private function afterSave(string $msg): void
{
    $this->incrementVersion('modal-penilaian-nyeri-ri');
    $this->dispatch('penilaian-ri-saved', riHdrNo: $this->riHdrNo);   // refresh count badge
    $this->dispatch('refresh-after-ri.saved', tab: 'penilaian', subTab: 'nyeri');
    $this->dispatch('toast', type: 'success', message: $msg);
}
```

Payload `subTab` penting supaya parent Alpine bisa clean **sub-tab spesifik** (bukan semua) — penting kalau salah satu child gagal validation, sub-tab itu TETAP dirty.

#### Modal Footer Config

Di emr-ri.blade.php (level `<x-tabbed-dirty-modal-content>`), tambah entry di `:tabs` dengan saveEvent generic yang akan di-handle bridge:

```blade
:tabs="[
    // ...
    ['key' => 'penilaian', 'label' => 'Penilaian Nyeri', 'saveEvent' => 'save-active-rm-penilaian-ri'],
]"
```

Label `'Penilaian Nyeri'` adalah label awal — di-mutate via Alpine `x-effect` ikut sub-tab aktif kalau perlu (lihat emr-ri penilaian-ri parent).

### Trade-off

| | Pattern Sub-Tab Dirty (yang dipakai) | Alternatif: Switch Warning Per Sub-Tab |
|---|---|---|
| UX switch | Mulus, no popup | Confirm dialog "Belum disimpan, simpan dulu?" |
| Data preservation | ✅ tetap di state child SFC | ✅ tetap |
| Wasted save call | ⊘ dirty tab only | ⊘ dirty tab only |
| Implementation complexity | Lower (no modal markup) | Higher (perlu switch-warning modal di parent) |
| Cocok untuk | Form ringan multi-entry | Form berat dgn validation panjang |

### Gotchas

- **`@entangle('subDirty').live` wajib** — bukan default deferred. Server harus tahu state subDirty real-time saat Simpan diklik supaya iterate tepat.
- **Child afterSave WAJIB include `subTab` di payload** — kalau tidak, parent fallback clean sub-tab aktif saja, sub-tab lain tetap dirty.
- **Reset subDirty di parent `resetForm()`** — supaya tidak persist saat modal close & reopen tanpa save.
- **Validation per-child independen** — kalau Nyeri dispatch save & gagal validation, Risiko Jatuh tetap dijalankan (tidak short-circuit). Yang gagal tidak fire `refresh-after-ri.saved` → sub-tab itu tetap dirty di UI.
