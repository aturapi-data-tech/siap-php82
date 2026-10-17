<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Http\Traits\Concerns\WithRenderVersioningTrait;

/**
 * Modal CRUD role (Spatie) + permission yang melekat.
 * Role 'Admin' dikunci: tidak bisa dihapus / diganti nama (cegah semua admin terkunci keluar).
 */
new class extends Component {
    use WithRenderVersioningTrait;

    private const ROLE_TERKUNCI = 'Admin';

    public string $formMode = 'create'; // create|edit
    public ?int   $roleId   = null;
    public string $originalName = '';
    public array  $renderVersions = [];
    protected array $renderAreas  = ['modal'];

    public array $form = [];
    public array $selectedPermissions = [];

    public function mount(): void
    {
        $this->registerAreas(['modal']);
        $this->resetForm();
    }

    // ─── Open Create ──────────────────────────────────────────────────────────
    #[On('sistem.role-control.openCreate')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->formMode = 'create';
        $this->roleId   = null;
        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'role-control-actions');
        $this->dispatch('focus-role-control-name');
    }

    // ─── Open Edit ────────────────────────────────────────────────────────────
    #[On('sistem.role-control.openEdit')]
    public function openEdit(int $roleId): void
    {
        $role = DB::table('roles')->where('id', $roleId)->first();
        if (!$role) {
            $this->dispatch('toast', type: 'error', message: 'Role tidak ditemukan.');
            return;
        }

        $this->resetForm();
        $this->formMode     = 'edit';
        $this->roleId       = (int) $role->id;
        $this->originalName = (string) $role->name;
        $this->form = [
            'name'       => (string) $role->name,
            'guard_name' => (string) ($role->guard_name ?? 'web'),
        ];
        $this->selectedPermissions = DB::table('role_has_permissions')
            ->where('role_id', $this->roleId)
            ->pluck('permission_id')
            ->map(fn($v) => (int) $v)
            ->all();

        $this->incrementVersion('modal');
        $this->dispatch('open-modal', name: 'role-control-actions');
        $this->dispatch('focus-role-control-name');
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    #[On('sistem.role-control.requestDelete')]
    public function deleteRole(int $roleId): void
    {
        $role = DB::table('roles')->where('id', $roleId)->first();
        if (!$role) {
            $this->dispatch('toast', type: 'error', message: 'Role tidak ditemukan.');
            return;
        }

        if ($role->name === self::ROLE_TERKUNCI) {
            $this->dispatch('toast', type: 'error', message: 'Role Admin tidak boleh dihapus.');
            return;
        }

        if (DB::table('model_has_roles')->where('role_id', $roleId)->exists()) {
            $this->dispatch('toast', type: 'error', message: 'Role masih dipakai user. Cabut role dari user terlebih dahulu.');
            return;
        }

        try {
            DB::transaction(function () use ($roleId) {
                DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
                DB::table('roles')->where('id', $roleId)->delete();
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'ORA-02292')) {
                $this->dispatch('toast', type: 'error', message: 'Role tidak bisa dihapus karena masih dipakai di data lain.');
                return;
            }
            throw $e;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->dispatch('toast', type: 'success', message: 'Role berhasil dihapus.');
        $this->dispatch('sistem.role-control.saved');
    }

    // ─── Permission ───────────────────────────────────────────────────────────
    #[Computed]
    public function allPermissions()
    {
        return DB::table('permissions')
            ->where('guard_name', $this->form['guard_name'] ?? 'web')
            ->orderBy('name')
            ->get();
    }

    public function togglePermission(int $permissionId): void
    {
        $key = array_search($permissionId, $this->selectedPermissions, true);

        if ($key === false) {
            $this->selectedPermissions[] = $permissionId;
        } else {
            unset($this->selectedPermissions[$key]);
            $this->selectedPermissions = array_values($this->selectedPermissions);
        }
    }

    public function selectAllPermissions(): void
    {
        $this->selectedPermissions = $this->allPermissions->pluck('id')->map(fn($v) => (int) $v)->all();
    }

    public function clearAllPermissions(): void
    {
        $this->selectedPermissions = [];
    }

    // ─── Validasi ─────────────────────────────────────────────────────────────
    protected function rules(): array
    {
        $guard  = $this->form['guard_name'] ?? 'web';
        $ignore = $this->formMode === 'edit' ? $this->roleId : 'NULL';

        return [
            'form.name'             => "required|string|max:125|unique:roles,name,{$ignore},id,guard_name,{$guard}",
            'form.guard_name'       => 'required|in:web,api',
            'selectedPermissions'   => 'array',
            'selectedPermissions.*' => 'integer|exists:permissions,id',
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'unique'   => ':attribute sudah digunakan pada guard yang sama.',
            'max'      => ':attribute maksimal :max karakter.',
            'in'       => ':attribute tidak valid.',
            'selectedPermissions.*.exists' => 'Salah satu permission tidak valid.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.name'           => 'Nama Role',
            'form.guard_name'     => 'Guard',
            'selectedPermissions' => 'Permissions',
        ];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────
    public function save(): void
    {
        $this->form['name'] = trim($this->form['name']);
        $this->validate();

        if ($this->formMode === 'edit' && $this->originalName === self::ROLE_TERKUNCI && $this->form['name'] !== self::ROLE_TERKUNCI) {
            $this->addError('form.name', 'Role Admin tidak boleh diganti nama.');
            return;
        }

        DB::transaction(function () {
            if ($this->formMode === 'create') {
                $role = Role::create(['name' => $this->form['name'], 'guard_name' => $this->form['guard_name']]);
            } else {
                $role = Role::findById($this->roleId, $this->originalGuard());
                $role->update(['name' => $this->form['name'], 'guard_name' => $this->form['guard_name']]);
            }

            $permissions = Permission::whereIn('id', $this->selectedPermissions)
                ->where('guard_name', $this->form['guard_name'])
                ->get();

            $role->syncPermissions($permissions);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->dispatch('toast', type: 'success', message: $this->formMode === 'create' ? 'Role berhasil ditambahkan.' : 'Role berhasil diperbarui.');
        $this->closeModal();
        $this->dispatch('sistem.role-control.saved');
    }

    private function originalGuard(): string
    {
        return (string) DB::table('roles')->where('id', $this->roleId)->value('guard_name');
    }

    // ─── Close ────────────────────────────────────────────────────────────────
    public function closeModal(): void
    {
        $this->resetForm();
        $this->dispatch('close-modal', name: 'role-control-actions');
        $this->resetVersion();
    }

    private function resetForm(): void
    {
        $this->form = ['name' => '', 'guard_name' => 'web'];
        $this->selectedPermissions = [];
        $this->originalName = '';
        $this->resetValidation();
    }
};
?>

<div>
    <x-modal name="role-control-actions" size="full" height="full" focusable>
        <x-dirty-modal-content
            name="role-control-actions"
            event="sistem.role-control.saved"
            label="Role"
            :wireKey="$this->renderKey('modal', [$formMode, $roleId ?? 'new'])">

            {{-- HEADER — standar modal modul dokumen (x-modul-dokumen.header, diport dari sirus) --}}
            <x-modul-dokumen.header :judul="($formMode === 'edit' ? 'Ubah Role' : 'Tambah Role Baru')"
                ikon="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" tutupAlpine="tryClose()">
                Kelola role & permission yang melekat pada role.
                <x-slot:badge><x-badge class="shrink-0 whitespace-nowrap" :variant="$formMode === 'edit' ? 'warning' : 'success'"> {{ $formMode === 'edit' ? 'Mode: Edit' : 'Mode: Tambah' }} </x-badge></x-slot:badge>
            </x-modul-dokumen.header>

            {{-- BODY --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain>
                <div class="grid max-w-5xl grid-cols-1 gap-4 lg:grid-cols-2"
                    x-data
                    x-on:focus-role-control-name.window="$nextTick(() => setTimeout(() => $refs.inputRoleName?.focus(), 150))">

                    <x-border-form title="Data Role">
                        <div class="space-y-4">
                            <div>
                                <x-input-label value="Nama Role *" />
                                <x-text-input wire:model.live="form.name" x-ref="inputRoleName"
                                    maxlength="125" placeholder="Contoh: Admin, Hrd, Karyawan"
                                    :disabled="$formMode === 'edit' && $originalName === 'Admin'"
                                    :error="$errors->has('form.name')" class="w-full mt-1"
                                    x-on:keydown.enter.prevent="$wire.save()" />
                                <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                    Nama unik per guard — gunakan huruf kapital di awal (Admin, Hrd, Karyawan).
                                    Menu memeriksa nama role tanpa membedakan huruf besar/kecil.
                                </p>
                            </div>

                            <div>
                                <x-input-label value="Guard *" />
                                <x-select-input wire:model.live="form.guard_name"
                                    :error="$errors->has('form.guard_name')" class="w-full mt-1">
                                    <option value="web">web</option>
                                    <option value="api">api</option>
                                </x-select-input>
                                <x-input-error :messages="$errors->get('form.guard_name')" class="mt-1" />
                                <p class="mt-1 text-[11px] text-muted dark:text-gray-400">
                                    Biasanya <code class="font-mono">web</code> untuk aplikasi ini.
                                </p>
                            </div>
                        </div>
                    </x-border-form>

                    <x-border-form title="Permissions">
                        <div class="space-y-3">
                            @if ($this->allPermissions->isEmpty())
                                <div class="p-4 text-sm text-center border border-gray-300 border-dashed text-muted dark:text-gray-400 dark:border-gray-700 rounded-xl">
                                    Belum ada permission untuk guard
                                    <code class="font-mono">{{ $form['guard_name'] }}</code>.
                                    Hak akses saat ini cukup diatur lewat role.
                                </div>
                            @else
                                <div class="flex items-center justify-between pb-2 border-b border-hairline dark:border-gray-700">
                                    <p class="text-xs text-muted dark:text-gray-400">
                                        {{ count($selectedPermissions) }} dari {{ $this->allPermissions->count() }} permission aktif
                                    </p>
                                    <div class="flex gap-2">
                                        <button type="button" wire:click="selectAllPermissions"
                                            class="text-xs font-medium text-brand-green dark:text-brand-lime hover:underline">Aktifkan semua</button>
                                        <span class="text-xs text-gray-300">|</span>
                                        <button type="button" wire:click="clearAllPermissions"
                                            class="text-xs font-medium text-muted hover:underline">Nonaktifkan semua</button>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-2 max-h-[50vh] overflow-y-auto pr-1">
                                    @foreach ($this->allPermissions as $perm)
                                        @php $isActive = in_array((int) $perm->id, $selectedPermissions, true); @endphp
                                        {{-- <button role="switch">: bisa dipilih dengan Tab + Spasi, status terbaca pembaca layar --}}
                                        <button type="button" role="switch" aria-checked="{{ $isActive ? 'true' : 'false' }}"
                                            wire:key="perm-{{ $perm->id }}"
                                            wire:click="togglePermission({{ (int) $perm->id }})"
                                            wire:loading.attr="disabled" wire:target="togglePermission({{ (int) $perm->id }})"
                                            class="flex items-center justify-between w-full gap-3 p-3 text-left rounded-lg border cursor-pointer transition
                                                focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:focus-visible:ring-brand-lime
                                                {{ $isActive
                                                    ? 'border-brand-green/50 bg-brand-green/5 dark:border-brand-lime/50 dark:bg-brand-lime/5'
                                                    : 'border-hairline dark:border-gray-700 hover:bg-surface-soft dark:hover:bg-gray-800/60' }}">
                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm font-medium truncate text-ink dark:text-gray-200">{{ $perm->name }}</div>
                                                <div class="text-[11px] text-muted-soft font-mono">id: {{ $perm->id }} · guard: {{ $perm->guard_name }}</div>
                                            </div>
                                            <div aria-hidden="true"
                                                class="relative h-6 transition rounded-full w-11 shrink-0 {{ $isActive ? 'bg-brand-green dark:bg-brand-lime' : 'bg-gray-300 dark:bg-gray-600' }}">
                                                <div class="absolute top-1 w-4 h-4 transition-all bg-canvas rounded-full shadow {{ $isActive ? 'left-6' : 'left-1' }}"></div>
                                            </div>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                            <x-input-error :messages="$errors->get('selectedPermissions')" class="mt-1" />
                            <x-input-error :messages="$errors->get('selectedPermissions.*')" class="mt-1" />
                        </div>
                    </x-border-form>
                </div>
            </div>

            {{-- FOOTER --}}
            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs text-muted dark:text-gray-400">
                        <kbd class="px-1.5 py-0.5 text-xs font-semibold bg-surface-card border border-hairline rounded dark:bg-gray-800 dark:border-gray-600">Enter</kbd>
                        <span class="mx-0.5">di Nama Role untuk menyimpan</span>
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
