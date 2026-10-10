<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Carbon\Carbon;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal CRUD `siapmst_employers`.
 * Key $form = nama kolom DB. Tanggal di form berformat dd/mm/yyyy, ke Oracle via to_date.
 */
new class extends Component {
    use WithRenderVersioningTrait;

    public string $formMode      = 'create'; // create|edit
    public string $originalEmpId = '';
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public array $form = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    // ─── Open Create ──────────────────────────────────────────────────────────
    #[On('master.karyawan.openCreate')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->formMode      = 'create';
        $this->originalEmpId = '';
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-karyawan-actions');
        $this->dispatch('focus-karyawan-emp-id');
    }

    // ─── Open Edit ────────────────────────────────────────────────────────────
    #[On('master.karyawan.openEdit')]
    public function openEdit(string $empId): void
    {
        $row = DB::table('siapmst_employers')->where('emp_id', $empId)->first();
        if (!$row) {
            $this->dispatch('toast', type: 'error', message: 'Data karyawan tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode      = 'edit';
        $this->originalEmpId = $empId;
        $this->form = [
            'emp_id'          => (string) $row->emp_id,
            'emp_name'        => (string) ($row->emp_name ?? ''),
            'emp_nik_ktp'     => (string) ($row->emp_nik_ktp ?? ''),
            'emp_sex'         => in_array($row->emp_sex, ['L', 'P'], true) ? $row->emp_sex : '',
            'emp_birth_place' => (string) ($row->emp_birth_place ?? ''),
            'emp_birth_date'  => $this->formatTanggal($row->emp_birth_date),
            'emp_address'     => (string) ($row->emp_address ?? ''),
            'emp_phone'       => (string) ($row->emp_phone ?? ''),
            'emp_join_date'   => $this->formatTanggal($row->emp_join_date),
            'emp_is_nakes'    => (string) $row->emp_is_nakes === '1' ? '1' : '0',
            'emp_shift_default' => (string) ($row->emp_shift_default ?? ''),
            // Hanya '1' yang aktif; null/'0' → non-aktif (konsisten dgn toggle di tabel).
            'active_status'   => (string) $row->active_status === '1' ? '1' : '0',
        ];

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'master-karyawan-actions');
        $this->dispatch('focus-karyawan-emp-name');
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    #[On('master.karyawan.requestDelete')]
    public function deleteKaryawan(string $empId): void
    {
        if (DB::table('users')->where('emp_id', $empId)->exists()) {
            $this->dispatch('toast', type: 'error', message: 'Karyawan tidak bisa dihapus karena masih dipakai oleh user login. Non-aktifkan saja.');
            return;
        }

        try {
            $deleted = DB::table('siapmst_employers')->where('emp_id', $empId)->delete();
            if ($deleted === 0) {
                $this->dispatch('toast', type: 'error', message: 'Data karyawan tidak ditemukan.');
                return;
            }

            $this->dispatch('toast', type: 'success', message: 'Data karyawan berhasil dihapus.');
            $this->dispatch('master.karyawan.saved');
        } catch (QueryException $e) {
            // FK dari siapmst_emp_licenses (STR/SIP) & transaksi SIAP lain
            if (str_contains($e->getMessage(), 'ORA-02292')) {
                $this->dispatch('toast', type: 'error', message: 'Karyawan tidak bisa dihapus karena masih dipakai di data STR/SIP atau transaksi. Non-aktifkan saja.');
                return;
            }
            throw $e;
        }
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        return [
            'form.emp_id' => $this->formMode === 'create'
                ? ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', 'unique:siapmst_employers,emp_id']
                : ['required', 'string'],
            'form.emp_name'        => 'required|string|max:100',
            'form.emp_nik_ktp'     => 'nullable|digits:16',
            'form.emp_sex'         => 'required|in:L,P',
            'form.emp_birth_place' => 'nullable|string|max:50',
            'form.emp_birth_date'  => 'nullable|date_format:d/m/Y',
            'form.emp_address'     => 'nullable|string|max:200',
            'form.emp_phone'       => 'nullable|string|max:20',
            'form.emp_join_date'   => 'nullable|date_format:d/m/Y',
            'form.emp_is_nakes'    => 'required|in:0,1',
            'form.emp_shift_default' => 'nullable|exists:siapmst_shifts,shift_id',
            'form.active_status'   => 'required|in:0,1',
        ];
    }

    protected function messages(): array
    {
        return [
            'required'    => ':attribute wajib diisi.',
            'max'         => ':attribute maksimal :max karakter.',
            'unique'      => ':attribute sudah digunakan.',
            'in'          => ':attribute tidak valid.',
            'digits'      => ':attribute harus :digits digit angka.',
            'date_format' => ':attribute harus berformat dd/mm/yyyy.',
            'form.emp_id.regex' => ':attribute hanya boleh huruf, angka, titik, atau strip.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.emp_id'          => 'ID Karyawan',
            'form.emp_name'        => 'Nama Karyawan',
            'form.emp_nik_ktp'     => 'NIK KTP',
            'form.emp_sex'         => 'Jenis Kelamin',
            'form.emp_birth_place' => 'Tempat Lahir',
            'form.emp_birth_date'  => 'Tanggal Lahir',
            'form.emp_address'     => 'Alamat',
            'form.emp_phone'       => 'No. HP',
            'form.emp_join_date'   => 'Tanggal Masuk',
            'form.emp_is_nakes'    => 'Tenaga Kesehatan',
            'form.emp_shift_default' => 'Shift Default (P/S)',
            'form.active_status'   => 'Status Aktif',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        $form = $this->form;
        $payload = [
            'emp_name'        => mb_strtoupper(trim($form['emp_name'])),
            'emp_nik_ktp'     => $form['emp_nik_ktp'] ?: null,
            'emp_sex'         => $form['emp_sex'],
            'emp_birth_place' => $form['emp_birth_place'] ? mb_strtoupper(trim($form['emp_birth_place'])) : null,
            'emp_birth_date'  => $this->tanggalOracle($form['emp_birth_date']),
            'emp_address'     => $form['emp_address'] ?: null,
            'emp_phone'       => $form['emp_phone'] ?: null,
            'emp_join_date'   => $this->tanggalOracle($form['emp_join_date']),
            'emp_is_nakes'    => $form['emp_is_nakes'],
            'emp_shift_default' => $form['emp_shift_default'] !== '' ? $form['emp_shift_default'] : null,
            'active_status'   => $form['active_status'],
            'updated_at'      => now(),
        ];

        if ($this->formMode === 'create') {
            DB::table('siapmst_employers')->insert([
                'emp_id'     => mb_strtoupper(trim($form['emp_id'])),
                'created_at' => now(),
                ...$payload,
            ]);
        } else {
            DB::table('siapmst_employers')->where('emp_id', $this->originalEmpId)->update($payload);
        }

        $this->dispatch('toast', type: 'success', message: 'Data karyawan berhasil disimpan.');
        $this->closeModal();
        $this->dispatch('master.karyawan.saved');
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'master-karyawan-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'emp_id'          => '',
            'emp_name'        => '',
            'emp_nik_ktp'     => '',
            'emp_sex'         => '',
            'emp_birth_place' => '',
            'emp_birth_date'  => '',
            'emp_address'     => '',
            'emp_phone'       => '',
            'emp_join_date'   => '',
            'emp_is_nakes'    => '0',
            'emp_shift_default' => '',
            'active_status'   => '1',
        ];
        $this->resetValidation();
    }

    private function formatTanggal(?string $tanggal): string
    {
        return empty($tanggal) ? '' : Carbon::parse($tanggal)->format('d/m/Y');
    }

    /** Tanggal sudah lolos date_format:d/m/Y — aman dirakit ke to_date. */
    private function tanggalOracle(?string $tanggal)
    {
        return empty($tanggal) ? null : DB::raw("to_date('{$tanggal}', 'dd/mm/yyyy')");
    }
};
?>

<div>
    <x-modal name="master-karyawan-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="master-karyawan-actions"
            event="master.karyawan.saved"
            label="Karyawan"
            :wireKey="$this->renderKey('modal', [$formMode, $originalEmpId])">

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
                                    {{ $formMode === 'edit' ? 'Ubah Data Karyawan' : 'Tambah Data Karyawan' }}
                                </h2>
                                <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                    ID Karyawan dipakai untuk login, absensi, dan STR/SIP. Tandai "Tenaga Kesehatan" untuk karyawan yang wajib STR/SIP.
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
                    x-on:focus-karyawan-emp-id.window="$nextTick(() => setTimeout(() => $refs.inputEmpId?.focus(), 150))"
                    x-on:focus-karyawan-emp-name.window="$nextTick(() => setTimeout(() => $refs.inputEmpName?.focus(), 150))">

                    <x-border-form title="Identitas">
                        <div class="space-y-4">
                            <div>
                                <x-input-label value="ID Karyawan" />
                                <x-text-input wire:model.live="form.emp_id" x-ref="inputEmpId"
                                    maxlength="20"
                                    :disabled="$formMode === 'edit'"
                                    :error="$errors->has('form.emp_id')"
                                    class="w-full mt-1 uppercase"
                                    x-on:keydown.enter.prevent="$refs.inputEmpName?.focus()" />
                                <x-input-error :messages="$errors->get('form.emp_id')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label value="Nama Karyawan" />
                                <x-text-input wire:model.live="form.emp_name" x-ref="inputEmpName"
                                    maxlength="100"
                                    :error="$errors->has('form.emp_name')"
                                    class="w-full mt-1 uppercase"
                                    x-on:keydown.enter.prevent="$refs.inputNikKtp?.focus()" />
                                <x-input-error :messages="$errors->get('form.emp_name')" class="mt-1" />
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label value="NIK KTP" />
                                    <x-text-input wire:model.live="form.emp_nik_ktp" x-ref="inputNikKtp"
                                        maxlength="16" inputmode="numeric"
                                        :error="$errors->has('form.emp_nik_ktp')"
                                        class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$refs.inputSex?.focus()" />
                                    <x-input-error :messages="$errors->get('form.emp_nik_ktp')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Jenis Kelamin" />
                                    <x-select-input wire:model.live="form.emp_sex" x-ref="inputSex"
                                        :error="$errors->has('form.emp_sex')" class="mt-1"
                                        x-on:keydown.enter.prevent="$refs.inputBirthPlace?.focus()">
                                        <option value="">— pilih —</option>
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </x-select-input>
                                    <x-input-error :messages="$errors->get('form.emp_sex')" class="mt-1" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label value="Tempat Lahir" />
                                    <x-text-input wire:model.live="form.emp_birth_place" x-ref="inputBirthPlace"
                                        maxlength="50"
                                        :error="$errors->has('form.emp_birth_place')"
                                        class="w-full mt-1 uppercase"
                                        x-on:keydown.enter.prevent="$refs.inputBirthDate?.focus()" />
                                    <x-input-error :messages="$errors->get('form.emp_birth_place')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Tanggal Lahir" />
                                    <x-text-input wire:model.live.debounce.500ms="form.emp_birth_date" x-ref="inputBirthDate"
                                        placeholder="dd/mm/yyyy" x-mask="99/99/9999"
                                        :error="$errors->has('form.emp_birth_date')"
                                        class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$refs.inputAddress?.focus()" />
                                    <x-input-error :messages="$errors->get('form.emp_birth_date')" class="mt-1" />
                                </div>
                            </div>

                            <div>
                                <x-input-label value="Alamat" />
                                <x-text-input wire:model.live="form.emp_address" x-ref="inputAddress"
                                    maxlength="200"
                                    :error="$errors->has('form.emp_address')"
                                    class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$refs.inputPhone?.focus()" />
                                <x-input-error :messages="$errors->get('form.emp_address')" class="mt-1" />
                            </div>
                        </div>
                    </x-border-form>

                    <x-border-form title="Kepegawaian">
                        <div class="space-y-4">
                            <div>
                                <x-input-label value="No. HP" />
                                <x-text-input wire:model.live="form.emp_phone" x-ref="inputPhone"
                                    maxlength="20" inputmode="tel"
                                    :error="$errors->has('form.emp_phone')"
                                    class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$refs.inputJoinDate?.focus()" />
                                <x-input-error :messages="$errors->get('form.emp_phone')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label value="Tanggal Masuk Kerja" />
                                <x-text-input wire:model.live.debounce.500ms="form.emp_join_date" x-ref="inputJoinDate"
                                    placeholder="dd/mm/yyyy" x-mask="99/99/9999"
                                    :error="$errors->has('form.emp_join_date')"
                                    class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$wire.save()" />
                                <x-input-error :messages="$errors->get('form.emp_join_date')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label value="Shift Default (P/S)" />
                                <x-select-input wire:model.live="form.emp_shift_default" :error="$errors->has('form.emp_shift_default')" class="mt-1">
                                    <option value="">— tidak ada —</option>
                                    @foreach (\App\Support\Jadwal::shiftBerkode() as $kode => $sh)
                                        <option value="{{ $sh->shift_id }}">{{ $kode }} — {{ $sh->shift_name }} ({{ $sh->jam_masuk }}–{{ $sh->jam_pulang }})</option>
                                    @endforeach
                                </x-select-input>
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">Kolom P/S di jadwal — dipakai tombol "Isi Sen–Sab" pada Jadwal Dinas.</p>
                                <x-input-error :messages="$errors->get('form.emp_shift_default')" class="mt-1" />
                            </div>

                            <div class="flex flex-wrap gap-6 pt-2">
                                <x-toggle wire:model.live="form.emp_is_nakes" trueValue="1" falseValue="0">
                                    Tenaga Kesehatan (wajib STR/SIP)
                                </x-toggle>
                                <x-toggle wire:model.live="form.active_status" trueValue="1" falseValue="0">
                                    Status Aktif
                                </x-toggle>
                            </div>
                        </div>
                    </x-border-form>
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs text-muted dark:text-gray-400">
                        <kbd class="px-1.5 py-0.5 text-xs font-semibold bg-surface-card border border-hairline rounded dark:bg-gray-800 dark:border-gray-600">Enter</kbd>
                        <span class="mx-0.5">untuk berpindah field,</span>
                        <kbd class="px-1.5 py-0.5 text-xs font-semibold bg-surface-card border border-hairline rounded dark:bg-gray-800 dark:border-gray-600">Enter</kbd>
                        <span class="hidden sm:inline"> di field terakhir untuk menyimpan</span>
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
