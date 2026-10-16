<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Support\Format;
use App\Support\Pemeliharaan;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal pemeliharaan alat.
 *   create : pilih ruang + tanggal → semua alat aktif di ruang itu tampil sekaligus, isi B / P / R
 *            (kosong = belum dicek, dilewati). Slot yang sudah terisi (harian: tanggal itu,
 *            mingguan: minggu itu) ikut tampil & diperbarui — tidak dobel.
 *   edit   : satu catatan (tanggal, hasil, keterangan).
 * Keterangan / tindakan wajib untuk P dan R.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode = 'create'; // create|edit
    public ?int   $pmId     = null;
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public string $ruangId  = '';
    public string $tanggal  = ''; // dd/mm/yyyy
    public bool   $tampilTidakRutin = false;

    /**
     * create: daftar berindeks angka — asset_id mengandung titik (IGD.DEF.01/2021) sehingga tidak bisa
     * dipakai sebagai key path Livewire (wire:model="baris.N.ket").
     * [['asset_id', 'hasil' => ''|B|P|R, 'ket', 'pm_id' => ?int, 'awal', 'ket_awal', 'tgl_awal'], ...]
     */
    public array $baris = [];

    /** edit: data satu catatan. */
    public array $form = ['asset_id' => '', 'hasil' => 'B', 'ket' => ''];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->tanggal = now()->format(Format::TANGGAL);
    }

    #[Computed]
    public function ruangs()
    {
        return DB::table('siapmst_ruangs as ru')
            ->whereExists(fn($s) => $s->select(DB::raw(1))->from('siapmst_assets as aa')
                ->whereColumn('aa.ruang_id', 'ru.ruang_id')->where('aa.active_status', '1'))
            ->orderBy('ru.ruang_id')
            ->get(['ru.ruang_id', 'ru.ruang_name']);
    }

    /** Alat yang ditampilkan di entry: rutin (harian dulu, lalu mingguan), tidak rutin bila dicentang. */
    #[Computed]
    public function alatEntry()
    {
        if ($this->ruangId === '') {
            return collect();
        }

        return DB::table('siapmst_assets')
            ->where('ruang_id', $this->ruangId)->where('active_status', '1')
            ->when(!$this->tampilTidakRutin, fn($q) => $q->where('pm_frekuensi', '<>', '-'))
            ->orderByRaw("CASE pm_frekuensi WHEN 'H' THEN 1 WHEN 'M' THEN 2 ELSE 3 END")
            ->orderBy('asset_name')->orderBy('asset_id')
            ->get(['asset_id', 'asset_name', 'merk', 'jumlah', 'pm_frekuensi', 'kondisi']);
    }

    /** Alat catatan yang sedang diedit. */
    #[Computed]
    public function alatEdit(): ?object
    {
        return $this->form['asset_id'] === '' ? null
            : DB::table('siapmst_assets as aa')->leftJoin('siapmst_ruangs as ru', 'ru.ruang_id', '=', 'aa.ruang_id')
                ->where('aa.asset_id', $this->form['asset_id'])->first(['aa.*', 'ru.ruang_name']);
    }

    // ─── Buka ─────────────────────────────────────────────────────────────────
    #[On('aset.pemeliharaan-alat.openCreate')]
    public function openCreate(?string $ruangId = null): void
    {
        $this->resetErrorBag();
        $this->formMode = 'create';
        $this->pmId = null;
        $this->tampilTidakRutin = false;
        $this->tanggal = now()->format(Format::TANGGAL);
        $this->ruangId = $ruangId && $this->ruangs->contains('ruang_id', $ruangId) ? $ruangId : '';
        $this->muatBaris();
        $this->bukaModal();
    }

    #[On('aset.pemeliharaan-alat.openEdit')]
    public function openEdit(int $pmId): void
    {
        $pm = DB::table('siaptxn_pemeliharaans')->where('pm_id', $pmId)->first();
        if (!$pm) {
            $this->dispatch('toast', type: 'error', message: 'Catatan pemeliharaan tidak ditemukan.');
            return;
        }

        $this->resetErrorBag();
        $this->formMode = 'edit';
        $this->pmId = (int) $pm->pm_id;
        $this->tanggal = Format::tanggal($pm->pm_tanggal);
        $this->form = ['asset_id' => $pm->asset_id, 'hasil' => $pm->pm_hasil, 'ket' => (string) $pm->keterangan];
        unset($this->alatEdit);
        $this->bukaModal();
    }

    private function bukaModal(): void
    {
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'pemeliharaan-alat-actions');
    }

    public function updatedRuangId(): void
    {
        $this->muatBaris();
    }

    public function updatedTanggal(): void
    {
        if ($this->formMode === 'create' && $this->tglValid()) {
            $this->muatBaris();
        }
    }

    public function updatedTampilTidakRutin(): void
    {
        $this->muatBaris();
    }

    /** Isi baris dari catatan yang sudah ada di slot tanggal terpilih. */
    private function muatBaris(): void
    {
        unset($this->alatEntry);
        $tgl = $this->tglValid();
        $baru = [];
        foreach ($this->alatEntry as $a) {
            $ada = $tgl ? Pemeliharaan::catatanSlot($a->asset_id, $a->pm_frekuensi, $tgl) : null;
            $baru[] = [
                'asset_id' => $a->asset_id,
                'hasil'    => $ada->pm_hasil ?? '',
                'ket'      => (string) ($ada->keterangan ?? ''),
                'pm_id'    => $ada ? (int) $ada->pm_id : null,
                'awal'     => $ada->pm_hasil ?? '',
                'ket_awal' => (string) ($ada->keterangan ?? ''),
                'tgl_awal' => $ada ? Format::tanggal($ada->pm_tanggal) : null,
            ];
        }
        $this->baris = $baru;
    }

    public function setHasil(int $i, string $hasil): void
    {
        if (isset($this->baris[$i]) && ($hasil === '' || isset(Pemeliharaan::HASIL[$hasil]))) {
            $this->baris[$i]['hasil'] = $hasil;
        }
    }

    public function semuaBaik(): void
    {
        foreach ($this->baris as $i => $b) {
            if ($b['hasil'] === '') {
                $this->baris[$i]['hasil'] = 'B';
            }
        }
    }

    private function tglValid(): ?Carbon
    {
        try {
            $t = Carbon::createFromFormat('!' . Format::TANGGAL, $this->tanggal);

            return $t && $t->format(Format::TANGGAL) === $this->tanggal ? $t : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Validasi tanggal bersama: wajib, valid, tidak di masa depan. */
    private function cekTanggal(): ?Carbon
    {
        $t = $this->tglValid();
        if (!$t) {
            $this->addError('tanggal', 'Tanggal tidak valid (dd/mm/yyyy).');
            return null;
        }
        if ($t->gt(now()->startOfDay())) {
            $this->addError('tanggal', 'Tanggal pemeriksaan tidak boleh di masa depan.');
            return null;
        }

        return $t;
    }

    // ─── Simpan ───────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->resetErrorBag();
        $this->formMode === 'edit' ? $this->simpanEdit() : $this->simpanEntry();
    }

    private function simpanEntry(): void
    {
        $tgl = $this->cekTanggal();
        if ($this->ruangId === '') {
            $this->addError('ruangId', 'Pilih ruang.');
        }
        if (!$tgl || $this->ruangId === '') {
            return;
        }

        $alat = $this->alatEntry->keyBy('asset_id');
        $simpan = [];
        foreach ($this->baris as $i => $b) {
            $id = $b['asset_id'];
            if (!$alat->has($id) || $b['hasil'] === '') {
                continue;
            }
            if (in_array($b['hasil'], ['P', 'R'], true) && trim($b['ket']) === '') {
                $this->addError("baris.{$i}.ket", 'Wajib diisi untuk P / R.');
                continue;
            }
            if (mb_strlen(trim($b['ket'])) > 200) {
                $this->addError("baris.{$i}.ket", 'Maksimal 200 karakter.');
                continue;
            }
            // Catatan lama yang tidak diubah tidak disentuh (petugas & tanggalnya tetap).
            if ($b['pm_id'] && $b['hasil'] === $b['awal'] && trim($b['ket']) === trim($b['ket_awal'])) {
                continue;
            }
            $simpan[$id] = $b;
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->dispatch('toast', type: 'error', message: 'Lengkapi keterangan untuk hasil Perlu Perbaikan / Rusak.');
            return;
        }
        if ($simpan === []) {
            $this->dispatch('toast', type: 'warning', message: 'Belum ada hasil baru yang diisi.');
            return;
        }

        DB::transaction(function () use ($simpan, $alat, $tgl) {
            foreach ($simpan as $id => $b) {
                Pemeliharaan::simpan($id, $alat[$id]->pm_frekuensi, $tgl, $b['hasil'], $b['ket'], auth()->id(), $b['pm_id']);
            }
        });

        $this->dispatch('toast', type: 'success', message: count($simpan) . ' catatan pemeliharaan tersimpan (' . $this->tanggal . ').');
        $this->selesai();
    }

    private function simpanEdit(): void
    {
        $tgl = $this->cekTanggal();
        $alat = $this->alatEdit;
        if (!isset(Pemeliharaan::HASIL[$this->form['hasil']])) {
            $this->addError('form.hasil', 'Pilih hasil.');
        }
        $ket = trim($this->form['ket']);
        if (in_array($this->form['hasil'], ['P', 'R'], true) && $ket === '') {
            $this->addError('form.ket', 'Keterangan / tindakan wajib diisi untuk P / R.');
        }
        if (mb_strlen($ket) > 200) {
            $this->addError('form.ket', 'Maksimal 200 karakter.');
        }
        if (!$tgl || !$alat || $this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $bentrok = Pemeliharaan::catatanSlot($alat->asset_id, $alat->pm_frekuensi, $tgl, $this->pmId);
        if ($bentrok) {
            $this->addError('tanggal', ($alat->pm_frekuensi === 'M' ? 'Minggu ini' : 'Tanggal ini')
                . ' sudah ada catatan (' . Format::tanggal($bentrok->pm_tanggal) . ').');
            return;
        }

        Pemeliharaan::simpan($alat->asset_id, $alat->pm_frekuensi, $tgl, $this->form['hasil'], $ket, auth()->id(), $this->pmId);
        $this->dispatch('toast', type: 'success', message: 'Catatan pemeliharaan diperbarui.');
        $this->selesai();
    }

    // ─── Hapus ────────────────────────────────────────────────────────────────
    #[On('aset.pemeliharaan-alat.requestDelete')]
    public function hapus(int $pmId): void
    {
        $pb = DB::table('siaptxn_perbaikans')->where('pm_id', $pmId)->value('pb_no');
        if ($pb) {
            $this->dispatch('toast', type: 'error', message: "Catatan ini sudah dilaporkan ke perbaikan {$pb} — tidak bisa dihapus.");
            return;
        }

        $n = DB::table('siaptxn_pemeliharaans')->where('pm_id', $pmId)->delete();
        $this->dispatch('toast', type: $n ? 'success' : 'error', message: $n ? 'Catatan pemeliharaan dihapus.' : 'Catatan tidak ditemukan.');
        $this->dispatch('aset.pemeliharaan-alat.saved');
    }

    private function selesai(): void
    {
        $this->dispatch('aset.pemeliharaan-alat.saved');
        $this->closeModal();
    }

    public function closeModal(): void
    {
        $this->resetErrorBag();
        $this->dispatch('close-modal', name: 'pemeliharaan-alat-actions');
    }
};
?>

<div>
    <x-modal name="pemeliharaan-alat-actions" size="4xl" height="full" focusable>
        <x-dirty-modal-content
            name="pemeliharaan-alat-actions"
            event="aset.pemeliharaan-alat.saved"
            label="Pemeliharaan"
            :wireKey="$this->renderKey('modal', [$formMode, $pmId ?? 'baru'])">

            @php
                $warnaPilih = ['B' => 'bg-emerald-600 text-white border-emerald-600', 'P' => 'bg-amber-500 text-white border-amber-500', 'R' => 'bg-red-600 text-white border-red-600'];
                $warnaBadge = ['H' => 'danger', 'M' => 'info', '-' => 'gray'];
            @endphp

            {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
            <x-modul-dokumen.header :judul="($formMode === 'edit' ? 'Ubah Catatan Pemeliharaan' : 'Entry Pemeliharaan Alat')"
                ikon="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z" tutupAlpine="tryClose()">
                @if ($formMode === 'edit') Satu catatan per alat per {{ ($this->alatEdit->pm_frekuensi ?? '') === 'M' ? 'minggu' : 'tanggal' }}. @else Pilih ruang & tanggal, lalu isi hasil tiap alat. Alat yang tidak dicek biarkan kosong. @endif
            </x-modul-dokumen.header>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20">
                @if ($formMode === 'edit')
                    @php $alat = $this->alatEdit; @endphp
                    <x-border-form title="Catatan">
                        <div class="space-y-4">
                            <div class="p-3 rounded-lg bg-surface-soft dark:bg-gray-800">
                                <div class="font-semibold text-ink dark:text-gray-100">{{ $alat->asset_name ?? '-' }}</div>
                                <div class="font-mono text-xs text-muted">{{ $alat->asset_id ?? '' }} · {{ $alat->ruang_id ?? '' }} {{ $alat->ruang_name ?? '' }}
                                    · {{ \App\Support\Pemeliharaan::FREKUENSI[$alat->pm_frekuensi ?? '-'] ?? '' }}</div>
                            </div>
                            <div class="w-48">
                                <x-input-label value="Tanggal pemeriksaan *" />
                                <x-text-input wire:model="tanggal" x-mask="99/99/9999" placeholder="dd/mm/yyyy" class="w-full mt-1 font-mono" />
                                <x-input-error :messages="$errors->get('tanggal')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Hasil *" />
                                <div class="flex gap-2 mt-1">
                                    @foreach (\App\Support\Pemeliharaan::HASIL as $kode => $nama)
                                        <button type="button" wire:click="$set('form.hasil', '{{ $kode }}')"
                                            class="px-4 py-2 text-sm font-semibold border rounded-lg transition {{ $form['hasil'] === $kode ? $warnaPilih[$kode] : 'bg-canvas text-muted border-hairline hover:border-gray-400' }}">
                                            {{ $kode }} — {{ $nama }}
                                        </button>
                                    @endforeach
                                </div>
                                <x-input-error :messages="$errors->get('form.hasil')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label value="Keterangan / tindakan {{ in_array($form['hasil'], ['P', 'R'], true) ? '*' : '' }}" />
                                <x-text-input wire:model="form.ket" maxlength="200" placeholder="Mis. kabel power terkelupas, dilaporkan ke IPSRS" class="w-full mt-1" />
                                <x-input-error :messages="$errors->get('form.ket')" class="mt-1" />
                            </div>
                        </div>
                    </x-border-form>
                @else
                    <x-border-form title="Ruang & tanggal">
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="w-72">
                                <x-input-label value="Ruang *" />
                                <x-select-input wire:model.live="ruangId" :error="$errors->has('ruangId')" class="mt-1">
                                    <option value="">— pilih —</option>
                                    @foreach ($this->ruangs as $ru)
                                        <option value="{{ $ru->ruang_id }}">{{ $ru->ruang_id }} — {{ $ru->ruang_name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('ruangId')" class="mt-1" />
                            </div>
                            <div class="w-40">
                                <x-input-label value="Tanggal *" />
                                <x-text-input wire:model.live.debounce.500ms="tanggal" x-mask="99/99/9999" placeholder="dd/mm/yyyy" class="w-full mt-1 font-mono" />
                                <x-input-error :messages="$errors->get('tanggal')" class="mt-1" />
                            </div>
                            <div class="pb-2">
                                <x-toggle wire:model.live="tampilTidakRutin" :trueValue="true" :falseValue="false" label="Tampilkan alat tidak rutin" />
                            </div>
                            @if (count($baris))
                                <x-secondary-button type="button" wire:click="semuaBaik" class="ml-auto">✓ Semua yang kosong = Baik</x-secondary-button>
                            @endif
                        </div>
                    </x-border-form>

                    <div class="mt-4 overflow-hidden border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                        <table class="ds-table">
                            <thead>
                                <tr>
                                    <th>Alat</th>
                                    <th>Frekuensi</th>
                                    <th class="ds-c">Hasil</th>
                                    <th>Keterangan / tindakan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $alatMap = $this->alatEntry->keyBy('asset_id'); @endphp
                                @forelse ($baris as $i => $b)
                                    @php $a = $alatMap[$b['asset_id']] ?? null; @endphp
                                    @continue(!$a)
                                    <tr wire:key="entry-{{ $a->asset_id }}">
                                        <td>
                                            <div class="ds-td-strong">{{ $a->asset_name }}</div>
                                            <div class="font-mono text-xs text-muted">{{ $a->asset_id }}{{ (int) $a->jumlah > 1 ? ' · ' . (int) $a->jumlah . ' unit' : '' }}</div>
                                            @if ($b['pm_id'])
                                                <div class="text-[11px] text-emerald-700">Sudah dicek {{ $b['tgl_awal'] }} ({{ $b['awal'] }}) — mengubah akan memperbarui catatan itu</div>
                                            @endif
                                        </td>
                                        <td>
                                            <x-badge :variant="$warnaBadge[$a->pm_frekuensi] ?? 'gray'">{{ \App\Support\Pemeliharaan::FREKUENSI[$a->pm_frekuensi] ?? $a->pm_frekuensi }}</x-badge>
                                        </td>
                                        <td class="ds-c whitespace-nowrap">
                                            <div class="inline-flex gap-1">
                                                @foreach (\App\Support\Pemeliharaan::HASIL as $kode => $nama)
                                                    <button type="button" wire:click="setHasil({{ $i }}, '{{ $kode }}')" title="{{ $nama }}"
                                                        class="w-9 h-8 text-sm font-bold border rounded-lg transition {{ $b['hasil'] === $kode ? $warnaPilih[$kode] : 'bg-canvas text-muted border-hairline hover:border-gray-400' }}">
                                                        {{ $kode }}
                                                    </button>
                                                @endforeach
                                                @if ($b['hasil'] !== '' && !$b['pm_id'])
                                                    <button type="button" wire:click="setHasil({{ $i }}, '')" title="Kosongkan (tidak dicek)"
                                                        class="w-8 h-8 text-sm border rounded-lg text-muted border-hairline hover:border-gray-400">×</button>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="min-w-[16rem]">
                                            <x-text-input wire:model="baris.{{ $i }}.ket" maxlength="200"
                                                placeholder="{{ in_array($b['hasil'], ['P', 'R'], true) ? 'Wajib: kerusakan / tindakan' : 'Opsional' }}" class="w-full text-sm" />
                                            <x-input-error :messages="$errors->get('baris.' . $i . '.ket')" class="mt-1" />
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-6 py-10 text-center text-muted dark:text-gray-400">
                                            {{ $ruangId === '' ? 'Pilih ruang dulu.' : 'Tidak ada alat ber-pemeliharaan rutin di ruang ini. Atur frekuensi di Inventaris Alat, atau centang "Tampilkan alat tidak rutin".' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm text-muted">
                        @if ($formMode === 'create' && count($baris))
                            @php $terisi = collect($baris)->filter(fn($b) => $b['hasil'] !== '')->count(); @endphp
                            {{ $terisi }} / {{ count($baris) }} alat terisi
                        @endif
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                        <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">Simpan</span>
                            <span wire:loading wire:target="save">Menyimpan...</span>
                        </x-primary-button>
                    </div>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>
