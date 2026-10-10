# PACS Orthanc — Persiapan Server & Sambungan ke ImagingStudy

Dokumen ini menyimpan **rencana dan langkah pemasangan PACS** yang dibutuhkan supaya
resource `ImagingStudy` SATUSEHAT kita bermakna. Ditulis 2026-08-24 sesudah uji kirim ke
staging. Statusnya **rencana** — servernya belum berdiri.

Terkait: `docs/radiologi-modul.md` (modul radiologi yang sekarang, berbasis unggah PDF),
`docs/satusehat-api.md` (§ImagingStudy).

---

## 1. Duduk perkara

`app/Http/Traits/SATUSEHAT/ImagingStudyTrait.php` sudah diuji ke **staging** dan
**diterima**: `ImagingStudy/16744a38-6141-43cc-ad1a-4c0280625374` (Encounter UGD 203859,
pasien `P02478375538`, THORAX PA/AP LOINC `36643-5` → modalitas DX).

Dua temuan yang menghemat waktu:

- Validator SATUSEHAT **tidak menuntut UID DICOM asli**
- `basedOn` (ServiceRequest) **tidak wajib**

Payload minimal lolos apa adanya: UID turunan arc `2.25`, SOP class *Secondary Capture*
(`1.2.840.10008.5.1.4.1.1.7`), `procedureCode` LOINC, `subject`, `encounter`, satu series
satu instance.

> **Lolos bentuk ≠ lolos makna.** UID yang kita kirim tidak menunjuk objek DICOM mana pun —
> tidak ada PACS yang bisa ditanya. Karena itu trait dipagari **staging** lewat komentar
> header. Produksi menunggu PACS yang menerbitkan UID nyata.

---

## 2. Prasyarat yang menentukan — dan ini bukan soal server

Orthanc hanyalah penampung. Yang menerbitkan Study/Series/SOP Instance UID adalah
**modalitas** (pesawat X-ray dan USG). Sebelum menyiapkan server apa pun, pastikan ke
vendor alat:

1. Alat punya **DICOM Store SCU** (bisa mengirim gambar ke node lain)?
2. Alat punya **Modality Worklist SCU** (bisa menarik daftar pasien dari SIMRS)?
3. Kedua fitur itu **sudah termasuk atau lisensi berbayar terpisah?**

Modul radiologi kita sekarang berbasis **unggah PDF** — yang menyiratkan alatnya belum
tentu DICOM. Kalau alat hanya mencetak film atau menyimpan JPEG ke USB, Orthanc akan
berdiri kosong dan kita tetap tidak punya UID asli.

---

## 3. Ukuran server — dari volume nyata, bukan tebakan

Rekap 12 bulan (`rstxn_rjrads` + `rstxn_ugdrads` + `rstxn_riradiologs`):

| Kelompok | Jumlah | Porsi | Per bulan |
|---|---:|---:|---:|
| Foto polos / lain | 4.145 | 86% | ~345 |
| USG | 695 | 14% | ~58 |
| CT / MRI | 1 | 0% | ~0 |
| **Total** | **4.841** | | **~403** |

Konsekuensinya:

- Foto polos ~25 MB/studi, USG ~15 MB → **±10 GB/bulan, ±115 GB/tahun**
- **Disk 1 TB cukup ~8 tahun.** 2 vCPU / 4 GB RAM sudah lega
- **PostgreSQL tidak perlu.** SQLite bawaan Orthanc jauh di atas cukup untuk angka ini —
  jangan menambah komponen yang tidak menghasilkan apa-apa

Kalau RS membeli CT, hitungan ini berubah drastis (satu studi CT 100–500 MB).

**Kenapa Orthanc:** C++, satu proses, ~50 MB RAM diam, REST API bawaan, GPLv3 gratis
penuh. `dcm4chee-arc` lebih lengkap tapi butuh Java + PostgreSQL + Keycloak — berlebihan
untuk 400 pemeriksaan/bulan.

---

## 4. Pemasangan (Ubuntu Server di Proxmox)

### A. VM/LXC

- **Ubuntu Server 24.04 LTS** (bukan Mint — itu distro desktop)
- 2 vCPU, 4 GB RAM
- Disk sistem 32 GB + **disk kedua terpisah** 500 GB–1 TB khusus gambar
- Bridge ke VLAN yang sama dengan alat radiologi, **IP statis**

Disk gambar dipisah supaya bisa dibesarkan tanpa menyentuh root.

Catatan Proxmox: kalau memasang lewat **Docker**, pakai **VM** — Docker di dalam LXC
unprivileged rewel. Kalau lewat `apt` (jalur di bawah), **LXC** justru enak dan ringan.

### B. Sistem dasar

```bash
sudo apt update && sudo apt full-upgrade -y
sudo timedatectl set-timezone Asia/Jakarta
```

IP statis — `sudo nano /etc/netplan/50-cloud-init.yaml`:

```yaml
network:
  version: 2
  ethernets:
    ens18:
      dhcp4: no
      addresses: [192.168.1.60/24]
      routes:
        - to: default
          via: 192.168.1.1
      nameservers:
        addresses: [192.168.1.1, 8.8.8.8]
```

```bash
sudo netplan apply
```

Jam harus benar: DICOM mencatat waktu di setiap studi, jam yang meleset mengacaukan urutan
pemeriksaan.

### C. Disk gambar

```bash
lsblk                                   # cari disk kedua, mis. /dev/sdb
sudo mkfs.ext4 /dev/sdb
sudo mkdir -p /data/orthanc
echo "/dev/sdb /data/orthanc ext4 defaults 0 2" | sudo tee -a /etc/fstab
sudo mount -a
df -h /data/orthanc
```

### D. Pasang Orthanc

```bash
sudo apt install -y orthanc orthanc-dicomweb
```

Pastikan plugin worklist ikut — inilah yang mengikat order SIMRS ke gambar:

```bash
apt search orthanc 2>/dev/null | grep -i orthanc
ls /usr/share/orthanc/plugins/
Orthanc --version
```

Kalau `libModalityWorklists.so` tidak ada, atau versinya terlalu tua, pakai image Docker
resmi `orthancteam/orthanc` sebagai gantinya — paket Ubuntu kadang tertinggal beberapa
rilis.

### E. Konfigurasi

Ubuntu/Debian memuat **semua** `.json` di `/etc/orthanc/`, jadi setelan boleh dipecah.

```bash
sudo mkdir -p /data/orthanc/db /data/orthanc/worklists
sudo chown -R orthanc:orthanc /data/orthanc
sudo nano /etc/orthanc/orthanc.json
```

```json
{
  "Name": "PACS RS Islam Madinah",
  "StorageDirectory": "/data/orthanc/db",
  "IndexDirectory": "/data/orthanc/db",

  "DicomAet": "ORTHANC_RSIM",
  "DicomPort": 4242,

  "HttpPort": 8042,
  "RemoteAccessAllowed": true,
  "AuthenticationEnabled": true,
  "RegisteredUsers": { "sirus": "GANTI_PASSWORD_KUAT" },

  "DicomAlwaysAllowStore": false,
  "DicomModalities": {
    "xray1": [ "AET_ALAT_XRAY", "192.168.1.50", 104 ],
    "usg1":  [ "AET_ALAT_USG",  "192.168.1.51", 104 ]
  },

  "Worklists": {
    "Enable": true,
    "Database": "/data/orthanc/worklists"
  }
}
```

`DicomAlwaysAllowStore: false` = hanya alat terdaftar yang boleh menyetor gambar. Isi
`DicomModalities` setelah dapat AE Title + IP dari vendor.

```bash
sudo systemctl restart orthanc
sudo systemctl enable orthanc
sudo systemctl status orthanc
sudo journalctl -u orthanc -n 50 --no-pager     # baca kalau gagal start
```

### F. Firewall

```bash
sudo ufw allow from 192.168.1.0/24 to any port 4242 proto tcp
sudo ufw allow from 192.168.1.0/24 to any port 8042 proto tcp
sudo ufw allow from 192.168.1.0/24 to any port 22 proto tcp
sudo ufw enable
```

Batasi ke subnet LAN — **jangan** `allow 8042` polos.

### G. Uji

```bash
# 1. REST hidup?
curl -u sirus:PASSWORD http://192.168.1.60:8042/system

# 2. DICOM hidup? (dari mesin lain di LAN)
sudo apt install -y dcmtk
echoscu -aec ORTHANC_RSIM 192.168.1.60 4242

# 3. kirim gambar contoh
storescu -aec ORTHANC_RSIM 192.168.1.60 4242 contoh.dcm

# 4. cari berdasarkan Accession Number — inti sambungan ke SIMRS
curl -u sirus:PASSWORD -X POST http://192.168.1.60:8042/tools/find \
  -d '{"Level":"Study","Query":{"AccessionNumber":"R00123"}}'
```

Antarmuka web: `http://192.168.1.60:8042`

### H. Cadangan

Snapshot Proxmox saja **tidak cukup** kalau `/data` ada di disk terpisah — pastikan disk itu
ikut jadwal backup, atau `rsync /data/orthanc` ke NAS. PACS itu rekam medis; kehilangan
datanya bukan sekadar gangguan operasional.

---

## 5. Sambungan ke SIMRS

Tali pengikatnya **sudah ada**: kolom `RADNUM_NO` di ketiga tabel order.

| Tabel | Kunci | Kolom nomor radiologi |
|---|---|---|
| `RSTXN_RJRADS` | `RJ_NO`, `RAD_DTL` | `RADNUM_NO` |
| `RSTXN_UGDRADS` | `RJ_NO`, `RAD_DTL` | `RADNUM_NO` |
| `RSTXN_RIRADIOLOGS` | `RIHDR_NO`, `RIRAD_NO` | `RADNUM_NO` |

> Nama tabel RI adalah **`RSTXN_RIRADIOLOGS`**, bukan `rstxn_rirads`. Mudah keliru karena
> RJ/UGD memakai pola `*rads`.

Alurnya:

1. Order dibuat di EMR → SIMRS menulis berkas worklist `.wl` ke `/data/orthanc/worklists`
   berisi nama pasien, No. RM, dan **`RADNUM_NO` sebagai AccessionNumber**
2. Petugas memilih pasien **dari layar alat** — sekaligus menghapus salah ketik identitas
3. Alat memotret, mengirim via C-STORE. UID diterbitkan alat, **asli**
4. SIMRS bertanya `POST /tools/find` dengan `AccessionNumber = RADNUM_NO` → dapat
   **StudyInstanceUID nyata**
5. UID itu menggantikan UID karangan di `ImagingStudy` → kiriman SATUSEHAT bisa ditelusuri

**DDL** untuk langkah 4–5 → `docs/ddl-pacs-study-uid.sql` (**sudah dijalankan**):

```sql
ALTER TABLE RSTXN_RJRADS ADD (STUDY_UID VARCHAR2(64));
ALTER TABLE RSTXN_UGDRADS ADD (STUDY_UID VARCHAR2(64));
ALTER TABLE RSTXN_RIRADIOLOGS ADD (STUDY_UID VARCHAR2(64));
```

Sesudah PACS berdiri, `uidStudi()` di `ImagingStudyTrait` **tidak dihapus** melainkan jadi
cadangan: dipakai hanya bila `STUDY_UID` kosong, dan idealnya kiriman semacam itu ditahan
saja daripada mengirim UID yang tak bisa ditelusuri.

---

## 5b. OrthancTrait — koneksi SIRUS → Orthanc

**File:** `app/Http/Traits/SATUSEHAT/OrthancTrait.php`

Trait ini menyambungkan SIRUS ke Orthanc via REST API. Konfigurasi di `.env`:

```env
ORTHANC_URL=http://localhost:8042
ORTHANC_USER=sirus
ORTHANC_PASSWORD=<password>
```

### Method yang tersedia

| Method | Fungsi |
|---|---|
| `cariStudyUid($accessionNumber)` | Query `/tools/find` by AccessionNumber → return `StudyInstanceUID` atau `null` |
| `sinkronStudyUid($tabel, $where, $radnumNo)` | Cari UID + simpan ke kolom `STUDY_UID` per row |
| `sinkronStudyUidBatch($tabel, $pkRef, $pkDtl, $limit)` | Batch: semua row yang punya `RADNUM_NO` tapi `STUDY_UID` kosong |

### Cara pakai di Livewire / class

```php
use App\Http\Traits\SATUSEHAT\OrthancTrait;

// Cari UID satu order:
$uid = $this->cariStudyUid('R00123');

// Sinkron satu row RJ:
$this->sinkronStudyUid('rstxn_rjrads', ['rj_no' => $rjNo, 'rad_dtl' => $dtl], $radnumNo);

// Batch sinkron semua RJ yang belum ada STUDY_UID:
$count = $this->sinkronStudyUidBatch('rstxn_rjrads', 'rj_no', 'rad_dtl');
```

### Alur kirim ImagingStudy ke SATUSEHAT (end-to-end)

```
┌─────────────┐     ┌──────────┐     ┌─────────────────┐     ┌───────────┐
│ Order Rad   │────▶│ Orthanc  │────▶│ SIRUS           │────▶│ SATUSEHAT │
│ (RADNUM_NO) │     │ /find    │     │ ImagingStudy    │     │ POST      │
│             │     │          │     │ Trait            │     │           │
│ AccessionNo │     │ StudyUID │     │ UID asli/turunan│     │ FHIR R4   │
└─────────────┘     └──────────┘     └─────────────────┘     └───────────┘
```

1. Petugas membuat order radiologi di EMR → `RADNUM_NO` terisi (= AccessionNumber DICOM)
2. Alat radiologi memotret, mengirim gambar ke Orthanc via C-STORE
3. SIRUS memanggil `cariStudyUid($radnumNo)` → dapat `StudyInstanceUID` asli
4. UID disimpan ke `STUDY_UID` di tabel order
5. `ImagingStudyTrait::postImagingStudy()` memakai UID asli dari `STUDY_UID`
6. Kalau `STUDY_UID` kosong (PACS belum berdiri / alat belum DICOM), fallback ke `uidStudi()` — UID turunan arc `2.25`

### Catatan penting

- **RADNUM_NO harus terisi** sebelum alur ini jalan — saat ini 0 dari 11.404 row RJ yang punya `RADNUM_NO`
- Perlu mekanisme generate `RADNUM_NO` otomatis saat order dibuat
- `STUDY_UID` hanya terisi kalau gambar sudah masuk Orthanc (alat sudah DICOM)

---

## 6. Keamanan

- Orthanc bawaan punya user `orthanc`/`orthanc` — **wajib diganti**, dan
  `AuthenticationEnabled` jangan dimatikan
- **Port 8042 & 4242 jangan menyentuh internet.** Isinya foto medis lengkap dengan
  identitas pasien. Cukup LAN; kalau perlu akses luar, lewat VPN
- `DicomAlwaysAllowStore: false` supaya bukan sembarang node bisa menyetor gambar

---

## 7. Jebakan yang sudah kena sekali

Kelas anonim yang `use SatuSehatTrait` **wajib** memanggil `initializeSatuSehat()`. Kalau
tidak, `clientId`/`baseUrl` bernilai null dan errornya menyamar jadi
`Failed to get access token:` dengan badan kosong — sempat terbaca seperti gangguan DNS
selama beberapa percobaan.

DNS ke `api-satusehat-stg.dto.kemkes.go.id` dari mesin dev memang intermiten; bungkus
pemanggilan uji dengan retry.

---

## 8. Yang perlu disiapkan sebelum melangkah

1. Jawaban vendor: alat X-ray & USG mendukung DICOM Store + Worklist? Berbayar?
2. AE Title dan IP tiap alat
3. VM/LXC Proxmox, IP statis, disk data ≥ 500 GB
4. Rencana cadangan
