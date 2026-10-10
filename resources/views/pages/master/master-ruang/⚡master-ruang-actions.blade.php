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
                                    {{ $formMode === 'edit' ? 'Ubah Data Ruang' : 'Tambah Data Ruang' }}
                                </h2>
                                <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                    Kode ruang singkat (mis. IGD, APT) dipakai sebagai awalan kode alat.
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
