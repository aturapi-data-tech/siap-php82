<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;
use App\Support\Absensi;
use App\Support\Format;
use App\Support\IndikatorMutu;

/**
 * Modal satu baris kertas kerja indikator mutu (`siaptxn_indikator_obs`).
 * Field dibentuk dari definisi indikator (IndikatorMutu::definisi()['kolom']); pembilang & penyebut
 * dihitung ulang di server saat simpan — tidak pernah diterima dari browser.
 * Guard server: akses unit, indikator milik unit, bulan belum dikunci, tanggal di bulan aktif & tidak di masa depan.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode = 'create'; // create|edit
    public int    $obsId    = 0;
    public string $indKode  = '';
    public string $unitId   = '';       // kode unit atau IndikatorMutu::TINGKAT_RS
    public string $bulan    = '';       // mm/yyyy
    public bool   $readOnly = false;
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    #[Computed]
    public function indikator(): ?array
    {
        return IndikatorMutu::cari($this->indKode);
    }

    // ─── Open ─────────────────────────────────────────────────────────────────
    #[On('mutu.kertas-kerja.openCreate')]
    public function openCreate(string $indKode, string $unitId, string $bulan): void
    {
        $this->indKode = $indKode;
        $this->unitId = $unitId;
        $this->bulan = $bulan;
        unset($this->indikator);
        if (!$this->bolehIsi()) {
            return;
        }

        $this->resetForm();
        $this->formMode = 'create';
        $this->obsId = 0;
        $this->readOnly = false;
        $awal = $this->awalBulan();
        // Tanggal awal: hari ini bila di bulan aktif, selain itu tanggal 1.
        $this->form['tanggal'] = (now()->isSameMonth($awal) ? now() : $awal)->format(Format::TANGGAL);

        $this->bukaModal();
    }

    #[On('mutu.kertas-kerja.openEdit')]
    public function openEdit(int $obsId): void
    {
        $row = DB::table('siaptxn_indikator_obs')->where('obs_id', $obsId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Baris kertas kerja tidak ditemukan.');
            return;
        }

        $this->indKode = $row->ind_kode;
        $this->unitId = $row->unit_id ?? IndikatorMutu::TINGKAT_RS;
        $this->bulan = Carbon::parse($row->obs_tanggal)->format(Format::BULAN);
        unset($this->indikator);
        if (!$this->bolehAksesUnit() || !$this->indikator) {
            $this->dispatch('toast', type: 'error', message: 'Anda tidak berhak membuka baris unit ini.');
            return;
        }

        $this->resetForm();
        $this->formMode = 'edit';
        $this->obsId = $obsId;
        $this->readOnly = IndikatorMutu::terkunci($this->awalBulan());
        $isian = json_decode((string) $row->obs_isian, true) ?: [];
        foreach ($this->indikator['kolom'] as $kolom) {
            $this->form['isian'][$kolom['key']] = (string) ($isian[$kolom['key']] ?? $this->form['isian'][$kolom['key']]);
        }
        $this->form['tanggal'] = Format::tanggal($row->obs_tanggal);
        $this->form['eksklusi'] = $row->obs_eksklusi === '1' ? '1' : '0';
        $this->form['keterangan'] = (string) $row->keterangan;

        $this->bukaModal();
    }

    private function bukaModal(): void
    {
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'mutu-kertas-kerja-actions');
        $this->dispatch('focus-obs-tanggal');
    }

    // ─── Guard ────────────────────────────────────────────────────────────────
    private function awalBulan(): Carbon
    {
        return Carbon::createFromFormat('!' . Format::BULAN, $this->bulan)->startOfMonth();
    }

    private function bolehAksesUnit(): bool
    {
        $cakupan = IndikatorMutu::cakupanUser(auth()->user());
        if ($cakupan['semuaUnit']) {
            return true;
        }

        return $this->unitId !== IndikatorMutu::TINGKAT_RS && $this->unitId === (string) $cakupan['unitId'];
    }

    /** Boleh menambah / mengubah / menghapus — toast alasannya bila tidak. */
    private function bolehIsi(): bool
    {
        $pesan = match (true) {
            !$this->indikator || !isset(IndikatorMutu::untukUnit($this->unitId)[$this->indKode]) => 'Indikator ini bukan milik unit tersebut.',
            !$this->bolehAksesUnit() => 'Anda hanya boleh mengisi kertas kerja unit Anda sendiri.',
            IndikatorMutu::terkunci($this->awalBulan()) => "Bulan {$this->bulan} sudah dikunci Komite Mutu.",
            default => null,
        };
        if ($pesan) {
            $this->dispatch('toast', type: 'error', message: $pesan);
        }

        return $pesan === null;
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        $rules = [
            'form.tanggal'    => 'required|date_format:' . Format::TANGGAL,
            'form.eksklusi'   => 'required|in:0,1',
            'form.keterangan' => 'nullable|required_if:form.eksklusi,1|string|max:300',
        ];
        foreach ($this->indikator['kolom'] ?? [] as $kolom) {
            $rules['form.isian.' . $kolom['key']] = match ($kolom['tipe']) {
                'teks'    => [($kolom['wajib'] ?? false) ? 'required' : 'nullable', 'string', 'max:150'],
                'jam'     => ['required', 'date_format:H:i'],
                'waktu'   => ['required', 'date_format:' . IndikatorMutu::FORMAT_WAKTU],
                'angka'   => ['required', 'integer', 'min:0', 'max:999999'],
                'ceklis'  => ['required', 'in:0,1'],
                'pilihan' => ['required', 'in:' . implode(',', array_keys($kolom['opsi']))],
                'nilai'   => ['required', 'in:1,2,3,4'],
            };
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'required'    => ':attribute wajib diisi.',
            'required_if' => ':attribute wajib diisi bila baris dieksklusi (tulis alasannya).',
            'date_format' => ':attribute tidak valid (format :format).',
            'integer'     => ':attribute harus angka bulat.',
            'min'         => ':attribute minimal :min.',
            'max'         => ':attribute terlalu besar / panjang.',
            'in'          => ':attribute belum dipilih.',
        ];
    }

    protected function validationAttributes(): array
    {
        $atribut = ['form.tanggal' => 'Tanggal', 'form.keterangan' => 'Alasan eksklusi'];
        foreach ($this->indikator['kolom'] ?? [] as $kolom) {
            $atribut['form.isian.' . $kolom['key']] = $kolom['label'];
        }

        return $atribut;
    }

    /** Pemeriksaan antar-field yang tidak tertangkap rules() — galat dipasang di field terkait. */
    private function periksaLogika(): bool
    {
        $tanggal = Carbon::createFromFormat('!' . Format::TANGGAL, $this->form['tanggal']);
        if (!$tanggal->isSameMonth($this->awalBulan())) {
            $this->addError('form.tanggal', "Tanggal harus di bulan {$this->bulan}.");
        } elseif ($tanggal->isAfter(today())) {
            $this->addError('form.tanggal', 'Tanggal tidak boleh di masa depan.');
        }

        $aturan = $this->indikator['aturan'];
        $isian = $this->form['isian'];
        $label = fn($key) => collect($this->indikator['kolom'])->firstWhere('key', $key)['label'];
        if (in_array($aturan['jenis'], ['jumlah', 'jumlah_ambang'], true)
            && (int) $isian[$aturan['pembilang']] > (int) $isian[$aturan['penyebut']]) {
            $this->addError('form.isian.' . $aturan['pembilang'], $label($aturan['pembilang']) . ' tidak boleh melebihi ' . mb_strtolower($label($aturan['penyebut'])) . '.');
        }
        if ($aturan['jenis'] === 'jumlah_selisih' && (int) $isian[$aturan['kurang']] > (int) $isian[$aturan['penyebut']]) {
            $this->addError('form.isian.' . $aturan['kurang'], $label($aturan['kurang']) . ' tidak boleh melebihi ' . mb_strtolower($label($aturan['penyebut'])) . '.');
        }
        if ($aturan['jenis'] === 'durasi_grading') {
            $mulai = Carbon::createFromFormat('!' . IndikatorMutu::FORMAT_WAKTU, $isian[$aturan['mulai']]);
            $selesai = Carbon::createFromFormat('!' . IndikatorMutu::FORMAT_WAKTU, $isian[$aturan['selesai']]);
            if ($selesai->lt($mulai)) {
                $this->addError('form.isian.' . $aturan['selesai'], 'Waktu ditanggapi tidak boleh sebelum waktu diterima.');
            }
        }

        return $this->getErrorBag()->isEmpty();
    }

    /** Hasil baris saat form masih diisi — hanya bila semua field yang dihitung sudah valid. */
    #[Computed]
    public function pratinjau(): ?array
    {
        if (!$this->indikator) {
            return null;
        }
        $validator = Validator::make(['form' => $this->form], $this->rules());

        return $validator->fails() ? null : IndikatorMutu::hitungBaris($this->indikator, $this->form['isian']);
    }

    // ─── Simpan ───────────────────────────────────────────────────────────────
    public function save(): void
    {
        if ($this->readOnly) {
            return;
        }
        $this->validate();
        if (!$this->bolehIsi() || !$this->periksaLogika()) {
            return;
        }

        $isian = [];
        foreach ($this->indikator['kolom'] as $kolom) {
            $isian[$kolom['key']] = trim((string) ($this->form['isian'][$kolom['key']] ?? ''));
        }
        $hasil = IndikatorMutu::hitungBaris($this->indikator, $isian);
        $payload = [
            'obs_tanggal'   => Absensi::tanggalSql(Carbon::createFromFormat('!' . Format::TANGGAL, $this->form['tanggal'])),
            'obs_isian'     => json_encode($isian, JSON_UNESCAPED_UNICODE),
            'obs_pembilang' => $hasil['pembilang'],
            'obs_penyebut'  => $hasil['penyebut'],
            'obs_eksklusi'  => $this->form['eksklusi'],
            'keterangan'    => $this->form['eksklusi'] === '1' ? trim($this->form['keterangan']) : null,
            'petugas_id'    => auth()->id(),
            'updated_at'    => now(),
        ];

        if ($this->formMode === 'create') {
            DB::table('siaptxn_indikator_obs')->insert([
                ...$payload,
                'ind_kode'   => $this->indKode,
                'unit_id'    => $this->unitId === IndikatorMutu::TINGKAT_RS ? null : $this->unitId,
                'created_at' => now(),
            ]);
            $this->dispatch('toast', type: 'success', message: 'Baris tersimpan — ' . $hasil['label'] . '.');
            // Tambah berurutan: form dikosongkan, tanggal dipertahankan supaya entri berikutnya cepat.
            $tanggal = $this->form['tanggal'];
            $this->resetForm();
            $this->form['tanggal'] = $tanggal;
            $this->incrementVersion('modal');
            $this->dispatch('focus-obs-tanggal');
            $this->dispatch('mutu.kertas-kerja.saved');

            return;
        }

        DB::table('siaptxn_indikator_obs')->where('obs_id', $this->obsId)->update($payload);
        $this->dispatch('toast', type: 'success', message: 'Baris kertas kerja diperbarui.');
        $this->closeModal();
        $this->dispatch('mutu.kertas-kerja.saved');
    }

    public function hapus(): void
    {
        if ($this->readOnly || $this->formMode !== 'edit' || !$this->bolehIsi()) {
            return;
        }
        $jumlah = DB::table('siaptxn_indikator_obs')->where('obs_id', $this->obsId)->delete();
        $this->dispatch('toast', type: $jumlah ? 'success' : 'error', message: $jumlah ? 'Baris kertas kerja dihapus.' : 'Baris tidak ditemukan.');
        $this->closeModal();
        $this->dispatch('mutu.kertas-kerja.saved');
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'mutu-kertas-kerja-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $isian = [];
        foreach ($this->indikator['kolom'] ?? [] as $kolom) {
            $isian[$kolom['key']] = $kolom['tipe'] === 'ceklis' ? '0' : '';
        }
        $this->form = ['tanggal' => '', 'isian' => $isian, 'eksklusi' => '0', 'keterangan' => ''];
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="mutu-kertas-kerja-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="mutu-kertas-kerja-actions"
            event="mutu.kertas-kerja.saved"
            label="Baris kertas kerja"
            :wireKey="$this->renderKey('modal', [$formMode, $obsId, $indKode])">

            @php $ind = $this->indikator; @endphp

            <x-modul-dokumen.header :judul="($formMode === 'edit' ? 'Ubah Pengamatan' : 'Tambah Pengamatan') . ($ind ? ' — ' . $ind['kode'] : '')"
                ikon="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"
                tutupAlpine="tryClose()" :readOnly="$readOnly">
                {{ $ind['judul'] ?? '' }}
                <x-slot:badge>
                    <x-badge class="shrink-0 whitespace-nowrap" :variant="$formMode === 'edit' ? 'warning' : 'success'">{{ $formMode === 'edit' ? 'Mode: Ubah' : 'Mode: Tambah' }}</x-badge>
                </x-slot:badge>
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain
                 x-data
                 x-on:focus-obs-tanggal.window="$nextTick(() => setTimeout(() => $refs.inputTanggal?.focus(), 150))">
                @if ($ind)
                    <div class="grid max-w-5xl grid-cols-1 gap-4 lg:grid-cols-3">
                        <div class="space-y-4 lg:col-span-2">
                            <x-border-form title="Pengamatan ({{ $ind['pengamatan'] }})">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="obs-tanggal" value="Tanggal *" />
                                        <x-text-input id="obs-tanggal" wire:model.live.debounce.400ms="form.tanggal" x-ref="inputTanggal"
                                            x-mask="99/99/9999" placeholder="dd/mm/yyyy" :disabled="$readOnly"
                                            :error="$errors->has('form.tanggal')" class="w-full mt-1 font-mono" />
                                        <x-input-error :messages="$errors->get('form.tanggal')" class="mt-1" />
                                    </div>

                                    @foreach ($ind['kolom'] as $kolom)
                                        @php
                                            $field = 'form.isian.' . $kolom['key'];
                                            $idField = 'obs-' . $kolom['key'];
                                            $wajib = $kolom['tipe'] !== 'teks' || ($kolom['wajib'] ?? false);
                                        @endphp
                                        @if ($kolom['tipe'] === 'ceklis')
                                            <div class="flex items-end sm:col-span-2" wire:key="kolom-{{ $kolom['key'] }}">
                                                <x-toggle wire:model.live="{{ $field }}" trueValue="1" falseValue="0" :disabled="$readOnly">
                                                    {{ $kolom['label'] }}
                                                </x-toggle>
                                            </div>
                                        @else
                                            <div wire:key="kolom-{{ $kolom['key'] }}" @class(['sm:col-span-2' => $kolom['tipe'] === 'teks' && ($kolom['wajib'] ?? false) && in_array($kolom['key'], ['isi', 'masalah', 'pemeriksaan', 'tindakan'], true)])>
                                                <x-input-label :for="$idField" :value="$kolom['label'] . ($wajib ? ' *' : '')" />
                                                @switch($kolom['tipe'])
                                                    @case('pilihan')
                                                    @case('nilai')
                                                        <x-select-input :id="$idField" wire:model.live="{{ $field }}" :disabled="$readOnly"
                                                            :error="$errors->has($field)" class="w-full mt-1">
                                                            <option value="">— pilih —</option>
                                                            @foreach ($kolom['tipe'] === 'nilai' ? ['1' => '1 — Tidak baik', '2' => '2 — Kurang baik', '3' => '3 — Baik', '4' => '4 — Sangat baik'] : $kolom['opsi'] as $nilaiOpsi => $labelOpsi)
                                                                <option value="{{ $nilaiOpsi }}">{{ $labelOpsi }}</option>
                                                            @endforeach
                                                        </x-select-input>
                                                    @break
                                                    @case('jam')
                                                        <x-text-input :id="$idField" wire:model.live.debounce.400ms="{{ $field }}" x-mask="99:99" placeholder="HH:MM"
                                                            :disabled="$readOnly" :error="$errors->has($field)" class="w-full mt-1 font-mono" />
                                                    @break
                                                    @case('waktu')
                                                        <x-text-input :id="$idField" wire:model.live.debounce.400ms="{{ $field }}" x-mask="99/99/9999 99:99" placeholder="dd/mm/yyyy hh:mi"
                                                            :disabled="$readOnly" :error="$errors->has($field)" class="w-full mt-1 font-mono" />
                                                    @break
                                                    @case('angka')
                                                        <x-text-input :id="$idField" wire:model.live.debounce.400ms="{{ $field }}" inputmode="numeric" x-mask="999999"
                                                            :disabled="$readOnly" :error="$errors->has($field)" class="w-full mt-1 font-mono" />
                                                    @break
                                                    @default
                                                        <x-text-input :id="$idField" wire:model.live.debounce.400ms="{{ $field }}" maxlength="150"
                                                            :disabled="$readOnly" :error="$errors->has($field)" class="w-full mt-1" />
                                                @endswitch
                                                <x-input-error :messages="$errors->get($field)" class="mt-1" />
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </x-border-form>

                            <x-border-form title="Eksklusi">
                                <div class="space-y-3">
                                    <x-toggle wire:model.live="form.eksklusi" trueValue="1" falseValue="0" :disabled="$readOnly">
                                        Memenuhi kriteria eksklusi (tetap tercatat, tidak dihitung)
                                    </x-toggle>
                                    @if ($form['eksklusi'] === '1')
                                        <div>
                                            <x-input-label for="obs-keterangan" value="Alasan eksklusi *" />
                                            <x-text-input id="obs-keterangan" wire:model.live.debounce.400ms="form.keterangan" maxlength="300"
                                                :disabled="$readOnly" :error="$errors->has('form.keterangan')" class="w-full mt-1" />
                                            <x-input-error :messages="$errors->get('form.keterangan')" class="mt-1" />
                                        </div>
                                    @endif
                                </div>
                            </x-border-form>
                        </div>

                        {{-- PETUNJUK + HASIL BARIS --}}
                        <div class="space-y-4">
                            <div class="p-4 text-sm border rounded-xl bg-canvas border-hairline text-body dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300">
                                <div class="mb-1 text-xs font-semibold tracking-wide uppercase text-muted dark:text-gray-400">Petunjuk</div>
                                {{ $ind['petunjuk'] }}
                            </div>
                            <div class="p-4 border rounded-xl bg-canvas border-hairline dark:bg-gray-900 dark:border-gray-700" role="status" aria-live="polite">
                                <div class="mb-1 text-xs font-semibold tracking-wide uppercase text-muted dark:text-gray-400">Hasil baris ini</div>
                                @php $hasil = $this->pratinjau; @endphp
                                @if ($form['eksklusi'] === '1')
                                    <x-badge variant="gray">Eksklusi — tidak dihitung</x-badge>
                                @elseif ($hasil)
                                    <x-badge :variant="$hasil['baik'] === null ? 'info' : ($hasil['baik'] ? 'success' : 'danger')">{{ $hasil['label'] }}</x-badge>
                                    @if ($hasil['catatan'] !== '')
                                        <span class="ml-1 text-sm tabular-nums text-body dark:text-gray-300">{{ $hasil['catatan'] }}</span>
                                    @endif
                                    <div class="mt-1 text-xs tabular-nums text-muted dark:text-gray-400">
                                        Pembilang {{ \App\Support\IndikatorMutu::angka($hasil['pembilang'], 0) }} · Penyebut {{ \App\Support\IndikatorMutu::angka($hasil['penyebut'], 0) }}
                                    </div>
                                @else
                                    <span class="text-sm text-muted dark:text-gray-400">Lengkapi isian untuk melihat hasil.</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        @if ($formMode === 'edit' && !$readOnly)
                            <x-confirm-button variant="danger-soft" action="hapus()" title="Hapus Baris"
                                message="Hapus baris pengamatan ini? Capaian bulan ini akan dihitung ulang tanpa baris ini."
                                confirmText="Ya, hapus" cancelText="Batal" data-dialog-anak>Hapus</x-confirm-button>
                        @elseif ($formMode === 'create')
                            <span class="text-xs text-muted dark:text-gray-400">Setelah simpan, form kosong lagi untuk pengamatan berikutnya.</span>
                        @endif
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" x-on:click="tryClose()">{{ $readOnly ? 'Tutup' : 'Batal' }}</x-secondary-button>
                        @unless ($readOnly)
                            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save">Simpan</span>
                                <span wire:loading wire:target="save">Menyimpan...</span>
                            </x-primary-button>
                        @endunless
                    </div>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>
