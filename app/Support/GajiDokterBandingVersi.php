<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * BANDINGKAN VERSI Gaji Dokter — dua snapshot satu dokter satu bulan jasa dibandingkan sampai tingkat baris lampiran pasien
 * (pola SIKEU SnapshotJurnal::bandingkan): baru (ada sekarang, dulu tidak), hilang (dulu ada, sekarang tidak),
 * berubah (nominal / pasien / tanggal / klaim / masuk slip / …).
 *
 * Dipakai tab Bandingkan Versi untuk dokter yang berbeda di antara dua VERSI PERIODE (GajiDokterVersiPeriode::bandingkan):
 * snapshot dokter di versi A vs di versi B.
 * Identitas baris = komponen + txn_no (cadangan bila txn_no kosong: reg_no + tanggal + nama + keterangan), TANPA nominal →
 * nominal beda = "berubah", bukan hilang + baru. Baris ber-identitas sama dalam satu versi digabung (nominal & pasien dijumlah).
 * Lampiran snapshot tidak pernah disunting → hasil banding lampiran di-cache file per pasangan versi.
 * Angka slip & komponen tetap dari GajiDokterSirus::banding() (dihitung langsung, ringan).
 */
class GajiDokterBandingVersi
{
    /** Batas baris selisih yang ditampilkan sekali banding (sisanya disebut jumlahnya saja). */
    public const BATAS_BARIS_SELISIH = 3000;

    /** Pengaturan slip yang ikut dibandingkan (bukan nominal hasil). */
    public const KOLOM_PENGATURAN = [
        'skema_gaji_pokok' => 'Skema gaji pokok', 'nilai_gaji_pokok' => 'Gaji pokok', 'potongan_rs_basis' => 'Basis potongan RS',
        'potongan_rs_persen' => 'Potongan RS (%)', 'pph21_persen' => 'PPh 21 (%)', 'npwp_status' => 'NPWP', 'gaji_status' => 'Status slip SIRUS',
        'sumber' => 'Sumber hitungan',
    ];

    /** Atribut baris lampiran yang dibandingkan → label perubahan. */
    public const KOLOM_LAMPIRAN = [
        'nominal' => 'nominal', 'pasien' => 'jumlah pasien', 'jumlah_baris' => 'jumlah baris', 'tgl' => 'tanggal', 'reg_no' => 'no. registrasi',
        'nama' => 'nama pasien', 'keterangan' => 'keterangan', 'klaim' => 'klaim', 'klaim_status' => 'status klaim', 'masuk_slip' => 'masuk slip',
    ];

    /**
     * Bandingkan dua snapshot (gd_id) satu dokter.
     * @return array{lama: object, baru: object, identik: bool, pengaturan: array, angka: array, detail: array, lampiran: array}|null
     */
    public static function bandingkan(int $gdLama, int $gdBaru): ?array
    {
        $headerLama = DB::table('siaptxn_gdok_hdrs')->where('gd_id', $gdLama)->first();
        $headerBaru = DB::table('siaptxn_gdok_hdrs')->where('gd_id', $gdBaru)->first();
        if (!$headerLama || !$headerBaru || $headerLama->dr_id !== $headerBaru->dr_id || $gdLama === $gdBaru) {
            return null;
        }

        $pengaturan = [];
        foreach (self::KOLOM_PENGATURAN as $kolom => $label) {
            $nilaiLama = $headerLama->$kolom ?? null;
            $nilaiBaru = $headerBaru->$kolom ?? null;
            $beda = is_numeric($nilaiLama) && is_numeric($nilaiBaru) ? abs((float) $nilaiLama - (float) $nilaiBaru) >= 0.005 : (string) $nilaiLama !== (string) $nilaiBaru;
            if ($beda) {
                $pengaturan[] = ['kolom' => $kolom, 'label' => $label, 'lama' => $nilaiLama, 'baru' => $nilaiBaru];
            }
        }

        $bandingSlip = GajiDokterSirus::banding($headerLama, $headerBaru);
        $bandingSlip['detail'] = self::asalSelisihKomponen($bandingSlip['detail'], $gdLama, $gdBaru);
        $lampiran = $headerLama->checksum === $headerBaru->checksum
            ? self::lampiranKosong($headerLama, $headerBaru)
            : Cache::store('file')->remember("siap.gdok.banding.{$gdLama}.{$gdBaru}", now()->addDay(), fn() => self::bandingkanLampiran($gdLama, $gdBaru));

        return [
            'lama' => $headerLama, 'baru' => $headerBaru,
            'identik' => $pengaturan === [] && $bandingSlip['header'] === [] && $bandingSlip['detail'] === [] && $lampiran['baris'] === [],
            'pengaturan' => $pengaturan, 'angka' => $bandingSlip['header'], 'detail' => $bandingSlip['detail'], 'lampiran' => $lampiran,
        ];
    }

    /** Σ nominal & jumlah baris lampiran per komponen satu snapshot. @return array<string, array{nominal: float, baris: int}> */
    private static function transaksiPerKomponen(int $gdId): array
    {
        return DB::table('siaptxn_gdok_lamps')->where('gd_id', $gdId)->selectRaw('komponen, SUM(nominal) AS nominal, COUNT(*) AS baris')->groupBy('komponen')->get()
            ->mapWithKeys(fn($row) => [(string) $row->komponen => ['nominal' => (float) $row->nominal, 'baris' => (int) $row->baris]])->all();
    }

    /**
     * Beri tiap komponen yang berbeda keterangan ASAL selisihnya, supaya terbaca tanpa menyimpulkan sendiri:
     *   transaksi — Σ transaksi komponen itu berbeda antar versi (lihat tabel transaksi);
     *   hitung    — transaksinya sama persis di kedua versi, jadi selisih berasal dari cara hitung slip (mis. arsip SIRUS
     *               tidak menghitung transaksi yang sudah ada di lampirannya, atau struktur/kapita berbeda);
     *   struktur  — komponen tanpa transaksi: kapita, tunjangan rutin, potongan RS/PPh (ikut total & persen), potongan/tambahan.
     * Kode komponen jasa = kode komponen transaksi (desc_doc), lihat GajiDokterHitung::hitung.
     */
    private static function asalSelisihKomponen(array $detail, int $gdLama, int $gdBaru): array
    {
        $transaksiLama = self::transaksiPerKomponen($gdLama);
        $transaksiBaru = self::transaksiPerKomponen($gdBaru);
        $rupiah = fn($nilai) => 'Rp ' . number_format((float) $nilai, 0, ',', '.');

        return array_map(function (array $komponen) use ($transaksiLama, $transaksiBaru, $rupiah) {
            $kode = $komponen['kode'];
            $lama = $transaksiLama[$kode] ?? null;
            $baru = $transaksiBaru[$kode] ?? null;
            if ($komponen['jenis'] === 'J' && ($lama || $baru)) {
                $nominalLama = $lama['nominal'] ?? 0.0;
                $nominalBaru = $baru['nominal'] ?? 0.0;
                if (abs($nominalLama - $nominalBaru) >= 0.5 || ($lama['baris'] ?? 0) !== ($baru['baris'] ?? 0)) {
                    return [...$komponen, 'asal' => 'transaksi', 'keterangan_asal' => 'Transaksinya berubah: ' . $rupiah($nominalLama) . ' (' . ($lama['baris'] ?? 0) . ' baris) → '
                        . $rupiah($nominalBaru) . ' (' . ($baru['baris'] ?? 0) . ' baris) — rinciannya di tabel transaksi di bawah.'];
                }
                $slipLama = $komponen['lama'];
                $keterangan = 'Transaksinya SAMA di kedua versi (' . $rupiah($nominalBaru) . ', ' . ($baru['baris'] ?? 0) . ' baris) — selisih dari cara hitung slip, bukan dari transaksi.';
                if ($slipLama === null) {
                    $keterangan .= ' Versi lama tidak menghitung transaksi ini di slipnya walau sudah ada di lampirannya.';
                } elseif (abs($slipLama - $nominalLama) >= 0.5) {
                    $keterangan .= ' Slip versi lama menghitung ' . $rupiah($slipLama) . ' dari transaksi ' . $rupiah($nominalLama) . '.';
                }

                return [...$komponen, 'asal' => 'hitung', 'keterangan_asal' => $keterangan];
            }
            $keterangan = match (true) {
                str_starts_with($kode, 'KAPITA') => 'Per kapita: tarif × pasien dari struktur gaji.',
                str_starts_with($kode, 'TUNJ') => 'Tunjangan rutin dari struktur gaji.',
                in_array($kode, ['RS', 'PPH21'], true) => 'Mengikuti total gaji serta basis & persen di struktur gaji.',
                $komponen['jenis'] === 'J' => 'Komponen tanpa transaksi di kedua versi.',
                default => 'Potongan / tambahan: struktur gaji (rutin) atau baris manual.',
            };

            return [...$komponen, 'asal' => 'struktur', 'keterangan_asal' => $keterangan];
        }, $detail);
    }

    private static function lampiranKosong(object $headerLama, object $headerBaru): array
    {
        return ['baris' => [], 'terpotong' => false, 'jumlah_selisih' => 0, 'jumlah_per_jenis' => [],
            'total' => ['lama' => ['baris' => (int) $headerLama->jumlah_lampiran], 'baru' => ['baris' => (int) $headerBaru->jumlah_lampiran]]];
    }

    /** Identitas baris lampiran tanpa nominal. */
    public static function kunciLampiran(object $row): string
    {
        $txnNo = trim((string) ($row->txn_no ?? ''));
        $identitas = $txnNo !== '' ? 'T:' . $txnNo : 'R:' . implode('|', [trim((string) $row->reg_no), trim((string) $row->tgl), trim((string) $row->nama), trim((string) $row->keterangan)]);

        return $row->komponen . '|' . $identitas;
    }

    /** Baris lampiran satu snapshot, digabung per identitas. @return array<string, array> */
    private static function lampiranTergabung(int $gdId): array
    {
        $hasil = [];
        foreach (GajiDokterSirus::lampiran($gdId) as $row) {
            $kunci = self::kunciLampiran($row);
            if (!isset($hasil[$kunci])) {
                $hasil[$kunci] = ['komponen' => (string) $row->komponen, 'label' => (string) ($row->label ?: $row->komponen), 'txn_no' => $row->txn_no,
                    'tgl' => $row->tgl, 'reg_no' => $row->reg_no, 'nama' => $row->nama, 'keterangan' => $row->keterangan, 'klaim' => $row->klaim,
                    'klaim_status' => $row->klaim_status, 'masuk_slip' => (string) $row->masuk_slip, 'nominal' => 0.0, 'pasien' => 0, 'jumlah_baris' => 0];
            }
            $hasil[$kunci]['nominal'] += (float) $row->nominal;
            $hasil[$kunci]['pasien'] += (int) $row->pasien;
            $hasil[$kunci]['jumlah_baris']++;
        }

        return $hasil;
    }

    /** Daftar atribut yang berubah antara dua baris tergabung (kunci KOLOM_LAMPIRAN). @return array<int, string> */
    private static function kolomBerubah(array $barisLama, array $barisBaru): array
    {
        $berubah = [];
        foreach (array_keys(self::KOLOM_LAMPIRAN) as $kolom) {
            $beda = $kolom === 'nominal'
                ? abs($barisLama['nominal'] - $barisBaru['nominal']) >= 0.005
                : trim((string) $barisLama[$kolom]) !== trim((string) $barisBaru[$kolom]);
            if ($beda) {
                $berubah[] = $kolom;
            }
        }

        return $berubah;
    }

    /**
     * Banding lampiran pasien dua snapshot.
     * @return array{baris: array<int, array{jenis: string, kunci: string, komponen: string, label: string, lama: ?array, baru: ?array, berubah: array<int, string>}>, terpotong: bool, jumlah_selisih: int, total: array}
     */
    public static function bandingkanLampiran(int $gdLama, int $gdBaru): array
    {
        $lampiranLama = self::lampiranTergabung($gdLama);
        $lampiranBaru = self::lampiranTergabung($gdBaru);

        $barisSelisih = [];
        foreach (array_unique([...array_keys($lampiranLama), ...array_keys($lampiranBaru)]) as $kunci) {
            $barisLama = $lampiranLama[$kunci] ?? null;
            $barisBaru = $lampiranBaru[$kunci] ?? null;
            $kolomBerubah = $barisLama && $barisBaru ? self::kolomBerubah($barisLama, $barisBaru) : [];
            if ($barisLama && $barisBaru && $kolomBerubah === []) {
                continue;
            }
            $acuan = $barisBaru ?? $barisLama;
            $barisSelisih[] = ['jenis' => !$barisLama ? 'baru' : (!$barisBaru ? 'hilang' : 'berubah'), 'kunci' => $kunci,
                'komponen' => $acuan['komponen'], 'label' => $acuan['label'], 'lama' => $barisLama, 'baru' => $barisBaru, 'berubah' => $kolomBerubah];
        }
        usort($barisSelisih, fn($kiri, $kanan) => [$kiri['komponen'], (string) ($kiri['baru'] ?? $kiri['lama'])['tgl'], (string) ($kiri['baru'] ?? $kiri['lama'])['nama']]
            <=> [$kanan['komponen'], (string) ($kanan['baru'] ?? $kanan['lama'])['tgl'], (string) ($kanan['baru'] ?? $kanan['lama'])['nama']]);

        $total = fn(array $lampiran) => ['baris' => (int) array_sum(array_column($lampiran, 'jumlah_baris')), 'nominal' => (float) array_sum(array_column($lampiran, 'nominal')),
            'pasien' => (int) array_sum(array_column($lampiran, 'pasien'))];

        return ['baris' => array_slice($barisSelisih, 0, self::BATAS_BARIS_SELISIH), 'terpotong' => count($barisSelisih) > self::BATAS_BARIS_SELISIH,
            'jumlah_selisih' => count($barisSelisih), 'jumlah_per_jenis' => array_count_values(array_column($barisSelisih, 'jenis')),
            'total' => ['lama' => $total($lampiranLama), 'baru' => $total($lampiranBaru)]];
    }

    /** Label perubahan satu baris berubah, mis. ["nominal", "status klaim"]. @return array<int, string> */
    public static function labelPerubahan(array $baris): array
    {
        return array_map(fn($kolom) => self::KOLOM_LAMPIRAN[$kolom] ?? $kolom, $baris['berubah'] ?? []);
    }
}
