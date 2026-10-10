---
name: emr-multi-entry-document
description: Pola dokumen multi-entri EMR Rawat Inap (CPPT & SBAR) — banyak catatan per pasien, ditulis PPA berbeda, tab per-profesi, di-review/TTD DPJP Utama, dengan aksi baku Tambah/Edit/Copy/Hapus/Review + cetak per-entri. WAJIB dibaca sebelum membuat dokumen EMR RI baru yang mirip konsep ini, atau saat menambah fitur Edit/Review/Copy pada CPPT/SBAR. Menjaga konsistensi hak akses (Edit=pemilik entri, Hapus=supervisor) & pola konkurensi.
---

# Dokumen Multi-Entri EMR RI (CPPT / SBAR)

Acuan lengkap: **`docs/emr-multi-entry-document-pattern.md`** — baca sebelum implementasi.

## Kapan skill ini relevan
- Membuat dokumen EMR Rawat Inap baru yang berisi **banyak entri kronologis** dari profesi berbeda (mirip CPPT/SBAR).
- Menambah fitur **Edit / Review DPJP / Copy-ke-form** pada dokumen semacam ini.
- File acuan kanonik:
  - `resources/views/pages/transaksi/ri/emr-ri/cppt-ri/⚡rm-cppt-ri-actions.blade.php`
  - `resources/views/pages/transaksi/ri/emr-ri/sbar-ri/⚡rm-sbar-ri-actions.blade.php`
  - Host modal + footer Simpan: `resources/views/pages/transaksi/ri/emr-ri/⚡emr-ri.blade.php`

## Aturan yang paling sering keliru (ingat ini)
1. **Hak Edit ≠ hak Hapus.** Edit = **pemilik entri** (`petugas<Dok>Code === myuser_code`) atau Admin. Hapus = **Supervisor ke atas** (Admin/Manager/Supervisor/Mr/Casemix), pemilik biasa tidak bisa. Review/TTD = **HANYA DPJP Utama** (leveling Pengkajian Awal) / Admin.
2. **Edit pakai tombol Simpan yang sama.** `add<Dok>()` mengalihkan ke `update<Dok>()` bila `editing<Dok>Id !== null`. Jangan bikin tombol simpan kedua.
3. **Guard hak dua lapis:** saat klik Edit (state) **dan** saat `update()` (baca-ulang segar dalam transaksi) — anti-bypass.
4. **Edit mencabut review DPJP:** `unset($row['reviewDpjp'])` karena isi berubah ⇒ TTD lama batal. Regen `fingerprint`.
5. **Identitas petugas tidak berubah saat edit** — hanya konten + tgl.
6. **Konkurensi:** semua tulis di `DB::transaction` → `lockRIRow` → `findDataRI` (baca-ulang segar), jangan tulis dari state basi. `appendAdminLogRI(...,'MR')` tiap perubahan.
7. **Footer Simpan (`emr-ri.blade.php`):** dokumen baru ber-Edit → tambah flag Alpine `<dok>Editing`, listener `<dok>-edit-mode.window`, reset di `open-modal.window`, dan `<span x-show>` label "Perbarui <Dok>".
8. **Copy & resetForm wajib mereset `editing<Dok>Id`.**

## Cara tercepat
Clone salah satu file acuan, ganti `cppt`/`sbar` → nama dokumen baru, sesuaikan struktur isi (`soap`/`sbar` → milik dokumen), lalu ikuti **Checklist** di akhir `docs/emr-multi-entry-document-pattern.md`.

Lihat juga: `blade-safe-edit` (jebakan Volt `?>`), `oracle-quirks` (CLOB JSON), `ttd-pattern-pdf-print` (cetak).
