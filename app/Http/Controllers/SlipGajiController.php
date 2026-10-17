<?php

namespace App\Http\Controllers;

use App\Support\GajiKaryawan;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF slip gaji dari link WhatsApp — TANPA login. Keamanannya dari tanda tangan URL
 * (middleware 'signed', kedaluwarsa GajiKaryawan::MASA_LINK_SLIP_HARI): link tidak bisa
 * diubah ke gaji_id lain. Slip yang dibuka kembali ke Draft tidak bisa diunduh lewat link lama.
 */
class SlipGajiController extends Controller
{
    public function __invoke(int $gajiId): Response
    {
        $slip = GajiKaryawan::slip($gajiId);
        abort_unless($slip && $slip['header']->gaji_status === GajiKaryawan::STATUS_FINAL, 404);

        $namaBerkas = 'slip-gaji-' . mb_strtolower($slip['header']->emp_id) . '-' . $slip['header']->periode . '.pdf';

        return GajiKaryawan::pdf([$slip], potongan: true)->stream($namaBerkas)
            ->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
