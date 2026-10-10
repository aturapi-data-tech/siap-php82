# Pola "Dokumen Multi-Entri EMR RI" (CPPT / SBAR)

Pola untuk dokumen EMR Rawat Inap yang berisi **banyak entri catatan** dalam satu pasien, di mana tiap entri ditulis oleh PPA (profesi klinis) berbeda, lalu di-review/TTD DPJP. Contoh kanonik: **CPPT** dan **SBAR**.

- CPPT: `resources/views/pages/transaksi/ri/emr-ri/cppt-ri/⚡rm-cppt-ri-actions.blade.php`
- SBAR: `resources/views/pages/transaksi/ri/emr-ri/sbar-ri/⚡rm-sbar-ri-actions.blade.php`
- Host modal EMR (footer Simpan + saveMap): `resources/views/pages/transaksi/ri/emr-ri/⚡emr-ri.blade.php`

Gunakan pola ini kalau ingin membuat dokumen EMR RI baru yang: **daftar entri kronologis + form entri tunggal + tab per-profesi + review DPJP + cetak per-entri**. Jangan reinvent — clone salah satu file di atas lalu ganti nama field.

---

## Struktur data (JSON di kolom `datadaftar_ri_json`)

Array di root `data['<namaDokumen>']` (mis. `data['cppt']`, `data['sbar']`), tiap elemen:

```php
[
    '<id>'          => (string) Str::uuid(),   // cpptId / sbarId — identitas entri
    'tgl<Dok>'      => 'd/m/Y H:i:s',           // waktu entri
    'petugas<Dok>'      => 'Nama User',         // snapshot nama penulis
    'petugas<Dok>Code'  => 'myuser_code',       // KUNCI hak edit (owner)
    'profession'    => 'Dokter|Perawat|...',   // dari profesiKlinis() saat entry
    '<isi>'         => [...],                   // soap[] / sbar[] — konten terstruktur
    'fingerprint'   => md5(...),               // anti-dobel (tgl + isi)
    'reviewDpjp'    => ['drId','drName','tglReview'], // OPSIONAL, di-set saat review
]
```

**Prinsip:** `petugas<Dok>Code` = snapshot `myuser_code` penulis → dipakai untuk cek pemilik entri. Nama petugas disnapshot juga (jangan re-lookup saat tampil/cetak).

---

## Enam aksi baku + siapa yang boleh

| Aksi | Method | Hak akses |
|---|---|---|
| **Tambah** | `add<Dok>()` (`#[On('save-rm-<dok>-ri')]`) | siapa saja yang tidak read-only |
| **Edit** | `edit<Dok>()` → `update<Dok>()` | **pemilik entri** (`petugas..Code === myuser_code`) **atau Admin** |
| **Copy ke form** | `copy<Dok>()` | profesi sama / Admin |
| **Hapus** | `remove<Dok>()` | **Supervisor ke atas** (Admin/Manager/Supervisor/Mr/Casemix) — pemilik biasa TIDAK bisa |
| **Review/TTD DPJP** | `review<Dok>Dpjp()` | **HANYA DPJP Utama** (leveling Pengkajian Awal, `levelDokter==='Utama'`) / Admin |
| **Batal review** | `batalReview<Dok>Dpjp()` | sama: DPJP Utama / Admin |
| Cetak per-entri | `print<Dok>()` | (di CPPT/SBAR: disembunyikan utk role Dokter) |

Perhatikan: **hak Edit ≠ hak Hapus**. Edit = pemilik entri, Hapus = supervisor. Ini disengaja.

---

## Pola EDIT (fitur inti — ini yang paling sering diminta menyusul)

State: `public ?string $editing<Dok>Id = null;`

1. **`edit<Dok>($id)`** — guard: `$isFormLocked` → entri ada → `canEdit<Dok>()`. Muat entri ke `formEntry<Dok>` (via `array_merge`), set `editing<Dok>Id`, `resetValidation()`, `incrementVersion()`, `dispatch('<dok>-edit-mode', editing: true)`, toast info.
2. **`canEdit<Dok>($row): bool`** — `Admin` OR (`petugas..Code !== '' && === myuser_code`).
3. **`add<Dok>()` mengalihkan ke `update<Dok>()`** bila `editing<Dok>Id !== null` (dicek paling atas, setelah cek read-only). Jadi **tombol Simpan yang sama** dipakai tambah & perbarui.
4. **`update<Dok>()`** — validasi sama seperti add → `DB::transaction`: `lockRIRow` → **baca-ulang segar** (`findDataRI`) → cari index → **guard ulang `canEdit` terhadap data segar** (anti-bypass state lama) → **perbarui konten saja** (identitas petugas asli DIPERTAHANKAN) → regen `fingerprint` → **`unset($row['reviewDpjp'])`** (konten berubah ⇒ TTD DPJP lama batal) → simpan → `appendAdminLogRI(...'Edit <Dok>...')`. Sukses: reset `editing<Dok>Id` + form, `dispatch('<dok>-edit-mode', editing:false)`, `afterSave()`.
5. **`cancelEdit<Dok>()`** — reset id+form, `dispatch('<dok>-edit-mode', editing:false)`.
6. `copy<Dok>()` & `resetForm()` **wajib** ikut mereset `editing<Dok>Id` (copy = entri baru).

### UI edit
- **Banner indigo** di atas form saat `$editing<Dok>Id` (ikon pensil + "Mengedit … milik <petugas> — ubah lalu klik **Perbarui <Dok>**") + tombol **Batal** (`wire:click="cancelEdit<Dok>"`).
- **Tombol Edit** (pensil, warna indigo) per kartu, gating `$canEdit` (`$isAdmin || ownerCode === myuser_code`). Taruh sebelum tombol Copy.

### Label tombol Simpan di footer host (`emr-ri.blade.php`)
Tombol Simpan modal adalah **satu** tombol Alpine yang membaca `saveMap[activeTab]` dan dispatch `saveEvent` tab aktif. Untuk label "Perbarui":
```blade
x-data="{ cpptEditing: false, sbarEditing: false }"
x-on:cppt-edit-mode.window="cpptEditing = $event.detail?.editing ?? false"
x-on:sbar-edit-mode.window="sbarEditing = $event.detail?.editing ?? false"
x-on:open-modal.window="cpptEditing = false; sbarEditing = false"
...
<span x-show="activeTab === '<dok>' && <dok>Editing" x-cloak>Perbarui <Dok></span>
```
**Dokumen baru → tambah satu flag `<dok>Editing` di sini**, plus listener `<dok>-edit-mode.window`, plus reset di `open-modal.window`, plus satu `<span x-show>`.

---

## Anti-dobel & konkurensi
- **`fingerprint = md5(json_encode([tgl, isi], JSON_UNESCAPED_UNICODE))`** — sebelum insert, cek `first(fn=fingerprint===...)` → tolak duplikat identik.
- Semua tulis (`add/update/remove/review`) DI DALAM `DB::transaction` + **`lockRIRow` lalu `findDataRI` (baca-ulang segar)** — jangan tulis dari `$this->dataDaftarRi` yang mungkin basi. Lihat juga skill `oracle-quirks` (CLOB `datadaftar_*_json`).
- Tiap perubahan → `appendAdminLogRI(riHdrNo, '<aksi> <Dok> — ...', 'MR')` (audit rekam medis).

## Tab per-profesi & render
- `professionTabs = ['Semua','Dokter','Perawat','Apoteker','Gizi','Penunjang']`; tab default dipilih dari `profesiKlinis()` user saat `open()`.
- `getSbarCount()/getCpptCount()` untuk badge angka per tab.
- Warna tab/badge **ditulis literal `match()`** (jangan kelas dinamis → ke-purge Tailwind).
- Re-render pakai `WithRenderVersioningTrait` (`incrementVersion('modal-<dok>-ri')` + `renderKey`). List entri di-`sortByDesc` tanggal (terbaru atas).
- Dirty-tracking modal: `afterSave()` → `dispatch('refresh-after-ri.saved', tab:'<dok>')`; blok `x-data` di root (markDirty/section-dirty) meniru file acuan.

## Cetak per-entri
`print<Dok>($id)` → ambil entri + `findDataMasterPasien(regNo)` → `Pdf::loadView('...cetak-<dok>...', [...])->setPaper('A4')` → `streamDownload`. Header identitas pasien pakai `<x-pdf.identitas-pasien>` (lihat `docs/ttd-pattern-pdf-print.md`).

---

## Checklist bikin dokumen multi-entri baru
1. Clone `rm-cppt-ri-actions.blade.php` (atau sbar) → ganti `cppt`→`<dok>`, `soap`→struktur isi.
2. Pastikan enam aksi + guard hak sesuai tabel di atas (Edit=pemilik, Hapus=supervisor).
3. Daftarkan tab di `emr-ri.blade.php` `saveMap` (`['key','label','saveEvent']`).
4. Kalau ada Edit → tambah flag `<dok>Editing` + listener `<dok>-edit-mode` di footer Simpan.
5. Buat blade cetak + entri di menu tab EMR.
6. Verifikasi Volt: **`?>` tepat 1**, tak ada `?>` dalam string (skill `blade-safe-edit`).

---

## Varian jalur UGD (SBAR UGD)

`resources/views/pages/transaksi/ugd/emr-ugd/sbar/⚡rm-sbar-ugd-actions.blade.php` — hasil port SBAR RI ke UGD.
Struktur data, enam aksi, guard hak, tab per-profesi, dan cetak per-entri **sama persis**. Yang berbeda hanya
karena jalur UGD memang lain:

| Aspek | RI | UGD |
| --- | --- | --- |
| Sumber data | `datadaftar_ri_json` (`riHdrNo`), `EmrRITrait` | `datadaftarugd_json` (`rjNo`), `EmrUGDTrait` |
| Node JSON | root `sbar[]` | root `sbar[]` (sama) |
| Event | `open-rm-sbar-ri` / `save-rm-sbar-ri` | `open-rm-sbar-ugd` / `save-rm-sbar-ugd` |
| DPJP (yang boleh review/TTD) | DPJP **Utama** dari leveling Pengkajian Awal | **dokter kunjungan UGD** (`dataDaftarUGD['drId']`) |
| Tombol Simpan | footer host `emr-ri.blade.php` (tab-aware + label "Perbarui SBAR") | **tombol milik komponen** (Tambah/Perbarui SBAR) — footer EMR UGD hanya menyimpan SOAP, tidak tab-aware |
| Dirty tracking | `refresh-after-ri.saved` ber-`tab:` (per-section) | dirty global modal; komponen **tidak** memancarkan `refresh-after-ugd.saved` (biar draft tab lain tak ikut ter-reset), hanya mendengarnya untuk memuat ulang bila state ter-wipe |
| Cetak | `pages.components.rekam-medis.ri.cetak-sbar.cetak-sbar-ri-print` (Ruang/Kelas) | `pages.components.rekam-medis.ugd.cetak-sbar.cetak-sbar-ugd-print` (Unit UGD/IGD + dokter, Tgl. Masuk UGD) |

Kalau mem-port dokumen multi-entri lain ke UGD/RJ, ikuti tabel ini: yang wajib diganti cuma trait + event +
sumber DPJP + mekanisme tombol simpan.
