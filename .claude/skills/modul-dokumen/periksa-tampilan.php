<?php
/**
 * Pemeriksa tampilan baku modul dokumen (docs/modul-dokumen-ri-pattern.md §2a).
 *
 * Jalankan dari akar repo:
 *   php .claude/skills/modul-dokumen/periksa-tampilan.php            # semua modul dokumen
 *   php .claude/skills/modul-dokumen/periksa-tampilan.php <berkas…>  # sebagian saja
 *
 * Memeriksa HTML HASIL RENDER, bukan isi berkas — itu bedanya dengan grep:
 *   1. keseimbangan tag di KEDUA layar (daftar & formulir)
 *   2. tombol tutup benar-benar mepet kanan (dibaca lewat DOM: induk & saudaranya)
 *   3. layar daftar punya tombol Tutup + Isi Formulir Baru (modul dua layar)
 *   4. saat kosong, tabel tetap tampil dengan keterangan
 *   5. display pasien ikut tampil di layar daftar (penjaga @if diForm salah tempat)
 *   6. layar daftar polos & full width: tanpa judul "… Tersimpan", tanpa "Klik baris …", tanpa max-w-5xl
 *   7. tabel entri tidak lagi memakai array_reverse() (lihat docs §2c)
 *   8. bentuk tabel daftar = Edukasi Terintegrasi (docs §2a "Tabel daftar"): tanpa kolom No,
 *      ada panah rincian, Lihat = <x-lihat-button>, Cetak = <x-cetak-button>, hapus = <x-hapus-button>, label "Lanjutkan Pengisian" utuh,
 *      keterangan footer "Setiap entri berdiri sendiri" di layar daftar
 *   9. header modal = komponen x-modul-dokumen.header & punya ikon (kotak w-7 h-7 rounded-lg sebelum judul)
 *  10. tabel layar daftar di dalam kartu <x-border-form padding="p-0"> (bukan tabel polos selebar modal)
 *  11. sel Aksi tabel daftar = komponen x-modul-dokumen.aksi-entri
 *  12. footer modal dua layar = komponen x-modul-dokumen.footer (kecuali Case Manager)
 *  13. kartu di tab = komponen x-modul-dokumen.kartu
 *  14. banner status = komponen x-modul-dokumen.banner (tidak ditulis tangan)
 *  15. badge Terkunci/Draft = komponen x-modul-dokumen.status-entri
 *  16. tabel layar daftar = komponen x-modul-dokumen.tabel-daftar (kecuali Case Manager)
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
auth()->loginUsingId(1);

function namaKomponen(string $p): string {
    $r = preg_replace('/\.blade\.php$/', '', str_replace('resources/views/pages/', '', $p));
    return 'pages::' . str_replace('/', '.', str_replace('⚡', '', $r));
}
function saldoTag(string $h): array {
    $out = [];
    foreach (['div','section','fieldset','table','ul','ol','p','span'] as $t) {
        $b = preg_match_all('/<'.$t.'[\s>]/i', $h);
        $c = preg_match_all('#</'.$t.'>#i', $h);
        if ($b !== $c) $out[$t] = $b - $c;
    }
    return $out;
}
function tutupMepetKanan(string $h): ?string {
    $doc = new DOMDocument(); libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $h . '</div>'); libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $btn = $xp->query('//button[@*[name()="wire:click"]="closeModal"]')->item(0);
    if (!$btn) return 'tombol tutup tak ada di DOM';
    $par = $btn->parentNode;
    $prev = $btn->previousSibling; while ($prev && $prev->nodeType !== XML_ELEMENT_NODE) $prev = $prev->previousSibling;
    $kPar = $par->getAttribute('class'); $kBtn = $btn->getAttribute('class');
    $kPrev = $prev ? $prev->getAttribute('class') : '';
    if (!str_contains($kPar, 'flex')) return 'tombol tutup bukan anak baris flex';
    $mepet = str_contains($kPar, 'justify-between') || str_contains($kBtn, 'ml-auto') || str_contains($kPrev, 'flex-1');
    return $mepet ? null : 'tombol tutup tak terdorong ke kanan';
}

$berkas = array_slice($argv, 1);
if (!$berkas) $berkas = glob('resources/views/pages/transaksi/*/emr-*/modul-dokumen/*/⚡rm-*-actions.blade.php');
$berkas = array_values(array_filter($berkas, fn($f) => str_contains(file_get_contents($f), '<x-modal')));

$masalah = 0;
foreach ($berkas as $path) {
    $catatan = [];
    $terender = false;
    foreach ([[], ['riHdrNo' => null], ['rjNo' => null]] as $args) {
        try { $t = Livewire\Livewire::test(namaKomponen($path), $args); } catch (\Throwable $e) { continue; }
        $terender = true;
        $daftar = $t->html();
        if ($s = saldoTag($daftar)) $catatan[] = 'tag layar daftar timpang: ' . json_encode($s);
        if ($e = tutupMepetKanan($daftar)) $catatan[] = $e;

        // Partial daftar (mis. pengkajian-pre-op *-daftar-tersimpan) ikut dibaca sebagai sumber.
        $sumber = file_get_contents($path)
            . implode('', array_map('file_get_contents', glob(dirname($path) . '/*-daftar-tersimpan.blade.php')));

        // Display pasien WAJIB ikut tampil di layar daftar. Kalau berkasnya memasang
        // <livewire:…display-pasien…> tapi HTML-nya tak memuatnya, biasanya
        // @if ($this->diForm()) kepasang di HEADER modal, bukan sebelum <fieldset>.
        if (str_contains($sumber, 'display-pasien') && !str_contains($daftar, 'display-pasien'))
            $catatan[] = 'display pasien hilang di layar daftar (@if diForm salah tempat?)';

        // Layar daftar polos: judul modul sudah di header modal.
        if (preg_match('/Tersimpan\s*<\/h3>/', $daftar)) $catatan[] = 'layar daftar masih berjudul "… Tersimpan"';
        if (str_contains($daftar, 'Klik baris')) $catatan[] = 'layar daftar masih memuat baris petunjuk "Klik baris …"';
        if (str_contains($daftar, 'max-w-5xl')) $catatan[] = 'isi terkurung max-w-5xl (harus max-w-full)';
        // Pembungkus isi modal WAJIB min-h-full: min-h-[calc(100vh-8rem)] lebih pendek ±5rem dari panel
        // (h-[calc(100dvh-3rem)]) → footer sticky melayang, ada ruang kosong di bawahnya (2026-09-15).
        if (str_contains($sumber, 'min-h-[calc(100vh-8rem)]')) $catatan[] = 'pembungkus modal min-h-[calc(100vh-8rem)] — pakai min-h-full supaya footer menempel di dasar';

        // Urutan tabel entri: terbaru di atas, bukan urutan simpan.
        if (str_contains($sumber, 'array_reverse(')) $catatan[] = 'masih array_reverse() — pakai collect()->sortByDesc(strtotime(strtr(…)))';


        // Bentuk tabel daftar (docs §2a "Tabel daftar", BAKU 2026-09-08).
        // Kolom No: hanya <thead> PERTAMA di blok daftar (tabel di baris rincian/formulir boleh bernomor).
        // Tabel daftar berkomponen: kolom dibaca dari :kolom (thead pertama di sumber = tabel bersarang baris rincian).
        if (preg_match('/<x-modul-dokumen\.tabel-daftar[^>]*:kolom="\[(.*?)\]"/s', $sumber, $kolomDaftar)) {
            if (preg_match("/'No\\.?'/i", $kolomDaftar[1])) $catatan[] = 'tabel daftar masih punya kolom No';
        } elseif (($posUnless = strrpos($sumber, '@unless ($this->diForm())')) !== false
            && preg_match('/<thead.*?<\/thead>/s', substr($sumber, $posUnless), $theadDaftar)
            && preg_match('/<th[^>]*>\s*No\.?\s*<\/th>/i', $theadDaftar[0]))
            $catatan[] = 'tabel daftar masih punya kolom No';
        if (preg_match('/<x-(info|primary|secondary|outline)-button[^>]*wire:click="cetak[A-Za-z]*\(/', substr($sumber, (int) strrpos($sumber, '@unless ($this->diForm())'))))  // cetak(…)/cetakFormA(…) per entri; cetakSemua tanpa kurung = cetak seluruh catatan, boleh berteks
            $catatan[] = 'Cetak di tabel daftar masih tombol berteks (harus <x-cetak-button> ikon saja)';
        if (preg_match('/>\s*Lanjutkan\s*<\//', $sumber)) $catatan[] = 'label "Lanjutkan" harus "Lanjutkan Pengisian"';
        if (preg_match('/<x-secondary-button[^>]*wire:click="(viewEntry|lihat)[^>]*>(?:(?!<\/x-secondary-button>).)*>\s*Lihat\s*<\/x-secondary-button>/s', substr($sumber, (int) strrpos($sumber, '@unless ($this->diForm())'))))
            $catatan[] = 'Lihat di tabel daftar masih tombol berteks (harus <x-lihat-button>)';
        if (preg_match('/<x-(outline|danger|icon)-button[^>]*wire:click(\.prevent)?="(hapus|remove|delete)/', substr($sumber, (int) strrpos($sumber, '@unless ($this->diForm())'))))
            $catatan[] = 'hapus di tabel daftar masih tombol manual (harus <x-hapus-button>)';

        // Banner status (terkunci / mode lihat / melanjutkan draft) WAJIB komponen x-modul-dokumen.banner.
        if (preg_match('/<div[^>]*class="[^"]*(text-amber-700 bg-amber-50|text-sky-700 bg-sky-50|bg-brand-lime\/10 border border-brand-lime)/', $sumber))
            $catatan[] = 'banner status ditulis tangan — pakai <x-modul-dokumen.banner jenis="terkunci|lihat|lanjut">';

        // Tabel layar daftar WAJIB komponen x-modul-dokumen.tabel-daftar (kecuali Case Manager: tabel di kartu Form A/B).
        if (str_contains($sumber, 'this->diForm()') && !str_contains($path, 'case-manager') && !str_contains($sumber, '<x-modul-dokumen.tabel-daftar'))
            $catatan[] = 'tabel layar daftar tidak memakai <x-modul-dokumen.tabel-daftar>';

        // Badge status entri WAJIB komponen x-modul-dokumen.status-entri (Terkunci/Draft ditulis tangan ditolak).
        if (preg_match('/<x-badge[^>]*>\s*Terkunci\s*<\/x-badge>/', $sumber))
            $catatan[] = 'badge Terkunci/Draft ditulis tangan — pakai <x-modul-dokumen.status-entri :final="…" />';

        // Kartu di tab WAJIB komponen x-modul-dokumen.kartu (judul · badge · deskripsi · tombol Buka + pratinjau).
        if (!str_contains($sumber, '<x-modul-dokumen.kartu'))
            $catatan[] = 'kartu di tab tidak memakai <x-modul-dokumen.kartu>';

        // Header modal WAJIB komponen x-modul-dokumen.header (ikon · judul · deskripsi · badge · tutup),
        // bukan markup tulis-tangan — sejak 2026-09-15 (71 modal dikonversi).
        if (!str_contains($sumber, '<x-modul-dokumen.header'))
            $catatan[] = 'header modal tidak memakai <x-modul-dokumen.header>';

        // Header modal WAJIB ikon (kotak w-7 h-7 rounded-lg) sebelum judul — docs §2a "Penamaan".
        $posTutup = strpos($daftar, 'wire:click="closeModal"');
        if ($posTutup !== false && !str_contains(substr($daftar, 0, $posTutup), 'w-7 h-7 rounded-lg'))
            $catatan[] = 'header modal tanpa ikon (kotak w-7 h-7 rounded-lg sebelum judul)';

        // Tabel layar daftar WAJIB di dalam kartu <x-border-form padding="p-0"> (acuan Edukasi Terintegrasi),
        // bukan tabel polos selebar modal (Formulir Penjaminan & modul bedah sebelum 2026-09-15).
        // Kartu boleh dibuka di dalam blok @unless, atau sudah terbuka sebelumnya (Case Manager: kartu "Form A").
        if (preg_match_all('/@unless \(\$this->diForm\(\)\)(.*?)@endunless/s', $sumber, $blokDaftar, PREG_OFFSET_CAPTURE)) {
            foreach ($blokDaftar[1] as [$isiBlok, $offsetBlok]) {
                $posTabel = strpos($isiBlok, '<table');
                if ($posTabel === false) continue;
                $sebelumTabel = substr($sumber, 0, $offsetBlok + $posTabel);
                $kartuTerbuka = substr_count($sebelumTabel, '<x-border-form') - substr_count($sebelumTabel, '</x-border-form>')
                    + substr_count($sebelumTabel, '<x-modul-dokumen.tabel-daftar') - substr_count($sebelumTabel, '</x-modul-dokumen.tabel-daftar>');
                if ($kartuTerbuka < 1 && !str_contains(substr($isiBlok, 0, $posTabel), '@include'))
                    $catatan[] = 'tabel layar daftar tidak dibungkus <x-border-form padding="p-0">';
            }
        }

        $duaLayar = str_contains($sumber, 'this->diForm()');
        if ($duaLayar) {
            // Footer modal dua layar WAJIB komponen — 2026-09-15. Case Manager dikecualikan: footernya baris
            // tombol di dalam kartu Form A / editor Form B, bukan footer sticky modal.
            if (!str_contains($sumber, '<x-modul-dokumen.footer') && !str_contains($path, 'case-manager'))
                $catatan[] = 'footer modal tidak memakai <x-modul-dokumen.footer>';
            // Sel Aksi tabel daftar WAJIB komponen (Lanjutkan/Lihat/Cetak │ Buka Kunci/Hapus + Gate) — 2026-09-15.
            if (!str_contains($sumber, '<x-modul-dokumen.aksi-entri'))
                $catatan[] = 'sel Aksi tabel daftar tidak memakai <x-modul-dokumen.aksi-entri>';
            if (preg_match('/<x-confirm-button[^>]*action="bukaKunci(Form)?\(/', substr($sumber, (int) strrpos($sumber, '@unless ($this->diForm())'))))
                $catatan[] = 'Buka Kunci entri masih ditulis tangan di tabel daftar (pakai <x-modul-dokumen.aksi-entri>)';
            if (!str_contains($sumber, 'rotate-90')) $catatan[] = 'tabel daftar tanpa panah rincian (baris expand)';
            if (!str_contains($daftar, 'Setiap entri berdiri sendiri')) $catatan[] = 'layar daftar tanpa keterangan footer "Setiap entri berdiri sendiri"';
            if (!preg_match('/>\s*Tutup\s*</', $daftar)) $catatan[] = 'layar daftar tanpa tombol Tutup';
            if (!str_contains($daftar, 'wire:click="tambahEntri"')) $catatan[] = 'layar daftar tanpa Isi Formulir Baru';
            if (str_contains($daftar, '<table') && !str_contains($daftar, 'Belum ada'))
                $catatan[] = 'tabel tanpa keterangan saat kosong';
            try {
                $t->call('tambahEntri');
                $form = $t->html();
                if ($s = saldoTag($form)) $catatan[] = 'tag layar formulir timpang: ' . json_encode($s);
                if (!str_contains($form, 'wire:click="kembaliKeDaftar"')) $catatan[] = 'layar formulir tanpa Kembali ke Daftar';
            } catch (\Throwable $e) { $catatan[] = 'tambahEntri gagal: ' . substr($e->getMessage(), 0, 60); }
        }
        break;
    }
    if (!$terender) $catatan[] = 'gagal dirender';
    if ($catatan) { $masalah++; printf("✗ %-46s %s\n", substr(basename($path), 0, 46), implode(' | ', $catatan)); }
}
printf("\nDIPERIKSA %d modul | BERMASALAH %d\n", count($berkas), $masalah);
exit($masalah > 0 ? 1 : 0);
