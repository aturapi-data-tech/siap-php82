<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VERSI PERIODE gaji dokter (pola SIKEU: snapshot satu periode → versi → sahkan satu versi). Satu versi = keadaan SELURUH
 * dokter satu bulan jasa: peta dr_id → gd_id (snapshot per dokter di siaptxn_gdok_hdrs). Dibuat oleh tiap Ambil yang hasilnya
 * berbeda dari versi terakhir, atau oleh koreksi manual di Rincian. Tepat satu versi periode DIPAKAI per bulan jasa; kolom
 * siaptxn_gdok_hdrs.dipakai = turunannya (versi dokter yang tercantum di versi periode dipakai).
 *
 * Isi awal: tiap Ambil lama direka ulang dari waktu dibuatnya snapshot (versi dokter terakhir yang sudah ada saat Ambil itu),
 * berurutan, yang petanya sama dengan versi sebelumnya dilewati. Versi periode dipakai = yang petanya sama dengan versi dokter
 * yang dipakai sekarang; bila tidak ada yang sama dibuat satu versi 'MIGRASI' dari keadaan sekarang.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siaptxn_gdok_versis', function (Blueprint $table) {
            $table->bigIncrements('versi_id');
            $table->string('periode', 7);
            $table->integer('versi');
            $table->string('sumber', 10)->default('AMBIL'); // AMBIL | KOREKSI | MIGRASI
            $table->unsignedBigInteger('ambil_id')->nullable();
            $table->string('keterangan', 200)->nullable();
            $table->string('checksum', 32);
            $table->string('dipakai', 1)->default('0');
            $table->timestamp('dipakai_at')->nullable();
            $table->unsignedBigInteger('dipakai_oleh')->nullable();
            $table->unsignedBigInteger('dibuat_oleh')->nullable();
            $table->timestamps();
            $table->unique(['periode', 'versi'], 'uk_siaptxn_gdok_versis');
        });
        Schema::create('siaptxn_gdok_versi_dtls', function (Blueprint $table) {
            $table->unsignedBigInteger('versi_id');
            $table->string('dr_id', 10);
            $table->unsignedBigInteger('gd_id');
            $table->primary(['versi_id', 'dr_id'], 'pk_siaptxn_gdok_versi_dtls');
            $table->foreign('versi_id', 'fk_siaptxn_gdok_versi_dtls_v')->references('versi_id')->on('siaptxn_gdok_versis')->onDelete('cascade');
        });

        $this->isiAwal();
    }

    private function isiAwal(): void
    {
        $sekarang = now();
        foreach (DB::table('siaptxn_gdok_hdrs')->distinct()->orderBy('periode')->pluck('periode') as $periode) {
            $versiDokter = DB::table('siaptxn_gdok_hdrs')->where('periode', $periode)->orderBy('versi')->get(['gd_id', 'dr_id', 'versi', 'dipakai', 'created_at']);
            $nomor = 0;
            $checksumTerakhir = null;
            $simpan = function (array $peta, string $sumber, ?int $ambilId, ?string $keterangan, $waktu) use ($periode, &$nomor, &$checksumTerakhir) {
                ksort($peta);
                $checksum = md5(json_encode($peta));
                if ($peta === [] || $checksum === $checksumTerakhir) {
                    return;
                }
                $nomor++;
                DB::table('siaptxn_gdok_versis')->insert(['periode' => $periode, 'versi' => $nomor, 'sumber' => $sumber, 'ambil_id' => $ambilId,
                    'keterangan' => $keterangan, 'checksum' => $checksum, 'dipakai' => '0', 'created_at' => $waktu, 'updated_at' => $waktu]);
                $versiId = (int) DB::table('siaptxn_gdok_versis')->where('periode', $periode)->where('versi', $nomor)->value('versi_id');
                foreach (array_chunk($peta, 200, true) as $potongan) {
                    DB::table('siaptxn_gdok_versi_dtls')->insert(array_map(fn($drId, $gdId) => ['versi_id' => $versiId, 'dr_id' => (string) $drId, 'gd_id' => $gdId], array_keys($potongan), $potongan));
                }
                $checksumTerakhir = $checksum;
            };
            foreach (DB::table('siaptxn_gdok_ambils')->where('periode', $periode)->orderBy('ambil_id')->get(['ambil_id', 'diambil_at']) as $ambil) {
                $peta = [];
                foreach ($versiDokter as $versi) {
                    if ($versi->created_at !== null && $versi->created_at <= $ambil->diambil_at) {
                        $peta[(string) $versi->dr_id] = (int) $versi->gd_id; // urut versi naik → yang terakhir menang
                    }
                }
                $simpan($peta, 'AMBIL', (int) $ambil->ambil_id, null, $ambil->diambil_at);
            }
            $petaDipakai = $versiDokter->where('dipakai', '1')->mapWithKeys(fn($versi) => [(string) $versi->dr_id => (int) $versi->gd_id])->all();
            ksort($petaDipakai);
            $checksumDipakai = md5(json_encode($petaDipakai));
            if ($petaDipakai !== [] && !DB::table('siaptxn_gdok_versis')->where('periode', $periode)->where('checksum', $checksumDipakai)->exists()) {
                $checksumTerakhir = null;
                $simpan($petaDipakai, 'MIGRASI', null, 'Keadaan saat versi periode diperkenalkan', $sekarang);
            }
            $versiDipakai = DB::table('siaptxn_gdok_versis')->where('periode', $periode)->where('checksum', $checksumDipakai)->max('versi');
            if ($versiDipakai) {
                DB::table('siaptxn_gdok_versis')->where('periode', $periode)->where('versi', $versiDipakai)->update(['dipakai' => '1', 'dipakai_at' => $sekarang]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_gdok_versi_dtls');
        Schema::dropIfExists('siaptxn_gdok_versis');
    }
};
