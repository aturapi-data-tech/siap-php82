-- =============================================================
-- DDL: EWS — Early Warning System (master parameter, rentang skor, respon)
-- Jalankan di Oracle sebagai user pemilik schema SIRUS.
-- Target: Oracle 10g (batas nama objek 30 karakter — semua nama di bawah aman).
--
-- BERSIH-PASANG: berkas ini MENGHAPUS dulu lalu MEMBUAT ulang. Bagian A wajar
-- mengeluarkan error "objek tidak ada" di environment yang masih bersih.
--
-- ⚠️ BAGIAN A MENGHAPUS TABEL BESERTA ISINYA. Master EWS bisa dipulihkan lewat
--    `php artisan ews:seed --force`, jadi risikonya hanya kustomisasi ambang yang
--    pernah diubah RS lewat /master/ews. Ekspor dulu bila ada.
--
-- ⚠️ SEBELUM MENJALANKAN: periksa dulu apakah tabel senama sudah ada dari sumber
--    lain (Oracle Dev 6i): SELECT table_name FROM user_tables WHERE table_name LIKE 'RSMST_EWS%';
--    Kalau sudah ada dan bukan dari berkas ini, hentikan dan periksa.
--
-- APA ITU EWS. Skor peringatan dini dari tanda vital rutin: tiap parameter diberi
-- skor 0-3 sesuai rentang, dijumlah, lalu total menentukan FREKUENSI pantau ulang
-- dan RESPON (siapa yang dihubungi). Ada 4 varian (kolom VARIAN):
--   DEWASA   = NEWS2 (RM 93a), pasien >= 16 tahun
--   ANAK     = PEWS  (RM 93b), 29 hari s.d. < 16 tahun
--   NEONATUS = EWS neonatus (RM 93d), 0-28 hari
--   MEOWS    = obstetri (RM 93c), dipilih manual oleh petugas
-- Acuan isi: formulir manual RSUD dr. Iskak Tulungagung rev 2024 (bukan versi
-- 5 warna 2018). Isi awal dipasang lewat `php artisan ews:seed`.
--
-- Skor DIHITUNG di aplikasi (App\Support\Ews\EwsSkor) dari master ini, hasilnya
-- disimpan di JSON EMR pada tiap entri Observasi Lanjutan (RI & UGD):
--   observasi.observasiLanjutan.tandaVital[].ews
-- Master ini hanya menyimpan ATURAN, bukan hasil per pasien.
-- =============================================================


-- =============================================================
-- BAGIAN A — BERSIHKAN
-- =============================================================
-- SQL biasa, tanpa pembungkus. Kalau objeknya memang belum pernah dibuat, Oracle
-- akan mengeluh — ABAIKAN error berikut dan lanjut ke perintah sesudahnya:
--   ORA-00942  tabel/view tidak ada
-- Selain itu, berhenti dan periksa.

DROP TABLE RSMST_EWS_RENTANGS;
DROP TABLE RSMST_EWS_RESPONS;
DROP TABLE RSMST_EWS_PARAMS;



-- =============================================================
-- BAGIAN B — BUAT
-- =============================================================

-- -------------------------------------------------------------
-- 1. PARAMETER — apa yang dinilai, per varian.
--    PARAM_KODE = key JSON sumber nilai di entri Observasi Lanjutan
--    (frekuensiNafas, spo2, sistolik, kesadaran, ...). Kode yang sama boleh
--    muncul di varian berbeda dengan rentang berbeda.
-- -------------------------------------------------------------
CREATE TABLE RSMST_EWS_PARAMS (
    PARAM_ID        NUMBER          NOT NULL,   -- PK, MAX+1 oleh aplikasi
    VARIAN          VARCHAR2(10)    NOT NULL,   -- DEWASA | ANAK | NEONATUS | MEOWS
    PARAM_KODE      VARCHAR2(30)    NOT NULL,   -- key JSON (camelCase)
    PARAM_DESC      VARCHAR2(100)   NOT NULL,   -- label di layar
    TIPE            VARCHAR2(10)    NOT NULL,   -- ANGKA | PILIHAN | REFERENSI
    SATUAN          VARCHAR2(20),               -- x/mnt, %, mmHg, °C ...
    URUTAN          NUMBER(3)       DEFAULT 0 NOT NULL,
    WAJIB           VARCHAR2(1)     DEFAULT '1' NOT NULL,   -- '1' wajib diisi agar skor lengkap
    GANTIKAN_KODE   VARCHAR2(30),               -- bila param ini diisi, param berkode ini TIDAK diskor (spo2Skala2 → spo2)
    ACTIVE_STATUS   VARCHAR2(1)     DEFAULT '1' NOT NULL,   -- '1' aktif / '0' nonaktif (idiom repo, BUKAN Y/N)

    CONSTRAINT PK_EWS_PARAMS PRIMARY KEY (PARAM_ID),
    CONSTRAINT UK_EWS_PARAMS UNIQUE (VARIAN, PARAM_KODE),
    CONSTRAINT CK_EWS_PARAMS_TIPE CHECK (TIPE IN ('ANGKA', 'PILIHAN', 'REFERENSI'))
);

COMMENT ON TABLE  RSMST_EWS_PARAMS IS 'Master parameter EWS per varian (DEWASA/ANAK/NEONATUS/MEOWS). Rentang skornya di RSMST_EWS_RENTANGS.';
COMMENT ON COLUMN RSMST_EWS_PARAMS.PARAM_KODE IS 'Key JSON sumber nilai di entri Observasi Lanjutan (observasi.observasiLanjutan.tandaVital[]).';
COMMENT ON COLUMN RSMST_EWS_PARAMS.TIPE IS 'ANGKA = nilai numerik dicocokkan ke BATAS_BAWAH..BATAS_ATAS; PILIHAN = petugas memilih PILIHAN_KODE; REFERENSI = tabel acuan (nadi/nafas normal per usia), tidak ikut skor.';
COMMENT ON COLUMN RSMST_EWS_PARAMS.GANTIKAN_KODE IS 'Bila param ini terisi, param dengan kode ini dilewati saat menghitung (SpO2 skala 2 menggantikan SpO2 skala 1).';

-- -------------------------------------------------------------
-- 2. RENTANG SKOR — satu baris = satu rentang nilai (atau satu pilihan) → skor.
--    Batas INKLUSIF dua sisi. NULL = tak terbatas (≤ 8 → BAWAH NULL, ATAS 8).
--    SYARAT = hanya berlaku bila pilihan param lain di varian sama bernilai ini
--    (SpO2 skala 2: "93-94 on O2" → SYARAT 'O2').
--    USIA_MIN_BLN/USIA_MAX_BLN = rentang usia (bulan, inklusif) untuk baris
--    acuan per usia (PEWS); NULL = berlaku semua usia.
-- -------------------------------------------------------------
CREATE TABLE RSMST_EWS_RENTANGS (
    RENTANG_ID      NUMBER          NOT NULL,   -- PK, MAX+1 oleh aplikasi
    PARAM_ID        NUMBER          NOT NULL,
    URUTAN          NUMBER(3)       DEFAULT 0 NOT NULL,
    BATAS_BAWAH     NUMBER(7,2),
    BATAS_ATAS      NUMBER(7,2),
    PILIHAN_KODE    VARCHAR2(30),               -- tipe PILIHAN: nilai yang tersimpan di JSON
    PILIHAN_DESC    VARCHAR2(300),              -- tipe PILIHAN: label; tipe ANGKA: label rentang opsional
    SYARAT          VARCHAR2(30),               -- pilihan kode param lain yang harus terpenuhi
    USIA_MIN_BLN    NUMBER(4),
    USIA_MAX_BLN    NUMBER(4),
    SKOR            NUMBER(2)       DEFAULT 0 NOT NULL,

    CONSTRAINT PK_EWS_RENTANGS PRIMARY KEY (RENTANG_ID),
    CONSTRAINT FK_EWS_RENTANGS_PARAM FOREIGN KEY (PARAM_ID)
        REFERENCES RSMST_EWS_PARAMS (PARAM_ID) ON DELETE CASCADE
);

COMMENT ON TABLE  RSMST_EWS_RENTANGS IS 'Rentang nilai/pilihan → skor untuk tiap parameter EWS. Batas inklusif; NULL = tak terbatas.';
COMMENT ON COLUMN RSMST_EWS_RENTANGS.SYARAT IS 'Baris hanya dipakai bila pilihan param lain (varian sama) = nilai ini. Contoh SpO2 skala 2 "95-96 on O2" → SYARAT = O2.';

CREATE INDEX IDX_EWS_RENTANGS_PARAM ON RSMST_EWS_RENTANGS (PARAM_ID);

-- -------------------------------------------------------------
-- 3. RESPON — total skor → kategori risiko, warna, frekuensi pantau, tindakan.
--    Baris COCOK bila (total di SKOR_MIN..SKOR_MAX) ATAU (PARAM_MERAH='1' dan ada
--    parameter berskor 3). Bila lebih dari satu cocok, URUTAN TERBESAR menang —
--    jadi urutkan dari ringan ke berat.
-- -------------------------------------------------------------
CREATE TABLE RSMST_EWS_RESPONS (
    RESPON_ID       NUMBER          NOT NULL,   -- PK, MAX+1 oleh aplikasi
    VARIAN          VARCHAR2(10)    NOT NULL,
    URUTAN          NUMBER(3)       DEFAULT 0 NOT NULL,
    SKOR_MIN        NUMBER(2),
    SKOR_MAX        NUMBER(2),                  -- NULL = tanpa batas atas
    PARAM_MERAH     VARCHAR2(1)     DEFAULT '0' NOT NULL,   -- '1' = juga berlaku bila ada satu parameter skor 3
    KATEGORI        VARCHAR2(30)    NOT NULL,   -- Rendah / Rendah-Sedang / Sedang / Tinggi
    WARNA           VARCHAR2(10)    NOT NULL,   -- PUTIH | HIJAU | KUNING | ORANYE | MERAH
    FREKUENSI       VARCHAR2(50)    NOT NULL,   -- teks: "Minimal tiap 1 jam"
    FREKUENSI_MENIT NUMBER(5),                  -- angka untuk jatuh tempo pantau ulang
    RESPON          VARCHAR2(600)   NOT NULL,   -- tindakan / eskalasi

    CONSTRAINT PK_EWS_RESPONS PRIMARY KEY (RESPON_ID),
    CONSTRAINT CK_EWS_RESPONS_WARNA CHECK (WARNA IN ('PUTIH', 'HIJAU', 'KUNING', 'ORANYE', 'MERAH'))
);

COMMENT ON TABLE  RSMST_EWS_RESPONS IS 'Interpretasi total skor EWS per varian: kategori, warna, frekuensi pantau ulang, respon klinis.';
COMMENT ON COLUMN RSMST_EWS_RESPONS.PARAM_MERAH IS '1 = baris ini juga berlaku bila ADA SATU parameter berskor 3 walau totalnya rendah (aturan NEWS2 & MEOWS).';

CREATE INDEX IDX_EWS_RESPONS_VARIAN ON RSMST_EWS_RESPONS (VARIAN);

-- Tidak ada SEQUENCE: PK diisi aplikasi dengan MAX+1 di dalam transaksi
-- (pola master-jasa-medis). Volume tulis master ini kecil, dan tanpa sequence
-- kodenya netral driver sehingga bisa diuji di sqlite.


-- =============================================================
-- BAGIAN C — ISI AWAL
-- =============================================================
-- Tidak ada INSERT di sini. Jalankan dari aplikasi supaya satu sumber kebenaran
-- (App\Support\Ews\EwsDefault) dipakai seed, unit test, dan dokumentasi:
--
--   php artisan ews:seed            # hanya bila ketiga tabel masih kosong
--   php artisan ews:seed --force    # kosongkan lalu isi ulang (kustomisasi hilang)
--   php artisan ews:seed --dry-run  # tampilkan ringkasan tanpa menulis


-- =============================================================
-- PEMERIKSAAN SESUDAH JALAN
-- =============================================================
-- Tiga tabel:
--   SELECT table_name FROM user_tables WHERE table_name LIKE 'RSMST_EWS%';
--
-- Setelah `php artisan ews:seed`, jumlah baris yang diharapkan:
--   SELECT varian, COUNT(*) FROM rsmst_ews_params  GROUP BY varian ORDER BY varian;
--     ANAK 5 · DEWASA 8 · MEOWS 14 · NEONATUS 3
--   SELECT COUNT(*) FROM rsmst_ews_rentangs;   -- 126
--   SELECT varian, COUNT(*) FROM rsmst_ews_respons GROUP BY varian ORDER BY varian;
--     ANAK 3 · DEWASA 5 · MEOWS 4 · NEONATUS 3
