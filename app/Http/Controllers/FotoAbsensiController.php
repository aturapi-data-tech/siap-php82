<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selfie absen HP disimpan privat (disk 'local'). Hanya role ber-hak absensi.lihatFoto (centang Role Control,
 * awal: Admin / HRD) dan karyawan pemilik absensi itu sendiri yang boleh membukanya.
 * Route-nya dilewatkan penjaga halaman (AksesHalaman::ROUTE_PENJAGA_SENDIRI) — penjaganya di sini.
 */
class FotoAbsensiController extends Controller
{
    public function __invoke(Request $request, int $absId, string $jenis): Response
    {
        if (!in_array($jenis, ['masuk', 'pulang'], true)) {
            abort(404);
        }

        $row = DB::table('siaptxn_absensis')->where('abs_id', $absId)->first(['emp_id', "{$jenis}_foto as foto"]);
        if (!$row || !$row->foto || !Storage::disk('local')->exists($row->foto)) {
            abort(404);
        }

        $user = $request->user();
        $bolehLihat = $user->can('absensi.lihatFoto') || (string) $user->emp_id === (string) $row->emp_id;
        abort_unless($bolehLihat, 403);

        return Storage::disk('local')->response($row->foto, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
