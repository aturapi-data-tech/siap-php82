-- =============================================================
-- DDL: Tambah kolom STUDY_UID untuk sambungan PACS/Orthanc
-- Jalankan di Oracle sebagai user pemilik schema SIRUS
-- =============================================================

-- StudyInstanceUID DICOM max 64 karakter (DICOM VR UI)
-- Diisi oleh SIRUS setelah query /tools/find ke Orthanc

ALTER TABLE RSTXN_RJRADS ADD (STUDY_UID VARCHAR2(64));
ALTER TABLE RSTXN_UGDRADS ADD (STUDY_UID VARCHAR2(64));
ALTER TABLE RSTXN_RIRADIOLOGS ADD (STUDY_UID VARCHAR2(64));

COMMENT ON COLUMN RSTXN_RJRADS.STUDY_UID IS 'DICOM StudyInstanceUID dari PACS Orthanc, diisi via REST /tools/find by AccessionNumber=RADNUM_NO';
COMMENT ON COLUMN RSTXN_UGDRADS.STUDY_UID IS 'DICOM StudyInstanceUID dari PACS Orthanc, diisi via REST /tools/find by AccessionNumber=RADNUM_NO';
COMMENT ON COLUMN RSTXN_RIRADIOLOGS.STUDY_UID IS 'DICOM StudyInstanceUID dari PACS Orthanc, diisi via REST /tools/find by AccessionNumber=RADNUM_NO';
