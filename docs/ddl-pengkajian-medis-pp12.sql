-- =============================================================
-- DDL: Pengkajian Medis — pakai-ulang & review (Akreditasi PP 1.2 poin e)
-- Jalankan di Oracle sebagai user pemilik schema SIRUS.
-- Target: Oracle 10g (batas nama objek 30 karakter — semua nama di bawah aman).
--
-- BERSIH-PASANG: berkas ini MENGHAPUS dulu lalu MEMBUAT ulang. Bagian A wajar
-- mengeluarkan error "objek tidak ada" di environment yang masih bersih — daftar
-- kode yang boleh diabaikan ada di Bagian A.
--
-- ⚠️ BAGIAN A MENGHAPUS TABEL BESERTA ISINYA. Selama modul ini belum dipakai
--    produksi itu tak masalah; kalau sudah ada review sungguhan, cadangkan dulu.
--
-- ATURANNYA: pengkajian medis yang dibuat <= 30 hari sebelum pasien masuk rawat
-- inap atau sebelum menjalani prosedur di rawat jalan BOLEH dipakai lagi, asal
-- ditinjau/diverifikasi dan diperbarui sesuai kondisi terkini. Lebih dari 30
-- hari, WAJIB pengkajian ulang.
--
-- TIDAK ADA KOLOM BARU DI TABEL KUNJUNGAN. Pengkajian yang sudah ada ditemukan
-- lewat penanda yang SUDAH tersedia:
--
--   RSTXN_RJHDRS.ERM_STATUS = 'L'   -> dokter sudah TTD-E Dokter Pemeriksa, artinya
--                                      pengkajian medis selesai (99.186 baris)
--   RSTXN_RJHDRS.RJ_DATE            -> tanggalnya; untuk RJ satu kunjungan = satu hari,
--                                      jadi tepat sampai ketelitian HARI
--   RSTXN_RJHDRS.DR_ID              -> dokternya, lewat RSMST_DOCTORS
--
-- Versi awal berkas ini sempat menambah TGL_PENGKAJIAN_MEDIS & DR_PENGKAJIAN_MEDIS
-- di RSTXN_RJHDRS/RSTXN_RIHDRS. DIBATALKAN: penandanya sudah ada, dan kolom baru
-- justru menuntut 95.690 kunjungan lama diisi mundur dulu sebelum berguna.
-- Relasinya cukup lewat RJ_NO / RIHDR_NO.
--
-- CATATAN RI: RSTXN_RIHDRS.ERM_STATUS tak pernah bernilai 'L' (tak ada TTD-E setara
-- di EMR RI). Cabang RI sudah disiapkan di kode tapi belum menghasilkan apa pun.
-- =============================================================


-- =============================================================
-- BAGIAN A — BERSIHKAN
-- =============================================================
-- SQL biasa, tanpa pembungkus. Kalau objeknya memang belum pernah dibuat, Oracle
-- akan mengeluh — ABAIKAN error berikut dan lanjut ke perintah sesudahnya:
--   ORA-00942  tabel/view tidak ada
--   ORA-02289  sequence tidak ada
--   ORA-01418  indeks tidak ada
--   ORA-00904  kolom tidak ada
-- Selain empat itu, berhenti dan periksa.

DROP TABLE RSTXN_PENGKAJIAN_REVIEWS;

DROP SEQUENCE SEQ_PENGKAJIAN_REVIEWS;

-- Sisa versi awal: indeks & kolom stempel di tabel kunjungan.
DROP INDEX IDX_RJHDRS_PENGKAJIAN;

DROP INDEX IDX_RIHDRS_PENGKAJIAN;

ALTER TABLE RSTXN_RJHDRS DROP (TGL_PENGKAJIAN_MEDIS, DR_PENGKAJIAN_MEDIS);

ALTER TABLE RSTXN_RIHDRS DROP (TGL_PENGKAJIAN_MEDIS, DR_PENGKAJIAN_MEDIS);


-- =============================================================
-- BAGIAN B — BUAT
-- =============================================================
-- SATU BARIS setiap kali sebuah pengkajian lama ditinjau untuk dipakai ulang.
-- Kunjungan yang membuat pengkajian baru dari nol tidak menulis baris di sini.

CREATE TABLE RSTXN_PENGKAJIAN_REVIEWS (
    -- Tiga kolom saja. Sisanya di REVIEW_JSON.
    REVIEW_NO           NUMBER          NOT NULL,   -- PK, dari SEQ_PENGKAJIAN_REVIEWS
    REG_NO              VARCHAR2(10)    NOT NULL,   -- satu-satunya penyaring lewat SQL
    REVIEW_JSON         CLOB,

    CONSTRAINT PK_PENGKAJIAN_REVIEWS PRIMARY KEY (REVIEW_NO)
);

-- -------------------------------------------------------------
-- KENAPA HANYA REG_NO YANG DATAR
--
-- Oracle di sini TIDAK mendukung JSON_VALUE (ORA-00904), jadi apa pun yang masuk
-- CLOB tak bisa dipakai memfilter, mengurutkan, maupun meng-indeks. REG_NO tetap
-- datar supaya "ambil review milik pasien ini" cukup satu query terindeks; jumlah
-- barisnya per pasien sedikit, sehingga sisanya (mencari review milik KUNJUNGAN
-- tertentu) aman diselesaikan dengan men-decode JSON di PHP.
--
-- YANG HILANG, DAN INI DISENGAJA: laporan akreditasi lintas-pasien — "berapa
-- kunjungan yang memakai ulang pengkajian <=30 hari vs yang wajib mengulang" —
-- tak bisa lagi dihitung lewat SQL. Menghitungnya berarti membuka SELURUH CLOB
-- satu per satu. Kalau kelak laporan itu diperlukan, KEPUTUSAN harus dinaikkan
-- kembali jadi kolom datar.
-- -------------------------------------------------------------

-- -------------------------------------------------------------
-- Bentuk REVIEW_JSON (didokumentasikan supaya tidak jadi tempat sampah):
--
-- {
--   "pemakai":  { "jenis": "RJ|RI", "no": 673464 },   -- kunjungan yang MEMAKAI ULANG
--   "sumber":   { "jenis": "RJ|RI|LUAR",              -- pengkajian yang DITINJAU
--                 "no": 583847,                        -- null bila LUAR
--                 "deskripsi": "Poli Bedah RS X" },
--   "tglPengkajian": "05/05/2026",                     -- tanggal pengkajian yang ditinjau
--   "reviewDate": "22/05/2026 10:15:00",               -- kapan ditinjau
--   "keputusan": "REVIEW|ULANG",                       -- <=30 hari | >30 hari
--   "usiaHariSaatReview": 17,
--
--   "form": {
--     "sumberJenis": "RJ|RI|LUAR", "sumberNo": "583847",
--     "sumberDeskripsi": "...", "tglPengkajian": "05/05/2026",
--     "drPengkaji": "dr. Andi Wijaya, Sp.B",           -- yang MELAKUKAN pengkajian dulu
--     "adaPerubahan": "Y|T", "perubahanDesc": "...",
--     "tindakanTinjau": true, "tindakanVerifikasi": true, "tindakanUlang": false,
--     "reviewCatatan": "...",
--     "petugas": "...", "petugasCode": "...", "petugasDate": "..."   -- stempel TTD
--   },
--
--   "review":   { "drId": "...", "drDesc": "...",      -- penanda tangan (user login)
--                 "terkunci": true,                     -- true = sudah TTD, baca-saja
--                 "waktu": "22/05/2026 10:15:00" },
--   "dibuat":   { "oleh": "...", "waktu": "..." }      -- jejak pembuat pertama
-- }
--
-- USIA PENGKAJIAN bukan sumber kebenaran: ia turunan pasti dari tglPengkajian dan
-- reviewDate. Yang disimpan hanya rekaman nilainya saat ditinjau.
--
-- SUMBER bisa menunjuk RSTXN_RJHDRS, RSTXN_RIHDRS, atau tak menunjuk apa pun
-- (LUAR = pengkajian dari luar RS, no = null).
--
-- Baca CLOB-nya lewat App\Support\OracleLob::read() — pola repo untuk menahan
-- ORA-01555 (snapshot too old) pada pembacaan CLOB besar.
-- -------------------------------------------------------------

COMMENT ON TABLE RSTXN_PENGKAJIAN_REVIEWS IS 'Catatan review/verifikasi pengkajian medis yang dipakai ulang (Akreditasi PP 1.2 poin e). Satu baris per peninjauan — isi pengkajiannya tetap di CLOB kunjungan asal.';
COMMENT ON COLUMN RSTXN_PENGKAJIAN_REVIEWS.REG_NO IS 'Nomor RM pasien — satu-satunya kolom penyaring. Sisanya (kunjungan pemakai, sumber, tanggal, keputusan) ada di REVIEW_JSON.';
COMMENT ON COLUMN RSTXN_PENGKAJIAN_REVIEWS.REVIEW_JSON IS 'Seluruh isi review: pemakai, sumber, tanggal, keputusan, formulir, penanda tangan, jejak pembuat. Bentuknya di docs/ddl-pengkajian-medis-pp12.sql.';

-- Satu-satunya indeks yang masih ada gunanya: ambil review milik satu pasien.
CREATE INDEX IDX_PKJ_REVIEW_REG ON RSTXN_PENGKAJIAN_REVIEWS (REG_NO);

CREATE SEQUENCE SEQ_PENGKAJIAN_REVIEWS START WITH 1 INCREMENT BY 1 NOCACHE;


-- =============================================================
-- PEMERIKSAAN SESUDAH JALAN
-- =============================================================
-- Harus 3 kolom (REVIEW_NO, REG_NO, REVIEW_JSON):
--   SELECT column_name, data_type FROM user_tab_columns
--    WHERE table_name = 'RSTXN_PENGKAJIAN_REVIEWS' ORDER BY column_id;
--
-- Harus 1 indeks (IDX_PKJ_REVIEW_REG):
--   SELECT index_name FROM user_indexes
--    WHERE table_name = 'RSTXN_PENGKAJIAN_REVIEWS';
--
-- Harus KOSONG — sisa versi awal sudah terhapus:
--   SELECT table_name, column_name FROM user_tab_columns
--    WHERE column_name IN ('TGL_PENGKAJIAN_MEDIS','DR_PENGKAJIAN_MEDIS');
--
-- Sequence hidup:
--   SELECT SEQ_PENGKAJIAN_REVIEWS.NEXTVAL FROM DUAL;
