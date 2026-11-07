<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gaji Dokter dari SIRUS — klien API + snapshot berversi per bulan jasa.
 *
 * API SIRUS hanya dibaca (JWT; akun = user SIRUS ber-role 'Api Siap', kredensialnya di .env: SIAP_API_URL / SIAP_API_USER /
 * SIAP_API_PASSWORD). Dua mesin (PengaturanGajiDokter::mesinSiap): mesin SIRUS = slip dihitung SIRUS, SIAP menarik hasilnya
 * sebagai snapshot (ambil); mesin SIAP = SIAP menarik transaksi mentah lalu menghitung sendiri dengan struktur gaji SIAP (prosesSiap).
 *
 * VERSI: tiap Ambil, slip tiap dokter diberi checksum (header + detail + lampiran). Sama → hanya diambil_at
 * diperbarui; beda → versi baru, versi lama tetap tersimpan (terbaru = '0'). Jadi angka yang pernah diproses
 * di SIAP tidak berubah diam-diam, dan perubahan di SIRUS terlihat sebagai perbandingan versi.
 *
 * PEMETAAN: dokter SIRUS (dr_id) ↔ karyawan SIAP (emp_id). Otomatis bila nama cocok persis (huruf saja, tanpa gelar),
 * selebihnya dipilih Keuangan di tab Pemetaan. Dokter tanpa pemetaan tetap diambil — anomali ditampilkan, bukan diblokir.
 */
class GajiDokterSirus
{
    public const LABEL_JENIS = ['J' => 'Jasa & tunjangan', 'P' => 'Potongan', 'T' => 'Tambahan'];

    /** Kolom nominal header yang dibandingkan antar versi / antar bulan. */
    public const KOLOM_NOMINAL = ['jasa_total', 'total_gaji', 'potongan_rs', 'pph21', 'potongan_lain_total', 'tambahan_total', 'gaji_diterima'];

    public const LABEL_NOMINAL = ['jasa_total' => 'Jasa', 'total_gaji' => 'Total gaji', 'potongan_rs' => 'Potongan RS', 'pph21' => 'PPh 21',
        'potongan_lain_total' => 'Potongan lain', 'tambahan_total' => 'Tambahan', 'gaji_diterima' => 'Diterima'];

    // ─── Klien API ────────────────────────────────────────────────────────────

    public static function aktif(): bool
    {
        return (string) config('services.sirus.url', '') !== '' && (string) config('services.sirus.user', '') !== '';
    }

    private static function token(bool $tokenSegar = false): string
    {
        if (!self::aktif()) {
            throw new RuntimeException('API SIRUS belum diatur (SIAP_API_URL / SIAP_API_USER / SIAP_API_PASSWORD di .env).');
        }
        if ($tokenSegar) {
            Cache::forget('sirus-api-token');
        }

        return Cache::remember('sirus-api-token', now()->addMinutes(45), function () {
            $respon = Http::acceptJson()->timeout(20)->post(rtrim(config('services.sirus.url'), '/') . '/api/siap/token', [
                'username' => config('services.sirus.user'), 'password' => config('services.sirus.password'),
            ]);
            if (!$respon->ok() || !$respon->json('token')) {
                throw new RuntimeException('Gagal login API SIRUS: ' . ($respon->json('error') ?? "HTTP {$respon->status()}"));
            }

            return (string) $respon->json('token');
        });
    }

    /** GET ber-Bearer; token kedaluwarsa (401) dicoba ulang sekali dengan token baru. API SIRUS hanya dibaca, tidak pernah ditulis. */
    public static function get(string $path, array $query = []): array
    {
        $url = rtrim(config('services.sirus.url'), '/') . '/api/siap/' . ltrim($path, '/');
        foreach ([false, true] as $tokenSegar) {
            $respon = Http::acceptJson()->timeout(120)->withToken(self::token($tokenSegar))->get($url, $query);
            if ($respon->status() === 401 && !$tokenSegar) {
                continue;
            }
            if (!$respon->ok()) {
                throw new RuntimeException("API SIRUS {$path}: " . ($respon->json('error') ?? "HTTP {$respon->status()}"));
            }

            return (array) $respon->json();
        }
        throw new RuntimeException("API SIRUS {$path}: token ditolak.");
    }

    /** Uji sambungan: login + jumlah dokter. @return array{ok: bool, pesan: string} */
    public static function ujiSambungan(): array
    {
        try {
            $dataDokter = self::get('dokter');

            return ['ok' => true, 'pesan' => 'Tersambung ke ' . config('services.sirus.url') . ' — ' . count($dataDokter['dokter'] ?? []) . ' dokter di master SIRUS.'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'pesan' => $exception->getMessage()];
        }
    }

    // ─── Pemetaan dokter ──────────────────────────────────────────────────────

    private static function normNama(?string $nama): string
    {
        return preg_replace('/[^A-Z]/', '', mb_strtoupper(preg_replace(['/,.*$/', '/\b(DR|DRG|H|HJ|NS|S|KEP|AMD|SKM|SP[A-Z.]*)\.?\s+/i'], '', (string) $nama)));
    }

    /**
     * Segarkan master dokter dari SIRUS (nama, NIK, kode BPJS, status aktif) + pemetaan otomatis (nama cocok persis & tunggal).
     * $dataDokterSirus boleh diberikan bila pemanggil sudah memegang hasil GET /dokter, supaya tidak memanggil API dua kali.
     * @return array{baru: int, oto: int}
     */
    public static function segarkanDokter(?int $userId, ?array $dataDokterSirus = null): array
    {
        $dataDokterSirus ??= self::get('dokter');
        $karyawan = DB::table('siapmst_employers')->where('active_status', '1')->get(['emp_id', 'emp_name'])->groupBy(fn($row) => self::normNama($row->emp_name));
        $dokterTersimpan = DB::table('siapmst_dokter_sirus')->get()->keyBy('dr_id');
        $hasilSegarkanDokterSirus = ['baru' => 0, 'oto' => 0];
        foreach ($dataDokterSirus['dokter'] ?? [] as $dokter) {
            $namaDokter = trim((string) $dokter['dr_name']) !== '' ? $dokter['dr_name'] : '(tanpa nama) ' . $dokter['dr_id'];
            $kolomDokter = ['dr_name' => mb_substr($namaDokter, 0, 100), 'dr_nik' => $dokter['dr_nik'] ?: null, 'kd_dr_bpjs' => $dokter['kd_dr_bpjs'] ?: null,
                'aktif' => $dokter['aktif'] ? '1' : '0', 'updated_at' => now()];
            $dokterLama = $dokterTersimpan[$dokter['dr_id']] ?? null;
            if ($dokterLama) {
                DB::table('siapmst_dokter_sirus')->where('dr_id', $dokter['dr_id'])->update($kolomDokter);
                if ($dokterLama->emp_id !== null) {
                    continue;
                }
            }
            $namaNormal = self::normNama($dokter['dr_name']);
            $karyawanCocok = $namaNormal !== '' ? ($karyawan[$namaNormal] ?? null) : null;
            $empId = $karyawanCocok && $karyawanCocok->count() === 1 ? $karyawanCocok->first()->emp_id : null;
            if ($dokterLama) {
                if ($empId) {
                    DB::table('siapmst_dokter_sirus')->where('dr_id', $dokter['dr_id'])->update(['emp_id' => $empId, 'cara_map' => 'OTO', 'updated_by' => $userId]);
                    $hasilSegarkanDokterSirus['oto']++;
                }
            } else {
                DB::table('siapmst_dokter_sirus')->insert([...$kolomDokter, 'dr_id' => $dokter['dr_id'], 'emp_id' => $empId, 'cara_map' => $empId ? 'OTO' : null, 'updated_by' => $userId, 'created_at' => now()]);
                $hasilSegarkanDokterSirus['baru']++;
                if ($empId) {
                    $hasilSegarkanDokterSirus['oto']++;
                }
            }
        }

        return $hasilSegarkanDokterSirus;
    }

    public static function petakan(string $drId, ?string $empId, ?int $userId): bool
    {
        if ($empId !== null && !DB::table('siapmst_employers')->where('emp_id', $empId)->exists()) {
            return false;
        }

        return DB::table('siapmst_dokter_sirus')->where('dr_id', $drId)
            ->update(['emp_id' => $empId, 'cara_map' => $empId ? 'MANUAL' : null, 'updated_by' => $userId, 'updated_at' => now()]) > 0;
    }

    /** @return Collection<int, object> master dokter + nama karyawan terpetakan */
    public static function dokterList(string $cari = ''): Collection
    {
        return DB::table('siapmst_dokter_sirus as d')->leftJoin('siapmst_employers as em', 'em.emp_id', '=', 'd.emp_id')
            ->when(trim($cari) !== '', fn($subQuery) => $subQuery->whereRaw('UPPER(d.dr_name) LIKE ?', ['%' . mb_strtoupper(trim($cari)) . '%']))
            ->orderByDesc('d.aktif')->orderBy('d.dr_name')
            ->get(['d.*', 'em.emp_name', 'em.active_status as emp_aktif']);
    }

    // ─── Ambil snapshot ───────────────────────────────────────────────────────

    public static function periodeValid(?string $periode): bool
    {
        return is_string($periode) && (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode);
    }

    /**
     * Tarik slip + lampiran satu bulan jasa dari SIRUS dan simpan berversi.
     * @return array{jumlah: int, baru: int, berubah: int, sama: int, lampiran: int, tanpaMap: array<int, string>}
     */
    public static function ambil(string $periode, ?int $userId): array
    {
        if (!self::periodeValid($periode)) {
            throw new RuntimeException('Periode tidak valid.');
        }
        [$tahun, $bulan] = explode('-', $periode);
        self::segarkanDokter($userId);
        $slipData = self::get("gaji-dokter/{$tahun}/{$bulan}");
        $lampiranData = self::get("gaji-dokter/{$tahun}/{$bulan}/lampiran");
        $lampiranPerDokter = collect($lampiranData['lampiran'] ?? [])->keyBy('dr_id');
        $empIdPerDokter = DB::table('siapmst_dokter_sirus')->pluck('emp_id', 'dr_id');
        $hasilAmbilGajiSirus = ['jumlah' => 0, 'baru' => 0, 'berubah' => 0, 'sama' => 0, 'lampiran' => 0, 'tanpaMap' => []];

        foreach ($slipData['slip'] ?? [] as $slipSirus) {
            $hasilAmbilGajiSirus['jumlah']++;
            $lampiranSirus = $lampiranPerDokter[$slipSirus['dr_id']]['baris'] ?? [];
            $checksum = self::checksum($slipSirus, $lampiranSirus);
            $empId = $empIdPerDokter[$slipSirus['dr_id']] ?? null;
            if ($empId === null) {
                $hasilAmbilGajiSirus['tanpaMap'][] = $slipSirus['dr_name'];
            }
            $snapshotTerbaru = DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dr_id', $slipSirus['dr_id'])->where('terbaru', '1')->first();
            if ($snapshotTerbaru && $snapshotTerbaru->checksum === $checksum) {
                DB::table('siaptxn_gdok_hdrs')->where('gd_id', $snapshotTerbaru->gd_id)->update(['diambil_at' => now(), 'diambil_oleh' => $userId, 'emp_id' => $empId, 'updated_at' => now()]);
                $hasilAmbilGajiSirus['sama']++;
                continue;
            }
            DB::transaction(function () use ($periode, $slipSirus, $lampiranSirus, $checksum, $empId, $snapshotTerbaru, $userId, &$hasilAmbilGajiSirus) {
                if ($snapshotTerbaru) {
                    DB::table('siaptxn_gdok_hdrs')->where('gd_id', $snapshotTerbaru->gd_id)->update(['terbaru' => '0', 'updated_at' => now()]);
                }
                $versi = $snapshotTerbaru ? (int) $snapshotTerbaru->versi + 1 : 1;
                DB::table('siaptxn_gdok_hdrs')->insert([
                    'periode' => $periode, 'dr_id' => $slipSirus['dr_id'], 'versi' => $versi, 'terbaru' => '1', 'emp_id' => $empId,
                    'gajidoctor_no' => (int) $slipSirus['gajidoctor_no'], 'dr_name' => mb_substr(trim((string) $slipSirus['dr_name']) !== '' ? $slipSirus['dr_name'] : '(tanpa nama) ' . $slipSirus['dr_id'], 0, 100),
                    'periode_gaji' => $slipSirus['tahun_gaji'] . '-' . $slipSirus['bulan_gaji'],
                    'skema_gaji_pokok' => $slipSirus['skema_gaji_pokok'], 'nilai_gaji_pokok' => $slipSirus['nilai_gaji_pokok'],
                    'potongan_rs_basis' => $slipSirus['potongan_rs_basis'], 'potongan_rs_persen' => $slipSirus['potongan_rs_persen'],
                    'pph21_persen' => $slipSirus['pph21_persen'], 'npwp_status' => $slipSirus['npwp_status'],
                    'jasa_total' => $slipSirus['jasa_total'], 'total_gaji' => $slipSirus['total_gaji'], 'potongan_rs' => $slipSirus['potongan_rs'], 'pph21' => $slipSirus['pph21'],
                    'potongan_lain_total' => $slipSirus['potongan_lain_total'], 'tambahan_total' => $slipSirus['tambahan_total'], 'gaji_diterima' => $slipSirus['gaji_diterima'],
                    'gaji_status' => $slipSirus['gaji_status'],
                    'tanggal_bayar' => $slipSirus['tanggal_bayar'] ? Absensi::tanggalSql(Carbon::parse($slipSirus['tanggal_bayar'])) : null,
                    'update_date_sirus' => $slipSirus['update_date'], 'checksum' => $checksum, 'jumlah_lampiran' => count($lampiranSirus), 'sumber' => 'SIRUS',
                    'diambil_at' => now(), 'diambil_oleh' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $gdId = (int) DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dr_id', $slipSirus['dr_id'])->where('versi', $versi)->value('gd_id');
                foreach ($slipSirus['detail'] as $komponen) {
                    DB::table('siaptxn_gdok_dtls')->insert(['gd_id' => $gdId, 'jenis' => $komponen['jenis'], 'kode' => mb_substr($komponen['kode'], 0, 20), 'label' => mb_substr($komponen['label'], 0, 100),
                        'keterangan' => mb_substr($komponen['keterangan'], 0, 100) ?: null, 'nilai' => $komponen['nilai'], 'nilai_manual' => $komponen['nilai_manual'] ? '1' : '0',
                        'jumlah_pasien' => $komponen['jumlah_pasien'], 'urutan' => $komponen['urutan']]);
                }
                foreach (array_chunk($lampiranSirus, 200) as $potonganLampiran) {
                    DB::table('siaptxn_gdok_lamps')->insert(array_map(fn($row) => [
                        'gd_id' => $gdId, 'komponen' => mb_substr($row['komponen'], 0, 20), 'label' => mb_substr($row['label'], 0, 100), 'tgl' => mb_substr((string) $row['tgl'], 0, 10),
                        'reg_no' => mb_substr((string) $row['reg_no'], 0, 20) ?: null, 'nama' => mb_substr((string) $row['nama'], 0, 100) ?: null,
                        'keterangan' => mb_substr((string) $row['keterangan'], 0, 200) ?: null, 'klaim' => mb_substr((string) $row['klaim'], 0, 60) ?: null,
                        'klaim_status' => mb_substr((string) $row['klaim_status'], 0, 20) ?: null, 'no_sep' => mb_substr((string) $row['no_sep'], 0, 30) ?: null,
                        'txn_no' => mb_substr((string) $row['txn_no'], 0, 30) ?: null, 'nominal' => $row['nominal'], 'pasien' => $row['pasien'],
                        'masuk_slip' => $row['masuk_slip'] ? '1' : '0', 'grup' => mb_substr((string) ($row['group'] ?? ''), 0, 10) ?: null,
                    ], $potonganLampiran));
                }
                $hasilAmbilGajiSirus['lampiran'] += count($lampiranSirus);
                $snapshotTerbaru ? $hasilAmbilGajiSirus['berubah']++ : $hasilAmbilGajiSirus['baru']++;
            });
        }

        DB::table('siaptxn_gdok_ambils')->insert(['periode' => $periode, 'diambil_at' => now(), 'diambil_oleh' => $userId, 'jumlah_slip' => $hasilAmbilGajiSirus['jumlah'],
            'baru' => $hasilAmbilGajiSirus['baru'], 'berubah' => $hasilAmbilGajiSirus['berubah'], 'sama' => $hasilAmbilGajiSirus['sama'], 'lampiran_baris' => $hasilAmbilGajiSirus['lampiran'],
            'keterangan' => $hasilAmbilGajiSirus['tanpaMap'] !== [] ? count($hasilAmbilGajiSirus['tanpaMap']) . ' dokter belum dipetakan' : null, 'created_at' => now(), 'updated_at' => now()]);

        return $hasilAmbilGajiSirus;
    }

    private static function checksum(array $slipSirus, array $lampiranSirus): string
    {
        $kolomInti = ['gaji_status', 'skema_gaji_pokok', 'nilai_gaji_pokok', 'potongan_rs_basis', 'potongan_rs_persen', 'pph21_persen', 'npwp_status', ...self::KOLOM_NOMINAL];
        $angkaHeader = array_map(fn($kolomNominal) => is_float($slipSirus[$kolomNominal] ?? null) ? round($slipSirus[$kolomNominal], 2) : ($slipSirus[$kolomNominal] ?? null), array_combine($kolomInti, $kolomInti));
        $detailInti = array_map(fn($komponen) => [$komponen['jenis'], $komponen['kode'], round((float) $komponen['nilai'], 2), (int) $komponen['jumlah_pasien'], (bool) $komponen['nilai_manual']], $slipSirus['detail']);
        usort($detailInti, fn($kiri, $kanan) => strcmp($kiri[0] . $kiri[1], $kanan[0] . $kanan[1]));
        $lampiranInti = array_map(fn($row) => [$row['komponen'], $row['txn_no'], round((float) $row['nominal'], 2)], $lampiranSirus);
        usort($lampiranInti, fn($kiri, $kanan) => strcmp($kiri[0] . $kiri[1], $kanan[0] . $kanan[1]));

        return md5(json_encode([$angkaHeader, $detailInti, $lampiranInti]));
    }

    // ─── Baca snapshot ────────────────────────────────────────────────────────

    /** Header versi terbaru satu bulan jasa + pemetaan + penanda perubahan. @return Collection<int, object> */
    public static function daftar(string $periode, string $cari = '', string $saring = 'semua'): Collection
    {
        $rows = DB::table('siaptxn_gdok_hdrs as h')
            ->leftJoin('siapmst_dokter_sirus as d', 'd.dr_id', '=', 'h.dr_id')
            ->leftJoin('siapmst_employers as em', 'em.emp_id', '=', 'd.emp_id')
            ->where('h.periode', $periode)->where('h.terbaru', '1')
            ->when(trim($cari) !== '', fn($subQuery) => $subQuery->whereRaw('UPPER(h.dr_name) LIKE ?', ['%' . mb_strtoupper(trim($cari)) . '%']))
            ->orderBy('h.dr_name')
            ->get(['h.*', 'd.emp_id as emp_id_map', 'd.cara_map', 'em.emp_name']);
        $bulanLalu = Carbon::createFromFormat('!Y-m', $periode)->subMonthNoOverflow()->format('Y-m');
        $headerBulanLalu = DB::table('siaptxn_gdok_hdrs')->where('periode', $bulanLalu)->where('terbaru', '1')->get()->keyBy('dr_id');
        // Versi yang pernah ditulis ke slip gaji (bisa versi lama) — penanda "slip memakai v1, snapshot sudah v2".
        $diterapkan = DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->whereNotNull('diterapkan_at')
            ->orderBy('versi')->get(['dr_id', 'versi', 'gaji_id', 'diterapkan_at'])->keyBy('dr_id');

        $rows = $rows->map(function ($row) use ($headerBulanLalu, $diterapkan) {
            $row->versi_sebelumnya = (int) $row->versi > 1;
            $row->bulan_lalu = $headerBulanLalu[$row->dr_id] ?? null;
            $row->selisih_bulan_lalu = $row->bulan_lalu ? (float) $row->gaji_diterima - (float) $row->bulan_lalu->gaji_diterima : null;
            $terapkanTerakhir = $diterapkan[$row->dr_id] ?? null;
            $row->diterapkan_versi = $terapkanTerakhir ? (int) $terapkanTerakhir->versi : null;
            $row->diterapkan_gaji_id = $terapkanTerakhir ? (int) $terapkanTerakhir->gaji_id : null;
            $row->diterapkan_at_slip = $terapkanTerakhir?->diterapkan_at;

            return $row;
        });

        return match ($saring) {
            'berubah'   => $rows->filter(fn($row) => $row->versi_sebelumnya)->values(),
            'tanpa_map' => $rows->filter(fn($row) => $row->emp_id_map === null)->values(),
            'final'     => $rows->where('gaji_status', 'F')->values(),
            default     => $rows,
        };
    }

    // ─── Terapkan ke slip Gaji Karyawan ───────────────────────────────────────

    /**
     * Tulis snapshot versi terbaru tiap dokter TERPETAKAN ke slip Draft Gaji Karyawan periode BULAN KERJA = bulan jasa.
     * (Slip SIAP bulan kerja Oktober dibayar awal November = "jasa Oktober, gaji November" di SIRUS — periode yang sama,
     * beda sebutan.) Pola JasaMedik::terapkan: AMAN DIULANG — baris berkode KODE_DOKTER_SIRUS dihapus lalu ditulis
     * ulang dari snapshot terbaru, slip dihitung ulang; slip Final dilewati; dokter tanpa slip / tanpa pemetaan dilaporkan.
     * Baris ditulis dengan otomatis = '0' (seperti Jasa Medik) supaya Generate ulang slip tidak menghapusnya.
     * Yang ditulis = angka SIRUS apa adanya, PER KOMPONEN slip (uang periksa, visite, operator, …; gaji pokok dokter bila ada;
     * tambahan) sebagai pendapatan; Potongan RS, PPh 21 SIRUS, potongan lain (IDI, BPJS, …) sebagai potongan
     * → diterima di SIAP = diterima di SIRUS untuk bagian dokter. Baris ini DIKUNCI di Rincian slip karyawan.
     * Bekas terapkan pada karyawan yang kini tidak lagi punya snapshot/pemetaan di periode itu dibersihkan.
     * Dokter terpetakan yang BELUM punya slip bulan itu dibuatkan slip Draft kosong (pokok/tunjangan 0, PPh 0) — Generate
     * melewati karyawan tanpa parameter gaji, dan dokter spesialis umumnya memang hanya digaji lewat jasa SIRUS. Bila
     * kelak parameternya diisi, Generate memperbarui slip itu tanpa menghapus baris dokter. Karyawan nonaktif dilaporkan.
     * @return array{diperbarui: int, dibuatkan: int, dibersihkan: int, periodeGaji: string, final: array<int, string>, tanpaSlip: array<int, string>, tanpaMap: array<int, string>}
     */
    public static function terapkan(string $periode, ?int $userId): array
    {
        $rows = self::daftar($periode);
        $periodeGaji = $periode; // bulan kerja slip SIAP
        $hasilTerapkanKeSlipGaji = ['diperbarui' => 0, 'dibuatkan' => 0, 'dibersihkan' => 0, 'periodeGaji' => $periodeGaji, 'final' => [], 'tanpaSlip' => [], 'tanpaMap' => []];
        $labelBulanJasa = self::namaPeriode($periode);

        $terpetakan = $rows->filter(fn($row) => $row->emp_id_map !== null);
        $hasilTerapkanKeSlipGaji['tanpaMap'] = $rows->filter(fn($row) => $row->emp_id_map === null)->pluck('dr_name')->values()->all();
        $slipList = DB::table('siaptxn_gaji_hdrs')->where('periode', $periodeGaji)
            ->whereIn('emp_id', $terpetakan->pluck('emp_id_map')->map(fn($empId) => (string) $empId)->all() ?: [''])
            ->get(['gaji_id', 'emp_id', 'gaji_status'])->keyBy('emp_id');
        $karyawanList = DB::table('siapmst_employers')->whereIn('emp_id', $terpetakan->pluck('emp_id_map')->map(fn($empId) => (string) $empId)->all() ?: [''])
            ->get(['emp_id', 'emp_unit_id', 'active_status'])->keyBy('emp_id');
        $gajiIdDipakai = [];

        foreach ($terpetakan as $row) {
            $slip = $slipList[$row->emp_id_map] ?? null;
            if (!$slip) {
                $karyawan = $karyawanList[$row->emp_id_map] ?? null;
                if (!$karyawan || $karyawan->active_status !== '1') {
                    $hasilTerapkanKeSlipGaji['tanpaSlip'][] = $row->dr_name;
                    continue;
                }
                DB::table('siaptxn_gaji_hdrs')->insert(['emp_id' => $row->emp_id_map, 'periode' => $periodeGaji, 'unit_id' => $karyawan->emp_unit_id,
                    'hari_hadir' => 0, 'gaji_pokok' => 0, 'tarif_makan' => 0, 'tarif_transport' => 0, 'pph21_persen' => 0,
                    'gaji_status' => GajiKaryawan::STATUS_DRAFT, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
                $slip = DB::table('siaptxn_gaji_hdrs')->where('emp_id', $row->emp_id_map)->where('periode', $periodeGaji)->first(['gaji_id', 'emp_id', 'gaji_status']);
                $hasilTerapkanKeSlipGaji['dibuatkan']++;
            }
            $gajiIdDipakai[] = (int) $slip->gaji_id;
            if ($slip->gaji_status === GajiKaryawan::STATUS_FINAL) {
                $hasilTerapkanKeSlipGaji['final'][] = $row->dr_name;
                continue;
            }
            $gajiId = (int) $slip->gaji_id;
            $keteranganSumber = "SIRUS #{$row->gajidoctor_no} v{$row->versi} · jasa {$labelBulanJasa}";
            // Per KOMPONEN slip SIRUS (uang periksa, visite, operator, …), bukan satu angka gabungan — rinciannya tetap terbaca di slip
            // karyawan. Jumlahnya sama dengan header: Σ jenis J = jasa_total, Σ P = potongan RS + PPh + lain, Σ T = tambahan.
            $detail = self::detail((int) $row->gd_id);
            DB::transaction(function () use ($gajiId, $row, $keteranganSumber, $userId, $periode, $detail) {
                DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->whereIn('kode', GajiKaryawan::KODE_DOKTER_SIRUS)->delete();
                $barisSlip = [];
                foreach ($detail as $komponen) {
                    $namaKomponen = $komponen->label ?: $komponen->kode;
                    $keteranganPasien = (int) $komponen->jumlah_pasien > 0 ? " · {$komponen->jumlah_pasien} pasien" : '';
                    $barisSlip[] = match (true) {
                        $komponen->jenis === 'J'                     => ['D', 'JASA_DOKTER', (float) $komponen->nilai, "{$namaKomponen}{$keteranganPasien} · {$keteranganSumber}", (int) $komponen->jumlah_pasien],
                        $komponen->jenis === 'T'                     => ['D', 'TAMBAHAN_DOKTER', (float) $komponen->nilai, "{$namaKomponen} · {$keteranganSumber}", 0],
                        $komponen->jenis === 'P' && $komponen->kode === 'RS'    => ['P', 'POT_RS', (float) $komponen->nilai, "{$namaKomponen} · {$keteranganSumber}", 0],
                        $komponen->jenis === 'P' && $komponen->kode === 'PPH21' => ['P', 'PPH21_DOKTER', (float) $komponen->nilai, "{$namaKomponen} " . rtrim(rtrim(number_format((float) $row->pph21_persen, 2, ',', '.'), '0'), ',') . '% ' . ($row->npwp_status === 'Y' ? 'NPWP' : 'tanpa NPWP') . " · {$keteranganSumber}", 0],
                        default                               => ['P', 'POT_DOKTER', (float) $komponen->nilai, "{$namaKomponen} · {$keteranganSumber}", 0],
                    };
                }
                if ((float) $row->nilai_gaji_pokok > 0) {
                    $barisSlip[] = ['D', 'POKOK_DOKTER', (float) $row->nilai_gaji_pokok, "Gaji pokok dokter skema {$row->skema_gaji_pokok} · {$keteranganSumber}", 0];
                }
                foreach ($barisSlip as [$jenis, $kode, $nilai, $keterangan, $jumlah]) {
                    if (abs($nilai) >= 0.5) {
                        GajiKaryawan::simpanBaris($gajiId, ['jenis' => $jenis, 'kode' => $kode, 'nilai' => $nilai, 'jumlah' => $jumlah, 'otomatis' => '0', 'keterangan' => mb_substr($keterangan, 0, 100)]);
                    }
                }
                DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dr_id', $row->dr_id)
                    ->update(['gaji_id' => null, 'diterapkan_at' => null, 'diterapkan_oleh' => null]);
                DB::table('siaptxn_gdok_hdrs')->where('gd_id', $row->gd_id)
                    ->update(['gaji_id' => $gajiId, 'diterapkan_at' => now(), 'diterapkan_oleh' => $userId, 'updated_at' => now()]);
            });
            GajiKaryawan::hitungUlang($gajiId);
            $hasilTerapkanKeSlipGaji['diperbarui']++;
        }

        // Bekas terapkan pada slip Draft periode gaji ini yang tidak lagi punya dokter terpetakan (pemetaan dilepas / dokter hilang).
        $gajiIdBekasTerapkan = DB::table('siaptxn_gaji_dtls as d')->join('siaptxn_gaji_hdrs as h', 'h.gaji_id', '=', 'd.gaji_id')
            ->where('h.periode', $periodeGaji)->where('h.gaji_status', GajiKaryawan::STATUS_DRAFT)
            ->whereIn('d.kode', GajiKaryawan::KODE_DOKTER_SIRUS)
            ->when($gajiIdDipakai !== [], fn($subQuery) => $subQuery->whereNotIn('h.gaji_id', $gajiIdDipakai))
            ->distinct()->pluck('h.gaji_id');
        foreach ($gajiIdBekasTerapkan as $gajiId) {
            DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->whereIn('kode', GajiKaryawan::KODE_DOKTER_SIRUS)->delete();
            DB::table('siaptxn_gdok_hdrs')->where('gaji_id', $gajiId)->update(['gaji_id' => null, 'diterapkan_at' => null, 'diterapkan_oleh' => null]);
            GajiKaryawan::hitungUlang((int) $gajiId);
            $hasilTerapkanKeSlipGaji['dibersihkan']++;
        }

        return $hasilTerapkanKeSlipGaji;
    }

    // ─── Tahap 2 & 3: hitungan SIAP (GajiDokterHitung) ───────────────────────

    /**
     * Struktur yang dipakai menghitung satu snapshot: baris struktur SIAP bila ada; kalau belum ada, direka dari snapshot itu
     * sendiri (parameter header + tunjangan/potongan rutin/kapita yang terbaca di detail) supaya pembanding tetap adil.
     * @return array{struktur: array, asal: string}
     */
    public static function strukturUntukSnapshot(object $header, Collection $detail): array
    {
        if (DB::table('siapmst_gdok_strukturs')->where('dr_id', $header->dr_id)->exists()) {
            return ['struktur' => GajiDokterStruktur::ambil($header->dr_id), 'asal' => 'struktur'];
        }
        $struktur = GajiDokterStruktur::rapikan(['basic_salary' => $header->nilai_gaji_pokok, 'npwp_status' => $header->npwp_status, 'skema_gaji_pokok' => $header->skema_gaji_pokok,
            'potongan_rs_basis' => $header->potongan_rs_basis, 'potongan_rs_persen' => $header->potongan_rs_persen, 'pph21_persen' => $header->pph21_persen]);
        foreach (GajiDokterStruktur::TUNJANGAN_RUTIN as $kolom => $kode) {
            $struktur[$kolom] = (float) $detail->where('jenis', 'J')->where('kode', $kode)->sum('nilai');
        }
        foreach (GajiDokterStruktur::POTONGAN_RUTIN as $kolom => $kode) {
            $struktur[$kolom] = abs((float) $detail->where('jenis', 'P')->where('kode', $kode)->where('nilai_manual', '!=', '1')->sum('nilai'));
        }
        foreach (['ri', 'rj'] as $jalur) {
            $kapita = $detail->where('jenis', 'J')->firstWhere('kode', 'KAPITA ' . strtoupper($jalur));
            if ($kapita && (int) $kapita->jumlah_pasien > 0) {
                $struktur["tarif_per_kapita_{$jalur}"] = round((float) $kapita->nilai / (int) $kapita->jumlah_pasien, 2);
            }
        }

        return ['struktur' => $struktur, 'asal' => 'snapshot'];
    }

    /** Baris manual (kasbon, bonus, …) satu dokter satu bulan jasa — ikut dihitung mesin SIAP. */
    public static function manualList(string $periode, string $drId): Collection
    {
        return DB::table('siaptxn_gdok_manuals')->where('periode', $periode)->where('dr_id', $drId)->orderBy('jenis')->orderBy('manual_id')->get();
    }

    /**
     * Tahap 2 — hitung ulang satu snapshot dengan mesin SIAP (lampiran snapshot + struktur) lalu bandingkan dengan angka SIRUS-nya.
     * @return array{hasil: array, banding: array, strukturAsal: string}|null
     */
    public static function ujiHitungSnapshot(int $gdId): ?array
    {
        $header = DB::table('siaptxn_gdok_hdrs')->where('gd_id', $gdId)->first();
        if (!$header) {
            return null;
        }
        $detail = self::detail($gdId);
        ['struktur' => $struktur, 'asal' => $asal] = self::strukturUntukSnapshot($header, $detail);
        // Baris manual di snapshot SIRUS (nilai_manual = 1, bukan RS/PPH21) ikut sebagai masukan manual supaya pembandingnya setara.
        $manualSnapshot = $detail->filter(fn($baris) => in_array($baris->jenis, ['P', 'T'], true) && $baris->nilai_manual === '1' && !in_array($baris->kode, ['RS', 'PPH21'], true))
            ->map(fn($baris) => ['jenis' => $baris->jenis, 'kode' => $baris->kode, 'keterangan' => $baris->label, 'nilai' => abs((float) $baris->nilai)]);
        $manualSiap = $header->sumber === 'SIAP' ? self::manualList($header->periode, $header->dr_id) : $manualSnapshot;
        $hasilHitung = GajiDokterHitung::hitung($struktur, self::lampiran($gdId), $manualSiap);

        return ['hasil' => $hasilHitung, 'banding' => GajiDokterHitung::banding($hasilHitung, $header, $detail), 'strukturAsal' => $asal];
    }

    /** Tahap 2 — uji semua snapshot terbaru satu bulan jasa. @return Collection<int, object> */
    public static function ujiHitung(string $periode): Collection
    {
        return DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('terbaru', '1')->orderBy('dr_name')->get(['gd_id', 'dr_id', 'dr_name', 'versi', 'sumber'])
            ->map(function ($row) {
                $uji = self::ujiHitungSnapshot((int) $row->gd_id);

                return (object) ['gd_id' => (int) $row->gd_id, 'dr_id' => $row->dr_id, 'dr_name' => $row->dr_name, 'versi' => (int) $row->versi, 'sumber' => $row->sumber,
                    'cocok' => $uji['banding']['cocok'] ?? false, 'strukturAsal' => $uji['strukturAsal'] ?? '-',
                    'selisihHeader' => $uji['banding']['header'] ?? [], 'selisihDetail' => $uji['banding']['detail'] ?? [], 'ringkasSiap' => $uji['hasil']['ringkas'] ?? []];
            });
    }

    private static function checksumSiap(array $ringkas, array $detail, array $baris): string
    {
        $komponen = array_map(fn($row) => [$row['jenis'], $row['kode'], round((float) $row['nilai'], 2), (int) $row['jumlah_pasien']], $detail);
        usort($komponen, fn($kiri, $kanan) => strcmp($kiri[0] . $kiri[1], $kanan[0] . $kanan[1]));
        $lampiranInti = array_map(fn($row) => [$row['komponen'], $row['txn_no'], round((float) $row['nominal'], 2)], $baris);
        usort($lampiranInti, fn($kiri, $kanan) => strcmp($kiri[0] . $kiri[1], $kanan[0] . $kanan[1]));

        return md5(json_encode([array_map(fn($nilai) => round((float) $nilai, 2), $ringkas), $komponen, $lampiranInti]));
    }

    /**
     * Tahap 3 — PROSES SLIP di SIAP: tarik transaksi mentah bulan jasa dari SIRUS (GET …/transaksi), hitung dengan struktur SIAP
     * (+ baris manual), simpan sebagai snapshot ber-sumber SIAP dengan aturan versi yang sama dengan Ambil (sama → tetap,
     * berubah → versi baru). Slip yang sudah Final di SIAP dilewati. Dokter tanpa struktur di SIAP memakai struktur SIRUS
     * yang ikut dikirim (dilaporkan).
     * @return array{jumlah: int, baru: int, berubah: int, sama: int, final: array<int, string>, tanpaStruktur: array<int, string>, tanpaMap: array<int, string>, catatan: array<int, string>}
     */
    public static function prosesSiap(string $periode, ?int $userId, ?string $drId = null): array
    {
        if (!self::periodeValid($periode)) {
            throw new RuntimeException('Periode tidak valid.');
        }
        [$tahun, $bulan] = explode('-', $periode);
        self::segarkanDokter($userId);
        $dataTransaksi = self::get("gaji-dokter/{$tahun}/{$bulan}/transaksi", $drId ? ['dr_id' => $drId] : []);
        $empIdPerDokter = DB::table('siapmst_dokter_sirus')->pluck('emp_id', 'dr_id');
        $periodeGaji = Carbon::createFromFormat('!Y-m', $periode)->addMonthNoOverflow()->format('Y-m');
        $hasilProses = ['jumlah' => 0, 'baru' => 0, 'berubah' => 0, 'sama' => 0, 'final' => [], 'tanpaStruktur' => [], 'tanpaMap' => [], 'catatan' => []];

        foreach ($dataTransaksi['dokter'] ?? [] as $dokter) {
            $drIdDokter = (string) $dokter['dr_id'];
            $namaDokter = trim((string) $dokter['dr_name']) !== '' ? $dokter['dr_name'] : '(tanpa nama) ' . $drIdDokter;
            $hasilProses['jumlah']++;
            $adaStruktur = DB::table('siapmst_gdok_strukturs')->where('dr_id', $drIdDokter)->exists();
            $struktur = $adaStruktur ? GajiDokterStruktur::ambil($drIdDokter) : GajiDokterStruktur::rapikan((array) ($dokter['struktur'] ?? []));
            if (!$adaStruktur) {
                $hasilProses['tanpaStruktur'][] = $namaDokter;
            }
            $empId = $empIdPerDokter[$drIdDokter] ?? null;
            if ($empId === null) {
                $hasilProses['tanpaMap'][] = $namaDokter;
            }
            $hasilHitung = GajiDokterHitung::hitung($struktur, $dokter['baris'] ?? [], self::manualList($periode, $drIdDokter));
            foreach ($hasilHitung['catatan'] as $catatan) {
                $hasilProses['catatan'][] = "{$namaDokter}: {$catatan}";
            }
            $baris = array_map(fn($row) => [...$row, 'masuk_slip' => !in_array((string) ($row['group'] ?? ''), $hasilHitung['grupKapita'], true)], $dokter['baris'] ?? []);
            $checksum = self::checksumSiap($hasilHitung['ringkas'], $hasilHitung['detail'], $baris);
            $snapshotTerbaru = DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dr_id', $drIdDokter)->where('terbaru', '1')->first();
            if ($snapshotTerbaru && $snapshotTerbaru->sumber === 'SIAP' && $snapshotTerbaru->gaji_status === 'F') {
                $hasilProses['final'][] = $namaDokter;
                continue;
            }
            if ($snapshotTerbaru && $snapshotTerbaru->checksum === $checksum) {
                DB::table('siaptxn_gdok_hdrs')->where('gd_id', $snapshotTerbaru->gd_id)->update(['diambil_at' => now(), 'diambil_oleh' => $userId, 'emp_id' => $empId, 'updated_at' => now()]);
                $hasilProses['sama']++;
                continue;
            }
            self::simpanSnapshotSiap($periode, $periodeGaji, $drIdDokter, $namaDokter, $empId, $struktur, $hasilHitung, $baris, $checksum, $snapshotTerbaru, $userId);
            $snapshotTerbaru ? $hasilProses['berubah']++ : $hasilProses['baru']++;
        }

        DB::table('siaptxn_gdok_ambils')->insert(['periode' => $periode, 'diambil_at' => now(), 'diambil_oleh' => $userId, 'jumlah_slip' => $hasilProses['jumlah'],
            'baru' => $hasilProses['baru'], 'berubah' => $hasilProses['berubah'], 'sama' => $hasilProses['sama'], 'lampiran_baris' => array_sum(array_map(fn($dokter) => count($dokter['baris'] ?? []), $dataTransaksi['dokter'] ?? [])),
            'keterangan' => trim('Proses Slip di SIAP. ' . ($hasilProses['tanpaMap'] !== [] ? count($hasilProses['tanpaMap']) . ' dokter belum dipetakan. ' : '') . ($hasilProses['tanpaStruktur'] !== [] ? count($hasilProses['tanpaStruktur']) . ' dokter memakai struktur SIRUS.' : '')) ?: null,
            'created_at' => now(), 'updated_at' => now()]);

        return $hasilProses;
    }

    private static function simpanSnapshotSiap(string $periode, string $periodeGaji, string $drId, string $namaDokter, ?string $empId, array $struktur, array $hasilHitung, array $baris, string $checksum, ?object $snapshotTerbaru, ?int $userId): int
    {
        return DB::transaction(function () use ($periode, $periodeGaji, $drId, $namaDokter, $empId, $struktur, $hasilHitung, $baris, $checksum, $snapshotTerbaru, $userId) {
            if ($snapshotTerbaru) {
                DB::table('siaptxn_gdok_hdrs')->where('gd_id', $snapshotTerbaru->gd_id)->update(['terbaru' => '0', 'updated_at' => now()]);
            }
            $versi = $snapshotTerbaru ? (int) $snapshotTerbaru->versi + 1 : 1;
            DB::table('siaptxn_gdok_hdrs')->insert([
                'periode' => $periode, 'dr_id' => $drId, 'versi' => $versi, 'terbaru' => '1', 'emp_id' => $empId, 'gajidoctor_no' => 0, 'dr_name' => mb_substr($namaDokter, 0, 100),
                'periode_gaji' => $periodeGaji, 'skema_gaji_pokok' => $struktur['skema_gaji_pokok'], 'nilai_gaji_pokok' => $struktur['basic_salary'],
                'potongan_rs_basis' => $struktur['potongan_rs_basis'], 'potongan_rs_persen' => $struktur['potongan_rs_persen'], 'pph21_persen' => $struktur['pph21_persen'],
                'npwp_status' => $struktur['npwp_status'], ...$hasilHitung['ringkas'], 'gaji_status' => 'D', 'tanggal_bayar' => null, 'update_date_sirus' => null,
                'checksum' => $checksum, 'jumlah_lampiran' => count($baris), 'sumber' => 'SIAP', 'diambil_at' => now(), 'diambil_oleh' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $gdId = (int) DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dr_id', $drId)->where('versi', $versi)->value('gd_id');
            foreach ($hasilHitung['detail'] as $komponen) {
                DB::table('siaptxn_gdok_dtls')->insert(['gd_id' => $gdId, 'jenis' => $komponen['jenis'], 'kode' => mb_substr($komponen['kode'], 0, 20), 'label' => mb_substr($komponen['label'], 0, 100),
                    'keterangan' => null, 'nilai' => $komponen['nilai'], 'nilai_manual' => $komponen['nilai_manual'], 'jumlah_pasien' => $komponen['jumlah_pasien'], 'urutan' => $komponen['urutan']]);
            }
            foreach (array_chunk($baris, 200) as $potonganLampiran) {
                DB::table('siaptxn_gdok_lamps')->insert(array_map(fn($row) => [
                    'gd_id' => $gdId, 'komponen' => mb_substr((string) $row['komponen'], 0, 20), 'label' => mb_substr((string) ($row['label'] ?? GajiDokterStruktur::labelKomponen((string) $row['komponen'])), 0, 100),
                    'tgl' => mb_substr((string) ($row['tgl'] ?? ''), 0, 10), 'reg_no' => mb_substr((string) ($row['reg_no'] ?? ''), 0, 20) ?: null, 'nama' => mb_substr((string) ($row['nama'] ?? ''), 0, 100) ?: null,
                    'keterangan' => mb_substr((string) ($row['keterangan'] ?? ''), 0, 200) ?: null, 'klaim' => mb_substr((string) ($row['klaim'] ?? ''), 0, 60) ?: null,
                    'klaim_status' => mb_substr((string) ($row['klaim_status'] ?? ''), 0, 20) ?: null, 'no_sep' => mb_substr((string) ($row['no_sep'] ?? ''), 0, 30) ?: null,
                    'txn_no' => mb_substr((string) ($row['txn_no'] ?? ''), 0, 30) ?: null, 'nominal' => $row['nominal'], 'pasien' => $row['pasien'],
                    'masuk_slip' => $row['masuk_slip'] ? '1' : '0', 'grup' => mb_substr((string) ($row['group'] ?? $row['grup'] ?? ''), 0, 10) ?: null,
                ], $potonganLampiran));
            }

            return $gdId;
        });
    }

    /**
     * Tahap 3 — hitung ulang satu snapshot SIAP tanpa memanggil SIRUS: transaksi diambil dari lampiran snapshot itu sendiri
     * (dipakai setelah baris manual / struktur diubah). Menghasilkan versi baru bila angkanya berubah.
     * @return array{gd_id: int, berubah: bool, pesan: ?string}
     */
    public static function hitungUlangSiap(int $gdId, ?int $userId): array
    {
        $header = DB::table('siaptxn_gdok_hdrs')->where('gd_id', $gdId)->first();
        if (!$header) {
            return ['gd_id' => $gdId, 'berubah' => false, 'pesan' => 'Snapshot tidak ditemukan.'];
        }
        if ($header->gaji_status === 'F') {
            return ['gd_id' => $gdId, 'berubah' => false, 'pesan' => 'Slip sudah Final — buka kunci dulu.'];
        }
        $struktur = GajiDokterStruktur::ambil($header->dr_id);
        $lampiran = self::lampiran($gdId)->map(fn($row) => ['komponen' => $row->komponen, 'group' => $row->grup ?? (GajiDokterStruktur::GRUP_KOMPONEN[$row->komponen] ?? ''), 'label' => $row->label,
            'tgl' => $row->tgl, 'reg_no' => $row->reg_no, 'nama' => $row->nama, 'keterangan' => $row->keterangan, 'klaim' => $row->klaim, 'klaim_status' => $row->klaim_status,
            'no_sep' => $row->no_sep, 'txn_no' => $row->txn_no, 'nominal' => (float) $row->nominal, 'pasien' => (int) $row->pasien])->values()->all();
        $hasilHitung = GajiDokterHitung::hitung($struktur, $lampiran, self::manualList($header->periode, $header->dr_id));
        $baris = array_map(fn($row) => [...$row, 'masuk_slip' => !in_array((string) $row['group'], $hasilHitung['grupKapita'], true)], $lampiran);
        $checksum = self::checksumSiap($hasilHitung['ringkas'], $hasilHitung['detail'], $baris);
        $terbaru = DB::table('siaptxn_gdok_hdrs')->where('periode', $header->periode)->where('dr_id', $header->dr_id)->where('terbaru', '1')->first();
        if ($terbaru && $terbaru->checksum === $checksum && $terbaru->sumber === 'SIAP') {
            return ['gd_id' => (int) $terbaru->gd_id, 'berubah' => false, 'pesan' => null];
        }
        $gdIdBaru = self::simpanSnapshotSiap($header->periode, $header->periode_gaji ?: Carbon::createFromFormat('!Y-m', $header->periode)->addMonthNoOverflow()->format('Y-m'),
            $header->dr_id, $header->dr_name, $header->emp_id, $struktur, $hasilHitung, $baris, $checksum, $terbaru, $userId);

        return ['gd_id' => $gdIdBaru, 'berubah' => true, 'pesan' => null];
    }

    /** Tahap 3 — kunci / buka kunci slip SIAP (hanya snapshot ber-sumber SIAP, versi terbaru). @return string|null pesan galat */
    public static function ubahStatusSiap(int $gdId, string $status, ?int $userId): ?string
    {
        $header = DB::table('siaptxn_gdok_hdrs')->where('gd_id', $gdId)->first();
        if (!$header || $header->terbaru !== '1') {
            return 'Hanya versi terbaru yang bisa diubah statusnya.';
        }
        if ($header->sumber !== 'SIAP') {
            return 'Slip ini dihitung SIRUS — status Final diatur di SIRUS.';
        }
        DB::table('siaptxn_gdok_hdrs')->where('gd_id', $gdId)->update(['gaji_status' => $status === 'F' ? 'F' : 'D',
            'difinalkan_at' => $status === 'F' ? now() : null, 'difinalkan_oleh' => $status === 'F' ? $userId : null, 'updated_at' => now()]);

        return null;
    }

    public static function namaPeriode(?string $periode): string
    {
        return $periode && preg_match('/^(\d{4})-(\d{2})$/', $periode, $bagianPeriode)
            ? ((['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'][$bagianPeriode[2]] ?? $bagianPeriode[2]) . ' ' . $bagianPeriode[1])
            : (string) $periode;
    }

    public static function periodeTersedia(): Collection
    {
        return DB::table('siaptxn_gdok_hdrs')->where('terbaru', '1')->select('periode', DB::raw('count(*) n'))->groupBy('periode')->orderByDesc('periode')->get();
    }

    public static function riwayatAmbil(int $batasBaris = 30): Collection
    {
        return DB::table('siaptxn_gdok_ambils as a')->leftJoin('users as u', 'u.id', '=', 'a.diambil_oleh')->orderByDesc('a.ambil_id')->limit($batasBaris)->get(['a.*', 'u.name']);
    }

    /** Semua versi satu dokter satu bulan jasa (terbaru dulu). */
    public static function versiList(string $periode, string $drId): Collection
    {
        return DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dr_id', $drId)->orderByDesc('versi')->get();
    }

    public static function detail(int $gdId): Collection
    {
        return DB::table('siaptxn_gdok_dtls')->where('gd_id', $gdId)->orderBy('jenis')->orderBy('urutan')->orderBy('gd_dtl')->get();
    }

    public static function lampiran(int $gdId): Collection
    {
        return DB::table('siaptxn_gdok_lamps')->where('gd_id', $gdId)->orderBy('komponen')->orderBy('tgl')->orderBy('lamp_id')->get();
    }

    /**
     * Bandingkan dua versi (atau versi terbaru vs bulan lalu): selisih nominal header, komponen yang berubah / hilang / baru,
     * dan jumlah baris lampiran.
     * @return array{header: array<string, array{lama: float, baru: float, selisih: float}>, detail: array<int, array{jenis: string, kode: string, label: string, lama: ?float, baru: ?float}>, lampiran: array{lama: int, baru: int}}
     */
    public static function banding(?object $headerLama, ?object $headerBaru): array
    {
        $hasilBandingVersi = ['header' => [], 'detail' => [], 'lampiran' => ['lama' => (int) ($headerLama->jumlah_lampiran ?? 0), 'baru' => (int) ($headerBaru->jumlah_lampiran ?? 0)]];
        foreach (self::KOLOM_NOMINAL as $kolomNominal) {
            $nilaiLama = (float) ($headerLama->$kolomNominal ?? 0);
            $nilaiBaru = (float) ($headerBaru->$kolomNominal ?? 0);
            if (abs($nilaiLama - $nilaiBaru) >= 0.5) {
                $hasilBandingVersi['header'][$kolomNominal] = ['lama' => $nilaiLama, 'baru' => $nilaiBaru, 'selisih' => $nilaiBaru - $nilaiLama];
            }
        }
        $detailLama = $headerLama ? self::detail((int) $headerLama->gd_id)->keyBy(fn($komponen) => $komponen->jenis . '|' . $komponen->kode) : collect();
        $detailBaru = $headerBaru ? self::detail((int) $headerBaru->gd_id)->keyBy(fn($komponen) => $komponen->jenis . '|' . $komponen->kode) : collect();
        foreach ($detailLama->keys()->merge($detailBaru->keys())->unique() as $kunciKomponen) {
            $komponenLama = $detailLama[$kunciKomponen] ?? null;
            $komponenBaru = $detailBaru[$kunciKomponen] ?? null;
            if ($komponenLama && $komponenBaru && abs((float) $komponenLama->nilai - (float) $komponenBaru->nilai) < 0.5 && (int) $komponenLama->jumlah_pasien === (int) $komponenBaru->jumlah_pasien) {
                continue;
            }
            [$jenis, $kode] = explode('|', $kunciKomponen, 2);
            $hasilBandingVersi['detail'][] = ['jenis' => $jenis, 'kode' => $kode, 'label' => ($komponenBaru ?? $komponenLama)->label ?: $kode,
                'lama' => $komponenLama ? (float) $komponenLama->nilai : null, 'baru' => $komponenBaru ? (float) $komponenBaru->nilai : null,
                'pasien_lama' => $komponenLama ? (int) $komponenLama->jumlah_pasien : null, 'pasien_baru' => $komponenBaru ? (int) $komponenBaru->jumlah_pasien : null];
        }

        return $hasilBandingVersi;
    }

    /** Karyawan aktif untuk LOV pemetaan. @return array<int, array{id: string, nama: string, kelas: string}> */
    public static function karyawanOpsi(): array
    {
        return DB::table('siapmst_employers as em')->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'em.emp_unit_id')
            ->where('em.active_status', '1')->orderBy('em.emp_name')->get(['em.emp_id', 'em.emp_name', 'un.unit_name'])
            ->map(fn($row) => ['id' => $row->emp_id, 'nama' => $row->emp_name, 'kelas' => $row->emp_id . ($row->unit_name ? ' · ' . $row->unit_name : '')])->all();
    }
}
