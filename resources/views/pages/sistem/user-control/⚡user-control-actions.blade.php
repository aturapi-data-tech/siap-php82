<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Models\User;
use App\Support\TtdUser;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal CRUD tabel `users`.
 * Role diberi/dicabut dari list (dropdown per baris); di sini hanya data akun.
 */
new class extends Component {
    use WithFileUploads, WithRenderVersioningTrait;

    public string $formMode = 'create'; // create|edit
    public ?int   $userId   = null;
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public array $form = [];

    // Field di luar $form: konfirmasi password (rule `confirmed`) & file upload.
    public string $password_confirmation = '';
    public $myuser_ttd_image = null;
    public ?string $existing_ttd_image = null;

    public ?string $emp_name = null; // tampilan saja, tidak disimpan

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    // ─── Open Create ──────────────────────────────────────────────────────────
    #[On('sistem.user-control.openCreate')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->formMode = 'create';
        $this->userId   = null;
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'user-control-actions');
        $this->dispatch('focus-user-control-name');
    }

    // ─── Open Edit ────────────────────────────────────────────────────────────
    #[On('sistem.user-control.openEdit')]
    public function openEdit(int $userId): void
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            $this->dispatch('toast', type: 'error', message: 'User tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode = 'edit';
        $this->userId   = (int) $user->id;
        $this->form = [
            'name'           => (string) ($user->name ?? ''),
            'email'          => (string) ($user->email ?? ''),
            'myuser_code'    => (string) ($user->myuser_code ?? ''),
            'myuser_profesi' => (string) ($user->myuser_profesi ?? ''),
            'emp_id'         => $user->emp_id ? (string) $user->emp_id : null,
            'active_status'  => (string) ($user->active_status ?? '1') === '1' ? '1' : '0',
            'password'       => '',
        ];
        $this->existing_ttd_image = $user->myuser_ttd_image ?? null;
        $this->emp_name = $this->form['emp_id']
            ? DB::table('siapmst_employers')->where('emp_id', $this->form['emp_id'])->value('emp_name')
            : null;

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'user-control-actions');
        $this->dispatch('focus-user-control-name');
    }

    // ─── LOV Karyawan ─────────────────────────────────────────────────────────
    #[On('lov.selected.user-control')]
    public function onKaryawanSelected(string $target, ?array $payload): void
    {
        $this->form['emp_id'] = $payload['emp_id'] ?? null;
        $this->emp_name       = $payload['emp_name'] ?? null;

        // Nama user kosong → isi dari nama karyawan supaya tidak mengetik dua kali.
        if ($payload && trim($this->form['name']) === '') {
            $this->form['name'] = $payload['emp_name'] ?? '';
        }

        $this->resetErrorBag('form.emp_id');
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    #[On('sistem.user-control.requestDelete')]
    public function deleteUser(int $userId): void
    {
        if ($userId === (int) auth()->id()) {
            $this->dispatch('toast', type: 'error', message: 'Tidak bisa menghapus akun sendiri.');
            return;
        }

        $user = User::find($userId);
        if (!$user) {
            $this->dispatch('toast', type: 'error', message: 'User tidak ditemukan.');
            return;
        }

        $ttdPath = TtdUser::pathDiskPublic($user->myuser_ttd_image);

        DB::transaction(function () use ($user) {
            $user->syncRoles([]);            // bersihkan pivot model_has_roles (tanpa FK ke users)
            $user->syncPermissions([]);
            $user->delete();
        });

        if ($ttdPath && Storage::disk('public')->exists($ttdPath)) {
            Storage::disk('public')->delete($ttdPath);
        }

        $this->dispatch('toast', type: 'success', message: 'User berhasil dihapus.');
        $this->dispatch('sistem.user-control.saved');
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        $ignoreId = $this->formMode === 'edit' ? ',' . $this->userId : '';

        return [
            'form.name'           => 'required|string|max:100',
            'form.email'          => 'required|email|max:100|unique:users,email' . $ignoreId,
            'form.myuser_code'    => 'nullable|string|max:50|unique:users,myuser_code' . $ignoreId,
            'form.myuser_profesi' => 'nullable|string|max:30',
            'form.emp_id'         => 'nullable|string|max:20|exists:siapmst_employers,emp_id',
            'form.active_status'  => 'required|in:0,1',
            'form.password'       => ($this->formMode === 'create' ? 'required' : 'nullable') . '|string|min:6|same:password_confirmation',
            'myuser_ttd_image'    => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'unique'   => ':attribute sudah digunakan.',
            'email'    => 'Format email tidak valid.',
            'min'      => ':attribute minimal :min karakter.',
            'max'      => ':attribute maksimal :max karakter.',
            'exists'   => ':attribute tidak ada di master karyawan.',
            'in'       => ':attribute tidak valid.',
            'form.password.same'       => 'Konfirmasi password tidak cocok.',
            'myuser_ttd_image.mimes'   => 'Gambar TTD harus JPG atau PNG.',
            'myuser_ttd_image.max'     => 'Ukuran gambar TTD maksimal 5 MB.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.name'           => 'Nama User',
            'form.email'          => 'Email',
            'form.myuser_code'    => 'Kode User',
            'form.myuser_profesi' => 'Label Peran',
            'form.emp_id'         => 'Karyawan',
            'form.active_status'  => 'Status Akun',
            'form.password'       => 'Password',
            'myuser_ttd_image'    => 'Gambar TTD',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->validate();

        if ($this->formMode === 'edit' && $this->userId === (int) auth()->id() && $this->form['active_status'] === '0') {
            $this->addError('form.active_status', 'Tidak bisa menonaktifkan akun sendiri.');
            return;
        }

        $form = $this->form;
        $data = [
            'name'           => trim($form['name']),
            'myuser_name'    => trim($form['name']),
            'email'          => mb_strtolower(trim($form['email'])),
            'myuser_code'    => $form['myuser_code'] !== '' ? mb_strtoupper(trim($form['myuser_code'])) : null,
            'myuser_profesi' => $form['myuser_profesi'] !== '' ? trim($form['myuser_profesi']) : null,
            'emp_id'         => $form['emp_id'] ?: null,
            'active_status'  => $form['active_status'],
            'updated_at'     => DB::raw('SYSDATE'),
        ];

        if ($form['password'] !== '') {
            $data['password'] = Hash::make($form['password']);
        }

        // TTD disimpan di disk PUBLIC folder UserTtd, kolom berisi nama file saja
        // (dibaca lewat App\Support\TtdUser — sama dengan SIRUS).
        $oldTtdPath = null;
        if ($this->myuser_ttd_image) {
            $filename = Carbon::now()->format('dmYHis') . '.' . $this->myuser_ttd_image->getClientOriginalExtension();
            $this->myuser_ttd_image->storeAs('UserTtd', $filename, 'public');
            $data['myuser_ttd_image'] = $filename;
            $oldTtdPath = TtdUser::pathDiskPublic($this->existing_ttd_image);
        }

        if ($this->formMode === 'create') {
            DB::table('users')->insert([
                ...$data,
                'created_at'        => DB::raw('SYSDATE'),
                'email_verified_at' => DB::raw('SYSDATE'), // dibuat admin → dianggap terverifikasi
            ]);
            $message = 'User berhasil ditambahkan.';
        } else {
            DB::table('users')->where('id', $this->userId)->update($data);
            $message = 'User berhasil diperbarui.';
        }

        if ($oldTtdPath && Storage::disk('public')->exists($oldTtdPath)) {
            Storage::disk('public')->delete($oldTtdPath);
        }

        $this->dispatch('toast', type: 'success', message: $message);
        $this->closeModal();
        $this->dispatch('sistem.user-control.saved');
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'user-control-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = [
            'name'           => '',
            'email'          => '',
            'myuser_code'    => '',
            'myuser_profesi' => '',
            'emp_id'         => null,
            'active_status'  => '1',
            'password'       => '',
        ];
        $this->password_confirmation = '';
        $this->myuser_ttd_image = null;
        $this->existing_ttd_image = null;
        $this->emp_name = null;
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="user-control-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="user-control-actions"
            event="sistem.user-control.saved"
            label="User"
            :wireKey="$this->renderKey('modal', [$formMode, $userId ?? 'new'])">

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
                                    {{ $formMode === 'edit' ? 'Ubah User' : 'Tambah User Baru' }}
                                </h2>
                                <p class="mt-0.5 text-sm text-muted dark:text-gray-400">
                                    Data akun login. Role diatur langsung dari daftar user (klik badge role).
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
                    x-on:focus-user-control-name.window="$nextTick(() => setTimeout(() => $refs.inputName?.focus(), 150))">

                    <x-border-form title="Akun">
                        <div class="space-y-4">
                            <div>
                                <x-input-label value="Nama User *" />
                                <x-text-input wire:model.live="form.name" x-ref="inputName"
                                    maxlength="100"
                                    :error="$errors->has('form.name')" class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$refs.inputEmail?.focus()" />
                                <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                            </div>

                            <div>
                                <x-input-label value="Email (untuk login) *" />
                                <x-text-input wire:model.live="form.email" x-ref="inputEmail" type="email"
                                    maxlength="100" placeholder="nama@siap.com"
                                    :error="$errors->has('form.email')" class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$refs.inputCode?.focus()" />
                                <x-input-error :messages="$errors->get('form.email')" class="mt-1" />
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label value="Kode User" />
                                    <x-text-input wire:model.live="form.myuser_code" x-ref="inputCode"
                                        maxlength="50" placeholder="Opsional, mis. ADM001"
                                        :error="$errors->has('form.myuser_code')" class="w-full mt-1 uppercase"
                                        x-on:keydown.enter.prevent="$refs.inputProfesi?.focus()" />
                                    <x-input-error :messages="$errors->get('form.myuser_code')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Label Peran" />
                                    <x-text-input wire:model.live="form.myuser_profesi" x-ref="inputProfesi"
                                        maxlength="30" placeholder="Kosong = ikut role pertama"
                                        :error="$errors->has('form.myuser_profesi')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$refs.inputPassword?.focus()" />
                                    <x-input-error :messages="$errors->get('form.myuser_profesi')" class="mt-1" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <x-input-label value="{{ $formMode === 'create' ? 'Password *' : 'Password Baru' }}" />
                                    <x-text-input wire:model="form.password" x-ref="inputPassword" type="password"
                                        autocomplete="new-password"
                                        placeholder="{{ $formMode === 'create' ? 'Minimal 6 karakter' : 'Kosongkan jika tidak diubah' }}"
                                        :error="$errors->has('form.password')" class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$refs.inputPasswordConfirm?.focus()" />
                                    <x-input-error :messages="$errors->get('form.password')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label value="Konfirmasi Password" />
                                    <x-text-input wire:model="password_confirmation" x-ref="inputPasswordConfirm" type="password"
                                        autocomplete="new-password" placeholder="Ulangi password"
                                        class="w-full mt-1"
                                        x-on:keydown.enter.prevent="$wire.save()" />
                                </div>
                            </div>

                            <div class="pt-2">
                                <x-toggle wire:model.live="form.active_status" trueValue="1" falseValue="0">
                                    {{ $form['active_status'] === '1' ? 'Aktif — boleh login' : 'Nonaktif — diblokir login' }}
                                </x-toggle>
                                <x-input-error :messages="$errors->get('form.active_status')" class="mt-1" />
                            </div>
                        </div>
                    </x-border-form>

                    <x-border-form title="Karyawan & Tanda Tangan">
                        <div class="space-y-4">
                            <div>
                                <livewire:lov.karyawan.lov-karyawan target="user-control" label="Karyawan"
                                    :initialEmpId="$form['emp_id']"
                                    wire:key="lov-karyawan-{{ $userId ?? 'new' }}-{{ $renderVersions['modal'] ?? 0 }}" />
                                <x-input-error :messages="$errors->get('form.emp_id')" class="mt-1" />
                                <p class="mt-1 text-xs text-muted dark:text-gray-400">
                                    Hubungkan akun ke data karyawan (Master Karyawan) — dipakai untuk absensi & atribusi transaksi.
                                </p>
                            </div>

                            @if ($form['emp_id'] && $emp_name)
                                <div class="p-3 border border-emerald-200 rounded-xl bg-emerald-50 dark:border-emerald-800/40 dark:bg-emerald-900/10">
                                    <p class="mb-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">Karyawan terhubung</p>
                                    <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">{{ $emp_name }}</p>
                                    <p class="font-mono text-xs text-emerald-600 dark:text-emerald-400">{{ $form['emp_id'] }}</p>
                                </div>
                            @endif

                            <div>
                                <x-file-upload
                                    name="myuser_ttd_image"
                                    label="Gambar Tanda Tangan (JPG/PNG, maks 5 MB)"
                                    accept="image/jpeg,image/png"
                                    :show-error="false" />
                                @if ($existing_ttd_image)
                                    <div class="mt-2">
                                        <img src="{{ \App\Support\TtdUser::url($existing_ttd_image) }}" class="h-16 bg-white border rounded" alt="TTD saat ini">
                                        <p class="mt-1 text-xs text-muted">Gambar saat ini. Upload baru untuk mengganti.</p>
                                    </div>
                                @endif
                                <x-input-error :messages="$errors->get('myuser_ttd_image')" class="mt-1" />
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
                        <span class="hidden sm:inline"> di konfirmasi password untuk menyimpan</span>
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" x-on:click="tryClose()">Batal</x-secondary-button>
                        <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ $formMode === 'edit' ? 'Perbarui' : 'Simpan' }}</span>
                            <span wire:loading wire:target="save">Menyimpan...</span>
                        </x-primary-button>
                    </div>
                </div>
            </div>

        </x-dirty-modal-content>
    </x-modal>
</div>
