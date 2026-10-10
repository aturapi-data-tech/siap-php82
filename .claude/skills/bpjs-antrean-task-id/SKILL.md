---
name: bpjs-antrean-task-id
description: Konsep taskId 1–7 & 99 (Antrean/Antrol BPJS) di node JSON taskIdPelayanan — struktur, siapa mengeset apa di RJ/UGD/RI-Resep, dan jebakannya. Baca sebelum menambah/mengubah titik yang mencatat waktu layanan (pendaftaran, poli, apotek, pembatalan) atau saat waktu antrean tidak terkirim / terkirim dobel ke BPJS.
---

# BPJS Antrean — taskId 1–7 & 99

Ini konsep **Antrean BPJS (Antrol)**, BUKAN SATUSEHAT. Rumah sakit melaporkan
stempel waktu tiap tahap layanan; BPJS memakainya menghitung waktu tunggu.

## 1. Di mana datanya

| Modul | Lokasi node |
|---|---|
| RJ / UGD | `taskIdPelayanan` di **akar** `dataDaftarPoliRJ` / `dataDaftarUGD` |
| RI-Resep | `apotekHdr[i].taskIdPelayanan` — **per lembar resep**, bukan di akar |

Tiap task menyimpan DUA key berpasangan: nilainya (waktu, format `d/m/Y H:i:s`) dan
hasil kiriman ke BPJS (`taskId6` + `taskId6Status`, diisi `metadata.code`, mis. 200/208).
Rangkanya didefinisikan di `EmrRJTrait` / `EmrUGDTrait` (`taskIdPelayanan` berisi
taskId1..taskId7, taskId99, plus `tambahPendaftaran`).

Pengirimnya satu: `App\Http\Traits\BPJS\AntrianTrait::update_antrean($kodebooking, $taskid, $waktuTimestamp, $jenisresep)`.
Perhatikan **waktu dikirim sebagai UNIX timestamp** (`Carbon::createFromFormat('d/m/Y H:i:s', ...)->timestamp`),
sedangkan yang DISIMPAN di JSON teks `d/m/Y H:i:s`. Jangan tertukar.

## 2. Siapa mengeset apa (kondisi kode saat ini)

| task | arti | diset di |
|---|---|---|
| 1 | admisi daftar pasien baru | `daftar-rj-actions` saat pendaftaran, dari data pasien |
| 2 | pasien dipanggil admisi | `daftar-rj-actions` |
| 3 | pasien daftar poli | **5 tempat**: `task-id-3`, `booking-rj`, `daftar-rj-actions` (2×), `EmrRJTrait`/`EmrUGDTrait` |
| 4 | pasien masuk poli | `task-id-poli-actions` (RJ) |
| 5 | pasien keluar poli | `task-id-poli-actions` (RJ) |
| 6 | pasien masuk apotek | `task-id-apotek-actions` RJ, UGD, RI-Resep |
| 7 | obat diserahkan ke pasien | `task-id-apotek-actions` RJ, UGD, RI-Resep |
| 99 | pembatalan | `task-id-99` (RJ) |

Arti task 1–7 dikonfirmasi user 2026-08-03. Task 99 disimpulkan dari nama berkas & alurnya
(pembatalan), belum dikonfirmasi — jangan menambah arti lain dari hafalan.

Urutannya menggambarkan perjalanan pasien: **daftar → dipanggil admisi → daftar poli →
masuk poli → keluar poli → masuk apotek → obat diterima**. Selisih antar-stempel itulah
waktu tunggu yang dinilai BPJS, jadi stempel yang telat/dobel bukan sekadar kosmetik.

## 3. Jebakan

**a. Task 3 diset dari 5 tempat, dengan sumber waktu berbeda** (`rjDate`, `now()`, waktu
booking). Sebelum menambah jalur baru yang menyentuh task 3, cek dulu kelimanya — kalau
tidak, waktunya bisa ketimpa nilai yang lebih lama/lebih baru tanpa ada yang sadar.

**b. RI-Resep menyimpannya per lembar.** Satu perawatan bisa punya banyak lembar resep,
masing-masing punya task 6/7 sendiri. Kode yang menganggap `$data['taskIdPelayanan']` selalu
ada di akar akan diam-diam salah untuk RI (pola beda-struktur yang sama seperti e-resep,
lihat `App\Support\EresepJson`).

**c. Panggilan API BPJS WAJIB di luar DB transaction.** Pola yang dipakai
`task-id-apotek-actions` & `task-id-3`: siapkan nilai → panggil `update_antrean` di luar →
baru `lockRJRow` + patch JSON di dalam transaksi. Menahan row lock selama HTTP call ke BPJS
membuat baris terkunci selama jaringan lambat.

**d. Selalu idempoten.** Semua titik memakai pola "set hanya bila kosong" dan "kirim hanya
bila `taskNStatus` belum 200/208". Tanpa itu, klik dua kali mengirim stempel waktu kedua
yang menggeser hitungan waktu tunggu di BPJS.

**e. Patch hanya key `taskIdPelayanan`.** Jangan menulis ulang seluruh JSON kunjungan dari
memori komponen — modul lain (EMR, e-resep) menulis ke JSON yang sama.

## 4. Kaitan

- `booking-rj` mengeset task 3 saat pasien datang dari booking Mobile JKN.
- Kartu antrean apotek (`antrian-apotek-{rj,ugd}`) membaca task 6/7 untuk menandai
  resep yang sudah diserahkan.
- Nomor antrean apotek dikirim terpisah (`tambahAntrianApotek`), statusnya juga disimpan
  di node yang sama.
