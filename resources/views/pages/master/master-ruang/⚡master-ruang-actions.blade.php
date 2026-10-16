<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode        = 'create'; // create|edit
    public string $originalRuangId = '';
    public array  $renderVersions  = [];
    protected array $renderAreas   = ['modal'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    #[On('master.ruang.openCreate')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->formMode        = 'create';
        $this->originalRuangId = '';
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-ruang-actions');
        $this->dispatch('focus-ruang-id');
    }

    #[On('master.ruang.openEdit')]
    public function openEdit(string $ruangId): void
    {
        $row = DB::table('siapmst_ruangs')->where('ruang_id', $ruangId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data ruang tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode        = 'edit';
        $this->originalRuangId = $ruangId;
        $this->form = [
            'ruang_id'      => (string) $row->ruang_id,
            'ruang_name'    => (string) $row->ruang_name,
            'active_status' => (string) $row->active_status === '1' ? '1' : '0',
        ];

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-ruang-actions');
        $this->dispatch('focus-ruang-name');
    }

    #[On('master.ruang.requestDelete')]
    public function deleteRuang(string $ruangId): void
    {
        try {
            $deleted = DB::table('siapmst_ruangs')->where('ruang_id', $ruangId)->delete();
            if ($deleted === 0) {
                $this->dispatch('toast', type: 'error', message: 'Data ruang tidak ditemukan.');
                return;
            }

            $this->dispatch('toast', type: 'success', message: 'Ruang berhasil dihapus.');
            $this->dispatch('master.ruang.saved');
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'ORA-02292')) {
                $this->dispatch('toast', type: 'error', message: 'Ruang tidak bisa dihapus karena masih dipakai di inventaris alat. Non-aktifkan saja.');
                return;
            }
            throw $e;
        }
    }

    public function save(): void
    {
        $this->validate(
            [
                'form.ruang_id' => $this->formMode === 'create'
                    ? ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\-]+$/', 'unique:siapmst_ruangs,ruang_id']
                    : ['required', 'string'],
                'form.ruang_name'    => 'required|string|max:100',
                'form.active_status' => 'required|in:0,1',
            ],
            [
                'required' => ':attribute wajib diisi.',
                'max'      => ':attribute maksimal :max karakter.',
                'unique'   => ':attribute sudah digunakan.',
                'in'       => ':attribute tidak valid.',
                'form.ruang_id.regex' => ':attribute hanya boleh huruf, angka, strip, atau garis bawah.',
            ],
            [
                'form.ruang_id'      => 'Kode Ruang',
                'form.ruang_name'    => 'Nama Ruang',
                'form.active_status' => 'Status',
            ],
        );

        $payload = [
            'ruang_name'    => mb_strtoupper(trim($this->form['ruang_name'])),
            'active_status' => $this->form['active_status'],
            'updated_at'    => now(),
        ];

        if ($this->formMode === 'create') {
            DB::table('siapmst_ruangs')->insert([
                'ruang_id'   => mb_strtoupper(trim($this->form['ruang_id'])),
                'created_at' => now(),
                ...$payload,
            ]);
        } else {
            DB::table('siapmst_ruangs')->where('ruang_id', $this->originalRuangId)->update($payload);
        }

        $this->dispatch('toast', type: 'success', message: 'Data ruang berhasil disimpan.');
        $this->closeModal();
        $this->dispatch('master.ruang.saved');
    }

    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'master-ruang-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = ['ruang_id' => '', 'ruang_name' => '', 'active_status' => '1'];
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="master-ruang-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="master-ruang-actions"
            event="master.ruang.saved"
            label="Ruang"
            :wireKey="$this->renderKey('modal', [$formMode, $originalRuangId])">

            {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
            <x-modul-dokumen.header :judul="($formMode === 'edit' ? 'Ubah Data Ruang' : 'Tambah Data Ruang')"
                ikon="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" tutupAlpine="tryClose()">
                Kode ruang singkat (mis. IGD, APT) dipakai sebagai awalan kode alat.
                <x-slot:badge><x-badge class="shrink-0 whitespace-nowrap" :variant="$formMode === 'edit' ? 'warning' : 'success'"> {{ $formMode === 'edit' ? 'Mode: Edit' : 'Mode: Tambah' }} </x-badge></x-slot:badge>
            </x-modul-dokumen.header>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain
                 x-data
                 x-on:focus-ruang-id.window="$nextTick(() => setTimeout(() => $refs.inputRuangId?.focus(), 150))"
                 x-on:focus-ruang-name.window="$nextTick(() => setTimeout(() => $refs.inputRuangName?.focus(), 150))">
                <div class="max-w-3xl">
                    <x-border-form title="Data Ruang">
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <x-input-label value="Kode Ruang *" />
                                    <x-text-input wire:model.live="form.ruang_id" x-ref="inputRuangId"
                                        maxlength="10" placeholder="Mis. IGD"
                                        :disabled="$formMode === 'edit'"
                                        :error="$errors->has('form.ruang_id')" class="w-full mt-1 uppercase"
                                        x-on:keydown.enter.prevent="$refs.inputRuangName?.focus()" />
                                    <x-input-error :messages="$errors->get('form.ruang_id')" class="mt-1" />
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label value="Nama Ruang *" />
                                    <x-text-input wire:model.live="form.ruang_name" x-ref="inputRuangName"
                                        maxlength="100"
                                        :error="$errors->has('form.ruang_name')" class="w-full mt-1 uppercase"
                                        x-on:keydown.enter.prevent="$wire.save()" />
                                    <x-input-error :messages="$errors->get('form.ruang_name')" class="mt-1" />
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
