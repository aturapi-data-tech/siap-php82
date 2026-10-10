<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal CRUD `siapmst_shifts`. lintas_hari diturunkan otomatis:
 * jam pulang <= jam masuk berarti pulang esok hari (mis. 21:00–07:00).
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode        = 'create'; // create|edit
    public string $originalShiftId = '';
    public array  $renderVersions  = [];
    protected array $renderAreas   = ['modal'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    // ─── Open Create ──────────────────────────────────────────────────────────
    #[On('master.shift.openCreate')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->formMode        = 'create';
        $this->originalShiftId = '';
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-shift-actions');
        $this->dispatch('focus-shift-id');
    }

    // ─── Open Edit ────────────────────────────────────────────────────────────
    #[On('master.shift.openEdit')]
    public function openEdit(string $shiftId): void
    {
        $row = DB::table('siapmst_shifts')->where('shift_id', $shiftId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data shift tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode        = 'edit';
        $this->originalShiftId = $shiftId;
        $this->form = [
            'shift_id'        => (string) $row->shift_id,
            'shift_name'      => (string) $row->shift_name,
            'shift_kode'      => (string) ($row->shift_kode ?? ''),
            'jam_masuk'       => (string) $row->jam_masuk,
            'jam_pulang'      => (string) $row->jam_pulang,
            'toleransi_menit' => (string) (int) $row->toleransi_menit,
            'active_status'   => (string) $row->active_status === '1' ? '1' : '0',
        ];

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-shift-actions');
        $this->dispatch('focus-shift-name');
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    #[On('master.shift.requestDelete')]
    public function deleteShift(string $shiftId): void
    {
        try {
            $deleted = DB::table('siapmst_shifts')->where('shift_id', $shiftId)->delete();
            if ($deleted === 0) {
                $this->dispatch('toast', type: 'error', message: 'Data shift tidak ditemukan.');
                return;
            }

            $this->dispatch('toast', type: 'success', message: 'Shift berhasil dihapus.');
            $this->dispatch('master.shift.saved');
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'ORA-02292')) {
                $this->dispatch('toast', type: 'error', message: 'Shift tidak bisa dihapus karena sudah dipakai di data absensi. Non-aktifkan saja.');
                return;
            }
            throw $e;
        }
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        return [
            'form.shift_id' => $this->formMode === 'create'
                ? ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\-]+$/', 'unique:siapmst_shifts,shift_id']
                : ['required', 'string'],
            'form.shift_name'      => 'required|string|max:50',
            'form.shift_kode'      => ['required', 'string', 'max:2', 'regex:/^[A-Za-z0-9]+$/',
                \Illuminate\Validation\Rule::unique('siapmst_shifts', 'shift_kode')->ignore($this->originalShiftId, 'shift_id'),
                \Illuminate\Validation\Rule::notIn(['I', 'C', 'i', 'c'])],
            'form.jam_masuk'       => 'required|date_format:H:i',
            'form.jam_pulang'      => 'required|date_format:H:i|different:form.jam_masuk',
            'form.toleransi_menit' => 'required|integer|min:0|max:240',
            'form.active_status'   => 'required|in:0,1',
        ];
    }

    protected function messages(): array
    {
        return [
            'required'    => ':attribute wajib diisi.',
            'max'         => ':attribute maksimal :max.',
            'min'         => ':attribute minimal :min.',
            'unique'      => ':attribute sudah digunakan.',
            'integer'     => ':attribute harus angka.',
            'in'          => ':attribute tidak valid.',
            'date_format' => ':attribute harus berformat jam HH:MM (mis. 07:00).',
            'different'   => 'Jam Pulang tidak boleh sama dengan Jam Masuk.',
            'form.shift_id.regex' => ':attribute hanya boleh huruf, angka, strip, atau garis bawah.',
            'form.shift_kode.regex' => ':attribute hanya huruf/angka.',
            'form.shift_kode.not_in' => ':attribute I dan C dipakai untuk Izin & Cuti di jadwal.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.shift_id'        => 'Kode Shift',
            'form.shift_name'      => 'Nama Shift',
            'form.shift_kode'      => 'Kode Jadwal',
            'form.jam_masuk'       => 'Jam Masuk',
            'form.jam_pulang'      => 'Jam Pulang',
            'form.toleransi_menit' => 'Toleransi',
            'form.active_status'   => 'Status',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        $form = $this->form;
        $payload = [
            'shift_name'      => trim($form['shift_name']),
            'shift_kode'      => mb_strtoupper(trim($form['shift_kode'])),
            'jam_masuk'       => $form['jam_masuk'],
            'jam_pulang'      => $form['jam_pulang'],
            'toleransi_menit' => (int) $form['toleransi_menit'],
            'lintas_hari'     => $form['jam_pulang'] <= $form['jam_masuk'] ? '1' : '0',
            'active_status'   => $form['active_status'],
            'updated_at'      => now(),
        ];

        if ($this->formMode === 'create') {
            DB::table('siapmst_shifts')->insert([
                'shift_id'   => mb_strtoupper(trim($form['shift_id'])),
                'created_at' => now(),
                ...$payload,
            ]);
        } else {
            DB::table('siapmst_shifts')->where('shift_id', $this->originalShiftId)->update($payload);
        }

        $this->dispatch('toast', type: 'success', message: 'Data shift berhasil disimpan.');
        $this->closeModal();
        $this->dispatch('master.shift.saved');
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'master-shift-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'shift_id'        => '',
            'shift_name'      => '',
            'shift_kode'      => '',
            'jam_masuk'       => '',
            'jam_pulang'      => '',
            'toleransi_menit' => '10',
            'active_status'   => '1',
        ];
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="master-shift-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="master-shift-actions"
            event="master.shift.saved"
            label="Shift"
            :wireKey="$this->renderKey('modal', [$formMode, $originalShiftId])">

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
                                    {{ $formMode === 'edit' ? 'Ubah Data Shift' : 'Tambah Data Shift' }}
                                </h2>
                                <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                    Jam pulang lebih kecil dari jam masuk = shift lintas hari (pulang esok pagi).
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
                 x-on:focus-shift-id.window="$nextTick(() => setTimeout(() => $refs.inputShiftId?.focus(), 150))"
                 x-on:focus-shift-name.window="$nextTick(() => setTimeout(() => $refs.inputShiftName?.focus(), 150))">

                <div class="max-w-3xl">
                    <x-border-form title="Data Shift">
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <x-input-label value="ID Shift *" />
                                    <x-text-input wire:model.live="form.shift_id" x-ref="inputShiftId"
                                        maxlength="10" placeholder="Mis. PAGI"
                                        :disabled="$formMode === 'edit'"
                                        :error="$errors->has('form.shift_id')" class="w-full mt-1 uppercase"
                                        x-on:keydown.enter.prevent="$refs.inputShiftName?.focus()" />
                                    <x-input-error :messages="$errors->get('form.shift_id')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Kode Jadwal *" />
                                    <x-text-input wire:model.live="form.shift_kode" maxlength="2" placeholder="P / S"
                                        :error="$errors->has('form.shift_kode')" class="w-full mt-1 font-mono text-center uppercase" />
                                    <x-input-error :messages="$errors->get('form.shift_kode')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Nama Shift *" />
                                    <x-text-input wire:model.live="form.shift_name" x-ref="inputShiftName"
                                        maxlength="50"
                                        :error="$errors->has('form.shift_name')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$refs.inputJamMasuk?.focus()" />
                                    <x-input-error :messages="$errors->get('form.shift_name')" class="mt-1" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <x-input-label value="Jam Masuk *" />
                                    <x-text-input wire:model.live="form.jam_masuk" x-ref="inputJamMasuk"
                                        x-mask="99:99" placeholder="HH:MM"
                                        :error="$errors->has('form.jam_masuk')" class="w-full mt-1 font-mono"
                                        x-on:keydown.enter.prevent="$refs.inputJamPulang?.focus()" />
                                    <x-input-error :messages="$errors->get('form.jam_masuk')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Jam Pulang *" />
                                    <x-text-input wire:model.live="form.jam_pulang" x-ref="inputJamPulang"
                                        x-mask="99:99" placeholder="HH:MM"
                                        :error="$errors->has('form.jam_pulang')" class="w-full mt-1 font-mono"
                                        x-on:keydown.enter.prevent="$refs.inputToleransi?.focus()" />
                                    @if (preg_match('/^\d{2}:\d{2}$/', $form['jam_masuk']) && preg_match('/^\d{2}:\d{2}$/', $form['jam_pulang']) && $form['jam_pulang'] < $form['jam_masuk'])
                                        <p class="mt-1 text-[11px] text-purple-700">Lintas hari — pulang esok hari.</p>
                                    @endif
                                    <x-input-error :messages="$errors->get('form.jam_pulang')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Toleransi Terlambat (menit) *" />
                                    <x-text-input wire:model.live="form.toleransi_menit" x-ref="inputToleransi"
                                        type="number" min="0" max="240"
                                        :error="$errors->has('form.toleransi_menit')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$wire.save()" />
                                    <x-input-error :messages="$errors->get('form.toleransi_menit')" class="mt-1" />
                                </div>
                            </div>

                            <x-toggle wire:model.live="form.active_status" trueValue="1" falseValue="0">
                                Status Aktif (tampil di kiosk)
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
