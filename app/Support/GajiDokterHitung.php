<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Mesin hitung slip gaji dokter di SIAP (tahap 2 pembanding, tahap 3 sumber) — salinan setia rumus SIRUS
 * (App\Support\GajiDokter\GajiDokter::susunDetail + hitungUlang + potonganRs; database/sql/2026_08_04_install_gaji_dokter.sql):
 *
 *   JASA       = Σ baris J komponen (per kapita: grup RI/RJ diganti satu baris KAPITA = pasien × tarif)
 *   TUNJANGAN  = Σ baris J 'TUNJ %' (kena pajak)
 *   TOTAL GAJI = jasa + tunjangan + pokok (A) | max(jasa, pokok) + tunjangan (G) | jasa + tunjangan (N)
 *   POT. RS    = −persen × total (T) | −persen × (total − pokok) (J) | 0 (N) | −Σ aturan per komponen (B)
 *   PPh 21     = −floor(persen × (total + pot. RS) × faktor NPWP), faktor 1,2 bila tanpa NPWP
 *   DITERIMA   = total + pot. RS + PPh 21 + Σ P lain + Σ T
 *   Tanda: potongan NEGATIF; hanya PPh yang dibulatkan (ke bawah), komponen lain tidak.
 *
 * Masukan transaksi = baris lampiran (komponen/desc_doc, grup/group_doc, nominal, pasien) — dari API /transaksi (tahap 3)
 * atau dari snapshot siaptxn_gdok_lamps (pembanding tahap 2). Baris grup kapita ikut dikembalikan sebagai lampiran
 * dengan masuk_slip = 0 (dasar hitung, bukan uang diterima).
 */
class GajiDokterHitung
{
    /**
     * @param array $struktur hasil GajiDokterStruktur::rapikan()
     * @param iterable $transaksi baris {komponen, grup?, nominal, pasien} (objek atau array)
     * @param iterable $manual baris {jenis P|T, kode, keterangan, nilai POSITIF} dari siaptxn_gdok_manuals
     * @return array{detail: array<int, array>, ringkas: array, grupKapita: array<int, string>, catatan: array<int, string>}
     */
    public static function hitung(array $struktur, iterable $transaksi, iterable $manual = []): array
    {
        $rows = collect($transaksi)->map(fn($row) => (object) (is_array($row) ? $row : (array) $row));
        $catatan = [];

        // Agregasi per komponen, grup dari baris (atau dari peta bila snapshot lama belum menyimpan grup).
        $perKomponen = $rows->groupBy(fn($row) => (string) $row->komponen)->map(function ($grup, $komponen) {
            $grupDoc = (string) ($grup->first()->grup ?? '') ?: (GajiDokterStruktur::GRUP_KOMPONEN[$komponen] ?? '');

            return (object) ['komponen' => $komponen, 'grup' => $grupDoc, 'nominal' => (float) $grup->sum('nominal'), 'pasien' => (int) $grup->sum('pasien'),
                'seq' => array_search($komponen, array_keys(GajiDokterStruktur::GRUP_KOMPONEN), true) !== false ? array_search($komponen, array_keys(GajiDokterStruktur::GRUP_KOMPONEN), true) : 99];
        })->sortBy('seq')->values();

        $detail = [];
        $grupKapita = [];
        foreach (GajiDokterStruktur::GRUP_KAPITA as $jalur => $grupJalur) {
            $tarif = (float) ($struktur["tarif_per_kapita_{$jalur}"] ?? 0);
            if ($tarif <= 0) {
                continue;
            }
            $grupKapita = array_merge($grupKapita, $grupJalur);
            $pasien = (int) $perKomponen->whereIn('grup', $grupJalur)->sum('pasien');
            if ($pasien === 0) {
                $catatan[] = 'Tidak ada pasien ' . strtoupper($jalur) . ' untuk dasar kapita.';
            }
            $detail[] = ['jenis' => 'J', 'kode' => 'KAPITA ' . strtoupper($jalur), 'label' => 'Per pasien ' . strtoupper($jalur) . ' @ ' . number_format($tarif, 0, ',', '.'),
                'nilai' => round($pasien * $tarif, 2), 'jumlah_pasien' => $pasien, 'nilai_manual' => '0', 'urutan' => 100 + count($detail)];
        }
        foreach ($perKomponen as $komponen) {
            if (in_array($komponen->grup, $grupKapita, true) || $komponen->nominal == 0.0) {
                continue;
            }
            $detail[] = ['jenis' => 'J', 'kode' => $komponen->komponen, 'label' => GajiDokterStruktur::labelKomponen($komponen->komponen),
                'nilai' => round($komponen->nominal, 2), 'jumlah_pasien' => $komponen->pasien, 'nilai_manual' => '0', 'urutan' => 100 + count($detail)];
        }
        foreach (GajiDokterStruktur::TUNJANGAN_RUTIN as $kolom => $kode) {
            $nilai = (float) ($struktur[$kolom] ?? 0);
            if ($nilai > 0) {
                $detail[] = ['jenis' => 'J', 'kode' => $kode, 'label' => GajiDokterStruktur::labelKomponen($kode), 'nilai' => $nilai, 'jumlah_pasien' => 0, 'nilai_manual' => '0', 'urutan' => 200 + count($detail)];
            }
        }
        foreach (GajiDokterStruktur::POTONGAN_RUTIN as $kolom => $kode) {
            $nilai = (float) ($struktur[$kolom] ?? 0);
            if ($nilai > 0) {
                $detail[] = ['jenis' => 'P', 'kode' => $kode, 'label' => GajiDokterStruktur::labelKomponen($kode), 'nilai' => -$nilai, 'jumlah_pasien' => 0, 'nilai_manual' => '0', 'urutan' => 400 + count($detail)];
            }
        }
        foreach ($manual as $barisManual) {
            $barisManual = (object) (is_array($barisManual) ? $barisManual : (array) $barisManual);
            $nilai = abs((float) $barisManual->nilai);
            if ($nilai == 0.0) {
                continue;
            }
            $detail[] = ['jenis' => $barisManual->jenis === 'T' ? 'T' : 'P', 'kode' => (string) $barisManual->kode,
                'label' => trim((string) ($barisManual->keterangan ?? '')) ?: GajiDokterStruktur::labelKomponen((string) $barisManual->kode),
                'nilai' => $barisManual->jenis === 'T' ? $nilai : -$nilai, 'jumlah_pasien' => 0, 'nilai_manual' => '1', 'urutan' => ($barisManual->jenis === 'T' ? 600 : 500) + count($detail)];
        }

        // ---- ringkasan (= hitungUlang SIRUS) ----
        $jasa = 0.0;
        $tunjangan = 0.0;
        foreach ($detail as $baris) {
            if ($baris['jenis'] !== 'J') {
                continue;
            }
            str_starts_with($baris['kode'], 'TUNJ ') ? $tunjangan += $baris['nilai'] : $jasa += $baris['nilai'];
        }
        $pokok = (float) ($struktur['basic_salary'] ?? 0);
        $total = match ($struktur['skema_gaji_pokok'] ?? 'A') {
            'G' => max($jasa, $pokok) + $tunjangan,
            'N' => $jasa + $tunjangan,
            default => $jasa + $tunjangan + $pokok,
        };
        $potonganRs = self::potonganRs($struktur, $detail, $total, $pokok);
        $faktorNpwp = ($struktur['npwp_status'] ?? 'Y') === 'N' ? 1.2 : 1.0;
        $pph21 = -floor((float) ($struktur['pph21_persen'] ?? 0) / 100 * ($total + $potonganRs) * $faktorNpwp);
        $potonganLain = (float) array_sum(array_map(fn($baris) => $baris['jenis'] === 'P' ? $baris['nilai'] : 0, $detail));
        $tambahan = (float) array_sum(array_map(fn($baris) => $baris['jenis'] === 'T' ? $baris['nilai'] : 0, $detail));

        $detail[] = ['jenis' => 'P', 'kode' => 'RS', 'label' => self::keteranganPotonganRs($struktur), 'nilai' => $potonganRs, 'jumlah_pasien' => 0, 'nilai_manual' => '0', 'urutan' => 10];
        $detail[] = ['jenis' => 'P', 'kode' => 'PPH21', 'label' => 'PPh 21' . ($faktorNpwp > 1 ? ' (+20% tanpa NPWP)' : ''), 'nilai' => $pph21, 'jumlah_pasien' => 0, 'nilai_manual' => '0', 'urutan' => 11];
        usort($detail, fn($kiri, $kanan) => [$kiri['jenis'], $kiri['urutan']] <=> [$kanan['jenis'], $kanan['urutan']]);

        return [
            'detail' => $detail,
            'ringkas' => ['jasa_total' => round($jasa, 2), 'total_gaji' => round($total, 2), 'potongan_rs' => round($potonganRs, 2), 'pph21' => round($pph21, 2),
                'potongan_lain_total' => round($potonganLain, 2), 'tambahan_total' => round($tambahan, 2),
                'gaji_diterima' => round($total + $potonganRs + $pph21 + $potonganLain + $tambahan, 2)],
            'grupKapita' => $grupKapita,
            'catatan' => $catatan,
        ];
    }

    private static function potonganRs(array $struktur, array $detail, float $total, float $pokok): float
    {
        $persen = (float) ($struktur['potongan_rs_persen'] ?? 0);
        switch ($struktur['potongan_rs_basis'] ?? 'T') {
            case 'N':
                return 0.0;
            case 'J':
                return -round($persen / 100 * ($total - $pokok), 2);
            case 'B':
                $jumlah = 0.0;
                foreach (GajiDokterStruktur::parseAturan($struktur['potongan_rs_aturan'] ?? null) as $kode => $aturanItem) {
                    $nilai = (float) array_sum(array_map(fn($baris) => $baris['jenis'] === 'J' && $baris['kode'] === $kode ? $baris['nilai'] : 0, $detail));
                    if ($nilai == 0.0) {
                        continue;
                    }
                    $jumlah += $aturanItem['tipe'] === 'N' ? min($aturanItem['nilai'], abs($nilai)) : $nilai * $aturanItem['nilai'] / 100;
                }

                return -round($jumlah, 2);
            default:
                return -round($persen / 100 * $total, 2);
        }
    }

    private static function keteranganPotonganRs(array $struktur): string
    {
        $persen = rtrim(rtrim(number_format((float) ($struktur['potongan_rs_persen'] ?? 0), 2, ',', '.'), '0'), ',');

        return match ($struktur['potongan_rs_basis'] ?? 'T') {
            'N' => 'Potongan RS (tidak dipotong)',
            'J' => "Potongan RS {$persen}% dari jasa",
            'B' => 'Potongan RS berjenjang per komponen',
            default => "Potongan RS {$persen}%",
        };
    }

    /**
     * Banding hasil hitung SIAP dengan snapshot (header + detail) — untuk tahap 2.
     * @return array{header: array<string, array{siap: float, sirus: float, selisih: float}>, detail: array<int, array>, cocok: bool}
     */
    public static function banding(array $hasilHitung, object $header, Collection $detailSnapshot): array
    {
        $hasilBanding = ['header' => [], 'detail' => [], 'cocok' => true];
        foreach (['jasa_total', 'total_gaji', 'potongan_rs', 'pph21', 'potongan_lain_total', 'tambahan_total', 'gaji_diterima'] as $kolom) {
            $siap = (float) $hasilHitung['ringkas'][$kolom];
            $sirus = (float) ($header->$kolom ?? 0);
            if (abs($siap - $sirus) >= 0.5) {
                $hasilBanding['header'][$kolom] = ['siap' => $siap, 'sirus' => $sirus, 'selisih' => round($siap - $sirus, 2)];
                $hasilBanding['cocok'] = false;
            }
        }
        $detailSiap = collect($hasilHitung['detail'])->keyBy(fn($baris) => $baris['jenis'] . '|' . $baris['kode']);
        $detailSirus = $detailSnapshot->keyBy(fn($baris) => $baris->jenis . '|' . $baris->kode);
        foreach ($detailSiap->keys()->merge($detailSirus->keys())->unique() as $kunci) {
            $siap = $detailSiap[$kunci] ?? null;
            $sirus = $detailSirus[$kunci] ?? null;
            $nilaiSiap = $siap ? (float) $siap['nilai'] : null;
            $nilaiSirus = $sirus ? (float) $sirus->nilai : null;
            if ($nilaiSiap !== null && $nilaiSirus !== null && abs($nilaiSiap - $nilaiSirus) < 0.5) {
                continue;
            }
            [$jenis, $kode] = explode('|', $kunci, 2);
            $hasilBanding['detail'][] = ['jenis' => $jenis, 'kode' => $kode, 'label' => $siap['label'] ?? ($sirus->label ?? $kode), 'siap' => $nilaiSiap, 'sirus' => $nilaiSirus];
            $hasilBanding['cocok'] = false;
        }

        return $hasilBanding;
    }
}
