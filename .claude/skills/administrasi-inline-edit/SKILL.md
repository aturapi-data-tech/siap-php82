---
name: administrasi-inline-edit
description: Pola sel tabel yang bisa diedit langsung ke DB di modul Administrasi/transaksi (tarif, hari, tanggal di Riwayat Kamar/Visit/Konsul/biaya). WAJIB dibaca sebelum menambah atau mengubah kolom editable pada tabel transaksi — di sini jebakannya bukan UI, melainkan kolom turunan (hari/subtotal) yang rumusnya harus sama dengan proses pembuatnya, dan audit log untuk tiap kolom yang mengalikan biaya.
---

# Edit Inline di Tabel Administrasi/Transaksi

Sel tabel yang langsung tersimpan saat blur (tanpa tombol Simpan). Dipakai di
`transaksi/ri/administrasi-ri/⚡room-ri.blade.php` (Hari, 3 tarif, tgl Mulai/Selesai),
dan bertetangga dengan `visit-ri.blade.php`, `konsul-ri.blade.php`.

Yang berbahaya di sini **bukan UI-nya** — melainkan angka biaya yang ikut bergerak.

## 1. Kerangka aksi (urutannya mengikat)

```php
public function updateTanggalKamar(int $trfrNo, string $kolom, ?string $nilai): void
{
    // a. whitelist kolom — nilai tak terduga DITOLAK, bukan jatuh ke else
    if (!in_array($kolom, ['start_date', 'end_date'], true)) return;

    // b. guard lock transaksi
    if ($this->isFormLocked) { toast error; $this->findData($this->riHdrNo); return; }

    // c. validasi (lihat §2)

    // d. skip bila nilai tidak berubah — tanpa query & tanpa toast
    if ($nilaiLama === $nilai) return;

    // e. tulis: lock baris induk + transaksi + audit log jadi satu kesatuan
    DB::transaction(function () { 
        $this->lockRIRow($this->riHdrNo);
        DB::table('rsmst_trfrooms')->where('trfr_no', $trfrNo)->update([...]);
        $this->appendAdminLogRI($this->riHdrNo, "Ubah ... : {lama} → {baru}");
    });

    // f. baca ulang dari DB + broadcast + toast
    $this->findData($this->riHdrNo);
    $this->dispatch('administrasi-ri.updated');
}
```

- Nilai dikirim lewat **argumen aksi** (`x-on:change="$wire.aksi(id, 'kolom', $event.target.value)"`),
  bukan `wire:model` — karena baris tabel tidak punya properti Livewire per-sel.
- Setiap jalur gagal WAJIB `findData()` ulang supaya angka di layar kembali ke isi DB
  (kalau tidak, user melihat nilai tolakan seolah tersimpan).

## 2. Validasi tanggal = rule repo, bukan cek manual

Ada ±95 tempat memakai rule Laravel; ikuti, jangan bikin cek sendiri.

```php
Validator::make(
    ['tanggal' => $nilai === '' ? null : $nilai],
    ['tanggal' => 'bail|required|date_format:d/m/Y H:i:s'],   // nullable bila boleh kosong
    ['tanggal.date_format' => 'Tanggal Selesai — format: dd/mm/yyyy hh:mm:ss.'],
);
```

`Carbon::createFromFormat` saja TIDAK cukup: `32/13/2026` diterima lalu digeser
diam-diam. Kalau terpaksa manual, format balik hasilnya dan bandingkan dengan input.

## 3. JEBAKAN UTAMA — kolom turunan harus memakai rumus proses pembuatnya

`day`/`hari` di `rsmst_trfrooms` mengalikan biaya: `subtotal = (kamar+prwtn+cs) × day`.
Proses **Pindah Kamar** (`pindah-kamar-ri.blade.php`) menulisnya begini:

```php
ROUND(TO_DATE(trfrDate) - start_date) as day
'day' => max(1, (int) $longDay->day),   // ← max(1): pindah < 1 hari TETAP 1 hari
```

Maka aksi lain yang menghitung ulang `day` wajib memakai `max(1, ROUND(selesai - mulai))`
juga. Memakai `max(0, ...)` membuat koreksi jam pada kamar transit (mis. UGD 7 jam)
menurunkan Hari 1 → 0 dan **menghapus tagihan satu hari tanpa disadari**.

Aturannya umum: sebelum menulis kolom turunan, cari dulu proses lain yang menulis
kolom yang sama dan tiru rumusnya persis. Beda rumus antar-jalur = data tidak konsisten
tergantung lewat pintu mana user masuk.

Selisih hari di PHP: `(int) round(($selesai->getTimestamp() - $mulai->getTimestamp()) / 86400)`.
JANGAN `diffInSeconds($other, false)` — tandanya terbalik di Carbon 3.

## 4. Audit log untuk SETIAP kolom yang menggerakkan biaya

Cek dulu apakah aksi lama di file yang sama sudah punya log. Di `room-ri` sempat
timpang: hapus kamar & ubah tarif ter-log, tapi `updateDay` (pengali subtotal!)
tidak sama sekali. Log berisi **nilai lama → nilai baru**, dan bila kolom bisa NULL
tulis maknanya (`(otomatis)`, `(kosong — kamar aktif)`), bukan `0`/kosong yang menyesatkan.

Letakkan `appendAdminLog*` DI DALAM `DB::transaction` yang sama — kalau update
rollback, log tidak boleh tertinggal.

## 5. Guard state, bukan hanya format

Contoh di Riwayat Kamar:
- `end_date` boleh dikosongkan = kamar kembali aktif → tolak bila sudah ada baris
  aktif lain (`whereNull('end_date')->where('trfr_no','<>',...)->exists()`).
- Selesai tidak boleh lebih kecil dari Mulai; **sama persis boleh** (kamar transit).
- Bandingkan pasangan nilai final (yang baru + yang tersimpan), supaya mengedit
  Mulai juga terkena aturan yang sama, bukan hanya kolom yang sedang diketik.

Guard yang SENGAJA belum dipasang di Riwayat Kamar (keputusan user, jangan
ditambahkan diam-diam): tumpang tindih antar baris, rentang di luar tanggal
rawat inap, dan sinkronisasi rantai transfer (Selesai baris bawah ↔ Mulai baris atas).

## 6. UI

- Input inline: ring fokus brand — `focus:border-brand-green focus:ring-brand-green/40
  dark:focus:border-brand-lime dark:focus:ring-brand-lime/40` (samakan dengan `x-text-input`).
- Tombol hapus baris: `x-outline-button` merah-tint + ikon sampah, padding `!px-2 !py-1`
  (lihat `docs/standar-komponen-tombol.md`), bukan tombol berlabel teks.
- Saat `isFormLocked`, sel kembali jadi teks biasa — jangan hanya `disabled`.

Terkait: [[oracle-quirks]] (to_date/to_char, '' = NULL), [[naming-conventions]]
(whitelist nilai, jangan if/else untuk cabang data sensitif).
