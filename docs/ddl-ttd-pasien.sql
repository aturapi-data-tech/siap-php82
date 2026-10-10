-- =============================================================
-- DDL: TTD PASIEN / KELUARGA / SAKSI — gambar tanda tangan non-petugas
-- Jalankan di Oracle sebagai user pemilik schema SIRUS.
-- Target: Oracle 10g (batas nama objek 30 karakter — semua nama di bawah aman).
--
-- KENAPA TABEL INI ADA. Tanda tangan pasien/keluarga/saksi dari signature-pad
-- selama ini disimpan sebagai data-URL (teks base64, +-27.000 karakter per TTD)
-- LANGSUNG di dalam JSON dokumen EMR (datadaftar*_json). Akibatnya:
--   1. CLOB dokumen EMR membengkak — tiap findDataRI/RJ/UGD() ikut memikulnya,
--      di SEMUA komponen, bukan cuma yang punya TTD;
--   2. properti publik Livewire ($signature, dan tiap entri di $...List) membawa
--      teks itu bolak-balik tiap request -> PayloadTooLargeException (1 MB).
-- Dengan tabel ini, JSON dokumen cukup menyimpan REFERENSI "TTD:<TTD_NO>";
-- gambarnya tinggal di sini dan hanya dibaca saat form dibuka atau dicetak.
--
-- DATA LAMA TIDAK DIMIGRASI DAN TIDAK PERLU. Field JSON yang sama menampung dua
-- format, dibedakan App\Support\TtdPasien:
--   "data:image/..."  -> TTD lama, tersimpan inline, dipakai apa adanya
--   "TTD:184223"      -> TTD baru, gambarnya di tabel ini
--
-- SIFATNYA SEPERTI LOG: baris TIDAK PERNAH di-UPDATE maupun dihapus oleh aplikasi.
-- "Hapus & ulangi TTD" membuat baris BARU; baris lama tetap ada sebagai jejak.
-- Karena itu tak ada lock & tak ada read-modify-write (bandingkan: catatan lepas
-- di skill dokumen-clob-per-kunjungan).
--
-- BARIS YATIM ITU WAJAR. Baris dibuat saat pasien selesai menggores (sebelum form
-- disimpan), supaya teks base64 tak ikut bolak-balik selama form terbuka. Kalau
-- petugas batal menyimpan, barisnya tak dirujuk dokumen mana pun. Ukurannya kecil;
-- bila kelak perlu dibersihkan, TTD_DATE tersedia sebagai penyaring.
--
-- AMAN DIPASANG KAPAN SAJA. Selama tabel ini belum ada, aplikasi otomatis tetap
-- menyimpan inline seperti sekarang (TtdPasien::tabelTersedia()).
-- =============================================================


-- =============================================================
-- BAGIAN A — BUAT (TANPA bagian hapus: tabel ini berisi bukti tanda tangan,
-- jangan pernah di-DROP oleh skrip. ORA-00955 "nama sudah dipakai" = sudah
-- terpasang, berhenti dan periksa — jangan dipaksa.)
-- =============================================================

CREATE TABLE RSTXN_TTDS (
    TTD_NO      NUMBER          NOT NULL,   -- PK, dari SEQ_TTDS; inilah angka di "TTD:<TTD_NO>"
    REG_NO      VARCHAR2(10),               -- No. RM pemilik dokumen; penyaring lewat SQL
    TTD_DATE    DATE            NOT NULL,   -- waktu gores diterima server
    TTD_DATA    CLOB,                       -- data-URL apa adanya (image/png atau image/svg+xml)

    CONSTRAINT PK_TTDS PRIMARY KEY (TTD_NO)
);

CREATE INDEX IDX_TTDS_REG ON RSTXN_TTDS (REG_NO);

CREATE SEQUENCE SEQ_TTDS START WITH 1 INCREMENT BY 1 NOCACHE;

-- Peran penanda tangan (pasien/keluarga/saksi), modul, dan "Waktu TTD" yang tampil
-- di layar TETAP di JSON dokumen — tabel ini murni menyimpan gambarnya. Itu
-- keputusan sadar: laporan lintas-pasien atas TTD tidak bisa dihitung lewat SQL.
