-- =============================================================
-- DDL: Pemantauan SUHU Ruang Server (Akreditasi MRMIK 2.2 - Perlindungan Data)
-- Formulir: "FORMULIR PEMANTAUAN RUANG & SUHU SERVER"
--           No. Dokumen 001/FORM/RSUI-MDN/01/2026, Revisi 00, terbit 05 Januari 2026
--
-- Jalankan di Oracle sebagai user pemilik schema SIRUS.
-- Target: Oracle 10g (batas nama objek 30 karakter - semua nama di bawah aman).
--
-- BERSIH-PASANG: berkas ini MENGHAPUS dulu lalu MEMBUAT ulang. Bagian A wajar
-- mengeluarkan error "objek tidak ada" di environment yang masih bersih.
--
-- ⚠️ BAGIAN A MENGHAPUS TABEL BESERTA ISINYA.
--
-- APA INI. Ruang server wajib dipantau suhunya supaya perangkat penyimpan data
-- rekam medis tidak rusak karena panas. Petugas mengukur suhu beberapa kali
-- sehari dan mencatatnya. Sebulan sekali catatan itu dicetak jadi formulir
-- resmi, ditandatangani Petugas Pemantau dan diketahui Kepala Unit IT / SIMRS.
--
-- SATU BARIS = SATU PENGUKURAN. Bukan satu lembar bulanan berisi array
-- pengukuran: catatan suhu itu peristiwa yang berdiri sendiri, ditulis satu per
-- satu sepanjang bulan. Merekamnya per baris berarti dua petugas yang mencatat
-- pada menit yang sama tak mungkin saling menimpa, tak perlu lock, dan menghapus
-- satu catatan salah tak menyentuh catatan lainnya.
--
-- FORMULIR DIRAKIT SAAT CETAK. Kop formulir (nama ruang, gedung, jumlah rack,
-- kapasitas AC, alat ukur, standar suhu) TIDAK disimpan per baris - nilainya
-- tetap sepanjang umur ruangan, jadi tinggal di App\Support\Options\
-- SuhuRuangServerOptions dan dipasang saat mencetak. Yang berubah tiap bulan
-- hanya daftar pengukurannya.
--
-- TANDA TANGAN DITEKEN DI KERTAS. Tak ada TTD tersimpan, tak ada status
-- terkunci/buka kunci: cetakan bulanan keluar dengan garis tanda tangan kosong
-- untuk Petugas Pemantau & Ka. Unit IT, persis formulir kertasnya. Yang tersimpan
-- per baris cuma PARAF petugas yang mencatat (nama + kode + waktu), terisi
-- otomatis dari pengguna yang login.
--
-- DUA KOLOM SAJA, TANPA PERIODE. Konsekuensinya disadari: tak ada kolom datar
-- yang bisa dipakai memfilter lewat SQL, jadi "pengukuran bulan ini" dikerjakan
-- dengan men-decode seluruh baris di PHP. Aman untuk volume modul ini - 1-2
-- pengukuran per hari berarti sekitar 700 baris setahun. Kalau kelak terasa
-- berat, yang harus dinaikkan jadi kolom datar adalah WAKTU (tipe DATE), bukan
-- periode teks: ia sekaligus melayani filter bulan, urutan, dan laporan tahunan.
-- =============================================================


-- =============================================================
-- BAGIAN A - BERSIHKAN
-- =============================================================
-- Abaikan error berikut kalau objeknya memang belum pernah dibuat:
--   ORA-00942  tabel/view tidak ada
--   ORA-02289  sequence tidak ada
--   ORA-01418  indeks tidak ada
-- Selain tiga itu, berhenti dan periksa.

DROP TABLE RSTXN_SUHUSERVERS;

DROP SEQUENCE SEQ_SUHUSERVERS;

-- Sisa rancangan lama yang berlembar bulanan.
DROP INDEX IDX_SUHUSERVER_PERIODE;


-- =============================================================
-- BAGIAN B - BUAT
-- =============================================================

CREATE TABLE RSTXN_SUHUSERVERS (
    -- Dua kolom saja. Seluruh isi pengukuran ada di SUHUSERVER_JSON.
    SUHUSERVER_NO       NUMBER      NOT NULL,   -- PK, dari SEQ_SUHUSERVERS
    SUHUSERVER_JSON     CLOB,

    CONSTRAINT PK_SUHUSERVERS PRIMARY KEY (SUHUSERVER_NO)
);

CREATE SEQUENCE SEQ_SUHUSERVERS START WITH 1 INCREMENT BY 1 NOCACHE;


-- =============================================================
-- BAGIAN C - KETERANGAN KOLOM
-- =============================================================
-- Tanpa titik koma di dalam teks komentar: pemecah statement per-';' akan
-- memotongnya jadi ORA-01756.

COMMENT ON TABLE RSTXN_SUHUSERVERS IS
    'Pemantauan Suhu Ruang Server (MRMIK 2.2). Satu baris = satu pengukuran suhu';

COMMENT ON COLUMN RSTXN_SUHUSERVERS.SUHUSERVER_NO IS
    'PK dari SEQ_SUHUSERVERS';

COMMENT ON COLUMN RSTXN_SUHUSERVERS.SUHUSERVER_JSON IS
    'Isi satu pengukuran. Kunci waktu, suhuAc, suhuRuang, statusAc, kondisi, tindakLanjut, paraf';


-- =============================================================
-- BAGIAN D - BENTUK SUHUSERVER_JSON (acuan, bukan perintah)
-- =============================================================
-- {
--   "waktu":        "26/08/2026 08:59:42",
--   "suhuAc":       "17",
--   "suhuRuang":    "22.5",
--   "statusAc":     "normal",
--   "kondisi":      "N",
--   "tindakLanjut": "",
--   "paraf": { "nama": "Nama User", "kode": "ADM", "tanggal": "26/08/2026 08:59:45" }
-- }
--
-- CATATAN ISI:
--   - waktu berformat "dd/mm/yyyy HH:MM:SS" - satu kunci, bukan tanggal + jam
--     terpisah. Petugas mencatat satu momen, dan tombol jam mengisinya sekali
--     klik. CETAKANNYA tetap dua kolom (Tanggal | Jam) karena begitulah bentuk
--     formulir kertasnya - dipecah lewat SuhuRuangServerOptions::pecahWaktu().
--   - statusAc menyimpan KUNCI (lihat SuhuRuangServerOptions), bukan label.
--     Redaksi label boleh diperbaiki tanpa merusak record lama, dan kunci yang
--     tak dikenal dibuang saat ditampilkan - tak pernah dicetak mentah.
--   - DUA suhu per pengukuran: suhuAc (setelan/keluaran AC, biasanya jauh lebih
--     dingin) dan suhuRuang (suhu ruang server itu sendiri). HANYA suhuRuang yang
--     diadu dengan ambang standar - AC yang disetel 17 C wajar dan tidak boleh
--     membuat kondisi jadi Tidak Normal.
--   - RECORD LAMA hanya punya kunci "suhu" (belum ada pemisahan AC/ruang).
--     Angkanya dibaca sebagai suhu RUANG lewat SuhuRuangServerOptions::suhuRuang();
--     suhuAc-nya dibiarkan kosong, bukan ditebak dari suhu ruang - angka itu
--     memang tak pernah diukur. TAK ADA MIGRASI DATA yang diperlukan.
--   - kondisi 'N'/'TN' DIHITUNG dari suhuRuang terhadap ambang standar, lalu DISIMPAN
--     sebagai snapshot. Standar boleh direvisi kelak - yang sudah tercetak dan
--     ditandatangani harus tetap sama dengan yang dinilai petugas saat itu.
--   - TIDAK ADA kelembaban: formulir menyebut standar 40%-60% RH, tapi RS belum
--     punya thermohygrometer. Kalau alatnya kelak ada, cukup tambah kunci di JSON
--     dan satu cabang di hitungKondisi() - TAK ADA PERUBAHAN DDL.
--   - paraf menyimpan nama + kode + waktu saja, bukan gambar.
-- =============================================================
