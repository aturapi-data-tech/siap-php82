<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Support\Kalibrasi;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal CRUD `siapmst_assets` (inventaris alat). Key $form = nama kolom DB.
 * Hapus ditolak bila alat sudah punya riwayat kalibrasi / pemeliharaan / perbaikan (bukti akreditasi).
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode        = 'create'; // create|edit
    public string $originalAssetId = '';
    public array  $renderVersions  = [];
    protected array $renderAreas   = ['modal'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    #[Computed]
    public function ruangs()
    {
        return DB::table('siapmst_ruangs')->where('active_status', '1')->orderBy('ruang_id')->get(['ruang_id', 'ruang_name']);
    }

    // ─── Open ─────────────────────────────────────────────────────────────────
    #[On('master.alat.openCreate')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->formMode        = 'create';
        $this->originalAssetId = '';
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-alat-actions');
        $this->dispatch('focus-alat-id');
    }

    #[On('master.alat.openEdit')]
    public function openEdit(string $assetId): void
    {
        $row = DB::table('siapmst_assets')->where('asset_id', $assetId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data alat tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode        = 'edit';
        $this->originalAssetId = $assetId;
        $this->form = [
            'asset_id'           => (string) $row->asset_id,
            'asset_name'         => (string) $row->asset_name,
            'asset_jenis'        => (string) $row->asset_jenis,
            'merk'               => (string) ($row->merk ?? ''),
            'tipe_model'         => (string) ($row->tipe_model ?? ''),
            'no_seri'            => (string) ($row->no_seri ?? ''),
            'ruang_id'           => (string) $row->ruang_id,
            'tahun_pengadaan'    => $row->tahun_pengadaan ? (string) (int) $row->tahun_pengadaan : '',
            'jumlah'             => (string) (int) $row->jumlah,
            'kondisi'            => (string) $row->kondisi,
            'wajib_kalibrasi'    => (string) $row->wajib_kalibrasi === '1' ? '1' : '0',
            'interval_kalibrasi' => (string) (int) $row->interval_kalibrasi,
            'pm_frekuensi'       => (string) ($row->pm_frekuensi ?? 'M'),
            'keterangan'         => (string) ($row->keterangan ?? ''),
            'active_status'      => (string) $row->active_status === '1' ? '1' : '0',
        ];

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-alat-actions');
        $this->dispatch('focus-alat-name');
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    #[On('master.alat.requestDelete')]
    public function deleteAlat(string $assetId): void
    {
        // Riwayat = bukti akreditasi → alat tidak boleh dihapus.
        $riwayat = collect([
            'kalibrasi'    => 'siaptxn_kalibrasis',
            'pemeliharaan' => 'siaptxn_pemeliharaans',
            'perbaikan'    => 'siaptxn_perbaikans',
        ])->filter(fn ($tabel) => DB::table($tabel)->where('asset_id', $assetId)->exists())->keys();
        if ($riwayat->isNotEmpty()) {
            $this->dispatch('toast', type: 'error', message: 'Alat tidak bisa dihapus karena sudah punya riwayat '
                . $riwayat->implode(', ') . ' — riwayat itu bukti akreditasi, jadi data alat harus tetap ada.');
            return;
        }

        try {
            $deleted = DB::table('siapmst_assets')->where('asset_id', $assetId)->delete();
            if ($deleted === 0) {
                $this->dispatch('toast', type: 'error', message: 'Data alat tidak ditemukan.');
                return;
            }

            $this->dispatch('toast', type: 'success', message: 'Data alat berhasil dihapus.');
            $this->dispatch('master.alat.saved');
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'ORA-02292')) {
                $this->dispatch('toast', type: 'error', message: 'Alat tidak bisa dihapus karena sudah punya riwayat — riwayat itu bukti akreditasi, jadi data alat harus tetap ada.');
                return;
            }
            throw $e;
        }
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        return [
            'form.asset_id' => $this->formMode === 'create'
                ? ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9.\/\-_]+$/', 'unique:siapmst_assets,asset_id']
                : ['required', 'string'],
            'form.asset_name'         => 'required|string|max:100',
            'form.asset_jenis'        => 'required|in:M,N',
            'form.merk'               => 'nullable|string|max:60',
            'form.tipe_model'         => 'nullable|string|max:60',
            'form.no_seri'            => 'nullable|string|max:60',
            'form.ruang_id'           => 'required|exists:siapmst_ruangs,ruang_id',
            'form.tahun_pengadaan'    => 'nullable|integer|min:1980|max:' . (now()->year + 1),
            'form.jumlah'             => 'required|integer|min:1|max:9999',
            'form.kondisi'            => 'required|in:B,RR,RB',
            'form.wajib_kalibrasi'    => 'required|in:0,1',
            'form.interval_kalibrasi' => 'required|integer|min:1|max:60',
            'form.pm_frekuensi'       => 'required|in:H,M,-',
            'form.keterangan'         => 'nullable|string|max:200',
            'form.active_status'      => 'required|in:0,1',
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'max'      => ':attribute maksimal :max.',
            'min'      => ':attribute minimal :min.',
            'integer'  => ':attribute harus angka.',
            'unique'   => ':attribute sudah digunakan.',
            'exists'   => ':attribute tidak ada di master.',
            'in'       => ':attribute tidak valid.',
            'form.asset_id.regex' => ':attribute hanya boleh huruf, angka, titik, garis miring, strip.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.asset_id'           => 'Kode Alat',
            'form.asset_name'         => 'Nama Alat',
            'form.asset_jenis'        => 'Jenis',
            'form.merk'               => 'Merk',
            'form.tipe_model'         => 'Type/Model',
            'form.no_seri'            => 'No. Seri',
            'form.ruang_id'           => 'Ruang',
            'form.tahun_pengadaan'    => 'Tahun Pengadaan',
            'form.jumlah'             => 'Jumlah',
            'form.kondisi'            => 'Kondisi',
            'form.interval_kalibrasi' => 'Interval Kalibrasi',
            'form.pm_frekuensi'       => 'Pemeliharaan Rutin',
            'form.keterangan'         => 'Keterangan',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        $f = $this->form;
        $payload = [
            'asset_name'         => mb_strtoupper(trim($f['asset_name'])),
            'asset_jenis'        => $f['asset_jenis'],
            'merk'               => $f['merk'] !== '' ? mb_strtoupper(trim($f['merk'])) : null,
            'tipe_model'         => $f['tipe_model'] !== '' ? trim($f['tipe_model']) : null,
            'no_seri'            => $f['no_seri'] !== '' ? trim($f['no_seri']) : null,
            'ruang_id'           => $f['ruang_id'],
            'tahun_pengadaan'    => $f['tahun_pengadaan'] !== '' ? (int) $f['tahun_pengadaan'] : null,
            'jumlah'             => (int) $f['jumlah'],
            'kondisi'            => $f['kondisi'],
            'wajib_kalibrasi'    => $f['wajib_kalibrasi'],
            'interval_kalibrasi' => (int) $f['interval_kalibrasi'],
            'pm_frekuensi'       => $f['pm_frekuensi'],
            'keterangan'         => $f['keterangan'] !== '' ? trim($f['keterangan']) : null,
            'active_status'      => $f['active_status'],
            'updated_at'         => now(),
        ];

        if ($this->formMode === 'create') {
            DB::table('siapmst_assets')->insert([
                'asset_id'   => mb_strtoupper(trim($f['asset_id'])),
                'created_at' => now(),
                ...$payload,
            ]);
        } else {
            DB::table('siapmst_assets')->where('asset_id', $this->originalAssetId)->update($payload);
        }

        $this->dispatch('toast', type: 'success', message: 'Data alat berhasil disimpan.');
        $this->closeModal();
        $this->dispatch('master.alat.saved');
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'master-alat-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'asset_id'           => '',
            'asset_name'         => '',
            'asset_jenis'        => 'M',
            'merk'               => '',
            'tipe_model'         => '',
            'no_seri'            => '',
            'ruang_id'           => '',
            'tahun_pengadaan'    => (string) now()->year,
            'jumlah'             => '1',
            'kondisi'            => 'B',
            'wajib_kalibrasi'    => '1',
            'interval_kalibrasi' => (string) Kalibrasi::INTERVAL_DEFAULT,
            'pm_frekuensi'       => 'M',
            'keterangan'         => '',
            'active_status'      => '1',
        ];
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="master-alat-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="master-alat-actions"
            event="master.alat.saved"
            label="Alat"
            :wireKey="$this->renderKey('modal', [$formMode, $originalAssetId])">

            {{-- HEADER --}}
            <div class="relative px-6 py-5 bg-surface-soft">
                <div class="relative flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3">
                            <div class="flex items-center justify-center w-10 h-10 rounded-xl bg-brand-green/10 dark:bg-brand-lime/15">
                                <img src="{{ asset('images/Logogram black solid.png') }}" alt="Logo" class="block w-6 h-6 dark:hidden" />
                                <img src="{{ asset('images/Logogram white solid.png') }}" alt="Logo" class="hidden w-6 h-6 dark:block" />
                            </div>
                            <div>
                                <h2 class="ds-display-sm dark:text-gray-100">
                                    {{ $formMode === 'edit' ? 'Ubah Data Alat' : 'Tambah Data Alat' }}
                                </h2>
                                <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                    Kolom mengikuti formulir Daftar Inventaris RS. Alat yang memiliki parameter ukur/keluaran wajib dikalibrasi (Permenkes 54/2015).
                                </p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <x-badge :variant="$formMode === 'edit' ? 'warning' : 'success'">
                                {{ $formMode === 'edit' ? 'Mode: Edit' : 'Mode: Tambah' }}
                            </x-badge>
                        </div>
                    </div>
                    <x-icon-button color="gray" type="button" x-on:click="tryClose()">
                        <span class="sr-only">Close</span>
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </x-icon-button>
                </div>
            </div>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain>
                <div class="grid max-w-5xl grid-cols-1 gap-4 lg:grid-cols-2"
                    x-data
                    x-on:focus-alat-id.window="$nextTick(() => setTimeout(() => $refs.inputAlatId?.focus(), 150))"
                    x-on:focus-alat-name.window="$nextTick(() => setTimeout(() => $refs.inputAlatName?.focus(), 150))">

                    <x-border-form title="Identitas Alat">
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label value="Kode Alat *" />
                                    <x-text-input wire:model.live="form.asset_id" x-ref="inputAlatId"
                                        maxlength="30" placeholder="Mis. IGD.SP.01/2025"
                                        :disabled="$formMode === 'edit'"
                                        :error="$errors->has('form.asset_id')" class="w-full mt-1 font-mono uppercase"
                                        x-on:keydown.enter.prevent="$refs.inputAlatName?.focus()" />
                                    <x-input-error :messages="$errors->get('form.asset_id')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Jenis *" />
                                    <x-select-input wire:model.live="form.asset_jenis" :error="$errors->has('form.asset_jenis')" class="mt-1">
                                        @foreach (\App\Support\Kalibrasi::JENIS as $kode => $nama)
                                            <option value="{{ $kode }}">{{ $nama }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                            </div>

                            <div>
                                <x-input-label value="Nama Alat *" />
                                <x-text-input wire:model.live="form.asset_name" x-ref="inputAlatName"
                                    maxlength="100"
                                    :error="$errors->has('form.asset_name')" class="w-full mt-1 uppercase"
                                    x-on:keydown.enter.prevent="$refs.inputMerk?.focus()" />
                                <x-input-error :messages="$errors->get('form.asset_name')" class="mt-1" />
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label value="Merk" />
                                    <x-text-input wire:model="form.merk" x-ref="inputMerk" maxlength="60"
                                        :error="$errors->has('form.merk')" class="w-full mt-1 uppercase"
                                        x-on:keydown.enter.prevent="$refs.inputTipe?.focus()" />
                                    <x-input-error :messages="$errors->get('form.merk')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Type / Model" />
                                    <x-text-input wire:model="form.tipe_model" x-ref="inputTipe" maxlength="60"
                                        :error="$errors->has('form.tipe_model')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$refs.inputSeri?.focus()" />
                                    <x-input-error :messages="$errors->get('form.tipe_model')" class="mt-1" />
                                </div>
                            </div>

                            <div>
                                <x-input-label value="No. Seri" />
                                <x-text-input wire:model="form.no_seri" x-ref="inputSeri" maxlength="60"
                                    :error="$errors->has('form.no_seri')" class="w-full mt-1 font-mono"
                                    x-on:keydown.enter.prevent="$refs.inputRuang?.focus()" />
                                <x-input-error :messages="$errors->get('form.no_seri')" class="mt-1" />
                            </div>
                        </div>
                    </x-border-form>

                    <x-border-form title="Lokasi, Kondisi, Kalibrasi & Pemeliharaan">
                        <div class="space-y-4">
                            <div>
                                <x-input-label value="Ruang *" />
                                <x-select-input wire:model.live="form.ruang_id" x-ref="inputRuang" :error="$errors->has('form.ruang_id')" class="mt-1">
                                    <option value="">— pilih —</option>
                                    @foreach ($this->ruangs as $ru)
                                        <option value="{{ $ru->ruang_id }}">{{ $ru->ruang_id }} — {{ $ru->ruang_name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('form.ruang_id')" class="mt-1" />
                            </div>

                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <x-input-label value="Tahun Pengadaan" />
                                    <x-text-input wire:model="form.tahun_pengadaan" x-mask="9999" placeholder="YYYY"
                                        :error="$errors->has('form.tahun_pengadaan')" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('form.tahun_pengadaan')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Jumlah *" />
                                    <x-text-input wire:model="form.jumlah" type="number" min="1"
                                        :error="$errors->has('form.jumlah')" class="w-full mt-1" />
                                    <x-input-error :messages="$errors->get('form.jumlah')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Kondisi *" />
                                    <x-select-input wire:model.live="form.kondisi" :error="$errors->has('form.kondisi')" class="mt-1">
                                        @foreach (\App\Support\Kalibrasi::KONDISI as $kode => $nama)
                                            <option value="{{ $kode }}">{{ $kode }} — {{ $nama }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                            </div>

                            <div class="p-3 border rounded-xl border-hairline dark:border-gray-700">
                                <x-toggle wire:model.live="form.wajib_kalibrasi" trueValue="1" falseValue="0">
                                    Wajib kalibrasi
                                </x-toggle>
                                @if ($form['wajib_kalibrasi'] === '1')
                                    <div class="flex items-center gap-2 mt-3">
                                        <x-input-label value="Interval kalibrasi ulang" class="!mb-0" />
                                        <x-text-input wire:model="form.interval_kalibrasi" type="number" min="1" max="60"
                                            :error="$errors->has('form.interval_kalibrasi')" class="w-20" />
                                        <span class="text-sm text-muted">bulan</span>
                                    </div>
                                    <x-input-error :messages="$errors->get('form.interval_kalibrasi')" class="mt-1" />
                                    <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Permenkes 54/2015: paling sedikit 1 kali dalam 1 tahun (12 bulan).</p>
                                @endif
                            </div>

                            <div>
                                <x-input-label value="Pemeliharaan rutin (checklist perawatan) *" />
                                <x-select-input wire:model.live="form.pm_frekuensi" :error="$errors->has('form.pm_frekuensi')" class="mt-1">
                                    @foreach (\App\Support\Pemeliharaan::FREKUENSI as $kode => $nama)
                                        <option value="{{ $kode }}">{{ $nama }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('form.pm_frekuensi')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label value="Keterangan" />
                                <x-text-input wire:model="form.keterangan" maxlength="200" class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$wire.save()" />
                                <x-input-error :messages="$errors->get('form.keterangan')" class="mt-1" />
                            </div>

                            <x-toggle wire:model.live="form.active_status" trueValue="1" falseValue="0">
                                Status Aktif (masih dipakai)
                            </x-toggle>
                        </div>
                    </x-border-form>
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs text-muted dark:text-gray-400">
                        <kbd class="px-1.5 py-0.5 text-xs font-semibold bg-surface-card border border-hairline rounded dark:bg-gray-800 dark:border-gray-600">Enter</kbd>
                        <span class="mx-0.5">untuk berpindah field</span>
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
