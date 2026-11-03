<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Support\Absensi;
use App\Support\Format;
use App\Support\IndikatorMutu;

/**
 * KERTAS KERJA INDIKATOR MUTU — satu indikator × satu unit × satu bulan.
 * Petugas mencatat setiap pengamatan (peluang / pasien / kejadian); pembilang, penyebut, dan
 * capaian dihitung otomatis dari aturan indikator (App\Support\IndikatorMutu). Mutasi di -actions.
 *
 * Cakupan (IndikatorMutu::cakupanUser):
 *   - admin / Komite Mutu → pilih unit, termasuk "Tingkat RS" (komplain, kepuasan, CP, MPP); kunci bulan.
 *   - role lain           → DIKUNCI ke unit karyawan yang terhubung ke akunnya (kepala ruang).
 * Bulan terkunci = sudah divalidasi Komite Mutu — kertas kerja hanya bisa dilihat.
 */
new class extends Component {
    #[Url(as: 'bulan')]
    public string $bulan = '';      // mm/yyyy
    #[Url(as: 'unit')]
    public string $filterUnit = ''; // admin / Komite Mutu; IndikatorMutu::TINGKAT_RS = tingkat RS
    #[Url(as: 'ind')]
    public string $indKode = '';

    public function mount(): void
    {
        if ($this->bulan === '') {
            $this->bulan = now()->format(Format::BULAN);
        }
        if ($this->cakupan['semuaUnit'] && $this->filterUnit === '') {
            $this->filterUnit = (string) array_key_first($this->unitPilihan);
        }
    }

    public function updatedFilterUnit(): void
    {
        unset($this->indikatorList, $this->indikator, $this->rows);
    }

    public function awalBulan(): Carbon
    {
        try {
            return Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    public function geserBulan(int $jumlahBulan): void
    {
        $this->bulan = $this->awalBulan()->addMonthsNoOverflow($jumlahBulan)->format(Format::BULAN);
    }

    // ─── Cakupan unit ─────────────────────────────────────────────────────────
    #[Computed]
    public function cakupan(): array
    {
        return IndikatorMutu::cakupanUser(auth()->user());
    }

    /** Unit yang sedang diisi; role terbatas selalu unitnya sendiri. '' = belum ada. */
    public function unitAktif(): string
    {
        return $this->cakupan['semuaUnit'] ? $this->filterUnit : (string) $this->cakupan['unitId'];
    }

    /** Pilihan unit admin / Komite Mutu: unit yang punya indikator + tingkat RS. */
    #[Computed]
    public function unitPilihan(): array
    {
        $pilihan = [IndikatorMutu::TINGKAT_RS => 'Tingkat RS (Komite Mutu)'];
        foreach (DB::table('siapmst_unit_kerjas')->where('active_status', '1')->orderBy('unit_name')->get(['unit_id', 'unit_name']) as $unit) {
            if (IndikatorMutu::untukUnit($unit->unit_id) !== []) {
                $pilihan[$unit->unit_id] = $unit->unit_name;
            }
        }

        return $pilihan;
    }

    #[Computed]
    public function namaUnitAktif(): string
    {
        $unitId = $this->unitAktif();
        if ($unitId === IndikatorMutu::TINGKAT_RS) {
            return 'Tingkat RS';
        }

        return (string) DB::table('siapmst_unit_kerjas')->where('unit_id', $unitId)->value('unit_name');
    }

    #[Computed]
    public function indikatorList(): array
    {
        return IndikatorMutu::untukUnit($this->unitAktif());
    }

    #[Computed]
    public function indikator(): ?array
    {
        return $this->indikatorList[$this->indKode] ?? (array_values($this->indikatorList)[0] ?? null);
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function rows()
    {
        if (!$this->indikator) {
            return collect();
        }
        $awal = $this->awalBulan();
        $unitId = $this->unitAktif();

        return DB::table('siaptxn_indikator_obs as ob')
            ->leftJoin('users as us', 'us.id', '=', 'ob.petugas_id')
            ->where('ob.ind_kode', $this->indikator['kode'])
            ->when($unitId === IndikatorMutu::TINGKAT_RS, fn($query) => $query->whereNull('ob.unit_id'))
            ->when($unitId !== IndikatorMutu::TINGKAT_RS, fn($query) => $query->where('ob.unit_id', $unitId))
            ->where('ob.obs_tanggal', '>=', Absensi::tanggalSql($awal))
            ->where('ob.obs_tanggal', '<', Absensi::tanggalSql($awal->copy()->addMonth()))
            ->orderBy('ob.obs_tanggal')->orderBy('ob.obs_id')
            ->get(['ob.*', 'us.name as petugas_name'])
            ->map(function ($row) {
                $row->isian = json_decode((string) $row->obs_isian, true) ?: [];
                $row->hasil = IndikatorMutu::hitungBaris($this->indikator, $row->isian);

                return $row;
            });
    }

    /** Ringkasan bulan berjalan — baris eksklusi tidak dihitung. */
    #[Computed]
    public function ringkasan(): array
    {
        $dihitung = $this->rows->where('obs_eksklusi', '0');
        $pembilang = (float) $dihitung->sum('obs_pembilang');
        $penyebut = (float) $dihitung->sum('obs_penyebut');
        $capaian = $this->indikator ? IndikatorMutu::capaian($pembilang, $penyebut, $this->indikator['pengali']) : null;

        return [
            'baris'     => $this->rows->count(),
            'eksklusi'  => $this->rows->where('obs_eksklusi', '1')->count(),
            'pembilang' => $pembilang,
            'penyebut'  => $penyebut,
            'capaian'   => $capaian,
            'tercapai'  => $this->indikator ? IndikatorMutu::tercapai($capaian, $this->indikator) : null,
        ];
    }

    #[Computed]
    public function terkunci(): bool
    {
        return IndikatorMutu::terkunci($this->awalBulan());
    }

    public function bisaIsi(): bool
    {
        return $this->indikator !== null && !$this->terkunci;
    }

    // ─── Aksi (dikirim ke -actions) ───────────────────────────────────────────
    public function openCreate(): void
    {
        if (!$this->bisaIsi()) {
            return;
        }
        $this->dispatch('mutu.kertas-kerja.openCreate', indKode: $this->indikator['kode'], unitId: $this->unitAktif(), bulan: $this->bulan);
    }

    public function openEdit(int $obsId): void
    {
        $this->dispatch('mutu.kertas-kerja.openEdit', obsId: $obsId);
    }

    #[On('mutu.kertas-kerja.saved')]
    public function refreshAfterSaved(): void
    {
        unset($this->rows, $this->terkunci);
    }

    /** Kunci / buka bulan — admin & Komite Mutu (Gate mutu.kunci). */
    public function kunciBulan(): void
    {
        abort_unless(auth()->user()?->can('mutu.kunci'), 403);
        DB::table('siaptxn_indikator_kuncis')->insertOrIgnore([
            'periode' => IndikatorMutu::periode($this->awalBulan()), 'dikunci_oleh' => auth()->id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        unset($this->terkunci);
        $this->dispatch('toast', type: 'success', message: "Kertas kerja {$this->bulan} dikunci — semua unit tidak bisa mengubah lagi.");
    }

    public function bukaKunci(): void
    {
        abort_unless(auth()->user()?->can('mutu.kunci'), 403);
        DB::table('siaptxn_indikator_kuncis')->where('periode', IndikatorMutu::periode($this->awalBulan()))->delete();
        unset($this->terkunci);
        $this->dispatch('toast', type: 'success', message: "Kunci {$this->bulan} dibuka.");
    }
};
?>

<div>
    <x-page-title
        title="Kertas Kerja Indikator Mutu"
        subtitle="Catat setiap pengamatan — pembilang, penyebut & capaian dihitung otomatis sesuai profil indikator" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            @if (!$this->cakupan['semuaUnit'] && !$this->cakupan['unitId'])
                <div class="max-w-2xl p-6 mt-6 border rounded-2xl border-warning-subtle bg-warning-soft text-fg-warning" role="alert">
                    <p class="font-semibold">Akun Anda belum terhubung ke unit kerja.</p>
                    <p class="mt-1 text-sm">Minta Admin menghubungkan akun ke karyawan (Sistem › User Control) dan mengisi
                        <b>Unit Kerja</b> karyawan tersebut (Master Karyawan).</p>
                </div>
            @else
                @php
                    $ind = $this->indikator;
                    $rk = $this->ringkasan;
                @endphp

                {{-- TOOLBAR --}}
                <div class="sticky z-30 px-4 py-3 border-b bg-surface-soft border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="flex items-center gap-1">
                                <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-2.5" aria-label="Bulan sebelumnya">‹</x-secondary-button>
                                <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" aria-label="Bulan (mm/yyyy)" class="w-28 font-mono text-center" />
                                <x-secondary-button type="button" wire:click="geserBulan(1)" class="!px-2.5" aria-label="Bulan berikutnya">›</x-secondary-button>
                            </div>
                            @if ($this->cakupan['semuaUnit'])
                                <div class="w-60">
                                    <x-select-input wire:model.live="filterUnit" aria-label="Unit">
                                        @foreach ($this->unitPilihan as $unitId => $namaUnit)
                                            <option value="{{ $unitId }}">{{ $namaUnit }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                            @else
                                <div class="px-3 py-2 text-sm font-semibold border rounded-lg bg-canvas border-hairline text-ink dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100">
                                    Unit: {{ $this->namaUnitAktif }}
                                </div>
                            @endif
                            <div class="w-full sm:w-[28rem]">
                                <x-select-input wire:model.live="indKode" aria-label="Indikator">
                                    @foreach ($this->indikatorList as $kode => $definisi)
                                        <option value="{{ $kode }}" @selected($ind && $ind['kode'] === $kode)>{{ $kode }} — {{ $definisi['judul'] }}</option>
                                    @endforeach
                                </x-select-input>
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-2">
                            @can('mutu.kunci')
                                @if ($this->terkunci)
                                    <x-confirm-button variant="warning-soft" action="bukaKunci()" title="Buka Kunci Bulan"
                                        message="Buka kunci {{ $bulan }}? Semua unit bisa mengubah kertas kerja bulan ini lagi."
                                        confirmText="Ya, buka" cancelText="Batal">Buka Kunci</x-confirm-button>
                                @else
                                    <x-confirm-button variant="outline" action="kunciBulan()" title="Kunci Bulan"
                                        message="Kunci {{ $bulan }}? Kertas kerja SEMUA unit bulan ini tidak bisa ditambah, diubah, atau dihapus lagi."
                                        confirmText="Ya, kunci" cancelText="Batal">Kunci Bulan</x-confirm-button>
                                @endif
                            @endcan
                            @if ($this->bisaIsi())
                                <x-primary-button type="button" wire:click="openCreate" wire:loading.attr="disabled" wire:target="openCreate">+ Tambah Baris</x-primary-button>
                            @endif
                        </div>
                    </div>
                </div>

                @if (!$ind)
                    <div class="p-10 mt-4 text-center border bg-canvas border-hairline rounded-2xl text-muted dark:bg-gray-900 dark:border-gray-700">
                        Belum ada indikator untuk unit ini.
                    </div>
                @else
                    {{-- KARTU INDIKATOR + RINGKASAN --}}
                    <div class="grid grid-cols-1 gap-3 mt-4 lg:grid-cols-3">
                        <div class="p-4 border lg:col-span-2 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="ds-td-token">{{ $ind['kode'] }}</span>
                                @foreach ($ind['kelompok'] as $kelompok)
                                    <x-badge variant="info">{{ $kelompok }}</x-badge>
                                @endforeach
                                <x-badge variant="gray">Target {{ \App\Support\IndikatorMutu::labelTarget($ind) }}</x-badge>
                                @if (\App\Support\IndikatorMutu::terbalik($ind))
                                    <x-badge variant="warning">Makin kecil makin baik</x-badge>
                                @endif
                                @if ($this->terkunci)
                                    <x-badge variant="danger">Terkunci</x-badge>
                                @endif
                            </div>
                            <h2 class="mt-2 text-base font-semibold text-ink dark:text-gray-100">{{ $ind['judul'] }}</h2>
                            <p class="mt-1 text-sm text-body dark:text-gray-300">{{ $ind['petunjuk'] }}</p>
                            <dl class="grid grid-cols-1 gap-1 mt-2 text-xs sm:grid-cols-2 text-muted dark:text-gray-400">
                                <div><dt class="inline font-semibold">Pembilang:</dt> <dd class="inline">{{ $ind['pembilang'] }}</dd></div>
                                <div><dt class="inline font-semibold">Penyebut:</dt> <dd class="inline">{{ $ind['penyebut'] }}</dd></div>
                            </dl>
                        </div>
                        <div class="p-4 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700" role="status" aria-live="polite">
                            <div class="text-xs font-semibold tracking-wide uppercase text-muted dark:text-gray-400">Capaian {{ $bulan }}</div>
                            <div class="mt-1 text-3xl font-bold tabular-nums text-ink dark:text-gray-100">
                                {{ $rk['capaian'] === null ? 'Tidak ada kasus' : \App\Support\IndikatorMutu::labelCapaian($rk['capaian'], $ind) }}
                            </div>
                            <div class="mt-1 text-sm tabular-nums text-body dark:text-gray-300">
                                {{ \App\Support\IndikatorMutu::angka($rk['pembilang'], 0) }} ÷ {{ \App\Support\IndikatorMutu::angka($rk['penyebut'], 0) }}
                                @if ($ind['pengali'] !== 100) × {{ $ind['pengali'] }} @else × 100% @endif
                            </div>
                            <div class="mt-2">
                                @if ($rk['tercapai'] === true)
                                    <x-badge variant="success">✓ Tercapai</x-badge>
                                @elseif ($rk['tercapai'] === false)
                                    <x-badge variant="danger">✗ Tidak tercapai</x-badge>
                                @endif
                            </div>
                            <div class="mt-2 text-xs text-muted dark:text-gray-400">{{ $rk['baris'] }} baris · {{ $rk['eksklusi'] }} eksklusi (tidak dihitung)</div>
                        </div>
                    </div>

                    {{-- TABEL KERTAS KERJA --}}
                    <div class="flex flex-col flex-1 min-h-0 mt-4 border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex-1 min-h-0 overflow-auto rounded-2xl">
                            <table class="ds-table">
                                <thead class="sticky top-0 z-10">
                                    <tr>
                                        <th class="ds-c">No</th>
                                        <th>Tanggal</th>
                                        @foreach ($ind['kolom'] as $kolom)
                                            <th @class(['ds-c' => in_array($kolom['tipe'], ['ceklis', 'jam', 'angka', 'nilai'], true)])>{{ $kolom['label'] }}</th>
                                        @endforeach
                                        <th>Hasil</th>
                                        <th class="ds-c">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($this->rows as $row)
                                        <tr wire:key="obs-{{ $row->obs_id }}" @class(['opacity-60' => $row->obs_eksklusi === '1'])>
                                            <td class="ds-c tabular-nums">{{ $loop->iteration }}</td>
                                            <td class="whitespace-nowrap tabular-nums">{{ \App\Support\Format::tanggal($row->obs_tanggal) }}</td>
                                            @foreach ($ind['kolom'] as $kolom)
                                                @php $nilai = (string) ($row->isian[$kolom['key']] ?? ''); @endphp
                                                @switch($kolom['tipe'])
                                                    @case('ceklis')
                                                        <td class="ds-c">
                                                            @if ($nilai === '1')
                                                                <span class="font-bold text-success-deep dark:text-success" aria-label="Ya">✓</span>
                                                            @else
                                                                <span class="font-bold text-error-deep dark:text-red-400" aria-label="Tidak">✗</span>
                                                            @endif
                                                        </td>
                                                    @break
                                                    @case('pilihan')
                                                        <td class="text-sm">{{ $kolom['opsi'][$nilai] ?? '—' }}</td>
                                                    @break
                                                    @case('jam')
                                                    @case('angka')
                                                    @case('nilai')
                                                        <td class="ds-c tabular-nums">{{ $nilai !== '' ? $nilai : '—' }}</td>
                                                    @break
                                                    @default
                                                        <td class="text-sm">{{ $nilai !== '' ? $nilai : '—' }}</td>
                                                @endswitch
                                            @endforeach
                                            <td class="whitespace-nowrap">
                                                @if ($row->obs_eksklusi === '1')
                                                    <x-badge variant="gray">Eksklusi</x-badge>
                                                    @if ($row->keterangan)
                                                        <div class="text-xs text-muted dark:text-gray-400">{{ $row->keterangan }}</div>
                                                    @endif
                                                @else
                                                    <x-badge :variant="$row->hasil['baik'] === null ? 'info' : ($row->hasil['baik'] ? 'success' : 'danger')">{{ $row->hasil['label'] }}</x-badge>
                                                    @if ($row->hasil['catatan'] !== '')
                                                        <div class="text-xs tabular-nums text-muted dark:text-gray-400">{{ $row->hasil['catatan'] }}</div>
                                                    @endif
                                                @endif
                                            </td>
                                            <td class="ds-c">
                                                <x-secondary-button type="button" wire:click="openEdit({{ $row->obs_id }})" class="!px-2.5 !py-1 text-xs"
                                                    wire:loading.attr="disabled" wire:target="openEdit({{ $row->obs_id }})">
                                                    {{ $this->terkunci ? 'Lihat' : 'Ubah' }}
                                                </x-secondary-button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ count($ind['kolom']) + 4 }}" class="px-6 py-16 text-center text-muted dark:text-gray-400">
                                                Belum ada pengamatan untuk {{ $ind['kode'] }} di {{ $this->namaUnitAktif }} bulan {{ $bulan }}.
                                                @if ($this->bisaIsi())
                                                    <div class="mt-1 text-sm">Klik <b>+ Tambah Baris</b> untuk mencatat pengamatan pertama.</div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endif

            <livewire:pages::mutu.kertas-kerja.kertas-kerja-actions wire:key="kertas-kerja-actions" />
        </div>
    </div>
</div>
