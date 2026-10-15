<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selfie absen HP disimpan privat (disk 'local'). Hanya admin / HRD dan
 * karyawan pemilik absensi itu sendiri yang boleh membukanya.
 */
class FotoAbsensiController extends Controller
{
    private const ROLE_PENINJAU = ['admin', 'hrd'];

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
        $roles = $user->getRoleNames()->map(fn($role) => trim(strtolower($role)))->all();
        $bolehLihat = array_intersect(self::ROLE_PENINJAU, $roles) !== [] || (string) $user->emp_id === (string) $row->emp_id;
        abort_unless($bolehLihat, 403);

        return Storage::disk('local')->response($row->foto, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
