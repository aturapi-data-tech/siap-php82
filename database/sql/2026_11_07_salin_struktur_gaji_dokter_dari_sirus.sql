-- ═══════════════════════════════════════════════════════════════════════════
-- SIAP — salin struktur gaji dokter dari SIRUS lewat database (tanpa API)
--
-- Setara dengan tombol Gaji Dokter → tab Struktur Gaji → "Tarik dari SIRUS", tetapi membaca
-- SIRUS.RSMST_DOCTORS langsung di Oracle yang sama. Dipakai saat SIRUS produksi belum/tidak
-- memasang endpoint API struktur gaji, atau saat modul gaji dokter SIRUS sudah dimatikan.
--
-- Cara pakai: login SQL Developer / sqlplus sebagai SYSTEM (DBA) pada Oracle produksi
--   (user SIAP produksi tidak punya hak baca skema SIRUS) → tempel seluruh isi → Run Script (F5).
-- Syarat: migrasi SIAP 2026_11_06_210000_gaji_dokter_pindah_ke_siap sudah dijalankan
--   (tabel SIAP.SIAPMST_GDOK_STRUKTURS & SIAP.SIAPMST_PENGATURAN_GDOKS ada).
-- Aman diulang: baris struktur yang sudah bertanda sumber = 'SIAP' (disetel dari SIAP) TIDAK ditimpa.
-- Baris yang masuk bertanda sumber = 'SIRUS', updated_by kosong.
-- ═══════════════════════════════════════════════════════════════════════════

SET DEFINE OFF

-- 1. Master dokter SIAP disamakan dengan SIRUS: nama, NIK, kode BPJS, status aktif/nonaktif.
--    Teks dirapikan dengan REGEXP_REPLACE (bukan TRIM) supaya spasi/baris-baru di ujung ikut terbuang, sama seperti trim() PHP.
--    Dokter yang belum ada dimasukkan tanpa pemetaan karyawan (dipetakan di tab Pemetaan Dokter).
MERGE INTO siap.siapmst_dokter_sirus d
USING (
    SELECT r.dr_id,
           SUBSTR(NVL(REGEXP_REPLACE(r.dr_name, '^[[:space:]]+|[[:space:]]+$', ''), '(tanpa nama) ' || r.dr_id), 1, 100) AS dr_name,
           SUBSTR(REGEXP_REPLACE(r.dr_nik, '^[[:space:]]+|[[:space:]]+$', ''), 1, 30)     AS dr_nik,
           SUBSTR(REGEXP_REPLACE(r.kd_dr_bpjs, '^[[:space:]]+|[[:space:]]+$', ''), 1, 20) AS kd_dr_bpjs,
           CASE WHEN TRIM(r.active_status) = '1' THEN '1' ELSE '0' END       AS aktif
      FROM sirus.rsmst_doctors r
) s
ON (d.dr_id = s.dr_id)
WHEN MATCHED THEN UPDATE SET
    d.dr_name    = s.dr_name,
    d.dr_nik     = s.dr_nik,
    d.kd_dr_bpjs = s.kd_dr_bpjs,
    d.aktif      = s.aktif,
    d.updated_at = SYSTIMESTAMP
WHEN NOT MATCHED THEN INSERT (dr_id, dr_name, dr_nik, kd_dr_bpjs, aktif, emp_id, cara_map, updated_by, created_at, updated_at)
    VALUES (s.dr_id, s.dr_name, s.dr_nik, s.kd_dr_bpjs, s.aktif, NULL, NULL, NULL, SYSTIMESTAMP, SYSTIMESTAMP);

-- 2. Struktur gaji per dokter (pembersihan nilai sama dengan GajiDokterStruktur::rapikan):
--    NPWP hanya Y/N (selain 'N' = Y), nomor NPWP dikosongkan bila tanpa NPWP,
--    aturan berjenjang hanya disimpan bila basis potongan RS = 'B', nominal kosong = 0.
MERGE INTO siap.siapmst_gdok_strukturs t
USING (
    SELECT r.dr_id,
           NVL(r.basic_salary, 0)                                                   AS basic_salary,
           CASE WHEN r.npwp_status = 'N' THEN 'N' ELSE 'Y' END                      AS npwp_status,
           CASE WHEN r.npwp_status = 'N' THEN NULL ELSE SUBSTR(REGEXP_REPLACE(r.npwp, '^[[:space:]]+|[[:space:]]+$', ''), 1, 30) END AS npwp,
           NVL(r.skema_gaji_pokok, 'A')                                             AS skema_gaji_pokok,
           NVL(r.potongan_rs_basis, 'T')                                            AS potongan_rs_basis,
           NVL(r.potongan_rs_persen, 0)                                             AS potongan_rs_persen,
           CASE WHEN NVL(r.potongan_rs_basis, 'T') = 'B' THEN SUBSTR(REGEXP_REPLACE(r.potongan_rs_aturan, '^[[:space:]]+|[[:space:]]+$', ''), 1, 1000) END AS potongan_rs_aturan,
           NVL(r.pph21_persen, 0)                                                   AS pph21_persen,
           NVL(r.tarif_per_kapita_ri, 0)                                            AS tarif_per_kapita_ri,
           NVL(r.tarif_per_kapita_rj, 0)                                            AS tarif_per_kapita_rj,
           NVL(r.tunjangan_struktural, 0)                                           AS tunjangan_struktural,
           NVL(r.tunjangan_fungsional, 0)                                           AS tunjangan_fungsional,
           NVL(r.tunjangan_hadir, 0)                                                AS tunjangan_hadir,
           NVL(r.potongan_idi, 0)                                                   AS potongan_idi,
           NVL(r.potongan_arisan, 0)                                                AS potongan_arisan,
           NVL(r.potongan_koperasi, 0)                                              AS potongan_koperasi,
           NVL(r.potongan_angsuran, 0)                                              AS potongan_angsuran,
           NVL(r.potongan_bpjs, 0)                                                  AS potongan_bpjs,
           NVL(r.potongan_zariyah, 0)                                               AS potongan_zariyah
      FROM sirus.rsmst_doctors r
     WHERE EXISTS (SELECT 1 FROM siap.siapmst_dokter_sirus d WHERE d.dr_id = r.dr_id)
) s
ON (t.dr_id = s.dr_id)
WHEN MATCHED THEN UPDATE SET
    t.basic_salary         = s.basic_salary,
    t.npwp_status          = s.npwp_status,
    t.npwp                 = s.npwp,
    t.skema_gaji_pokok     = s.skema_gaji_pokok,
    t.potongan_rs_basis    = s.potongan_rs_basis,
    t.potongan_rs_persen   = s.potongan_rs_persen,
    t.potongan_rs_aturan   = s.potongan_rs_aturan,
    t.pph21_persen         = s.pph21_persen,
    t.tarif_per_kapita_ri  = s.tarif_per_kapita_ri,
    t.tarif_per_kapita_rj  = s.tarif_per_kapita_rj,
    t.tunjangan_struktural = s.tunjangan_struktural,
    t.tunjangan_fungsional = s.tunjangan_fungsional,
    t.tunjangan_hadir      = s.tunjangan_hadir,
    t.potongan_idi         = s.potongan_idi,
    t.potongan_arisan      = s.potongan_arisan,
    t.potongan_koperasi    = s.potongan_koperasi,
    t.potongan_angsuran    = s.potongan_angsuran,
    t.potongan_bpjs        = s.potongan_bpjs,
    t.potongan_zariyah     = s.potongan_zariyah,
    t.sumber               = 'SIRUS',
    t.disinkron_at         = NULL,
    t.sinkron_pesan        = NULL,
    t.updated_by           = NULL,
    t.updated_at           = SYSTIMESTAMP
    WHERE t.sumber <> 'SIAP'
WHEN NOT MATCHED THEN INSERT (dr_id, basic_salary, npwp_status, npwp, skema_gaji_pokok, potongan_rs_basis, potongan_rs_persen, potongan_rs_aturan,
        pph21_persen, tarif_per_kapita_ri, tarif_per_kapita_rj, tunjangan_struktural, tunjangan_fungsional, tunjangan_hadir,
        potongan_idi, potongan_arisan, potongan_koperasi, potongan_angsuran, potongan_bpjs, potongan_zariyah,
        sumber, disinkron_at, sinkron_pesan, updated_by, created_at, updated_at)
    VALUES (s.dr_id, s.basic_salary, s.npwp_status, s.npwp, s.skema_gaji_pokok, s.potongan_rs_basis, s.potongan_rs_persen, s.potongan_rs_aturan,
        s.pph21_persen, s.tarif_per_kapita_ri, s.tarif_per_kapita_rj, s.tunjangan_struktural, s.tunjangan_fungsional, s.tunjangan_hadir,
        s.potongan_idi, s.potongan_arisan, s.potongan_koperasi, s.potongan_angsuran, s.potongan_bpjs, s.potongan_zariyah,
        'SIRUS', NULL, NULL, NULL, SYSTIMESTAMP, SYSTIMESTAMP);

-- 3. Baris mode (id 1) dipastikan ada; nilainya tidak diubah bila sudah ada.
MERGE INTO siap.siapmst_pengaturan_gdoks p
USING (SELECT 1 AS id FROM dual) s
ON (p.id = s.id)
WHEN NOT MATCHED THEN INSERT (id, sumber_struktur, mesin_slip, updated_by, created_at, updated_at)
    VALUES (1, 'SIRUS', 'SIRUS', NULL, SYSTIMESTAMP, SYSTIMESTAMP);

COMMIT;

-- 4. Pemeriksaan: jumlah dokter & struktur harus sama dengan jumlah baris SIRUS.RSMST_DOCTORS.
SELECT (SELECT COUNT(*) FROM sirus.rsmst_doctors)                              AS dokter_sirus,
       (SELECT COUNT(*) FROM siap.siapmst_dokter_sirus)                        AS dokter_siap,
       (SELECT COUNT(*) FROM siap.siapmst_dokter_sirus WHERE aktif = '1')      AS dokter_aktif,
       (SELECT COUNT(*) FROM siap.siapmst_gdok_strukturs)                      AS struktur_siap,
       (SELECT COUNT(*) FROM siap.siapmst_gdok_strukturs WHERE sumber = 'SIAP') AS struktur_disetel_siap
  FROM dual;
