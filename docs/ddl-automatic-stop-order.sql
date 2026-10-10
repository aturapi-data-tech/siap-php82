-- =============================================================
-- DDL: Automatic Stop Order (master golongan obat + pemetaan obat)
-- Jalankan di Oracle sebagai user pemilik schema SIRUS.
-- Target: Oracle 10g (batas nama objek 30 karakter — semua nama di bawah aman).
--
-- BERSIH-PASANG: berkas ini MENGHAPUS dulu lalu MEMBUAT ulang. Bagian A wajar
-- mengeluarkan error "objek tidak ada" di environment yang masih bersih.
--
-- !! BAGIAN A MENGHAPUS TABEL BESERTA ISINYA. Golongan bisa dipulihkan lewat
--    `php artisan automatic-stop-order:seed --force`, tetapi PEMETAAN OBAT (RSMST_STOP_ORDER_PRODUCTS)
--    diisi manual oleh apoteker dan TIDAK ada seed-nya. Ekspor dulu bila ada.
--
-- !! SEBELUM MENJALANKAN: periksa dulu apakah tabel senama sudah ada dari sumber
--    lain (Oracle Dev 6i): SELECT table_name FROM user_tables WHERE table_name LIKE 'RSMST_STOP_ORDER%';
--    Kalau sudah ada dan bukan dari berkas ini, hentikan dan periksa.
--
-- APA ITU Automatic Stop Order. Kebijakan farmasi (PKPO): order obat golongan tertentu berhenti
-- OTOMATIS setelah sekian hari kecuali dokter mengkaji ulang dan menulis
-- order baru. Tujuannya keamanan pasien (obat yang toksik bila menumpuk) dan
-- pengendalian antimikroba (KPRA/KFT). Master ini menyimpan ATURANNYA:
--   RSMST_STOP_ORDER_GOLONGANS  golongan obat + batas hari + keterangan kebijakan
--   RSMST_STOP_ORDER_PRODUCTS   obat mana (immst_products.product_id) masuk golongan mana
-- Pemetaan per product_id dipilih (bukan tebakan dari nama obat) supaya
-- apoteker yang memutuskan; obat yang belum dipetakan DIABAIKAN Automatic Stop Order.
--
-- Hari berjalan per pasien DIHITUNG di aplikasi (App\Support\AutomaticStopOrder) dari e-resep
-- RI di JSON; master ini tidak menyimpan hasil per pasien.
-- =============================================================


-- =============================================================
-- BAGIAN A — BERSIHKAN
-- =============================================================
-- SQL biasa, tanpa pembungkus. Kalau objeknya memang belum pernah dibuat, Oracle
-- akan mengeluh — ABAIKAN error berikut dan lanjut ke perintah sesudahnya:
--   ORA-00942  tabel/view tidak ada
-- Selain itu, berhenti dan periksa.

DROP TABLE RSMST_STOP_ORDER_PRODUCTS;
DROP TABLE RSMST_STOP_ORDER_GOLONGANS;



-- =============================================================
-- BAGIAN B — BUAT
-- =============================================================

-- -------------------------------------------------------------
-- 1. GOLONGAN — satu baris = satu golongan obat dengan batas harinya.
--    KETERANGAN = teks kebijakan yang tampil ke petugas (ASCII/Latin-1 saja,
--    charset DB WE8ISO8859P1).
-- -------------------------------------------------------------
CREATE TABLE RSMST_STOP_ORDER_GOLONGANS (
    GOLONGAN_ID          NUMBER          NOT NULL,   -- PK, MAX+1 oleh aplikasi
    GOLONGAN_KODE        VARCHAR2(30)    NOT NULL,   -- kode singkat, huruf besar (KETOROLAK, ANTIKOAGULAN, ...)
    GOLONGAN_NAMA        VARCHAR2(150)   NOT NULL,   -- nama di layar
    BATAS_HARI      NUMBER(3)       NOT NULL,   -- order berhenti otomatis setelah hari ke-N
    BATAS_MINIMAL_HARI  NUMBER(3),                  -- opsional: lama pemberian MINIMAL sebelum dikaji/dihentikan (<= BATAS_HARI)
    KETERANGAN      VARCHAR2(600),              -- kebijakan / syarat lanjut / catatan dosis
    URUTAN          NUMBER(3)       DEFAULT 0 NOT NULL,
    ACTIVE_STATUS   VARCHAR2(1)     DEFAULT '1' NOT NULL,   -- '1' aktif / '0' nonaktif (idiom repo, BUKAN Y/N)

    CONSTRAINT PK_STOP_ORDER_GOLONGANS PRIMARY KEY (GOLONGAN_ID),
    CONSTRAINT UK_STOP_ORDER_GOLONGANS UNIQUE (GOLONGAN_KODE),
    CONSTRAINT CK_STOP_ORDER_HARI CHECK (BATAS_HARI > 0),
    CONSTRAINT CK_STOP_ORDER_MINIMAL CHECK (BATAS_MINIMAL_HARI IS NULL OR (BATAS_MINIMAL_HARI > 0 AND BATAS_MINIMAL_HARI <= BATAS_HARI))
);

COMMENT ON TABLE  RSMST_STOP_ORDER_GOLONGANS IS 'Master golongan Automatic Stop Order: batas hari order per golongan obat + keterangan kebijakan.';
COMMENT ON COLUMN RSMST_STOP_ORDER_GOLONGANS.BATAS_HARI IS 'Order obat golongan ini berhenti otomatis setelah N hari kecuali dokter mengkaji ulang.';
COMMENT ON COLUMN RSMST_STOP_ORDER_GOLONGANS.BATAS_MINIMAL_HARI IS 'Opsional. Lama pemberian minimal (hari) sebelum obat boleh dikaji/dihentikan. NULL = tidak ada batas minimal.';

-- -------------------------------------------------------------
-- 2. PEMETAAN OBAT — obat mana masuk golongan mana. Satu obat = satu golongan
--    (PK product_id). CATATAN untuk syarat khusus per obat (mis. dosis maksimal).
-- -------------------------------------------------------------
CREATE TABLE RSMST_STOP_ORDER_PRODUCTS (
    PRODUCT_ID      VARCHAR2(30)    NOT NULL,   -- = immst_products.product_id
    GOLONGAN_ID          NUMBER          NOT NULL,
    CATATAN         VARCHAR2(300),
    ACTIVE_STATUS   VARCHAR2(1)     DEFAULT '1' NOT NULL,

    CONSTRAINT PK_STOP_ORDER_PRODUCTS PRIMARY KEY (PRODUCT_ID),
    CONSTRAINT FK_STOP_ORDER_PRODUCTS_GOL FOREIGN KEY (GOLONGAN_ID)
        REFERENCES RSMST_STOP_ORDER_GOLONGANS (GOLONGAN_ID)
);

COMMENT ON TABLE  RSMST_STOP_ORDER_PRODUCTS IS 'Pemetaan obat (immst_products) ke golongan Automatic Stop Order. Obat yang tidak ada di sini diabaikan Automatic Stop Order.';

CREATE INDEX IDX_STOP_ORDER_PRODUCTS_GOL ON RSMST_STOP_ORDER_PRODUCTS (GOLONGAN_ID);

-- Tambahan 2026-09-07 untuk DB yang sudah memasang versi tanpa BATAS_MINIMAL_HARI:
--   ALTER TABLE RSMST_STOP_ORDER_GOLONGANS ADD (BATAS_MINIMAL_HARI NUMBER(3));
--   ALTER TABLE RSMST_STOP_ORDER_GOLONGANS ADD CONSTRAINT CK_STOP_ORDER_MINIMAL CHECK (BATAS_MINIMAL_HARI IS NULL OR (BATAS_MINIMAL_HARI > 0 AND BATAS_MINIMAL_HARI <= BATAS_HARI));
--   COMMENT ON COLUMN RSMST_STOP_ORDER_GOLONGANS.BATAS_MINIMAL_HARI IS 'Opsional. Lama pemberian minimal (hari) sebelum obat boleh dikaji/dihentikan. NULL = tidak ada batas minimal.';

-- Environment yang sempat memasang nama lama (RSMST_ASO_*, GOL_ID, BATAS_MIN_HARI)
-- pada 2026-09-07 diubah namanya, bukan dibuat ulang:
--   ALTER TABLE RSMST_ASO_GOLONGANS RENAME TO RSMST_STOP_ORDER_GOLONGANS;
--   ALTER TABLE RSMST_ASO_PRODUCTS  RENAME TO RSMST_STOP_ORDER_PRODUCTS;
--   ALTER TABLE RSMST_STOP_ORDER_GOLONGANS RENAME COLUMN GOL_ID TO GOLONGAN_ID;  (idem GOL_KODE, GOL_NAMA, BATAS_MIN_HARI)
--   ALTER TABLE RSMST_STOP_ORDER_PRODUCTS  RENAME COLUMN GOL_ID TO GOLONGAN_ID;
--   ALTER TABLE ... RENAME CONSTRAINT <lama> TO <baru>;  ALTER INDEX <lama> RENAME TO <baru>;

-- Tidak ada SEQUENCE: PK golongan diisi aplikasi dengan MAX+1 di dalam transaksi
-- (pola master EWS / master-jasa-medis) supaya netral driver dan bisa diuji di sqlite.
-- Tidak ada FK ke immst_products: tabel itu milik Oracle Dev 6i; aplikasi
-- memvalidasi keberadaan product_id saat menyimpan.


-- =============================================================
-- BAGIAN C — ISI AWAL
-- =============================================================
-- Tidak ada INSERT di sini. Jalankan dari aplikasi supaya satu sumber kebenaran
-- (App\Support\AutomaticStopOrder\AutomaticStopOrderDefault) dipakai seed, unit test, dan dokumentasi:
--
--   php artisan automatic-stop-order:seed            # hanya bila tabel golongan masih kosong
--   php artisan automatic-stop-order:seed --force    # kosongkan golongan lalu isi ulang (pemetaan obat ikut terhapus!)
--   php artisan automatic-stop-order:seed --dry-run  # tampilkan ringkasan tanpa menulis
--
-- Pemetaan obat diisi apoteker lewat /master/automatic-stop-order (tab Pemetaan Obat).


-- =============================================================
-- PEMERIKSAAN SESUDAH JALAN
-- =============================================================
--   SELECT table_name FROM user_tables WHERE table_name LIKE 'RSMST_STOP_ORDER%';   -- 2 tabel
--   SELECT COUNT(*) FROM rsmst_stop_order_golongans;   -- 12 setelah automatic-stop-order:seed
--   SELECT golongan.golongan_nama, COUNT(pemetaan.product_id) FROM rsmst_stop_order_golongans golongan
--     LEFT JOIN rsmst_stop_order_products p ON pemetaan.golongan_id = golongan.golongan_id GROUP BY golongan.golongan_nama;
