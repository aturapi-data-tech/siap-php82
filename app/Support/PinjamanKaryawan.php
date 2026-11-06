<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pinjaman Karyawan — buku pinjaman yang dipotong dari gaji, pengganti workbook koperasi
 * ("Angsuran Tiap Bulan Potong Gaji", sheet 1% / 0,8% / LAPORAN 1) dan blok BRI / Koperasi / Rinjani
 * di sheet "angsuran" workbook gaji lama.
 *
 * KETENTUAN (master, siapmst_pinjaman_sumbers) per sumber pinjaman: % sewa modal per bulan, pembulatan, kode potongan slip.
 * Ubah di layar bila ketentuan berubah — pinjaman BARU memakai ketentuan terbaru, pinjaman lama tetap pada % saat pinjam
 * (snapshot di baris pinjaman, bisa diubah per pinjaman bila memang disepakati).
 *
 * HITUNGAN (sama dengan Excel koperasi):
 *   sewa modal / bulan  = pokok × % sewa
 *   pokok / bulan       = pokok ÷ lama bulan
 *   angsuran / bulan    = BULATKAN KE ATAS (pokok / bulan + sewa modal) ke kelipatan pembulatan (mis. 1.000)
 *   angsuran_tetap      = bila diisi (jadwal bank), menimpa rumus di atas — angsuran / bulan = angsuran_tetap
 *   angsuran ke-n       = selisih bulan kerja dengan bulan awal + 1; berjalan selama 1 ≤ n ≤ lama bulan
 *   pelunasan dipercepat: lunas_periode = bulan kerja pertama yang TIDAK dipotong lagi.
 *
 * BUKU PEMBAYARAN (siaptxn_pinjaman_bayars): satu baris per angsuran terbayar, dikenali lewat `periode` angsurannya.
 *   - GAJI  : dipotong di slip `dipotong_periode` (otomatis saat Terapkan; tunggakan / bulan depan lewat tombol Tagih).
 *   - TUNAI / TRANSFER / LAIN : dibayar di luar gaji → angsuran itu TIDAK dipotong di slip. Boleh dobel (beberapa periode
 *     sekaligus), boleh di muka (periode mendatang). Tunggakan = angsuran yang sudah lewat tanpa pembayaran apa pun.
 *   - Pinjaman lunas bila semua angsuran (lama_bulan) terbayar, atau ditandai Lunasi (pelunasan dipercepat).
 *
 * "Terapkan ke Slip" (gaji.kelola) menulis satu baris potongan per angsuran yang ditagih bulan itu ke slip Draft
 * (kode sesuai sumber: ANGS_KOP / ANGS_BANK / ANGSURAN, keterangan "Koperasi ke-5/15"). Aman diulang: baris kode itu
 * diganti, bukan ditambah; angsuran yang sudah dibayar di luar gaji dilewati. Satu-satunya sumber angsuran slip —
 * Form Bagian (Excel) tidak lagi punya kolom angsuran koperasi / bank.
 */
class PinjamanKaryawan
{
    /** Kode potongan slip yang boleh dipakai sumber pinjaman (urutan cetak di GajiKaryawan::URUTAN). */
    public const KODE_POTONGAN = ['ANGS_KOP' => 'Angsuran Koperasi', 'ANGS_BANK' => 'Angsuran Bank', 'ANGSURAN' => 'Angsuran (lain)'];

    public const PEMBULATAN_PILIHAN = [1, 100, 1000, 10000];

    /** Sumber pembayaran angsuran. GAJI = dipotong slip (otomatis); selain itu dicatat Keuangan di tab Pembayaran. */
    public const SUMBER_BAYAR = ['GAJI' => 'Potong gaji', 'TUNAI' => 'Tunai', 'TRANSFER' => 'Transfer', 'LAIN' => 'Lainnya'];

    /** Ketentuan awal (dipakai migrasi). */
    public const SUMBER_BAWAAN = [
        ['sumber_kode' => 'KOP', 'nama' => 'Koperasi An-Nashir', 'persen_sewa' => 0.8, 'pembulatan' => 1000, 'kode_potongan' => 'ANGS_KOP', 'urutan' => 1],
        ['sumber_kode' => 'BRI', 'nama' => 'Bank BRI', 'persen_sewa' => 0, 'pembulatan' => 1000, 'kode_potongan' => 'ANGS_BANK', 'urutan' => 2],
        ['sumber_kode' => 'RINJANI', 'nama' => 'Rinjani', 'persen_sewa' => 0, 'pembulatan' => 1000, 'kode_potongan' => 'ANGSURAN', 'urutan' => 3],
    ];

    /** Bank-bank umum yang disiapkan NONAKTIF (migrasi 2026_11_06_120000) — nyalakan di tabel Ketentuan saat dipakai. */
    public const BANK_TAMBAHAN = [
        ['sumber_kode' => 'BSI', 'nama' => 'Bank Syariah Indonesia', 'persen_sewa' => 0, 'pembulatan' => 1, 'kode_potongan' => 'ANGS_BANK', 'urutan' => 10],
        ['sumber_kode' => 'MANDIRI', 'nama' => 'Bank Mandiri', 'persen_sewa' => 0, 'pembulatan' => 1, 'kode_potongan' => 'ANGS_BANK', 'urutan' => 11],
        ['sumber_kode' => 'BNI', 'nama' => 'Bank BNI', 'persen_sewa' => 0, 'pembulatan' => 1, 'kode_potongan' => 'ANGS_BANK', 'urutan' => 12],
        ['sumber_kode' => 'BCA', 'nama' => 'Bank BCA', 'persen_sewa' => 0, 'pembulatan' => 1, 'kode_potongan' => 'ANGS_BANK', 'urutan' => 13],
        ['sumber_kode' => 'BTN', 'nama' => 'Bank BTN', 'persen_sewa' => 0, 'pembulatan' => 1, 'kode_potongan' => 'ANGS_BANK', 'urutan' => 14],
        ['sumber_kode' => 'JATIM', 'nama' => 'Bank Jatim', 'persen_sewa' => 0, 'pembulatan' => 1, 'kode_potongan' => 'ANGS_BANK', 'urutan' => 15],
        ['sumber_kode' => 'BPR', 'nama' => 'BPR', 'persen_sewa' => 0, 'pembulatan' => 1, 'kode_potongan' => 'ANGS_BANK', 'urutan' => 16],
        ['sumber_kode' => 'PEGADAIAN', 'nama' => 'Pegadaian', 'persen_sewa' => 0, 'pembulatan' => 1, 'kode_potongan' => 'ANGSURAN', 'urutan' => 20],
    ];

    public const LAMA_MAKS = 240;

    // ─── Ketentuan (master sumber) ────────────────────────────────────────────

    /** @return Collection<string, object> sumber_kode => baris master, urut 'urutan'. */
    public static function sumber(bool $hanyaAktif = false): Collection
    {
        return DB::table('siapmst_pinjaman_sumbers')->when($hanyaAktif, fn($query) => $query->where('active_status', '1'))
            ->orderBy('urutan')->orderBy('nama')->get()->keyBy('sumber_kode');
    }

    /**
     * Tambah / ubah satu sumber. @return string|null pesan galat (null = tersimpan).
     * @param array{sumber_kode: string, nama: string, persen_sewa: mixed, pembulatan: mixed, kode_potongan: string} $data
     */
    public static function simpanSumber(array $data, ?int $userId): ?string
    {
        $kode = strtoupper(trim((string) ($data['sumber_kode'] ?? '')));
        $nama = trim((string) ($data['nama'] ?? ''));
        if (!preg_match('/^[A-Z0-9_]{2,10}$/', $kode)) {
            return 'Kode sumber 2–10 huruf / angka tanpa spasi, mis. KOP atau BRI.';
        }
        if ($nama === '' || mb_strlen($nama) > 40) {
            return 'Nama sumber wajib diisi (maks. 40 huruf).';
        }
        if (!is_numeric($data['persen_sewa'] ?? null) || (float) $data['persen_sewa'] < 0 || (float) $data['persen_sewa'] > 10) {
            return 'Persen sewa modal harus 0–10 (% per bulan).';
        }
        if (!in_array((int) ($data['pembulatan'] ?? 0), self::PEMBULATAN_PILIHAN, true)) {
            return 'Pembulatan harus salah satu dari: ' . implode(', ', self::PEMBULATAN_PILIHAN) . '.';
        }
        if (!isset(self::KODE_POTONGAN[$data['kode_potongan'] ?? ''])) {
            return 'Kode potongan slip tidak dikenal.';
        }

        $baris = ['nama' => $nama, 'persen_sewa' => round((float) $data['persen_sewa'], 3), 'pembulatan' => (int) $data['pembulatan'],
            'kode_potongan' => $data['kode_potongan'], 'updated_by' => $userId, 'updated_at' => now()];
        if (DB::table('siapmst_pinjaman_sumbers')->where('sumber_kode', $kode)->exists()) {
            DB::table('siapmst_pinjaman_sumbers')->where('sumber_kode', $kode)->update($baris);
        } else {
            $urutan = (int) DB::table('siapmst_pinjaman_sumbers')->max('urutan') + 1;
            DB::table('siapmst_pinjaman_sumbers')->insert([...$baris, 'sumber_kode' => $kode, 'urutan' => $urutan, 'active_status' => '1', 'created_at' => now()]);
        }

        return null;
    }

    /** Ubah satu kolom ketentuan di layar (persen_sewa / pembulatan / active_status). */
    public static function ubahSumber(string $kode, string $kolom, mixed $nilai, ?int $userId): bool
    {
        $data = match ($kolom) {
            'persen_sewa'   => is_numeric($nilai) && (float) $nilai >= 0 && (float) $nilai <= 10 ? ['persen_sewa' => round((float) $nilai, 3)] : null,
            'pembulatan'    => in_array((int) $nilai, self::PEMBULATAN_PILIHAN, true) ? ['pembulatan' => (int) $nilai] : null,
            'active_status' => in_array($nilai, ['0', '1'], true) ? ['active_status' => $nilai] : null,
            default         => null,
        };
        if ($data === null) {
            return false;
        }

        return DB::table('siapmst_pinjaman_sumbers')->where('sumber_kode', $kode)
            ->update([...$data, 'updated_by' => $userId, 'updated_at' => now()]) > 0;
    }

    // ─── Hitungan ─────────────────────────────────────────────────────────────

    public static function bulatkan(float $nilai, int $pembulatan): float
    {
        return $pembulatan > 1 ? ceil(round($nilai, 2) / $pembulatan) * $pembulatan : ceil($nilai);
    }

    /** @return array{sewa: float, pokok_bulan: float, angsuran: float, total: float} */
    public static function hitung(float $pokok, float $persen, int $lama, int $pembulatan): array
    {
        $lama = max(1, $lama);
        $sewa = round($pokok * $persen / 100, 2);
        $pokokBulan = self::bulatkan($pokok / $lama, $pembulatan);
        $angsuran = self::bulatkan($pokok / $lama + $sewa, $pembulatan);

        return ['sewa' => $sewa, 'pokok_bulan' => $pokokBulan, 'angsuran' => $angsuran, 'total' => $angsuran * $lama];
    }

    /** Angsuran ke berapa pada bulan kerja $periode (1 = bulan awal; ≤ 0 = belum mulai). */
    public static function angsuranKe(string $bulanAwal, string $periode): int
    {
        $awal = Carbon::createFromFormat('!Y-m', $bulanAwal);
        $kini = Carbon::createFromFormat('!Y-m', $periode);

        return ($kini->year - $awal->year) * 12 + ($kini->month - $awal->month) + 1;
    }

    /** Bulan kerja angsuran ke-$n (1 = bulan awal). */
    public static function periodeKe(string $bulanAwal, int $n): string
    {
        return Carbon::createFromFormat('!Y-m', $bulanAwal)->addMonthsNoOverflow($n - 1)->format('Y-m');
    }

    /**
     * Posisi satu pinjaman pada bulan kerja, memperhitungkan buku pembayaran.
     *   status : belum | berjalan | lunas (semua angsuran terbayar, ATAU total nominal terbayar ≥ total hutang, ATAU masa habis)
     *            | dilunasi (pelunasan dipercepat)
     *   aktif  : angsuran bulan ini masih harus ditagih lewat gaji (berjalan & belum dibayar di luar gaji)
     *   terbayar / tunggakan (periode lewat tanpa pembayaran) / bayar_bulan_ini (baris pembayaran periode ini, bila ada)
     * @param array<string, object> $bayar periode => baris pembayaran pinjaman ini
     * @return array{ke: int, sisa: int, aktif: bool, status: string, terbayar: int, tunggakan: array<int, string>, bayar_bulan_ini: ?object, luar_gaji_bulan_ini: bool}
     */
    public static function posisi(object $pinjaman, string $periode, array $bayar = []): array
    {
        $ke = self::angsuranKe((string) $pinjaman->bulan_awal, $periode);
        $lama = (int) $pinjaman->lama_bulan;
        $terbayar = count($bayar);
        $terbayarNominal = (float) array_sum(array_map(fn($b) => (float) $b->jumlah, $bayar));
        $totalHutang = (float) ($pinjaman->angsuran ?? 0) * $lama; // angsuran diisi lengkapi() sebelum posisi()
        $lunasNominal = $totalHutang > 0 && $terbayarNominal >= $totalHutang - 0.5;
        $tunggakan = [];
        if (!$lunasNominal) {
            for ($n = 1; $n <= min($ke - 1, $lama); $n++) {
                $periodeN = self::periodeKe((string) $pinjaman->bulan_awal, $n);
                if (!isset($bayar[$periodeN]) && ($pinjaman->lunas_periode === null || $periodeN < $pinjaman->lunas_periode)) {
                    $tunggakan[] = $periodeN;
                }
            }
        }
        $status = match (true) {
            $pinjaman->lunas_periode !== null && $periode >= $pinjaman->lunas_periode => 'dilunasi',
            $terbayar >= $lama || $lunasNominal => 'lunas',
            $ke < 1    => 'belum',
            $ke > $lama => 'lunas',
            default    => 'berjalan',
        };
        $bayarIni = $bayar[$periode] ?? null;
        $luarGaji = $bayarIni !== null && $bayarIni->sumber !== 'GAJI';

        return ['ke' => $ke, 'sisa' => $status === 'berjalan' ? max(0, $lama - max($ke, $terbayar)) : 0,
            'aktif' => $status === 'berjalan' && !$luarGaji, 'status' => $status,
            'terbayar' => $terbayar, 'terbayar_nominal' => $terbayarNominal, 'total_hutang' => $totalHutang, 'lunas_nominal' => $lunasNominal,
            'tunggakan' => $tunggakan, 'bayar_bulan_ini' => $bayarIni, 'luar_gaji_bulan_ini' => $luarGaji];
    }

    public static function periodeValid(?string $periode): bool
    {
        return is_string($periode) && (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode);
    }

    // ─── Buku pinjaman ────────────────────────────────────────────────────────

    private static function query()
    {
        return DB::table('siaptxn_pinjamans as p')
            ->join('siapmst_employers as em', 'em.emp_id', '=', 'p.emp_id')
            ->join('siapmst_pinjaman_sumbers as s', 's.sumber_kode', '=', 'p.sumber_kode')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'em.emp_unit_id')
            ->select('p.*', 'em.emp_name', 'em.active_status as emp_aktif', 'un.unit_name', 's.nama as sumber_nama', 's.pembulatan', 's.kode_potongan');
    }

    public static function ambil(int $id, ?string $periode = null): ?object
    {
        $row = self::query()->where('p.pinjaman_id', $id)->first();

        return $row ? self::lengkapi($row, $periode, $periode ? self::bayarSemua()[$id] ?? [] : []) : null;
    }

    /** Seluruh buku pembayaran: pinjaman_id => [periode => baris]. @return array<int, array<string, object>> */
    public static function bayarSemua(): array
    {
        $hasil = [];
        foreach (DB::table('siaptxn_pinjaman_bayars')->orderBy('periode')->get() as $row) {
            $hasil[(int) $row->pinjaman_id][$row->periode] = $row;
        }

        return $hasil;
    }

    /** Tambahkan hitungan (sewa, angsuran, total) dan posisi pada $periode ke satu baris. angsuran_tetap > 0 menimpa rumus. */
    private static function lengkapi(object $row, ?string $periode, array $bayar = []): object
    {
        $hitung = self::hitung((float) $row->pokok, (float) $row->persen_sewa, (int) $row->lama_bulan, (int) $row->pembulatan);
        $tetap = (float) ($row->angsuran_tetap ?? 0);
        $row->sewa = $hitung['sewa'];
        $row->pokok_bulan = $hitung['pokok_bulan'];
        $row->angsuran = $tetap > 0 ? $tetap : $hitung['angsuran'];
        $row->angsuran_ditetapkan = $tetap > 0;
        $row->total = $row->angsuran * max(1, (int) $row->lama_bulan);
        $row->bulan_akhir = Carbon::createFromFormat('!Y-m', (string) $row->bulan_awal)->addMonthsNoOverflow((int) $row->lama_bulan - 1)->format('Y-m');
        if ($periode !== null) {
            foreach (self::posisi($row, $periode, $bayar) as $kunci => $nilai) {
                $row->$kunci = $nilai;
            }
        }

        return $row;
    }

    /**
     * Daftar pinjaman dengan posisi pada bulan kerja. $status: berjalan | selesai (lunas / dilunasi) | semua.
     * Karyawan non-aktif tetap tampil (riwayat) tapi tidak ikut tagihan.
     * @return Collection<int, object>
     */
    public static function daftar(string $periode, ?string $sumber = null, string $status = 'berjalan', string $cari = ''): Collection
    {
        $rows = self::query()
            ->when($sumber, fn($query) => $query->where('p.sumber_kode', $sumber))
            ->when(trim($cari) !== '', function ($query) use ($cari) {
                $kata = mb_strtoupper(trim($cari));
                $query->where(fn($sub) => $sub->whereRaw('UPPER(em.emp_id) LIKE ?', ["%{$kata}%"])->orWhereRaw('UPPER(em.emp_name) LIKE ?', ["%{$kata}%"]));
            })
            ->orderBy('em.emp_name')->orderBy('p.bulan_awal')->get();
        $bayarSemua = self::bayarSemua();
        $rows = $rows->map(fn($row) => self::lengkapi($row, $periode, $bayarSemua[(int) $row->pinjaman_id] ?? []));

        return $rows->filter(fn($row) => match ($status) {
            'berjalan' => $row->status === 'berjalan' || $row->status === 'belum',
            'selesai'  => $row->status === 'lunas' || $row->status === 'dilunasi',
            default    => true,
        })->values();
    }

    /** Pinjaman sudah punya pembayaran tercatat → data pokoknya terkunci (hanya keterangan & pelunasan yang boleh diubah, tidak bisa dihapus). */
    public static function punyaRiwayat(int $id): bool
    {
        return DB::table('siaptxn_pinjaman_bayars')->where('pinjaman_id', $id)->exists();
    }

    /**
     * Tambah ($id null) / ubah satu pinjaman. Pinjaman yang sudah punya riwayat pembayaran: hanya keterangan & lunas_periode
     * yang diubah (kolom lain diabaikan — data valid). @return array{ok: bool, pesan: string, id: int|null}
     * @param array{emp_id: string, sumber_kode: string, pokok: mixed, persen_sewa: mixed, lama_bulan: mixed, bulan_awal: string, lunas_periode: ?string, keterangan: ?string, angsuran_tetap?: mixed} $data
     */
    public static function simpan(?int $id, array $data, ?int $userId): array
    {
        $gagal = fn(string $pesan) => ['ok' => false, 'pesan' => $pesan, 'id' => null];
        $empId = trim((string) ($data['emp_id'] ?? ''));
        if ($empId === '' || !DB::table('siapmst_employers')->where('emp_id', $empId)->where('active_status', '1')->exists()) {
            return $gagal('Pilih karyawan aktif.');
        }
        $sumber = self::sumber()[$data['sumber_kode'] ?? ''] ?? null;
        if (!$sumber) {
            return $gagal('Pilih sumber pinjaman.');
        }
        if (!is_numeric($data['pokok'] ?? null) || (float) $data['pokok'] <= 0 || (float) $data['pokok'] > 999999999999) {
            return $gagal('Pokok pinjaman harus lebih dari 0.');
        }
        if (!is_numeric($data['persen_sewa'] ?? null) || (float) $data['persen_sewa'] < 0 || (float) $data['persen_sewa'] > 10) {
            return $gagal('Persen sewa modal harus 0–10 (% per bulan).');
        }
        $lama = $data['lama_bulan'] ?? null;
        if (!is_numeric($lama) || (float) $lama != (int) $lama || (int) $lama < 1 || (int) $lama > self::LAMA_MAKS) {
            return $gagal('Lama angsuran 1–' . self::LAMA_MAKS . ' bulan.');
        }
        if (!self::periodeValid($data['bulan_awal'] ?? null)) {
            return $gagal('Bulan awal angsuran tidak valid (mm/yyyy).');
        }
        $lunas = $data['lunas_periode'] ?? null;
        $lunas = $lunas === '' ? null : $lunas;
        if ($lunas !== null && (!self::periodeValid($lunas) || $lunas <= $data['bulan_awal'])) {
            return $gagal('Bulan pelunasan harus mm/yyyy setelah bulan awal.');
        }
        $tetap = $data['angsuran_tetap'] ?? null;
        $tetap = $tetap === '' || $tetap === null ? 0 : $tetap;
        if (!is_numeric($tetap) || (float) $tetap < 0 || (float) $tetap > 999999999999) {
            return $gagal('Angsuran tetap harus angka ≥ 0 (0 = pakai rumus).');
        }

        $baris = [
            'emp_id' => $empId, 'sumber_kode' => $sumber->sumber_kode, 'pokok' => round((float) $data['pokok'], 2),
            'persen_sewa' => round((float) $data['persen_sewa'], 3), 'lama_bulan' => (int) $lama, 'bulan_awal' => $data['bulan_awal'],
            'lunas_periode' => $lunas, 'keterangan' => mb_substr(trim((string) ($data['keterangan'] ?? '')), 0, 200) ?: null,
            'angsuran_tetap' => (float) $tetap > 0 ? round((float) $tetap, 2) : null,
            'updated_by' => $userId, 'updated_at' => now(),
        ];
        if ($id) {
            if (self::punyaRiwayat($id)) {
                $baris = ['lunas_periode' => $lunas, 'keterangan' => $baris['keterangan'], 'updated_by' => $userId, 'updated_at' => now()];
            }
            if (DB::table('siaptxn_pinjamans')->where('pinjaman_id', $id)->update($baris) === 0) {
                return $gagal('Pinjaman tidak ditemukan.');
            }
            return ['ok' => true, 'pesan' => self::punyaRiwayat($id) ? 'Keterangan / pelunasan diperbarui (data pokok pinjaman terkunci karena sudah ada pembayaran).' : 'Pinjaman diperbarui.', 'id' => $id];
        }
        // insert() biasa — pinjaman_id dari trigger/sequence (insertGetId memicu warning yajra); id = terbesar milik karyawan itu.
        DB::table('siaptxn_pinjamans')->insert([...$baris, 'created_by' => $userId, 'created_at' => now()]);
        $idBaru = (int) DB::table('siaptxn_pinjamans')->where('emp_id', $empId)->max('pinjaman_id');

        return ['ok' => true, 'pesan' => 'Pinjaman dicatat.', 'id' => $idBaru];
    }

    /** Pelunasan dipercepat: mulai bulan kerja $periode tidak dipotong lagi. */
    public static function lunaskan(int $id, string $periode, ?int $userId): bool
    {
        $row = DB::table('siaptxn_pinjamans')->where('pinjaman_id', $id)->first(['bulan_awal']);
        if (!$row || !self::periodeValid($periode) || $periode <= $row->bulan_awal) {
            return false;
        }

        return DB::table('siaptxn_pinjamans')->where('pinjaman_id', $id)->update(['lunas_periode' => $periode, 'updated_by' => $userId, 'updated_at' => now()]) > 0;
    }

    /**
     * Praktik koperasi: pinjaman BARU melunasi sisa pinjaman lama (karyawan & sumber sama) yang masih berjalan saat
     * pinjaman baru mulai → pinjaman lama ditandai dilunasi mulai bulan awal pinjaman baru (hanya satu angsuran per orang).
     * @return int jumlah pinjaman lama yang ditutup
     */
    public static function tutupPinjamanLama(string $empId, string $sumberKode, string $bulanAwalBaru, ?int $kecualiId, ?int $userId): int
    {
        $ditutup = 0;
        $lamaList = DB::table('siaptxn_pinjamans')->where('emp_id', $empId)->where('sumber_kode', $sumberKode)
            ->whereNull('lunas_periode')->where('bulan_awal', '<', $bulanAwalBaru)
            ->when($kecualiId, fn($query) => $query->where('pinjaman_id', '!=', $kecualiId))->get();
        foreach ($lamaList as $lama) {
            $bulanAkhir = Carbon::createFromFormat('!Y-m', (string) $lama->bulan_awal)->addMonthsNoOverflow((int) $lama->lama_bulan - 1)->format('Y-m');
            if ($bulanAkhir < $bulanAwalBaru) {
                continue; // sudah selesai sendiri sebelum pinjaman baru
            }
            $catatan = 'dilunasi oleh pinjaman baru ' . substr($bulanAwalBaru, 5, 2) . '/' . substr($bulanAwalBaru, 0, 4);
            DB::table('siaptxn_pinjamans')->where('pinjaman_id', $lama->pinjaman_id)->update([
                'lunas_periode' => $bulanAwalBaru,
                'keterangan' => mb_substr(trim(($lama->keterangan ? $lama->keterangan . ' — ' : '') . $catatan), 0, 200),
                'updated_by' => $userId, 'updated_at' => now(),
            ]);
            $ditutup++;
        }

        return $ditutup;
    }

    public static function batalLunas(int $id, ?int $userId): bool
    {
        return DB::table('siaptxn_pinjamans')->where('pinjaman_id', $id)->update(['lunas_periode' => null, 'updated_by' => $userId, 'updated_at' => now()]) > 0;
    }

    /** Hapus pinjaman (salah input, sebelum ada pembayaran). @return string|null pesan galat */
    public static function hapus(int $id): ?string
    {
        if (self::punyaRiwayat($id)) {
            return 'Pinjaman sudah punya pembayaran tercatat — tidak bisa dihapus. Pakai Lunasi bila selesai.';
        }

        return DB::table('siaptxn_pinjamans')->where('pinjaman_id', $id)->delete() > 0 ? null : 'Pinjaman tidak ditemukan.';
    }

    /**
     * Riwayat pembayaran SATU KARYAWAN: semua pinjamannya (urut berjalan dulu) + baris pembayaran + sisa (kurang berapa).
     * @return Collection<int, object> tiap pinjaman punya ->riwayat (Collection), ->sisa_angsuran, ->sisa_nominal
     */
    public static function riwayatKaryawan(string $empId, string $periode): Collection
    {
        $bayarSemua = self::bayarSemua();

        return self::daftar($periode, null, 'semua')->where('emp_id', $empId)
            ->sortBy(fn($row) => ($row->status === 'berjalan' || $row->status === 'belum' ? '0' : '1') . $row->bulan_awal)->values()
            ->map(function ($row) use ($bayarSemua) {
                $row->riwayat = collect($bayarSemua[(int) $row->pinjaman_id] ?? [])->values()->map(function ($b) use ($row) {
                    $b->ke = self::angsuranKe((string) $row->bulan_awal, $b->periode);
                    return $b;
                });
                $selesai = in_array($row->status, ['lunas', 'dilunasi'], true);
                $row->sisa_angsuran = $selesai ? 0 : max(0, (int) $row->lama_bulan - $row->terbayar);
                $row->sisa_nominal = $selesai ? 0.0 : max(0.0, $row->total_hutang - $row->terbayar_nominal); // kurang = total hutang − yang sudah dibayar (nominal)

                return $row;
            });
    }

    // ─── Slip ─────────────────────────────────────────────────────────────────

    /**
     * Tagihan bulan kerja (yang akan / sudah dipotong di slip bulan itu), milik karyawan AKTIF:
     *   - angsuran bulan ini dari pinjaman berjalan yang belum dibayar di luar gaji (baris GAJI dibuat saat Terapkan);
     *   - baris GAJI yang ditagih di bulan ini lewat tombol Tagih (tunggakan periode lewat / bulan depan di muka).
     * @return array<string, array<int, array{pinjaman_id: int, periode: string, sumber_kode: string, kode: string, nilai: float, keterangan: string, ke: int, lama: int}>> emp_id => baris
     */
    public static function tagihan(string $periode): array
    {
        $hasil = [];
        $semua = self::daftar($periode, null, 'semua')->keyBy('pinjaman_id');
        $bayarSemua = self::bayarSemua();
        foreach ($semua as $row) {
            if ($row->emp_aktif !== '1') {
                continue;
            }
            $bayar = $bayarSemua[(int) $row->pinjaman_id] ?? [];
            $periodeDitagih = [];
            if ($row->aktif && !isset($bayar[$periode])) {
                $periodeDitagih[$periode] = (float) $row->angsuran; // otomatis: angsuran bulan ini
            }
            foreach ($bayar as $periodeBayar => $b) {
                if ($b->sumber === 'GAJI' && $b->dipotong_periode === $periode) {
                    $periodeDitagih[$periodeBayar] = (float) $b->jumlah > 0 ? (float) $b->jumlah : (float) $row->angsuran;
                }
            }
            ksort($periodeDitagih);
            foreach ($periodeDitagih as $periodeBayar => $nilai) {
                $ke = self::angsuranKe((string) $row->bulan_awal, $periodeBayar);
                $tanda = $periodeBayar < $periode ? ' (tunggakan)' : ($periodeBayar > $periode ? ' (di muka)' : '');
                $hasil[$row->emp_id][] = ['pinjaman_id' => (int) $row->pinjaman_id, 'periode' => $periodeBayar, 'sumber_kode' => $row->sumber_kode,
                    'kode' => $row->kode_potongan, 'nilai' => $nilai, 'keterangan' => "{$row->sumber_nama} ke-{$ke}/{$row->lama_bulan}{$tanda}",
                    'ke' => $ke, 'lama' => (int) $row->lama_bulan];
            }
        }

        return $hasil;
    }

    // ─── Buku pembayaran ──────────────────────────────────────────────────────

    /** Slip bulan kerja karyawan sudah Final? */
    public static function slipFinal(string $periode, string $empId): bool
    {
        return DB::table('siaptxn_gaji_hdrs')->where('periode', $periode)->where('emp_id', $empId)->value('gaji_status') === GajiKaryawan::STATUS_FINAL;
    }

    /** Riwayat pembayaran satu pinjaman (urut periode) + angsuran ke-n. @return Collection<int, object> */
    public static function riwayatBayar(int $pinjamanId): Collection
    {
        $pinjaman = DB::table('siaptxn_pinjamans')->where('pinjaman_id', $pinjamanId)->first(['bulan_awal']);

        return DB::table('siaptxn_pinjaman_bayars as b')->leftJoin('users as u', 'u.id', '=', 'b.created_by')
            ->where('b.pinjaman_id', $pinjamanId)->orderBy('b.periode')
            ->get(['b.*', 'u.name as oleh'])
            ->map(function ($row) use ($pinjaman) {
                $row->ke = $pinjaman ? self::angsuranKe((string) $pinjaman->bulan_awal, $row->periode) : 0;
                return $row;
            });
    }

    /** Pembayaran LUAR GAJI terbaru (semua pinjaman), untuk tab Pembayaran. @return Collection<int, object> */
    public static function pembayaranLuarGaji(int $batas = 100): Collection
    {
        return DB::table('siaptxn_pinjaman_bayars as b')
            ->join('siaptxn_pinjamans as p', 'p.pinjaman_id', '=', 'b.pinjaman_id')
            ->join('siapmst_employers as em', 'em.emp_id', '=', 'p.emp_id')
            ->join('siapmst_pinjaman_sumbers as s', 's.sumber_kode', '=', 'p.sumber_kode')
            ->leftJoin('users as u', 'u.id', '=', 'b.created_by')
            ->where('b.sumber', '!=', 'GAJI')
            ->orderByDesc('b.tanggal_bayar')->orderByDesc('b.bayar_id')->limit($batas)
            ->get(['b.*', 'p.emp_id', 'p.bulan_awal', 'p.lama_bulan', 'em.emp_name', 's.nama as sumber_nama', 'u.name as oleh'])
            ->map(function ($row) {
                $row->ke = self::angsuranKe((string) $row->bulan_awal, $row->periode);
                return $row;
            });
    }

    /**
     * Catat pembayaran LUAR GAJI untuk satu atau beberapa angsuran (dobel / di muka): satu baris per periode.
     * Periode yang sudah dipotong di slip FINAL tidak bisa diganti; yang baru ditagih di slip Draft diganti (slip
     * dibersihkan saat Terapkan berikutnya).
     * @param array<int, string> $periodeList bulan kerja angsuran (YYYY-MM)
     * @return array{dibuat: int, dilewati: array<int, string>}
     */
    public static function catatBayar(int $pinjamanId, array $periodeList, string $sumber, float $jumlah, ?string $tanggalBayar, ?string $keterangan, ?int $userId): array
    {
        $hasil = ['dibuat' => 0, 'dilewati' => []];
        $pinjaman = self::ambil($pinjamanId);
        if (!$pinjaman || !isset(self::SUMBER_BAYAR[$sumber]) || $sumber === 'GAJI' || $jumlah <= 0 || $jumlah > 999999999999) {
            $hasil['dilewati'][] = 'Data pembayaran tidak valid.';
            return $hasil;
        }
        $tanggal = $tanggalBayar ? Carbon::createFromFormat('!Y-m-d', $tanggalBayar) : now()->startOfDay();
        DB::transaction(function () use ($pinjamanId, $pinjaman, $periodeList, $sumber, $jumlah, $tanggal, $keterangan, $userId, &$hasil) {
            foreach (array_unique($periodeList) as $periode) {
                $ke = self::periodeValid($periode) ? self::angsuranKe((string) $pinjaman->bulan_awal, $periode) : 0;
                if ($ke < 1 || $ke > (int) $pinjaman->lama_bulan) {
                    $hasil['dilewati'][] = "{$periode}: di luar masa angsuran (ke-1 s/d ke-{$pinjaman->lama_bulan}).";
                    continue;
                }
                $ada = DB::table('siaptxn_pinjaman_bayars')->where('pinjaman_id', $pinjamanId)->where('periode', $periode)->first();
                if ($ada) {
                    if ($ada->sumber !== 'GAJI') {
                        $hasil['dilewati'][] = "{$periode} (ke-{$ke}): sudah dibayar " . (self::SUMBER_BAYAR[$ada->sumber] ?? $ada->sumber) . '.';
                        continue;
                    }
                    if (self::slipFinal((string) $ada->dipotong_periode, (string) $pinjaman->emp_id)) {
                        $hasil['dilewati'][] = "{$periode} (ke-{$ke}): sudah dipotong di slip Final " . self::labelPeriode((string) $ada->dipotong_periode) . '.';
                        continue;
                    }
                    DB::table('siaptxn_pinjaman_bayars')->where('bayar_id', $ada->bayar_id)->delete(); // tagihan gaji Draft diganti pembayaran luar gaji
                }
                DB::table('siaptxn_pinjaman_bayars')->insert([
                    'pinjaman_id' => $pinjamanId, 'periode' => $periode, 'sumber' => $sumber, 'dipotong_periode' => null,
                    'jumlah' => round($jumlah, 2), 'tanggal_bayar' => Absensi::tanggalSql($tanggal),
                    'keterangan' => mb_substr(trim((string) $keterangan), 0, 200) ?: null,
                    'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $hasil['dibuat']++;
            }
        });

        return $hasil;
    }

    /**
     * Tagih angsuran periode lain di slip bulan kerja $dipotongPeriode (tunggakan yang lewat, atau bulan depan di muka):
     * baris GAJI dengan dipotong_periode = bulan kerja itu. Ditulis ke slip saat Terapkan.
     * @return array{dibuat: int, dilewati: array<int, string>}
     */
    public static function tagihDiSlip(int $pinjamanId, array $periodeList, string $dipotongPeriode, ?int $userId): array
    {
        $hasil = ['dibuat' => 0, 'dilewati' => []];
        $pinjaman = self::ambil($pinjamanId);
        if (!$pinjaman || !self::periodeValid($dipotongPeriode)) {
            $hasil['dilewati'][] = 'Pinjaman / bulan kerja tidak valid.';
            return $hasil;
        }
        if (self::slipFinal($dipotongPeriode, (string) $pinjaman->emp_id)) {
            $hasil['dilewati'][] = 'Slip ' . self::labelPeriode($dipotongPeriode) . ' sudah Final — buka ke Draft dulu.';
            return $hasil;
        }
        foreach (array_unique($periodeList) as $periode) {
            $ke = self::periodeValid($periode) ? self::angsuranKe((string) $pinjaman->bulan_awal, $periode) : 0;
            if ($ke < 1 || $ke > (int) $pinjaman->lama_bulan) {
                $hasil['dilewati'][] = "{$periode}: di luar masa angsuran.";
                continue;
            }
            if (DB::table('siaptxn_pinjaman_bayars')->where('pinjaman_id', $pinjamanId)->where('periode', $periode)->exists()) {
                $hasil['dilewati'][] = "{$periode} (ke-{$ke}): sudah terbayar / sudah ditagih.";
                continue;
            }
            DB::table('siaptxn_pinjaman_bayars')->insert([
                'pinjaman_id' => $pinjamanId, 'periode' => $periode, 'sumber' => 'GAJI', 'dipotong_periode' => $dipotongPeriode,
                'jumlah' => (float) $pinjaman->angsuran, 'tanggal_bayar' => null, 'keterangan' => $periode < $dipotongPeriode ? 'tunggakan ditagih di slip' : 'ditagih di muka',
                'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $hasil['dibuat']++;
        }

        return $hasil;
    }

    /** Hapus satu baris pembayaran. Baris GAJI yang slipnya sudah Final tidak bisa dihapus. @return string|null pesan galat */
    public static function hapusBayar(int $bayarId): ?string
    {
        $row = DB::table('siaptxn_pinjaman_bayars as b')->join('siaptxn_pinjamans as p', 'p.pinjaman_id', '=', 'b.pinjaman_id')
            ->where('b.bayar_id', $bayarId)->first(['b.*', 'p.emp_id']);
        if (!$row) {
            return 'Pembayaran tidak ditemukan.';
        }
        if ($row->sumber === 'GAJI' && $row->dipotong_periode && self::slipFinal((string) $row->dipotong_periode, (string) $row->emp_id)) {
            return 'Sudah dipotong di slip Final ' . self::labelPeriode((string) $row->dipotong_periode) . ' — tidak bisa dihapus.';
        }
        DB::table('siaptxn_pinjaman_bayars')->where('bayar_id', $bayarId)->delete();

        return null;
    }

    /**
     * Isi riwayat angsuran yang sudah dipotong gaji SEBELUM buku pembayaran dipakai (mis. pinjaman hasil impor):
     * semua periode ≤ $sampaiPeriode (dan < lunas_periode) yang belum punya baris → GAJI, dipotong di bulannya sendiri.
     * @return int jumlah baris dibuat
     */
    public static function isiRiwayatGaji(string $sampaiPeriode, ?int $userId, ?int $hanyaPinjamanId = null): int
    {
        $dibuat = 0;
        $bayarSemua = self::bayarSemua();
        $loanList = DB::table('siaptxn_pinjamans')->when($hanyaPinjamanId, fn($query) => $query->where('pinjaman_id', $hanyaPinjamanId))->get();
        foreach ($loanList as $row) {
            $hitung = self::hitung((float) $row->pokok, (float) $row->persen_sewa, (int) $row->lama_bulan,
                (int) (DB::table('siapmst_pinjaman_sumbers')->where('sumber_kode', $row->sumber_kode)->value('pembulatan') ?? 1000));
            $angsuran = (float) ($row->angsuran_tetap ?? 0) > 0 ? (float) $row->angsuran_tetap : $hitung['angsuran'];
            for ($n = 1; $n <= (int) $row->lama_bulan; $n++) {
                $periode = self::periodeKe((string) $row->bulan_awal, $n);
                if ($periode > $sampaiPeriode || ($row->lunas_periode !== null && $periode >= $row->lunas_periode) || isset($bayarSemua[(int) $row->pinjaman_id][$periode])) {
                    continue;
                }
                DB::table('siaptxn_pinjaman_bayars')->insert([
                    'pinjaman_id' => (int) $row->pinjaman_id, 'periode' => $periode, 'sumber' => 'GAJI', 'dipotong_periode' => $periode,
                    'jumlah' => $angsuran, 'tanggal_bayar' => Absensi::tanggalSql(Carbon::createFromFormat('!Y-m', $periode)->endOfMonth()),
                    'keterangan' => 'riwayat sebelum SIAP', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $dibuat++;
            }
        }

        return $dibuat;
    }

    public static function labelPeriode(string $periode): string
    {
        return self::periodeValid($periode) ? substr($periode, 5, 2) . '/' . substr($periode, 0, 4) : $periode;
    }

    /**
     * Sinkronkan baris GAJI otomatis bulan kerja $periode dengan keadaan buku: pinjaman berjalan yang belum punya baris
     * periode itu → baris GAJI (dipotong bulan ini); baris GAJI otomatis (periode = dipotong) milik pinjaman yang tidak lagi
     * ditagih (lunas / dilunasi / dihapus / karyawan non-aktif) → dihapus. Slip Final tidak disentuh.
     */
    private static function sinkronBayarGaji(string $periode, ?int $userId): void
    {
        $semua = self::daftar($periode, null, 'semua');
        $final = DB::table('siaptxn_gaji_hdrs')->where('periode', $periode)->where('gaji_status', GajiKaryawan::STATUS_FINAL)->pluck('emp_id')->flip();
        $bayarSemua = self::bayarSemua();
        foreach ($semua as $row) {
            if (isset($final[$row->emp_id])) {
                continue;
            }
            $id = (int) $row->pinjaman_id;
            $adaIni = $bayarSemua[$id][$periode] ?? null;
            $ditagih = $row->aktif && $row->emp_aktif === '1';
            if ($ditagih && !$adaIni) {
                DB::table('siaptxn_pinjaman_bayars')->insert([
                    'pinjaman_id' => $id, 'periode' => $periode, 'sumber' => 'GAJI', 'dipotong_periode' => $periode,
                    'jumlah' => (float) $row->angsuran, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif (!$ditagih && $adaIni && $adaIni->sumber === 'GAJI' && $adaIni->dipotong_periode === $periode && $adaIni->keterangan === null) {
                DB::table('siaptxn_pinjaman_bayars')->where('bayar_id', $adaIni->bayar_id)->delete();
            } elseif ($ditagih && $adaIni && $adaIni->sumber === 'GAJI' && $adaIni->dipotong_periode === $periode && abs((float) $adaIni->jumlah - (float) $row->angsuran) >= 0.01) {
                DB::table('siaptxn_pinjaman_bayars')->where('bayar_id', $adaIni->bayar_id)->update(['jumlah' => (float) $row->angsuran, 'updated_at' => now()]); // ketentuan / pinjaman diubah
            }
        }
    }

    /**
     * Tulis angsuran ke slip Draft bulan ini. AMAN DIULANG: untuk kode potongan yang ditagih, semua baris kode itu
     * di slip karyawan diganti; baris hasil terapkan lama (tarif > 0 = lama bulan; hanya menu ini yang mengisinya)
     * pada karyawan / kode yang kini tanpa tagihan dibersihkan — pinjaman lunas otomatis berhenti.
     * @return array{diperbarui: int, dibersihkan: int, final: array<int, string>, tanpaSlip: array<int, string>}
     */
    public static function terapkan(string $periode, ?int $userId): array
    {
        self::sinkronBayarGaji($periode, $userId);
        $tagihan = self::tagihan($periode);
        $hasil = ['diperbarui' => 0, 'dibersihkan' => 0, 'final' => [], 'tanpaSlip' => []];
        $kodeList = array_keys(self::KODE_POTONGAN);
        $slipList = DB::table('siaptxn_gaji_hdrs as h')->join('siapmst_employers as em', 'em.emp_id', '=', 'h.emp_id')
            ->where('h.periode', $periode)->get(['h.gaji_id', 'h.emp_id', 'h.gaji_status', 'em.emp_name'])->keyBy('emp_id');

        foreach ($tagihan as $empId => $barisList) {
            $slip = $slipList[$empId] ?? null;
            if (!$slip) {
                $hasil['tanpaSlip'][] = DB::table('siapmst_employers')->where('emp_id', $empId)->value('emp_name') ?? $empId;
                continue;
            }
            if ($slip->gaji_status === GajiKaryawan::STATUS_FINAL) {
                $hasil['final'][] = $slip->emp_name;
                continue;
            }
            $gajiId = (int) $slip->gaji_id;
            DB::transaction(function () use ($gajiId, $barisList, $kodeList) {
                $kodeDitagih = array_unique(array_column($barisList, 'kode'));
                DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->whereIn('kode', $kodeDitagih)->delete();
                DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->whereIn('kode', array_diff($kodeList, $kodeDitagih))->where('tarif', '>', 0)->delete();
                foreach ($barisList as $baris) {
                    GajiKaryawan::simpanBaris($gajiId, ['jenis' => 'P', 'kode' => $baris['kode'], 'nilai' => $baris['nilai'],
                        'jumlah' => $baris['ke'], 'tarif' => $baris['lama'], 'keterangan' => $baris['keterangan']]);
                }
            });
            GajiKaryawan::hitungUlang($gajiId);
            $hasil['diperbarui']++;
        }

        // Bekas terapkan pada karyawan yang kini tanpa tagihan (lunas / dihapus / non-aktif).
        $sisaLama = DB::table('siaptxn_gaji_dtls as d')->join('siaptxn_gaji_hdrs as h', 'h.gaji_id', '=', 'd.gaji_id')
            ->where('h.periode', $periode)->where('h.gaji_status', GajiKaryawan::STATUS_DRAFT)
            ->whereIn('d.kode', $kodeList)->where('d.tarif', '>', 0)
            ->when($tagihan !== [], fn($query) => $query->whereNotIn('h.emp_id', array_map('strval', array_keys($tagihan))))
            ->distinct()->pluck('h.gaji_id');
        foreach ($sisaLama as $gajiId) {
            DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->whereIn('kode', $kodeList)->where('tarif', '>', 0)->delete();
            GajiKaryawan::hitungUlang((int) $gajiId);
            $hasil['dibersihkan']++;
        }

        $data = ['diterapkan_at' => now(), 'diterapkan_oleh' => $userId, 'updated_at' => now()];
        DB::table('siaptxn_pinjaman_hdrs')->where('periode', $periode)->exists()
            ? DB::table('siaptxn_pinjaman_hdrs')->where('periode', $periode)->update($data)
            : DB::table('siaptxn_pinjaman_hdrs')->insert([...$data, 'periode' => $periode, 'created_at' => now()]);

        return $hasil;
    }

    /**
     * Cocokkan tagihan dengan slip per karyawan — penanda "perlu diterapkan ulang".
     * @return array<string, array{status: string, hitung: float, slip: float}> status: sesuai | beda | final | tanpa_slip
     */
    public static function cocokSlip(string $periode, ?array $tagihan = null): array
    {
        $tagihan ??= self::tagihan($periode);
        $kodeList = array_keys(self::KODE_POTONGAN);
        $slip = DB::table('siaptxn_gaji_hdrs as h')
            ->leftJoin('siaptxn_gaji_dtls as d', fn($join) => $join->on('d.gaji_id', '=', 'h.gaji_id')->whereIn('d.kode', $kodeList))
            ->where('h.periode', $periode)
            ->groupBy('h.emp_id', 'h.gaji_status')
            ->select('h.emp_id', 'h.gaji_status', DB::raw('NVL(SUM(ABS(d.nilai)), 0) AS potongan'),
                DB::raw('SUM(CASE WHEN d.tarif > 0 THEN 1 ELSE 0 END) AS baris_terapkan'))
            ->get()->keyBy('emp_id');

        $hasil = [];
        foreach ($slip->keys()->merge(array_keys($tagihan))->unique() as $empId) {
            $hitung = array_sum(array_column($tagihan[$empId] ?? [], 'nilai'));
            $row = $slip[$empId] ?? null;
            if (!isset($tagihan[$empId]) && (int) ($row->baris_terapkan ?? 0) === 0) {
                continue; // bukan peminjam & tidak pernah diterapkan — angsuran manual di rincian bukan urusan menu ini
            }
            $diSlip = (float) ($row->potongan ?? 0);
            $hasil[$empId] = ['hitung' => $hitung, 'slip' => $diSlip, 'status' => match (true) {
                !$row => $hitung > 0 ? 'tanpa_slip' : 'sesuai',
                abs($hitung - $diSlip) < 0.5 => 'sesuai',
                $row->gaji_status === GajiKaryawan::STATUS_FINAL => 'final',
                default => 'beda',
            }];
        }

        return $hasil;
    }

    public static function terakhirDiterapkan(string $periode): ?object
    {
        return DB::table('siaptxn_pinjaman_hdrs as h')->leftJoin('users as u', 'u.id', '=', 'h.diterapkan_oleh')
            ->where('h.periode', $periode)->first(['h.diterapkan_at', 'u.name']);
    }

    /** Ringkasan per sumber pada bulan kerja: jumlah pinjaman berjalan & total angsuran bulan ini. @return array<string, array{jumlah: int, angsuran: float, pokok: float}> */
    public static function rekap(string $periode): array
    {
        $hasil = [];
        foreach (self::sumber() as $kode => $sumber) {
            $hasil[$kode] = ['jumlah' => 0, 'angsuran' => 0.0, 'pokok' => 0.0];
        }
        foreach (self::daftar($periode, null, 'berjalan') as $row) {
            if ($row->status === 'berjalan' && $row->emp_aktif === '1') {
                $hasil[$row->sumber_kode]['jumlah']++;
                $hasil[$row->sumber_kode]['pokok'] += (float) $row->pokok;
            }
        }
        foreach (self::tagihan($periode) as $barisList) {
            foreach ($barisList as $baris) {
                $hasil[$baris['sumber_kode']]['angsuran'] += $baris['nilai'];
            }
        }

        return $hasil;
    }

    /** Karyawan aktif yang bisa dipinjami (punya parameter gaji → punya slip untuk dipotong). @return Collection<int, object> */
    public static function karyawanPilihan(): Collection
    {
        return DB::table('siapmst_employers as em')
            ->join('siapmst_gaji_karyawans as gk', 'gk.emp_id', '=', 'em.emp_id')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'em.emp_unit_id')
            ->where('em.active_status', '1')->orderBy('em.emp_name')
            ->get(['em.emp_id', 'em.emp_name', 'un.unit_name']);
    }
}
