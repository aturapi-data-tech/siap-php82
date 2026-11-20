<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * VERSI PERIODE gaji dokter — pola SIKEU (snapshot satu periode → versi → sahkan satu versi untuk pembukuan).
 *
 * Satu versi periode = keadaan SELURUH dokter satu bulan jasa: peta dr_id → gd_id (snapshot per dokter, siaptxn_gdok_hdrs).
 *   - dibuat tiap Ambil yang petanya berbeda dari versi periode terakhir (Ambil yang hasilnya sama tidak membuat versi);
 *   - dibuat juga oleh koreksi manual di Rincian (salinan versi dipakai dengan satu dokter diganti) — langsung dipakai;
 *   - versi periode PERTAMA bulan itu langsung dipakai; berikutnya MENUNGGU dipilih (pakai()).
 * Tepat satu versi periode dipakai per bulan jasa; siaptxn_gdok_hdrs.dipakai = turunannya, jadi Buku / Final / Terapkan cukup
 * membaca kolom itu. Dokter yang versi dipakainya Final (hitungan SIAP) mengunci: pindah versi periode ditolak bila dokter
 * Final itu angkanya akan berganti.
 */
class GajiDokterVersiPeriode
{
    public const LABEL_SUMBER = ['AMBIL' => 'Ambil', 'KOREKSI' => 'Koreksi manual', 'MIGRASI' => 'Keadaan awal'];

    /** Jenis perbedaan dokter antar versi periode → urutan tampil (yang perlu diperiksa dulu). */
    public const URUTAN_JENIS = ['berubah' => 0, 'baru' => 0, 'hilang' => 0, 'setara' => 1, 'sama' => 2];

    public const LABEL_JENIS = ['berubah' => 'berubah', 'baru' => 'baru', 'hilang' => 'hilang', 'setara' => 'angka sama', 'sama' => 'sama'];

    /** Versi periode satu bulan jasa (terbaru dulu) + ringkasan angka. @return Collection<int, object> */
    public static function daftar(string $periode): Collection
    {
        $ringkas = DB::table('siaptxn_gdok_versi_dtls as vd')->join('siaptxn_gdok_versis as v', 'v.versi_id', '=', 'vd.versi_id')
            ->join('siaptxn_gdok_hdrs as h', 'h.gd_id', '=', 'vd.gd_id')->where('v.periode', $periode)
            ->groupBy('vd.versi_id')
            ->selectRaw('vd.versi_id, COUNT(*) AS jumlah_dokter, SUM(h.jasa_total) AS jasa_total, SUM(h.gaji_diterima) AS gaji_diterima')
            ->get()->keyBy('versi_id');

        return DB::table('siaptxn_gdok_versis as v')->leftJoin('users as u', 'u.id', '=', 'v.dibuat_oleh')->leftJoin('users as up', 'up.id', '=', 'v.dipakai_oleh')
            ->where('v.periode', $periode)->orderByDesc('v.versi')
            ->get(['v.*', 'u.name as dibuat_nama', 'up.name as dipakai_nama'])
            ->map(function ($versi) use ($ringkas) {
                $versi->versi_id = (int) $versi->versi_id;
                $versi->versi = (int) $versi->versi;
                $angka = $ringkas[$versi->versi_id] ?? null;
                $versi->jumlah_dokter = (int) ($angka->jumlah_dokter ?? 0);
                $versi->jasa_total = (float) ($angka->jasa_total ?? 0);
                $versi->gaji_diterima = (float) ($angka->gaji_diterima ?? 0);

                return $versi;
            });
    }

    public static function dipakai(string $periode): ?object
    {
        return DB::table('siaptxn_gdok_versis')->where('periode', $periode)->where('dipakai', '1')->first();
    }

    /** Versi periode terbaru bila BELUM dipakai (menunggu keputusan), selain itu null. */
    public static function menunggu(string $periode): ?object
    {
        $terbaru = DB::table('siaptxn_gdok_versis')->where('periode', $periode)->orderByDesc('versi')->first();

        return $terbaru && $terbaru->dipakai !== '1' ? $terbaru : null;
    }

    /** @return array<string, int> dr_id → gd_id */
    public static function peta(int $versiId): array
    {
        return DB::table('siaptxn_gdok_versi_dtls')->where('versi_id', $versiId)->pluck('gd_id', 'dr_id')
            ->mapWithKeys(fn($gdId, $drId) => [(string) $drId => (int) $gdId])->all();
    }

    private static function checksum(array $peta): string
    {
        ksort($peta);

        return md5(json_encode($peta));
    }

    /**
     * Catat keadaan bulan jasa sebagai versi periode bila berbeda dari versi terakhir. Versi pertama bulan itu langsung dipakai.
     * @return array{versi: ?object, dibuat: bool, dipakai: bool}
     */
    public static function catat(string $periode, array $peta, string $sumber, ?int $ambilId, ?string $keterangan, ?int $userId): array
    {
        $terakhir = DB::table('siaptxn_gdok_versis')->where('periode', $periode)->orderByDesc('versi')->first();
        if ($peta === []) {
            return ['versi' => $terakhir, 'dibuat' => false, 'dipakai' => false];
        }
        $checksum = self::checksum($peta);
        if ($terakhir && $terakhir->checksum === $checksum) {
            return ['versi' => $terakhir, 'dibuat' => false, 'dipakai' => $terakhir->dipakai === '1'];
        }
        $nomor = (int) ($terakhir->versi ?? 0) + 1;
        DB::transaction(function () use ($periode, $peta, $sumber, $ambilId, $keterangan, $userId, $checksum, $nomor) {
            DB::table('siaptxn_gdok_versis')->insert(['periode' => $periode, 'versi' => $nomor, 'sumber' => $sumber, 'ambil_id' => $ambilId,
                'keterangan' => $keterangan ? mb_substr($keterangan, 0, 200) : null, 'checksum' => $checksum, 'dipakai' => '0',
                'dibuat_oleh' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $versiId = (int) DB::table('siaptxn_gdok_versis')->where('periode', $periode)->where('versi', $nomor)->value('versi_id');
            foreach (array_chunk($peta, 200, true) as $potongan) {
                DB::table('siaptxn_gdok_versi_dtls')->insert(array_map(fn($drId, $gdId) => ['versi_id' => $versiId, 'dr_id' => (string) $drId, 'gd_id' => (int) $gdId],
                    array_keys($potongan), $potongan));
            }
        });
        $versi = DB::table('siaptxn_gdok_versis')->where('periode', $periode)->where('versi', $nomor)->first();
        $langsungDipakai = !DB::table('siaptxn_gdok_versis')->where('periode', $periode)->where('dipakai', '1')->exists();
        if ($langsungDipakai) {
            self::terapkanDipakai($versi, $userId);
        }

        return ['versi' => $versi, 'dibuat' => true, 'dipakai' => $langsungDipakai];
    }

    /**
     * Pakai satu versi periode untuk gaji bulan jasanya (mengganti semua dokter sekaligus). Ditolak bila ada dokter Final
     * yang angkanya akan berganti. @return string|null pesan galat
     */
    public static function pakai(int $versiId, ?int $userId): ?string
    {
        $versi = DB::table('siaptxn_gdok_versis')->where('versi_id', $versiId)->first();
        if (!$versi) {
            return 'Versi tidak ditemukan.';
        }
        if ($versi->dipakai === '1') {
            return "Versi {$versi->versi} sudah dipakai.";
        }
        $petaBaru = self::peta($versiId);
        $finalTerdampak = DB::table('siaptxn_gdok_hdrs')->where('periode', $versi->periode)->where('dipakai', '1')->get()
            ->filter(fn($header) => GajiDokterSirus::terkunci($header) && ($petaBaru[(string) $header->dr_id] ?? null) !== (int) $header->gd_id)
            ->pluck('dr_name')->all();
        if ($finalTerdampak !== []) {
            return count($finalTerdampak) . ' dokter sudah Final dan angkanya berbeda di versi ' . $versi->versi . ': '
                . implode(', ', array_slice($finalTerdampak, 0, 5)) . (count($finalTerdampak) > 5 ? ' …' : '') . '. Buka Kunci dulu di Buku.';
        }
        self::terapkanDipakai($versi, $userId);

        return null;
    }

    /** Tandai versi periode dipakai + turunkan ke siaptxn_gdok_hdrs.dipakai (hanya versi dokter yang tercantum). */
    private static function terapkanDipakai(object $versi, ?int $userId): void
    {
        $gdIdList = array_values(self::peta((int) $versi->versi_id));
        DB::transaction(function () use ($versi, $gdIdList, $userId) {
            DB::table('siaptxn_gdok_versis')->where('periode', $versi->periode)->update(['dipakai' => '0']);
            DB::table('siaptxn_gdok_versis')->where('versi_id', $versi->versi_id)->update(['dipakai' => '1', 'dipakai_at' => now(), 'dipakai_oleh' => $userId, 'updated_at' => now()]);
            DB::table('siaptxn_gdok_hdrs')->where('periode', $versi->periode)->where('dipakai', '1')->update(['dipakai' => '0']);
            foreach (array_chunk($gdIdList, 500) as $potongan) {
                DB::table('siaptxn_gdok_hdrs')->whereIn('gd_id', $potongan)->update(['dipakai' => '1', 'dipakai_at' => now(), 'dipakai_oleh' => $userId]);
            }
        });
    }

    /**
     * Koreksi manual satu dokter (Rincian → hitung ulang) = versi periode baru: salinan versi dipakai dengan dokter itu diganti
     * snapshot barunya, langsung dipakai (keputusan Keuangan sendiri). @return object versi periode baru
     */
    public static function koreksi(string $periode, string $drId, int $gdBaru, string $namaDokter, ?int $userId): ?object
    {
        $dipakai = self::dipakai($periode);
        $peta = $dipakai ? self::peta((int) $dipakai->versi_id) : [];
        $peta[$drId] = $gdBaru;
        $hasil = self::catat($periode, $peta, 'KOREKSI', null, "Koreksi manual {$namaDokter}", $userId);
        if ($hasil['versi'] && $hasil['versi']->dipakai !== '1') {
            self::terapkanDipakai($hasil['versi'], $userId);
        }

        return $hasil['versi'];
    }

    /**
     * Bandingkan dua versi periode per dokter: berubah / baru (hanya di B) / hilang (hanya di A) / setara (snapshot lain tetapi
     * semua angka & jumlah baris transaksi sama — mis. arsip SIRUS vs hitungan SIAP yang hasilnya identik) / sama (snapshot sama).
     * Urut: berubah/baru/hilang (selisih terbesar dulu), lalu setara, lalu sama.
     * @return array{dokter: Collection<int, object>, total: array}
     */
    public static function bandingkan(int $versiA, int $versiB): array
    {
        $petaA = self::peta($versiA);
        $petaB = self::peta($versiB);
        $header = DB::table('siaptxn_gdok_hdrs')->whereIn('gd_id', array_values($petaA + []) ?: [0])
            ->orWhereIn('gd_id', array_values($petaB) ?: [0])->get(['gd_id', 'dr_id', 'dr_name', 'versi', 'gaji_status', 'sumber', 'jumlah_lampiran', ...GajiDokterSirus::KOLOM_NOMINAL])->keyBy('gd_id');
        $angkaSama = fn($a, $b) => (int) $a->jumlah_lampiran === (int) $b->jumlah_lampiran
            && collect(GajiDokterSirus::KOLOM_NOMINAL)->every(fn($kolom) => abs((float) $a->$kolom - (float) $b->$kolom) < 0.5);
        $dokter = collect(array_unique([...array_keys($petaA), ...array_keys($petaB)]))->map(function ($drId) use ($petaA, $petaB, $header, $angkaSama) {
            $a = isset($petaA[$drId]) ? ($header[$petaA[$drId]] ?? null) : null;
            $b = isset($petaB[$drId]) ? ($header[$petaB[$drId]] ?? null) : null;
            $jenis = !$a ? 'baru' : (!$b ? 'hilang' : ((int) $a->gd_id === (int) $b->gd_id ? 'sama' : ($angkaSama($a, $b) ? 'setara' : 'berubah')));

            return (object) ['dr_id' => (string) $drId, 'dr_name' => (string) (($b ?? $a)->dr_name ?? $drId), 'jenis' => $jenis,
                'gd_a' => $a ? (int) $a->gd_id : null, 'gd_b' => $b ? (int) $b->gd_id : null,
                'versi_a' => $a ? (int) $a->versi : null, 'versi_b' => $b ? (int) $b->versi : null,
                'diterima_a' => $a ? (float) $a->gaji_diterima : null, 'diterima_b' => $b ? (float) $b->gaji_diterima : null,
                'jasa_a' => $a ? (float) $a->jasa_total : null, 'jasa_b' => $b ? (float) $b->jasa_total : null,
                'selisih' => (float) ($b->gaji_diterima ?? 0) - (float) ($a->gaji_diterima ?? 0)];
        })->sortBy([fn($kiri, $kanan) => self::URUTAN_JENIS[$kiri->jenis] <=> self::URUTAN_JENIS[$kanan->jenis], fn($kiri, $kanan) => abs($kanan->selisih) <=> abs($kiri->selisih),
            fn($kiri, $kanan) => strcmp($kiri->dr_name, $kanan->dr_name)])->values();

        $jumlah = fn(string $kolom) => (float) $dokter->sum($kolom);

        return ['dokter' => $dokter, 'total' => [
            'dokter_a' => count($petaA), 'dokter_b' => count($petaB),
            'jasa_a' => $jumlah('jasa_a'), 'jasa_b' => $jumlah('jasa_b'), 'diterima_a' => $jumlah('diterima_a'), 'diterima_b' => $jumlah('diterima_b'),
            'per_jenis' => $dokter->countBy('jenis')->all(),
        ]];
    }
}
