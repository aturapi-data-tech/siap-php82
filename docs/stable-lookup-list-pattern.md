# Stable Lookup List Pattern

Pola dependency untuk dropdown/opsi filter (mis. **dokterList**, **poliList**, dll.) di halaman listing transaksi (Daftar RJ/UGD/RI, Pelayanan, Apotek, Kasir, Manajemen). Tujuannya: dropdown filter tidak "lenyap" atau berubah-ubah saat user pindah filter lain di halaman yang sama.

Sebelumnya `dokterList()` ikut menyaring berdasarkan `filterStatus`, `filterKlaim`, `searchKeyword`. Saat user pindah dari status `A` ke `S`, opsi dokter berubah karena query backing list-nya juga ke-filter status. User kehilangan persepsi "siapa saja yang praktek hari ini" + dokter yang sudah dipilih bisa hilang dari dropdown.

---

## 1. Prinsip dasar

**Lookup list (dropdown filter) hanya boleh depend pada SATU dimensi waktu** — tanggal atau range tanggal. Filter operasional lain (status, klaim, poli, searchKeyword, dll.) **tidak boleh** masuk ke query lookup list.

### Mental model

```
LOOKUP LIST (dokterList, poliList, dll.)
  ↑ depend: tanggal / range tanggal  ✓
  ✗ depend: status, klaim, poli, searchKeyword  ✗

MAIN ROWS (data tabel utama)
  ↑ depend: SEMUA filter (tanggal + status + klaim + poli + search)
```

User-side:
- Pilih dokter dari dropdown → main rows ke-filter ke dokter itu
- Pindah status → main rows berubah, **dropdown dokter tetap**
- Cari nama pasien → main rows berubah, **dropdown dokter tetap**
- Ganti tanggal → main rows + dropdown dokter dua-duanya berubah (memang harus, karena beda hari = beda dokter praktek)

### Kenapa hanya tanggal yang boleh trigger refresh dropdown?

Karena **persepsi user** soal "dokter yang relevan" terikat ke tanggal. Per tanggal kerja, dokter X praktek di poli Y. Status pasien (Antri/Periksa/Selesai) atau jenis klaim (BPJS/UMUM) **bukan atribut dokter** — itu atribut visit/encounter. Filter status mempersempit visit yang ditampilkan, bukan dokternya.

---

## 2. Implementasi standar — `dokterList()` computed property

```php
#[Computed]
public function dokterList()
{
    // NOTE: dokterList HANYA depend pada tanggal — "semua dokter yang praktek
    // pada tanggal/periode tersebut". Filter lain (status, klaim, searchKeyword)
    // sengaja TIDAK dipakai supaya opsi dropdown stabil: user bisa pindah-pindah
    // status/klaim tanpa kehilangan dokter yang sudah dipilih, meskipun query
    // utama jadi kosong.
    return DB::table('rstxn_rjhdrs')   // ganti tabel sesuai modul (rstxn_ugdhdrs, rstxn_rihdrs)
        ->select(
            'rstxn_rjhdrs.dr_id',
            DB::raw('MAX(rsmst_doctors.dr_name) as dr_name'),
            'rstxn_rjhdrs.poli_id',
            DB::raw('MAX(rsmst_polis.poli_desc) as poli_desc'),
            DB::raw('COUNT(DISTINCT rstxn_rjhdrs.rj_no) as total_pasien'),
        )
        ->join('rsmst_doctors', 'rsmst_doctors.dr_id', '=', 'rstxn_rjhdrs.dr_id')
        ->join('rsmst_polis', 'rsmst_polis.poli_id', '=', 'rstxn_rjhdrs.poli_id')
        ->where(DB::raw("to_char(rstxn_rjhdrs.rj_date, 'dd/mm/yyyy')"), '=', $this->filterTanggal)
        // ↑ atau ->whereBetween('rstxn_rjhdrs.rj_date', [$start, $end]) untuk range bulanan
        ->groupBy('rstxn_rjhdrs.dr_id', 'rstxn_rjhdrs.poli_id')
        ->orderBy('poli_desc')
        ->orderBy('dr_name')
        ->get();
}
```

### Pilih `to_char()` vs `whereBetween`

- **Daily filter** (mis. Pelayanan RJ — tanggal tunggal): pakai `to_char(rj_date, 'dd/mm/yyyy') = $this->filterTanggal`. String comparison cocok karena `filterTanggal` UI-binding string `dd/mm/yyyy`.
- **Range filter** (mis. Daftar Bulanan): pakai `whereBetween('rj_date', [$start, $end])`. Lebih cepat karena index `rj_date` kepakai (`to_char()` mematikan index Oracle).

### Format output

Per row: `dr_id`, `dr_name`, `poli_id`, `poli_desc`, `total_pasien`. Group by `dr_id + poli_id` supaya satu dokter yang praktek di banyak poli muncul terpisah (relevan untuk dokter shared, misal Sp.A yang juga di poli umum).

`total_pasien` berguna untuk tampil sebagai counter di dropdown — "dr. X (12)" — biar user tahu volumenya tanpa harus click filter. Hitungan ini cuma "total praktek di tanggal itu" (tidak bergantung status), jadi ikut stabilitas filter.

---

## 3. Cakupan implementasi saat ini

Total **17 file** punya `dokterList()` — semua sudah comply ke pola ini per 2026-05-22.

**6 file yang di-fix (sebelumnya kena bug filter operasional):**

| File | Modul | Filter tanggal |
|---|---|---|
| `transaksi/rj/pelayanan-rj/⚡pelayanan-rj.blade.php` | Pelayanan RJ (harian) | `to_char()` daily |
| `transaksi/rj/daftar-rj/⚡daftar-rj.blade.php` | Daftar RJ (range) | `whereBetween` |
| `transaksi/rj/daftar-rj-bulanan/⚡daftar-rj-bulanan.blade.php` | Daftar RJ Bulanan | `whereBetween` |
| `transaksi/ugd/daftar-ugd/⚡daftar-ugd.blade.php` | Daftar UGD (harian) | `to_char()` daily |
| `transaksi/ugd/pelayanan-ugd/⚡pelayanan-ugd.blade.php` | Pelayanan UGD (harian) | `to_char()` daily |
| `transaksi/ugd/daftar-ugd-bulanan/⚡daftar-ugd-bulanan.blade.php` | Daftar UGD Bulanan | `whereBetween` |

**11 file yang dari awal sudah benar (no fix needed):**

- `transaksi/ri/daftar-ri/⚡daftar-ri.blade.php` — pakai `NVL(ri_status,'I')='I'` (Inap aktif, snapshot natural)
- `transaksi/ri/daftar-ri-bulanan/⚡daftar-ri-bulanan.blade.php` — `whereBetween('exit_date')`
- `transaksi/apotek/antrian-apotek-ri/antrian-apotek-ri.blade.php` — `whereBetween('sls_date') + whereNotNull('rihdr_no')`
- `transaksi/kasir/antrian-kasir-ri/⚡antrian-kasir-ri.blade.php` — sama dengan apotek RI
- `transaksi/ugd/antrian-apotek-ugd/⚡antrian-apotek-ugd.blade.php` — `whereBetween('rj_date') + klaim_id != 'KR'` (KR=batal, structural exclusion)
- `transaksi/ugd/antrian-kasir-ugd/⚡antrian-kasir-ugd.blade.php` — sama dengan apotek UGD
- `transaksi/rj/antrian-apotek-rj/⚡antrian-apotek-rj.blade.php` — sama
- `transaksi/rj/antrian-kasir-rj/⚡antrian-kasir-rj.blade.php` — sama
- `manajemen/rs/ugd/laporan-task-id-ugd/⚡laporan-task-id-ugd.blade.php` — laporan
- `manajemen/rs/rj/laporan-task-id-rj/⚡laporan-task-id-rj.blade.php` — laporan
- `manajemen/rs/tu/pendapatan-jasa-dokter/⚡pendapatan-jasa-dokter.blade.php` — laporan keuangan

### Catatan exception: structural filter (bukan operasional)

Beberapa file pakai filter tambahan SELAIN tanggal yang **bukan filter operasional user** — tetap acceptable:

- `klaim_id != 'KR'` di Apotek/Kasir RJ+UGD — exclude visit yang sudah dibatalkan. Ini struktural (selalu aktif, tidak di-toggle user).
- `whereNotNull('rihdr_no')` di Apotek/Kasir RI — marker khusus untuk RI saja (bukan RJ). Struktural.
- `NVL(ri_status,'I')='I'` di Daftar RI harian — "yang sedang Inap" (snapshot). Bisa dianggap pengganti filter tanggal untuk modul snapshot.

Kuncinya: structural filter adalah kondisi yang **tidak diubah user via UI**. Filter operasional (status, klaim_id yang user toggle, searchKeyword) ≠ structural.

---

## 4. Kapan TIDAK pakai pola ini

Pola di atas berlaku untuk **lookup dropdown filter di halaman listing transaksi**. Beberapa kasus yang **tidak** cocok:

### 4.1 Master/setting (mis. /master/dokter)

Master Dokter punya tabel sendiri yang murni metadata user — list dokter di sini tidak depend ke transaksi sama sekali. Sumbernya `rsmst_doctors` langsung, tanpa join ke `rstxn_*hdrs`.

### 4.2 Dropdown picker untuk transaksi baru

Saat user buat transaksi baru (mis. SEP, SKDP, Rujukan), dropdown dokter umumnya mau memunculkan **semua dokter aktif** (terlepas dari hari ini praktek atau tidak). Tabel sumbernya `rsmst_doctors` dengan filter `active_status='1'`.

### 4.3 Form filter yang TIDAK paginated (single-query report)

Mis. laporan jadwal dokter, laporan jaga dokter — dokter di sini bisa muncul semua atau ter-filter manual via search box. Tidak terkait dengan listing transaksi paginated.

---

## 5. Pola serupa untuk dropdown lain

Prinsip "stable lookup list" yang sama bisa diterapkan untuk dropdown lain di halaman listing:

### `poliList()` — Daftar Poli

Tidak ada bug ini di codebase saat ini karena `poliList()` biasanya pakai sumber `rsmst_polis` langsung (tanpa join transaksi) — sudah stabil by design.

Kalau ingin pola "poli yang ada transaksinya hari ini saja", boleh pakai:

```php
#[Computed]
public function poliList()
{
    // Sama prinsip: HANYA filter tanggal, tidak ikut filter operasional
    return DB::table('rstxn_rjhdrs')
        ->select('rstxn_rjhdrs.poli_id', DB::raw('MAX(rsmst_polis.poli_desc) as poli_desc'))
        ->join('rsmst_polis', 'rsmst_polis.poli_id', '=', 'rstxn_rjhdrs.poli_id')
        ->whereBetween('rstxn_rjhdrs.rj_date', [$start, $end])
        ->groupBy('rstxn_rjhdrs.poli_id')
        ->orderBy('poli_desc')
        ->get();
}
```

### `bangsalList()` — Daftar Bangsal (RI)

Kalau pakai listing per range tanggal, sama: depend tanggal saja, **bukan** `filterStatus`/`filterKlaim`.

### `klaimList()` — Daftar Jenis Klaim

Hampir selalu di-render dari `rsmst_klaims` (master) — tidak perlu pola ini. Klaim itu master, bukan derived dari transaksi.

---

## 6. Anti-pattern (jangan diulang)

```php
// ❌ JANGAN — bikin dokterList tergantung filterStatus / filterKlaim
public function dokterList()
{
    $query = DB::table('rstxn_rjhdrs')->whereBetween('rj_date', [$start, $end]);

    if (!empty($this->filterStatus)) {
        $query->where('rj_status', $this->filterStatus);   // ❌ ini bikin dropdown lenyap saat ganti status
    }
    if (!empty($this->filterKlaim)) {
        $query->where('klaim_id', $this->filterKlaim);     // ❌ idem
    }
    if (!empty($this->searchKeyword)) {
        $query->where('reg_name', 'like', "%$kw%");        // ❌ ini bikin dropdown lenyap saat user ngetik
    }

    return $query->get();
}
```

**Tanda-tanda anti-pattern:**
- Ada `if (!empty($this->filterX))` di dalam dokterList/poliList/bangsalList kecuali `filterTanggal`/range tanggal.
- Komentar `"langsung query agar selalu fresh saat filter berubah"` — fresh terhadap filter operasional itu justru bug, bukan fitur.
- User report "dokter saya hilang dari dropdown" / "kok pas ganti status dokter berubah" → biasanya akar masalah di sini.

---

## 7. Checklist saat menambah halaman listing baru dengan filter dokter

- [ ] Pakai `#[Computed] public function dokterList()` (Livewire 3 computed property).
- [ ] Query SELECT dr_id + dr_name + poli + total_pasien dari tabel transaksi (`rstxn_*hdrs`) JOIN `rsmst_doctors` + `rsmst_polis`.
- [ ] WHERE clause **HANYA** `rj_date = filterTanggal` (daily) atau `whereBetween('rj_date', [$start, $end])` (range).
- [ ] JANGAN tambah `if filterStatus/filterKlaim/filterPoli/searchKeyword` di dokterList.
- [ ] GROUP BY `dr_id + poli_id`, ORDER BY `poli_desc`, `dr_name`.
- [ ] Tambah NOTE comment di atas query menjelaskan kenapa cuma tanggal yang dipakai (supaya reviewer/developer berikutnya tidak nambah filter karena "lupa" pertimbangan UX-nya).
- [ ] Test manual: pilih dokter → ganti status → konfirmasi dokter tetap di dropdown.

---

## 8. Referensi

- Commit fix awal `463ea84` (2026-05-21) — decouple `searchKeyword` saja
- Commit fix lanjutan (sesi 2026-05-22) — decouple `filterStatus` juga di 4 modul: Pelayanan RJ, Daftar RJ, Daftar RJ Bulanan, Daftar UGD Bulanan
- Contoh implementasi: `resources/views/pages/transaksi/rj/pelayanan-rj/⚡pelayanan-rj.blade.php` line ~322
