<?php

namespace Tests\Unit;

use App\Support\Ews\EwsDefault;
use App\Support\Ews\EwsSkor;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Uji mesin skor terhadap isi bawaan master — angka acuan diambil dari contoh
 * kasus materi pelatihan (EWS RSI Madinah 2026) dan formulir RSUD dr. Iskak.
 * Tidak butuh Oracle: master dibaca dari EwsDefault langsung.
 */
class EwsSkorTest extends TestCase
{
    private array $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = ['params' => EwsDefault::params(), 'respons' => EwsDefault::respons()];
    }

    // ── Kasus #1 materi: laki-laki 47 th, alert, TD 130/80, nadi 100, RR 26, SpO2 98 room air → skor 3 (RR 3 versi NEWS2) ──
    public function test_dewasa_kasus_ringan_dengan_satu_parameter_merah(): void
    {
        $hasil = EwsSkor::hitung('DEWASA', [
            'frekuensiNafas' => 26, 'spo2' => 98, 'oksigen' => 'ROOM_AIR', 'sistolik' => 130,
            'frekuensiNadi' => 100, 'kesadaran' => 'A', 'suhu' => 36.5,
        ], $this->master);

        $this->assertTrue($hasil['tersedia']);
        $this->assertTrue($hasil['lengkap']);
        $this->assertSame(3, $hasil['per']['frekuensiNafas']['skor']);
        $this->assertSame(1, $hasil['per']['frekuensiNadi']['skor']);
        $this->assertSame(4, $hasil['total']);
        $this->assertTrue($hasil['adaMerah']);
        // total 1-4 TAPI ada parameter merah → aturan kode merah (kuning, tiap 1 jam) menang
        $this->assertSame('KUNING', $hasil['warna']);
        $this->assertSame(60, $hasil['frekuensiMenit']);
    }

    // ── Kasus #3 materi: 77 th PPOK, verbal, TD 160/90, nadi 120, RR 40, SpO2 88 room air ──
    public function test_dewasa_kasus_berat_spo2_skala_2(): void
    {
        $hasil = EwsSkor::hitung('DEWASA', [
            'frekuensiNafas' => 40, 'spo2' => 88, 'spo2Skala2' => 88, 'oksigen' => 'ROOM_AIR',
            'sistolik' => 160, 'frekuensiNadi' => 120, 'kesadaran' => 'V', 'suhu' => 37,
        ], $this->master);

        // spo2Skala2 terisi → spo2 skala 1 dilewati, 88 pada skala 2 = 0
        $this->assertArrayNotHasKey('spo2', $hasil['per']);
        $this->assertSame(0, $hasil['per']['spo2Skala2']['skor']);
        $this->assertSame(3 + 0 + 0 + 0 + 2 + 3 + 0, $hasil['total']);
        $this->assertSame('MERAH', $hasil['warna']);
        $this->assertSame('Tinggi', $hasil['kategori']);
    }

    public function test_dewasa_spo2_skala_2_dengan_oksigen_dinilai_terbalik(): void
    {
        $nilai = [
            'frekuensiNafas' => 18, 'spo2' => 97, 'spo2Skala2' => 97, 'oksigen' => 'O2',
            'sistolik' => 120, 'frekuensiNadi' => 80, 'kesadaran' => 'A', 'suhu' => 36.8,
        ];
        $hasil = EwsSkor::hitung('DEWASA', $nilai, $this->master);
        $this->assertSame(3, $hasil['per']['spo2Skala2']['skor'], '≥97 on O2 = 3 (hiperoksia pada gagal nafas tipe 2)');

        $nilai['oksigen'] = 'ROOM_AIR';
        $hasil = EwsSkor::hitung('DEWASA', $nilai, $this->master);
        $this->assertSame(0, $hasil['per']['spo2Skala2']['skor'], '≥93 room air = 0');
    }

    public function test_dewasa_skor_nol_dan_batas_rentang(): void
    {
        $hasil = EwsSkor::hitung('DEWASA', [
            'frekuensiNafas' => 12, 'spo2' => 96, 'oksigen' => 'ROOM_AIR', 'sistolik' => 111,
            'frekuensiNadi' => 51, 'kesadaran' => 'A', 'suhu' => 36.1,
        ], $this->master);

        $this->assertSame(0, $hasil['total']);
        $this->assertSame('PUTIH', $hasil['warna']);
        $this->assertSame(720, $hasil['frekuensiMenit']);

        // Suhu 36.04 → dibulatkan 36.0 → skor 1 (bukan jatuh ke celah 36.0-36.1)
        $hasil = EwsSkor::hitung('DEWASA', ['suhu' => 36.04], $this->master);
        $this->assertSame(1, $hasil['per']['suhu']['skor']);
    }

    public function test_parameter_wajib_kosong_membuat_tidak_lengkap_tapi_tetap_menjumlah(): void
    {
        $hasil = EwsSkor::hitung('DEWASA', [
            'frekuensiNafas' => 25, 'spo2' => 90, 'sistolik' => 120, 'frekuensiNadi' => 80, 'suhu' => 37,
        ], $this->master);

        $this->assertFalse($hasil['lengkap']);
        $this->assertContains('Penggunaan oksigen', $hasil['kurang']);
        $this->assertContains('Tingkat kesadaran (ACVPU)', $hasil['kurang']);
        $this->assertSame(6, $hasil['total']);
        $this->assertSame('ORANYE', $hasil['warna'], 'respon 5-6 tetap dipilih walau belum lengkap');
    }

    // ── Kasus #2 materi: anak 5 th kejang demam — gelisah (2), takipnea+otot bantu (2), nadi 140 (2) → 6 ──
    public function test_anak_pews_dan_acuan_usia(): void
    {
        $hasil = EwsSkor::hitung('ANAK', [
            'keadaanUmum' => 'IRITABEL', 'kardiovaskular' => 'SIANOTIK', 'respirasi' => 'TAKIPNEA_20',
            'frekuensiNadi' => 140, 'frekuensiNafas' => 38,
        ], $this->master, umurBulan: 60);

        $this->assertSame(6, $hasil['total']);
        $this->assertSame('MERAH', $hasil['warna']);
        $this->assertArrayNotHasKey('nadiNormal', $hasil['per'], 'REFERENSI tidak ikut diskor');

        $acuanNadi = EwsSkor::acuanUsia($this->master, 'ANAK', 'nadiNormal', 60);
        $this->assertSame(70.0, (float) $acuanNadi['batas_bawah']);
        $this->assertSame(110.0, (float) $acuanNadi['batas_atas']);
        $this->assertNull(EwsSkor::acuanUsia($this->master, 'ANAK', 'nadiNormal', null));
    }

    public function test_neonatus(): void
    {
        $hasil = EwsSkor::hitung('NEONATUS', [
            'keadaanUmum' => 'MENANGIS_LEMAH', 'kardiovaskular' => 'PUCAT', 'respirasi' => 'TAKIPNEA_70',
        ], $this->master);

        $this->assertSame(3, $hasil['total']);
        $this->assertSame('KUNING', $hasil['warna']);
        $this->assertSame(120, $hasil['frekuensiMenit']);
    }

    // ── Kasus #4 materi: 19 th P1001 gemelli, verbal, TD 95/60, nadi 140 lemah, RR 28, suhu 38.1, SpO2 98, perdarahan ──
    public function test_meows_kasus_perdarahan(): void
    {
        $hasil = EwsSkor::hitung('MEOWS', [
            'frekuensiNafas' => 28, 'spo2' => 98, 'oksigen' => 'ROOM_AIR', 'suhu' => 38.1,
            'sistolik' => 95, 'distolik' => 60, 'frekuensiNadi' => 140, 'kesadaran' => 'V',
            'nyeri' => 5, 'perdarahan' => 'YA',
        ], $this->master);

        $this->assertTrue($hasil['lengkap'], 'lochea/urine/protein/DJJ opsional');
        $this->assertSame(3 + 0 + 0 + 2 + 0 + 0 + 3 + 3 + 2 + 3, $hasil['total']);
        $this->assertSame('MERAH', $hasil['warna']);
        $this->assertSame(15, $hasil['frekuensiMenit']);
    }

    public function test_meows_satu_parameter_merah_menaikkan_ke_oranye(): void
    {
        $hasil = EwsSkor::hitung('MEOWS', [
            'frekuensiNafas' => 16, 'spo2' => 98, 'oksigen' => 'ROOM_AIR', 'suhu' => 36.8,
            'sistolik' => 120, 'distolik' => 80, 'frekuensiNadi' => 80, 'kesadaran' => 'A',
            'nyeri' => 2, 'perdarahan' => 'YA',
        ], $this->master);

        $this->assertSame(3, $hasil['total']);
        $this->assertSame('ORANYE', $hasil['warna'], 'total 3 tapi perdarahan = merah → baris 5-6/merah');
    }

    public function test_varian_dari_umur(): void
    {
        $this->assertSame('NEONATUS', EwsSkor::varianUntukUmur(10, 0));
        $this->assertSame('NEONATUS', EwsSkor::varianUntukUmur(28, 0));
        $this->assertSame('ANAK', EwsSkor::varianUntukUmur(29, 0));
        $this->assertSame('ANAK', EwsSkor::varianUntukUmur(5000, 13));
        $this->assertSame('DEWASA', EwsSkor::varianUntukUmur(6000, 16));
        $this->assertNull(EwsSkor::varianUntukUmur(null, null));
    }

    public function test_umur_dari_birth_date(): void
    {
        $acuan = Carbon::create(2026, 9, 6);
        $umur = EwsSkor::umurDari('2026-08-20 00:00:00', $acuan);
        $this->assertSame(['hari' => 17, 'bulan' => 0, 'tahun' => 0], $umur);

        $umur = EwsSkor::umurDari('2021-03-06', $acuan);
        $this->assertSame(5, $umur['tahun']);
        $this->assertSame(66, $umur['bulan']);

        $this->assertSame(['hari' => null, 'bulan' => null, 'tahun' => null], EwsSkor::umurDari(null));
        $this->assertSame(['hari' => null, 'bulan' => null, 'tahun' => null], EwsSkor::umurDari('bukan tanggal'));
    }

    public function test_skor_terakhir_untuk_badge_display_pasien(): void
    {
        $entri = fn(string $waktu, int $total, ?string $pantau) => [
            'waktuPemeriksaan' => $waktu,
            'ews' => ['tersedia' => true, 'total' => $total, 'kategori' => 'Uji', 'warna' => 'MERAH', 'frekuensi' => 'Minimal tiap 1 jam', 'pantauUlang' => $pantau, 'varian' => 'DEWASA', 'lengkap' => true],
        ];
        $daftar = [
            $entri('06/09/2026 10:00:00', 2, '06/09/2026 16:00'),
            ['waktuPemeriksaan' => '06/09/2026 12:00:00'],                       // legacy tanpa ews → dilewati
            $entri('06/09/2026 11:00:00', 7, '06/09/2026 12:00'),                // terbaru walau di tengah array
            ['waktuPemeriksaan' => '06/09/2026 13:00:00', 'ews' => null],        // master tak tersedia → dilewati
        ];

        $hasil = EwsSkor::terakhirDari($daftar, Carbon::create(2026, 9, 6, 12, 30));
        $this->assertSame(7, $hasil['total']);
        $this->assertSame('06/09/2026 11:00:00', $hasil['waktu']);
        $this->assertTrue($hasil['terlambat'], 'pantau ulang 12:00 sudah lewat pada 12:30');

        $hasil = EwsSkor::terakhirDari($daftar, Carbon::create(2026, 9, 6, 11, 30));
        $this->assertFalse($hasil['terlambat']);

        $this->assertNull(EwsSkor::terakhirDari([['waktuPemeriksaan' => '06/09/2026 12:00:00']]));
        $this->assertNull(EwsSkor::terakhirDari([]));
    }

    public function test_master_kosong_menandai_tidak_tersedia(): void
    {
        $hasil = EwsSkor::hitung('DEWASA', ['frekuensiNafas' => 20], ['params' => [], 'respons' => []]);
        $this->assertFalse($hasil['tersedia']);
        $this->assertFalse($hasil['lengkap']);
        $this->assertNull($hasil['warna']);
    }

    public function test_isi_bawaan_konsisten(): void
    {
        $params = EwsDefault::params();
        $kunci = [];
        foreach ($params as $param) {
            $k = $param['varian'] . '/' . $param['param_kode'];
            $this->assertArrayNotHasKey($k, $kunci, "parameter ganda: {$k}");
            $kunci[$k] = true;
            $this->assertNotEmpty($param['rentang'], "{$k} tanpa rentang");
            if ($param['tipe'] === 'PILIHAN') {
                foreach ($param['rentang'] as $r) {
                    $this->assertNotEmpty($r['pilihan_kode'], "{$k} pilihan tanpa kode");
                }
            }
            if (!empty($param['gantikan_kode'])) {
                $this->assertArrayHasKey($param['varian'] . '/' . $param['gantikan_kode'], $kunci, "{$k} menggantikan kode yang tak ada");
            }
        }
        foreach (EwsDefault::respons() as $respon) {
            $this->assertContains($respon['warna'], ['PUTIH', 'HIJAU', 'KUNING', 'ORANYE', 'MERAH']);
            $this->assertTrue($respon['skor_min'] !== null || $respon['skor_max'] !== null || $respon['param_merah'] === '1', 'respon tanpa rentang & tanpa flag merah tak pernah cocok');
        }
    }
}
