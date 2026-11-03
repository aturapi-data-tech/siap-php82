<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Indikator mutu RSUI Madinah 2026 — kertas kerja digital.
 *
 * Sumber rumus: dokumen "Daftar dan Profil Indikator Mutu 2026" (konsep, Komite Mutu) yang
 * dibangun dari PMK 30/2022 (13 INM) dan SK 166/SK/RSI-MDN/1/2026 (IMP-Unit & IMP-RS).
 * Ubah rumus/target DI SINI saja — kertas kerja, rekap, run chart, dan cetak membaca definisi ini.
 *
 * Satu baris kertas kerja = satu pengamatan. Saat disimpan, baris dihitung menjadi
 * pembilang/penyebut (hitungBaris) sehingga capaian = SUM(pembilang) ÷ SUM(penyebut) × pengali.
 * Rekap triwulan/tahunan memakai jumlah pembilang ÷ jumlah penyebut, BUKAN rata-rata persen.
 *
 * Unit pengisi ('unit'):
 *   SEMUA_UNIT  → semua unit boleh mengisi (kebersihan tangan, APD, identifikasi).
 *   TINGKAT_RS  → diisi Komite Mutu, tersimpan dengan unit_id NULL (komplain, kepuasan, CP, MPP).
 *   [kode unit] → hanya unit tsb. Kode yang belum ada di Master Unit Kerja (VK, IC, RH, SN)
 *                 baru muncul setelah unitnya ditambahkan dengan kode yang sama.
 */
class IndikatorMutu
{
    /** Role yang melihat & mengisi semua unit, termasuk indikator tingkat RS, dan mengunci bulan. */
    public const ROLE_SEMUA_UNIT = ['admin', 'komite mutu'];

    public const SEMUA_UNIT = '*';

    /** Penanda unit untuk indikator tingkat RS (bukan kode unit — 'RS' sudah dipakai Ruang Said). */
    public const TINGKAT_RS = '#RS';

    public const KELOMPOK = [
        'INM'      => 'Indikator Nasional Mutu',
        'IMP-UNIT' => 'Indikator Mutu Prioritas Unit',
        'IMP-RS'   => 'Indikator Mutu Prioritas RS',
    ];

    /** Ruang rawat inap (+ ICU) — pengisi visite, risiko jatuh, readmisi, CP ADHF, konsul jantung. */
    private const RAWAT_INAP = ['JS', 'RA', 'RS', 'SH', 'IC'];

    public const NAMA_BULAN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private static ?array $definisi = null;

    // ─── Definisi ─────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> kode → definisi, urut sesuai daftar profil. */
    public static function definisi(): array
    {
        if (self::$definisi !== null) {
            return self::$definisi;
        }

        $pasien = [
            ['key' => 'pasien', 'label' => 'Nama pasien', 'tipe' => 'teks', 'wajib' => true],
            ['key' => 'no_rm', 'label' => 'No. RM', 'tipe' => 'teks', 'wajib' => false],
        ];
        $petugas = ['key' => 'petugas', 'label' => 'Petugas / profesi', 'tipe' => 'teks', 'wajib' => true];

        $daftar = [
            // ═══════════════ INM — PMK 30/2022 ═══════════════
            self::buat('INM-01', ['INM'], 'Kepatuhan Kebersihan Tangan', self::SEMUA_UNIT, 'Peluang',
                'Jumlah tindakan kebersihan tangan yang dilakukan dengan benar',
                'Jumlah seluruh peluang kebersihan tangan yang diobservasi',
                '>=', 85,
                'Satu baris = satu peluang (momen), bukan satu petugas. Patuh bila kebersihan tangan dilakukan pada momen yang tepat dengan 6 langkah.',
                [
                    $petugas,
                    ['key' => 'momen', 'label' => 'Momen', 'tipe' => 'pilihan', 'wajib' => true, 'opsi' => [
                        '1' => '1. Sebelum kontak pasien', '2' => '2. Sebelum tindakan aseptik',
                        '3' => '3. Setelah terpapar cairan tubuh', '4' => '4. Setelah kontak pasien',
                        '5' => '5. Setelah kontak lingkungan pasien']],
                    ['key' => 'dilakukan', 'label' => 'Kebersihan tangan dilakukan benar (6 langkah)', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'ceklis_semua', 'kolom' => ['dilakukan']]),

            self::buat('INM-02', ['INM'], 'Kepatuhan Penggunaan APD', self::SEMUA_UNIT, 'Petugas',
                'Jumlah petugas yang patuh menggunakan APD sesuai indikasi',
                'Jumlah petugas yang terindikasi menggunakan APD dan diobservasi',
                '>=', 100,
                'Catat HANYA petugas yang sedang melakukan tindakan yang memerlukan APD. Petugas yang tidak terindikasi tidak dicatat.',
                [
                    $petugas,
                    ['key' => 'tindakan', 'label' => 'Tindakan', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'patuh', 'label' => 'APD lengkap sesuai indikasi', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'ceklis_semua', 'kolom' => ['patuh']]),

            self::buat('INM-03', ['INM', 'IMP-RS'], 'Kepatuhan Identifikasi Pasien', self::SEMUA_UNIT, 'Pemberi layanan',
                'Jumlah pemberi layanan yang melakukan identifikasi pasien secara benar',
                'Jumlah pemberi layanan yang diobservasi',
                '>=', 100,
                'Patuh bila petugas mencocokkan minimal dua identitas (nama, tanggal lahir, No. RM) secara aktif sebelum memberi layanan. Melihat gelang terpasang saja belum cukup.',
                [
                    $petugas,
                    ['key' => 'layanan', 'label' => 'Jenis layanan', 'tipe' => 'pilihan', 'wajib' => true, 'opsi' => [
                        'OBAT' => 'Pemberian obat', 'CAIRAN' => 'Cairan intravena', 'DARAH' => 'Darah / produk darah',
                        'TINDAKAN' => 'Tindakan / operasi', 'DIAGNOSTIK' => 'Pengambilan sampel / diagnostik', 'DIET' => 'Pemberian diet']],
                    ['key' => 'benar', 'label' => 'Mencocokkan minimal 2 identitas secara aktif', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'ceklis_semua', 'kolom' => ['benar']],
                kategoriRs: 'Sasaran keselamatan pasien'),

            self::buat('INM-04', ['INM'], 'Waktu Tanggap Operasi SC Emergensi Kategori I', ['OK', 'VK'], 'Pasien',
                'Jumlah pasien SC emergensi kategori I yang diinsisi ≤ 30 menit sejak keputusan operasi',
                'Jumlah seluruh pasien SC emergensi kategori I',
                '>=', 80,
                'Hanya SC emergensi kategori I. Waktu dari jam keputusan operasi oleh DPJP sampai jam insisi kulit.',
                [...$pasien,
                    ['key' => 'jam_keputusan', 'label' => 'Jam keputusan operasi', 'tipe' => 'jam'],
                    ['key' => 'jam_insisi', 'label' => 'Jam insisi', 'tipe' => 'jam'],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_keputusan', 'selesai' => 'jam_insisi', 'batas' => 30, 'op' => '<=']),

            self::buat('INM-05', ['INM'], 'Waktu Tunggu Rawat Jalan', ['PL'], 'Pasien',
                'Jumlah pasien rawat jalan dengan waktu tunggu ≤ 60 menit',
                'Jumlah seluruh pasien rawat jalan yang diobservasi',
                '>=', 80,
                'Dari jam terdaftar sampai jam MULAI dilayani dokter — bukan sampai pasien pulang atau selesai di apotek.',
                [...$pasien,
                    ['key' => 'poli', 'label' => 'Poliklinik', 'tipe' => 'teks', 'wajib' => false],
                    ['key' => 'jam_daftar', 'label' => 'Jam terdaftar', 'tipe' => 'jam'],
                    ['key' => 'jam_dokter', 'label' => 'Jam mulai dilayani dokter', 'tipe' => 'jam'],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_daftar', 'selesai' => 'jam_dokter', 'batas' => 60, 'op' => '<=']),

            self::buat('INM-06', ['INM'], 'Penundaan Operasi Elektif', ['OK'], 'Pasien',
                'Jumlah pasien operasi elektif yang tertunda > 1 jam dari jadwal',
                'Jumlah seluruh pasien operasi elektif',
                '<=', 5,
                'Indikator terbalik — makin kecil makin baik. Tertunda bila operasi dimulai lebih dari 60 menit dari jadwal. Penundaan karena kondisi medis / permintaan pasien = eksklusi.',
                [...$pasien,
                    ['key' => 'jam_jadwal', 'label' => 'Jam jadwal operasi', 'tipe' => 'jam'],
                    ['key' => 'jam_mulai', 'label' => 'Jam operasi dimulai', 'tipe' => 'jam'],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_jadwal', 'selesai' => 'jam_mulai', 'batas' => 60, 'op' => '>']),

            self::buat('INM-07', ['INM'], 'Kepatuhan Waktu Visite Dokter', self::RAWAT_INAP, 'Pasien',
                'Jumlah pasien yang di-visite DPJP pada pukul 06.00–14.00',
                'Jumlah seluruh pasien rawat inap yang diobservasi',
                '>=', 80,
                'Satu baris = satu pasien (bukan satu dokter). Tepat waktu bila jam visite DPJP pukul 06.00–14.00.',
                [...$pasien,
                    ['key' => 'dpjp', 'label' => 'DPJP', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'jam_visite', 'label' => 'Jam visite', 'tipe' => 'jam'],
                ],
                ['jenis' => 'jam_rentang', 'kolom' => 'jam_visite', 'dari' => '06:00', 'sampai' => '14:00']),

            self::buat('INM-08', ['INM'], 'Pelaporan Hasil Kritis Laboratorium', ['LB'], 'Hasil kritis',
                'Jumlah hasil kritis laboratorium yang dilaporkan ≤ 30 menit',
                'Jumlah seluruh hasil kritis laboratorium',
                '>=', 100,
                'Dari jam hasil diverifikasi sampai jam diterima dokter (baca ulang).',
                [...$pasien,
                    ['key' => 'pemeriksaan', 'label' => 'Pemeriksaan & nilai kritis', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'jam_verifikasi', 'label' => 'Jam hasil diverifikasi', 'tipe' => 'jam'],
                    ['key' => 'jam_diterima', 'label' => 'Jam diterima dokter', 'tipe' => 'jam'],
                    ['key' => 'penerima', 'label' => 'Dokter penerima', 'tipe' => 'teks', 'wajib' => false],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_verifikasi', 'selesai' => 'jam_diterima', 'batas' => 30, 'op' => '<=']),

            self::buat('INM-09', ['INM'], 'Kepatuhan Penggunaan Formularium Nasional', ['FR'], 'Resep JKN',
                'Jumlah item R/ dalam resep pasien JKN yang sesuai Fornas',
                'Jumlah seluruh item R/ dalam resep pasien JKN yang diobservasi',
                '>=', 80,
                'Yang dihitung ITEM obat (R/), bukan lembar resep. Resep berisi 5 R/ dihitung 5.',
                [...$pasien,
                    ['key' => 'no_resep', 'label' => 'No. resep', 'tipe' => 'teks', 'wajib' => false],
                    ['key' => 'r_total', 'label' => 'Jumlah R/ dalam resep', 'tipe' => 'angka'],
                    ['key' => 'r_fornas', 'label' => 'Jumlah R/ sesuai Fornas', 'tipe' => 'angka'],
                ],
                ['jenis' => 'jumlah', 'pembilang' => 'r_fornas', 'penyebut' => 'r_total']),

            self::buat('INM-10', ['INM'], 'Kepatuhan terhadap Clinical Pathway', self::TINGKAT_RS, 'Pasien CP',
                'Jumlah pelayanan oleh PPA yang sesuai clinical pathway',
                'Jumlah seluruh pelayanan oleh PPA pada clinical pathway yang diobservasi',
                '>=', 80,
                'Nilai seluruh butir CP oleh semua PPA (dokter, perawat, farmasi, gizi), bukan hanya DPJP. Delapan CP sesuai SK 256/SK/RSI-MDN/1/2026.',
                [...$pasien,
                    ['key' => 'cp', 'label' => 'Clinical pathway', 'tipe' => 'pilihan', 'wajib' => true, 'opsi' => [
                        'CVA' => 'CVA infark trombotik akut', 'ULKUS' => 'Ulkus diabetikum', 'HIV' => 'HIV',
                        'TB' => 'TB paru', 'AML' => 'Acute myeloid leukemia', 'APP' => 'Apendisitis akut',
                        'BP' => 'Bronkopneumonia', 'KATARAK' => 'Katarak']],
                    ['key' => 'butir_total', 'label' => 'Jumlah butir CP', 'tipe' => 'angka'],
                    ['key' => 'butir_sesuai', 'label' => 'Butir dilaksanakan sesuai', 'tipe' => 'angka'],
                ],
                ['jenis' => 'jumlah', 'pembilang' => 'butir_sesuai', 'penyebut' => 'butir_total']),

            self::buat('INM-11', ['INM'], 'Kepatuhan Upaya Pencegahan Risiko Pasien Jatuh', [...self::RAWAT_INAP, 'VK'], 'Pasien risiko tinggi',
                'Jumlah pasien risiko tinggi jatuh yang mendapat ketiga upaya pencegahan',
                'Jumlah seluruh pasien rawat inap risiko tinggi jatuh yang diobservasi',
                '>=', 100,
                'Catat hanya pasien RISIKO TINGGI jatuh. Patuh bila KETIGA upaya dilakukan: asesmen awal, asesmen ulang, intervensi (termasuk stiker kuning). Satu kosong = tidak patuh.',
                [...$pasien,
                    ['key' => 'asesmen_awal', 'label' => 'Asesmen awal', 'tipe' => 'ceklis'],
                    ['key' => 'asesmen_ulang', 'label' => 'Asesmen ulang', 'tipe' => 'ceklis'],
                    ['key' => 'intervensi', 'label' => 'Intervensi (stiker, pengaman, edukasi)', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'ceklis_semua', 'kolom' => ['asesmen_awal', 'asesmen_ulang', 'intervensi']]),

            self::buat('INM-12', ['INM'], 'Kecepatan Waktu Tanggap Komplain', self::TINGKAT_RS, 'Komplain',
                'Jumlah komplain yang ditanggapi dan ditindaklanjuti sesuai waktu grading',
                'Jumlah seluruh komplain yang diterima',
                '>=', 80,
                'Batas waktu menurut grading: merah ≤ 1 × 24 jam, kuning ≤ 3 hari, hijau ≤ 7 hari.',
                [
                    ['key' => 'pelapor', 'label' => 'Pelapor', 'tipe' => 'teks', 'wajib' => false],
                    ['key' => 'isi', 'label' => 'Isi komplain', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'grading', 'label' => 'Grading', 'tipe' => 'pilihan', 'wajib' => true, 'opsi' => [
                        'M' => 'Merah (≤ 24 jam)', 'K' => 'Kuning (≤ 3 hari)', 'H' => 'Hijau (≤ 7 hari)']],
                    ['key' => 'waktu_terima', 'label' => 'Diterima', 'tipe' => 'waktu'],
                    ['key' => 'waktu_tanggap', 'label' => 'Ditanggapi & ditindaklanjuti', 'tipe' => 'waktu'],
                ],
                ['jenis' => 'durasi_grading', 'grading' => 'grading', 'mulai' => 'waktu_terima', 'selesai' => 'waktu_tanggap',
                    'batas' => ['M' => 1440, 'K' => 4320, 'H' => 10080]]),

            self::buat('INM-13', ['INM'], 'Kepuasan Pasien', self::TINGKAT_RS, 'Responden',
                'Jumlah nilai persepsi 9 unsur seluruh responden',
                'Jumlah unsur × jumlah responden',
                '>=', 76.61,
                'Satu baris = satu kuesioner SKM. Nilai tiap unsur 1–4. Indeks = rata-rata nilai × 25.',
                [
                    ['key' => 'responden', 'label' => 'Kode responden', 'tipe' => 'teks', 'wajib' => false],
                    ...array_map(fn($nomor, $unsur) => ['key' => "u{$nomor}", 'label' => "U{$nomor} {$unsur}", 'tipe' => 'nilai'],
                        range(1, 9),
                        ['Persyaratan', 'Prosedur', 'Waktu', 'Biaya/tarif', 'Produk layanan', 'Kompetensi', 'Perilaku', 'Pengaduan', 'Sarana']),
                ],
                ['jenis' => 'survei', 'kolom' => array_map(fn($nomor) => "u{$nomor}", range(1, 9))],
                pengali: 25, satuan: ''),

            // ═══════════════ IMP-Unit — SK 166/2026 ═══════════════
            self::buat('IMU-IGD', ['IMP-UNIT'], 'Emergency Response Time IGD ≤ 5 Menit', ['IG'], 'Pasien',
                'Jumlah pasien IGD yang dilayani dokter ≤ 5 menit sejak tiba',
                'Jumlah seluruh pasien IGD yang diobservasi',
                '>=', 100,
                'Dari jam pasien tiba di IGD sampai jam mulai dilayani dokter. Pasien DOA = eksklusi.',
                [...$pasien,
                    ['key' => 'jam_tiba', 'label' => 'Jam tiba', 'tipe' => 'jam'],
                    ['key' => 'jam_dokter', 'label' => 'Jam dilayani dokter', 'tipe' => 'jam'],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_tiba', 'selesai' => 'jam_dokter', 'batas' => 5, 'op' => '<=']),

            self::buat('IMU-RJ', ['IMP-UNIT'], 'Ketepatan Waktu Kedatangan Dokter Sesuai Jadwal Praktik', ['PL'], 'Jadwal praktik',
                'Jumlah kedatangan dokter ≤ 60 menit dari jam praktik',
                'Jumlah seluruh jadwal praktik dokter yang terlaksana',
                '>=', 100,
                'Satu baris = satu jadwal praktik. Tepat bila dokter tiba paling lambat 60 menit setelah jam praktik (SK 166).',
                [
                    ['key' => 'dokter', 'label' => 'Dokter', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'poli', 'label' => 'Poliklinik', 'tipe' => 'teks', 'wajib' => false],
                    ['key' => 'jam_praktik', 'label' => 'Jam praktik', 'tipe' => 'jam'],
                    ['key' => 'jam_tiba', 'label' => 'Jam tiba', 'tipe' => 'jam'],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_praktik', 'selesai' => 'jam_tiba', 'batas' => 60, 'op' => '<=']),

            self::buat('IMU-RI', ['IMP-UNIT', 'IMP-RS'], 'Angka Readmisi Pasien BPJS Rawat Inap', self::RAWAT_INAP, 'Pasien BPJS pulang',
                'Jumlah pasien BPJS dirawat kembali tidak terencana dengan diagnosis sama ≤ 30 hari',
                'Jumlah seluruh pasien BPJS rawat inap yang pulang hidup',
                '<=', 5,
                'Indikator terbalik. Satu baris = satu pasien BPJS pulang hidup (tanggal = tanggal pulang). Centang bila pasien dirawat kembali ≤ 30 hari, tidak terencana, diagnosis utama sama. Angka bulan ini baru final 30 hari kemudian.',
                [...$pasien,
                    ['key' => 'diagnosis', 'label' => 'Diagnosis utama', 'tipe' => 'teks', 'wajib' => false],
                    ['key' => 'readmisi', 'label' => 'Readmisi ≤ 30 hari (tidak terencana, diagnosis sama)', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'kejadian', 'kolom' => 'readmisi'],
                kategoriRs: 'Terkait manajemen risiko'),

            self::buat('IMU-OK', ['IMP-UNIT'], 'Ketepatan Diagnosis Pre dan Post Operasi', ['OK'], 'Pasien operasi',
                'Jumlah pasien operasi yang diagnosis pre dan post operasinya sama',
                'Jumlah seluruh pasien operasi',
                '>=', 100,
                'Operasi eksplorasi / menunggu hasil PA = eksklusi.',
                [...$pasien,
                    ['key' => 'dx_pre', 'label' => 'Diagnosis pre operasi', 'tipe' => 'teks', 'wajib' => false],
                    ['key' => 'dx_post', 'label' => 'Diagnosis post operasi', 'tipe' => 'teks', 'wajib' => false],
                    ['key' => 'sama', 'label' => 'Diagnosis pre = post', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'ceklis_semua', 'kolom' => ['sama']]),

            self::buat('IMU-VK', ['IMP-UNIT'], 'Angka Tidak Dilakukannya IMD pada Bayi Baru Lahir', ['VK'], 'Bayi lahir hidup',
                'Jumlah bayi lahir hidup yang memenuhi syarat IMD tetapi tidak dilakukan IMD',
                'Jumlah seluruh bayi lahir hidup yang memenuhi syarat IMD',
                '<=', 0,
                'Indikator terbalik, target 0%. Centang bila bayi TIDAK dilakukan IMD. Bayi/ibu dengan kondisi medis yang tidak memungkinkan IMD = eksklusi.',
                [
                    ['key' => 'ibu', 'label' => 'Nama ibu', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'no_rm', 'label' => 'No. RM', 'tipe' => 'teks', 'wajib' => false],
                    ['key' => 'persalinan', 'label' => 'Persalinan', 'tipe' => 'pilihan', 'wajib' => true, 'opsi' => ['N' => 'Normal', 'SC' => 'Seksio sesarea']],
                    ['key' => 'tidak_imd', 'label' => 'Bayi TIDAK dilakukan IMD', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'kejadian', 'kolom' => 'tidak_imd']),

            self::buat('IMU-RAD', ['IMP-UNIT'], 'Waktu Tunggu Hasil Foto Thorax ≤ 3 Jam', ['RR'], 'Pemeriksaan',
                'Jumlah foto thorax dengan waktu tunggu hasil ≤ 3 jam',
                'Jumlah seluruh foto thorax yang diobservasi',
                '>=', 100,
                'Dari jam pasien difoto sampai jam hasil ekspertise diterima.',
                [...$pasien,
                    ['key' => 'jam_foto', 'label' => 'Jam difoto', 'tipe' => 'jam'],
                    ['key' => 'jam_hasil', 'label' => 'Jam hasil ekspertise', 'tipe' => 'jam'],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_foto', 'selesai' => 'jam_hasil', 'batas' => 180, 'op' => '<=']),

            self::buat('IMU-RHB', ['IMP-UNIT'], 'Kejadian Drop Out Pasien Rehabilitasi Medik', ['RH'], 'Pasien program',
                'Jumlah pasien yang drop out dari program rehabilitasi medik',
                'Jumlah seluruh pasien yang mendapat program rehabilitasi medik',
                '<=', 50,
                'Indikator terbalik. Centang bila pasien berhenti sebelum program selesai tanpa alasan medis. Dihentikan atas saran dokter / dirujuk = eksklusi.',
                [...$pasien,
                    ['key' => 'drop_out', 'label' => 'Drop out', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'kejadian', 'kolom' => 'drop_out']),

            self::buat('IMU-FAR', ['IMP-UNIT'], 'Tidak Adanya Kejadian Kesalahan Pemberian Obat', ['FR'], 'Hari pelayanan',
                'Jumlah pasien dilayani dikurangi pasien yang mengalami kesalahan pemberian obat',
                'Jumlah seluruh pasien yang dilayani instalasi farmasi',
                '>=', 100,
                'Satu baris = satu hari. Near miss (tertangkap sebelum sampai ke pasien) tidak dihitung sebagai kesalahan — laporkan sebagai KNC.',
                [
                    ['key' => 'dilayani', 'label' => 'Jumlah pasien dilayani', 'tipe' => 'angka'],
                    ['key' => 'kesalahan', 'label' => 'Pasien mengalami kesalahan pemberian obat', 'tipe' => 'angka'],
                ],
                ['jenis' => 'jumlah_selisih', 'penyebut' => 'dilayani', 'kurang' => 'kesalahan']),

            self::buat('IMU-LAB', ['IMP-UNIT'], 'Waktu Tunggu Hasil Darah Rutin ≤ 15 Menit', ['LB'], 'Pemeriksaan',
                'Jumlah pemeriksaan darah rutin dengan waktu tunggu hasil ≤ 15 menit',
                'Jumlah seluruh pemeriksaan darah rutin yang diobservasi',
                '>=', 100,
                'Dari jam sampel diterima laboratorium sampai jam hasil diverifikasi. Sampel ulang (lisis/beku) = eksklusi.',
                [...$pasien,
                    ['key' => 'jam_terima', 'label' => 'Jam sampel diterima', 'tipe' => 'jam'],
                    ['key' => 'jam_hasil', 'label' => 'Jam hasil diverifikasi', 'tipe' => 'jam'],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_terima', 'selesai' => 'jam_hasil', 'batas' => 15, 'op' => '<=']),

            self::buat('IMU-RM', ['IMP-UNIT'], 'Kelengkapan Pengisian Rekam Medis Rawat Inap di EMR', ['MR'], 'Rekam medis',
                'Jumlah rekam medis rawat inap terisi lengkap ≤ 24 jam setelah pasien pulang',
                'Jumlah seluruh rekam medis rawat inap pasien pulang yang diobservasi',
                '>=', 100,
                'Lengkap bila seluruh butir daftar tilik EMR terisi dalam 24 jam setelah pasien pulang.',
                [...$pasien,
                    ['key' => 'lengkap', 'label' => 'Lengkap ≤ 24 jam setelah pulang', 'tipe' => 'ceklis'],
                    ['key' => 'kurang', 'label' => 'Butir yang belum terisi', 'tipe' => 'teks', 'wajib' => false],
                ],
                ['jenis' => 'ceklis_semua', 'kolom' => ['lengkap']]),

            self::buat('IMU-SAN', ['IMP-UNIT'], 'Pengelolaan Limbah Padat Infeksius Sesuai Aturan', ['SN'], 'Proses',
                'Jumlah proses pengelolaan limbah padat infeksius yang sesuai SPO',
                'Jumlah seluruh proses pengelolaan limbah padat infeksius yang diobservasi',
                '>=', 100,
                'Satu baris = satu proses yang diamati (pemilahan, pewadahan, pengangkutan, TPS, penyerahan bermanifes).',
                [
                    ['key' => 'lokasi', 'label' => 'Lokasi / tahap', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'sesuai', 'label' => 'Seluruh tahap sesuai SPO', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'ceklis_semua', 'kolom' => ['sesuai']]),

            self::buat('IMU-AMB', ['IMP-UNIT'], 'Respon Time Pelayanan Ambulans < 15 Menit', ['SS'], 'Permintaan',
                'Jumlah permintaan ambulans yang dilayani < 15 menit',
                'Jumlah seluruh permintaan pelayanan ambulans',
                '>=', 100,
                'Dari jam permintaan diterima sampai jam ambulans berangkat. Permintaan terjadwal = eksklusi.',
                [
                    ['key' => 'tujuan', 'label' => 'Pasien / tujuan', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'jam_minta', 'label' => 'Jam permintaan', 'tipe' => 'jam'],
                    ['key' => 'jam_berangkat', 'label' => 'Jam berangkat', 'tipe' => 'jam'],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_minta', 'selesai' => 'jam_berangkat', 'batas' => 15, 'op' => '<']),

            self::buat('IMU-KAM', ['IMP-UNIT'], 'Tidak Adanya Kehilangan Barang Berharga Pasien Rawat Inap', ['SS'], 'Hari',
                'Jumlah pasien rawat inap dikurangi pasien yang kehilangan barang berharga',
                'Jumlah seluruh pasien rawat inap',
                '>=', 100,
                'Satu baris = satu hari (atau satu bulan): jumlah pasien rawat inap dari sensus dan jumlah laporan kehilangan.',
                [
                    ['key' => 'pasien_ranap', 'label' => 'Jumlah pasien rawat inap', 'tipe' => 'angka'],
                    ['key' => 'kehilangan', 'label' => 'Pasien melapor kehilangan', 'tipe' => 'angka'],
                ],
                ['jenis' => 'jumlah_selisih', 'penyebut' => 'pasien_ranap', 'kurang' => 'kehilangan']),

            self::buat('IMU-LDR', ['IMP-UNIT'], 'Tidak Adanya Kejadian Linen Hilang', ['LD'], 'Pengiriman',
                'Jumlah linen yang kembali ke laundry',
                'Jumlah linen yang dikirim laundry ke ruangan',
                '>=', 100,
                'Satu baris = satu serah terima ke satu ruangan. Linen diafkir dengan berita acara tidak dihitung hilang.',
                [
                    ['key' => 'ruangan', 'label' => 'Ruangan', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'dikirim', 'label' => 'Linen dikirim', 'tipe' => 'angka'],
                    ['key' => 'kembali', 'label' => 'Linen kembali', 'tipe' => 'angka'],
                ],
                ['jenis' => 'jumlah', 'pembilang' => 'kembali', 'penyebut' => 'dikirim']),

            self::buat('IMU-IPS', ['IMP-UNIT'], 'Kecepatan Waktu Menanggapi Kerusakan Alat ≤ 15 Menit', ['KP'], 'Laporan',
                'Jumlah laporan kerusakan alat yang ditanggapi ≤ 15 menit',
                'Jumlah seluruh laporan kerusakan alat',
                '>=', 80,
                'Dari jam laporan diterima IPSRS sampai jam petugas mulai menanggapi (bukan jam selesai perbaikan).',
                [
                    ['key' => 'alat', 'label' => 'Alat / unit pelapor', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'jam_lapor', 'label' => 'Jam laporan diterima', 'tipe' => 'jam'],
                    ['key' => 'jam_tanggap', 'label' => 'Jam mulai ditanggapi', 'tipe' => 'jam'],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_lapor', 'selesai' => 'jam_tanggap', 'batas' => 15, 'op' => '<=']),

            self::buat('IMU-GZ', ['IMP-UNIT'], 'Kepatuhan Membersihkan Alat Penyimpanan Bahan Makanan', ['GZ'], 'Petugas',
                'Jumlah petugas yang patuh membersihkan alat penyimpanan bahan makanan',
                'Jumlah seluruh petugas gizi yang diobservasi',
                '>=', 100,
                'Patuh bila pembersihan sesuai jadwal dan SPO, tercatat di ceklis saat itu juga (bukan di akhir bulan).',
                [
                    $petugas,
                    ['key' => 'alat', 'label' => 'Alat penyimpanan', 'tipe' => 'teks', 'wajib' => false],
                    ['key' => 'patuh', 'label' => 'Dibersihkan sesuai jadwal & SPO', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'ceklis_semua', 'kolom' => ['patuh']]),

            // ═══════════════ IMP-RS — SK 166/2026 (identifikasi & readmisi = INM-03 / IMU-RI) ═══════════════
            self::buat('IMP-ADHF', ['IMP-RS'], 'Kepatuhan terhadap Clinical Pathway ADHF', self::RAWAT_INAP, 'Kasus ADHF',
                'Jumlah kasus ADHF yang asuhannya patuh terhadap clinical pathway',
                'Jumlah seluruh kasus ADHF yang memakai clinical pathway',
                '>=', 80,
                'Satu kasus patuh bila ≥ 80% butir CP ADHF terlaksana. Yang dihitung jumlah KASUS patuh, bukan rata-rata persen butir.',
                [...$pasien,
                    ['key' => 'butir_total', 'label' => 'Jumlah butir CP ADHF', 'tipe' => 'angka'],
                    ['key' => 'butir_sesuai', 'label' => 'Butir terlaksana', 'tipe' => 'angka'],
                ],
                ['jenis' => 'jumlah_ambang', 'pembilang' => 'butir_sesuai', 'penyebut' => 'butir_total', 'ambang' => 0.8],
                kategoriRs: 'Pelayanan klinis prioritas'),

            self::buat('IMP-KONSUL', ['IMP-RS'], 'Waktu Tanggap DPJP Jantung terhadap Konsul Rawat Inap ≤ 15 Menit', self::RAWAT_INAP, 'Konsul',
                'Jumlah konsul rawat inap yang direspons DPJP jantung ≤ 15 menit',
                'Jumlah seluruh konsul rawat inap kepada DPJP jantung',
                '>=', 80,
                'Dari jam konsul dikirim sampai jawaban pertama DPJP (telepon/pesan yang dicatat di CPPT atau datang langsung). Target 80% masih usulan.',
                [...$pasien,
                    ['key' => 'jam_konsul', 'label' => 'Jam konsul', 'tipe' => 'jam'],
                    ['key' => 'jam_jawab', 'label' => 'Jam jawaban pertama', 'tipe' => 'jam'],
                    ['key' => 'cara', 'label' => 'Cara', 'tipe' => 'pilihan', 'wajib' => true, 'opsi' => ['TELP' => 'Telepon', 'PESAN' => 'Pesan', 'DATANG' => 'Datang langsung']],
                ],
                ['jenis' => 'durasi', 'mulai' => 'jam_konsul', 'selesai' => 'jam_jawab', 'batas' => 15, 'op' => '<='],
                kategoriRs: 'Sesuai tujuan strategis RS'),

            self::buat('IMP-MPP', ['IMP-RS'], 'Efektivitas MPP dalam Menengahi Permasalahan Medis', self::TINGKAT_RS, 'Kasus MPP',
                'Jumlah kasus yang terselesaikan oleh MPP',
                'Jumlah seluruh kasus yang dirujuk kepada MPP',
                '>=', 80,
                'Satu baris = satu kasus yang dirujuk ke MPP. Terselesaikan bila tuntas dan tercatat di formulir MPP sebelum pasien pulang. Target 80% masih usulan.',
                [...$pasien,
                    ['key' => 'masalah', 'label' => 'Permasalahan', 'tipe' => 'teks', 'wajib' => true],
                    ['key' => 'selesai', 'label' => 'Terselesaikan sebelum pulang', 'tipe' => 'ceklis'],
                ],
                ['jenis' => 'ceklis_semua', 'kolom' => ['selesai']],
                kategoriRs: 'Terkait perbaikan sistem'),
        ];

        return self::$definisi = array_column($daftar, null, 'kode');
    }

    private static function buat(string $kode, array $kelompok, string $judul, string|array $unit, string $pengamatan,
        string $pembilang, string $penyebut, string $op, float $nilai, string $petunjuk, array $kolom, array $aturan,
        int $pengali = 100, string $satuan = '%', ?string $kategoriRs = null): array
    {
        return compact('kode', 'kelompok', 'judul', 'unit', 'pengamatan', 'pembilang', 'penyebut', 'petunjuk', 'kolom',
            'aturan', 'pengali', 'satuan', 'kategoriRs') + ['target' => ['op' => $op, 'nilai' => $nilai]];
    }

    public static function cari(string $kode): ?array
    {
        return self::definisi()[$kode] ?? null;
    }

    /** Indikator terbalik: makin kecil makin baik (penundaan, readmisi, tidak IMD, drop out). */
    public static function terbalik(array $definisi): bool
    {
        return $definisi['target']['op'] === '<=';
    }

    /**
     * Indikator yang diisi oleh pilihan unit tsb.
     * $unitId = TINGKAT_RS → indikator tingkat RS (hanya role semua unit).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function untukUnit(?string $unitId): array
    {
        if ($unitId === null || $unitId === '') {
            return [];
        }

        return array_filter(self::definisi(), function ($definisi) use ($unitId) {
            if ($unitId === self::TINGKAT_RS) {
                return $definisi['unit'] === self::TINGKAT_RS;
            }
            if ($definisi['unit'] === self::SEMUA_UNIT) {
                return true;
            }

            return is_array($definisi['unit']) && in_array($unitId, $definisi['unit'], true);
        });
    }

    // ─── Hitung satu baris ────────────────────────────────────────────────────

    /**
     * Hitung pembilang/penyebut satu baris kertas kerja.
     *
     * @return array{pembilang: float, penyebut: float, baik: ?bool, label: string, catatan: string}
     *         baik null = baris berbentuk jumlah (bukan ya/tidak).
     */
    public static function hitungBaris(array $definisi, array $isian): array
    {
        $aturan = $definisi['aturan'];
        $terbalik = self::terbalik($definisi);

        // Baris ya/tidak: $memenuhi = baris masuk pembilang.
        $hasilYaTidak = function (bool $memenuhi, string $catatan = '') use ($terbalik): array {
            return [
                'pembilang' => $memenuhi ? 1.0 : 0.0,
                'penyebut'  => 1.0,
                'baik'      => $terbalik ? !$memenuhi : $memenuhi,
                'label'     => $terbalik ? ($memenuhi ? 'Terjadi' : 'Tidak terjadi') : ($memenuhi ? 'Sesuai' : 'Tidak sesuai'),
                'catatan'   => $catatan,
            ];
        };
        $angka = fn(string $key) => (float) ($isian[$key] ?? 0);

        switch ($aturan['jenis']) {
            case 'ceklis_semua':
                $semua = collect($aturan['kolom'])->every(fn($key) => ($isian[$key] ?? '0') === '1');

                return $hasilYaTidak($semua);

            case 'kejadian':
                return $hasilYaTidak(($isian[$aturan['kolom']] ?? '0') === '1');

            case 'durasi':
                $menit = self::selisihMenit($definisi, $aturan['mulai'], $aturan['selesai'], $isian);
                $memenuhi = match ($aturan['op']) {
                    '<'  => $menit < $aturan['batas'],
                    '>'  => $menit > $aturan['batas'],
                    default => $menit <= $aturan['batas'],
                };

                return $hasilYaTidak($memenuhi, Absensi::labelMenit($menit) ?: '0 mnt');

            case 'durasi_grading':
                $menit = self::selisihMenit($definisi, $aturan['mulai'], $aturan['selesai'], $isian);
                $batas = $aturan['batas'][$isian[$aturan['grading']] ?? ''] ?? 0;

                return $hasilYaTidak($menit <= $batas, Absensi::labelMenit($menit) ?: '0 mnt');

            case 'jam_rentang':
                $jam = (string) ($isian[$aturan['kolom']] ?? '');

                return $hasilYaTidak($jam >= $aturan['dari'] && $jam <= $aturan['sampai'], $jam);

            case 'jumlah':
                $pembilang = $angka($aturan['pembilang']);
                $penyebut = $angka($aturan['penyebut']);

                return ['pembilang' => $pembilang, 'penyebut' => $penyebut, 'baik' => null,
                    'label' => self::angka($pembilang, 0) . ' / ' . self::angka($penyebut, 0), 'catatan' => ''];

            case 'jumlah_selisih':
                $penyebut = $angka($aturan['penyebut']);
                $pembilang = max(0, $penyebut - $angka($aturan['kurang']));

                return ['pembilang' => $pembilang, 'penyebut' => $penyebut, 'baik' => $pembilang >= $penyebut,
                    'label' => self::angka($pembilang, 0) . ' / ' . self::angka($penyebut, 0), 'catatan' => ''];

            case 'jumlah_ambang':
                $penyebut = $angka($aturan['penyebut']);
                $rasio = $penyebut > 0 ? $angka($aturan['pembilang']) / $penyebut : 0;

                return $hasilYaTidak($rasio >= $aturan['ambang'], self::angka($rasio * 100) . '% butir');

            case 'survei':
                $nilai = collect($aturan['kolom'])->sum(fn($key) => (int) ($isian[$key] ?? 0));
                $jumlahUnsur = count($aturan['kolom']);

                return ['pembilang' => (float) $nilai, 'penyebut' => (float) $jumlahUnsur, 'baik' => null,
                    'label' => 'Rata-rata ' . self::angka($nilai / $jumlahUnsur), 'catatan' => ''];
        }

        throw new \InvalidArgumentException("Aturan indikator tidak dikenal: {$aturan['jenis']}");
    }

    /** Selisih menit antar dua kolom jam (lewat tengah malam = hari berikutnya) atau waktu (dd/mm/yyyy hh:mi). */
    private static function selisihMenit(array $definisi, string $kolomMulai, string $kolomSelesai, array $isian): int
    {
        $tipe = collect($definisi['kolom'])->firstWhere('key', $kolomMulai)['tipe'] ?? 'jam';
        $mulai = (string) ($isian[$kolomMulai] ?? '');
        $selesai = (string) ($isian[$kolomSelesai] ?? '');

        if ($tipe === 'waktu') {
            $dari = Carbon::createFromFormat('!' . self::FORMAT_WAKTU, $mulai);
            $sampai = Carbon::createFromFormat('!' . self::FORMAT_WAKTU, $selesai);

            return (int) floor(($sampai->getTimestamp() - $dari->getTimestamp()) / 60);
        }

        $menitMulai = self::menitDariJam($mulai);
        $menitSelesai = self::menitDariJam($selesai);

        return $menitSelesai >= $menitMulai ? $menitSelesai - $menitMulai : $menitSelesai + 1440 - $menitMulai;
    }

    public const FORMAT_WAKTU = 'd/m/Y H:i';

    private static function menitDariJam(string $jam): int
    {
        [$jamKe, $menit] = array_map('intval', explode(':', $jam) + [0, 0]);

        return $jamKe * 60 + $menit;
    }

    // ─── Capaian ──────────────────────────────────────────────────────────────

    /** Capaian = pembilang ÷ penyebut × pengali; null bila penyebut 0 ("tidak ada kasus"). */
    public static function capaian(float $pembilang, float $penyebut, int $pengali = 100): ?float
    {
        return $penyebut > 0 ? round($pembilang / $penyebut * $pengali, 2) : null;
    }

    public static function tercapai(?float $nilai, array $definisi): ?bool
    {
        if ($nilai === null) {
            return null;
        }
        $batas = (float) $definisi['target']['nilai'];

        return self::terbalik($definisi) ? $nilai <= $batas + 1e-9 : $nilai >= $batas - 1e-9;
    }

    public static function labelTarget(array $definisi): string
    {
        $nilai = $definisi['target']['nilai'];
        $teks = self::angka((float) $nilai, floor($nilai) == $nilai ? 0 : 2) . $definisi['satuan'];

        return ($nilai == 100 && !self::terbalik($definisi)) || $nilai == 0 ? $teks : ($definisi['target']['op'] === '<=' ? '≤ ' : '≥ ') . $teks;
    }

    public static function labelCapaian(?float $nilai, array $definisi): string
    {
        return $nilai === null ? '—' : self::angka($nilai) . $definisi['satuan'];
    }

    public static function angka(float $nilai, int $desimal = 2): string
    {
        return number_format($nilai, $desimal, ',', '.');
    }

    // ─── Rekap ────────────────────────────────────────────────────────────────

    /**
     * Rekap per indikator per bulan satu tahun.
     * $unitFilter: null = seluruh RS (semua unit + tingkat RS), TINGKAT_RS = hanya baris tingkat RS, kode = unit tsb.
     *
     * @return array<string, array<int, array{pembilang: float, penyebut: float, jumlah: int, eksklusi: int}>>
     */
    public static function rekapTahun(int $tahun, ?string $unitFilter = null): array
    {
        $rows = DB::table('siaptxn_indikator_obs')
            ->where('obs_tanggal', '>=', Absensi::tanggalSql(Carbon::create($tahun, 1, 1)))
            ->where('obs_tanggal', '<', Absensi::tanggalSql(Carbon::create($tahun + 1, 1, 1)))
            ->when($unitFilter === self::TINGKAT_RS, fn($query) => $query->whereNull('unit_id'))
            ->when($unitFilter !== null && $unitFilter !== self::TINGKAT_RS, fn($query) => $query->where('unit_id', $unitFilter))
            ->groupBy('ind_kode', DB::raw("TO_CHAR(obs_tanggal, 'MM')"))
            ->select('ind_kode', DB::raw("TO_CHAR(obs_tanggal, 'MM') AS bulan"),
                DB::raw("SUM(CASE WHEN obs_eksklusi = '0' THEN obs_pembilang ELSE 0 END) AS pembilang"),
                DB::raw("SUM(CASE WHEN obs_eksklusi = '0' THEN obs_penyebut ELSE 0 END) AS penyebut"),
                DB::raw("SUM(CASE WHEN obs_eksklusi = '0' THEN 1 ELSE 0 END) AS jumlah"),
                DB::raw("SUM(CASE WHEN obs_eksklusi = '1' THEN 1 ELSE 0 END) AS eksklusi"))
            ->get();

        $rekap = [];
        foreach ($rows as $row) {
            $rekap[$row->ind_kode][(int) $row->bulan] = [
                'pembilang' => (float) $row->pembilang,
                'penyebut'  => (float) $row->penyebut,
                'jumlah'    => (int) $row->jumlah,
                'eksklusi'  => (int) $row->eksklusi,
            ];
        }

        return $rekap;
    }

    /** Jumlahkan beberapa bulan (triwulan / tahun) — jumlah pembilang ÷ jumlah penyebut. */
    public static function gabung(array $perBulan, array $bulanList): array
    {
        $total = ['pembilang' => 0.0, 'penyebut' => 0.0, 'jumlah' => 0, 'eksklusi' => 0];
        foreach ($bulanList as $bulan) {
            foreach ($total as $key => $nilai) {
                $total[$key] = $nilai + ($perBulan[$bulan][$key] ?? 0);
            }
        }

        return $total;
    }

    // ─── Kunci & akses ────────────────────────────────────────────────────────

    public static function periode(Carbon $awalBulan): string
    {
        return $awalBulan->format('Ym');
    }

    public static function terkunci(Carbon $awalBulan): bool
    {
        return DB::table('siaptxn_indikator_kuncis')->where('periode', self::periode($awalBulan))->exists();
    }

    /** ['semuaUnit' => bool, 'unitId' => ?string] — admin & Komite Mutu melihat semua unit. */
    public static function cakupanUser(?object $user): array
    {
        if (!$user) {
            return ['semuaUnit' => false, 'unitId' => null];
        }

        $roles = $user->getRoleNames()->map(fn($role) => trim(strtolower($role)))->all();
        if (array_intersect(self::ROLE_SEMUA_UNIT, $roles) !== []) {
            return ['semuaUnit' => true, 'unitId' => null];
        }

        return ['semuaUnit' => false, 'unitId' => UnitKerja::cakupanUser($user)['unitId']];
    }
}
