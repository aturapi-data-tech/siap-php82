-- =============================================================
-- DDL: PRMRJ — Profil Ringkas Medis Rawat Jalan (formulir RM.06)
-- Jalankan di Oracle sebagai user pemilik schema SIRUS.
-- Target: Oracle 10g (batas nama objek 30 karakter — semua nama di bawah aman).
--
-- BERSIH-PASANG: berkas ini MENGHAPUS dulu lalu MEMBUAT ulang. Bagian A wajar
-- mengeluarkan error "objek tidak ada" di environment yang masih bersih.
--
-- ⚠️ BAGIAN A MENGHAPUS TABEL BESERTA ISINYA. Selama modul ini belum dipakai
--    produksi itu tak masalah; kalau sudah ada PRMRJ sungguhan, cadangkan dulu.
--
-- APA ITU PRMRJ. Ringkasan medis pasien rawat jalan dengan kondisi kompleks,
-- ditempatkan paling atas berkas rekam medis rawat jalan supaya mudah ditelusuri
-- (easy to retrieve) dan mudah direview. Diidentifikasi & dilengkapi DPJP Utama.
--
-- KRITERIA (SPO poin 2) — pasien dapat PRMRJ bila SALAH SATU terpenuhi:
--   a. >= 3 diagnosis penyerta (DM, hipertensi grade II, gagal ginjal kronik,
--      congestive heart failure, TB paru dalam pengobatan/sembuh, post operasi besar)
--   b. >= 3 asuhan (gizi, radiologi, laboratorium, rehabilitasi medis,
--      kemoterapi, EKG, tindakan operasi)
--   c. alergi obat atau multi drug resistance
--
-- SATU BARIS PER KUNJUNGAN, bukan per pasien — sama seperti
-- RSTXN_PENGKAJIAN_REVIEWS. Formulir RM.06 yang berisi banyak baris itu DIRAKIT
-- saat tampil/cetak dengan mengambil semua baris milik REG_NO dan mengurutkannya
-- menurut tanggal kunjungan.
--
-- Alasannya bukan selera: satu baris per pasien berarti dua poli yang membuka
-- pasien sama pada hari sama harus saling menunggu lock pada satu CLOB, dan
-- kesalahan tulis di satu poli merusak seluruh riwayat pasien. Per kunjungan,
-- tabrakan itu tak mungkin terjadi.
-- =============================================================


-- =============================================================
-- BAGIAN A — BERSIHKAN
-- =============================================================
-- SQL biasa, tanpa pembungkus. Kalau objeknya memang belum pernah dibuat, Oracle
-- akan mengeluh — ABAIKAN error berikut dan lanjut ke perintah sesudahnya:
--   ORA-00942  tabel/view tidak ada
--   ORA-02289  sequence tidak ada
-- Selain dua itu, berhenti dan periksa.

DROP TABLE RSTXN_PRMRJS;

DROP SEQUENCE SEQ_PRMRJS;


-- =============================================================
-- BAGIAN B — BUAT
-- =============================================================

CREATE TABLE RSTXN_PRMRJS (
    -- Tiga kolom saja. Sisanya di PRMRJ_JSON.
    PRMRJ_NO            NUMBER          NOT NULL,   -- PK, dari SEQ_PRMRJS
    REG_NO              VARCHAR2(10)    NOT NULL,   -- satu-satunya penyaring lewat SQL
    PRMRJ_JSON          CLOB,

    CONSTRAINT PK_PRMRJS PRIMARY KEY (PRMRJ_NO)
);

-- -------------------------------------------------------------
-- KENAPA HANYA REG_NO YANG DATAR
--
-- Oracle di sini TIDAK mendukung JSON_VALUE (ORA-00904), jadi apa pun yang masuk
-- CLOB tak bisa dipakai memfilter, mengurutkan, maupun meng-indeks. REG_NO tetap
-- datar supaya "ambil PRMRJ milik pasien ini" cukup satu query terindeks; jumlah
-- barisnya per pasien sedikit, sehingga mencari baris milik KUNJUNGAN tertentu
-- aman diselesaikan dengan men-decode JSON di PHP.
--
-- YANG HILANG, DAN INI DISENGAJA: laporan lintas-pasien — "berapa pasien memenuhi
-- kriteria PRMRJ triwulan ini" untuk evaluasi Tim Audit Medis & Case Manager (SPO
-- poin 6) — tak bisa dihitung lewat SQL. Kalau kelak laporan itu diperlukan,
-- penanda kriteria harus dinaikkan jadi kolom datar.
-- -------------------------------------------------------------

-- -------------------------------------------------------------
-- Bentuk PRMRJ_JSON (didokumentasikan supaya tidak jadi tempat sampah):
--
-- {
--   "kunjungan": { "jenis": "RJ", "no": 583847 },     -- kunjungan yang diringkas
--
--   -- KRITERIA — diisi petugas lewat x-toggle, TIDAK dihitung sistem.
--   -- Sistem boleh menyarankan, tapi yang tersimpan adalah yang dicentang.
--   "kriteria": {
--     "diagnosisKompleks":  true,     -- >= 3 diagnosis penyerta
--     "asuhanTigaAtauLebih": false,   -- >= 3 asuhan
--     "alergiObatMdr":      false,    -- alergi obat / multi drug resistance
--     "catatan": "..."                -- alasan bebas bila perlu
--   },
--
--   -- OTOMATIS — SNAPSHOT isi EMR kunjungan itu saat PRMRJ disimpan.
--   -- Sengaja disalin, bukan dibaca ulang saat cetak: RM.06 adalah dokumen
--   -- bertanda tangan, isinya harus sama dengan yang dilihat DPJP saat menandatangani
--   -- walau EMR-nya kelak dikoreksi.
--   "otomatis": {
--     "tglKunjungan":  "25/08/2025 10:31:23",
--     "poliklinik":    "POLI GIGI",
--     "dpjp":          "drg. Haidar Birra Syadad Barq",
--     "diagnosa":      [ { "kode": "K04.1", "desc": "Necrosis of pulp" } ],
--     "riwayatAlergi": "dingin",
--     "terapi":        "R/ OPIMOX TAB 500MG | No. 10 | S 3dd1",
--     "tindakan":      [ { "kode": "93.39", "desc": "..." } ],
--     "operasi":       [ { "no": 123, "desc": "..." } ],   -- RSTXN_OKS status_rjri='RJ'
--     "rencanaTindakLanjut": "Perawatan Selesai"
--   },
--
--   -- MANUAL — tak ada padanannya di EMR, harus diketik DPJP.
--   "manual": { "obatKhusus": "..." },
--
--   -- TTD DPJP: mengunci baris. Yang disimpan nama + kode + waktu, BUKAN gambar;
--   -- gambarnya diambil ulang dari users.myuser_ttd_image saat cetak.
--   "ttd": { "nama": "...", "kode": "...", "tanggal": "25/08/2026 10:15:00" },
--   "terkunci": true,
--
--   "dibuat": { "oleh": "...", "waktu": "..." }        -- jejak pembuat pertama
-- }
--
-- Baca CLOB-nya lewat App\Support\OracleLob::read() — pola repo untuk menahan
-- ORA-01555 (snapshot too old) pada pembacaan CLOB besar.
-- -------------------------------------------------------------

COMMENT ON TABLE RSTXN_PRMRJS IS 'Profil Ringkas Medis Rawat Jalan (RM.06). Satu baris per kunjungan - formulir per pasien dirakit dari semua baris milik REG_NO.';
COMMENT ON COLUMN RSTXN_PRMRJS.REG_NO IS 'Nomor RM pasien — satu-satunya kolom penyaring. Sisanya (kunjungan, kriteria, snapshot EMR, obat khusus, TTD) ada di PRMRJ_JSON.';
COMMENT ON COLUMN RSTXN_PRMRJS.PRMRJ_JSON IS 'Seluruh isi PRMRJ: kunjungan, kriteria toggle, snapshot otomatis dari EMR, isian manual, tanda tangan, jejak pembuat. Bentuknya di docs/ddl-prmrj.sql.';

-- Satu-satunya indeks yang masih ada gunanya: ambil PRMRJ milik satu pasien.
CREATE INDEX IDX_PRMRJ_REG ON RSTXN_PRMRJS (REG_NO);

CREATE SEQUENCE SEQ_PRMRJS START WITH 1 INCREMENT BY 1 NOCACHE;


-- =============================================================
-- PEMERIKSAAN SESUDAH JALAN
-- =============================================================
-- Harus 3 kolom (PRMRJ_NO, REG_NO, PRMRJ_JSON):
--   SELECT column_name, data_type FROM user_tab_columns
--    WHERE table_name = 'RSTXN_PRMRJS' ORDER BY column_id;
--
-- Harus ada indeks IDX_PRMRJ_REG + PK_PRMRJS:
--   SELECT index_name FROM user_indexes WHERE table_name = 'RSTXN_PRMRJS';
--
-- Sequence hidup:
--   SELECT SEQ_PRMRJS.NEXTVAL FROM DUAL;
