# Standar UI & Komponen

Panduan standarisasi tampilan dan penggunaan komponen Blade di seluruh aplikasi SIRUS.

> 📚 Lihat juga **[Indeks Skill](skills-index.md)** — katalog 12 skill repo (aturan "baca-dulu-sebelum-X"
> per tipe tugas: blade-safe-edit, naming-conventions, modul-dokumen, oracle-quirks, dll).

---

## 1. Struktur Modal (x-modal)

Semua modal full-screen mengikuti pola 3 bagian: **Header**, **Body**, **Footer**.

### Header

```blade
<div class="relative px-6 py-5 border-b border-gray-200 dark:border-gray-700">
    {{-- Dot pattern background --}}
    <div class="absolute inset-0 opacity-[0.06] dark:opacity-[0.10]"
        style="background-image: radial-gradient(currentColor 1px, transparent 1px); background-size: 14px 14px;">
    </div>
    <div class="relative flex items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                {{-- Ikon modul --}}
                <div class="flex items-center justify-center w-10 h-10 rounded-xl bg-brand-green/10">
                    ...
                </div>
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                        {{ $formMode === 'edit' ? 'Ubah Data ...' : 'Tambah Data ...' }}
                    </h2>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">Deskripsi singkat.</p>
                </div>
            </div>
            <div class="flex gap-2 mt-3">
                <x-badge :variant="$formMode === 'edit' ? 'warning' : 'success'">
                    {{ $formMode === 'edit' ? 'Mode: Edit' : 'Mode: Tambah' }}
                </x-badge>
            </div>
        </div>

        {{-- Close X --}}
        <x-icon-button color="gray" type="button" wire:click="closeModal">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                    clip-rule="evenodd" />
            </svg>
        </x-icon-button>
    </div>
</div>
```

### Body

```blade
<div class="flex-1 px-4 py-4 bg-gray-50/70 dark:bg-gray-950/20">
    <div class="max-w-full mx-auto">
        {{-- Content menggunakan x-border-form --}}
    </div>
</div>
```

### Footer

```blade
<div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-white border-t border-gray-200 dark:bg-gray-900 dark:border-gray-700">
    <div class="flex items-center justify-between gap-3">
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Pastikan data sudah benar sebelum menyimpan.
        </p>
        <div class="flex gap-2">
            <x-secondary-button type="button" wire:click="closeModal">Batal</x-secondary-button>
            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove>Simpan</span>
                <span wire:loading><x-loading /> Menyimpan...</span>
            </x-primary-button>
        </div>
    </div>
</div>
```

### Footer dengan Navigasi (Transaksi)

```blade
<div class="sticky bottom-0 z-10 px-6 py-4 bg-white border-t border-gray-200 dark:bg-gray-900 dark:border-gray-700">
    <div class="flex justify-between gap-3">
        {{-- Kiri: navigasi --}}
        <a href="{{ route('master.pasien') }}" wire:navigate>
            <x-ghost-button type="button">
                <svg>{{-- ikon user --}}</svg>
                Master Pasien
            </x-ghost-button>
        </a>
        {{-- Kanan: batal + simpan --}}
        <div class="flex gap-3">
            <x-secondary-button wire:click="closeModal">Batal</x-secondary-button>
            <x-primary-button wire:click.prevent="save()" class="min-w-[120px]"
                wire:loading.attr="disabled" :disabled="$isFormLocked">
                <span wire:loading.remove>Simpan</span>
                <span wire:loading><x-loading /> Menyimpan...</span>
            </x-primary-button>
        </div>
    </div>
</div>
```

---

## 2. Form Section (`<x-border-form>`)

Gunakan `<x-border-form>` untuk mengelompokkan field dalam card. Jangan buat card manual dengan `<div class="bg-white border...">` + `<h3>`.

```blade
{{-- Satu section --}}
<x-border-form title="Data Dokter">
    <div class="space-y-4">
        {{-- fields --}}
    </div>
</x-border-form>

{{-- Dua kolom --}}
<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <x-border-form title="Data Dokter">
        <div class="space-y-4">
            {{-- fields kolom kiri --}}
        </div>
    </x-border-form>

    <x-border-form title="Tarif & Administrasi">
        <div class="space-y-4">
            {{-- fields kolom kanan --}}
        </div>
    </x-border-form>
</div>
```

**Props:**

| Prop | Default | Keterangan |
|------|---------|------------|
| `title` | `''` | Judul section (tampil di header card) |
| `align` | `start` | Alignment judul: `start` / `center` / `end` |
| `bgcolor` | `bg-white` | Warna background card |
| `class` | `''` | Class tambahan (misal `max-w-xl`) |
| `padding` | `p-4` | Padding content area |

**Komponen sudah handle:** `border`, `rounded-2xl`, `shadow-sm`, `dark:bg-gray-900`, header dengan `bg-gray-50` + `border-b`.

**Jangan lakukan:**
```blade
{{-- JANGAN: card manual + h3 --}}
<div class="p-5 bg-white border border-gray-200 shadow-sm rounded-2xl dark:bg-gray-900 dark:border-gray-700">
    <h3 class="text-sm font-semibold ...">Data Dokter</h3>
    ...
</div>

{{-- LAKUKAN: pakai x-border-form --}}
<x-border-form title="Data Dokter">
    ...
</x-border-form>
```

---

## 3. Halaman Tabel Master (List Page)

Pola standar untuk halaman daftar data master.

### Toolbar

```blade
<div class="sticky z-30 px-4 py-3 bg-white border-b border-gray-200 top-20 dark:bg-gray-900 dark:border-gray-700">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div class="w-full lg:max-w-md">
            <x-text-input type="text" wire:model.live.debounce.300ms="searchKeyword"
                placeholder="Cari..." class="block w-full" />
        </div>
        <div class="flex items-center justify-end gap-2">
            <div class="w-28">
                <x-select-input wire:model.live="itemsPerPage">
                    <option value="10">10</option>
                    <option value="20">20</option>
                </x-select-input>
            </div>
            <x-primary-button type="button" wire:click="openCreate">
                + Tambah Data
            </x-primary-button>
        </div>
    </div>
</div>
```

### Tombol Aksi di Baris Tabel

```blade
<td class="px-4 py-3">
    <div class="flex flex-wrap gap-2">
        {{-- Edit --}}
        <x-secondary-button type="button"
            wire:click="openEdit('{{ $row->id }}')" class="px-2 py-1 text-xs">
            Edit
        </x-secondary-button>

        {{-- Hapus --}}
        <x-confirm-button variant="danger"
            :action="'requestDelete(\'' . $row->id . '\')'"
            title="Hapus Data"
            :message="'Yakin hapus ' . $row->name . '?'"
            confirmText="Ya, hapus" cancelText="Batal"
            class="px-2 py-1 text-xs">
            Hapus
        </x-confirm-button>
    </div>
</td>
```

**Aturan tabel master:**
- Edit selalu `<x-secondary-button>` + `class="px-2 py-1 text-xs"`
- Hapus selalu `<x-confirm-button variant="danger">` + `class="px-2 py-1 text-xs"`
- Jangan pakai `x-outline-button` untuk Edit di tabel
- Jangan pakai `x-danger-button` + `wire:confirm` untuk Hapus

---

## 3b. Kolom Pasien di List Transaksi — namespace `x-list.*`

Komponen baris tabel/list **layar** dikumpulkan di `resources/views/components/list/`
→ dipakai sebagai `<x-list.*>`. Namespace ini **paralel** dgn `<x-pdf.*>` (versi cetak):
`x-list.*` = tampilan list layar, `x-pdf.*` = cetak. Jangan buat markup identitas/SEP
inline lagi di halaman — pakai komponen ini supaya satu perubahan berlaku semua list.

### `<x-list.identitas-pasien>` — blok identitas pasien (layar)

Acuan tampilan: `transaksi/rj/pelayanan-rj`. Urutan baku 4 baris:
**No RM → Nama + ikon gender → tgl lahir (umur) → alamat**.

```blade
<x-list.identitas-pasien :regNo="$row->reg_no" :nama="$row->reg_name" :sex="$row->sex"
    :tglLahir="$row->birth_date" :alamat="$row->address" :collapseUmur="true" />
```

- **Umur SELALU dihitung dari `:tglLahir`** (birth_date, format d/m/Y) DI DALAM komponen —
  satu format `X Thn Y Bln Z Hr`, dijamin fresh (bukan kolom snapshot thn/bln/hari).
  Jangan hitung umur di halaman lalu oper `:umur` — biarkan komponen. `:umur` hanya
  override opsional.
- **Gender = simbol**: `♂` biru (L) / `♀` rose (P), bukan teks; `title`/`aria-label` utk aksesibilitas.
- `:collapseUmur="true"` → baris tgl-lahir/umur ikut toggle Alpine `expanded` (list yg punya
  detail: daftar/pelayanan RJ-UGD). Default `false` = umur selalu tampil.
- **Slot** (opsional) utk baris tambahan spesifik halaman (mis. `Masuk: ...` di kasir,
  badge jenis resep di apotek): taruh di antara tag buka/tutup, dirender setelah alamat.
- Class wrapper default `space-y-0 leading-tight`; JANGAN oper `class="space-y-1"` (konflik
  precedence Tailwind). Oper hanya `class="min-w-0"` bila perlu.
- Beda dari `<x-pdf.identitas-pasien>` (cetak, berbasis `<table>`).

### `<x-list.identitas-aksi>` — kepala menu aksi (tombol titik-tiga)

Anak pertama isi dropdown aksi di list transaksi — **8 halaman**: Pelayanan RJ/UGD,
Daftar RJ/UGD/RI, dan tiga list bulanan yang jadi tab pasien Casemix (Daftar RJ/UGD/RI
Bulanan). Isinya:
**No. RM → Nama + ikon gender → badge jalur**. Menjawab satu pertanyaan — menu ini berlaku
untuk pasien yang mana.

```blade
<x-slot name="content">
    <div class="p-2 space-y-2">
        <x-list.identitas-aksi :regNo="$row->reg_no" :nama="$row->reg_name" :sex="$row->sex"
            jalur="Rawat Jalan" />
        …butir menu…
```

- Nama pasien disebut **SEKALI di kepala menu**, JANGAN diulang di tiap butir. Pola lama
  `Administrasi<br><span class="font-semibold">{{ $row->reg_name }}</span>` dibuang: dulu
  hanya 6 dari 21 butir memakainya, sehingga nama muncul-hilang tanpa pola. Butir menu
  cukup berisi nama aksinya (subjudul statis seperti `Formulir & Consent` tetap boleh).
- `jalur` opsional: `Rawat Jalan` | `UGD` | `Rawat Inap` — tampil sebagai badge di kanan.
- Ringkas 2 baris karena hidup di dalam dropdown. Untuk kolom PASIEN pakai
  `<x-list.identitas-pasien>` (4 baris + alamat), untuk cetak `<x-pdf.identitas-pasien>`.
- Ikon gender sengaja disamakan dgn `<x-list.identitas-pasien>` (♂ biru / ♀ rose).
- Variabel baris tidak selalu `$row` — `daftar-ri-bulanan` pakai `$r`. Sesuaikan saat menyalin.
- BELUM baku (masih pola lama): `ri/gizi-ri` dan `penunjang/kamar-operasi`. Kasir &
  Administrasi RJ/UGD/RI tidak punya menu titik-tiga sama sekali — aksinya tombol langsung.

### `<x-list.sep-spri>` — nomor SEP & SPRI

```blade
<x-list.sep-spri :sep="$row->vno_sep" :spri="$row->no_spri" />
```

- **SEP** → `font-mono text-xs text-emerald-600 dark:text-emerald-400` (hijau, non-bold).
- **SPRI** → `font-mono text-xs text-purple-600 dark:text-purple-400` (ungu). SPRI = Surat
  Perintah Rawat **Inap**, hanya relevan jalur RI; di RJ/UGD cukup oper `:sep` saja.
- Tampil hanya bila ada nilainya; `'-'`/kosong disembunyikan.
- Pakai di **kolom SEP list**. JANGAN pakai di modal detail / header EMR (`display-pasien-*`)
  / pesan status casemix — itu konteks lain.

### `<x-list.klaim-badge>` — cara bayar / klaim

```blade
<x-list.klaim-badge :status="$row->klaim_status" :desc="$row->klaim_desc" :id="$row->klaim_id" />
```

- **Data tetap dari MODEL KLAIM** (`rsmst_klaimtypes`): label = `klaim_desc` asli (mis. "JKN MANDIRI"),
  **BUKAN** disederhanakan jadi "UMUM/BPJS".
- **Warna dari `klaim_status`** (kategori) — robust untuk SEMUA jenis klaim:
  `BPJS → success` · `UMUM → alternative` · `KRONIS → warning` · `DOKEL → purple` · lainnya `→ gray`.
  (Jangan match `klaim_id` — jenis lain seperti Jasa Raharja/BPJS-TK jatuh ke default yang salah.)
- Label format **"KATEGORI · desc"** (mis. "BPJS · JKN MANDIRI"); dedupe bila desc = kategori
  (jadi "UMUM", "KRONIS").
- Query WAJIB select `klaim_status`, `klaim_desc`, `klaim_id` (join `rsmst_klaimtypes`).
- Pakai di **kolom cara bayar semua list** (pelayanan/daftar/kasir/apotek/bulanan). JANGAN untuk
  filter toolbar (opsi BPJS/UMUM di `<select filterKlaim>` itu FILTER, bukan display).

---

## 4. Input Harga / Tarif (`<x-text-input-number>`)

Semua field yang berisi nominal uang (harga, tarif, gaji, biaya) **wajib** menggunakan `<x-text-input-number>`.

```blade
<x-text-input-number wire:model="basicSalary"
    :error="$errors->has('basicSalary')"
    class="w-full mt-1"
    x-ref="inputBasicSalary"
    x-on:keydown.enter.prevent="$refs.nextField?.focus()" />
```

**Fitur otomatis:**
- Format ribuan saat display (999,999)
- Hapus format saat focus (user ketik angka biasa)
- Sync integer bersih ke Livewire saat blur via `$wire.set()`
- `inputmode="numeric"` untuk keyboard mobile
- Alignment kanan (`text-right`) + `tabular-nums`
- **Posisi kursor dipertahankan saat menyunting di tengah angka** (lihat di bawah)

**JANGAN sederhanakan handler `x-on:input`-nya.**

Handler itu terlihat bertele-tele, tapi setiap barisnya perlu. Format ribuan menuntut
`$el.value` ditulis ulang tiap ketikan, dan menulis `.value` **selalu** melempar caret ke
ujung. Akibatnya menyunting angka yang sudah terisi jadi kacau:

| Isi | Aksi | Tanpa perbaikan | Seharusnya |
|-----|------|-----------------|------------|
| `12,000` | hapus digit `2` di tengah | kursor lompat ke ujung → ketikan berikutnya bikin `10,002` | `1,000`, kursor tetap di tengah |

Solusinya BUKAN menyimpan posisi karakter — posisi itu bergeser sendiri saat koma
bertambah/berkurang. Yang disimpan **berapa DIGIT ada sebelum kursor**, lalu setelah
format ulang kursor dikembalikan ke posisi setelah digit ke-N yang sama:

```js
const digitSebelumKursor = $el.value.slice(0, $el.selectionStart).replace(/\D/g, '').length;
const terformat = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
$el.value = terformat;
// cari indeks tepat setelah digit ke-N, lalu setSelectionRange
```

Berlaku untuk SEMUA input nominal di aplikasi — komponen ini dipakai lintas modul
(master dokter saja punya 77 input).

**Field berdesimal — sebutkan `:decimals`:**

```blade
{{-- persen, PPN — 2 angka di belakang koma --}}
<x-text-input-number wire:model="pph21Persen" :decimals="2" />
```

Bawaannya `decimals="0"` = integer, persis perilaku lama. Tanpa prop ini input
di-strip `/\D/g` sehingga titik desimal ikut terbuang dan `7.5` tersimpan sebagai
`75` — sepuluh kali lipat. Prop ini ditambahkan 2026-08-02 setelah tujuh tempat di
aplikasi (PPN penerimaan medis & non-medis, PPN master identitas, persen potongan RS
& PPh 21 di modul gaji) mengulang akal-akalan `<x-text-input inputmode="decimal">`
yang kehilangan pemisah ribuan, rata kanan, dan font tabular.

Pada mode desimal, "karakter penting" yang dihitung untuk menjaga kursor mencakup
titik desimal, bukan hanya digit — kalau tidak, kursor yang berada tepat sesudah
titik akan melompat ke sebelum titik. Mode integer diuji berpasangan terhadap
algoritma lama pada 126 kombinasi input × posisi kursor: **nol perbedaan**.

**Jangan lakukan:**
```blade
{{-- JANGAN: text-input biasa untuk harga --}}
<x-text-input wire:model="price" type="number" />

{{-- JANGAN: wrapper Rp manual --}}
<div class="relative">
    <span class="absolute ...">Rp</span>
    <x-text-input wire:model="price" class="pl-10" />
</div>

{{-- LAKUKAN: --}}
<x-text-input-number wire:model="price" />
```

**Catatan:** `wire:model.live` tidak dipakai — komponen sync via `$wire.set()` saat blur. Gunakan `wire:model` (tanpa `.live`).

---

## 5. Komponen Tombol

Lihat [standar-komponen-tombol.md](standar-komponen-tombol.md) untuk panduan lengkap penggunaan tombol.

### Ringkasan Cepat

| Komponen | Warna | Kegunaan |
|----------|-------|----------|
| `x-primary-button` | Hijau solid | Simpan, Submit (1 per modal) |
| `x-secondary-button` | Abu-abu | Batal, Edit (di tabel) |
| `x-outline-button` | Tint hijau + border | Tab navigasi |
| `x-ghost-button` | Tint tipis + border | Link navigasi (Master Pasien) |
| `x-icon-button` | Transparan kotak | Close X, Cetak (ikon saja) |
| `x-info-button` | Biru solid | BPJS / SEP |
| `x-success-button` | Lime solid | Serah obat |
| `x-danger-button` | Merah solid | Hapus ringan (dalam form) |
| `x-confirm-button` | Multi-variant | Hapus penting + dialog konfirmasi |
| `x-warning-button` | Kuning solid | Aksi perlu perhatian |

---

## 6. Komponen Tab

Lihat [tabs-pattern.md](tabs-pattern.md) untuk panduan lengkap `x-tabs` / `x-tab` (4 varian, mode server vs Alpine, warna per modul).

### Ringkasan Cepat

```blade
{{-- server (Livewire) --}}
<x-tabs variant="underline">
    <x-tab :active="$activeTab === 'rj'" color="emerald" wire:click="setTab('rj')">Rawat Jalan</x-tab>
</x-tabs>

{{-- Alpine (instan, @entangle) --}}
<x-tabs variant="underline">
    <x-tab active-expr="tab === 'rj'" x-on:click="tab = 'rj'">Rawat Jalan</x-tab>
</x-tabs>
```

> **Halaman Wrapper Hub ("model kasir")** — kompose beberapa komponen standalone (yang punya
> route sendiri) ke satu layar via tab; wrapper tipis (hanya `$activeTab` + `setTab()`), nol
> logika bisnis. Pilih **Alpine `x-show`** untuk anak ringan (semua mounted, state terjaga —
> mis. modul-dokumen) vs **server `@if`** untuk anak berat/`wire:poll` (lazy 1 anak — mis.
> apotek/kasir/casemix). Konsekuensi server-`@if`: anak di-unmount/remount saat ganti tab →
> `mount()` jalan lagi → **filter ter-reset**. Persist dengan `#[Session]` per properti + guard
> `mount()` (`$this->x = $this->x ?: default`); JANGAN guard `resetFilters()`. Detail:
> [tabs-pattern.md](tabs-pattern.md) §"Halaman Wrapper Hub" & §"Persist state anak" + skill
> `livewire-input-patterns` §9.

---

## 7. Tanda Tangan Petugas (`<x-signature.ttd-petugas>`)

TTD **di layar/form entry** (stamp nama user login + tgl). Jangan tulis blok "TTD Saya" inline lagi. Panduan lengkap: [ttd-petugas-component.md](ttd-petugas-component.md). (Untuk TTD di **cetakan PDF** lihat [ttd-pattern-pdf-print.md](ttd-pattern-pdf-print.md).)

### Ringkasan Cepat

```blade
{{-- default (framed, subtitle "Petugas (Penanda-tangan)") --}}
<x-signature.ttd-petugas :ttd="$newForm['ttd']" :date="$newForm['ttdDate'] ?? ''" :locked="$isFormLocked" />

{{-- judul spesifik + simpan kode (utk gambar TTD di cetak), tanpa subtitle --}}
<x-signature.ttd-petugas :ttd="$newForm['operatorTtd']" :date="$newForm['operatorTtdDate'] ?? ''"
    :code="$newForm['operatorTtdCode'] ?? ''" :locked="$isFormLocked"
    sign="setOperatorTtd" clear="clearOperatorTtd"
    title="Tanda Tangan Operator" label="" signLabel="TTD sebagai Operator" clearLabel="Hapus TTD" />

{{-- tanpa bingkai (rata kiri) utk grid-cell --}}
<x-signature.ttd-petugas :framed="false" ... />
```

Induk **wajib** sediakan method `sign`/`clear` (default `ttdSaya`/`hapusTtd`) dengan **guard `$isFormLocked` server-side** + simpan `ttdCode` (myuser_code). **Gotcha:** jangan taruh `<x-...>` di komentar file komponen → runtime `Undefined variable $component`.

---

## 8. Combobox (`<x-combobox>`)

Semua combobox ketik-saring memakai **satu** basis: `resources/views/components/combobox.blade.php`.
Pemakai tidak memanggilnya langsung, melainkan lewat pembungkus yang membawa sumber datanya:

| Pembungkus | Sumber | Yang disimpan |
|---|---|---|
| `<x-catatan-signa-combobox>` | `rsmst_signa_catatans` (dikirim induk) | teks |
| `<x-ppa-combobox>` | `users.myuser_name` | teks |
| `<x-rekonsiliasi-obat-combobox>` | `immst_products` (cache 30 mnt) | teks |
| `<x-ruangan-combobox>` | `rsmst_rooms` / `rsmst_polis` (cache 30 mnt) | teks **+ id** kalau cocok master |

### Aturan pokok: yang diketik ADALAH nilainya

Daftar itu bantuan ketik, **bukan pagar**. Isian di luar daftar tetap sah dan tersimpan apa
adanya — obat bawaan pasien dari luar RS, catatan signa tak baku, ruangan yang belum
terdaftar di master. Komponen **tidak pernah** mengubah, mengembalikan, atau menghapus
ketikan petugas. Nilainya diikat `wire:model` langsung di input.

`wire-model-id` menambah satu hal saja: selama teks di kotak **cocok persis** dengan salah
satu baris daftar, id-nya ikut disimpan; begitu teksnya menyimpang, id dikosongkan. Jadi id
itu bonus (tautan ke master kalau kebetulan cocok), bukan syarat, dan tak pernah ada id basi
yang menunjuk baris lain dari yang tertulis.

> **Konsekuensi untuk induk:** yang diwajibkan di `rules()` harus **teksnya**, bukan id-nya.
> Form Pindah Antar Ruang RI dulu mewajibkan `keRoomId` — itu diam-diam melarang ruangan yang
> belum terdaftar, jadi sekarang yang wajib `keRoomDesc`. Kalau butuh id yang **dijamin** ada
> (mis. memesan bed), itu pekerjaan `<livewire:lov.room.lov-room>`, bukan komponen ini.

### Enter

Enter **tidak** memilih baris kecuali ada yang tersorot lewat panah/hover — ia menjalankan
`enter-action`, karena yang diketik sudah jadi nilainya. Tab sama: mengambil baris yang
tersorot (kalau ada) lalu fokus tetap lanjut ke isian berikutnya.

**JANGAN menyalakan sorot-otomatis pada baris teratas.** Terdengar membantu, tapi ia MEREBUT
Enter dari `enter-action` di 6 form (rekonsiliasi obat RI/UGD, pelaporan ESO RI/UGD,
pengkajian dokter RI) yang memakai Enter untuk **menambah baris**.

### Enter di form entri

Konvensi repo: **semua isian dalam satu baris entri memakai Enter untuk aksi commit yang sama.**
Combobox lewat `enter-action`, input biasa lewat `wire:keydown.enter.prevent`.

```blade
{{-- baris entri: Enter = tambah baris --}}
<x-rekonsiliasi-obat-combobox wire-model="formEntryRekonsiliasi.namaObat"
    enter-action="$wire.addRekonsiliasiObat()" :error="$errors->has('formEntryRekonsiliasi.namaObat')" />
<x-text-input wire:model="formEntryRekonsiliasi.dosis" wire:keydown.enter.prevent="addRekonsiliasiObat" />

{{-- form pindah/transfer: Enter = simpan entri; nama jadi nilai, id ikut kalau cocok --}}
<x-ruangan-combobox wire-model="newPindah.keRoomId" wire-model-nama="newPindah.keRoomDesc"
    enter-action="$wire.save()" :error="$errors->has('newPindah.keRoomDesc')" />
```

### Lain-lain

- Dropdown wajib tetap `wire:ignore` — lihat komentar di komponennya.
- `pick()` mengembalikan fokus ke kotak; jangan menambah pemanggilan `pick()` dari `blur`,
  karena fokus yang ditarik balik dari `blur` bikin Tab selamanya mental ke kotak itu.

## Aturan Umum

1. **Jangan pakai `!important` override** — pilih komponen yang tepat
2. **Jangan pakai `wire:confirm`** (browser native) — pakai `<x-confirm-button>`
3. **Jangan buat card manual** untuk form section — pakai `<x-border-form>`
4. **Jangan pakai `x-text-input` untuk harga** — pakai `<x-text-input-number>`
5. **Satu `<x-primary-button>` per modal** — hanya untuk aksi utama
6. **Close X selalu `<x-icon-button color="gray">`**
7. **Body modal selalu `px-4 py-4 bg-gray-50/70`** — jangan variasikan padding
8. **Jangan tulis blok TTD "TTD Saya" inline** — pakai `<x-signature.ttd-petugas>`


## Tombol aksi per entri di tabel (BAKU 2026-09-08)

Tinggi semua tombol aksi = **40px**: tombol berteks (`x-primary/secondary/outline/confirm-button`)
memakai padding bawaan `px-5 py-2.5`; tombol ikon memakai `p-2.5` + ikon `w-5 h-5`.
**Jangan** menambah `px-2 py-1 text-xs` atau `!py-1` pada tombol aksi baris tabel.

| Aksi | Komponen | Catatan |
|---|---|---|
| Lihat per entri | `<x-lihat-button wire:click="viewEntry(…)" />` | ikon mata abu-abu; `label="…"` bila perlu teks |
| Cetak per entri | `<x-cetak-button wire:click="cetak(…)" />` | ikon printer biru; `label="…"` bila perlu teks (Cetak E-Resep, Etiket) |
| Hapus per entri | `<x-hapus-button wire:click.prevent="hapus(…)" confirm="…" />` | dialog browser (wire:confirm) |
| Hapus, dialog modal | `<x-hapus-button :action="'removeBaris(' . $i . ')'" title="…" :message="…" />` | dirender lewat `x-confirm-button` varian danger-soft |
| Buka Kunci / konfirmasi lain | `<x-confirm-button action="…">` | ukuran bawaan; teks tak patah baris (`whitespace-nowrap` bawaan) |
| Menu titik-3 | `<x-secondary-button class="p-2.5">` + ikon 20px | dulu `p-2` = 36px |

Sel `<td>` Aksi diberi `whitespace-nowrap` supaya "Lanjutkan Pengisian"/"Buka Kunci" tidak patah dua baris.
Yang sengaja di luar aturan ini: chip toggle (`x-ghost-button` `!py-0.5`), tanda × pembuang kode iDRG,
baris edit-inline administrasi (Lain-lain RJ/UGD), dan X penutup header modal (`x-icon-button` bawaan).
