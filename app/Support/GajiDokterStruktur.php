<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Struktur gaji per dokter (tahap 1 pemindahan penggajian dokter ke SIAP) — salinan kolom gaji RSMST_DOCTORS SIRUS di
 * siapmst_gdok_strukturs, disetel di Gaji Dokter → Struktur Gaji dan (bila PengaturanGajiDokter::strukturDiSiap()) dikirim
 * ke SIRUS lewat PUT /api/siap/dokter/{dr_id}/struktur-gaji. Konstanta komponen & aturan berjenjang = salinan
 * App\Support\GajiDokter\GajiDokter di SIRUS supaya mesin hitung SIAP (GajiDokterHitung) memakai kosakata yang sama.
 */
class GajiDokterStruktur
{
    public const KOLOM = ['basic_salary', 'npwp_status', 'npwp', 'skema_gaji_pokok', 'potongan_rs_basis', 'potongan_rs_persen', 'potongan_rs_aturan',
        'pph21_persen', 'tarif_per_kapita_ri', 'tarif_per_kapita_rj', 'tunjangan_struktural', 'tunjangan_fungsional', 'tunjangan_hadir',
        'potongan_idi', 'potongan_arisan', 'potongan_koperasi', 'potongan_angsuran', 'potongan_bpjs', 'potongan_zariyah'];

    public const KOLOM_NOMINAL = ['basic_salary', 'potongan_rs_persen', 'pph21_persen', 'tarif_per_kapita_ri', 'tarif_per_kapita_rj',
        'tunjangan_struktural', 'tunjangan_fungsional', 'tunjangan_hadir',
        'potongan_idi', 'potongan_arisan', 'potongan_koperasi', 'potongan_angsuran', 'potongan_bpjs', 'potongan_zariyah'];

    public const SKEMA = ['A' => 'Aditif — jasa + gaji pokok', 'G' => 'Garanty fee — yang terbesar antara jasa dan gaji pokok', 'N' => 'Tanpa gaji pokok — murni jasa'];
    public const BASIS = ['T' => 'Dari total gaji (gaji pokok ikut dipotong)', 'J' => 'Dari jasa saja (gaji pokok bebas potongan)', 'N' => 'Tidak dipotong', 'B' => 'Berjenjang per komponen'];

    /** Grup komponen yang digantikan satu baris KAPITA bila tarif per kepala jalur itu > 0 (= GajiDokter::GRUP_KAPITA SIRUS). */
    public const GRUP_KAPITA = ['ri' => ['RI'], 'rj' => ['RJ']];

    /** Urutan komponen jasa mengikuti GROUP_SEQ view RSVIEW_NEWDOCSALARIES; nilai = grup (group_doc). */
    public const GRUP_KOMPONEN = [
        'UP RJ' => 'RJ', 'JD RJ' => 'RJ', 'UP RJTRF' => 'RJTRF', 'JD RJTRF' => 'RJTRF', 'UP UGD' => 'UGD', 'JD UGD' => 'UGD',
        'UP UGDTRF' => 'UGDTRF', 'JD UGDTRF' => 'UGDTRF', 'VISIT' => 'RI', 'KONSUL' => 'RI', 'JD RI' => 'RI', 'OPERATOR' => 'OK', 'ANASTESI' => 'OK',
        'UP KLINIK' => 'KLINIK', 'JD KLINIK' => 'KLINIK', 'RAD RJ' => 'RADRJ', 'RAD UGD' => 'RADUGD', 'RAD RI' => 'RADRI',
    ];

    public const KOMPONEN_JASA = ['UP RJ', 'JD RJ', 'UP RJTRF', 'JD RJTRF', 'UP UGD', 'JD UGD', 'UP UGDTRF', 'JD UGDTRF', 'VISIT', 'KONSUL', 'JD RI',
        'OPERATOR', 'ANASTESI', 'UP KLINIK', 'JD KLINIK', 'RAD RJ', 'RAD UGD', 'RAD RI'];

    public const KODE_POTONGAN = ['KASBON', 'IDI', 'ARISAN', 'KOPERASI', 'ANGSURAN', 'BPJS', 'ZARIYAH', 'LAIN'];
    public const KODE_TAMBAHAN = ['BONUS', 'RAPEL', 'KOREKSI', 'LAIN'];

    /** Potongan rutin di struktur → kode baris jenis P (NEGATIF di slip). */
    public const POTONGAN_RUTIN = ['potongan_idi' => 'IDI', 'potongan_arisan' => 'ARISAN', 'potongan_koperasi' => 'KOPERASI',
        'potongan_angsuran' => 'ANGSURAN', 'potongan_bpjs' => 'BPJS', 'potongan_zariyah' => 'ZARIYAH'];

    /** Tunjangan rutin di struktur → kode baris jenis J (KENA PAJAK). */
    public const TUNJANGAN_RUTIN = ['tunjangan_struktural' => 'TUNJ STRUKTURAL', 'tunjangan_fungsional' => 'TUNJ FUNGSIONAL', 'tunjangan_hadir' => 'TUNJ HADIR'];

    public const LABEL_KOMPONEN = [
        'UP RJ' => 'Uang Periksa Rawat Jalan', 'JD RJ' => 'Jasa Dokter Rawat Jalan', 'UP RJTRF' => 'Uang Periksa Rawat Jalan Transfer', 'JD RJTRF' => 'Jasa Dokter Rawat Jalan Transfer',
        'UP UGD' => 'Uang Periksa UGD', 'JD UGD' => 'Jasa Dokter UGD', 'UP UGDTRF' => 'Uang Periksa UGD Transfer', 'JD UGDTRF' => 'Jasa Dokter UGD Transfer',
        'VISIT' => 'Visite Rawat Inap', 'KONSUL' => 'Konsultasi Rawat Inap', 'JD RI' => 'Jasa Dokter Rawat Inap',
        'OPERATOR' => 'Operator Bedah', 'ANASTESI' => 'Anestesi', 'OPERATOR RJ' => 'Operator Bedah Rawat Jalan', 'ANASTESI RJ' => 'Anestesi Rawat Jalan',
        'OPERATOR UGD' => 'Operator Bedah UGD', 'ANASTESI UGD' => 'Anestesi UGD',
        'UP KLINIK' => 'Uang Periksa Klinik', 'JD KLINIK' => 'Jasa Dokter Klinik', 'RAD RJ' => 'Bacaan Radiologi Rawat Jalan', 'RAD UGD' => 'Bacaan Radiologi UGD', 'RAD RI' => 'Bacaan Radiologi Rawat Inap',
        'KAPITA RI' => 'Per Pasien Rawat Inap', 'KAPITA RJ' => 'Per Kunjungan Rawat Jalan',
        'TUNJ STRUKTURAL' => 'Tunjangan Struktural', 'TUNJ FUNGSIONAL' => 'Tunjangan Fungsional', 'TUNJ HADIR' => 'Tunjangan Kehadiran',
        'RS' => 'Potongan Rumah Sakit', 'PPH21' => 'PPh 21', 'KASBON' => 'Kasbon / Telah Diambil', 'IDI' => 'Iuran IDI', 'ARISAN' => 'Arisan', 'KOPERASI' => 'Koperasi',
        'ANGSURAN' => 'Angsuran', 'BPJS' => 'BPJS Kesehatan', 'ZARIYAH' => 'Zariyah',
        'BONUS' => 'Bonus', 'RAPEL' => 'Rapel', 'KOREKSI' => 'Koreksi Bulan Lalu', 'LAIN' => 'Lain-lain',
    ];

    public static function labelKomponen(string $kode): string
    {
        return self::LABEL_KOMPONEN[$kode] ?? $kode;
    }

    /** Nilai bawaan struktur (= DEFAULT kolom RSMST_DOCTORS) untuk dokter yang belum punya baris. */
    public static function bawaan(): array
    {
        return ['basic_salary' => 0.0, 'npwp_status' => 'Y', 'npwp' => null, 'skema_gaji_pokok' => 'A', 'potongan_rs_basis' => 'T', 'potongan_rs_persen' => 10.0,
            'potongan_rs_aturan' => null, 'pph21_persen' => 2.5, 'tarif_per_kapita_ri' => 0.0, 'tarif_per_kapita_rj' => 0.0,
            'tunjangan_struktural' => 0.0, 'tunjangan_fungsional' => 0.0, 'tunjangan_hadir' => 0.0,
            'potongan_idi' => 0.0, 'potongan_arisan' => 0.0, 'potongan_koperasi' => 0.0, 'potongan_angsuran' => 0.0, 'potongan_bpjs' => 0.0, 'potongan_zariyah' => 0.0];
    }

    /** Struktur satu dokter sebagai array bertipe (bawaan bila belum ada). */
    public static function ambil(string $drId): array
    {
        $row = DB::table('siapmst_gdok_strukturs')->where('dr_id', $drId)->first();

        return self::rapikan($row ? (array) $row : []);
    }

    /** Normalisasi tipe: nominal → float, teks → string, aturan → null bila bukan B. */
    public static function rapikan(array $data): array
    {
        $struktur = self::bawaan();
        foreach (self::KOLOM as $kolom) {
            if (!array_key_exists($kolom, $data) || $data[$kolom] === null) {
                continue;
            }
            $struktur[$kolom] = in_array($kolom, self::KOLOM_NOMINAL, true) ? (float) $data[$kolom] : (string) $data[$kolom];
        }
        $struktur['npwp_status'] = $struktur['npwp_status'] === 'N' ? 'N' : 'Y';
        $struktur['npwp'] = $struktur['npwp_status'] === 'N' ? null : (trim((string) $struktur['npwp']) ?: null);
        $struktur['potongan_rs_aturan'] = $struktur['potongan_rs_basis'] === 'B' ? (trim((string) $struktur['potongan_rs_aturan']) ?: null) : null;

        return $struktur;
    }

    /** Daftar dokter + strukturnya + pemetaan karyawan (untuk tab Struktur Gaji). */
    public static function daftar(string $cari = '', string $saring = 'aktif'): Collection
    {
        return DB::table('siapmst_dokter_sirus as d')
            ->leftJoin('siapmst_gdok_strukturs as s', 's.dr_id', '=', 'd.dr_id')
            ->leftJoin('siapmst_employers as em', 'em.emp_id', '=', 'd.emp_id')
            ->when(trim($cari) !== '', fn($q) => $q->whereRaw('UPPER(d.dr_name) LIKE ?', ['%' . mb_strtoupper(trim($cari)) . '%']))
            ->when($saring === 'aktif', fn($q) => $q->where('d.aktif', '1'))
            ->when($saring === 'belum', fn($q) => $q->where('d.aktif', '1')->whereNull('s.dr_id'))
            ->when($saring === 'gagal', fn($q) => $q->whereNotNull('s.sinkron_pesan'))
            ->orderByDesc('d.aktif')->orderBy('d.dr_name')
            ->get(['d.dr_id', 'd.dr_name', 'd.aktif', 'd.emp_id', 'em.emp_name', 's.basic_salary', 's.npwp_status', 's.skema_gaji_pokok', 's.potongan_rs_basis',
                's.potongan_rs_persen', 's.pph21_persen', 's.tarif_per_kapita_ri', 's.tarif_per_kapita_rj', 's.sumber', 's.disinkron_at', 's.sinkron_pesan', 's.updated_at',
                DB::raw('CASE WHEN s.dr_id IS NULL THEN 0 ELSE 1 END AS ada_struktur')]);
    }

    public static function aturanValidasi(): array
    {
        return ['basic_salary' => 'nullable|numeric|min:0', 'npwp_status' => 'required|in:Y,N', 'npwp' => 'nullable|string|max:30',
            'skema_gaji_pokok' => 'required|in:A,G,N', 'potongan_rs_basis' => 'required|in:T,J,N,B', 'potongan_rs_persen' => 'nullable|numeric|min:0|max:100',
            'potongan_rs_aturan' => 'nullable|string|max:1000', 'pph21_persen' => 'nullable|numeric|min:0|max:100',
            'tarif_per_kapita_ri' => 'nullable|numeric|min:0', 'tarif_per_kapita_rj' => 'nullable|numeric|min:0',
            'tunjangan_struktural' => 'nullable|numeric|min:0', 'tunjangan_fungsional' => 'nullable|numeric|min:0', 'tunjangan_hadir' => 'nullable|numeric|min:0',
            'potongan_idi' => 'nullable|numeric|min:0', 'potongan_arisan' => 'nullable|numeric|min:0', 'potongan_koperasi' => 'nullable|numeric|min:0',
            'potongan_angsuran' => 'nullable|numeric|min:0', 'potongan_bpjs' => 'nullable|numeric|min:0', 'potongan_zariyah' => 'nullable|numeric|min:0'];
    }

    public const LABEL_KOLOM = ['basic_salary' => 'Gaji Pokok', 'npwp_status' => 'Status NPWP', 'npwp' => 'Nomor NPWP', 'skema_gaji_pokok' => 'Skema Gaji Pokok',
        'potongan_rs_basis' => 'Basis Potongan RS', 'potongan_rs_persen' => 'Potongan RS (%)', 'potongan_rs_aturan' => 'Aturan Potongan Berjenjang', 'pph21_persen' => 'PPh 21 (%)',
        'tarif_per_kapita_ri' => 'Tarif per Pasien RI', 'tarif_per_kapita_rj' => 'Tarif per Pasien RJ', 'tunjangan_struktural' => 'Tunjangan Struktural',
        'tunjangan_fungsional' => 'Tunjangan Fungsional', 'tunjangan_hadir' => 'Tunjangan Kehadiran', 'potongan_idi' => 'Potongan IDI', 'potongan_arisan' => 'Potongan Arisan',
        'potongan_koperasi' => 'Potongan Koperasi', 'potongan_angsuran' => 'Potongan Angsuran', 'potongan_bpjs' => 'Potongan BPJS', 'potongan_zariyah' => 'Potongan Zariyah'];

    /**
     * Simpan struktur satu dokter (sumber SIAP). Bila sumber struktur = SIAP dan API aktif, langsung dikirim ke SIRUS;
     * gagal kirim TIDAK membatalkan simpan — dicatat di sinkron_pesan supaya bisa dikirim ulang.
     * @return array{ok: bool, pesan: string, sinkron: ?string}
     */
    public static function simpan(string $drId, array $data, ?int $userId): array
    {
        if (!DB::table('siapmst_dokter_sirus')->where('dr_id', $drId)->exists()) {
            return ['ok' => false, 'pesan' => 'Dokter tidak dikenal — Segarkan master dokter dari SIRUS dulu.', 'sinkron' => null];
        }
        $validator = Validator::make($data, self::aturanValidasi(), [
            '*.required' => ':attribute wajib diisi.', '*.numeric' => ':attribute harus berupa angka.', '*.min' => ':attribute tidak boleh kurang dari :min.',
            '*.max' => ':attribute maksimal :max.', '*.in' => ':attribute tidak valid.',
        ], self::LABEL_KOLOM);
        if ($validator->fails()) {
            return ['ok' => false, 'pesan' => $validator->errors()->first(), 'sinkron' => null];
        }
        $struktur = self::rapikan($validator->validated());
        if ($struktur['potongan_rs_basis'] === 'B') {
            $pesanAturan = self::validasiAturan($struktur['potongan_rs_aturan']);
            if ($pesanAturan !== null) {
                return ['ok' => false, 'pesan' => $pesanAturan, 'sinkron' => null];
            }
        }
        $kolom = [...$struktur, 'sumber' => 'SIAP', 'updated_by' => $userId, 'updated_at' => now()];
        DB::table('siapmst_gdok_strukturs')->where('dr_id', $drId)->exists()
            ? DB::table('siapmst_gdok_strukturs')->where('dr_id', $drId)->update($kolom)
            : DB::table('siapmst_gdok_strukturs')->insert([...$kolom, 'dr_id' => $drId, 'created_at' => now()]);

        $sinkron = null;
        if (PengaturanGajiDokter::strukturDiSiap() && GajiDokterSirus::aktif()) {
            $sinkron = self::kirimKeSirus($drId);
        }

        return ['ok' => true, 'pesan' => 'Struktur gaji tersimpan.', 'sinkron' => $sinkron];
    }

    /** Kirim struktur satu dokter ke SIRUS (PUT). @return string|null pesan galat (null = beres) */
    public static function kirimKeSirus(string $drId): ?string
    {
        $struktur = self::ambil($drId);
        try {
            GajiDokterSirus::put("dokter/{$drId}/struktur-gaji", $struktur);
            DB::table('siapmst_gdok_strukturs')->where('dr_id', $drId)->update(['disinkron_at' => now(), 'sinkron_pesan' => null, 'updated_at' => now()]);

            return null;
        } catch (\Throwable $exception) {
            DB::table('siapmst_gdok_strukturs')->where('dr_id', $drId)->update(['sinkron_pesan' => mb_substr($exception->getMessage(), 0, 200), 'updated_at' => now()]);

            return $exception->getMessage();
        }
    }

    /** Kirim ulang semua struktur ber-sumber SIAP (yang pernah gagal, atau semuanya). @return array{terkirim: int, gagal: array<int, string>} */
    public static function kirimSemuaKeSirus(bool $hanyaGagal = true): array
    {
        $hasilKirim = ['terkirim' => 0, 'gagal' => []];
        $drIdList = DB::table('siapmst_gdok_strukturs as s')->join('siapmst_dokter_sirus as d', 'd.dr_id', '=', 's.dr_id')
            ->where('s.sumber', 'SIAP')->when($hanyaGagal, fn($q) => $q->where(fn($w) => $w->whereNotNull('s.sinkron_pesan')->orWhereNull('s.disinkron_at')))
            ->orderBy('d.dr_name')->get(['s.dr_id', 'd.dr_name']);
        foreach ($drIdList as $row) {
            $galat = self::kirimKeSirus((string) $row->dr_id);
            $galat === null ? $hasilKirim['terkirim']++ : $hasilKirim['gagal'][] = "{$row->dr_name}: {$galat}";
        }

        return $hasilKirim;
    }

    /**
     * Tarik struktur semua dokter dari SIRUS lewat API (GET /dokter → field struktur). Saat SIRUS masih sumber: semua baris
     * ditimpa (sumber SIRUS). Saat SIAP sudah sumber: hanya dokter yang belum punya baris SIAP yang diisi — setelan SIAP tidak ditimpa.
     * @return array{ditarik: int, dilewati: int, dokterBaru: int, mode: array}
     */
    public static function tarikDariSirus(?int $userId): array
    {
        return self::terapkanTarikan(GajiDokterSirus::get('dokter'), $userId);
    }

    /**
     * Sama dengan tarikDariSirus, tetapi membaca tabel RSMST_DOCTORS SIRUS langsung lewat koneksi database SIAP (satu Oracle,
     * skema berbeda) — tanpa API. Dipakai saat SIRUS belum memasang endpoint struktur atau modul gaji dokter SIRUS sudah dimatikan.
     * Baris yang belum ada di-insert, yang sudah ada di-update (master dokter juga). Butuh user SIAP bisa membaca skema SIRUS.
     * @return array{ditarik: int, dilewati: int, dokterBaru: int, mode: array}
     */
    public static function salinDariDatabaseSirus(?int $userId): array
    {
        $rows = DB::table(self::tabelDokterSirus())->orderBy('dr_name')->get();
        $dokterList = $rows->map(fn($row) => [
            'dr_id' => (string) $row->dr_id, 'dr_name' => trim((string) $row->dr_name), 'dr_nik' => trim((string) ($row->dr_nik ?? '')),
            'kd_dr_bpjs' => trim((string) ($row->kd_dr_bpjs ?? '')), 'aktif' => trim((string) $row->active_status) === '1',
            'struktur' => self::strukturDariBarisSirus($row),
        ])->values()->all();

        return self::terapkanTarikan(['mode' => [], 'dokter' => $dokterList], $userId);
    }

    /** Apakah koneksi database SIAP bisa membaca tabel dokter SIRUS (grant sudah diberikan). Hasil diingat per permintaan. */
    public static function databaseSirusTersedia(): bool
    {
        static $tersedia = null;
        if ($tersedia === null) {
            try {
                DB::table(self::tabelDokterSirus())->select('dr_id')->limit(1)->get();
                $tersedia = true;
            } catch (\Throwable) {
                $tersedia = false;
            }
        }

        return $tersedia;
    }

    /** Nama tabel dokter SIRUS berawalan skema (SIAP_SIRUS_SCHEMA, bawaan SIRUS). */
    private static function tabelDokterSirus(): string
    {
        return strtolower((string) config('services.sirus.schema', 'SIRUS')) . '.rsmst_doctors';
    }

    /** Bentuk struktur dari satu baris RSMST_DOCTORS — sama dengan GajiDokterApiController::strukturDokter di SIRUS. */
    private static function strukturDariBarisSirus(object $row): array
    {
        $struktur = [];
        foreach (self::KOLOM as $kolom) {
            $struktur[$kolom] = in_array($kolom, self::KOLOM_NOMINAL, true) ? (float) ($row->{$kolom} ?? 0) : ($row->{$kolom} ?? null);
        }
        $struktur['npwp_status'] = ($row->npwp_status ?? 'Y') === 'N' ? 'N' : 'Y';
        $struktur['npwp'] = trim((string) ($row->npwp ?? '')) ?: null;
        $struktur['skema_gaji_pokok'] = (string) ($row->skema_gaji_pokok ?? 'A');
        $struktur['potongan_rs_basis'] = (string) ($row->potongan_rs_basis ?? 'T');
        $struktur['potongan_rs_aturan'] = ($row->potongan_rs_aturan ?? null) ?: null;

        return $struktur;
    }

    /**
     * Inti Tarik: master dokter disamakan dulu (dokter baru masuk, status aktif/nonaktif mengikuti SIRUS) supaya tidak ada
     * struktur yang terlewat, lalu struktur tiap dokter disalin dengan sumber SIRUS.
     * @param array{mode?: array, dokter?: array} $dataDokterSirus  bentuk yang sama dengan GET /dokter
     * @return array{ditarik: int, dilewati: int, dokterBaru: int, mode: array}
     */
    private static function terapkanTarikan(array $dataDokterSirus, ?int $userId): array
    {
        $hasilSegarkanDokterSirus = GajiDokterSirus::segarkanDokter($userId, $dataDokterSirus);
        $strukturDiSiap = PengaturanGajiDokter::strukturDiSiap();
        $adaSiap = DB::table('siapmst_gdok_strukturs')->where('sumber', 'SIAP')->pluck('dr_id')->flip();
        $dokterDikenal = DB::table('siapmst_dokter_sirus')->pluck('dr_id')->flip();
        $hasilTarik = ['ditarik' => 0, 'dilewati' => 0, 'dokterBaru' => $hasilSegarkanDokterSirus['baru'], 'mode' => (array) ($dataDokterSirus['mode'] ?? [])];
        foreach ($dataDokterSirus['dokter'] ?? [] as $dokter) {
            $drId = (string) $dokter['dr_id'];
            if (!isset($dokterDikenal[$drId]) || !isset($dokter['struktur'])) {
                $hasilTarik['dilewati']++;
                continue;
            }
            if ($strukturDiSiap && isset($adaSiap[$drId])) {
                $hasilTarik['dilewati']++;
                continue;
            }
            $kolom = [...self::rapikan((array) $dokter['struktur']), 'sumber' => 'SIRUS', 'updated_by' => $userId, 'updated_at' => now()];
            DB::table('siapmst_gdok_strukturs')->where('dr_id', $drId)->exists()
                ? DB::table('siapmst_gdok_strukturs')->where('dr_id', $drId)->update($kolom)
                : DB::table('siapmst_gdok_strukturs')->insert([...$kolom, 'dr_id' => $drId, 'created_at' => now()]);
            $hasilTarik['ditarik']++;
        }

        return $hasilTarik;
    }

    // ─── Aturan potongan berjenjang (= GajiDokter::parseAturan / susunAturan / validasiAturan SIRUS) ───────────────

    /** @return array<string, array{tipe: 'P'|'N', nilai: float}> angka telanjang = persen; "Rp…" = nominal */
    public static function parseAturan(?string $json): array
    {
        $data = json_decode(trim((string) $json) ?: '{}', true);
        if (!is_array($data)) {
            return [];
        }
        $aturan = [];
        foreach ($data as $kode => $nilai) {
            if (is_numeric($nilai)) {
                $aturan[$kode] = ['tipe' => 'P', 'nilai' => (float) $nilai];
                continue;
            }
            $teks = strtoupper(str_replace(' ', '', (string) $nilai));
            if (str_ends_with($teks, '%')) {
                $aturan[$kode] = ['tipe' => 'P', 'nilai' => (float) str_replace(',', '.', rtrim($teks, '%'))];
            } elseif (str_starts_with($teks, 'RP')) {
                $aturan[$kode] = ['tipe' => 'N', 'nilai' => (float) str_replace(['.', ','], '', substr($teks, 2))];
            }
        }

        return $aturan;
    }

    public static function susunAturan(array $items): string
    {
        $aturanTersusun = [];
        foreach ($items as $kode => $aturanItem) {
            $aturanTersusun[$kode] = ($aturanItem['tipe'] ?? 'P') === 'N' ? 'Rp' . (int) $aturanItem['nilai'] : round((float) $aturanItem['nilai'], 2) + 0;
        }

        return json_encode($aturanTersusun, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) ?: '{}';
    }

    /** @return string|null pesan galat */
    public static function validasiAturan(?string $json): ?string
    {
        $teks = trim((string) $json);
        if ($teks === '') {
            return 'Aturan berjenjang wajib diisi, mis. {"UP RJ":10} atau {"JD RJ":"Rp500000"}.';
        }
        $data = json_decode($teks, true);
        if (!is_array($data) || $data === []) {
            return 'Aturan berjenjang harus JSON objek, mis. {"UP RJ":10,"JD RJ":"Rp500000"}.';
        }
        foreach ($data as $kode => $nilai) {
            if (!in_array($kode, self::KOMPONEN_JASA, true)) {
                return "Komponen \"{$kode}\" tidak dikenal. Pilihan: " . implode(', ', self::KOMPONEN_JASA) . '.';
            }
            if (is_numeric($nilai)) {
                if ((float) $nilai < 0 || (float) $nilai > 100) {
                    return "Persen untuk {$kode} harus 0–100. Nominal rupiah ditulis dengan awalan Rp, mis. \"Rp500000\".";
                }
                continue;
            }
            $bersih = strtoupper(str_replace(' ', '', (string) $nilai));
            if (str_ends_with($bersih, '%')) {
                $angka = str_replace(',', '.', rtrim($bersih, '%'));
                if (!is_numeric($angka) || (float) $angka < 0 || (float) $angka > 100) {
                    return "Persen untuk {$kode} harus 0–100.";
                }
            } elseif (str_starts_with($bersih, 'RP')) {
                if (!is_numeric(str_replace(['.', ','], '', substr($bersih, 2)))) {
                    return "Nominal untuk {$kode} tidak valid — tulis mis. \"Rp500000\".";
                }
            } else {
                return "Nilai untuk {$kode} harus persen (mis. 10 atau \"7.5%\") atau nominal (\"Rp500000\").";
            }
        }

        return null;
    }
}
