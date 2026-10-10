-- =============================================================
-- DDL: Pelaporan Down Time SIMRS (Akreditasi MRMIK 13.1 - Penanganan Down Time)
-- Formulir: DT-01 "Log Kejadian & Penanganan Down Time SIMRS"
--           Diisi Unit IT / Penyelenggara SIMRS untuk SETIAP kejadian waktu henti.
--
-- Jalankan di Oracle sebagai user pemilik schema SIRUS.
-- Target: Oracle 10g (batas nama objek 30 karakter - semua nama di bawah aman).
--
-- BERSIH-PASANG: berkas ini MENGHAPUS dulu lalu MEMBUAT ulang. Bagian A wajar
-- mengeluarkan error "objek tidak ada" di environment yang masih bersih.
--
-- ⚠️ BAGIAN A MENGHAPUS TABEL BESERTA ISINYA.
--
-- HUBUNGANNYA DENGAN MODUL DOWN TIME YANG SUDAH ADA. Menu "Formulir Manual Down
-- Time" mencetak formulir KOSONG untuk diisi tangan saat SIMRS mati - termasuk
-- DT-01 ini (resources/views/pages/downtime/cetak/form/dt-01-log-kejadian.blade.php).
-- Modul ini kebalikannya: MEREKAM isi DT-01 ke sistem SESUDAH layanan pulih,
-- supaya laporannya bisa dicari, direkap, dan dievaluasi. Bentuk & urutan
-- bagiannya sengaja dibuat sama persis dengan formulir cetaknya.
--
-- SATU BARIS = SATU KEJADIAN. Down time itu peristiwa yang berdiri sendiri: dua
-- kejadian di hari yang sama adalah dua laporan terpisah dengan penyebab &
-- penanganannya masing-masing.
--
-- DUA KOLOM SAJA, TANPA PERIODE - sama seperti dua modul pemantauan ruang server
-- (docs/ddl-pemantauan-suhu-ruang-server.sql, docs/ddl-pemantauan-akses-ruang-server.sql).
-- Konsekuensinya disadari: tak ada kolom datar yang bisa dipakai memfilter lewat
-- SQL, jadi "laporan bulan ini" dikerjakan dengan men-decode seluruh baris di PHP.
-- Aman untuk volume modul ini - down time bukan peristiwa harian. Kalau kelak
-- terasa berat, yang harus dinaikkan jadi kolom datar adalah WAKTU MULAI (tipe
-- DATE): ia sekaligus melayani filter bulan, urutan, dan rekap tahunan.
--
-- TANDA TANGAN DITEKEN DI KERTAS. Tak ada TTD tersimpan, tak ada status
-- terkunci/buka kunci: cetakan laporan keluar dengan TIGA garis tanda tangan
-- kosong (Petugas IT Penanganan, Ka. Unit IT / SIMRS, Manajemen RS), persis
-- formulir kertasnya. Yang tersimpan cuma PARAF petugas yang merekam laporan
-- (nama + kode + waktu), terisi otomatis dari pengguna yang login.
-- =============================================================


-- =============================================================
-- BAGIAN A - BERSIHKAN
-- =============================================================
-- Abaikan error berikut kalau objeknya memang belum pernah dibuat:
--   ORA-00942  tabel/view tidak ada
--   ORA-02289  sequence tidak ada
--   ORA-01418  indeks tidak ada
-- Selain tiga itu, berhenti dan periksa.

DROP TABLE RSTXN_DOWNTIMES;

DROP SEQUENCE SEQ_DOWNTIMES;

-- Sisa rancangan lama yang berkolom PERIODE.
DROP INDEX IDX_DOWNTIME_PERIODE;


-- =============================================================
-- BAGIAN B - BUAT
-- =============================================================

CREATE TABLE RSTXN_DOWNTIMES (
    -- Dua kolom saja. Seluruh isi laporan ada di DOWNTIME_JSON.
    DOWNTIME_NO     NUMBER      NOT NULL,   -- PK, dari SEQ_DOWNTIMES
    DOWNTIME_JSON   CLOB,

    CONSTRAINT PK_DOWNTIMES PRIMARY KEY (DOWNTIME_NO)
);

CREATE SEQUENCE SEQ_DOWNTIMES START WITH 1 INCREMENT BY 1 NOCACHE;


-- =============================================================
-- BAGIAN C - KETERANGAN KOLOM
-- =============================================================
-- Tanpa titik koma di dalam teks komentar: pemecah statement per-';' akan
-- memotongnya jadi ORA-01756.

COMMENT ON TABLE RSTXN_DOWNTIMES IS
    'Laporan DT-01 Log Kejadian & Penanganan Down Time SIMRS (MRMIK 13.1). Satu baris = satu kejadian waktu henti';

COMMENT ON COLUMN RSTXN_DOWNTIMES.DOWNTIME_NO IS
    'PK dari SEQ_DOWNTIMES';

COMMENT ON COLUMN RSTXN_DOWNTIMES.DOWNTIME_JSON IS
    'Isi satu laporan. Node kejadian (A), pelaporan (B), penanganan (C), dampak (D), evaluasi (E), paraf';


-- =============================================================
-- BAGIAN D - BENTUK DOWNTIME_JSON (acuan, bukan perintah)
-- =============================================================
-- {
--   "kejadian": {                                     -- Bagian A
--       "jenis": "tidakTerencana", "noLog": "DT-2026-014",
--       "waktuMulai": "12/08/2026 08:15:00",
--       "waktuPulih": "12/08/2026 10:40:00",
--       "durasi": "2 jam 25 menit",
--       "lingkup": "sebagianModul",
--       "modulTerdampak": "Pendaftaran RJ, Kasir"
--   },
--   "pelaporan": {                                    -- Bagian B
--       "dilaporkanOleh": "", "unitPelapor": "", "jamLaporanDiterima": "08:20",
--       "mediaLaporan": "telepon", "gejalaAwal": ""
--   },
--   "penanganan": {                                   -- Bagian C
--       "penyebab": "", "estimasiPemulihan": "", "jamInformasi": "08:35",
--       "tindakan": "", "hasil": ""
--   },
--   "dampak": [                                       -- Bagian D
--       { "unit": "pendaftaran", "manual": true, "jumlah": "37", "catatan": "" }
--   ],
--   "evaluasi": {                                     -- Bagian E
--       "akarMasalah": "", "rencanaTindakLanjut": "", "penanggungJawab": "",
--       "targetSelesai": "20/08/2026", "statusBackup": ""
--   },
--   "paraf": { "nama": "Nama User", "kode": "ADM", "tanggal": "12/08/2026 11:00:00" }
-- }
--
-- CATATAN ISI:
--   - waktuMulai & waktuPulih berformat "dd/mm/yyyy HH:MM:SS" - keduanya LENGKAP
--     dengan tanggal, bukan tanggal & jam di kunci terpisah. Itu yang membuat
--     gangguan yang melewati tengah malam terhitung durasinya dengan benar.
--   - waktuPulih boleh KOSONG: artinya layanan belum dinyatakan pulih. Layar
--     menyorotnya supaya petugas ingat melengkapi, bukan menolak laporannya.
--   - jenis, lingkup, mediaLaporan, dan dampak[].unit menyimpan KUNCI (lihat
--     App\Support\Options\PelaporanDowntimeOptions), bukan label.
--   - durasi DIHITUNG dari waktu mulai & pulih lalu DISIMPAN sebagai snapshot,
--     supaya angka di laporan yang sudah dicetak & ditandatangani tak berubah.
--   - dampak selalu berisi SEMUA unit dari daftar baku, termasuk yang tak
--     terdampak. "Tidak terdampak" adalah keterangan yang bernilai untuk auditor -
--     baris yang dihilangkan tak bisa dibedakan dari baris yang lupa diisi.
--   - paraf menyimpan nama + kode + waktu saja, bukan gambar.
-- =============================================================
