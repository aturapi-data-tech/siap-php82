# Indeks Skill (`.claude/skills/`)

Katalog **skill** repo ini — instruksi terpaket yang dibaca AI (dan bisa dibaca manusia) sebelum
mengerjakan tipe tugas tertentu, supaya konsisten dengan pola & jebakan yang sudah dipetakan.
File tiap skill: `.claude/skills/<nama>/SKILL.md`. Panggil dengan `/<nama>`.

> Beda **skill** vs **docs/**: `docs/*.md` = referensi pola/arsitektur (dokumen). **Skill** =
> pembungkus "baca-dulu-sebelum-X" yang sering **menunjuk** ke docs terkait. Banyak skill = docs +
> aturan keras + kapan-wajib-dibaca.

Total: **6 skill**.

---

## 1. Keselamatan edit & konvensi kode

| Skill | Cakupan | Baca saat |
|---|---|---|
| **blade-safe-edit** | Aturan aman mengedit `*.blade.php` / Volt: dilarang regex multiline, verifikasi balance tag, jebakan compiler Volt (`?>`/`use`/`reuse`), escape ganda prop komponen. | Sebelum edit bulk / sed / regex pada Blade, atau edit banyak file sekaligus. |
| **naming-conventions** | Standar penamaan variable/method (camelCase Indonesia, hindari singkatan), singkatan modul yang "dipesan" (`rj`/`ri`/`ugd`/`rm`), aturan `use` vs FQCN di Volt. | Sebelum menulis kode PHP/Livewire/Volt baru, menamai variable domain, atau import class. |

## 2. Database & query

| Skill | Cakupan | Baca saat |
|---|---|---|
| **oracle-quirks** | Gotcha Oracle (Laravel + Oracle Dev 6i hybrid): `'' = NULL`, `JSON_VALUE` tak didukung, kolom mixed-case, `active_status` `'1'/'0'`, lookup shift, filter wilayah (`kab_id`). | Sebelum menulis/men-debug query DB, hasil query kosong tak terduga, ORA-00904. |

## 3. Livewire / UI

| Skill | Cakupan | Baca saat |
|---|---|---|
| **livewire-input-patterns** | Pola input Livewire/Alpine teruji: `wire:model.blur` auto-calc, `x-text-input-number`, Enter→`$wire` race, `x-now-button`, search input "mental", **persist filter antar tab (`#[Session]`)**. | Menambah/men-debug input numerik EMR, aksi Enter, filter list ber-tab. |
| **livewire-render-versioning** | Aturan `wire:key` berversi (`WithRenderVersioningTrait` / `renderKey`) saat ada komponen Livewire bersarang di dalamnya. | Sebelum menambah `renderKey`/`incrementVersion` pada pembungkus, atau saat muncul "Snapshot missing on Livewire component". |
| **ui-pattern-docs** | Indeks pola UI di `docs/` (tombol, modal, tab, page-frame, cetak PDF/TTD, editor, list stabil, wrapper hub). | Sebelum membuat komponen UI baru — cek dulu polanya sudah ada. |

---

## Cara memakai
- AI: panggil `Skill` dengan nama (mis. `/oracle-quirks`) — instruksi termuat ke konteks.
- Manusia: buka `.claude/skills/<nama>/SKILL.md`.
- Menambah skill baru: buat folder `.claude/skills/<nama>/SKILL.md` (frontmatter `name` + `description`
  yang menyebut **kapan wajib dibaca**), lalu tambahkan barisnya ke tabel di atas.

> Referensi silang: banyak skill menunjuk ke `docs/` — lihat [ui-pattern-docs](../.claude/skills/ui-pattern-docs/SKILL.md)
> untuk indeks pola UI, dan [standar-ui-komponen.md](standar-ui-komponen.md) untuk standar komponen.
