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
use App\Support\AksesHalaman;
use App\Support\HakAkses;
use App\Support\ProgramAkses;

/**
 * Modal CRUD role (Spatie) + permission yang melekat.
 * Role 'Admin' dikunci: tidak bisa dihapus / diganti nama (cegah semua admin terkunci keluar) dan selalu boleh semua.
 * Permission = hak akses aplikasi (definisi per program: app/Akses/Program); dicentang di sini per role.
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
            ->map(fn($v) => (string) $v)
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

    /**
     * Matriks centang PER KELOMPOK → PROGRAM (berkas app/Akses/Program, urutan definisi) — tampilan sama dengan
     * SIRUS: tiap program memuat baris halamannya (Lihat) lalu baris aksi khususnya.
     * 'id' = id permission (string), null bila belum dibuat (akses:sinkron belum jalan).
     * 'lainnya' = permission di luar definisi (seharusnya kosong — sinkron menghapusnya).
     *
     * @return array{program: array<string, array<string, array{halaman: array, khusus: array}>>, lainnya: array, belumSinkron: bool}
     */
    #[Computed]
    public function matriks(): array
    {
        $idPerNama = $this->allPermissions->pluck('id', 'name')->map(fn ($v) => (string) $v)->all();
        $terpakai = [];
        $sudah = [];
        $program = [];
        $belumSinkron = false;

        foreach (ProgramAkses::kelompok() as $kelompok) {
            foreach ($kelompok['program'] as $namaProgram => $definisi) {
                $halaman = [];
                foreach ($definisi['menu'] ?? [] as $m) {
                    if (isset($sudah[$m['route']])) {
                        continue;
                    }
                    $sudah[$m['route']] = true;
                    $nama = AksesHalaman::namaPermission($m['route'], 'lihat');
                    $terpakai[$nama] = true;
                    $terbuka = in_array(HakAkses::SEMUA_ROLE, $m['roles'], true);
                    $id = $idPerNama[$nama] ?? null;
                    $belumSinkron = $belumSinkron || (!$terbuka && $id === null);
                    $halaman[] = ['route' => $m['route'], 'title' => $m['title'], 'terbuka' => $terbuka, 'id' => $id];
                }

                $khusus = [];
                foreach ($definisi['aksiKhusus'] ?? [] as $nama => $aksi) {
                    $terpakai[$nama] = true;
                    $id = $idPerNama[$nama] ?? null;
                    $belumSinkron = $belumSinkron || $id === null;
                    $khusus[] = ['nama' => $nama, 'label' => $aksi['label'], 'id' => $id];
                }

                if ($halaman || $khusus) {
                    $program[$kelompok['judul']][$namaProgram] = ['halaman' => $halaman, 'khusus' => $khusus];
                }
            }
        }

        $lainnya = $this->allPermissions
            ->reject(fn ($p) => isset($terpakai[$p->name]))
            ->map(fn ($p) => ['id' => (string) $p->id, 'nama' => $p->name])
            ->values()
            ->all();

        return compact('program', 'lainnya', 'belumSinkron');
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
            // Tanpa `exists` (1 query per centang); id asing tersaring whereIn di save().
            'selectedPermissions.*' => 'integer',
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'unique'   => ':attribute sudah digunakan pada guard yang sama.',
            'max'      => ':attribute maksimal :max karakter.',
            'in'       => ':attribute tidak valid.',
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

            $permissions = Permission::whereIn('id', array_map('intval', $this->selectedPermissions))
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

            {{-- BODY — tampilan sama dengan Role Control SIRUS. Sengaja TANPA overflow-y-auto: yang scroll panel
                 x-modal, supaya thead tabel hak akses bisa menempel (sticky). --}}
            <div class="flex-1 px-4 py-4 bg-surface-soft dark:bg-gray-950/20" x-enter-chain>
                <div class="p-1 space-y-4"
                    x-data
                    x-on:focus-role-control-name.window="$nextTick(() => setTimeout(() => $refs.inputRoleName?.focus(), 150))">

                        <x-border-form title="Data Role">
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
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

                    {{-- MATRIKS HAK AKSES --}}
                    @php $matriks = $this->matriks; @endphp
                    <x-border-form title="Hak Akses">
                        <div x-data="{
                                pilih: $wire.entangle('selectedPermissions'),
                                cari: '',
                                cocok(teks) { return this.cari === '' || teks.includes(this.cari.toLowerCase()) },
                                semuaNyala(ids) { return ids.length > 0 && ids.every(i => this.pilih.includes(i)) },
                                atur(ids) {
                                    const nyala = !this.semuaNyala(ids);
                                    const sisa = this.pilih.filter(i => !ids.includes(i));
                                    this.pilih = nyala ? [...sisa, ...ids] : sisa;
                                    $el.dispatchEvent(new Event('change', { bubbles: true }));
                                },
                            }" class="space-y-4">

                            <div class="p-3 text-sm text-blue-800 border border-blue-200 rounded-lg bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800/40 dark:text-blue-200">
                                <p><b>Lihat</b> = menu tampil & halaman bisa dibuka. <b>Aksi Khusus</b> = hak di dalam halaman
                                    (kelola gaji, ubah rekap absensi, nilai lembur, kunci mutu, dll.).</p>
                                <p class="mt-1">User mendapat gabungan hak dari semua role yang dipegangnya. Perubahan berlaku setelah <b>Simpan</b>.</p>
                            </div>

                            @if ($originalName === 'Admin')
                                <div class="p-3 text-sm border rounded-lg text-amber-800 bg-amber-50 border-amber-200 dark:bg-amber-900/20 dark:border-amber-800/40 dark:text-amber-200">
                                    Role <b>Admin</b> selalu boleh semua (pengaman supaya tidak ada yang terkunci keluar) — centang di bawah tidak berpengaruh.
                                </div>
                            @endif

                            @if ($matriks['belumSinkron'])
                                <div class="flex items-start gap-2 p-3 text-sm border rounded-lg text-amber-800 bg-amber-50 border-amber-200 dark:bg-amber-900/20 dark:border-amber-800/40 dark:text-amber-200">
                                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                                    <p>Sebagian hak belum dibuat (tanda —) — otomatis dibuat pada pembukaan halaman berikutnya, atau jalankan <code class="font-mono">php artisan akses:sinkron</code>.</p>
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="w-full sm:w-80">
                                    <x-text-input x-model.debounce.200ms="cari" placeholder="Cari menu / aksi..." class="w-full" />
                                </div>
                                <p class="text-sm text-muted dark:text-gray-400">
                                    <span x-text="pilih.length"></span> hak aktif
                                </p>
                            </div>

                            <div class="border rounded-lg border-hairline dark:border-gray-700">
                                <table class="w-full text-sm">
                                    <thead class="sticky top-0 z-10 text-xs uppercase shadow-sm bg-surface-soft text-muted dark:bg-gray-800 dark:text-gray-400">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Halaman / Aksi</th>
                                            <th class="w-20 px-2 py-2 text-center">Lihat</th>
                                            <th class="w-28 px-2 py-2 text-center">Aksi Khusus</th>
                                            <th class="w-16 px-2 py-2 text-center">Semua</th>
                                        </tr>
                                    </thead>
                                    {{-- Per KELOMPOK → per PROGRAM: baris judul program (tombol "semua" per kolom), baris halamannya,
                                         lalu baris aksi khususnya. --}}
                                    @foreach ($matriks['program'] as $judulKelompok => $daftarProgram)
                                        @php
                                            $teksKelompok = mb_strtolower($judulKelompok . ' ' . collect($daftarProgram)->map(fn ($isi, $namaProgram) => $namaProgram . ' '
                                                . collect($isi['halaman'])->map(fn ($r) => $r['title'] . ' ' . $r['route'])->implode(' ') . ' '
                                                . collect($isi['khusus'])->map(fn ($r) => $r['label'] . ' ' . $r['nama'])->implode(' '))->implode(' '));
                                        @endphp
                                        <tbody wire:key="kelompok-{{ md5($judulKelompok) }}" x-show="cocok(@js($teksKelompok))">
                                            <tr class="border-t-2 border-brand/40 bg-brand/10 dark:bg-gray-700/60 dark:border-gray-600">
                                                <td colspan="4" class="px-3 py-2 text-sm font-bold tracking-wide uppercase text-ink dark:text-gray-100">{{ $judulKelompok }}</td>
                                            </tr>
                                        </tbody>
                                        @foreach ($daftarProgram as $namaProgram => $isi)
                                            @php
                                                $awalan = mb_strtolower($judulKelompok . ' ' . $namaProgram . ' ');
                                                $teksProgram = $awalan . mb_strtolower(collect($isi['halaman'])->map(fn ($r) => $r['title'] . ' ' . $r['route'])->implode(' ') . ' '
                                                    . collect($isi['khusus'])->map(fn ($r) => $r['label'] . ' ' . $r['nama'])->implode(' '));
                                                $idsLihat = array_values(array_filter(array_column($isi['halaman'], 'id')));
                                                $idsKhusus = array_values(array_filter(array_column($isi['khusus'], 'id')));
                                                $idsProgram = [...$idsLihat, ...$idsKhusus];
                                            @endphp
                                            <tbody wire:key="program-{{ md5($judulKelompok . '|' . $namaProgram) }}" x-show="cocok(@js($teksProgram))">
                                                <tr class="border-t bg-brand/5 border-hairline dark:bg-gray-800/60 dark:border-gray-700">
                                                    <td class="px-3 py-2 font-semibold text-ink dark:text-gray-200">{{ $namaProgram }}</td>
                                                    @foreach ([$idsLihat, $idsKhusus] as $ids)
                                                        <td class="px-2 py-2 text-center">
                                                            @if ($ids)
                                                                <button type="button" x-on:click="atur(@js($ids))"
                                                                    class="text-xs font-medium hover:underline"
                                                                    x-bind:class="semuaNyala(@js($ids)) ? 'text-fg-brand' : 'text-muted'"
                                                                    title="Centang / lepas semua di {{ $namaProgram }}">semua</button>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                    <td class="px-2 py-2 text-center">
                                                        @if ($idsProgram)
                                                            <button type="button" x-on:click="atur(@js($idsProgram))"
                                                                class="text-xs font-medium text-muted hover:underline"
                                                                title="Centang / lepas SEMUA hak {{ $namaProgram }}">semua</button>
                                                        @endif
                                                    </td>
                                                </tr>

                                                {{-- Halaman program ini --}}
                                                @foreach ($isi['halaman'] as $r)
                                                    <tr wire:key="hal-{{ $r['route'] }}"
                                                        x-show="cocok(@js($awalan . mb_strtolower($r['title'] . ' ' . $r['route'])))"
                                                        class="border-t border-hairline dark:border-gray-700 hover:bg-surface-soft dark:hover:bg-gray-800/40">
                                                        <td class="px-3 py-1.5 pl-6">
                                                            <div class="text-ink dark:text-gray-200">{{ $r['title'] }}</div>
                                                            <div class="font-mono text-xs text-muted-soft">{{ $r['route'] }}</div>
                                                        </td>
                                                        @if ($r['terbuka'])
                                                            <td colspan="3" class="px-2 py-1.5 text-center">
                                                                <x-badge variant="alternative">Terbuka untuk semua user</x-badge>
                                                            </td>
                                                        @else
                                                            <td class="px-2 py-1.5 text-center">
                                                                @if ($r['id'])
                                                                    <x-toggle-alpine x-model="pilih" :value="$r['id']" :aria-label="'Lihat ' . $r['title']" />
                                                                @else
                                                                    <span class="text-muted-soft" title="Belum dibuat — jalankan akses:sinkron">—</span>
                                                                @endif
                                                            </td>
                                                            <td></td>
                                                            <td></td>
                                                        @endif
                                                    </tr>
                                                @endforeach

                                                {{-- Aksi khusus program ini --}}
                                                @foreach ($isi['khusus'] as $r)
                                                    <tr wire:key="khusus-{{ $r['nama'] }}"
                                                        x-show="cocok(@js($awalan . mb_strtolower($r['label'] . ' ' . $r['nama'])))"
                                                        class="border-t border-hairline dark:border-gray-700 hover:bg-surface-soft dark:hover:bg-gray-800/40">
                                                        <td class="px-3 py-1.5 pl-6">
                                                            <div class="text-ink dark:text-gray-200">{{ $r['label'] }}</div>
                                                            <div class="font-mono text-xs text-muted-soft">{{ $r['nama'] }}</div>
                                                        </td>
                                                        <td class="px-2 py-1.5 text-xs text-center text-muted-soft">aksi khusus</td>
                                                        <td class="px-2 py-1.5 text-center">
                                                            @if ($r['id'])
                                                                <x-toggle-alpine x-model="pilih" :value="$r['id']" :aria-label="$r['label']" />
                                                            @else
                                                                <span class="text-muted-soft" title="Belum dibuat — jalankan akses:sinkron">—</span>
                                                            @endif
                                                        </td>
                                                        <td></td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        @endforeach
                                    @endforeach
                                </table>
                            </div>

                            {{-- Permission di luar definisi (lama / buatan manual) — dihapus akses:sinkron --}}
                            @if ($matriks['lainnya'])
                                <div class="border rounded-lg border-hairline dark:border-gray-700" x-show="cocok('lainnya ' + @js(mb_strtolower(collect($matriks['lainnya'])->pluck('nama')->implode(' '))))">
                                    <div class="px-3 py-2 text-sm font-semibold border-b text-ink bg-surface-soft border-hairline dark:bg-gray-800/60 dark:border-gray-700 dark:text-gray-200">
                                        Lainnya (di luar definisi — akan dihapus akses:sinkron)
                                    </div>
                                    <div class="flex flex-wrap gap-4 p-3">
                                        @foreach ($matriks['lainnya'] as $r)
                                            <x-toggle-alpine wire:key="lain-{{ $r['id'] }}" x-model="pilih" :value="$r['id']" :label="$r['nama']" />
                                        @endforeach
                                    </div>
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
