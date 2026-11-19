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
 * Versi di SIAP dibuat PER DOKTER (GajiDokterSirus::ambil / hitungUlangSiap: checksum beda → versi baru, versi lama tetap).
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
     * Dokter satu bulan jasa + ringkasan versinya, berversi > 1 dulu.
     * @return Collection<int, object{dr_id: string, dr_name: string, jumlah_versi: int, gd_terbaru: int, gd_pertama: int, versi_terbaru: int, diterima_pertama: float, diterima_terbaru: float, selisih: float, diambil_terakhir: ?string}>
     */
    public static function dokterList(string $periode, string $cari = '', bool $hanyaBerversi = true): Collection
    {
        $kataCari = mb_strtolower(trim($cari));

        return DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)
            ->orderBy('dr_id')->orderBy('versi')
            ->get(['gd_id', 'dr_id', 'dr_name', 'versi', 'terbaru', 'gaji_diterima', 'diambil_at', 'created_at'])
            ->groupBy('dr_id')
            ->map(function (Collection $versiDokter) {
                $pertama = $versiDokter->first();
                $terbaru = $versiDokter->firstWhere('terbaru', '1') ?? $versiDokter->last();

                return (object) [
                    'dr_id' => (string) $pertama->dr_id, 'dr_name' => (string) $terbaru->dr_name, 'jumlah_versi' => $versiDokter->count(),
                    'gd_terbaru' => (int) $terbaru->gd_id, 'gd_pertama' => (int) $pertama->gd_id, 'versi_terbaru' => (int) $terbaru->versi,
                    'diterima_pertama' => (float) $pertama->gaji_diterima, 'diterima_terbaru' => (float) $terbaru->gaji_diterima,
                    'selisih' => (float) $terbaru->gaji_diterima - (float) $pertama->gaji_diterima,
                    'diambil_terakhir' => $terbaru->created_at ?? $terbaru->diambil_at,
                ];
            })
            ->filter(fn($dokter) => (!$hanyaBerversi || $dokter->jumlah_versi > 1)
                && ($kataCari === '' || str_contains(mb_strtolower($dokter->dr_name), $kataCari) || str_contains(mb_strtolower($dokter->dr_id), $kataCari)))
            ->sortBy([fn($kiri, $kanan) => ($kanan->jumlah_versi > 1) <=> ($kiri->jumlah_versi > 1), fn($kiri, $kanan) => strcmp($kiri->dr_name, $kanan->dr_name)])
            ->values();
    }

    /** Jumlah dokter bulan itu yang punya lebih dari satu versi (untuk teks kosong / penanda). */
    public static function jumlahDokterBerversi(string $periode): int
    {
        return DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('versi', '>', 1)->distinct()->count('dr_id');
    }

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
        $lampiran = $headerLama->checksum === $headerBaru->checksum
            ? self::lampiranKosong($headerLama, $headerBaru)
            : Cache::store('file')->remember("siap.gdok.banding.{$gdLama}.{$gdBaru}", now()->addDay(), fn() => self::bandingkanLampiran($gdLama, $gdBaru));

        return [
            'lama' => $headerLama, 'baru' => $headerBaru,
            'identik' => $pengaturan === [] && $bandingSlip['header'] === [] && $bandingSlip['detail'] === [] && $lampiran['baris'] === [],
            'pengaturan' => $pengaturan, 'angka' => $bandingSlip['header'], 'detail' => $bandingSlip['detail'], 'lampiran' => $lampiran,
        ];
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
