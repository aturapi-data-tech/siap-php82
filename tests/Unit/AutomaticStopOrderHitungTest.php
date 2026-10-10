<?php

namespace Tests\Unit;

use App\Support\AutomaticStopOrder\AutomaticStopOrderHitung;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Uji mesin Automatic Stop Order tanpa Oracle: master dirakit langsung dalam
 * bentuk keluaran AutomaticStopOrderMaster::muat().
 */
class AutomaticStopOrderHitungTest extends TestCase
{
    private array $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = [
            'tersedia' => true,
            'golongan' => [
                1 => ['golongan_id' => 1, 'golongan_kode' => 'KETOROLAK', 'golongan_nama' => 'Ketorolak', 'batas_hari' => 5, 'batas_minimal_hari' => null, 'keterangan' => 'IV maks 120 mg/hari', 'urutan' => 1, 'active_status' => '1'],
                2 => ['golongan_id' => 2, 'golongan_kode' => 'ANTIINFEKSI_SISTEMIK', 'golongan_nama' => 'Antiinfeksi', 'batas_hari' => 7, 'batas_minimal_hari' => 3, 'keterangan' => '', 'urutan' => 2, 'active_status' => '1'],
                3 => ['golongan_id' => 3, 'golongan_kode' => 'NONAKTIF', 'golongan_nama' => 'Nonaktif', 'batas_hari' => 2, 'batas_minimal_hari' => null, 'keterangan' => '', 'urutan' => 3, 'active_status' => '0'],
            ],
            'produk' => [
                'KET01' => ['golongan_id' => 1, 'catatan' => '', 'active_status' => '1'],
                'CEF01' => ['golongan_id' => 2, 'catatan' => 'sesuai kultur', 'active_status' => '1'],
                'NON01' => ['golongan_id' => 3, 'catatan' => '', 'active_status' => '1'],
            ],
        ];
    }

    private function resep(string $tanggal, array $obat, bool $ttd = true, ?string $slsNo = null, int $no = 1): array
    {
        return [
            'resepNo' => $no,
            'resepDate' => $tanggal . ' 08:00:00',
            'slsNo' => $slsNo,
            'tandaTanganDokter' => ['dokterPeresep' => $ttd ? 'dr. Uji' : ''],
            'eresep' => array_map(fn(string $id) => ['productId' => $id, 'productName' => 'Obat ' . $id, 'signaX' => 3, 'signaHari' => 1], $obat),
        ];
    }

    public function test_hari_berjalan_dari_resep_pertama_bukan_resep_terbaru(): void
    {
        $hasil = AutomaticStopOrderHitung::nilai([
            $this->resep('01/09/2026', ['KET01'], no: 1),
            $this->resep('02/09/2026', ['KET01'], no: 2),
            $this->resep('03/09/2026', ['KET01'], no: 3),
        ], $this->master, Carbon::create(2026, 9, 3, 10, 0)); // 50 jam setelah 01/09 08:00 -> hari ke-3

        $this->assertCount(1, $hasil['baris']);
        $ket = $hasil['baris'][0];
        $this->assertSame('01/09/2026 08:00', $ket['tglMulai']);
        $this->assertSame(50, $ket['jamBerjalan']);
        $this->assertSame(3, $ket['hariBerjalan']);
        $this->assertSame(3, $ket['sisaHari']);
        $this->assertSame(AutomaticStopOrderHitung::STATUS_AMAN, $ket['status']);
        $this->assertSame([1, 2, 3], $ket['resepNoList']);
        $this->assertSame('06/09/2026 08:00', $ket['tglBatasStop']); // 5 x 24 jam sejak 01/09 08:00
        $this->assertNull($ket['tglBatasMinimal']);
    }

    public function test_status_mendekati_lalu_lewat_batas(): void
    {
        $resep = [$this->resep('01/09/2026', ['KET01'])];

        // 05/09 09:00 = 97 jam -> hari ke-5 (24 jam terakhir dari batas 5 hari) -> MENDEKATI
        $h5 = AutomaticStopOrderHitung::nilai($resep, $this->master, Carbon::create(2026, 9, 5, 9, 0));
        $this->assertSame(AutomaticStopOrderHitung::STATUS_MENDEKATI, $h5['baris'][0]['status']);
        $this->assertSame(1, $h5['baris'][0]['sisaHari']);

        // 06/09 07:59 masih < 120 jam -> tetap hari ke-5; 06/09 08:00 = 120 jam -> hari ke-6 -> LEWAT
        $sebelum = AutomaticStopOrderHitung::nilai($resep, $this->master, Carbon::create(2026, 9, 6, 7, 59));
        $this->assertSame(AutomaticStopOrderHitung::STATUS_MENDEKATI, $sebelum['baris'][0]['status']);
        $h6 = AutomaticStopOrderHitung::nilai($resep, $this->master, Carbon::create(2026, 9, 6, 8, 0));
        $this->assertSame(AutomaticStopOrderHitung::STATUS_LEWAT, $h6['baris'][0]['status']);
        $this->assertSame(0, $h6['baris'][0]['sisaHari']);
        $this->assertSame(1, $h6['ringkasan'][AutomaticStopOrderHitung::STATUS_LEWAT]);
    }

    public function test_jeda_lebih_dari_batas_memutus_rantai(): void
    {
        $hasil = AutomaticStopOrderHitung::nilai([
            $this->resep('01/09/2026', ['KET01']),
            $this->resep('02/09/2026', ['KET01']),
            $this->resep('06/09/2026', ['KET01']), // jeda 96 jam > JEDA_MAKSIMAL_HARI x 24
        ], $this->master, Carbon::create(2026, 9, 7, 9, 0));

        $this->assertSame('06/09/2026 08:00', $hasil['baris'][0]['tglMulai']);
        $this->assertSame(2, $hasil['baris'][0]['hariBerjalan']);
    }

    public function test_resep_draft_tanpa_ttd_dan_tanpa_slsno_diabaikan(): void
    {
        $hasil = AutomaticStopOrderHitung::nilai([
            $this->resep('01/09/2026', ['KET01'], ttd: false),
            $this->resep('03/09/2026', ['KET01'], ttd: false, slsNo: 'SLS1'),
        ], $this->master, Carbon::create(2026, 9, 3, 12, 0));

        $this->assertSame('03/09/2026 08:00', $hasil['baris'][0]['tglMulai']);
    }

    public function test_obat_tak_dipetakan_dan_golongan_nonaktif_masuk_daftar_terpisah(): void
    {
        $hasil = AutomaticStopOrderHitung::nilai([
            $this->resep('01/09/2026', ['KET01', 'LAIN01', 'NON01']),
        ], $this->master, Carbon::create(2026, 9, 1));

        $this->assertCount(1, $hasil['baris']);
        $this->assertSame(['LAIN01', 'NON01'], array_column($hasil['tidakDipetakan'], 'productId'));
    }

    public function test_batas_minimal_ditandai_belum_tercapai(): void
    {
        $hasil = AutomaticStopOrderHitung::nilai([$this->resep('01/09/2026', ['CEF01'])], $this->master, Carbon::create(2026, 9, 2, 12, 0));
        $cef = $hasil['baris'][0];
        $this->assertTrue($cef['belumMinimal']);
        $this->assertSame('sesuai kultur', $cef['catatanProduk']);
        $this->assertSame('04/09/2026 08:00', $cef['tglBatasMinimal']); // 3 x 24 jam
        $this->assertSame('08/09/2026 08:00', $cef['tglBatasStop']);    // 7 x 24 jam

        $hasil = AutomaticStopOrderHitung::nilai([$this->resep('01/09/2026', ['CEF01'])], $this->master, Carbon::create(2026, 9, 3, 8, 0)); // 48 jam -> hari ke-3
        $this->assertFalse($hasil['baris'][0]['belumMinimal']);
    }

    public function test_urutan_paling_mendesak_di_atas(): void
    {
        $hasil = AutomaticStopOrderHitung::nilai([
            $this->resep('01/09/2026', ['KET01', 'CEF01']),
        ], $this->master, Carbon::create(2026, 9, 6, 9, 0)); // KET01 lewat (hari ke-6/5), CEF01 aman (6/7)

        $this->assertSame(['KET01', 'CEF01'], array_column($hasil['baris'], 'productId'));
    }

    public function test_tanggal_resep_toleran_format(): void
    {
        $this->assertSame('01/09/2026 10:11', AutomaticStopOrderHitung::tanggalResep('01/09/2026 10:11:12')->format('d/m/Y H:i'));
        $this->assertSame('01/09/2026 00:00', AutomaticStopOrderHitung::tanggalResep('01/09/2026')->format('d/m/Y H:i'));
        $this->assertNull(AutomaticStopOrderHitung::tanggalResep(''));
        $this->assertNull(AutomaticStopOrderHitung::tanggalResep('2026-09-01'));
    }
}
