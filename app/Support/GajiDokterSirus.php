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
 * SIRUS menghitung gaji dokter spesialis (uang periksa, jasa tindakan, visite, operasi, kapita, potongan RS, PPh 21)
 * di modulnya sendiri. SIAP tidak menghitung ulang: menarik hasilnya lewat API baca-saja (JWT; akun = user SIRUS ber-role
 * 'Api Siap', kredensialnya di .env: SIAP_API_URL / SIAP_API_USER / SIAP_API_PASSWORD) dan menyimpannya sebagai snapshot.
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

    private static function token(bool $segar = false): string
    {
        if (!self::aktif()) {
            throw new RuntimeException('API SIRUS belum diatur (SIAP_API_URL / SIAP_API_USER / SIAP_API_PASSWORD di .env).');
        }
        if ($segar) {
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

    /** GET ber-Bearer; token kedaluwarsa (401) dicoba ulang sekali dengan token baru. */
    private static function get(string $path, array $query = []): array
    {
        $url = rtrim(config('services.sirus.url'), '/') . '/api/siap/' . ltrim($path, '/');
        foreach ([false, true] as $segar) {
            $respon = Http::acceptJson()->timeout(120)->withToken(self::token($segar))->get($url, $query);
            if ($respon->status() === 401 && !$segar) {
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
            $data = self::get('dokter');

            return ['ok' => true, 'pesan' => 'Tersambung ke ' . config('services.sirus.url') . ' — ' . count($data['dokter'] ?? []) . ' dokter di master SIRUS.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'pesan' => $e->getMessage()];
        }
    }

    // ─── Pemetaan dokter ──────────────────────────────────────────────────────

    private static function normNama(?string $nama): string
    {
        return preg_replace('/[^A-Z]/', '', mb_strtoupper(preg_replace(['/,.*$/', '/\b(DR|DRG|H|HJ|NS|S|KEP|AMD|SKM|SP[A-Z.]*)\.?\s+/i'], '', (string) $nama)));
    }

    /** Segarkan master dokter dari SIRUS + pemetaan otomatis (nama cocok persis & tunggal). @return array{baru: int, oto: int} */
    public static function segarkanDokter(?int $userId): array
    {
        $data = self::get('dokter');
        $karyawan = DB::table('siapmst_employers')->where('active_status', '1')->get(['emp_id', 'emp_name'])->groupBy(fn($r) => self::normNama($r->emp_name));
        $ada = DB::table('siapmst_dokter_sirus')->get()->keyBy('dr_id');
        $hasil = ['baru' => 0, 'oto' => 0];
        foreach ($data['dokter'] ?? [] as $d) {
            $nama = trim((string) $d['dr_name']) !== '' ? $d['dr_name'] : '(tanpa nama) ' . $d['dr_id'];
            $baris = ['dr_name' => mb_substr($nama, 0, 100), 'dr_nik' => $d['dr_nik'] ?: null, 'kd_dr_bpjs' => $d['kd_dr_bpjs'] ?: null,
                'aktif' => $d['aktif'] ? '1' : '0', 'updated_at' => now()];
            $lama = $ada[$d['dr_id']] ?? null;
            if ($lama) {
                DB::table('siapmst_dokter_sirus')->where('dr_id', $d['dr_id'])->update($baris);
                if ($lama->emp_id !== null) {
                    continue;
                }
            }
            $norm = self::normNama($d['dr_name']);
            $cocok = $norm !== '' ? ($karyawan[$norm] ?? null) : null;
            $empId = $cocok && $cocok->count() === 1 ? $cocok->first()->emp_id : null;
            if ($lama) {
                if ($empId) {
                    DB::table('siapmst_dokter_sirus')->where('dr_id', $d['dr_id'])->update(['emp_id' => $empId, 'cara_map' => 'OTO', 'updated_by' => $userId]);
                    $hasil['oto']++;
                }
            } else {
                DB::table('siapmst_dokter_sirus')->insert([...$baris, 'dr_id' => $d['dr_id'], 'emp_id' => $empId, 'cara_map' => $empId ? 'OTO' : null, 'updated_by' => $userId, 'created_at' => now()]);
                $hasil['baru']++;
                if ($empId) {
                    $hasil['oto']++;
                }
            }
        }

        return $hasil;
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
            ->when(trim($cari) !== '', fn($q) => $q->whereRaw('UPPER(d.dr_name) LIKE ?', ['%' . mb_strtoupper(trim($cari)) . '%']))
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
        $peta = DB::table('siapmst_dokter_sirus')->pluck('emp_id', 'dr_id');
        $hasil = ['jumlah' => 0, 'baru' => 0, 'berubah' => 0, 'sama' => 0, 'lampiran' => 0, 'tanpaMap' => []];

        foreach ($slipData['slip'] ?? [] as $slip) {
            $hasil['jumlah']++;
            $lampiran = $lampiranPerDokter[$slip['dr_id']]['baris'] ?? [];
            $checksum = self::checksum($slip, $lampiran);
            $empId = $peta[$slip['dr_id']] ?? null;
            if ($empId === null) {
                $hasil['tanpaMap'][] = $slip['dr_name'];
            }
            $terbaru = DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dr_id', $slip['dr_id'])->where('terbaru', '1')->first();
            if ($terbaru && $terbaru->checksum === $checksum) {
                DB::table('siaptxn_gdok_hdrs')->where('gd_id', $terbaru->gd_id)->update(['diambil_at' => now(), 'diambil_oleh' => $userId, 'emp_id' => $empId, 'updated_at' => now()]);
                $hasil['sama']++;
                continue;
            }
            DB::transaction(function () use ($periode, $slip, $lampiran, $checksum, $empId, $terbaru, $userId, &$hasil) {
                if ($terbaru) {
                    DB::table('siaptxn_gdok_hdrs')->where('gd_id', $terbaru->gd_id)->update(['terbaru' => '0', 'updated_at' => now()]);
                }
                $versi = $terbaru ? (int) $terbaru->versi + 1 : 1;
                DB::table('siaptxn_gdok_hdrs')->insert([
                    'periode' => $periode, 'dr_id' => $slip['dr_id'], 'versi' => $versi, 'terbaru' => '1', 'emp_id' => $empId,
                    'gajidoctor_no' => (int) $slip['gajidoctor_no'], 'dr_name' => mb_substr(trim((string) $slip['dr_name']) !== '' ? $slip['dr_name'] : '(tanpa nama) ' . $slip['dr_id'], 0, 100),
                    'periode_gaji' => $slip['tahun_gaji'] . '-' . $slip['bulan_gaji'],
                    'skema_gaji_pokok' => $slip['skema_gaji_pokok'], 'nilai_gaji_pokok' => $slip['nilai_gaji_pokok'],
                    'potongan_rs_basis' => $slip['potongan_rs_basis'], 'potongan_rs_persen' => $slip['potongan_rs_persen'],
                    'pph21_persen' => $slip['pph21_persen'], 'npwp_status' => $slip['npwp_status'],
                    'jasa_total' => $slip['jasa_total'], 'total_gaji' => $slip['total_gaji'], 'potongan_rs' => $slip['potongan_rs'], 'pph21' => $slip['pph21'],
                    'potongan_lain_total' => $slip['potongan_lain_total'], 'tambahan_total' => $slip['tambahan_total'], 'gaji_diterima' => $slip['gaji_diterima'],
                    'gaji_status' => $slip['gaji_status'],
                    'tanggal_bayar' => $slip['tanggal_bayar'] ? Absensi::tanggalSql(Carbon::parse($slip['tanggal_bayar'])) : null,
                    'update_date_sirus' => $slip['update_date'], 'checksum' => $checksum, 'jumlah_lampiran' => count($lampiran),
                    'diambil_at' => now(), 'diambil_oleh' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $gdId = (int) DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dr_id', $slip['dr_id'])->where('versi', $versi)->value('gd_id');
                foreach ($slip['detail'] as $d) {
                    DB::table('siaptxn_gdok_dtls')->insert(['gd_id' => $gdId, 'jenis' => $d['jenis'], 'kode' => mb_substr($d['kode'], 0, 20), 'label' => mb_substr($d['label'], 0, 100),
                        'keterangan' => mb_substr($d['keterangan'], 0, 100) ?: null, 'nilai' => $d['nilai'], 'nilai_manual' => $d['nilai_manual'] ? '1' : '0',
                        'jumlah_pasien' => $d['jumlah_pasien'], 'urutan' => $d['urutan']]);
                }
                foreach (array_chunk($lampiran, 200) as $potongan) {
                    DB::table('siaptxn_gdok_lamps')->insert(array_map(fn($b) => [
                        'gd_id' => $gdId, 'komponen' => mb_substr($b['komponen'], 0, 20), 'label' => mb_substr($b['label'], 0, 100), 'tgl' => mb_substr((string) $b['tgl'], 0, 10),
                        'reg_no' => mb_substr((string) $b['reg_no'], 0, 20) ?: null, 'nama' => mb_substr((string) $b['nama'], 0, 100) ?: null,
                        'keterangan' => mb_substr((string) $b['keterangan'], 0, 200) ?: null, 'klaim' => mb_substr((string) $b['klaim'], 0, 60) ?: null,
                        'klaim_status' => mb_substr((string) $b['klaim_status'], 0, 20) ?: null, 'no_sep' => mb_substr((string) $b['no_sep'], 0, 30) ?: null,
                        'txn_no' => mb_substr((string) $b['txn_no'], 0, 30) ?: null, 'nominal' => $b['nominal'], 'pasien' => $b['pasien'],
                        'masuk_slip' => $b['masuk_slip'] ? '1' : '0',
                    ], $potongan));
                }
                $hasil['lampiran'] += count($lampiran);
                $terbaru ? $hasil['berubah']++ : $hasil['baru']++;
            });
        }

        DB::table('siaptxn_gdok_ambils')->insert(['periode' => $periode, 'diambil_at' => now(), 'diambil_oleh' => $userId, 'jumlah_slip' => $hasil['jumlah'],
            'baru' => $hasil['baru'], 'berubah' => $hasil['berubah'], 'sama' => $hasil['sama'], 'lampiran_baris' => $hasil['lampiran'],
            'keterangan' => $hasil['tanpaMap'] !== [] ? count($hasil['tanpaMap']) . ' dokter belum dipetakan' : null, 'created_at' => now(), 'updated_at' => now()]);

        return $hasil;
    }

    private static function checksum(array $slip, array $lampiran): string
    {
        $kunci = ['gaji_status', 'skema_gaji_pokok', 'nilai_gaji_pokok', 'potongan_rs_basis', 'potongan_rs_persen', 'pph21_persen', 'npwp_status', ...self::KOLOM_NOMINAL];
        $inti = array_map(fn($k) => is_float($slip[$k] ?? null) ? round($slip[$k], 2) : ($slip[$k] ?? null), array_combine($kunci, $kunci));
        $detail = array_map(fn($d) => [$d['jenis'], $d['kode'], round((float) $d['nilai'], 2), (int) $d['jumlah_pasien'], (bool) $d['nilai_manual']], $slip['detail']);
        usort($detail, fn($a, $b) => strcmp($a[0] . $a[1], $b[0] . $b[1]));
        $lamp = array_map(fn($b) => [$b['komponen'], $b['txn_no'], round((float) $b['nominal'], 2)], $lampiran);
        usort($lamp, fn($a, $b) => strcmp($a[0] . $a[1], $b[0] . $b[1]));

        return md5(json_encode([$inti, $detail, $lamp]));
    }

    // ─── Baca snapshot ────────────────────────────────────────────────────────

    /** Header versi terbaru satu bulan jasa + pemetaan + penanda perubahan. @return Collection<int, object> */
    public static function daftar(string $periode, string $cari = '', string $saring = 'semua'): Collection
    {
        $rows = DB::table('siaptxn_gdok_hdrs as h')
            ->leftJoin('siapmst_dokter_sirus as d', 'd.dr_id', '=', 'h.dr_id')
            ->leftJoin('siapmst_employers as em', 'em.emp_id', '=', 'd.emp_id')
            ->where('h.periode', $periode)->where('h.terbaru', '1')
            ->when(trim($cari) !== '', fn($q) => $q->whereRaw('UPPER(h.dr_name) LIKE ?', ['%' . mb_strtoupper(trim($cari)) . '%']))
            ->orderBy('h.dr_name')
            ->get(['h.*', 'd.emp_id as emp_id_map', 'd.cara_map', 'em.emp_name']);
        $bulanLalu = Carbon::createFromFormat('!Y-m', $periode)->subMonthNoOverflow()->format('Y-m');
        $lalu = DB::table('siaptxn_gdok_hdrs')->where('periode', $bulanLalu)->where('terbaru', '1')->get()->keyBy('dr_id');
        // Versi yang pernah ditulis ke slip gaji (bisa versi lama) — penanda "slip memakai v1, snapshot sudah v2".
        $diterapkan = DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->whereNotNull('diterapkan_at')
            ->orderBy('versi')->get(['dr_id', 'versi', 'gaji_id', 'diterapkan_at'])->keyBy('dr_id');

        $rows = $rows->map(function ($row) use ($lalu, $diterapkan) {
            $row->versi_sebelumnya = (int) $row->versi > 1;
            $row->bulan_lalu = $lalu[$row->dr_id] ?? null;
            $row->selisih_bulan_lalu = $row->bulan_lalu ? (float) $row->gaji_diterima - (float) $row->bulan_lalu->gaji_diterima : null;
            $t = $diterapkan[$row->dr_id] ?? null;
            $row->diterapkan_versi = $t ? (int) $t->versi : null;
            $row->diterapkan_gaji_id = $t ? (int) $t->gaji_id : null;
            $row->diterapkan_at_slip = $t?->diterapkan_at;

            return $row;
        });

        return match ($saring) {
            'berubah'   => $rows->filter(fn($r) => $r->versi_sebelumnya)->values(),
            'tanpa_map' => $rows->filter(fn($r) => $r->emp_id_map === null)->values(),
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
     * Yang ditulis = angka SIRUS apa adanya: Jasa, gaji pokok dokter (bila ada), tambahan sebagai pendapatan; Potongan RS,
     * PPh 21 SIRUS, potongan lain sebagai potongan → diterima di SIAP = diterima di SIRUS untuk bagian dokter.
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
        $hasil = ['diperbarui' => 0, 'dibuatkan' => 0, 'dibersihkan' => 0, 'periodeGaji' => $periodeGaji, 'final' => [], 'tanpaSlip' => [], 'tanpaMap' => []];
        $label = self::namaPeriode($periode);

        $terpetakan = $rows->filter(fn($r) => $r->emp_id_map !== null);
        $hasil['tanpaMap'] = $rows->filter(fn($r) => $r->emp_id_map === null)->pluck('dr_name')->values()->all();
        $slipList = DB::table('siaptxn_gaji_hdrs')->where('periode', $periodeGaji)
            ->whereIn('emp_id', $terpetakan->pluck('emp_id_map')->map(fn($e) => (string) $e)->all() ?: [''])
            ->get(['gaji_id', 'emp_id', 'gaji_status'])->keyBy('emp_id');
        $karyawan = DB::table('siapmst_employers')->whereIn('emp_id', $terpetakan->pluck('emp_id_map')->map(fn($e) => (string) $e)->all() ?: [''])
            ->get(['emp_id', 'emp_unit_id', 'active_status'])->keyBy('emp_id');
        $dipakai = [];

        foreach ($terpetakan as $row) {
            $slip = $slipList[$row->emp_id_map] ?? null;
            if (!$slip) {
                $em = $karyawan[$row->emp_id_map] ?? null;
                if (!$em || $em->active_status !== '1') {
                    $hasil['tanpaSlip'][] = $row->dr_name;
                    continue;
                }
                DB::table('siaptxn_gaji_hdrs')->insert(['emp_id' => $row->emp_id_map, 'periode' => $periodeGaji, 'unit_id' => $em->emp_unit_id,
                    'hari_hadir' => 0, 'gaji_pokok' => 0, 'tarif_makan' => 0, 'tarif_transport' => 0, 'pph21_persen' => 0,
                    'gaji_status' => GajiKaryawan::STATUS_DRAFT, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
                $slip = DB::table('siaptxn_gaji_hdrs')->where('emp_id', $row->emp_id_map)->where('periode', $periodeGaji)->first(['gaji_id', 'emp_id', 'gaji_status']);
                $hasil['dibuatkan']++;
            }
            $dipakai[] = (int) $slip->gaji_id;
            if ($slip->gaji_status === GajiKaryawan::STATUS_FINAL) {
                $hasil['final'][] = $row->dr_name;
                continue;
            }
            $gajiId = (int) $slip->gaji_id;
            $ket = "bulan jasa {$label} · SIRUS slip #{$row->gajidoctor_no} v{$row->versi}";
            DB::transaction(function () use ($gajiId, $row, $ket, $userId, $periode) {
                DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->whereIn('kode', GajiKaryawan::KODE_DOKTER_SIRUS)->delete();
                $baris = [
                    ['D', 'JASA_DOKTER', (float) $row->jasa_total, "Jasa dokter {$ket}"],
                    ['D', 'POKOK_DOKTER', (float) $row->nilai_gaji_pokok, "Gaji pokok dokter skema {$row->skema_gaji_pokok} · {$ket}"],
                    ['D', 'TAMBAHAN_DOKTER', (float) $row->tambahan_total, "Tambahan slip dokter · {$ket}"],
                    ['P', 'POT_RS', (float) $row->potongan_rs, 'Potongan RS ' . rtrim(rtrim(number_format((float) $row->potongan_rs_persen, 2, ',', '.'), '0'), ',') . "% · {$ket}"],
                    ['P', 'PPH21_DOKTER', (float) $row->pph21, 'PPh 21 ' . rtrim(rtrim(number_format((float) $row->pph21_persen, 2, ',', '.'), '0'), ',') . '% ' . ($row->npwp_status === 'Y' ? 'NPWP' : 'tanpa NPWP') . " · {$ket}"],
                    ['P', 'POT_DOKTER', (float) $row->potongan_lain_total, "Potongan lain slip dokter · {$ket}"],
                ];
                foreach ($baris as [$jenis, $kode, $nilai, $keterangan]) {
                    if (abs($nilai) >= 0.5) {
                        GajiKaryawan::simpanBaris($gajiId, ['jenis' => $jenis, 'kode' => $kode, 'nilai' => $nilai, 'otomatis' => '0', 'keterangan' => mb_substr($keterangan, 0, 100)]);
                    }
                }
                DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->where('dr_id', $row->dr_id)
                    ->update(['gaji_id' => null, 'diterapkan_at' => null, 'diterapkan_oleh' => null]);
                DB::table('siaptxn_gdok_hdrs')->where('gd_id', $row->gd_id)
                    ->update(['gaji_id' => $gajiId, 'diterapkan_at' => now(), 'diterapkan_oleh' => $userId, 'updated_at' => now()]);
            });
            GajiKaryawan::hitungUlang($gajiId);
            $hasil['diperbarui']++;
        }

        // Bekas terapkan pada slip Draft periode gaji ini yang tidak lagi punya dokter terpetakan (pemetaan dilepas / dokter hilang).
        $sisaLama = DB::table('siaptxn_gaji_dtls as d')->join('siaptxn_gaji_hdrs as h', 'h.gaji_id', '=', 'd.gaji_id')
            ->where('h.periode', $periodeGaji)->where('h.gaji_status', GajiKaryawan::STATUS_DRAFT)
            ->whereIn('d.kode', GajiKaryawan::KODE_DOKTER_SIRUS)
            ->when($dipakai !== [], fn($q) => $q->whereNotIn('h.gaji_id', $dipakai))
            ->distinct()->pluck('h.gaji_id');
        foreach ($sisaLama as $gajiId) {
            DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->whereIn('kode', GajiKaryawan::KODE_DOKTER_SIRUS)->delete();
            DB::table('siaptxn_gdok_hdrs')->where('gaji_id', $gajiId)->update(['gaji_id' => null, 'diterapkan_at' => null, 'diterapkan_oleh' => null]);
            GajiKaryawan::hitungUlang((int) $gajiId);
            $hasil['dibersihkan']++;
        }

        return $hasil;
    }

    public static function namaPeriode(?string $ym): string
    {
        return $ym && preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)
            ? ((['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'][$m[2]] ?? $m[2]) . ' ' . $m[1])
            : (string) $ym;
    }

    public static function periodeTersedia(): Collection
    {
        return DB::table('siaptxn_gdok_hdrs')->where('terbaru', '1')->select('periode', DB::raw('count(*) n'))->groupBy('periode')->orderByDesc('periode')->get();
    }

    public static function riwayatAmbil(int $batas = 30): Collection
    {
        return DB::table('siaptxn_gdok_ambils as a')->leftJoin('users as u', 'u.id', '=', 'a.diambil_oleh')->orderByDesc('a.ambil_id')->limit($batas)->get(['a.*', 'u.name']);
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
    public static function banding(?object $lama, ?object $baru): array
    {
        $hasil = ['header' => [], 'detail' => [], 'lampiran' => ['lama' => (int) ($lama->jumlah_lampiran ?? 0), 'baru' => (int) ($baru->jumlah_lampiran ?? 0)]];
        foreach (self::KOLOM_NOMINAL as $k) {
            $a = (float) ($lama->$k ?? 0);
            $b = (float) ($baru->$k ?? 0);
            if (abs($a - $b) >= 0.5) {
                $hasil['header'][$k] = ['lama' => $a, 'baru' => $b, 'selisih' => $b - $a];
            }
        }
        $dLama = $lama ? self::detail((int) $lama->gd_id)->keyBy(fn($d) => $d->jenis . '|' . $d->kode) : collect();
        $dBaru = $baru ? self::detail((int) $baru->gd_id)->keyBy(fn($d) => $d->jenis . '|' . $d->kode) : collect();
        foreach ($dLama->keys()->merge($dBaru->keys())->unique() as $kunci) {
            $x = $dLama[$kunci] ?? null;
            $y = $dBaru[$kunci] ?? null;
            if ($x && $y && abs((float) $x->nilai - (float) $y->nilai) < 0.5 && (int) $x->jumlah_pasien === (int) $y->jumlah_pasien) {
                continue;
            }
            [$jenis, $kode] = explode('|', $kunci, 2);
            $hasil['detail'][] = ['jenis' => $jenis, 'kode' => $kode, 'label' => ($y ?? $x)->label ?: $kode,
                'lama' => $x ? (float) $x->nilai : null, 'baru' => $y ? (float) $y->nilai : null,
                'pasien_lama' => $x ? (int) $x->jumlah_pasien : null, 'pasien_baru' => $y ? (int) $y->jumlah_pasien : null];
        }

        return $hasil;
    }

    /** Karyawan aktif untuk LOV pemetaan. @return array<int, array{id: string, nama: string, kelas: string}> */
    public static function karyawanOpsi(): array
    {
        return DB::table('siapmst_employers as em')->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'em.emp_unit_id')
            ->where('em.active_status', '1')->orderBy('em.emp_name')->get(['em.emp_id', 'em.emp_name', 'un.unit_name'])
            ->map(fn($r) => ['id' => $r->emp_id, 'nama' => $r->emp_name, 'kelas' => $r->emp_id . ($r->unit_name ? ' · ' . $r->unit_name : '')])->all();
    }
}
