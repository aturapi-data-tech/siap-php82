# Pola Modul-Dokumen RI (formulir bertanda tangan, multi-entri)

Formulir EMR Rawat Inap yang **ditandatangani** dan bisa berulang: General Consent,
Inform Consent, Permintaan Kerohanian, Edukasi Terintegrasi, Penundaan Pelayanan,
Pengkajian Akhir Hayat, dst. Semua tampil sebagai tab di
`transaksi/ri/emr-ri/modul-dokumen/⚡modul-dokumen-ri.blade.php`.

Acuan kanonik: **Inform Consent RI** (paling lengkap) dan **Pengkajian Akhir Hayat**
(paling baru; contoh gabungan formulir KARS + RM.RI).

> Beda dengan `docs/emr-multi-entry-document-pattern.md` (CPPT/SBAR): di sana entri
> ditulis banyak PPA per-profesi & di-review DPJP. Di sini entri **ditandatangani
> pasien/keluarga + saksi + petugas**, lalu **terkunci** dan bisa dicetak.

---

## 1. Struktur file (3 titik sentuh)

```
resources/views/pages/transaksi/ri/emr-ri/modul-dokumen/<nama>/rm-<nama>-actions.blade.php   ← komponen Volt
resources/views/pages/components/modul-dokumen/ri/<nama>/cetak-<nama>-print.blade.php       ← cetak PDF
resources/views/pages/transaksi/ri/emr-ri/modul-dokumen/⚡modul-dokumen-ri.blade.php           ← daftarkan tab
```

Pendaftaran tab = 2 tempat di file yang sama: `<x-tab …>` + panel `<div x-show="activeTab === '<key>'">`
berisi `<livewire:… :riHdrNo="$riHdrNo" :disabled="$isFormLocked" wire:key="…" />`.

## 2. Kerangka komponen

- Traits: `EmrRITrait, MasterPasienTrait, WithRenderVersioningTrait, WithValidationToastTrait`.
- Kartu ringkas + tombol → `openModal()` → `x-modal size=full height=full`.
- Data disimpan di **`datadaftarri_json`** dengan key khusus modul (mis. `pengkajianAkhirHayatRI`),
  berbentuk LIST entri: `['id', 'created_at', 'created_by', 'form' => [...], 'finalized' => bool]`.
- Tulis SELALU lewat `DB::transaction` + `lockRIRow()` + `updateJsonRI()` + `appendAdminLogRI(..., 'MR')`.
- `array_replace_recursive(defaultForm(), $entri['form'])` saat memuat entri lama —
  record lama yang belum punya key baru tetap aman (lihat feedback normalisasi JSON legacy).

## 2a. Tampilan baku modul dokumen (BAKU sejak 2026-08-27) — RINGKASAN

Berlaku untuk **semua 69 modul dokumen** (multi-entri maupun sekali-entri). Kalau membuat
modul baru, salin susunan ini apa adanya; kalau menyunting yang lama, jangan turunkan
salah satunya diam-diam.

### Kartu di tab

```
[judul · badge · deskripsi(1 baris + "Selengkapnya")]                    [Buka … ]
[tabel pratinjau 3 entri terbaru — tetap tampil walau kosong]
```

- baris judul: `flex items-baseline flex-1 gap-2 min-w-0`; judul `truncate shrink-0`;
  badge `shrink-0 whitespace-nowrap`; deskripsi `<x-deskripsi-ringkas>` bila > 90 karakter
- **induk baris judul WAJIB `min-w-0`** — tanpa itu `truncate` tak menggigit dan kartunya
  melar melewati layar sampai tombol "Buka …" terdorong keluar
- **Kartu = komponen `<x-modul-dokumen.kartu>`** (BAKU 2026-09-15, 71 modul):

  ```blade
  <x-modul-dokumen.kartu judul="Laporan Anestesi" :jumlah="$laCount" satuan="laporan"
      :nonaktif="$disabled || !$riHdrNo">
      <x-slot:deskripsi>Laporan pelaksanaan anestesi (PAB 6 / RM 53): …</x-slot:deskripsi>
      <div class="overflow-x-auto rounded-2xl border border-hairline dark:border-gray-700">
          <table class="min-w-full text-sm">…pratinjau 3 entri terbaru…</table>
      </div>
  </x-modul-dokumen.kartu>
  ```

  `jumlah` + `satuan` → badge hijau "N laporan" / kuning "Belum ada"; status khusus lewat slot `badge`
  (General Consent "Sudah ditandatangani", Surat Kematian). Prop `tombol` (label, boleh `:tombol` dinamis),
  `buka` (default `openModal`), `nonaktif`, `tampilTombol`. Slot `ringkasan` = dl/ul di bawah judul;
  slot `tombolLain` = tombol di sebelah Buka (Cetak Catatan); slot bawaan = pratinjau, dibungkus
  `mt-3 space-y-3` oleh komponen — **jangan beri `mt-*` sendiri**. Nama slot tak boleh sama dengan
  nama prop (slot `tombol` sempat menimpa label tombol).

### Modal

```
[ikon] Judul · deskripsi ······················ [badge] [✕]     ← satu baris, X mepet kanan
[display pasien]
[isi: daftar ⇄ formulir]                                        ← area flex-1
[footer]                                                        ← DI LUAR area isi
```

- **Header = komponen `<x-modul-dokumen.header>`** (BAKU 2026-09-15, 71 modal) — jangan tulis
  markup header sendiri:

  ```blade
  <x-modul-dokumen.header judul="Formulir Penjaminan & Orientasi Kamar"
      ikon="M9 12l2 2 4-4m5.618-4.016A11.955…"
      jalur="UGD" :jumlah="$penjaminanCount" :readOnly="$isFormLocked">
      Pernyataan penjaminan pembiayaan sekaligus orientasi ruang rawat kepada pasien/keluarga.
      <x-slot:badge>
          @if ($editingKey)<x-badge class="shrink-0 whitespace-nowrap" variant="warning">Mode: Edit</x-badge>@endif
      </x-slot:badge>
  </x-modul-dokumen.header>
  ```

  Prop: `judul` (karakter asli, bukan entity), `ikon` (atribut `d` path heroicons 24×24), `jalur`
  `RJ|UGD|RI` (badge brand), `jumlah` (badge "N tersimpan" bila > 0 — ekspresi dievaluasi LANGSUNG,
  beri `?? []` bila datanya bisa belum ada), `readOnly`, `tutup` (default `closeModal`). Slot bawaan =
  deskripsi (> 90 karakter otomatis diringkas + "Selengkapnya"); slot `badge` = badge khusus modul.
- Komponen khusus modul dokumen dikumpulkan di **`resources/views/components/modul-dokumen/`**
  (dipanggil `x-modul-dokumen.<nama>`) — bagian berulang berikutnya (footer, tabel daftar, …) masuk sini.
- X = **anak terakhir baris flex judul**, `class="ml-auto shrink-0"` (sudah diurus komponen header).
- footer = **saudara** area isi yang ber-`flex-1`, ditambah `sticky bottom-0`: menempel di
  dasar saat isi pendek, tetap terlihat saat isi panjang. Pembungkus isi = anak langsung `<x-modal>` dengan
  **`flex flex-col min-h-full`** — BUKAN `min-h-[calc(100vh-8rem)]` (±5rem lebih pendek dari panel
  `h-[calc(100dvh-3rem)]`, footer jadi melayang dengan ruang kosong di bawahnya; dibenahi 74 modal
  2026-09-15). Modal payung Modul Dokumen RJ/UGD/RI juga memakai `<x-modul-dokumen.header>`.
- **Footer modul dua layar = komponen `<x-modul-dokumen.footer>`** (BAKU 2026-09-15, 62 modal):

  ```blade
  <x-modul-dokumen.footer :formulir="$this->diForm()" :terkunci="$isFormLocked"
      :lihat="$viewOnly" :mengedit="$editingKey" :bisaSimpan="$riHdrNo && !$isFormLocked">
      Simpan draft dulu, lalu <strong>kunci</strong> lewat tombol <strong>TTD Petugas &amp; Kunci</strong>.
      <x-slot:tombol>
          @if ($editingKey && !$isFormLocked)<x-secondary-button wire:click="cancelEdit">Batal Edit</x-secondary-button>@endif
      </x-slot:tombol>
  </x-modul-dokumen.footer>
  ```

  Layar daftar: keterangan "Setiap entri berdiri sendiri" + Tutup + Isi Formulir Baru (hilang saat
  `terkunci`). Layar formulir: petunjuk (slot bawaan, tampil bila bisa simpan) / "Mode lihat" +
  Kembali ke Daftar + slot `tombol` + Selesai Melihat (saat `lihat`) / Simpan. `bisaSimpan` default
  `!terkunci && !lihat`; `labelSimpan` default Simpan Draft / Simpan Perubahan menurut `mengedit`.
  Method `simpan`/`selesaiLihat`/`kembali`/`tutup`/`tambah` (default `saveDraft`/`cancelEdit`/
  `kembaliKeDaftar`/`closeModal`/`tambahEntri`; `""` = tombol tidak ada). Tutup SENGAJA hanya di layar
  daftar. Case Manager dikecualikan (tombol di dalam kartu Form A/B).
- tombol: layar daftar `[Tutup] [Isi Formulir Baru]`, layar formulir
  `[Kembali ke Daftar] [Simpan …]`.
- **Banner status di atas formulir = `<x-modul-dokumen.banner>`** (BAKU 2026-09-15, 137 titik):

  ```blade
  @if ($isFormLocked)
      <x-modul-dokumen.banner jenis="terkunci" />
  @endif
  @if ($viewOnly)
      <x-modul-dokumen.banner jenis="lihat" />
  @elseif ($editingKey && !$isFormLocked)
      <x-modul-dokumen.banner jenis="lanjut" />
  @endif
  ```

  Kondisi tetap di modul; komponen = tampilan (kuning/biru/hijau, ikon gembok/mata/pensil) + teks baku.
  Teks khusus lewat slot (Case Manager Form A/B, Form Transfer final, "pasien sudah pulang"); margin
  lewat `class`. Teks baku sengaja TIDAK menampilkan kunci teknis entri (`$editingKey` = timestamp).

### Tabel (daftar & pratinjau)

```blade
<div class="overflow-x-auto rounded-2xl">      {{-- sudut ikut kartu; tabel tanpa border sendiri --}}
    <table class="min-w-full text-sm">
        <thead class="sticky top-0 z-10 bg-surface-card dark:bg-gray-800">
            <tr class="text-xs font-semibold tracking-wide text-left text-muted uppercase dark:text-gray-300">
                <th class="whitespace-nowrap px-4 py-3 border-b bg-surface-card dark:bg-gray-800">…</th>
```

- latar wajib ikut di `<th>` (syarat sticky), judul kolom `whitespace-nowrap` supaya tak patah
- **`whitespace-nowrap` hanya untuk tabel daftar/pratinjau**, jangan tabel di dalam formulir —
  kolomnya banyak dan judulnya panjang, nowrap memaksa formulir bergulir ke samping
- kosong pun tabel tetap tampil, dengan `<td colspan="N">Belum ada data tersimpan</td>`

### Layar daftar: polos dan selebar modal (BAKU sejak 2026-08-30)

Layar daftar **tidak boleh** punya judul sendiri (`<h3>Daftar … Tersimpan</h3>`) maupun baris
petunjuk "Klik baris untuk lihat detail lengkap" — judul modul sudah terpampang di header
modal, jadi keduanya cuma pengulangan (keputusan user 2026-08-30, dibuang di 8 modul bedah +
11 VK + kelompok lain).

Tabelnya **selebar area isi modal, di dalam kartu `<x-border-form padding="p-0">`** yang dibuka
tepat di dalam `@unless ($this->diForm())` (acuan Edukasi Terintegrasi; BAKU 2026-09-15 — tabel
polos tanpa kartu seperti Formulir Penjaminan & modul bedah dulu terlihat "lepas", header tabelnya
melebar melewati display pasien). Kartu ber-padding `p-6 space-y-6 bg-canvas border … sm:p-8
rounded-2xl` hanya milik LAYAR FORMULIR — dua cara yang sah:

```blade
{{-- a. kartu menempel di <fieldset> (dipakai Monitoring Pasca Anestesi, Instruksi Pasca Bedah) --}}
<fieldset @disabled($formReadOnly) class="p-6 space-y-6 bg-canvas border border-hairline shadow-sm sm:p-8 rounded-2xl …">

{{-- b. kartu membungkus keduanya → kelasnya dibuat BERSYARAT --}}
<div class="{{ $this->diForm() ? 'p-6 sm:p-8 bg-canvas border border-hairline shadow-sm rounded-2xl …' : '' }} space-y-6">
```

`max-w-5xl mx-auto` pada pembungkus isi **dilarang** — pakai `max-w-full mx-auto`; modal sudah
`size="full" height="full"`.

Badge header baku: `Rawat Inap` · `{{ count($daftar) }} tersimpan` · `Read Only` (bila terkunci),
dalam `<div class="flex items-center gap-1.5 ml-auto shrink-0">` **sebelum** tombol ✕. Lima modul
Surveilans HAIs sempat tanpa badge sama sekali.

### Tabel daftar: acuan Edukasi Terintegrasi (BAKU sejak 2026-09-08)

Layar daftar setiap modul multi-entri memakai satu bentuk tabel, acuannya
`edukasi-terintegrasi-ri`. **Kerangkanya = komponen `<x-modul-dokumen.tabel-daftar>`** (BAKU 2026-09-15,
62 tabel) — kartu `x-border-form p-0` + tabel `min-w-full text-sm` + thead sticky:

```blade
@unless ($this->diForm())
    <x-modul-dokumen.tabel-daftar :kolom="['', 'Tanggal', 'Petugas (TTD)', 'Status' => 'text-center', 'Aksi' => 'text-center w-64']">
        @forelse ($daftarUrut as $entry)
            <tbody wire:key="…" x-data="{ open: false }" class="border-b border-hairline dark:border-gray-700">…</tbody>
        @empty
            <x-modul-dokumen.baris-kosong :kolom="5" />
        @endforelse
    </x-modul-dokumen.tabel-daftar>
@endunless
```

`:kolom` = label berurutan; berkunci teks = label ⇒ kelas tambahan (`text-center`, `w-64`); label `''` =
kolom panah rincian (`w-8 px-2`). Slot `atas` = baris tombol di atas tabel di dalam kartu (Catatan Terapi
Neonatal). `baris-kosong`: `:kolom` (colspan) + `pesan` (default "Belum ada data tersimpan"). Case Manager
dikecualikan (tabel di dalam kartu Form A/B). Tabel bersarang di baris rincian tetap ditulis biasa. Disapu ke 63 modul dua layar pada 2026-09-08 (commit 81f60f88,
eab5fdbe, 8a941775). Kalau membuat modul baru, salin dari sana; jangan bikin varian.

```
[▸] Tanggal | <ringkasan khas modul, 1–2 kolom> | Petugas (TTD) | Status | Aksi
    ↳ baris rincian <dl> 2 kolom (terbuka saat baris diklik; kolom Aksi @click.stop)
```

- **Tanpa kolom No** — nomor urut tak bermakna karena daftar diurut ulang (§2c).
- **Kolom pertama = panah rincian**: `<tbody wire:key x-data="{ open: false }">` per entri,
  `<tr @click="open = !open">` dengan `<svg :class="{ 'rotate-90': open }">`, lalu
  `<tr x-show="open" x-cloak><td colspan="N"><dl class="grid … md:grid-cols-2">`.
  Semua baris mulai TERTUTUP. Isi `<dl>` = ringkasan isian yang tidak muat di kolom,
  bukan seluruh formulir.
- **Tanggal** `font-mono`; **Petugas (TTD)** = `<x-modul-dokumen.status-ttd :nama="$entry['ttd'] ?? ''" />`
  (nama / badge merah `Belum TTD`); **Status** = `<x-modul-dokumen.status-entri :final="$isFinal" />`
  (badge `Terkunci` info / `Draft` warning); kolom teks lain `text-muted`. BAKU 2026-09-15, 121 + 160 titik.
  `status-ttd`: `gaya` tebal (tabel daftar) · biasa (baris rincian) · polos (pratinjau kartu, warna ikut sel);
  `:waktu` → "… — waktu"; `:sudah` untuk TTD pasien tanpa nama (signature pad) → "Sudah TTD".
  `:nama` wajib `?? ''` bila kuncinya bisa tak ada (prop dievaluasi langsung, bukan di dalam @if).
  `status-entri`: `labelDraft` (Identifikasi Bayi "Belum TTD"). Pengecualian yang sengaja ditulis tangan:
  Pelaporan ESO (baris "dibuat: …" di bawah badge) & Formulir Penjaminan (kode petugas).
- **Aksi = komponen `<x-modul-dokumen.aksi-entri>`** (BAKU 2026-09-15, 64 titik) — jangan tulis tombol
  sendiri. Aturan di bawah sudah ada di dalamnya:

  ```blade
  <td class="px-4 py-3 text-center align-middle whitespace-nowrap" @click.stop>
      <x-modul-dokumen.aksi-entri kunci="{{ $rowKey }}" :final="$isFinal" :terkunci="$isFormLocked"
          judulBukaKunci="Buka Kunci Inform Consent" konfirmasiHapus="Yakin hapus Inform Consent ini?" />
  </td>
  ```

  Prop method `lanjut`/`lihat`/`cetak`/`bukaKunci`/`hapus` (default `editEntry`/`viewEntry`/`cetak`/
  `bukaKunci`/`hapus`; **string kosong `""` = tombol tidak ada** — `null` jatuh ke default),
  `argumenAwal` (Case Manager `'formA'` → `hapusForm('formA','kunci')`), `bisaDiubah` (syarat tambahan,
  mis. `filled($id)`), `cetakHanyaFinal` (dokumen legal), `hapusHanyaDraft` (Pindah Antar Ruang), teks
  `judulLanjut`/`judulLihat`/`judulBukaKunci`/`pesanBukaKunci`/`konfirmasiHapus`. Slot = tombol khusus
  di kelompok kiri (TTD Petugas menyusul di Inform Consent, TTD Saya di Identifikasi Bayi).
  Jebakan saat membuat komponen sejenis: atribut `wire:*` ditulis LITERAL `wire:click="{{ $m }}('{{ $kunci }}')"`
  — binding `:wire:click` di-escape Blade lalu di-escape lagi oleh lihat/cetak/hapus-button (wire:target
  rusak); tanda kutip ganda di dalam nilai atribut komponen memutus parser tag.
- **Aksi, SATU baris rata kanan** (BAKU 2026-09-08, permintaan user — dua baris lama membuat
  Buka Kunci & hapus berdempetan dan rawan salah klik):
  `[Lanjutkan Pengisian (primary, draft) | <x-lihat-button> (terkunci)] [<x-cetak-button>]  │
  [Buka Kunci = x-confirm-button variant="warning-soft" (kuning), @can dokumen.bukaKunci]  │
  [<x-hapus-button>, @can dokumen.hapus]` — pembungkus `flex items-center justify-end gap-2`;
  kelompok berisiko dalam `<div class="flex items-center gap-2 pl-3 ml-1 border-l border-hairline
  dark:border-gray-700">` yang hanya dirender bila `!$isFormLocked` DAN user punya salah satu hak
  (supaya tak menyisakan garis kosong). Hapus selalu paling kanan; Buka Kunci kuning, bukan merah.
  Sel Aksi `whitespace-nowrap` supaya teks tombol tidak patah dua baris. **Cetak wajib komponen `<x-cetak-button wire:click="cetak(…)"
  title="…" />`** dan **hapus wajib `<x-hapus-button wire:click.prevent="hapus(…)" confirm="…" />`**
  (ikon tong sampah merah, tinggi 40px sama dengan tombol berteks — jangan `x-outline-button`
  merah manual ber-`!px-2 !py-1` lagi). Cetak (`resources/views/components/cetak-button.blade.php`, ikon-saja biru meniru
  tabel Slip Gaji Dokter; wire:target & spinner otomatis) — jangan tulis tombol sekunder
  berteks "Cetak" lagi, jangan `x-info-button`/`x-primary-button`. Komponen yang sama
  berlaku untuk SEMUA tombol cetak di repo (86 titik, 2026-09-08): butuh keterangan →
  `label="Cetak E-Resep"` atau isi slot; etiket obat → `label="Etiket"`; aksi per entri →
  tanpa label. Kelas tata letak (`ml-auto`, `w-full`, `min-w-[…]`) boleh ditambah lewat `class`.
- Modul tanpa draft (Identifikasi Bayi): tombol atas `TTD Saya` (primary) menggantikan
  Lanjutkan Pengisian; Status `Terkunci` / `Belum TTD`.
- **Keterangan footer layar daftar** (kiri, `mr-auto`): "Setiap entri berdiri sendiri —
  **Isi Formulir Baru** untuk entri baru, **Lanjutkan Pengisian** untuk melanjutkan draft."
  Kata kedua harus sama dengan label tombolnya (dulu "Edit").
- Daftar kartu-baris (`<div class="flex … justify-between">` per entri) **tidak dipakai lagi**;
  Surveilans HAIs ×5 dan Identifikasi Bayi sudah dikonversi.

### Penamaan

Header modal WAJIB ikon: kotak `flex items-center justify-center w-7 h-7 rounded-lg shrink-0
bg-brand-green/10 dark:bg-brand-lime/15` berisi `<svg class="w-4 h-4 text-brand-green …">` tepat
sebelum `<h2>` judul, baris header `py-2.5` + `items-center` (12 modal disusulkan 2026-09-15).

Kalimat utuh, bukan singkatan: "Lanjutkan Pengisian" (bukan "Lanjut Isi"), "Isi Formulir
Baru" (bukan "Tambah Entri"), "Formulir Transfer UGD → Rawat Inap" (bukan "Form Transfer
UGD → RI"), "Case Manager — Manajer Pelayanan Pasien" (bukan "(MPP)"). Tiap modal wajib
punya **ikon + judul + keterangan**.

### Alat periksa

```bash
php .claude/skills/modul-dokumen/periksa-tampilan.php            # semua modul dokumen
php .claude/skills/modul-dokumen/periksa-tampilan.php <berkas…>  # sebagian
```

Membaca HTML hasil render (bukan isi berkas): keseimbangan tag di kedua layar, posisi tombol
tutup lewat DOM, kelengkapan tombol footer, dan keterangan tabel saat kosong. Sejak 2026-09-08
juga memeriksa bentuk tabel daftar: kolom No, panah rincian, Cetak bukan info/primary, label
"Lanjutkan" tanpa "Pengisian", dan keterangan footer. Sejak 2026-09-15: ikon header modal & tabel
daftar di dalam `<x-border-form>`. EXIT 0 = lolos.

### Jebakan yang sudah menggigit (jangan diulang)

| Gejala | Sebabnya |
|---|---|
| `truncate` tak menggigit, kartu melar | induk `flex-1` tanpa `min-w-0` (item flex bawaannya `min-width:auto`) |
| Badge patah dua baris | badge tanpa `shrink-0 whitespace-nowrap` |
| X turun sendiri / tak mepet kanan | X di luar baris flex, atau di dalam kelompok judul, atau tanpa `ml-auto` |
| X & badge hilang di layar daftar | `@if ($this->diForm())` terbuka DI DALAM baris judul — naikkan markupnya ke atas penjaga, jangan geser penjaganya (di sebagian berkas directive & tag HTML saling menyilang) |
| Sel tabel berdempet tanpa padding | kelas `ds-c`/`ds-td-*` dipakai di luar `<table class="ds-table">` |
| Footer mengambang di tengah | footer ditulis DI DALAM area isi, bukan sebagai saudaranya |
| Kartu bergaris dua | tabel membawa `border … rounded-lg` sendiri di dalam kartu `padding="p-0"` |
| **Display pasien & badge hilang di layar daftar, modal "berantakan"** | `@if ($this->diForm())` dipasang **di header modal**, bukan tepat sebelum `<fieldset>` formulir — seluruh blok badge, display pasien, dan pembungkus isi ikut tak dirender. Kena 9 modul di 3 kelompok (bedah, VK, HAIs) |
| Tabel layar daftar tampak sempit walau modal `size="full"` | isi terkurung `max-w-5xl mx-auto`, atau kartu `p-6 … sm:p-8` membungkus formulir **dan** daftarnya sekaligus |
| Kolom Tanggal urut acak | daftar dibalik `array_reverse()` (urutan simpan), bukan diurutkan tanggal — lihat §2c |

## 2b. Dua layar: daftar dulu, formulir menyusul (BAKU sejak 2026-08-27)

Modal modul dokumen punya **dua layar**, tidak boleh menampilkan formulir dan daftarnya
sekaligus:

| Layar | Isi | Tombol |
|---|---|---|
| `daftar` (dibuka duluan) | grid entri tersimpan; per baris Edit/Lihat/Cetak/Hapus | `Tutup`, `Tambah Entri` |
| `form` | formulir entri | tombol simpan/TTD lama + `Kembali ke Daftar` (menggantikan `Tutup`) |

**Kenapa.** Dulu formulir nongkrong bersama daftarnya, lalu dikosongkan diam-diam sesudah
tersimpan. Petugas yang mengira itu masih formulir yang tadi diisi mengetik ulang di atasnya
— dan tersimpan sebagai **draft baru**. Duplikat draft ini keluhan nyata dari pemakaian.

### Potongan baku

```php
// Layar aktif di modal: 'daftar' (grid entri) atau 'form' (tambah/edit/lihat).
public string $layar = 'daftar';

/** Layar formulir sedang tampil? Saat terkunci, formulir tak pernah dirender. */
public function diForm(): bool
{
    return !$this->isFormLocked && ($this->viewOnly || $this->editingKey !== null || $this->layar === 'form');
}

public function tambahEntri(): void
{
    if ($this->isFormLocked || $this->disabled) { /* toast + return */ }
    $this->cancelEdit();     // kosongkan formulir (sekaligus balik ke daftar)…
    $this->layar = 'form';   // …lalu naikkan formulirnya
}

public function kembaliKeDaftar(): void { $this->cancelEdit(); }
```

**Kunci polanya ada di reset:** method `reset*()` yang mengosongkan formulir ikut menyetel
`$this->layar = 'daftar'`. Dengan begitu SETIAP jalur yang mengosongkan formulir — Simpan
Draft, finalize/TTD, batal edit, hapus entri yang sedang dibuka — otomatis kembali ke daftar
tanpa perlu diingat satu per satu. `openModal()` juga menyetelnya ke `'daftar'`.

Di markup: formulir dibungkus `@if ($this->diForm())`, daftar dibungkus
`@unless ($this->diForm())`, dan isi footer bercabang mengikuti keduanya.

**LETAK `@if ($this->diForm())` — tepat sebelum `<fieldset>` formulir, TITIK.** Ini kesalahan
paling mahal yang ditemukan 2026-08-30: di 9 modul (Laporan Anestesi, Instruksi Pasca Bedah,
Laporan Persalinan, Indikator SC, Pengkajian Awal Bayi, Surveilans HAP/ILO/Plebitis/VAP)
penjaga itu dipasang **di dalam header modal**, sehingga di layar daftar blok badge, display
pasien, dan pembungkus isi ikut hilang — petugas melihat modal "berantakan" tanpa identitas
pasien. `periksa-tampilan.php` TETAP LOLOS waktu itu, karena saldo tag & tombol tutupnya masih
benar.

Cara memastikan (jangan mengandalkan urutan baris):

```php
// display pasien WAJIB ada di HTML layar daftar
$html = Livewire::test($komponen, ['riHdrNo' => $hdr])->call('openModal')->html();
str_contains($html, 'display-pasien');   // harus true
```

**AWAS POSITIF PALSU:** membandingkan nomor baris `@if ($this->diForm())` dengan baris
`display-pasien` saja menyesatkan. Di `edukasi-terintegrasi-ri` penjaga itu memang sengaja
membungkus badge "Mode: Lihat/Edit/Tambah" di header; memindahkannya justru membuat saldo
`<div>` timpang. Yang sahih adalah hasil render.

**NAMA METHOD WAJIB `tambahEntri()` dan `kembaliKeDaftar()`.** `periksa-tampilan.php` mencari
persis `wire:click="tambahEntri"` di layar daftar dan `wire:click="kembaliKeDaftar"` di layar
formulir. Modul dengan tombol bernama sendiri gagal periksa — Case Manager RI (tiga layar:
`daftar` → `formA` → `formB`, karena Form B anak Form A) memakai `tambahFormA`/`cancelEditA`/
`tutupFormB`, jadi ditambahi alias:

```php
/** Nama baku modul dokumen untuk "entri baru"; di modul ini entri baru = Form A baru. */
public function tambahEntri(): void { $this->tambahFormA(); }

/** Tutup formulir mana pun yang sedang tampil, kembali ke daftar. */
public function kembaliKeDaftar(): void { $this->cancelEditA(); $this->tutupFormB(); }
```

### Susunan atas modal (BAKU)

Urutannya **display pasien dulu, baru judul** — mengikuti pola EMR, supaya identitas pasien
yang paling sering dicek petugas tidak tertutup blok judul besar:

```blade
{{-- DISPLAY PASIEN — paling atas, mengikuti pola EMR --}}
<div class="px-4 pt-4">
    <livewire:pages::transaksi.<jalur>.display-pasien-<jalur>.display-pasien-<jalur> … />
</div>

{{-- JUDUL RINGKAS --}}
<div class="relative px-6 py-3 border-b …">   {{-- py-3, bukan py-5 --}}
    … ikon w-8 h-8 · <h2> text-base (bukan text-2xl) · subjudul text-xs · badge · tombol tutup
</div>
```

Judulnya sengaja kecil: modal ini sudah dibuka dari tab yang bernama sama, jadi judul besar
cuma memakan ruang.

**Judul dan tombol tutup SEBARIS di paling atas** — judul kiri, X kanan, display pasien
menyusul di bawahnya:

```blade
{{-- JUDUL + TOMBOL TUTUP SEBARIS — judul di kiri, X di kanan, paling atas modal --}}
<div class="relative px-6 py-2.5 border-b …">
    <div class="relative flex items-center gap-3 min-w-0">
        <div class="flex items-center flex-1 gap-3 min-w-0">
            [ikon w-7] <div class="flex items-baseline gap-2 min-w-0">
                          <h2 class="truncate shrink-0 text-sm …">Judul</h2>
                          <p class="truncate text-xs …">Subjudul</p>
                       </div>
            <div class="flex items-center gap-1.5 ml-auto shrink-0"> [badge…] </div>
        </div>
        <x-icon-button … class="ml-2 shrink-0"> X </x-icon-button>
    </div>
</div>
```

Aturan yang lahir dari percobaan, jangan diulang:
- **Jangan** membuat X mengambang (`absolute` di pembungkus `relative`) — ia menimpa kartu
  display pasien dan menutupi nomor antrian.
- **Jangan** menaruh X di baris terpisah sendiri — barisnya nyaris kosong dan boros ruang.
- **Jangan** menumpuk judul/subjudul/badge ke bawah; semuanya `truncate` + `min-w-0` supaya
  memendek, bukan turun baris.

### Blok daftar: tanpa judul sendiri, tabel selebar kartunya

Blok daftar **tidak** memasang judul lagi (`<h3>Daftar … Tersimpan</h3>`) — judul modal di
baris atas sudah menyebutkan dokumennya, jadi judul kedua cuma memakan tinggi. Kartunya juga
tanpa padding dalam supaya tabel memakai ruang penuh:

```blade
@unless ($this->diForm())
    <x-border-form padding="p-0">   {{-- tanpa title, tanpa padding --}}
        <div class="overflow-x-auto rounded-2xl">   {{-- sudut ikut kartunya --}}
            <table class="min-w-full text-sm">…</table>   {{-- TANPA border/rounded sendiri --}}
        </div>
    </x-border-form>
@endunless
```

Bingkai & sudut cukup dari kartunya. Tabel yang membawa `border … rounded-lg` sendiri bikin
garis ganda dengan radius yang beda (lg di dalam 2xl), dan sudut header sticky-nya menyembul
keluar kartu.

**Kepala tabel menyontek `daftar-rj`** (acuan halaman list):

```blade
<thead class="sticky top-0 z-10 bg-surface-card dark:bg-gray-800">
    <tr class="text-xs font-semibold tracking-wide text-left text-muted uppercase dark:text-gray-300">
        <th class="px-4 py-3 border-b bg-surface-card dark:bg-gray-800">Tindakan</th>
        …
```

Latar dipasang di `<th>` juga, bukan cuma di `<thead>` — itu syarat header sticky supaya
baris tabel tak menembus di belakangnya saat digulir.

> **Awas saat menyisir berkas:** jangan memakai kata "Tersimpan"/"Riwayat" sebagai penyaring
> `grep` untuk menemukan modul dokumen — sejak judul blok dibuang, 18 berkas tak lagi memuat
> kata itu dan daftar berkasnya menyusut diam-diam (55 → 37). Penanda yang stabil:
> `grep -rl 'this->diForm()' resources/views/pages/transaksi`.

### Kartu di tab: pratinjau entri

Kartu modul di tab (sebelum modal dibuka) menampilkan **ringkasan entri terbaru** supaya
petugas tak perlu membuka modal hanya untuk memastikan sudah terisi:

```blade
{{-- PRATINJAU ENTRI DI KARTU — ringkasan entri terbaru, tanpa perlu membuka modal --}}
@if (count($daftar ?? []) > 0)
    <div class="mt-3 overflow-x-auto rounded-2xl border border-hairline …">
        <table class="min-w-full text-sm">   {{-- kolom = kolom tabel modal, tanpa panah & Aksi --}}
    </div>
    @if (count($daftar) > 3)
        <p class="…">+{{ count($daftar) - 3 }} entri lain — buka untuk melihat semua.</p>
    @endif
@endif
```

Kolomnya sengaja **diklon dari tabel di modalnya sendiri** (buang kolom panah & kolom Aksi),
supaya istilah dan urutan kolomnya sama persis — bukan dikarang ulang per modul.

**Judul kartu juga SATU BARIS**, sama seperti judul modal — judul · badge · deskripsi
(`hidden truncate … sm:block`, jadi menghilang di layar sempit alih-alih memanjangkan kartu),
tombol "Buka …" tetap di kanan.

**Gaya tabel kartu = gaya tabel modal**: pembungkus `mt-3 overflow-x-auto rounded-2xl border`,
`<table class="min-w-full text-sm">` tanpa border sendiri, `<thead class="bg-surface-card …">`,
baris header `text-xs … uppercase`, sel `px-3 py-2`.

**Saat belum ada entri, tabelnya TETAP tampil** dengan baris
`<td colspan="N">Belum ada data tersimpan</td>` — jangan dibungkus `@if (count(...) > 0)`.
Kartu yang kosong melompong tak bisa dibedakan dari kartu yang gagal memuat.

> **Jebakan scope:** variabel sumber daftar (mis. `$list`) sering didefinisikan `@php` DI DALAM
> modal, jadi null di kartu. Pratinjau di kartu wajib punya definisinya sendiri
> (`@php $list = $dataDaftarRi['kunci'] ?? []; @endphp`) — kalau tidak, `count($list)` melempar
> "must be of type Countable|array, null given" begitu penjaga `@if (count(...))` dibuang.

### Cara memverifikasi (jangan cuma lihat "tidak error")

`php -l` maupun `Blade::compileString` TIDAK menangkap kerusakan berkas Volt, dan halaman
yang tetap ter-render belum berarti layarnya berpindah. Ukur tiga hal lewat `Livewire::test`:

1. render `daftar` → `tambahEntri` → `kembaliKeDaftar` tidak melempar;
2. **isian formulir benar-benar hilang** di layar daftar — hitung `wire:model` pada HTML
   kedua layar (harus 0 vs puluhan). Ini yang menangkap "konversi palsu" (12 berkas sempat
   lolos render tapi tak menyembunyikan apa pun);
3. tombol ada di layar yang benar (`saveDraft` 0 di daftar, `tambahEntri` 0 di formulir);
4. **daftar TETAP menampilkan tabelnya saat kosong**, lengkap dengan baris
   `<td colspan="N">Belum ada data tersimpan</td>` (pola empty state `daftar-rj`). Jangan
   membungkus tabel dengan `@if (count($list) > 0)` — layar daftar jadi kosong melompong dan
   petugas tak tahu apakah datanya nihil atau halamannya rusak;
5. **keseimbangan tag pada HTML HASIL RENDER di kedua layar** (`<div>` vs `</div>`, section,
   fieldset, table, ul/ol, p, span).

Lapis 5 itu wajib dan tidak bisa digantikan pemeriksa statis. Menghitung tag pada BERKAS
tidak cukup: rentang yang menutup `<div>` header lalu membuka `<div>` isi terhitung
"seimbang" padahal sarangnya salah. Render komponen sendirian juga tidak cukup: anak yang
kelebihan satu `</div>` tetap terlihat wajar berdiri sendiri, dan baru meledak saat
bersarang di komponen induk — `MultipleRootElementsDetectedException` pada INDUK, bukan pada
berkas yang salah. Sepuluh berkas pernah lolos tiga lapis pertama dengan cacat ini.

Kalau modul yang dikonversi ikut dipasang di komponen payung (mis. Pelayanan Bedah yang
menampung 8 anak), buka juga halaman daftarnya (`/ri/daftar`, `/rj/daftar`, `/ugd/daftar`)
dan pastikan 200.

## 2c. Urutan tabel entri: TERBARU DI ATAS (BAKU sejak 2026-08-30)

`array_reverse($daftar)` **dilarang** — ia cuma membalik urutan simpan. Kolom Tanggal diisi
petugas (boleh mundur/maju) dan entri yang di-edit tetap duduk di posisi lamanya, jadi begitu
urutan isi ≠ urutan simpan tabelnya tampil acak (laporan user 2026-08-30 di Pra Anestesi:
10:32 → 10:37 → 10:45 → 10:43 → 10:14).

Pola baku, ditulis langsung di komponen (114 titik di 59 blade RI/RJ/UGD, commit `695dd130`) —
**tanpa trait/helper**, mengikuti daftar CPPT/SBAR EMR RI:

```blade
@forelse (collect($daftar)->sortByDesc(fn($entri) => strtotime(strtr(
    ($entri['tanggal'] ?? '') ?: ($entri['createdAt'] ?? ''), '/', '-')))->values()->all() as $entry)
```

| Aturan | Alasan |
|---|---|
| Kunci urut = **tanggal yang TAMPIL** di kolom, fallback `createdAt` | yang dibaca petugas itu kolomnya, bukan waktu simpan |
| **Jangan** `Carbon::createFromFormat` | melempar exception untuk satu entri berformat menyimpang (tanggal tanpa jam / data lama kotor) → modal 500 |
| **Jangan** `Carbon::parse` | dengan garis miring ia menebak m/d/Y (Amerika): 09/08 jadi 9 Agustus |
| `strtr('/', '-')` sebelum `strtotime` | memaksa pembacaan d-m-Y gaya Eropa; nilai tak terbaca → `false` → entri turun ke bawah, bukan error |
| Tutup dengan `->values()->all()` | tetap array — ada pemanggil `array_slice(...)` untuk pratinjau 3 entri di kartu |

## 3. Siklus hidup entri (BAKU)

```
Simpan Draft (tanpa validasi)  →  pasien/keluarga TTD (+saksi, opsional)
   →  PETUGAS TTD  =  validasi penuh + stempel + KUNCI entri
   →  entri read-only: hanya Lihat / Cetak
   →  [Admin | Manager Umum | Manager Medis]  Buka Kunci  →  kembali draft, TTD petugas dicabut
```

Aturan yang mengikat:
- **TTD petugas adalah aksi terakhir dan sekaligus pengunci** (`setDokterPenjelas` di Inform
  Consent, `ttdPetugas()` di Akhir Hayat). JANGAN sediakan tombol "Simpan & Kunci" terpisah —
  dua jalan mengunci = perilaku bercabang.
- Varian **multi-TTD petugas** (Surgical Safety Checklist: Perawat Sirkuler + Dokter Anestesi +
  Operator, TANPA TTD pasien): satu aksi `setTtdRole($role)` dipakai semua peran (peta peran di
  konstanta `TTD_ROLES`), entri terkunci **otomatis saat TTD terakhir terisi** (`semuaTtdTerisi()`) —
  bukan lewat satu method pengunci khusus. Buka kunci pada varian ini mencabut **semua** TTD
  petugas (aturan "TTD pasien dipertahankan" tak berlaku karena memang tak ada TTD pasien).
- Tombol footer cukup **Simpan Draft** (jadi "Simpan Perubahan" saat melanjutkan draft).
- `entryIsFinal()` baca flag `finalized`; fallback record lama = ada TTD pasien/keluarga.
- Entri final tak boleh ditimpa: `persistEntry()` melempar RuntimeException bila targetnya final.
- **Buka kunci** hanya mencabut `finalized` + **TTD petugas**; TTD pasien/keluarga & saksi
  DIPERTAHANKAN (tak boleh dihapus sepihak oleh staf) + audit log wajib menyebut pelakunya.
  Gate dua lapis: `@can('dokumen.bukaKunci')` di tombol **dan** `bolehBukaKunci()` (yang mengembalikan
  `auth()->user()?->can('dokumen.bukaKunci')`) di server.
- **Hapus entri** juga digate: `@can('dokumen.hapus')` di tombol + guard
  `if (!auth()->user()?->can('dokumen.hapus')) { toast; return; }` sebagai statement pertama
  method hapus. Berlaku baik untuk draft maupun entri final.
- **Role terpusat**: daftar role Hapus & Buka Kunci ada di **satu file** `App\Support\AksiRole`
  (konstanta `DOKUMEN_HAPUS` & `DOKUMEN_BUKA_KUNCI`, saat ini triad `Admin | Manager Umum | Manager Medis`), didaftarkan
  sebagai Gate `dokumen.hapus` & `dokumen.bukaKunci` di `AppServiceProvider::boot()`. Menambah role =
  ubah 1 file itu. **JANGAN** tulis `@hasanyrole('Admin|...')`/`hasAnyRole([...])` literal di modul dokumen.

## 4. Tanda tangan

| Pihak | Cara | Wajib? |
|---|---|---|
| Pasien / keluarga | `x-signature.signature-pad` → `signature-result` bila sudah ada | wajib |
| Saksi | idem | **WAJIB (`required`) saat kunci di semua dokumen ber-saksi** — tampilkan langsung, jangan sembunyikan di balik tombol (keputusan 2026-09-08; sebelumnya nullable) |
| Petugas | `x-signature.ttd-petugas` (`:framed=false`, `:allowClear=false`); saat sudah TTD komponen menampilkan **gambar TTD user** (`x-signature.ttd-gambar`, sumber `App\Support\TtdUser`) di kotak putih selebar kolom | wajib; menstempel nama+kode+jam user login. Kartu stempel bespoke dilarang |

- TTD ikut `rules()` (`'signature' => 'required|string'`) supaya error tampil **merah di
  kolomnya** + toast — jangan cek manual yang cuma memunculkan toast.
- Nama/waktu petugas TIDAK divalidasi: di-stempel oleh aksinya sendiri.
- Tiga kolom TTD dibuat **sama tinggi** (`items-stretch` + `h-full flex flex-col`, area TTD `flex-1`).
- Cetak: gambar TTD pasien dari base64 di JSON; TTD petugas dari `users.myuser_ttd_image`
  via `petugasCode`. Layout TTD di PDF wajib `<table>` (lihat `docs/ttd-pattern-pdf-print.md`).

## 5. Validasi — seminimal mungkin

Formulir klinis diisi bertahap. Mewajibkan banyak field hanya membuat petugas mengetik asal
supaya bisa mengunci. Wajibkan hanya: **tanggal**, **nama + hubungan penanda tangan**, **TTD**.
Aturan bersyarat dipakai hanya bila tanpa isian itu datanya tak bermakna
(mis. pilih "Donasi organ" → organnya wajib disebut).

Tanggal SELALU `date_format:d/m/Y H:i:s` (standar repo, ±95 tempat).

### 5a. Kolom tanggal/jam — teks + `x-now-button`, JANGAN `type="date"`/`type="time"`

Input HTML native menyimpan `Y-m-d` / `H:i`, bentrok dengan format sisa JSON EMR
(`d/m/Y H:i:s`) sehingga cetak & display tampil beda gaya, dan validasi
`date_format:d/m/Y H:i:s` otomatis gagal. Pakai `<x-text-input>` teks biasa:

```blade
{{-- tanggal + jam --}}
<div class="flex gap-1 mt-1">
    <x-text-input wire:model="newForm.FIELD" placeholder="dd/mm/yyyy HH:mm:ss" class="w-full" />
    <x-now-button wire:click="setNow('FIELD')" :disabled="$formReadOnly" />
</div>

{{-- tanggal murni (HPHT, tgl lahir, dll) --}}
<div class="flex gap-1 mt-1">
    <x-text-input wire:model="newForm.FIELD" placeholder="dd/mm/yyyy" class="w-full" />
    <x-now-button wire:click="setToday('FIELD')" :disabled="$formReadOnly" title="Set ke tanggal hari ini" />
</div>
```

Method baku di komponen — nama `setNow`/`setToday` (samakan lintas modul, jangan
`setJamSekarang`/`setTglSekarang`/`setTglJamSekarang`):

```php
public function setNow(string $field): void   { /* guard */ $this->newForm[$field] = Carbon::now(config('app.timezone'))->format('d/m/Y H:i:s'); }
public function setToday(string $field): void { /* guard */ $this->newForm[$field] = Carbon::now(config('app.timezone'))->format('d/m/Y'); }
```

Tombolnya di-gate lewat `:disabled="$formReadOnly"`, bukan dibungkus `@if (!$formReadOnly)`.
**Satu peristiwa = satu kolom**: jangan pasangan `xxxTgl` + `xxxJam` terpisah — gabung jadi
satu kolom datetime (`ketubanPecah`, `hisMulai`, …). Pasangan terpisah memaksa helper
`$tgljam($t,$j)` di tiap blade cetak dan gampang tidak sinkron.

### 5d. Data berulang di dalam satu entri = tabel entri, BUKAN entri dokumen terpisah

Kalau satu dokumen wajar berisi banyak baris sejenis (titik-waktu observasi, obat
pre-medikasi, riwayat kehamilan), simpan sebagai **`rows` di dalam SATU entri**, jangan
dijadikan banyak entri dokumen. Entri dokumen membawa siklus Draft→TTD→Kunci sendiri;
memakainya untuk tiap baris memaksa petugas menandatangani berulang padahal cetaknya
memang satu lembar.

Acuan: **Obat Pre Medikasi** di `pra-induksi-ri`. Bentuk bakunya:

- Property input baris di atas tabel: `public string $barisXxx = ''` per kolom.
- `barisKosong()` — bentuk satu baris; `normalizeRows(array $entry)` — `array_replace`
  tiap baris dengan `barisKosong()` supaya baris lama yang kolomnya belum lengkap aman,
  sekaligus membaca entri LEGACY yang masih datar (tanpa `rows`) sebagai satu baris.
- `tambahBaris()` — guard read-only → `validateWithToast()` (kolom kunci `required`,
  sisanya `nullable`) → push → `resetBarisInput()`. `hapusBaris(int $index)` → `unset` +
  `array_values`.
- Kolom waktu di-sort lewat `Carbon::createFromFormat('d/m/Y H:i:s', …)`, JANGAN
  leksikografis — `01/08` akan mendahului `02/07`.
- Markup: blok field (`@if (!$formReadOnly)`) → tombol **Tambah** full-width → tabel
  **baca-saja** `ds-table` `No | …kolom… | Aksi` dengan `x-confirm-button
  variant="danger-soft"` dan baris kosong `Belum ada …`. Tabel selalu tampil; saat
  read-only kolom Aksi jadi `—`.
- Cetak & viewer ikut per-entri: `cetak($createdAt)` mengambil `rows` dari entri itu,
  sedangkan `diagnosa`/`ttd` diambil dari ENTRI (bukan di-pluck dari baris).

### 5c. Penamaan variabel di blade cetak — nama LENGKAP

Blade cetak dulu memakai singkatan satu huruf (`$v`, `$c`, `$r`, `$k`, `$i`) sehingga sulit
diaudit programmer lain. Paling parah: `$id` di sana berarti **identitas pasien**, bukan
identifier, dan helper yang isinya identik dipakai dengan tiga nama berbeda
(`$v` / `$cell` / `$show`). Peta baku sekarang:

| Peran | Nama |
|---|---|
| `$data['identitas']` | `$identitas` (JANGAN `$id`) |
| helper ambil nilai dari `$form` by key | `$nilaiForm = fn(string $field) => …` |
| helper format satu nilai/sel tabel | `$nilaiSel = fn($nilai) => …` |
| helper ambil nilai dari satu baris | `$nilaiBaris = fn(array $baris, string $field) => …` |
| helper implode array jadi teks | `$nilaiFormList` |
| item loop baris | `$baris` |
| indeks loop | `$nomor` |
| parameter closure berisi nama field | `$field` (`$fieldTgl`/`$fieldJam` bila spesifik) |

Boleh tetap: `$data`, `$form`, `$row`/`$rows` (idiom repo), `$loop`. Beri type hint pada
parameter closure supaya niatnya terbaca tanpa menelusuri pemanggil.

**Verifikasi rename blade WAJIB render nyata** — `php -l` dan `view:cache` TIDAK menangkap
variabel yang putus. Render versi lama (`git show HEAD:<path>`) dan versi baru dengan payload
dummy yang sama, lalu bandingkan md5-nya; harus identik.

### 5b. Lebar isi modal = `max-w-full`

Body `<x-modal size="full">` diisi `<div class="max-w-full mx-auto space-y-4">`. Memakai
`max-w-5xl` menyisakan ruang kosong kiri-kanan yang mencolok pada formulir bergrid banyak
kolom (dikeluhkan user 2026-08-11 di Pengkajian Awal Obstetri).

## 6. Teks legal → clause-versioning

Kalimat pernyataan/persetujuan TIDAK ditulis inline di blade. Taruh di
`App\Support\<Nama>Clause`, stempel `clauseVersion` per entri, cetak pakai versi tersimpan.
Lihat `docs/clause-versioning.md` + skill `clause-versioning`.

## 7. Rancangan isi form (pelajaran dari Akhir Hayat)

- Pecah jadi **panel bernomor sama dengan formulir kertas** (`x-border-form :collapsible`),
  hanya panel awal `:open="true"`. Satu panel raksasa = melelahkan.
- Sub-kelompok di dalam panel diberi **bingkai** + judul kecil uppercase, dipasang kanan-kiri.
  Untuk pasien vs keluarga: dua kartu sejajar, isi & urutan field dibuat cermin.
- Skala keparahan (Tidak ada/Ringan/Sedang/Berat) lebih ringkas daripada belasan checkbox
  gejala — beri **warna gradasi** (hijau→kuning→oranye→merah) dan klik ulang = batal pilih.
- Gabungkan opsi bersinonim, TAPI **jangan gabungkan diagnosis keperawatan (SDKI) atau
  tindakan medis** — tiap butir keputusan klinis harus berdiri sendiri.
- Cek tumpang tindih antar bagian sebelum rilis (mis. "rencana rawat di rumah" vs "instruksi
  perawatan di rumah"; "dukungan/kunjungan" vs "intervensi keperawatan").
- Isi otomatis dari data yang sudah ada (diagnosis & TTV dari Pengkajian Awal), tetap bisa dikoreksi.

## 8. Jebakan Blade yang SUDAH pernah menggigit di modul ini

1. **Escape ganda pada prop yang di-echo ulang komponen.**
   `value="A &amp; B"` / `title=` / `nameLabel=` / `signLabel=` → komponen meng-echo `{{ }}`
   lagi → layar menampilkan `&amp;`. Tulis `&` polos di atribut komponen; `&amp;` hanya untuk
   teks HTML biasa. Untuk nilai dinamis pakai **`:value="$x"`**, bukan `value="{{ $x }}"`.
2. **Tag `x-...` di dalam komentar PHP tetap dikompilasi** → ParseError/`Undefined $component`.
   Sebut nama komponennya tanpa angle-bracket.
3. `x-radio-button` props aslinya `label/value/name` + `wire:model.live` (tidak ada
   `current`/`wireClick`); `signature-pad` hanya punya `wireMethod`. Jangan mengarang prop.
4. Verifikasi sebelum lapor: compile via `Blade::compileString` + `php -l`, hitung
   keseimbangan `<div>`/`<fieldset>`/`<x-border-form>`, dan render dengan data contoh
   lalu cek `Unexpected end tag` via DOMDocument.

> Catatan `Blade::compileString`: sebagian file host (mis. `cetak-rekam-medis-open`)
> **tidak** standalone-compilable (pakai `@if` yang terentang via `@include`/slot) —
> hasilnya "unexpected endif". Bandingkan dgn versi `git HEAD`: kalau HEAD gagal identik,
> itu pre-existing, bukan salahmu. Validasi final yang sahih = **`php artisan view:cache`**
> (EXIT 0 = semua view kompilasi lewat pipeline Blade asli), lalu `view:clear`.

---

## 9. Port ke jalur lain (RI ⇄ UGD ⇄ RJ)

Satu form dokumen sering harus tersedia di lebih dari satu jalur. **Jangan tulis ulang** —
salin file actions + cetak dari jalur acuan, lalu ganti token berikut (per-string, bukan
`RI→UGD` global karena banyak identifier mengandung "RI"):

| RI | UGD | RJ |
|----|-----|----|
| `Txn\Ri\EmrRITrait` | `Txn\Ugd\EmrUGDTrait` | `Txn\Rj\EmrRJTrait` |
| `?string $riHdrNo` | `?int $rjNo` | `?int $rjNo` |
| `$dataDaftarRi` | `$dataDaftarUGD` | `$dataDaftarPoliRJ` |
| `findDataRI` / `checkEmrRIStatus` | `findDataUGD` / `checkEmrUGDStatus` | `findDataRJ` / … |
| `updateJsonRI` / `appendAdminLogRI` / `lockRIRow` | `…UGD` | `…RJ` |
| key JSON `pengkajian<Dok>RI` | `pengkajian<Dok>UGD` | `…RJ` |
| modal `rm-<dok>-ri-` · area `modal-<dok>-ri` | `…-ugd` | `…-rj` |
| `pages::transaksi.ri.display-pasien-ri.display-pasien-ri :riHdrNo` | `pages::transaksi.ugd.display-pasien-ugd.display-pasien-ugd :rjNo` | `pages::transaksi.rj.display-pasien-rj.display-pasien-rj :rjNo` |
| prefill `pengkajianAwalPasienRawatInap…` | path EMR UGD (mis. `diagnosisFreeText`) | path EMR RJ |
| loadView `…r-i.<dok>.cetak-<dok>-ri-print` | `…u-g-d.<dok>.cetak-<dok>-print` | `…r-j.…` |

> ⚠ Ganti tag `display-pasien` **utuh termasuk prefix namespace** `pages::transaksi.<jalur>.` —
> jebakan nyata: replace per-token hanya mengganti sufiks komponen sehingga tersisa
> `pages::transaksi.ri.display-pasien-rj.…` (komponen salah jalur, tapi Blade tetap kompilasi).
> Sapu jaring pengaman: `grep -rn "pages::transaksi\.ri\." resources/views/pages/transaksi/{rj,ugd}/`.

Konvensi nama: folder/file **UGD/RJ membuang sufiks** `-ri` (`…/akhir-hayat/rm-akhir-hayat-actions`),
tapi **modal-name / renderArea / nama file PDF tetap** memakai `-ugd`/`-rj`. `regNo`/`regName`
tersedia di data UGD/RJ juga, jadi cetak inline + `MasterPasienTrait` tetap jalan.
`App\Support\*Clause` & `App\Support\*Options` (peta label cetak) **dipakai bersama** semua jalur —
jangan diduplikasi. Verifikasi: `view:cache` EXIT 0 + grep tidak ada token RI nyasar.

## 10. Viewer di display Rekam Medis (WAJIB saat menambah dokumen)

Menambah form baru **belum selesai** sampai dokumennya bisa dilihat di **display Rekam Medis**.
Pola viewer read-only (Lihat = render blade cetak ke iframe) ada di
`docs/dokumen-view-pattern.md`. Untuk tiap jalur yang dipasang:

1. Buat komponen viewer `resources/views/pages/components/rekam-medis/<jalur>/dokumen-view/<dok>-view-<jalur>.blade.php`.
2. **Daftarkan** di `…/rekam-medis/<jalur>/cetak-rekam-medis/cetak-rekam-medis-open.blade.php`
   (RI pakai var `$ri` + `:riHdrNo`; UGD pakai `$txn` + `:rjNo`), oper `:entries="$rec['pengkajian<Dok><Jalur>'] ?? []"`.
3. Dokumen dgn cetak **payload seragam** (dataRi/form/ttd) → pakai
   `DokumenViewSupportTrait::previewDokumenRi()/streamCetakDokumenRi()` langsung.
4. Dokumen dgn cetak **payload bespoke** (butuh `entry` + `opsiLabel` + `clause`, mis. Akhir Hayat)
   → viewer **self-contained**: pakai `pasienDokumen/identitasRsDokumen/renderDokumenPreview` + `TtdUser::pathBerkasDariKode`
   + `buildData()` yang meniru `cetak()` komponen EMR. Taruh peta label di
   `App\Support\<Dok>Options::labels()` supaya satu sumber untuk semua jalur.

## 11. Penanda tab (badge "ada data")

Tiap `<x-tab>` di hub `modul-dokumen-<jalur>.blade.php` **wajib** menampilkan badge saat
dokumennya sudah ada isi — supaya user tahu tab mana yang berisi tanpa membukanya. Hub sudah
memegang seluruh JSON EMR di `$dataDaftarRi` / `$dataDaftarUGD` / `$dataDaftarPoliRJ`, jadi badge
membaca langsung dari `$dataDaftar<Jalur>['<jsonKey>']`. Sisipkan **sebelum `</x-tab>`**, gaya
seragam `<x-badge variant="success" class="text-[10px] px-1.5 py-0">…</x-badge>`.

| Tipe dokumen | Guard | Isi badge |
|---|---|---|
| **multi** (array entri) | `@if (count($key ?? []) > 0)` | `{{ count($key) }}` (angka) |
| **single** (satu objek) | `@if (!empty($key['signature']))` / `['isFinal']` | `&#10003;` (atau `TTD`) |
| **dual** (mis. `formMPP`) | `@if ($n > 0)` dgn `$n = count(formA)+count(formB)` | `{{ $n }}` |
| **umbrella** (tab berisi banyak sub-dok, mis. Pelayanan Bedah / VK) | `@if (collect([...childKeys])->first(fn($k) => !empty($dataDaftar<Jalur>[$k])))` | `&#10003;` |

Contoh (multi): `@if (count($dataDaftarRi['informConsentPasienRI'] ?? []) > 0) <x-badge …>{{ count($dataDaftarRi['informConsentPasienRI']) }}</x-badge> @endif`.
Contoh (umbrella): daftar semua child key dokumen di dalam tab (Pelayanan Bedah = 8 dok bedah/anestesi;
VK/Kebidanan = 11 dok obstetri/neonatal) lalu `->first(...)` → `&#10003;` bila salah satu ada data.
`suket` (RJ/UGD) = container dua sub-key single → `!empty($key['suketSehat']) || !empty($key['suketIstirahat'])`.
