<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Mesin slip gaji karyawan — siaptxn_gaji_hdrs / siaptxn_gaji_dtls.
 * Pola mengikuti gaji dokter sirus-php82 (App\Support\GajiDokter\GajiDokter).
 *
 * Potongan disimpan NEGATIF sehingga semuanya cukup dijumlah:
 *   total_pendapatan = SUM(detail D)
 *   pph21            = -FLOOR(pph21_persen% × total_pendapatan)   (kecuali ditimpa manual)
 *   total_potongan   = SUM(detail P) termasuk PPh 21
 *   gaji_diterima    = total_pendapatan + total_potongan
 *
 * Generate (per periode) menyusun baris OTOMATIS dari parameter gaji + absensi:
 *   gaji pokok, tunjangan makan & transport (tarif × HARI KERJA slip), tunjangan struktural &
 *   profesi, potongan rutin. Baris yang diisi petugas (jasa medik, upah lembur, bonus, kasbon,
 *   angsuran, …) dan baris otomatis yang nilainya ditimpa TIDAK disentuh saat generate ulang.
 * Slip Final terkunci — generate ulang melewatinya; buka kembali ke Draft untuk mengubah.
 *
 * HARI KERJA (kolom header hari_hadir) = pengali tarif harian. Slip baru memakai hari_kerja_default
 * dari parameter; setelah itu diedit petugas di rincian (cuti / izin / tambahan) lewat ubahHariKerja().
 * Generate ulang TIDAK menimpanya. Jumlah hadir menurut absensi (hariHadir) hanya acuan di layar.
 */
class GajiKaryawan
{
    public const STATUS_DRAFT = 'D';
    public const STATUS_FINAL = 'F';

    public const STATUS = [self::STATUS_DRAFT => 'Draft', self::STATUS_FINAL => 'Final'];

    /** Masa berlaku link PDF slip yang dikirim lewat WhatsApp. */
    public const MASA_LINK_SLIP_HARI = 7;

    public const PRINT_VIEW = 'pages.keuangan.gaji-karyawan.gaji-karyawan-slip-print';

    /** Kode pendapatan (jenis D) → label slip. */
    public const PENDAPATAN = [
        'POKOK'      => 'Gaji Pokok',
        'MAKAN'      => 'Tunjangan Makan',
        'TRANSPORT'  => 'Tunjangan Transport',
        'STRUKTURAL' => 'Tunjangan Struktural',
        'PROFESI'    => 'Tunjangan Profesi',
        'JASA_MEDIK' => 'Jasa Medik',
        'LEMBUR'     => 'Upah Lembur',
        'BONUS'      => 'Bonus',
        'RAPEL'      => 'Rapel',
        'KOREKSI'    => 'Koreksi',
        'LAIN'       => 'Pendapatan Lain',
    ];

    /** Kode potongan (jenis P) → label slip. */
    public const POTONGAN = [
        'ASURANSI'   => 'Asuransi',
        'ORGANISASI' => 'Iuran Organisasi Profesi',
        'ARISAN'     => 'Arisan',
        'KOPERASI'   => 'Koperasi / Iuran',
        'INFAQ'      => 'Infaq',
        'BPJS'       => 'BPJS',
        'ANGSURAN'   => 'Angsuran',
        'KASBON'     => 'Kasbon / Telah Diambil',
        'PPH21'      => 'PPh 21',
        'LAIN'       => 'Potongan Lain',
    ];

    /** Kolom parameter → kode baris tetap per bulan. */
    private const PENDAPATAN_RUTIN = [
        'gaji_pokok'           => 'POKOK',
        'tunjangan_struktural' => 'STRUKTURAL',
        'tunjangan_profesi'    => 'PROFESI',
    ];

    /** Kolom parameter tarif per hari kerja → kode (baris "harian": nilai = hari kerja × tarif). */
    public const PENDAPATAN_HARIAN = [
        'tarif_makan'     => 'MAKAN',
        'tarif_transport' => 'TRANSPORT',
    ];

    private const POTONGAN_RUTIN = [
        'potongan_asuransi'   => 'ASURANSI',
        'potongan_organisasi' => 'ORGANISASI',
        'potongan_arisan'     => 'ARISAN',
        'potongan_koperasi'   => 'KOPERASI',
        'potongan_infaq'      => 'INFAQ',
        'potongan_bpjs'       => 'BPJS',
    ];

    /** Kolom parameter yang berupa nominal rupiah (form parameter & validasi). */
    public const KOLOM_NOMINAL = [
        'gaji_pokok', 'tarif_makan', 'tarif_transport', 'tunjangan_struktural', 'tunjangan_profesi',
        'potongan_asuransi', 'potongan_organisasi', 'potongan_arisan', 'potongan_koperasi', 'potongan_infaq', 'potongan_bpjs',
    ];

    /** Urutan cetak per kode (pendapatan dulu, PPh 21 paling akhir). */
    private const URUTAN = [
        'POKOK' => 1, 'MAKAN' => 2, 'TRANSPORT' => 3, 'STRUKTURAL' => 4, 'PROFESI' => 5, 'JASA_MEDIK' => 6,
        'LEMBUR' => 7, 'BONUS' => 8, 'RAPEL' => 9, 'KOREKSI' => 10,
        'ASURANSI' => 21, 'ORGANISASI' => 22, 'ARISAN' => 23, 'KOPERASI' => 24, 'INFAQ' => 25, 'BPJS' => 26,
        'ANGSURAN' => 27, 'KASBON' => 28, 'PPH21' => 99,
    ];

    public static function label(string $jenis, string $kode): string
    {
        return ($jenis === 'P' ? self::POTONGAN : self::PENDAPATAN)[$kode] ?? $kode;
    }

    /** Periode 'YYYY-MM' valid? */
    public static function periodeValid(string $periode): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode);
    }

    public static function awalPeriode(string $periode): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $periode)->startOfMonth();
    }

    /** Jumlah hari Hadir / Terlambat per karyawan dalam satu periode. @return array<string, int> */
    public static function hariHadir(array $empIds, string $periode): array
    {
        if ($empIds === []) {
            return [];
        }
        $awal = self::awalPeriode($periode);

        return DB::table('siaptxn_absensis')
            ->whereIn('emp_id', $empIds)
            ->whereIn('abs_status', ['H', 'T'])
            ->where('abs_date', '>=', Absensi::tanggalSql($awal))
            ->where('abs_date', '<=', Absensi::tanggalSql($awal->copy()->endOfMonth()))
            ->groupBy('emp_id')
            ->select('emp_id', DB::raw('COUNT(*) AS jumlah'))
            ->pluck('jumlah', 'emp_id')
            ->map(fn($jumlah) => (int) $jumlah)
            ->all();
    }

    /**
     * Susun / perbarui slip Draft satu periode untuk karyawan aktif yang sudah punya parameter gaji.
     * $empId diisi = hanya satu karyawan (tombol "susun ulang" di rincian slip).
     * @return array{dibuat: int, diperbarui: int, dilewatiFinal: array<int, string>, tanpaParameter: int}
     */
    public static function generate(string $periode, ?string $unitId, ?int $userId, ?string $empId = null): array
    {
        $karyawanList = DB::table('siapmst_employers as em')
            ->leftJoin('siapmst_gaji_karyawans as gk', 'gk.emp_id', '=', 'em.emp_id')
            ->where('em.active_status', '1')
            ->when($unitId, fn($subQuery) => $subQuery->where('em.emp_unit_id', $unitId))
            ->when($empId, fn($subQuery) => $subQuery->where('em.emp_id', $empId))
            ->select('em.emp_id', 'em.emp_name', 'em.emp_unit_id', 'gk.*', DB::raw('gk.emp_id AS parameter_emp_id'))
            ->get();

        $hasil = ['dibuat' => 0, 'diperbarui' => 0, 'dilewatiFinal' => [], 'tanpaParameter' => 0];
        $berparameter = $karyawanList->whereNotNull('parameter_emp_id');
        $hasil['tanpaParameter'] = $karyawanList->count() - $berparameter->count();

        $slipLama = DB::table('siaptxn_gaji_hdrs')->where('periode', $periode)
            ->whereIn('emp_id', $berparameter->pluck('emp_id')->all() ?: [''])
            ->get()->keyBy('emp_id');

        foreach ($berparameter as $parameter) {
            $slip = $slipLama[$parameter->emp_id] ?? null;
            if ($slip && $slip->gaji_status === self::STATUS_FINAL) {
                $hasil['dilewatiFinal'][] = $parameter->emp_name;
                continue;
            }

            DB::transaction(function () use ($parameter, $slip, $periode, $userId, &$hasil) {
                $snapshot = [
                    'unit_id'         => $parameter->emp_unit_id,
                    // Slip yang sudah ada: hari kerja hasil edit petugas dipertahankan.
                    'hari_hadir'      => $slip ? (int) $slip->hari_hadir : (int) $parameter->hari_kerja_default,
                    'gaji_pokok'      => (float) $parameter->gaji_pokok,
                    'tarif_makan'     => (float) $parameter->tarif_makan,
                    'tarif_transport' => (float) $parameter->tarif_transport,
                    'pph21_persen'    => (float) $parameter->pph21_persen,
                    'updated_by'      => $userId,
                    'updated_at'      => now(),
                ];

                if ($slip) {
                    $gajiId = (int) $slip->gaji_id;
                    DB::table('siaptxn_gaji_hdrs')->where('gaji_id', $gajiId)->update($snapshot);
                    // Baris otomatis disusun ulang; yang ditimpa petugas & baris manual dipertahankan.
                    DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->where('otomatis', '1')->where('nilai_manual', '0')->delete();
                    $hasil['diperbarui']++;
                } else {
                    // insert() biasa — gaji_id dari trigger/sequence (insertGetId memicu warning yajra di 10g);
                    // id diambil lewat kunci unik (emp_id, periode).
                    DB::table('siaptxn_gaji_hdrs')->insert([
                        ...$snapshot,
                        'emp_id'      => $parameter->emp_id,
                        'periode'     => $periode,
                        'gaji_status' => self::STATUS_DRAFT,
                        'created_at'  => now(),
                    ]);
                    $gajiId = (int) DB::table('siaptxn_gaji_hdrs')->where('emp_id', $parameter->emp_id)->where('periode', $periode)->value('gaji_id');
                    $hasil['dibuat']++;
                }

                $dipertahankan = DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->where('otomatis', '1')->pluck('kode')->all();
                foreach (self::barisOtomatis($parameter, $snapshot['hari_hadir']) as $baris) {
                    if (!in_array($baris['kode'], $dipertahankan, true)) {
                        self::simpanBaris($gajiId, [...$baris, 'otomatis' => '1']);
                    }
                }

                self::hitungUlang($gajiId);
            });
        }

        return $hasil;
    }

    /** Baris otomatis dari parameter gaji (nilai nol dilewati). */
    private static function barisOtomatis(object $parameter, int $hariKerja): array
    {
        $baris = [];
        foreach (self::PENDAPATAN_RUTIN as $kolom => $kode) {
            if ((float) $parameter->$kolom > 0) {
                $baris[] = ['jenis' => 'D', 'kode' => $kode, 'nilai' => (float) $parameter->$kolom];
            }
        }
        foreach (self::PENDAPATAN_HARIAN as $kolom => $kode) {
            if ((float) $parameter->$kolom > 0) {
                $baris[] = ['jenis' => 'D', 'kode' => $kode, 'jumlah' => $hariKerja, 'tarif' => (float) $parameter->$kolom,
                    'keterangan' => "{$hariKerja} hari kerja", 'nilai' => $hariKerja * (float) $parameter->$kolom];
            }
        }
        foreach (self::POTONGAN_RUTIN as $kolom => $kode) {
            if ((float) $parameter->$kolom > 0) {
                $baris[] = ['jenis' => 'P', 'kode' => $kode, 'nilai' => -abs((float) $parameter->$kolom)];
            }
        }

        return $baris;
    }

    /** Hitung ulang PPh 21 & total header dari detail. Dipanggil setiap rincian berubah. */
    public static function hitungUlang(int $gajiId): void
    {
        $slip = DB::table('siaptxn_gaji_hdrs')->where('gaji_id', $gajiId)->first();
        if (!$slip) {
            return;
        }

        $detail = DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->get();
        $pendapatan = (float) $detail->where('jenis', 'D')->sum('nilai');

        // PPh 21: ditimpa petugas → dipertahankan; selain itu dari persen snapshot.
        $pphManual = $detail->where('kode', 'PPH21')->firstWhere('nilai_manual', '1');
        $pph21 = $pphManual ? -abs((float) $pphManual->nilai) : -floor((float) $slip->pph21_persen / 100 * max(0, $pendapatan));

        DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->where('kode', 'PPH21')->where('nilai_manual', '0')->delete();
        if (!$pphManual && $pph21 < 0) {
            self::simpanBaris($gajiId, ['jenis' => 'P', 'kode' => 'PPH21', 'nilai' => $pph21, 'otomatis' => '1',
                'keterangan' => rtrim(rtrim(number_format((float) $slip->pph21_persen, 2, ',', '.'), '0'), ',') . '%']);
        }

        $potongan = (float) $detail->where('jenis', 'P')->where('kode', '!=', 'PPH21')->sum('nilai') + $pph21;

        DB::table('siaptxn_gaji_hdrs')->where('gaji_id', $gajiId)->update([
            'total_pendapatan' => round($pendapatan, 2),
            'total_potongan'   => round($potongan, 2),
            'gaji_diterima'    => round($pendapatan + $potongan, 2),
            'updated_at'       => now(),
        ]);
    }

    /** Simpan satu baris detail; tanda nilai mengikuti jenis (potongan selalu negatif, koreksi boleh minus). */
    public static function simpanBaris(int $gajiId, array $baris): void
    {
        $nilai = (float) ($baris['nilai'] ?? 0);
        $nilai = match (true) {
            $baris['jenis'] === 'P'         => -abs($nilai),
            $baris['kode'] === 'KOREKSI'    => $nilai,
            default                         => abs($nilai),
        };

        DB::table('siaptxn_gaji_dtls')->insert([
            'gaji_id'      => $gajiId,
            'jenis'        => $baris['jenis'],
            'kode'         => $baris['kode'],
            'keterangan'   => isset($baris['keterangan']) && trim($baris['keterangan']) !== '' ? mb_substr(trim($baris['keterangan']), 0, 100) : null,
            'jumlah'       => (float) ($baris['jumlah'] ?? 0),
            'tarif'        => (float) ($baris['tarif'] ?? 0),
            'nilai'        => round($nilai, 2),
            'otomatis'     => $baris['otomatis'] ?? '0',
            'nilai_manual' => $baris['nilai_manual'] ?? '0',
            'urutan'       => self::URUTAN[$baris['kode']] ?? ($baris['jenis'] === 'P' ? 50 : 15),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Slip masih Draft? (semua penyuntingan rincian wajib lewat sini). */
    public static function bisaDiubah(int $gajiId): bool
    {
        return DB::table('siaptxn_gaji_hdrs')->where('gaji_id', $gajiId)->value('gaji_status') === self::STATUS_DRAFT;
    }

    /** Ubah hari kerja slip → baris harian (makan, transport) dihitung ulang = hari × tarif. */
    public static function ubahHariKerja(int $gajiId, int $hariKerja): bool
    {
        if ($hariKerja < 0 || $hariKerja > 31 || !self::bisaDiubah($gajiId)) {
            return false;
        }

        DB::table('siaptxn_gaji_hdrs')->where('gaji_id', $gajiId)->update(['hari_hadir' => $hariKerja, 'updated_at' => now()]);
        $barisHarian = DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->whereIn('kode', array_values(self::PENDAPATAN_HARIAN))->get();
        foreach ($barisHarian as $row) {
            DB::table('siaptxn_gaji_dtls')->where('dtl_id', $row->dtl_id)->update([
                'jumlah'       => $hariKerja,
                'nilai'        => round($hariKerja * (float) $row->tarif, 2),
                'keterangan'   => "{$hariKerja} hari kerja",
                'nilai_manual' => '0',
                'updated_at'   => now(),
            ]);
        }
        self::hitungUlang($gajiId);

        return true;
    }

    /** Ubah nilai satu baris. Baris otomatis jadi "ditimpa" → dipertahankan saat generate ulang. */
    public static function ubahNilai(int $dtlId, float $nilai): bool
    {
        $baris = DB::table('siaptxn_gaji_dtls')->where('dtl_id', $dtlId)->first();
        if (!$baris || !self::bisaDiubah((int) $baris->gaji_id)) {
            return false;
        }

        $nilai = match (true) {
            $baris->jenis === 'P'        => -abs($nilai),
            $baris->kode === 'KOREKSI'   => $nilai,
            default                      => abs($nilai),
        };
        DB::table('siaptxn_gaji_dtls')->where('dtl_id', $dtlId)->update([
            'nilai'        => round($nilai, 2),
            'nilai_manual' => $baris->otomatis === '1' ? '1' : '0',
            'updated_at'   => now(),
        ]);
        self::hitungUlang((int) $baris->gaji_id);

        return true;
    }

    /**
     * Hapus baris yang diisi petugas, atau kembalikan baris otomatis yang ditimpa ke nilai parameter
     * (barisnya dihapus lalu slip disusun ulang untuk karyawan itu). Baris otomatis yang tidak
     * ditimpa tidak bisa dihapus — akan muncul lagi saat generate; ubah nilainya ke 0 bila perlu.
     */
    public static function hapusBaris(int $dtlId, ?int $userId): bool
    {
        $baris = DB::table('siaptxn_gaji_dtls')->where('dtl_id', $dtlId)->first();
        if (!$baris || !self::bisaDiubah((int) $baris->gaji_id) || ($baris->otomatis === '1' && $baris->nilai_manual !== '1')) {
            return false;
        }

        DB::table('siaptxn_gaji_dtls')->where('dtl_id', $dtlId)->delete();
        if ($baris->otomatis === '1') {
            $slip = DB::table('siaptxn_gaji_hdrs')->where('gaji_id', $baris->gaji_id)->first(['emp_id', 'periode']);
            self::generate($slip->periode, null, $userId, $slip->emp_id);
        } else {
            self::hitungUlang((int) $baris->gaji_id);
        }

        return true;
    }

    public static function ubahStatus(int $gajiId, string $status, ?int $userId): bool
    {
        if (!isset(self::STATUS[$status])) {
            return false;
        }

        return DB::table('siaptxn_gaji_hdrs')->where('gaji_id', $gajiId)->update([
            'gaji_status'     => $status,
            'difinalkan_oleh' => $status === self::STATUS_FINAL ? $userId : null,
            'difinalkan_at'   => $status === self::STATUS_FINAL ? now() : null,
            'updated_by'      => $userId,
            'updated_at'      => now(),
        ]) > 0;
    }

    /** Slip lengkap untuk layar & cetak: header (+ nama, unit, rekening) dan detail urut. */
    public static function slip(int $gajiId): ?array
    {
        $header = DB::table('siaptxn_gaji_hdrs as gh')
            ->join('siapmst_employers as em', 'em.emp_id', '=', 'gh.emp_id')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'gh.unit_id')
            ->leftJoin('siapmst_gaji_karyawans as gk', 'gk.emp_id', '=', 'gh.emp_id')
            ->where('gh.gaji_id', $gajiId)
            ->select('gh.*', 'em.emp_name', 'em.emp_phone', 'un.unit_name', 'gk.nama_bank', 'gk.no_rekening')
            ->first();
        if (!$header) {
            return null;
        }

        return ['header' => $header, 'detail' => self::detail($gajiId)];
    }

    /** @return Collection<int, object> */
    public static function detail(int $gajiId): Collection
    {
        return DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->orderBy('urutan')->orderBy('dtl_id')->get();
    }

    /** Kertas seukuran satu potongan x-pdf.layout-a4-bagi-4 (105 × 148 mm, satuan pt) — slip lewat link WA. */
    private const KERTAS_POTONGAN = [0, 0, 297.64, 419.53];

    /**
     * PDF slip — dipakai cetak di layar & link WA.
     * $potongan = false: 4 slip per lembar A4 (dipotong); true: satu slip per halaman seukuran potongan (dibuka di HP).
     */
    public static function pdf(array $slipList, bool $potongan = false)
    {
        return Pdf::loadView(self::PRINT_VIEW, ['slipList' => $slipList, 'potongan' => $potongan])
            ->setPaper($potongan ? self::KERTAS_POTONGAN : 'A4');
    }

    // ─── Kirim lewat WhatsApp (wa.me, klik-kirim oleh petugas) ────────────────

    /** No. HP → format wa.me (62…); null bila kosong / tidak masuk akal. */
    public static function nomorWa(?string $telepon): ?string
    {
        $angka = preg_replace('/\D/', '', (string) $telepon);
        $angka = match (true) {
            str_starts_with($angka, '62') => $angka,
            str_starts_with($angka, '0')  => '62' . substr($angka, 1),
            str_starts_with($angka, '8')  => '62' . $angka,
            default                       => '',
        };

        return strlen($angka) >= 10 && strlen($angka) <= 15 ? $angka : null;
    }

    /**
     * Link PDF slip tanpa login — bertanda tangan (URL::temporarySignedRoute) & kedaluwarsa.
     * Hanya membuka slip ini; berhenti berlaku bila slip dibuka kembali ke Draft (dicek di controller).
     */
    public static function linkSlip(int $gajiId): string
    {
        return URL::temporarySignedRoute('keuangan.slip-gaji.unduh', now()->addDays(self::MASA_LINK_SLIP_HARI), ['gajiId' => $gajiId]);
    }

    /** URL wa.me berisi ringkasan + link PDF; null bila slip belum Final atau No. HP tidak valid. */
    public static function tautanWa(object $header): ?string
    {
        $nomor = self::nomorWa($header->emp_phone ?? null);
        if (!$nomor || $header->gaji_status !== self::STATUS_FINAL) {
            return null;
        }

        $bulan = Carbon::createFromFormat('!Y-m', $header->periode);
        $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$bulan->month];
        $berlaku = now()->addDays(self::MASA_LINK_SLIP_HARI)->format('d/m/Y');

        $pesan = implode("\n", [
            "Assalamu'alaikum {$header->emp_name},",
            '',
            "Berikut slip gaji bulan kerja *{$namaBulan} {$bulan->year}* — " . Identitas::namaPendek() . ':',
            'Pendapatan: Rp ' . self::rupiah($header->total_pendapatan),
            'Potongan: Rp ' . self::rupiah(abs((float) $header->total_potongan)),
            '*Gaji diterima: Rp ' . self::rupiah($header->gaji_diterima) . '*',
            '',
            "Unduh slip (PDF, berlaku s/d {$berlaku}):",
            self::linkSlip((int) $header->gaji_id),
            '',
            'Slip ini rahasia, mohon tidak diteruskan. Bila ada yang tidak sesuai, hubungi bagian keuangan.',
        ]);

        return 'https://wa.me/' . $nomor . '?text=' . rawurlencode($pesan);
    }

    public static function tandaiWa(int $gajiId): void
    {
        DB::table('siaptxn_gaji_hdrs')->where('gaji_id', $gajiId)->update(['wa_dikirim_at' => now()]);
    }

    public static function rupiah(float|int|string|null $nilai): string
    {
        return number_format((float) $nilai, 0, ',', '.');
    }
}
