<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\Format;
use App\Support\GajiDokterSirus;
use App\Support\GajiKaryawan;

/**
 * Tab LAMPIRAN Gaji Dokter (anak pembungkus gaji-dokter): rincian pasien / transaksi di balik angka slip satu dokter,
 * dikelompokkan per komponen (uang periksa, tindakan, visite, operasi, kapita, …) berikut subtotal — snapshot versi yang dipilih.
 * Dibuka dari Buku (tombol Lampiran → gd_id) atau pilih sendiri bulan jasa + dokter + versi di sini.
 *
 * Penyajiannya meniru modal Lampiran Rincian Pasien di SIRUS (pages/manajemen/rs/tu/gaji-dokter/gaji-dokter-lampiran):
 *   - kartu identitas dokter + catatan tanggal layanan,
 *   - tabel per komponen (table-fixed) dengan subtotal; komponen besar dilipat (BATAS_LIPAT) sampai diminta,
 *   - grup kapita ditandai "dasar hitung per kapita" — nominalnya tarif komponen yang TIDAK masuk slip,
 *   - rekonsiliasi total transaksi vs jasa pada slip (selisih = jasa manual / transaksi berubah setelah slip dibuat),
 *   - Unduh Excel (CSV pipih, kolom sama dengan SIRUS) untuk pivot.
 * Bedanya: data di sini snapshot SIAP (tidak live), jadi ada pemilih versi.
 */
new class extends Component {
    /** Komponen dengan baris lebih dari ini dilipat sampai diminta (HTML awal tidak berisi ribuan baris). */
    public const BATAS_LIPAT = 300;

    public const NAMA_BULAN = ['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
        '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'];

    public const KOLOM_CSV = ['Dokter', 'Periode Jasa', 'Komponen', 'Tanggal', 'No. RM', 'Nama Pasien', 'Keterangan Jasa', 'Klaim', 'Jenis Klaim',
        'No. SEP', 'No. Transaksi', 'Nominal', 'Masuk Slip'];

    #[Session(key: 'gaji-dokter-bulan')]
    public string $bulan = ''; // mm/yyyy = bulan JASA

    public ?int $gdId = null;
    public string $drId = '';

    /** Saringan komponen ('' = semua) — dipilih lewat kartu ringkasan. */
    public string $komponen = '';

    /** Komponen yang barisnya sengaja ditampilkan walau melebihi BATAS_LIPAT. */
    public array $komponenTerbuka = [];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('gaji.kelola'), 403);
    }

    public function mount(?int $gdId = null): void
    {
        if ($gdId && ($h = DB::table('siaptxn_gdok_hdrs')->where('gd_id', $gdId)->first())) {
            $this->bulan = Carbon::createFromFormat('!Y-m', $h->periode)->format(Format::BULAN);
            $this->drId = $h->dr_id;
            $this->gdId = (int) $h->gd_id;
            return;
        }
        if (!preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $this->bulan)) {
            $this->bulan = now()->subMonthNoOverflow()->format(Format::BULAN);
        }
        $this->pilihDokterPertama();
    }

    private function periode(): string
    {
        return preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $this->bulan) ? Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->format('Y-m') : now()->subMonthNoOverflow()->format('Y-m');
    }

    private function pilihDokterPertama(): void
    {
        $pertama = $this->dokterOpsi->first();
        $this->drId = $pertama?->dr_id ?? '';
        $this->gdId = $pertama ? (int) $pertama->gd_id : null;
        $this->komponen = '';
        $this->komponenTerbuka = [];
    }

    public function updatedBulan(): void
    {
        unset($this->dokterOpsi);
        $this->pilihDokterPertama();
        $this->segarkan();
    }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
        $this->updatedBulan();
    }

    public function updatedDrId(): void
    {
        $terbaru = $this->dokterOpsi->firstWhere('dr_id', $this->drId);
        $this->gdId = $terbaru ? (int) $terbaru->gd_id : null;
        $this->komponen = '';
        $this->komponenTerbuka = [];
        $this->segarkan();
    }

    public function updatedGdId(): void
    {
        $this->gdId = $this->gdId ? (int) $this->gdId : null;
        $this->komponen = '';
        $this->komponenTerbuka = [];
        $this->segarkan();
    }

    public function pilihKomponen(string $kode): void
    {
        $this->komponen = $this->komponen === $kode ? '' : $kode;
    }

    public function toggleKomponen(string $kode): void
    {
        $this->komponenTerbuka = in_array($kode, $this->komponenTerbuka, true)
            ? array_values(array_diff($this->komponenTerbuka, [$kode]))
            : [...$this->komponenTerbuka, $kode];
    }

    private function segarkan(): void
    {
        unset($this->header, $this->detail, $this->semuaBaris, $this->versiOpsi, $this->perKomponen, $this->komponenOpsi, $this->angka);
    }

    /** Dokter yang punya snapshot (versi terbaru) di bulan jasa ini. */
    #[Computed]
    public function dokterOpsi()
    {
        return DB::table('siaptxn_gdok_hdrs')->where('periode', $this->periode())->where('terbaru', '1')->orderBy('dr_name')->get(['gd_id', 'dr_id', 'dr_name', 'versi', 'jumlah_lampiran']);
    }

    #[Computed]
    public function versiOpsi()
    {
        return $this->drId !== '' ? GajiDokterSirus::versiList($this->periode(), $this->drId) : collect();
    }

    #[Computed]
    public function header(): ?object
    {
        return $this->gdId ? DB::table('siaptxn_gdok_hdrs')->where('gd_id', $this->gdId)->first() : null;
    }

    /** Baris slip (jenis J/P/T) versi ini — dasar rekonsiliasi. */
    #[Computed]
    public function detail()
    {
        return $this->gdId ? GajiDokterSirus::detail($this->gdId) : collect();
    }

    #[Computed]
    public function semuaBaris()
    {
        return $this->gdId ? GajiDokterSirus::lampiran($this->gdId) : collect();
    }

    /** Baris per komponen: yang masuk slip dulu, lalu grup kapita; urut label. */
    #[Computed]
    public function perKomponen()
    {
        return $this->semuaBaris->groupBy('komponen')
            ->sortBy(fn($g) => ($g->first()->masuk_slip === '1' ? '0' : '1') . ($g->first()->label ?: $g->first()->komponen));
    }

    /** [komponen => [label, n, pasien, nominal, masuk_slip]] untuk kartu ringkasan. */
    #[Computed]
    public function komponenOpsi(): array
    {
        return $this->perKomponen->map(fn($g, $k) => ['label' => $g->first()->label ?: $k, 'n' => $g->count(), 'pasien' => (int) $g->sum('pasien'),
            'nominal' => (float) $g->sum('nominal'), 'masuk_slip' => $g->first()->masuk_slip === '1'])->all();
    }

    /**
     * Rekonsiliasi — rumus sama dengan SIRUS (GajiDokterLampiran::rekonsiliasi): baris grup kapita TIDAK dijumlahkan
     * (slip memakai jumlah pasien × tarif kepala, bukan nominal komponennya); jasa slip = jenis J tanpa "TUNJ ";
     * kapita slip = jenis J berkode "KAPITA ". Selisih ditampilkan apa adanya — angka tak cocok justru informasi.
     */
    #[Computed]
    public function angka(): array
    {
        $totalTransaksi = (float) $this->semuaBaris->where('masuk_slip', '1')->sum('nominal');
        $totalDasarKapita = (float) $this->semuaBaris->where('masuk_slip', '!=', '1')->sum('nominal');
        $jasa = $this->detail->where('jenis', 'J');
        $jasaSlip = (float) $jasa->reject(fn($d) => str_starts_with((string) $d->kode, 'TUNJ '))->sum('nilai');
        $kapitaSlip = (float) $jasa->filter(fn($d) => str_starts_with((string) $d->kode, 'KAPITA '))->sum('nilai');

        return ['totalTransaksi' => $totalTransaksi, 'totalDasarKapita' => $totalDasarKapita, 'jasaSlip' => $jasaSlip, 'kapitaSlip' => $kapitaSlip,
            'selisih' => round($jasaSlip - $totalTransaksi - $kapitaSlip, 2)];
    }

    public static function namaPeriode(?string $ym): string
    {
        if (!$ym || !preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
            return (string) $ym;
        }
        return (self::NAMA_BULAN[$m[2]] ?? $m[2]) . ' ' . $m[1];
    }

    /**
     * Unduh lampiran dokter ini (semua komponen, tanpa saringan) sebagai CSV siap Excel — kolom & bentuk sama dengan SIRUS:
     * PIPIH (tanpa judul/subtotal di tengah data) supaya bisa dipivot; nominal angka mentah; BOM UTF-8 agar nama beraksen tidak rusak.
     */
    public function unduhCsv(): mixed
    {
        $h = $this->header;
        if (!$h || $this->semuaBaris->isEmpty()) {
            $this->dispatch('toast', type: 'warning', message: 'Tidak ada data untuk diunduh.');
            return null;
        }
        $baris = $this->semuaBaris;
        $periodeJasa = self::namaPeriode($h->periode);
        $nama = 'lampiran-pasien-' . $h->dr_id . '-' . str_replace('-', '', $h->periode) . '-v' . $h->versi . '.csv';

        return response()->streamDownload(function () use ($h, $baris, $periodeJasa) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::KOLOM_CSV, ';', '"', '\\');
            foreach ($baris as $b) {
                fputcsv($out, [$h->dr_name, $periodeJasa, $b->label ?: $b->komponen, $b->tgl, $b->reg_no, $b->nama, $b->keterangan, $b->klaim, $b->klaim_status,
                    $b->no_sep, $b->txn_no, (int) round((float) $b->nominal), $b->masuk_slip === '1' ? 'Ya' : 'Tidak'], ';', '"', '\\');
            }
            fclose($out);
        }, $nama, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
};
?>

<div class="mt-4">
    @php
        $rp = fn($n) => GajiKaryawan::rupiah($n);
        $h = $this->header;
        $komponenOpsi = $this->komponenOpsi;
        $angka = $this->angka;
    @endphp

    {{-- TOOLBAR --}}
    <div class="flex flex-wrap items-end gap-3 px-4 py-3 mb-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
        <div>
            <x-input-label value="Bulan Jasa" />
            <div class="flex items-center gap-1 mt-1">
                <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-3" title="Bulan sebelumnya" aria-label="Bulan sebelumnya">‹</x-secondary-button>
                <div class="w-28">
                    <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" class="w-full font-mono text-center" aria-label="Bulan jasa" />
                </div>
                <x-secondary-button type="button" wire:click="geserBulan(1)" class="!px-3" title="Bulan berikutnya" aria-label="Bulan berikutnya">›</x-secondary-button>
            </div>
        </div>
        <div class="w-72">
            <x-input-label for="gdl-dokter" value="Dokter" />
            <div class="mt-1">
                <x-select-input id="gdl-dokter" wire:model.live="drId">
                    @if ($this->dokterOpsi->isEmpty())<option value="">– belum ada snapshot bulan ini –</option>@endif
                    @foreach ($this->dokterOpsi as $d)
                        <option value="{{ $d->dr_id }}">{{ $d->dr_name }} · {{ $d->jumlah_lampiran }} baris</option>
                    @endforeach
                </x-select-input>
            </div>
        </div>
        <div class="w-60">
            <x-input-label for="gdl-versi" value="Versi snapshot" />
            <div class="mt-1">
                <x-select-input id="gdl-versi" wire:model.live="gdId">
                    @foreach ($this->versiOpsi as $v)
                        <option value="{{ $v->gd_id }}">Versi {{ $v->versi }}{{ $v->terbaru === '1' ? ' (terbaru)' : '' }} · {{ \Carbon\Carbon::parse($v->diambil_at)->format('d/m/Y H:i') }}</option>
                    @endforeach
                </x-select-input>
            </div>
        </div>
        @if ($h)
            <div class="ml-auto">
                {{-- Unduh mengekspor dokter + versi yang sedang tampil (semua komponen), supaya isi berkas tidak pernah berbeda dari yang dilihat. --}}
                <x-info-button type="button" wire:click="unduhCsv" wire:loading.attr="disabled" wire:target="unduhCsv" title="Unduh sebagai CSV — siap dibuka di Excel">
                    <span wire:loading.remove wire:target="unduhCsv" class="inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        Unduh Excel
                    </span>
                    <span wire:loading wire:target="unduhCsv" class="inline-flex items-center gap-2"><x-loading /> Menyiapkan...</span>
                </x-info-button>
            </div>
        @endif
    </div>

    @if (!$h)
        <div class="px-4 py-10 text-sm text-center border border-dashed border-gray-300 rounded-2xl text-muted dark:border-gray-700 dark:text-gray-400">
            Belum ada snapshot bulan jasa ini. Tarik dulu di tab Buku Gaji Dokter → <b>Ambil dari SIRUS</b>.
        </div>
    @else
        {{-- RINGKASAN PER KOMPONEN (sekaligus saringan) --}}
        <div class="grid grid-cols-2 gap-2 mb-3 sm:grid-cols-3 lg:grid-cols-6" role="group" aria-label="Saring komponen">
            @foreach ($komponenOpsi as $kode => $k)
                <button type="button" wire:click="pilihKomponen('{{ $kode }}')" wire:key="chip-{{ $kode }}"
                    class="px-3 py-2 text-left border rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-gray-900 {{ $komponen === $kode ? 'bg-surface-strong border-brand-green dark:bg-gray-800 dark:border-brand-lime' : 'bg-canvas border-hairline hover:bg-surface-soft dark:bg-gray-900 dark:border-gray-700 dark:hover:bg-gray-800' }}"
                    aria-pressed="{{ $komponen === $kode ? 'true' : 'false' }}" title="{{ $k['label'] }}">
                    <span class="block text-xs truncate text-muted dark:text-gray-400">{{ $k['label'] }}</span>
                    <span class="block font-mono text-sm font-semibold tabular-nums text-ink dark:text-gray-100">{{ $rp($k['nominal']) }}</span>
                    <span class="block text-xs text-muted dark:text-gray-400">{{ $k['pasien'] }} pasien · {{ $k['n'] }} baris</span>
                    @if (!$k['masuk_slip'])
                        <span class="inline-block mt-1 px-1.5 py-0.5 text-[11px] font-medium rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">dasar kapita</span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- KARTU DOKTER (pola SIRUS: identitas → catatan tanggal → tabel per komponen → rekonsiliasi) --}}
        <div class="overflow-hidden border bg-canvas border-hairline rounded-xl dark:bg-gray-900 dark:border-gray-700">

            {{-- IDENTITAS DOKTER --}}
            <div class="px-4 py-3 border-b border-hairline bg-surface-card dark:bg-gray-800 dark:border-gray-700">
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                    <h3 class="flex items-center gap-2 text-base font-bold text-ink dark:text-gray-100">
                        {{ $h->dr_name }}
                        <x-badge variant="{{ $h->terbaru === '1' ? 'brand' : 'gray' }}">Versi {{ $h->versi }}{{ $h->terbaru === '1' ? ' · terbaru' : '' }}</x-badge>
                    </h3>
                    <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted dark:text-gray-400">
                        <span>Periode Jasa: <span class="font-medium text-body dark:text-gray-200">{{ $this::namaPeriode($h->periode) }}</span></span>
                        <span>Dibayarkan: <span class="font-medium text-body dark:text-gray-200">{{ $this::namaPeriode($h->periode_gaji) }}</span></span>
                        <span>Jumlah Baris: <span class="font-medium text-body dark:text-gray-200">{{ $this->semuaBaris->count() }} transaksi</span></span>
                        <span>Diambil: <span class="font-medium text-body dark:text-gray-200">{{ \Carbon\Carbon::parse($h->diambil_at)->format('d/m/Y H:i') }}</span></span>
                    </div>
                </div>
            </div>

            {{-- Catatan tanggal WAJIB: tanpa ini baris rawat inap bertanggal bulan lain terbaca salah periode. --}}
            <div class="px-4 py-2 text-xs border-b border-hairline bg-info-tint text-info-deep dark:bg-blue-900/20 dark:text-blue-200 dark:border-gray-700">
                Tanggal di bawah adalah <strong>tanggal layanan</strong> (visite, konsultasi, tindakan, operasi, atau kunjungan). Jasa rawat inap masuk periode gaji saat pasien
                <strong>pulang</strong>, sehingga baris rawat inap dapat bertanggal bulan sebelum periode jasa di atas.
                @if ($komponen !== '')
                    <button type="button" wire:click="pilihKomponen('{{ $komponen }}')" class="ml-2 font-semibold underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green rounded">Tampilkan semua komponen</button>
                @endif
            </div>

            @if ($this->semuaBaris->isEmpty())
                <div class="p-6 text-sm text-center text-muted dark:text-gray-400">Tidak ada transaksi pasien pada snapshot ini.</div>
            @endif

            {{-- TABEL PER KOMPONEN --}}
            @foreach ($this->perKomponen as $kode => $barisKomponen)
                @continue($komponen !== '' && $komponen !== $kode)
                @php
                    $label = $barisKomponen->first()->label ?: $kode;
                    $kapita = $barisKomponen->first()->masuk_slip !== '1';
                    $subtotal = (float) $barisKomponen->sum('nominal');
                    $pasien = (int) $barisKomponen->sum('pasien');
                    $besar = $barisKomponen->count() > $this::BATAS_LIPAT;
                    $dilipat = $besar && !in_array($kode, $komponenTerbuka, true);
                @endphp

                <div class="border-b border-hairline dark:border-gray-700" wire:key="komp-{{ $kode }}">
                    <div class="flex flex-wrap items-baseline justify-between px-4 py-2 gap-x-4 bg-surface-soft dark:bg-gray-800/60">
                        <div class="text-sm font-semibold text-ink dark:text-gray-100">
                            {{ $label }}
                            @if ($kapita)
                                <span class="ml-1 px-1.5 py-0.5 text-xs font-medium rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">dasar hitung per kapita</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 text-xs text-muted dark:text-gray-400">
                            <span>{{ $pasien }} pasien &middot; <span class="font-semibold tabular-nums text-body dark:text-gray-200">{{ $rp($subtotal) }}</span></span>
                            @if ($besar)
                                <x-secondary-button type="button" wire:click="toggleKomponen('{{ $kode }}')" wire:loading.attr="disabled" wire:target="toggleKomponen('{{ $kode }}')" class="!py-1 !px-2.5 text-xs whitespace-nowrap">
                                    <span wire:loading.remove wire:target="toggleKomponen('{{ $kode }}')">{{ $dilipat ? 'Tampilkan ' . $barisKomponen->count() . ' baris' : 'Sembunyikan baris' }}</span>
                                    <span wire:loading wire:target="toggleKomponen('{{ $kode }}')" class="inline-flex items-center gap-1.5"><x-loading class="w-4 h-4" /> Memuat...</span>
                                </x-secondary-button>
                            @endif
                        </div>
                    </div>

                    @if ($dilipat)
                        <div class="px-4 py-2 text-xs italic text-muted dark:text-gray-400">
                            {{ $barisKomponen->count() }} baris dilipat supaya lampiran cepat dibuka &mdash; subtotal di atas sudah menghitung semuanya. Unduh Excel tetap memuat seluruh baris.
                        </div>
                    @else
                        @if ($kapita)
                            <div class="px-4 py-1.5 text-xs border-b border-hairline bg-amber-50 text-amber-900 dark:bg-amber-900/20 dark:text-amber-200 dark:border-gray-700">
                                Dokter ini dibayar per kepala. Nominal di bawah adalah tarif komponen dan <strong>tidak masuk slip</strong>; yang dipakai hanya jumlah pasiennya.
                            </div>
                        @endif

                        {{-- table-fixed: lebar kolom dipatuhi, isi berlebih dipotong (tooltip) — satu No. SEP panjang tidak menggusur kolom nama. --}}
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[64rem] text-sm text-left table-fixed text-body dark:text-gray-300">
                                <thead class="text-xs uppercase text-muted bg-canvas dark:bg-gray-900 dark:text-gray-400">
                                    <tr>
                                        <th class="w-12 px-2 py-2 text-right">No</th>
                                        <th class="w-24 px-2 py-2">Tanggal</th>
                                        <th class="w-24 px-2 py-2">No. RM</th>
                                        <th class="px-3 py-2">Nama Pasien</th>
                                        <th class="w-40 px-2 py-2">Klaim</th>
                                        <th class="px-2 py-2 w-52">No. SEP</th>
                                        <th class="w-24 px-2 py-2">No. Transaksi</th>
                                        <th class="w-32 px-3 py-2 text-right">Nominal (Rp)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($barisKomponen as $baris)
                                        <tr wire:key="lamp-{{ $baris->lamp_id }}" class="border-t border-hairline hover:bg-surface-soft dark:border-gray-700 dark:hover:bg-gray-800/50">
                                            <td class="px-2 py-1.5 text-right tabular-nums text-muted dark:text-gray-400">{{ $loop->iteration }}</td>
                                            <td class="px-2 py-1.5 whitespace-nowrap tabular-nums">{{ $baris->tgl }}</td>
                                            <td class="px-2 py-1.5 whitespace-nowrap tabular-nums">{{ $baris->reg_no ?: '—' }}</td>
                                            {{-- Keterangan (nama jasa/tindakan) ditumpuk di bawah nama supaya kolom nama tidak menyempit. --}}
                                            <td class="px-3 py-1.5 text-ink dark:text-gray-100" title="{{ $baris->nama }}{{ $baris->keterangan ? ' — ' . $baris->keterangan : '' }}">
                                                <span class="block truncate">{{ $baris->nama ?: '—' }}</span>
                                                @if ($baris->keterangan)
                                                    <span class="block text-xs truncate text-muted dark:text-gray-400">{{ $baris->keterangan }}</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-1.5 truncate" title="{{ $baris->klaim }}">
                                                {{ $baris->klaim ?: '—' }}
                                                @if ($baris->klaim_status === 'BPJS')
                                                    <span class="ml-1 px-1 py-0.5 text-xs font-medium rounded bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">BPJS</span>
                                                @elseif ($baris->klaim_status && $baris->klaim_status !== $baris->klaim)
                                                    <span class="ml-1 text-xs text-muted dark:text-gray-400">{{ $baris->klaim_status }}</span>
                                                @endif
                                            </td>
                                            {{-- SEP kosong itu WAJAR: pasien umum tidak punya. --}}
                                            <td class="px-2 py-1.5 truncate tabular-nums text-muted dark:text-gray-400" title="{{ $baris->no_sep }}">{{ $baris->no_sep ?: '—' }}</td>
                                            <td class="px-2 py-1.5 whitespace-nowrap tabular-nums text-muted dark:text-gray-400">{{ $baris->txn_no }}</td>
                                            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ $rp($baris->nominal) }}</td>
                                        </tr>
                                    @endforeach
                                    <tr class="font-semibold border-t-2 border-gray-400 bg-surface-soft dark:border-gray-600 dark:bg-gray-800/60">
                                        <td colspan="7" class="px-3 py-2 text-right">Subtotal {{ $label }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ $rp($subtotal) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- REKONSILIASI — lampiran = transaksi; slip = snapshot angka. Selisih tampil apa adanya. --}}
            <div class="px-4 py-3 bg-surface-card dark:bg-gray-800">
                <div class="mb-1.5 text-xs font-semibold tracking-wider uppercase text-muted dark:text-gray-400">Rekonsiliasi dengan Slip</div>
                <table class="w-full text-sm text-body dark:text-gray-300">
                    <tr>
                        <td class="py-1">Total nominal transaksi pada lampiran</td>
                        <td class="w-40 py-1 text-right tabular-nums">{{ $rp($angka['totalTransaksi']) }}</td>
                    </tr>
                    @if ($angka['kapitaSlip'] != 0.0 || $angka['totalDasarKapita'] != 0.0)
                        <tr>
                            <td class="py-1">Jasa per kapita pada slip <span class="text-xs text-muted dark:text-gray-400">(dasar hitung {{ $rp($angka['totalDasarKapita']) }} tidak dijumlahkan)</span></td>
                            <td class="py-1 text-right tabular-nums">{{ $rp($angka['kapitaSlip']) }}</td>
                        </tr>
                    @endif
                    <tr class="font-bold border-t border-gray-400 text-ink dark:border-gray-600 dark:text-gray-100">
                        <td class="py-1.5">Jasa pada Slip Gaji <span class="text-xs font-normal text-muted dark:text-gray-400">(versi {{ $h->versi }})</span></td>
                        <td class="py-1.5 text-right tabular-nums">{{ $rp($angka['jasaSlip']) }}</td>
                    </tr>
                    @if (round($angka['selisih'], 0) != 0.0)
                        <tr class="text-amber-800 dark:text-amber-300">
                            <td class="py-1">Selisih <span class="text-xs">&mdash; dari baris jasa yang ditambahkan manual pada slip, atau dari transaksi yang berubah setelah slip dibuat.</span></td>
                            <td class="py-1 font-bold text-right tabular-nums">{{ $rp($angka['selisih']) }}</td>
                        </tr>
                    @endif
                    <tr class="text-muted dark:text-gray-400">
                        <td class="py-1">Diterima dokter (setelah potongan &amp; tambahan)</td>
                        <td class="py-1 text-right tabular-nums">{{ $rp($h->gaji_diterima) }}</td>
                    </tr>
                </table>
            </div>
        </div>
    @endif
</div>
