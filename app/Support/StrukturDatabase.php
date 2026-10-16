<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Struktur database SIAP — dibaca langsung dari kamus data Oracle (user_tab_columns, user_constraints),
 * jadi selalu sesuai database. Dipakai:
 *   - perintah `php artisan siap:struktur-db` → database/sql/siap-struktur.sql + public/images/panduan/erd-siap.svg
 *   - halaman Panduan › Struktur Database.
 */
class StrukturDatabase
{
    public const BERKAS_SQL = 'database/sql/siap-struktur.sql';
    public const BERKAS_SVG = 'images/panduan/erd-siap.svg';   // relatif public/

    /** Modul → [warna kepala tabel di ERD, keterangan, daftar tabel]. Tabel yang tidak terdaftar = infrastruktur Laravel. */
    public const MODUL = [
        'Karyawan & Organisasi' => ['#157547', 'Data karyawan, unit kerja, lokasi absen HP, STR/SIP nakes', [
            'SIAPMST_EMPLOYERS', 'SIAPMST_UNIT_KERJAS', 'SIAPMST_LOKASI_ABSENS', 'SIAPMST_EMP_LICENSES',
        ]],
        'Absensi' => ['#2563eb', 'Shift, jadwal dinas, absensi harian (kiosk / HP / manual), aturan absen', [
            'SIAPMST_SHIFTS', 'SIAPTXN_JADWALS', 'SIAPTXN_ABSENSIS', 'SIAPMST_PENGATURAN_ABSENSIS',
        ]],
        'Gaji' => ['#b45309', 'Parameter gaji per karyawan, slip gaji bulanan (header + rincian)', [
            'SIAPMST_GAJI_KARYAWANS', 'SIAPTXN_GAJI_HDRS', 'SIAPTXN_GAJI_DTLS',
        ]],
        'Sistem' => ['#475569', 'User login, role & permission (Spatie), identitas instansi', [
            'USERS', 'ROLES', 'PERMISSIONS', 'MODEL_HAS_ROLES', 'MODEL_HAS_PERMISSIONS', 'ROLE_HAS_PERMISSIONS', 'SIAPMST_IDENTITAS',
        ]],
        'Aset' => ['#7c3aed', 'Ruang, inventaris alat, kalibrasi, pemeliharaan, perbaikan', [
            'SIAPMST_RUANGS', 'SIAPMST_ASSETS', 'SIAPTXN_KALIBRASIS', 'SIAPTXN_PEMELIHARAANS', 'SIAPTXN_PERBAIKANS',
        ]],
    ];

    /**
     * Relasi LOGIS (tanpa foreign key di database) yang tetap penting dipahami: [anak, kolom, induk, kolom, keterangan].
     * Kolom updated_by / *_oleh di banyak tabel juga menunjuk users.id — tidak digambar supaya diagram tetap terbaca.
     */
    public const RELASI_LOGIS = [
        ['USERS', 'EMP_ID', 'SIAPMST_EMPLOYERS', 'EMP_ID', 'akun ↔ karyawan (kepala ruang, absen HP)'],
        ['MODEL_HAS_ROLES', 'MODEL_ID', 'USERS', 'ID', 'role milik user'],
        ['SIAPTXN_PERBAIKANS', 'PM_ID', 'SIAPTXN_PEMELIHARAANS', 'PM_ID', 'perbaikan dari temuan pemeliharaan'],
    ];

    /** @return Collection<int, string> semua tabel milik skema, urut nama */
    public static function tabel(): Collection
    {
        return collect(DB::select('SELECT table_name FROM user_tables ORDER BY table_name'))->pluck('table_name');
    }

    public static function modulTabel(string $tabel): ?string
    {
        foreach (self::MODUL as $modul => [, , $daftar]) {
            if (in_array($tabel, $daftar, true)) {
                return $modul;
            }
        }

        return null;
    }

    /** @return Collection<string, Collection> kolom per tabel: column_name, tipe, nullable, pk, fk (tabel induk) */
    public static function kolom(): Collection
    {
        $pk = collect(DB::select("SELECT c.table_name, cc.column_name FROM user_constraints c
            JOIN user_cons_columns cc ON cc.constraint_name = c.constraint_name WHERE c.constraint_type = 'P'"))
            ->map(fn($row) => "{$row->table_name}.{$row->column_name}")->flip();
        $fk = self::relasi()->mapWithKeys(fn($row) => ["{$row->table_name}.{$row->column_name}" => $row->r_table]);

        return collect(DB::select('SELECT table_name, column_name, data_type, data_length, data_precision, data_scale, char_length, nullable
            FROM user_tab_columns ORDER BY table_name, column_id'))
            ->map(function ($row) use ($pk, $fk) {
                $kunci = "{$row->table_name}.{$row->column_name}";
                $row->tipe = self::tipe($row);
                $row->pk = $pk->has($kunci);
                $row->fk = $fk[$kunci] ?? null;

                return $row;
            })
            ->groupBy('table_name');
    }

    /** @return Collection<int, object> foreign key: table_name, column_name, r_table, r_column, constraint_name, delete_rule */
    public static function relasi(): Collection
    {
        return collect(DB::select("SELECT a.table_name, c.column_name, b.table_name AS r_table, d.column_name AS r_column,
                a.constraint_name, a.delete_rule
            FROM user_constraints a
            JOIN user_cons_columns c ON c.constraint_name = a.constraint_name
            JOIN user_constraints b ON b.constraint_name = a.r_constraint_name
            JOIN user_cons_columns d ON d.constraint_name = b.constraint_name AND d.position = c.position
            WHERE a.constraint_type = 'R'
            ORDER BY a.table_name, c.column_name"));
    }

    private static function tipe(object $row): string
    {
        return match ($row->data_type) {
            'NUMBER'   => $row->data_precision !== null ? "NUMBER({$row->data_precision}" . ((int) $row->data_scale > 0 ? ",{$row->data_scale}" : '') . ')' : 'NUMBER',
            'VARCHAR2', 'CHAR', 'NVARCHAR2' => "{$row->data_type}(" . ((int) $row->char_length ?: (int) $row->data_length) . ')',
            default    => preg_replace('/\(\d\)/', '', (string) $row->data_type),
        };
    }

    // ─── SQL ──────────────────────────────────────────────────────────────────

    /** DDL lengkap yang bisa langsung dieksekusi di skema kosong (sequence → tabel → index → FK → trigger → data migrations). */
    public static function sql(): string
    {
        DB::statement("BEGIN
            DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'STORAGE', FALSE);
            DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'SEGMENT_ATTRIBUTES', FALSE);
            DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'SQLTERMINATOR', TRUE);
            DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'REF_CONSTRAINTS', FALSE);
            DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM, 'PRETTY', TRUE);
        END;");

        $ddl = fn(string $jenis, string $nama) => self::bersihkan(OracleLob::toString(
            DB::selectOne('SELECT DBMS_METADATA.GET_DDL(?, ?) AS ddl FROM dual', [$jenis, $nama])->ddl));

        $tabel = self::tabel();
        $bagian = [];

        $bagian[] = self::judul('1. SEQUENCE (auto increment kolom id)');
        foreach (DB::select('SELECT sequence_name FROM user_sequences ORDER BY sequence_name') as $row) {
            $bagian[] = preg_replace('/START WITH \d+/', 'START WITH 1', $ddl('SEQUENCE', $row->sequence_name));
        }

        $bagian[] = self::judul('2. TABEL (+ primary key, unique, check)');
        foreach ($tabel as $nama) {
            $modul = self::modulTabel($nama) ?? 'Infrastruktur Laravel';
            $bagian[] = "-- {$nama} — {$modul}\n" . $ddl('TABLE', $nama);
        }

        $bagian[] = self::judul('3. INDEX tambahan (di luar index primary key / unique)');
        $index = DB::select('SELECT index_name FROM user_indexes WHERE index_type <> \'LOB\'
            AND index_name NOT IN (SELECT index_name FROM user_constraints WHERE index_name IS NOT NULL) ORDER BY table_name, index_name');
        foreach ($index as $row) {
            $bagian[] = $ddl('INDEX', $row->index_name);
        }

        $bagian[] = self::judul('4. FOREIGN KEY (relasi antar tabel)');
        foreach (self::relasi()->unique('constraint_name') as $row) {
            $bagian[] = $ddl('REF_CONSTRAINT', $row->constraint_name);
        }

        $bagian[] = self::judul('5. TRIGGER (isi kolom id dari sequence)');
        foreach (DB::select('SELECT trigger_name FROM user_triggers ORDER BY trigger_name') as $row) {
            $bagian[] = $ddl('TRIGGER', $row->trigger_name);
        }

        $bagian[] = self::judul('6. DATA tabel migrations — supaya `php artisan migrate` tidak membuat ulang tabel di atas');
        foreach (DB::table('migrations')->orderBy('id')->get() as $row) {
            $bagian[] = "INSERT INTO migrations (migration, batch) VALUES ('" . str_replace("'", "''", $row->migration) . "', 1);";
        }
        $bagian[] = 'COMMIT;';

        $kepala = "-- ═══════════════════════════════════════════════════════════════════════════\n"
            . "-- SIAP — struktur database (Oracle)\n"
            . '-- Dibuat otomatis oleh `php artisan siap:struktur-db` pada ' . now()->format('d/m/Y H:i') . "\n"
            . '-- ' . $tabel->count() . ' tabel · ' . self::relasi()->unique('constraint_name')->count() . " foreign key.\n"
            . "--\n"
            . "-- Cara pakai: login sebagai user skema SIAP yang masih KOSONG, lalu jalankan berkas ini\n"
            . "-- (SQL*Plus / SQL Developer: Run Script / F5). Sesudahnya jalankan seeder data awal bila perlu.\n"
            . "-- Struktur sama dengan hasil `php artisan migrate` — pilih salah satu, bukan keduanya.\n"
            . "-- ═══════════════════════════════════════════════════════════════════════════\n";

        return $kepala . "\n" . implode("\n\n", $bagian) . "\n";
    }

    /** Buang nama skema ("SIAP".) & spasi berlebih supaya bisa dijalankan di skema mana pun. */
    private static function bersihkan(string $ddl): string
    {
        $skema = strtoupper((string) DB::selectOne('SELECT USER AS u FROM dual')->u);
        $ddl = str_replace("\"{$skema}\".", '', $ddl);

        return trim(preg_replace("/\n\s*\n/", "\n", $ddl));
    }

    private static function judul(string $teks): string
    {
        return "-- ───────────────────────────────────────────────────────────────────────────\n-- {$teks}\n-- ───────────────────────────────────────────────────────────────────────────";
    }

    // ─── ERD (graphviz dot) ───────────────────────────────────────────────────

    /** Sumber graphviz: tabel per modul (cluster) + garis FK antar kolom + relasi logis putus-putus. */
    public static function dot(): string
    {
        $kolom = self::kolom();
        $e = fn($teks) => htmlspecialchars((string) $teks, ENT_XML1);
        $baris = ['digraph SIAP {', '  graph [rankdir=LR, newrank=true, splines=true, nodesep=0.3, ranksep=0.9, fontname="Helvetica", bgcolor="white", pad=0.3];',
            '  node [shape=plaintext, fontname="Helvetica", fontsize=10];', '  edge [color="#64748b", arrowhead=crow, arrowtail=none, dir=both, arrowsize=0.8];'];

        foreach (self::MODUL as $modul => [$warna, $keterangan, $daftar]) {
            $baris[] = '  subgraph "cluster_' . $e($modul) . '" {';
            $baris[] = '    label=<<B>' . $e($modul) . '</B><BR/><FONT POINT-SIZE="9">' . $e($keterangan) . '</FONT>>; fontsize=13; style="rounded,dashed"; color="' . $warna . '"; fontcolor="' . $warna . '";';
            foreach ($daftar as $tabel) {
                if (!$kolom->has($tabel)) {
                    continue;
                }
                $isi = '<TR><TD BGCOLOR="' . $warna . '" COLSPAN="2"><FONT COLOR="white"><B>' . $e(strtolower($tabel)) . '</B></FONT></TD></TR>';
                foreach ($kolom[$tabel] as $row) {
                    $penanda = trim(($row->pk ? 'PK ' : '') . ($row->fk ? 'FK' : ''));
                    $nama = strtolower($row->column_name);
                    $namaTampil = $row->pk ? "<B>{$e($nama)}</B>" : ($row->fk ? "<I>{$e($nama)}</I>" : $e($nama));
                    $isi .= '<TR><TD ALIGN="LEFT" PORT="' . $e($row->column_name) . '">' . ($penanda ? '<FONT COLOR="' . $warna . '">' . $penanda . '</FONT> ' : '') . $namaTampil
                        . '</TD><TD ALIGN="LEFT"><FONT COLOR="#94a3b8">' . $e(strtolower($row->tipe)) . ($row->nullable === 'N' && !$row->pk ? ' *' : '') . '</FONT></TD></TR>';
                }
                $baris[] = '    "' . $tabel . '" [label=<<TABLE BORDER="1" CELLBORDER="0" CELLSPACING="0" CELLPADDING="3" COLOR="' . $warna . '">' . $isi . '</TABLE>>];';
            }
            $baris[] = '  }';
        }

        foreach (self::relasi() as $row) {
            if (self::modulTabel($row->table_name) && self::modulTabel($row->r_table)) {
                $baris[] = "  \"{$row->r_table}\":\"{$row->r_column}\":e -> \"{$row->table_name}\":\"{$row->column_name}\":w [arrowtail=tee];";
            }
        }
        foreach (self::RELASI_LOGIS as [$anak, $kolomAnak, $induk, $kolomInduk, $keterangan]) {
            $baris[] = "  \"{$induk}\":\"{$kolomInduk}\":e -> \"{$anak}\":\"{$kolomAnak}\":w [style=dashed, color=\"#94a3b8\", arrowtail=tee, tooltip=\"{$e($keterangan)}\"];";
        }
        $baris[] = '}';

        return implode("\n", $baris);
    }
}
