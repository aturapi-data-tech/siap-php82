<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/** Modal CRUD `siapmst_unit_kerjas`. */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode       = 'create'; // create|edit
    public string $originalUnitId = '';
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    // ─── Open Create ──────────────────────────────────────────────────────────
    #[On('master.unit-kerja.openCreate')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->formMode       = 'create';
        $this->originalUnitId = '';
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-unit-kerja-actions');
        $this->dispatch('focus-unit-id');
    }

    // ─── Open Edit ────────────────────────────────────────────────────────────
    #[On('master.unit-kerja.openEdit')]
    public function openEdit(string $unitId): void
    {
        $row = DB::table('siapmst_unit_kerjas')->where('unit_id', $unitId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data unit tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode       = 'edit';
        $this->originalUnitId = $unitId;
        $this->form = [
            'unit_id'       => (string) $row->unit_id,
            'unit_name'     => (string) $row->unit_name,
            'active_status' => (string) $row->active_status === '1' ? '1' : '0',
        ];

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-unit-kerja-actions');
        $this->dispatch('focus-unit-name');
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    #[On('master.unit-kerja.requestDelete')]
    public function deleteUnit(string $unitId): void
    {
        try {
            $deleted = DB::table('siapmst_unit_kerjas')->where('unit_id', $unitId)->delete();
            if ($deleted === 0) {
                $this->dispatch('toast', type: 'error', message: 'Data unit tidak ditemukan.');
                return;
            }

            $this->dispatch('toast', type: 'success', message: 'Unit berhasil dihapus.');
            $this->dispatch('master.unit-kerja.saved');
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'ORA-02292')) {
                $this->dispatch('toast', type: 'error', message: 'Unit tidak bisa dihapus karena sudah dipakai karyawan / shift. Non-aktifkan saja.');
                return;
            }
            throw $exception;
        }
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        return [
            'form.unit_id' => $this->formMode === 'create'
                ? ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\-]+$/', 'unique:siapmst_unit_kerjas,unit_id']
                : ['required', 'string'],
            'form.unit_name'     => 'required|string|max:100',
            'form.active_status' => 'required|in:0,1',
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'max'      => ':attribute maksimal :max.',
            'unique'   => ':attribute sudah digunakan.',
            'in'       => ':attribute tidak valid.',
            'form.unit_id.regex' => ':attribute hanya boleh huruf, angka, strip, atau garis bawah.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.unit_id'       => 'Kode Unit',
            'form.unit_name'     => 'Nama Unit',
            'form.active_status' => 'Status',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        $payload = [
            'unit_name'     => trim($this->form['unit_name']),
            'active_status' => $this->form['active_status'],
            'updated_at'    => now(),
        ];

        if ($this->formMode === 'create') {
            DB::table('siapmst_unit_kerjas')->insert([
                'unit_id'    => mb_strtoupper(trim($this->form['unit_id'])),
                'created_at' => now(),
                ...$payload,
            ]);
        } else {
            DB::table('siapmst_unit_kerjas')->where('unit_id', $this->originalUnitId)->update($payload);
        }

        $this->dispatch('toast', type: 'success', message: 'Data unit berhasil disimpan.');
        $this->closeModal();
        $this->dispatch('master.unit-kerja.saved');
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'master-unit-kerja-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'unit_id'       => '',
            'unit_name'     => '',
            'active_status' => '1',
        ];
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="master-unit-kerja-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="master-unit-kerja-actions"
            event="master.unit-kerja.saved"
            label="Unit"
            :wireKey="$this->renderKey('modal', [$formMode, $originalUnitId])">

            {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
            <x-modul-dokumen.header :judul="($formMode === 'edit' ? 'Ubah Unit Kerja' : 'Tambah Unit Kerja')"
                ikon="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" tutupAlpine="tryClose()">
                Mis. Gizi, Rawat Inap, IGD. Kepala ruang hanya bisa mengatur jadwal unitnya sendiri.
                <x-slot:badge><x-badge class="shrink-0 whitespace-nowrap" :variant="$formMode === 'edit' ? 'warning' : 'success'"> {{ $formMode === 'edit' ? 'Mode: Edit' : 'Mode: Tambah' }} </x-badge></x-slot:badge>
            </x-modul-dokumen.header>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain
                 x-data
                 x-on:focus-unit-id.window="$nextTick(() => setTimeout(() => $refs.inputUnitId?.focus(), 150))"
                 x-on:focus-unit-name.window="$nextTick(() => setTimeout(() => $refs.inputUnitName?.focus(), 150))">

                <div class="max-w-3xl">
                    <x-border-form title="Data Unit">
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <x-input-label value="Kode Unit *" />
                                    <x-text-input wire:model.live="form.unit_id" x-ref="inputUnitId"
                                        maxlength="10" placeholder="Mis. GIZI"
                                        :disabled="$formMode === 'edit'"
                                        :error="$errors->has('form.unit_id')" class="w-full mt-1 uppercase"
                                        x-on:keydown.enter.prevent="$refs.inputUnitName?.focus()" />
                                    <x-input-error :messages="$errors->get('form.unit_id')" class="mt-1" />
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label value="Nama Unit *" />
                                    <x-text-input wire:model.live="form.unit_name" x-ref="inputUnitName"
                                        maxlength="100" placeholder="Mis. Instalasi Gizi"
                                        :error="$errors->has('form.unit_name')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$wire.save()" />
                                    <x-input-error :messages="$errors->get('form.unit_name')" class="mt-1" />
                                </div>
                            </div>

                            <x-toggle wire:model.live="form.active_status" trueValue="1" falseValue="0">
                                Status Aktif
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
                        <span class="mx-0.5">di field terakhir untuk simpan</span>
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                        <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                            <span wire:loading.remove>Simpan</span>
                            <span wire:loading>Menyimpan...</span>
                        </x-primary-button>
                    </div>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>
