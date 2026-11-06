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
 * Tab LAMPIRAN Gaji Dokter (anak pembungkus gaji-dokter): rincian pasien / transaksi di balik angka slip satu dokter
 * (uang periksa, tindakan, visite, operasi, kapita, …) per komponen dengan subtotal — snapshot versi yang dipilih.
 * Dibuka dari Buku (tombol Lampiran → gd_id) atau pilih sendiri bulan jasa + dokter + versi di sini.
 * Baris "tidak masuk slip" = grup kapita yang di SIRUS dibayar lewat skema lain (ditampilkan untuk pembanding).
 */
new class extends Component {
    #[Session(key: 'gaji-dokter-bulan')]
    public string $bulan = ''; // mm/yyyy = bulan JASA

    public ?int $gdId = null;
    public string $drId = '';
    public string $komponen = '';
    public bool $hanyaMasukSlip = true;

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
        $this->segarkan();
    }

    public function updatedGdId(): void { $this->gdId = $this->gdId ? (int) $this->gdId : null; $this->komponen = ''; $this->segarkan(); }
    public function updatedKomponen(): void { unset($this->rows); }
    public function updatedHanyaMasukSlip(): void { unset($this->rows); }

    private function segarkan(): void
    {
        unset($this->header, $this->rows, $this->versiOpsi, $this->komponenOpsi);
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

    #[Computed]
    public function semuaBaris()
    {
        return $this->gdId ? GajiDokterSirus::lampiran($this->gdId) : collect();
    }

    /** [komponen => [label, n, nominal, masuk_slip]] untuk saringan & ringkasan. */
    #[Computed]
    public function komponenOpsi(): array
    {
        return $this->semuaBaris->groupBy('komponen')->map(fn($g, $k) => ['label' => $g->first()->label ?: $k, 'n' => $g->count(), 'pasien' => (int) $g->sum('pasien'),
            'nominal' => (float) $g->sum('nominal'), 'masuk_slip' => $g->first()->masuk_slip === '1'])->sortBy('label')->all();
    }

    #[Computed]
    public function rows()
    {
        return $this->semuaBaris
            ->when($this->komponen !== '', fn($c) => $c->where('komponen', $this->komponen))
            ->when($this->hanyaMasukSlip, fn($c) => $c->where('masuk_slip', '1'))
            ->values();
    }
};
?>

<div class="mt-4">
    @php
        $rp = fn($n) => GajiKaryawan::rupiah($n);
        $h = $this->header;
        $komponenOpsi = $this->komponenOpsi;
    @endphp

    {{-- TOOLBAR --}}
    <div class="flex flex-wrap items-end gap-3 px-4 py-3 mb-3 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
        <div>
            <x-input-label value="Bulan Jasa" />
            <div class="flex items-center gap-1 mt-1">
                <x-secondary-button type="button" wire:click="geserBulan(-1)" class="!px-3" title="Bulan sebelumnya" aria-label="Bulan sebelumnya">‹</x-secondary-button>
                <div class="w-28">
                    <x-text-input wire:model.live.debounce.600ms="bulan" x-mask="99/9999" placeholder="mm/yyyy" class="w-full text-center font-mono" aria-label="Bulan jasa" />
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
                        <option value="{{ $d->dr_id }}">{{ $d->dr_name }} ({{ $d->jumlah_lampiran }} baris)</option>
                    @endforeach
                </x-select-input>
            </div>
        </div>
        <div class="w-56">
            <x-input-label for="gdl-versi" value="Versi snapshot" />
            <div class="mt-1">
                <x-select-input id="gdl-versi" wire:model.live="gdId">
                    @foreach ($this->versiOpsi as $v)
                        <option value="{{ $v->gd_id }}">Versi {{ $v->versi }}{{ $v->terbaru === '1' ? ' (terbaru)' : '' }} · {{ \Carbon\Carbon::parse($v->diambil_at)->format('d/m/Y H:i') }}</option>
                    @endforeach
                </x-select-input>
            </div>
        </div>
        <div class="w-64">
            <x-input-label for="gdl-komponen" value="Komponen" />
            <div class="mt-1">
                <x-select-input id="gdl-komponen" wire:model.live="komponen">
                    <option value="">Semua komponen</option>
                    @foreach ($komponenOpsi as $kode => $k)
                        <option value="{{ $kode }}">{{ $k['label'] }} ({{ $k['n'] }})</option>
                    @endforeach
                </x-select-input>
            </div>
        </div>
        <label class="inline-flex items-center gap-2 pb-2 text-sm cursor-pointer select-none">
            <x-toggle :current="$hanyaMasukSlip ? '1' : '0'" trueValue="1" falseValue="0" wireClick="$set('hanyaMasukSlip', {{ $hanyaMasukSlip ? 'false' : 'true' }})" aria-label="Hanya baris yang masuk slip" />
            <span>Hanya yang masuk slip</span>
        </label>
    </div>

    @if (!$h)
        <div class="px-4 py-8 text-center border bg-canvas border-hairline rounded-2xl text-muted dark:bg-gray-900 dark:border-gray-700">Belum ada snapshot bulan jasa ini. Tarik dulu di tab Buku Gaji Dokter → <b>Ambil dari SIRUS</b>.</div>
    @else
        {{-- RINGKASAN PER KOMPONEN --}}
        <div class="grid grid-cols-2 gap-2 mb-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($komponenOpsi as $kode => $k)
                <button type="button" wire:click="$set('komponen', '{{ $komponen === $kode ? '' : $kode }}')"
                    class="px-3 py-2 text-left border rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:focus-visible:ring-brand-lime {{ $komponen === $kode ? 'bg-surface-strong border-brand-green dark:bg-gray-800 dark:border-brand-lime' : 'bg-canvas border-hairline dark:bg-gray-900 dark:border-gray-700' }} {{ $k['masuk_slip'] ? '' : 'opacity-70' }}"
                    aria-pressed="{{ $komponen === $kode ? 'true' : 'false' }}">
                    <span class="block text-xs truncate text-muted">{{ $k['label'] }}{!! $k['masuk_slip'] ? '' : ' <span class="italic">(tidak masuk slip)</span>' !!}</span>
                    <span class="block font-mono text-sm font-semibold tabular-nums">{{ $rp($k['nominal']) }}</span>
                    <span class="block text-xs text-muted">{{ $k['n'] }} baris · {{ $k['pasien'] }} pasien</span>
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2 px-4 mb-2 text-xs text-muted">
            <span class="font-semibold text-ink dark:text-gray-100">{{ $h->dr_name }}</span>
            <span>· versi {{ $h->versi }} · diambil {{ \Carbon\Carbon::parse($h->diambil_at)->format('d/m/Y H:i') }} · jasa {{ $rp($h->jasa_total) }} · diterima {{ $rp($h->gaji_diterima) }}</span>
            <span class="ml-auto">{{ $this->rows->count() }} baris · {{ $rp($this->rows->sum('nominal')) }}</span>
        </div>

        <div class="overflow-x-auto bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th>Komponen</th>
                        <th>Tgl</th>
                        <th>Pasien</th>
                        <th>Keterangan</th>
                        <th>Klaim</th>
                        <th>No. transaksi</th>
                        <th class="text-right">Jml</th>
                        <th class="text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        <tr wire:key="lamp-{{ $row->lamp_id }}" class="{{ $row->masuk_slip === '1' ? '' : 'opacity-60' }}">
                            <td class="text-xs">{{ $row->label ?: $row->komponen }}@if ($row->masuk_slip !== '1') <x-badge variant="gray">tidak masuk slip</x-badge>@endif</td>
                            <td class="font-mono text-xs whitespace-nowrap">{{ $row->tgl }}</td>
                            <td><span class="text-sm">{{ $row->nama ?: '–' }}</span>@if ($row->reg_no)<span class="block font-mono text-xs text-muted">{{ $row->reg_no }}</span>@endif</td>
                            <td class="text-xs">{{ $row->keterangan }}</td>
                            <td class="text-xs">{{ $row->klaim }}@if ($row->klaim_status)<span class="block text-muted">{{ $row->klaim_status }}</span>@endif @if ($row->no_sep)<span class="block font-mono text-muted">{{ $row->no_sep }}</span>@endif</td>
                            <td class="font-mono text-xs">{{ $row->txn_no }}</td>
                            <td class="font-mono text-right tabular-nums">{{ $row->pasien ?: '' }}</td>
                            <td class="font-mono text-right tabular-nums">{{ $rp($row->nominal) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-8 text-center text-muted">Tidak ada baris lampiran untuk saringan ini.</td></tr>
                    @endforelse
                </tbody>
                @if ($this->rows->isNotEmpty())
                    <tfoot>
                        <tr class="font-semibold">
                            <td colspan="6">Total {{ $komponen !== '' ? ($komponenOpsi[$komponen]['label'] ?? $komponen) : 'semua komponen' }}</td>
                            <td class="font-mono text-right tabular-nums">{{ $this->rows->sum('pasien') ?: '' }}</td>
                            <td class="font-mono text-right tabular-nums">{{ $rp($this->rows->sum('nominal')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif
</div>
