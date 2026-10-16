<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Support\StrukturDatabase;

/**
 * PANDUAN STRUKTUR DATABASE — gambar relasi antar tabel (ERD), daftar tabel & kolom per modul,
 * dan SQL struktur siap eksekusi. Kolom dibaca langsung dari kamus data Oracle (selalu terbaru);
 * gambar ERD & berkas SQL dibuat ulang lewat `php artisan siap:struktur-db`. Hanya Admin.
 */
new class extends Component {
    public function boot(): void
    {
        abort_unless(auth()->user()?->can('sistem.strukturDb'), 403);
    }

    #[Computed]
    public function kolom()
    {
        return StrukturDatabase::kolom();
    }

    #[Computed]
    public function relasi()
    {
        return StrukturDatabase::relasi();
    }
};
?>

@php
    $struktur = \App\Support\StrukturDatabase::class;
    $adaSvg = is_file(public_path($struktur::BERKAS_SVG));
    $adaSql = is_file(base_path($struktur::BERKAS_SQL));
    $kolom = $this->kolom;
    $tabelModul = collect($struktur::MODUL)->flatMap(fn($modul) => $modul[2])->all();
    $tabelInfrastruktur = $kolom->keys()->reject(fn($tabel) => in_array($tabel, $tabelModul, true))->values();
    $bagian = ['erd' => 'Gambar relasi (ERD)'] + collect($struktur::MODUL)->keys()->mapWithKeys(fn($modul) => [\Illuminate\Support\Str::slug($modul) => $modul])->all()
        + ['infrastruktur' => 'Infrastruktur Laravel', 'relasi' => 'Daftar relasi', 'sql' => 'SQL struktur'];
@endphp

<div>
    <x-page-title title="Struktur Database" subtitle="Gambar relasi antar tabel, daftar kolom per modul, dan SQL struktur yang tinggal dieksekusi" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="grid grid-cols-1 gap-6 px-6 py-6 mx-auto max-w-7xl lg:grid-cols-4">

            {{-- DAFTAR ISI --}}
            <nav class="lg:sticky lg:top-24 lg:self-start">
                <div class="p-4 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <p class="mb-2 text-xs font-semibold tracking-wide uppercase text-muted">Daftar isi</p>
                    <ul class="space-y-1 text-sm">
                        @foreach ($bagian as $id => $judul)
                            <li><a href="#{{ $id }}" class="block px-2 py-1 rounded-lg text-body hover:bg-surface-soft hover:text-brand-green dark:text-gray-300">{{ $judul }}</a></li>
                        @endforeach
                    </ul>
                    <p class="pt-3 mt-3 text-xs border-t border-hairline text-muted">
                        {{ $kolom->count() }} tabel · {{ $this->relasi->unique('constraint_name')->count() }} foreign key
                    </p>
                </div>
            </nav>

            {{-- ISI --}}
            <article class="space-y-6 lg:col-span-3 text-body dark:text-gray-300 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-ink dark:[&_h2]:text-gray-100 [&_h3]:font-semibold [&_h3]:text-ink dark:[&_h3]:text-gray-100 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:space-y-1.5 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">

                {{-- ERD --}}
                <section id="erd" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700"
                    x-data="{ skala: 1 }">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2>Gambar relasi antar tabel (ERD)</h2>
                        @if ($adaSvg)
                            <div class="flex items-center gap-2">
                                <x-secondary-button type="button" x-on:click="skala = Math.max(0.4, skala - 0.2)" title="Perkecil">−</x-secondary-button>
                                <span class="w-12 text-sm text-center font-mono" x-text="Math.round(skala * 100) + '%'"></span>
                                <x-secondary-button type="button" x-on:click="skala = Math.min(3, skala + 0.2)" title="Perbesar">+</x-secondary-button>
                                <a href="{{ asset($struktur::BERKAS_SVG) }}" target="_blank" rel="noopener">
                                    <x-ghost-button type="button">Buka gambar penuh</x-ghost-button>
                                </a>
                            </div>
                        @endif
                    </div>
                    <ul class="mt-3 text-sm">
                        <li>Kotak = tabel, warna = modul. <b>PK</b> = primary key (tebal), <b>FK</b> = foreign key (miring), <b>*</b> = wajib diisi (NOT NULL).</li>
                        <li>Garis tegas = foreign key di database (dari kolom induk ke kolom anak, ujung bercabang = "banyak").
                            Garis putus-putus = relasi logis tanpa foreign key ({{ collect($struktur::RELASI_LOGIS)->map(fn($r) => strtolower($r[0]) . '.' . strtolower($r[1]) . ' → ' . strtolower($r[2]))->implode('; ') }}).</li>
                        <li>Kolom <code>updated_by</code> / <code>*_oleh</code> di banyak tabel berisi <code>users.id</code> — tidak digambar supaya diagram tetap terbaca.</li>
                    </ul>
                    @if ($adaSvg)
                        <div class="mt-4 overflow-auto border rounded-xl border-hairline bg-white max-h-[75vh]">
                            <img src="{{ asset($struktur::BERKAS_SVG) }}?v={{ filemtime(public_path($struktur::BERKAS_SVG)) }}" alt="ERD SIAP"
                                class="max-w-none origin-top-left" x-bind:style="'width:' + (skala * 100) + '%'">
                        </div>
                        <p class="mt-2 text-xs text-muted">Gambar dibuat {{ \App\Support\Format::waktu(\Carbon\Carbon::createFromTimestamp(filemtime(public_path($struktur::BERKAS_SVG)))) }}.
                            Setelah ada migrasi baru, jalankan <code>php artisan siap:struktur-db</code> untuk memperbarui gambar &amp; SQL.</p>
                    @else
                        <p class="p-3 mt-4 text-sm border rounded-xl border-amber-300 bg-amber-50 text-amber-900">Gambar belum dibuat — jalankan <code>php artisan siap:struktur-db</code>.</p>
                    @endif
                </section>

                {{-- PER MODUL --}}
                @foreach ($struktur::MODUL as $modul => [$warna, $keterangan, $daftarTabel])
                    <section id="{{ \Illuminate\Support\Str::slug($modul) }}" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                        <h2 style="color: {{ $warna }}">{{ $modul }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $keterangan }}</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($daftarTabel as $tabel)
                                @continue(!$kolom->has($tabel))
                                @include('pages.panduan.panduan-struktur-db.tabel-kolom', ['tabel' => $tabel, 'daftarKolom' => $kolom[$tabel], 'warna' => $warna])
                            @endforeach
                        </div>
                    </section>
                @endforeach

                {{-- INFRASTRUKTUR --}}
                <section id="infrastruktur" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Infrastruktur Laravel</h2>
                    <p class="mt-1 text-sm text-muted">Tabel bawaan framework (cache, antrean, sesi, migrasi) — tidak berelasi dengan data SIAP, tidak digambar di ERD, tetap ada di SQL.</p>
                    <div class="mt-3 space-y-2">
                        @foreach ($tabelInfrastruktur as $tabel)
                            @include('pages.panduan.panduan-struktur-db.tabel-kolom', ['tabel' => $tabel, 'daftarKolom' => $kolom[$tabel], 'warna' => '#64748b'])
                        @endforeach
                    </div>
                </section>

                {{-- RELASI --}}
                <section id="relasi" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Daftar relasi (foreign key)</h2>
                    <div class="mt-3 overflow-x-auto">
                        <table class="text-sm ds-table">
                            <thead><tr><th>Tabel anak · kolom</th><th>→ Tabel induk · kolom</th><th>Hapus induk</th><th>Nama constraint</th></tr></thead>
                            <tbody>
                                @foreach ($this->relasi as $row)
                                    <tr>
                                        <td class="font-mono">{{ strtolower($row->table_name) }}.<b>{{ strtolower($row->column_name) }}</b></td>
                                        <td class="font-mono">{{ strtolower($row->r_table) }}.{{ strtolower($row->r_column) }}</td>
                                        <td>{{ $row->delete_rule === 'CASCADE' ? 'ikut terhapus' : 'ditolak (ORA-02292)' }}</td>
                                        <td class="font-mono text-xs text-muted">{{ $row->constraint_name }}</td>
                                    </tr>
                                @endforeach
                                @foreach ($struktur::RELASI_LOGIS as [$anak, $kolomAnak, $induk, $kolomInduk, $keteranganRelasi])
                                    <tr>
                                        <td class="font-mono">{{ strtolower($anak) }}.<b>{{ strtolower($kolomAnak) }}</b></td>
                                        <td class="font-mono">{{ strtolower($induk) }}.{{ strtolower($kolomInduk) }}</td>
                                        <td colspan="2" class="text-xs text-muted">relasi logis (tanpa foreign key) — {{ $keteranganRelasi }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-sm">"Ditolak (ORA-02292)" = data induk yang masih dipakai tidak bisa dihapus — aplikasi menyarankan <b>non-aktifkan</b>.</p>
                </section>

                {{-- SQL --}}
                <section id="sql" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2>SQL struktur (tinggal dieksekusi)</h2>
                        @if ($adaSql)
                            <a href="{{ route('panduan.struktur-db.sql') }}">
                                <x-primary-button type="button">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                    Unduh siap-struktur.sql
                                </x-primary-button>
                            </a>
                        @endif
                    </div>
                    <p class="mt-2 text-sm">Berkas <code>{{ $struktur::BERKAS_SQL }}</code> berisi DDL Oracle lengkap, urut aman dieksekusi:</p>
                    <ol class="mt-2 text-sm">
                        <li><b>Sequence</b> — penghitung kolom id otomatis.</li>
                        <li><b>Tabel</b> beserta primary key, unique &amp; nilai bawaan.</li>
                        <li><b>Index</b> tambahan.</li>
                        <li><b>Foreign key</b> — relasi antar tabel (dibuat sesudah semua tabel ada).</li>
                        <li><b>Trigger</b> — mengisi id dari sequence saat insert.</li>
                        <li><b>Data tabel migrations</b> — supaya <code>php artisan migrate</code> menganggap tabel sudah ada.</li>
                    </ol>
                    <h3 class="mt-4">Cara pakai</h3>
                    <ol class="mt-1 text-sm">
                        <li>Siapkan user/skema Oracle yang <b>kosong</b> (mis. <code>siap</code>) dengan hak CONNECT &amp; RESOURCE.</li>
                        <li>Login sebagai user itu, jalankan berkasnya: SQL*Plus <code>@siap-struktur.sql</code>, atau SQL Developer › Run Script (F5).</li>
                        <li>Isi <code>.env</code> aplikasi dengan user tersebut, lalu jalankan seeder data awal bila perlu (shift, unit, identitas, role).</li>
                    </ol>
                    <p class="p-3 mt-3 text-sm border rounded-xl border-amber-300 bg-amber-50 text-amber-900">
                        Pilih <b>salah satu</b>: eksekusi SQL ini, <b>atau</b> <code>php artisan migrate</code> — jangan keduanya di skema yang sama.
                        SQL ini sudah diuji dijalankan di skema kosong: semua objek terbentuk tanpa error dan identik dengan database aplikasi.
                    </p>
                    @if ($adaSql)
                        <p class="mt-2 text-xs text-muted">Berkas dibuat {{ \App\Support\Format::waktu(\Carbon\Carbon::createFromTimestamp(filemtime(base_path($struktur::BERKAS_SQL)))) }} ·
                            {{ number_format(filesize(base_path($struktur::BERKAS_SQL))) }} byte.</p>
                    @endif
                </section>

            </article>
        </div>
    </div>
</div>
