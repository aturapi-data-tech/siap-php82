-- =============================================================
-- DDL: Pemantauan AKSES Ruang Server (Akreditasi MRMIK 2.2 - Perlindungan Data)
--      Pendamping docs/ddl-pemantauan-suhu-ruang-server.sql - bentuk & alasannya
--      sama persis, isinya yang berbeda.
--
-- Jalankan di Oracle sebagai user pemilik schema SIRUS.
-- Target: Oracle 10g (batas nama objek 30 karakter - semua nama di bawah aman).
--
-- BERSIH-PASANG: berkas ini MENGHAPUS dulu lalu MEMBUAT ulang. Bagian A wajar
-- mengeluarkan error "objek tidak ada" di environment yang masih bersih.
--
-- ⚠️ BAGIAN A MENGHAPUS TABEL BESERTA ISINYA.
--
-- APA INI. Catatan siapa yang keluar-masuk ruang server, kapan, dan untuk apa.
-- Ruang server menyimpan seluruh rekam medis elektronik, jadi aksesnya dibatasi
-- dan setiap kunjungan - terutama oleh vendor & auditor dari luar - harus
-- tercatat dan didampingi petugas IT. Sebulan sekali catatan itu dicetak jadi
-- formulir resmi, ditandatangani Petugas Pencatat dan diketahui Ka. Unit IT.
--
-- SATU BARIS = SATU KUNJUNGAN. Bukan satu lembar bulanan berisi array kunjungan:
-- tiap kunjungan berdiri sendiri, ditulis satu per satu sepanjang bulan.
-- Merekamnya per baris berarti dua petugas yang mencatat pada menit yang sama
-- tak mungkin saling menimpa, tak perlu lock, dan menghapus satu catatan salah
-- tak menyentuh catatan lainnya.
--
-- FORMULIR DIRAKIT SAAT CETAK. Kop formulir (nama ruang, gedung, penanggung
-- jawab) TIDAK disimpan per baris - nilainya tetap sepanjang umur ruangan, jadi
-- tinggal di App\Support\Options\RuangServerOptions dan dipasang saat mencetak.
-- Berkas itu SENGAJA dipakai bersama modul pemantauan suhu: mengganti nama ruang
-- cukup di satu tempat.
--
-- TANDA TANGAN DITEKEN DI KERTAS. Tak ada TTD tersimpan, tak ada status
-- terkunci/buka kunci. Yang tersimpan per baris cuma PARAF petugas yang mencatat
-- (nama + kode + waktu), terisi otomatis dari pengguna yang login.
--
-- DUA KOLOM SAJA, TANPA PERIODE. Konsekuensinya disadari: tak ada kolom datar
-- yang bisa dipakai memfilter lewat SQL, jadi "kunjungan bulan ini" dikerjakan
-- dengan men-decode seluruh baris di PHP. Aman untuk volume modul ini - ruang
-- server tidak ramai. Kalau kelak terasa berat, yang harus dinaikkan jadi kolom
-- datar adalah WAKTU masuk (tipe DATE): ia sekaligus melayani filter bulan,
-- urutan, dan laporan tahunan.
-- =============================================================


-- =============================================================
-- BAGIAN A - BERSIHKAN
-- =============================================================
-- Abaikan error berikut kalau objeknya memang belum pernah dibuat:
--   ORA-00942  tabel/view tidak ada
--   ORA-02289  sequence tidak ada
--   ORA-01418  indeks tidak ada
-- Selain tiga itu, berhenti dan periksa.

DROP TABLE RSTXN_AKSESSERVERS;

DROP SEQUENCE SEQ_AKSESSERVERS;

-- Sisa rancangan lama yang berlembar bulanan.
DROP INDEX IDX_AKSESSERVER_PERIODE;


-- =============================================================
-- BAGIAN B - BUAT
-- =============================================================

CREATE TABLE RSTXN_AKSESSERVERS (
    -- Dua kolom saja. Seluruh isi kunjungan ada di AKSESSERVER_JSON.
    AKSESSERVER_NO      NUMBER      NOT NULL,   -- PK, dari SEQ_AKSESSERVERS
    AKSESSERVER_JSON    CLOB,

    CONSTRAINT PK_AKSESSERVERS PRIMARY KEY (AKSESSERVER_NO)
);

CREATE SEQUENCE SEQ_AKSESSERVERS START WITH 1 INCREMENT BY 1 NOCACHE;


-- =============================================================
-- BAGIAN C - KETERANGAN KOLOM
-- =============================================================
-- Tanpa titik koma di dalam teks komentar: pemecah statement per-';' akan
-- memotongnya jadi ORA-01756.

COMMENT ON TABLE RSTXN_AKSESSERVERS IS
    'Pemantauan Akses Ruang Server (MRMIK 2.2). Satu baris = satu kunjungan keluar-masuk';

COMMENT ON COLUMN RSTXN_AKSESSERVERS.AKSESSERVER_NO IS
    'PK dari SEQ_AKSESSERVERS';

COMMENT ON COLUMN RSTXN_AKSESSERVERS.AKSESSERVER_JSON IS
    'Isi satu kunjungan. Kunci waktu, waktuKeluar, nama, unitInstansi, jenisPengunjung, keperluan, membawaPerangkat, didampingi, catatan, paraf';


-- =============================================================
-- BAGIAN D - BENTUK AKSESSERVER_JSON (acuan, bukan perintah)
-- =============================================================
-- {
--   "waktu":            "26/08/2026 09:00:00",
--   "waktuKeluar":      "26/08/2026 10:15:00",
--   "nama":             "Nama Tamu",
--   "unitInstansi":     "PT Vendor Jaya",
--   "jenisPengunjung":  "vendor",
--   "keperluan":        "perbaikan",
--   "keperluanLain":    "",
--   "membawaPerangkat": "laptop, HDD eksternal",
--   "didampingi":       "Nama Petugas IT",
--   "catatan":          "",
--   "paraf": { "nama": "Nama User", "kode": "ADM", "tanggal": "26/08/2026 10:16:02" }
-- }
--
-- CATATAN ISI:
--   - waktu & waktuKeluar berformat "dd/mm/yyyy HH:MM:SS" - keduanya LENGKAP
--     dengan tanggal, bukan jam saja. Itu yang membuat kunjungan yang melewati
--     tengah malam tercatat sebagai satu baris, bukan dipaksa dipecah dua.
--   - waktuKeluar boleh KOSONG: artinya tamunya belum keluar. Layar menyorotnya
--     supaya petugas ingat melengkapi, bukan menolak barisnya.
--   - jenisPengunjung & keperluan menyimpan KUNCI (lihat AksesRuangServerOptions),
--     bukan label. Redaksi label boleh diperbaiki tanpa merusak record lama, dan
--     kunci tak dikenal dibuang saat ditampilkan - tak pernah dicetak mentah.
--   - didampingi WAJIB untuk pengunjung non-internal. Ditegakkan saat menyimpan,
--     bukan sekadar tulisan di label.
--   - paraf menyimpan nama + kode + waktu saja, bukan gambar.
-- =============================================================
